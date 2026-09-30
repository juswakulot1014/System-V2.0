<?php
require_once __DIR__ . '/../config/auth.php';

$page_title = "Enrollment Reports";

// Check if we are exporting to Word
$export_word = isset($_GET['export_word']) && $_GET['export_word'] == 1;

// Get filters
$selected_sy = $_GET['school_year'] ?? '';
$strand_filter = $_GET['strand_filter'] ?? '';
$view_graduated = isset($_GET['view_graduated']);

$sy_filter = "";
$params_main = [];
$types_main = "";

if (!empty($selected_sy)) {
    $sy_filter = " AND e.school_year = ?";
    $params_main[] = $selected_sy;
    $types_main .= "s";
}
if (!empty($strand_filter)) {
    $sy_filter .= " AND e.strand = ?";
    $params_main[] = $strand_filter;
    $types_main .= "s";
}

// ====================== KPI QUERIES ======================
$ts_sql = "SELECT COUNT(DISTINCT e.student_id) as total FROM enrollment_form e WHERE 1=1 $sy_filter";
$stmt = $conn->prepare($ts_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute();
$total_students = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$tsec_sql = "SELECT COUNT(DISTINCT e.section) as total FROM enrollment_form e WHERE e.section IS NOT NULL AND TRIM(e.section) != '' $sy_filter";
$stmt = $conn->prepare($tsec_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute();
$total_sections = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$gen_sql = "SELECT SUM(CASE WHEN s.sex = 'Male' THEN 1 ELSE 0 END) as boys, SUM(CASE WHEN s.sex = 'Female' THEN 1 ELSE 0 END) as girls FROM enrollment_form e JOIN students_info s ON e.student_id = s.student_id WHERE 1=1 $sy_filter";
$stmt = $conn->prepare($gen_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute();
$gender = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
$boys = (int)($gender['boys'] ?? 0);
$girls = (int)($gender['girls'] ?? 0);
$total_gender = $boys + $girls;
$boy_percent = $total_gender > 0 ? round(($boys / $total_gender) * 100, 1) : 0;
$girl_percent = $total_gender > 0 ? round(($girls / $total_gender) * 100, 1) : 0;

$tstr_sql = "SELECT COUNT(DISTINCT e.strand) as total FROM enrollment_form e WHERE e.strand IS NOT NULL $sy_filter";
$stmt = $conn->prepare($tstr_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute();
$total_strands = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

// ====================== GRADUATED STUDENTS ======================
$graduated_students = [];
$total_graduated = 0;
if ($view_graduated) {
    $grad_sql = "SELECT s.student_id, s.student_id_number, s.lrn, s.last_name, s.first_name, s.middle_name, s.sex, e.grade_level, e.strand, e.program, e.section, e.school_year, e.status FROM students_info s JOIN enrollment_form e ON s.student_id = e.student_id WHERE e.status = 'Graduated'";
    $grad_params = []; $grad_types = "";
    if (!empty($selected_sy)) { $grad_sql .= " AND e.school_year = ?"; $grad_params[] = $selected_sy; $grad_types .= "s"; }
    if (!empty($strand_filter)) { $grad_sql .= " AND e.strand = ?"; $grad_params[] = $strand_filter; $grad_types .= "s"; }
    $grad_sql .= " ORDER BY s.last_name ASC, s.first_name ASC";
    $gs = $conn->prepare($grad_sql);
    if (!empty($grad_params)) $gs->bind_param($grad_types, ...$grad_params);
    $gs->execute();
    $graduated_students = $gs->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $total_graduated = count($graduated_students);
    $gs->close();
}

// ====================== REPORT QUERIES ======================
$grade_sql = "SELECT e.grade_level, COUNT(DISTINCT s.student_id) AS total_students, SUM(CASE WHEN s.sex = 'Male' THEN 1 ELSE 0 END) AS boys, SUM(CASE WHEN s.sex = 'Female' THEN 1 ELSE 0 END) AS girls FROM enrollment_form e JOIN students_info s ON e.student_id = s.student_id WHERE e.grade_level IS NOT NULL $sy_filter GROUP BY e.grade_level ORDER BY e.grade_level ASC";
$stmt = $conn->prepare($grade_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute();
$grade_report = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$strand_sql = "SELECT COALESCE(e.strand, 'General') AS strand, COALESCE(e.program, 'N/A') AS program, COUNT(DISTINCT s.student_id) AS total_students, SUM(CASE WHEN s.sex = 'Male' THEN 1 ELSE 0 END) AS boys, SUM(CASE WHEN s.sex = 'Female' THEN 1 ELSE 0 END) AS girls FROM enrollment_form e JOIN students_info s ON e.student_id = s.student_id WHERE 1=1 $sy_filter GROUP BY e.strand, e.program ORDER BY total_students DESC, strand ASC";
$stmt = $conn->prepare($strand_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute();
$strand_report = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$section_sql = "SELECT e.grade_level, e.section, COUNT(DISTINCT s.student_id) AS total_students, SUM(CASE WHEN s.sex = 'Male' THEN 1 ELSE 0 END) AS boys, SUM(CASE WHEN s.sex = 'Female' THEN 1 ELSE 0 END) AS girls FROM enrollment_form e JOIN students_info s ON e.student_id = s.student_id WHERE e.section IS NOT NULL AND TRIM(e.section) != '' $sy_filter GROUP BY e.grade_level, e.section ORDER BY e.grade_level ASC, e.section ASC";
$stmt = $conn->prepare($section_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute();
$section_report = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$year_sql = "SELECT e.school_year, COUNT(DISTINCT s.student_id) AS total_students, SUM(CASE WHEN s.sex = 'Male' THEN 1 ELSE 0 END) AS boys, SUM(CASE WHEN s.sex = 'Female' THEN 1 ELSE 0 END) AS girls FROM enrollment_form e JOIN students_info s ON e.student_id = s.student_id WHERE e.school_year IS NOT NULL $sy_filter GROUP BY e.school_year ORDER BY e.school_year DESC";
$stmt = $conn->prepare($year_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute();
$year_report = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$sy_list = $conn->query("SELECT DISTINCT school_year FROM enrollment_form ORDER BY school_year DESC")->fetch_all(MYSQLI_ASSOC) ?: [];
$strand_list = $conn->query("SELECT DISTINCT strand FROM enrollment_form WHERE strand IS NOT NULL AND strand != '' ORDER BY strand ASC")->fetch_all(MYSQLI_ASSOC) ?: [];

// ====================== CHART DATA ======================
$grade_labels = []; $grade_totals = []; $grade_boys = []; $grade_girls = [];
foreach($grade_report as $g){ $grade_labels[]=$g['grade_level']; $grade_totals[]=(int)$g['total_students']; $grade_boys[]=(int)$g['boys']; $grade_girls[]=(int)$g['girls']; }
$strand_labels = []; $strand_totals = [];
foreach($strand_report as $s){ $strand_labels[]=$s['strand'].($s['program']!='N/A'?' - '.$s['program']:''); $strand_totals[]=(int)$s['total_students']; }
$year_labels = []; $year_totals = [];
foreach(array_reverse($year_report) as $y){ $year_labels[]=$y['school_year']; $year_totals[]=(int)$y['total_students']; }

// ====================== WORD EXPORT ======================
if ($export_word) {
    header("Content-Type: application/msword");
    header("Content-Disposition: attachment; filename=enrollment_report_" . date('Y-m-d') . ".doc");
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Report</title><style>body{font-family:Arial;margin:2cm}h1{color:#1e3c72}h2{color:#2b4c8c;margin-top:20px;border-bottom:2px solid #2b4c8c}table{width:100%;border-collapse:collapse;margin:15px 0}th,td{border:1px solid #ddd;padding:8px}th{background:#f2f2f2}</style></head><body>';
    echo '<h1>USAT College Enrollment Report</h1><p>Generated: '.date('F d, Y h:i A').'</p>';
    if(!empty($selected_sy)) echo '<p><strong>School Year:</strong> '.htmlspecialchars($selected_sy).'</p>';
    if(!empty($strand_filter)) echo '<p><strong>Strand:</strong> '.htmlspecialchars($strand_filter).'</p>';
    if ($view_graduated) {
        echo '<h2>Graduated Students</h2><p>Total: '.$total_graduated.'</p>';
        echo '<table><tr><th>Student ID</th><th>LRN</th><th>Name</th><th>Sex</th><th>Strand</th><th>Section</th><th>School Year</th></tr>';
        foreach($graduated_students as $g) echo '<tr><td>'.htmlspecialchars($g['student_id_number']??'—').'</td><td>'.htmlspecialchars($g['lrn']??'—').'</td><td>'.htmlspecialchars($g['last_name'].', '.$g['first_name']).'</td><td>'.htmlspecialchars($g['sex']??'—').'</td><td>'.htmlspecialchars($g['strand']??'—').'</td><td>'.htmlspecialchars($g['section']??'—').'</td><td>'.htmlspecialchars($g['school_year']??'—').'</td></tr>';
        echo '</table>';
    } else {
        echo '<h2>Summary</h2><table><tr><th>Total Students</th><td>'.number_format($total_students).'</td><th>Total Sections</th><td>'.number_format($total_sections).'</td></tr><tr><th>Male</th><td>'.number_format($boys).' ('.$boy_percent.'%)</td><th>Female</th><td>'.number_format($girls).' ('.$girl_percent.'%)</td></tr></table>';
        echo '<h2>By Grade Level</h2><table><tr><th>Grade</th><th>Total</th><th>Male</th><th>Female</th></tr>';
        foreach($grade_report as $r) echo '<tr><td>'.htmlspecialchars($r['grade_level']).'</td><td>'.number_format($r['total_students']).'</td><td>'.number_format($r['boys']).'</td><td>'.number_format($r['girls']).'</td></tr>';
        echo '</table>';
    }
    echo '<p><em>End of Report</em></p></body></html>';
    exit;
}

$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports • USAT Admin</title>

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
            --blue: #2563eb;
            --pink: #ec4899;

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
            grid-template-columns:repeat(auto-fit,minmax(210px,1fr));
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
        .stat-card.tone-blue::before{background:var(--accent)}
        .stat-card.tone-green::before{background:var(--green)}
        .stat-card.tone-red::before{background:var(--red)}
        .stat-card.tone-purple::before{background:var(--purple)}
        .stat-card.tone-amber::before{background:var(--amber)}
        .stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);border-color:var(--border-strong)}

        .stat-icon{
            width:42px;height:42px;border-radius:12px;
            display:flex;align-items:center;justify-content:center;
            font-size:1.1rem;flex-shrink:0;
            background:var(--accent-soft);color:var(--accent);
        }
        .tone-green  .stat-icon{background:var(--green-soft);color:var(--green)}
        .tone-red    .stat-icon{background:var(--red-soft);color:var(--red)}
        .tone-purple .stat-icon{background:var(--purple-soft);color:var(--purple)}
        .tone-amber  .stat-icon{background:var(--amber-soft);color:var(--amber)}

        .stat-info{min-width:0;flex:1}
        .stat-info .stat-value{
            font-size:1.55rem;font-weight:700;line-height:1.1;
            letter-spacing:-.02em;font-variant-numeric:tabular-nums;
        }
        .stat-info .stat-value .slash{color:var(--text-3);font-weight:500;margin:0 .1rem}
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
           Filter bar
           ============================================================ */
        .filter-bar{
            display:grid;
            grid-template-columns:minmax(180px,1fr) minmax(200px,1fr) auto;
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
        .filter-bar input:focus,.filter-bar select:focus{
            border-color:var(--accent);background:var(--surface);
            box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
            outline:none;
        }
        .filter-bar .actions{display:flex;gap:.5rem;flex-wrap:nowrap}

        /* ============================================================
           Charts
           ============================================================ */
        .chart-wrap{position:relative;height:280px}
        .chart-wrap.tall{height:260px}
        .chart-wrap canvas{max-height:100%}

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
            white-space:nowrap;position:sticky;top:0;z-index:1;
        }
        .table-admin tbody td{
            padding:.65rem 1rem;border-bottom:1px solid var(--border);
            font-size:.85rem;vertical-align:middle;
            transition:background .12s;
        }
        .table-admin tbody tr:hover td{background:color-mix(in srgb, var(--accent) 4%, transparent)}
        .table-admin tbody tr:last-child td{border-bottom:none}
        .table-admin tfoot td{
            padding:.7rem 1rem;background:var(--surface-2);
            font-weight:700;font-size:.85rem;border-top:2px solid var(--border);
            font-variant-numeric:tabular-nums;
        }
        .cell-strong{font-weight:600;color:var(--text)}
        .cell-muted{color:var(--text-2);font-size:.82rem}
        .cell-center{text-align:center}
        .cell-right{text-align:right}
        .cell-num{
            font-variant-numeric:tabular-nums;font-weight:600;
            color:var(--text);font-size:.85rem;
        }
        .cell-male{color:var(--blue);font-weight:600;font-variant-numeric:tabular-nums}
        .cell-female{color:var(--pink);font-weight:600;font-variant-numeric:tabular-nums}
        .cell-accent{color:var(--accent);font-weight:600}
        .cell-ratio{font-variant-numeric:tabular-nums;color:var(--text-2)}

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
        .btn-outline-success{color:var(--green);border-color:color-mix(in srgb, var(--green) 35%, transparent)}
        .btn-outline-success:hover{background:var(--green);color:#fff;border-color:var(--green)}
        .btn-grad{
            background:linear-gradient(135deg,var(--green),#047857);
            color:#fff;border:none;font-weight:600;
            box-shadow:0 4px 12px -4px rgba(5,150,105,.5);
        }
        .btn-grad:hover{background:linear-gradient(135deg,#047857,#065f46);color:#fff;transform:translateY(-1px)}
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
           Responsive
           ============================================================ */
        @media (max-width:1100px){
            .filter-bar{grid-template-columns:1fr 1fr}
            .filter-bar .actions{grid-column:1 / -1;justify-content:flex-end}
        }
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
            .filter-bar{grid-template-columns:1fr}
            .filter-bar .actions{grid-column:span 1;justify-content:stretch}
            .filter-bar .actions .btn{flex:1}
            .chart-wrap{height:220px}
        }
    </style>
</head>
<body>

<!-- ================= Sidebar ================= -->
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

    <!-- ================= Top Bar ================= -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h2><?= $view_graduated ? 'Graduated Students' : 'Enrollment Reports' ?></h2>
                <p class="sub"><?= $view_graduated ? 'List of all graduated students' : 'Enrollment statistics &amp; summaries' ?></p>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if($view_graduated): ?>
                <a href="reports.php?<?= http_build_query(array_filter(['school_year'=>$selected_sy,'strand_filter'=>$strand_filter])) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back to Reports
                </a>
            <?php endif; ?>
            <a href="?export_word=1<?= !empty($selected_sy)?'&school_year='.urlencode($selected_sy):'' ?><?= !empty($strand_filter)?'&strand_filter='.urlencode($strand_filter):'' ?><?= $view_graduated?'&view_graduated=1':'' ?>" class="btn btn-outline-success btn-sm">
                <i class="bi bi-file-word me-1"></i> Export
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i> Print
            </button>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <!-- ================= Filters ================= -->
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-funnel"></i> Filters</h3>
            <?php if($selected_sy || $strand_filter): ?>
                <span class="hint"><i class="bi bi-funnel-fill me-1"></i> Active filters applied</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-bar">
                <div class="field">
                    <label for="f_sy">School Year</label>
                    <select name="school_year" id="f_sy">
                        <option value="">All Years</option>
                        <?php foreach($sy_list as $sy): ?>
                            <option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $selected_sy==$sy['school_year']?'selected':'' ?>>
                                <?= htmlspecialchars($sy['school_year']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_strand">Strand</label>
                    <select name="strand_filter" id="f_strand">
                        <option value="">All Strands</option>
                        <?php foreach($strand_list as $st): ?>
                            <option value="<?= htmlspecialchars($st['strand']) ?>" <?= $strand_filter==$st['strand']?'selected':'' ?>>
                                <?= htmlspecialchars($st['strand']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2 me-1"></i> Apply
                    </button>
                    <a href="reports.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i> Reset
                    </a>
                    <?php if(!$view_graduated): ?>
                        <a href="reports.php?view_graduated=1<?= !empty($selected_sy)?'&school_year='.urlencode($selected_sy):'' ?><?= !empty($strand_filter)?'&strand_filter='.urlencode($strand_filter):'' ?>" class="btn btn-grad btn-sm">
                            <i class="bi bi-mortarboard me-1"></i> View Graduated
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if($view_graduated): ?>
    <!-- ================= Graduated View ================= -->
    <div class="stat-grid">
        <div class="stat-card tone-green">
            <div class="stat-icon"><i class="bi bi-mortarboard-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_graduated) ?></div>
                <div class="stat-label">Total Graduated</div>
            </div>
        </div>
        <div class="stat-card tone-blue">
            <div class="stat-icon"><i class="bi bi-calendar-range"></i></div>
            <div class="stat-info">
                <div class="stat-value" style="font-size:1.15rem"><?= htmlspecialchars($selected_sy?:'All Years') ?></div>
                <div class="stat-label">School Year</div>
            </div>
        </div>
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="bi bi-tags"></i></div>
            <div class="stat-info">
                <div class="stat-value" style="font-size:1.15rem"><?= htmlspecialchars($strand_filter?:'All Strands') ?></div>
                <div class="stat-label">Strand</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-mortarboard"></i> Graduated Students <span class="hint">(<?= number_format($total_graduated) ?>)</span></h3>
        </div>
        <div class="card-body no-padding">
            <?php if(!empty($graduated_students)): ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>LRN</th>
                                <th>Name</th>
                                <th>Sex</th>
                                <th>Strand</th>
                                <th>Program</th>
                                <th>Section</th>
                                <th>School Year</th>
                                <th style="text-align:right;width:80px">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($graduated_students as $g): ?>
                            <tr>
                                <td class="cell-num"><?= htmlspecialchars($g['student_id_number']??'—') ?></td>
                                <td class="cell-muted"><?= htmlspecialchars($g['lrn']??'—') ?></td>
                                <td class="cell-strong"><?= htmlspecialchars($g['last_name'].', '.$g['first_name']) ?></td>
                                <td><?= htmlspecialchars($g['sex']??'—') ?></td>
                                <td><?= htmlspecialchars($g['strand']??'—') ?></td>
                                <td class="cell-muted"><?= htmlspecialchars($g['program']??'—') ?></td>
                                <td class="cell-accent"><?= htmlspecialchars($g['section']??'—') ?></td>
                                <td class="cell-muted"><?= htmlspecialchars($g['school_year']??'—') ?></td>
                                <td style="text-align:right">
                                    <a href="view_student.php?id=<?= (int)$g['student_id'] ?>"
                                       class="btn btn-icon btn-outline-primary"
                                       title="View profile">
                                        <i class="bi bi-arrow-right-short" style="font-size:1.15rem"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-mortarboard"></i></div>
                    <div class="empty-title">No graduated students found</div>
                    <div class="empty-sub">Try adjusting the school year or strand filters.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php else: ?>
    <!-- ================= Reports View ================= -->
    <div class="stat-grid">
        <div class="stat-card tone-blue">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_students) ?></div>
                <div class="stat-label">Total Students</div>
            </div>
        </div>
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="bi bi-diagram-3"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_sections) ?></div>
                <div class="stat-label">Sections</div>
            </div>
        </div>
        <div class="stat-card tone-green">
            <div class="stat-icon"><i class="bi bi-gender-ambiguous"></i></div>
            <div class="stat-info">
                <div class="stat-value">
                    <?= number_format($boys) ?><span class="slash">/</span><?= number_format($girls) ?>
                </div>
                <div class="stat-label">Male / Female</div>
                <div class="stat-hint"><?= $boy_percent ?>% / <?= $girl_percent ?>%</div>
            </div>
        </div>
        <div class="stat-card tone-amber">
            <div class="stat-icon"><i class="bi bi-tags"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_strands) ?></div>
                <div class="stat-label">Strands</div>
            </div>
        </div>
    </div>

    <!-- ================= Charts ================= -->
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <h3><i class="bi bi-bar-chart"></i> By Grade Level</h3>
                    <span class="hint">Male vs Female</span>
                </div>
                <div class="card-body">
                    <div class="chart-wrap"><canvas id="gradeChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <h3><i class="bi bi-pie-chart"></i> By Strand</h3>
                    <span class="hint">Distribution</span>
                </div>
                <div class="card-body">
                    <div class="chart-wrap"><canvas id="strandChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3><i class="bi bi-graph-up"></i> Yearly Trend</h3>
                    <span class="hint">Total students per school year</span>
                </div>
                <div class="card-body">
                    <div class="chart-wrap tall"><canvas id="trendChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= Breakdown Tables ================= -->
    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3><i class="bi bi-list-ol"></i> Grade Level Breakdown</h3></div>
                <div class="card-body no-padding">
                    <div class="table-scroll">
                        <table class="table-admin">
                            <thead>
                                <tr>
                                    <th>Grade</th>
                                    <th class="cell-center">Total</th>
                                    <th class="cell-center">Male</th>
                                    <th class="cell-center">Female</th>
                                    <th class="cell-center">Ratio</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if(!empty($grade_report)): foreach($grade_report as $r):
                                $ratio = $r['girls']>0 ? round($r['boys']/$r['girls'],2) : null;
                            ?>
                                <tr>
                                    <td class="cell-strong"><?= htmlspecialchars($r['grade_level']) ?></td>
                                    <td class="cell-center cell-num"><?= number_format($r['total_students']) ?></td>
                                    <td class="cell-center cell-male"><?= number_format($r['boys']) ?></td>
                                    <td class="cell-center cell-female"><?= number_format($r['girls']) ?></td>
                                    <td class="cell-center cell-ratio"><?= $ratio !== null ? $ratio . ':1' : '—' ?></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="5">
                                    <div class="empty">
                                        <div class="empty-icon"><i class="bi bi-bar-chart"></i></div>
                                        <div class="empty-title">No data</div>
                                        <div class="empty-sub">No grade level records for this filter.</div>
                                    </div>
                                </td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3><i class="bi bi-tags"></i> Strand &amp; Program</h3></div>
                <div class="card-body no-padding">
                    <div class="table-scroll">
                        <table class="table-admin">
                            <thead>
                                <tr>
                                    <th>Strand</th>
                                    <th>Program</th>
                                    <th class="cell-center">Total</th>
                                    <th class="cell-center">Male</th>
                                    <th class="cell-center">Female</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if(!empty($strand_report)): foreach($strand_report as $r): ?>
                                <tr>
                                    <td class="cell-strong"><?= htmlspecialchars($r['strand']) ?></td>
                                    <td class="cell-muted"><?= htmlspecialchars($r['program']) ?></td>
                                    <td class="cell-center cell-num"><?= number_format($r['total_students']) ?></td>
                                    <td class="cell-center cell-male"><?= number_format($r['boys']) ?></td>
                                    <td class="cell-center cell-female"><?= number_format($r['girls']) ?></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="5">
                                    <div class="empty">
                                        <div class="empty-icon"><i class="bi bi-tags"></i></div>
                                        <div class="empty-title">No data</div>
                                        <div class="empty-sub">No strand records for this filter.</div>
                                    </div>
                                </td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header"><h3><i class="bi bi-diagram-3"></i> Sections</h3></div>
                <div class="card-body no-padding">
                    <div class="table-scroll">
                        <table class="table-admin">
                            <thead>
                                <tr>
                                    <th>Grade</th>
                                    <th>Section</th>
                                    <th class="cell-center">Total</th>
                                    <th class="cell-center">Male</th>
                                    <th class="cell-center">Female</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if(!empty($section_report)): foreach($section_report as $r): ?>
                                <tr>
                                    <td class="cell-muted"><?= htmlspecialchars($r['grade_level']) ?></td>
                                    <td class="cell-accent"><?= htmlspecialchars($r['section']) ?></td>
                                    <td class="cell-center cell-num"><?= number_format($r['total_students']) ?></td>
                                    <td class="cell-center cell-male"><?= number_format($r['boys']) ?></td>
                                    <td class="cell-center cell-female"><?= number_format($r['girls']) ?></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="5">
                                    <div class="empty">
                                        <div class="empty-icon"><i class="bi bi-diagram-3"></i></div>
                                        <div class="empty-title">No sections</div>
                                        <div class="empty-sub">No section records for this filter.</div>
                                    </div>
                                </td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header"><h3><i class="bi bi-calendar-range"></i> Yearly Trend</h3></div>
                <div class="card-body no-padding">
                    <div class="table-scroll">
                        <table class="table-admin">
                            <thead>
                                <tr>
                                    <th>School Year</th>
                                    <th class="cell-center">Total</th>
                                    <th class="cell-center">Male</th>
                                    <th class="cell-center">Female</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if(!empty($year_report)): foreach($year_report as $r): ?>
                                <tr>
                                    <td class="cell-strong"><?= htmlspecialchars($r['school_year']) ?></td>
                                    <td class="cell-center cell-num"><?= number_format($r['total_students']) ?></td>
                                    <td class="cell-center cell-male"><?= number_format($r['boys']) ?></td>
                                    <td class="cell-center cell-female"><?= number_format($r['girls']) ?></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="4">
                                    <div class="empty">
                                        <div class="empty-icon"><i class="bi bi-calendar-range"></i></div>
                                        <div class="empty-title">No yearly records</div>
                                        <div class="empty-sub">No school year data for this filter.</div>
                                    </div>
                                </td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
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

function cssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}
function axisGrid() {
    return { color: cssVar('--border'), drawBorder: false };
}
function tickColor() {
    return cssVar('--text-3');
}
function tooltipStyle() {
    return {
        backgroundColor: '#0f172a',
        padding: 10,
        cornerRadius: 8,
        titleFont: { weight: '600' },
        titleColor: '#f1f5f9',
        bodyColor: '#e2e8f0'
    };
}

// Re-apply dynamic CSS-var colors to charts on theme change
function refreshChartColors() {
    if (window.__gradeChart) {
        const g = window.__gradeChart;
        g.options.scales.x.ticks.color = tickColor();
        g.options.scales.y.grid.color  = cssVar('--border');
        g.options.scales.y.ticks.color = tickColor();
        g.options.plugins.legend.labels.color = cssVar('--text-2');
        g.update();
    }
    if (window.__strandChart) {
        const s = window.__strandChart;
        s.data.datasets[0].borderColor = cssVar('--surface');
        s.options.plugins.legend.labels.color = cssVar('--text-2');
        s.update();
    }
    if (window.__trendChart) {
        const t = window.__trendChart;
        t.options.scales.x.ticks.color = tickColor();
        t.options.scales.y.grid.color  = cssVar('--border');
        t.options.scales.y.ticks.color = tickColor();
        t.update();
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

<?php if(!$view_graduated): ?>
// ---------- Grade chart ----------
const gradeCtx = document.getElementById('gradeChart').getContext('2d');
const maleGrad   = gradeCtx.createLinearGradient(0, 0, 0, 280);
maleGrad.addColorStop(0, 'rgba(79,70,229,0.95)');
maleGrad.addColorStop(1, 'rgba(79,70,229,0.55)');
const femaleGrad = gradeCtx.createLinearGradient(0, 0, 0, 280);
femaleGrad.addColorStop(0, 'rgba(236,72,153,0.95)');
femaleGrad.addColorStop(1, 'rgba(236,72,153,0.55)');

window.__gradeChart = new Chart(gradeCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($grade_labels) ?>,
        datasets: [
            {
                label: 'Male',
                data: <?= json_encode($grade_boys) ?>,
                backgroundColor: maleGrad,
                hoverBackgroundColor: '#4f46e5',
                borderRadius: 6,
                borderSkipped: false,
                maxBarThickness: 36,
            },
            {
                label: 'Female',
                data: <?= json_encode($grade_girls) ?>,
                backgroundColor: femaleGrad,
                hoverBackgroundColor: '#ec4899',
                borderRadius: 6,
                borderSkipped: false,
                maxBarThickness: 36,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    pointStyle: 'circle',
                    boxWidth: 8, boxHeight: 8,
                    padding: 16,
                    color: cssVar('--text-2'),
                    font: { size: 11, weight: '500' }
                }
            },
            tooltip: tooltipStyle()
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { color: tickColor(), font: { size: 11, weight: '500' } }
            },
            y: {
                beginAtZero: true,
                grid: axisGrid(),
                ticks: { color: tickColor(), font: { size: 11 }, precision: 0 }
            }
        }
    }
});

// ---------- Strand chart ----------
window.__strandChart = new Chart(document.getElementById('strandChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($strand_labels) ?>,
        datasets: [{
            data: <?= json_encode($strand_totals) ?>,
            backgroundColor: ['#4f46e5','#7c3aed','#6366f1','#818cf8','#a5b4fc','#c7d2fe','#8b5cf6','#6d28d9'],
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
                    usePointStyle: true,
                    pointStyle: 'circle',
                    boxWidth: 8, boxHeight: 8,
                    padding: 14,
                    color: cssVar('--text-2'),
                    font: { size: 11, weight: '500' }
                }
            },
            tooltip: tooltipStyle()
        }
    }
});

// ---------- Trend chart ----------
const trendCtx = document.getElementById('trendChart').getContext('2d');
const trendGrad = trendCtx.createLinearGradient(0, 0, 0, 260);
trendGrad.addColorStop(0, 'rgba(79,70,229,0.25)');
trendGrad.addColorStop(1, 'rgba(79,70,229,0)');

window.__trendChart = new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode($year_labels) ?>,
        datasets: [{
            label: 'Students',
            data: <?= json_encode($year_totals) ?>,
            borderColor: '#4f46e5',
            borderWidth: 2.5,
            backgroundColor: trendGrad,
            fill: true,
            tension: 0.35,
            pointRadius: 5,
            pointHoverRadius: 7,
            pointBackgroundColor: '#4f46e5',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: tooltipStyle()
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: { color: tickColor(), font: { size: 11, weight: '500' } }
            },
            y: {
                beginAtZero: true,
                grid: axisGrid(),
                ticks: { color: tickColor(), font: { size: 11 }, precision: 0 }
            }
        }
    }
});
<?php endif; ?>
</script>
</body>
</html>