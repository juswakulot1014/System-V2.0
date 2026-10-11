<?php
require_once __DIR__ . '/../config/auth.php';

$current_page = basename($_SERVER['PHP_SELF']);

// ====================== FETCH DASHBOARD DATA ======================
// NOTE: Graduated students are excluded from every dashboard query.
// They only appear in reports.php.

// Total students — exclude anyone whose enrollment is Graduated
$total_students = $conn->query("
    SELECT COUNT(*) as cnt FROM students_info s
    WHERE NOT EXISTS (
        SELECT 1 FROM enrollment_form e
        WHERE e.student_id = s.student_id AND e.status = 'Graduated'
    )
")->fetch_assoc()['cnt'] ?? 0;

// Total enrollments (distinct students who are not graduated)
$total_enrolled = $conn->query("
    SELECT COUNT(DISTINCT student_id) as cnt FROM enrollment_form
    WHERE status IS NULL OR status != 'Graduated'
")->fetch_assoc()['cnt'] ?? 0;

// Active enrollment — unchanged (already filters to Active or NULL)
$active_enrollment = $conn->query("
    SELECT COUNT(*) as cnt FROM enrollment_form
    WHERE status='Active' OR status IS NULL
")->fetch_assoc()['cnt'] ?? 0;

// Sections — exclude graduated
$total_sections = $conn->query("
    SELECT COUNT(DISTINCT section) as cnt FROM enrollment_form
    WHERE section IS NOT NULL AND section != ''
      AND (status IS NULL OR status != 'Graduated')
")->fetch_assoc()['cnt'] ?? 0;

// Gender stats — exclude graduated
$gender = $conn->query("
    SELECT
        SUM(CASE WHEN s.sex='Male' THEN 1 ELSE 0 END) as boys,
        SUM(CASE WHEN s.sex='Female' THEN 1 ELSE 0 END) as girls
    FROM students_info s
    WHERE NOT EXISTS (
        SELECT 1 FROM enrollment_form e
        WHERE e.student_id = s.student_id AND e.status = 'Graduated'
    )
")->fetch_assoc();
$boys  = (int)($gender['boys']  ?? 0);
$girls = (int)($gender['girls'] ?? 0);

// Recent enrollments — exclude graduated
$recent = $conn->query("
    SELECT s.student_id, s.first_name, s.last_name, s.lrn, s.student_id_number,
           e.grade_level, e.strand, e.section, e.enrolled_at
    FROM students_info s
    JOIN enrollment_form e ON s.student_id = e.student_id
    WHERE (e.status IS NULL OR e.status != 'Graduated')
    ORDER BY e.enrolled_at DESC
    LIMIT 8
");

// Grade level distribution — exclude graduated
$grade_dist = $conn->query("
    SELECT grade_level, COUNT(*) as cnt FROM enrollment_form
    WHERE (status IS NULL OR status != 'Graduated')
    GROUP BY grade_level
    ORDER BY grade_level
");
$grade_labels = []; $grade_data = [];
while($g = $grade_dist->fetch_assoc()) {
    $grade_labels[] = $g['grade_level'];
    $grade_data[]   = (int)$g['cnt'];
}

// Strand distribution — exclude graduated
$strand_dist = $conn->query("
    SELECT COALESCE(strand,'Unassigned') as strand, COUNT(*) as cnt
    FROM enrollment_form
    WHERE (status IS NULL OR status != 'Graduated')
    GROUP BY strand
    ORDER BY cnt DESC
");
$strand_labels = []; $strand_data = []; $strand_colors = [];
$colors = ['#4f46e5','#7c3aed','#6366f1','#818cf8','#a5b4fc','#c7d2fe','#8b5cf6','#6d28d9'];
$ci = 0;
while($s = $strand_dist->fetch_assoc()) {
    $strand_labels[] = $s['strand'];
    $strand_data[]   = (int)$s['cnt'];
    $strand_colors[] = $colors[$ci % count($colors)];
    $ci++;
}

// Unassigned students — exclude graduated
$unassigned = $conn->query("
    SELECT COUNT(*) as cnt FROM enrollment_form
    WHERE (section IS NULL OR section = '')
      AND (status IS NULL OR status != 'Graduated')
")->fetch_assoc()['cnt'] ?? 0;

// Recently added (this week) — exclude graduated
$week_start = date('Y-m-d', strtotime('monday this week'));
$new_this_week = $conn->query("
    SELECT COUNT(*) as cnt FROM students_info s
    WHERE s.created_at >= '$week_start'
      AND NOT EXISTS (
          SELECT 1 FROM enrollment_form e
          WHERE e.student_id = s.student_id AND e.status = 'Graduated'
      )
")->fetch_assoc()['cnt'] ?? 0;

// ====================== FILTERS ======================
$search       = trim($_GET['search'] ?? '');
$grade_filter = trim($_GET['grade_level'] ?? '');
$sy_filter    = trim($_GET['school_year'] ?? '');

$where  = [];
$params = [];
$types  = "";

// Always exclude graduated students from the directory
$where[] = "(e.status IS NULL OR e.status != 'Graduated')";

if ($search !== '') {
    $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
    $types .= "ssss";
}
if ($grade_filter !== '') { $where[] = "e.grade_level = ?"; $params[] = $grade_filter; $types .= "s"; }
if ($sy_filter !== '')    { $where[] = "e.school_year = ?"; $params[] = $sy_filter;    $types .= "s"; }

$where_clause = "WHERE " . implode(" AND ", $where);

// Students list with filters
$students_sql = "SELECT s.student_id, s.first_name, s.last_name, s.middle_name, s.lrn, s.student_id_number, s.sex, s.age, e.grade_level, e.school_year, e.strand, e.program, e.section, e.status FROM students_info s LEFT JOIN enrollment_form e ON s.student_id=e.student_id $where_clause ORDER BY s.last_name ASC LIMIT 100";
$stmt = $conn->prepare($students_sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$students = $stmt->get_result();
$filtered_count = $students->num_rows;
$stmt->close();

// Dropdowns — exclude graduated options
$grade_levels = $conn->query("
    SELECT DISTINCT grade_level FROM enrollment_form
    WHERE grade_level IS NOT NULL
      AND (status IS NULL OR status != 'Graduated')
    ORDER BY grade_level ASC
");
$school_years = $conn->query("
    SELECT DISTINCT school_year FROM enrollment_form
    WHERE school_year IS NOT NULL
      AND (status IS NULL OR status != 'Graduated')
    ORDER BY school_year DESC
");

$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard • USAT Admin</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons — served from cdnjs because CSP font-src allows it -->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>

    <style>
        /* ============================================================
           Design tokens
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
            margin-bottom:.1rem;position:relative;
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
            grid-template-columns:repeat(3, minmax(0, 1fr));
            gap:1rem;margin-bottom:1.5rem;
        }
        .stat-card{
            position:relative;
            background:var(--surface);border-radius:var(--radius-lg);
            border:1px solid var(--border);box-shadow:var(--shadow-sm);
            padding:1.25rem 1.25rem 1.25rem 1.5rem;
            display:flex;align-items:center;gap:1rem;
            transition:transform .2s, box-shadow .2s, border-color .2s;
            overflow:hidden;
        }
        .stat-card::before{
            content:'';position:absolute;left:0;top:0;bottom:0;width:4px;
            background:var(--accent);
        }
        .stat-card.tone-blue::before{background:var(--accent)}
        .stat-card.tone-green::before{background:var(--green)}
        .stat-card.tone-purple::before{background:var(--purple)}
        .stat-card.tone-amber::before{background:var(--amber)}
        .stat-card.tone-red::before{background:var(--red)}
        .stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);border-color:var(--border-strong)}

        .stat-icon{
            width:44px;height:44px;border-radius:12px;
            display:flex;align-items:center;justify-content:center;
            font-size:1.15rem;flex-shrink:0;
            background:var(--accent-soft);color:var(--accent);
        }
        .tone-green  .stat-icon{background:var(--green-soft);color:var(--green)}
        .tone-purple .stat-icon{background:var(--purple-soft);color:var(--purple)}
        .tone-amber  .stat-icon{background:var(--amber-soft);color:var(--amber)}
        .tone-red    .stat-icon{background:var(--red-soft);color:var(--red)}

        .stat-info{min-width:0;flex:1}
        .stat-info .stat-value{
            font-size:1.65rem;font-weight:700;line-height:1.1;
            letter-spacing:-.02em;font-variant-numeric:tabular-nums;
        }
        .stat-info .stat-label{
            font-size:.7rem;color:var(--text-2);text-transform:uppercase;
            letter-spacing:.6px;margin-top:.25rem;font-weight:600;
        }
        .stat-info .stat-hint{
            font-size:.72rem;color:var(--text-3);margin-top:.35rem;font-weight:500;
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
           Charts
           ============================================================ */
        .chart-wrap{position:relative;height:260px}
        .chart-wrap canvas{max-height:100%}

        /* ============================================================
           Filter bar
           ============================================================ */
        .filter-bar{
            display:grid;
            grid-template-columns:minmax(220px,1.4fr) minmax(150px,1fr) minmax(150px,1fr) auto;
            gap:.75rem;align-items:end;width:100%;
        }
        .filter-bar .field{display:flex;flex-direction:column;gap:.3rem;min-width:0}
        .filter-bar label{
            font-size:.68rem;font-weight:700;color:var(--text-2);
            text-transform:uppercase;letter-spacing:.5px;
        }
        .filter-bar input,.filter-bar select{
            background:var(--surface-2);border:1px solid var(--border);
            border-radius:10px;padding:.5rem .8rem;font-size:.85rem;
            color:var(--text);font-family:inherit;width:100%;
            transition:border-color .15s, box-shadow .15s, background .15s;
        }
        .filter-bar input::placeholder{color:var(--text-3)}
        .filter-bar input:focus,.filter-bar select:focus{
            border-color:var(--accent);background:var(--surface);
            box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
            outline:none;
        }
        .filter-bar .actions{display:flex;gap:.5rem;flex-wrap:nowrap}

        /* ============================================================
           Table
           ============================================================ */
        .table-scroll{
            overflow:auto;
            max-height:600px;
            border-radius:0 0 var(--radius-lg) var(--radius-lg);
        }
        .table-admin{width:100%;border-collapse:separate;border-spacing:0}
        .table-admin thead th{
            background:var(--surface-2);
            font-size:.68rem;font-weight:700;color:var(--text-2);
            text-transform:uppercase;letter-spacing:.6px;
            padding:.7rem 1rem;text-align:left;
            border-bottom:1px solid var(--border);
            white-space:nowrap;position:sticky;top:0;z-index:2;
        }
        .table-admin tbody td{
            padding:.7rem 1rem;border-bottom:1px solid var(--border);
            font-size:.85rem;vertical-align:middle;
            transition:background .12s;
        }
        .table-admin tbody tr:hover td{background:color-mix(in srgb, var(--accent) 4%, transparent)}
        .table-admin tbody tr:last-child td{border-bottom:none}
        .cell-id{font-variant-numeric:tabular-nums;font-weight:600;font-size:.82rem;color:var(--text)}
        .cell-name{font-weight:600;color:var(--text)}
        .cell-muted{color:var(--text-2);font-size:.82rem}
        .cell-accent{color:var(--accent);font-weight:600}
        .cell-num{color:var(--text-3);font-size:.78rem;font-variant-numeric:tabular-nums}

        /* ============================================================
           Status pills / badges
           ============================================================ */
        .pill{
            display:inline-flex;align-items:center;gap:.4rem;
            padding:.25rem .65rem;border-radius:999px;
            font-size:.72rem;font-weight:600;
            border:1px solid transparent;
        }
        .pill::before{
            content:'';width:6px;height:6px;border-radius:50%;
            background:currentColor;opacity:.9;
        }
        .pill.success{background:var(--green-soft);color:var(--green)}
        .pill.warning{background:var(--amber-soft);color:var(--amber)}
        .pill.danger {background:var(--red-soft);  color:var(--red)}
        .pill.muted  {background:var(--surface-2); color:var(--text-2)}

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
        .btn-icon{
            width:32px;height:32px;padding:0;
            display:inline-flex;align-items:center;justify-content:center;
            border-radius:9px;
        }
        .theme-btn{
            width:38px;height:38px;border-radius:10px;
            border:1px solid var(--border);background:var(--surface);
            color:var(--text-2);cursor:pointer;
            display:flex;align-items:center;justify-content:center;
            transition:all .15s;
        }
        .theme-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}

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
           Alerts
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
        @media (min-width:1440px){
            .stat-grid{grid-template-columns:repeat(6, minmax(0, 1fr))}
        }
        @media (max-width:1100px){
            .filter-bar{grid-template-columns:1fr 1fr}
            .filter-bar .actions{grid-column:span 2;justify-content:flex-end}
        }
        @media (max-width:1024px){
            .sidebar{transform:translateX(-100%)}
            .sidebar.open{transform:translateX(0)}
            .main-content{margin-left:0}
            .menu-toggle{display:flex}
        }
        @media (max-width:640px){
            .stat-grid{grid-template-columns:repeat(2, minmax(0, 1fr));gap:.75rem}
            .stat-card{padding:1rem 1rem 1rem 1.1rem;gap:.75rem}
            .stat-icon{width:38px;height:38px;font-size:1rem}
            .stat-info .stat-value{font-size:1.35rem}
            .filter-bar{grid-template-columns:1fr}
            .filter-bar .actions{grid-column:span 1}
            .card-body{padding:1rem}
        }
    </style>
</head>
<body>

<?php
    $__current = basename($_SERVER['PHP_SELF']);
    $__super   = (($_SESSION['admin_role'] ?? '') === 'superadmin');
    // Student sub-pages all highlight "Students"
    $__student_pages = ['student_profile.php','view_student.php','edit_student.php','export_students.php','sections_list.php','sf10.php'];
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
        <?php
    $__pending_count = (int)($conn->query("SELECT COUNT(*) c FROM enrollment_form WHERE status='Pending'")->fetch_assoc()['c'] ?? 0);
?>
<a href="pending_enrollments.php"<?= $__a('pending_enrollments.php') ?>>
    <i class="bi bi-hourglass-split"></i> Pending
    <?php if ($__pending_count > 0): ?>
        <span style="margin-left:auto;background:#d97706;color:#fff;font-size:.68rem;padding:.15rem .5rem;border-radius:999px;font-weight:700;line-height:1">
            <?= $__pending_count ?>
        </span>
    <?php endif; ?>
</a>
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

    <!-- ================= Alerts ================= -->
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

    <!-- ================= Top Bar ================= -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h2>Dashboard</h2>
                <p class="sub">Enrollment overview &amp; student management</p>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print
            </button>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <!-- ================= Stats ================= -->
    <div class="stat-grid">
        <div class="stat-card tone-blue">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_students) ?></div>
                <div class="stat-label">Total Students</div>
                <div class="stat-hint"><?= number_format($new_this_week) ?> added this week</div>
            </div>
        </div>
        <div class="stat-card tone-green">
            <div class="stat-icon"><i class="bi bi-mortarboard-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($active_enrollment) ?></div>
                <div class="stat-label">Active Enrolled</div>
                <div class="stat-hint"><?= number_format($total_enrolled) ?> total enrollments</div>
            </div>
        </div>
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="bi bi-diagram-3"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_sections) ?></div>
                <div class="stat-label">Sections</div>
                <div class="stat-hint">Across all grade levels</div>
            </div>
        </div>
        <div class="stat-card tone-amber">
            <div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($unassigned) ?></div>
                <div class="stat-label">Need Section</div>
                <div class="stat-hint">Students without section</div>
            </div>
        </div>
        <div class="stat-card tone-blue">
            <div class="stat-icon"><i class="bi bi-gender-male"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($boys) ?></div>
                <div class="stat-label">Male</div>
            </div>
        </div>
        <div class="stat-card tone-red">
            <div class="stat-icon"><i class="bi bi-gender-female"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($girls) ?></div>
                <div class="stat-label">Female</div>
            </div>
        </div>
    </div>

    <!-- ================= Charts ================= -->
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">
                    <h3><i class="bi bi-bar-chart"></i> Enrollment by Grade Level</h3>
                    <span class="hint">Student count per grade</span>
                </div>
                <div class="card-body">
                    <div class="chart-wrap"><canvas id="gradeChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header">
                    <h3><i class="bi bi-pie-chart"></i> Strand Distribution</h3>
                    <span class="hint">Active enrollments</span>
                </div>
                <div class="card-body">
                    <div class="chart-wrap"><canvas id="strandChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= Student Directory ================= -->
    <div class="card mt-3">
        <div class="card-header">
            <h3>
                <i class="bi bi-list-ul"></i> Student Directory
                <span class="hint">(<?= number_format($filtered_count) ?> result<?= $filtered_count === 1 ? '' : 's' ?>)</span>
            </h3>
        </div>
        <div class="card-body" style="padding:1rem 1.25rem;border-bottom:1px solid var(--border)">
            <form class="filter-bar" method="GET">
                <div class="field">
                    <label for="f_search">Search</label>
                    <input type="text" id="f_search" name="search" placeholder="Name, LRN, or student ID" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="field">
                    <label for="f_grade">Grade Level</label>
                    <select id="f_grade" name="grade_level" onchange="this.form.submit()">
                        <option value="">All Grades</option>
                        <?php while($gl=$grade_levels->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($gl['grade_level']) ?>" <?= $gl['grade_level']===$grade_filter?'selected':'' ?>>
                                <?= htmlspecialchars($gl['grade_level']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_sy">School Year</label>
                    <select id="f_sy" name="school_year" onchange="this.form.submit()">
                        <option value="">All Years</option>
                        <?php while($sy=$school_years->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $sy['school_year']===$sy_filter?'selected':'' ?>>
                                <?= htmlspecialchars($sy['school_year']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel me-1"></i> Filter
                    </button>
                    <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>
        <div class="card-body no-padding">
            <?php if($students->num_rows > 0): ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th style="width:44px">#</th>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>LRN</th>
                                <th>Grade</th>
                                <th>Strand</th>
                                <th>Section</th>
                                <th>Status</th>
                                <th style="text-align:right;width:80px">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $n=1; while($s=$students->fetch_assoc()):
                                $name = htmlspecialchars($s['last_name'].', '.$s['first_name'].($s['middle_name']?' '.$s['middle_name']:''));
                                $status = $s['status'] ?? 'Active';
                                $pillClass = $status === 'Active' ? 'success' : ($status === '' ? 'muted' : 'warning');
                            ?>
                            <tr>
                                <td class="cell-num"><?= $n++ ?></td>
                                <td class="cell-id"><?= htmlspecialchars($s['student_id_number']??'—') ?></td>
                                <td class="cell-name"><?= $name ?></td>
                                <td class="cell-muted"><?= htmlspecialchars($s['lrn']??'—') ?></td>
                                <td><?= htmlspecialchars($s['grade_level']??'—') ?></td>
                                <td class="cell-muted"><?= htmlspecialchars($s['strand']??'—') ?></td>
                                <td class="cell-accent"><?= htmlspecialchars($s['section']??'—') ?></td>
                                <td><span class="pill <?= $pillClass ?>"><?= htmlspecialchars($status ?: 'Unknown') ?></span></td>
                                <td style="text-align:right">
                                    <a href="view_student.php?id=<?= (int)$s['student_id'] ?>"
                                       class="btn btn-icon btn-outline-primary"
                                       title="View profile">
                                        <i class="bi bi-arrow-right-short" style="font-size:1.15rem"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-search"></i></div>
                    <div class="empty-title">No students found</div>
                    <div class="empty-sub">Try adjusting your search or filters.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================= Recent Enrollments ================= -->
    <div class="card mt-3">
        <div class="card-header">
            <h3><i class="bi bi-clock-history"></i> Recent Enrollments</h3>
            <span class="hint">Latest 8</span>
        </div>
        <div class="card-body no-padding">
            <?php if($recent && $recent->num_rows > 0): ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>LRN</th>
                                <th>Grade</th>
                                <th>Strand</th>
                                <th>Section</th>
                                <th>Date Enrolled</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($r=$recent->fetch_assoc()): ?>
                            <tr>
                                <td class="cell-id"><?= htmlspecialchars($r['student_id_number']??'—') ?></td>
                                <td class="cell-name"><?= htmlspecialchars($r['last_name'].', '.$r['first_name']) ?></td>
                                <td class="cell-muted"><?= htmlspecialchars($r['lrn']??'—') ?></td>
                                <td><?= htmlspecialchars($r['grade_level']??'—') ?></td>
                                <td class="cell-muted"><?= htmlspecialchars($r['strand']??'—') ?></td>
                                <td class="cell-accent"><?= htmlspecialchars($r['section']??'—') ?></td>
                                <td class="cell-muted"><?= htmlspecialchars(date('M d, Y', strtotime($r['enrolled_at']??''))) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                    <div class="empty-title">No recent enrollments</div>
                    <div class="empty-sub">New enrollments will appear here.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ================= Mobile overlay ================= -->
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

function cssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

// Re-apply dynamic CSS-var colors to charts on theme change
function refreshChartColors() {
    if (window.__gradeChart) {
        const g = window.__gradeChart;
        g.options.scales.x.ticks.color = cssVar('--text-2');
        g.options.scales.y.grid.color  = cssVar('--border');
        g.options.scales.y.ticks.color = cssVar('--text-3');
        g.update();
    }
    if (window.__strandChart) {
        const s = window.__strandChart;
        s.data.datasets[0].borderColor = cssVar('--surface');
        s.options.plugins.legend.labels.color = cssVar('--text-2');
        s.update();
    }
}

function st(t) {
    h.setAttribute('data-bs-theme', t);
    ti.className = 'bi bi-' + (t === 'dark' ? 'sun-fill' : 'moon-stars-fill');
    document.cookie = 'admin_theme=' + t + ';path=/;max-age=' + (60*60*24*365);
    refreshChartColors();
}
(function () {
    const m = document.cookie.match(/admin_theme=([^;]+)/);
    st(m ? m[1] : 'light');
})();
tb.addEventListener('click', () => st(h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));

// ---------- Charts ----------
const gradeCtx = document.getElementById('gradeChart').getContext('2d');
const gradeGradient = gradeCtx.createLinearGradient(0, 0, 0, 260);
gradeGradient.addColorStop(0, 'rgba(99,102,241,0.95)');
gradeGradient.addColorStop(1, 'rgba(79,70,229,0.55)');

window.__gradeChart = new Chart(gradeCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($grade_labels) ?>,
        datasets: [{
            label: 'Students',
            data: <?= json_encode($grade_data) ?>,
            backgroundColor: gradeGradient,
            hoverBackgroundColor: '#4f46e5',
            borderRadius: 8,
            borderSkipped: false,
            maxBarThickness: 48,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#0f172a',
                padding: 10,
                cornerRadius: 8,
                titleFont: { weight: '600' },
                displayColors: false,
            }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { color: cssVar('--text-2'), font: { size: 11, weight: '500' } }
            },
            y: {
                beginAtZero: true,
                grid: { color: cssVar('--border'), drawBorder: false },
                ticks: { color: cssVar('--text-3'), font: { size: 11 }, precision: 0 }
            }
        }
    }
});

window.__strandChart = new Chart(document.getElementById('strandChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($strand_labels) ?>,
        datasets: [{
            data: <?= json_encode($strand_data) ?>,
            backgroundColor: <?= json_encode($strand_colors) ?>,
            borderWidth: 3,
            borderColor: cssVar('--surface'),
            hoverOffset: 6,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 14,
                    usePointStyle: true,
                    pointStyle: 'circle',
                    color: cssVar('--text-2'),
                    font: { size: 11, weight: '500' },
                    boxWidth: 8,
                    boxHeight: 8,
                }
            },
            tooltip: {
                backgroundColor: '#0f172a',
                padding: 10,
                cornerRadius: 8,
                titleFont: { weight: '600' },
            }
        }
    }
});
</script>
</body>
</html>