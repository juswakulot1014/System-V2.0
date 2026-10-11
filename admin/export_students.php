<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/audit.php';

// ====================== ROUTING / FILTERS ======================
$format = $_GET['format'] ?? '';          // '', 'csv', 'word'
$mode   = $_GET['mode']   ?? 'students';  // CSV mode: 'students' | 'grades' | 'full'

$student_id_filter = (int)($_GET['student_id'] ?? 0);

$selected_sy    = trim($_GET['school_year']    ?? '');
$grade_filter   = trim($_GET['grade_level']    ?? '');
$strand_filter  = trim($_GET['strand_filter']  ?? '');
$section_filter = trim($_GET['section_filter'] ?? '');
$status_filter  = trim($_GET['status_filter']  ?? '');

$where  = [];
$params = [];
$types  = "";

if ($student_id_filter > 0) {
    $where[]  = "s.student_id = ?";
    $params[] = $student_id_filter;
    $types   .= "i";
} else {
    if ($selected_sy !== '')    { $where[] = "e.school_year = ?";  $params[] = $selected_sy;    $types .= "s"; }
    if ($grade_filter !== '')   { $where[] = "e.grade_level = ?"; $params[] = $grade_filter;   $types .= "s"; }
    if ($strand_filter !== '')  { $where[] = "e.strand = ?";      $params[] = $strand_filter;  $types .= "s"; }
    if ($section_filter !== '') { $where[] = "e.section = ?";     $params[] = $section_filter; $types .= "s"; }
    if ($status_filter !== '')  { $where[] = "e.status = ?";      $params[] = $status_filter;  $types .= "s"; }
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// ====================== HELPERS ======================
function csvSafe($v) {
    $v = (string)$v;
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) $v = "'" . $v;
    return $v;
}

function calcGwa(array $grades) {
    $t = 0; $c = 0;
    foreach ($grades as $g) {
        if ($g['grade'] !== null && is_numeric($g['grade'])) { $t += (float)$g['grade']; $c++; }
    }
    return $c > 0 ? round($t / $c, 2) : null;
}

function normalizeGradeLevel($gl) {
    $g = strtolower(trim((string)$gl));
    if ($g === '') return null;
    if (preg_match('/\b11\b/', $g) || strpos($g, '11') !== false) return 'Grade 11';
    if (preg_match('/\b12\b/', $g) || strpos($g, '12') !== false) return 'Grade 12';
    return null;
}

function normalizeTerm($t) {
    $t = strtolower(trim((string)$t));
    if ($t === '') return null;
    if (strpos($t, '1st') !== false || strpos($t, 'first')  !== false) return '1st Term';
    if (strpos($t, '2nd') !== false || strpos($t, 'second') !== false) return '2nd Term';
    if (strpos($t, '3rd') !== false || strpos($t, 'third')  !== false) return '3rd Term';
    return null;
}

/**
 * Fetch all students (with optional filter) plus their enrollments,
 * education, and grades grouped by grade level + term.
 */
function fetchExportData(mysqli $conn, string $where_sql, array $params, string $types): array {
    $sql = "
        SELECT s.student_id, s.student_id_number, s.lrn,
               s.last_name, s.first_name, s.middle_name, s.nick_name, s.ext_name,
               s.sex, s.birth_date, s.age, s.civil_status, s.nationality, s.religion,
               s.height, s.weight, s.email, s.phone, s.special_skills,
               p.father_name, p.father_occupation, p.father_contact,
               p.mother_name, p.mother_maiden_name, p.mother_occupation, p.mother_contact,
               p.guardian_fullname, p.guardian_relation, p.guardian_contact,
               p.ave_family_income, p.is_4ps, p.household_id AS parent_household_id,
               a.purok_street, a.barangay, a.town_city, a.province,
               a.region, a.district, a.postal_code,
               e.enrollment_id, e.school_year, e.grade_level, e.track, e.strand, e.program,
               e.section, e.term, e.status, e.voucher_status, e.household_id,
               e.previous_school_name, e.previous_school_address,
               e.previous_track, e.previous_strand, e.previous_program,
               e.previous_year_completed
        FROM students_info s
        LEFT JOIN parents_info p ON s.student_id = p.student_id
        LEFT JOIN addresses   a ON s.student_id = a.student_id
        LEFT JOIN enrollment_form e ON s.student_id = e.student_id
        $where_sql
        ORDER BY s.last_name ASC, s.first_name ASC, e.school_year ASC, e.term ASC
    ";
    $stmt = $conn->prepare($sql);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $students = [];
    $ids = [];
    foreach ($rows as $r) {
        $sid = (int)$r['student_id'];
        if (!isset($students[$sid])) {
            $students[$sid] = [
                'info' => [
                    'student_id'        => $r['student_id'],
                    'student_id_number' => $r['student_id_number'],
                    'lrn'               => $r['lrn'],
                    'last_name'         => $r['last_name'],
                    'first_name'        => $r['first_name'],
                    'middle_name'       => $r['middle_name'],
                    'nick_name'         => $r['nick_name'],
                    'ext_name'          => $r['ext_name'],
                    'sex'               => $r['sex'],
                    'birth_date'        => $r['birth_date'],
                    'age'               => $r['age'],
                    'civil_status'      => $r['civil_status'],
                    'nationality'       => $r['nationality'],
                    'religion'          => $r['religion'],
                    'height'            => $r['height'],
                    'weight'            => $r['weight'],
                    'email'             => $r['email'],
                    'phone'             => $r['phone'],
                    'special_skills'    => $r['special_skills'],
                    'father_name'       => $r['father_name'],
                    'father_occupation' => $r['father_occupation'],
                    'father_contact'    => $r['father_contact'],
                    'mother_name'       => $r['mother_name'],
                    'mother_maiden_name'=> $r['mother_maiden_name'],
                    'mother_occupation' => $r['mother_occupation'],
                    'mother_contact'    => $r['mother_contact'],
                    'guardian_fullname' => $r['guardian_fullname'],
                    'guardian_relation' => $r['guardian_relation'],
                    'guardian_contact'  => $r['guardian_contact'],
                    'ave_family_income' => $r['ave_family_income'],
                    'is_4ps'            => $r['is_4ps'],
                    'household_id'      => $r['parent_household_id'],
                    'purok_street'      => $r['purok_street'],
                    'barangay'          => $r['barangay'],
                    'town_city'         => $r['town_city'],
                    'province'          => $r['province'],
                    'region'            => $r['region'],
                    'district'          => $r['district'],
                    'postal_code'       => $r['postal_code'],
                ],
                'enrollments' => [],
                'grades'      => [],
                'education'   => [],
            ];
            $ids[] = $sid;
        }
        if (!empty($r['enrollment_id'])) {
            $students[$sid]['enrollments'][(int)$r['enrollment_id']] = [
                'enrollment_id' => $r['enrollment_id'],
                'school_year'   => $r['school_year'],
                'grade_level'   => $r['grade_level'],
                'track'         => $r['track'],
                'strand'        => $r['strand'],
                'program'       => $r['program'],
                'section'       => $r['section'],
                'term'          => $r['term'],
                'status'        => $r['status'],
                'voucher_status'=> $r['voucher_status'],
                'household_id'  => $r['household_id'],
                'previous_school_name'    => $r['previous_school_name'],
                'previous_school_address' => $r['previous_school_address'],
                'previous_track'          => $r['previous_track'],
                'previous_strand'         => $r['previous_strand'],
                'previous_program'        => $r['previous_program'],
                'previous_year_completed' => $r['previous_year_completed'],
            ];
        }
    }

    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));

        // Grades
        $gsql = "
            SELECT sg.grade_id, sg.student_id, sg.enrollment_id,
                   sg.term, sg.grade_level, sg.school_year,
                   sg.subject_code, sg.subject_name, sg.grade, sg.remarks
            FROM student_grades sg
            WHERE sg.student_id IN ($ph)
            ORDER BY sg.student_id, sg.school_year ASC, sg.grade_level ASC,
                     sg.term ASC, sg.subject_name ASC
        ";
        $gstmt = $conn->prepare($gsql);
        $gstmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $gstmt->execute();
        $gres = $gstmt->get_result();
        while ($g = $gres->fetch_assoc()) {
            $students[(int)$g['student_id']]['grades'][] = $g;
        }
        $gstmt->close();

        // Educational history
        $esql = "
            SELECT edu_id, student_id, level, school_name, school_address, year_completed
            FROM educational_history
            WHERE student_id IN ($ph)
            ORDER BY student_id, FIELD(level, 'Elementary','JHS','Transferred')
        ";
        $estmt = $conn->prepare($esql);
        $estmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $estmt->execute();
        $eres = $estmt->get_result();
        while ($e = $eres->fetch_assoc()) {
            $students[(int)$e['student_id']]['education'][] = $e;
        }
        $estmt->close();
    }

    return $students;
}

/**
 * Build a structured grade map for a student:
 *   [ 'Grade 11' => [ '1st Term' => [subject rows], '2nd Term' => [...], '3rd Term' => [...] ],
 *     'Grade 12' => ... ]
 */
function buildGradeStructure(array $grades): array {
    $structure = [
        'Grade 11' => ['1st Term' => [], '2nd Term' => [], '3rd Term' => []],
        'Grade 12' => ['1st Term' => [], '2nd Term' => [], '3rd Term' => []],
    ];

    foreach ($grades as $g) {
        $gl = normalizeGradeLevel($g['grade_level'] ?? '');
        $tm = normalizeTerm($g['term'] ?? '');
        if ($gl === null || $tm === null) continue;

        $structure[$gl][$tm][] = [
            'code'        => $g['subject_code'],
            'name'        => $g['subject_name'],
            'grade'       => $g['grade'],
            'remarks'     => $g['remarks'],
            'school_year' => $g['school_year'],
        ];
    }

    // Sort subjects alphabetically within each term
    foreach ($structure as $gl => &$terms) {
        foreach ($terms as $tm => &$subjects) {
            usort($subjects, function($a, $b) {
                return strcasecmp($a['code'] ?: $a['name'], $b['code'] ?: $b['name']);
            });
        }
    }
    unset($terms, $subjects);

    return $structure;
}

function termGwa(array $subjects) {
    $t = 0; $c = 0;
    foreach ($subjects as $s) {
        if ($s['grade'] !== null && is_numeric($s['grade'])) { $t += (float)$s['grade']; $c++; }
    }
    return $c > 0 ? round($t / $c, 2) : null;
}

function gradeLevelGwa(array $terms) {
    $vals = [];
    foreach ($terms as $subjects) {
        $g = termGwa($subjects);
        if ($g !== null) $vals[] = $g;
    }
    return count($vals) > 0 ? round(array_sum($vals) / count($vals), 2) : null;
}

function fmtDate($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }
function nz($v) { return ($v === null || $v === '') ? '—' : $v; }

// ====================== FETCH ======================
$students = fetchExportData($conn, $where_sql, $params, $types);

// Pre-compute grade structure per student
foreach ($students as $sid => &$s) {
    $s['grade_structure'] = buildGradeStructure($s['grades']);
    $s['overall_gwa']     = calcGwa($s['grades']);
}
unset($s);

$single_name = '';
$single_id_number = '';
if ($student_id_filter > 0 && !empty($students)) {
    $first = reset($students);
    $single_name = trim(($first['info']['last_name'] ?? '') . ', ' . ($first['info']['first_name'] ?? '') . ' ' . ($first['info']['middle_name'] ?? ''));
    $single_id_number = $first['info']['student_id_number'] ?? '';
}

$filter_desc = [];
if ($student_id_filter > 0) {
    $filter_desc[] = "Single student: " . ($single_name !== '' ? $single_name : "ID $student_id_filter");
} else {
    if ($selected_sy !== '')    $filter_desc[] = "School Year: $selected_sy";
    if ($grade_filter !== '')   $filter_desc[] = "Grade: $grade_filter";
    if ($strand_filter !== '')  $filter_desc[] = "Strand: $strand_filter";
    if ($section_filter !== '') $filter_desc[] = "Section: $section_filter";
    if ($status_filter !== '')  $filter_desc[] = "Status: $status_filter";
}
$filter_text = $filter_desc ? implode(' · ', $filter_desc) : 'All students';

// ====================== CSV EXPORT ======================
if ($format === 'csv') {
    set_time_limit(600);
    while (ob_get_level()) ob_end_clean();

    $fname = ($student_id_filter > 0 ? 'student_' . $student_id_filter : 'students') . '_' . $mode . '_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    if ($mode === 'grades') {
        // Flat: one row per grade
        fputcsv($out, [
            'Student ID Number','LRN','Last Name','First Name','Middle Name','Sex',
            'School Year','Grade Level','Term',
            'Subject Code','Subject Name','Grade','Remarks'
        ]);
        foreach ($students as $sid => $s) {
            if (empty($s['grades'])) continue;
            foreach ($s['grades'] as $g) {
                fputcsv($out, [
                    csvSafe($s['info']['student_id_number']),
                    csvSafe($s['info']['lrn']),
                    csvSafe($s['info']['last_name']),
                    csvSafe($s['info']['first_name']),
                    csvSafe($s['info']['middle_name']),
                    csvSafe($s['info']['sex']),
                    csvSafe($g['school_year']),
                    csvSafe($g['grade_level']),
                    csvSafe($g['term']),
                    csvSafe($g['subject_code']),
                    csvSafe($g['subject_name']),
                    $g['grade'] !== null ? number_format((float)$g['grade'], 2, '.', '') : '',
                    csvSafe($g['remarks']),
                ]);
            }
        }
    } elseif ($mode === 'full') {
        // Full: one row per student with everything + grades as columns
        $header = [
            'Student ID Number','LRN','Last Name','First Name','Middle Name','Nickname','Extension',
            'Sex','Birth Date','Age','Civil Status','Nationality','Religion',
            'Height','Weight','Email','Phone','Special Skills',
            'Address',
            'Father Name','Father Occupation','Father Contact',
            'Mother (Maiden)','Mother Occupation','Mother Contact',
            'Guardian','Guardian Relation','Guardian Contact',
            'Family Income','4Ps','Household ID',
            'School Year','Grade Level','Term','Section','Track','Strand','Program',
            'Status','Voucher Status',
        ];

        // Grade columns for Grade 11 (3 terms) and Grade 12 (3 terms)
        foreach (['Grade 11', 'Grade 12'] as $gl) {
            foreach (['1st Term', '2nd Term', '3rd Term'] as $tm) {
                $header[] = "$gl - $tm";
                $header[] = "$gl - $tm GWA";
            }
        }
        $header[] = 'Overall GWA';

        fputcsv($out, $header);

        foreach ($students as $sid => $s) {
            $info = $s['info'];
            $address = trim(implode(', ', array_filter([
                $info['purok_street'], $info['barangay'], $info['town_city'],
                $info['province'], $info['region'], $info['postal_code']
            ])));

            $en = !empty($s['enrollments']) ? reset($s['enrollments']) : [];

            $base = [
                csvSafe($info['student_id_number']), csvSafe($info['lrn']),
                csvSafe($info['last_name']), csvSafe($info['first_name']), csvSafe($info['middle_name']),
                csvSafe($info['nick_name']), csvSafe($info['ext_name']),
                csvSafe($info['sex']), csvSafe($info['birth_date']), csvSafe($info['age']),
                csvSafe($info['civil_status']), csvSafe($info['nationality']), csvSafe($info['religion']),
                csvSafe($info['height']), csvSafe($info['weight']),
                csvSafe($info['email']), csvSafe($info['phone']), csvSafe($info['special_skills']),
                csvSafe($address),
                csvSafe($info['father_name']), csvSafe($info['father_occupation']), csvSafe($info['father_contact']),
                csvSafe($info['mother_maiden_name']), csvSafe($info['mother_occupation']), csvSafe($info['mother_contact']),
                csvSafe($info['guardian_fullname']), csvSafe($info['guardian_relation']), csvSafe($info['guardian_contact']),
                csvSafe($info['ave_family_income']), csvSafe($info['is_4ps']), csvSafe($info['household_id']),
                csvSafe($en['school_year'] ?? ''), csvSafe($en['grade_level'] ?? ''), csvSafe($en['term'] ?? ''),
                csvSafe($en['section'] ?? ''), csvSafe($en['track'] ?? ''), csvSafe($en['strand'] ?? ''), csvSafe($en['program'] ?? ''),
                csvSafe($en['status'] ?? ''), csvSafe($en['voucher_status'] ?? ''),
            ];

            // Grade columns
            foreach (['Grade 11', 'Grade 12'] as $gl) {
                foreach (['1st Term', '2nd Term', '3rd Term'] as $tm) {
                    $subs = $s['grade_structure'][$gl][$tm] ?? [];
                    if (empty($subs)) {
                        $base[] = '';
                        $base[] = '';
                        continue;
                    }
                    // Compose "CODE: grade | CODE: grade | ..."
                    $parts = [];
                    foreach ($subs as $sub) {
                        $code = $sub['code'] ?: $sub['name'];
                        $gv   = $sub['grade'] !== null ? number_format((float)$sub['grade'], 2, '.', '') : '—';
                        $parts[] = $code . ': ' . $gv;
                    }
                    $base[] = csvSafe(implode(' | ', $parts));
                    $g = termGwa($subs);
                    $base[] = $g !== null ? number_format($g, 2, '.', '') : '';
                }
            }
            $base[] = $s['overall_gwa'] !== null ? number_format($s['overall_gwa'], 2, '.', '') : '';

            fputcsv($out, $base);
        }
    } else {
        // Default 'students' mode: one row per enrollment
        fputcsv($out, [
            'Student ID Number','LRN','Last Name','First Name','Middle Name','Sex','Birth Date','Age',
            'Email','Phone',
            'Address',
            'Father Name','Father Contact','Mother (Maiden)','Mother Contact',
            'Guardian','Guardian Contact','Family Income','4Ps','Household ID',
            'School Year','Grade Level','Term','Section','Track','Strand','Program',
            'Status','Voucher Status',
            'Grade Summary','GWA'
        ]);

        foreach ($students as $sid => $s) {
            $info = $s['info'];
            $address = trim(implode(', ', array_filter([
                $info['purok_street'], $info['barangay'], $info['town_city'],
                $info['province'], $info['region'], $info['postal_code']
            ])));

            if (empty($s['enrollments'])) {
                fputcsv($out, [
                    csvSafe($info['student_id_number']), csvSafe($info['lrn']),
                    csvSafe($info['last_name']), csvSafe($info['first_name']), csvSafe($info['middle_name']),
                    csvSafe($info['sex']), csvSafe($info['birth_date']), csvSafe($info['age']),
                    csvSafe($info['email']), csvSafe($info['phone']),
                    csvSafe($address),
                    csvSafe($info['father_name']), csvSafe($info['father_contact']),
                    csvSafe($info['mother_maiden_name']), csvSafe($info['mother_contact']),
                    csvSafe($info['guardian_fullname']), csvSafe($info['guardian_contact']),
                    csvSafe($info['ave_family_income']), csvSafe($info['is_4ps']), csvSafe($info['household_id']),
                    '','','','','','','','','',
                    '', ''
                ]);
                continue;
            }

            foreach ($s['enrollments'] as $en) {
                $parts = [];
                foreach ($s['grades'] as $g) {
                    if ((int)$g['enrollment_id'] !== (int)$en['enrollment_id']) continue;
                    $code = $g['subject_code'] ?: $g['subject_name'];
                    $gv   = $g['grade'] !== null ? number_format((float)$g['grade'], 2, '.', '') : '—';
                    $parts[] = $code . ': ' . $gv;
                }
                $summary = implode(' | ', $parts);
                $gwa = calcGwa(array_filter($s['grades'], fn($g) => (int)$g['enrollment_id'] === (int)$en['enrollment_id']));

                fputcsv($out, [
                    csvSafe($info['student_id_number']), csvSafe($info['lrn']),
                    csvSafe($info['last_name']), csvSafe($info['first_name']), csvSafe($info['middle_name']),
                    csvSafe($info['sex']), csvSafe($info['birth_date']), csvSafe($info['age']),
                    csvSafe($info['email']), csvSafe($info['phone']),
                    csvSafe($address),
                    csvSafe($info['father_name']), csvSafe($info['father_contact']),
                    csvSafe($info['mother_maiden_name']), csvSafe($info['mother_contact']),
                    csvSafe($info['guardian_fullname']), csvSafe($info['guardian_contact']),
                    csvSafe($info['ave_family_income']), csvSafe($info['is_4ps']), csvSafe($info['household_id']),
                    csvSafe($en['school_year']), csvSafe($en['grade_level']), csvSafe($en['term']),
                    csvSafe($en['section']), csvSafe($en['track']), csvSafe($en['strand']),
                    csvSafe($en['program']), csvSafe($en['status']), csvSafe($en['voucher_status']),
                    csvSafe($summary),
                    $gwa !== null ? number_format($gwa, 2, '.', '') : '',
                ]);
            }
        }
    }
    fclose($out);

    audit_log($conn, 'export.csv', ['type'=>'batch','mode'=>$mode,'scope'=>$filter_text,'students'=>count($students)]);

    exit;
}

// ====================== WORD EXPORT ======================
if ($format === 'word') {
    set_time_limit(600);
    while (ob_get_level()) ob_end_clean();

    $wfname = $student_id_filter > 0
        ? 'student_' . $student_id_filter . '_' . date('Y-m-d_His') . '.doc'
        : 'students_full_export_' . date('Y-m-d_His') . '.doc';

    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $wfname . '"');

    echo "\xEF\xBB\xBF";
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Students Export</title><style>
        @page { size: A4; margin: 1.5cm; }
        body{font-family:Arial,sans-serif;color:#111;font-size:11px;line-height:1.5}
        h1{color:#1e3c72;border-bottom:3px double #1e3c72;padding-bottom:8px;font-size:22px;margin-bottom:6px}
        h2{color:#2b4c8c;margin-top:24px;border-bottom:2px solid #2b4c8c;padding-bottom:4px;font-size:15px;page-break-before:always}
        h3{color:#4f46e5;margin:14px 0 6px;font-size:12px;letter-spacing:0.5px}
        h4{color:#2b4c8c;margin:10px 0 4px;font-size:11.5px;background:#f0f4f8;padding:4px 8px;border-left:3px solid #2b4c8c}
        table{width:100%;border-collapse:collapse;margin:6px 0 10px}
        th,td{border:1px solid #cbd5e0;padding:5px 7px;text-align:left;font-size:10px;vertical-align:top}
        th{background:#eef2ff;font-weight:bold;color:#1e3a72;text-align:center}
        .meta{background:#f9f9f9;padding:8px 12px;border-left:4px solid #4f46e5;margin:10px 0;font-size:11px}
        .badge{padding:2px 8px;border-radius:10px;background:#eef2ff;color:#4338ca;font-size:9px;font-weight:bold;margin-right:6px}
        .gwa-badge{background:#fef3c7;color:#92400e}
        .term-header{background:#dbeafe;font-weight:bold;font-size:11px;padding:6px 10px;border:1px solid #93c5fd;color:#1e40af;margin-top:8px}
        .empty-term{padding:8px 12px;background:#f9fafb;color:#94a3b8;font-style:italic;font-size:10px;border:1px dashed #cbd5e0}
        .gwa-row{background:#fef3c7;font-weight:bold}
        .grade-num{text-align:center;font-weight:bold}
        .pass{color:#059669}.fail{color:#dc2626}
        .footer{margin-top:30px;padding-top:10px;border-top:1px dashed #cbd5e0;text-align:center;font-size:10px;color:#94a3b8}
    </style></head><body>';

    $title = $student_id_filter > 0 ? 'USAT College — Student Record' : 'USAT College — Student Masterlist';
    echo '<h1>' . $title . '</h1>';
    echo '<div class="meta">';
    echo '<strong>Generated:</strong> ' . date('F d, Y h:i A') . '<br>';
    echo '<strong>Scope:</strong> ' . htmlspecialchars($filter_text) . '<br>';
    echo '<strong>Total students:</strong> ' . count($students);
    echo '</div>';

    $idx = 0;
    foreach ($students as $sid => $s) {
        $idx++;
        $info = $s['info'];
        $fullName = trim(($info['last_name'] ?? '') . ', ' . ($info['first_name'] ?? '') . ' ' . ($info['middle_name'] ?? ''));

        if ($student_id_filter > 0) {
            echo '<h2>' . htmlspecialchars($fullName) . '</h2>';
        } else {
            echo '<h2>' . $idx . '. ' . htmlspecialchars($fullName) . '</h2>';
        }

        // Header badges
        echo '<p>';
        if ($s['overall_gwa'] !== null) {
            echo '<span class="badge gwa-badge">Overall GWA: ' . number_format($s['overall_gwa'], 2) . '</span>';
        }
        $g11 = gradeLevelGwa($s['grade_structure']['Grade 11']);
        $g12 = gradeLevelGwa($s['grade_structure']['Grade 12']);
        if ($g11 !== null) echo '<span class="badge">Grade 11 GWA: ' . number_format($g11, 2) . '</span>';
        if ($g12 !== null) echo '<span class="badge">Grade 12 GWA: ' . number_format($g12, 2) . '</span>';
        echo '</p>';

        // -------------------- PERSONAL INFORMATION --------------------
        echo '<h3>Personal Information</h3><table>';
        $rows = [
            'Student ID Number' => $info['student_id_number'],
            'LRN'               => $info['lrn'],
            'Last Name'         => $info['last_name'],
            'First Name'        => $info['first_name'],
            'Middle Name'       => $info['middle_name'],
            'Nickname'          => $info['nick_name'],
            'Extension'         => $info['ext_name'],
            'Sex'               => $info['sex'],
            'Birth Date'        => fmtDate($info['birth_date']),
            'Age'               => $info['age'],
            'Civil Status'      => $info['civil_status'],
            'Nationality'       => $info['nationality'],
            'Religion'          => $info['religion'],
            'Height'            => $info['height'] ? $info['height'] . ' cm' : null,
            'Weight'            => $info['weight'] ? $info['weight'] . ' kg' : null,
            'Email'             => $info['email'],
            'Phone'             => $info['phone'],
            'Special Skills'    => $info['special_skills'],
        ];
        foreach ($rows as $k => $v) echo '<tr><th style="width:24%">' . htmlspecialchars($k) . '</th><td>' . htmlspecialchars(nz($v)) . '</td></tr>';
        echo '</table>';

        // -------------------- PARENTS & GUARDIAN --------------------
        echo '<h3>Parents & Guardian</h3><table>';
        $prows = [
            'Father'            => $info['father_name'],
            'Father Occupation' => $info['father_occupation'],
            'Father Contact'    => $info['father_contact'],
            'Mother (Maiden)'   => $info['mother_maiden_name'],
            'Mother Occupation' => $info['mother_occupation'],
            'Mother Contact'    => $info['mother_contact'],
            'Guardian'          => trim(($info['guardian_fullname'] ?? '') . ' (' . ($info['guardian_relation'] ?? '') . ')', ' ()'),
            'Guardian Contact'  => $info['guardian_contact'],
            'Family Income'     => $info['ave_family_income'] ? 'PHP ' . number_format((float)$info['ave_family_income'], 2) : null,
            '4Ps'               => $info['is_4ps'] ? 'Yes' : 'No',
            'Household ID'      => $info['household_id'],
        ];
        foreach ($prows as $k => $v) echo '<tr><th style="width:24%">' . htmlspecialchars($k) . '</th><td>' . htmlspecialchars(nz($v)) . '</td></tr>';
        echo '</table>';

        // -------------------- ADDRESS --------------------
        $addrParts = array_filter([$info['purok_street'], $info['barangay'], $info['town_city'], $info['province'], $info['region'], $info['district'], $info['postal_code']]);
        if ($addrParts) {
            echo '<h3>Address</h3><table><tr><th style="width:24%">Full Address</th><td>' . htmlspecialchars(implode(', ', $addrParts)) . '</td></tr></table>';
        }

        // -------------------- EDUCATIONAL HISTORY --------------------
        if (!empty($s['education'])) {
            echo '<h3>Educational History</h3><table>';
            echo '<tr><th>Level</th><th>School Name</th><th>School Address</th><th>Year Completed</th></tr>';
            foreach ($s['education'] as $e) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars(nz($e['level'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($e['school_name'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($e['school_address'])) . '</td>';
                echo '<td style="text-align:center">' . htmlspecialchars(nz($e['year_completed'])) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }

        // -------------------- ENROLLMENT --------------------
        if (!empty($s['enrollments'])) {
            echo '<h3>Enrollment Details</h3><table>';
            echo '<tr><th>School Year</th><th>Grade</th><th>Term</th><th>Section</th><th>Track</th><th>Strand</th><th>Program</th><th>Status</th><th>Voucher</th></tr>';
            foreach ($s['enrollments'] as $en) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars(nz($en['school_year'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['grade_level'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['term'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['section'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['track'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['strand'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['program'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['status'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['voucher_status'])) . '</td>';
                echo '</tr>';
            }
            echo '</table>';

            // Transferee info (from any enrollment that has it)
            $prevInfo = null;
            foreach ($s['enrollments'] as $en) {
                if (!empty($en['previous_school_name'])) { $prevInfo = $en; break; }
            }
            if ($prevInfo) {
                echo '<h4>Transferee — Previous School</h4><table>';
                echo '<tr><th style="width:24%">Previous School</th><td>' . htmlspecialchars(nz($prevInfo['previous_school_name'])) . '</td></tr>';
                echo '<tr><th>School Address</th><td>' . htmlspecialchars(nz($prevInfo['previous_school_address'])) . '</td></tr>';
                echo '<tr><th>Previous Track</th><td>' . htmlspecialchars(nz($prevInfo['previous_track'])) . '</td></tr>';
                echo '<tr><th>Previous Strand</th><td>' . htmlspecialchars(nz($prevInfo['previous_strand'])) . '</td></tr>';
                echo '<tr><th>Previous Program</th><td>' . htmlspecialchars(nz($prevInfo['previous_program'])) . '</td></tr>';
                echo '<tr><th>Year Completed</th><td>' . htmlspecialchars(nz($prevInfo['previous_year_completed'])) . '</td></tr>';
                echo '</table>';
            }
        }

        // -------------------- ACADEMIC RECORD --------------------
        echo '<h3>Academic Record — Grade 11 &amp; Grade 12</h3>';

        foreach (['Grade 11', 'Grade 12'] as $gl) {
            $terms = $s['grade_structure'][$gl];
            $glGwa = gradeLevelGwa($terms);

            echo '<div class="term-header">' . htmlspecialchars(strtoupper($gl));
            if ($glGwa !== null) echo ' &nbsp;·&nbsp; General Average: ' . number_format($glGwa, 2);
            echo '</div>';

            foreach (['1st Term', '2nd Term', '3rd Term'] as $tm) {
                $subs = $terms[$tm] ?? [];
                $tmGwa = termGwa($subs);

                echo '<h4 style="margin-top:8px">' . htmlspecialchars($tm);
                if ($tmGwa !== null) echo ' &nbsp;·&nbsp; GWA: ' . number_format($tmGwa, 2);
                echo '</h4>';

                if (empty($subs)) {
                    echo '<div class="empty-term">No grades recorded for this term.</div>';
                    continue;
                }

                echo '<table>';
                echo '<tr><th style="width:18%">Subject Code</th><th style="width:50%">Subject Title</th><th style="width:14%">Final Grade</th><th style="width:18%">Remarks</th></tr>';
                foreach ($subs as $sub) {
                    $gv = $sub['grade'] !== null ? number_format((float)$sub['grade'], 2) : '—';
                    $remark = !empty($sub['remarks']) ? $sub['remarks'] : (($sub['grade'] !== null && $sub['grade'] >= 75) ? 'Passed' : 'Failed');
                    $cls = ($sub['grade'] !== null && $sub['grade'] >= 75) ? 'pass' : 'fail';
                    echo '<tr>';
                    echo '<td style="text-align:center">' . htmlspecialchars(nz($sub['code'])) . '</td>';
                    echo '<td>' . htmlspecialchars(nz($sub['name'])) . '</td>';
                    echo '<td class="grade-num ' . $cls . '">' . $gv . '</td>';
                    echo '<td style="text-align:center">' . htmlspecialchars($remark) . '</td>';
                    echo '</tr>';
                }
                // Term GWA row
                echo '<tr class="gwa-row">';
                echo '<td colspan="2" style="text-align:right">General Average for ' . htmlspecialchars($tm) . ':</td>';
                echo '<td class="grade-num">' . ($tmGwa !== null ? number_format($tmGwa, 2) : '—') . '</td>';
                echo '<td></td>';
                echo '</tr>';
                echo '</table>';
            }
        }

        // Overall GWA
        if ($s['overall_gwa'] !== null) {
            echo '<table style="margin-top:14px">';
            echo '<tr class="gwa-row">';
            echo '<td style="width:70%"><strong>OVERALL GENERAL WEIGHTED AVERAGE (Grade 11 – Grade 12):</strong></td>';
            echo '<td class="grade-num" style="font-size:13px">' . number_format($s['overall_gwa'], 2) . '</td>';
            echo '</tr>';
            echo '</table>';
        }
    }

    echo '<p class="footer"><em>End of Report — ' . count($students) . ' student(s)</em></p>';
    audit_log($conn, 'export.word', ['type'=>'batch','scope'=>$filter_text,'students'=>count($students)]);
    echo '</body></html>';
    exit;
}

// ====================== DROPDOWN DATA ======================
$sy_list      = $conn->query("SELECT DISTINCT school_year FROM enrollment_form WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC")->fetch_all(MYSQLI_ASSOC) ?: [];
$grade_list   = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form WHERE grade_level IS NOT NULL AND grade_level != '' ORDER BY grade_level ASC")->fetch_all(MYSQLI_ASSOC) ?: [];
$strand_list  = $conn->query("SELECT DISTINCT strand FROM enrollment_form WHERE strand IS NOT NULL AND strand != '' ORDER BY strand ASC")->fetch_all(MYSQLI_ASSOC) ?: [];
$section_list = $conn->query("SELECT DISTINCT section FROM enrollment_form WHERE section IS NOT NULL AND section != '' ORDER BY section ASC")->fetch_all(MYSQLI_ASSOC) ?: [];

// Preview counts per student (how many have grades, etc.)
$preview_total = count($students);
$preview_with_grades = 0;
foreach ($students as $s) { if (!empty($s['grades'])) $preview_with_grades++; }

$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $student_id_filter > 0 ? 'Export Student' : 'Export Students' ?> • USAT Admin</title>

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
           Design tokens (matched to the rest of the app)
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
            --blue-soft: #dbeafe;

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
            --blue-soft: #1e3a5f;

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
        .breadcrumb-nav{
            display:flex;align-items:center;gap:.4rem;
            font-size:.78rem;color:var(--text-2);
            margin-bottom:.25rem;
        }
        .breadcrumb-nav a{color:var(--accent);text-decoration:none;font-weight:500}
        .breadcrumb-nav a:hover{text-decoration:underline}

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
           Meta badge
           ============================================================ */
        .meta-badge{
            font-size:.72rem;font-weight:600;
            padding:.25rem .7rem;border-radius:999px;
            background:var(--accent-soft);color:var(--accent);
            border:1px solid color-mix(in srgb, var(--accent) 22%, transparent);
        }
        .meta-badge.ok{
            background:var(--green-soft);color:var(--green);
            border-color:color-mix(in srgb, var(--green) 22%, transparent);
        }

        /* ============================================================
           Buttons
           ============================================================ */
        .btn{font-weight:500;border-radius:9px;font-size:.82rem;transition:all .15s}
        .btn-primary{background:var(--accent);border-color:var(--accent)}
        .btn-primary:hover{background:var(--accent-2);border-color:var(--accent-2)}
        .btn-outline-secondary{color:var(--text-2);border-color:var(--border)}
        .btn-outline-secondary:hover{background:var(--surface-2);color:var(--text);border-color:var(--border-strong)}
        .theme-btn{
            width:38px;height:38px;border-radius:10px;
            border:1px solid var(--border);background:var(--surface);
            color:var(--text-2);cursor:pointer;
            display:flex;align-items:center;justify-content:center;
            transition:all .15s;
        }
        .theme-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}

        /* ============================================================
           Single student banner
           ============================================================ */
        .single-banner{
            background:linear-gradient(135deg,var(--accent),var(--accent-2));
            color:#fff;padding:1.35rem 1.5rem;
            border-radius:var(--radius-lg);
            margin-bottom:1.25rem;
            display:flex;align-items:center;gap:1rem;
            box-shadow:0 12px 32px -12px rgba(79,70,229,.6);
            position:relative;overflow:hidden;
        }
        .single-banner::after{
            content:'';position:absolute;right:-40px;top:-40px;
            width:180px;height:180px;border-radius:50%;
            background:rgba(255,255,255,.08);
        }
        .single-banner .avatar{
            width:56px;height:56px;border-radius:50%;
            background:rgba(255,255,255,.2);
            display:flex;align-items:center;justify-content:center;
            font-size:1.35rem;font-weight:800;
            flex-shrink:0;
            box-shadow:0 0 0 3px rgba(255,255,255,.25);
            backdrop-filter:blur(4px);
            z-index:1;
        }
        .single-banner .info{flex:1;min-width:0;z-index:1}
        .single-banner h4{
            font-size:1.1rem;font-weight:700;
            margin:0 0 .25rem;
            letter-spacing:-.01em;
        }
        .single-banner .id-row{
            font-size:.82rem;opacity:.92;
            display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;
        }
        .single-banner .id-row strong{font-weight:700;opacity:1}
        .single-banner .btn-light{
            background:rgba(255,255,255,.95);color:var(--accent);
            border:none;font-weight:600;
            box-shadow:0 4px 12px rgba(0,0,0,.15);
            z-index:1;
        }
        .single-banner .btn-light:hover{background:#fff;transform:translateY(-1px)}

        /* ============================================================
           Filter bar
           ============================================================ */
        .filter-bar{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
            gap:.75rem;align-items:end;
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
        .filter-bar .actions{
            display:flex;gap:.5rem;
            grid-column:1 / -1;
            justify-content:flex-end;
            margin-top:.25rem;
        }

        /* ============================================================
           Export cards
           ============================================================ */
        .export-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(340px,1fr));
            gap:1rem;
        }
        .export-card{
            display:flex;gap:1.1rem;
            padding:1.35rem 1.4rem;
            border:1.5px solid var(--border);
            border-radius:var(--radius-lg);
            background:var(--surface);
            transition:transform .18s, box-shadow .18s, border-color .18s;
            align-items:flex-start;height:100%;
            text-decoration:none;color:inherit;cursor:pointer;
            position:relative;overflow:hidden;
        }
        .export-card:hover{
            border-color:var(--accent);
            box-shadow:var(--shadow-lg);
            transform:translateY(-3px);
            color:inherit;
        }
        .export-card .icon{
            width:56px;height:56px;border-radius:14px;
            display:flex;align-items:center;justify-content:center;
            font-size:1.5rem;flex-shrink:0;
            transition:transform .2s;
        }
        .export-card:hover .icon{transform:scale(1.05)}
        .export-card .body{min-width:0;flex:1}
        .export-card .title{
            font-size:.95rem;font-weight:700;
            color:var(--text);margin:0 0 .35rem;
            letter-spacing:-.01em;
        }
        .export-card .desc{
            font-size:.82rem;color:var(--text-2);
            line-height:1.55;margin:0;
        }
        .export-card .tags{
            display:flex;gap:.35rem;margin-top:.7rem;flex-wrap:wrap;
        }
        .export-card .tag{
            font-size:.68rem;font-weight:600;
            padding:.15rem .55rem;border-radius:999px;
            background:var(--surface-2);color:var(--text-2);
            border:1px solid var(--border);
        }
        .export-card .arrow{
            position:absolute;top:1.15rem;right:1.15rem;
            color:var(--text-3);
            opacity:0;transform:translateX(-6px);
            transition:opacity .2s, transform .2s;
            font-size:.9rem;
        }
        .export-card:hover .arrow{
            opacity:1;transform:translateX(0);
            color:var(--accent);
        }

        /* Export icon tones */
        .icon-word  {background:linear-gradient(135deg,var(--blue-soft),#bfdbfe);color:var(--blue)}
        .icon-csv   {background:linear-gradient(135deg,var(--green-soft),#a7f3d0);color:var(--green)}
        .icon-grades{background:linear-gradient(135deg,var(--amber-soft),#fde68a);color:var(--amber)}
        .icon-full  {background:linear-gradient(135deg,var(--purple-soft),#ddd6fe);color:var(--purple)}
        [data-bs-theme="dark"] .icon-word  {background:linear-gradient(135deg,#1e3a5f,#1e40af);color:#bfdbfe}
        [data-bs-theme="dark"] .icon-csv   {background:linear-gradient(135deg,#064e3b,#047857);color:#a7f3d0}
        [data-bs-theme="dark"] .icon-grades{background:linear-gradient(135deg,#78350f,#92400e);color:#fde68a}
        [data-bs-theme="dark"] .icon-full  {background:linear-gradient(135deg,#4c1d95,#5b21b6);color:#ddd6fe}

        /* ============================================================
           Preview table
           ============================================================ */
        .table-scroll{overflow-x:auto}
        .table-admin{width:100%;border-collapse:separate;border-spacing:0}
        .table-admin thead th{
            background:var(--surface-2);
            font-size:.66rem;font-weight:700;color:var(--text-2);
            text-transform:uppercase;letter-spacing:.6px;
            padding:.7rem 1rem;text-align:left;
            border-bottom:1px solid var(--border);
            white-space:nowrap;position:sticky;top:0;z-index:1;
        }
        .table-admin tbody td{
            padding:.7rem 1rem;border-bottom:1px solid var(--border);
            font-size:.83rem;vertical-align:middle;
            transition:background .12s;
        }
        .table-admin tbody tr:hover td{background:color-mix(in srgb, var(--accent) 4%, transparent)}
        .table-admin tbody tr:last-child td{border-bottom:none}
        .cell-id{
            font-weight:600;font-size:.82rem;color:var(--text);
            font-variant-numeric:tabular-nums;
        }
        .cell-name{font-weight:600;color:var(--text)}
        .cell-lrn{
            font-size:.72rem;color:var(--text-2);
            font-variant-numeric:tabular-nums;
        }
        .cell-accent{color:var(--accent);font-weight:600}
        .cell-center{text-align:center}
        .cell-num{
            font-variant-numeric:tabular-nums;font-weight:700;
            color:var(--accent);font-size:.88rem;
        }

        /* ============================================================
           Pills
           ============================================================ */
        .pill{
            display:inline-flex;align-items:center;gap:.35rem;
            padding:.22rem .65rem;border-radius:999px;
            font-size:.7rem;font-weight:600;
            border:1px solid transparent;
            white-space:nowrap;
        }
        .pill i{font-size:.75rem}
        .pill.ok   {background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb, var(--green) 22%, transparent)}
        .pill.warn {background:var(--amber-soft);color:var(--amber);border-color:color-mix(in srgb, var(--amber) 22%, transparent)}
        .pill.info {background:var(--blue-soft);color:var(--blue);border-color:color-mix(in srgb, var(--blue) 22%, transparent)}
        .pill.muted{background:var(--surface-2);color:var(--text-2);border-color:var(--border)}

        .gwa-sub{
            font-size:.68rem;color:var(--text-2);
            margin-top:.15rem;
            font-variant-numeric:tabular-nums;
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
           Table footer note
           ============================================================ */
        .table-note{
            text-align:center;
            padding:.85rem 1rem;
            font-size:.78rem;color:var(--text-2);
            border-top:1px solid var(--border);
            background:var(--surface-2);
        }
        .table-note i{color:var(--accent);margin-right:.3rem}

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
            .main-content{padding:1rem}
            .filter-bar{grid-template-columns:1fr}
            .filter-bar .actions{grid-column:span 1;justify-content:stretch}
            .filter-bar .actions .btn{flex:1}
            .export-grid{grid-template-columns:1fr}
            .single-banner{flex-direction:column;text-align:center;padding:1.25rem 1rem}
            .single-banner .info{text-align:center}
            .single-banner .id-row{justify-content:center}
        }
    </style>
</head>
<body>

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
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="student_profile.php" class="active"><i class="bi bi-people-fill"></i> Students</a>
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
        <a href="reports.php"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php" class="btn btn-outline-danger btn-sm w-100">
            <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
    </div>
</aside>

<div class="main-content">

    <!-- ================= Topbar ================= -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <div class="breadcrumb-nav">
                    <a href="student_profile.php">Students</a>
                    <i class="bi bi-chevron-right" style="font-size:.7rem;opacity:.6"></i>
                    <?php if ($student_id_filter > 0): ?>
                        <a href="view_student.php?id=<?= $student_id_filter ?>"><?= htmlspecialchars($single_name ?: 'Student') ?></a>
                        <i class="bi bi-chevron-right" style="font-size:.7rem;opacity:.6"></i>
                        <span>Export</span>
                    <?php else: ?>
                        <span>Export</span>
                    <?php endif; ?>
                </div>
                <h2><?= $student_id_filter > 0 ? 'Export Student' : 'Export Students' ?></h2>
                <p class="sub"><?= htmlspecialchars($filter_text) ?></p>
            </div>
        </div>
        <button class="theme-btn" id="themeToggle" title="Toggle theme">
            <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
        </button>
    </div>

    <!-- ================= Single student banner ================= -->
    <?php if ($student_id_filter > 0 && $single_name): ?>
        <?php
        // Safe initials extraction
        $nameParts = explode(',', $single_name);
        $lastInitial  = trim(substr($nameParts[0] ?? 'S', 0, 1));
        $firstInitial = trim(substr($nameParts[1] ?? 'S', 0, 1));
        $initials = strtoupper($firstInitial . $lastInitial);
        ?>
        <div class="single-banner">
            <div class="avatar"><?= htmlspecialchars($initials) ?></div>
            <div class="info">
                <h4><?= htmlspecialchars($single_name) ?></h4>
                <div class="id-row">
                    <i class="bi bi-person-badge"></i>
                    Student ID: <strong><?= htmlspecialchars($single_id_number ?: '—') ?></strong>
                </div>
            </div>
            <a href="view_student.php?id=<?= $student_id_filter ?>" class="btn btn-light btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Profile
            </a>
        </div>
    <?php endif; ?>

    <!-- ================= Filters ================= -->
    <?php if ($student_id_filter <= 0): ?>
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-funnel"></i> Filters</h3>
            <span class="meta-badge ok">
                <i class="bi bi-check2-circle me-1"></i>
                <?= $preview_total ?> student<?= $preview_total === 1 ? '' : 's' ?> matched
            </span>
        </div>
        <div class="card-body">
            <form method="GET" id="filterForm" class="filter-bar">
                <div class="field">
                    <label for="f_sy">School Year</label>
                    <select id="f_sy" name="school_year">
                        <option value="">All Years</option>
                        <?php foreach ($sy_list as $sy): ?>
                            <option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $selected_sy === $sy['school_year'] ? 'selected' : '' ?>><?= htmlspecialchars($sy['school_year']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_gl">Grade Level</label>
                    <select id="f_gl" name="grade_level">
                        <option value="">All</option>
                        <?php foreach ($grade_list as $g): ?>
                            <option value="<?= htmlspecialchars($g['grade_level']) ?>" <?= $grade_filter === $g['grade_level'] ? 'selected' : '' ?>><?= htmlspecialchars($g['grade_level']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_st">Strand</label>
                    <select id="f_st" name="strand_filter">
                        <option value="">All</option>
                        <?php foreach ($strand_list as $s): ?>
                            <option value="<?= htmlspecialchars($s['strand']) ?>" <?= $strand_filter === $s['strand'] ? 'selected' : '' ?>><?= htmlspecialchars($s['strand']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_se">Section</label>
                    <select id="f_se" name="section_filter">
                        <option value="">All</option>
                        <?php foreach ($section_list as $s): ?>
                            <option value="<?= htmlspecialchars($s['section']) ?>" <?= $section_filter === $s['section'] ? 'selected' : '' ?>><?= htmlspecialchars($s['section']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_status">Status</label>
                    <select id="f_status" name="status_filter">
                        <option value="">All</option>
                        <?php foreach (['Active','Transferred','Stopped','Dropped','Graduated'] as $st): ?>
                            <option value="<?= $st ?>" <?= $status_filter === $st ? 'selected' : '' ?>><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2 me-1"></i> Apply
                    </button>
                    <a href="export_students.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================= Export formats ================= -->
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-download"></i> Export Formats</h3>
            <?php if ($student_id_filter <= 0): ?>
                <span class="meta-badge">
                    <i class="bi bi-journal-check me-1"></i>
                    <?= $preview_with_grades ?> / <?= $preview_total ?> with grades
                </span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="export-grid">
                <a href="?<?= http_build_query(array_filter(array_merge($_GET, ['format' => 'word']))) ?>" class="export-card">
                    <div class="icon icon-word"><i class="bi bi-file-earmark-word-fill"></i></div>
                    <div class="body">
                        <h5 class="title">Full Student Report — Word (.doc)</h5>
                        <p class="desc">Complete record per student: personal info, parents, address, educational history, enrollment, and grades for Grade 11 and Grade 12 (all three terms). Best for printing and filing.</p>
                        <div class="tags">
                            <span class="tag">Personal</span>
                            <span class="tag">Parents</span>
                            <span class="tag">Grade 11 &amp; 12</span>
                            <span class="tag">GWA</span>
                        </div>
                    </div>
                    <i class="bi bi-arrow-right arrow"></i>
                </a>

                <a href="?<?= http_build_query(array_filter(array_merge($_GET, ['format' => 'csv', 'mode' => 'full']))) ?>" class="export-card">
                    <div class="icon icon-full"><i class="bi bi-table"></i></div>
                    <div class="body">
                        <h5 class="title">Full Data — CSV (one row per student)</h5>
                        <p class="desc">Every field plus grade columns for Grade 11 (3 terms) and Grade 12 (3 terms), each with its own GWA. Includes overall GWA. Great for spreadsheets and analysis.</p>
                        <div class="tags">
                            <span class="tag">All fields</span>
                            <span class="tag">Grade columns</span>
                            <span class="tag">Overall GWA</span>
                        </div>
                    </div>
                    <i class="bi bi-arrow-right arrow"></i>
                </a>

                <a href="?<?= http_build_query(array_filter(array_merge($_GET, ['format' => 'csv', 'mode' => 'students']))) ?>" class="export-card">
                    <div class="icon icon-csv"><i class="bi bi-file-earmark-spreadsheet-fill"></i></div>
                    <div class="body">
                        <h5 class="title">Students &amp; Enrollment — CSV</h5>
                        <p class="desc">One row per enrollment. Personal info, parents, address, enrollment details, and a concatenated grade summary with GWA per enrollment.</p>
                        <div class="tags">
                            <span class="tag">Per enrollment</span>
                            <span class="tag">Grade summary</span>
                        </div>
                    </div>
                    <i class="bi bi-arrow-right arrow"></i>
                </a>

                <a href="?<?= http_build_query(array_filter(array_merge($_GET, ['format' => 'csv', 'mode' => 'grades']))) ?>" class="export-card">
                    <div class="icon icon-grades"><i class="bi bi-list-columns-reverse"></i></div>
                    <div class="body">
                        <h5 class="title">Grades Only — CSV (flat)</h5>
                        <p class="desc">One row per grade. School Year, Grade Level, Term, Subject Code, Subject Name, Grade, Remarks. Ideal for pivot tables and grade analysis.</p>
                        <div class="tags">
                            <span class="tag">Flat format</span>
                            <span class="tag">Per grade</span>
                        </div>
                    </div>
                    <i class="bi bi-arrow-right arrow"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- ================= Preview ================= -->
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-eye"></i> Preview</h3>
            <?php if ($student_id_filter <= 0): ?>
                <span class="meta-badge">
                    <i class="bi bi-list-ul me-1"></i>
                    Showing first <?= min(10, count($students)) ?> of <?= count($students) ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="card-body no-padding">
            <?php if (empty($students)): ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                    <div class="empty-title">No students match the current filters</div>
                    <div class="empty-sub">Try adjusting your filter selections or reset.</div>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Grade Level</th>
                                <th>Section</th>
                                <th class="cell-center">Grade 11</th>
                                <th class="cell-center">Grade 12</th>
                                <th class="cell-center">Overall GWA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $shown = 0; foreach ($students as $sid => $s):
                                if ($shown++ >= 10) break;
                                $info = $s['info'];
                                $name = trim(($info['last_name'] ?? '') . ', ' . ($info['first_name'] ?? '') . ' ' . ($info['middle_name'] ?? ''));

                                // Determine current grade level from latest enrollment
                                $latestEn = null;
                                if (!empty($s['enrollments'])) {
                                    $latestEn = end($s['enrollments']);
                                }

                                $g11Count = 0; $g12Count = 0;
                                foreach ($s['grades'] as $g) {
                                    $gl = normalizeGradeLevel($g['grade_level'] ?? '');
                                    if ($gl === 'Grade 11') $g11Count++;
                                    if ($gl === 'Grade 12') $g12Count++;
                                }
                                $g11Gwa = gradeLevelGwa($s['grade_structure']['Grade 11']);
                                $g12Gwa = gradeLevelGwa($s['grade_structure']['Grade 12']);
                            ?>
                                <tr>
                                    <td class="cell-id"><?= htmlspecialchars($info['student_id_number'] ?: '—') ?></td>
                                    <td>
                                        <div class="cell-name"><?= htmlspecialchars($name) ?></div>
                                        <div class="cell-lrn">LRN: <?= htmlspecialchars($info['lrn'] ?: '—') ?></div>
                                    </td>
                                    <td>
                                        <?php if ($latestEn): ?>
                                            <span class="pill info">
                                                <i class="bi bi-mortarboard"></i>
                                                <?= htmlspecialchars($latestEn['grade_level'] ?: '—') ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="cell-lrn">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($latestEn && !empty($latestEn['section'])): ?>
                                            <span class="cell-accent"><?= htmlspecialchars($latestEn['section']) ?></span>
                                        <?php else: ?>
                                            <span class="cell-lrn">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="cell-center">
                                        <?php if ($g11Count > 0): ?>
                                            <span class="pill ok">
                                                <i class="bi bi-check-circle-fill"></i>
                                                <?= $g11Count ?> grades
                                            </span>
                                            <?php if ($g11Gwa !== null): ?>
                                                <div class="gwa-sub">GWA <?= number_format($g11Gwa, 2) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="pill warn">
                                                <i class="bi bi-dash-circle"></i>
                                                None
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="cell-center">
                                        <?php if ($g12Count > 0): ?>
                                            <span class="pill ok">
                                                <i class="bi bi-check-circle-fill"></i>
                                                <?= $g12Count ?> grades
                                            </span>
                                            <?php if ($g12Gwa !== null): ?>
                                                <div class="gwa-sub">GWA <?= number_format($g12Gwa, 2) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="pill warn">
                                                <i class="bi bi-dash-circle"></i>
                                                None
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="cell-center">
                                        <?php if ($s['overall_gwa'] !== null): ?>
                                            <span class="cell-num"><?= number_format($s['overall_gwa'], 2) ?></span>
                                        <?php else: ?>
                                            <span class="cell-lrn">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($students) > 10): ?>
                    <div class="table-note">
                        <i class="bi bi-info-circle"></i>
                        Showing first 10 of <strong><?= count($students) ?></strong> — all <strong><?= count($students) ?></strong> will be included in the export.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
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
document.getElementById('menuToggle')?.addEventListener('click', () => {
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
</script>
</body>
</html>