<?php
// shared/pages/sections_list.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../lib/teacher_scope.php';

$__nav_section = 'students';

$__is_teacher = (($_SESSION['admin_role'] ?? '') === 'teacher');
$__teacher_id = $__is_teacher ? (int)($_SESSION['admin_id'] ?? 0) : 0;

/* ═══ EXPORT MODES ═══ */
$export_word = isset($_GET['export_word']) && $_GET['export_word'] == 1;
$export_pdf  = isset($_GET['export_pdf'])  && $_GET['export_pdf']  == 1;

/* ═══ FILTERS ═══ */
$school_year = trim($_GET['school_year'] ?? '');
$grade_level = trim($_GET['grade_level'] ?? '');
$program     = trim($_GET['program'] ?? '');
$section     = trim($_GET['section'] ?? '');

$show_student_list = !empty($section);

/* ═══ ROLE / CSRF ═══ */
$can_edit_section = in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true);
$csrf_token       = csrf_token();

/* ═══════════════════════════════════════════════════════════════════
   POST: RENAME SECTION (Scoped to specific cluster)
   ═══════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_section'])) {

    if (!$can_edit_section) {
        http_response_code(403);
        exit('Forbidden');
    }
    verify_csrf();

    $old_name       = trim((string)($_POST['old_section_name'] ?? ''));
    $new_name       = trim((string)($_POST['new_section_name'] ?? ''));
    $target_grade   = trim((string)($_POST['target_grade']     ?? ''));
    $target_sy      = trim((string)($_POST['target_sy']        ?? ''));
    $target_program = trim((string)($_POST['target_program']   ?? ''));

    $redirect_params = [
        'school_year' => $school_year,
        'grade_level' => $grade_level,
        'program'     => $program,
        'section'     => $section,
    ];

    $err = '';

    if ($old_name === '') {
        $err = 'Current section name is missing.';
    } elseif ($new_name === '') {
        $err = 'New section name is required.';
    } elseif (mb_strlen($new_name) > 100) {
        $err = 'Section name must be at most 100 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9\s\-_.()\/]+$/u', $new_name)) {
        $err = 'Section name may only contain letters, numbers, spaces, dashes, underscores, dots, parentheses, or slashes.';
    } elseif ($old_name === $new_name) {
        $_SESSION['info'] = 'No changes — the new name is identical to the current one.';
        $qs = http_build_query(array_filter($redirect_params));
        header('Location: ' . URL_PREFIX . '/sections_list.php' . ($qs !== '' ? '?' . $qs : ''));
        exit;
    }

    // FIX: Verify the cluster actually exists before touching anything.
    if ($err === '') {
        $verify = $conn->prepare("
            SELECT 1 FROM enrollment_form
             WHERE section = ?
               AND COALESCE(grade_level, '') = ?
               AND COALESCE(school_year, '') = ?
               AND COALESCE(program, '')     = ?
             LIMIT 1
        ");
        if ($verify) {
            $verify->bind_param('ssss', $old_name, $target_grade, $target_sy, $target_program);
            $verify->execute();
            $verify->store_result();
            if ($verify->num_rows === 0) {
                $err = 'That section cluster no longer exists. Refresh and try again.';
            }
            $verify->close();
        } else {
            $err = 'System error: could not verify cluster.';
        }
    }

    // Verify new name doesn't already exist in this cluster
    if ($err === '') {
        $chk = $conn->prepare("
            SELECT COUNT(*) AS c FROM enrollment_form
             WHERE section = ?
               AND COALESCE(grade_level, '') = ?
               AND COALESCE(school_year, '') = ?
               AND COALESCE(program, '')     = ?
        ");
        if ($chk) {
            $chk->bind_param('ssss', $new_name, $target_grade, $target_sy, $target_program);
            $chk->execute();
            $exists = (int)($chk->get_result()->fetch_assoc()['c'] ?? 0);
            $chk->close();
            if ($exists > 0) {
                $err = "A section named \"{$new_name}\" already exists in this specific grade/year/program cluster. Pick a different name.";
            }
        } else {
            $err = 'System error: could not verify name uniqueness.';
        }
    }

    if ($err === '') {
        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("
                UPDATE enrollment_form
                   SET section = ?
                 WHERE section = ?
                   AND COALESCE(grade_level, '') = ?
                   AND COALESCE(school_year, '') = ?
                   AND COALESCE(program, '')     = ?
            ");
            if (!$upd) throw new RuntimeException('Prepare failed: ' . $conn->error);
            $upd->bind_param('sssss', $new_name, $old_name, $target_grade, $target_sy, $target_program);
            $upd->execute();
            $affected = $upd->affected_rows;
            $upd->close();

            if ($affected === 0) {
                $conn->rollback();
                $err = 'No records found matching that section and cluster.';
            } else {
                // FIX: COALESCE on teacher_sections cascade so NULL columns don't skip the row.
                $cascade = $conn->prepare("
                    UPDATE teacher_sections
                       SET section = ?
                     WHERE section = ?
                       AND COALESCE(grade_level, '') = ?
                       AND COALESCE(school_year, '') = ?
                       AND COALESCE(program, '')     = ?
                ");
                if ($cascade) {
                    $cascade->bind_param('sssss', $new_name, $old_name, $target_grade, $target_sy, $target_program);
                    $cascade->execute();
                    $cascade->close();
                }

                $conn->commit();

                if (function_exists('audit_log')) {
                    audit_log($conn, 'section.rename', [
                        'type'         => 'section',
                        'old_name'     => $old_name,
                        'new_name'     => $new_name,
                        'target_grade' => $target_grade,
                        'target_sy'    => $target_sy,
                        'target_prog'  => $target_program,
                        'records'      => $affected,
                        'actor_role'   => $_SESSION['admin_role'] ?? 'unknown',
                    ]);
                }

                $_SESSION['success'] = "Section renamed: \"{$old_name}\" → \"{$new_name}\" ({$affected} record" . ($affected === 1 ? '' : 's') . " updated in the selected cluster).";

                if ($show_student_list && $section === $old_name) {
                    $redirect_params['section']     = $new_name;
                    $redirect_params['grade_level'] = $target_grade;
                    $redirect_params['school_year'] = $target_sy;
                    $redirect_params['program']     = $target_program;
                }
                $qs = http_build_query(array_filter($redirect_params));
                header('Location: ' . URL_PREFIX . '/sections_list.php' . ($qs !== '' ? '?' . $qs : ''));
                exit;
            }
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('section.rename failed: ' . $e->getMessage());
            $err = 'Rename failed: ' . $e->getMessage();
        }
    }

    $_SESSION['error'] = $err;
    $qs = http_build_query(array_filter($redirect_params));
    header('Location: ' . URL_PREFIX . '/sections_list.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

/* ═══ DATA ═══ */
$section_result  = null;
$section_clusters = [];
$students_result = null;
$roster_clusters = [];

if (!$show_student_list) {
    $where = "WHERE e.section IS NOT NULL AND e.section != ''";
    $where .= " AND COALESCE(e.status, 'Active') NOT IN ('Graduated', 'Pending')";
    $params = [];
    $types = "";

    if ($__is_teacher) {
        $ts = teacher_scope_filter($conn, $__teacher_id, 'e');
        $where .= " AND " . $ts['sql'];
        $params = array_merge($params, $ts['params']);
        $types .= $ts['types'];
    }

    if ($school_year) { $where .= " AND e.school_year = ?"; $params[] = $school_year; $types .= "s"; }
    if ($grade_level) { $where .= " AND e.grade_level = ?"; $params[] = $grade_level; $types .= "s"; }
    if ($program)     { $where .= " AND e.program = ?";     $params[] = $program;     $types .= "s"; }

    $sql = "SELECT e.section, e.grade_level,
                   COUNT(DISTINCT s.student_id) AS total,
                   COUNT(DISTINCT CASE WHEN s.sex = 'Male'   THEN s.student_id END) AS boys,
                   COUNT(DISTINCT CASE WHEN s.sex = 'Female' THEN s.student_id END) AS girls
            FROM enrollment_form e
            JOIN students_info s ON e.student_id = s.student_id
            $where
            GROUP BY e.section, e.grade_level
            ORDER BY e.grade_level ASC, e.section ASC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $section_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!empty($section_result)) {
        $clusterSql = "SELECT e.section, e.grade_level, e.school_year, e.program,
                              COUNT(DISTINCT s.student_id) AS total,
                              COUNT(DISTINCT CASE WHEN s.sex = 'Male'   THEN s.student_id END) AS boys,
                              COUNT(DISTINCT CASE WHEN s.sex = 'Female' THEN s.student_id END) AS girls
                       FROM enrollment_form e
                       JOIN students_info s ON e.student_id = s.student_id
                       $where
                       GROUP BY e.section, e.grade_level, e.school_year, e.program
                       ORDER BY e.section ASC, e.school_year DESC, e.grade_level ASC, e.program ASC";

        $cstmt = $conn->prepare($clusterSql);
        if (!empty($params)) $cstmt->bind_param($types, ...$params);
        $cstmt->execute();
        $cres = $cstmt->get_result();
        while ($row = $cres->fetch_assoc()) {
            $key = $row['section'] . '|' . ($row['grade_level'] ?? '');
            $section_clusters[$key][] = [
                'grade_level' => (string)($row['grade_level'] ?? ''),
                'school_year' => (string)($row['school_year'] ?? ''),
                'program'     => (string)($row['program'] ?? ''),
                'total'       => (int)$row['total'],
                'boys'        => (int)$row['boys'],
                'girls'       => (int)$row['girls'],
            ];
        }
        $cstmt->close();
    }
} else {
    $where = "WHERE e.section = ?";
    $where .= " AND COALESCE(e.status, 'Active') NOT IN ('Graduated', 'Pending')";
    $params = [$section];
    $types = "s";

    if ($__is_teacher) {
        $ts = teacher_scope_filter($conn, $__teacher_id, 'e');
        $where .= " AND " . $ts['sql'];
        $params = array_merge($params, $ts['params']);
        $types .= $ts['types'];
    }

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

    $cSql = "SELECT e.grade_level, e.school_year, e.program,
                    COUNT(DISTINCT s.student_id) AS total
             FROM enrollment_form e
             JOIN students_info s ON e.student_id = s.student_id
             $where
             GROUP BY e.grade_level, e.school_year, e.program
             ORDER BY e.school_year DESC, e.grade_level ASC, e.program ASC";
    $cstmt = $conn->prepare($cSql);
    if (!empty($params)) $cstmt->bind_param($types, ...$params);
    $cstmt->execute();
    $cres = $cstmt->get_result();
    while ($row = $cres->fetch_assoc()) {
        $roster_clusters[] = [
            'grade_level' => (string)($row['grade_level'] ?? ''),
            'school_year' => (string)($row['school_year'] ?? ''),
            'program'     => (string)($row['program'] ?? ''),
            'total'       => (int)$row['total'],
        ];
    }
    $cstmt->close();
}

/* ═══ Dropdowns (scoped for teachers) ═══ */
if ($__is_teacher) {
    $ts = teacher_scope_filter($conn, $__teacher_id, 'enrollment_form');

    $ys = $conn->prepare("SELECT DISTINCT school_year FROM enrollment_form WHERE school_year IS NOT NULL AND school_year != '' AND " . $ts['sql'] . " ORDER BY school_year DESC");
    if ($ys) {
        if (!empty($ts['params'])) $ys->bind_param($ts['types'], ...$ts['params']);
        $ys->execute();
        $years = $ys->get_result()->fetch_all(MYSQLI_ASSOC);
        $ys->close();
    } else { $years = []; }

    $gs = $conn->prepare("SELECT DISTINCT grade_level FROM enrollment_form WHERE grade_level IS NOT NULL AND grade_level != '' AND " . $ts['sql'] . " ORDER BY grade_level ASC");
    if ($gs) {
        if (!empty($ts['params'])) $gs->bind_param($ts['types'], ...$ts['params']);
        $gs->execute();
        $grades = $gs->get_result()->fetch_all(MYSQLI_ASSOC);
        $gs->close();
    } else { $grades = []; }

    $ps = $conn->prepare("SELECT DISTINCT program FROM enrollment_form WHERE program IS NOT NULL AND program != '' AND " . $ts['sql'] . " ORDER BY program ASC");
    if ($ps) {
        if (!empty($ts['params'])) $ps->bind_param($ts['types'], ...$ts['params']);
        $ps->execute();
        $programs = $ps->get_result()->fetch_all(MYSQLI_ASSOC);
        $ps->close();
    } else { $programs = []; }
} else {
    $years    = $conn->query("SELECT DISTINCT school_year FROM enrollment_form ORDER BY school_year DESC")->fetch_all(MYSQLI_ASSOC);
    $grades   = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form ORDER BY grade_level ASC")->fetch_all(MYSQLI_ASSOC);
    $programs = $conn->query("SELECT DISTINCT program FROM enrollment_form WHERE program IS NOT NULL AND program != '' ORDER BY program ASC")->fetch_all(MYSQLI_ASSOC);
}

/* ═══ STATS — summary ═══ */
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

/* ═══ STATS — roster ═══ */
$sec_title = ''; $sec_count = 0; $sec_boys = 0; $sec_girls = 0;
if ($show_student_list && !empty($students_result)) {
    $sec_title = (string)($students_result[0]['section'] ?? $section);
    $sec_count = count($students_result);
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

/* ═══ EXPORT BUILDER ═══ */
if (!function_exists('build_export_html')) {
    function build_export_html(
        bool $show_student_list,
        array $section_result,
        array $section_clusters,
        array $students_result,
        string $filter_text,
        string $sec_title,
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
            .cluster-cell { font-size: 10px; color: #475569; }
            .cluster-pill { display: inline-block; padding: 1px 7px; border-radius: 12px; background: #f1f5f9; color: #334155; font-size: 9px; margin: 1px 2px 1px 0; border: 1px solid #cbd5e1; }
            .section-row-cell { font-weight: bold; color: #1e3c72; }
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
            $html .= '<th style="width:120px">LRN</th>';
            $html .= '<th style="width:120px">Grade · SY</th>';
            $html .= '<th style="width:70px">Sex</th>';
            $html .= '</tr></thead><tbody>';

            $i = 1;
            foreach ($students_result as $r) {
                $name = trim(($r['last_name'] ?? '') . ', ' . ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? ''));
                $sex_class = $r['sex'] === 'Male' ? 'badge badge-male' : ($r['sex'] === 'Female' ? 'badge badge-female' : 'badge');
                $cluster_label = trim(($r['grade_level'] ?? '—') . ' · ' . ($r['school_year'] ?? '—'));

                $html .= '<tr>';
                $html .= '<td class="num">' . $i++ . '</td>';
                $html .= '<td><strong>' . htmlspecialchars($r['student_id_number'] ?? '—') . '</strong></td>';
                $html .= '<td>' . htmlspecialchars($name) . '</td>';
                $html .= '<td>' . htmlspecialchars($r['lrn'] ?? '—') . '</td>';
                $html .= '<td class="cluster-cell">' . htmlspecialchars($cluster_label) . '</td>';
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
            $html .= '<th style="width:70px">Total</th>';
            $html .= '<th style="width:60px">Male</th>';
            $html .= '<th style="width:60px">Female</th>';
            $html .= '<th>Clusters (SY · Program)</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($section_result as $s) {
                $clusters = $section_clusters[$s['section'] . '|' . $s['grade_level']] ?? [];
                $cluster_html = '';
                foreach ($clusters as $c) {
                    $label = trim(
                        ($c['school_year'] ?: '—') .
                        ($c['program'] !== '' ? ' · ' . $c['program'] : '')
                    );
                    $cluster_html .= '<span class="cluster-pill">' . htmlspecialchars($label) . ' (' . (int)$c['total'] . ')</span>';
                }

                $html .= '<tr>';
                $html .= '<td class="section-row-cell">' . htmlspecialchars($s['section']) . '</td>';
                $html .= '<td class="cluster-cell">' . htmlspecialchars($s['grade_level'] ?: '—') . '</td>';
                $html .= '<td class="num"><strong>' . (int)$s['total'] . '</strong></td>';
                $html .= '<td class="male">' . (int)$s['boys'] . '</td>';
                $html .= '<td class="female">' . (int)$s['girls'] . '</td>';
                $html .= '<td class="cluster-cell">' . ($cluster_html ?: '—') . '</td>';
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
        $show_student_list, $section_result ?? [], $section_clusters ?? [], $students_result ?? [],
        $filter_text, $sec_title, $sec_count, $sec_boys, $sec_girls,
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
        $show_student_list, $section_result ?? [], $section_clusters ?? [], $students_result ?? [],
        $filter_text, $sec_title, $sec_count, $sec_boys, $sec_girls,
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

/* ═══ HELPERS FOR RENDER ═══ */
if (!function_exists('_cluster_chip_label')) {
    function _cluster_chip_label(array $c): string {
        $parts = [];
        if (!empty($c['grade_level'])) $parts[] = $c['grade_level'];
        if (!empty($c['school_year'])) $parts[] = 'SY ' . $c['school_year'];
        if (!empty($c['program']))     $parts[] = $c['program'];
        return $parts ? implode(' · ', $parts) : 'Unassigned';
    }
}

/* ═══ RENDER ═══ */
$page_title = ($show_student_list ? 'Section Students' : 'Sections') . ' • USAT Admin';

$page_css = <<<'CSS'
    /* Section rows (clickable) */
    .section-row { cursor: pointer; transition: background .15s ease; }
    .section-row:hover td { background: color-mix(in srgb, var(--accent) 6%, var(--surface)) !important; }
    .section-row:active td { background: color-mix(in srgb, var(--accent) 10%, var(--surface)) !important; }
    .section-row .row-chevron { color: var(--text-3); font-size: 1rem; transition: transform .15s ease, color .15s ease; display: inline-block; }
    .section-row:hover .row-chevron { color: var(--accent); transform: translateX(2px); }

    .section-cell{display:flex;align-items:center;gap:.6rem}
    .section-badge{width:34px;height:34px;border-radius:9px;background:var(--accent-soft);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
    .section-name{font-weight:700;color:var(--text);font-size:.9rem;line-height:1.2;text-decoration:none;display:flex;align-items:center;gap:.5rem;}
    .section-name:hover{color:var(--accent)}
    .section-total{font-size:.72rem;color:var(--text-2);margin-top:.15rem}
    .section-total strong{color:var(--text);font-weight:700}

    /* Cluster chips */
    .cluster-chips { display: flex; flex-wrap: wrap; gap: .3rem; }
    .cluster-chip {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .22rem .6rem .22rem .55rem;
        border-radius: 7px;
        font-size: .7rem; font-weight: 600;
        background: var(--surface-2);
        color: var(--text-2);
        border: 1px solid var(--border);
        text-decoration: none;
        transition: all .15s ease;
        white-space: nowrap;
        cursor: pointer;
    }
    .cluster-chip:hover {
        background: var(--accent-soft);
        color: var(--accent);
        border-color: color-mix(in srgb, var(--accent) 40%, transparent);
        transform: translateY(-1px);
        text-decoration: none;
    }
    .cluster-chip .cluster-label { font-weight: 600; }
    .cluster-chip .cluster-count {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 1.3rem; padding: 0 .35rem;
        border-radius: 999px;
        background: var(--surface);
        color: var(--text);
        font-size: .68rem;
        font-weight: 700;
        border: 1px solid var(--border);
    }
    .cluster-chip:hover .cluster-count {
        background: var(--accent);
        color: #fff;
        border-color: var(--accent);
    }
    .cluster-more {
        display: inline-flex; align-items: center;
        padding: .22rem .6rem;
        border-radius: 7px;
        font-size: .7rem; font-weight: 600;
        background: var(--surface-2);
        color: var(--text-3);
        border: 1px dashed var(--border);
    }

    /* Hero */
    .section-hero{
        display:flex;align-items:center;gap:1.25rem;padding:1.5rem 1.75rem;
        background:linear-gradient(135deg,#5b54e8 0%, #7c78f0 100%);
        color:#fff;position:relative;overflow:hidden;
        border-radius:var(--radius-lg);margin-bottom:1.25rem;
        box-shadow:0 12px 32px -14px rgba(79,70,229,.55);
        flex-wrap:wrap;
    }
    [data-bs-theme="dark"] .section-hero{background:linear-gradient(135deg,#4c46c5 0%, #5f5acc 100%)}
    .section-hero::after{content:'';position:absolute;right:-40px;top:-40px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none}
    .section-hero-avatar{width:64px;height:64px;border-radius:16px;background:rgba(255,255,255,.18);border:2px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:800;flex-shrink:0;backdrop-filter:blur(4px);z-index:1}
    .section-hero-info{flex:1;min-width:0;z-index:1}
    .section-hero-info h1{font-size:1.35rem;font-weight:700;letter-spacing:-.01em;margin:0 0 .35rem;line-height:1.2}
    .section-hero-chips{display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.2rem}
    .section-hero-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .7rem;border-radius:999px;background:rgba(255,255,255,.18);font-size:.72rem;font-weight:600;backdrop-filter:blur(4px);white-space:nowrap;color:#fff;text-decoration:none;transition:background .15s}
    .section-hero-chip:hover{background:rgba(255,255,255,.3);color:#fff}
    .section-hero-chip i{font-size:.75rem;opacity:.9}
    .section-hero-chip .chip-count{background:rgba(0,0,0,.25);padding:.05rem .4rem;border-radius:999px;font-size:.65rem;font-weight:800;margin-left:.15rem}
    .section-hero-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;z-index:1}

    .section-hero .btn-hero{background:rgba(255,255,255,.92);color:var(--accent);border:none;font-weight:600;padding:.55rem 1.1rem;border-radius:9px;font-size:.82rem;display:inline-flex;align-items:center;gap:.45rem;text-decoration:none;transition:all .15s;flex-shrink:0;cursor:pointer;font-family:inherit;line-height:1.2}
    .section-hero .btn-hero:hover{background:#fff;transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.15);color:var(--accent)}
    .section-hero .btn-hero:focus-visible{outline:2px solid #fff;outline-offset:2px}
    .section-hero .btn-hero-edit{background:rgba(255,255,255,.95);color:#b45309;border:1px solid rgba(180,83,9,.2);box-shadow:0 4px 12px rgba(0,0,0,.12)}
    .section-hero .btn-hero-edit:hover{background:#fff;color:#92400e;transform:translateY(-1px);box-shadow:0 8px 18px rgba(0,0,0,.18)}

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

    /* Rename modal */
    .rename-modal .modal-content{border-radius:var(--radius-lg);border:none;overflow:hidden;box-shadow:0 24px 64px -12px rgba(0,0,0,.35)}
    .rename-modal .modal-header{background:linear-gradient(135deg,var(--amber),#d97706);color:#fff;border-bottom:none;padding:1.15rem 1.35rem}
    .rename-modal .modal-header .modal-title{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:.5rem}
    .rename-modal .modal-header .btn-close{filter:brightness(0) invert(1);opacity:.85}
    .rename-modal .modal-header .btn-close:hover{opacity:1}
    .rename-modal .modal-body{padding:1.35rem}
    .rename-modal .modal-footer{padding:1rem 1.35rem;border-top:1px solid var(--border);background:var(--surface-2)}
    .rename-modal .form-label{font-size:.72rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:.4rem}
    .rename-modal .form-control, .rename-modal .form-select{background:var(--surface-2);border:1.5px solid var(--border);border-radius:10px;padding:.6rem .85rem;font-size:.9rem;transition:border-color .15s, box-shadow .15s, background .15s}
    .rename-modal .form-control:focus, .rename-modal .form-select:focus{border-color:var(--amber);background:var(--surface);box-shadow:0 0 0 3px color-mix(in srgb,var(--amber) 18%,transparent);outline:none}
    .rename-modal .form-control[readonly]{background:var(--surface);border-style:dashed;cursor:not-allowed;color:var(--text-2)}
    .rename-modal .warning-note{display:flex;align-items:flex-start;gap:.55rem;margin-top:.9rem;padding:.7rem .9rem;border-radius:8px;background:var(--amber-soft);color:var(--amber);font-size:.78rem;line-height:1.5}
    .rename-modal .warning-note i{flex-shrink:0;margin-top:2px}
    .rename-modal .teacher-note{display:flex;align-items:flex-start;gap:.55rem;margin-top:.9rem;padding:.7rem .9rem;border-radius:8px;background:#dbeafe;color:#1e40af;font-size:.78rem;line-height:1.5;border:1px solid rgba(30,64,175,.2)}
    .rename-modal .teacher-note i{flex-shrink:0;margin-top:2px;color:#2563eb}
    [data-bs-theme="dark"] .rename-modal .teacher-note{background:#1e3a5f;color:#93c5fd;border-color:rgba(147,197,253,.2)}

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
        .section-hero-actions{justify-content:center;width:100%}
        .cluster-chips{justify-content:center}
    }
CSS;

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/sidebar.php';
?>

<div class="main-content" id="mainContent">

    <?php if (isset($_SESSION['success'])): ?>
        <div class="flash success">
            <i class="bi bi-check-circle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['success']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="flash error">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['error']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['info'])): ?>
        <div class="flash" style="background:var(--accent-soft);color:var(--accent);border-color:color-mix(in srgb,var(--accent) 25%,transparent)">
            <i class="bi bi-info-circle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['info']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['info']); ?>
    <?php endif; ?>

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
                    <p class="sub">Click a section row to open its roster, or click a cluster chip to view that grade / year only</p>
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
                        <?php foreach ($roster_clusters as $c):
                            $label = _cluster_chip_label($c);
                        ?>
                            <span class="section-hero-chip">
                                <i class="bi bi-diagram-3-fill"></i>
                                <?= htmlspecialchars($label) ?>
                                <span class="chip-count"><?= (int)$c['total'] ?></span>
                            </span>
                        <?php endforeach; ?>
                        <span class="section-hero-chip">
                            <i class="bi bi-people-fill"></i>
                            <?= $sec_count ?> student<?= $sec_count === 1 ? '' : 's' ?>
                        </span>
                    </div>
                </div>

                <div class="section-hero-actions">
                    <?php if ($can_edit_section): ?>
                        <button type="button"
                                class="btn-hero btn-hero-edit"
                                data-bs-toggle="modal"
                                data-bs-target="#renameSectionModal">
                            <i class="bi bi-pencil-square"></i> Update Section Name
                        </button>
                    <?php endif; ?>

                    <a href="<?= URL_PREFIX ?>/sections_list.php" class="btn-hero">
                        <i class="bi bi-arrow-left"></i> All Sections
                    </a>
                </div>
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
                    <div class="stat-icon"><i class="bi bi-diagram-3"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format(count($roster_clusters)) ?></div>
                        <div class="stat-label">Cluster<?= count($roster_clusters) === 1 ? '' : 's' ?></div>
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
                                    <th style="width:180px">Grade · SY</th>
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
                                    <td class="cell-muted">
                                        <?= htmlspecialchars(($r['grade_level'] ?? '—') . ' · ' . ($r['school_year'] ?? '—')) ?>
                                    </td>
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
                <?php if (!empty($section_result)): ?>
                    <span class="cell-muted" style="font-size:.75rem">
                        <i class="bi bi-hand-index-thumb me-1"></i> Row → full roster · chip → that grade / year only<?= $can_edit_section ? ' · pencil → rename' : '' ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body no-padding">
                <?php if(!empty($section_result)): ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th>Section</th>
                                <th class="cell-center" style="width:80px">Total</th>
                                <th class="cell-center" style="width:70px">Male</th>
                                <th class="cell-center" style="width:80px">Female</th>
                                <th>Clusters</th>
                                <th style="width:110px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($section_result as $s):
                                $roster_url = URL_PREFIX . '/sections_list.php?' . http_build_query(array_filter([
                                    'section'     => $s['section'],
                                    'grade_level' => $s['grade_level'],
                                    'school_year' => $school_year,
                                    'program'     => $program,
                                ]));

                                $cluster_key = $s['section'] . '|' . $s['grade_level'];
                                $clusters = $section_clusters[$cluster_key] ?? [];
                                $visible_chips = array_slice($clusters, 0, 6);
                                $hidden_chips  = array_slice($clusters, 6);
                            ?>
                            <tr class="section-row"
                                tabindex="0"
                                role="link"
                                aria-label="Open full roster for section <?= htmlspecialchars($s['section']) ?>"
                                data-href="<?= htmlspecialchars($roster_url, ENT_QUOTES, 'UTF-8') ?>"
                                onclick="window.location=this.dataset.href"
                                onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location=this.dataset.href;}">
                                <td>
                                    <div class="section-cell">
                                        <div class="section-badge"><i class="bi bi-columns-gap"></i></div>
                                        <div>
                                            <div class="section-name">
                                                <?= htmlspecialchars($s['section']) ?>
                                                <span class="pill info" style="font-size:.65rem; padding: 0.15rem 0.4rem; border-radius: 4px;">
                                                    <?= htmlspecialchars($s['grade_level'] ?: 'No Grade') ?>
                                                </span>
                                            </div>
                                            <div class="section-total"><strong><?= number_format((int)$s['total']) ?></strong> student<?= (int)$s['total'] === 1 ? '' : 's' ?> across <?= count($clusters) ?> cluster<?= count($clusters) === 1 ? '' : 's' ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="cell-center cell-num" style="font-weight:700;color:var(--text)"><?= number_format((int)$s['total']) ?></td>
                                <td class="cell-center cell-male"><?= number_format((int)$s['boys']) ?></td>
                                <td class="cell-center cell-female"><?= number_format((int)$s['girls']) ?></td>
                                <td>
                                    <?php if (!empty($clusters)): ?>
                                        <div class="cluster-chips">
                                            <?php foreach ($visible_chips as $c):
                                                $chip_url = URL_PREFIX . '/sections_list.php?' . http_build_query(array_filter([
                                                    'section'     => $s['section'],
                                                    'grade_level' => $c['grade_level'],
                                                    'school_year' => $c['school_year'],
                                                    'program'     => $c['program'],
                                                ]));
                                                $label = _cluster_chip_label($c);
                                            ?>
                                                <a class="cluster-chip"
                                                   href="<?= htmlspecialchars($chip_url, ENT_QUOTES, 'UTF-8') ?>"
                                                   onclick="event.stopPropagation();"
                                                   title="View <?= htmlspecialchars($label) ?> only">
                                                    <span class="cluster-label"><?= htmlspecialchars($label) ?></span>
                                                    <span class="cluster-count"><?= (int)$c['total'] ?></span>
                                                </a>
                                            <?php endforeach; ?>
                                            <?php if (!empty($hidden_chips)): ?>
                                                <span class="cluster-more" title="<?= htmlspecialchars(implode(' · ', array_map('_cluster_chip_label', $hidden_chips))) ?>">
                                                    +<?= count($hidden_chips) ?> more
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="cell-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;white-space:nowrap">
                                    <?php if ($can_edit_section): ?>
                                        <a href="<?= htmlspecialchars($roster_url . '&open_rename=1', ENT_QUOTES, 'UTF-8') ?>"
                                           class="btn btn-icon btn-outline-warning"
                                           onclick="event.stopPropagation();"
                                           title="Rename this section">
                                            <i class="bi bi-pencil-square"></i>
                                        </a>
                                    <?php endif; ?>
                                    <i class="bi bi-chevron-right row-chevron" aria-hidden="true"></i>
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

<?php if ($can_edit_section && $show_student_list && !empty($students_result)): ?>
<!-- ═══════════════════════════════════════════════════════
     UPDATE SECTION NAME MODAL
     ═══════════════════════════════════════════════════════ -->
<div class="modal fade rename-modal" id="renameSectionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="renameSectionForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="rename_section" value="1">
                <input type="hidden" name="old_section_name" value="<?= htmlspecialchars($section, ENT_QUOTES, 'UTF-8') ?>">

                <input type="hidden" name="target_grade" id="rs_grade" value="">
                <input type="hidden" name="target_sy" id="rs_sy" value="">
                <input type="hidden" name="target_program" id="rs_program" value="">

                <div class="modal-header">
                    <h6 class="modal-title">
                        <i class="bi bi-pencil-square"></i> Update Section Name
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <?php if (count($roster_clusters) > 1): ?>
                    <div class="mb-3">
                        <label class="form-label" for="rs_cluster">Which cluster to rename? <span class="text-danger">*</span></label>
                        <select class="form-select" id="rs_cluster" required>
                            <?php foreach ($roster_clusters as $c):
                                $val = json_encode([
                                    'g' => $c['grade_level'],
                                    's' => $c['school_year'],
                                    'p' => $c['program']
                                ]);
                                $label = _cluster_chip_label($c);
                            ?>
                                <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>"
                                        data-total="<?= (int)$c['total'] ?>"
                                        data-grade="<?= htmlspecialchars($c['grade_level'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-sy="<?= htmlspecialchars($c['school_year'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-program="<?= htmlspecialchars($c['program'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($label) ?> (<?= (int)$c['total'] ?> students)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text mt-1" style="font-size:.72rem;color:var(--text-3)">
                            Only students in the selected cluster will be updated. Other grades/years are untouched.
                        </div>
                    </div>
                    <?php else: ?>
                        <?php $single_cluster = $roster_clusters[0]; ?>
                        <select id="rs_cluster" style="display:none">
                            <option value="<?= htmlspecialchars(json_encode(['g' => $single_cluster['grade_level'], 's' => $single_cluster['school_year'], 'p' => $single_cluster['program']]), ENT_QUOTES, 'UTF-8') ?>"
                                    data-total="<?= (int)$single_cluster['total'] ?>"
                                    data-grade="<?= htmlspecialchars($single_cluster['grade_level'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-sy="<?= htmlspecialchars($single_cluster['school_year'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-program="<?= htmlspecialchars($single_cluster['program'], ENT_QUOTES, 'UTF-8') ?>">
                            </option>
                        </select>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label" for="rs_current">Current name</label>
                        <input type="text"
                               class="form-control"
                               id="rs_current"
                               value="<?= htmlspecialchars($section, ENT_QUOTES, 'UTF-8') ?>"
                               readonly>
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="rs_new">New name <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control"
                               id="rs_new"
                               name="new_section_name"
                               required
                               maxlength="100"
                               autocomplete="off"
                               placeholder="e.g. ICT Alpha">
                        <div class="form-text mt-1" style="font-size:.72rem;color:var(--text-3)">
                            Letters, numbers, spaces, dashes, underscores, dots, parentheses, slashes. Max 100 characters.
                        </div>
                    </div>

                    <div class="teacher-note" style="background:var(--accent-soft);color:var(--accent);border-color:color-mix(in srgb,var(--accent) 25%,transparent)">
                        <i class="bi bi-shield-check"></i>
                        <span>
                            You are editing as <strong><?= htmlspecialchars(ucfirst($_SESSION['admin_role'] ?? 'admin')) ?></strong>.
                            This renames the section cluster and cascades to <code>teacher_sections</code>.
                        </span>
                    </div>

                    <div class="warning-note">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span id="rs_warning_text"></span>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm" style="color:#fff;font-weight:600">
                        <i class="bi bi-check-lg me-1"></i> Update Name
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const modalEl = document.getElementById('renameSectionModal');
    if (!modalEl) return;
    const newInput = document.getElementById('rs_new');
    const oldValue = document.getElementById('rs_current').value;
    const form     = document.getElementById('renameSectionForm');
    const clusterSelect = document.getElementById('rs_cluster');
    const warningText = document.getElementById('rs_warning_text');

    // FIX: Auto-open when arriving via "?open_rename=1" from the summary table pencil button.
    if (new URLSearchParams(location.search).get('open_rename') === '1') {
        new bootstrap.Modal(modalEl).show();
    }

    function updateWarning() {
        if (!clusterSelect || !warningText) return;
        const opt = clusterSelect.options[clusterSelect.selectedIndex];
        if (!opt) return;
        const count = opt.getAttribute('data-total');
        const grade = opt.getAttribute('data-grade');
        const sy = opt.getAttribute('data-sy');
        const prog = opt.getAttribute('data-program');

        let label = [];
        if (grade) label.push(grade);
        if (sy) label.push('SY ' + sy);
        if (prog) label.push(prog);

        warningText.innerHTML = `This will rename <strong>${count} record(s)</strong> in the <strong>${label.join(' · ')}</strong> cluster. Students in other grades or school years will not be affected.`;
    }

    if (clusterSelect) {
        clusterSelect.addEventListener('change', updateWarning);
    }

    modalEl.addEventListener('shown.bs.modal', function () {
        newInput.value = oldValue;
        newInput.focus();
        newInput.select();
        updateWarning();
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        newInput.value = '';
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Update Name';
        }
    });

    form.addEventListener('submit', function (e) {
        if (!clusterSelect) {
            e.preventDefault();
            alert('No cluster selected.');
            return;
        }

        const clusterData = JSON.parse(clusterSelect.value);
        document.getElementById('rs_grade').value   = clusterData.g || '';
        document.getElementById('rs_sy').value      = clusterData.s || '';
        document.getElementById('rs_program').value = clusterData.p || '';

        const v = newInput.value.trim();
        if (v === '') {
            e.preventDefault();
            newInput.focus();
            return;
        }
        if (v === oldValue) {
            e.preventDefault();
            alert('The new name is identical to the current name. Change it to something different.');
            newInput.focus();
            newInput.select();
            return;
        }

        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Updating…';
        }
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>