<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/audit.php';

$error = '';

$remember_cookie_name   = 'admin_remember_token';
$remember_duration_days = 30;
$remember_duration_secs = 86400 * $remember_duration_days;

// ---------------------------------------------------------------------------
// Rate limiting — DB-backed so it survives a session reset.
// ---------------------------------------------------------------------------
$MAX_LOGIN_ATTEMPTS = 3;
$LOGIN_WINDOW       = 900;

$rl_key = 'login_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rl = rate_limit_check($rl_key, $MAX_LOGIN_ATTEMPTS, $LOGIN_WINDOW, $conn);

if ($rl['locked']) {
    $mins  = max(1, (int)ceil($rl['window_left'] / 60));
    $error = "Too many login attempts. Please try again in {$mins} minute(s).";
}

/**
 * Remember-me cookie options.
 *
 * NOTE: SameSite=Lax is REQUIRED for remember-me to work reliably.
 * With Strict, the cookie is not sent when the browser is launched
 * fresh, restored from a previous session, or opened via bookmark —
 * which is exactly when remember-me is supposed to kick in.
 */
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

/** Clear the remember-me cookie + DB token for the current session. */
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

/* ---------- 1. Auto-login from Remember Me cookie ---------- */
if (!isset($_SESSION['admin_id']) && isset($_COOKIE[$remember_cookie_name]) && empty($error)) {
    $token = $_COOKIE[$remember_cookie_name];

    $stmt = $conn->prepare("
        SELECT id, fullname, username, role
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
            session_regenerate_id(true);

            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_name'] = $admin['fullname'];
            $_SESSION['admin_role'] = $admin['role'];
            $_SESSION['login_time'] = time();
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';

            // Rotate the token for extra safety
            $new_token   = bin2hex(random_bytes(32));
            $new_expires = date('Y-m-d H:i:s', time() + $remember_duration_secs);

            $upd = $conn->prepare("UPDATE admins SET remember_token = ?, remember_expires = ? WHERE id = ?");
            if ($upd) {
                $upd->bind_param('ssi', $new_token, $new_expires, $admin['id']);
                $upd->execute();
                $upd->close();
            }

            setcookie($remember_cookie_name, $new_token, $remember_cookie_opts(time() + $remember_duration_secs));

            audit_log($conn, 'admin.login_auto', ['type' => 'admin', 'id' => $admin['id']]);
            header('Location: dashboard.php');
            exit;
        }

        // Invalid or expired token — clear it
        $clearRememberCookie();
        $stmt->close();
    }
}

/* ---------- 2. Already logged in → bounce to dashboard ---------- */
if (isset($_SESSION['admin_id'])) {
    if (isset($_SESSION['user_agent'])
        && !hash_equals($_SESSION['user_agent'], $_SERVER['HTTP_USER_AGENT'] ?? '')) {
        // Clear remember cookie too — a UA change while logged in is suspicious.
        $clearRememberCookie();
        session_unset();
        session_destroy();
        header('Location: admin_login.php');
        exit;
    }
    header('Location: dashboard.php');
    exit;
}

/* ---------- 3. Process login form ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if ($username === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $conn->prepare("
            SELECT id, fullname, username, password, role
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
                    rate_limit_reset($rl_key, $conn);
                    session_regenerate_id(true);

                    $_SESSION['admin_id']   = $admin['id'];
                    $_SESSION['admin_name'] = $admin['fullname'];
                    $_SESSION['admin_role'] = $admin['role'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';

                    if (password_needs_rehash($admin['password'], PASSWORD_DEFAULT)) {
                        $new_hash = password_hash($password, PASSWORD_DEFAULT);
                        $rh = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
                        if ($rh) {
                            $rh->bind_param('si', $new_hash, $admin['id']);
                            $rh->execute();
                            $rh->close();
                        }
                    }

                    if ($remember) {
                        $tk = bin2hex(random_bytes(32));
                        $ex = date('Y-m-d H:i:s', time() + $remember_duration_secs);
                        $upd = $conn->prepare("UPDATE admins SET remember_token = ?, remember_expires = ? WHERE id = ?");
                        if ($upd) {
                            $upd->bind_param('ssi', $tk, $ex, $admin['id']);
                            $upd->execute();
                            $upd->close();
                        }
                        // SameSite=Lax is the key fix — lets the cookie survive
                        // fresh browser launches, bookmark clicks, etc.
                        setcookie($remember_cookie_name, $tk, $remember_cookie_opts(time() + $remember_duration_secs));
                    } elseif (isset($_COOKIE[$remember_cookie_name])) {
                        $clearRememberCookie();
                    }

                    audit_log($conn, 'admin.login', ['type' => 'admin', 'id' => $admin['id']]);
                    header('Location: dashboard.php');
                    exit;
                }

                rate_limit_hit($rl_key, $LOGIN_WINDOW, $conn);
                $rl = rate_limit_check($rl_key, $MAX_LOGIN_ATTEMPTS, $LOGIN_WINDOW, $conn);
                $error = $rl['remaining'] > 0
                    ? "Invalid password. {$rl['remaining']} attempt(s) remaining."
                    : 'Too many login attempts. Please try again in 15 minutes.';
                audit_log($conn, 'admin.login_failed', ['username' => $username]);
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

/* ---------- CSRF for the form ---------- */
$csrf_token = csrf_token();

if (isset($_GET['timeout'])) {
    $error = 'Your session has expired. Please login again.';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login • USAT College Sagay City</title>

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
            --accent-light: #fbbf24;
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --bg-glass: rgba(255, 255, 255, 0.98);
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.12);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.15);
            --shadow-xl: 0 20px 50px rgba(0,0,0,0.2);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        [data-theme="dark"] {
            --primary: #60a5fa;
            --primary-light: #93c5fd;
            --primary-dark: #3b82f6;
            --accent: #fcd34d;
            --accent-light: #fde68a;
            --bg-glass: rgba(30, 41, 59, 0.98);
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --border-color: #475569;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.4);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.5);
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
            -moz-osx-font-smoothing: grayscale;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
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

        .particles {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 2;
        }

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

        .login-container {
            width: 100%;
            max-width: 960px;
            position: relative;
            z-index: 10;
        }

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

        .login-card:hover {
            box-shadow: 0 25px 60px rgba(30, 58, 138, 0.6);
        }

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

        .logo-wrapper {
            position: relative;
            z-index: 1;
            margin-bottom: 2.5rem;
        }

        .logo {
            width: 160px;
            height: 160px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255,255,255,0.9);
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
            transition: var(--transition);
        }

        .logo:hover {
            transform: scale(1.05);
            box-shadow: 0 25px 70px rgba(0,0,0,0.5);
        }

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

        .form-header {
            margin-bottom: 2.5rem;
        }

        .form-header h2 {
            font-size: 2.1rem;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-weight: 700;
        }

        .form-header p {
            color: var(--text-secondary);
            font-size: 1.1rem;
        }

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
            to { opacity: 1; transform: translateY(0); }
        }

        .error i {
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .form-group {
            position: relative;
            margin-bottom: 2rem;
        }

        .form-group .icon {
            position: absolute;
            left: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 1.4rem;
            transition: var(--transition);
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

        [data-theme="dark"] .form-group input {
            background: #1e2937;
            border-color: #475569;
            color: #f1f5f9;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
        }

        .form-group input:focus + .icon {
            color: var(--primary);
        }

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

        [data-theme="dark"] .form-group label {
            background: #1e2937;
        }

        .form-group input:focus ~ label,
        .form-group input:not(:placeholder-shown) ~ label {
            top: -0.7rem;
            left: 1.2rem;
            font-size: 0.9rem;
            color: var(--primary);
            font-weight: 600;
        }

        .form-group input:focus ~ .icon {
            color: var(--primary);
        }

        .password-wrapper {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 1.4rem;
            transition: var(--transition);
            z-index: 2;
            background: none;
            border: none;
            padding: 0.5rem;
        }

        .toggle-password:hover {
            color: var(--primary);
        }

        .options {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            margin: 1.5rem 0 2.5rem;
            font-size: 1rem;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            user-select: none;
        }

        .remember-me input[type="checkbox"] {
            width: 1.2rem;
            height: 1.2rem;
            cursor: pointer;
            accent-color: var(--primary);
        }

        .remember-me label {
            cursor: pointer;
            color: var(--text-secondary);
            font-weight: 500;
        }

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

        .btn-login:hover::before {
            left: 100%;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(30, 58, 138, 0.5);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .student-link {
            margin-top: 2rem;
            text-align: center;
        }

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

        .student-link a:hover {
            background: rgba(59, 130, 246, 0.1);
            transform: translateX(5px);
        }

        .footer-note {
            text-align: center;
            margin-top: auto;
            padding-top: 2rem;
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

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

        .theme-toggle:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.1) rotate(15deg);
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .fa-spinner {
            animation: spin 1s linear infinite;
        }

        @media (max-width: 860px) {
            .login-card {
                flex-direction: column;
                min-height: auto;
            }
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
                <img src="../assets/img/usat.jpg" alt="USAT College Seal" class="logo"
                     onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22160%22 height=%22160%22><rect fill=%22%234a90d9%22 width=%22160%22 height=%22160%22 rx=%2280%22/><text fill=%22white%22 font-size=%2260%22 x=%2250%25%22 y=%2255%25%22 text-anchor=%22middle%22 dy=%22.3em%22>USAT</text></svg>'">
            </div>
            <h1>Admin Portal</h1>
            <div class="school-name">USAT COLLEGE Sagay City INC.</div>
            <p class="subtitle">Enrollment Profiling System</p>
        </div>

        <div class="right-side">
            <?php if ($error): ?>
                <div class="error" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <div class="form-header">
                <h2>Welcome Back</h2>
                <p>Sign in to access the admin dashboard</p>
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

            <div class="student-link">
                <a href="../enrollment/enroll_form.php">
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
(function() {
    const container = document.getElementById('particles');
    if (!container) return;

    for (let i = 0; i < 20; i++) {
        const particle = document.createElement('div');
        particle.className = 'particle';
        const size = Math.random() * 4 + 2;
        particle.style.width = size + 'px';
        particle.style.height = size + 'px';
        particle.style.left = Math.random() * 100 + '%';
        particle.style.top = Math.random() * 100 + '%';
        particle.style.animationDelay = Math.random() * 20 + 's';
        particle.style.animationDuration = (Math.random() * 10 + 15) + 's';
        container.appendChild(particle);
    }
})();

(function() {
    const html = document.documentElement;
    const toggleBtn = document.getElementById('themeToggle');
    const icon = toggleBtn.querySelector('i');

    const savedTheme = localStorage.getItem('admin_theme') || 'light';
    html.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme);

    toggleBtn.addEventListener('click', () => {
        const current = html.getAttribute('data-theme') || 'light';
        const newTheme = current === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('admin_theme', newTheme);
        updateThemeIcon(newTheme);
    });

    function updateThemeIcon(theme) {
        icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        toggleBtn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    }
})();

(function() {
    const passwordInput = document.getElementById('password');
    const toggleBtn = document.getElementById('togglePassword');
    const icon = toggleBtn.querySelector('i');

    toggleBtn.addEventListener('click', () => {
        const isPassword = passwordInput.type === 'password';
        passwordInput.type = isPassword ? 'text' : 'password';
        icon.className = isPassword ? 'fas fa-eye-slash' : 'fas fa-eye';
        toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        passwordInput.focus();
    });
})();

(function() {
    const form = document.getElementById('loginForm');
    const submitBtn = document.getElementById('submitBtn');
    const btnText = submitBtn.querySelector('span');
    const btnIcon = submitBtn.querySelector('i');

    form.addEventListener('submit', function(e) {
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value;

        if (!username || !password) {
            e.preventDefault();
            return;
        }

        submitBtn.disabled = true;
        btnIcon.className = 'fas fa-spinner fa-spin';
        btnText.textContent = 'Authenticating...';

        setTimeout(() => {
            if (submitBtn.disabled) {
                submitBtn.disabled = false;
                btnIcon.className = 'fas fa-sign-in-alt';
                btnText.textContent = 'Sign In';
            }
        }, 30000);
    });
})();

document.addEventListener('DOMContentLoaded', () => {
    const usernameInput = document.getElementById('username');
    if (usernameInput && !usernameInput.value) {
        usernameInput.focus();
    }
});

if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>

</body>
</html>