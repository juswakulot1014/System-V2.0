<?php
// shared/pages/sections_list.php
require_once __DIR__ . '/../../config/auth.php';

$__nav_section = 'students';

/* ═══ EXPORT MODES ═══ */
$export_word = isset($_GET['export_word']) && $_GET['export_word'] == 1;
$export_pdf  = isset($_GET['export_pdf'])  && $_GET['export_pdf']  == 1;

/* ═══ FILTERS ═══ */
$school_year = trim($_GET['school_year'] ?? '');
$grade_level = trim($_GET['grade_level'] ?? '');
$program     = trim($_GET['program'] ?? '');
$section     = trim($_GET['section'] ?? '');

$show_student_list = !empty($section);

/* ═══ DATA ═══ */
$section_result  = null;
$students_result = null;

if (!$show_student_list) {
    $where = "WHERE e.section IS NOT NULL AND e.section != ''";
    $where .= " AND COALESCE(e.status, 'Active') NOT IN ('Graduated', 'Pending')";
    $params = [];
    $types = "";

    if ($school_year) { $where .= " AND e.school_year = ?"; $params[] = $school_year; $types .= "s"; }
    if ($grade_level) { $where .= " AND e.grade_level = ?"; $params[] = $grade_level; $types .= "s"; }
    if ($program)     { $where .= " AND e.program = ?";     $params[] = $program;     $types .= "s"; }

    $sql = "SELECT e.section, e.program, e.grade_level, e.school_year,
                   COUNT(DISTINCT s.student_id) as total,
                   SUM(CASE WHEN s.sex = 'Male' THEN 1 ELSE 0 END) as boys,
                   SUM(CASE WHEN s.sex = 'Female' THEN 1 ELSE 0 END) as girls
            FROM enrollment_form e
            JOIN students_info s ON e.student_id = s.student_id
            $where
            GROUP BY e.section, e.program, e.grade_level, e.school_year
            ORDER BY e.grade_level ASC, e.program ASC, e.section ASC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $section_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $where = "WHERE e.section = ?";
    $where .= " AND COALESCE(e.status, 'Active') NOT IN ('Graduated', 'Pending')";
    $params = [$section];
    $types = "s";

    if ($school_year) { $where .= " AND e.school_year = ?"; $params[] = $school_year; $types .= "s"; }
    if ($grade_level) { $where .= " AND e.grade_level = ?"; $params[] = $grade_level; $types .= "s"; }
    if ($program)     { $where .= " AND e.program = ?";     $params[] = $program;     $types .= "s"; }

    $sql = "SELECT e.section, e.program, e.grade_level, e.school_year,
                   s.student_id, s.lrn, s.last_name, s.first_name, s.middle_name,
                   s.ext_name, s.sex, s.photo, s.student_id_number
            FROM enrollment_form e
            JOIN students_info s ON e.student_id = s.student_id
            $where
            ORDER BY s.last_name ASC, s.first_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $students_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* ═══ DROPDOWN DATA ═══ */
$years    = $conn->query("SELECT DISTINCT school_year FROM enrollment_form ORDER BY school_year DESC")->fetch_all(MYSQLI_ASSOC);
$grades   = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form ORDER BY grade_level ASC")->fetch_all(MYSQLI_ASSOC);
$programs = $conn->query("SELECT DISTINCT program FROM enrollment_form WHERE program IS NOT NULL AND program != '' ORDER BY program ASC")->fetch_all(MYSQLI_ASSOC);

/* ═══ STATS — summary view ═══ */
$stat_total_sections = 0;
$stat_total_students = 0;
$stat_boys  = 0;
$stat_girls = 0;
if (!$show_student_list && !empty($section_result)) {
    foreach ($section_result as $r) {
        $stat_total_sections++;
        $stat_total_students += (int)$r['total'];
        $stat_boys  += (int)$r['boys'];
        $stat_girls += (int)$r['girls'];
    }
}

/* ═══ STATS — student list view ═══ */
$sec_title = ''; $sec_grade = ''; $sec_program = ''; $sec_sy = '';
$sec_count = 0; $sec_boys = 0; $sec_girls = 0;
if ($show_student_list && !empty($students_result)) {
    $first       = $students_result[0];
    $sec_title   = $first['section'] ?? '';
    $sec_grade   = $first['grade_level'] ?? '';
    $sec_program = $first['program'] ?? '';
    $sec_sy      = $first['school_year'] ?? '';
    $sec_count   = count($students_result);
    foreach ($students_result as $r) {
        if ($r['sex'] === 'Male')   $sec_boys++;
        if ($r['sex'] === 'Female') $sec_girls++;
    }
}

$filter_desc = [];
if ($school_year) $filter_desc[] = "School Year: $school_year";
if ($grade_level) $filter_desc[] = "Grade: $grade_level";
if ($program)     $filter_desc[] = "Program: $program";
if ($section)     $filter_desc[] = "Section: $section";
$filter_text = $filter_desc ? implode(' · ', $filter_desc) : 'All Records (excl. Graduated & Pending)';

/* ═══ EXPORT BUILDER (guarded) ═══ */
if (!function_exists('build_export_html')) {
    function build_export_html(
        bool $show_student_list,
        array $section_result,
        array $students_result,
        string $filter_text,
        string $sec_title,
        string $sec_grade,
        string $sec_program,
        string $sec_sy,
        int $sec_count,
        int $sec_boys,
        int $sec_girls,
        int $stat_total_sections,
        int $stat_total_students,
        int $stat_boys,
        int $stat_girls
    ): string {
        $now  = date('F d, Y h:i A');
        $year = date('Y');

        $css = "
            @page { margin: 14mm; }
            * { box-sizing: border-box; }
            body { font-family: Arial, Helvetica, sans-serif; margin: 0; color: #1a1a1a; font-size: 11px; line-height: 1.5; }
            h1 { color: #1e3c72; font-size: 20px; margin: 0 0 4px; letter-spacing: 0.5px; }
            h2 { color: #2b4c8c; font-size: 15px; margin: 22px 0 8px; border-bottom: 2px solid #2b4c8c; padding-bottom: 5px; }
            h3 { color: #334155; font-size: 13px; margin: 14px 0 6px; }
            .header { text-align: center; padding-bottom: 14px; border-bottom: 3px double #1e3c72; margin-bottom: 16px; }
            .header .sub { font-size: 11px; color: #64748b; margin-top: 3px; }
            .header .school { font-size: 17px; font-weight: bold; color: #1e3c72; letter-spacing: 1.5px; margin: 5px 0 2px; }
            .meta { background: #f8fafc; border-left: 4px solid #4f46e5; padding: 9px 14px; margin: 10px 0 16px; font-size: 10.5px; }
            .meta strong { color: #1e3c72; }
            table { width: 100%; border-collapse: collapse; margin: 8px 0 14px; page-break-inside: auto; }
            tr { page-break-inside: avoid; }
            thead { display: table-header-group; }
            th { background: #eef2ff; color: #1e3a72; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; padding: 8px 8px; text-align: left; border-bottom: 2px solid #c7d2fe; }
            td { padding: 7px 8px; font-size: 10.5px; border-bottom: 1px solid #e5e7eb; }
            tr:nth-child(even) td { background: #f9fafb; }
            .num { text-align: center; font-variant-numeric: tabular-nums; }
            .male { color: #1e40af; font-weight: bold; text-align: center; }
            .female { color: #be185d; font-weight: bold; text-align: center; }
            .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; background: #eef2ff; color: #4338ca; font-size: 9.5px; font-weight: bold; }
            .badge-male { background: #dbeafe; color: #1e40af; }
            .badge-female { background: #fce7f3; color: #9d174d; }
            .stats-grid { display: table; width: 100%; margin: 10px 0 18px; border-collapse: separate; border-spacing: 8px 0; }
            .stat { display: table-cell; background: #f8fafc; border-left: 3px solid #4f46e5; padding: 10px 12px; border-radius: 6px; width: 25%; }
            .stat .label { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold; }
            .stat .value { font-size: 18px; font-weight: bold; color: #0f172a; margin-top: 2px; }
            .footer { margin-top: 30px; padding-top: 10px; border-top: 1px dashed #cbd5e0; text-align: center; font-size: 9px; color: #94a3b8; letter-spacing: 0.3px; }
            .hero { background: #eef2ff; border-left: 4px solid #4f46e5; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
            .hero h2 { border: none; margin: 0 0 6px; padding: 0; color: #1e3c72; font-size: 16px; }
            .hero .chips { margin-top: 4px; }
            .hero .chip { display: inline-block; padding: 2px 9px; border-radius: 20px; background: #fff; color: #4338ca; font-size: 9.5px; font-weight: bold; margin-right: 4px; border: 1px solid #c7d2fe; }
        ";

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Sections Report</title><style>' . $css . '</style></head><body>';

        $html .= '<div class="header">';
        $html .= '<div class="school">USAT COLLEGE SAGAY CITY INC.</div>';
        $html .= '<h1>' . ($show_student_list ? 'Section Student Roster' : 'Sections Summary Report') . '</h1>';
        $html .= '<div class="sub">Sagay City, Negros Occidental</div>';
        $html .= '</div>';

        $html .= '<div class="meta">';
        $html .= '<strong>Generated:</strong> ' . htmlspecialchars($now) . ' &nbsp;·&nbsp; ';
        $html .= '<strong>Filters:</strong> ' . htmlspecialchars($filter_text);
        $html .= '<br><strong>Note:</strong> Graduated and Pending students are excluded from this report.';
        $html .= '</div>';

        if ($show_student_list && !empty($students_result)) {
            $ratio = $sec_girls > 0 ? round($sec_boys / $sec_girls, 2) . ':1' : '—';

            $html .= '<div class="hero">';
            $html .= '<h2>' . htmlspecialchars($sec_title) . '</h2>';
            $html .= '<div class="chips">';
            if ($sec_grade)   $html .= '<span class="chip">' . htmlspecialchars($sec_grade) . '</span>';
            if ($sec_program) $html .= '<span class="chip">' . htmlspecialchars($sec_program) . '</span>';
            if ($sec_sy)      $html .= '<span class="chip">SY ' . htmlspecialchars($sec_sy) . '</span>';
            $html .= '</div></div>';

            $html .= '<div class="stats-grid">';
            $html .= '<div class="stat"><div class="label">Total Students</div><div class="value">' . $sec_count . '</div></div>';
            $html .= '<div class="stat"><div class="label">Male</div><div class="value">' . $sec_boys . '</div></div>';
            $html .= '<div class="stat"><div class="label">Female</div><div class="value">' . $sec_girls . '</div></div>';
            $html .= '<div class="stat"><div class="label">M / F Ratio</div><div class="value">' . $ratio . '</div></div>';
            $html .= '</div>';

            $html .= '<h2>Student Roster</h2>';
            $html .= '<table><thead><tr>';
            $html .= '<th style="width:30px">#</th>';
            $html .= '<th style="width:130px">Student ID</th>';
            $html .= '<th>Name</th>';
            $html .= '<th style="width:130px">LRN</th>';
            $html .= '<th style="width:80px">Sex</th>';
            $html .= '</tr></thead><tbody>';

            $i = 1;
            foreach ($students_result as $r) {
                $name = trim(($r['last_name'] ?? '') . ', ' . ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? ''));
                $sex_class = $r['sex'] === 'Male' ? 'badge badge-male' : ($r['sex'] === 'Female' ? 'badge badge-female' : 'badge');

                $html .= '<tr>';
                $html .= '<td class="num">' . $i++ . '</td>';
                $html .= '<td><strong>' . htmlspecialchars($r['student_id_number'] ?? '—') . '</strong></td>';
                $html .= '<td>' . htmlspecialchars($name) . '</td>';
                $html .= '<td>' . htmlspecialchars($r['lrn'] ?? '—') . '</td>';
                $html .= '<td><span class="' . $sex_class . '">' . htmlspecialchars($r['sex'] ?: '—') . '</span></td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
        } elseif (!empty($section_result)) {
            $html .= '<div class="stats-grid">';
            $html .= '<div class="stat"><div class="label">Total Sections</div><div class="value">' . $stat_total_sections . '</div></div>';
            $html .= '<div class="stat"><div class="label">Total Students</div><div class="value">' . $stat_total_students . '</div></div>';
            $html .= '<div class="stat"><div class="label">Male</div><div class="value">' . $stat_boys . '</div></div>';
            $html .= '<div class="stat"><div class="label">Female</div><div class="value">' . $stat_girls . '</div></div>';
            $html .= '</div>';

            $html .= '<h2>Sections Summary</h2>';
            $html .= '<table><thead><tr>';
            $html .= '<th>Section</th>';
            $html .= '<th style="width:90px">Grade</th>';
            $html .= '<th>Program</th>';
            $html .= '<th style="width:90px">School Year</th>';
            $html .= '<th style="width:60px">Total</th>';
            $html .= '<th style="width:60px">Male</th>';
            $html .= '<th style="width:60px">Female</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($section_result as $s) {
                $html .= '<tr>';
                $html .= '<td><strong>' . htmlspecialchars($s['section']) . '</strong></td>';
                $html .= '<td><span class="badge">' . htmlspecialchars($s['grade_level'] ?? '—') . '</span></td>';
                $html .= '<td>' . htmlspecialchars($s['program'] ?? '—') . '</td>';
                $html .= '<td>' . htmlspecialchars($s['school_year'] ?? '—') . '</td>';
                $html .= '<td class="num"><strong>' . (int)$s['total'] . '</strong></td>';
                $html .= '<td class="male">' . (int)$s['boys'] . '</td>';
                $html .= '<td class="female">' . (int)$s['girls'] . '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
        } else {
            $html .= '<p style="text-align:center;color:#94a3b8;font-style:italic;padding:40px 0">No data available for the selected filters.</p>';
        }

        $html .= '<div class="footer">End of Report &nbsp;·&nbsp; USAT College Sagay City Inc. &nbsp;·&nbsp; © ' . $year . '</div>';
        $html .= '</body></html>';

        return $html;
    }
}

/* ═══ WORD EXPORT ═══ */
if ($export_word) {
    while (ob_get_level()) ob_end_clean();

    $html = build_export_html(
        $show_student_list, $section_result ?? [], $students_result ?? [],
        $filter_text, $sec_title, $sec_grade, $sec_program, $sec_sy,
        $sec_count, $sec_boys, $sec_girls,
        $stat_total_sections, $stat_total_students, $stat_boys, $stat_girls
    );

    $filename = 'USAT_Sections_' . ($show_student_list ? preg_replace('/[^A-Za-z0-9_-]/', '_', $sec_title) : 'Summary') . '_' . date('Y-m-d') . '.doc';

    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    echo "\xEF\xBB\xBF";
    echo str_replace('<!DOCTYPE html>', '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">', $html);
    exit;
}

/* ═══ PDF EXPORT ═══ */
if ($export_pdf) {
    while (ob_get_level()) ob_end_clean();

    $html = build_export_html(
        $show_student_list, $section_result ?? [], $students_result ?? [],
        $filter_text, $sec_title, $sec_grade, $sec_program, $sec_sy,
        $sec_count, $sec_boys, $sec_girls,
        $stat_total_sections, $stat_total_students, $stat_boys, $stat_girls
    );

    $autoload = __DIR__ . '/../../vendor/autoload.php';

    if (!is_file($autoload)) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div style="font-family:Arial;padding:1rem;background:#fee2e2;color:#991b1b;border-left:4px solid #dc2626;border-radius:8px">';
        echo '<strong>PDF library not installed.</strong> Run <code>composer require dompdf/dompdf</code> in the project root.';
        echo '</div>';
        exit;
    }

    require_once $autoload;

    if (!class_exists('Dompdf\\Dompdf')) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div style="font-family:Arial;padding:1rem;background:#fee2e2;color:#991b1b;border-left:4px solid #dc2626;border-radius:8px">';
        echo '<strong>Dompdf class not found.</strong> Run <code>composer dump-autoload</code>.';
        echo '</div>';
        exit;
    }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('dpi', 120);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    $filename = 'USAT_Sections_' . ($show_student_list ? preg_replace('/[^A-Za-z0-9_-]/', '_', $sec_title) : 'Summary') . '_' . date('Y-m-d') . '.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

/* ═══ RENDER ═══ */
$page_title = ($show_student_list ? 'Section Students' : 'Sections') . ' • USAT Admin';

$page_css = <<<'CSS'
    .section-hero{
        display:flex;align-items:center;gap:1.25rem;padding:1.5rem 1.75rem;
        background:linear-gradient(135deg,#5b54e8 0%, #7c78f0 100%);
        color:#fff;position:relative;overflow:hidden;
        border-radius:var(--radius-lg);margin-bottom:1.25rem;
        box-shadow:0 12px 32px -14px rgba(79,70,229,.55);
    }
    [data-bs-theme="dark"] .section-hero{background:linear-gradient(135deg,#4c46c5 0%, #5f5acc 100%)}
    .section-hero::after{content:'';position:absolute;right:-40px;top:-40px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none}
    .section-hero-avatar{width:64px;height:64px;border-radius:16px;background:rgba(255,255,255,.18);border:2px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:800;flex-shrink:0;backdrop-filter:blur(4px);z-index:1}
    .section-hero-info{flex:1;min-width:0;z-index:1}
    .section-hero-info h1{font-size:1.35rem;font-weight:700;letter-spacing:-.01em;margin:0 0 .35rem;line-height:1.2}
    .section-hero-chips{display:flex;gap:.35rem;flex-wrap:wrap}
    .section-hero-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .7rem;border-radius:999px;background:rgba(255,255,255,.18);font-size:.72rem;font-weight:600;backdrop-filter:blur(4px);white-space:nowrap}
    .section-hero-chip i{font-size:.75rem;opacity:.9}
    .section-hero .btn-hero{background:rgba(255,255,255,.92);color:var(--accent);border:none;font-weight:600;padding:.5rem 1rem;border-radius:9px;font-size:.82rem;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .15s;z-index:1;flex-shrink:0}
    .section-hero .btn-hero:hover{background:#fff;transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.15);color:var(--accent)}

    .section-cell{display:flex;align-items:center;gap:.6rem}
    .section-badge{width:34px;height:34px;border-radius:9px;background:var(--accent-soft);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
    .section-name{font-weight:700;color:var(--text);font-size:.88rem;line-height:1.2}
    .section-sub{font-size:.72rem;color:var(--text-2);margin-top:.1rem}

    .student-cell{display:flex;align-items:center;gap:.7rem;min-width:0}
    .student-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0;box-shadow:0 0 0 2px color-mix(in srgb,var(--accent) 15%, transparent)}
    .student-info{min-width:0}
    .student-name{font-weight:600;color:var(--text);font-size:.86rem;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .student-meta{font-size:.72rem;color:var(--text-2);font-variant-numeric:tabular-nums;margin-top:.1rem}

    .pill.male{background:#dce7fb;color:#1e40af;border-color:rgba(30,64,175,.12)}
    .pill.female{background:#fbe0eb;color:#9d174d;border-color:rgba(157,23,77,.12)}
    [data-bs-theme="dark"] .pill.male{background:#1a2b4a;color:#93c5fd;border-color:rgba(147,197,253,.15)}
    [data-bs-theme="dark"] .pill.female{background:#4a1d36;color:#f9a8d4;border-color:rgba(249,168,212,.15)}

    .export-dropdown .btn{display:inline-flex;align-items:center;gap:.4rem}
    .export-dropdown .dropdown-menu{border-radius:12px;border:1px solid var(--border);box-shadow:var(--shadow-lg);padding:.35rem;min-width:220px}
    .export-dropdown .dropdown-item{display:flex;align-items:center;gap:.6rem;padding:.55rem .75rem;border-radius:8px;font-size:.85rem;color:var(--text);font-weight:500}
    .export-dropdown .dropdown-item:hover{background:var(--surface-2);color:var(--text)}
    .export-dropdown .dropdown-item i{width:20px;text-align:center;font-size:1rem}
    .export-dropdown .dropdown-item .ext-hint{font-size:.72rem;color:var(--text-2);margin-left:auto}

    .table-admin tbody tr.clickable{cursor:pointer}

    .filter-bar{grid-template-columns:minmax(140px,1fr) minmax(140px,1fr) minmax(200px,1.5fr) minmax(160px,1fr) auto}

    @media (max-width:1280px){
        .filter-bar{grid-template-columns:1fr 1fr auto}
        .filter-bar .actions{grid-column:1 / -1;justify-content:flex-end}
    }
    @media (max-width:640px){
        .filter-bar{grid-template-columns:1fr}
        .filter-bar .actions{grid-column:span 1;justify-content:stretch}
        .filter-bar .actions .btn{flex:1}
        .section-hero{flex-direction:column;text-align:center;padding:1.25rem 1rem}
        .section-hero-info{text-align:center}
        .section-hero-chips{justify-content:center}
    }
CSS;

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/sidebar.php';
?>

<div class="main-content" id="mainContent">

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle" aria-label="Open menu">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <?php if ($show_student_list): ?>
                    <div style="display:flex;align-items:center;gap:.4rem;font-size:.78rem;color:var(--text-2);margin-bottom:.25rem">
                        <a href="<?= URL_PREFIX ?>/sections_list.php" style="color:var(--accent);text-decoration:none;font-weight:500">Sections</a>
                        <i class="bi bi-chevron-right" style="font-size:.7rem;opacity:.6"></i>
                        <span><?= htmlspecialchars($sec_title ?: 'Students') ?></span>
                    </div>
                    <h2>Section Students</h2>
                    <p class="sub">Students assigned to this section</p>
                <?php else: ?>
                    <h2>Sections &amp; Students</h2>
                    <p class="sub">Manage class sections and student rosters</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="<?= URL_PREFIX ?>/student_profile.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Students
            </a>

            <div class="dropdown export-dropdown">
                <button class="btn btn-outline-success btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-download me-1"></i> Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <?php $wp_pdf = ['export_pdf'=>1,'school_year'=>$school_year,'grade_level'=>$grade_level,'program'=>$program,'section'=>$section]; ?>
                        <a class="dropdown-item" href="?<?= http_build_query(array_filter($wp_pdf)) ?>">
                            <i class="bi bi-file-earmark-pdf-fill text-danger"></i>
                            Export as PDF
                            <span class="ext-hint">.pdf</span>
                        </a>
                    </li>
                    <li>
                        <?php $wp_word = ['export_word'=>1,'school_year'=>$school_year,'grade_level'=>$grade_level,'program'=>$program,'section'=>$section]; ?>
                        <a class="dropdown-item" href="?<?= http_build_query(array_filter($wp_word)) ?>">
                            <i class="bi bi-file-earmark-word-fill text-primary"></i>
                            Export as Word
                            <span class="ext-hint">.doc</span>
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="#" onclick="window.print();return false;">
                            <i class="bi bi-printer text-secondary"></i>
                            Print
                            <span class="ext-hint">Ctrl+P</span>
                        </a>
                    </li>
                </ul>
            </div>

            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-funnel"></i> Filters</h3>
            <?php if ($school_year || $grade_level || $program || $section): ?>
                <span class="cell-muted" style="font-size:.75rem"><i class="bi bi-funnel-fill me-1"></i> Active filters applied</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form class="filter-bar" method="GET">
                <div class="field">
                    <label for="f_sy">School Year</label>
                    <select id="f_sy" name="school_year">
                        <option value="">All Years</option>
                        <?php foreach($years as $y): ?>
                            <option value="<?= htmlspecialchars($y['school_year']) ?>" <?= $school_year===$y['school_year']?'selected':'' ?>><?= htmlspecialchars($y['school_year']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_grade">Grade Level</label>
                    <select id="f_grade" name="grade_level">
                        <option value="">All Grades</option>
                        <?php foreach($grades as $g): ?>
                            <option value="<?= htmlspecialchars($g['grade_level']) ?>" <?= $grade_level===$g['grade_level']?'selected':'' ?>><?= htmlspecialchars($g['grade_level']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_program">Program</label>
                    <select id="f_program" name="program">
                        <option value="">All Programs</option>
                        <?php foreach($programs as $p): ?>
                            <option value="<?= htmlspecialchars($p['program']) ?>" <?= $program===$p['program']?'selected':'' ?>><?= htmlspecialchars($p['program']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_section">Section Name</label>
                    <input type="text" id="f_section" name="section" placeholder="e.g. ICT A" value="<?= htmlspecialchars($section) ?>">
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Filter</button>
                    <a href="<?= URL_PREFIX ?>/sections_list.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle me-1"></i> Reset</a>
                </div>
            </form>
        </div>
    </div>

    <?php if($show_student_list): ?>
        <?php if(!empty($students_result)): ?>

            <div class="section-hero">
                <div class="section-hero-avatar">
                    <i class="bi bi-columns-gap"></i>
                </div>
                <div class="section-hero-info">
                    <h1><?= htmlspecialchars($sec_title) ?></h1>
                    <div class="section-hero-chips">
                        <?php if ($sec_grade): ?>
                            <span class="section-hero-chip"><i class="bi bi-mortarboard-fill"></i><?= htmlspecialchars($sec_grade) ?></span>
                        <?php endif; ?>
                        <?php if ($sec_program): ?>
                            <span class="section-hero-chip"><i class="bi bi-diagram-3-fill"></i><?= htmlspecialchars($sec_program) ?></span>
                        <?php endif; ?>
                        <?php if ($sec_sy): ?>
                            <span class="section-hero-chip"><i class="bi bi-calendar-range"></i>SY <?= htmlspecialchars($sec_sy) ?></span>
                        <?php endif; ?>
                        <span class="section-hero-chip"><i class="bi bi-people-fill"></i><?= $sec_count ?> student<?= $sec_count === 1 ? '' : 's' ?></span>
                    </div>
                </div>
                <a href="<?= URL_PREFIX ?>/sections_list.php?<?= http_build_query(array_filter(['school_year'=>$school_year,'grade_level'=>$grade_level,'program'=>$program])) ?>" class="btn-hero">
                    <i class="bi bi-arrow-left"></i> All Sections
                </a>
            </div>

            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($sec_count) ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-gender-male"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($sec_boys) ?></div>
                        <div class="stat-label">Male</div>
                    </div>
                </div>
                <div class="stat-card tone-red">
                    <div class="stat-icon"><i class="bi bi-gender-female"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($sec_girls) ?></div>
                        <div class="stat-label">Female</div>
                    </div>
                </div>
                <div class="stat-card tone-purple">
                    <div class="stat-icon"><i class="bi bi-percent"></i></div>
                    <div>
                        <?php $ratio = $sec_girls > 0 ? round($sec_boys / $sec_girls, 2) : null; ?>
                        <div class="stat-value"><?= $ratio !== null ? $ratio . ':1' : '—' ?></div>
                        <div class="stat-label">M/F Ratio</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>
                        <i class="bi bi-people"></i> Student Roster
                        <span class="cell-muted" style="font-weight:500;font-size:.85rem">(<?= number_format($sec_count) ?>)</span>
                    </h3>
                </div>
                <div class="card-body no-padding">
                    <div class="table-scroll">
                        <table class="table-admin">
                            <thead>
                                <tr>
                                    <th style="width:44px">#</th>
                                    <th style="width:180px">Student ID</th>
                                    <th>Name</th>
                                    <th style="width:160px">LRN</th>
                                    <th style="width:110px">Sex</th>
                                    <th style="width:90px;text-align:right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach($students_result as $r):
                                    $name = trim($r['last_name'].', '.$r['first_name'].' '.($r['middle_name']??''));
                                    $initials = strtoupper(substr($r['first_name'] ?? '', 0, 1) . substr($r['last_name'] ?? '', 0, 1));
                                ?>
                                <tr class="clickable" onclick="window.location='<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$r['student_id'] ?>'">
                                    <td class="cell-muted"><?= $i++ ?></td>
                                    <td class="cell-strong"><?= htmlspecialchars($r['student_id_number'] ?? '—') ?></td>
                                    <td>
                                        <div class="student-cell">
                                            <div class="student-avatar"><?= htmlspecialchars($initials) ?></div>
                                            <div class="student-info">
                                                <div class="student-name"><?= htmlspecialchars($name) ?></div>
                                                <div class="student-meta"><?= htmlspecialchars($r['ext_name'] ?? '') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="cell-muted"><?= htmlspecialchars($r['lrn'] ?? '—') ?></td>
                                    <td>
                                        <?php if($r['sex'] === 'Male'): ?>
                                            <span class="pill male"><i class="bi bi-gender-male"></i>Male</span>
                                        <?php elseif($r['sex'] === 'Female'): ?>
                                            <span class="pill female"><i class="bi bi-gender-female"></i>Female</span>
                                        <?php else: ?>
                                            <span class="pill muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right">
                                        <a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$r['student_id'] ?>"
                                           class="btn btn-icon btn-outline-primary"
                                           onclick="event.stopPropagation()"
                                           title="View profile">
                                            <i class="bi bi-arrow-right-short" style="font-size:1.15rem"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <div class="empty">
                        <div class="empty-icon"><i class="bi bi-search"></i></div>
                        <div class="empty-title">No students found</div>
                        <div class="empty-sub">No students match the section "<strong><?= htmlspecialchars($section) ?></strong>" with the current filters.</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <?php if(!empty($section_result)): ?>
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-diagram-3"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($stat_total_sections) ?></div>
                        <div class="stat-label">Total Sections</div>
                    </div>
                </div>
                <div class="stat-card tone-purple">
                    <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($stat_total_students) ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-gender-male"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($stat_boys) ?></div>
                        <div class="stat-label">Male</div>
                    </div>
                </div>
                <div class="stat-card tone-red">
                    <div class="stat-icon"><i class="bi bi-gender-female"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($stat_girls) ?></div>
                        <div class="stat-label">Female</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3>
                    <i class="bi bi-diagram-3"></i> Sections
                    <span class="cell-muted" style="font-weight:500;font-size:.85rem">(<?= count($section_result ?? []) ?>)</span>
                </h3>
            </div>
            <div class="card-body no-padding">
                <?php if(!empty($section_result)): ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th>Section</th>
                                <th style="width:120px">Grade</th>
                                <th>Program</th>
                                <th style="width:110px">School Year</th>
                                <th class="cell-center" style="width:100px">Total</th>
                                <th class="cell-center" style="width:90px">Male</th>
                                <th class="cell-center" style="width:90px">Female</th>
                                <th style="width:130px;text-align:right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($section_result as $s): ?>
                            <tr>
                                <td>
                                    <div class="section-cell">
                                        <div class="section-badge"><i class="bi bi-columns-gap"></i></div>
                                        <div>
                                            <div class="section-name"><?= htmlspecialchars($s['section']) ?></div>
                                            <div class="section-sub">
                                                <?= $s['total'] ?> student<?= (int)$s['total'] === 1 ? '' : 's' ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="pill info">
                                        <i class="bi bi-mortarboard"></i>
                                        <?= htmlspecialchars($s['grade_level'] ?? '—') ?>
                                    </span>
                                </td>
                                <td class="cell-muted"><?= htmlspecialchars($s['program'] ?? '—') ?></td>
                                <td class="cell-muted"><?= htmlspecialchars($s['school_year'] ?? '—') ?></td>
                                <td class="cell-center cell-num"><?= number_format((int)$s['total']) ?></td>
                                <td class="cell-center cell-male"><?= number_format((int)$s['boys']) ?></td>
                                <td class="cell-center cell-female"><?= number_format((int)$s['girls']) ?></td>
                                <td style="text-align:right">
                                    <a href="<?= URL_PREFIX ?>/sections_list.php?section=<?= urlencode($s['section']) ?>&school_year=<?= urlencode($school_year) ?>&grade_level=<?= urlencode($grade_level) ?>&program=<?= urlencode($program) ?>"
                                       class="btn btn-xs btn-outline-primary">
                                        <i class="bi bi-eye me-1"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-table"></i></div>
                    <div class="empty-title">No sections found</div>
                    <div class="empty-sub">Try adjusting your filters or resetting the search.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>