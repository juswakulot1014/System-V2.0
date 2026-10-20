<?php
// shared/pages/student_profile.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';

$__nav_section = 'students';

/* ── Detect AJAX Requests ── */
$is_ajax = (isset($_GET['ajax']) && $_GET['ajax'] === '1') || 
           (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');

/* ── Role gate (Allows teacher) ── */
if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin', 'teacher'], true)) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: ' . URL_PREFIX . '/dashboard.php');
    exit;
}

/* ═══════════════════════════════════════════════════════════
   Helpers
   ═══════════════════════════════════════════════════════════ */
if (!function_exists('strandInitials')) {
    function strandInitials(string $strand): string {
        $stopwords = ['and','of','the','for','in','on','to','a','an','at','by','with','or'];
        $words = preg_split('/\s+/', trim($strand)); $out = '';
        foreach ($words as $w) { $w = preg_replace('/[^A-Za-z0-9]/', '', $w); if ($w === '') continue; if (in_array(strtolower($w), $stopwords, true)) continue; $out .= strtoupper($w[0]); }
        return $out !== '' ? $out : 'GEN';
    }
}
if (!function_exists('formatStudentName')) {
    function formatStudentName(array $s): string {
        $mi  = !empty($s['middle_name']) ? ' ' . strtoupper(substr(trim($s['middle_name']), 0, 1)) . '.' : '';
        $ext = !empty($s['ext_name'])    ? ' ' . trim($s['ext_name']) : '';
        return trim(($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? '') . $mi . $ext);
    }
}

/* ═══════════════════════════════════════════════════════════
   POST: AUTO SECTION ASSIGNMENT (SECURED)
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_assign'])) {
    verify_csrf();
    
    // SECURITY FIX: Only Admin/Registrar can Auto-Assign
    if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)) {
        $_SESSION['error'] = 'Access denied. Only administrators can assign sections.';
        header('Location: ' . URL_PREFIX . '/student_profile.php'); exit();
    }

    $grade_level = trim((string)($_POST['grade_level'] ?? '')); $school_year = trim((string)($_POST['school_year'] ?? ''));
    if (empty($grade_level) || empty($school_year)) { $_SESSION['error'] = "Please select Grade Level and School Year."; } 
    else {
        $target_size = max(5, min(60, (int)($_POST['section_size'] ?? 35))); $replace_existing = !empty($_POST['replace_sections']); $max_sections = 26;
        $stmt = $conn->prepare("SELECT e.enrollment_id, e.strand, e.program, e.section, s.last_name, s.first_name, s.sex FROM enrollment_form e INNER JOIN students_info s ON e.student_id = s.student_id WHERE e.grade_level = ? AND e.school_year = ? AND COALESCE(e.status, 'Active') = 'Active' ORDER BY e.strand ASC, s.last_name ASC, s.first_name ASC");
        $stmt->bind_param("ss", $grade_level, $school_year); $stmt->execute(); $all_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        if (empty($all_rows)) { $_SESSION['info'] = "No active students found."; } 
        else {
            $groups = []; $skipped_groups = []; $skipped_students = 0;
            foreach ($all_rows as $r) { $strand = trim((string)($r['strand'] ?? '')); if ($strand === '') $strand = trim((string)($r['program'] ?? '')); if ($strand === '') $strand = 'General'; $groups[$strand][] = $r; }
            if (!$replace_existing) {
                foreach ($groups as $strand => $rows_in_group) {
                    $has_existing = false; foreach ($rows_in_group as $r) { if (!empty($r['section'])) { $has_existing = true; break; } }
                    if ($has_existing) { $skipped_groups[] = $strand; $skipped_students += count($rows_in_group); unset($groups[$strand]); }
                }
            }
            if (empty($groups)) { $_SESSION['info'] = "Nothing to assign."; } 
            else {
                $upd = $conn->prepare("UPDATE enrollment_form SET section = ? WHERE enrollment_id = ?");
                if (!$upd) { $_SESSION['error'] = "Prepare failed."; } 
                else {
                    $total_assigned = 0; $total_sections = 0; $capped_groups = []; $conn->begin_transaction();
                    try {
                        foreach ($groups as $strand => $students) {
                            $base = strandInitials($strand); if ($base === '') $base = 'GEN'; $males = []; $females = [];
                            foreach ($students as $s) { $sx = strtoupper(trim($s['sex'] ?? '')); if ($sx === 'M' || $sx === 'MALE') $males[] = $s; else $females[] = $s; }
                            $ordered = []; $mi = 0; $fi = 0; $n_m = count($males); $n_f = count($females); $n_total = $n_m + $n_f;
                            for ($i = 0; $i < $n_total; $i++) { $pick_male = false; if ($mi < $n_m && $fi < $n_f) { $pick_male = ($mi * $n_f) <= ($fi * $n_m); } elseif ($mi < $n_m) { $pick_male = true; } $ordered[] = $pick_male ? $males[$mi++] : $females[$fi++]; }
                            $n = count($ordered); $num_sections = (int)ceil($n / $target_size); if ($num_sections < 1) $num_sections = 1;
                            if ($num_sections > $max_sections) { $capped_groups[] = $base; $num_sections = $max_sections; }
                            $per_section = (int)ceil($n / $num_sections); $idx = 0;
                            for ($sec = 0; $sec < $num_sections; $sec++) {
                                $letter = chr(65 + $sec); $section_name = $base . ' ' . $letter;
                                for ($i = 0; $i < $per_section && $idx < $n; $i++, $idx++) { $upd->bind_param("si", $section_name, $ordered[$idx]['enrollment_id']); if (!$upd->execute()) throw new Exception("Failed to update section."); $total_assigned++; }
                                $total_sections++;
                            }
                        }
                        $upd->close(); $conn->commit(); audit_log($conn, 'section.auto_assign', ['type'=>'batch','grade'=>$grade_level,'sy'=>$school_year,'assigned'=>$total_assigned,'sections'=>$total_sections]);
                        $msg = "Auto-section complete: $total_assigned student(s) assigned to $total_sections section(s).";
                        if ($skipped_students > 0) $msg .= " Skipped $skipped_students student(s) with existing sections.";
                        $_SESSION['success'] = $msg;
                    } catch (Exception $e) { $conn->rollback(); $upd->close(); $_SESSION['error'] = "Auto-section failed: " . $e->getMessage(); }
                }
            }
        }
    }
    $redirect_url = URL_PREFIX . "/student_profile.php?grade_level=" . urlencode($grade_level) . "&school_year=" . urlencode($school_year);
    if ($is_ajax) { $redirect_url .= "&ajax=1"; }
    header("Location: " . $redirect_url); exit();
}

/* ═══════════════════════════════════════════════════════════
   DEFAULT SCHOOL YEAR & FILTERS
   ═══════════════════════════════════════════════════════════ */
// Fetch latest school year for default
$latest_sy_q = $conn->query("SELECT school_year FROM enrollment_form WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC LIMIT 1");
$default_sy = ($latest_sy_q && $row = $latest_sy_q->fetch_assoc()) ? $row['school_year'] : '';

$school_year = isset($_GET['school_year']) ? trim((string)$_GET['school_year']) : $default_sy;
$search        = trim((string)($_GET['search']       ?? ''));
$grade_level   = trim((string)($_GET['grade_level']  ?? ''));
$status        = trim((string)($_GET['status']       ?? ''));
$needs_section = isset($_GET['needs_section']) && $_GET['needs_section'] === '1';

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15; 
$offset   = ($page - 1) * $per_page;

$all_students = []; $total_students = 0; $total_pages = 0; $total_all_students = 0; $boys = 0; $girls = 0; $sec_count = 0; $unassigned_count = 0;

/* Build Base Query */
$sql = "SELECT s.student_id, s.student_id_number, s.lrn, s.last_name, s.first_name, s.middle_name, s.ext_name, s.sex, s.age, e.grade_level, e.school_year, e.section, e.strand, e.track, e.program, e.term, COALESCE(e.status, 'Active') AS status, COUNT(*) OVER() as total_count FROM students_info s INNER JOIN enrollment_form e ON s.student_id = e.student_id WHERE 1=1";
$params = []; $types = "";

if ($search !== '') { $like = "%$search%"; $sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)"; array_push($params, $like, $like, $like, $like); $types .= "ssss"; }
if ($grade_level !== '') { $sql .= " AND e.grade_level = ?"; $params[] = $grade_level; $types .= "s"; }
if ($school_year !== '') { $sql .= " AND e.school_year = ?"; $params[] = $school_year; $types .= "s"; }
if ($status === '' || $status === 'All') { $sql .= " AND COALESCE(e.status, 'Active') NOT IN ('Graduated', 'Pending', 'Promoted')"; } else { $sql .= " AND COALESCE(e.status, 'Active') = ?"; $params[] = $status; $types .= "s"; }
if ($needs_section) { $sql .= " AND (e.section IS NULL OR e.section = '')"; }

$sql .= " ORDER BY s.last_name ASC, s.first_name ASC LIMIT ? OFFSET ?";
$params[] = $per_page; $types .= "i"; $params[] = $offset; $types .= "i";

$stmt = $conn->prepare($sql); if (!empty($params)) $stmt->bind_param($types, ...$params); $stmt->execute(); $all_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $total_students = !empty($all_students) ? (int)$all_students[0]['total_count'] : 0; $total_pages = (int)ceil($total_students / $per_page); $stmt->close();

/* Global Stats */
$tsql = "SELECT COUNT(*) AS c FROM students_info s INNER JOIN enrollment_form e ON s.student_id = e.student_id WHERE COALESCE(e.status, 'Active') NOT IN ('Graduated', 'Pending', 'Promoted')";
$tp = []; $tt = "";
if ($search !== '') { $tlike = "%$search%"; $tsql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)"; array_push($tp, $tlike, $tlike, $tlike, $tlike); $tt .= "ssss"; }
if ($grade_level !== '') { $tsql .= " AND e.grade_level = ?"; $tp[] = $grade_level; $tt .= "s"; }
if ($school_year !== '') { $tsql .= " AND e.school_year = ?"; $tp[] = $school_year; $tt .= "s"; }
$ts = $conn->prepare($tsql); if (!empty($tp)) $ts->bind_param($tt, ...$tp); $ts->execute(); $ts_row = $ts->get_result()->fetch_assoc(); $ts->close(); $total_all_students = (int)($ts_row['c'] ?? 0);

$unassigned_row = $conn->query("SELECT COUNT(*) AS c FROM students_info s INNER JOIN enrollment_form e ON s.student_id = e.student_id WHERE (e.section IS NULL OR e.section = '') AND COALESCE(e.status, 'Active') NOT IN ('Graduated', 'Pending', 'Promoted')")->fetch_assoc(); $unassigned_count = (int)($unassigned_row['c'] ?? 0);

$gsql = "SELECT SUM(CASE WHEN UPPER(TRIM(s.sex)) IN ('MALE','M') THEN 1 ELSE 0 END) AS boys, SUM(CASE WHEN UPPER(TRIM(s.sex)) IN ('FEMALE','F') THEN 1 ELSE 0 END) AS girls FROM students_info s INNER JOIN enrollment_form e ON s.student_id = e.student_id WHERE COALESCE(e.status, 'Active') NOT IN ('Graduated', 'Pending', 'Promoted')";
$gp = []; $gt = "";
if ($search !== '')      { $gsql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ?)"; $gp = [$like, $like, $like]; $gt = "sss"; }
if ($grade_level !== '') { $gsql .= " AND e.grade_level = ?"; $gp[] = $grade_level; $gt .= "s"; }
if ($school_year !== '') { $gsql .= " AND e.school_year = ?"; $gp[] = $school_year; $gt .= "s"; }
$gs = $conn->prepare($gsql); if (!empty($gp)) $gs->bind_param($gt, ...$gp); $gs->execute(); $st = $gs->get_result()->fetch_assoc(); $gs->close(); $boys  = (int)($st['boys'] ?? 0); $girls = (int)($st['girls'] ?? 0);

$sec_count_row = $conn->query("SELECT COUNT(DISTINCT section) AS c FROM enrollment_form WHERE section IS NOT NULL AND section != '' AND COALESCE(status, 'Active') NOT IN ('Graduated', 'Pending', 'Promoted')")->fetch_assoc(); $sec_count = (int)($sec_count_row['c'] ?? 0);

$grade_levels_data = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form WHERE grade_level IS NOT NULL AND grade_level != '' ORDER BY grade_level ASC")->fetch_all(MYSQLI_ASSOC);
$school_years_data = $conn->query("SELECT DISTINCT school_year FROM enrollment_form WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC")->fetch_all(MYSQLI_ASSOC);
$possible_statuses = ['Active', 'Pending', 'Transferred', 'Stopped', 'Dropped', 'Graduated', 'Promoted'];
$base_url = "?search=" . urlencode($search) . "&grade_level=" . urlencode($grade_level) . "&school_year=" . urlencode($school_year) . "&status=" . urlencode($status) . ($needs_section ? "&needs_section=1" : "");
$csrf_token = csrf_token();

$page_title = 'Student Profiles • USAT Admin';

$page_css = <<<'CSS'
    .student-avatar-mini { width: 34px; height: 34px; border-radius: 10px; background: linear-gradient(135deg, var(--accent), var(--purple)); color: #fff; font-weight: 700; font-size: 0.75rem; display: inline-flex; align-items: center; justify-content: center; margin-right: 0.75rem; flex-shrink: 0; box-shadow: 0 2px 6px rgba(79, 70, 229, 0.3); }
    .student-info-cell { display: flex; align-items: center; min-width: 0; }
    .student-info-text { display: flex; flex-direction: column; min-width: 0; }
    .student-info-text .name { font-weight: 700; color: var(--text); font-size: 0.88rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .student-info-text .meta { font-size: 0.72rem; color: var(--text-2); font-variant-numeric: tabular-nums; margin-top: 0.15rem; }
    .tag-group { display: flex; gap: 0.35rem; flex-wrap: wrap; }
    .s-tag { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.68rem; font-weight: 700; border: 1px solid transparent; white-space: nowrap; }
    .s-tag i { font-size: 0.75rem; opacity: 0.8; }
    .s-tag.grade { background: #e0e7ff; color: #4338ca; border-color: #c7d2fe; }
    .s-tag.section { background: #dcfce7; color: #15803d; border-color: #bbf7d0; }
    .s-tag.section.unassigned { background: var(--amber-soft); color: var(--amber); border-color: var(--amber); }
    [data-bs-theme="dark"] .s-tag.grade { background: #1e1e38; color: #a5b4fc; border-color: #312e81; }
    [data-bs-theme="dark"] .s-tag.section { background: #052e16; color: #86efac; border-color: #166534; }
    [data-bs-theme="dark"] .s-tag.section.unassigned { background: #3d2a08; color: #fcd34d; border-color: #78350f; }
    .filter-bar { grid-template-columns: minmax(200px,1.6fr) minmax(140px,1fr) minmax(140px,1fr) minmax(140px,1fr) auto; }
    .pagination-wrap { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 0 0; margin-top: 1.5rem; border-top: 1px solid var(--border); flex-wrap: wrap; gap: 1rem; }
    .page-info-text { font-size: .85rem; color: var(--text-2); font-weight: 500; }
    .page-info-text strong { color: var(--text); }
    .pagination { display: inline-flex; align-items: center; gap: .25rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; padding: .25rem; }
    .page-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; border-radius: 8px; border: none; background: transparent; color: var(--text-2); font-size: .85rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: all .15s; }
    .page-btn:hover:not(.disabled) { background: var(--surface); color: var(--accent); box-shadow: 0 2px 6px rgba(0,0,0,.05); }
    .page-btn.disabled { opacity: .4; cursor: not-allowed; }
    .page-btn.active { background: var(--accent); color: #fff; }
    @media (max-width: 1280px) { .filter-bar { grid-template-columns: 1fr 1fr 1fr auto; } .filter-bar .actions { grid-column: 1 / -1; justify-content: flex-end; margin-top: .5rem; } }
    @media (max-width: 768px) { .filter-bar { grid-template-columns: 1fr 1fr; } .filter-bar .actions { grid-column: span 2; justify-content: stretch; } .filter-bar .actions .btn { flex: 1; } .pagination-wrap { flex-direction: column; justify-content: center; } }
CSS;

if (!$is_ajax) {
    require __DIR__ . '/../partials/head.php';
    require __DIR__ . '/../partials/sidebar.php';
    ?>
    <div class="main-content" id="mainContent">
        <div class="topbar">
            <div class="d-flex align-items-center gap-3">
                <button class="menu-toggle" id="menuToggle" aria-label="Open menu"><i class="bi bi-list fs-5"></i></button>
                <div>
                    <h2>Student Profiles</h2>
                    <p class="sub">Manage enrolled students, sections, and promotions</p>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <?php if (in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="openAutoAssignBtn" data-grade="<?= htmlspecialchars($grade_level) ?>" data-sy="<?= htmlspecialchars($school_year) ?>"><i class="bi bi-magic me-1"></i> Auto Assign</button>
                    <a href="<?= URL_PREFIX ?>/promote.php" class="btn btn-outline-warning btn-sm"><i class="bi bi-rocket-takeoff me-1"></i> Promote</a>
                    <a href="<?= URL_PREFIX ?>/graduates.php" class="btn btn-outline-success btn-sm"><i class="bi bi-mortarboard-fill me-1"></i> Graduates</a>
                <?php endif; ?>
                <a href="<?= URL_PREFIX ?>/sections_list.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-diagram-3 me-1"></i> Sections</a>
                <button class="theme-btn" id="themeToggle" title="Toggle theme"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>
            </div>
        </div>
        
        <!-- AJAX Container Starts Here -->
        <div id="ajax-container">
<?php } ?>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['success'])): ?><div class="flash success"><i class="bi bi-check-circle-fill"></i><span><?= htmlspecialchars($_SESSION['success']) ?></span><button class="btn-close" data-bs-dismiss="alert"></button></div><?php unset($_SESSION['success']); endif; ?>
            <?php if (isset($_SESSION['error'])): ?><div class="flash error"><i class="bi bi-exclamation-triangle-fill"></i><span><?= htmlspecialchars($_SESSION['error']) ?></span><button class="btn-close" data-bs-dismiss="alert"></button></div><?php unset($_SESSION['error']); endif; ?>
            <?php if (isset($_SESSION['info'])): ?><div class="flash info"><i class="bi bi-info-circle-fill"></i><span><?= htmlspecialchars($_SESSION['info']) ?></span><button class="btn-close" data-bs-dismiss="alert"></button></div><?php unset($_SESSION['info']); endif; ?>

            <?php if ($unassigned_count > 0 && !$needs_section): ?>
                <div class="flash" style="background: var(--amber-soft); color: var(--amber); border-color: var(--amber); margin-bottom: 1.5rem; border-left: 4px solid var(--amber);">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.25rem;"></i>
                    <div style="flex: 1;"><strong style="display: block; margin-bottom: .15rem;">Action Required: <?= $unassigned_count ?> student(s) need a section.</strong><span style="font-size: .85rem;">You can click the <strong>"Auto Assign"</strong> button at the top right, or click <a href="?needs_section=1" style="color: var(--amber); text-decoration: underline; font-weight: 700;">here to view them</a>.</span></div>
                    <a href="?needs_section=1" class="btn btn-outline-warning btn-sm" style="white-space: nowrap;">View Unassigned</a>
                </div>
            <?php elseif ($needs_section): ?>
                <div class="flash" style="background: var(--accent-soft); color: var(--accent); border-color: var(--accent); margin-bottom: 1.5rem;">
                    <i class="bi bi-funnel-fill"></i><span style="flex: 1;"><strong>Filter Active:</strong> Showing only students without a section.</span>
                    <a href="<?= URL_PREFIX ?>/student_profile.php" class="btn btn-outline-primary btn-sm reset-btn">Clear Filter</a>
                </div>
            <?php endif; ?>

            <div class="stat-grid">
                <div class="stat-card tone-blue"><div class="stat-icon"><i class="bi bi-people-fill"></i></div><div class="stat-info"><div class="stat-value"><?= number_format($total_all_students) ?></div><div class="stat-label">Total Students</div></div></div>
                <div class="stat-card tone-blue"><div class="stat-icon"><i class="bi bi-gender-male"></i></div><div class="stat-info"><div class="stat-value"><?= number_format($boys) ?></div><div class="stat-label">Male</div></div></div>
                <div class="stat-card tone-red"><div class="stat-icon"><i class="bi bi-gender-female"></i></div><div class="stat-info"><div class="stat-value"><?= number_format($girls) ?></div><div class="stat-label">Female</div></div></div>
                <a class="stat-card tone-purple <?= $needs_section ? 'is-active-filter' : '' ?>" href="?needs_section=<?= $needs_section ? '0' : '1' ?>" style="text-decoration: none; cursor: pointer; <?= $needs_section ? 'border: 2px solid var(--purple);' : '' ?>"><div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div><div class="stat-info"><div class="stat-value"><?= number_format($unassigned_count) ?></div><div class="stat-label">Need Section</div></div></a>
                <div class="stat-card tone-green"><div class="stat-icon"><i class="bi bi-diagram-3"></i></div><div class="stat-info"><div class="stat-value"><?= number_format($sec_count) ?></div><div class="stat-label">Sections</div></div></div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h3><i class="bi bi-funnel"></i> Filters</h3>
                    <?php if ($search || $grade_level || $school_year || $status || $needs_section): ?>
                        <span class="hint text-primary"><i class="bi bi-funnel-fill me-1"></i> Active filters applied</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form class="filter-bar" method="GET" id="filterForm">
                        <input type="hidden" name="page" value="1">
                        <?php if ($needs_section): ?><input type="hidden" name="needs_section" value="1"><?php endif; ?>
                        
                        <div class="field"><label for="f_search">Search</label><input type="text" id="f_search" name="search" placeholder="Name, LRN, or ID" value="<?= htmlspecialchars($search) ?>"></div>
                        
                        <div class="field">
                            <label for="f_grade">Grade Level</label>
                            <select id="f_grade" name="grade_level">
                                <option value="">All Grades</option>
                                <?php foreach($grade_levels_data as $gl): ?>
                                    <option value="<?= htmlspecialchars($gl['grade_level']) ?>" <?= $gl['grade_level']===$grade_level?'selected':'' ?>><?= htmlspecialchars($gl['grade_level']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="field">
                            <label for="f_sy">School Year</label>
                            <select id="f_sy" name="school_year">
                                <option value="">All Years</option>
                                <?php foreach($school_years_data as $sy): ?>
                                    <option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $sy['school_year']===$school_year?'selected':'' ?>><?= htmlspecialchars($sy['school_year']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="field">
                            <label for="f_status">Status</label>
                            <select id="f_status" name="status">
                                <option value="">All Active</option>
                                <?php foreach($possible_statuses as $st): ?>
                                    <option value="<?= $st ?>" <?= $st===$status?'selected':'' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="actions">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Filter</button>
                            <a href="<?= URL_PREFIX ?>/student_profile.php" class="btn btn-outline-secondary btn-sm reset-btn"><i class="bi bi-x-circle me-1"></i> Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="bi bi-people"></i> Student Directory <span class="hint">(<?= number_format($total_students) ?> result<?= $total_students === 1 ? '' : 's' ?>)</span></h3>
                    <?php if($school_year): ?>
                        <span class="pill info" style="margin-left:auto;"><i class="bi bi-calendar3"></i> SY <?= htmlspecialchars($school_year) ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body no-padding">
                    <?php if($total_students > 0): ?>
                        <div class="table-scroll">
                            <table class="table-admin">
                                <thead>
                                    <tr>
                                        <th style="width: 40%">Student Name</th>
                                        <th style="width: 20%">Grade &amp; Section</th>
                                        <th style="width: 20%">Strand</th>
                                        <th style="width: 10%">Status</th>
                                        <th style="width: 10%; text-align:right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($all_students as $s):
                                        $name = htmlspecialchars(formatStudentName($s));
                                        $initials = strtoupper(substr($s['first_name']??'',0,1).substr($s['last_name']??'',0,1));
                                        $st = $s['status'] ?? 'Active';
                                        $strand_txt = $s['strand'] ?? '—';
                                        $has_section = !empty($s['section']);
                                        $pillClass = match($st) {
                                            'Active'    => 'success',
                                            'Pending'   => 'warning',
                                            'Graduated' => 'purple',
                                            default     => 'danger'
                                        };
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="student-info-cell">
                                                <div class="student-avatar-mini"><?= htmlspecialchars($initials) ?></div>
                                                <div class="student-info-text">
                                                    <span class="name"><?= $name ?></span>
                                                    <span class="meta">LRN: <?= htmlspecialchars($s['lrn']??'—') ?> · ID: <?= htmlspecialchars($s['student_id_number']??'—') ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="tag-group">
                                                <span class="s-tag grade"><i class="bi bi-mortarboard"></i> <?= htmlspecialchars($s['grade_level']??'—') ?></span>
                                                <?php if (!$has_section): ?>
                                                    <span class="s-tag section unassigned"><i class="bi bi-exclamation-triangle-fill"></i> No Section</span>
                                                <?php else: ?>
                                                    <span class="s-tag section"><i class="bi bi-columns-gap"></i> <?= htmlspecialchars($s['section']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="cell-muted" style="font-size: 0.82rem;">
                                            <?= htmlspecialchars($strand_txt) ?>
                                        </td>
                                        <td>
                                            <span class="pill <?= $pillClass ?>" style="font-size: .7rem;"><?= htmlspecialchars($st) ?></span>
                                        </td>
                                        <td style="text-align:right">
                                            <a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$s['student_id'] ?>" class="btn btn-sm btn-outline-primary" title="View Profile">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <div class="pagination-wrap" style="padding: 1.25rem;">
                                <div class="page-info-text">Showing <strong><?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total_students)) ?></strong> of <strong><?= number_format($total_students) ?></strong></div>
                                <div class="pagination">
                                    <a href="<?= $base_url ?>&page=1" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-left"></i></a>
                                    <a href="<?= $base_url ?>&page=<?= max(1,$page-1) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-left"></i></a>
                                    <?php $start_page = max(1, $page - 2); $end_page = min($total_pages, $page + 2); for ($i = $start_page; $i <= $end_page; $i++): ?>
                                        <a href="<?= $base_url ?>&page=<?= $i ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                                    <?php endfor; ?>
                                    <a href="<?= $base_url ?>&page=<?= min($total_pages,$page+1) ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
                                    <a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-right"></i></a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="pagination-wrap" style="border-top: none; padding: 1rem 1.25rem;"><div class="page-info-text">Showing <strong>all <?= number_format($total_students) ?></strong> records</div></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty">
                            <div class="empty-icon"><i class="bi bi-search"></i></div>
                            <div class="empty-title">No students found</div>
                            <div class="empty-sub">Try adjusting your filters or clearing the search.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
<?php if (!$is_ajax) { ?>
        </div> <!-- end #ajax-container -->
    </div> <!-- end main-content -->

    <?php if (in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)): ?>
    <div class="modal fade aa-modal" id="autoAssignModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border: none; overflow: hidden; box-shadow: 0 20px 40px -10px rgba(0,0,0,0.2);">
                <form method="POST" action="<?= URL_PREFIX ?>/student_profile.php" id="autoAssignForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>"><input type="hidden" name="auto_assign" value="1">
                    <div class="aa-hero" style="background: linear-gradient(135deg, var(--accent), var(--purple)); color: #fff; padding: 1.5rem;">
                        <div class="d-flex align-items-center gap-3 mb-2"><div style="width: 42px; height: 42px; border-radius: 12px; background: rgba(255,255,255,.2); display: flex; align-items: center; justify-content: center; font-size: 1.25rem;"><i class="bi bi-magic"></i></div><h4 style="margin: 0; font-size: 1.1rem; font-weight: 700;">Auto Assign Sections</h4></div>
                        <p style="margin: 0; font-size: .85rem; opacity: .9;">Distribute students into balanced sections based on their strand.</p>
                    </div>
                    <div class="aa-body" style="padding: 1.5rem;">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6 pm-field"><label class="form-label" style="font-size: .75rem; font-weight: 700; color: var(--text-2); text-transform: uppercase;">Grade Level</label><select name="grade_level" id="aa_grade" class="form-select" required><option value="">Select Grade</option><?php foreach($grade_levels_data as $gl): ?><option value="<?= htmlspecialchars($gl['grade_level']) ?>"><?= htmlspecialchars($gl['grade_level']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6 pm-field"><label class="form-label" style="font-size: .75rem; font-weight: 700; color: var(--text-2); text-transform: uppercase;">School Year</label><select name="school_year" id="aa_sy" class="form-select" required><option value="">Select Year</option><?php foreach($school_years_data as $sy): ?><option value="<?= htmlspecialchars($sy['school_year']) ?>"><?= htmlspecialchars($sy['school_year']) ?></option><?php endforeach; ?></select></div>
                        </div>
                        <div class="pm-field mb-3"><label class="form-label" style="font-size: .75rem; font-weight: 700; color: var(--text-2); text-transform: uppercase;">Target Section Size</label><input type="number" name="section_size" id="aa_size" value="35" min="5" max="60" required class="form-control"><div class="hint" style="font-size: .72rem; color: var(--text-3); margin-top: .3rem;">Recommended: 30–40 students per section.</div></div>
                        <label class="aa-check" style="display: flex; align-items: flex-start; gap: .65rem; padding: .85rem 1rem; border: 1.5px solid var(--border); border-radius: 11px; background: var(--surface-2); cursor: pointer;"><input type="checkbox" name="replace_sections" id="aa_replace" value="1" style="margin-top:.2rem; width:16px; height:16px; accent-color:var(--accent);"><span class="aa-check-body"><span class="aa-check-title" style="display: block; font-size:.85rem; font-weight:700; color:var(--text); margin-bottom:.15rem;">Replace existing sections</span><span class="aa-check-desc" style="display: block; font-size:.75rem; color:var(--text-2); line-height:1.45;">By default, strands that already have sections are skipped. Check this to reassign everyone.</span></span></label>
                    </div>
                    <div class="pm-footer" style="padding: 1.15rem 1.5rem; background: var(--surface-2); border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: .75rem;"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" style="background: var(--accent); border: none;"><i class="bi bi-check-lg me-1"></i> Assign Sections</button></div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    (function () {
        const modalEl = document.getElementById('autoAssignModal'); if (!modalEl) return;
        const btnOpen = document.getElementById('openAutoAssignBtn'); const modal = new bootstrap.Modal(modalEl);
        btnOpen.addEventListener('click', function () { const grade = this.dataset.grade; const sy = this.dataset.sy; if (grade) document.getElementById('aa_grade').value = grade; if (sy) document.getElementById('aa_sy').value = sy; document.getElementById('aa_size').value = '35'; document.getElementById('aa_replace').checked = false; modal.show(); });
    })();

    document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('ajax-container');
        if (!container) return;

        function loadAjax(url) {
            container.style.opacity = '0.5';
            container.style.pointerEvents = 'none';
            
            const ajaxUrl = url + (url.includes('?') ? '&' : '?') + 'ajax=1';
            
            fetch(ajaxUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.text())
                .then(html => {
                    container.innerHTML = html;
                    container.style.opacity = '1';
                    container.style.pointerEvents = 'auto';
                })
                .catch(err => {
                    console.error('AJAX load failed', err);
                    window.location.href = url;
                });
        }

        container.addEventListener('click', function(e) {
            const link = e.target.closest('.pagination a, .reset-btn');
            if (!link) return;
            if (link.classList.contains('disabled')) return;
            
            e.preventDefault();
            let url = link.getAttribute('href');
            
            if (link.classList.contains('reset-btn')) {
                url = '<?= URL_PREFIX ?>/student_profile.php';
            }
            
            loadAjax(url);
            window.history.pushState(null, '', url);
        });

        container.addEventListener('submit', function(e) {
            if (e.target.matches('#filterForm')) {
                e.preventDefault();
                const formData = new FormData(e.target);
                const params = new URLSearchParams(formData);
                const cleanUrl = '?' + params.toString();
                loadAjax(cleanUrl);
                window.history.pushState(null, '', cleanUrl);
            }
        });

        window.addEventListener('popstate', function() {
            loadAjax(window.location.href);
        });

        const autoAssignForm = document.getElementById('autoAssignForm');
        if (autoAssignForm) {
            autoAssignForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const action = this.getAttribute('action');
                
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalHtml = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Assigning...';
                
                fetch(action, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(response => {
                    if (!response.ok) throw new Error('Server error');
                    if (response.redirected) {
                        let newUrl = response.url;
                        newUrl = newUrl.replace(/(\?|&)ajax=1/, ''); // Remove ajax param from address bar
                        window.history.pushState(null, '', newUrl);
                    }
                    return response.text();
                })
                .then(html => {
                    container.innerHTML = html;
                    const modalEl = document.getElementById('autoAssignModal');
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalHtml;
                })
                .catch(err => {
                    console.error('Auto assign failed', err);
                    window.location.reload();
                });
            });
        }
    });
    </script>

    <?php require __DIR__ . '/../partials/footer.php'; ?>
<?php } ?>