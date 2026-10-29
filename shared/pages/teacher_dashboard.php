<?php
// shared/pages/teacher_dashboard.php
// Dedicated teacher dashboard — scoped to the teacher's assigned sections only.
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../lib/teacher_scope.php';

$__nav_section = 'dashboard';

/* ── Hard gate: teachers only ── */
if (($_SESSION['admin_role'] ?? '') !== 'teacher') {
    header('Location: ' . URL_PREFIX . '/dashboard.php');
    exit;
}

$teacher_id = (int)$_SESSION['admin_id'];
$teacher_name = (string)($_SESSION['admin_name'] ?? 'Teacher');

/* ── Load all assignments ── */
$assignments = teacher_assigned_sections($conn, $teacher_id);

/* ── Available school years (from assignments) ── */
$available_sys = [];
foreach ($assignments as $a) {
    if ($a['school_year'] !== '') $available_sys[$a['school_year']] = true;
}
$available_sys = array_keys($available_sys);
rsort($available_sys);

/* ── Selected SY: default latest, override via ?sy= ── */
$selected_sy = trim((string)($_GET['sy'] ?? ''));
if (!in_array($selected_sy, $available_sys, true)) {
    $selected_sy = $available_sys[0] ?? '';
}

/* ── Filter assignments to selected SY ── */
$active_assignments = [];
foreach ($assignments as $a) {
    if ($a['school_year'] === $selected_sy) $active_assignments[] = $a;
}

/* ── Per-class stats ── */
$classes = [];
$total_students    = 0;
$total_with_grades = 0;
$total_grade_rows  = 0;
$total_missing     = 0;

foreach ($active_assignments as $a) {
    $args = [$a['section'], $a['grade_level'], $a['school_year'], $a['program']];

    // Total students in this cluster
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT e.student_id) AS c
          FROM enrollment_form e
         WHERE e.section = ?
           AND e.grade_level = ?
           AND e.school_year = ?
           AND COALESCE(e.program,'') = ?
           AND COALESCE(e.status,'Active') NOT IN ('Graduated','Pending','Promoted')
    ");
    $stmt->bind_param('ssss', ...$args);
    $stmt->execute();
    $student_count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    // Students with at least one grade row in this SY
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT e.student_id) AS c
          FROM enrollment_form e
         WHERE e.section = ?
           AND e.grade_level = ?
           AND e.school_year = ?
           AND COALESCE(e.program,'') = ?
           AND COALESCE(e.status,'Active') NOT IN ('Graduated','Pending','Promoted')
           AND EXISTS (
               SELECT 1 FROM student_grades sg
                WHERE sg.student_id = e.student_id
                  AND sg.school_year = e.school_year
           )
    ");
    $stmt->bind_param('ssss', ...$args);
    $stmt->execute();
    $with_grades = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    // Total grade rows recorded in this cluster
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
          FROM student_grades sg
         WHERE sg.school_year = ?
           AND sg.student_id IN (
               SELECT e.student_id FROM enrollment_form e
                WHERE e.section = ?
                  AND e.grade_level = ?
                  AND e.school_year = ?
                  AND COALESCE(e.program,'') = ?
                  AND COALESCE(e.status,'Active') NOT IN ('Graduated','Pending','Promoted')
           )
    ");
    $grade_args = [$a['school_year'], $a['section'], $a['grade_level'], $a['school_year'], $a['program']];
    $stmt->bind_param('sssss', ...$grade_args);
    $stmt->execute();
    $grade_rows = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $missing = max(0, $student_count - $with_grades);

    $total_students    += $student_count;
    $total_with_grades += $with_grades;
    $total_grade_rows  += $grade_rows;
    $total_missing     += $missing;

    $classes[] = [
        'section'     => $a['section'],
        'grade_level' => $a['grade_level'],
        'school_year' => $a['school_year'],
        'program'     => $a['program'],
        'students'    => $student_count,
        'with_grades' => $with_grades,
        'missing'     => $missing,
        'grade_rows'  => $grade_rows,
    ];
}

/* ── Recent grades (Paginated) ── */
$recent_grades = [];
$total_grades = 0;
$total_pages_grades = 1;
$per_page_grades = 10;
$page_grades = max(1, (int)($_GET['page_grades'] ?? 1));
$offset_grades = ($page_grades - 1) * $per_page_grades;

$student_ids = teacher_student_ids($conn, $teacher_id);
if (!empty($student_ids) && $selected_sy !== '') {
    $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
    $types = str_repeat('i', count($student_ids)) . 's';
    $params = array_merge($student_ids, [$selected_sy]);

    // 1. Get total count for pagination
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c 
          FROM student_grades 
         WHERE student_id IN ($placeholders) 
           AND school_year = ?
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total_grades = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $total_pages_grades = max(1, (int)ceil($total_grades / $per_page_grades));
    if ($page_grades > $total_pages_grades) {
        $page_grades = $total_pages_grades;
        $offset_grades = ($page_grades - 1) * $per_page_grades;
    }

    // 2. Fetch the paginated results
    $stmt = $conn->prepare("
        SELECT sg.grade_id, sg.subject_code, sg.subject_name, sg.term,
               sg.grade, sg.remarks, sg.grade_level,
               s.student_id, s.last_name, s.first_name, s.student_id_number
          FROM student_grades sg
          JOIN students_info s ON s.student_id = sg.student_id
         WHERE sg.student_id IN ($placeholders)
           AND sg.school_year = ?
         ORDER BY sg.grade_id DESC
         LIMIT ? OFFSET ?
    ");
    
    $types_limit = $types . 'ii';
    $params_limit = array_merge($params, [$per_page_grades, $offset_grades]);
    
    $stmt->bind_param($types_limit, ...$params_limit);
    $stmt->execute();
    $recent_grades = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* ── Helpers ── */
if (!function_exists('td_initials')) {
    function td_initials(string $first, string $last): string {
        return strtoupper(substr(trim($first), 0, 1) . substr(trim($last), 0, 1));
    }
}
if (!function_exists('td_format_name')) {
    function td_format_name(array $s): string {
        $mi  = !empty($s['middle_name']) ? ' ' . strtoupper(substr(trim($s['middle_name']), 0, 1)) . '.' : '';
        return trim(($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? '') . $mi);
    }
}

// Time-based greeting
$hour = (int)date('H');
if ($hour < 12) $greeting = 'Good morning';
elseif ($hour < 18) $greeting = 'Good afternoon';
else $greeting = 'Good evening';

$page_title = 'My Teaching Dashboard • USAT';
$page_css = <<<'CSS'
    /* ── Header Greeting ── */
    .greeting-text { font-size: 0.95rem; color: var(--text-2); font-weight: 500; margin-bottom: 0.25rem; }
    .greeting-text span { color: var(--accent); font-weight: 700; }

    /* ── Quick Actions (Horizontal) ── */
    .qa-grid { 
        display: grid; 
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); 
        gap: 1rem; 
    }
    .qa-tile {
        display: flex; flex-direction: row; align-items: center; justify-content: flex-start;
        gap: 1rem; padding: 1.25rem 1.5rem;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: 16px; text-decoration: none; color: var(--text);
        transition: all 0.2s; text-align: left;
    }
    .qa-tile:hover {
        background: var(--surface-2); border-color: var(--accent);
        transform: translateY(-2px); color: var(--accent);
        box-shadow: 0 8px 16px -8px rgba(79,70,229,.2);
    }
    .qa-icon {
        width: 48px; height: 48px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.35rem; background: var(--accent-soft); color: var(--accent);
        transition: transform 0.2s; flex-shrink: 0;
    }
    .qa-tile:hover .qa-icon { transform: scale(1.1); }
    .qa-label { font-size: 0.95rem; font-weight: 700; line-height: 1.2; }

    /* ── Class Cards Grid ── */
    .class-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 1.25rem;
    }
    .class-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        overflow: hidden;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
        position: relative;
        box-shadow: 0 4px 12px rgba(15,23,42,0.03);
    }
    .class-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 32px rgba(15,23,42,0.1);
        border-color: color-mix(in srgb, var(--accent) 30%, var(--border));
    }
    .class-card-head {
        padding: 1.25rem 1.5rem;
        background: linear-gradient(135deg, var(--surface) 0%, var(--surface-2) 100%);
        border-bottom: 1px solid var(--border);
        position: relative;
    }
    .class-card-head::before {
        content: '';
        position: absolute;
        left: 0; top: 0; bottom: 0; width: 4px;
        background: linear-gradient(180deg, var(--accent), var(--purple));
        border-radius: 4px 0 0 4px;
    }
    .class-name {
        font-size: 1.25rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: var(--text);
        margin: 0 0 0.25rem;
    }
    .class-meta {
        font-size: 0.75rem;
        color: var(--text-2);
        display: flex;
        gap: 1rem;
        font-weight: 500;
    }
    .class-meta span { display: inline-flex; align-items: center; gap: 0.35rem; }
    .class-meta i { color: var(--accent); font-size: 0.85rem; }

    .class-body {
        padding: 1.5rem;
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    /* ── SVG Circular Progress ── */
    .progress-section {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.5rem;
    }
    .circular-progress {
        position: relative;
        width: 72px;
        height: 72px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .circular-progress svg {
        width: 72px;
        height: 72px;
        transform: rotate(-90deg);
    }
    .circular-progress circle {
        fill: none;
        stroke-width: 8;
        stroke-linecap: round;
    }
    .circular-progress .bg { stroke: var(--surface-2); }
    .circular-progress .fg { 
        stroke-dasharray: 201;
        stroke-dashoffset: 201;
        transition: stroke-dashoffset 1s ease-out;
    }
    .circular-progress .fg.full { stroke: var(--green); }
    .circular-progress .fg.partial { stroke: var(--accent); }
    .circular-progress .fg.empty { stroke: var(--amber); }
    .circular-progress .pct-text {
        position: absolute;
        font-size: 0.85rem;
        font-weight: 800;
        color: var(--text);
        font-variant-numeric: tabular-nums;
    }

    .class-stat-block { text-align: right; }
    .class-stat-label { font-size: 0.65rem; font-weight: 800; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.5px; }
    .class-stat-value { font-size: 1.4rem; font-weight: 800; color: var(--text); line-height: 1.1; }
    .class-stat-value.is-missing { color: var(--amber); }

    .class-actions {
        display: flex;
        gap: 0.65rem;
        margin-top: auto;
    }
    .class-actions .btn {
        flex: 1;
        justify-content: center;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.6rem 1rem;
        font-size: 0.8rem;
        font-weight: 700;
        border-radius: 10px;
        transition: all 0.2s;
    }
    .class-actions .btn-outline-secondary:hover { background: var(--surface-2); color: var(--accent); border-color: var(--accent); }
    .class-actions .btn-primary { background: linear-gradient(135deg, var(--accent), var(--purple)); border: none; box-shadow: 0 4px 12px -4px rgba(79,70,229,.4); }
    .class-actions .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px -4px rgba(79,70,229,.6); }

    /* ── Modern Feed / Recent Grades ── */
    .feed-list { display: flex; flex-direction: column; }
    .feed-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border);
        transition: background 0.15s;
    }
    .feed-item:last-child { border-bottom: none; }
    .feed-item:hover { background: var(--surface-2); }
    
    .feed-avatar {
        width: 42px; height: 42px; border-radius: 12px;
        background: linear-gradient(135deg, var(--accent-soft), var(--purple-soft));
        color: var(--accent); font-weight: 800; font-size: 0.9rem;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .feed-body { flex: 1; min-width: 0; }
    .feed-title { font-weight: 700; color: var(--text); font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .feed-sub { font-size: 0.75rem; color: var(--text-2); margin-top: 0.15rem; display: flex; gap: 0.4rem; align-items: center; }
    .feed-sub span { display: inline-flex; align-items: center; gap: 0.2rem; }
    .feed-sub .dot { width: 3px; height: 3px; border-radius: 50%; background: var(--text-3); margin: 0 0.2rem; }
    
    .feed-grade {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 50px; padding: 0.3rem 0.6rem; border-radius: 8px;
        font-weight: 800; font-size: 0.9rem; font-variant-numeric: tabular-nums;
        flex-shrink: 0;
    }
    .feed-grade.pass { background: var(--green-soft); color: var(--green); }
    .feed-grade.fail { background: var(--red-soft); color: var(--red); }
    .feed-grade.none { background: var(--surface-2); color: var(--text-3); }

    /* ── Empty State ── */
    .teacher-empty {
        background: var(--surface);
        border: 1px dashed var(--border-strong);
        border-radius: var(--radius-xl);
        padding: 4rem 2rem;
        text-align: center;
        margin: 2rem auto;
        max-width: 600px;
    }
    .teacher-empty-icon {
        width: 80px; height: 80px; margin: 0 auto 1.5rem;
        border-radius: 50%; background: var(--accent-soft); color: var(--accent);
        display: flex; align-items: center; justify-content: center;
        font-size: 2.2rem; box-shadow: 0 0 0 8px color-mix(in srgb, var(--accent) 8%, transparent);
    }
    .teacher-empty h3 { font-size: 1.3rem; font-weight: 800; margin-bottom: 0.5rem; color: var(--text); }
    .teacher-empty p { color: var(--text-2); font-size: 0.95rem; line-height: 1.6; margin-bottom: 1.5rem; }

    /* ── SY filter pill row ── */
    .sy-pill-row {
        display: inline-flex; align-items: center; gap: 0.5rem;
        padding: 0.4rem 0.4rem 0.4rem 1rem;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: 999px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .sy-pill-row label { font-size: 0.75rem; font-weight: 700; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.5px; margin: 0; }
    .sy-pill-row select {
        background: var(--surface-2); border: 1px solid var(--border); border-radius: 999px;
        padding: 0.4rem 1rem; font-size: 0.85rem; font-weight: 700; color: var(--text);
        font-family: inherit; cursor: pointer; outline: none; transition: all 0.15s;
    }
    .sy-pill-row select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent); }
CSS;

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/sidebar.php';
?>

<div class="main-content" id="mainContent">

    <!-- Topbar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle" aria-label="Open menu">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <div class="greeting-text"><?= $greeting ?>, <span><?= htmlspecialchars($teacher_name) ?></span></div>
                <h2>My Teaching Dashboard</h2>
                <?php if ($selected_sy !== '' && !empty($classes)): ?>
                    <p class="sub">You have <strong><?= count($classes) ?></strong> active class<?= count($classes) === 1 ? '' : 'es' ?> for SY <?= htmlspecialchars($selected_sy) ?>.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <?php if (count($available_sys) > 1): ?>
                <form method="GET" class="sy-pill-row">
                    <label for="sySelect"><i class="bi bi-calendar3 me-1"></i> SY</label>
                    <select id="sySelect" name="sy" onchange="this.form.submit()">
                        <?php foreach ($available_sys as $sy): ?>
                            <option value="<?= htmlspecialchars($sy) ?>" <?= $sy === $selected_sy ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sy) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <?php if (empty($assignments)): ?>

        <!-- ── NO ASSIGNMENTS AT ALL ── -->
        <div class="teacher-empty">
            <div class="teacher-empty-icon"><i class="bi bi-person-workspace"></i></div>
            <h3>No sections assigned yet</h3>
            <p>
                You're not assigned to any sections at the moment. Once the registrar assigns you a section,
                your students, their grades, and their rosters will show up here.
            </p>
            <a href="<?= URL_PREFIX ?>/profile.php" class="btn btn-outline-primary btn-sm px-4 py-2">
                <i class="bi bi-person-circle me-1"></i> View My Profile
            </a>
        </div>

    <?php elseif (empty($classes)): ?>

        <!-- ── ASSIGNMENTS EXIST BUT NONE FOR THIS SY ── -->
        <div class="teacher-empty">
            <div class="teacher-empty-icon"><i class="bi bi-calendar-x"></i></div>
            <h3>No classes for SY <?= htmlspecialchars($selected_sy ?: '—') ?></h3>
            <p>
                You have assignments in other school years. Pick a different year from the selector above,
                or contact the registrar if you believe this is incorrect.
            </p>
        </div>

    <?php else: ?>

        <!-- ── STAT CARDS ── -->
        <div class="stat-grid">
            <div class="stat-card tone-blue">
                <div class="stat-icon"><i class="bi bi-diagram-3-fill"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format(count($classes)) ?></div>
                    <div class="stat-label">My Classes</div>
                    <div class="stat-hint">For SY <?= htmlspecialchars($selected_sy) ?></div>
                </div>
            </div>
            <div class="stat-card tone-purple">
                <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($total_students) ?></div>
                    <div class="stat-label">My Students</div>
                    <div class="stat-hint"><?= number_format($total_with_grades) ?> with grades</div>
                </div>
            </div>
            <div class="stat-card tone-green">
                <div class="stat-icon"><i class="bi bi-journal-check"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($total_grade_rows) ?></div>
                    <div class="stat-label">Grades Recorded</div>
                    <div class="stat-hint">Across all my students</div>
                </div>
            </div>
            <div class="stat-card <?= $total_missing > 0 ? 'tone-amber' : 'tone-green' ?>">
                <div class="stat-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($total_missing) ?></div>
                    <div class="stat-label">Missing Grades</div>
                    <div class="stat-hint">
                        <?= $total_missing > 0 ? 'Students needing grades' : 'All caught up' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── QUICK ACTIONS (Moved Above Sections) ── -->
        <div class="card mb-4">
            <div class="card-header">
                <h3><i class="bi bi-lightning-charge-fill text-warning"></i> Quick Actions</h3>
            </div>
            <div class="card-body">
                <div class="qa-grid">
                    <a href="<?= URL_PREFIX ?>/grades.php" class="qa-tile">
                        <div class="qa-icon"><i class="bi bi-journal-plus"></i></div>
                        <div class="qa-label">Manage Grades</div>
                    </a>
                    <a href="<?= URL_PREFIX ?>/student_profile.php" class="qa-tile">
                        <div class="qa-icon"><i class="bi bi-people-fill"></i></div>
                        <div class="qa-label">My Students</div>
                    </a>
                    <a href="<?= URL_PREFIX ?>/sections_list.php" class="qa-tile">
                        <div class="qa-icon"><i class="bi bi-diagram-3-fill"></i></div>
                        <div class="qa-label">My Sections</div>
                    </a>
                    <a href="<?= URL_PREFIX ?>/profile.php" class="qa-tile">
                        <div class="qa-icon"><i class="bi bi-person-badge-fill"></i></div>
                        <div class="qa-label">My Profile</div>
                    </a>
                </div>
            </div>
        </div>

        <!-- ── MY CLASSES (Sections) ── -->
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h3 class="m-0 fw-bold fs-5"><i class="bi bi-collection-fill text-primary me-2"></i>My Classes</h3>
            <a href="<?= URL_PREFIX ?>/grades.php" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                Manage All Grades <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
        
        <div class="class-grid mb-4">
            <?php foreach ($classes as $c):
                $pct = $c['students'] > 0 ? min(100, (int)round(($c['with_grades'] / $c['students']) * 100)) : 0;
                
                if ($c['with_grades'] === 0)      $fill = 'empty';
                elseif ($c['with_grades'] >= $c['students']) $fill = 'full';
                else                              $fill = 'partial';

                $dashoffset = 201 - (201 * $pct / 100);

                $roster_url = URL_PREFIX . '/sections_list.php?' . http_build_query(array_filter([
                    'section'     => $c['section'],
                    'grade_level' => $c['grade_level'],
                    'school_year' => $c['school_year'],
                    'program'     => $c['program'],
                ]));
                $grades_url = URL_PREFIX . '/grades.php?' . http_build_query(array_filter([
                    'section'     => $c['section'],
                    'grade_level' => $c['grade_level'],
                ]));
            ?>
                <div class="class-card">
                    <div class="class-card-head">
                        <div class="class-name"><?= htmlspecialchars($c['section']) ?></div>
                        <div class="class-meta">
                            <span><i class="bi bi-mortarboard-fill"></i> <?= htmlspecialchars($c['grade_level']) ?></span>
                            <span><i class="bi bi-calendar-range"></i> SY <?= htmlspecialchars($c['school_year']) ?></span>
                        </div>
                    </div>
                    <div class="class-body">
                        
                        <div class="progress-section">
                            <div class="circular-progress">
                                <svg viewBox="0 0 72 72">
                                    <circle class="bg" cx="36" cy="36" r="32"></circle>
                                    <circle class="fg <?= $fill ?>" cx="36" cy="36" r="32" style="stroke-dashoffset: <?= $dashoffset ?>"></circle>
                                </svg>
                                <div class="pct-text"><?= $pct ?>%</div>
                            </div>
                            <div class="class-stat-block">
                                <div class="class-stat-label">Students</div>
                                <div class="class-stat-value"><?= number_format($c['students']) ?></div>
                                <div class="class-stat-label mt-2">Missing Grades</div>
                                <div class="class-stat-value <?= $c['missing'] > 0 ? 'is-missing' : '' ?>">
                                    <?= number_format($c['missing']) ?>
                                </div>
                            </div>
                        </div>

                        <div class="class-actions">
                            <a href="<?= htmlspecialchars($roster_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-people"></i> Roster
                            </a>
                            <a href="<?= htmlspecialchars($grades_url, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">
                                <i class="bi bi-journal-plus"></i> Grades
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ── RECENT GRADES (Now Full Width) ── -->
        <div class="card">
            <div class="card-header">
                <h3><i class="bi bi-clock-history"></i> Recent Grades</h3>
                <span class="hint">Total: <?= number_format($total_grades) ?> records</span>
            </div>
            <div class="card-body no-padding">
                <?php if (empty($recent_grades)): ?>
                    <div class="empty py-5">
                        <div class="empty-icon"><i class="bi bi-journal-x"></i></div>
                        <div class="empty-title">No grades recorded yet</div>
                        <div class="empty-sub">Grades you record for your students will appear here.</div>
                    </div>
                <?php else: ?>
                    <div class="feed-list">
                    <?php foreach ($recent_grades as $g):
                        $gv = $g['grade'] !== null ? (float)$g['grade'] : null;
                        if ($gv === null)          $chip = 'none';
                        elseif ($gv >= 75)         $chip = 'pass';
                        else                       $chip = 'fail';
                        $initials = td_initials($g['first_name'] ?? '', $g['last_name'] ?? '');
                    ?>
                        <div class="feed-item">
                            <div class="feed-avatar"><?= htmlspecialchars($initials) ?></div>
                            <div class="feed-body">
                                <div class="feed-title"><?= htmlspecialchars(td_format_name($g)) ?></div>
                                <div class="feed-sub">
                                    <span class="text-accent"><i class="bi bi-book"></i> <?= htmlspecialchars($g['subject_name']) ?></span>
                                    <span class="dot"></span>
                                    <span><?= htmlspecialchars($g['term']) ?></span>
                                    <span class="dot"></span>
                                    <span>ID: <?= htmlspecialchars($g['student_id_number'] ?: '—') ?></span>
                                </div>
                            </div>
                            <div class="feed-grade <?= $chip ?>">
                                <?= $gv !== null ? number_format($gv, 2) : '—' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>

                    <!-- Pagination Controls -->
                    <?php if ($total_pages_grades > 1): ?>
                    <div class="d-flex justify-content-between align-items-center p-3 border-top">
                        <span class="text-muted" style="font-size:.82rem;">
                            Showing <?= number_format($offset_grades + 1) ?>–<?= number_format(min($offset_grades + $per_page_grades, $total_grades)) ?> of <?= number_format($total_grades) ?>
                        </span>
                        <div class="pagination" style="display:inline-flex; align-items:center; gap:.25rem; background:var(--surface-2); border:1px solid var(--border); border-radius:10px; padding:.25rem;">
                            <?php $base_url_grades = '?sy=' . urlencode($selected_sy) . '&page_grades='; ?>
                            <a href="<?= $base_url_grades . max(1, $page_grades - 1) ?>" class="btn btn-sm <?= $page_grades <= 1 ? 'disabled' : '' ?>" style="border:none; color:var(--text-2);">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                            <span class="px-2" style="font-size:.82rem; font-weight:600; color:var(--text);">Page <?= $page_grades ?> of <?= $total_pages_grades ?></span>
                            <a href="<?= $base_url_grades . min($total_pages_grades, $page_grades + 1) ?>" class="btn btn-sm <?= $page_grades >= $total_pages_grades ? 'disabled' : '' ?>" style="border:none; color:var(--text-2);">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>