<?php
require_once __DIR__ . '/../config/auth.php';

// Check if we are exporting to Word
$export_word = isset($_GET['export_word']) && $_GET['export_word'] == 1;

// Get filters
$school_year = trim($_GET['school_year'] ?? '');
$grade_level = trim($_GET['grade_level'] ?? '');
$program     = trim($_GET['program'] ?? '');
$section     = trim($_GET['section'] ?? '');

$show_student_list = !empty($section);

// ====================== SECTIONS SUMMARY ======================
$section_result = null;
$students_result = null;

if (!$show_student_list) {
    $where = "WHERE e.section IS NOT NULL AND e.section != ''";
    $params = [];
    $types = "";

    if ($school_year) { $where .= " AND e.school_year = ?"; $params[] = $school_year; $types .= "s"; }
    if ($grade_level) { $where .= " AND e.grade_level = ?"; $params[] = $grade_level; $types .= "s"; }
    if ($program)     { $where .= " AND e.program = ?";     $params[] = $program;     $types .= "s"; }

    $sql = "SELECT e.section, e.program, e.grade_level, e.school_year, COUNT(DISTINCT s.student_id) as total, SUM(CASE WHEN s.sex = 'Male' THEN 1 ELSE 0 END) as boys, SUM(CASE WHEN s.sex = 'Female' THEN 1 ELSE 0 END) as girls FROM enrollment_form e JOIN students_info s ON e.student_id = s.student_id $where GROUP BY e.section, e.program, e.grade_level, e.school_year ORDER BY e.grade_level ASC, e.program ASC, e.section ASC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $section_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $where = "WHERE e.section = ?";
    $params = [$section];
    $types = "s";

    if ($school_year) { $where .= " AND e.school_year = ?"; $params[] = $school_year; $types .= "s"; }
    if ($grade_level) { $where .= " AND e.grade_level = ?"; $params[] = $grade_level; $types .= "s"; }
    if ($program)     { $where .= " AND e.program = ?";     $params[] = $program;     $types .= "s"; }

    $sql = "SELECT e.section, e.program, e.grade_level, e.school_year, s.student_id, s.lrn, s.last_name, s.first_name, s.middle_name, s.ext_name, s.sex, s.photo, s.student_id_number FROM enrollment_form e JOIN students_info s ON e.student_id = s.student_id $where ORDER BY s.last_name ASC, s.first_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $students_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Filter options
$years    = $conn->query("SELECT DISTINCT school_year FROM enrollment_form ORDER BY school_year DESC")->fetch_all(MYSQLI_ASSOC);
$grades   = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form ORDER BY grade_level ASC")->fetch_all(MYSQLI_ASSOC);
$programs = $conn->query("SELECT DISTINCT program FROM enrollment_form WHERE program IS NOT NULL AND program != '' ORDER BY program ASC")->fetch_all(MYSQLI_ASSOC);

// ====================== WORD EXPORT ======================
if ($export_word) {
    header("Content-Type: application/msword");
    header("Content-Disposition: attachment; filename=sections_report_" . date('Y-m-d') . ".doc");
    header("Cache-Control: no-cache, must-revalidate");
    
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Report</title><style>body{font-family:Arial;margin:2cm}h1{color:#1e3c72}h2{color:#2b4c8c;margin-top:20px}table{width:100%;border-collapse:collapse;margin:15px 0}th,td{border:1px solid #ddd;padding:8px}th{background:#f2f2f2}</style></head><body>';
    echo '<h1>USAT College - Sections Report</h1><p>Generated: '.date('F d, Y h:i A').'</p>';
    
    if ($show_student_list && !empty($students_result)) {
        $first = $students_result[0];
        echo '<h2>Student List: '.htmlspecialchars($first['grade_level'].' - '.$first['section']).'</h2>';
        echo '<p>Program: '.htmlspecialchars($first['program']??'N/A').' | SY: '.htmlspecialchars($first['school_year']).'</p>';
        echo '<table><tr><th>LRN</th><th>Name</th><th>Sex</th></tr>';
        foreach($students_result as $r) echo '<tr><td>'.htmlspecialchars($r['lrn']??'—').'</td><td>'.htmlspecialchars(trim($r['last_name'].', '.$r['first_name'])).'</td><td>'.htmlspecialchars($r['sex']).'</td></tr>';
        echo '</table>';
    } elseif (!empty($section_result)) {
        echo '<h2>Sections Summary</h2><table><tr><th>Section</th><th>Grade</th><th>Program</th><th>Total</th><th>Boys</th><th>Girls</th></tr>';
        foreach($section_result as $s) echo '<tr><td>'.htmlspecialchars($s['section']).'</td><td>'.htmlspecialchars($s['grade_level']).'</td><td>'.htmlspecialchars($s['program']).'</td><td>'.$s['total'].'</td><td>'.$s['boys'].'</td><td>'.$s['girls'].'</td></tr>';
        echo '</table>';
    }
    echo '</body></html>';
    exit;
}

$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sections • USAT Admin</title>

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
        :root {
            --bg: #f1f5f9; --surface: #ffffff; --text: #1a1f36; --text2: #6b7280;
            --border: #e5e7eb; --accent: #4f46e5; --accent2: #6366f1;
            --green: #059669; --red: #dc2626; --blue: #2563eb;
            --shadow: 0 1px 3px rgba(0,0,0,0.06); --shadow-lg: 0 10px 25px rgba(0,0,0,0.08);
            --radius: 12px; --radius-lg: 16px;
        }
        [data-bs-theme="dark"] {
            --bg: #0f172a; --surface: #1e293b; --text: #f1f5f9; --text2: #94a3b8;
            --border: #334155; --accent: #818cf8; --accent2: #6366f1;
            --shadow: 0 1px 3px rgba(0,0,0,0.3); --shadow-lg: 0 10px 25px rgba(0,0,0,0.5);
        }

        *{margin:0;padding:0;box-sizing:border-box}
        body{
            font-family:'Inter',system-ui,sans-serif;
            background:var(--bg);color:var(--text);min-height:100vh;
        }
        
        .sidebar{position:fixed;left:0;top:0;bottom:0;width:260px;background:var(--surface);border-right:1px solid var(--border);z-index:200;display:flex;flex-direction:column;transition:transform 0.3s}
        .sidebar-brand{padding:1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:0.75rem}
        .sidebar-brand img{width:40px;height:40px;border-radius:10px}
        .sidebar-brand span{font-weight:700;font-size:1.1rem}
        .sidebar-nav{flex:1;padding:1rem 0.75rem;overflow-y:auto}
        .sidebar-nav a{display:flex;align-items:center;gap:0.75rem;padding:0.7rem 1rem;border-radius:10px;color:var(--text2);text-decoration:none;font-weight:500;font-size:0.9rem;transition:all 0.2s;margin-bottom:0.25rem}
        .sidebar-nav a:hover,.sidebar-nav a.active{background:var(--accent);color:white}
        .sidebar-nav a i{font-size:1.2rem;width:24px;text-align:center}
        .sidebar-footer{padding:1rem 0.75rem;border-top:1px solid var(--border)}
        
        .main-content{margin-left:260px;padding:1.5rem;min-height:100vh}
        .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem}
        .menu-toggle{display:none;width:40px;height:40px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;align-items:center;justify-content:center}
        
        .card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow);margin-bottom:1.25rem;overflow:hidden}
        .card-header{padding:1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
        .card-header h3{margin:0;font-size:0.95rem;font-weight:600;display:flex;align-items:center;gap:0.5rem}
        .card-header h3 i{color:var(--accent)}
        .card-body{padding:1.5rem}.card-body.no-padding{padding:0}
        
        .filter-bar{display:flex;gap:0.75rem;flex-wrap:wrap;align-items:end}
        .filter-bar input,.filter-bar select{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:0.5rem 1rem;font-size:0.85rem;color:var(--text);min-width:150px}
        
        .table-admin{width:100%;border-collapse:collapse}
        .table-admin th{background:var(--bg);font-size:0.7rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;padding:0.75rem 1rem;text-align:left;border-bottom:2px solid var(--border)}
        .table-admin td{padding:0.7rem 1rem;border-bottom:1px solid var(--border);font-size:0.85rem;vertical-align:middle}
        .table-admin tr:hover td{background:rgba(79,70,229,0.03);cursor:pointer}
        .table-admin tr:last-child td{border-bottom:none}
        
        .badge-pill{display:inline-flex;align-items:center;gap:0.3rem;padding:0.2rem 0.6rem;border-radius:50px;font-size:0.7rem;font-weight:600}
        .badge-pill.blue{background:#dbeafe;color:#1e40af}.badge-pill.pink{background:#fce7f3;color:#9d174d}
        [data-bs-theme="dark"] .badge-pill.blue{background:#1e3a5f;color:#93c5fd}[data-bs-theme="dark"] .badge-pill.pink{background:#831843;color:#f9a8d4}
        
        .theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;display:flex;align-items:center;justify-content:center}
        .theme-btn:hover{background:var(--accent);color:white;border-color:var(--accent)}
        .btn{font-weight:500;border-radius:8px}
        
        @media(max-width:1024px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0}.menu-toggle{display:flex}}
        @media(max-width:640px){.main-content{padding:1rem}.filter-bar{flex-direction:column}.filter-bar input,.filter-bar select{width:100%}}
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
            <div><h2 style="font-size:1.4rem;font-weight:700;margin:0">Sections & Students</h2><p class="text-muted small mb-0">Manage class sections and student lists</p></div>
        </div>
        <div class="d-flex gap-2">
            <?php $wp=['school_year'=>$school_year,'grade_level'=>$grade_level,'program'=>$program,'section'=>$section]; ?>
            <a href="?export_word=1&<?= http_build_query(array_filter($wp)) ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-file-word me-1"></i> Export</a>
            <button class="theme-btn" id="themeToggle"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>
        </div>
    </div>

    <!-- Filter -->
    <div class="card">
        <div class="card-header"><h3><i class="bi bi-funnel"></i> Filters</h3></div>
        <div class="card-body">
            <form class="filter-bar" method="GET">
                <select name="school_year"><option value="">All Years</option><?php foreach($years as $y): ?><option value="<?= htmlspecialchars($y['school_year']) ?>" <?= $school_year===$y['school_year']?'selected':'' ?>><?= htmlspecialchars($y['school_year']) ?></option><?php endforeach; ?></select>
                <select name="grade_level"><option value="">All Grades</option><?php foreach($grades as $g): ?><option value="<?= htmlspecialchars($g['grade_level']) ?>" <?= $grade_level===$g['grade_level']?'selected':'' ?>><?= htmlspecialchars($g['grade_level']) ?></option><?php endforeach; ?></select>
                <select name="program"><option value="">All Programs</option><?php foreach($programs as $p): ?><option value="<?= htmlspecialchars($p['program']) ?>" <?= $program===$p['program']?'selected':'' ?>><?= htmlspecialchars($p['program']) ?></option><?php endforeach; ?></select>
                <input type="text" name="section" placeholder="Section name..." value="<?= htmlspecialchars($section) ?>">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="sections_list.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </form>
        </div>
    </div>

    <?php if($show_student_list): ?>
        <!-- Student List View -->
        <?php if(!empty($students_result)): 
            $first = $students_result[0];
            $section_title = htmlspecialchars($first['grade_level'].' - '.$first['section']);
        ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="bi bi-people"></i> <?= $section_title ?> <span class="text-muted small">(<?= count($students_result) ?> students)</span></h3>
                <a href="sections_list.php?<?= http_build_query(array_filter(['school_year'=>$school_year,'grade_level'=>$grade_level,'program'=>$program])) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> All Sections</a>
            </div>
            <div class="card-body no-padding">
                <table class="table-admin">
                    <thead><tr><th>Student ID</th><th>LRN</th><th>Name</th><th>Sex</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach($students_result as $r): ?>
                        <tr onclick="window.location='view_student.php?id=<?= $r['student_id'] ?>'">
                            <td class="fw-semibold small"><?= htmlspecialchars($r['student_id_number']??'—') ?></td>
                            <td class="small"><?= htmlspecialchars($r['lrn']??'—') ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars(trim($r['last_name'].', '.$r['first_name'].' '.($r['middle_name']??''))) ?></td>
                            <td><span class="badge-pill <?= $r['sex']=='Male'?'blue':'pink' ?>"><?= $r['sex'] ?></span></td>
                            <td><a href="view_student.php?id=<?= $r['student_id'] ?>" class="btn btn-outline-primary btn-xs" onclick="event.stopPropagation()"><i class="bi bi-eye me-1"></i> View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="card"><div class="card-body text-center py-5 text-muted"><i class="bi bi-search display-4 d-block mb-3"></i>No students found for "<?= htmlspecialchars($section) ?>"</div></div>
        <?php endif; ?>

    <?php else: ?>
        <!-- Sections Summary -->
        <div class="card">
            <div class="card-header"><h3><i class="bi bi-diagram-3"></i> Sections (<?= count($section_result) ?>)</h3></div>
            <div class="card-body no-padding">
                <?php if(!empty($section_result)): ?>
                <table class="table-admin">
                    <thead><tr><th>Section</th><th>Grade</th><th>Program</th><th class="text-center">Total</th><th class="text-center">Boys</th><th class="text-center">Girls</th><th class="text-center">Action</th></tr></thead>
                    <tbody>
                        <?php foreach($section_result as $s): ?>
                        <tr>
                            <td class="fw-bold" style="color:var(--accent)"><?= htmlspecialchars($s['section']) ?></td>
                            <td><?= htmlspecialchars($s['grade_level']??'—') ?></td>
                            <td><?= htmlspecialchars($s['program']??'—') ?></td>
                            <td class="fw-semibold text-center"><?= $s['total'] ?></td>
                            <td class="text-center" style="color:var(--blue)"><?= $s['boys'] ?></td>
                            <td class="text-center" style="color:var(--red)"><?= $s['girls'] ?></td>
                            <td class="text-center"><a href="sections_list.php?section=<?= urlencode($s['section']) ?>&school_year=<?= urlencode($school_year) ?>&grade_level=<?= urlencode($grade_level) ?>&program=<?= urlencode($program) ?>" class="btn btn-outline-primary btn-xs"><i class="bi bi-eye me-1"></i> View Students</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="text-center py-5 text-muted"><i class="bi bi-table display-4 d-block mb-3"></i>No sections found</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:199" onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sb=document.getElementById('sidebar'),ov=document.getElementById('sidebarOverlay');
document.getElementById('menuToggle').addEventListener('click',()=>{sb.classList.toggle('open');ov.style.display=sb.classList.contains('open')?'block':'none'});
const tb=document.getElementById('themeToggle'),ti=document.getElementById('themeIcon'),h=document.documentElement;
function st(t){h.setAttribute('data-bs-theme',t);ti.className='bi bi-'+(t==='dark'?'sun-fill':'moon-stars-fill');document.cookie='admin_theme='+t+';path=/;max-age='+60*60*24*365}
(function(){const m=document.cookie.match(/(?:^|; )admin_theme=([^;]+)/);st(m?decodeURIComponent(m[1]):'light')})();
tb.addEventListener('click',()=>st(h.getAttribute('data-bs-theme')==='dark'?'light':'dark'));
</script>
</body>
</html>