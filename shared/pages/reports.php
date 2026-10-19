<?php
// shared/pages/reports.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';

$__nav_section = 'reports';

/* ═══ Report type ═══ */
$valid_reports = ['summary','masterlist','by_grade','by_strand','by_section','by_gender','documents','graduated'];
$report_type = $_GET['report'] ?? 'summary';
if (!in_array($report_type, $valid_reports, true)) $report_type = 'summary';

/* ═══ Filters ═══ */
$selected_sy     = $_GET['school_year']   ?? '';
$strand_filter   = $_GET['strand_filter'] ?? '';
$grade_filter    = $_GET['grade_filter']  ?? '';
$section_filter  = $_GET['section_filter']?? '';
$status_filter   = $_GET['status_filter'] ?? '';

$export_word = isset($_GET['export_word']) && $_GET['export_word'] == 1;
$export_pdf  = isset($_GET['export_pdf'])  && $_GET['export_pdf']  == 1;

/* ═══ Cascading dropdowns ═══ */
$dd_build_where = function(array $pairs): array {
    $w = []; $p = []; $t = '';
    foreach ($pairs as $col => $val) {
        if ($val === '' || $val === null) continue;
        $w[] = "$col = ?";
        $p[] = $val;
        $t .= 's';
    }
    return [$w ? ' AND ' . implode(' AND ', $w) : '', $p, $t];
};

$sy_list = $conn->query("
    SELECT DISTINCT school_year FROM enrollment_form
    WHERE school_year IS NOT NULL AND school_year != ''
    ORDER BY school_year DESC
")->fetch_all(MYSQLI_ASSOC) ?: [];

[$w, $p, $t] = $dd_build_where(['school_year' => $selected_sy]);
$stmt = $conn->prepare("SELECT DISTINCT grade_level FROM enrollment_form WHERE grade_level IS NOT NULL AND grade_level != '' $w ORDER BY grade_level ASC");
if (!empty($p)) $stmt->bind_param($t, ...$p);
$stmt->execute();
$grade_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

[$w, $p, $t] = $dd_build_where(['school_year' => $selected_sy, 'grade_level' => $grade_filter]);
$stmt = $conn->prepare("SELECT DISTINCT strand FROM enrollment_form WHERE strand IS NOT NULL AND strand != '' $w ORDER BY strand ASC");
if (!empty($p)) $stmt->bind_param($t, ...$p);
$stmt->execute();
$strand_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

[$w, $p, $t] = $dd_build_where(['school_year' => $selected_sy, 'grade_level' => $grade_filter, 'strand' => $strand_filter]);
$stmt = $conn->prepare("SELECT DISTINCT section FROM enrollment_form WHERE section IS NOT NULL AND section != '' $w ORDER BY section ASC");
if (!empty($p)) $stmt->bind_param($t, ...$p);
$stmt->execute();
$section_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

/* ═══ Build main filter fragments ═══ */
$sy_filter    = "";
$params_main  = [];
$types_main   = "";

if ($selected_sy !== '')     { $sy_filter .= " AND e.school_year = ?";  $params_main[] = $selected_sy;    $types_main .= "s"; }
if ($strand_filter !== '')   { $sy_filter .= " AND e.strand = ?";       $params_main[] = $strand_filter;  $types_main .= "s"; }
if ($grade_filter !== '')    { $sy_filter .= " AND e.grade_level = ?";  $params_main[] = $grade_filter;   $types_main .= "s"; }
if ($section_filter !== '')  { $sy_filter .= " AND e.section = ?";      $params_main[] = $section_filter; $types_main .= "s"; }
if ($status_filter !== '')   { $sy_filter .= " AND e.status = ?";       $params_main[] = $status_filter;  $types_main .= "s"; }

if ($status_filter === '') {
    $sy_filter .= " AND COALESCE(e.status,'Active') NOT IN ('Graduated', 'Pending')";
}

$graduated_excluded = ($status_filter === '');

/* ═══ KPI queries ═══ */
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

/* ═══ Report queries ═══ */
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

$master_sql = "SELECT s.student_id, s.student_id_number, s.lrn, s.last_name, s.first_name, s.middle_name, s.ext_name,
                      s.sex, s.birth_date, s.age, s.email, s.phone,
                      e.grade_level, e.strand, e.program, e.section, e.school_year, e.term, e.track,
                      COALESCE(e.status,'Active') AS status
               FROM students_info s
               INNER JOIN enrollment_form e ON e.student_id = s.student_id
               WHERE 1=1 $sy_filter
               ORDER BY e.grade_level ASC, e.strand ASC, e.section ASC, s.last_name ASC, s.first_name ASC";
$stmt = $conn->prepare($master_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute();
$masterlist = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$masterlist_status_counts = [];
foreach ($masterlist as $m) {
    $st = $m['status'] ?? 'Active';
    if ($st === '' || $st === null) $st = 'Active';
    $masterlist_status_counts[$st] = ($masterlist_status_counts[$st] ?? 0) + 1;
}
$__status_order = ['Active', 'Pending', 'Transferred', 'Stopped', 'Dropped', 'Graduated'];
uksort($masterlist_status_counts, function($a, $b) use ($__status_order) {
    $ia = array_search($a, $__status_order, true);
    $ib = array_search($b, $__status_order, true);
    if ($ia === false) $ia = 999;
    if ($ib === false) $ib = 999;
    return $ia <=> $ib;
});

$grouped_masterlist = [];
foreach ($masterlist as $m) {
    $gl = $m['grade_level'] ?: 'Unassigned Grade';
    $st = $m['strand']      ?: 'Unassigned Strand';
    $sc = $m['section']     ?: 'Unassigned Section';
    $grouped_masterlist[$gl][$st][$sc][] = $m;
}
$grade_order = ['Grade 11' => 1, 'Grade 12' => 2];
uksort($grouped_masterlist, function($a, $b) use ($grade_order) {
    $oa = $grade_order[$a] ?? 99;
    $ob = $grade_order[$b] ?? 99;
    return $oa <=> $ob ?: strcmp($a, $b);
});

$gender_sql = "SELECT e.grade_level, SUM(CASE WHEN s.sex = 'Male' THEN 1 ELSE 0 END) AS boys, SUM(CASE WHEN s.sex = 'Female' THEN 1 ELSE 0 END) AS girls, COUNT(DISTINCT s.student_id) AS total_students FROM enrollment_form e INNER JOIN students_info s ON s.student_id = e.student_id WHERE 1=1 $sy_filter GROUP BY e.grade_level ORDER BY e.grade_level ASC";
$stmt = $conn->prepare($gender_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute();
$gender_report = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$doc_sql = "SELECT s.student_id, s.student_id_number, s.lrn, s.last_name, s.first_name,
                   e.grade_level, e.strand, e.section, e.school_year,
                   (SELECT COUNT(*) FROM entrance_documents d WHERE d.student_id = s.student_id) AS docs_total,
                   (SELECT COUNT(*) FROM entrance_documents d WHERE d.student_id = s.student_id AND d.submitted = 1 AND d.file_path IS NOT NULL) AS docs_uploaded
            FROM students_info s
            INNER JOIN enrollment_form e ON e.student_id = s.student_id
            WHERE 1=1 $sy_filter
            ORDER BY e.grade_level ASC, s.last_name ASC";
$stmt = $conn->prepare($doc_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute();
$doc_report = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$graduated_students = [];
$total_graduated = 0;
$grad_sql = "SELECT s.student_id, s.student_id_number, s.lrn, s.last_name, s.first_name, s.middle_name, s.sex,
                    e.grade_level, e.strand, e.program, e.section, e.school_year, e.status
             FROM students_info s
             INNER JOIN enrollment_form e ON s.student_id = e.student_id
             WHERE e.status = 'Graduated'";
$grad_params = []; $grad_types = "";
if ($selected_sy !== '')   { $grad_sql .= " AND e.school_year = ?"; $grad_params[] = $selected_sy;    $grad_types .= "s"; }
if ($strand_filter !== '') { $grad_sql .= " AND e.strand = ?";      $grad_params[] = $strand_filter;  $grad_types .= "s"; }
if ($grade_filter !== '')  { $grad_sql .= " AND e.grade_level = ?"; $grad_params[] = $grade_filter;   $grad_types .= "s"; }
$grad_sql .= " ORDER BY s.last_name ASC, s.first_name ASC";
$gs = $conn->prepare($grad_sql);
if (!empty($grad_params)) $gs->bind_param($grad_types, ...$grad_params);
$gs->execute();
$graduated_students = $gs->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$total_graduated = count($graduated_students);
$gs->close();

$grade_labels = []; $grade_totals = []; $grade_boys = []; $grade_girls = [];
foreach($grade_report as $g){ $grade_labels[]=$g['grade_level']; $grade_totals[]=(int)$g['total_students']; $grade_boys[]=(int)$g['boys']; $grade_girls[]=(int)$g['girls']; }
$strand_labels = []; $strand_totals = [];
foreach($strand_report as $s){ $strand_labels[]=$s['strand'].($s['program']!='N/A'?' - '.$s['program']:''); $strand_totals[]=(int)$s['total_students']; }
$year_labels = []; $year_totals = [];
foreach(array_reverse($year_report) as $y){ $year_labels[]=$y['school_year']; $year_totals[]=(int)$y['total_students']; }

$reports = [
    'summary'    => ['icon'=>'bi-speedometer2',   'title'=>'Enrollment Summary',  'desc'=>'Overview with KPIs, charts, and breakdown tables',       'color'=>'blue'],
    'masterlist' => ['icon'=>'bi-list-ul',         'title'=>'Student Masterlist',  'desc'=>'Students grouped by grade, strand, and section',          'color'=>'purple'],
    'by_grade'   => ['icon'=>'bi-layers',          'title'=>'By Grade Level',      'desc'=>'Enrollment counts grouped by grade level',               'color'=>'green'],
    'by_strand'  => ['icon'=>'bi-diagram-3',       'title'=>'By Strand & Program', 'desc'=>'Distribution across strands and programs',               'color'=>'amber'],
    'by_section' => ['icon'=>'bi-columns-gap',     'title'=>'By Section',          'desc'=>'Section roster with male/female breakdown',              'color'=>'blue'],
    'by_gender'  => ['icon'=>'bi-gender-ambiguous','title'=>'Gender Distribution', 'desc'=>'Male vs Female across all grade levels',                 'color'=>'red'],
    'documents'  => ['icon'=>'bi-folder-check',    'title'=>'Document Compliance', 'desc'=>'Which students have completed their requirements',       'color'=>'purple'],
    'graduated'  => ['icon'=>'bi-mortarboard',     'title'=>'Graduated Students',  'desc'=>'List of all students who completed Grade 12',            'color'=>'green'],
];

if (!function_exists('report_url')) {
    function report_url(string $report, array $filters): string {
        $params = ['report' => $report];
        foreach ($filters as $k => $v) {
            if ($v !== '' && $v !== null) $params[$k] = $v;
        }
        return '?' . http_build_query($params);
    }
}

$filter_arr = [
    'school_year'    => $selected_sy,
    'strand_filter'  => $strand_filter,
    'grade_filter'   => $grade_filter,
    'section_filter' => $section_filter,
    'status_filter'  => $status_filter,
];

$filter_desc = [];
if ($selected_sy !== '')     $filter_desc[] = "SY: $selected_sy";
if ($grade_filter !== '')    $filter_desc[] = "Grade: $grade_filter";
if ($strand_filter !== '')   $filter_desc[] = "Strand: $strand_filter";
if ($section_filter !== '')  $filter_desc[] = "Section: $section_filter";
if ($status_filter !== '')   $filter_desc[] = "Status: $status_filter";
$filter_text = $filter_desc ? implode(' · ', $filter_desc) : 'All records (excl. Graduated & Pending)';

/* ═══ Word + PDF export ═══ */
if ($export_word || $export_pdf) {
    ob_start();

    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Report</title><style>
        @page{margin:14mm}
        body{font-family:Arial;margin:2cm;font-size:11px;color:#000}
        h1{color:#1e3c72;border-bottom:3px double #1e3c72;padding-bottom:8px}
        h2{color:#2b4c8c;margin-top:20px;border-bottom:2px solid #2b4c8c;padding-bottom:4px}
        h3{color:#4f46e5;margin:18px 0 8px;font-size:13px;background:#eef2ff;padding:6px 10px;border-left:4px solid #4f46e5}
        h4{color:#334155;margin:12px 0 6px;font-size:11.5px;background:#f8fafc;padding:5px 10px;border-left:3px solid #94a3b8}
        table{width:100%;border-collapse:collapse;margin:8px 0;page-break-inside:auto;table-layout:fixed}
        tr{page-break-inside:avoid;page-break-after:auto}
        thead{display:table-header-group}
        tfoot{display:table-footer-group}
        h1,h2,h3,h4{page-break-after:avoid}
        th,td{border:1px solid #ddd;padding:6px;text-align:left;word-break:break-word;overflow-wrap:anywhere}
        th{background:#eef2ff;color:#1e3a72;font-weight:bold}
        .meta{background:#f8fafc;padding:10px 14px;border-left:4px solid #4f46e5;margin:10px 0;page-break-inside:avoid}
        .num{text-align:center;font-variant-numeric:tabular-nums}
        .section-page{page-break-inside:auto}
        .section-page + .section-page{page-break-before:always}
        .section-page .section-page-head{background:#eef2ff;border-left:4px solid #4f46e5;padding:8px 12px;margin:0 0 10px;page-break-after:avoid}
        .section-page .section-page-title{font-size:14px;font-weight:bold;color:#1e3c72;margin:0 0 3px}
        .section-page .section-page-meta{font-size:10px;color:#475569;margin:0}
        .section-page .section-page-meta span{margin-right:10px}
        .status-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:9px;font-weight:700;letter-spacing:.3px;white-space:nowrap;border:1px solid}
        .status-active      {background:#d1fae5;color:#047857;border-color:#10b981}
        .status-pending     {background:#fef3c7;color:#b45309;border-color:#f59e0b}
        .status-transferred {background:#dbeafe;color:#1e40af;border-color:#3b82f6}
        .status-stopped     {background:#e2e8f0;color:#475569;border-color:#94a3b8}
        .status-dropped     {background:#fee2e2;color:#b91c1c;border-color:#ef4444}
        .status-graduated   {background:#ede9fe;color:#6d28d9;border-color:#8b5cf6}
        .status-summary{margin:10px 0 14px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:10px}
        .status-summary .lbl{font-weight:bold;color:#334155;margin-right:6px}
        .status-summary .item{display:inline-block;margin-right:10px;font-weight:600}
    </style></head><body>';

    echo '<h1>USAT College Sagay City Inc.</h1>';
    echo '<h2 style="border:none;margin:0;font-size:16px;color:#334155">' . htmlspecialchars($reports[$report_type]['title']) . '</h2>';
    echo '<div class="meta">';
    echo '<strong>Generated:</strong> ' . date('F d, Y h:i A') . '<br>';
    echo '<strong>Filters:</strong> ' . htmlspecialchars($filter_text);
    if ($report_type !== 'graduated' && $graduated_excluded) {
        echo '<br><strong>Note:</strong> Graduated and Pending students are excluded from this report.';
    }
    echo '</div>';

    if ($report_type === 'summary') {
        echo '<h2>Summary</h2>';
        echo '<table><tr><th>Total Students</th><td class="num">' . number_format($total_students) . '</td><th>Total Sections</th><td class="num">' . number_format($total_sections) . '</td></tr>';
        echo '<tr><th>Male</th><td class="num">' . number_format($boys) . ' (' . $boy_percent . '%)</td><th>Female</th><td class="num">' . number_format($girls) . ' (' . $girl_percent . '%)</td></tr>';
        echo '<tr><th>Strands</th><td class="num">' . number_format($total_strands) . '</td><th></th><td></td></tr></table>';
        echo '<h2>By Grade Level</h2><table><tr><th>Grade</th><th class="num">Total</th><th class="num">Male</th><th class="num">Female</th></tr>';
        foreach($grade_report as $r) echo '<tr><td>'.htmlspecialchars($r['grade_level']).'</td><td class="num">'.number_format($r['total_students']).'</td><td class="num">'.number_format($r['boys']).'</td><td class="num">'.number_format($r['girls']).'</td></tr>';
        echo '</table>';
        echo '<h2>By Strand &amp; Program</h2><table><tr><th>Strand</th><th>Program</th><th class="num">Total</th><th class="num">Male</th><th class="num">Female</th></tr>';
        foreach($strand_report as $r) echo '<tr><td>'.htmlspecialchars($r['strand']).'</td><td>'.htmlspecialchars($r['program']).'</td><td class="num">'.number_format($r['total_students']).'</td><td class="num">'.number_format($r['boys']).'</td><td class="num">'.number_format($r['girls']).'</td></tr>';
        echo '</table>';
    }
    elseif ($report_type === 'masterlist') {
        echo '<h2>Student Masterlist</h2>';
        echo '<p style="font-size:10px;color:#64748b;margin:4px 0 8px;font-style:italic">'
           . count($masterlist) . ' student(s) across '
           . count($grouped_masterlist) . ' grade level(s). Each section is printed on its own page.</p>';

        if (!empty($masterlist_status_counts)) {
            echo '<div class="status-summary">';
            echo '<span class="lbl">Status breakdown:</span>';
            $total_with_status = 0;
            foreach ($masterlist_status_counts as $st => $cnt) {
                $cls = 'status-' . strtolower($st);
                echo '<span class="item"><span class="status-badge ' . $cls . '">' . htmlspecialchars($st) . '</span> ' . (int)$cnt . '</span>';
                $total_with_status += (int)$cnt;
            }
            echo '<span class="item" style="float:right">Total: ' . (int)$total_with_status . '</span>';
            echo '</div>';
        }

        $first_page = true;
        foreach ($grouped_masterlist as $grade => $strands) {
            foreach ($strands as $strand => $sections) {
                foreach ($sections as $section => $students) {
                    $m = 0; $f = 0;
                    foreach ($students as $s) {
                        if (strcasecmp($s['sex'] ?? '', 'Male') === 0)         $m++;
                        elseif (strcasecmp($s['sex'] ?? '', 'Female') === 0)   $f++;
                    }

                    $break_style = $first_page ? '' : ' style="page-break-before:always;"';
                    $first_page = false;

                    echo '<div class="section-page"' . $break_style . '>';
                    echo '<div class="section-page-head">';
                    echo   '<div class="section-page-title">' . htmlspecialchars($section) . '</div>';
                    echo   '<div class="section-page-meta">';
                    echo     '<span><strong>Grade:</strong> ' . htmlspecialchars($grade) . '</span>';
                    echo     '<span><strong>Strand:</strong> ' . htmlspecialchars($strand) . '</span>';
                    echo     '<span><strong>Total:</strong> ' . count($students) . ' student(s)</span>';
                    echo     '<span><strong>Male:</strong> ' . $m . '</span>';
                    echo     '<span><strong>Female:</strong> ' . $f . '</span>';
                    echo   '</div>';
                    echo '</div>';

                    echo '<table>';
                    echo '<thead><tr>';
                    echo   '<th class="num" style="width:30px">#</th>';
                    echo   '<th style="width:120px">Student ID</th>';
                    echo   '<th style="width:120px">LRN</th>';
                    echo   '<th>Name</th>';
                    echo   '<th style="width:50px">Sex</th>';
                    echo   '<th class="num" style="width:40px">Age</th>';
                    echo   '<th style="width:90px">Status</th>';
                    echo '</tr></thead><tbody>';

                    $i = 1;
                    foreach ($students as $s) {
                        $full_name = trim(($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? '') . ($s['middle_name'] ? ' ' . $s['middle_name'] : ''));
                        $st = $s['status'] ?? 'Active';
                        $cls = 'status-' . strtolower($st);

                        echo '<tr>';
                        echo   '<td class="num">' . $i++ . '</td>';
                        echo   '<td>' . htmlspecialchars($s['student_id_number'] ?? '—') . '</td>';
                        echo   '<td>' . htmlspecialchars($s['lrn'] ?? '—') . '</td>';
                        echo   '<td>' . htmlspecialchars($full_name) . '</td>';
                        echo   '<td>' . htmlspecialchars($s['sex'] ?? '—') . '</td>';
                        echo   '<td class="num">' . htmlspecialchars($s['age'] ?? '—') . '</td>';
                        echo   '<td><span class="status-badge ' . $cls . '">' . htmlspecialchars($st) . '</span></td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                    echo '</div>';
                }
            }
        }
    }
    elseif ($report_type === 'by_grade') {
        echo '<h2>By Grade Level</h2><table><tr><th>Grade</th><th class="num">Total</th><th class="num">Male</th><th class="num">Female</th></tr>';
        foreach($grade_report as $r) echo '<tr><td>'.htmlspecialchars($r['grade_level']).'</td><td class="num">'.number_format($r['total_students']).'</td><td class="num">'.number_format($r['boys']).'</td><td class="num">'.number_format($r['girls']).'</td></tr>';
        echo '</table>';
    }
    elseif ($report_type === 'by_strand') {
        echo '<h2>By Strand &amp; Program</h2><table><tr><th>Strand</th><th>Program</th><th class="num">Total</th><th class="num">Male</th><th class="num">Female</th></tr>';
        foreach($strand_report as $r) echo '<tr><td>'.htmlspecialchars($r['strand']).'</td><td>'.htmlspecialchars($r['program']).'</td><td class="num">'.number_format($r['total_students']).'</td><td class="num">'.number_format($r['boys']).'</td><td class="num">'.number_format($r['girls']).'</td></tr>';
        echo '</table>';
    }
    elseif ($report_type === 'by_section') {
        echo '<h2>Section Breakdown</h2><table><tr><th>Grade</th><th>Section</th><th class="num">Total</th><th class="num">Male</th><th class="num">Female</th></tr>';
        foreach($section_report as $r) echo '<tr><td>'.htmlspecialchars($r['grade_level']).'</td><td>'.htmlspecialchars($r['section']).'</td><td class="num">'.number_format($r['total_students']).'</td><td class="num">'.number_format($r['boys']).'</td><td class="num">'.number_format($r['girls']).'</td></tr>';
        echo '</table>';
    }
    elseif ($report_type === 'by_gender') {
        echo '<h2>Gender Distribution by Grade</h2><table><tr><th>Grade</th><th class="num">Total</th><th class="num">Male</th><th class="num">Female</th><th class="num">M/F Ratio</th></tr>';
        foreach($gender_report as $r) {
            $ratio = $r['girls'] > 0 ? round($r['boys']/$r['girls'], 2) . ':1' : '—';
            echo '<tr><td>'.htmlspecialchars($r['grade_level']).'</td><td class="num">'.number_format($r['total_students']).'</td><td class="num">'.number_format($r['boys']).'</td><td class="num">'.number_format($r['girls']).'</td><td class="num">'.$ratio.'</td></tr>';
        }
        echo '</table>';
    }
    elseif ($report_type === 'documents') {
        echo '<h2>Document Compliance (' . count($doc_report) . ' students)</h2>';
        echo '<table><tr><th class="num" style="width:30px">#</th><th>Student ID</th><th>Name</th><th>Grade</th><th>Section</th><th class="num">Uploaded</th><th class="num">Status</th></tr>';
        $i=1;
        foreach($doc_report as $d) {
            $status = $d['docs_total'] > 0 && $d['docs_uploaded'] >= $d['docs_total'] ? 'Complete' : ($d['docs_uploaded'] > 0 ? 'Partial' : 'Missing');
            echo '<tr><td class="num">'.$i++.'</td><td>'.htmlspecialchars($d['student_id_number']??'—').'</td><td>'.htmlspecialchars($d['last_name'].', '.$d['first_name']).'</td><td>'.htmlspecialchars($d['grade_level']??'—').'</td><td>'.htmlspecialchars($d['section']??'—').'</td><td class="num">'.$d['docs_uploaded'].' / '.$d['docs_total'].'</td><td class="num">'.$status.'</td></tr>';
        }
        echo '</table>';
    }
    elseif ($report_type === 'graduated') {
        echo '<h2>Graduated Students (' . $total_graduated . ')</h2><table><tr><th>Student ID</th><th>LRN</th><th>Name</th><th>Sex</th><th>Strand</th><th>Section</th><th>SY</th></tr>';
        foreach($graduated_students as $g) echo '<tr><td>'.htmlspecialchars($g['student_id_number']??'—').'</td><td>'.htmlspecialchars($g['lrn']??'—').'</td><td>'.htmlspecialchars($g['last_name'].', '.$g['first_name']).'</td><td>'.htmlspecialchars($g['sex']??'—').'</td><td>'.htmlspecialchars($g['strand']??'—').'</td><td>'.htmlspecialchars($g['section']??'—').'</td><td>'.htmlspecialchars($g['school_year']??'—').'</td></tr>';
        echo '</table>';
    }

    echo '<p style="text-align:center;color:#94a3b8;font-size:10px;margin-top:30px;border-top:1px dashed #cbd5e0;padding-top:10px"><em>End of Report · USAT College Sagay City Inc.</em></p>';
    echo '</body></html>';

    $export_html = ob_get_clean();

    if ($export_word) {
        $filename = 'usat_' . $report_type . '_report_' . date('Y-m-d') . '.doc';
        header("Content-Type: application/msword");
        header("Content-Disposition: attachment; filename=\"$filename\"");
        echo $export_html;
        exit;
    }

    $autoload = __DIR__ . '/../../vendor/autoload.php';

    if (!is_file($autoload)) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div style="font-family:Arial;padding:1rem;background:#fee2e2;color:#991b1b;border-left:4px solid #dc2626"><strong>PDF library not installed.</strong> Run <code>composer require dompdf/dompdf</code>.</div>';
        echo $export_html;
        exit;
    }

    require_once $autoload;

    if (!class_exists('Dompdf\\Dompdf')) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div style="font-family:Arial;padding:1rem;background:#fee2e2;color:#991b1b;border-left:4px solid #dc2626"><strong>Dompdf class not found.</strong> Run <code>composer dump-autoload</code>.</div>';
        echo $export_html;
        exit;
    }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('dpi', 120);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($export_html, 'UTF-8');
    $dompdf->render();

    $filename = 'usat_' . $report_type . '_report_' . date('Y-m-d') . '.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

/* ═══ RENDER ═══ */
$page_title = 'Reports • USAT Admin';
$page_head  = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>';

$page_css = <<<'CSS'
    .report-selector{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem;margin-bottom:1.5rem}
    .report-tile{display:flex;align-items:flex-start;gap:.75rem;padding:1rem 1.1rem;background:var(--surface);border:1.5px solid var(--border);border-radius:14px;text-decoration:none;color:inherit;transition:transform .15s, box-shadow .15s, border-color .15s;position:relative;overflow:hidden;cursor:pointer}
    .report-tile:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);border-color:var(--border-strong)}
    .report-tile.active{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%, transparent);background:linear-gradient(135deg,var(--surface),var(--accent-soft))}
    .report-tile .tile-icon{width:38px;height:38px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.05rem;background:var(--accent-soft);color:var(--accent)}
    .report-tile.tone-green  .tile-icon{background:var(--green-soft);color:var(--green)}
    .report-tile.tone-amber  .tile-icon{background:var(--amber-soft);color:var(--amber)}
    .report-tile.tone-red    .tile-icon{background:var(--red-soft);color:var(--red)}
    .report-tile.tone-purple .tile-icon{background:var(--purple-soft);color:var(--purple)}
    .report-tile .tile-body{min-width:0;flex:1}
    .report-tile .tile-title{font-size:.85rem;font-weight:700;color:var(--text);line-height:1.2;margin-bottom:.15rem}
    .report-tile .tile-desc{font-size:.72rem;color:var(--text-2);line-height:1.35}
    .report-tile.active .tile-title{color:var(--accent)}

    .exclude-note{display:flex;align-items:center;gap:.6rem;padding:.7rem 1rem;border-radius:12px;background:var(--green-soft);color:var(--green);border:1px solid color-mix(in srgb,var(--green) 25%, transparent);font-size:.83rem;font-weight:600;margin-bottom:1.25rem}
    .exclude-note i{font-size:.95rem}
    .exclude-note .hint-inline{font-weight:500;color:var(--text-2);margin-left:auto;font-size:.78rem}

    .chart-wrap{position:relative;height:280px}
    .chart-wrap.tall{height:260px}

    .grade-group{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow-sm);margin-bottom:1.25rem;overflow:hidden}
    .grade-header{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 1.25rem;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff}
    .grade-header .grade-title{font-size:1rem;font-weight:700;display:flex;align-items:center;gap:.5rem;margin:0}
    .grade-header .grade-title i{font-size:1.15rem;opacity:.9}
    .grade-header .grade-stats{display:flex;gap:.5rem;flex-wrap:wrap}
    .grade-header .stat-chip{background:rgba(255,255,255,.2);padding:.25rem .7rem;border-radius:999px;font-size:.72rem;font-weight:600;backdrop-filter:blur(4px);white-space:nowrap}
    .grade-header .stat-chip i{margin-right:.25rem;opacity:.9}

    .strand-band{padding:.85rem 1.25rem;background:var(--surface-2);border-bottom:1px solid var(--border);border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
    .strand-band:first-child{border-top:none}
    .strand-band .strand-title{font-size:.88rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:.5rem;margin:0}
    .strand-band .strand-title i{color:var(--accent);font-size:1rem}
    .strand-band .strand-meta{display:flex;gap:.5rem;flex-wrap:wrap;font-size:.72rem;color:var(--text-2);font-weight:600}
    .strand-band .strand-meta span{background:var(--surface);border:1px solid var(--border);padding:.15rem .55rem;border-radius:999px;white-space:nowrap}

    .section-block{border-bottom:1px solid var(--border);background:var(--surface)}
    .section-block:last-child{border-bottom:none}
    .section-block > summary{list-style:none;cursor:pointer;padding:.75rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;transition:background .15s;user-select:none;flex-wrap:wrap}
    .section-block > summary::-webkit-details-marker{display:none}
    .section-block > summary::before{content:'\F229';font-family:'bootstrap-icons';margin-right:.5rem;color:var(--text-3);transition:transform .2s;display:inline-block;font-size:.85rem}
    .section-block[open] > summary::before{transform:rotate(90deg)}
    .section-block > summary:hover{background:var(--surface-2)}
    .section-block .section-name{font-size:.85rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:.5rem}
    .section-block .section-name i{color:var(--accent)}
    .section-block .section-pills{display:flex;gap:.4rem;flex-wrap:wrap;align-items:center}
    .section-block .mini-pill{font-size:.7rem;font-weight:600;padding:.15rem .55rem;border-radius:999px;background:var(--surface-2);color:var(--text-2);border:1px solid var(--border);white-space:nowrap;font-variant-numeric:tabular-nums}
    .section-block .mini-pill.male{background:#dbeafe;color:#1e40af;border-color:rgba(30,64,175,.2)}
    .section-block .mini-pill.female{background:#fce7f3;color:var(--pink);border-color:rgba(236,72,153,.2)}
    .section-block .section-body{padding:0 1.25rem 1rem}

    .status-summary-bar{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;padding:.85rem 1.25rem;background:var(--surface-2);border-bottom:1px solid var(--border)}
    .status-summary-bar .label{font-size:.72rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;margin-right:.35rem}
    .status-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .7rem;border-radius:999px;font-size:.72rem;font-weight:700;border:1px solid transparent;font-variant-numeric:tabular-nums}
    .status-chip i{font-size:.75rem}
    .status-chip .count{background:rgba(255,255,255,.55);padding:.05rem .45rem;border-radius:999px;font-size:.68rem;font-weight:800}
    [data-bs-theme="dark"] .status-chip .count{background:rgba(0,0,0,.25)}
    .status-chip.active      {background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb,var(--green) 25%, transparent)}
    .status-chip.pending     {background:var(--amber-soft);color:var(--amber);border-color:color-mix(in srgb,var(--amber) 25%, transparent)}
    .status-chip.transferred {background:#dbeafe;color:#1e40af;border-color:rgba(30,64,175,.2)}
    .status-chip.stopped     {background:var(--surface);color:var(--text-2);border-color:var(--border)}
    .status-chip.dropped     {background:var(--red-soft);color:var(--red);border-color:color-mix(in srgb,var(--red) 25%, transparent)}
    .status-chip.graduated   {background:var(--purple-soft);color:var(--purple);border-color:color-mix(in srgb,var(--purple) 25%, transparent)}
    .status-summary-bar .total-chip{margin-left:auto;font-size:.72rem;font-weight:700;color:var(--text-2);padding:.3rem .8rem;border-radius:999px;background:var(--surface);border:1px solid var(--border)}

    .doc-bar{width:100%;height:6px;border-radius:999px;background:var(--surface-2);overflow:hidden;margin-top:.25rem}
    .doc-bar-fill{height:100%;border-radius:999px;transition:width .3s}
    .fill-full{background:var(--green)}
    .fill-partial{background:var(--accent)}
    .fill-empty{background:var(--amber)}

    .btn-ghost{background:var(--surface-2);border:1px solid var(--border);color:var(--text-2);font-size:.72rem;padding:.3rem .7rem;border-radius:8px}
    .btn-ghost:hover{background:var(--surface);color:var(--text);border-color:var(--border-strong)}

    .filter-bar{grid-template-columns:repeat(auto-fit,minmax(140px,1fr))}
    .filter-bar .actions{grid-column:1/-1;justify-content:flex-end}

    @media (max-width:640px){
        .stat-grid{grid-template-columns:repeat(2,1fr);gap:.75rem}
        .chart-wrap{height:220px}
        .report-selector{grid-template-columns:1fr}
        .grade-header{flex-direction:column;align-items:flex-start}
        .status-summary-bar{padding:.7rem 1rem}
        .status-summary-bar .total-chip{margin-left:0;width:100%;text-align:center}
        .exclude-note{flex-wrap:wrap}
        .exclude-note .hint-inline{margin-left:0;width:100%}
    }

    @media print {
        .no-print, .sidebar, .topbar, .menu-toggle, .report-selector, .theme-btn, .filter-bar, .exclude-note, .btn { display:none !important; }
        .main-content { margin:0 !important; padding:0 !important; max-width:100% !important; width:100% !important; }
        .card, .grade-group, .stat-card { box-shadow:none !important; border:1px solid #ccc !important; }
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
                <h2><?= htmlspecialchars($reports[$report_type]['title']) ?></h2>
                <p class="sub"><?= htmlspecialchars($reports[$report_type]['desc']) ?></p>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <a href="?<?= http_build_query(array_merge($filter_arr, ['report'=>$report_type,'export_word'=>1])) ?>" class="btn btn-outline-success btn-sm">
                <i class="bi bi-file-word me-1"></i> Export Word
            </a>
            <a href="?<?= http_build_query(array_merge($filter_arr, ['report'=>$report_type,'export_pdf'=>1])) ?>" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-file-pdf me-1"></i> Export PDF
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print">
                <i class="bi bi-printer me-1"></i> Print
            </button>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <div class="report-selector">
        <?php foreach ($reports as $key => $r): ?>
            <a href="<?= htmlspecialchars(report_url($key, $filter_arr)) ?>"
               class="report-tile tone-<?= $r['color'] ?><?= $report_type === $key ? ' active' : '' ?>">
                <div class="tile-icon"><i class="bi <?= $r['icon'] ?>"></i></div>
                <div class="tile-body">
                    <div class="tile-title"><?= htmlspecialchars($r['title']) ?></div>
                    <div class="tile-desc"><?= htmlspecialchars($r['desc']) ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($graduated_excluded && $report_type !== 'graduated'): ?>
    <div class="exclude-note">
        <i class="bi bi-shield-check"></i>
        <span>Graduated and Pending students are excluded from this report.</span>
        <span class="hint-inline">
            <i class="bi bi-info-circle me-1"></i>
            Pick a specific status below (or view the <a href="?report=graduated" style="color:inherit;text-decoration:underline;font-weight:700">Graduated report</a>) to see them.
        </span>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-funnel"></i> Filters</h3>
            <?php if ($filter_desc): ?>
                <span class="hint"><i class="bi bi-funnel-fill me-1"></i><?= htmlspecialchars($filter_text) ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-bar">
                <input type="hidden" name="report" value="<?= htmlspecialchars($report_type) ?>">
                <div class="field">
                    <label for="f_sy">School Year</label>
                    <select name="school_year" id="f_sy" onchange="this.form.submit()">
                        <option value="">All Years</option>
                        <?php foreach($sy_list as $sy): ?>
                            <option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $selected_sy==$sy['school_year']?'selected':'' ?>>
                                <?= htmlspecialchars($sy['school_year']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_grade">Grade Level</label>
                    <select name="grade_filter" id="f_grade" onchange="this.form.submit()">
                        <option value="">All Grades</option>
                        <?php foreach($grade_list as $g): ?>
                            <option value="<?= htmlspecialchars($g['grade_level']) ?>" <?= $grade_filter==$g['grade_level']?'selected':'' ?>>
                                <?= htmlspecialchars($g['grade_level']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_strand">Strand</label>
                    <select name="strand_filter" id="f_strand" onchange="this.form.submit()">
                        <option value="">All Strands</option>
                        <?php foreach($strand_list as $st): ?>
                            <option value="<?= htmlspecialchars($st['strand']) ?>" <?= $strand_filter==$st['strand']?'selected':'' ?>>
                                <?= htmlspecialchars($st['strand']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_section">Section</label>
                    <select name="section_filter" id="f_section">
                        <option value="">All Sections</option>
                        <?php foreach($section_list as $sec): ?>
                            <option value="<?= htmlspecialchars($sec['section']) ?>" <?= $section_filter==$sec['section']?'selected':'' ?>>
                                <?= htmlspecialchars($sec['section']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_status">Status</label>
                    <select name="status_filter" id="f_status">
                        <option value="">All (excl. Graduated &amp; Pending)</option>
                        <?php foreach (['Active','Pending','Transferred','Stopped','Dropped','Graduated'] as $st): ?>
                            <option value="<?= $st ?>" <?= $status_filter===$st?'selected':'' ?>><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2 me-1"></i> Apply Filters
                    </button>
                    <a href="?report=<?= htmlspecialchars($report_type) ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($report_type === 'summary'): ?>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_students) ?></div>
                <div class="stat-label">Total Students</div>
                <?php if ($graduated_excluded): ?><div class="stat-hint">Excl. Graduated &amp; Pending</div><?php endif; ?>
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
                <div class="stat-value"><?= number_format($boys) ?> / <?= number_format($girls) ?></div>
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

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3><i class="bi bi-bar-chart"></i> By Grade Level</h3><span class="hint">Male vs Female</span></div>
                <div class="card-body"><div class="chart-wrap"><canvas id="gradeChart"></canvas></div></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3><i class="bi bi-pie-chart"></i> By Strand</h3><span class="hint">Distribution</span></div>
                <div class="card-body"><div class="chart-wrap"><canvas id="strandChart"></canvas></div></div>
            </div>
        </div>
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h3><i class="bi bi-graph-up"></i> Yearly Trend</h3><span class="hint">Total students per school year</span></div>
                <div class="card-body"><div class="chart-wrap tall"><canvas id="trendChart"></canvas></div></div>
            </div>
        </div>
    </div>

    <?php elseif ($report_type === 'masterlist'): ?>
    <?php if (empty($grouped_masterlist)): ?>
        <div class="card"><div class="card-body"><div class="empty"><div class="empty-icon"><i class="bi bi-inbox"></i></div><div class="empty-title">No students found</div><div class="empty-sub">Try adjusting the filters.</div></div></div></div>
    <?php else: ?>
        <div class="card no-print" style="margin-bottom:1rem">
            <div class="card-body" style="padding:.75rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
                <div style="font-size:.85rem;color:var(--text-2)">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong style="color:var(--text)"><?= number_format(count($masterlist)) ?></strong> student(s) across
                    <strong style="color:var(--text)"><?= number_format(count($grouped_masterlist)) ?></strong> grade level(s)
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn-ghost" onclick="document.querySelectorAll('.section-block').forEach(d=>d.open=true)">
                        <i class="bi bi-arrows-expand me-1"></i> Expand All
                    </button>
                    <button type="button" class="btn-ghost" onclick="document.querySelectorAll('.section-block').forEach(d=>d.open=false)">
                        <i class="bi bi-arrows-collapse me-1"></i> Collapse All
                    </button>
                </div>
            </div>
        </div>

        <?php if (!empty($masterlist_status_counts)): ?>
        <div class="card" style="margin-bottom:1rem">
            <div class="status-summary-bar">
                <span class="label"><i class="bi bi-tags-fill me-1"></i>Status breakdown</span>
                <?php foreach ($masterlist_status_counts as $st => $cnt):
                    $cls  = 'status-chip ' . strtolower($st);
                ?>
                    <span class="<?= $cls ?>">
                        <i class="bi bi-circle-fill"></i>
                        <?= htmlspecialchars($st) ?>
                        <span class="count"><?= number_format($cnt) ?></span>
                    </span>
                <?php endforeach; ?>
                <span class="total-chip"><i class="bi bi-people-fill me-1"></i>Total: <?= number_format(count($masterlist)) ?></span>
            </div>
        </div>
        <?php endif; ?>

        <?php foreach ($grouped_masterlist as $grade => $strands):
            $grade_total = 0; $grade_m = 0; $grade_f = 0;
            foreach ($strands as $secs) {
                foreach ($secs as $studs) {
                    $grade_total += count($studs);
                    foreach ($studs as $s) {
                        if (strcasecmp($s['sex'] ?? '', 'Male') === 0)   $grade_m++;
                        elseif (strcasecmp($s['sex'] ?? '', 'Female') === 0) $grade_f++;
                    }
                }
            }
        ?>
        <div class="grade-group">
            <div class="grade-header">
                <h3 class="grade-title"><i class="bi bi-mortarboard-fill"></i> <?= htmlspecialchars($grade) ?></h3>
                <div class="grade-stats">
                    <span class="stat-chip"><i class="bi bi-people-fill"></i><?= number_format($grade_total) ?></span>
                    <span class="stat-chip"><i class="bi bi-gender-male"></i><?= number_format($grade_m) ?></span>
                    <span class="stat-chip"><i class="bi bi-gender-female"></i><?= number_format($grade_f) ?></span>
                    <span class="stat-chip"><i class="bi bi-diagram-3"></i><?= number_format(count($strands)) ?> strand(s)</span>
                </div>
            </div>

            <?php foreach ($strands as $strand => $sections):
                $strand_total = 0; $strand_m = 0; $strand_f = 0;
                foreach ($sections as $studs) {
                    $strand_total += count($studs);
                    foreach ($studs as $s) {
                        if (strcasecmp($s['sex'] ?? '', 'Male') === 0)   $strand_m++;
                        elseif (strcasecmp($s['sex'] ?? '', 'Female') === 0) $strand_f++;
                    }
                }
            ?>
            <div class="strand-band">
                <h4 class="strand-title"><i class="bi bi-diagram-3"></i> <?= htmlspecialchars($strand) ?></h4>
                <div class="strand-meta">
                    <span><i class="bi bi-people me-1"></i><?= number_format($strand_total) ?></span>
                    <span><i class="bi bi-columns-gap me-1"></i><?= number_format(count($sections)) ?> section(s)</span>
                    <span><i class="bi bi-gender-male me-1"></i><?= number_format($strand_m) ?></span>
                    <span><i class="bi bi-gender-female me-1"></i><?= number_format($strand_f) ?></span>
                </div>
            </div>

            <?php foreach ($sections as $section => $students):
                $sec_m = 0; $sec_f = 0;
                foreach ($students as $s) {
                    if (strcasecmp($s['sex'] ?? '', 'Male') === 0)   $sec_m++;
                    elseif (strcasecmp($s['sex'] ?? '', 'Female') === 0) $sec_f++;
                }
            ?>
            <details class="section-block" open>
                <summary>
                    <div class="section-name"><i class="bi bi-columns-gap"></i> <?= htmlspecialchars($section) ?></div>
                    <div class="section-pills">
                        <span class="mini-pill"><i class="bi bi-people me-1"></i><?= number_format(count($students)) ?></span>
                        <span class="mini-pill male"><i class="bi bi-gender-male me-1"></i><?= number_format($sec_m) ?></span>
                        <span class="mini-pill female"><i class="bi bi-gender-female me-1"></i><?= number_format($sec_f) ?></span>
                    </div>
                </summary>
                <div class="section-body">
                    <div class="table-scroll">
                        <table class="table-admin">
                            <thead>
                                <tr>
                                    <th style="width:44px">#</th>
                                    <th>Student ID</th>
                                    <th>LRN</th>
                                    <th>Name</th>
                                    <th>Sex</th>
                                    <th>Age</th>
                                    <th style="width:130px">Status</th>
                                    <th style="text-align:right;width:70px" class="no-print">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $i = 1; foreach ($students as $m):
                                $status = $m['status'] ?? 'Active';
                                if ($status === '' || $status === null) $status = 'Active';
                                $pillClass = match($status) {
                                    'Active'      => 'success',
                                    'Pending'     => 'warning',
                                    'Transferred' => 'info',
                                    'Graduated'   => 'purple',
                                    'Dropped'     => 'danger',
                                    default       => 'muted',
                                };
                            ?>
                                <tr>
                                    <td class="cell-muted"><?= $i++ ?></td>
                                    <td class="cell-strong"><?= htmlspecialchars($m['student_id_number']??'—') ?></td>
                                    <td class="cell-muted"><?= htmlspecialchars($m['lrn']??'—') ?></td>
                                    <td class="cell-strong"><?= htmlspecialchars($m['last_name'].', '.$m['first_name'].($m['middle_name']?' '.$m['middle_name']:'')) ?></td>
                                    <td><?= htmlspecialchars($m['sex']??'—') ?></td>
                                    <td class="cell-muted"><?= htmlspecialchars($m['age']??'—') ?></td>
                                    <td><span class="pill <?= $pillClass ?>"><?= htmlspecialchars($status) ?></span></td>
                                    <td style="text-align:right" class="no-print">
                                        <a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$m['student_id'] ?>" class="btn btn-icon btn-outline-primary" title="View">
                                            <i class="bi bi-arrow-right-short" style="font-size:1.15rem"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </details>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php elseif ($report_type === 'by_grade'): ?>
    <div class="card">
        <div class="card-header"><h3><i class="bi bi-layers"></i> Grade Level Breakdown</h3></div>
        <div class="card-body no-padding">
            <div class="table-scroll">
                <table class="table-admin">
                    <thead><tr><th>Grade Level</th><th class="cell-center">Total</th><th class="cell-center">Male</th><th class="cell-center">Female</th><th class="cell-center">M/F Ratio</th></tr></thead>
                    <tbody>
                    <?php if (!empty($grade_report)): foreach ($grade_report as $r): $ratio = $r['girls']>0 ? round($r['boys']/$r['girls'],2) : null; ?>
                        <tr>
                            <td class="cell-strong"><?= htmlspecialchars($r['grade_level']) ?></td>
                            <td class="cell-center cell-num"><?= number_format($r['total_students']) ?></td>
                            <td class="cell-center cell-male"><?= number_format($r['boys']) ?></td>
                            <td class="cell-center cell-female"><?= number_format($r['girls']) ?></td>
                            <td class="cell-center cell-muted"><?= $ratio !== null ? $ratio . ':1' : '—' ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5"><div class="empty"><div class="empty-title">No data</div></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php elseif ($report_type === 'by_strand'): ?>
    <div class="card">
        <div class="card-header"><h3><i class="bi bi-diagram-3"></i> Strand &amp; Program Breakdown</h3></div>
        <div class="card-body no-padding">
            <div class="table-scroll">
                <table class="table-admin">
                    <thead><tr><th>Strand</th><th>Program</th><th class="cell-center">Total</th><th class="cell-center">Male</th><th class="cell-center">Female</th></tr></thead>
                    <tbody>
                    <?php if (!empty($strand_report)): foreach ($strand_report as $r): ?>
                        <tr>
                            <td class="cell-strong"><?= htmlspecialchars($r['strand']) ?></td>
                            <td class="cell-muted"><?= htmlspecialchars($r['program']) ?></td>
                            <td class="cell-center cell-num"><?= number_format($r['total_students']) ?></td>
                            <td class="cell-center cell-male"><?= number_format($r['boys']) ?></td>
                            <td class="cell-center cell-female"><?= number_format($r['girls']) ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5"><div class="empty"><div class="empty-title">No data</div></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php elseif ($report_type === 'by_section'): ?>
    <div class="card">
        <div class="card-header"><h3><i class="bi bi-columns-gap"></i> Section Breakdown</h3></div>
        <div class="card-body no-padding">
            <div class="table-scroll">
                <table class="table-admin">
                    <thead><tr><th>Grade</th><th>Section</th><th class="cell-center">Total</th><th class="cell-center">Male</th><th class="cell-center">Female</th></tr></thead>
                    <tbody>
                    <?php if (!empty($section_report)): foreach ($section_report as $r): ?>
                        <tr>
                            <td class="cell-muted"><?= htmlspecialchars($r['grade_level']) ?></td>
                            <td class="cell-accent"><?= htmlspecialchars($r['section']) ?></td>
                            <td class="cell-center cell-num"><?= number_format($r['total_students']) ?></td>
                            <td class="cell-center cell-male"><?= number_format($r['boys']) ?></td>
                            <td class="cell-center cell-female"><?= number_format($r['girls']) ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5"><div class="empty"><div class="empty-title">No sections found</div></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php elseif ($report_type === 'by_gender'): ?>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-gender-male"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($boys) ?></div>
                <div class="stat-label">Male</div>
                <div class="stat-hint"><?= $boy_percent ?>% of total</div>
            </div>
        </div>
        <div class="stat-card tone-red">
            <div class="stat-icon"><i class="bi bi-gender-female"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($girls) ?></div>
                <div class="stat-label">Female</div>
                <div class="stat-hint"><?= $girl_percent ?>% of total</div>
            </div>
        </div>
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_gender) ?></div>
                <div class="stat-label">Total</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i class="bi bi-gender-ambiguous"></i> Gender Distribution by Grade</h3></div>
        <div class="card-body no-padding">
            <div class="table-scroll">
                <table class="table-admin">
                    <thead><tr><th>Grade Level</th><th class="cell-center">Total</th><th class="cell-center">Male</th><th class="cell-center">Female</th><th class="cell-center">M/F Ratio</th></tr></thead>
                    <tbody>
                    <?php if (!empty($gender_report)): foreach ($gender_report as $r): $ratio = $r['girls']>0 ? round($r['boys']/$r['girls'],2) : null; ?>
                        <tr>
                            <td class="cell-strong"><?= htmlspecialchars($r['grade_level']) ?></td>
                            <td class="cell-center cell-num"><?= number_format($r['total_students']) ?></td>
                            <td class="cell-center cell-male"><?= number_format($r['boys']) ?></td>
                            <td class="cell-center cell-female"><?= number_format($r['girls']) ?></td>
                            <td class="cell-center cell-muted"><?= $ratio !== null ? $ratio . ':1' : '—' ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5"><div class="empty"><div class="empty-title">No data</div></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php elseif ($report_type === 'documents'): ?>
    <?php
        $complete = 0; $partial = 0; $missing = 0;
        foreach ($doc_report as $d) {
            if ($d['docs_total'] > 0 && $d['docs_uploaded'] >= $d['docs_total']) $complete++;
            elseif ($d['docs_uploaded'] > 0) $partial++;
            else $missing++;
        }
    ?>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format(count($doc_report)) ?></div>
                <div class="stat-label">Total Students</div>
            </div>
        </div>
        <div class="stat-card tone-green">
            <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($complete) ?></div>
                <div class="stat-label">Complete</div>
            </div>
        </div>
        <div class="stat-card tone-amber">
            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($partial) ?></div>
                <div class="stat-label">Partial</div>
            </div>
        </div>
        <div class="stat-card tone-red">
            <div class="stat-icon"><i class="bi bi-x-circle"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($missing) ?></div>
                <div class="stat-label">Missing All</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i class="bi bi-folder-check"></i> Document Compliance <span class="hint">(<?= count($doc_report) ?> students)</span></h3></div>
        <div class="card-body no-padding">
            <div class="table-scroll">
                <table class="table-admin">
                    <thead>
                        <tr>
                            <th style="width:44px">#</th>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Grade</th>
                            <th>Section</th>
                            <th style="min-width:200px">Documents</th>
                            <th style="width:120px;text-align:right" class="no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($doc_report)): $i=1; foreach ($doc_report as $d):
                        $up = (int)$d['docs_uploaded'];
                        $tot = (int)$d['docs_total'];
                        $pct = $tot > 0 ? min(100, round(($up/$tot)*100)) : 0;

                        if ($up === 0) { $fill = 'fill-empty'; $label = 'None'; $pill = 'danger'; }
                        elseif ($tot > 0 && $up >= $tot) { $fill = 'fill-full'; $label = 'Complete'; $pill = 'success'; }
                        else { $fill = 'fill-partial'; $label = 'Partial'; $pill = 'warning'; }
                    ?>
                        <tr>
                            <td class="cell-muted"><?= $i++ ?></td>
                            <td class="cell-strong"><?= htmlspecialchars($d['student_id_number']??'—') ?></td>
                            <td class="cell-strong"><?= htmlspecialchars($d['last_name'].', '.$d['first_name']) ?></td>
                            <td><?= htmlspecialchars($d['grade_level']??'—') ?></td>
                            <td class="cell-accent"><?= htmlspecialchars($d['section']??'—') ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.2rem">
                                    <span class="pill <?= $pill ?>"><?= $label ?></span>
                                    <span class="cell-muted" style="font-size:.75rem"><?= $up ?> / <?= $tot ?></span>
                                </div>
                                <div class="doc-bar"><div class="doc-bar-fill <?= $fill ?>" style="width:<?= $pct ?>%"></div></div>
                            </td>
                            <td style="text-align:right" class="no-print">
                                <a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$d['student_id'] ?>#documents" class="btn btn-icon btn-outline-primary" title="View documents">
                                    <i class="bi bi-folder2-open"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7"><div class="empty"><div class="empty-title">No students</div></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php elseif ($report_type === 'graduated'): ?>
    <div class="stat-grid">
        <div class="stat-card tone-green">
            <div class="stat-icon"><i class="bi bi-mortarboard-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_graduated) ?></div>
                <div class="stat-label">Total Graduated</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-calendar-range"></i></div>
            <div class="stat-info">
                <div class="stat-value" style="font-size:1.15rem"><?= htmlspecialchars($selected_sy ?: 'All Years') ?></div>
                <div class="stat-label">School Year Filter</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i class="bi bi-mortarboard"></i> Graduated Students <span class="hint">(<?= number_format($total_graduated) ?>)</span></h3></div>
        <div class="card-body no-padding">
            <?php if (!empty($graduated_students)): ?>
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
                            <th style="text-align:right;width:70px" class="no-print">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($graduated_students as $g): ?>
                        <tr>
                            <td class="cell-num"><?= htmlspecialchars($g['student_id_number']??'—') ?></td>
                            <td class="cell-muted"><?= htmlspecialchars($g['lrn']??'—') ?></td>
                            <td class="cell-strong"><?= htmlspecialchars($g['last_name'].', '.$g['first_name']) ?></td>
                            <td><?= htmlspecialchars($g['sex']??'—') ?></td>
                            <td><?= htmlspecialchars($g['strand']??'—') ?></td>
                            <td class="cell-muted"><?= htmlspecialchars($g['program']??'—') ?></td>
                            <td class="cell-accent"><?= htmlspecialchars($g['section']??'—') ?></td>
                            <td class="cell-muted"><?= htmlspecialchars($g['school_year']??'—') ?></td>
                            <td style="text-align:right" class="no-print">
                                <a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$g['student_id'] ?>" class="btn btn-icon btn-outline-primary" title="View profile">
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
                    <div class="empty-sub">Try adjusting the filters.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($report_type === 'summary'): ?>
<script>
(function () {
    const h = document.documentElement;
    function cssVar(n){ return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); }
    function tooltipStyle(){ return { backgroundColor:'#0f172a', padding:10, cornerRadius:8, titleFont:{ weight:'600' }, titleColor:'#f1f5f9', bodyColor:'#e2e8f0' }; }

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
                { label:'Male',   data: <?= json_encode($grade_boys) ?>,  backgroundColor: maleGrad,   hoverBackgroundColor:'#4f46e5', borderRadius:6, borderSkipped:false, maxBarThickness:36 },
                { label:'Female', data: <?= json_encode($grade_girls) ?>, backgroundColor: femaleGrad, hoverBackgroundColor:'#ec4899', borderRadius:6, borderSkipped:false, maxBarThickness:36 }
            ]
        },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ position:'bottom', labels:{ usePointStyle:true, pointStyle:'circle', boxWidth:8, boxHeight:8, padding:16, color:cssVar('--text-2'), font:{ size:11, weight:'500' } } }, tooltip: tooltipStyle() },
            scales:{ x:{ grid:{ display:false }, ticks:{ color:cssVar('--text-3'), font:{ size:11, weight:'500' } } }, y:{ beginAtZero:true, grid:{ color:cssVar('--border'), drawBorder:false }, ticks:{ color:cssVar('--text-3'), font:{ size:11 }, precision:0 } } }
        }
    });

    window.__strandChart = new Chart(document.getElementById('strandChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($strand_labels) ?>,
            datasets: [{ data: <?= json_encode($strand_totals) ?>, backgroundColor:['#4f46e5','#7c3aed','#6366f1','#818cf8','#a5b4fc','#c7d2fe','#8b5cf6','#6d28d9'], borderWidth:3, borderColor: cssVar('--surface'), hoverOffset:6 }]
        },
        options:{
            responsive:true, maintainAspectRatio:false, cutout:'62%',
            plugins:{ legend:{ position:'bottom', labels:{ usePointStyle:true, pointStyle:'circle', boxWidth:8, boxHeight:8, padding:14, color:cssVar('--text-2'), font:{ size:11, weight:'500' } } }, tooltip: tooltipStyle() }
        }
    });

    const trendCtx = document.getElementById('trendChart').getContext('2d');
    const trendGrad = trendCtx.createLinearGradient(0, 0, 0, 260);
    trendGrad.addColorStop(0, 'rgba(79,70,229,0.25)');
    trendGrad.addColorStop(1, 'rgba(79,70,229,0)');

    window.__trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($year_labels) ?>,
            datasets: [{ label:'Students', data: <?= json_encode($year_totals) ?>, borderColor:'#4f46e5', borderWidth:2.5, backgroundColor:trendGrad, fill:true, tension:0.35, pointRadius:5, pointHoverRadius:7, pointBackgroundColor:'#4f46e5', pointBorderColor:'#fff', pointBorderWidth:2 }]
        },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ display:false }, tooltip: tooltipStyle() },
            scales:{ x:{ grid:{ display:false }, ticks:{ color:cssVar('--text-3'), font:{ size:11, weight:'500' } } }, y:{ beginAtZero:true, grid:{ color:cssVar('--border'), drawBorder:false }, ticks:{ color:cssVar('--text-3'), font:{ size:11 }, precision:0 } } }
        }
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>