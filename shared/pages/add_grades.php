<?php
// shared/pages/add_grades.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../lib/teacher_scope.php';   // PATCH 1: helper

$__nav_section = 'grades';

/* ── Role gate ── */
if (!in_array($_SESSION['admin_role'] ?? '', ['teacher', 'registrar', 'superadmin'], true)) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: ' . URL_PREFIX . '/dashboard.php'); exit;
}

$student_id = (int)($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
if ($student_id <= 0) { $_SESSION['error'] = 'No student specified.'; header('Location: ' . URL_PREFIX . '/student_profile.php'); exit; }

/* Resolve the student + active enrollment */
$stmt = $conn->prepare("
    SELECT s.student_id, s.student_id_number, s.lrn, s.last_name, s.first_name, s.middle_name, s.ext_name,
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

/* PATCH 2: teacher scope gate */
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

/* ═══════════════════════════════════════════════════════════
   POST — Save a grade (Insert or Update)
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grade'])) {
    verify_csrf();
    $subject_code = trim((string)($_POST['subject_code'] ?? ''));
    $subject_name = trim((string)($_POST['subject_name'] ?? ''));
    $term         = trim((string)($_POST['term'] ?? ''));
    $grade        = (float)($_POST['grade'] ?? -1);
    
    // AUTOMATED REMARKS
    $remarks      = ($grade >= 75) ? 'Passed' : 'Failed';

    $errors = [];
    if ($subject_code === '' || mb_strlen($subject_code) > 20) $errors[] = 'Subject Code is required (max 20 chars).';
    if ($subject_name === '' || mb_strlen($subject_name) > 100) $errors[] = 'Subject Name is required (max 100 chars).';
    if ($term === '' || mb_strlen($term) > 50) $errors[] = 'Term is required.';
    if ($grade < 0 || $grade > 100) $errors[] = 'Grade must be between 0 and 100.';

    if ($errors) {
        $_SESSION['error'] = implode(' ', $errors);
    } else {
        // Check if this grade already exists to update it (Upsert logic)
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

/* Fetch existing grades */
$stmt = $conn->prepare("
    SELECT * FROM student_grades 
    WHERE student_id = ? AND school_year = ?
    ORDER BY FIELD(term, '1st Term', '2nd Term', '3rd Term') ASC, subject_name ASC
");
$stmt->bind_param("is", $student_id, $sy);
$stmt->execute();
$grades = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ── Check Term Availability ── */
$has_t1 = false;
$has_t2 = false;
$has_t3 = false;
foreach ($grades as $g) {
    if ($g['term'] === '1st Term') $has_t1 = true;
    if ($g['term'] === '2nd Term') $has_t2 = true;
    if ($g['term'] === '3rd Term') $has_t3 = true;
}
// 2nd Term unlocks if there is a 1st Term grade (or if they already have 2nd Term grades)
$disable_t2 = (!$has_t1 && !$has_t2);
// 3rd Term unlocks if there is a 2nd Term grade (or if they already have 3rd Term grades)
$disable_t3 = (!$has_t2 && !$has_t3);

/* Calculate Stats */
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

$csrf = csrf_token();
$name = trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') .
        (!empty($student['middle_name']) ? ' ' . strtoupper(substr($student['middle_name'],0,1)) . '.' : ''));
$page_title = 'Grades • ' . $name;

$page_css = <<<'CSS'
    /* Term Segments Styling */
    .term-segments { display:grid; grid-template-columns:repeat(3,1fr); gap:.3rem; background:var(--surface-2); padding:.3rem; border-radius:11px; border:1px solid var(--border); }
    .term-segments input[type=radio] { position:absolute; opacity:0; pointer-events:none; }
    .term-segments label { display:flex; align-items:center; justify-content:center; padding:.5rem .25rem; font-size:.82rem; font-weight:600; color:var(--text-2); border-radius:8px; cursor:pointer; transition:all .15s; user-select:none; text-align:center; margin:0; border:1px solid transparent; }
    .term-segments label:hover { color:var(--text); background:color-mix(in srgb, var(--accent) 8%, transparent); }
    .term-segments input[type=radio]:checked + label { background:var(--accent); color:#fff; box-shadow:0 2px 8px -2px rgba(79,70,229,.5); }
    
    /* Disabled Term Styling */
    .term-segments input[type=radio]:disabled + label { 
        opacity: 0.4; 
        cursor: not-allowed; 
        background: transparent !important; 
        color: var(--text-3) !important; 
        box-shadow: none !important; 
        text-decoration: line-through;
    }

    /* Grade Input Wrapper */
    .grade-input-wrap { position:relative; }
    .grade-input-wrap .form-control { padding-right:78px; font-size:1.05rem; font-weight:700; font-variant-numeric:tabular-nums; transition:border-color .15s, box-shadow .15s; }
    .grade-input-wrap .grade-badge { position:absolute; right:8px; top:50%; transform:translateY(-50%); font-size:.68rem; font-weight:700; padding:.25rem .6rem; border-radius:999px; pointer-events:none; display:none; text-transform:uppercase; letter-spacing:.4px; }
    
    .grade-input-wrap.has-value .grade-badge { display:inline-block; }
    
    .grade-input-wrap.is-pass .form-control { border-color:var(--green); box-shadow:0 0 0 3px color-mix(in srgb, var(--green) 16%, transparent); background:var(--surface); }
    .grade-input-wrap.is-pass .grade-badge { background:var(--green-soft); color:var(--green); }
    
    .grade-input-wrap.is-fail .form-control { border-color:var(--red); box-shadow:0 0 0 3px color-mix(in srgb, var(--red) 16%, transparent); background:var(--surface); }
    .grade-input-wrap.is-fail .grade-badge { background:var(--red-soft); color:var(--red); }

    /* Term Group Headers */
    .term-group-header { background:var(--surface-2); padding:.65rem 1.25rem; font-size:.8rem; font-weight:700; color:var(--text-2); text-transform:uppercase; letter-spacing:.6px; border-top:1px solid var(--border); border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
    
    .table-admin tbody tr:last-child td { border-bottom:1px solid var(--border); }
CSS;

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/sidebar.php';
?>

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
        <p class="sub">
          <?= htmlspecialchars($grade_level) ?>
          · <?= htmlspecialchars($student['section'] ?: 'No section') ?>
          · SY <?= htmlspecialchars($sy) ?>
        </p>
      </div>
    </div>
    <a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$student_id ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i> Back to Profile
    </a>
  </div>

  <!-- Grade Summary Stats -->
  <?php if ($total_subjects > 0): ?>
  <div class="stat-grid mb-4">
      <div class="stat-card tone-blue">
          <div class="stat-icon"><i class="bi bi-journal-bookmark-fill"></i></div>
          <div class="stat-info"><div class="stat-value"><?= $total_subjects ?></div><div class="stat-label">Subjects Recorded</div></div>
      </div>
      <div class="stat-card tone-green">
          <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
          <div class="stat-info"><div class="stat-value"><?= $passed_count ?></div><div class="stat-label">Passed</div></div>
      </div>
      <div class="stat-card tone-red">
          <div class="stat-icon"><i class="bi bi-x-circle-fill"></i></div>
          <div class="stat-info"><div class="stat-value"><?= $failed_count ?></div><div class="stat-label">Failed</div></div>
      </div>
      <div class="stat-card tone-amber">
          <div class="stat-icon"><i class="bi bi-calculator-fill"></i></div>
          <div class="stat-info"><div class="stat-value"><?= number_format($gwa, 2) ?></div><div class="stat-label">Current GWA</div></div>
      </div>
  </div>
  <?php endif; ?>

  <!-- Add/Update Form -->
  <div class="card mb-4">
    <div class="card-header"><h3><i class="bi bi-plus-circle"></i> Add / Update Grade</h3></div>
    <div class="card-body">
      <form method="POST" class="row g-3" id="gradeForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
        <input type="hidden" name="save_grade" value="1">

        <div class="col-md-3">
          <label class="form-label">Subject Code</label>
          <input type="text" name="subject_code" class="form-control" maxlength="20" placeholder="e.g. MATH101" required autocomplete="off">
        </div>
        <div class="col-md-5">
          <label class="form-label">Subject Name</label>
          <input type="text" name="subject_name" class="form-control" maxlength="100" placeholder="e.g. General Mathematics" required autocomplete="off">
        </div>
        <div class="col-md-4">
          <label class="form-label">Term</label>
          <div class="term-segments">
            <!-- 1st Term is always enabled -->
            <input type="radio" name="term" id="term_1" value="1st Term" checked>
            <label for="term_1">1st Term</label>

            <!-- 2nd Term -->
            <input type="radio" name="term" id="term_2" value="2nd Term" <?= $disable_t2 ? 'disabled' : '' ?>>
            <label for="term_2" <?= $disable_t2 ? 'title="Please add a 1st Term grade first."' : '' ?>>2nd Term</label>

            <!-- 3rd Term -->
            <input type="radio" name="term" id="term_3" value="3rd Term" <?= $disable_t3 ? 'disabled' : '' ?>>
            <label for="term_3" <?= $disable_t3 ? 'title="Please add a 2nd Term grade first."' : '' ?>>3rd Term</label>
          </div>
        </div>
        
        <div class="col-md-4">
          <label class="form-label">Grade</label>
          <div class="grade-input-wrap" id="gradeWrap">
            <input type="number" name="grade" id="gradeInput" class="form-control" min="0" max="100" step="0.01" placeholder="0 – 100" required autocomplete="off">
            <span class="grade-badge" id="gradeBadge"></span>
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Remarks</label>
          <!-- Readonly because it's automated -->
          <input type="text" name="remarks" id="remarksInput" class="form-control" placeholder="Auto-calculated" readonly style="background:var(--surface-2); cursor:not-allowed;">
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <button class="btn btn-primary w-100" type="submit" style="height:42px; border-radius:10px;">
            <i class="bi bi-check-lg me-1"></i> Save Grade
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Recorded Grades List -->
  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-journal-check"></i> Recorded Grades <span class="hint">(<?= $total_subjects ?>)</span></h3>
    </div>
    <div class="card-body no-padding">
      <?php if (!$grades): ?>
        <div class="empty" style="padding:3rem 1.5rem;">
            <div class="empty-icon"><i class="bi bi-journal-x"></i></div>
            <div class="empty-title">No grades yet</div>
            <div class="empty-sub">Use the form above to record the first grade for this student.</div>
        </div>
      <?php else: ?>
        
        <?php 
        // Group grades by Term for better readability
        $grouped_grades = [];
        foreach ($grades as $g) { $grouped_grades[$g['term']][] = $g; }
        
        foreach ($grouped_grades as $term_name => $term_grades): 
            $term_total = 0; $term_count = 0;
            foreach($term_grades as $tg) { if($tg['grade'] !== null) { $term_total += (float)$tg['grade']; $term_count++; } }
            $term_gwa = $term_count > 0 ? round($term_total / $term_count, 2) : null;
        ?>
            <div class="term-group-header">
                <span><i class="bi bi-calendar-range me-2"></i> <?= htmlspecialchars($term_name) ?></span>
                <?php if ($term_gwa !== null): ?>
                    <span class="pill primary" style="font-size:.7rem;">Term GWA: <?= number_format($term_gwa, 2) ?></span>
                <?php endif; ?>
            </div>
            
            <div class="table-responsive">
              <table class="table-admin">
                <thead>
                  <tr>
                    <th style="width:15%">Code</th>
                    <th style="width:45%">Subject Name</th>
                    <th style="width:15%">Grade</th>
                    <th style="width:15%">Remarks</th>
                    <th style="width:10%;text-align:right">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($term_grades as $g): ?>
                    <tr>
                      <td><span class="pill primary" style="text-transform:uppercase"><?= htmlspecialchars($g['subject_code']) ?></span></td>
                      <td class="cell-strong"><?= htmlspecialchars($g['subject_name']) ?></td>
                      <td>
                        <span class="fs-5 fw-bold <?= ($g['grade'] < 75) ? 'text-danger' : 'text-success' ?>">
                          <?= $g['grade'] !== null ? number_format((float)$g['grade'], 2) : '—' ?>
                        </span>
                      </td>
                      <td>
                        <?php if ($g['remarks'] === 'Passed'): ?>
                          <span class="pill success"><i class="bi bi-check-circle"></i> Passed</span>
                        <?php elseif ($g['remarks'] === 'Failed'): ?>
                          <span class="pill danger"><i class="bi bi-x-circle"></i> Failed</span>
                        <?php else: ?>
                          <span class="text-muted">—</span>
                        <?php endif; ?>
                      </td>
                      <td style="text-align:right">
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this grade?');">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                          <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
                          <input type="hidden" name="grade_id" value="<?= (int)$g['grade_id'] ?>">
                          <input type="hidden" name="delete_grade" value="1">
                          <button class="btn btn-icon btn-outline-danger" title="Delete">
                            <i class="bi bi-trash"></i>
                          </button>
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
// AUTOMATE REMARKS ON FRONTEND
document.addEventListener('DOMContentLoaded', function() {
    const gradeInput = document.getElementById('gradeInput');
    const remarksInput = document.getElementById('remarksInput');
    const gradeWrap = document.getElementById('gradeWrap');
    const gradeBadge = document.getElementById('gradeBadge');

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
            gradeWrap.classList.add('is-pass');
            gradeWrap.classList.remove('is-fail');
            gradeBadge.textContent = 'Passed';
        } else {
            remarksInput.value = 'Failed';
            gradeWrap.classList.add('is-fail');
            gradeWrap.classList.remove('is-pass');
            gradeBadge.textContent = 'Failed';
        }
    }

    // Listen for typing
    gradeInput.addEventListener('input', updateRemarks);
    
    // Initial check in case the browser autofilled
    updateRemarks();
});
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>