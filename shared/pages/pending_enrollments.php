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
           s.ext_name, s.sex, s.birth_date, s.email, s.phone,
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
    /* Table & Filters */
    .stat-hint { font-size:.68rem; color:var(--text-3); margin-top:.35rem; font-weight:500; display:flex; align-items:center; gap:.25rem; }
    .stat-hint i { color:var(--accent); }
    .quick-filters{display:flex;gap:.5rem;flex-wrap:wrap;padding:0 1.25rem 1rem}
    .chip{display:inline-flex;align-items:center;gap:.4rem;padding:.4rem .85rem;border-radius:999px;border:1px solid var(--border);background:var(--surface-2);color:var(--text-2);font-size:.78rem;font-weight:600;cursor:pointer;transition:all .15s;font-family:inherit}
    .chip:hover{background:var(--surface);color:var(--text);border-color:var(--border-strong)}
    .chip.active{background:var(--accent);color:#fff;border-color:var(--accent);box-shadow:0 4px 12px -4px color-mix(in srgb,var(--accent) 50%, transparent)}
    .chip .count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:18px;padding:0 .35rem;border-radius:999px;background:var(--surface);color:var(--text-2);font-size:.68rem;font-weight:700;line-height:1}
    .chip.active .count{background:rgba(255,255,255,.25);color:#fff}
    .chip.chip-green.active{background:var(--green);border-color:var(--green)}
    .chip.chip-amber.active{background:var(--amber);border-color:var(--amber)}
    .chip.chip-red.active{background:var(--red);border-color:var(--red)}
    .table-admin tbody tr.row-ready td:first-child{box-shadow:inset 4px 0 0 var(--green)}
    .table-admin tbody tr.row-nodocs td:first-child{box-shadow:inset 4px 0 0 var(--amber)}
    .student-cell{display:flex;align-items:center;gap:.7rem;min-width:0}
    .student-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;flex-shrink:0;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 15%, transparent)}
    .student-info{min-width:0}
    .student-name{font-weight:600;color:var(--text);font-size:.87rem;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .student-lrn{font-size:.72rem;color:var(--text-2);font-variant-numeric:tabular-nums}
    .doc-progress{display:flex;flex-direction:column;gap:.3rem;min-width:90px}
    .doc-progress .doc-bar{width:100%;height:6px;border-radius:999px;background:var(--surface-2);overflow:hidden}
    .doc-progress .doc-bar-fill{height:100%;border-radius:999px;transition:width .3s ease}
    .doc-progress .doc-bar-fill.fill-full{background:var(--green)}
    .doc-progress .doc-bar-fill.fill-partial{background:var(--accent)}
    .doc-progress .doc-bar-fill.fill-empty{background:var(--amber)}
    .doc-progress .doc-label{font-size:.7rem;font-weight:600;color:var(--text-2);font-variant-numeric:tabular-nums;display:flex;align-items:center;gap:.3rem}
    .doc-progress .doc-label.ok{color:var(--green)}
    .doc-progress .doc-label.warn{color:var(--amber)}
    .time-cell{white-space:nowrap}
    .time-cell .rel{font-size:.82rem;font-weight:600;color:var(--text)}
    .time-cell .rel.overdue{color:var(--red)}
    .time-cell .abs{font-size:.72rem;color:var(--text-2);font-variant-numeric:tabular-nums}
    .pagination-bar { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border-top: 1px solid var(--border); background: var(--surface); }
    .pagination { display: inline-flex; align-items: center; gap: .25rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; padding: .25rem; }
    .pagination .btn { border: none; color: var(--text-2); min-width: 34px; padding: .35rem .55rem; font-weight: 600; }
    .pagination .btn:hover:not(:disabled) { background: var(--surface); color: var(--text); }
    .pagination .btn:disabled { opacity: .4; cursor: not-allowed; }
    .pagination .page-info { font-size: .78rem; color: var(--text-2); padding: 0 .65rem; font-weight: 600; }

    /* ═══════════════════════════════════════════════════════
       ENHANCED MODALS
       ═══════════════════════════════════════════════════════ */
    .modal-content { border: none; border-radius: 20px; overflow: hidden; box-shadow: 0 24px 64px -12px rgba(0,0,0,0.3); }
    
    /* Approve Modal Specifics */
    .approve-hero { background: linear-gradient(135deg, #059669, #047857); padding: 2rem 2rem 1.5rem; color: #fff; position: relative; }
    .approve-hero-inner { display: flex; align-items: center; gap: 1.25rem; position: relative; z-index: 1; }
    .approve-hero-icon { width: 56px; height: 56px; border-radius: 16px; background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.3); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; }
    .approve-hero-info h5 { margin: 0 0 .25rem; font-size: 1.15rem; font-weight: 800; letter-spacing: -0.01em; display: flex; align-items: center; gap: .5rem; }
    .approve-hero-info .student-meta { display: flex; gap: .5rem; font-size: .85rem; opacity: .9; font-weight: 500; }
    .approve-hero-info .student-meta .sep { opacity: .5; }
    .approve-body { padding: 1.75rem 2rem; }
    
    /* Checklist */
    .approve-subtitle { font-size: 0.75rem; font-weight: 800; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
    .approve-subtitle i { font-size: 1rem; color: var(--accent); }
    .approve-checklist { display: flex; flex-direction: column; gap: 0.85rem; margin-bottom: 2rem; }
    .approve-check { display: flex; align-items: flex-start; gap: 1rem; padding: 1rem; border-radius: 14px; background: var(--surface-2); border: 1px solid var(--border); transition: transform 0.15s ease, box-shadow 0.15s ease; }
    .approve-check:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); background: var(--surface); }
    .approve-check-icon { width: 38px; height: 38px; border-radius: 10px; background: var(--green-soft); color: var(--green); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
    .approve-check-body { flex: 1; }
    .approve-check-title { font-size: 0.9rem; font-weight: 700; color: var(--text); margin-bottom: 0.2rem; }
    .approve-check-desc { font-size: 0.8rem; color: var(--text-2); line-height: 1.5; margin: 0; }
    
    /* Email Box */
    .approve-email-box { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.25rem; border-radius: 14px; background: var(--surface-2); border: 1px dashed var(--border-strong); }
    .approve-email-box .icon { width: 40px; height: 40px; border-radius: 10px; background: var(--accent-soft); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
    .approve-email-box .label { font-size: 0.7rem; font-weight: 800; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.5px; }
    .approve-email-box .value { font-size: 0.95rem; font-weight: 600; color: var(--text); margin-top: 0.1rem; word-break: break-all; }
    .approve-email-box .value.empty { color: var(--text-3); font-style: italic; font-weight: 500; }
    
    /* Warning */
    .approve-warn { display: flex; align-items: flex-start; gap: 0.75rem; padding: 1rem; border-radius: 14px; background: var(--amber-soft); color: var(--amber); border: 1px solid color-mix(in srgb, var(--amber) 30%, transparent); font-size: 0.85rem; line-height: 1.5; margin-top: 1rem; }
    .approve-warn i { font-size: 1.2rem; flex-shrink: 0; margin-top: 1px; }
    
    /* Footer */
    .approve-footer { padding: 1.25rem 2rem; background: var(--surface); border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .approve-footer .btn { padding: 0.65rem 1.5rem; font-weight: 600; border-radius: 10px; }
    .approve-footer .btn-confirm { background: linear-gradient(135deg, #059669, #047857); border: none; color: #fff; box-shadow: 0 4px 12px -2px rgba(5,150,105,.4); transition: all .15s; }
    .approve-footer .btn-confirm:hover { transform: translateY(-1px); box-shadow: 0 6px 16px -2px rgba(5,150,105,.5); }
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
                <div class="stat-value"><?= $stat_total ?></div>
                <div class="stat-label">Total Pending</div>
            </div>
        </div>
        <div class="stat-card tone-green">
            <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $stat_withdocs ?></div>
                <div class="stat-label">With Documents</div>
            </div>
        </div>
        <div class="stat-card tone-amber">
            <div class="stat-icon"><i class="bi bi-exclamation-circle"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $stat_nodocs ?></div>
                <div class="stat-label">Awaiting Documents</div>
            </div>
        </div>
        <div class="stat-card tone-red">
            <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= $stat_overdue ?></div>
                <div class="stat-label">Overdue (3+ days)</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-funnel"></i> Filters</h3>
            <span class="pill info" style="text-transform:none;letter-spacing:0;">
                <i class="bi bi-hourglass-split"></i> <?= $stat_total ?> pending
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
                        <option value="">All</option>
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
            <span class="cell-muted" id="visibleCount"></span>
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
                                <th style="width:240px">Student</th>
                                <th>Program</th>
                                <th style="width:140px">Section</th>
                                <th style="width:160px">Submitted</th>
                                <th style="width:140px">Documents</th>
                                <th style="text-align:right;width:230px">Action</th>
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
                                                · LRN <?= htmlspecialchars($p['lrn'] ?: '—') ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="cell-strong"><?= htmlspecialchars($p['grade_level']) ?></div>
                                    <div class="cell-muted"><?= htmlspecialchars($p['strand'] ?: '—') ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($p['section'])): ?>
                                        <span class="pill info" style="text-transform:none;letter-spacing:0;"><?= htmlspecialchars($p['section']) ?></span>
                                    <?php else: ?>
                                        <span class="cell-muted">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td class="time-cell">
                                    <div class="rel <?= $is_overdue ? 'overdue' : '' ?>" title="<?= htmlspecialchars($abs) ?>">
                                        <?php if ($is_overdue): ?><i class="bi bi-exclamation-triangle-fill me-1" style="font-size:.75rem"></i><?php endif; ?>
                                        <?= htmlspecialchars($rel) ?>
                                    </div>
                                    <div class="abs"><?= date('M d, Y', $ts) ?></div>
                                </td>
                                <td>
                                    <div class="doc-progress">
                                        <div class="doc-bar">
                                            <div class="doc-bar-fill <?= $fill_class ?>" style="width:<?= $pct ?>%"></div>
                                        </div>
                                        <div class="doc-label <?= $label_class ?>">
                                            <i class="bi <?= $label_icon ?>"></i>
                                            <?= $docs_up ?> of <?= $docs_total ?> uploaded
                                        </div>
                                    </div>
                                </td>
                                <td style="text-align:right">
                                    <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
                                        <button class="btn btn-outline-secondary btn-xs view-btn"
                                                data-id="<?= (int)$p['enrollment_id'] ?>"
                                                data-name="<?= htmlspecialchars($fullName) ?>"
                                                data-sid="<?= (int)$p['student_id'] ?>"
                                                data-idno="<?= htmlspecialchars($p['student_id_number']) ?>"
                                                data-lrn="<?= htmlspecialchars($p['lrn']) ?>"
                                                data-sex="<?= htmlspecialchars($p['sex']) ?>"
                                                data-birth="<?= htmlspecialchars($p['birth_date']) ?>"
                                                data-email="<?= htmlspecialchars($p['email'] ?? '') ?>"
                                                data-phone="<?= htmlspecialchars($p['phone'] ?? '') ?>"
                                                data-sy="<?= htmlspecialchars($p['school_year']) ?>"
                                                data-grade="<?= htmlspecialchars($p['grade_level']) ?>"
                                                data-term="<?= htmlspecialchars($p['term']) ?>"
                                                data-track="<?= htmlspecialchars($p['track']) ?>"
                                                data-strand="<?= htmlspecialchars($p['strand']) ?>"
                                                data-program="<?= htmlspecialchars($p['program']) ?>"
                                                data-section="<?= htmlspecialchars($p['section']) ?>"
                                                data-docsup="<?= $docs_up ?>"
                                                data-docstotal="<?= $docs_total ?>"
                                                title="Review details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="btn btn-success btn-xs approve-btn"
                                                data-id="<?= (int)$p['enrollment_id'] ?>"
                                                data-name="<?= htmlspecialchars($fullName) ?>"
                                                data-email="<?= htmlspecialchars($p['email'] ?? '') ?>"
                                                data-idno="<?= htmlspecialchars($p['student_id_number']) ?>"
                                                data-grade="<?= htmlspecialchars($p['grade_level']) ?>"
                                                data-strand="<?= htmlspecialchars($p['strand']) ?>"
                                                data-docsup="<?= $docs_up ?>"
                                                data-docstotal="<?= $docs_total ?>"
                                                title="Approve enrollment">
                                            <i class="bi bi-check-lg me-1"></i> Approve
                                        </button>
                                        <button class="btn btn-outline-danger btn-xs reject-btn"
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
                    <span class="muted-note">
                        Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $limit, $total_records)) ?> of <?= number_format($total_records) ?>
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
                <div class="approve-hero">
                    <button type="button" class="btn-close btn-close-white position-absolute" data-bs-dismiss="modal" style="top: 1rem; right: 1rem; z-index: 2;"></button>
                    <div class="approve-hero-inner">
                        <div class="approve-hero-icon"><i class="bi bi-check2-circle"></i></div>
                        <div class="approve-hero-info">
                            <h5 id="approveName">Student Name</h5>
                            <div class="student-meta">
                                <span id="approveIdno">ID</span>
                                <span class="sep">·</span>
                                <span id="approveGrade">Grade & Strand</span>
                                <span class="sep">·</span>
                                <span id="approveDocsInline">Docs</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Body -->
                <div class="approve-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="enrollment_id" id="approveId">
                    <input type="hidden" name="action" value="approve">

                    <div class="approve-subtitle">
                        <i class="bi bi-list-check"></i> What happens next
                    </div>

                    <div class="approve-checklist">
                        <div class="approve-check">
                            <div class="approve-check-icon"><i class="bi bi-person-check-fill"></i></div>
                            <div class="approve-check-body">
                                <div class="approve-check-title">Set status to Active</div>
                                <div class="approve-check-desc">The enrollment will be officially activated and appear in the student masterlist.</div>
                            </div>
                        </div>

                        <div class="approve-check">
                            <div class="approve-check-icon"><i class="bi bi-envelope-check-fill"></i></div>
                            <div class="approve-check-body">
                                <div class="approve-check-title">Send confirmation email</div>
                                <div class="approve-check-desc">The student will receive an enrollment confirmation with their student ID and section.</div>
                            </div>
                        </div>

                        <div class="approve-check">
                            <div class="approve-check-icon"><i class="bi bi-journal-text"></i></div>
                            <div class="approve-check-body">
                                <div class="approve-check-title">Record in audit trail</div>
                                <div class="approve-check-desc">The approval will be logged with your name and timestamp for accountability.</div>
                            </div>
                        </div>
                    </div>

                    <div class="approve-subtitle">
                        <i class="bi bi-bell-fill"></i> Notification
                    </div>

                    <div class="approve-email-box">
                        <div class="icon"><i class="bi bi-envelope-fill"></i></div>
                        <div class="body">
                            <div class="label">Student Email</div>
                            <div class="value" id="approveEmailDisplay">—</div>
                        </div>
                    </div>

                    <div class="approve-warn" id="approveEmailWarn" style="display:none">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <div>
                            <strong>No email on file.</strong>
                            The approval will still succeed, but the student won't be notified. You may need to contact them directly.
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="approve-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-confirm">
                        <i class="bi bi-check-lg me-2"></i> Confirm Approval
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="reviewName">Student Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="reviewBody">
                <!-- Loaded via JS -->
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?= URL_PREFIX ?>/approve_enrollment.php">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Enrollment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="enrollment_id" id="rejectId">
                    <input type="hidden" name="action" value="reject">
                    <p class="mb-3">Are you sure you want to reject <strong id="rejectName" class="text-danger"></strong>?</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Reason (sent to student)</label>
                        <textarea name="reason" class="form-control" rows="3" required maxlength="500"
                                  placeholder="e.g. Incomplete documents — please re-upload your PSA Birth Certificate."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-x-lg me-1"></i> Reject Application</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const URL_PREFIX = <?= json_encode(URL_PREFIX) ?>;

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

// Modal Logic
document.querySelectorAll('.view-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const d = btn.dataset;
        document.getElementById('reviewName').textContent = d.name || 'Student Details';
        
        let html = `
            <div class="row g-4">
                <div class="col-md-6">
                    <h6 class="text-muted fw-bold mb-2">PERSONAL INFO</h6>
                    <p class="mb-1"><strong>ID:</strong> ${d.idno}</p>
                    <p class="mb-1"><strong>LRN:</strong> ${d.lrn}</p>
                    <p class="mb-1"><strong>Sex:</strong> ${d.sex}</p>
                    <p class="mb-1"><strong>Birth Date:</strong> ${d.birth}</p>
                    <p class="mb-1"><strong>Email:</strong> ${d.email || '—'}</p>
                    <p class="mb-1"><strong>Phone:</strong> ${d.phone || '—'}</p>
                </div>
                <div class="col-md-6">
                    <h6 class="text-muted fw-bold mb-2">ENROLLMENT</h6>
                    <p class="mb-1"><strong>Grade:</strong> ${d.grade}</p>
                    <p class="mb-1"><strong>Strand:</strong> ${d.strand || '—'}</p>
                    <p class="mb-1"><strong>Program:</strong> ${d.program || '—'}</p>
                    <p class="mb-1"><strong>Section:</strong> ${d.section || 'Unassigned'}</p>
                    <p class="mb-1"><strong>School Year:</strong> ${d.sy}</p>
                    <p class="mb-1"><strong>Term:</strong> ${d.term}</p>
                </div>
            </div>
        `;
        document.getElementById('reviewBody').innerHTML = html;
        new bootstrap.Modal(document.getElementById('reviewModal')).show();
    });
});

function openApprove(d) {
    document.getElementById('approveId').value = d.id;
    document.getElementById('approveName').textContent = d.name;
    document.getElementById('approveIdno').textContent = d.idno;
    document.getElementById('approveGrade').textContent = d.grade + (d.strand ? ' · ' + d.strand : '');
    document.getElementById('approveDocsInline').textContent = d.docsup + ' of ' + d.docstotal + ' docs';

    const emailEl = document.getElementById('approveEmailDisplay');
    if (d.email && d.email.trim() !== '') {
        emailEl.textContent = d.email;
        emailEl.classList.remove('empty');
        document.getElementById('approveEmailWarn').style.display = 'none';
    } else {
        emailEl.textContent = 'No email on file';
        emailEl.classList.add('empty');
        document.getElementById('approveEmailWarn').style.display = 'flex';
    }

    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

document.querySelectorAll('.approve-btn').forEach(btn => {
    btn.addEventListener('click', () => openApprove(btn.dataset));
});

document.querySelectorAll('.reject-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('rejectId').value = btn.dataset.id;
        document.getElementById('rejectName').textContent = btn.dataset.name;
        new bootstrap.Modal(document.getElementById('rejectModal')).show();
    });
});
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>