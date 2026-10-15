<?php
declare(strict_types=1);

/**
 * logout.php — Terminate an admin session cleanly.
 *
 * Hardening applied:
 *   - Audited BEFORE session destruction
 *   - Remember-me token cleared from DB and cookie
 *   - Cookie cleared with SameSite=Lax (matches login)
 *   - Full session wipe + destroy
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/security.php';

/* ── 1. Audit the logout BEFORE destroying the session ────────── */
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
if ($admin_id > 0) {
    try {
        audit_log($conn, 'admin.logout', [
            'type' => 'admin',
            'id'   => $admin_id,
        ]);
    } catch (Throwable $e) {
        error_log('logout: audit_log() threw: ' . $e->getMessage());
    }
}

/* ── 2. Clear remember-me token from DB + cookie ─────────────── */
$remember_cookie_name = 'admin_remember_token';

if (isset($_COOKIE[$remember_cookie_name])) {
    $t = (string)$_COOKIE[$remember_cookie_name];

    if ($t !== '') {
        $clear = $conn->prepare(
            "UPDATE admins
                SET remember_token = NULL, remember_expires = NULL
              WHERE remember_token = ?"
        );
        if ($clear) {
            $clear->bind_param('s', $t);
            $clear->execute();
            $clear->close();
        }
    }

    // SameSite=Lax to match the login cookie (Strict would still clear
    // the cookie, but this keeps behavior symmetric across the app).
    setcookie($remember_cookie_name, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    unset($_COOKIE[$remember_cookie_name]);
}

/* ── 3. Wipe the session completely ──────────────────────────── */
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $p['path']      ?? '/',
            'domain'   => $p['domain']    ?? '',
            'secure'   => $p['secure']    ?? is_https(),
            'httponly' => $p['httponly']  ?? true,
            'samesite' => 'Lax',
        ]
    );
}

session_destroy();

/* ── 4. Redirect to login ─────────────────────────────────────── */
header('Location: admin_login.php');
exit;