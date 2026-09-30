<?php
$REQUIRE_ROLE = 'superadmin';          // must be set BEFORE auth.php runs
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/audit.php';

$error   = "";
$success = "";

// ============================================
// AJAX: live username existence check
// ============================================
if (($_GET['action'] ?? '') === 'check_username') {
    header('Content-Type: application/json; charset=utf-8');
    verify_csrf();
    $un = trim($_POST['username'] ?? '');
    if ($un === '') {
        echo json_encode(['exists' => false, 'valid' => false]);
        exit;
    }
    $chk = $conn->prepare("SELECT id FROM admins WHERE username = ? LIMIT 1");
    if (!$chk) {
        echo json_encode(['exists' => false, 'valid' => true, 'error' => 'db']);
        exit;
    }
    $chk->bind_param("s", $un);
    $chk->execute();
    $exists = $chk->get_result()->num_rows === 1;
    $chk->close();
    echo json_encode(['exists' => $exists, 'valid' => true]);
    exit;
}

// ============================================
// Rate limiting — 3 attempts / 15 minutes
// ============================================
$max_attempts   = 3;
$lockout_time   = 900;
$rate_limit_key = 'pwd_change_attempts_' . $_SESSION['admin_id'];

if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
}
if ($_SESSION[$rate_limit_key]['count'] >= $max_attempts) {
    $since = time() - $_SESSION[$rate_limit_key]['last_attempt'];
    if ($since >= $lockout_time) {
        $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
    }
}

// ============================================
// POST handling
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $is_locked = (
        $_SESSION[$rate_limit_key]['count'] >= $max_attempts &&
        (time() - $_SESSION[$rate_limit_key]['last_attempt']) < $lockout_time
    );

    if ($is_locked) {
        $since     = time() - $_SESSION[$rate_limit_key]['last_attempt'];
        $remaining = ceil(($lockout_time - $since) / 60);
        $error = "Too many attempts. Please try again in {$remaining} minute(s).";
    }

    // Step 1: verify acting admin's password
    if (empty($error)) {
        $admin_password = $_POST['admin_password'] ?? '';
        if ($admin_password === '') {
            $error = "Please enter your own admin password for verification.";
        } else {
            $verify_stmt = $conn->prepare("SELECT password FROM admins WHERE id = ? LIMIT 1");
            if (!$verify_stmt) {
                $error = "System error. Please try again later.";
            } else {
                $verify_stmt->bind_param("i", $_SESSION['admin_id']);
                $verify_stmt->execute();
                $verify_result = $verify_stmt->get_result();
                if ($verify_result->num_rows !== 1) {
                    $error = "Your session is no longer valid. Please log out and log in again.";
                } else {
                    $admin_data = $verify_result->fetch_assoc();
                    if (!password_verify($admin_password, $admin_data['password'])) {
                        $error = "Your admin password is incorrect. Action denied.";
                        $_SESSION[$rate_limit_key]['count']++;
                        $_SESSION[$rate_limit_key]['last_attempt'] = time();
                        audit_log($conn, 'password.change_denied', [
                            'type' => 'admin',
                            'id'   => (int)$_SESSION['admin_id'],
                        ]);
                    }
                }
                $verify_stmt->close();
            }
        }
    }

    // Step 2: validate new password
    if (empty($error)) {
        $target_username  = trim($_POST['username'] ?? '');
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($target_username === '' || $new_password === '' || $confirm_password === '') {
            $error = "Please fill in all fields.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error = "New password must be at least 8 characters long.";
        } elseif (strlen($new_password) > 128) {
            $error = "Password must not exceed 128 characters.";
        } elseif (!preg_match('/[A-Z]/', $new_password)) {
            $error = "Password must contain at least one uppercase letter.";
        } elseif (!preg_match('/[a-z]/', $new_password)) {
            $error = "Password must contain at least one lowercase letter.";
        } elseif (!preg_match('/[0-9]/', $new_password)) {
            $error = "Password must contain at least one number.";
        } elseif (!preg_match('/[^A-Za-z0-9]/', $new_password)) {
            $error = "Password must contain at least one special character.";
        }
    }

    // Step 3: look up target and update
    if (empty($error)) {
        $target_username = trim($_POST['username'] ?? '');

        $stmt = $conn->prepare("SELECT id, fullname, username, role FROM admins WHERE username = ? LIMIT 1");
        if (!$stmt) {
            $error = "System error. Please try again later.";
        } else {
            $stmt->bind_param("s", $target_username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows !== 1) {
                $error = "Username \"" . htmlspecialchars($target_username, ENT_QUOTES, 'UTF-8') . "\" does not exist.";
            } else {
                $user    = $result->fetch_assoc();
                $is_self = ((int)$user['id'] === (int)$_SESSION['admin_id']);

                // Guard: superadmin cannot reset ANOTHER superadmin's password
                // (but CAN change their own — that's the new fix)
                if ($user['role'] === 'superadmin' && !$is_self) {
                    $error = "You cannot change another superadmin's password.";
                }
                // Guard: self-change must differ from current
                elseif ($is_self && password_verify($new_password, $admin_data['password'])) {
                    $error = "Your new password must be different from your current password.";
                }
                else {
                    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);

                    $upd = $conn->prepare(
                        "UPDATE admins
                            SET password         = ?,
                                reset_token      = NULL,
                                reset_expires    = NULL,
                                remember_token   = NULL,
                                remember_expires = NULL
                          WHERE id = ?"
                    );
                    if (!$upd) {
                        error_log('Password change prepare failed: ' . $conn->error);
                        $error = "System error. Please try again later.";
                    } else {
                        $upd->bind_param("si", $hashed_password, $user['id']);
                        if (!$upd->execute()) {
                            error_log('Password change execute failed: ' . $upd->error);
                            $error = "System error. Please try again later.";
                        } else {
                            $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
                            $_SESSION['csrf_token']    = bin2hex(random_bytes(32));

                            if ($is_self) {
                                audit_log($conn, 'password.self_change', [
                                    'type' => 'admin',
                                    'id'   => $user['id'],
                                ]);
                                $success = "Your password has been updated successfully.";
                            } else {
                                audit_log($conn, 'password.change', [
                                    'type'     => 'admin',
                                    'id'       => $user['id'],
                                    'username' => $target_username,
                                ]);
                                $success = "Password successfully updated for user: "
                                         . htmlspecialchars($target_username, ENT_QUOTES, 'UTF-8');
                            }

                            unset($_POST['username'], $_POST['new_password'], $_POST['confirm_password']);
                        }
                        $upd->close();
                    }
                }
            }
            $stmt->close();
        }
    }
}

// ============================================
// View data
// ============================================
$csrf_token    = csrf_token();
$admin_name    = htmlspecialchars($_SESSION['admin_name'] ?? 'Administrator', ENT_QUOTES, 'UTF-8');

$attempts_used = (int)$_SESSION[$rate_limit_key]['count'];
$attempts_left = max(0, $max_attempts - $attempts_used);
$locked_until  = 0;
if ($attempts_used >= $max_attempts) {
    $since = time() - $_SESSION[$rate_limit_key]['last_attempt'];
    if ($since < $lockout_time) $locked_until = ceil(($lockout_time - $since) / 60);
}

$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change User Password • USAT Admin</title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root {
            --bg: #f4f6fb;
            --surface: #ffffff;
            --surface-2: #f8fafc;
            --text: #0f172a;
            --text-2: #64748b;
            --text-3: #94a3b8;
            --border: #e5e7eb;
            --border-strong: #d1d5db;
            --accent: #4f46e5;
            --accent-2: #6366f1;
            --accent-soft: #eef2ff;
            --green: #059669;
            --green-soft: #d1fae5;
            --red: #dc2626;
            --red-soft: #fee2e2;
            --amber: #d97706;
            --amber-soft: #fef3c7;
            --purple: #7c3aed;
            --purple-soft: #ede9fe;

            --shadow-xs: 0 1px 2px rgba(15,23,42,0.04);
            --shadow-sm: 0 1px 3px rgba(15,23,42,0.06), 0 1px 2px rgba(15,23,42,0.04);
            --shadow-md: 0 4px 12px rgba(15,23,42,0.06);
            --shadow-lg: 0 12px 32px rgba(15,23,42,0.10);

            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;

            --sidebar-w: 264px;
        }

        [data-bs-theme="dark"] {
            --bg: #0b1220;
            --surface: #131c2e;
            --surface-2: #1a2439;
            --text: #f1f5f9;
            --text-2: #94a3b8;
            --text-3: #64748b;
            --border: #1f2a44;
            --border-strong: #2d3b57;
            --accent: #818cf8;
            --accent-2: #6366f1;
            --accent-soft: #1e2140;
            --green-soft: #052e24;
            --red-soft: #3f1517;
            --amber-soft: #3d2a08;
            --purple-soft: #2e1a54;

            --shadow-xs: 0 1px 2px rgba(0,0,0,0.3);
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.35);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 12px 32px rgba(0,0,0,0.55);
        }

        *{margin:0;padding:0;box-sizing:border-box}
        html,body{height:100%}
        body{
            font-family:'Inter',system-ui,-apple-system,sans-serif;
            background:var(--bg);color:var(--text);min-height:100vh;
            -webkit-font-smoothing:antialiased;
        }

        .sidebar{
            position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-w);
            background:var(--surface);border-right:1px solid var(--border);
            z-index:200;display:flex;flex-direction:column;
            transition:transform .28s cubic-bezier(.4,0,.2,1);
        }
        .sidebar-brand{
            padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);
            display:flex;align-items:center;gap:.75rem;
        }
        .sidebar-brand img{
            width:38px;height:38px;border-radius:10px;object-fit:cover;
            box-shadow:0 0 0 3px var(--accent-soft);
        }
        .sidebar-brand .brand-text{display:flex;flex-direction:column;line-height:1.15}
        .sidebar-brand .brand-name{font-weight:700;font-size:.95rem;color:var(--text)}
        .sidebar-brand .brand-sub{font-size:.68rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;font-weight:600}

        .sidebar-nav{flex:1;padding:.75rem .75rem 1rem;overflow-y:auto}
        .sidebar-nav .nav-label{
            font-size:.65rem;font-weight:700;color:var(--text-3);
            text-transform:uppercase;letter-spacing:.8px;
            padding:.75rem .75rem .35rem;
        }
        .sidebar-nav a{
            display:flex;align-items:center;gap:.75rem;
            padding:.6rem .75rem;border-radius:10px;
            color:var(--text-2);text-decoration:none;
            font-weight:500;font-size:.875rem;
            transition:background .15s, color .15s;
            margin-bottom:.1rem;
        }
        .sidebar-nav a:hover{background:var(--surface-2);color:var(--text)}
        .sidebar-nav a.active{background:var(--accent);color:#fff;box-shadow:0 4px 12px -4px rgba(79,70,229,.5)}
        .sidebar-nav a.active:hover{background:var(--accent)}
        .sidebar-nav a i{font-size:1.05rem;width:20px;text-align:center;flex-shrink:0}
        .sidebar-footer{padding:.75rem;border-top:1px solid var(--border)}

        .main-content{
            margin-left:var(--sidebar-w);
            padding:1.5rem clamp(1rem,2.5vw,2rem) 2rem;
            min-height:100vh;
            max-width:1600px;
        }
        .topbar{
            display:flex;align-items:center;justify-content:space-between;
            margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap;
        }
        .topbar h2{font-size:1.35rem;font-weight:700;letter-spacing:-.02em;margin:0}
        .topbar .sub{font-size:.85rem;color:var(--text-2);margin:0}
        .menu-toggle{
            display:none;width:40px;height:40px;border-radius:10px;
            border:1px solid var(--border);background:var(--surface);
            color:var(--text);cursor:pointer;
            align-items:center;justify-content:center;
            transition:background .15s;
        }
        .menu-toggle:hover{background:var(--surface-2)}
        .breadcrumb-nav{
            display:flex;align-items:center;gap:.4rem;
            font-size:.78rem;color:var(--text-2);
            margin-bottom:.25rem;
        }
        .breadcrumb-nav a{color:var(--accent);text-decoration:none;font-weight:500}
        .breadcrumb-nav a:hover{text-decoration:underline}

        .theme-btn{
            width:38px;height:38px;border-radius:10px;
            border:1px solid var(--border);background:var(--surface);
            color:var(--text-2);cursor:pointer;
            display:flex;align-items:center;justify-content:center;
            transition:all .15s;
        }
        .theme-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}

        .btn{font-weight:500;border-radius:9px;font-size:.82rem;transition:all .15s}
        .btn-outline-secondary{color:var(--text-2);border-color:var(--border)}
        .btn-outline-secondary:hover{background:var(--surface-2);color:var(--text);border-color:var(--border-strong)}
        .btn-outline-danger{color:var(--red);border-color:color-mix(in srgb, var(--red) 35%, transparent)}
        .btn-outline-danger:hover{background:var(--red);color:#fff;border-color:var(--red)}

        .cw-wrap{
            max-width:640px;margin:0 auto;
            animation:cw-fade .25s ease;
        }
        @keyframes cw-fade{
            from{opacity:0;transform:translateY(6px)}
            to{opacity:1;transform:none}
        }

        .cw-card{
            background:var(--surface);border:1px solid var(--border);
            border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);
            overflow:hidden;
        }
        .cw-card-head{
            display:flex;align-items:center;gap:.85rem;
            padding:1.15rem 1.5rem;
            border-bottom:1px solid var(--border);
            background:linear-gradient(180deg,var(--surface),var(--surface-2));
        }
        [data-bs-theme="dark"] .cw-card-head{
            background:linear-gradient(180deg,var(--surface),var(--surface-2));
        }
        .cw-card-head-icon{
            width:42px;height:42px;border-radius:11px;
            background:linear-gradient(135deg,var(--accent),var(--accent-2));
            color:#fff;display:flex;align-items:center;justify-content:center;
            font-size:1.1rem;flex-shrink:0;
            box-shadow:0 4px 12px rgba(79,70,229,.35);
        }
        .cw-card-head h3{font-size:1rem;font-weight:700;margin:0 0 .1rem;color:var(--text)}
        .cw-card-head p{font-size:.82rem;color:var(--text-2);margin:0}
        .cw-card-body{padding:1.5rem}

        .cw-field{margin-bottom:1.15rem}
        .cw-field-head{
            display:flex;align-items:center;justify-content:space-between;
            margin-bottom:.4rem;
        }
        .cw-field label{
            display:block;font-size:.75rem;font-weight:700;
            color:var(--text-2);text-transform:uppercase;
            letter-spacing:.55px;margin-bottom:.4rem;
        }
        .cw-field-head label{margin-bottom:0}
        .cw-charcount{
            font-size:.72rem;color:var(--text-2);
            font-variant-numeric:tabular-nums;font-weight:500;
        }

        .cw-input-wrap{position:relative}
        .cw-input-wrap input{
            width:100%;
            padding:.8rem 2.75rem .8rem 2.6rem;
            border:1.5px solid var(--border);
            border-radius:11px;
            font-size:.92rem;
            background:var(--surface-2);
            color:var(--text);
            font-family:inherit;
            transition:border-color .15s ease, box-shadow .15s ease, background .15s ease;
        }
        .cw-input-wrap input:focus{
            outline:none;border-color:var(--accent);
            box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
            background:var(--surface);
        }
        .cw-input-wrap input::placeholder{color:var(--text-3);opacity:1}

        .cw-input-icon{
            position:absolute;left:.95rem;top:50%;
            transform:translateY(-50%);
            color:var(--text-3);font-size:1rem;
            pointer-events:none;
            transition:color .15s ease;
        }
        .cw-input-wrap input:focus ~ .cw-input-icon{color:var(--accent)}

        .cw-eye{
            position:absolute;right:.5rem;top:50%;
            transform:translateY(-50%);
            width:32px;height:32px;border:none;
            background:transparent;color:var(--text-2);
            cursor:pointer;border-radius:8px;
            display:flex;align-items:center;justify-content:center;
            transition:background .15s ease, color .15s ease;
        }
        .cw-eye:hover{background:var(--surface-2);color:var(--accent)}

        .cw-hint{
            margin:.45rem 0 0;
            font-size:.78rem;color:var(--text-2);line-height:1.4;
        }
        .cw-hint.is-valid{color:var(--green);font-weight:600}
        .cw-hint.is-invalid{color:var(--red);font-weight:600}
        .cw-hint i{margin-right:.25rem}

        .cw-divider{
            display:flex;align-items:center;gap:.75rem;
            margin:1.5rem 0 1.15rem;
        }
        .cw-divider::before,
        .cw-divider::after{
            content:'';flex:1;height:1px;background:var(--border);
        }
        .cw-divider span{
            font-size:.7rem;font-weight:700;color:var(--text-2);
            text-transform:uppercase;letter-spacing:.6px;
            white-space:nowrap;
            display:inline-flex;align-items:center;gap:.35rem;
        }
        .cw-divider span i{font-size:.75rem;color:var(--accent)}

        .cw-strength{
            display:grid;grid-template-columns:repeat(5,1fr);
            gap:4px;margin-top:.55rem;
        }
        .cw-strength-seg{
            height:5px;border-radius:3px;
            background:var(--border);
            transition:background .2s ease;
        }
        .cw-strength-seg.active-weak  {background:var(--red)}
        .cw-strength-seg.active-medium{background:var(--amber)}
        .cw-strength-seg.active-strong{background:var(--green)}

        .cw-reqs{
            display:flex;flex-wrap:wrap;gap:.35rem;
            margin-top:.6rem;
        }
        .cw-req{
            display:inline-flex;align-items:center;gap:.35rem;
            padding:.22rem .6rem;border-radius:20px;
            font-size:.7rem;font-weight:600;
            background:var(--surface-2);color:var(--text-2);
            border:1px solid var(--border);
            transition:all .15s ease;
        }
        .cw-req i{font-size:.6rem;color:var(--text-3);opacity:.7;transition:all .15s ease}
        .cw-req.met{
            background:var(--green-soft);color:var(--green);
            border-color:color-mix(in srgb, var(--green) 30%, transparent);
        }
        .cw-req.met i{color:var(--green);opacity:1}

        .cw-submit{
            width:100%;
            padding:.9rem 1.25rem;
            border:none;border-radius:11px;
            background:linear-gradient(135deg,var(--accent),var(--accent-2));
            color:#fff;
            font-size:.95rem;font-weight:600;font-family:inherit;
            cursor:pointer;
            display:inline-flex;align-items:center;justify-content:center;
            gap:.5rem;
            box-shadow:0 4px 14px rgba(79,70,229,.3);
            transition:transform .15s ease, box-shadow .15s ease, opacity .15s ease;
            margin-top:.75rem;letter-spacing:.2px;
        }
        .cw-submit:hover:not(:disabled){
            transform:translateY(-1px);
            box-shadow:0 6px 18px rgba(79,70,229,.4);
        }
        .cw-submit:active:not(:disabled){transform:translateY(0)}
        .cw-submit:disabled{opacity:.65;cursor:not-allowed}

        .cw-alert{
            display:flex;align-items:flex-start;gap:.65rem;
            padding:.85rem 1.1rem;
            border-radius:12px;
            font-size:.86rem;line-height:1.5;
            margin-bottom:1.15rem;
            border-left:4px solid;
            animation:cw-fade .2s ease;
        }
        .cw-alert i{margin-top:2px;flex-shrink:0;font-size:1.05rem}
        .cw-alert-error{
            background:var(--red-soft);color:var(--red);
            border-color:var(--red);
        }
        .cw-alert-success{
            background:var(--green-soft);color:var(--green);
            border-color:var(--green);
        }

        .cw-attempts{
            display:flex;align-items:center;gap:.55rem;
            padding:.65rem .9rem;border-radius:10px;
            background:var(--amber-soft);color:var(--amber);
            font-size:.82rem;font-weight:600;
            margin-bottom:1.15rem;
            border-left:4px solid var(--amber);
        }
        .cw-attempts i{font-size:1rem}

        @media (max-width:1024px){
            .sidebar{transform:translateX(-100%)}
            .sidebar.open{transform:translateX(0)}
            .main-content{margin-left:0}
            .menu-toggle{display:flex}
        }
        @media (max-width:640px){
            .main-content{padding:1rem}
            .cw-card-body{padding:1.15rem}
            .cw-card-head{padding:1rem 1.15rem}
        }
    </style>
</head>
<body>

<?php
    $__current = basename($_SERVER['PHP_SELF']);
    $__super   = (($_SESSION['admin_role'] ?? '') === 'superadmin');
    // Student sub-pages all highlight "Students"
    $__student_pages = ['student_profile.php','view_student.php','edit_student.php','export_students.php','sections_list.php','sf10.php','student_documents.php'];
    $__act = in_array($__current, $__student_pages, true) ? 'student_profile.php' : $__current;
    $__a   = fn($f) => $f === $__act ? ' class="active"' : '';
?>
<!-- ================= Sidebar ================= -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="../assets/img/usat.jpg" alt="USAT" onerror="this.style.background='var(--accent)'">
        <div class="brand-text">
            <span class="brand-name">USAT Admin</span>
            <span class="brand-sub">Enrollment System</span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Overview</div>
        <a href="dashboard.php"<?= $__a('dashboard.php') ?>><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="student_profile.php"<?= $__a('student_profile.php') ?>><i class="bi bi-people-fill"></i> Students</a>
        <a href="reports.php"<?= $__a('reports.php') ?>><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>

        <?php if ($__super): ?>
        <div class="nav-label" style="margin-top:.5rem">Administration</div>
        <a href="create_account.php"<?= $__a('create_account.php') ?>><i class="bi bi-person-plus"></i> Accounts</a>
        <a href="audit_log.php"<?= $__a('audit_log.php') ?>><i class="bi bi-shield-check"></i> Audit Log</a>
        <?php endif; ?>

        <div class="nav-label" style="margin-top:.5rem">Account</div>
        <a href="profile.php"<?= $__a('profile.php') ?>><i class="bi bi-person-gear"></i> My Profile</a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php" class="btn btn-outline-danger btn-sm w-100">
            <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
    </div>
</aside>

<div class="main-content" id="mainContent">

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <div class="breadcrumb-nav">
                    <a href="create_account.php">Accounts</a>
                    <i class="bi bi-chevron-right" style="font-size:.7rem;opacity:.6"></i>
                    <span>Change Password</span>
                </div>
                <h2>Change User Password</h2>
                <p class="sub">Reset another admin's password</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="create_account.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <div class="cw-wrap">

        <?php if ($error): ?>
            <div class="cw-alert cw-alert-error" role="alert">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="cw-alert cw-alert-success" role="status">
                <i class="bi bi-check-circle-fill"></i>
                <span><?= $success ?></span>
            </div>
        <?php endif; ?>

        <?php if ($attempts_used > 0 && $locked_until === 0): ?>
            <div class="cw-attempts">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= $attempts_left ?> attempt<?= $attempts_left === 1 ? '' : 's' ?> remaining before lockout.</span>
            </div>
        <?php endif; ?>

        <div class="cw-card">
            <div class="cw-card-head">
                <div class="cw-card-head-icon"><i class="bi bi-key-fill"></i></div>
                <div>
                    <h3>Reset Password</h3>
                    <p>Enter the target username and set a new password.</p>
                </div>
            </div>

            <div class="cw-card-body">
                <form method="POST" id="passwordForm" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="cw-field">
                        <label for="username">Target Username</label>
                        <div class="cw-input-wrap">
                            <input type="text"
                                   id="username"
                                   name="username"
                                   placeholder="Enter username"
                                   required
                                   autofocus
                                   maxlength="50"
                                   autocomplete="off"
                                   value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8') : '' ?>">
                            <i class="bi bi-person cw-input-icon"></i>
                        </div>
                        <p class="cw-hint" id="usernameStatus"></p>
                    </div>

                    <div class="cw-field">
                        <div class="cw-field-head">
                            <label for="new_password">New Password</label>
                            <span class="cw-charcount" id="cwCharCount">0 / 128</span>
                        </div>
                        <div class="cw-input-wrap">
                            <input type="password"
                                   id="new_password"
                                   name="new_password"
                                   placeholder="Enter new password"
                                   required
                                   minlength="8"
                                   maxlength="128"
                                   autocomplete="new-password">
                            <i class="bi bi-lock-fill cw-input-icon"></i>
                            <button type="button" class="cw-eye" data-toggle-pw="new_password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="cw-strength" id="cwStrength">
                            <span class="cw-strength-seg"></span>
                            <span class="cw-strength-seg"></span>
                            <span class="cw-strength-seg"></span>
                            <span class="cw-strength-seg"></span>
                            <span class="cw-strength-seg"></span>
                        </div>
                        <div class="cw-reqs" id="cwReqs">
                            <span class="cw-req" data-req="length"><i class="bi bi-circle-fill"></i> 8+ chars</span>
                            <span class="cw-req" data-req="upper"><i class="bi bi-circle-fill"></i> A–Z</span>
                            <span class="cw-req" data-req="lower"><i class="bi bi-circle-fill"></i> a–z</span>
                            <span class="cw-req" data-req="num"><i class="bi bi-circle-fill"></i> 0–9</span>
                            <span class="cw-req" data-req="special"><i class="bi bi-circle-fill"></i> !@#</span>
                        </div>
                    </div>

                    <div class="cw-field">
                        <label for="confirm_password">Confirm New Password</label>
                        <div class="cw-input-wrap">
                            <input type="password"
                                   id="confirm_password"
                                   name="confirm_password"
                                   placeholder="Re-enter new password"
                                   required
                                   minlength="8"
                                   maxlength="128"
                                   autocomplete="new-password">
                            <i class="bi bi-lock-fill cw-input-icon"></i>
                            <button type="button" class="cw-eye" data-toggle-pw="confirm_password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <p class="cw-hint" id="matchStatus"></p>
                    </div>

                    <div class="cw-divider">
                        <span><i class="bi bi-shield-check"></i> Verification</span>
                    </div>

                    <div class="cw-field">
                        <label for="admin_password">Your Admin Password</label>
                        <div class="cw-input-wrap">
                            <input type="password"
                                   id="admin_password"
                                   name="admin_password"
                                   placeholder="Enter your password to confirm"
                                   required
                                   autocomplete="current-password">
                            <i class="bi bi-shield-lock-fill cw-input-icon"></i>
                            <button type="button" class="cw-eye" data-toggle-pw="admin_password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <p class="cw-hint">Required as proof that you're the one making this change. You have <?= $attempts_left ?> attempt<?= $attempts_left === 1 ? '' : 's' ?> left.</p>
                    </div>

                    <button type="submit" class="cw-submit" id="cwSubmit" <?= $locked_until > 0 ? 'disabled' : '' ?>>
                        <i class="bi <?= $locked_until > 0 ? 'bi-lock-fill' : 'bi-key-fill' ?>"></i>
                        <span><?= $locked_until > 0 ? "Locked for {$locked_until} min" : 'Change Password' ?></span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);backdrop-filter:blur(2px);z-index:199"
     onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ---------- Sidebar ----------
const sb = document.getElementById('sidebar');
const ov = document.getElementById('sidebarOverlay');
document.getElementById('menuToggle').addEventListener('click', () => {
    sb.classList.toggle('open');
    ov.style.display = sb.classList.contains('open') ? 'block' : 'none';
});

// ---------- Theme ----------
const tb = document.getElementById('themeToggle');
const ti = document.getElementById('themeIcon');
const h  = document.documentElement;
function st(t) {
    h.setAttribute('data-bs-theme', t);
    ti.className = 'bi bi-' + (t === 'dark' ? 'sun-fill' : 'moon-stars-fill');
    document.cookie = 'admin_theme=' + t + ';path=/;max-age=' + (60*60*24*365);
}
(function () {
    const m = document.cookie.match(/(?:^|; )admin_theme=([^;]+)/);
    st(m ? decodeURIComponent(m[1]) : 'light');
})();
tb.addEventListener('click', () => st(h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));

// ---------- Password visibility ----------
document.querySelectorAll('[data-toggle-pw]').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = document.getElementById(btn.getAttribute('data-toggle-pw'));
        if (!input) return;
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            if (icon) icon.className = 'bi bi-eye-slash';
        } else {
            input.type = 'password';
            if (icon) icon.className = 'bi bi-eye';
        }
    });
});

// ---------- Strength meter ----------
(function () {
    const input = document.getElementById('new_password');
    const segs  = document.querySelectorAll('#cwStrength .cw-strength-seg');
    const reqs  = document.getElementById('cwReqs');
    const count = document.getElementById('cwCharCount');
    if (!input || !segs.length || !reqs) return;

    const rules = {
        length:  p => p.length >= 8,
        upper:   p => /[A-Z]/.test(p),
        lower:   p => /[a-z]/.test(p),
        num:     p => /[0-9]/.test(p),
        special: p => /[^A-Za-z0-9]/.test(p),
    };

    input.addEventListener('input', () => {
        const p = input.value;
        let met = 0;

        for (const [key, test] of Object.entries(rules)) {
            const pill = reqs.querySelector('[data-req="' + key + '"]');
            if (!pill) continue;
            const ok = test(p);
            pill.classList.toggle('met', ok);
            if (ok) met++;
        }

        const level = met <= 2 ? 'active-weak'
                    : met <= 3 ? 'active-medium'
                    : 'active-strong';
        segs.forEach((seg, i) => {
            seg.className = 'cw-strength-seg';
            if (i < met) seg.classList.add(level);
        });

        if (count) {
            const n = p.length;
            count.textContent = n + ' / 128';
            count.style.color = n > 128 ? 'var(--red)' : (n >= 8 ? 'var(--green)' : 'var(--text-2)');
        }
        checkMatch();
    });
})();

// ---------- Live password-match indicator ----------
const newPwEl  = document.getElementById('new_password');
const confPwEl = document.getElementById('confirm_password');
const matchEl  = document.getElementById('matchStatus');
function checkMatch() {
    if (!matchEl) return;
    const a = newPwEl.value;
    const b = confPwEl.value;
    if (!b) { matchEl.className = 'cw-hint'; matchEl.textContent = ''; return; }
    if (a === b) {
        matchEl.className = 'cw-hint is-valid';
        matchEl.innerHTML = '<i class="bi bi-check-circle-fill"></i>Passwords match.';
    } else {
        matchEl.className = 'cw-hint is-invalid';
        matchEl.innerHTML = '<i class="bi bi-x-circle-fill"></i>Passwords do not match.';
    }
}
if (confPwEl) confPwEl.addEventListener('input', checkMatch);

// ---------- Live username existence check (AJAX) ----------
const unEl     = document.getElementById('username');
const unStatus = document.getElementById('usernameStatus');
let unTimer = null;
let unLastChecked = '';

if (unEl && unStatus) {
    unEl.addEventListener('input', () => {
        clearTimeout(unTimer);
        const v = unEl.value.trim();
        if (!v) { unStatus.className = 'cw-hint'; unStatus.textContent = ''; unLastChecked = ''; return; }

        unTimer = setTimeout(async () => {
            if (v === unLastChecked) return;
            unLastChecked = v;
            unStatus.className = 'cw-hint';
            unStatus.innerHTML = '<i class="bi bi-hourglass-split"></i>Checking…';

            try {
                const fd = new FormData();
                fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
                fd.append('username', v);
                const r = await fetch('?action=check_username', { method: 'POST', body: fd });
                const j = await r.json();

                // Guard against stale response if the user kept typing
                if (unEl.value.trim() !== v) return;

                if (j.exists) {
                    unStatus.className = 'cw-hint is-valid';
                    unStatus.innerHTML = '<i class="bi bi-check-circle-fill"></i>User found.';
                } else {
                    unStatus.className = 'cw-hint is-invalid';
                    unStatus.innerHTML = '<i class="bi bi-x-circle-fill"></i>No account with that username.';
                }
            } catch (e) {
                unStatus.className = 'cw-hint';
                unStatus.textContent = '';
            }
        }, 350);
    });
}

// ---------- Submit: client-side validation + loading state ----------
(function () {
    const form = document.getElementById('passwordForm');
    const btn  = document.getElementById('cwSubmit');
    if (!form || !btn) return;
    const btnText = btn.querySelector('span');
    const btnIcon = btn.querySelector('i');

    form.addEventListener('submit', function (e) {
        const np = newPwEl.value;
        const cp = confPwEl.value;
        const ap = document.getElementById('admin_password').value;
        const un = unEl.value.trim();

        if (!un || !np || !cp || !ap) {
            e.preventDefault();
            alert('Please fill in all fields.');
            return;
        }
        if (np !== cp) {
            e.preventDefault();
            alert('New passwords do not match.');
            return;
        }
        if (np.length < 8) {
            e.preventDefault();
            alert('Password must be at least 8 characters.');
            return;
        }
        if (!/[A-Z]/.test(np) || !/[a-z]/.test(np) || !/[0-9]/.test(np) || !/[^A-Za-z0-9]/.test(np)) {
            e.preventDefault();
            alert('Password must contain uppercase, lowercase, number, and special character.');
            return;
        }

        btn.disabled = true;
        if (btnIcon) btnIcon.className = 'spinner-border spinner-border-sm';
        if (btnText) btnText.textContent = 'Updating...';
    });
})();
</script>
</body>
</html>