<?php
// shared/pages/add_grades.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../lib/teacher_scope.php';
require_once __DIR__ . '/../lib/grades_email.php';
require_once __DIR__ . '/../lib/email_queue.php';

// Set to true temporarily to log spawn cmd to error_log — remove when stable
// define('WORKER_SPAWN_DEBUG', true);

$__nav_section = 'grades';

/* ── Role gate ── */
if (!in_array($_SESSION['admin_role'] ?? '', ['teacher', 'registrar', 'superadmin'], true)) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: ' . URL_PREFIX . '/dashboard.php'); exit;
}

$student_id = (int)($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
if ($student_id <= 0) { $_SESSION['error'] = 'No student specified.'; header('Location: ' . URL_PREFIX . '/student_profile.php'); exit; }

$stmt = $conn->prepare("
    SELECT s.student_id, s.student_id_number, s.lrn, s.last_name, s.first_name, s.middle_name, s.ext_name, s.email,
           e.enrollment_id, e.grade_level, e.section, e.strand, e.school_year
      FROM students_info s
      INNER JOIN enrollment_form e ON e.student_id = s.student_id
     WHERE s.student_id = ?
       AND COALESCE(e.status,'Active') = 'Active'
     ORDER BY e.enrollment_id DESC
     LIMIT 1
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$student) { $_SESSION['error'] = 'Student not found or not actively enrolled.'; header('Location: ' . URL_PREFIX . '/student_profile.php'); exit; }

$enrollment_id = (int)$student['enrollment_id'];
$sy            = (string)$student['school_year'];
$grade_level   = (string)$student['grade_level'];
$student_email = trim((string)($student['email'] ?? ''));

if (($_SESSION['admin_role'] ?? '') === 'teacher') {
    $teacher_id = (int)$_SESSION['admin_id'];
    if (!teacher_has_student($conn, $teacher_id, $student_id, $enrollment_id)) {
        security_log('grades.teacher_scope_denied', [
            'teacher_id'    => $teacher_id,
            'student_id'    => $student_id,
            'enrollment_id' => $enrollment_id,
            'ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        $_SESSION['error'] = 'You are not assigned to this student.';
        header('Location: ' . URL_PREFIX . '/dashboard.php'); exit;
    }
}

/* ── JSON helper for non-streaming endpoints ── */
$want_json = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
          || (($_POST['respond'] ?? '') === 'json');

$json_reply = function (array $payload, int $status = 200) use ($want_json): void {
    if ($want_json) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!empty($payload['flash_error']))   $_SESSION['error']   = $payload['flash_error'];
    if (!empty($payload['flash_success'])) $_SESSION['success'] = $payload['flash_success'];
    header('Location: ' . URL_PREFIX . '/add_grades.php?student_id=' . ($payload['student_id'] ?? 0));
    exit;
};

/* ═══════════════════════════════════════════════════════════
   POST — Save a grade
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grade'])) {
    verify_csrf();
    $subject_code = trim((string)($_POST['subject_code'] ?? ''));
    $subject_name = trim((string)($_POST['subject_name'] ?? ''));
    $term         = trim((string)($_POST['term'] ?? ''));
    $grade        = (float)($_POST['grade'] ?? -1);
    $remarks      = ($grade >= 75) ? 'Passed' : 'Failed';

    $errors = [];
    if ($subject_code === '' || mb_strlen($subject_code) > 20) $errors[] = 'Subject Code is required (max 20 chars).';
    if ($subject_name === '' || mb_strlen($subject_name) > 100) $errors[] = 'Subject Name is required (max 100 chars).';
    if ($term === '' || mb_strlen($term) > 50) $errors[] = 'Term is required.';
    if ($grade < 0 || $grade > 100) $errors[] = 'Grade must be between 0 and 100.';

    if ($errors) {
        $_SESSION['error'] = implode(' ', $errors);
    } else {
        $check = $conn->prepare("SELECT grade_id FROM student_grades WHERE student_id = ? AND subject_code = ? AND term = ? AND school_year = ?");
        $check->bind_param("isss", $student_id, $subject_code, $term, $sy);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            $stmt = $conn->prepare("UPDATE student_grades SET grade = ?, remarks = ?, subject_name = ?, grade_level = ?, enrollment_id = ? WHERE grade_id = ?");
            $stmt->bind_param("dsssii", $grade, $remarks, $subject_name, $grade_level, $enrollment_id, $existing['grade_id']);
            $msg = "Grade updated: {$subject_code} ({$term}) = " . number_format($grade, 2);
            $action = 'grade.update';
        } else {
            $stmt = $conn->prepare("INSERT INTO student_grades (student_id, enrollment_id, subject_code, subject_name, term, grade_level, school_year, grade, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisssssds", $student_id, $enrollment_id, $subject_code, $subject_name, $term, $grade_level, $sy, $grade, $remarks);
            $msg = "Grade saved: {$subject_code} ({$term}) = " . number_format($grade, 2);
            $action = 'grade.save';
        }

        if ($stmt->execute()) {
            audit_log($conn, $action, [
                'student_id' => $student_id, 'subject_code' => $subject_code,
                'term' => $term, 'grade' => $grade, 'remarks' => $remarks
            ]);
            $_SESSION['success'] = $msg;
        } else {
            $_SESSION['error'] = 'Failed to save grade.';
        }
        $stmt->close();
    }
    header('Location: ' . URL_PREFIX . '/add_grades.php?student_id=' . $student_id);
    exit;
}

/* ═══════════════════════════════════════════════════════════
   POST — Delete a grade
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_grade'])) {
    verify_csrf();
    $gid = (int)($_POST['grade_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM student_grades WHERE grade_id = ? AND student_id = ?");
    $stmt->bind_param("ii", $gid, $student_id);
    $stmt->execute();
    $_SESSION['success'] = $stmt->affected_rows > 0 ? 'Grade deleted.' : 'Grade not found.';
    $stmt->close();
    header('Location: ' . URL_PREFIX . '/add_grades.php?student_id=' . $student_id);
    exit;
}

/* ═══════════════════════════════════════════════════════════
   POST — Enqueue grade email (fast-return + background process)
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_grades_email'])) {
    verify_csrf();

    if (!in_array($_SESSION['admin_role'] ?? '', ['teacher', 'registrar', 'superadmin'], true)) {
        $json_reply(['ok' => false, 'message' => 'Access denied.', 'student_id' => $student_id], 403);
    }

    if (($_SESSION['admin_role'] ?? '') === 'teacher') {
        if (!teacher_has_student($conn, (int)$_SESSION['admin_id'], $student_id, $enrollment_id)) {
            security_log('grades.email_scope_denied', [
                'teacher_id' => (int)$_SESSION['admin_id'],
                'student_id' => $student_id,
            ]);
            $json_reply(['ok' => false, 'message' => 'You are not assigned to this student.', 'student_id' => $student_id], 403);
        }
    }

    $email_term = trim((string)($_POST['email_term'] ?? ''));
    if (!in_array($email_term, ['1st Term', '2nd Term', '3rd Term'], true)) {
        $json_reply(['ok' => false, 'message' => 'Invalid term.', 'student_id' => $student_id], 400);
    }

    // Rate limit: 2/term/day
    $today_key = 'grades_email_' . $student_id . '_' . md5($email_term) . '_' . date('Y-m-d');
    $_SESSION[$today_key] = (int)($_SESSION[$today_key] ?? 0);
    if ($_SESSION[$today_key] >= 2) {
        $json_reply([
            'ok' => false,
            'message' => 'Already sent twice today for this term. Try again tomorrow.',
            'term' => $email_term,
            'student_id' => $student_id,
        ], 429);
    }

    // Load grades
    $q = $conn->prepare("
        SELECT subject_code, subject_name, grade, remarks
          FROM student_grades
         WHERE student_id = ? AND term = ? AND school_year = ?
         ORDER BY subject_name ASC
    ");
    if (!$q) {
        $json_reply(['ok' => false, 'message' => 'System error.', 'student_id' => $student_id], 500);
    }
    $q->bind_param('iss', $student_id, $email_term, $sy);
    $q->execute();
    $email_grades = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();

    if (empty($email_grades)) {
        $json_reply(['ok' => false, 'message' => "No grades recorded for {$email_term}.", 'term' => $email_term, 'student_id' => $student_id], 400);
    }

    // Load student
    $st = $conn->prepare("SELECT first_name, middle_name, last_name, ext_name, lrn, email FROM students_info WHERE student_id = ? LIMIT 1");
    $st->bind_param('i', $student_id);
    $st->execute();
    $email_student = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$email_student) {
        $json_reply(['ok' => false, 'message' => 'Student not found.', 'student_id' => $student_id], 404);
    }

    $to_email = trim((string)($email_student['email'] ?? ''));
    if ($to_email === '' || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        $json_reply(['ok' => false, 'message' => 'Student has no valid email on file.', 'student_id' => $student_id], 400);
    }

    // Term GWA
    $term_total = 0; $term_count = 0;
    foreach ($email_grades as $g) {
        if ($g['grade'] !== null && is_numeric($g['grade'])) {
            $term_total += (float)$g['grade'];
            $term_count++;
        }
    }
    $term_gwa = $term_count > 0 ? round($term_total / $term_count, 2) : null;

    $subject_line = "[USAT] Term Grade Report — {$email_term} · {$sy} · " . ge_format_name($email_student);
    $payload = [
        'student'     => $email_student,
        'grades'      => $email_grades,
        'term'        => $email_term,
        'sy'          => $sy,
        'section'     => (string)($student['section'] ?? ''),
        'grade_level' => (string)($student['grade_level'] ?? ''),
        'strand'      => (string)($student['strand'] ?? ''),
        'gwa'         => $term_gwa,
        'queued_at'   => date('c'),
    ];

    $enq = eq_enqueue($conn, [
        'student_id'    => $student_id,
        'enrollment_id' => $enrollment_id,
        'term'          => $email_term,
        'school_year'   => $sy,
        'recipient'     => $to_email,
        'subject'       => $subject_line,
        'payload'       => $payload,
        'created_by'    => (int)$_SESSION['admin_id'],
    ]);

    if (empty($enq['ok'])) {
        $json_reply([
            'ok' => false,
            'message' => 'Could not queue: ' . ($enq['error'] ?? 'unknown'),
            'student_id' => $student_id,
        ], 500);
    }

    $job_id = (int)$enq['job_id'];

    $_SESSION[$today_key]++;
    audit_log($conn, 'grade.email_queued', [
        'type' => 'student', 'id' => $student_id,
        'term' => $email_term, 'sy' => $sy,
        'recipient' => $to_email, 'job_id' => $job_id,
        'grade_count' => count($email_grades),
    ]);

    /* ── Send JSON to client NOW, keep PHP running to actually send ── */
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok'         => true,
        'queued'     => true,
        'job_id'     => $job_id,
        'message'    => "Queued for {$to_email}",
        'term'       => $email_term,
        'recipient'  => $to_email,
        'student_id' => $student_id,
    ], JSON_UNESCAPED_UNICODE);

    flush_http_response_to_client(); // ← client disconnects here

    /* ── Now we're alone. Try the fast path (spawn), then fall back. ── */
    spawn_worker_async($job_id);

    // Wait up to 2s for the spawned worker to claim the job
    $job = null;
    for ($i = 0; $i < 10; $i++) {
        usleep(200000); // 200ms
        $job = eq_get_job($conn, $job_id);
        if (!$job || $job['status'] !== 'queued') {
            break; // worker took it
        }
    }

    // Worker never picked it up — process inline now
    if ($job && $job['status'] === 'queued') {
        eq_process_now($conn, $job_id);
    }

    exit;
}

/* ═══════════════════════════════════════════════════════════
   POST — Poll job status
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email_status'])) {
    verify_csrf();

    if (($_SESSION['admin_role'] ?? '') === 'teacher') {
        if (!teacher_has_student($conn, (int)$_SESSION['admin_id'], $student_id, $enrollment_id)) {
            $json_reply(['ok' => false, 'message' => 'Access denied.'], 403);
        }
    }

    $job_id = (int)($_POST['job_id'] ?? 0);
    if ($job_id <= 0) {
        $json_reply(['ok' => false, 'message' => 'Missing job_id.'], 400);
    }

    $job = eq_get_job($conn, $job_id);
    if (!$job || (int)$job['student_id'] !== $student_id) {
        $json_reply(['ok' => false, 'message' => 'Job not found.'], 404);
    }

    $json_reply([
        'ok'         => true,
        'job_id'     => $job_id,
        'status'     => $job['status'],
        'attempts'   => (int)$job['attempts'],
        'max'        => (int)$job['max_attempts'],
        'last_error' => $job['last_error'],
        'sent_at'    => $job['sent_at'],
    ]);
}

/* ── Load existing data ── */
$stmt = $conn->prepare("
    SELECT * FROM student_grades 
    WHERE student_id = ? AND school_year = ?
    ORDER BY FIELD(term, '1st Term', '2nd Term', '3rd Term') ASC, subject_name ASC
");
$stmt->bind_param("is", $student_id, $sy);
$stmt->execute();
$grades = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$has_t1 = false; $has_t2 = false; $has_t3 = false;
foreach ($grades as $g) {
    if ($g['term'] === '1st Term') $has_t1 = true;
    if ($g['term'] === '2nd Term') $has_t2 = true;
    if ($g['term'] === '3rd Term') $has_t3 = true;
}
$disable_t2 = (!$has_t1 && !$has_t2);
$disable_t3 = (!$has_t2 && !$has_t3);

$total_subjects = count($grades);
$passed_count = 0; $failed_count = 0; $total_grade = 0;
foreach ($grades as $g) {
    if ($g['grade'] !== null) {
        $total_grade += (float)$g['grade'];
        if ((float)$g['grade'] >= 75) $passed_count++;
        else $failed_count++;
    }
}
$gwa = $total_subjects > 0 ? round($total_grade / $total_subjects, 2) : 0;

/* Last-sent history */
$email_history = [];
$hq = $conn->prepare("
    SELECT meta, created_at
      FROM audit_log
     WHERE action IN ('grade.email_sent','grade.email_queued')
       AND target_type = 'student'
       AND target_id = ?
     ORDER BY created_at DESC
     LIMIT 30
");
if ($hq) {
    $hq->bind_param('i', $student_id);
    $hq->execute();
    $hres = $hq->get_result();
    while ($row = $hres->fetch_assoc()) {
        $meta = json_decode($row['meta'] ?? '', true);
        $t = is_array($meta) ? ($meta['term'] ?? '') : '';
        if ($t && !isset($email_history[$t])) {
            $email_history[$t] = strtotime($row['created_at']);
        }
    }
    $hq->close();
}

if (!function_exists('fmt_ago')) {
    function fmt_ago(int $ts): string {
        if ($ts <= 0) return '';
        $d = time() - $ts;
        if ($d < 60)        return 'just now';
        if ($d < 3600)      return floor($d / 60) . ' min ago';
        if ($d < 86400)     return floor($d / 3600) . ' hr ago';
        if ($d < 86400 * 7) return floor($d / 86400) . ' day' . (floor($d / 86400) === 1 ? '' : 's') . ' ago';
        return date('M d', $ts);
    }
}

$csrf = csrf_token();
$name = trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') .
        (!empty($student['middle_name']) ? ' ' . strtoupper(substr($student['middle_name'],0,1)) . '.' : ''));
$page_title = 'Grades • ' . $name;

$page_css = <<<'CSS'
    .term-segments { display:grid; grid-template-columns:repeat(3,1fr); gap:.3rem; background:var(--surface-2); padding:.3rem; border-radius:11px; border:1px solid var(--border); }
    .term-segments input[type=radio] { position:absolute; opacity:0; pointer-events:none; }
    .term-segments label { display:flex; align-items:center; justify-content:center; padding:.5rem .25rem; font-size:.82rem; font-weight:600; color:var(--text-2); border-radius:8px; cursor:pointer; transition:all .15s; user-select:none; text-align:center; margin:0; border:1px solid transparent; }
    .term-segments label:hover { color:var(--text); background:color-mix(in srgb, var(--accent) 8%, transparent); }
    .term-segments input[type=radio]:checked + label { background:var(--accent); color:#fff; box-shadow:0 2px 8px -2px rgba(79,70,229,.5); }
    .term-segments input[type=radio]:disabled + label { opacity:.4; cursor:not-allowed; background:transparent !important; color:var(--text-3) !important; box-shadow:none !important; text-decoration:line-through; }

    .grade-input-wrap { position:relative; }
    .grade-input-wrap .form-control { padding-right:78px; font-size:1.05rem; font-weight:700; font-variant-numeric:tabular-nums; transition:border-color .15s, box-shadow .15s; }
    .grade-input-wrap .grade-badge { position:absolute; right:8px; top:50%; transform:translateY(-50%); font-size:.68rem; font-weight:700; padding:.25rem .6rem; border-radius:999px; pointer-events:none; display:none; text-transform:uppercase; letter-spacing:.4px; }
    .grade-input-wrap.has-value .grade-badge { display:inline-block; }
    .grade-input-wrap.is-pass .form-control { border-color:var(--green); box-shadow:0 0 0 3px color-mix(in srgb, var(--green) 16%, transparent); background:var(--surface); }
    .grade-input-wrap.is-pass .grade-badge { background:var(--green-soft); color:var(--green); }
    .grade-input-wrap.is-fail .form-control { border-color:var(--red); box-shadow:0 0 0 3px color-mix(in srgb, var(--red) 16%, transparent); background:var(--surface); }
    .grade-input-wrap.is-fail .grade-badge { background:var(--red-soft); color:var(--red); }

    .term-group-header { background:var(--surface-2); padding:.65rem 1.25rem; font-size:.8rem; font-weight:700; color:var(--text-2); text-transform:uppercase; letter-spacing:.6px; border-top:1px solid var(--border); border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; gap:.75rem; flex-wrap:wrap; }
    .table-admin tbody tr:last-child td { border-bottom:1px solid var(--border); }

    .send-grades-btn { transition:background .15s, color .15s, border-color .15s, transform .1s; }
    .send-grades-btn:not(:disabled):active { transform:scale(.97); }
    .send-grades-btn.is-queued     { background:var(--amber-soft); border-color:var(--amber); color:var(--amber); }
    .send-grades-btn.is-processing { background:var(--accent-soft); border-color:var(--accent); color:var(--accent); }
    .send-grades-btn.is-sent       { background:var(--green) !important; border-color:var(--green) !important; color:#fff !important; }
    .send-grades-btn.is-error      { background:var(--red) !important;   border-color:var(--red) !important;   color:#fff !important; }
    .sent-meta { font-size:.66rem; color:var(--text-3); font-weight:600; white-space:nowrap; }

    #toastStack { position:fixed; top:1rem; right:1rem; z-index:9999; display:flex; flex-direction:column; gap:.55rem; max-width:min(420px, calc(100vw - 2rem)); pointer-events:none; }
    .toast { pointer-events:auto; display:flex; align-items:flex-start; gap:.7rem; padding:.9rem 1.1rem; border-radius:12px; color:#fff; font-weight:600; font-size:.88rem; line-height:1.4; box-shadow:0 12px 40px -8px rgba(15,23,42,.35); animation:toastIn .28s cubic-bezier(.4,0,.2,1); }
    .toast.is-success { background:linear-gradient(135deg,#10b981,#047857); }
    .toast.is-error   { background:linear-gradient(135deg,#ef4444,#b91c1c); }
    .toast.is-info    { background:linear-gradient(135deg,#3b82f6,#1e40af); }
    .toast.is-leaving { animation:toastOut .25s ease forwards; }
    .toast .ti-icon { font-size:1.05rem; margin-top:1px; flex-shrink:0; }
    .toast .ti-body { flex:1; min-width:0; }
    .toast .ti-title { font-weight:800; letter-spacing:-.01em; margin-bottom:.1rem; }
    .toast .ti-msg { font-weight:500; opacity:.95; word-break:break-word; }
    .toast .ti-close { background:transparent; border:none; color:#fff; opacity:.7; font-size:.85rem; cursor:pointer; padding:0; margin-left:.25rem; }
    .toast .ti-close:hover { opacity:1; }
    @keyframes toastIn  { from { opacity:0; transform:translateX(20px) scale(.96); } to { opacity:1; transform:none; } }
    @keyframes toastOut { to   { opacity:0; transform:translateX(24px) scale(.96); } }
CSS;

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/sidebar.php';
?>

<div id="toastStack" aria-live="polite" aria-atomic="false"></div>

<div class="main-content" id="mainContent">
  <?php if (isset($_SESSION['success'])): ?>
    <div class="flash success"><i class="bi bi-check-circle-fill"></i><span><?= htmlspecialchars($_SESSION['success']) ?></span><button class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php unset($_SESSION['success']); endif; ?>
  <?php if (isset($_SESSION['error'])): ?>
    <div class="flash error"><i class="bi bi-exclamation-triangle-fill"></i><span><?= htmlspecialchars($_SESSION['error']) ?></span><button class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php unset($_SESSION['error']); endif; ?>

  <div class="topbar">
    <div class="d-flex align-items-center gap-3">
      <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
      <div>
        <h2>Grades — <?= htmlspecialchars($name) ?></h2>
        <p class="sub"><?= htmlspecialchars($grade_level) ?> · <?= htmlspecialchars($student['section'] ?: 'No section') ?> · SY <?= htmlspecialchars($sy) ?></p>
      </div>
    </div>
    <a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$student_id ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i> Back to Profile
    </a>
  </div>

  <?php if ($total_subjects > 0): ?>
  <div class="stat-grid mb-4">
      <div class="stat-card tone-blue"><div class="stat-icon"><i class="bi bi-journal-bookmark-fill"></i></div><div class="stat-info"><div class="stat-value"><?= $total_subjects ?></div><div class="stat-label">Subjects Recorded</div></div></div>
      <div class="stat-card tone-green"><div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div><div class="stat-info"><div class="stat-value"><?= $passed_count ?></div><div class="stat-label">Passed</div></div></div>
      <div class="stat-card tone-red"><div class="stat-icon"><i class="bi bi-x-circle-fill"></i></div><div class="stat-info"><div class="stat-value"><?= $failed_count ?></div><div class="stat-label">Failed</div></div></div>
      <div class="stat-card tone-amber"><div class="stat-icon"><i class="bi bi-calculator-fill"></i></div><div class="stat-info"><div class="stat-value"><?= number_format($gwa, 2) ?></div><div class="stat-label">Current GWA</div></div></div>
  </div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-header"><h3><i class="bi bi-plus-circle"></i> Add / Update Grade</h3></div>
    <div class="card-body">
      <form method="POST" class="row g-3" id="gradeForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
        <input type="hidden" name="save_grade" value="1">

        <div class="col-md-3"><label class="form-label">Subject Code</label><input type="text" name="subject_code" class="form-control" maxlength="20" placeholder="e.g. MATH101" required autocomplete="off"></div>
        <div class="col-md-5"><label class="form-label">Subject Name</label><input type="text" name="subject_name" class="form-control" maxlength="100" placeholder="e.g. General Mathematics" required autocomplete="off"></div>
        <div class="col-md-4">
          <label class="form-label">Term</label>
          <div class="term-segments">
            <input type="radio" name="term" id="term_1" value="1st Term" checked><label for="term_1">1st Term</label>
            <input type="radio" name="term" id="term_2" value="2nd Term" <?= $disable_t2 ? 'disabled' : '' ?>><label for="term_2" <?= $disable_t2 ? 'title="Please add a 1st Term grade first."' : '' ?>>2nd Term</label>
            <input type="radio" name="term" id="term_3" value="3rd Term" <?= $disable_t3 ? 'disabled' : '' ?>><label for="term_3" <?= $disable_t3 ? 'title="Please add a 2nd Term grade first."' : '' ?>>3rd Term</label>
          </div>
        </div>
        <div class="col-md-4"><label class="form-label">Grade</label><div class="grade-input-wrap" id="gradeWrap"><input type="number" name="grade" id="gradeInput" class="form-control" min="0" max="100" step="0.01" placeholder="0 – 100" required autocomplete="off"><span class="grade-badge" id="gradeBadge"></span></div></div>
        <div class="col-md-4"><label class="form-label">Remarks</label><input type="text" name="remarks" id="remarksInput" class="form-control" placeholder="Auto-calculated" readonly style="background:var(--surface-2); cursor:not-allowed;"></div>
        <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit" style="height:42px; border-radius:10px;"><i class="bi bi-check-lg me-1"></i> Save Grade</button></div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3><i class="bi bi-journal-check"></i> Recorded Grades <span class="hint">(<?= $total_subjects ?>)</span></h3></div>
    <div class="card-body no-padding">
      <?php if (!$grades): ?>
        <div class="empty" style="padding:3rem 1.5rem;"><div class="empty-icon"><i class="bi bi-journal-x"></i></div><div class="empty-title">No grades yet</div><div class="empty-sub">Use the form above to record the first grade for this student.</div></div>
      <?php else: ?>
        <?php
        $grouped_grades = [];
        foreach ($grades as $g) { $grouped_grades[$g['term']][] = $g; }
        foreach ($grouped_grades as $term_name => $term_grades):
            $term_total = 0; $term_count = 0;
            foreach($term_grades as $tg) { if($tg['grade'] !== null) { $term_total += (float)$tg['grade']; $term_count++; } }
            $term_gwa = $term_count > 0 ? round($term_total / $term_count, 2) : null;
            $has_email = ($student_email !== '' && filter_var($student_email, FILTER_VALIDATE_EMAIL));
            $last_sent_ts = $email_history[$term_name] ?? 0;
        ?>
            <div class="term-group-header">
                <span><i class="bi bi-calendar-range me-2"></i> <?= htmlspecialchars($term_name) ?></span>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <?php if ($term_gwa !== null): ?>
                        <span class="pill primary" style="font-size:.7rem;">Term GWA: <?= number_format($term_gwa, 2) ?></span>
                    <?php endif; ?>

                    <?php if ($has_email): ?>
                        <form method="POST" class="m-0 email-grades-form" data-term="<?= htmlspecialchars($term_name) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="send_grades_email" value="1">
                            <input type="hidden" name="email_term" value="<?= htmlspecialchars($term_name) ?>">
                            <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success send-grades-btn" style="padding:.25rem .65rem;font-size:.72rem;border-radius:8px;text-transform:none;letter-spacing:0;font-weight:600;" data-term="<?= htmlspecialchars($term_name) ?>">
                                <i class="bi bi-envelope-paper me-1"></i> Email Grades
                            </button>
                        </form>
                        <span class="sent-meta" data-sent-term="<?= htmlspecialchars($term_name) ?>" <?= $last_sent_ts > 0 ? '' : 'style="display:none;"' ?>>
                            <?php if ($last_sent_ts > 0): ?><i class="bi bi-check2-all"></i> <?= htmlspecialchars(fmt_ago($last_sent_ts)) ?><?php endif; ?>
                        </span>
                    <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" style="padding:.25rem .65rem;font-size:.72rem;border-radius:8px;text-transform:none;letter-spacing:0;font-weight:600;" disabled title="Student has no email on file"><i class="bi bi-envelope-slash me-1"></i> No Email</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="table-responsive">
              <table class="table-admin">
                <thead><tr><th style="width:15%">Code</th><th style="width:45%">Subject Name</th><th style="width:15%">Grade</th><th style="width:15%">Remarks</th><th style="width:10%;text-align:right">Action</th></tr></thead>
                <tbody>
                  <?php foreach ($term_grades as $g): ?>
                    <tr>
                      <td><span class="pill primary" style="text-transform:uppercase"><?= htmlspecialchars($g['subject_code']) ?></span></td>
                      <td class="cell-strong"><?= htmlspecialchars($g['subject_name']) ?></td>
                      <td><span class="fs-5 fw-bold <?= ($g['grade'] < 75) ? 'text-danger' : 'text-success' ?>"><?= $g['grade'] !== null ? number_format((float)$g['grade'], 2) : '—' ?></span></td>
                      <td>
                        <?php if ($g['remarks'] === 'Passed'): ?><span class="pill success"><i class="bi bi-check-circle"></i> Passed</span>
                        <?php elseif ($g['remarks'] === 'Failed'): ?><span class="pill danger"><i class="bi bi-x-circle"></i> Failed</span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                      </td>
                      <td style="text-align:right">
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this grade?');">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                          <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
                          <input type="hidden" name="grade_id" value="<?= (int)$g['grade_id'] ?>">
                          <input type="hidden" name="delete_grade" value="1">
                          <button class="btn btn-icon btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
    const gradeInput   = document.getElementById('gradeInput');
    const remarksInput = document.getElementById('remarksInput');
    const gradeWrap    = document.getElementById('gradeWrap');
    const gradeBadge   = document.getElementById('gradeBadge');

    function updateRemarks() {
        const val = gradeInput.value.trim();
        const grade = parseFloat(val);
        if (val === '' || isNaN(grade)) {
            remarksInput.value = '';
            gradeWrap.classList.remove('has-value', 'is-pass', 'is-fail');
            gradeBadge.textContent = '';
            return;
        }
        gradeWrap.classList.add('has-value');
        if (grade >= 75) {
            remarksInput.value = 'Passed';
            gradeWrap.classList.add('is-pass'); gradeWrap.classList.remove('is-fail');
            gradeBadge.textContent = 'Passed';
        } else {
            remarksInput.value = 'Failed';
            gradeWrap.classList.add('is-fail'); gradeWrap.classList.remove('is-pass');
            gradeBadge.textContent = 'Failed';
        }
    }
    gradeInput.addEventListener('input', updateRemarks);
    updateRemarks();

    const stack = document.getElementById('toastStack');
    function toast(kind, title, message, duration = 4200) {
        const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        const el = document.createElement('div');
        el.className = `toast is-${kind}`;
        el.innerHTML = `
            <i class="ti-icon bi ${icons[kind] || 'bi-info-circle-fill'}"></i>
            <div class="ti-body"><div class="ti-title"></div><div class="ti-msg"></div></div>
            <button type="button" class="ti-close" aria-label="Dismiss"><i class="bi bi-x-lg"></i></button>`;
        el.querySelector('.ti-title').textContent = title;
        el.querySelector('.ti-msg').textContent = message;
        el.querySelector('.ti-close').addEventListener('click', () => dismiss(el));
        stack.appendChild(el);
        const t = setTimeout(() => dismiss(el), duration);
        el._timer = t;
    }
    function dismiss(el) {
        if (!el || el.classList.contains('is-leaving')) return;
        el.classList.add('is-leaving');
        clearTimeout(el._timer);
        setTimeout(() => el.remove(), 260);
    }

    document.querySelectorAll('.email-grades-form').forEach(form => {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn  = form.querySelector('.send-grades-btn');
            const term = form.dataset.term || 'term';
            const meta = document.querySelector(`.sent-meta[data-sent-term="${CSS.escape(term)}"]`);
            const studentInput = form.querySelector('input[name="student_id"]');
            const csrfInput    = form.querySelector('input[name="csrf_token"]');

            if (!confirm(`Send the ${term} grade report to the student's email address?\n\nOnce sent, it cannot be recalled.`)) {
                return;
            }

            const prevHTML = btn.innerHTML;
            btn.disabled = true;
            btn.classList.remove('is-sent', 'is-error', 'is-processing');
            btn.classList.add('is-queued');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Queueing…';

            const t0 = performance.now();
            const endpoint = window.location.pathname + window.location.search;

            try {
                const fd = new FormData(form);
                fd.append('respond', 'json');
                const res = await fetch(endpoint, {
                    method: 'POST', body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin', cache: 'no-store',
                });
                let data = null;
                try { data = await res.json(); } catch { data = null; }

                if (!res.ok || !data || !data.ok) {
                    throw new Error(data?.message || `Queue failed (HTTP ${res.status})`);
                }

                const queueMs = Math.round(performance.now() - t0);

                btn.classList.remove('is-queued');
                btn.classList.add('is-processing');
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Sending…';

                const jobId     = data.job_id;
                const csrf      = csrfInput.value;
                const studentId = studentInput.value;
                const pollMax   = 90000;
                const pollEvery = 1500;
                const pollStart = performance.now();
                let final = null;

                while (performance.now() - pollStart < pollMax) {
                    await new Promise(r => setTimeout(r, pollEvery));
                    const p = new FormData();
                    p.append('csrf_token', csrf);
                    p.append('email_status', '1');
                    p.append('job_id', jobId);
                    p.append('student_id', studentId);
                    p.append('respond', 'json');
                    const sres = await fetch(endpoint, {
                        method: 'POST', body: p,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin', cache: 'no-store',
                    });
                    const sdata = await sres.json().catch(() => null);
                    if (sdata && (sdata.status === 'sent' || sdata.status === 'failed')) {
                        final = sdata;
                        break;
                    }
                }

                const totalMs = Math.round(performance.now() - t0);

                if (final && final.status === 'sent') {
                    btn.classList.remove('is-processing');
                    btn.classList.add('is-sent');
                    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Sent';
                    toast('success', 'Grades sent',
                        `${term} → ${data.recipient} · queued ${queueMs}ms · delivered ${totalMs}ms`);
                    if (meta) {
                        meta.style.display = '';
                        meta.innerHTML = '<i class="bi bi-check2-all"></i> just now';
                    }
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.classList.remove('is-sent');
                        btn.classList.add('btn-outline-success');
                        btn.innerHTML = prevHTML;
                    }, 20000);
                } else if (final && final.status === 'failed') {
                    btn.disabled = false;
                    btn.classList.remove('is-processing');
                    btn.classList.add('is-error');
                    btn.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i> Retry';
                    toast('error', 'Email failed', final.last_error || 'Worker rejected the job.');
                    setTimeout(() => {
                        btn.classList.remove('is-error');
                        btn.classList.add('btn-outline-success');
                        btn.innerHTML = prevHTML;
                    }, 6000);
                } else {
                    btn.disabled = false;
                    btn.classList.remove('is-processing');
                    btn.classList.add('btn-outline-success');
                    btn.innerHTML = prevHTML;
                    toast('info', 'Still sending',
                        `Job #${jobId} is in the queue. It will deliver within a minute via the safety worker.`);
                }
            } catch (err) {
                btn.disabled = false;
                btn.classList.remove('is-queued', 'is-processing');
                btn.classList.add('is-error');
                btn.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i> Retry';
                toast('error', 'Could not queue', err.message || 'Network error.');
                setTimeout(() => {
                    btn.classList.remove('is-error');
                    btn.classList.add('btn-outline-success');
                    btn.innerHTML = prevHTML;
                }, 6000);
            }
        });
    });
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>