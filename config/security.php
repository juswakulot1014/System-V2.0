<?php
declare(strict_types=1);

/**
 * config/security.php
 * ─────────────────────────────────────────────────────────────────
 * Central security library. Loaded FIRST from bootstrap.php.
 */

/* ═══════════════════════════════════════════════════════════════
   0. PRIMITIVES
   ═══════════════════════════════════════════════════════════════ */

if (!function_exists('is_https')) {
    function is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return false;
    }
}

if (!function_exists('human_size')) {
    function human_size(int $bytes): string
    {
        if ($bytes < 1024)       return $bytes . ' B';
        if ($bytes < 1048576)    return number_format($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return number_format($bytes / 1048576, 2) . ' MB';
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }
}

/* ═══════════════════════════════════════════════════════════════
   1. SESSION BOOTSTRAP
   ═══════════════════════════════════════════════════════════════ */

if (!function_exists('secure_session_start')) {
    function secure_session_start(): void
    {
        if (PHP_SAPI === 'cli') return;
        if (session_status() === PHP_SESSION_ACTIVE) return;

        $params = [
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($params);
        } else {
            session_set_cookie_params(
                $params['lifetime'],
                $params['path'] . '; samesite=' . $params['samesite'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_name('USAT_SESSID');
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.gc_maxlifetime', '7200');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['__last_regen'])) {
            $_SESSION['__last_regen'] = time();
        } elseif (time() - (int)$_SESSION['__last_regen'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['__last_regen'] = time();
        }
    }
}

/* ═══════════════════════════════════════════════════════════════
   1b. CSRF TOKEN
   ═══════════════════════════════════════════════════════════════ */

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')
            . '">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(): void
    {
        $expected = $_SESSION['csrf_token'] ?? '';

        $submitted = (string)(
            $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_GET['csrf_token']
            ?? ''
        );

        if ($expected !== '' && $submitted !== '' && hash_equals($expected, $submitted)) {
            return;
        }

        if (function_exists('security_log')) {
            security_log('csrf.failed', [
                'ip'     => client_ip(),
                'uri'    => $_SERVER['REQUEST_URI'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            ]);
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $wants_json = (
            (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest')
            || (strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false)
        );

        if ($wants_json) {
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'      => false,
                'error'   => 'csrf',
                'message' => 'Your session expired. Please reload and try again.',
            ]);
            exit;
        }

        http_response_code(419);
        exit('Security check failed — your session expired. Please go back and reload the page.');
    }
}

/* ═══════════════════════════════════════════════════════════════
   1c. AUTH / ROLE HELPERS
   ═══════════════════════════════════════════════════════════════ */

if (!function_exists('current_admin_id')) {
    function current_admin_id(): int
    {
        return (int)($_SESSION['admin_id'] ?? 0);
    }
}

if (!function_exists('current_admin_role')) {
    function current_admin_role(): string
    {
        return (string)($_SESSION['admin_role'] ?? '');
    }
}

if (!function_exists('current_admin_name')) {
    function current_admin_name(): string
    {
        return (string)($_SESSION['admin_name'] ?? '');
    }
}

if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool
    {
        return current_admin_id() > 0;
    }
}

if (!function_exists('has_role')) {
    function has_role(string|array $roles): bool
    {
        $current = current_admin_role();
        if ($current === '') return false;
        $roles = is_array($roles) ? $roles : [$roles];
        return in_array($current, $roles, true);
    }
}

if (!function_exists('is_superadmin')) {
    function is_superadmin(): bool
    {
        return has_role('superadmin');
    }
}

if (!function_exists('is_registrar')) {
    function is_registrar(): bool
    {
        return has_role('registrar');
    }
}

if (!function_exists('require_login')) {
    function require_login(string $login_url = '../admin/admin_login.php'): void
    {
        if (is_logged_in()) return;

        $_SESSION['__redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';

        header('Location: ' . $login_url);
        exit;
    }
}

if (!function_exists('require_role')) {
    /**
     * Enforce that the current admin is logged in and (optionally) has
     * one of the given roles.
     *
     *   require_role(null);                      // only requires login
     *   require_role('superadmin');              // single role
     *   require_role(['superadmin','registrar']);// any of these roles
     */
    function require_role(string|array|null $roles = null): void
    {
        // ── 1. Must be logged in ──
        if (!is_logged_in()) {
            $script    = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $login_url = (strpos($script, '/admin/') !== false)
                ? 'admin_login.php'
                : 'admin/admin_login.php';

            $_SESSION['__redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
            header('Location: ' . $login_url);
            exit;
        }

        // ── 2. No role constraint → done ──
        if ($roles === null || $roles === '' || $roles === []) {
            return;
        }

        // ── 3. Normalize ──
        $roles = is_array($roles) ? $roles : [$roles];

        $roles = array_values(array_filter(
            $roles,
            static fn($r) => $r !== null && $r !== ''
        ));

        if (empty($roles)) {
            return;
        }

        // ── 4. Role check ──
        if (!has_role($roles)) {
            if (function_exists('security_log')) {
                security_log('auth.role_denied', [
                    'admin_id' => current_admin_id(),
                    'role'     => current_admin_role(),
                    'required' => $roles,
                    'uri'      => $_SERVER['REQUEST_URI'] ?? '',
                    'ip'       => client_ip(),
                ]);
            }

            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>403 Forbidden</title>'
               . '<style>body{font-family:system-ui,sans-serif;background:#f4f6fb;color:#0f172a;'
               . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
               . '.box{background:#fff;border-radius:16px;padding:2.5rem 3rem;box-shadow:0 12px 32px rgba(15,23,42,.1);'
               . 'text-align:center;max-width:420px}'
               . 'h1{font-size:3rem;margin:0 0 .5rem;color:#dc2626}'
               . 'p{color:#64748b;margin:.5rem 0 1.5rem}'
               . 'a{display:inline-block;padding:.75rem 1.5rem;background:#4f46e5;color:#fff;text-decoration:none;'
               . 'border-radius:10px;font-weight:600}'
               . 'a:hover{background:#6366f1}'
               . '</style></head><body><div class="box">'
               . '<h1>403</h1>'
               . '<p>You do not have permission to access this page.</p>'
               . '<a href="dashboard.php">Back to Dashboard</a>'
               . '</div></body></html>';
            exit;
        }
    }
}

if (!function_exists('require_guest')) {
    function require_guest(string $redirect = 'dashboard.php'): void
    {
        if (!is_logged_in()) return;
        header('Location: ' . $redirect);
        exit;
    }
}

if (!function_exists('validate_strong_password')) {
    function validate_strong_password(string $password, int $min_len = 10): ?string
    {
        if (strlen($password) < $min_len) {
            return "Password must be at least {$min_len} characters.";
        }
        if (strlen($password) > 128) {
            return 'Password must not exceed 128 characters.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return 'Password must contain at least one uppercase letter.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            return 'Password must contain at least one lowercase letter.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one number.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Password must contain at least one special character.';
        }
        return null;
    }
}

if (!function_exists('generate_token')) {
    function generate_token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}

/* ═══════════════════════════════════════════════════════════════
   2. HTTP HARDENING HEADERS
   ═══════════════════════════════════════════════════════════════ */

if (!function_exists('send_security_headers')) {
    function send_security_headers(): void
    {
        if (headers_sent()) return;

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

        header(
            "Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://code.jquery.com; "
            . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
            . "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com data:; "
            . "img-src 'self' data: blob: https:; "
            . "connect-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self';"
        );

        if (is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        header_remove('X-Powered-By');
    }
}

if (!function_exists('send_public_security_headers')) {
    function send_public_security_headers(): void
    {
        if (headers_sent()) return;

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(self), camera=(self), payment=()');

        header(
            "Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
            . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
            . "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com data:; "
            . "img-src 'self' data: blob:; "
            . "media-src 'self' blob:; "
            . "connect-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self';"
        );

        if (is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        header_remove('X-Powered-By');
    }
}

/* ═══════════════════════════════════════════════════════════════
   3. SQL INJECTION GUARDS
   ═══════════════════════════════════════════════════════════════ */

if (!function_exists('sql_whitelist')) {
    function sql_whitelist(string $value, array $allowed, ?string $default = null): string
    {
        if (in_array($value, $allowed, true)) return $value;
        if ($default !== null && in_array($default, $allowed, true)) return $default;
        throw new InvalidArgumentException('Value not in whitelist: ' . $value);
    }
}

if (!function_exists('sql_identifier')) {
    function sql_identifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $name);
        }
        return $name;
    }
}

if (!function_exists('sql_int')) {
    function sql_int(
        mixed $value,
        int $min = PHP_INT_MIN,
        int $max = PHP_INT_MAX,
        int $default = 0
    ): int {
        if (!is_numeric($value)) return max($min, min($max, $default));
        return max($min, min($max, (int)$value));
    }
}

/* ═══════════════════════════════════════════════════════════════
   4. FILE UPLOAD VALIDATION
   ═══════════════════════════════════════════════════════════════ */

if (!function_exists('upload_magic_bytes')) {
    function upload_magic_bytes(): array
    {
        return [
            'application/pdf' => ["%PDF-"],
            'image/jpeg'      => ["\xFF\xD8\xFF"],
            'image/png'       => ["\x89PNG\r\n\x1a\n"],
        ];
    }
}

if (!function_exists('validate_upload')) {
    function validate_upload(string $abs_path, string $original_name, array $opts = []): array
    {
        $max_bytes   = $opts['max_bytes']   ?? 5 * 1024 * 1024;
        $allowed     = $opts['allowed']     ?? ['application/pdf', 'image/jpeg', 'image/png'];
        $image_min_w = $opts['image_min_w'] ?? 100;
        $image_min_h = $opts['image_min_h'] ?? 100;

        if (!is_file($abs_path)) {
            throw new RuntimeException('Uploaded file is missing.');
        }

        $size = filesize($abs_path);
        if ($size === false || $size === 0) {
            throw new RuntimeException('Uploaded file is empty.');
        }
        if ($size > $max_bytes) {
            throw new RuntimeException(sprintf(
                'File too large (%s). Maximum is %s.',
                human_size($size),
                human_size($max_bytes)
            ));
        }

        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi === false) {
            throw new RuntimeException('Server misconfiguration: finfo unavailable.');
        }
        $mime = (string)finfo_file($fi, $abs_path);
        finfo_close($fi);

        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException('File type not allowed: ' . $mime);
        }

        $sigs = upload_magic_bytes()[$mime] ?? null;
        if ($sigs !== null) {
            $fh = fopen($abs_path, 'rb');
            if ($fh === false) {
                throw new RuntimeException('Cannot read uploaded file.');
            }
            $head = fread($fh, 16);
            fclose($fh);

            $matched = false;
            foreach ($sigs as $sig) {
                if (str_starts_with($head, $sig)) { $matched = true; break; }
            }
            if (!$matched) {
                throw new RuntimeException('File contents do not match the declared type.');
            }
        }

        $fh = fopen($abs_path, 'rb');
        if ($fh !== false) {
            $head = fread($fh, 8192);
            fclose($fh);

            if (preg_match('/<\?(php|=|\s)/i', $head)
                || stripos($head, '<script') !== false
                || stripos($head, '<svg')    !== false) {
                throw new RuntimeException('File contains disallowed markup.');
            }
        }

        $width = 0; $height = 0;
        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($abs_path);
            if ($info === false || $info[0] < $image_min_w || $info[1] < $image_min_h) {
                throw new RuntimeException(
                    "Image is too small — minimum {$image_min_w}×{$image_min_h}px."
                );
            }
            $width  = (int)$info[0];
            $height = (int)$info[1];
        }

        $sha = hash_file('sha256', $abs_path);
        if ($sha === false) {
            throw new RuntimeException('Cannot hash uploaded file.');
        }

        $safe_original = preg_replace('/[^\w.\- ]+/u', '_', $original_name) ?? 'file';
        $safe_original = trim(mb_substr($safe_original, 0, 200));
        if ($safe_original === '') $safe_original = 'file';

        $ext_map = [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
        ];

        return [
            'mime'          => $mime,
            'ext'           => $ext_map[$mime],
            'size'          => (int)$size,
            'width'         => $width,
            'height'        => $height,
            'sha256'        => $sha,
            'safe_original' => $safe_original,
        ];
    }
}

if (!function_exists('move_upload_securely')) {
    function move_upload_securely(string $tmp_path, string $dest_dir, string $ext): string
    {
        if (!is_dir($dest_dir)) {
            throw new RuntimeException('Upload destination does not exist.');
        }
        if (!is_writable($dest_dir)) {
            throw new RuntimeException('Upload destination is not writable.');
        }

        $ext  = preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'bin';
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = rtrim($dest_dir, '/\\') . DIRECTORY_SEPARATOR . $name;

        $ok = is_uploaded_file($tmp_path)
            ? move_uploaded_file($tmp_path, $dest)
            : @rename($tmp_path, $dest);

        if (!$ok) {
            if (@copy($tmp_path, $dest)) {
                @unlink($tmp_path);
            } else {
                throw new RuntimeException('Failed to store uploaded file.');
            }
        }

        @chmod($dest, 0600);

        $real_dest = realpath($dest);
        $real_dir  = realpath($dest_dir);
        if ($real_dest === false || $real_dir === false
            || !str_starts_with($real_dest, $real_dir . DIRECTORY_SEPARATOR)) {
            @unlink($dest);
            throw new RuntimeException('Path containment violation.');
        }

        return $real_dest;
    }
}

/* ═══════════════════════════════════════════════════════════════
   5. PATH CONTAINMENT
   ═══════════════════════════════════════════════════════════════ */

if (!function_exists('safe_path')) {
    function safe_path(string $base, string $user_part): string
    {
        $base_real = realpath($base);
        if ($base_real === false) {
            throw new RuntimeException('Base directory does not exist.');
        }

        $candidate = $base_real . DIRECTORY_SEPARATOR . basename($user_part);
        $real      = realpath($candidate);

        if ($real === false) {
            throw new RuntimeException('Path does not resolve.');
        }
        if (!str_starts_with($real, $base_real . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Path escape detected.');
        }
        return $real;
    }
}

/* ═══════════════════════════════════════════════════════════════
   6. INPUT SANITIZERS
   ═══════════════════════════════════════════════════════════════ */

if (!function_exists('clean_string')) {
    function clean_string(mixed $v, int $max_len = 255): string
    {
        if (!is_string($v)) return '';
        $v = trim($v);
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        return mb_substr($v, 0, $max_len);
    }
}

if (!function_exists('is_valid_lrn')) {
    function is_valid_lrn(string $v): bool
    {
        return (bool)preg_match('/^\d{12}$/', $v);
    }
}

if (!function_exists('is_valid_ph_mobile')) {
    function is_valid_ph_mobile(string $v): bool
    {
        return (bool)preg_match('/^09\d{9}$/', $v);
    }
}

if (!function_exists('is_valid_school_year')) {
    function is_valid_school_year(string $v): bool
    {
        if (!preg_match('/^(\d{4})-(\d{4})$/', $v, $m)) return false;
        return (int)$m[2] === (int)$m[1] + 1;
    }
}

/* ═══════════════════════════════════════════════════════════════
   7. DB-BACKED RATE LIMITING
   ═══════════════════════════════════════════════════════════════ */

if (!function_exists('rate_limit_ensure_table')) {
    function rate_limit_ensure_table(mysqli $conn): void
    {
        static $done = false;
        if ($done) return;

        $conn->query("
            CREATE TABLE IF NOT EXISTS usat_security_rate_limits (
                rl_key      VARCHAR(255) NOT NULL PRIMARY KEY,
                rl_count    INT NOT NULL DEFAULT 0,
                rl_first_at DATETIME NOT NULL,
                rl_last_at  DATETIME NOT NULL,
                INDEX idx_last (rl_last_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $done = true;
    }
}

if (!function_exists('rate_limit_check')) {
    function rate_limit_check(string $key, int $max, int $window_secs, mysqli $conn): array
    {
        rate_limit_ensure_table($conn);

        $stmt = $conn->prepare("
            SELECT rl_count, rl_first_at, rl_last_at
              FROM usat_security_rate_limits
             WHERE rl_key = ?
             LIMIT 1
        ");
        if (!$stmt) {
            return ['locked' => false, 'remaining' => $max, 'window_left' => 0];
        }

        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['locked' => false, 'remaining' => $max, 'window_left' => 0];
        }

        $first_at = strtotime((string)$row['rl_first_at']);
        $now      = time();

        if (($now - $first_at) >= $window_secs) {
            return ['locked' => false, 'remaining' => $max, 'window_left' => 0];
        }

        $count = (int)$row['rl_count'];

        if ($count >= $max) {
            $window_left = max(0, $window_secs - ($now - $first_at));
            return ['locked' => true, 'remaining' => 0, 'window_left' => $window_left];
        }

        return [
            'locked'      => false,
            'remaining'   => $max - $count,
            'window_left' => 0,
        ];
    }
}

if (!function_exists('rate_limit_hit')) {
    function rate_limit_hit(string $key, int $window_secs, mysqli $conn): void
    {
        rate_limit_ensure_table($conn);

        $now_str = date('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            INSERT INTO usat_security_rate_limits (rl_key, rl_count, rl_first_at, rl_last_at)
            VALUES (?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE
                rl_count = IF(
                    TIMESTAMPDIFF(SECOND, rl_first_at, VALUES(rl_last_at)) >= ?,
                    1,
                    rl_count + 1
                ),
                rl_first_at = IF(
                    TIMESTAMPDIFF(SECOND, rl_first_at, VALUES(rl_last_at)) >= ?,
                    VALUES(rl_first_at),
                    rl_first_at
                ),
                rl_last_at = VALUES(rl_last_at)
        ");
        if (!$stmt) return;

        // 5 params: s(key) s(now_str) s(now_str) i(window) i(window)
        $stmt->bind_param('sssii', $key, $now_str, $now_str, $window_secs, $window_secs);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('rate_limit_reset')) {
    function rate_limit_reset(string $key, mysqli $conn): void
    {
        rate_limit_ensure_table($conn);

        $stmt = $conn->prepare("DELETE FROM usat_security_rate_limits WHERE rl_key = ?");
        if (!$stmt) return;

        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('rate_limit_gc')) {
    function rate_limit_gc(mysqli $conn, int $older_than_secs = 86400): void
    {
        rate_limit_ensure_table($conn);

        $cutoff = date('Y-m-d H:i:s', time() - $older_than_secs);
        $stmt = $conn->prepare("DELETE FROM usat_security_rate_limits WHERE rl_last_at < ?");
        if (!$stmt) return;

        $stmt->bind_param('s', $cutoff);
        $stmt->execute();
        $stmt->close();
    }
}

/* ═══════════════════════════════════════════════════════════════
   8. SESSION-BASED RATE LIMITING
   ═══════════════════════════════════════════════════════════════ */

if (!function_exists('rate_limit_session')) {
    function rate_limit_session(string $key, int $max, int $window_secs): array
    {
        $bucket_key = '__rl_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $key);
        $now        = time();

        $bucket = $_SESSION[$bucket_key] ?? ['hits' => [], 'blocked_until' => 0];

        if ($bucket['blocked_until'] > $now) {
            return [
                'allowed'     => false,
                'remaining'   => 0,
                'retry_after' => $bucket['blocked_until'] - $now,
            ];
        }

        $bucket['hits'] = array_values(array_filter(
            $bucket['hits'],
            fn(int $t) => ($now - $t) < $window_secs
        ));

        if (count($bucket['hits']) >= $max) {
            $bucket['blocked_until'] = $now + $window_secs;
            $_SESSION[$bucket_key] = $bucket;
            return [
                'allowed'     => false,
                'remaining'   => 0,
                'retry_after' => $window_secs,
            ];
        }

        $bucket['hits'][] = $now;
        $_SESSION[$bucket_key] = $bucket;

        return [
            'allowed'     => true,
            'remaining'   => $max - count($bucket['hits']),
            'retry_after' => 0,
        ];
    }
}

/* ═══════════════════════════════════════════════════════════════
   9. SECURITY LOGGING
   ═══════════════════════════════════════════════════════════════ */

if (!function_exists('security_log')) {
    function security_log(string $event, array $context = []): void
    {
        $log_dir = dirname(__DIR__) . '/logs';
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0700, true);
        }

        $line = sprintf(
            "[%s] %s %s\n",
            date('c'),
            $event,
            json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        @error_log($line, 3, $log_dir . '/security.log');
    }
}

/* ═══════════════════════════════════════════════════════════════
   10. PHP < 8.0 POLYFILLS
   ═══════════════════════════════════════════════════════════════ */

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}