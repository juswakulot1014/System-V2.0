<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/audit.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid student ID.";
    header("Location: student_profile.php");
    exit();
}

$student_id = (int)$_GET['id'];

// ====================== HELPERS ======================
function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function nz($v) { return ($v === null || $v === '') ? '—' : $v; }
function fmtDate($d) { return $d ? date('F d, Y', strtotime($d)) : '—'; }

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

function autoRemark($grade) {
    if ($grade === null || !is_numeric($grade)) return '—';
    return ((float)$grade >= 75) ? 'Passed' : 'Failed';
}

// ====================== REQUESTED GRADE VIEW ======================
// ?grade=Grade 11 limits the SF10 to that level. Anything else (or no parameter)
// gives the full Senior High School record. Only the two known levels are accepted.
$ALL_LEVELS  = ['Grade 11', 'Grade 12'];
$onlyLevel   = normalizeGradeLevel($_GET['grade'] ?? '');
if (!in_array($onlyLevel, $ALL_LEVELS, true)) {
    $onlyLevel = null;
}
$levels = $onlyLevel !== null ? [$onlyLevel] : $ALL_LEVELS;

// ====================== FETCH STUDENT ======================
$sql = "
    SELECT s.student_id, s.student_id_number, s.lrn,
           s.last_name, s.first_name, s.middle_name, s.ext_name,
           s.sex, s.birth_date, s.age, s.civil_status, s.nationality, s.religion,
           a.purok_street, a.barangay, a.town_city, a.province, a.region, a.district, a.postal_code,
           p.father_name, p.mother_maiden_name, p.guardian_fullname, p.guardian_relation,
           e.previous_school_name, e.previous_school_address,
           e.previous_year_completed, e.previous_track, e.previous_strand,
           e.track, e.strand, e.program
    FROM students_info s
    LEFT JOIN addresses       a ON s.student_id = a.student_id
    LEFT JOIN parents_info    p ON s.student_id = p.student_id
    LEFT JOIN enrollment_form e ON s.student_id = e.student_id
    WHERE s.student_id = ?
    ORDER BY e.enrollment_id DESC
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    $_SESSION['error'] = "Student not found.";
    header("Location: student_profile.php");
    exit();
}

// ====================== FETCH ALL ENROLLMENTS ======================
$enrollments = [];
$en_stmt = $conn->prepare("
    SELECT enrollment_id, school_year, grade_level, term,
           track, strand, program, section,
           voucher_status, household_id, status, cct_4ps
    FROM enrollment_form
    WHERE student_id = ?
    ORDER BY school_year ASC, term ASC, enrollment_id ASC
");
$en_stmt->bind_param("i", $student_id);
$en_stmt->execute();
$res = $en_stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $enrollments[(int)$row['enrollment_id']] = $row;
}
$en_stmt->close();

/* ═══════════════════════════════════════════════════════════════
   Pick the "primary" enrollment for each grade level.
   Preference: latest school year, latest term (3rd > 2nd > 1st),
   then highest enrollment_id.
   ═══════════════════════════════════════════════════════════════ */
$primaryEnrollmentByGrade = ['Grade 11' => null, 'Grade 12' => null];
$termRank = ['1st Term' => 1, '2nd Term' => 2, '3rd Term' => 3];

foreach ($enrollments as $en) {
    $gl = normalizeGradeLevel($en['grade_level'] ?? '');
    $tm = normalizeTerm($en['term'] ?? '');
    if ($gl === null) continue;

    $candidate = $en;
    $candidate['_term_rank'] = $termRank[$tm] ?? 0;
    $candidate['_sy']        = (string)($en['school_year'] ?? '');

    $current = $primaryEnrollmentByGrade[$gl];

    if ($current === null) {
        $primaryEnrollmentByGrade[$gl] = $candidate;
        continue;
    }

    $cmp_sy = strcmp($candidate['_sy'], (string)$current['_sy']);
    if ($cmp_sy > 0) {
        $primaryEnrollmentByGrade[$gl] = $candidate;
    } elseif ($cmp_sy === 0 && $candidate['_term_rank'] > $current['_term_rank']) {
        $primaryEnrollmentByGrade[$gl] = $candidate;
    } elseif ($cmp_sy === 0
              && $candidate['_term_rank'] === $current['_term_rank']
              && (int)$candidate['enrollment_id'] > (int)$current['enrollment_id']) {
        $primaryEnrollmentByGrade[$gl] = $candidate;
    }
}

// Header track/strand/program come from the enrollment of the level being shown.
// For the full record, they come from the latest enrollment (already in $student).
if ($onlyLevel !== null && !empty($primaryEnrollmentByGrade[$onlyLevel])) {
    $pe = $primaryEnrollmentByGrade[$onlyLevel];
    $student['track']   = $pe['track']   ?? $student['track'];
    $student['strand']  = $pe['strand']  ?? $student['strand'];
    $student['program'] = $pe['program'] ?? $student['program'];
}

// ====================== FETCH ALL GRADES ======================
$grades_by_enrollment = [];
$gr_stmt = $conn->prepare("
    SELECT enrollment_id, term, grade_level, school_year,
           subject_code, subject_name, grade, remarks
    FROM student_grades
    WHERE student_id = ?
    ORDER BY enrollment_id ASC, FIELD(term, '1st Term', '2nd Term', '3rd Term'), subject_name ASC
");
$gr_stmt->bind_param("i", $student_id);
$gr_stmt->execute();
$res = $gr_stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $grades_by_enrollment[(int)$row['enrollment_id']][] = $row;
}
$gr_stmt->close();

// ====================== BUILD SF10 STRUCTURE ======================
$emptyTerm = ['enrollment' => null, 'subjects' => [], 'gwa' => null, 'school_year' => ''];
$sf10 = [
    'Grade 11' => ['1st Term' => $emptyTerm, '2nd Term' => $emptyTerm, '3rd Term' => $emptyTerm],
    'Grade 12' => ['1st Term' => $emptyTerm, '2nd Term' => $emptyTerm, '3rd Term' => $emptyTerm],
];

// Flatten grades, resolving grade level and term from the enrollment when a row lacks them
$allGrades = [];
foreach ($grades_by_enrollment as $en_id => $rows) {
    $enRow = $enrollments[$en_id] ?? null;
    foreach ($rows as $g) {
        $gl = normalizeGradeLevel($g['grade_level'] ?? '');
        $tm = normalizeTerm($g['term'] ?? '');
        if ($gl === null && $enRow) $gl = normalizeGradeLevel($enRow['grade_level'] ?? '');
        if ($tm === null && $enRow) $tm = normalizeTerm($enRow['term'] ?? '');
        if ($gl === null || $tm === null) continue;

        $allGrades[] = [
            'grade_level' => $gl,
            'term'        => $tm,
            'school_year' => $g['school_year'] ?? ($enRow['school_year'] ?? ''),
            'code'        => $g['subject_code'],
            'name'        => $g['subject_name'],
            'grade'       => $g['grade'],
            'remarks'     => $g['remarks'],
        ];
    }
}

// Attach enrollment info to each term slot
foreach ($enrollments as $en) {
    $gl = normalizeGradeLevel($en['grade_level'] ?? '');
    $tm = normalizeTerm($en['term'] ?? '');
    if ($gl === null || $tm === null) continue;
    $sf10[$gl][$tm]['enrollment']  = $en;
    $sf10[$gl][$tm]['school_year'] = $en['school_year'];
}

// Place each subject into its term block (one row per subject code)
foreach ($allGrades as $g) {
    $gl = $g['grade_level'];
    $tm = $g['term'];

    if (empty($sf10[$gl][$tm]['school_year']) && !empty($g['school_year'])) {
        $sf10[$gl][$tm]['school_year'] = $g['school_year'];
    }

    $key = !empty($g['code']) ? $g['code'] : ('__' . $g['name']);

    if (!isset($sf10[$gl][$tm]['subjects'][$key])) {
        $sf10[$gl][$tm]['subjects'][$key] = [
            'code'   => $g['code'],
            'name'   => $g['name'],
            'final'  => null,
            'remark' => $g['remarks'] ?? null,
        ];
    }

    if ($g['grade'] !== null && is_numeric($g['grade'])) {
        $sf10[$gl][$tm]['subjects'][$key]['final'] = (float)$g['grade'];
    }
    if (!empty($g['remarks'])) {
        $sf10[$gl][$tm]['subjects'][$key]['remark'] = $g['remarks'];
    }
}

// Sort subjects and compute term GWAs
foreach ($ALL_LEVELS as $gl) {
    foreach (['1st Term', '2nd Term', '3rd Term'] as $tm) {
        $subjects = $sf10[$gl][$tm]['subjects'];
        uasort($subjects, function ($a, $b) {
            return strcasecmp($a['code'] ?: $a['name'], $b['code'] ?: $b['name']);
        });
        $sf10[$gl][$tm]['subjects'] = $subjects;

        $finals = [];
        foreach ($subjects as $subj) {
            if ($subj['final'] !== null) $finals[] = $subj['final'];
        }
        $sf10[$gl][$tm]['gwa'] = count($finals) > 0
            ? round(array_sum($finals) / count($finals), 2)
            : null;
    }
}

// Grade-level GWAs and overall GWA, over the levels being shown
$gradeLevelGwa = [];
$allFinalGrades = [];
foreach ($levels as $gl) {
    $vals  = [];
    $finals = [];
    foreach (['1st Term', '2nd Term', '3rd Term'] as $tm) {
        if ($sf10[$gl][$tm]['gwa'] !== null) $vals[] = $sf10[$gl][$tm]['gwa'];
        foreach ($sf10[$gl][$tm]['subjects'] as $subj) {
            if ($subj['final'] !== null) $finals[] = $subj['final'];
        }
    }
    $gradeLevelGwa[$gl] = count($vals) > 0 ? round(array_sum($vals) / count($vals), 2) : null;
    $allFinalGrades     = array_merge($allFinalGrades, $finals);
}

$overall_gwa = count($allFinalGrades) > 0
    ? round(array_sum($allFinalGrades) / count($allFinalGrades), 2)
    : null;

$fullName = trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
$address = implode(', ', array_filter([
    $student['purok_street'] ?? null,
    $student['barangay']     ?? null,
    $student['town_city']    ?? null,
    $student['province']     ?? null,
])) ?: '—';

audit_log($conn, 'sf10.print', [
    'type' => 'student',
    'id'   => $student_id,
    'view' => $onlyLevel ?? 'full',
]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF10-SHS — <?= h($fullName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --ink:        #0a0a0a;
            --ink-soft:   #1f2937;
            --rule:       #0a0a0a;
            --label-bg:   #f4f4f5;
            --head-bg:    #e4e4e7;
            --band-bg:    #f1f1f3;
            --accent:     #1e3a8a;
            --accent-2:   #2563eb;
            --gold:       #fef3c7;
            --paper-grey: #d6d6d6;

            --green:      #059669;
            --green-soft: #d1fae5;
            --red:        #dc2626;
            --red-soft:   #fee2e2;
            --amber:      #d97706;
            --amber-soft: #fef3c7;
            --purple:     #7c3aed;
            --purple-soft:#ede9fe;

            --paper-width:  215.9mm;
            --paper-height: 330.2mm;
            --paper-pad-x:  16mm;
            --paper-pad-y:  14mm;
        }

        body.paper-long   { --paper-width: 215.9mm; --paper-height: 330.2mm; }
        body.paper-legal  { --paper-width: 215.9mm; --paper-height: 355.6mm; }
        body.paper-a4     { --paper-width: 210mm;   --paper-height: 297mm;   }
        body.paper-letter { --paper-width: 215.9mm; --paper-height: 279.4mm; }

        * { box-sizing: border-box; }

        html, body { margin: 0; padding: 0; }

        body {
            background: var(--paper-grey);
            padding: 90px 0 60px;
            font-family: 'Times New Roman', Times, Georgia, serif;
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }

        /* ============================================================
           Floating toolbar
           ============================================================ */
        .toolbar {
            position: fixed;
            top: 18px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            background: rgba(255,255,255,0.98);
            border-radius: 999px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.18), 0 2px 6px rgba(0,0,0,0.08);
            border: 1px solid rgba(0,0,0,0.06);
            z-index: 1000;
            font-family: system-ui, -apple-system, sans-serif;
            backdrop-filter: blur(10px);
        }

        .toolbar .btn-back,
        .toolbar .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-family: inherit;
            transition: transform 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
            white-space: nowrap;
            line-height: 1;
        }
        .toolbar .btn-print {
            background: #0f172a;
            color: #fff;
            box-shadow: 0 4px 12px rgba(15,23,42,0.35);
        }
        .toolbar .btn-print:hover { transform: translateY(-1px); background: #1e293b; box-shadow: 0 6px 16px rgba(15,23,42,0.45); }
        .toolbar .btn-back {
            background: #f4f4f5;
            color: #18181b;
        }
        .toolbar .btn-back:hover { background: #e4e4e7; }

        .toolbar .divider {
            width: 1px;
            height: 22px;
            background: #e4e4e7;
        }

        .toolbar .paper-group {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 2px 2px 2px 4px;
            background: #fafafa;
            border: 1px solid #e4e4e7;
            border-radius: 999px;
        }
        .toolbar .paper-group label {
            padding-left: 10px;
            font-size: 0.72rem;
            font-weight: 700;
            color: #52525b;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }
        .toolbar .paper-group select {
            appearance: none;
            -webkit-appearance: none;
            background: #fff url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'><path fill='%2352525b' d='M0 0l5 6 5-6z'/></svg>") no-repeat right 12px center;
            border: 1px solid #e4e4e7;
            border-radius: 999px;
            padding: 6px 28px 6px 14px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #18181b;
            cursor: pointer;
            font-family: inherit;
            outline: none;
            transition: border-color 0.15s ease;
        }
        .toolbar .paper-group select:hover,
        .toolbar .paper-group select:focus { border-color: #a1a1aa; }

        /* ============================================================
           Paper
           ============================================================ */
        .sf10-container {
            width: var(--paper-width);
            min-height: var(--paper-height);
            margin: 0 auto;
            background: #fff;
            padding: var(--paper-pad-y) var(--paper-pad-x) calc(var(--paper-pad-y) + 4mm);
            font-family: 'Times New Roman', Times, Georgia, serif;
            font-size: 11.5px;
            color: var(--ink);
            line-height: 1.45;
            border: 1px solid #8a8a8a;
            box-shadow: 0 24px 60px rgba(0,0,0,0.28), 0 4px 12px rgba(0,0,0,0.12);
            transition: width 0.2s ease, min-height 0.2s ease;
        }

        /* ============================================================
           Official header
           ============================================================ */
        .official-header {
            position: relative;
            text-align: center;
            padding-bottom: 14px;
            margin-bottom: 18px;
            border-bottom: 3px double var(--ink);
        }

        .official-header .logo-wrap {
            display: flex;
            justify-content: center;
            margin-bottom: 8px;
        }

        .official-header .logo {
            width: 110px;
            height: 110px;
            object-fit: contain;
            border-radius: 50%;
            padding: 4px;
            background: #fff;
            box-shadow:
                0 0 0 1.5px #d4d4d8,
                0 0 0 4px #fff,
                0 0 0 5.5px #e4e4e7;
        }

        .official-header .rep-line {
            font-size: 11px;
            letter-spacing: 0.6px;
            line-height: 1.35;
        }

        .official-header .school-name {
            font-size: 17px;
            font-weight: bold;
            letter-spacing: 1.4px;
            margin: 6px 0 3px;
            text-transform: uppercase;
            line-height: 1.15;
        }

        .official-header .school-addr {
            font-size: 10.5px;
            font-style: italic;
            letter-spacing: 0.3px;
            color: #374151;
        }

        .official-header .title-band {
            margin-top: 14px;
            padding-top: 10px;
            border-top: 1px solid #d4d4d8;
        }

        .official-header .title {
            font-size: 15px;
            font-weight: bold;
            letter-spacing: 2px;
            margin: 0 0 3px;
            text-transform: uppercase;
        }

        .official-header .subtitle {
            font-size: 10px;
            font-style: italic;
            color: #4b5563;
            letter-spacing: 0.4px;
        }

        /* Archived-view notice (only shown with ?grade=) */
        .archive-notice {
            margin: 0 0 14px;
            padding: 6px 10px;
            border: 1px solid #f59e0b;
            background: #fffbeb;
            font-size: 10.5px;
            font-style: italic;
            color: #92400e;
            text-align: center;
        }

        /* ============================================================
           Section titles
           ============================================================ */
        .section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: bold;
            font-size: 11px;
            letter-spacing: 1.3px;
            text-transform: uppercase;
            padding: 5px 0 5px 12px;
            margin: 20px 0 10px;
            border-left: 4px solid var(--accent);
            border-top: 1px solid #d4d4d8;
            border-bottom: 1px solid #d4d4d8;
            background: linear-gradient(to right, rgba(30,58,138,0.05), rgba(30,58,138,0.005));
            color: var(--ink-soft);
        }

        /* ============================================================
           Tables
           ============================================================ */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        th, td {
            border: 1px solid var(--ink);
            padding: 6px 9px;
            vertical-align: top;
            font-size: 11px;
        }
        th {
            background: var(--head-bg);
            font-weight: bold;
            text-align: center;
            font-size: 10.5px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        td.label {
            background: var(--label-bg);
            font-weight: bold;
            width: 22%;
            font-size: 10.5px;
            letter-spacing: 0.3px;
            vertical-align: middle;
        }
        td.center { text-align: center; }
        td.right  { text-align: right; }
        td.mono   { font-variant-numeric: tabular-nums; }

        /* ============================================================
           Grade blocks
           ============================================================ */
        .grade-block {
            page-break-inside: avoid;
            margin-bottom: 20px;
            border: 1.5px solid var(--ink);
            border-radius: 2px;
            overflow: hidden;
        }
        .grade-block.page-break {
            page-break-before: always;
            break-before: page;
        }

        .grade-header {
            background: var(--accent);
            color: #fff;
            padding: 8px 14px;
            font-weight: bold;
            font-size: 12.5px;
            letter-spacing: 1.8px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .grade-header .grade-gwa {
            background: #fff;
            color: var(--accent);
            padding: 3px 12px;
            font-size: 10px;
            letter-spacing: 0.6px;
            border-radius: 2px;
            font-weight: bold;
            text-transform: none;
        }

        /* ============================================================
           Enrollment details panel
           ============================================================ */
        .enrollment-panel {
            background: #fafafc;
            border-bottom: 1px solid var(--ink);
            padding: 12px 14px;
            page-break-inside: avoid;
        }

        .enrollment-panel-header {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10.5px;
            font-weight: bold;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: var(--accent);
            margin-bottom: 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #d4d4d8;
        }
        .enrollment-panel-header i {
            font-size: 12px;
        }

        .enrollment-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            overflow: hidden;
            background: #fff;
        }

        .enrollment-item {
            padding: 8px 11px;
            border-right: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
            background: #fff;
            min-height: 46px;
        }
        .enrollment-item:nth-child(3n)        { border-right: none; }
        .enrollment-item:nth-last-child(-n+3) { border-bottom: none; }

        .enrollment-item .lbl {
            font-size: 8.5px;
            font-weight: bold;
            color: #64748b;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            margin-bottom: 3px;
        }
        .enrollment-item .val {
            font-size: 10.5px;
            font-weight: 600;
            color: #0f172a;
            line-height: 1.35;
            word-break: break-word;
        }
        .enrollment-item .val.accent { color: var(--accent); }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 1px 8px 1px 6px;
            border-radius: 999px;
            font-size: 9.5px;
            font-weight: bold;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            border: 1px solid;
        }
        .status-pill::before {
            content: '';
            width: 6px; height: 6px; border-radius: 50%;
            background: currentColor;
        }
        .status-pill.active      { background: var(--green-soft);  color: var(--green);  border-color: #10b981; }
        .status-pill.pending     { background: var(--amber-soft);  color: var(--amber);  border-color: #f59e0b; }
        .status-pill.graduated   { background: var(--purple-soft); color: var(--purple); border-color: #8b5cf6; }
        .status-pill.danger      { background: var(--red-soft);    color: var(--red);    border-color: #ef4444; }
        .status-pill.muted       { background: #f1f5f9;            color: #475569;       border-color: #cbd5e1; }
        .status-pill.muted::before { background: #94a3b8; }

        .enrollment-empty {
            background: #fff7ed;
            border-left: 3px solid #f59e0b;
            padding: 10px 14px;
            font-size: 10.5px;
            font-style: italic;
            color: #92400e;
        }

        /* ============================================================
           Term blocks
           ============================================================ */
        .term-header {
            background: var(--band-bg);
            padding: 6px 14px;
            font-weight: bold;
            font-size: 10.5px;
            letter-spacing: 0.9px;
            text-transform: uppercase;
            border-top: 1px solid var(--ink);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            color: var(--ink-soft);
        }
        .term-header .sy-tag {
            font-weight: normal;
            font-style: italic;
            text-transform: none;
            letter-spacing: 0;
            font-size: 10px;
            color: #4b5563;
        }

        .grades-table { margin: 0; }
        .grades-table th,
        .grades-table td {
            border: 1px solid var(--ink);
            border-left: none;
            border-right: none;
        }
        .grades-table thead th {
            background: #ececec;
            border-top: none;
        }
        .grades-table tbody tr:nth-child(even) td {
            background: #fafafa;
        }

        .gwa-row td {
            background: #f4f4f5 !important;
            font-weight: bold;
            text-align: right;
            padding: 7px 10px;
            letter-spacing: 0.3px;
            font-size: 10.5px;
            text-transform: uppercase;
            color: var(--ink-soft);
        }
        .gwa-row td.val {
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            font-variant-numeric: tabular-nums;
            color: var(--accent);
        }

        .grade-footer {
            background: #f4f4f5;
            font-weight: bold;
            font-size: 11px;
            padding: 8px 12px;
            text-align: right;
            letter-spacing: 0.4px;
            border-top: 1.5px solid var(--ink);
            text-transform: uppercase;
            color: var(--ink-soft);
        }
        .grade-footer .grade-footer-val {
            display: inline-block;
            min-width: 72px;
            text-align: center;
            background: #fff;
            border: 1px solid var(--ink);
            padding: 2px 10px;
            margin-left: 12px;
            font-size: 12.5px;
            font-variant-numeric: tabular-nums;
            color: var(--accent);
        }

        .empty-block {
            padding: 16px;
            text-align: center;
            color: #6b7280;
            font-style: italic;
            font-size: 10.5px;
            background: #fafafa;
        }

        /* ============================================================
           Overall GWA
           ============================================================ */
        .overall-table {
            margin-top: 18px;
            border: 2px solid var(--ink);
        }
        .overall-table td {
            background: var(--gold) !important;
            border: none !important;
            font-weight: bold;
            padding: 12px 16px;
            font-size: 12px;
            letter-spacing: 0.6px;
            text-transform: uppercase;
        }
        .overall-table td:last-child {
            text-align: center;
            font-size: 16px;
            font-variant-numeric: tabular-nums;
            color: var(--accent);
            border-left: 2px solid var(--ink) !important;
            width: 120px;
        }

        /* ============================================================
           Certification + signatures
           ============================================================ */
        .certification {
            text-align: center;
            font-style: italic;
            padding: 12px 30px;
            line-height: 1.65;
            font-size: 11px;
            color: var(--ink-soft);
        }

        .signature-block {
            display: flex;
            justify-content: space-around;
            gap: 60px;
            margin-top: 70px;
            page-break-inside: avoid;
        }
        .sig-item { flex: 1; text-align: center; }
        .sig-line {
            border-top: 1px solid var(--ink);
            margin: 0 auto 6px;
            width: 240px;
            padding-top: 5px;
            font-weight: bold;
            font-size: 11px;
            letter-spacing: 0.7px;
            text-transform: uppercase;
        }
        .sig-label {
            font-size: 10px;
            font-style: italic;
            color: #4b5563;
            margin-top: 2px;
        }

        .doc-footer {
            text-align: center;
            font-size: 9px;
            color: #6b7280;
            margin-top: 34px;
            padding-top: 10px;
            border-top: 1px dashed #a1a1aa;
            letter-spacing: 0.4px;
            font-family: system-ui, -apple-system, sans-serif;
        }

        /* ============================================================
           Print
           ============================================================ */
        @media print {
            @page { size: 8.5in 13in; margin: 10mm; }
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .sf10-container {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                border: none;
                box-shadow: none;
            }
            .grade-block,
            .signature-block,
            .overall-table,
            .enrollment-panel { page-break-inside: avoid; }
            .grade-block.page-break { page-break-before: always; break-before: page; }
            .section-title { page-break-after: avoid; }
            .official-header { page-break-after: avoid; }
            .grade-header,
            .section-title,
            .term-header,
            th,
            .gwa-row td,
            .grade-footer,
            .overall-table td,
            .official-header .logo,
            .enrollment-item,
            .enrollment-panel-header,
            .status-pill {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        @media screen and (max-width: 900px) {
            body { padding: 80px 12px 40px; }
            .toolbar {
                flex-wrap: wrap;
                max-width: calc(100vw - 24px);
                justify-content: center;
            }
            .sf10-container {
                width: 100%;
                min-height: auto;
                padding: 20px;
            }
            .official-header .logo { width: 88px; height: 88px; }
            .signature-block { flex-direction: column; gap: 50px; }
            .enrollment-grid { grid-template-columns: 1fr 1fr; }
            .enrollment-item:nth-child(3n) { border-right: 1px solid #cbd5e1; }
            .enrollment-item:nth-child(2n) { border-right: none; }
        }
    </style>
</head>
<body class="paper-long">

<!-- =========================================================
     Floating toolbar
     ========================================================= -->
<div class="toolbar no-print">
    <a class="btn-back" href="view_student.php?id=<?= $student_id ?>">
        <i class="bi bi-arrow-left"></i> Back
    </a>

    <div class="divider"></div>

    <div class="paper-group">
        <label for="paperSize">Paper</label>
        <select id="paperSize" aria-label="Paper size">
            <option value="long"   selected>Long Bond · 8.5 × 13</option>
            <option value="legal">Legal · 8.5 × 14</option>
            <option value="a4">A4 · 210 × 297 mm</option>
            <option value="letter">Short Bond · 8.5 × 11</option>
        </select>
    </div>

    <button class="btn-print" onclick="printNow()">
        <i class="bi bi-printer-fill"></i> Print
    </button>
</div>

<!-- =========================================================
     Paper
     ========================================================= -->
<div class="sf10-container">

    <!-- Header -->
    <div class="official-header">
        <div class="logo-wrap">
            <img src="../assets/img/usat.jpg"
                 alt="USAT College Seal"
                 class="logo"
                 onerror="this.onerror=null; this.src='data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 200 200%22><defs><linearGradient id=%22g%22 x1=%220%22 y1=%220%22 x2=%221%22 y2=%221%22><stop offset=%220%22 stop-color=%22%232563eb%22/><stop offset=%221%22 stop-color=%22%231e40af%22/></linearGradient></defs><circle cx=%22100%22 cy=%22100%22 r=%2295%22 fill=%22url(%23g)%22 stroke=%22%23fbbf24%22 stroke-width=%224%22/><ellipse cx=%22100%22 cy=%22100%22 rx=%2260%22 ry=%2222%22 fill=%22none%22 stroke=%22%23fde047%22 stroke-width=%223%22 transform=%22rotate(30 100 100)%22/><ellipse cx=%22100%22 cy=%22100%22 rx=%2260%22 ry=%2222%22 fill=%22none%22 stroke=%22%23f472b6%22 stroke-width=%223%22 transform=%22rotate(-30 100 100)%22/><circle cx=%22100%22 cy=%22100%22 r=%2210%22 fill=%22%23fde047%22/><text x=%22100%22 y=%22175%22 text-anchor=%22middle%22 fill=%22%23fde047%22 font-family=%22Georgia,serif%22 font-size=%2216%22 font-weight=%22bold%22 letter-spacing=%222%22>USAT</text></svg>';">
        </div>
        <div class="rep-line">Republic of the Philippines</div>
        <div class="rep-line">Department of Education</div>
        <div class="school-name">USAT College Sagay City, Inc.</div>
        <div class="school-addr">Sagay City, Negros Occidental</div>

        <div class="title-band">
            <div class="title">Learner's Permanent Academic Record</div>
            <div class="subtitle">SF 10 – SHS · Senior High School (<?= $onlyLevel !== null ? h($onlyLevel) : 'Grade 11 to Grade 12' ?>)</div>
        </div>
    </div>

    <?php if ($onlyLevel !== null): ?>
        <div class="archive-notice">
            Archived view: showing the <?= h($onlyLevel) ?> record only.
        </div>
    <?php endif; ?>

    <!-- Personal Info -->
    <div class="section-title">Learner's Personal Information</div>
    <table>
        <tr>
            <td class="label">LRN</td>
            <td class="mono"><?= h(nz($student['lrn'])) ?></td>
            <td class="label">Student ID No.</td>
            <td class="mono"><?= h(nz($student['student_id_number'])) ?></td>
        </tr>
        <tr>
            <td class="label">Last Name</td>
            <td><?= h(nz($student['last_name'])) ?></td>
            <td class="label">First Name</td>
            <td><?= h(nz($student['first_name'])) ?></td>
        </tr>
        <tr>
            <td class="label">Middle Name</td>
            <td><?= h(nz($student['middle_name'])) ?></td>
            <td class="label">Ext. Name</td>
            <td><?= h(nz($student['ext_name'])) ?></td>
        </tr>
        <tr>
            <td class="label">Sex</td>
            <td><?= h(nz($student['sex'])) ?></td>
            <td class="label">Date of Birth</td>
            <td><?= h(fmtDate($student['birth_date'])) ?></td>
        </tr>
        <tr>
            <td class="label">Nationality</td>
            <td><?= h(nz($student['nationality'])) ?></td>
            <td class="label">Religion</td>
            <td><?= h(nz($student['religion'])) ?></td>
        </tr>
        <tr>
            <td class="label">Address</td>
            <td colspan="3"><?= h($address) ?></td>
        </tr>
        <tr>
            <td class="label">Father</td>
            <td colspan="3"><?= h(nz($student['father_name'])) ?></td>
        </tr>
        <tr>
            <td class="label">Mother (Maiden)</td>
            <td colspan="3"><?= h(nz($student['mother_maiden_name'])) ?></td>
        </tr>
        <tr>
            <td class="label">Guardian</td>
            <td colspan="3">
                <?= h(nz($student['guardian_fullname'])) ?>
                <?php if (!empty($student['guardian_relation'])): ?>
                    (<?= h($student['guardian_relation']) ?>)
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <!-- Scholastic Record -->
    <div class="section-title">Scholastic Record — Senior High School</div>

    <?php foreach ($levels as $idx => $gradeLevel):
        $pageBreakClass = ($idx > 0) ? ' page-break' : '';
        $enr = $primaryEnrollmentByGrade[$gradeLevel] ?? null;
    ?>
        <div class="grade-block<?= $pageBreakClass ?>">
            <div class="grade-header">
                <span><?= strtoupper($gradeLevel) ?></span>
                <?php if ($gradeLevelGwa[$gradeLevel] !== null): ?>
                    <span class="grade-gwa">General Average: <?= number_format($gradeLevelGwa[$gradeLevel], 2) ?></span>
                <?php endif; ?>
            </div>

            <!-- ENROLLMENT DETAILS PANEL -->
            <?php if ($enr): ?>
                <?php
                    $status_raw = strtolower(trim((string)($enr['status'] ?? 'Active')));
                    if ($status_raw === '') $status_raw = 'active';

                    $status_class = match ($status_raw) {
                        'active'      => 'active',
                        'pending'     => 'pending',
                        'graduated'   => 'graduated',
                        'dropped',
                        'stopped',
                        'transferred' => 'danger',
                        default       => 'muted',
                    };

                    $voucher_raw  = trim((string)($enr['voucher_status'] ?? ''));
                    $voucher_disp = ($voucher_raw !== '') ? $voucher_raw : '—';

                    $hh_raw = trim((string)($enr['household_id'] ?? ''));
                    $is_4ps = !empty($enr['cct_4ps']) ? 'Yes' : 'No';
                ?>
                <div class="enrollment-panel">
                    <div class="enrollment-panel-header">
                        <i class="bi bi-mortarboard-fill"></i>
                        Enrollment Details — <?= h($gradeLevel) ?>
                    </div>
                    <div class="enrollment-grid">
                        <div class="enrollment-item">
                            <div class="lbl">School Year</div>
                            <div class="val"><?= h(nz($enr['school_year'])) ?></div>
                        </div>
                        <div class="enrollment-item">
                            <div class="lbl">Grade Level</div>
                            <div class="val"><?= h(nz($enr['grade_level'])) ?></div>
                        </div>
                        <div class="enrollment-item">
                            <div class="lbl">Term</div>
                            <div class="val"><?= h(nz($enr['term'])) ?></div>
                        </div>
                        <div class="enrollment-item">
                            <div class="lbl">Section</div>
                            <div class="val accent"><?= h(nz($enr['section'])) ?></div>
                        </div>
                        <div class="enrollment-item">
                            <div class="lbl">Track</div>
                            <div class="val"><?= h(nz($enr['track'])) ?></div>
                        </div>
                        <div class="enrollment-item">
                            <div class="lbl">Strand</div>
                            <div class="val"><?= h(nz($enr['strand'])) ?></div>
                        </div>
                        <div class="enrollment-item">
                            <div class="lbl">Program</div>
                            <div class="val"><?= h(nz($enr['program'])) ?></div>
                        </div>
                        <div class="enrollment-item">
                            <div class="lbl">Voucher Status</div>
                            <div class="val"><?= h($voucher_disp) ?></div>
                        </div>
                        <div class="enrollment-item">
                            <div class="lbl">Household ID</div>
                            <div class="val"><?= h($hh_raw !== '' ? $hh_raw : '—') ?></div>
                        </div>
                        <div class="enrollment-item">
                            <div class="lbl">CCT / 4Ps</div>
                            <div class="val"><?= h($is_4ps) ?></div>
                        </div>
                        <div class="enrollment-item" style="grid-column: span 2;">
                            <div class="lbl">Status</div>
                            <div class="val">
                                <span class="status-pill <?= $status_class ?>">
                                    <?= h(ucfirst($status_raw)) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="enrollment-panel">
                    <div class="enrollment-panel-header">
                        <i class="bi bi-mortarboard-fill"></i>
                        Enrollment Details — <?= h($gradeLevel) ?>
                    </div>
                    <div class="enrollment-empty">
                        No enrollment record on file for <?= h($gradeLevel) ?>.
                    </div>
                </div>
            <?php endif; ?>

            <!-- Term-by-term grades -->
            <?php foreach (['1st Term', '2nd Term', '3rd Term'] as $termName):
                $block     = $sf10[$gradeLevel][$termName];
                $en        = $block['enrollment'];
                $sy        = $block['school_year'];
                $subjects  = $block['subjects'];
                $hasGrades = !empty($subjects);
            ?>
                <div class="term-header">
                    <span><?= h($termName) ?></span>
                    <?php if ($sy): ?>
                        <span class="sy-tag">School Year: <?= h($sy) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($hasGrades): ?>
                    <table class="grades-table">
                        <thead>
                            <tr>
                                <th style="width:18%">Subject Code</th>
                                <th style="width:52%">Subject Title</th>
                                <th style="width:15%">Final Grade</th>
                                <th style="width:15%">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $subj): ?>
                                <tr>
                                    <td class="center mono"><?= h(nz($subj['code'])) ?></td>
                                    <td><?= h(nz($subj['name'])) ?></td>
                                    <td class="center mono"><strong><?= $subj['final'] !== null ? number_format($subj['final'], 2) : '—' ?></strong></td>
                                    <td class="center"><?= h(!empty($subj['remark']) ? $subj['remark'] : autoRemark($subj['final'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="gwa-row">
                                <td colspan="2">General Average for <?= h($termName) ?>:</td>
                                <td class="val mono"><?= $block['gwa'] !== null ? number_format($block['gwa'], 2) : '—' ?></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-block">
                        <?= $en ? 'No grades recorded for this term.' : 'No enrollment record for this term.' ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="grade-footer">
                <?= strtoupper($gradeLevel) ?> General Average:
                <span class="grade-footer-val mono"><?= $gradeLevelGwa[$gradeLevel] !== null ? number_format($gradeLevelGwa[$gradeLevel], 2) : '—' ?></span>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Overall GWA -->
    <?php if ($overall_gwa !== null): ?>
        <table class="overall-table">
            <tr>
                <td style="width:80%">
                    <?= $onlyLevel !== null
                        ? 'Overall General Weighted Average (' . h($onlyLevel) . ')'
                        : 'Overall General Weighted Average (Grade 11 – Grade 12)' ?>
                </td>
                <td><?= number_format($overall_gwa, 2) ?></td>
            </tr>
        </table>
    <?php endif; ?>

    <!-- Certification -->
    <div class="section-title">Certification</div>
    <p class="certification">
        I hereby certify that the above information is true and correct based on the records of the school.
    </p>

    <div class="signature-block">
        <div class="sig-item">
            <div class="sig-line">School Head / Principal</div>
            <div class="sig-label">Date: <?= date('F d, Y') ?></div>
        </div>
        <div class="sig-item">
            <div class="sig-line">Registrar</div>
            <div class="sig-label">USAT College Sagay City, Inc.</div>
        </div>
    </div>

    <div class="doc-footer">
        Generated on <?= date('F d, Y h:i A') ?> · SF 10 – SHS · USAT College Sagay City, Inc.
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const PAPER_SIZES = {
    long:   { page: '8.5in 13in',  w: '215.9mm', h: '330.2mm' },
    legal:  { page: '8.5in 14in',  w: '215.9mm', h: '355.6mm' },
    a4:     { page: '210mm 297mm', w: '210mm',   h: '297mm'   },
    letter: { page: '8.5in 11in',  w: '215.9mm', h: '279.4mm' },
};

let currentPaper = 'long';

function applyPaperSize(sizeKey) {
    const def = PAPER_SIZES[sizeKey];
    if (!def) return;

    currentPaper = sizeKey;

    document.body.classList.remove('paper-long', 'paper-legal', 'paper-a4', 'paper-letter');
    document.body.classList.add('paper-' + sizeKey);

    const container = document.querySelector('.sf10-container');
    if (container) {
        container.style.width = def.w;
        container.style.minHeight = def.h;
    }

    let styleEl = document.getElementById('dynamic-page-style');
    if (!styleEl) {
        styleEl = document.createElement('style');
        styleEl.id = 'dynamic-page-style';
        document.head.appendChild(styleEl);
    }
    styleEl.textContent = `@media print { @page { size: ${def.page}; margin: 10mm; } }`;

    try { localStorage.setItem('sf10_paper_size', sizeKey); } catch (e) {}
}

function printNow() {
    applyPaperSize(currentPaper);
    setTimeout(() => window.print(), 80);
}

document.getElementById('paperSize').addEventListener('change', function () {
    applyPaperSize(this.value);
});

(function restorePaperSize() {
    let saved = 'long';
    try { saved = localStorage.getItem('sf10_paper_size') || 'long'; } catch (e) {}
    if (!PAPER_SIZES[saved]) saved = 'long';

    const sel = document.getElementById('paperSize');
    sel.value = saved;
    applyPaperSize(saved);
})();

document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
        e.preventDefault();
        printNow();
    }
});
</script>
</body>
</html>