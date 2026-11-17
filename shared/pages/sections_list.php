<?php
// shared/pages/sections_list.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../lib/teacher_scope.php';
require_once __DIR__ . '/../lib/dir_view.php';
require_once __DIR__ . '/../lib/card_helper.php';
require_once __DIR__ . '/../print/render.php';

$__nav_section = 'students';

$__is_teacher = (($_SESSION['admin_role'] ?? '') === 'teacher');
$__teacher_id = $__is_teacher ? (int)($_SESSION['admin_id'] ?? 0) : 0;

/* ═══ EXPORT MODES ═══ */
$export_word  = isset($_GET['export_word']) && $_GET['export_word'] == 1;
$export_pdf   = isset($_GET['export_pdf'])  && $_GET['export_pdf']  == 1;
$export_print = isset($_GET['export_print'])&& $_GET['export_print']== 1;

/* ═══ FILTERS ═══ */
$school_year = trim($_GET['school_year'] ?? '');
$grade_level = trim($_GET['grade_level'] ?? '');
$program     = trim($_GET['program'] ?? '');
$section     = trim($_GET['section'] ?? '');

$show_student_list = !empty($section);

/* ═══ VIEW MODE ═══ */
$__view_sections = dir_view('sections');
$__view_roster   = dir_view('section_roster');

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

                $_SESSION['success'] = "Section renamed: \"{$old_name}\" → \"{$new_name}\" ({$affected} record" . ($affected === 1 ? '' : "s") . " updated in the selected cluster).";

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

/* ═══ Dropdowns ═══ */
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

// ─── FORMAL DOCUMENT EXPORT ─────────────────────────────────────
if (!function_exists('build_export_doc')) {
    function build_export_doc(
        bool   $show_student_list,
        array  $section_result,
        array  $section_clusters,
        array  $students_result,
        string $filter_text,
        string $sec_title,
        int    $sec_count,
        int    $sec_boys,
        int    $sec_girls,
        int    $stat_total_sections,
        int    $stat_total_students,
        int    $stat_boys,
        int    $stat_girls
    ): array {
        $ident   = school_identity();
        $title   = $show_student_list ? 'Section Student Roster' : 'Sections Summary Report';
        $subtitle = $show_student_list
            ? ($sec_title !== '' ? $sec_title : 'Section')
            : ($ident['school'] ?? '');

        $statsHtml = '<table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin:6pt 0 14pt;"><tr>';
        if ($show_student_list) {
            $ratio = $sec_girls > 0 ? round($sec_boys / $sec_girls, 2) . ':1' : '—';
            $statsHtml .= '<td width="25%" style="background:#f8fafc; border-left:3pt solid #1e3a8a; padding:6pt 10pt;"><div style="font-size:7.5pt; font-weight:bold; color:#64748b; text-transform:uppercase; letter-spacing:.4pt;">Total Students</div><div style="font-size:14pt; font-weight:bold; color:#0f172a; margin-top:2pt;">' . (int)$sec_count . '</div></td>';
            $statsHtml .= '<td width="25%" style="background:#f8fafc; border-left:3pt solid #1e3a8a; padding:6pt 10pt;"><div style="font-size:7.5pt; font-weight:bold; color:#64748b; text-transform:uppercase; letter-spacing:.4pt;">Male</div><div style="font-size:14pt; font-weight:bold; color:#0f172a; margin-top:2pt;">' . (int)$sec_boys . '</div></td>';
            $statsHtml .= '<td width="25%" style="background:#f8fafc; border-left:3pt solid #1e3a8a; padding:6pt 10pt;"><div style="font-size:7.5pt; font-weight:bold; color:#64748b; text-transform:uppercase; letter-spacing:.4pt;">Female</div><div style="font-size:14pt; font-weight:bold; color:#0f172a; margin-top:2pt;">' . (int)$sec_girls . '</div></td>';
            $statsHtml .= '<td width="25%" style="background:#f8fafc; border-left:3pt solid #1e3a8a; padding:6pt 10pt;"><div style="font-size:7.5pt; font-weight:bold; color:#64748b; text-transform:uppercase; letter-spacing:.4pt;">M / F Ratio</div><div style="font-size:14pt; font-weight:bold; color:#0f172a; margin-top:2pt;">' . htmlspecialchars($ratio) . '</div></td>';
        } else {
            $statsHtml .= '<td width="25%" style="background:#f8fafc; border-left:3pt solid #1e3a8a; padding:6pt 10pt;"><div style="font-size:7.5pt; font-weight:bold; color:#64748b; text-transform:uppercase; letter-spacing:.4pt;">Total Sections</div><div style="font-size:14pt; font-weight:bold; color:#0f172a; margin-top:2pt;">' . (int)$stat_total_sections . '</div></td>';
            $statsHtml .= '<td width="25%" style="background:#f8fafc; border-left:3pt solid #1e3a8a; padding:6pt 10pt;"><div style="font-size:7.5pt; font-weight:bold; color:#64748b; text-transform:uppercase; letter-spacing:.4pt;">Total Students</div><div style="font-size:14pt; font-weight:bold; color:#0f172a; margin-top:2pt;">' . (int)$stat_total_students . '</div></td>';
            $statsHtml .= '<td width="25%" style="background:#f8fafc; border-left:3pt solid #1e3a8a; padding:6pt 10pt;"><div style="font-size:7.5pt; font-weight:bold; color:#64748b; text-transform:uppercase; letter-spacing:.4pt;">Male</div><div style="font-size:14pt; font-weight:bold; color:#0f172a; margin-top:2pt;">' . (int)$stat_boys . '</div></td>';
            $statsHtml .= '<td width="25%" style="background:#f8fafc; border-left:3pt solid #1e3a8a; padding:6pt 10pt;"><div style="font-size:7.5pt; font-weight:bold; color:#64748b; text-transform:uppercase; letter-spacing:.4pt;">Female</div><div style="font-size:14pt; font-weight:bold; color:#0f172a; margin-top:2pt;">' . (int)$stat_girls . '</div></td>';
        }
        $statsHtml .= '</tr></table>';

        $metaHtml  = '<div class="meta-block">';
        $metaHtml .= '<strong>Filters:</strong> ' . htmlspecialchars($filter_text) . '<br>';
        $metaHtml .= '<strong>Note:</strong> Graduated and Pending students are excluded from this report.';
        $metaHtml .= '</div>';

        $bodyCss  = '<style>';
        $bodyCss .= 'table.grid thead { display: table-header-group; }';
        $bodyCss .= 'table.grid tr { page-break-inside: avoid; mso-row-break: avoid; }';
        $bodyCss .= 'table.grid td { word-break: break-word; }';
        $bodyCss .= '</style>';

        $bodyHtml = $bodyCss . $statsHtml . $metaHtml;

        if ($show_student_list && !empty($students_result)) {
            $bodyHtml .= '<div class="section-title">Student Roster</div>';
            $bodyHtml .= '<table class="grid"><thead><tr>';
            $bodyHtml .= '<th style="width:24pt">#</th>';
            $bodyHtml .= '<th style="width:78pt">Student ID</th>';
            $bodyHtml .= '<th>Name</th>';
            $bodyHtml .= '<th style="width:78pt">LRN</th>';
            $bodyHtml .= '<th style="width:80pt">Grade · SY</th>';
            $bodyHtml .= '<th style="width:44pt">Sex</th>';
            $bodyHtml .= '</tr></thead><tbody>';

            $i = 1;
            foreach ($students_result as $r) {
                $name = trim(($r['last_name'] ?? '') . ', ' . ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? ''));
                $cluster_label = trim(($r['grade_level'] ?? '—') . ' · ' . ($r['school_year'] ?? '—'));
                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td class="num">' . $i++ . '</td>';
                $bodyHtml .= '<td><strong>' . htmlspecialchars($r['student_id_number'] ?? '—') . '</strong></td>';
                $bodyHtml .= '<td>' . htmlspecialchars($name) . '</td>';
                $bodyHtml .= '<td>' . htmlspecialchars($r['lrn'] ?? '—') . '</td>';
                $bodyHtml .= '<td>' . htmlspecialchars($cluster_label) . '</td>';
                $bodyHtml .= '<td class="num">' . htmlspecialchars($r['sex'] ?? '—') . '</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</tbody></table>';

        } elseif (!$show_student_list && !empty($section_result)) {
            $bodyHtml .= '<div class="section-title">Sections Summary</div>';
            $bodyHtml .= '<table class="grid"><thead><tr>';
            $bodyHtml .= '<th>Section</th>';
            $bodyHtml .= '<th style="width:55pt">Grade</th>';
            $bodyHtml .= '<th style="width:42pt">Total</th>';
            $bodyHtml .= '<th style="width:42pt">Male</th>';
            $bodyHtml .= '<th style="width:46pt">Female</th>';
            $bodyHtml .= '<th style="width:140pt">Clusters (SY · Program)</th>';
            $bodyHtml .= '</tr></thead><tbody>';

            foreach ($section_result as $s) {
                $cluster_key = $s['section'] . '|' . $s['grade_level'];
                $clusters    = $section_clusters[$cluster_key] ?? [];
                $clusterBits = [];
                foreach ($clusters as $c) {
                    $label = trim(
                        ($c['school_year'] ?: '—') .
                        ($c['program'] !== '' ? ' · ' . $c['program'] : '')
                    );
                    $clusterBits[] = htmlspecialchars($label) . ' (' . (int)$c['total'] . ')';
                }

                $bodyHtml .= '<tr>';
                $bodyHtml .= '<td><strong>' . htmlspecialchars($s['section']) . '</strong></td>';
                $bodyHtml .= '<td>' . htmlspecialchars($s['grade_level'] ?: '—') . '</td>';
                $bodyHtml .= '<td class="num"><strong>' . (int)$s['total'] . '</strong></td>';
                $bodyHtml .= '<td class="num">' . (int)$s['boys'] . '</td>';
                $bodyHtml .= '<td class="num">' . (int)$s['girls'] . '</td>';
                $bodyHtml .= '<td style="font-size:8.5pt">' . ($clusterBits ? implode('<br>', $clusterBits) : '—') . '</td>';
                $bodyHtml .= '</tr>';
            }
            $bodyHtml .= '</tbody></table>';
        } else {
            $bodyHtml .= '<p style="text-align:center;color:#94a3b8;font-style:italic;padding:40pt 0;">No data available for the selected filters.</p>';
        }

        $signatories = [];
        $reg  = $ident['signatories']['registrar'] ?? [];
        $head = $ident['signatories']['principal'] ?? [];

        if (!empty($reg['name']) || !empty($reg['position'])) {
            $signatories[] = [
                'name'     => $reg['name']     ?? '',
                'position' => $reg['position'] ?? 'University Registrar',
                'label'    => 'Prepared by',
            ];
        }
        if (!empty($head['name']) || !empty($head['position'])) {
            $signatories[] = [
                'name'     => $head['name']     ?? '',
                'position' => $head['position'] ?? 'School President',
                'label'    => 'Approved by',
            ];
        }

        return [
            'title'       => $title,
            'subtitle'    => $subtitle,
            'paper'       => 'A4',
            'body'        => $bodyHtml,
            'signatories' => $signatories,
            'control_no'  => 'SEC-' . date('Ymd-His'),
            'copy_label'  => $show_student_list ? 'Registrar Copy' : 'Official Report',
        ];
    }
}

/* ═══ EXPORT HANDLERS ═══ */
if ($export_word || $export_pdf || $export_print) {
    while (ob_get_level()) ob_end_clean();

    $doc = build_export_doc(
        $show_student_list,
        $section_result ?? [],
        $section_clusters ?? [],
        $students_result ?? [],
        $filter_text,
        $sec_title,
        $sec_count,
        $sec_boys,
        $sec_girls,
        $stat_total_sections,
        $stat_total_students,
        $stat_boys,
        $stat_girls
    );

    $filename = 'USAT_Sections_' . ($show_student_list ? preg_replace('/[^A-Za-z0-9_-]/', '_', $sec_title) : 'Summary') . '_' . date('Y-m-d');

    if ($export_word) {
        output_document_word($doc, $filename . '.doc');
    } elseif ($export_pdf) {
        output_document_pdf($doc, $filename . '.pdf');
    } elseif ($export_print) {
        output_document_print($doc);
    }
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
                        <?php $wp_print = ['export_print'=>1,'school_year'=>$school_year,'grade_level'=>$grade_level,'program'=>$program,'section'=>$section]; ?>
                        <a class="dropdown-item" href="?<?= http_build_query(array_filter($wp_print)) ?>" target="_blank">
                            <i class="bi bi-printer text-secondary"></i>
                            Print Formal Document
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
                    <?= dir_view_toggle($__view_roster) ?>
                </div>
                <div class="card-body no-padding <?= dir_view_class($__view_roster) ?>" data-page-key="section_roster">
                    <div class="dir-table-wrap">
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

                    <div class="dir-cards-wrap">
                        <div class="student-cards-grid">
                            <?php foreach($students_result as $r):
                                $name = trim($r['last_name'].', '.$r['first_name'].' '.($r['middle_name']??''));
                                $initials = strtoupper(substr($r['first_name'] ?? '', 0, 1) . substr($r['last_name'] ?? '', 0, 1));
                                $sex_class = $r['sex'] === 'Male' ? 'male' : ($r['sex'] === 'Female' ? 'female' : 'muted');
                                $sex_icon  = $r['sex'] === 'Male' ? 'bi-gender-male' : ($r['sex'] === 'Female' ? 'bi-gender-female' : 'bi-dash');
                            ?>
                                <?= render_dir_card([
                                    'title'    => $name,
                                    'initials' => $initials,
                                    'meta'     => [
                                        'ID: '  . ($r['student_id_number'] ?? '—'),
                                        'LRN: ' . ($r['lrn'] ?? '—'),
                                    ],
                                    'badge'    => ['label' => $r['sex'] ?? '—', 'class' => $sex_class],
                                    'tags'     => [
                                        ['icon' => 'bi-mortarboard', 'label' => $r['grade_level'] ?? '—', 'class' => 'grade'],
                                        ['icon' => 'bi-calendar3',   'label' => 'SY ' . ($r['school_year'] ?? '—'), 'class' => 'grade'],
                                    ],
                                    'footer_left' => $r['ext_name'] ?? '',
                                    'action'      => [
                                        'href'  => URL_PREFIX . '/view_student.php?id=' . (int)$r['student_id'],
                                        'label' => 'Profile',
                                        'icon'  => 'bi-person',
                                    ],
                                ]) ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <div class="empty">
                        <div class="empty-icon"><i class="bi bi-search"></i></div>
                        <div class="empty-title">No students found</div>
                        <div class="empty-sub">No students match the current section filter.</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <?php if(!empty($section_result)): ?>

            <div class="stat-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-diagram-3-fill"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($stat_total_sections) ?></div>
                        <div class="stat-label">Total Sections</div>
                    </div>
                </div>
                <div class="stat-card tone-green">
                    <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                    <div>
                        <div class="stat-value"><?= number_format($stat_total_students) ?></div>
                        <div class="stat-label">Students</div>
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

            <div class="card">
                <div class="card-header">
                    <h3>
                        <i class="bi bi-diagram-3"></i> Sections Overview
                        <span class="cell-muted" style="font-weight:500;font-size:.85rem">(<?= number_format($stat_total_sections) ?>)</span>
                    </h3>
                    <?= dir_view_toggle($__view_sections) ?>
                </div>
                <div class="card-body no-padding <?= dir_view_class($__view_sections) ?>" data-page-key="sections">
                    <div class="dir-table-wrap">
                        <div class="table-scroll">
                            <table class="table-admin">
                                <thead>
                                    <tr>
                                        <th style="width:280px">Section</th>
                                        <th style="width:130px">Grade</th>
                                        <th style="width:80px;text-align:right">Total</th>
                                        <th style="width:80px;text-align:right">Male</th>
                                        <th style="width:80px;text-align:right">Female</th>
                                        <th>Clusters (SY · Program)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($section_result as $s):
                                        $cluster_key = $s['section'] . '|' . $s['grade_level'];
                                        $clusters    = $section_clusters[$cluster_key] ?? [];
                                        $open_url = URL_PREFIX . '/sections_list.php?' . http_build_query(array_filter([
                                            'section'     => $s['section'],
                                            'school_year' => $school_year,
                                            'grade_level' => $s['grade_level'],
                                            'program'     => $program,
                                        ]));
                                    ?>
                                    <tr class="section-row" onclick="window.location='<?= htmlspecialchars($open_url) ?>'">
                                        <td>
                                            <div class="section-cell">
                                                <div class="section-badge"><i class="bi bi-columns-gap"></i></div>
                                                <div>
                                                    <a class="section-name" href="<?= htmlspecialchars($open_url) ?>" onclick="event.stopPropagation()">
                                                        <?= htmlspecialchars($s['section']) ?>
                                                        <i class="bi bi-chevron-right row-chevron"></i>
                                                    </a>
                                                    <div class="section-total"><strong><?= (int)$s['total'] ?></strong> student<?= (int)$s['total'] === 1 ? '' : 's' ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="cell-muted"><?= htmlspecialchars($s['grade_level'] ?: '—') ?></td>
                                        <td class="cell-strong" style="text-align:right"><?= (int)$s['total'] ?></td>
                                        <td class="cell-muted" style="text-align:right"><?= (int)$s['boys'] ?></td>
                                        <td class="cell-muted" style="text-align:right"><?= (int)$s['girls'] ?></td>
                                        <td>
                                            <div class="cluster-chips">
                                                <?php foreach (array_slice($clusters, 0, 3) as $c):
                                                    $label = trim(($c['school_year'] ?: '—') . ($c['program'] !== '' ? ' · ' . $c['program'] : ''));
                                                    $chip_url = URL_PREFIX . '/sections_list.php?' . http_build_query(array_filter([
                                                        'section'     => $s['section'],
                                                        'school_year' => $c['school_year'],
                                                        'grade_level' => $c['grade_level'],
                                                        'program'     => $c['program'],
                                                    ]));
                                                ?>
                                                    <a href="<?= htmlspecialchars($chip_url) ?>" class="cluster-chip" onclick="event.stopPropagation()">
                                                        <span class="cluster-label"><?= htmlspecialchars($label) ?></span>
                                                        <span class="cluster-count"><?= (int)$c['total'] ?></span>
                                                    </a>
                                                <?php endforeach; ?>
                                                <?php if (count($clusters) > 3): ?>
                                                    <span class="cluster-more">+<?= count($clusters) - 3 ?> more</span>
                                                <?php endif; ?>
                                                <?php if (empty($clusters)): ?>
                                                    <span class="cell-muted" style="font-style:italic;font-size:.78rem">No clusters</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="dir-cards-wrap">
                        <div class="student-cards-grid">
                            <?php foreach($section_result as $s):
                                $cluster_key = $s['section'] . '|' . $s['grade_level'];
                                $clusters    = $section_clusters[$cluster_key] ?? [];
                                $open_url = URL_PREFIX . '/sections_list.php?' . http_build_query(array_filter([
                                    'section'     => $s['section'],
                                    'school_year' => $school_year,
                                    'grade_level' => $s['grade_level'],
                                    'program'     => $program,
                                ]));
                            ?>
                                <?= render_dir_card([
                                    'title'    => $s['section'],
                                    'initials' => strtoupper(substr($s['section'] ?? 'S', 0, 1)),
                                    'meta'     => [
                                        'Grade ' . ($s['grade_level'] ?: '—'),
                                        (int)$s['total'] . ' students',
                                    ],
                                    'badge'    => ['label' => 'M ' . (int)$s['boys'] . ' · F ' . (int)$s['girls'], 'class' => 'info'],
                                    'tags'     => array_map(function($c) {
                                        $label = trim(($c['school_year'] ?: '—') . ($c['program'] !== '' ? ' · ' . $c['program'] : ''));
                                        return ['icon' => 'bi-diagram-3', 'label' => $label . ' (' . (int)$c['total'] . ')', 'class' => 'grade'];
                                    }, array_slice($clusters, 0, 2)),
                                    'footer_left' => (count($clusters) > 2 ? '+' . (count($clusters) - 2) . ' more clusters' : ''),
                                    'action'      => [
                                        'href'  => $open_url,
                                        'label' => 'View',
                                        'icon'  => 'bi-arrow-right-short',
                                    ],
                                ]) ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <div class="empty">
                        <div class="empty-icon"><i class="bi bi-diagram-3"></i></div>
                        <div class="empty-title">No sections found</div>
                        <div class="empty-sub">No sections match the current filters. Try widening your search.</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($show_student_list && $can_edit_section): ?>
<div class="modal fade rename-modal" id="renameSectionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="rename_section" value="1">
                <input type="hidden" name="old_section_name" value="<?= htmlspecialchars($sec_title) ?>">
                <input type="hidden" name="target_grade" value="<?= htmlspecialchars($grade_level) ?>">
                <input type="hidden" name="target_sy" value="<?= htmlspecialchars($school_year) ?>">
                <input type="hidden" name="target_program" value="<?= htmlspecialchars($program) ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Update Section Name</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Current Name</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($sec_title) ?>" readonly>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="new_section_name">New Name</label>
                        <input type="text" id="new_section_name" name="new_section_name" class="form-control"
                               maxlength="100" required value="<?= htmlspecialchars($sec_title) ?>">
                    </div>

                    <div class="warning-note">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <div>This will update <strong><?= $sec_count ?> student record<?= $sec_count === 1 ? '' : 's' ?></strong> in this section for the selected cluster. Other sections with the same name in different grade/year/program clusters are unaffected.</div>
                    </div>

                    <div class="teacher-note">
                        <i class="bi bi-info-circle-fill"></i>
                        <div>Teacher assignments referencing this section will also be updated automatically.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-check-lg me-1"></i> Rename Section</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>