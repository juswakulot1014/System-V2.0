<?php
// config/security.php — central security bootstrap.
// Include FIRST in every entry-point, BEFORE session_start().

declare(strict_types=1);

if (defined('SECURITY_BOOTSTRAPPED')) return;
define('SECURITY_BOOTSTRAPPED', true);

// ---------- Hardened session bootstrap ----------
function secure_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    ini_set('session.cookie_httponly', '1');

    session_start();

    $now = time();
    if (!isset($_SESSION['_created'])) {
        $_SESSION['_created'] = $now;
        $_SESSION['_last']    = $now;
    } elseif ($now - $_SESSION['_last'] > 1800 || $now - $_SESSION['_created'] > 28800) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['_created'] = $now;
        $_SESSION['_last']    = $now;
    } else {
        $_SESSION['_last'] = $now;
    }

    if (!isset($_SESSION['_rot']) || $now - $_SESSION['_rot'] > 900) {
        session_regenerate_id(true);
        $_SESSION['_rot'] = $now;
    }
}

// ---------- Security headers ----------
function send_security_headers(): void {
    if (headers_sent()) return;
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
      . "script-src 'self' https://cdn.jsdelivr.net https://code.jquery.com https://cdnjs.cloudflare.com 'unsafe-inline'; "
      . "style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com 'unsafe-inline'; "
      . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
      . "img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'"
    );
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ---------- CSRF ----------
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
         . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
function verify_csrf(bool $rotate = false): void {
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(419);
        exit('Invalid CSRF token. Please refresh and try again.');
    }
    if ($rotate) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// ---------- Rate limiting ----------
//
// DB-backed when a mysqli connection is supplied. Falls back to session
// storage on ANY failure so a missing/broken table can never take down
// the login page.
//
// Implementation notes:
//   - We do NOT touch mysqli_report()/mysqli_driver(). db.php already
//     enables MYSQLI_REPORT_STRICT, so every error throws — plain
//     try/catch is sufficient and portable across PHP builds.
//   - The rate_limits table is auto-created on first use. If the DB
//     user lacks CREATE privilege, we log once and use session storage.

if (!function_exists('rate_limit_ensure_table')) {
    /**
     * Ensure the rate_limits table exists. Returns true on success.
     * Only attempts creation once per request (static cache).
     */
    function rate_limit_ensure_table(mysqli $conn): bool
    {
        static $ensured = null;
        if ($ensured !== null) return $ensured;

        // ---- 1. Does the table already exist? ----
        try {
            $res = $conn->query("SHOW TABLES LIKE 'rate_limits'");
            if ($res instanceof mysqli_result && $res->num_rows > 0) {
                $res->free();
                return $ensured = true;
            }
            if ($res instanceof mysqli_result) $res->free();
        } catch (\Throwable $e) {
            error_log('rate_limit: SHOW TABLES failed: ' . $e->getMessage());
            return $ensured = false;
        }

        // ---- 2. Try to create it ----
        try {
            $conn->query("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    bucket_key VARCHAR(191) NOT NULL PRIMARY KEY,
                    count      INT UNSIGNED NOT NULL DEFAULT 0,
                    window_end DATETIME NOT NULL,
                    INDEX idx_window_end (window_end)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Throwable $e) {
            error_log('rate_limit: CREATE TABLE failed (falling back to session storage): '
                    . $e->getMessage());
            return $ensured = false;
        }

        // ---- 3. Re-verify (CREATE IF NOT EXISTS can return true even
        //           when the user lacks privileges on some MySQL setups) ----
        try {
            $res = $conn->query("SHOW TABLES LIKE 'rate_limits'");
            $exists = $res instanceof mysqli_result && $res->num_rows > 0;
            if ($res instanceof mysqli_result) $res->free();
            return $ensured = $exists;
        } catch (\Throwable $e) {
            error_log('rate_limit: verify failed: ' . $e->getMessage());
            return $ensured = false;
        }
    }
}

if (!function_exists('rate_limit_check')) {
    function rate_limit_check(string $key, int $max = 5, int $window = 900, ?mysqli $conn = null): array
    {
        $now = time();

        // ---------- DB-backed path ----------
        if ($conn instanceof mysqli && rate_limit_ensure_table($conn)) {
            try {
                $bucket = 'rl:' . $key;

                // Opportunistic cleanup — cheap, keeps the table small.
                if (random_int(1, 50) === 1) {
                    $conn->query("DELETE FROM rate_limits WHERE window_end < NOW() - INTERVAL 1 HOUR");
                }

                $stmt = $conn->prepare("SELECT count, window_end FROM rate_limits WHERE bucket_key = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $bucket);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($row && strtotime($row['window_end']) > $now) {
                        $count = (int)$row['count'];
                        $left  = max(0, strtotime($row['window_end']) - $now);
                        return [
                            'count'       => $count,
                            'remaining'   => max(0, $max - $count),
                            'window_left' => $left,
                            'locked'      => $count >= $max,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                error_log('rate_limit_check: DB path failed, using session: ' . $e->getMessage());
            }
        }

        // ---------- Session fallback (always safe) ----------
        $k = 'rl_' . $key;
        if (!isset($_SESSION[$k])) {
            $_SESSION[$k] = ['count' => 0, 'start' => $now];
        }
        if ($now - $_SESSION[$k]['start'] > $window) {
            $_SESSION[$k] = ['count' => 0, 'start' => $now];
        }
        $b = $_SESSION[$k];
        return [
            'count'       => $b['count'],
            'remaining'   => max(0, $max - $b['count']),
            'window_left' => max(0, $window - ($now - $b['start'])),
            'locked'      => $b['count'] >= $max,
        ];
    }
}

if (!function_exists('rate_limit_hit')) {
    function rate_limit_hit(string $key, int $window = 900, ?mysqli $conn = null): void
    {
        if ($conn instanceof mysqli && rate_limit_ensure_table($conn)) {
            try {
                $bucket = 'rl:' . $key;
                $end    = date('Y-m-d H:i:s', time() + $window);

                $stmt = $conn->prepare("
                    INSERT INTO rate_limits (bucket_key, count, window_end)
                    VALUES (?, 1, ?)
                    ON DUPLICATE KEY UPDATE
                        count      = IF(window_end > NOW(), count + 1, 1),
                        window_end = IF(window_end > NOW(), window_end, VALUES(window_end))
                ");
                if ($stmt) {
                    $stmt->bind_param('ss', $bucket, $end);
                    $stmt->execute();
                    $stmt->close();
                }
                return;
            } catch (\Throwable $e) {
                error_log('rate_limit_hit: DB path failed, using session: ' . $e->getMessage());
            }
        }

        // Session fallback
        $k = 'rl_' . $key;
        $now = time();
        if (!isset($_SESSION[$k])) {
            $_SESSION[$k] = ['count' => 0, 'start' => $now];
        }
        $_SESSION[$k]['count']++;
    }
}

if (!function_exists('rate_limit_reset')) {
    function rate_limit_reset(string $key, ?mysqli $conn = null): void
    {
        if ($conn instanceof mysqli && rate_limit_ensure_table($conn)) {
            try {
                $bucket = 'rl:' . $key;
                $stmt = $conn->prepare("DELETE FROM rate_limits WHERE bucket_key = ?");
                if ($stmt) {
                    $stmt->bind_param('s', $bucket);
                    $stmt->execute();
                    $stmt->close();
                }
                return;
            } catch (\Throwable $e) {
                error_log('rate_limit_reset: DB path failed, using session: ' . $e->getMessage());
            }
        }
        unset($_SESSION['rl_' . $key]);
    }
}

// ---------- Auth guards ----------
function require_admin(): void {
    if (empty($_SESSION['admin_id'])) {
        header('Location: admin_login.php');
        exit;
    }
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (isset($_SESSION['user_agent']) && !hash_equals($_SESSION['user_agent'], $ua)) {
        session_unset();
        session_destroy();
        header('Location: admin_login.php?timeout=1');
        exit;
    }
}
function require_superadmin(): void {
    require_admin();
    if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
        http_response_code(403);
        exit('Forbidden — Super Admin only.');
    }
}
function require_role(?string $role = null): void {
    if (empty($_SESSION['admin_id'])) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
        header('Location: admin_login.php');
        exit;
    }
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (isset($_SESSION['user_agent']) && !hash_equals($_SESSION['user_agent'], $ua)) {
        session_unset();
        session_destroy();
        header('Location: admin_login.php?timeout=1');
        exit;
    }
    if ($role !== null && ($_SESSION['admin_role'] ?? '') !== $role) {
        http_response_code(403);
        exit('Forbidden.');
    }
}
function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method Not Allowed');
    }
}

// ---------- Helpers ----------
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function safe_filename(string $v): string {
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', $v) ?: 'file';
}