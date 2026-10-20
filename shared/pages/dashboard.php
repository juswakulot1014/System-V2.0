<?php
// shared/pages/dashboard.php
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';

$__nav_section     = 'dashboard';

/* ── Detect AJAX Requests ── */
$is_ajax = (isset($_GET['ajax']) && $_GET['ajax'] === '1') || 
           (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');

// Determine label based on folder
$__dashboard_label = 'Admin';
if (str_contains(URL_PREFIX, '/registrar')) $__dashboard_label = 'Registrar';
if (str_contains(URL_PREFIX, '/teacher'))   $__dashboard_label = 'Teacher';

const DASH_PAGE_SIZE         = 10;
const DASH_EXPORT_MAX        = 5000;
const DASH_LIVE_INTERVAL_MS  = 20000;
const DASH_HIDDEN            = "'Graduated','Pending','Promoted'";
const DASH_VISIBLE           = "COALESCE(le.status, 'Active') NOT IN (" . DASH_HIDDEN . ")";

const DASH_BASE_FROM = "
    FROM students_info s
    LEFT JOIN (
        SELECT e.*
        FROM enrollment_form e
        JOIN (
            SELECT student_id, MAX(enrollment_id) AS max_id
            FROM enrollment_form
            GROUP BY student_id
        ) m ON m.max_id = e.enrollment_id
    ) le ON le.student_id = s.student_id
";
const DASH_BASE_VIS_ONLY = DASH_BASE_FROM . " WHERE " . DASH_VISIBLE;

const DASH_DIR_COLUMNS = "s.student_id, s.first_name, s.last_name, s.middle_name, s.lrn,
                          s.student_id_number, s.sex, le.grade_level, le.school_year,
                          le.strand, le.program, le.section, COALESCE(le.status, 'Active') AS status";

function dash_e(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function dash_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if ($stmt === false) { error_log('[dashboard] prepare failed: ' . $conn->error); return []; }
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute(); $res = $stmt->get_result(); $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close(); return $rows;
}

function dash_scalar(mysqli $conn, string $sql, string $types = '', array $params = []): int {
    $rows = dash_rows($conn, $sql, $types, $params); $row = $rows[0] ?? []; return (int)(reset($row) ?: 0);
}

function dash_vis(string $extra = ''): string { return DASH_BASE_VIS_ONLY . ($extra !== '' ? " AND $extra" : ''); }
function dash_get(string $key): string { $v = $_GET[$key] ?? ''; return is_string($v) ? trim($v) : ''; }

function dash_url(array $changes = []): string {
    $q = array_merge($_GET, $changes);
    foreach ($q as $k => $v) { if ($v === null || $v === '' || $v === false) unset($q[$k]); }
    return '?' . http_build_query($q);
}

function dash_csv_safe($v): string { $s = (string)$v; return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s; }

function dash_snapshot(mysqli $conn): array {
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $s = dash_rows($conn, "SELECT COUNT(*) AS total, COALESCE(SUM(s.sex = 'Male'), 0) AS boys, COALESCE(SUM(s.sex = 'Female'), 0) AS girls, COALESCE(SUM(le.status IS NULL OR le.status = 'Active'), 0) AS active, COALESCE(SUM(le.section IS NULL OR le.section = ''), 0) AS no_section, COUNT(DISTINCT NULLIF(le.section, '')) AS sections, COALESCE(SUM(s.created_at >= ?), 0) AS new_week " . DASH_BASE_VIS_ONLY, 's', [$week_start])[0] ?? [];
    $grade_rows = dash_rows($conn, "SELECT le.grade_level AS label, COUNT(*) AS cnt " . dash_vis("le.grade_level IS NOT NULL AND le.grade_level <> ''") . " GROUP BY le.grade_level ORDER BY le.grade_level");
    $strand_rows = dash_rows($conn, "SELECT COALESCE(NULLIF(le.strand, ''), 'Unassigned') AS label, COUNT(*) AS cnt " . dash_vis() . " GROUP BY label ORDER BY cnt DESC");
    $recent_rows = dash_rows($conn, "SELECT s.student_id, s.first_name, s.last_name, s.lrn, s.student_id_number, le.grade_level, le.strand, le.section, le.enrolled_at " . dash_vis() . " ORDER BY le.enrolled_at DESC LIMIT 10");
    $recent = [];
    foreach ($recent_rows as $r) {
        $recent[] = [
            'student_id' => (int)$r['student_id'], 'student_id_number' => (string)($r['student_id_number'] ?? ''),
            'name' => trim(($r['last_name'] ?? '') . ', ' . ($r['first_name'] ?? '')), 'lrn' => (string)($r['lrn'] ?? ''),
            'grade_level' => (string)($r['grade_level'] ?? ''), 'strand' => (string)($r['strand'] ?? ''),
            'section' => (string)($r['section'] ?? ''), 'enrolled_label' => !empty($r['enrolled_at']) ? date('M d, Y', strtotime($r['enrolled_at'])) : '',
        ];
    }
    return [
        'server_time' => date('c'), 'server_time_label' => date('M d, Y g:i:s A'),
        'stats' => ['total' => (int)($s['total'] ?? 0), 'boys' => (int)($s['boys'] ?? 0), 'girls' => (int)($s['girls'] ?? 0), 'active' => (int)($s['active'] ?? 0), 'no_section' => (int)($s['no_section'] ?? 0), 'sections' => (int)($s['sections'] ?? 0), 'new_week' => (int)($s['new_week'] ?? 0)],
        'pending' => dash_scalar($conn, "SELECT COUNT(DISTINCT student_id) FROM enrollment_form WHERE status = 'Pending'"),
        'grade' => ['labels' => array_column($grade_rows, 'label'), 'counts' => array_map('intval', array_column($grade_rows, 'cnt'))],
        'strand' => ['labels' => array_column($strand_rows, 'label'), 'counts' => array_map('intval', array_column($strand_rows, 'cnt'))],
        'recent' => $recent,
    ];
}

if (dash_get('action') === 'live') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(dash_snapshot($conn), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$snap = dash_snapshot($conn);

/* ═══════════════════════════════════════════════════════════
   DEFAULT SCHOOL YEAR LOGIC
   ═══════════════════════════════════════════════════════════ */
$default_sy = '';
$latest_sy_q = $conn->query("SELECT school_year FROM enrollment_form WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC LIMIT 1");
if ($latest_sy_q && $latest_sy_row = $latest_sy_q->fetch_assoc()) {
    $default_sy = $latest_sy_row['school_year'];
}

if (!isset($_GET['school_year'])) {
    $f_sy = $default_sy;
} else {
    $f_sy = trim((string)$_GET['school_year']);
}

$f_search = dash_get('search'); $f_grade  = dash_get('grade_level'); $f_strand = dash_get('strand'); $f_needs  = dash_get('needs_section') === '1';

// SORTS: Defaulted to grade with a secondary sort by name
$SORTS = [
    'name' => 's.last_name {d}, s.first_name {d}', 
    'lrn' => 's.lrn {d}', 
    'id' => 's.student_id_number {d}', 
    'grade' => 'le.grade_level {d}, s.last_name ASC, s.first_name ASC', 
    'section' => 'le.section {d}, le.grade_level ASC',
    'strand' => 'le.strand {d}, le.grade_level ASC',
    'status' => 'COALESCE(le.status, \'Active\') {d}, le.grade_level ASC'
];

$sort_in = dash_get('sort'); $sort = isset($SORTS[$sort_in]) ? $sort_in : 'grade'; $dir_lc = strtolower(dash_get('dir')) === 'desc' ? 'desc' : 'asc'; $dir_sql = strtoupper($dir_lc);

$where  = [DASH_VISIBLE]; $types  = ''; $params = [];
if ($f_search !== '') { $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)"; $like = '%' . addcslashes($f_search, '%_\\') . '%'; array_push($params, $like, $like, $like, $like); $types  .= 'ssss'; }
if ($f_grade !== '')  { $where[] = 'le.grade_level = ?'; $params[] = $f_grade;  $types .= 's'; }
if ($f_sy !== '')     { $where[] = 'le.school_year = ?'; $params[] = $f_sy;     $types .= 's'; }
if ($f_strand !== '') { $where[] = 'le.strand = ?';      $params[] = $f_strand; $types .= 's'; }
if ($f_needs)         { $where[] = "(le.section IS NULL OR le.section = '')"; }
$where_sql = 'WHERE ' . implode(' AND ', $where); $order_sql = str_replace('{d}', $dir_sql, $SORTS[$sort]);

if (dash_get('export') === 'csv') {
    $rows = dash_rows($conn, "SELECT " . DASH_DIR_COLUMNS . " " . DASH_BASE_FROM . " $where_sql ORDER BY $order_sql, s.student_id ASC LIMIT ?", $types . 'i', array_merge($params, [DASH_EXPORT_MAX]));
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="students_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Student ID', 'LRN', 'Last Name', 'First Name', 'Middle Name', 'Sex', 'Grade Level', 'School Year', 'Strand', 'Program', 'Section', 'Status']);
    foreach ($rows as $r) { fputcsv($out, array_map('dash_csv_safe', [$r['student_id_number'], $r['lrn'], $r['last_name'], $r['first_name'], $r['middle_name'], $r['sex'], $r['grade_level'], $r['school_year'], $r['strand'], $r['program'], $r['section'], $r['status']])); }
    fclose($out); exit;
}

$school_years = dash_rows($conn, "SELECT DISTINCT le.school_year AS v " . dash_vis("le.school_year IS NOT NULL AND le.school_year <> ''") . " ORDER BY v DESC");
$strands = dash_rows($conn, "SELECT DISTINCT le.strand AS v " . dash_vis("le.strand IS NOT NULL AND le.strand <> ''") . " ORDER BY v");

$total_rows  = dash_scalar($conn, "SELECT COUNT(*) " . DASH_BASE_FROM . " $where_sql", $types, $params);
$total_pages = max(1, (int)ceil($total_rows / DASH_PAGE_SIZE));
$page        = max(1, min($total_pages, (int)($_GET['page'] ?? 1)));
$offset      = ($page - 1) * DASH_PAGE_SIZE;

$students = dash_rows($conn, "SELECT " . DASH_DIR_COLUMNS . " " . DASH_BASE_FROM . " $where_sql ORDER BY $order_sql, s.student_id ASC LIMIT ? OFFSET ?", $types . 'ii', array_merge($params, [DASH_PAGE_SIZE, $offset]));

$range_from = $total_rows === 0 ? 0 : $offset + 1; $range_to = min($offset + DASH_PAGE_SIZE, $total_rows);
$JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

$page_title = $__dashboard_label . ' Dashboard • USAT Admin';
$page_head  = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>';
$page_css   = '
    /* --- LAYOUT FIXES TO PREVENT HORIZONTAL OVERFLOW --- */
    .main-content {
        min-width: 0; 
        flex: 1;
        overflow-x: hidden;
    }
    
    .topbar {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap; 
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    /* --- CHARTS & CARDS --- */
    .chart-wrap { position: relative; height: 260px; width: 100%; }
    .chart-wrap canvas { max-height: 100%; }
    .chart-empty { height: 260px; display: flex; align-items: center; justify-content: center; color: var(--text-3); font-size: .9rem; }
    .card { max-width: 100%; overflow: hidden; }

    /* --- FILTER BAR --- */
    .filter-bar { grid-template-columns: minmax(200px,1.4fr) minmax(140px,1fr) minmax(140px,1fr) minmax(140px,1fr) auto; }
    .dash-quick { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1rem; align-items: center; }
    .dash-quick .badge-count { background: rgba(255,255,255,.25); border-radius: 999px; padding: 0 .5rem; margin-left: .35rem; }
    
    /* --- STAT CARDS --- */
    .stat-card.is-link { text-decoration: none; color: inherit; cursor: pointer; }
    .stat-card.is-link:hover { transform: translateY(-1px); }
    .stat-card.is-active-filter { outline: 2px solid var(--accent, #4f46e5); }
    
    /* --- TABLES & PAGINATION --- */
    .sort-link { color: inherit; text-decoration: none; white-space: nowrap; display: inline-flex; align-items: center; gap: 0.25rem; }
    .sort-link:hover { text-decoration: underline; color: var(--accent); }
    
    .sort-icon { font-size: 0.75em; transition: opacity 0.2s; }
    .sort-icon.active { opacity: 1; color: var(--accent); }
    .sort-icon.inactive { opacity: 0.2; }

    .dash-pager { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; padding: .9rem 1.25rem; border-top: 1px solid var(--border); }
    .dash-pager .pages { display: flex; align-items: center; gap: .6rem; }
    .dash-pager .pg-btn { padding: .35rem .8rem; border: 1px solid var(--border); border-radius: 8px; text-decoration: none; color: inherit; font-size: .85rem; }
    .dash-pager .pg-btn.disabled { opacity: .45; pointer-events: none; }
    .dash-pager .muted-note { color: var(--text-3); font-size: .85rem; }
    
    /* --- LIVE INDICATORS --- */
    .live-status { display: inline-flex; align-items: center; gap: .45rem; font-size: .8rem; color: var(--text-3); white-space: nowrap; }
    .live-dot { width: 8px; height: 8px; border-radius: 50%; background: #9ca3af; display: inline-block; }
    .live-dot.live { background: #10b981; box-shadow: 0 0 0 0 rgba(16,185,129,.6); animation: livePulse 1.8s infinite; }
    .live-dot.offline { background: #ef4444; }
    @keyframes livePulse { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,.55); } 70% { box-shadow: 0 0 0 7px rgba(16,185,129,0); } 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); } }
    
    /* --- RESPONSIVE ADJUSTMENTS --- */
    @media (max-width: 1024px) {
        .filter-bar { grid-template-columns: 1fr 1fr 1fr auto; }
        .filter-bar .actions { grid-column: 1 / -1; justify-content: flex-end; margin-top: .5rem; }
    }
    @media (max-width: 768px) {
        .filter-bar { grid-template-columns: 1fr 1fr; }
        .filter-bar .actions { grid-column: span 2; justify-content: stretch; }
        .filter-bar .actions .btn { flex: 1; }
    }
';

if (!$is_ajax) {
    require __DIR__ . '/../partials/head.php';
    require __DIR__ . '/../partials/sidebar.php';
    ?>
    <div class="main-content" id="mainContent">
        <div class="topbar">
            <div class="d-flex align-items-center gap-3">
                <button class="menu-toggle" id="menuToggle" aria-label="Open menu"><i class="bi bi-list fs-5"></i></button>
                <div>
                    <h2><?= dash_e($__dashboard_label) ?> Dashboard</h2>
                    <p class="sub">Enrollment overview &amp; student management · <span id="liveClock" data-server-ms="<?= (int)(microtime(true) * 1000) ?>" data-tz="<?= dash_e(date_default_timezone_get()) ?>"><?= date('M d, Y g:i:s A') ?></span></p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <span class="live-status" aria-live="polite"><span class="live-dot" id="liveDot"></span><span id="liveText">Connecting…</span></span>
                <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
                <button class="theme-btn" id="themeToggle" title="Toggle theme"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>
            </div>
        </div>
        
        <!-- AJAX Container Starts Here -->
        <div id="ajax-container">
<?php } ?>

            <?php if (isset($_SESSION['success'])): ?><div class="flash success"><i class="bi bi-check-circle-fill"></i><span><?= dash_e($_SESSION['success']) ?></span><button class="btn-close" data-bs-dismiss="alert"></button></div><?php unset($_SESSION['success']); endif; ?>
            <?php if (isset($_SESSION['error'])): ?><div class="flash error"><i class="bi bi-exclamation-triangle-fill"></i><span><?= dash_e($_SESSION['error']) ?></span><button class="btn-close" data-bs-dismiss="alert"></button></div><?php unset($_SESSION['error']); endif; ?>

            <div class="dash-quick">
                <a class="btn btn-primary btn-sm" href="<?= URL_PREFIX ?>/pending_enrollments.php"><i class="bi bi-hourglass-split me-1"></i> Review pending <span class="badge-count" data-stat="pending"><?= number_format($snap['pending']) ?></span></a>
                <a class="btn btn-outline-primary btn-sm" href="<?= URL_PREFIX ?>/reports.php"><i class="bi bi-file-earmark-text me-1"></i> Reports</a>
                <a class="btn btn-outline-success btn-sm" href="<?= dash_e(dash_url(['export' => 'csv', 'page' => null])) ?>"><i class="bi bi-filetype-csv me-1"></i> Export CSV</a>
            </div>

            <div class="stat-grid">
                <div class="stat-card tone-blue"><div class="stat-icon"><i class="bi bi-people-fill"></i></div><div class="stat-info"><div class="stat-value" data-stat="total"><?= number_format($snap['stats']['total']) ?></div><div class="stat-label">Total Students</div><div class="stat-hint"><span data-stat="new_week"><?= number_format($snap['stats']['new_week']) ?></span> added this week</div></div></div>
                <div class="stat-card tone-green"><div class="stat-icon"><i class="bi bi-mortarboard-fill"></i></div><div class="stat-info"><div class="stat-value" data-stat="active"><?= number_format($snap['stats']['active']) ?></div><div class="stat-label">Active Enrolled</div><div class="stat-hint">Current enrollment status</div></div></div>
                <div class="stat-card tone-purple"><div class="stat-icon"><i class="bi bi-diagram-3"></i></div><div class="stat-info"><div class="stat-value" data-stat="sections"><?= number_format($snap['stats']['sections']) ?></div><div class="stat-label">Sections</div><div class="stat-hint">Across all grade levels</div></div></div>
                
                <a class="stat-card tone-amber is-link <?= $f_needs ? 'is-active-filter' : '' ?>" href="<?= dash_e(dash_url(['needs_section' => $f_needs ? null : 1, 'page' => null])) ?>" title="Show only students without a section">
                    <div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
                    <div class="stat-info"><div class="stat-value" data-stat="no_section"><?= number_format($snap['stats']['no_section']) ?></div><div class="stat-label">Need Section</div><div class="stat-hint"><?= $f_needs ? 'Filter on: click to clear' : 'Click to filter' ?></div></div>
                </a>
                
                <div class="stat-card tone-blue"><div class="stat-icon"><i class="bi bi-gender-male"></i></div><div class="stat-info"><div class="stat-value" data-stat="boys"><?= number_format($snap['stats']['boys']) ?></div><div class="stat-label">Male</div></div></div>
                <div class="stat-card tone-red"><div class="stat-icon"><i class="bi bi-gender-female"></i></div><div class="stat-info"><div class="stat-value" data-stat="girls"><?= number_format($snap['stats']['girls']) ?></div><div class="stat-label">Female</div></div></div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-lg-7"><div class="card h-100"><div class="card-header"><h3><i class="bi bi-bar-chart"></i> Enrollment by Grade Level</h3><span class="hint">Students per grade</span></div><div class="card-body"><div class="chart-wrap" id="gradeWrap" style="<?= !empty($snap['grade']['labels']) ? '' : 'display:none' ?>"><canvas id="gradeChart"></canvas></div><div class="chart-empty" id="gradeEmpty" style="<?= !empty($snap['grade']['labels']) ? 'display:none' : '' ?>">No grade data yet.</div></div></div></div>
                <div class="col-lg-5"><div class="card h-100"><div class="card-header"><h3><i class="bi bi-pie-chart"></i> Strand Distribution</h3><span class="hint">Current students</span></div><div class="card-body"><div class="chart-wrap" id="strandWrap" style="<?= !empty($snap['strand']['labels']) ? '' : 'display:none' ?>"><canvas id="strandChart"></canvas></div><div class="chart-empty" id="strandEmpty" style="<?= !empty($snap['strand']['labels']) ? 'display:none' : '' ?>">No strand data yet.</div></div></div></div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h3><i class="bi bi-list-ul"></i> Student Directory <span class="hint" id="dir-count">(<?= number_format($total_rows) ?> result<?= $total_rows === 1 ? '' : 's' ?>)</span></h3></div>
                <div class="card-body" style="padding:1rem 1.25rem;border-bottom:1px solid var(--border)">
                    <form class="filter-bar" method="GET" id="filterForm">
                        <input type="hidden" name="sort" value="<?= dash_e($sort) ?>">
                        <input type="hidden" name="dir" value="<?= dash_e($dir_lc) ?>">
                        <?php if ($f_needs): ?><input type="hidden" name="needs_section" value="1"><?php endif; ?>
                        <div class="field"><label for="f_search">Search</label><input type="text" id="f_search" name="search" placeholder="Name, LRN, or student ID" value="<?= dash_e($f_search) ?>"></div>
                        <div class="field"><label for="f_grade">Grade Level</label><select id="f_grade" name="grade_level"><option value="">All Grades</option><?php foreach ($snap['grade']['labels'] as $gl): ?><option value="<?= dash_e($gl) ?>" <?= $gl === $f_grade ? 'selected' : '' ?>><?= dash_e($gl) ?></option><?php endforeach; ?></select></div>
                        <div class="field"><label for="f_strand">Strand</label><select id="f_strand" name="strand"><option value="">All Strands</option><?php foreach ($strands as $sr): ?><option value="<?= dash_e($sr['v']) ?>" <?= $sr['v'] === $f_strand ? 'selected' : '' ?>><?= dash_e($sr['v']) ?></option><?php endforeach; ?></select></div>
                        <div class="field"><label for="f_sy">School Year</label><select id="f_sy" name="school_year"><option value="">All Years</option><?php foreach ($school_years as $sy): ?><option value="<?= dash_e($sy['v']) ?>" <?= $sy['v'] === $f_sy ? 'selected' : '' ?>><?= dash_e($sy['v']) ?></option><?php endforeach; ?></select></div>
                        <div class="actions"><button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Filter</button><a href="<?= URL_PREFIX ?>/dashboard.php" class="btn btn-outline-secondary btn-sm reset-btn"><i class="bi bi-x-circle me-1"></i> Reset</a></div>
                    </form>
                </div>
                <div class="card-body no-padding">
                    <?php if ($students): ?>
                        <div class="table-scroll">
                            <table class="table-admin">
                                <thead>
                                    <tr>
                                        <th style="width:60px">#</th>
                                        <th><a class="sort-link" href="<?= dash_e(dash_url(['sort' => 'id', 'dir' => ($sort === 'id' && $dir_lc === 'asc') ? 'desc' : 'asc', 'page' => null])) ?>">Student ID <?= $sort === 'id' ? ($dir_lc === 'asc' ? '<i class="bi bi-sort-down-alt sort-icon active"></i>' : '<i class="bi bi-sort-down sort-icon active"></i>') : '<i class="bi bi-arrow-down-up sort-icon inactive"></i>' ?></a></th>
                                        <th><a class="sort-link" href="<?= dash_e(dash_url(['sort' => 'name', 'dir' => ($sort === 'name' && $dir_lc === 'asc') ? 'desc' : 'asc', 'page' => null])) ?>">Name <?= $sort === 'name' ? ($dir_lc === 'asc' ? '<i class="bi bi-sort-down-alt sort-icon active"></i>' : '<i class="bi bi-sort-down sort-icon active"></i>') : '<i class="bi bi-arrow-down-up sort-icon inactive"></i>' ?></a></th>
                                        <th><a class="sort-link" href="<?= dash_e(dash_url(['sort' => 'lrn', 'dir' => ($sort === 'lrn' && $dir_lc === 'asc') ? 'desc' : 'asc', 'page' => null])) ?>">LRN <?= $sort === 'lrn' ? ($dir_lc === 'asc' ? '<i class="bi bi-sort-down-alt sort-icon active"></i>' : '<i class="bi bi-sort-down sort-icon active"></i>') : '<i class="bi bi-arrow-down-up sort-icon inactive"></i>' ?></a></th>
                                        <th><a class="sort-link" href="<?= dash_e(dash_url(['sort' => 'grade', 'dir' => ($sort === 'grade' && $dir_lc === 'asc') ? 'desc' : 'asc', 'page' => null])) ?>">Grade <?= $sort === 'grade' ? ($dir_lc === 'asc' ? '<i class="bi bi-sort-down-alt sort-icon active"></i>' : '<i class="bi bi-sort-down sort-icon active"></i>') : '<i class="bi bi-arrow-down-up sort-icon inactive"></i>' ?></a></th>
                                        <th><a class="sort-link" href="<?= dash_e(dash_url(['sort' => 'strand', 'dir' => ($sort === 'strand' && $dir_lc === 'asc') ? 'desc' : 'asc', 'page' => null])) ?>">Strand <?= $sort === 'strand' ? ($dir_lc === 'asc' ? '<i class="bi bi-sort-down-alt sort-icon active"></i>' : '<i class="bi bi-sort-down sort-icon active"></i>') : '<i class="bi bi-arrow-down-up sort-icon inactive"></i>' ?></a></th>
                                        <th><a class="sort-link" href="<?= dash_e(dash_url(['sort' => 'section', 'dir' => ($sort === 'section' && $dir_lc === 'asc') ? 'desc' : 'asc', 'page' => null])) ?>">Section <?= $sort === 'section' ? ($dir_lc === 'asc' ? '<i class="bi bi-sort-down-alt sort-icon active"></i>' : '<i class="bi bi-sort-down sort-icon active"></i>') : '<i class="bi bi-arrow-down-up sort-icon inactive"></i>' ?></a></th>
                                        <th><a class="sort-link" href="<?= dash_e(dash_url(['sort' => 'status', 'dir' => ($sort === 'status' && $dir_lc === 'asc') ? 'desc' : 'asc', 'page' => null])) ?>">Status <?= $sort === 'status' ? ($dir_lc === 'asc' ? '<i class="bi bi-sort-down-alt sort-icon active"></i>' : '<i class="bi bi-sort-down sort-icon active"></i>') : '<i class="bi bi-arrow-down-up sort-icon inactive"></i>' ?></a></th>
                                        <th style="text-align:right;width:80px">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $i => $s):
                                        $fullName = $s['last_name'] . ', ' . $s['first_name'] . (!empty($s['middle_name']) ? ' ' . $s['middle_name'] : '');
                                        $status   = (string)$s['status'];
                                        $pill     = $status === 'Active' ? 'success' : 'warning';
                                    ?>
                                    <tr>
                                        <td class="cell-num"><?= $offset + $i + 1 ?></td>
                                        <td class="cell-id"><?= dash_e($s['student_id_number'] ?: '—') ?></td>
                                        <td class="cell-strong"><?= dash_e($fullName) ?></td>
                                        <td class="cell-muted"><?= dash_e($s['lrn'] ?: '—') ?></td>
                                        <td><?= dash_e($s['grade_level'] ?: '—') ?></td>
                                        <td class="cell-muted"><?= dash_e($s['strand'] ?: '—') ?></td>
                                        <td class="cell-accent"><?= dash_e($s['section'] ?: '—') ?></td>
                                        <td><span class="pill <?= $pill ?>"><?= dash_e($status ?: 'Unknown') ?></span></td>
                                        <td style="text-align:right"><a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$s['student_id'] ?>" class="btn btn-icon btn-outline-primary" title="View profile"><i class="bi bi-arrow-right-short" style="font-size:1.15rem"></i></a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="dash-pager">
                            <span class="muted-note">Showing <?= number_format($range_from) ?>–<?= number_format($range_to) ?> of <?= number_format($total_rows) ?></span>
                            <div class="pages">
                                <a class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= dash_e(dash_url(['page' => $page - 1])) ?>"><i class="bi bi-chevron-left"></i> Prev</a>
                                <span class="muted-note">Page <?= $page ?> of <?= $total_pages ?></span>
                                <a class="pg-btn <?= $page >= $total_pages ? 'disabled' : '' ?>" href="<?= dash_e(dash_url(['page' => $page + 1])) ?>">Next <i class="bi bi-chevron-right"></i></a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty"><div class="empty-icon"><i class="bi bi-search"></i></div><div class="empty-title">No students found</div><div class="empty-sub">Try adjusting your search or filters.</div></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h3><i class="bi bi-clock-history"></i> Recent Enrollments</h3><span class="hint">Latest 10 · live</span></div>
                <div class="card-body no-padding">
                    <div class="table-scroll" id="recentWrap" style="<?= !empty($snap['recent']) ? '' : 'display:none' ?>">
                        <table class="table-admin">
                            <thead><tr><th>Student ID</th><th>Name</th><th>LRN</th><th>Grade</th><th>Strand</th><th>Section</th><th>Date Enrolled</th><th style="text-align:right;width:80px">Action</th></tr></thead>
                            <tbody id="recentBody">
                                <?php foreach ($snap['recent'] as $r): ?>
                                <tr>
                                    <td class="cell-id"><?= dash_e($r['student_id_number'] ?: '—') ?></td>
                                    <td class="cell-strong"><?= dash_e($r['name']) ?></td>
                                    <td class="cell-muted"><?= dash_e($r['lrn'] ?: '—') ?></td>
                                    <td><?= dash_e($r['grade_level'] ?: '—') ?></td>
                                    <td class="cell-muted"><?= dash_e($r['strand'] ?: '—') ?></td>
                                    <td class="cell-accent"><?= dash_e($r['section'] ?: '—') ?></td>
                                    <td class="cell-muted"><?= dash_e($r['enrolled_label'] ?: '—') ?></td>
                                    <td style="text-align:right"><a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$r['student_id'] ?>" class="btn btn-icon btn-outline-primary" title="View profile"><i class="bi bi-arrow-right-short" style="font-size:1.15rem"></i></a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="empty" id="recentEmpty" style="<?= !empty($snap['recent']) ? 'display:none' : '' ?>"><div class="empty-icon"><i class="bi bi-inbox"></i></div><div class="empty-title">No recent enrollments</div><div class="empty-sub">New enrollments will appear here.</div></div>
                </div>
            </div>

<?php if (!$is_ajax) { ?>
        </div> <!-- end #ajax-container -->
    </div> <!-- end main-content -->

    <script>
    (function () {
        'use strict';
        const root        = document.documentElement;
        const LIVE_URL    = location.pathname + '?action=live';
        const LIVE_MS     = <?= (int)DASH_LIVE_INTERVAL_MS ?>;
        const VIEW_URL    = <?= json_encode(URL_PREFIX . '/view_student.php?id=', $JSON_FLAGS) ?>;
        const PALETTE     = ['#4f46e5','#7c3aed','#6366f1','#818cf8','#a5b4fc','#c7d2fe','#8b5cf6','#6d28d9'];
        const HAS_CHART   = typeof Chart !== 'undefined';
        const INITIAL     = <?= json_encode($snap, $JSON_FLAGS) ?>;
        
        let gradeChart = null, strandChart = null;
        let lastSig = '', failures = 0, timer = null, inFlight = false;
        
        const $ = id => document.getElementById(id);
        const nf = n => Number(n || 0).toLocaleString('en-US');
        const cssVar = name => getComputedStyle(root).getPropertyValue(name).trim();

        (function liveClock() {
            const el = $('liveClock'); if (!el) return;
            const offset = Number(el.dataset.serverMs) - Date.now();
            const opts = { year: 'numeric', month: 'short', day: '2-digit', hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true };
            let fmt; try { fmt = new Intl.DateTimeFormat('en-US', Object.assign({ timeZone: el.dataset.tz || undefined }, opts)); } catch (e) { fmt = new Intl.DateTimeFormat('en-US', opts); }
            const tick = () => { el.textContent = fmt.format(new Date(Date.now() + offset)); }; tick(); setInterval(tick, 1000);
        })();

        function setStatus(state, text) { const dot = $('liveDot'); if (dot) dot.className = 'live-dot ' + state; const t = $('liveText'); if (t) t.textContent = text; }
        function setVisible(id, visible) { const el = $(id); if (el) el.style.display = visible ? '' : 'none'; }

        function renderGrade(d) {
            setVisible('gradeWrap', d.labels.length > 0); setVisible('gradeEmpty', d.labels.length === 0);
            if (!HAS_CHART || d.labels.length === 0) return;
            if (!gradeChart) {
                const ctx  = $('gradeChart').getContext('2d'); const grad = ctx.createLinearGradient(0, 0, 0, 260);
                grad.addColorStop(0, 'rgba(99,102,241,0.95)'); grad.addColorStop(1, 'rgba(79,70,229,0.55)');
                gradeChart = new Chart(ctx, {
                    type: 'bar', data: { labels: d.labels, datasets: [{ label: 'Students', data: d.counts, backgroundColor: grad, hoverBackgroundColor: '#4f46e5', borderRadius: 8, borderSkipped: false, maxBarThickness: 48 }] },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: tooltipStyle() }, scales: { x: { grid: { display: false }, ticks: { color: cssVar('--text-2'), font: { size: 11, weight: '500' } } }, y: { beginAtZero: true, grid: { color: cssVar('--border') }, ticks: { color: cssVar('--text-3'), font: { size: 11 }, precision: 0 } } } }
                });
            } else { gradeChart.data.labels = d.labels; gradeChart.data.datasets[0].data = d.counts; gradeChart.update(); }
        }

        function renderStrand(d) {
            setVisible('strandWrap', d.labels.length > 0); setVisible('strandEmpty', d.labels.length === 0);
            if (!HAS_CHART || d.labels.length === 0) return;
            const colors = d.labels.map((_, i) => PALETTE[i % PALETTE.length]);
            if (!strandChart) {
                strandChart = new Chart($('strandChart'), {
                    type: 'doughnut', data: { labels: d.labels, datasets: [{ data: d.counts, backgroundColor: colors, borderWidth: 3, borderColor: cssVar('--surface'), hoverOffset: 6 }] },
                    options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true, pointStyle: 'circle', color: cssVar('--text-2'), font: { size: 11, weight: '500' }, boxWidth: 8, boxHeight: 8 } }, tooltip: tooltipStyle() } }
                });
            } else { strandChart.data.labels = d.labels; strandChart.data.datasets[0].data = d.counts; strandChart.data.datasets[0].backgroundColor = colors; strandChart.update(); }
        }

        function tooltipStyle() { return { backgroundColor: '#0f172a', padding: 10, cornerRadius: 8, titleFont: { weight: '600' }, displayColors: false }; }

        function refreshChartColors() {
            if (gradeChart) { gradeChart.options.scales.x.ticks.color = cssVar('--text-2'); gradeChart.options.scales.y.grid.color  = cssVar('--border'); gradeChart.options.scales.y.ticks.color = cssVar('--text-3'); gradeChart.update(); }
            if (strandChart) { strandChart.data.datasets[0].borderColor = cssVar('--surface'); strandChart.options.plugins.legend.labels.color = cssVar('--text-2'); strandChart.update(); }
        }

        function cell(tag, text, cls) { const el = document.createElement(tag); if (cls) el.className = cls; el.textContent = text; return el; }

        function renderRecent(rows) {
            const body = $('recentBody'); if (!body) return; body.replaceChildren();
            setVisible('recentWrap', rows.length > 0); setVisible('recentEmpty', rows.length === 0);
            rows.forEach(r => {
                const tr = document.createElement('tr');
                const actionTd = document.createElement('td'); actionTd.style.textAlign = 'right';
                const a = document.createElement('a'); a.href = VIEW_URL + encodeURIComponent(r.student_id); a.className = 'btn btn-icon btn-outline-primary'; a.title = 'View profile';
                const icon = document.createElement('i'); icon.className = 'bi bi-arrow-right-short'; icon.style.fontSize = '1.15rem'; a.appendChild(icon); actionTd.appendChild(a);
                tr.append(cell('td', r.student_id_number || '—', 'cell-id'), cell('td', r.name || '—', 'cell-strong'), cell('td', r.lrn || '—', 'cell-muted'), cell('td', r.grade_level || '—'), cell('td', r.strand || '—', 'cell-muted'), cell('td', r.section || '—', 'cell-accent'), cell('td', r.enrolled_label || '—', 'cell-muted'), actionTd);
                body.appendChild(tr);
            });
        }

        window.applySnapshot = function(d) {
            const sig = JSON.stringify([d.stats, d.pending, d.grade, d.strand, d.recent]); if (sig === lastSig) return; lastSig = sig;
            document.querySelectorAll('[data-stat]').forEach(el => { const key = el.dataset.stat; const value = key === 'pending' ? d.pending : d.stats[key]; if (value !== undefined) el.textContent = nf(value); });
            renderGrade(d.grade); renderStrand(d.strand); renderRecent(d.recent);
        };

        function schedule() { clearTimeout(timer); const delay = failures ? Math.min(LIVE_MS * Math.pow(2, failures), 5 * 60 * 1000) : LIVE_MS; timer = setTimeout(refresh, delay); }

        async function refresh() {
            if (document.hidden || inFlight) return; inFlight = true;
            try {
                const r = await fetch(LIVE_URL, { cache: 'no-store', credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                if (r.redirected) { window.location.href = r.url; return; } if (!r.ok) throw new Error('HTTP ' + r.status);
                const d = await r.json(); applySnapshot(d); failures = 0; setStatus('live', 'Live · updated ' + d.server_time_label);
            } catch (err) { failures++; setStatus('offline', 'Offline · retrying'); } finally { inFlight = false; schedule(); }
        }

        document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
        new MutationObserver(muts => { muts.forEach(m => { if (m.attributeName === 'data-bs-theme') refreshChartColors(); }); }).observe(root, { attributes: true });
        
        if (INITIAL) {
            applySnapshot(INITIAL); setStatus('live', 'Live · updated ' + INITIAL.server_time_label); schedule();
        }

        // AJAX NAVIGATION LOGIC
        const container = document.getElementById('ajax-container');
        
        function loadAjax(url) {
            if (!container) return;
            container.style.opacity = '0.5';
            container.style.pointerEvents = 'none';
            
            const ajaxUrl = url + (url.includes('?') ? '&' : '?') + 'ajax=1';
            
            fetch(ajaxUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.text())
                .then(html => {
                    container.innerHTML = html;
                    container.style.opacity = '1';
                    container.style.pointerEvents = 'auto';
                    window.history.pushState(null, '', url);
                    
                    // Re-fetch live snapshot to update charts and stats properly
                    fetch(LIVE_URL)
                        .then(res => res.json())
                        .then(data => {
                            if (gradeChart) { gradeChart.destroy(); gradeChart = null; }
                            if (strandChart) { strandChart.destroy(); strandChart = null; }
                            lastSig = ''; // force update
                            applySnapshot(data);
                        });
                })
                .catch(() => window.location.href = url);
        }

        container.addEventListener('click', function(e) {
            const link = e.target.closest('.sort-link, .dash-pager a, .stat-card.is-link, .reset-btn');
            if (!link) return;
            if (link.classList.contains('disabled')) return;
            
            e.preventDefault();
            let url = link.getAttribute('href');
            
            if (link.classList.contains('reset-btn')) {
                url = '<?= URL_PREFIX ?>/dashboard.php';
            }
            
            loadAjax(url);
        });

        container.addEventListener('submit', function(e) {
            if (e.target.matches('#filterForm')) {
                e.preventDefault();
                const formData = new FormData(e.target);
                const params = new URLSearchParams(formData);
                const cleanUrl = '?' + params.toString();
                loadAjax(cleanUrl);
            }
        });

        window.addEventListener('popstate', function() {
            loadAjax(window.location.href);
        });

    })();
    </script>

    <?php require __DIR__ . '/../partials/footer.php'; ?>
<?php } ?>