<?php
// shared/pages/pending_enrollments.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';

$__nav_section = 'enrollment';

if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: ' . URL_PREFIX . '/dashboard.php');
    exit;
}

/* ── Time helper ── */
if (!function_exists('time_ago')) {
    function time_ago(string $dt): string {
        $t = strtotime($dt);
        if ($t === false) return '—';
        $diff = time() - $t;
        if ($diff < 60)         return 'Just now';
        if ($diff < 3600)       return floor($diff / 60) . ' min ago';
        if ($diff < 86400)      return floor($diff / 3600) . ' hr ago';
        if ($diff < 604800)     return floor($diff / 86400) . ' day' . (floor($diff / 86400) === 1 ? '' : 's') . ' ago';
        return date('M d, Y', $t);
    }
}

/* ═══ REQUIRED DOCUMENTS ═══ */
$REQUIRED_DOCS = [
    'Good Moral Certificate',
    'Junior High School Certificate (Front)',
    'Junior High School Certificate (Back)',
    'NSO/PSA Birth Certificate (Original)',
    '2 pcs 2×2 picture',
];
$REQUIRED_DOCS_COUNT = count($REQUIRED_DOCS);

/* ── Filters ── */
$sy_filter    = trim($_GET['school_year'] ?? '');
$grade_filter = trim($_GET['grade_level'] ?? '');
$search       = trim($_GET['search'] ?? '');

$where  = ["e.status = 'Pending'"];
$params = [];
$types  = '';

if ($sy_filter !== '')    { $where[] = "e.school_year = ?"; $params[] = $sy_filter;    $types .= 's'; }
if ($grade_filter !== '') { $where[] = "e.grade_level = ?"; $params[] = $grade_filter; $types .= 's'; }
if ($search !== '') {
    $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$sql = "
    SELECT e.enrollment_id, e.student_id, e.school_year, e.grade_level, e.term,
           e.track, e.strand, e.program, e.section, e.enrolled_at,
           s.student_id_number, s.lrn, s.first_name, s.middle_name, s.last_name,
           s.ext_name, s.sex, s.birth_date, s.email, s.phone, s.religion, s.nationality,
           p.father_name, p.father_occupation, p.mother_maiden_name, p.mother_occupation, 
           p.guardian_fullname, p.guardian_relation, p.guardian_contact,
           a.purok_street, a.barangay, a.town_city, a.province, a.region,
           (SELECT COUNT(DISTINCT d.document_name) FROM entrance_documents d
             WHERE d.student_id = s.student_id
               AND d.submitted = 1
               AND d.file_path IS NOT NULL
               AND (
                   d.document_name = 'Good Moral Certificate'
                   OR d.document_name = 'Junior High School Certificate (Front)'
                   OR d.document_name = 'Junior High School Certificate (Back)'
                   OR d.document_name = 'NSO/PSA Birth Certificate (Original)'
                   OR d.document_name LIKE '2 pcs%2×2%'
                   OR d.document_name LIKE '2 pcs%2x2%'
               )
           ) AS docs_uploaded,
           {$REQUIRED_DOCS_COUNT} AS docs_total
    FROM enrollment_form e
    INNER JOIN students_info s ON s.student_id = e.student_id
    LEFT JOIN parents_info p ON s.student_id = p.student_id
    LEFT JOIN addresses a ON s.student_id = a.student_id
    $where_sql
    ORDER BY e.enrolled_at ASC
";
$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$all_pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ── Pagination Logic ── */
$limit = 10;
$total_records = count($all_pending);
$total_pages = max(1, ceil($total_records / $limit));
$page = max(1, min($total_pages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $limit;

$pending = array_slice($all_pending, $offset, $limit);

/* ── Fetch Full Data for Modals (Education & Docs) ── */
$student_data_js = [];
if (!empty($pending)) {
    $student_ids = array_column($pending, 'student_id');
    $in_query = implode(',', array_fill(0, count($student_ids), '?'));
    
    // Fetch Education
    $edu_data = [];
    $estmt = $conn->prepare("SELECT student_id, level, school_name, school_address, year_completed FROM educational_history WHERE student_id IN ($in_query) ORDER BY FIELD(level, 'Elementary', 'JHS', 'Transferred')");
    $estmt->bind_param(str_repeat('i', count($student_ids)), ...$student_ids);
    $estmt->execute();
    $eres = $estmt->get_result();
    while ($row = $eres->fetch_assoc()) {
        $edu_data[$row['student_id']][] = $row;
    }
    $estmt->close();

    // Fetch Documents
    $doc_data = [];
    $dstmt = $conn->prepare("SELECT entrance_id, student_id, document_name, submitted, file_path FROM entrance_documents WHERE student_id IN ($in_query)");
    $dstmt->bind_param(str_repeat('i', count($student_ids)), ...$student_ids);
    $dstmt->execute();
    $dres = $dstmt->get_result();
    while ($row = $dres->fetch_assoc()) {
        $doc_data[$row['student_id']][] = $row;
    }
    $dstmt->close();

    // Merge into pending array for JSON output
    foreach ($pending as $p) {
        $sid = $p['student_id'];
        $p['education'] = $edu_data[$sid] ?? [];
        
        // Normalize docs to match required list
        $uploaded = [];
        foreach ($doc_data[$sid] ?? [] as $d) {
            if ($d['submitted'] && !empty($d['file_path'])) {
                $uploaded[$d['document_name']] = $d['entrance_id'];
            }
        }
        
        $p['documents'] = [];
        foreach ($REQUIRED_DOCS as $req) {
            $p['documents'][] = [
                'name' => $req,
                'uploaded' => isset($uploaded[$req]),
                'id' => $uploaded[$req] ?? 0
            ];
        }
        
        $student_data_js[$p['enrollment_id']] = $p;
    }
}

/* ── Stats ── */
$stat_total    = $total_records;
$stat_withdocs = 0;
$stat_nodocs   = 0;
$stat_overdue  = 0;
foreach ($all_pending as $p) {
    if ((int)$p['docs_uploaded'] > 0) $stat_withdocs++;
    else                              $stat_nodocs++;
    if ((time() - strtotime($p['enrolled_at'])) > 3 * 86400) $stat_overdue++;
}

$sy_list    = $conn->query("SELECT DISTINCT school_year FROM enrollment_form WHERE status='Pending' ORDER BY school_year DESC")->fetch_all(MYSQLI_ASSOC) ?: [];
$grade_list = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form WHERE status='Pending' ORDER BY grade_level ASC")->fetch_all(MYSQLI_ASSOC) ?: [];

$csrf_token = csrf_token();
$pagination_url = "?search=" . urlencode($search) . "&school_year=" . urlencode($sy_filter) . "&grade_level=" . urlencode($grade_filter);

$page_title = 'Pending Enrollments • USAT Admin';

$page_css = <<<'CSS'
    /* ═══ STAT CARDS ═══ */
    .stat-card { position:relative; overflow:hidden; transition:transform .2s, box-shadow .2s; }
    .stat-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); }
    .stat-card::before { transition:width .3s ease; }
    .stat-card:hover::before { width:6px; }
    .stat-icon { transition:transform .2s; }
    .stat-card:hover .stat-icon { transform:scale(1.1) rotate(-3deg); }

    /* ═══ QUICK FILTERS (CHIPS) ═══ */
    .quick-filters { display:flex; gap:.5rem; flex-wrap:wrap; padding:0 1.25rem 1rem; }
    .chip { display:inline-flex; align-items:center; gap:.4rem; padding:.45rem 1rem; border-radius:999px; border:1px solid var(--border); background:var(--surface); color:var(--text-2); font-size:.78rem; font-weight:600; cursor:pointer; transition:all .2s ease; font-family:inherit; }
    .chip:hover { background:var(--surface-2); color:var(--text); border-color:var(--border-strong); transform:translateY(-1px); }
    .chip.active { background:var(--accent); color:#fff; border-color:var(--accent); box-shadow:0 4px 12px -4px color-mix(in srgb,var(--accent) 50%, transparent); transform:translateY(-1px); }
    .chip .count { display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:20px; padding:0 .4rem; border-radius:999px; background:var(--surface-2); color:var(--text-2); font-size:.7rem; font-weight:700; line-height:1; transition:all .2s; }
    .chip:hover .count { background:var(--border); color:var(--text); }
    .chip.active .count { background:rgba(255,255,255,.25); color:#fff; }
    .chip.chip-green.active { background:var(--green); border-color:var(--green); box-shadow:0 4px 12px -4px rgba(5,150,105,.5); }
    .chip.chip-amber.active { background:var(--amber); border-color:var(--amber); box-shadow:0 4px 12px -4px rgba(217,119,6,.5); }
    .chip.chip-red.active { background:var(--red); border-color:var(--red); box-shadow:0 4px 12px -4px rgba(220,38,38,.5); }

    /* ═══ TABLE ROWS ═══ */
    .table-admin tbody tr { transition:background .15s; }
    .table-admin tbody tr:hover td { background:color-mix(in srgb, var(--accent) 4%, var(--surface)) !important; }
    .table-admin tbody tr.row-ready td:first-child { box-shadow:inset 4px 0 0 var(--green); }
    .table-admin tbody tr.row-nodocs td:first-child { box-shadow:inset 4px 0 0 var(--amber); }
    
    .student-cell { display:flex; align-items:center; gap:.85rem; min-width:0; }
    .student-avatar { width:40px; height:40px; border-radius:12px; background:linear-gradient(135deg,var(--accent),var(--accent-2)); color:#fff; display:flex; align-items:center; justify-content:center; font-size:.85rem; font-weight:800; flex-shrink:0; box-shadow:0 4px 12px -4px rgba(79,70,229,.5); letter-spacing:.5px; }
    .student-info { min-width:0; }
    .student-name { font-weight:700; color:var(--text); font-size:.88rem; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .student-lrn { font-size:.72rem; color:var(--text-2); font-variant-numeric:tabular-nums; margin-top:.15rem; font-weight:500; }
    
    /* ═══ DOC PROGRESS ═══ */
    .doc-progress { display:flex; flex-direction:column; gap:.4rem; min-width:110px; }
    .doc-progress .doc-bar { width:100%; height:8px; border-radius:999px; background:var(--border); overflow:hidden; box-shadow:inset 0 1px 2px rgba(0,0,0,.05); }
    .doc-progress .doc-bar-fill { height:100%; border-radius:999px; transition:width .6s cubic-bezier(.4,0,.2,1); }
    .doc-progress .doc-bar-fill.fill-full { background:linear-gradient(90deg, #10b981, #059669); }
    .doc-progress .doc-bar-fill.fill-partial { background:linear-gradient(90deg, #6366f1, #4f46e5); }
    .doc-progress .doc-bar-fill.fill-empty { background:linear-gradient(90deg, #f59e0b, #d97706); }
    .doc-progress .doc-label { font-size:.7rem; font-weight:700; color:var(--text-2); font-variant-numeric:tabular-nums; display:flex; align-items:center; justify-content:space-between; gap:.3rem; }
    .doc-progress .doc-label span { display:flex; align-items:center; gap:.3rem; }
    .doc-progress .doc-label.ok { color:var(--green); }
    .doc-progress .doc-label.warn { color:var(--amber); }
    
    /* ═══ TIME CELL ═══ */
    .time-cell { white-space:nowrap; }
    .time-cell .rel { font-size:.82rem; font-weight:700; color:var(--text); display:flex; align-items:center; gap:.3rem; }
    .time-cell .rel.overdue { color:var(--red); }
    .time-cell .abs { font-size:.72rem; color:var(--text-3); font-variant-numeric:tabular-nums; margin-top:.15rem; font-weight:500; }

    /* ═══ ACTION BUTTONS ═══ */
    .action-group { display:inline-flex; gap:.35rem; flex-wrap:wrap; justify-content:flex-end; }
    .btn-action { display:inline-flex; align-items:center; justify-content:center; gap:.35rem; padding:.4rem .75rem; border-radius:8px; font-size:.75rem; font-weight:600; font-family:inherit; cursor:pointer; transition:all .15s; border:1px solid transparent; white-space:nowrap; }
    .btn-action:active { transform:translateY(1px); }
    
    .btn-action.view { background:transparent; color:var(--text-2); border-color:var(--border); }
    .btn-action.view:hover { background:var(--surface-2); color:var(--text); border-color:var(--border-strong); }
    
    .btn-action.approve { background:var(--green); color:#fff; box-shadow:0 4px 12px -4px rgba(5,150,105,.4); }
    .btn-action.approve:hover { background:#047857; box-shadow:0 6px 16px -4px rgba(5,150,105,.6); transform:translateY(-1px); }
    
    .btn-action.reject { background:transparent; color:var(--red); border-color:color-mix(in srgb, var(--red) 30%, transparent); }
    .btn-action.reject:hover { background:var(--red-soft); border-color:var(--red); color:var(--red); }

    /* ═══ PAGINATION ═══ */
    .pagination-bar { display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; border-top:1px solid var(--border); background:var(--surface); }
    .pagination { display:inline-flex; align-items:center; gap:.25rem; background:var(--surface-2); border:1px solid var(--border); border-radius:10px; padding:.25rem; }
    .pagination .btn { border:none; color:var(--text-2); min-width:34px; padding:.35rem .55rem; font-weight:600; border-radius:8px; }
    .pagination .btn:hover:not(:disabled) { background:var(--surface); color:var(--accent); box-shadow:0 2px 6px rgba(0,0,0,.05); }
    .pagination .btn:disabled { opacity:.4; cursor:not-allowed; }
    .pagination .page-info { font-size:.78rem; color:var(--text-2); padding:0 .65rem; font-weight:600; }

    /* ═══ MODALS ═══ */
    .modal-content { border:none; border-radius:24px; overflow:hidden; box-shadow:0 32px 80px -16px rgba(15,23,42,.4); }
    
    /* Review Modal Enhancements */
    .review-modal .modal-header { background:linear-gradient(135deg, #1e293b, #0f172a); color:#fff; border-bottom:none; padding:1.25rem 1.5rem; }
    .review-modal .modal-header h5 { font-weight:700; display:flex; align-items:center; gap:.5rem; }
    .review-modal .modal-body { padding:0; background:var(--surface); max-height:70vh; overflow-y:auto; }
    .review-section { padding:1.5rem; border-bottom:1px solid var(--border); }
    .review-section:last-child { border-bottom:none; }
    .review-section-title { font-size:.75rem; font-weight:800; color:var(--text-2); text-transform:uppercase; letter-spacing:.8px; margin-bottom:1rem; display:flex; align-items:center; gap:.4rem; }
    .review-section-title i { color:var(--accent); }
    .review-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(200px, 1fr)); gap:1rem; }
    .review-item .lbl { font-size:.7rem; font-weight:600; color:var(--text-3); text-transform:uppercase; margin-bottom:.15rem; }
    .review-item .val { font-size:.9rem; font-weight:600; color:var(--text); word-break:break-word; }
    .review-item.full-width { grid-column:1 / -1; }
    
    /* Doc Checklist */
    .doc-checklist { display:flex; flex-direction:column; gap:.5rem; }
    .doc-check-item { display:flex; align-items:center; justify-content:space-between; padding:.6rem .85rem; border-radius:8px; background:var(--surface-2); border:1px solid var(--border); }
    .doc-check-item .name { font-size:.82rem; font-weight:600; color:var(--text); display:flex; align-items:center; gap:.5rem; }
    .doc-check-item .name i { color:var(--text-3); font-size:1rem; }
    .doc-check-item .action { font-size:.75rem; font-weight:600; }
    .doc-check-item.uploaded { border-color:color-mix(in srgb, var(--green) 30%, transparent); background:var(--green-soft); }
    .doc-check-item.uploaded .name i { color:var(--green); }
    .doc-check-item.uploaded .action a { color:var(--green); text-decoration:none; display:inline-flex; align-items:center; gap:.25rem; }
    .doc-check-item.uploaded .action a:hover { text-decoration:underline; }
    .doc-check-item.missing { border-color:color-mix(in srgb, var(--amber) 30%, transparent); background:var(--amber-soft); }
    .doc-check-item.missing .name i { color:var(--amber); }
    .doc-check-item.missing .action { color:var(--amber); }

    /* Quick Reason Chips in Reject Modal */
    .quick-reasons { display:flex; flex-wrap:wrap; gap:.4rem; margin-bottom:1rem; }
    .reason-chip { padding:.35rem .7rem; border-radius:6px; background:var(--surface-2); border:1px solid var(--border); font-size:.75rem; font-weight:600; color:var(--text-2); cursor:pointer; transition:all .15s; font-family:inherit; }
    .reason-chip:hover { background:var(--red-soft); color:var(--red); border-color:color-mix(in srgb, var(--red) 30%, transparent); }
    
    @media (max-width: 768px) {
        .action-group { flex-direction:column; width:100%; }
        .btn-action { width:100%; justify-content:center; }
        .review-grid { grid-template-columns:1fr; }
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

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle" aria-label="Open menu">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <h2>Pending Enrollments</h2>
                <p class="sub">Review and approve student submissions</p>
            </div>
        </div>
        <button class="theme-btn" id="themeToggle" title="Toggle theme">
            <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
        </button>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-inbox-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($stat_total) ?></div>
                <div class="stat-label">Total Pending</div>
                <div class="stat-hint"><i class="bi bi-hourglass-split"></i> Awaiting review</div>
            </div>
        </div>
        <div class="stat-card tone-green">
            <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($stat_withdocs) ?></div>
                <div class="stat-label">With Documents</div>
                <div class="stat-hint"><i class="bi bi-file-earmark-check"></i> Ready to process</div>
            </div>
        </div>
        <div class="stat-card tone-amber">
            <div class="stat-icon"><i class="bi bi-exclamation-circle"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($stat_nodocs) ?></div>
                <div class="stat-label">Awaiting Docs</div>
                <div class="stat-hint"><i class="bi bi-file-earmark-x"></i> Missing uploads</div>
            </div>
        </div>
        <div class="stat-card tone-red">
            <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($stat_overdue) ?></div>
                <div class="stat-label">Overdue</div>
                <div class="stat-hint"><i class="bi bi-exclamation-triangle"></i> 3+ days pending</div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <h3><i class="bi bi-funnel"></i> Filters</h3>
            <span class="pill info" style="text-transform:none;letter-spacing:0;">
                <i class="bi bi-search"></i> <?= $stat_total ?> result<?= $stat_total === 1 ? '' : 's' ?>
            </span>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-bar">
                <div class="field">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Name, LRN, or ID" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="field">
                    <label>School Year</label>
                    <select name="school_year">
                        <option value="">All Years</option>
                        <?php foreach ($sy_list as $sy): ?>
                            <option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $sy_filter === $sy['school_year'] ? 'selected' : '' ?>><?= htmlspecialchars($sy['school_year']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Grade Level</label>
                    <select name="grade_level">
                        <option value="">All Grades</option>
                        <?php foreach ($grade_list as $g): ?>
                            <option value="<?= htmlspecialchars($g['grade_level']) ?>" <?= $grade_filter === $g['grade_level'] ? 'selected' : '' ?>><?= htmlspecialchars($g['grade_level']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="actions">
                    <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Apply</button>
                    <a href="<?= URL_PREFIX ?>/pending_enrollments.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle me-1"></i> Reset</a>
                </div>
            </form>
        </div>

        <div class="quick-filters">
            <button type="button" class="chip active" data-filter="all">
                <i class="bi bi-list-ul"></i> All <span class="count"><?= $stat_total ?></span>
            </button>
            <button type="button" class="chip chip-green" data-filter="ready">
                <i class="bi bi-check2-circle"></i> Ready for Review <span class="count"><?= $stat_withdocs ?></span>
            </button>
            <button type="button" class="chip chip-amber" data-filter="nodocs">
                <i class="bi bi-exclamation-circle"></i> Missing Docs <span class="count"><?= $stat_nodocs ?></span>
            </button>
            <button type="button" class="chip chip-red" data-filter="overdue">
                <i class="bi bi-clock-history"></i> Overdue <span class="count"><?= $stat_overdue ?></span>
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-inbox"></i> Submissions Awaiting Approval</h3>
            <span class="cell-muted" id="visibleCount" style="font-weight:600;font-size:.8rem"></span>
        </div>
        <div class="card-body no-padding">
            <?php if (empty($pending)): ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-check2-circle"></i></div>
                    <div class="empty-title">All caught up</div>
                    <div class="empty-sub">No pending enrollments to review right now.</div>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table-admin" id="pendingTable">
                        <thead>
                            <tr>
                                <th style="width:250px">Student</th>
                                <th>Program</th>
                                <th style="width:140px">Section</th>
                                <th style="width:160px">Submitted</th>
                                <th style="width:160px">Documents</th>
                                <th style="text-align:right;width:240px">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pending as $p):
                            $fullName   = trim(($p['last_name'] ?? '') . ', ' . ($p['first_name'] ?? '') . ' ' . ($p['middle_name'] ?? ''));
                            $initials   = strtoupper(substr($p['first_name'] ?? '?', 0, 1) . substr($p['last_name'] ?? '', 0, 1));
                            $docs_total = (int)$p['docs_total'];
                            $docs_up    = min((int)$p['docs_uploaded'], $docs_total);

                            if ($docs_total > 0) {
                                $pct = min(100, round(($docs_up / max(1, $docs_total)) * 100));
                            } else {
                                $pct = 0;
                            }
                            if ($docs_up === 0) {
                                $fill_class = 'fill-empty';
                                $label_class = 'warn';
                                $label_icon = 'bi-exclamation-circle';
                                $row_class = 'row-nodocs';
                            } elseif ($docs_total > 0 && $docs_up >= $docs_total) {
                                $fill_class = 'fill-full';
                                $label_class = 'ok';
                                $label_icon = 'bi-check2-circle';
                                $row_class = 'row-ready';
                            } else {
                                $fill_class = 'fill-partial';
                                $label_class = '';
                                $label_icon = 'bi-circle-half';
                                $row_class = 'row-ready';
                            }

                            $ts = strtotime($p['enrolled_at']);
                            $is_overdue = (time() - $ts) > 3 * 86400;
                            $rel = time_ago($p['enrolled_at']);
                            $abs = date('M d, Y · g:i A', $ts);

                            $data_filter = $docs_up > 0 ? 'ready' : 'nodocs';
                            if ($is_overdue) $data_filter .= ' overdue';
                        ?>
                            <tr class="<?= $row_class ?>" data-row-filter="<?= $data_filter ?>">
                                <td>
                                    <div class="student-cell">
                                        <div class="student-avatar"><?= htmlspecialchars($initials) ?></div>
                                        <div class="student-info">
                                            <div class="student-name"><?= htmlspecialchars($fullName) ?></div>
                                            <div class="student-lrn">
                                                <?= htmlspecialchars($p['student_id_number'] ?: '—') ?>
                                                &nbsp;·&nbsp; LRN <?= htmlspecialchars($p['lrn'] ?: '—') ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="cell-strong" style="font-size:.85rem"><?= htmlspecialchars($p['grade_level']) ?></div>
                                    <div class="cell-muted" style="font-size:.75rem;margin-top:.1rem"><?= htmlspecialchars($p['strand'] ?: '—') ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($p['section'])): ?>
                                        <span class="pill info" style="text-transform:none;letter-spacing:0;"><?= htmlspecialchars($p['section']) ?></span>
                                    <?php else: ?>
                                        <span class="cell-muted" style="font-style:italic;font-size:.78rem">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td class="time-cell">
                                    <div class="rel <?= $is_overdue ? 'overdue' : '' ?>" title="<?= htmlspecialchars($abs) ?>">
                                        <i class="bi bi-clock"></i> <?= htmlspecialchars($rel) ?>
                                    </div>
                                    <div class="abs"><?= date('M d, Y', $ts) ?></div>
                                </td>
                                <td>
                                    <div class="doc-progress">
                                        <div class="doc-bar">
                                            <div class="doc-bar-fill <?= $fill_class ?>" style="width:<?= $pct ?>%"></div>
                                        </div>
                                        <div class="doc-label <?= $label_class ?>">
                                            <span><i class="bi <?= $label_icon ?>"></i> <?= $docs_up ?> / <?= $docs_total ?> docs</span>
                                            <span><?= $pct ?>%</span>
                                        </div>
                                    </div>
                                </td>
                                <td style="text-align:right">
                                    <div class="action-group">
                                        <button class="btn-action view view-btn"
                                                data-id="<?= (int)$p['enrollment_id'] ?>"
                                                title="Review details">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                        <button class="btn-action approve approve-btn"
                                                data-id="<?= (int)$p['enrollment_id'] ?>"
                                                title="Approve enrollment">
                                            <i class="bi bi-check-lg"></i> Approve
                                        </button>
                                        <button class="btn-action reject reject-btn"
                                                data-id="<?= (int)$p['enrollment_id'] ?>"
                                                data-name="<?= htmlspecialchars($fullName) ?>"
                                                title="Reject enrollment">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="pagination-bar">
                    <span class="muted-note" style="font-size:.8rem;color:var(--text-2)">
                        Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $limit, $total_records)) ?> of <?= number_format($total_records) ?> records
                    </span>
                    <div class="pagination">
                        <a href="<?= $pagination_url ?>&page=1" class="btn btn-sm <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-left"></i></a>
                        <a href="<?= $pagination_url ?>&page=<?= max(1, $page - 1) ?>" class="btn btn-sm <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-left"></i></a>
                        <span class="page-info">Page <?= $page ?> of <?= $total_pages ?></span>
                        <a href="<?= $pagination_url ?>&page=<?= min($total_pages, $page + 1) ?>" class="btn btn-sm <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
                        <a href="<?= $pagination_url ?>&page=<?= $total_pages ?>" class="btn btn-sm <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-right"></i></a>
                    </div>
                </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODALS
     ═══════════════════════════════════════════════════════ -->

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?= URL_PREFIX ?>/approve_enrollment.php" id="approveForm">
                
                <!-- Hero Header -->
                <div class="approve-hero" style="background: linear-gradient(135deg, #059669, #047857); padding: 2rem 2rem 1.5rem; color: #fff; position: relative;">
                    <button type="button" class="btn-close btn-close-white position-absolute" data-bs-dismiss="modal" style="top: 1rem; right: 1rem; z-index: 2;"></button>
                    <div class="approve-hero-inner" style="display: flex; align-items: center; gap: 1.25rem; position: relative; z-index: 1;">
                        <div style="width: 56px; height: 56px; border-radius: 16px; background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.3); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;">
                            <i class="bi bi-check2-circle"></i>
                        </div>
                        <div>
                            <h5 id="approveName" style="margin: 0 0 .25rem; font-size: 1.15rem; font-weight: 800; letter-spacing: -0.01em;">Student Name</h5>
                            <div style="display: flex; gap: .5rem; font-size: .85rem; opacity: .9; font-weight: 500;">
                                <span id="approveIdno">ID</span>
                                <span style="opacity:.5">·</span>
                                <span id="approveGrade">Grade & Strand</span>
                                <span style="opacity:.5">·</span>
                                <span id="approveDocsInline">Docs</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Body -->
                <div style="padding: 1.75rem 2rem;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="enrollment_id" id="approveId">
                    <input type="hidden" name="action" value="approve">

                    <div style="font-size: 0.75rem; font-weight: 800; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="bi bi-list-check" style="font-size: 1rem; color: var(--accent);"></i> What happens next
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 0.85rem; margin-bottom: 2rem;">
                        <div style="display: flex; align-items: flex-start; gap: 1rem; padding: 1rem; border-radius: 14px; background: var(--surface-2); border: 1px solid var(--border);">
                            <div style="width: 38px; height: 38px; border-radius: 10px; background: var(--green-soft); color: var(--green); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;"><i class="bi bi-person-check-fill"></i></div>
                            <div>
                                <div style="font-size: 0.9rem; font-weight: 700; color: var(--text); margin-bottom: 0.2rem;">Set status to Active</div>
                                <div style="font-size: 0.8rem; color: var(--text-2); line-height: 1.5;">The enrollment will be officially activated and appear in the student masterlist.</div>
                            </div>
                        </div>
                        <div style="display: flex; align-items: flex-start; gap: 1rem; padding: 1rem; border-radius: 14px; background: var(--surface-2); border: 1px solid var(--border);">
                            <div style="width: 38px; height: 38px; border-radius: 10px; background: var(--accent-soft); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;"><i class="bi bi-envelope-check-fill"></i></div>
                            <div>
                                <div style="font-size: 0.9rem; font-weight: 700; color: var(--text); margin-bottom: 0.2rem;">Send confirmation email</div>
                                <div style="font-size: 0.8rem; color: var(--text-2); line-height: 1.5;">The student will receive an enrollment confirmation with their student ID and section.</div>
                            </div>
                        </div>
                    </div>

                    <div style="font-size: 0.75rem; font-weight: 800; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="bi bi-bell-fill" style="font-size: 1rem; color: var(--accent);"></i> Notification
                    </div>

                    <div style="display: flex; align-items: center; gap: 1rem; padding: 1rem 1.25rem; border-radius: 14px; background: var(--surface-2); border: 1px dashed var(--border-strong);">
                        <div style="width: 40px; height: 40px; border-radius: 10px; background: var(--accent-soft); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;"><i class="bi bi-envelope-fill"></i></div>
                        <div>
                            <div style="font-size: 0.7rem; font-weight: 800; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.5px;">Student Email</div>
                            <div id="approveEmailDisplay" style="font-size: 0.95rem; font-weight: 600; color: var(--text); margin-top: 0.1rem; word-break: break-all;">—</div>
                        </div>
                    </div>

                    <div id="approveEmailWarn" style="display:none; align-items: flex-start; gap: 0.75rem; padding: 1rem; border-radius: 14px; background: var(--amber-soft); color: var(--amber); border: 1px solid color-mix(in srgb, var(--amber) 30%, transparent); font-size: 0.85rem; line-height: 1.5; margin-top: 1rem;">
                        <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.2rem; flex-shrink: 0; margin-top: 1px;"></i>
                        <div>
                            <strong>No email on file.</strong> The approval will still succeed, but the student won't be notified. You may need to contact them directly.
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div style="padding: 1.25rem 2rem; background: var(--surface); border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-confirm" style="background: linear-gradient(135deg, #059669, #047857); border: none; color: #fff; box-shadow: 0 4px 12px -2px rgba(5,150,105,.4); padding: 0.65rem 1.5rem; font-weight: 600; border-radius: 10px;">
                        <i class="bi bi-check-lg me-2"></i> Confirm Approval
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Review Modal (Full Application View) -->
<div class="modal fade review-modal" id="reviewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reviewName"><i class="bi bi-person-lines-fill"></i> Student Application</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="reviewBody">
                <!-- Loaded via JS -->
            </div>
            <div style="padding: 1.25rem 1.5rem; background: var(--surface-2); border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                <div style="font-size:.8rem; color:var(--text-2);">
                    <i class="bi bi-shield-check me-1" style="color:var(--accent)"></i> Review all data carefully before approving.
                </div>
                <div style="display:flex; gap:.5rem;">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success btn-sm" id="reviewApproveBtn" style="background:var(--green); border:none; font-weight:600;">
                        <i class="bi bi-check2-circle me-1"></i> Approve Application
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?= URL_PREFIX ?>/approve_enrollment.php">
                <div class="modal-header bg-danger text-white" style="border-bottom:none;">
                    <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Enrollment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="enrollment_id" id="rejectId">
                    <input type="hidden" name="action" value="reject">
                    
                    <p class="mb-4" style="font-size:.95rem;">Are you sure you want to reject <strong id="rejectName" class="text-danger"></strong>?</p>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text-2)">Quick Reasons</label>
                        <div class="quick-reasons">
                            <button type="button" class="reason-chip" data-reason="Incomplete documents. Please re-upload your PSA Birth Certificate.">Missing Documents</button>
                            <button type="button" class="reason-chip" data-reason="The information provided does not match our records. Please verify your details.">Invalid Data</button>
                            <button type="button" class="reason-chip" data-reason="The uploaded document is blurry or unreadable. Please upload a clearer copy.">Unreadable File</button>
                            <button type="button" class="reason-chip" data-reason="Duplicate enrollment application detected.">Duplicate Entry</button>
                        </div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label fw-bold" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text-2)">Reason (sent to student)</label>
                        <textarea name="reason" id="rejectReasonText" class="form-control" rows="3" required maxlength="500"
                                  placeholder="Type or select a quick reason above..." style="background:var(--surface-2);border-radius:12px;border:1.5px solid var(--border);padding:.75rem;"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="background:var(--surface-2);border-top:1px solid var(--border);">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="padding:.6rem 1.5rem;border-radius:10px;font-weight:600;"><i class="bi bi-x-lg me-1"></i> Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const URL_PREFIX = <?= json_encode(URL_PREFIX) ?>;
const studentData = <?= json_encode($student_data_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// Quick filters
(function () {
    const chips = document.querySelectorAll('.chip');
    const rows  = document.querySelectorAll('#pendingTable tbody tr');
    const visibleCountEl = document.getElementById('visibleCount');

    function applyFilter(filter) {
        let shown = 0;
        rows.forEach(row => {
            const tags = (row.dataset.rowFilter || '').split(' ');
            let show = false;
            if (filter === 'all')          show = true;
            else if (filter === 'ready')   show = tags.includes('ready');
            else if (filter === 'nodocs')  show = tags.includes('nodocs');
            else if (filter === 'overdue') show = tags.includes('overdue');
            row.style.display = show ? '' : 'none';
            if (show) shown++;
        });
        if (visibleCountEl) {
            visibleCountEl.textContent = (filter === 'all') ? '' : shown + ' shown';
        }
    }

    chips.forEach(chip => {
        chip.addEventListener('click', () => {
            chips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            applyFilter(chip.dataset.filter);
        });
    });
})();

// Review Modal Logic
document.querySelectorAll('.view-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const d = studentData[id];
        if (!d) return;

        const fullName = `${d.last_name}, ${d.first_name} ${d.middle_name || ''}`;
        document.getElementById('reviewName').innerHTML = `<i class="bi bi-person-lines-fill"></i> Application: ${fullName}`;

        // Build Education HTML
        let eduHtml = '';
        if (d.education && d.education.length > 0) {
            d.education.forEach(e => {
                eduHtml += `
                    <div style="margin-bottom:.5rem; padding:.5rem .75rem; background:var(--surface); border:1px solid var(--border); border-radius:6px;">
                        <div style="font-size:.7rem;font-weight:700;color:var(--accent);text-transform:uppercase;margin-bottom:.2rem;">${e.level}</div>
                        <div style="font-size:.85rem;font-weight:600;">${e.school_name}</div>
                        <div style="font-size:.75rem;color:var(--text-2);">${e.school_address || ''} ${e.year_completed ? '· ' + e.year_completed : ''}</div>
                    </div>
                `;
            });
        } else {
            eduHtml = '<div style="font-size:.8rem;color:var(--text-3);font-style:italic">No educational history provided.</div>';
        }

        // Build Documents HTML
        let docsHtml = '';
        if (d.documents && d.documents.length > 0) {
            d.documents.forEach(doc => {
                const isUploaded = doc.uploaded;
                const cls = isUploaded ? 'uploaded' : 'missing';
                const icon = isUploaded ? 'bi-check-circle-fill' : 'bi-x-circle-fill';
                const action = isUploaded 
                    ? `<a href="${URL_PREFIX}/view_document.php?id=${doc.id}" target="_blank"><i class="bi bi-box-arrow-up-right"></i> View File</a>` 
                    : `<span>Not Uploaded</span>`;
                
                docsHtml += `
                    <div class="doc-check-item ${cls}">
                        <div class="name"><i class="bi ${icon}"></i> ${doc.name}</div>
                        <div class="action">${action}</div>
                    </div>
                `;
            });
        }

        let html = `
            <div class="review-section">
                <div class="review-section-title"><i class="bi bi-person-vcard"></i> Personal Information</div>
                <div class="review-grid">
                    <div class="review-item"><div class="lbl">Student ID</div><div class="val">${d.student_id_number || '—'}</div></div>
                    <div class="review-item"><div class="lbl">LRN</div><div class="val">${d.lrn || '—'}</div></div>
                    <div class="review-item"><div class="lbl">Sex</div><div class="val">${d.sex || '—'}</div></div>
                    <div class="review-item"><div class="lbl">Birth Date</div><div class="val">${d.birth_date || '—'}</div></div>
                    <div class="review-item"><div class="lbl">Religion</div><div class="val">${d.religion || '—'}</div></div>
                    <div class="review-item"><div class="lbl">Nationality</div><div class="val">${d.nationality || '—'}</div></div>
                    <div class="review-item"><div class="lbl">Email</div><div class="val">${d.email || '—'}</div></div>
                    <div class="review-item"><div class="lbl">Phone</div><div class="val">${d.phone || '—'}</div></div>
                </div>
            </div>
            
            <div class="review-section">
                <div class="review-section-title"><i class="bi bi-geo-alt"></i> Address</div>
                <div class="review-grid">
                    <div class="review-item"><div class="lbl">Street / Purok</div><div class="val">${d.purok_street || '—'}</div></div>
                    <div class="review-item"><div class="lbl">Barangay</div><div class="val">${d.barangay || '—'}</div></div>
                    <div class="review-item"><div class="lbl">Town / City</div><div class="val">${d.town_city || '—'}</div></div>
                    <div class="review-item"><div class="lbl">Province</div><div class="val">${d.province || '—'}</div></div>
                    <div class="review-item"><div class="lbl">Region</div><div class="val">${d.region || '—'}</div></div>
                </div>
            </div>

            <div class="review-section">
                <div class="review-section-title"><i class="bi bi-people"></i> Parents & Guardian</div>
                <div class="review-grid">
                    <div class="review-item"><div class="lbl">Father</div><div class="val">${d.father_name || '—'}</div></div>
                    <div class="review-item"><div class="lbl">Father Occupation</div><div class="val">${d.father_occupation || '—'}</div></div>
                    <div class="review-item"><div class="lbl">Mother (Maiden)</div><div class="val">${d.mother_maiden_name || '—'}</div></div>
                    <div class="review-item"><div class="lbl">Mother Occupation</div><div class="val">${d.mother_occupation || '—'}</div></div>
                    <div class="review-item full-width"><div class="lbl">Guardian</div><div class="val">${d.guardian_fullname || '—'} ${d.guardian_relation ? '('+d.guardian_relation+')' : ''} ${d.guardian_contact ? '· '+d.guardian_contact : ''}</div></div>
                </div>
            </div>

            <div class="review-section">
                <div class="review-section-title"><i class="bi bi-mortarboard"></i> Enrollment Details</div>
                <div class="review-grid">
                    <div class="review-item"><div class="lbl">Grade Level</div><div class="val">${d.grade_level}</div></div>
                    <div class="review-item"><div class="lbl">School Year</div><div class="val">${d.school_year}</div></div>
                    <div class="review-item"><div class="lbl">Term</div><div class="val">${d.term}</div></div>
                    <div class="review-item"><div class="lbl">Section</div><div class="val">${d.section || '<span class="text-muted">Unassigned</span>'}</div></div>
                    <div class="review-item full-width"><div class="lbl">Strand & Program</div><div class="val">${d.strand || '—'} ${d.program ? '· ' + d.program : ''}</div></div>
                </div>
            </div>

            <div class="review-section">
                <div class="review-section-title"><i class="bi bi-book"></i> Educational History</div>
                <div>${eduHtml}</div>
            </div>

            <div class="review-section" style="background:var(--surface-2);">
                <div class="review-section-title"><i class="bi bi-folder-check"></i> Entrance Documents</div>
                <div class="doc-checklist">${docsHtml}</div>
            </div>
        `;
        
        document.getElementById('reviewBody').innerHTML = html;
        
        // Setup the Approve button inside the review modal
        const reviewApproveBtn = document.getElementById('reviewApproveBtn');
        reviewApproveBtn.onclick = function() {
            bootstrap.Modal.getInstance(document.getElementById('reviewModal')).hide();
            setTimeout(() => openApprove(d), 300); // Open approve modal
        };

        new bootstrap.Modal(document.getElementById('reviewModal')).show();
    });
});

// Approve Modal Logic
function openApprove(d) {
    const fullName = `${d.last_name}, ${d.first_name} ${d.middle_name || ''}`;
    
    document.getElementById('approveId').value = d.enrollment_id;
    document.getElementById('approveName').textContent = fullName;
    document.getElementById('approveIdno').textContent = d.student_id_number || '—';
    document.getElementById('approveGrade').textContent = d.grade_level + (d.strand ? ' · ' + d.strand : '');
    document.getElementById('approveDocsInline').textContent = d.docs_uploaded + ' of ' + d.docs_total + ' docs';

    const emailEl = document.getElementById('approveEmailDisplay');
    const warnEl = document.getElementById('approveEmailWarn');
    
    if (d.email && d.email.trim() !== '') {
        emailEl.textContent = d.email;
        emailEl.style.color = 'var(--accent)';
        emailEl.style.fontStyle = 'normal';
        warnEl.style.display = 'none';
    } else {
        emailEl.textContent = 'No email on file';
        emailEl.style.color = 'var(--text-3)';
        emailEl.style.fontStyle = 'italic';
        warnEl.style.display = 'flex';
    }

    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

document.querySelectorAll('.approve-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const id = btn.dataset.id;
        const d = studentData[id];
        if (d) openApprove(d);
    });
});

// Reject Modal Logic & Quick Chips
document.querySelectorAll('.reject-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('rejectId').value = btn.dataset.id;
        document.getElementById('rejectName').textContent = btn.dataset.name;
        document.getElementById('rejectReasonText').value = ''; // Clear previous
        new bootstrap.Modal(document.getElementById('rejectModal')).show();
    });
});

document.querySelectorAll('.reason-chip').forEach(chip => {
    chip.addEventListener('click', function() {
        document.getElementById('rejectReasonText').value = this.dataset.reason;
        document.getElementById('rejectReasonText').focus();
    });
});
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>