<?php
require_once __DIR__ . '/../config/auth.php';

if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    $_SESSION['error'] = "Access Denied! Only the Principal can manage accounts.";
    header("Location: dashboard.php");
    exit();
}

// ====================== HANDLE ADD ACCOUNT ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_account'])) {
    verify_csrf();
    $fullname = trim($_POST['fullname']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role     = trim($_POST['role']);

    if (empty($fullname) || empty($username) || empty($password) || empty($role)) {
        $_SESSION['error'] = "All fields are required!";
    } elseif (strlen($password) < 8) {
        $_SESSION['error'] = "Password must be at least 8 characters!";
    } else {
        $check = $conn->prepare("SELECT id FROM admins WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $_SESSION['error'] = "Username already exists!";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO admins (fullname, username, password, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $fullname, $username, $hashed_password, $role);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Account created for " . htmlspecialchars($fullname) . "!";
            } else {
                $_SESSION['error'] = "Failed to create account.";
            }
            $stmt->close();
        }
        $check->close();
    }
    header("Location: create_account.php");
    exit();
}

// ====================== HANDLE DELETE ACCOUNT ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
    verify_csrf();
    $account_id = (int)$_POST['account_id'];

    if ($account_id == $_SESSION['admin_id']) {
        $_SESSION['error'] = "You cannot delete your own account!";
    } else {
        $count_sa = $conn->query("SELECT COUNT(*) as cnt FROM admins WHERE role='superadmin'")->fetch_assoc()['cnt'];
        $target_role = $conn->query("SELECT role FROM admins WHERE id=$account_id")->fetch_assoc()['role'] ?? '';

        if ($target_role === 'superadmin' && $count_sa <= 1) {
            $_SESSION['error'] = "Cannot delete the last Super Admin account!";
        } else {
            $stmt = $conn->prepare("DELETE FROM admins WHERE id = ?");
            $stmt->bind_param("i", $account_id);
            $stmt->execute() ? $_SESSION['success'] = "Account deleted!" : $_SESSION['error'] = "Failed to delete.";
            $stmt->close();
        }
    }
    header("Location: create_account.php");
    exit();
}

// ====================== FETCH ACCOUNTS ======================
$accounts = $conn->query("SELECT id, fullname, username, role, created_at FROM admins ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
$total_accounts = count($accounts);
$superadmin_count = count(array_filter($accounts, fn($a) => $a['role'] === 'superadmin'));
$registrar_count = $total_accounts - $superadmin_count;

$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts • USAT Admin</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons — served from cdnjs because CSP font-src allows it -->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        /* ============================================================
           Design tokens (matches dashboard.php / student_profile.php)
           ============================================================ */
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
            --radius-xl: 20px;

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

        /* ============================================================
           Sidebar
           ============================================================ */
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

        /* ============================================================
           Main content
           ============================================================ */
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

        /* ============================================================
           Stat cards
           ============================================================ */
        .stat-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
            gap:1rem;margin-bottom:1.5rem;
        }
        .stat-card{
            position:relative;
            background:var(--surface);border-radius:var(--radius-lg);
            border:1px solid var(--border);box-shadow:var(--shadow-sm);
            padding:1.1rem 1.25rem 1.1rem 1.4rem;
            display:flex;align-items:center;gap:1rem;
            transition:transform .2s, box-shadow .2s, border-color .2s;
            overflow:hidden;
        }
        .stat-card::before{
            content:'';position:absolute;left:0;top:0;bottom:0;width:4px;
            background:var(--accent);
        }
        .stat-card.tone-blue::before  {background:var(--accent)}
        .stat-card.tone-purple::before{background:var(--purple)}
        .stat-card.tone-green::before {background:var(--green)}
        .stat-card.tone-red::before   {background:var(--red)}
        .stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);border-color:var(--border-strong)}

        .stat-icon{
            width:42px;height:42px;border-radius:12px;
            display:flex;align-items:center;justify-content:center;
            font-size:1.1rem;flex-shrink:0;
            background:var(--accent-soft);color:var(--accent);
        }
        .tone-purple .stat-icon{background:var(--purple-soft);color:var(--purple)}
        .tone-green  .stat-icon{background:var(--green-soft);color:var(--green)}
        .tone-red    .stat-icon{background:var(--red-soft);color:var(--red)}

        .stat-info{min-width:0;flex:1}
        .stat-info .stat-value{
            font-size:1.55rem;font-weight:700;line-height:1.1;
            letter-spacing:-.02em;font-variant-numeric:tabular-nums;
            color:var(--text);
        }
        .stat-info .stat-label{
            font-size:.7rem;color:var(--text-2);text-transform:uppercase;
            letter-spacing:.6px;margin-top:.2rem;font-weight:600;
        }
        .stat-info .stat-hint{
            font-size:.72rem;color:var(--text-3);margin-top:.3rem;font-weight:500;
        }

        /* ============================================================
           Cards
           ============================================================ */
        .card{
            background:var(--surface);border-radius:var(--radius-lg);
            border:1px solid var(--border);box-shadow:var(--shadow-sm);
            margin-bottom:1.25rem;overflow:hidden;
        }
        .card-header{
            padding:.9rem 1.25rem;border-bottom:1px solid var(--border);
            display:flex;align-items:center;justify-content:space-between;
            gap:1rem;flex-wrap:wrap;background:var(--surface);
        }
        .card-header h3{
            margin:0;font-size:.9rem;font-weight:600;
            display:flex;align-items:center;gap:.5rem;color:var(--text);
        }
        .card-header h3 i{color:var(--accent);font-size:1rem}
        .card-header .hint{font-size:.75rem;color:var(--text-2);font-weight:500}
        .card-body{padding:1.25rem}
        .card-body.no-padding{padding:0}

        /* ============================================================
           Table
           ============================================================ */
        .table-scroll{overflow-x:auto}
        .table-admin{width:100%;border-collapse:separate;border-spacing:0}
        .table-admin thead th{
            background:var(--surface-2);
            font-size:.68rem;font-weight:700;color:var(--text-2);
            text-transform:uppercase;letter-spacing:.6px;
            padding:.7rem 1rem;text-align:left;
            border-bottom:1px solid var(--border);
            white-space:nowrap;
        }
        .table-admin tbody td{
            padding:.7rem 1rem;border-bottom:1px solid var(--border);
            font-size:.85rem;vertical-align:middle;
            transition:background .12s;
        }
        .table-admin tbody tr:hover td{background:color-mix(in srgb, var(--accent) 4%, transparent)}
        .table-admin tbody tr:last-child td{border-bottom:none}
        .table-admin tbody tr.is-self td{background:color-mix(in srgb, var(--amber) 5%, transparent)}
        .table-admin tbody tr.is-self:hover td{background:color-mix(in srgb, var(--amber) 8%, transparent)}
        .cell-id{
            font-variant-numeric:tabular-nums;font-weight:600;
            font-size:.8rem;color:var(--text-3);
        }
        .cell-strong{font-weight:600;color:var(--text)}
        .cell-muted{color:var(--text-2);font-size:.82rem}
        .cell-center{text-align:center}

        /* ============================================================
           Avatar (account row)
           ============================================================ */
        .account-cell{display:flex;align-items:center;gap:.75rem;min-width:0}
        .account-avatar{
            width:38px;height:38px;border-radius:50%;
            background:linear-gradient(135deg,var(--accent),var(--accent-2));
            color:#fff;display:flex;align-items:center;justify-content:center;
            font-weight:700;font-size:.85rem;flex-shrink:0;
            box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 15%, transparent);
        }
        .account-avatar.super{background:linear-gradient(135deg,#dc2626,#b91c1c);box-shadow:0 0 0 3px rgba(220,38,38,.15)}
        .account-info{min-width:0}
        .account-info .nm{font-weight:600;font-size:.88rem;line-height:1.2;color:var(--text)}
        .account-info .un{font-size:.74rem;color:var(--text-2);font-variant-numeric:tabular-nums}

        /* ============================================================
           Pills / badges
           ============================================================ */
        .pill{
            display:inline-flex;align-items:center;gap:.35rem;
            padding:.25rem .65rem;border-radius:999px;
            font-size:.72rem;font-weight:600;
            border:1px solid transparent;
            white-space:nowrap;
        }
        .pill i{font-size:.75rem}
        .pill.success{background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb, var(--green) 22%, transparent)}
        .pill.danger {background:var(--red-soft);color:var(--red);border-color:color-mix(in srgb, var(--red) 22%, transparent)}
        .pill.primary{background:var(--accent-soft);color:var(--accent);border-color:color-mix(in srgb, var(--accent) 22%, transparent)}
        .pill.warning{background:var(--amber-soft);color:var(--amber);border-color:color-mix(in srgb, var(--amber) 22%, transparent)}
        .pill.muted  {background:var(--surface-2);color:var(--text-2);border-color:var(--border)}

        /* ============================================================
           Buttons
           ============================================================ */
        .btn{font-weight:500;border-radius:9px;font-size:.82rem;transition:all .15s}
        .btn-primary{background:var(--accent);border-color:var(--accent)}
        .btn-primary:hover{background:var(--accent-2);border-color:var(--accent-2)}
        .btn-outline-primary{color:var(--accent);border-color:var(--border-strong)}
        .btn-outline-primary:hover{background:var(--accent);border-color:var(--accent);color:#fff}
        .btn-outline-secondary{color:var(--text-2);border-color:var(--border)}
        .btn-outline-secondary:hover{background:var(--surface-2);color:var(--text);border-color:var(--border-strong)}
        .btn-outline-danger{color:var(--red);border-color:color-mix(in srgb, var(--red) 35%, transparent)}
        .btn-outline-danger:hover{background:var(--red);color:#fff;border-color:var(--red)}
        .btn-icon{
            width:32px;height:32px;padding:0;
            display:inline-flex;align-items:center;justify-content:center;
            border-radius:9px;
        }
        .btn-xs{padding:.25rem .6rem;font-size:.72rem;border-radius:8px}
        .theme-btn{
            width:38px;height:38px;border-radius:10px;
            border:1px solid var(--border);background:var(--surface);
            color:var(--text-2);cursor:pointer;
            display:flex;align-items:center;justify-content:center;
            transition:all .15s;
        }
        .theme-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}

        /* ============================================================
           Modals
           ============================================================ */
        .modal-content{border-radius:var(--radius-lg);border:none;box-shadow:var(--shadow-lg)}
        .modal-header{
            padding:1rem 1.25rem;border-bottom:1px solid var(--border);
            border-top-left-radius:var(--radius-lg);border-top-right-radius:var(--radius-lg);
        }
        .modal-header.bg-danger{background:var(--red) !important}
        .modal-header .modal-title{font-size:.92rem}
        .modal-body{padding:1.25rem}
        .modal-footer{padding:.85rem 1.25rem;border-top:1px solid var(--border)}
        .form-label{font-size:.78rem;font-weight:600;color:var(--text-2);margin-bottom:.35rem;display:block}
        .form-control,.form-select{
            background:var(--surface-2);border:1px solid var(--border);
            border-radius:10px;padding:.5rem .8rem;font-size:.85rem;
            color:var(--text);transition:border-color .15s, box-shadow .15s;
        }
        .form-control:focus,.form-select:focus{
            border-color:var(--accent);background:var(--surface);
            box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
        }
        .form-text{font-size:.72rem;color:var(--text-3)}
        .input-group-icon{position:relative}
        .input-group-icon .bi{
            position:absolute;right:.8rem;top:50%;transform:translateY(-50%);
            color:var(--text-3);pointer-events:none;font-size:.9rem;
        }

        /* ============================================================
           Empty state
           ============================================================ */
        .empty{
            padding:3rem 1.5rem;text-align:center;color:var(--text-2);
        }
        .empty .empty-icon{
            width:64px;height:64px;border-radius:50%;
            background:var(--surface-2);color:var(--text-3);
            display:inline-flex;align-items:center;justify-content:center;
            font-size:1.6rem;margin-bottom:1rem;
        }
        .empty .empty-title{font-weight:600;color:var(--text);margin-bottom:.25rem}
        .empty .empty-sub{font-size:.85rem;color:var(--text-2)}

        /* ============================================================
           Flash alerts
           ============================================================ */
        .flash{
            display:flex;align-items:center;gap:.65rem;
            padding:.75rem 1rem;border-radius:var(--radius);
            margin-bottom:1rem;font-size:.88rem;font-weight:500;
            border:1px solid transparent;
        }
        .flash.success{background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb, var(--green) 25%, transparent)}
        .flash.error  {background:var(--red-soft);  color:var(--red);  border-color:color-mix(in srgb, var(--red) 25%, transparent)}
        .flash .btn-close{margin-left:auto;opacity:.6}

        /* ============================================================
           Responsive
           ============================================================ */
        @media (max-width:1024px){
            .sidebar{transform:translateX(-100%)}
            .sidebar.open{transform:translateX(0)}
            .main-content{margin-left:0}
            .menu-toggle{display:flex}
        }
        @media (max-width:640px){
            .stat-grid{grid-template-columns:repeat(2,1fr);gap:.75rem}
            .stat-card{padding:1rem 1rem 1rem 1.1rem;gap:.75rem}
            .stat-icon{width:36px;height:36px;font-size:.95rem}
            .stat-info .stat-value{font-size:1.3rem}
            .main-content{padding:1rem}
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

    <!-- ================= Flash alerts ================= -->
    <?php if(isset($_SESSION['success'])): ?>
        <div class="flash success">
            <i class="bi bi-check-circle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['success']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <div class="flash error">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['error']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

    <!-- ================= Topbar ================= -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h2>Admin Accounts</h2>
                <p class="sub">Manage system administrators</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <a href="storage_health.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-hdd-stack me-1"></i> Storage Health
            </a>
            <a href="change_user_password.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-key me-1"></i> Change Password
            </a>
            <span class="pill danger"><i class="bi bi-shield-lock"></i> Restricted Area</span>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <!-- ================= Stat grid ================= -->
    <div class="stat-grid">
        <div class="stat-card tone-blue">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= (int)$total_accounts ?></div>
                <div class="stat-label">Total Accounts</div>
                <div class="stat-hint">All admin users</div>
            </div>
        </div>
        <div class="stat-card tone-red">
            <div class="stat-icon"><i class="bi bi-shield-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= (int)$superadmin_count ?></div>
                <div class="stat-label">Super Admins</div>
                <div class="stat-hint">Full access</div>
            </div>
        </div>
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="bi bi-person-check-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= (int)$registrar_count ?></div>
                <div class="stat-label">Registrars</div>
                <div class="stat-hint">Enrollment staff</div>
            </div>
        </div>
    </div>

    <!-- ================= Accounts table ================= -->
    <div class="card">
        <div class="card-header">
            <h3>
                <i class="bi bi-people"></i> All Admin Accounts
                <span class="hint">(<?= (int)$total_accounts ?>)</span>
            </h3>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                <i class="bi bi-plus-lg me-1"></i> Add Account
            </button>
        </div>
        <div class="card-body no-padding">
            <?php if(!empty($accounts)): ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th style="width:70px">ID</th>
                                <th>Account</th>
                                <th>Role</th>
                                <th style="width:150px">Created</th>
                                <th style="width:120px;text-align:center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($accounts as $row):
                            $is_self = ($row['id'] == $_SESSION['admin_id']);
                            $is_super = ($row['role'] === 'superadmin');
                            $parts = explode(' ', trim($row['fullname']));
                            $initials = strtoupper(substr($parts[0] ?? '?', 0, 1) . substr(end($parts) ?: '', 0, 1));
                        ?>
                            <tr class="<?= $is_self ? 'is-self' : '' ?>">
                                <td class="cell-id">#<?= (int)$row['id'] ?></td>
                                <td>
                                    <div class="account-cell">
                                        <div class="account-avatar <?= $is_super ? 'super' : '' ?>"><?= htmlspecialchars($initials) ?></div>
                                        <div class="account-info">
                                            <div class="nm">
                                                <?= htmlspecialchars($row['fullname']) ?>
                                                <?php if($is_self): ?>
                                                    <span class="pill warning ms-1" style="font-size:.62rem;padding:.1rem .4rem">you</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="un">@<?= htmlspecialchars($row['username']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="pill <?= $is_super ? 'danger' : 'primary' ?>">
                                        <i class="bi bi-<?= $is_super ? 'shield-fill' : 'person-check' ?>"></i>
                                        <?= htmlspecialchars(ucfirst($row['role'])) ?>
                                    </span>
                                </td>
                                <td class="cell-muted"><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                                <td style="text-align:center">
                                    <?php if(!$is_self): ?>
                                        <button class="btn btn-outline-danger btn-xs"
                                                onclick="confirmDelete(<?= (int)$row['id'] ?>,'<?= addslashes(htmlspecialchars($row['fullname'])) ?>')">
                                            <i class="bi bi-trash3 me-1"></i> Delete
                                        </button>
                                    <?php else: ?>
                                        <span class="pill muted">Current</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-people"></i></div>
                    <div class="empty-title">No accounts found</div>
                    <div class="empty-sub">Create the first admin account to get started.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);backdrop-filter:blur(2px);z-index:199"
     onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<!-- ================= Add Account Modal ================= -->
<div class="modal fade" id="addAccountModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-person-plus me-2"></i>Create New Account</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="fullname" class="form-control" placeholder="e.g. Juan Dela Cruz" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" placeholder="e.g. juan_admin" required autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <div class="input-group-icon">
                            <input type="password" name="password" id="newAccountPassword" class="form-control" placeholder="Min. 8 characters" minlength="8" required autocomplete="new-password">
                            <i class="bi bi-eye" id="togglePassword" style="pointer-events:auto;cursor:pointer" title="Show password"></i>
                        </div>
                        <div class="form-text mt-1">Minimum 8 characters. Use a strong, unique password.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" required>
                            <option value="registrar">Registrar</option>
                            <option value="superadmin">Super Admin</option>
                        </select>
                        <div class="form-text mt-1">Super Admin has full access including account management.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_account" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2 me-1"></i> Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================= Delete Confirmation Modal ================= -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Delete Account</h6>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Delete <strong id="deleteAccountName"></strong>? This cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="delete_account" value="1">
                    <input type="hidden" name="account_id" id="deleteAccountId">
                    <button class="btn btn-danger btn-sm"><i class="bi bi-trash3 me-1"></i> Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

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
    const m = document.cookie.match(/admin_theme=([^;]+)/);
    st(m ? m[1] : 'light');
})();
tb.addEventListener('click', () => st(h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));

// ---------- Delete confirmation ----------
function confirmDelete(id, name) {
    document.getElementById('deleteAccountId').value = id;
    document.getElementById('deleteAccountName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
}

// ---------- Show/hide password ----------
const pw = document.getElementById('newAccountPassword');
const tp = document.getElementById('togglePassword');
if (pw && tp) {
    tp.addEventListener('click', () => {
        const show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        tp.className = 'bi bi-' + (show ? 'eye-slash' : 'eye');
    });
}

// ---------- Clear form when modal closes (so error state doesn't persist) ----------
const addModal = document.getElementById('addAccountModal');
if (addModal) {
    addModal.addEventListener('hidden.bs.modal', function () {
        const form = this.querySelector('form');
        if (form) form.reset();
        if (pw) pw.type = 'password';
        if (tp) tp.className = 'bi bi-eye';
    });
}
</script>
</body>
</html>