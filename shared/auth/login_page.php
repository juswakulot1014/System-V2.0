<?php
declare(strict_types=1);

/**
 * shared/auth/login_page.php
 */

if (!isset($LOGIN_ROLE, $LOGIN_REDIRECT)) {
    http_response_code(500);
    exit('Login page misconfigured — $LOGIN_ROLE and $LOGIN_REDIRECT must be set by the wrapper.');
}

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/audit.php';

/**
 * Strict 1:1 portal → role isolation.
 *
 *   /admin      → superadmin (system administrator) only
 *   /registrar  → registrar only
 *   /teacher    → teacher only
 *
 * Cross-portal fallbacks were removed on purpose: an account may only
 * authenticate through the portal that belongs to its role. If you ever
 * need a role to reach multiple portals (e.g. superadmin acting as a
 * registrar), add it explicitly here AND document the reason — do not
 * quietly broaden this again.
 */
function login_allowed_roles($base_roles): array
{
    $roles = is_array($base_roles) ? $base_roles : [$base_roles];

    // Defence-in-depth: hard-map the URL prefix to its canonical role,
    // and intersect with whatever the wrapper asked for. If the wrapper
    // is misconfigured, the intersection is empty and login fails closed.
    $portal_role = null;
    if (str_contains(URL_PREFIX, '/admin')) {
        $portal_role = 'superadmin';
    } elseif (str_contains(URL_PREFIX, '/registrar')) {
        $portal_role = 'registrar';
    } elseif (str_contains(URL_PREFIX, '/teacher')) {
        $portal_role = 'teacher';
    }

    if ($portal_role === null) {
        // Unknown prefix — fall back to the wrapper's value, do not broaden.
        return array_values(array_unique($roles));
    }

    return array_values(array_intersect($roles, [$portal_role]));
}

/**
 * Determine where to send a user based on their role.
 * Unknown roles fail closed to LOGIN_URL instead of the admin dashboard.
 */
function get_dashboard_url(string $role): string
{
    return match ($role) {
        'superadmin' => ASSET_BASE . '/admin/dashboard.php',
        'registrar'  => ASSET_BASE . '/registrar/dashboard.php',
        'teacher'    => ASSET_BASE . '/teacher/dashboard.php',
        default      => LOGIN_URL,
    };
}

$error = '';

$remember_cookie_name   = 'admin_remember_token';
$remember_duration_days = 30;
$remember_duration_secs = 86400 * $remember_duration_days;

/* ── Rate limiting ── */
$MAX_LOGIN_ATTEMPTS = 3;
$LOGIN_WINDOW       = 900;

$rl_key = 'login_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rl = rate_limit_check($rl_key, $MAX_LOGIN_ATTEMPTS, $LOGIN_WINDOW, $conn);

if ($rl['locked']) {
    $mins  = max(1, (int)ceil($rl['window_left'] / 60));
    $error = "Too many login attempts. Please try again in {$mins} minute(s).";
}

$remember_cookie_opts = function (int $expires) use ($remember_cookie_name) {
    return [
        'expires'  => $expires,
        'path'     => '/',
        'domain'   => '',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
};

$clearRememberCookie = function () use ($conn, $remember_cookie_name, $remember_cookie_opts) {
    if (!empty($_COOKIE[$remember_cookie_name])) {
        $t = $_COOKIE[$remember_cookie_name];
        $cl = $conn->prepare("UPDATE admins SET remember_token = NULL, remember_expires = NULL WHERE remember_token = ?");
        if ($cl) {
            $cl->bind_param('s', $t);
            $cl->execute();
            $cl->close();
        }
    }
    setcookie($remember_cookie_name, '', $remember_cookie_opts(time() - 3600));
    unset($_COOKIE[$remember_cookie_name]);
};

/* ═══════════════════════════════════════════════════════════════
   1. AUTO-LOGIN FROM REMEMBER COOKIE
   ═══════════════════════════════════════════════════════════════ */
if (!isset($_SESSION['admin_id']) && isset($_COOKIE[$remember_cookie_name]) && empty($error)) {

    $token = $_COOKIE[$remember_cookie_name];

    $stmt = $conn->prepare("
        SELECT admin_id, fullname, username, role
        FROM admins
        WHERE remember_token = ?
          AND remember_expires > NOW()
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 1) {
            $admin = $res->fetch_assoc();
            $stmt->close();

            $allowed_roles = login_allowed_roles($LOGIN_ROLE);

            if (!in_array($admin['role'], $allowed_roles, true)) {
                // Wrong portal for this account — do NOT create a session here.
                // Send them to the portal that matches their role instead.
                security_log('login.auto_role_mismatch', [
                    'role'     => $admin['role'],
                    'endpoint' => URL_PREFIX,
                    'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
                ]);
                $redirect = get_dashboard_url($admin['role']);
                header('Location: ' . $redirect);
                exit;
            }

            session_regenerate_id(true);

            $_SESSION['admin_id']   = $admin['admin_id'];
            $_SESSION['admin_name'] = $admin['fullname'];
            $_SESSION['admin_role'] = $admin['role'];
            $_SESSION['login_time'] = time();
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';

            $new_token   = bin2hex(random_bytes(32));
            $new_expires = date('Y-m-d H:i:s', time() + $remember_duration_secs);

            $upd = $conn->prepare("UPDATE admins SET remember_token = ?, remember_expires = ? WHERE admin_id = ?");
            if ($upd) {
                $upd->bind_param('ssi', $new_token, $new_expires, $admin['admin_id']);
                $upd->execute();
                $upd->close();
            }

            setcookie($remember_cookie_name, $new_token, $remember_cookie_opts(time() + $remember_duration_secs));

            audit_log($conn, 'admin.login_auto', ['type' => 'admin', 'id' => $admin['admin_id']]);

            header('Location: ' . get_dashboard_url($admin['role']));
            exit;
        }

        $clearRememberCookie();
        $stmt->close();
    }
}

/* ═══════════════════════════════════════════════════════════════
   2. ALREADY LOGGED IN — validate session, then redirect
   ═══════════════════════════════════════════════════════════════ */
if (isset($_SESSION['admin_id'])) {

    if (isset($_SESSION['user_agent'])
        && !hash_equals($_SESSION['user_agent'], $_SERVER['HTTP_USER_AGENT'] ?? '')) {
        $clearRememberCookie();
        session_unset();
        session_destroy();
        header('Location: ' . LOGIN_URL);
        exit;
    }

    $current_role  = $_SESSION['admin_role'] ?? '';
    $allowed_roles = login_allowed_roles($LOGIN_ROLE);

    if ($current_role === '' || !in_array($current_role, $allowed_roles, true)) {
        // Session's role isn't valid for this endpoint — could be a
        // stale/corrupted session. Don't redirect to a dashboard we can't
        // trust; kill it and bounce to login.
        $clearRememberCookie();
        session_unset();
        session_destroy();
        header('Location: ' . LOGIN_URL);
        exit;
    }

    header('Location: ' . get_dashboard_url($current_role));
    exit;
}

/* ═══════════════════════════════════════════════════════════════
   3. PROCESS LOGIN FORM
   ═══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {

    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if ($username === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $conn->prepare("
            SELECT admin_id, fullname, username, password, role
            FROM admins
            WHERE username = ?
            LIMIT 1
        ");

        if (!$stmt) {
            error_log('admin_login: prepare failed: ' . $conn->error);
            $error = 'System error. Please try again later.';
        } else {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res->num_rows === 1) {
                $admin = $res->fetch_assoc();

                if (password_verify($password, $admin['password'])) {

                    $allowed_roles = login_allowed_roles($LOGIN_ROLE);

                    if (!in_array($admin['role'], $allowed_roles, true)) {
                        rate_limit_hit($rl_key, $LOGIN_WINDOW, $conn);
                        audit_log($conn, 'admin.login_role_mismatch', [
                            'type'     => 'admin',
                            'id'       => $admin['admin_id'],
                            'role'     => $admin['role'],
                            'endpoint' => URL_PREFIX,
                        ]);
                        security_log('login.role_mismatch', [
                            'username' => $username,
                            'actual'   => $admin['role'],
                            'expected' => $LOGIN_ROLE,
                            'endpoint' => URL_PREFIX,
                            'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
                        ]);
                        $error = 'Invalid username or password.';
                    } else {
                        rate_limit_reset($rl_key, $conn);
                        session_regenerate_id(true);

                        $_SESSION['admin_id']   = $admin['admin_id'];
                        $_SESSION['admin_name'] = $admin['fullname'];
                        $_SESSION['admin_role'] = $admin['role'];
                        $_SESSION['login_time'] = time();
                        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';

                        if (password_needs_rehash($admin['password'], PASSWORD_DEFAULT)) {
                            $new_hash = password_hash($password, PASSWORD_DEFAULT);
                            $rh = $conn->prepare("UPDATE admins SET password = ? WHERE admin_id = ?");
                            if ($rh) {
                                $rh->bind_param('si', $new_hash, $admin['admin_id']);
                                $rh->execute();
                                $rh->close();
                            }
                        }

                        if ($remember) {
                            $tk = bin2hex(random_bytes(32));
                            $ex = date('Y-m-d H:i:s', time() + $remember_duration_secs);
                            $upd = $conn->prepare("UPDATE admins SET remember_token = ?, remember_expires = ? WHERE admin_id = ?");
                            if ($upd) {
                                $upd->bind_param('ssi', $tk, $ex, $admin['admin_id']);
                                $upd->execute();
                                $upd->close();
                            }
                            setcookie($remember_cookie_name, $tk, $remember_cookie_opts(time() + $remember_duration_secs));
                        } elseif (isset($_COOKIE[$remember_cookie_name])) {
                            $clearRememberCookie();
                        }

                        audit_log($conn, 'admin.login', ['type' => 'admin', 'id' => $admin['admin_id']]);
                        $stmt->close();

                        header('Location: ' . get_dashboard_url($admin['role']));
                        exit;
                    }
                } else {
                    rate_limit_hit($rl_key, $LOGIN_WINDOW, $conn);
                    $rl = rate_limit_check($rl_key, $MAX_LOGIN_ATTEMPTS, $LOGIN_WINDOW, $conn);
                    $error = $rl['remaining'] > 0
                        ? "Invalid password. {$rl['remaining']} attempt(s) remaining."
                        : 'Too many login attempts. Please try again in 15 minutes.';
                    audit_log($conn, 'admin.login_failed', ['username' => $username]);
                }
            } else {
                rate_limit_hit($rl_key, $LOGIN_WINDOW, $conn);
                $rl = rate_limit_check($rl_key, $MAX_LOGIN_ATTEMPTS, $LOGIN_WINDOW, $conn);
                $error = $rl['remaining'] > 0
                    ? "No account found with that username. {$rl['remaining']} attempt(s) remaining."
                    : 'Too many login attempts. Please try again in 15 minutes.';
                audit_log($conn, 'admin.login_failed', ['username' => $username]);
            }

            $stmt->close();
        }
    }
}

$csrf_token = csrf_token();

if (isset($_GET['timeout'])) {
    $error = 'Your session has expired. Please login again.';
}

$__is_admin_endpoint     = str_contains(URL_PREFIX, '/admin');
$__is_registrar_endpoint = str_contains(URL_PREFIX, '/registrar');
$__is_teacher_endpoint   = str_contains(URL_PREFIX, '/teacher');

$__portal_name = 'Admin';
if ($__is_registrar_endpoint) $__portal_name = 'Registrar';
if ($__is_teacher_endpoint)   $__portal_name = 'Teacher';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $__portal_name ?> Login • USAT College Sagay City</title>

    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #1e40af;
            --primary-light: #3b82f6;
            --primary-dark: #1e3a8a;
            --accent: #d4af37;
            --success: #10b981;
            --error: #ef4444;
            --bg-glass: rgba(255, 255, 255, 0.98);
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --shadow-xl: 0 20px 50px rgba(0,0,0,0.2);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        [data-theme="dark"] {
            --primary: #60a5fa;
            --primary-light: #93c5fd;
            --primary-dark: #3b82f6;
            --accent: #fcd34d;
            --bg-glass: rgba(30, 41, 59, 0.98);
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --border-color: #475569;
            --shadow-xl: 0 20px 50px rgba(0,0,0,0.6);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 40%, #1e40af 100%);
            background-size: 400% 400%;
            animation: gradientShift 35s ease infinite;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            position: relative;
            overflow-y: auto;
            -webkit-font-smoothing: antialiased;
        }

        @keyframes gradientShift {
            0%   { background-position: 0% 50%; }
            50%  { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(circle at 20% 30%, rgba(255,255,255,0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(212,175,55,0.12) 0%, transparent 50%),
                radial-gradient(circle at 40% 80%, rgba(147,197,253,0.1) 0%, transparent 60%);
            pointer-events: none;
            z-index: 1;
        }

        .particles { position: fixed; inset: 0; pointer-events: none; z-index: 2; }
        .particle {
            position: absolute;
            background: rgba(255,255,255,0.3);
            border-radius: 50%;
            animation: float 20s infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { transform: translateY(-100vh) rotate(720deg); opacity: 0; }
        }

        .login-container { width: 100%; max-width: 960px; position: relative; z-index: 10; }

        .login-card {
            display: flex;
            background: var(--bg-glass);
            backdrop-filter: blur(32px);
            -webkit-backdrop-filter: blur(32px);
            border-radius: 28px;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            min-height: 540px;
            border: 1px solid rgba(255,255,255,0.6);
            transition: var(--transition);
        }

        .login-card:hover { box-shadow: 0 25px 60px rgba(30, 58, 138, 0.6); }

        .left-side {
            flex: 1;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            padding: 4rem 3rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .left-side::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.3; }
            50% { transform: scale(1.1); opacity: 0.5; }
        }

        .logo-wrapper { position: relative; z-index: 1; margin-bottom: 2.5rem; }
        .logo {
            width: 160px;
            height: 160px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255,255,255,0.9);
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
            transition: var(--transition);
        }
        .logo:hover { transform: scale(1.05); }

        .left-side h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2.8rem;
            font-weight: 800;
            margin-bottom: 0.8rem;
            letter-spacing: -1.5px;
            position: relative;
            z-index: 1;
        }

        .left-side .school-name {
            font-size: 1.65rem;
            font-weight: 700;
            letter-spacing: 3px;
            color: var(--accent);
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
            text-transform: uppercase;
        }

        .left-side .subtitle {
            font-size: 1.25rem;
            opacity: 0.95;
            position: relative;
            z-index: 1;
        }

        .right-side {
            flex: 1.15;
            padding: 4rem 4.5rem;
            display: flex;
            flex-direction: column;
            background: var(--bg-glass);
        }

        .portal-switch {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            background: rgba(15, 23, 42, 0.04);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 4px;
            margin-bottom: 1.75rem;
            gap: 2px;
            width: fit-content;
        }

        [data-theme="dark"] .portal-switch {
            background: rgba(255, 255, 255, 0.04);
        }

        .portal-switch-item {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.55rem 0.9rem;
            border-radius: 9px;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-decoration: none;
            transition: background 0.2s ease, color 0.2s ease;
            font-family: 'Inter', sans-serif;
            line-height: 1;
            white-space: nowrap;
        }

        .portal-switch-item:not(.active):hover {
            background: rgba(30, 64, 175, 0.08);
            color: var(--primary);
        }

        [data-theme="dark"] .portal-switch-item:not(.active):hover {
            background: rgba(96, 165, 250, 0.12);
        }

        .portal-switch-item.active {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 2px 8px rgba(30, 64, 175, 0.25);
            cursor: default;
        }

        .portal-switch-item i { font-size: 0.85rem; }

        .form-header { margin-bottom: 2.5rem; }
        .form-header h2 { font-size: 2.1rem; color: var(--text-primary); margin-bottom: 0.5rem; font-weight: 700; }
        .form-header p  { color: var(--text-secondary); font-size: 1.1rem; }

        .error {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            color: #991b1b;
            padding: 1.2rem 1.5rem;
            border-radius: 14px;
            margin-bottom: 2rem;
            text-align: center;
            font-size: 1rem;
            font-weight: 500;
            border-left: 4px solid var(--error);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .error i { font-size: 1.25rem; flex-shrink: 0; }

        .form-group { position: relative; margin-bottom: 2rem; }
        .form-group .icon {
            position: absolute;
            left: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 1.4rem;
            z-index: 2;
        }

        .form-group input {
            width: 100%;
            padding: 1.4rem 1.5rem 1.4rem 4.5rem;
            border: 2px solid var(--border-color);
            border-radius: 14px;
            font-size: 1.1rem;
            background: white;
            color: var(--text-primary);
            transition: var(--transition);
            font-family: 'Inter', sans-serif;
        }
        [data-theme="dark"] .form-group input { background: #1e2937; border-color: #475569; color: #f1f5f9; }
        .form-group input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15); }
        .form-group input:focus + .icon { color: var(--primary); }

        .form-group label {
            position: absolute;
            left: 4.5rem;
            top: 1.4rem;
            color: var(--text-secondary);
            font-size: 1.1rem;
            pointer-events: none;
            transition: var(--transition);
            background: white;
            padding: 0 8px;
            z-index: 1;
        }
        [data-theme="dark"] .form-group label { background: #1e2937; }

        .form-group input:focus ~ label,
        .form-group input:not(:placeholder-shown) ~ label {
            top: -0.7rem;
            left: 1.2rem;
            font-size: 0.9rem;
            color: var(--primary);
            font-weight: 600;
        }

        .toggle-password {
            position: absolute;
            right: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 1.4rem;
            z-index: 2;
            background: none;
            border: none;
            padding: 0.5rem;
        }
        .toggle-password:hover { color: var(--primary); }

        .options { display: flex; justify-content: flex-start; align-items: center; margin: 1.5rem 0 2.5rem; font-size: 1rem; }
        .remember-me { display: flex; align-items: center; gap: 0.75rem; cursor: pointer; user-select: none; }
        .remember-me input[type="checkbox"] { width: 1.2rem; height: 1.2rem; cursor: pointer; accent-color: var(--primary); }
        .remember-me label { cursor: pointer; color: var(--text-secondary); font-weight: 500; }

        .btn-login {
            width: 100%;
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 1.2rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 8px 25px rgba(30, 58, 138, 0.4);
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
        }
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        .btn-login:hover::before { left: 100%; }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 12px 35px rgba(30, 58, 138, 0.5); }
        .btn-login:active { transform: translateY(0); }
        .btn-login:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }

        .live-stats {
            display: flex;
            align-items: stretch;
            background: rgba(15, 23, 42, 0.03);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 4px;
            margin-top: 1.5rem;
            position: relative;
            transition: opacity 0.3s ease;
        }

        [data-theme="dark"] .live-stats {
            background: rgba(255, 255, 255, 0.03);
        }

        .live-stat {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: .7rem .5rem;
            gap: .2rem;
            position: relative;
        }

        .live-stat + .live-stat::before {
            content: '';
            position: absolute;
            left: 0;
            top: 25%;
            bottom: 25%;
            width: 1px;
            background: var(--border-color);
        }

        .live-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-primary);
            font-variant-numeric: tabular-nums;
            line-height: 1;
            letter-spacing: -.02em;
        }

        .live-label {
            font-size: .62rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: .6px;
            display: inline-flex;
            align-items: center;
            gap: .35rem;
        }

        .live-dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #10b981;
            animation: live-pulse 2s infinite;
        }

        @keyframes live-pulse {
            0%   { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70%  { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .student-link { margin-top: 2rem; text-align: center; }
        .student-link a {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 1.05rem;
            transition: var(--transition);
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
        }
        .student-link a:hover { background: rgba(59, 130, 246, 0.1); transform: translateX(5px); }

        .footer-note { text-align: center; margin-top: auto; padding-top: 2rem; color: var(--text-secondary); font-size: 0.95rem; }

        .theme-toggle {
            position: fixed;
            top: 2rem;
            right: 2rem;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255,255,255,0.3);
            width: 48px;
            height: 48px;
            border-radius: 50%;
            color: white;
            font-size: 1.4rem;
            cursor: pointer;
            z-index: 100;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .theme-toggle:hover { background: rgba(255,255,255,0.3); transform: scale(1.1) rotate(15deg); }

        .fa-spinner { animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 860px) {
            .login-card { flex-direction: column; min-height: auto; }
            .left-side { padding: 3rem 2rem 2rem; }
            .right-side { padding: 2.5rem 2rem; }
            .logo { width: 120px; height: 120px; }
            .left-side h1 { font-size: 2.2rem; }
            .form-header h2 { font-size: 1.8rem; }
        }
        @media (max-width: 480px) {
            body { padding: 1rem 0.5rem; }
            .left-side { padding: 2rem 1.5rem 1.5rem; }
            .right-side { padding: 2rem 1.5rem; }
            .options { flex-direction: column; gap: 1rem; align-items: flex-start; }
            .portal-switch { width: 100%; justify-content: center; }
            .portal-switch-item { flex: 1; justify-content: center; font-size: 0.75rem; padding: 0.55rem 0.5rem; }
            .live-value { font-size: 1rem; }
            .live-label { font-size: .55rem; }
        }
    </style>
</head>
<body>

<div class="particles" id="particles"></div>

<button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
    <i class="fas fa-moon"></i>
</button>

<div class="login-container">
    <div class="login-card">

        <div class="left-side">
            <div class="logo-wrapper">
                <img src="<?= ASSET_BASE ?>/assets/img/usat.jpg" alt="USAT College Seal" class="logo"
                     onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22160%22 height=%22160%22><rect fill=%22%234a90d9%22 width=%22160%22 height=%22160%22 rx=%2280%22/><text fill=%22white%22 font-size=%2260%22 x=%2250%25%22 y=%2255%25%22 text-anchor=%22middle%22 dy=%22.3em%22>USAT</text></svg>'">
            </div>
            <h1><?= $__portal_name ?> Portal</h1>
            <div class="school-name">USAT COLLEGE Sagay City INC.</div>
            <p class="subtitle">Enrollment Profiling System</p>
        </div>

        <div class="right-side">

            <nav class="portal-switch" aria-label="Portal selection">

                <?php if ($__is_admin_endpoint): ?>
                    <span class="portal-switch-item active" aria-current="page">
                        <i class="fas fa-shield-alt" aria-hidden="true"></i> Admin
                    </span>
                <?php else: ?>
                    <a href="<?= ASSET_BASE ?>/admin/admin_login.php" class="portal-switch-item">
                        <i class="fas fa-shield-alt" aria-hidden="true"></i> Admin
                    </a>
                <?php endif; ?>

                <?php if ($__is_registrar_endpoint): ?>
                    <span class="portal-switch-item active" aria-current="page">
                        <i class="fas fa-user-tie" aria-hidden="true"></i> Registrar
                    </span>
                <?php else: ?>
                    <a href="<?= ASSET_BASE ?>/registrar/admin_login.php" class="portal-switch-item">
                        <i class="fas fa-user-tie" aria-hidden="true"></i> Registrar
                    </a>
                <?php endif; ?>

                <?php if ($__is_teacher_endpoint): ?>
                    <span class="portal-switch-item active" aria-current="page">
                        <i class="fas fa-book" aria-hidden="true"></i> Teacher
                    </span>
                <?php else: ?>
                    <a href="<?= ASSET_BASE ?>/teacher/admin_login.php" class="portal-switch-item">
                        <i class="fas fa-book" aria-hidden="true"></i> Teacher
                    </a>
                <?php endif; ?>

            </nav>

            <?php if ($error): ?>
                <div class="error" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <div class="form-header">
                <h2>Welcome Back</h2>
                <p>Sign in to access the <?= strtolower($__portal_name) ?> dashboard</p>
            </div>

            <form method="POST" id="loginForm" autocomplete="off" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-group">
                    <i class="fas fa-user icon"></i>
                    <input type="text"
                           id="username"
                           name="username"
                           placeholder=" "
                           required
                           autofocus
                           autocomplete="username"
                           maxlength="50"
                           aria-label="Username or Email">
                    <label for="username">Username or Email</label>
                </div>

                <div class="form-group password-wrapper">
                    <i class="fas fa-lock icon"></i>
                    <input type="password"
                           id="password"
                           name="password"
                           placeholder=" "
                           required
                           autocomplete="current-password"
                           minlength="6"
                           aria-label="Password">
                    <label for="password">Password</label>
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Toggle password visibility">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>

                <div class="options">
                    <label class="remember-me">
                        <input type="checkbox" id="remember" name="remember">
                        <span>Remember me</span>
                    </label>
                </div>

                <button type="submit" class="btn-login" id="submitBtn">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Sign In</span>
                </button>
            </form>

            <div class="live-stats" id="liveStats" role="status" aria-live="polite" style="display:none">
                <div class="live-stat">
                    <span class="live-value" data-stat="students">—</span>
                    <span class="live-label">
                        <span class="live-dot" aria-hidden="true"></span>
                        Students
                    </span>
                </div>
                <div class="live-stat">
                    <span class="live-value" data-stat="pending">—</span>
                    <span class="live-label">Pending</span>
                </div>
                <div class="live-stat">
                    <span class="live-value" data-stat="sections">—</span>
                    <span class="live-label">Sections</span>
                </div>
            </div>

            <div class="student-link">
                <a href="<?= ASSET_BASE ?>/enrollment/enroll_form.php">
                    <i class="fas fa-user-graduate"></i>
                    <span>Student Enrollment Portal</span>
                    <i class="fas fa-arrow-right" style="font-size: 0.9rem;"></i>
                </a>
            </div>

            <div class="footer-note">
                © <?= date('Y') ?> USAT College Sagay City Inc. • All Rights Reserved
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const container = document.getElementById('particles');
    if (!container) return;
    for (let i = 0; i < 20; i++) {
        const p = document.createElement('div');
        p.className = 'particle';
        const size = Math.random() * 4 + 2;
        p.style.width = size + 'px';
        p.style.height = size + 'px';
        p.style.left = Math.random() * 100 + '%';
        p.style.top = Math.random() * 100 + '%';
        p.style.animationDelay = Math.random() * 20 + 's';
        p.style.animationDuration = (Math.random() * 10 + 15) + 's';
        container.appendChild(p);
    }
})();

(function () {
    const html = document.documentElement;
    const btn = document.getElementById('themeToggle');
    const icon = btn.querySelector('i');
    const saved = localStorage.getItem('admin_theme') || 'light';
    html.setAttribute('data-theme', saved);
    updateIcon(saved);
    btn.addEventListener('click', () => {
        const cur = html.getAttribute('data-theme') || 'light';
        const next = cur === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        localStorage.setItem('admin_theme', next);
        updateIcon(next);
    });
    function updateIcon(t) { icon.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon'; }
})();

(function () {
    const pwd = document.getElementById('password');
    const btn = document.getElementById('togglePassword');
    const icon = btn.querySelector('i');
    btn.addEventListener('click', () => {
        const isPwd = pwd.type === 'password';
        pwd.type = isPwd ? 'text' : 'password';
        icon.className = isPwd ? 'fas fa-eye-slash' : 'fas fa-eye';
        pwd.focus();
    });
})();

(function () {
    const form = document.getElementById('loginForm');
    const btn = document.getElementById('submitBtn');
    const text = btn.querySelector('span');
    const icon = btn.querySelector('i');
    form.addEventListener('submit', function (e) {
        const u = document.getElementById('username').value.trim();
        const p = document.getElementById('password').value;
        if (!u || !p) { e.preventDefault(); return; }
        btn.disabled = true;
        icon.className = 'fas fa-spinner fa-spin';
        text.textContent = 'Authenticating...';
        setTimeout(() => {
            if (btn.disabled) {
                btn.disabled = false;
                icon.className = 'fas fa-sign-in-alt';
                text.textContent = 'Sign In';
            }
        }, 30000);
    });
})();

(function () {
    const statsEl = document.getElementById('liveStats');
    if (!statsEl) return;

    const statEls = {
        students: statsEl.querySelector('[data-stat="students"]'),
        pending:  statsEl.querySelector('[data-stat="pending"]'),
        sections: statsEl.querySelector('[data-stat="sections"]'),
    };
    const STATS_URL = '<?= URL_PREFIX ?>/public_stats.php';
    const POLL_MS   = 30000;

    let lastData  = { students: null, pending: null, sections: null };
    let pollTimer = null;
    let hasShown  = false;

    function animate(el, from, to) {
        if (from === null || isNaN(from) || from === to) {
            el.textContent = Number(to).toLocaleString();
            return;
        }
        const start = performance.now();
        const duration = 600;
        function step(now) {
            const t = Math.min(1, (now - start) / duration);
            const eased = 1 - Math.pow(1 - t, 3);
            const val = Math.round(from + (to - from) * eased);
            el.textContent = val.toLocaleString();
            if (t < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    async function fetchStats() {
        try {
            const res = await fetch(STATS_URL, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
            });
            if (!res.ok) return;
            const data = await res.json();
            if (typeof data.students !== 'number') return;

            const total = (data.students || 0) + (data.pending || 0) + (data.sections || 0);
            if (total === 0 && !hasShown) {
                statsEl.style.display = 'none';
                return;
            }
            statsEl.style.display = '';
            hasShown = true;

            Object.keys(statEls).forEach(function (key) {
                const el = statEls[key];
                if (!el) return;
                const newVal = Number(data[key] ?? 0);
                if (lastData[key] === newVal) return;
                animate(el, lastData[key], newVal);
                lastData[key] = newVal;
            });
        } catch (e) {
            /* silent — stats are cosmetic, login form must not be blocked */
        }
    }

    function startPolling() {
        if (pollTimer) return;
        fetchStats();
        pollTimer = setInterval(fetchStats, POLL_MS);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) stopPolling();
        else startPolling();
    });

    setTimeout(startPolling, 400);
})();

document.addEventListener('DOMContentLoaded', () => {
    const u = document.getElementById('username');
    if (u && !u.value) u.focus();
});

if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>

</body>
</html>