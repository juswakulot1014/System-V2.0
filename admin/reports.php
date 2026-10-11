<?php
require_once __DIR__ . '/../config/auth.php';

$page_title = "Enrollment Reports";

// ====================== REPORT TYPE ======================
$valid_reports = ['summary','masterlist','by_grade','by_strand','by_section','by_gender','documents','graduated'];
$report_type = $_GET['report'] ?? 'summary';
if (!in_array($report_type, $valid_reports, true)) $report_type = 'summary';

// ====================== FILTERS ======================
$selected_sy     = $_GET['school_year']   ?? '';
$strand_filter   = $_GET['strand_filter'] ?? '';
$grade_filter    = $_GET['grade_filter']  ?? '';
$section_filter  = $_GET['section_filter']?? '';
$status_filter   = $_GET['status_filter'] ?? '';

$export_word = isset($_GET['export_word']) && $_GET['export_word'] == 1;
$export_pdf  = isset($_GET['export_pdf'])  && $_GET['export_pdf']  == 1;

// ====================== CASCADING FILTER DROPDOWNS ======================
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
$stmt = $conn->prepare("
    SELECT DISTINCT grade_level FROM enrollment_form
    WHERE grade_level IS NOT NULL AND grade_level != ''
    $w
    ORDER BY grade_level ASC
");
if (!empty($p)) $stmt->bind_param($t, ...$p);
$stmt->execute();
$grade_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

[$w, $p, $t] = $dd_build_where([
    'school_year' => $selected_sy,
    'grade_level' => $grade_filter,
]);
$stmt = $conn->prepare("
    SELECT DISTINCT strand FROM enrollment_form
    WHERE strand IS NOT NULL AND strand != ''
    $w
    ORDER BY strand ASC
");
if (!empty($p)) $stmt->bind_param($t, ...$p);
$stmt->execute();
$strand_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

[$w, $p, $t] = $dd_build_where([
    'school_year' => $selected_sy,
    'grade_level' => $grade_filter,
    'strand'      => $strand_filter,
]);
$stmt = $conn->prepare("
    SELECT DISTINCT section FROM enrollment_form
    WHERE section IS NOT NULL AND section != ''
    $w
    ORDER BY section ASC
");
if (!empty($p)) $stmt->bind_param($t, ...$p);
$stmt->execute();
$section_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

// ====================== BUILD MAIN FILTER FRAGMENTS ======================
$sy_filter    = "";
$params_main  = [];
$types_main   = "";

if ($selected_sy !== '')     { $sy_filter .= " AND e.school_year = ?";  $params_main[] = $selected_sy;    $types_main .= "s"; }
if ($strand_filter !== '')   { $sy_filter .= " AND e.strand = ?";       $params_main[] = $strand_filter;  $types_main .= "s"; }
if ($grade_filter !== '')    { $sy_filter .= " AND e.grade_level = ?";  $params_main[] = $grade_filter;   $types_main .= "s"; }
if ($section_filter !== '')  { $sy_filter .= " AND e.section = ?";      $params_main[] = $section_filter; $types_main .= "s"; }
if ($status_filter !== '')   { $sy_filter .= " AND e.status = ?";       $params_main[] = $status_filter;  $types_main .= "s"; }

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

// Masterlist
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

$gender_sql = "SELECT e.grade_level,
                      SUM(CASE WHEN s.sex = 'Male' THEN 1 ELSE 0 END) AS boys,
                      SUM(CASE WHEN s.sex = 'Female' THEN 1 ELSE 0 END) AS girls,
                      COUNT(DISTINCT s.student_id) AS total_students
               FROM enrollment_form e
               INNER JOIN students_info s ON s.student_id = e.student_id
               WHERE 1=1 $sy_filter
               GROUP BY e.grade_level
               ORDER BY e.grade_level ASC";
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

function report_url(string $report, array $filters): string {
    $params = ['report' => $report];
    foreach ($filters as $k => $v) {
        if ($v !== '' && $v !== null) $params[$k] = $v;
    }
    return '?' . http_build_query($params);
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
$filter_text = $filter_desc ? implode(' · ', $filter_desc) : 'All records';

// ====================== WORD + PDF EXPORT ======================
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
        .section-info{font-size:10px;color:#64748b;margin:4px 0 8px;font-style:italic}
        .section-page{page-break-inside:auto}
        .section-page + .section-page{page-break-before:always}
        .section-page .section-page-head{background:#eef2ff;border-left:4px solid #4f46e5;padding:8px 12px;margin:0 0 10px;page-break-after:avoid}
        .section-page .section-page-title{font-size:14px;font-weight:bold;color:#1e3c72;margin:0 0 3px}
        .section-page .section-page-meta{font-size:10px;color:#475569;margin:0}
        .section-page .section-page-meta span{margin-right:10px}
    </style></head><body>';

    echo '<h1>USAT College Sagay City Inc.</h1>';
    echo '<h2 style="border:none;margin:0;font-size:16px;color:#334155">' . htmlspecialchars($reports[$report_type]['title']) . '</h2>';
    echo '<div class="meta">';
    echo '<strong>Generated:</strong> ' . date('F d, Y h:i A') . '<br>';
    echo '<strong>Filters:</strong> ' . htmlspecialchars($filter_text);
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
        // ── Masterlist: one section per page ──
        echo '<h2>Student Masterlist</h2>';
        echo '<p style="font-size:10px;color:#64748b;margin:4px 0 14px;font-style:italic">'
           . count($masterlist) . ' student(s) across '
           . count($grouped_masterlist) . ' grade level(s). Each section is printed on its own page.</p>';

        $first_page = true;
        foreach ($grouped_masterlist as $grade => $strands) {
            $grade_total = 0;
            foreach ($strands as $secs) foreach ($secs as $studs) $grade_total += count($studs);

            foreach ($strands as $strand => $sections) {
                $strand_total = 0;
                foreach ($sections as $studs) $strand_total += count($studs);

                foreach ($sections as $section => $students) {
                    $m = 0; $f = 0;
                    foreach ($students as $s) {
                        if (strcasecmp($s['sex'] ?? '', 'Male') === 0)         $m++;
                        elseif (strcasecmp($s['sex'] ?? '', 'Female') === 0)   $f++;
                    }

                    $break_style = $first_page ? '' : ' style="page-break-before:always;"';
                    $first_page = false;

                    echo '<div class="section-page"' . $break_style . '>';

                    // Section header block — compact, shows grade + strand + section context
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

                    // Student roster table
                    echo '<table>';
                    echo '<thead><tr>';
                    echo   '<th class="num" style="width:30px">#</th>';
                    echo   '<th style="width:130px">Student ID</th>';
                    echo   '<th style="width:130px">LRN</th>';
                    echo   '<th>Name</th>';
                    echo   '<th style="width:60px">Sex</th>';
                    echo   '<th class="num" style="width:50px">Age</th>';
                    echo   '<th style="width:80px">Status</th>';
                    echo '</tr></thead><tbody>';

                    $i = 1;
                    foreach ($students as $s) {
                        $full_name = trim(($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? '') . ($s['middle_name'] ? ' ' . $s['middle_name'] : ''));
                        echo '<tr>';
                        echo   '<td class="num">' . $i++ . '</td>';
                        echo   '<td>' . htmlspecialchars($s['student_id_number'] ?? '—') . '</td>';
                        echo   '<td>' . htmlspecialchars($s['lrn'] ?? '—') . '</td>';
                        echo   '<td>' . htmlspecialchars($full_name) . '</td>';
                        echo   '<td>' . htmlspecialchars($s['sex'] ?? '—') . '</td>';
                        echo   '<td class="num">' . htmlspecialchars($s['age'] ?? '—') . '</td>';
                        echo   '<td>' . htmlspecialchars($s['status'] ?? '—') . '</td>';
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

    /* ---------- Word (.doc) ---------- */
    if ($export_word) {
        $filename = 'usat_' . $report_type . '_report_' . date('Y-m-d') . '.doc';
        header("Content-Type: application/msword");
        header("Content-Disposition: attachment; filename=\"$filename\"");
        echo $export_html;
        exit;
    }

    /* ---------- PDF (Dompdf) ---------- */
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!is_file($autoload)) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div style="font-family:Arial;padding:1rem;background:#fee2e2;color:#991b1b;border-left:4px solid #dc2626">';
        echo '<strong>PDF library not installed.</strong> Run <code>composer require dompdf/dompdf</code> in <code>' . htmlspecialchars(dirname(__DIR__)) . '</code>, then reload this page.';
        echo '</div>';
        echo $export_html;
        exit;
    }

    require_once $autoload;

    if (!class_exists('Dompdf\\Dompdf')) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div style="font-family:Arial;padding:1rem;background:#fee2e2;color:#991b1b;border-left:4px solid #dc2626">';
        echo '<strong>Dompdf class not found.</strong> The autoloader loaded but did not register the Dompdf class. Run <code>composer dump-autoload</code> in the project root, then reload this page.';
        echo '</div>';
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

$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports • USAT Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>

    <style>
        :root{
            --bg:#f4f6fb;--surface:#fff;--surface-2:#f8fafc;--text:#0f172a;--text-2:#64748b;
            --text-3:#94a3b8;--border:#e5e7eb;--border-strong:#d1d5db;--accent:#4f46e5;
            --accent-2:#6366f1;--accent-soft:#eef2ff;--green:#059669;--green-soft:#d1fae5;
            --red:#dc2626;--red-soft:#fee2e2;--amber:#d97706;--amber-soft:#fef3c7;
            --purple:#7c3aed;--purple-soft:#ede9fe;--blue:#2563eb;--blue-soft:#dbeafe;
            --pink:#ec4899;--shadow-sm:0 1px 3px rgba(15,23,42,.06),0 1px 2px rgba(15,23,42,.04);
            --shadow-md:0 4px 12px rgba(15,23,42,.08);--shadow-lg:0 12px 32px rgba(15,23,42,.10);
            --radius:12px;--radius-lg:16px;--sidebar-w:264px;
        }
        [data-bs-theme="dark"]{
            --bg:#0b1220;--surface:#131c2e;--surface-2:#1a2439;--text:#f1f5f9;--text-2:#94a3b8;
            --text-3:#64748b;--border:#1f2a44;--border-strong:#2d3b57;--accent:#818cf8;
            --accent-2:#6366f1;--accent-soft:#1e2140;--green-soft:#052e24;--red-soft:#3f1517;
            --amber-soft:#3d2a08;--purple-soft:#2e1a54;--blue-soft:#1e3a5f;
            --shadow-sm:0 1px 3px rgba(0,0,0,.35);--shadow-md:0 4px 12px rgba(0,0,0,.4);
            --shadow-lg:0 12px 32px rgba(0,0,0,.55);
        }
        *{margin:0;padding:0;box-sizing:border-box}
        html,body{height:100%}
        body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased}

        .sidebar{position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);z-index:200;display:flex;flex-direction:column;transition:transform .28s}
        .sidebar-brand{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem}
        .sidebar-brand img{width:38px;height:38px;border-radius:10px;object-fit:cover;box-shadow:0 0 0 3px var(--accent-soft)}
        .sidebar-brand .brand-name{font-weight:700;font-size:.95rem}
        .sidebar-brand .brand-sub{font-size:.68rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;font-weight:600}
        .sidebar-nav{flex:1;padding:.75rem .75rem 1rem;overflow-y:auto}
        .sidebar-nav .nav-label{font-size:.65rem;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.8px;padding:.75rem .75rem .35rem}
        .sidebar-nav a{display:flex;align-items:center;gap:.75rem;padding:.6rem .75rem;border-radius:10px;color:var(--text-2);text-decoration:none;font-weight:500;font-size:.875rem;transition:background .15s,color .15s;margin-bottom:.1rem}
        .sidebar-nav a:hover{background:var(--surface-2);color:var(--text)}
        .sidebar-nav a.active{background:var(--accent);color:#fff}
        .sidebar-nav a i{font-size:1.05rem;width:20px;text-align:center;flex-shrink:0}
        .sidebar-footer{padding:.75rem;border-top:1px solid var(--border)}

        .main-content{margin-left:var(--sidebar-w);padding:1.5rem clamp(1rem,2.5vw,2rem) 2rem;min-height:100vh;max-width:1600px}
        .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap}
        .topbar h2{font-size:1.35rem;font-weight:700;letter-spacing:-.02em;margin:0}
        .topbar .sub{font-size:.85rem;color:var(--text-2);margin:0}
        .menu-toggle{display:none;width:40px;height:40px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;align-items:center;justify-content:center}

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

        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem}
        .stat-card{position:relative;background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow-sm);padding:1.1rem 1.25rem 1.1rem 1.4rem;display:flex;align-items:center;gap:1rem;overflow:hidden;transition:transform .2s, box-shadow .2s}
        .stat-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--accent)}
        .stat-card.tone-green::before{background:var(--green)}
        .stat-card.tone-red::before{background:var(--red)}
        .stat-card.tone-purple::before{background:var(--purple)}
        .stat-card.tone-amber::before{background:var(--amber)}
        .stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-lg)}
        .stat-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;background:var(--accent-soft);color:var(--accent)}
        .tone-green .stat-icon{background:var(--green-soft);color:var(--green)}
        .tone-red .stat-icon{background:var(--red-soft);color:var(--red)}
        .tone-purple .stat-icon{background:var(--purple-soft);color:var(--purple)}
        .tone-amber .stat-icon{background:var(--amber-soft);color:var(--amber)}
        .stat-info{min-width:0;flex:1}
        .stat-info .stat-value{font-size:1.55rem;font-weight:700;line-height:1.1;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
        .stat-info .stat-value .slash{color:var(--text-3);font-weight:500;margin:0 .1rem}
        .stat-info .stat-label{font-size:.7rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;margin-top:.2rem;font-weight:600}
        .stat-info .stat-hint{font-size:.72rem;color:var(--text-3);margin-top:.3rem;font-weight:500}

        .card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow-sm);margin-bottom:1.25rem;overflow:hidden}
        .card-header{padding:.9rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;background:var(--surface)}
        .card-header h3{margin:0;font-size:.9rem;font-weight:600;display:flex;align-items:center;gap:.5rem;color:var(--text)}
        .card-header h3 i{color:var(--accent);font-size:1rem}
        .card-header .hint{font-size:.75rem;color:var(--text-2);font-weight:500}
        .card-body{padding:1.25rem}.card-body.no-padding{padding:0}

        .filter-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;align-items:end;width:100%}
        .filter-bar .field{display:flex;flex-direction:column;gap:.3rem;min-width:0}
        .filter-bar label{font-size:.68rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px}
        .filter-bar input,.filter-bar select{background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:.5rem .8rem;font-size:.85rem;color:var(--text);font-family:inherit;width:100%}
        .filter-bar input:focus,.filter-bar select:focus{border-color:var(--accent);background:var(--surface);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%, transparent);outline:none}
        .filter-bar .actions{display:flex;gap:.5rem;flex-wrap:wrap;grid-column:1/-1;justify-content:flex-end}

        .chart-wrap{position:relative;height:280px}.chart-wrap.tall{height:260px}

        .table-scroll{overflow-x:auto;max-height:640px}
        .table-admin{width:100%;border-collapse:separate;border-spacing:0}
        .table-admin thead th{background:var(--surface-2);font-size:.68rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;padding:.7rem 1rem;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;position:sticky;top:0;z-index:2}
        .table-admin tbody td{padding:.65rem 1rem;border-bottom:1px solid var(--border);font-size:.85rem;vertical-align:middle}
        .table-admin tbody tr:hover td{background:color-mix(in srgb,var(--accent) 4%, transparent)}
        .table-admin tbody tr:last-child td{border-bottom:none}
        .cell-strong{font-weight:600;color:var(--text)}
        .cell-muted{color:var(--text-2);font-size:.82rem}
        .cell-center{text-align:center}
        .cell-num{font-variant-numeric:tabular-nums;font-weight:600;color:var(--text);font-size:.85rem}
        .cell-male{color:var(--blue);font-weight:600;font-variant-numeric:tabular-nums}
        .cell-female{color:var(--pink);font-weight:600;font-variant-numeric:tabular-nums}
        .cell-accent{color:var(--accent);font-weight:600}

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
        .section-block .mini-pill.male{background:var(--blue-soft);color:var(--blue);border-color:color-mix(in srgb,var(--blue) 25%, transparent)}
        .section-block .mini-pill.female{background:#fce7f3;color:var(--pink);border-color:color-mix(in srgb,var(--pink) 25%, transparent)}
        [data-bs-theme="dark"] .section-block .mini-pill.female{background:#4a1d36;color:#f9a8d4}
        .section-block .section-body{padding:0 1.25rem 1rem}

        .doc-bar{width:100%;height:6px;border-radius:999px;background:var(--surface-2);overflow:hidden;margin-top:.25rem}
        .doc-bar-fill{height:100%;border-radius:999px;transition:width .3s}
        .fill-full{background:var(--green)}
        .fill-partial{background:var(--accent)}
        .fill-empty{background:var(--amber)}

        .pill{display:inline-flex;align-items:center;gap:.35rem;padding:.22rem .65rem;border-radius:999px;font-size:.7rem;font-weight:600;border:1px solid transparent;white-space:nowrap}
        .pill.success{background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb,var(--green) 22%, transparent)}
        .pill.warning{background:var(--amber-soft);color:var(--amber);border-color:color-mix(in srgb,var(--amber) 22%, transparent)}
        .pill.danger {background:var(--red-soft);color:var(--red);border-color:color-mix(in srgb,var(--red) 22%, transparent)}
        .pill.info   {background:var(--accent-soft);color:var(--accent);border-color:color-mix(in srgb,var(--accent) 22%, transparent)}
        .pill.muted  {background:var(--surface-2);color:var(--text-2);border-color:var(--border)}

        .btn{font-weight:500;border-radius:9px;font-size:.82rem;transition:all .15s}
        .btn-primary{background:var(--accent);border-color:var(--accent)}
        .btn-primary:hover{background:var(--accent-2);border-color:var(--accent-2)}
        .btn-outline-secondary{color:var(--text-2);border-color:var(--border)}
        .btn-outline-secondary:hover{background:var(--surface-2);color:var(--text);border-color:var(--border-strong)}
        .btn-outline-success{color:var(--green);border-color:color-mix(in srgb,var(--green) 35%, transparent)}
        .btn-outline-success:hover{background:var(--green);color:#fff;border-color:var(--green)}
        .btn-outline-danger{color:var(--red);border-color:color-mix(in srgb,var(--red) 35%, transparent)}
        .btn-outline-danger:hover{background:var(--red);color:#fff;border-color:var(--red)}
        .btn-icon{width:32px;height:32px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:9px}
        .btn-outline-primary{color:var(--accent);border-color:var(--border-strong)}
        .btn-outline-primary:hover{background:var(--accent);border-color:var(--accent);color:#fff}
        .btn-ghost{background:var(--surface-2);border:1px solid var(--border);color:var(--text-2);font-size:.72rem;padding:.3rem .7rem;border-radius:8px}
        .btn-ghost:hover{background:var(--surface);color:var(--text);border-color:var(--border-strong)}
        .theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text-2);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s}
        .theme-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}

        .empty{padding:3rem 1.5rem;text-align:center;color:var(--text-2)}
        .empty .empty-icon{width:64px;height:64px;border-radius:50%;background:var(--surface-2);color:var(--text-3);display:inline-flex;align-items:center;justify-content:center;font-size:1.6rem;margin-bottom:1rem}
        .empty .empty-title{font-weight:600;color:var(--text);margin-bottom:.25rem}
        .empty .empty-sub{font-size:.85rem}

        @media (max-width:1024px){
            .sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}
            .main-content{margin-left:0}.menu-toggle{display:flex}
        }
        @media (max-width:640px){
            .stat-grid{grid-template-columns:repeat(2,1fr);gap:.75rem}
            .stat-card{padding:1rem 1rem 1rem 1.1rem;gap:.75rem}
            .stat-icon{width:36px;height:36px;font-size:.95rem}
            .stat-info .stat-value{font-size:1.3rem}
            .chart-wrap{height:220px}
            .report-selector{grid-template-columns:1fr}
            .grade-header{flex-direction:column;align-items:flex-start}
        }

        @media print {
            @page { size: A4; margin: 12mm; }
            html, body { background: #fff !important; color: #000 !important; padding: 0 !important; margin: 0 !important; min-height: auto !important; }
            .sidebar, #sidebar, #sidebarOverlay, .topbar, .menu-toggle, .report-selector, .theme-btn, .no-print,
            .filter-card, .report-selector + .card, .btn:not(.keep-print), button.btn:not(.keep-print), a.btn:not(.keep-print) { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; max-width: 100% !important; width: 100% !important; }
            .main-content::before { content: "USAT College Sagay City Inc. — " attr(data-report-title); display: block; font-size: 14pt; font-weight: bold; color: #1e3c72; padding-bottom: 6px; border-bottom: 2px solid #1e3c72; margin-bottom: 12px; }
            .main-content::after { content: "Generated by USAT Enrollment System · " attr(data-generated); display: block; text-align: center; font-size: 8pt; color: #666; padding-top: 12px; border-top: 1px solid #ddd; margin-top: 20px; }
            .card, .grade-group, .stat-card { box-shadow: none !important; border: 1px solid #ccc !important; border-radius: 4px !important; background: #fff !important; page-break-inside: avoid; margin-bottom: 8px !important; }
            .card-header { background: #f5f5f5 !important; border-bottom: 1px solid #ccc !important; padding: 6px 10px !important; }
            .card-header h3, .card-header h3 i { font-size: 10pt !important; color: #000 !important; }
            .stat-grid { gap: 6px !important; margin-bottom: 10px !important; grid-template-columns: repeat(4, 1fr) !important; }
            .stat-card { padding: 8px 10px !important; }
            .stat-card::before { background: #666 !important; }
            .stat-icon { width: 30px !important; height: 30px !important; font-size: 12pt !important; background: #f0f0f0 !important; color: #333 !important; }
            .stat-value { font-size: 14pt !important; }
            .stat-label { font-size: 8pt !important; color: #333 !important; }
            .stat-hint { color: #555 !important; font-size: 7pt !important; }
            .chart-wrap { height: 220px !important; page-break-inside: avoid; }
            .table-scroll { overflow: visible !important; max-height: none !important; }
            .table-admin { font-size: 9pt !important; }
            .table-admin thead th { background: #e5e5e5 !important; color: #000 !important; position: static !important; padding: 4px 6px !important; font-size: 8pt !important; border: 1px solid #999 !important; }
            .table-admin tbody td { padding: 3px 6px !important; border: 1px solid #ccc !important; font-size: 9pt !important; }
            .table-admin tbody tr:hover td { background: transparent !important; }
            .cell-male   { color: #1e40af !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .cell-female { color: #be185d !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .cell-accent { color: #4338ca !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .pill { border: 1px solid #999 !important; background: #f5f5f5 !important; color: #000 !important; font-size: 8pt !important; padding: 1px 5px !important; }
            .grade-group { page-break-inside: auto; }
            .grade-header { background: #1e3c72 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; padding: 6px 10px !important; }
            .grade-header .grade-title { font-size: 12pt !important; }
            .grade-header .stat-chip { background: rgba(255,255,255,0.25) !important; font-size: 8pt !important; padding: 1px 6px !important; }
            .strand-band { background: #eef2ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; padding: 5px 10px !important; border-top: 1px solid #ccc !important; border-bottom: 1px solid #ccc !important; }
            .strand-band .strand-title { font-size: 10pt !important; color: #1e3c72 !important; }
            .strand-band .strand-meta span { background: #fff !important; font-size: 8pt !important; padding: 1px 5px !important; border: 1px solid #999 !important; }
            .section-block { page-break-inside: avoid; border: 1px solid #ccc !important; margin-bottom: 6px !important; }
            .section-block > summary { list-style: none; padding: 4px 8px !important; background: #f5f5f5 !important; border-bottom: 1px solid #ccc !important; }
            .section-block > summary::before { display: none !important; }
            .section-block .section-body { padding: 4px 8px !important; }
            .section-block .mini-pill { font-size: 8pt !important; background: #f5f5f5 !important; color: #000 !important; border: 1px solid #999 !important; }
            .doc-bar { background: #e5e5e5 !important; }
            .doc-bar-fill { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            details, details > summary, details > *:not(summary) { display: block !important; }
        }
    </style>
</head>
<body>

<?php
    $__current = basename($_SERVER['PHP_SELF']);
    $__super   = (($_SESSION['admin_role'] ?? '') === 'superadmin');
    $__student_pages = ['student_profile.php','view_student.php','edit_student.php','export_students.php','sections_list.php','sf10.php'];
    $__act = in_array($__current, $__student_pages, true) ? 'student_profile.php' : $__current;
    $__a   = fn($f) => $f === $__act ? ' class="active"' : '';
?>
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

<div class="main-content" id="mainContent"
     data-report-title="<?= htmlspecialchars($reports[$report_type]['title']) ?>"
     data-generated="<?= date('F d, Y g:i A') ?>">

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h2><?= htmlspecialchars($reports[$report_type]['title']) ?></h2>
                <p class="sub"><?= htmlspecialchars($reports[$report_type]['desc']) ?></p>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="?<?= http_build_query(array_merge($filter_arr, ['report'=>$report_type,'export_word'=>1])) ?>"
               class="btn btn-outline-success btn-sm">
                <i class="bi bi-file-word me-1"></i> Export Word
            </a>
            <a href="?<?= http_build_query(array_merge($filter_arr, ['report'=>$report_type,'export_pdf'=>1])) ?>"
               class="btn btn-outline-danger btn-sm">
                <i class="bi bi-file-pdf me-1"></i> Export PDF
            </a>
            <button onclick="preparePrint()" class="btn btn-outline-secondary btn-sm no-print">
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

    <div class="card filter-card">
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
                        <option value="">All Status</option>
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
                <div class="stat-value"><?= number_format($boys) ?><span class="slash">/</span><?= number_format($girls) ?></div>
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

    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h3><i class="bi bi-list-ol"></i> Grade Level Breakdown</h3></div>
                <div class="card-body no-padding">
                    <div class="table-scroll">
                        <table class="table-admin">
                            <thead><tr><th>Grade</th><th class="cell-center">Total</th><th class="cell-center">Male</th><th class="cell-center">Female</th><th class="cell-center">Ratio</th></tr></thead>
                            <tbody>
                            <?php if(!empty($grade_report)): foreach($grade_report as $r): $ratio = $r['girls']>0 ? round($r['boys']/$r['girls'],2) : null; ?>
                                <tr>
                                    <td class="cell-strong"><?= htmlspecialchars($r['grade_level']) ?></td>
                                    <td class="cell-center cell-num"><?= number_format($r['total_students']) ?></td>
                                    <td class="cell-center cell-male"><?= number_format($r['boys']) ?></td>
                                    <td class="cell-center cell-female"><?= number_format($r['girls']) ?></td>
                                    <td class="cell-center cell-muted"><?= $ratio !== null ? $ratio . ':1' : '—' ?></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="5"><div class="empty"><div class="empty-icon"><i class="bi bi-bar-chart"></i></div><div class="empty-title">No data</div></div></td></tr>
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
                            <thead><tr><th>Strand</th><th>Program</th><th class="cell-center">Total</th><th class="cell-center">Male</th><th class="cell-center">Female</th></tr></thead>
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
                                <tr><td colspan="5"><div class="empty"><div class="empty-icon"><i class="bi bi-tags"></i></div><div class="empty-title">No data</div></div></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php elseif ($report_type === 'masterlist'): ?>
    <?php if (empty($grouped_masterlist)): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                    <div class="empty-title">No students found</div>
                    <div class="empty-sub">Try adjusting the filters.</div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card no-print" style="margin-bottom:1rem">
            <div class="card-body" style="padding:.75rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
                <div style="font-size:.85rem;color:var(--text-2)">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong style="color:var(--text)"><?= number_format(count($masterlist)) ?></strong>
                    student(s) across
                    <strong style="color:var(--text)"><?= number_format(count($grouped_masterlist)) ?></strong>
                    grade level(s) —
                    grouped by <em>Grade → Strand → Section</em>
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
                <h3 class="grade-title">
                    <i class="bi bi-mortarboard-fill"></i>
                    <?= htmlspecialchars($grade) ?>
                </h3>
                <div class="grade-stats">
                    <span class="stat-chip"><i class="bi bi-people-fill"></i><?= number_format($grade_total) ?> students</span>
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
                <h4 class="strand-title">
                    <i class="bi bi-diagram-3"></i>
                    <?= htmlspecialchars($strand) ?>
                </h4>
                <div class="strand-meta">
                    <span><i class="bi bi-people me-1"></i><?= number_format($strand_total) ?> students</span>
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
                    <div class="section-name">
                        <i class="bi bi-columns-gap"></i>
                        <?= htmlspecialchars($section) ?>
                    </div>
                    <div class="section-pills">
                        <span class="mini-pill"><i class="bi bi-people me-1"></i><?= number_format(count($students)) ?> students</span>
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
                                    <th>Status</th>
                                    <th style="text-align:right;width:70px" class="no-print">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $i = 1; foreach ($students as $m):
                                $status = $m['status'] ?? 'Active';
                                $pill = $status === 'Active' ? 'success' : ($status === 'Pending' ? 'warning' : ($status === 'Graduated' ? 'info' : 'muted'));
                            ?>
                                <tr>
                                    <td class="cell-muted"><?= $i++ ?></td>
                                    <td class="cell-strong"><?= htmlspecialchars($m['student_id_number']??'—') ?></td>
                                    <td class="cell-muted"><?= htmlspecialchars($m['lrn']??'—') ?></td>
                                    <td class="cell-strong"><?= htmlspecialchars($m['last_name'].', '.$m['first_name'].($m['middle_name']?' '.$m['middle_name']:'')) ?></td>
                                    <td><?= htmlspecialchars($m['sex']??'—') ?></td>
                                    <td class="cell-muted"><?= htmlspecialchars($m['age']??'—') ?></td>
                                    <td><span class="pill <?= $pill ?>"><?= htmlspecialchars($status) ?></span></td>
                                    <td style="text-align:right" class="no-print">
                                        <a href="view_student.php?id=<?= (int)$m['student_id'] ?>" class="btn btn-icon btn-outline-primary" title="View">
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
                        <tr><td colspan="5"><div class="empty"><div class="empty-icon"><i class="bi bi-layers"></i></div><div class="empty-title">No grade level data</div></div></td></tr>
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
                        <tr><td colspan="5"><div class="empty"><div class="empty-icon"><i class="bi bi-diagram-3"></i></div><div class="empty-title">No strand data</div></div></td></tr>
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
                        <tr><td colspan="5"><div class="empty"><div class="empty-icon"><i class="bi bi-columns-gap"></i></div><div class="empty-title">No sections found</div></div></td></tr>
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
                        <tr><td colspan="5"><div class="empty"><div class="empty-icon"><i class="bi bi-gender-ambiguous"></i></div><div class="empty-title">No gender data</div></div></td></tr>
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
                                <a href="view_student.php?id=<?= (int)$d['student_id'] ?>#documents" class="btn btn-icon btn-outline-primary" title="View documents">
                                    <i class="bi bi-folder2-open"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7"><div class="empty"><div class="empty-icon"><i class="bi bi-folder-check"></i></div><div class="empty-title">No students</div></div></td></tr>
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
                                <a href="view_student.php?id=<?= (int)$g['student_id'] ?>" class="btn btn-icon btn-outline-primary" title="View profile">
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

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);backdrop-filter:blur(2px);z-index:199"
     onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sb = document.getElementById('sidebar');
const ov = document.getElementById('sidebarOverlay');
document.getElementById('menuToggle').addEventListener('click', () => {
    sb.classList.toggle('open');
    ov.style.display = sb.classList.contains('open') ? 'block' : 'none';
});

const tb = document.getElementById('themeToggle');
const ti = document.getElementById('themeIcon');
const h  = document.documentElement;

function cssVar(name){ return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }
function axisGrid(){ return { color: cssVar('--border'), drawBorder: false }; }
function tickColor(){ return cssVar('--text-3'); }
function tooltipStyle(){ return { backgroundColor:'#0f172a', padding:10, cornerRadius:8, titleFont:{ weight:'600' }, titleColor:'#f1f5f9', bodyColor:'#e2e8f0' }; }

function refreshChartColors(){
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

function st(t){
    h.setAttribute('data-bs-theme', t);
    ti.className = 'bi bi-' + (t === 'dark' ? 'sun-fill' : 'moon-stars-fill');
    document.cookie = 'admin_theme=' + t + ';path=/;max-age=' + (60*60*24*365);
    refreshChartColors();
}
(function(){ const m = document.cookie.match(/admin_theme=([^;]+)/); st(m ? decodeURIComponent(m[1]) : 'light'); })();
tb.addEventListener('click', () => st(h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));

function preparePrint() {
    document.querySelectorAll('details').forEach(d => d.open = true);
    setTimeout(() => { window.print(); }, 150);
}

<?php if ($report_type === 'summary'): ?>
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
        scales:{ x:{ grid:{ display:false }, ticks:{ color:tickColor(), font:{ size:11, weight:'500' } } }, y:{ beginAtZero:true, grid: axisGrid(), ticks:{ color:tickColor(), font:{ size:11 }, precision:0 } } }
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
        scales:{ x:{ grid:{ display:false }, ticks:{ color:tickColor(), font:{ size:11, weight:'500' } } }, y:{ beginAtZero:true, grid: axisGrid(), ticks:{ color:tickColor(), font:{ size:11 }, precision:0 } } }
    }
});
<?php endif; ?>
</script>
</body>
</html>