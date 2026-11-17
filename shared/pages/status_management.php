<?php
// shared/pages/status_management.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../lib/dir_view.php';
require_once __DIR__ . '/../lib/card_helper.php';

$__nav_section = 'students';

if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$__view = dir_view('status_management');

/* ═══ POST: UPDATE STATUS ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    verify_csrf();
    
    $student_id    = (int)($_POST['student_id'] ?? 0);
    $enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
    $new_status    = trim((string)($_POST['new_status'] ?? ''));
    $reason        = trim((string)($_POST['reason'] ?? ''));
    
    $valid_statuses = ['Active', 'Pending', 'Graduated', 'Promoted', 'Stopped', 'Dropped', 'Transferred'];
    
    $err = '';
    if ($student_id <= 0 || $enrollment_id <= 0) {
        $err = 'Invalid student or enrollment record.';
    } elseif (!in_array($new_status, $valid_statuses, true)) {
        $err = 'Invalid status selected.';
    } elseif ($reason === '') {
        $err = 'A reason is required for status changes.';
    }

    if ($err === '') {
        try {
            // Fetch current status to log the old status
            $stmt = $conn->prepare("SELECT status FROM enrollment_form WHERE enrollment_id = ? AND student_id = ? LIMIT 1");
            $stmt->bind_param('ii', $enrollment_id, $student_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                throw new Exception("Enrollment record not found.");
            }

            $old_status = $row['status'] ?? 'Active';
            if ($old_status === $new_status) {
                throw new Exception("Student is already in '{$new_status}' status.");
            }

            $conn->begin_transaction();
            $admin_id = (int)$_SESSION['admin_id'];

            // 1. Update the enrollment_form
            $upd = $conn->prepare("UPDATE enrollment_form SET status = ? WHERE enrollment_id = ?");
            $upd->bind_param('si', $new_status, $enrollment_id);
            $upd->execute();
            $upd->close();

            // 2. Insert into status history
            $hist = $conn->prepare("INSERT INTO student_status_history (student_id, old_status, new_status, reason, changed_by) VALUES (?, ?, ?, ?, ?)");
            $hist->bind_param('isssi', $student_id, $old_status, $new_status, $reason, $admin_id);
            $hist->execute();
            $hist->close();

            // 3. Audit log
            audit_log($conn, 'student.status_change', [
                'type'          => 'student',
                'id'            => $student_id,
                'enrollment_id' => $enrollment_id,
                'old_status'    => $old_status,
                'new_status'    => $new_status,
                'reason'        => $reason
            ]);

            $conn->commit();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Status updated to {$new_status} successfully."];
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Status update failed: ' . $e->getMessage());
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Failed to update status: ' . $e->getMessage()];
        }
    } else {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $err];
    }

    header('Location: ' . URL_PREFIX . '/status_management.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ═══ FILTERS & PAGINATION ═══ */
$search      = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$grade_filter  = trim($_GET['grade_level'] ?? '');

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;

$where = ["1=1"];
$params = [];
$types = "";

if ($search !== '') {
    $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
    $types .= "ssss";
}
if ($status_filter !== '') {
    $where[] = "e.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}
if ($grade_filter !== '') {
    $where[] = "e.grade_level = ?";
    $params[] = $grade_filter;
    $types .= "s";
}

$where_sql = implode(' AND ', $where);

/* ═══ FETCH DATA ═══ */
try {
    // Stats
    $stats_sql = "SELECT e.status, COUNT(DISTINCT s.student_id) as cnt FROM students_info s JOIN enrollment_form e ON s.student_id = e.student_id JOIN (SELECT student_id, MAX(enrollment_id) as max_id FROM enrollment_form GROUP BY student_id) latest ON latest.max_id = e.enrollment_id GROUP BY e.status";
    $stats_res = $conn->query($stats_sql);
    $status_counts = ['Active'=>0, 'Pending'=>0, 'Graduated'=>0, 'Promoted'=>0, 'Stopped'=>0, 'Dropped'=>0, 'Transferred'=>0];
    while ($r = $stats_res->fetch_assoc()) {
        $status_counts[$r['status']] = (int)$r['cnt'];
    }

    // Main Query (join with latest enrollment)
    $base_sql = "
        FROM students_info s
        JOIN enrollment_form e ON e.student_id = s.student_id
        JOIN (
            SELECT student_id, MAX(enrollment_id) as max_id
            FROM enrollment_form
            GROUP BY student_id
        ) latest ON latest.max_id = e.enrollment_id
        WHERE $where_sql
    ";

    $cnt_stmt = $conn->prepare("SELECT COUNT(*) $base_sql");
    if ($types !== '') $cnt_stmt->bind_param($types, ...$params);
    $cnt_stmt->execute();
    $total_records = (int)($cnt_stmt->get_result()->fetch_row()[0] ?? 0);
    $cnt_stmt->close();

    $total_pages = max(1, (int)ceil($total_records / $limit));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $limit;

    $sql = "
        SELECT s.student_id, s.student_id_number, s.lrn, s.first_name, s.middle_name, s.last_name, s.sex, 
               e.enrollment_id, e.grade_level, e.school_year, e.section, e.strand, COALESCE(e.status, 'Active') as status
        $base_sql
        ORDER BY s.last_name ASC, s.first_name ASC
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql);
    $bind_types = $types . "ii";
    $bind_params = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($bind_types, ...$bind_params);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Grade levels for dropdown
    $grades = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form WHERE grade_level IS NOT NULL ORDER BY grade_level ASC")->fetch_all(MYSQLI_ASSOC);

} catch (Throwable $e) {
    error_log('Status Management load failed: ' . $e->getMessage());
    $flash = ['type' => 'error', 'msg' => 'Database error: ' . htmlspecialchars($e->getMessage())];
    $students = [];
    $total_records = 0;
    $total_pages = 1;
    $status_counts = [];
    $grades = [];
}

$pagination_qs = http_build_query(array_filter(['search'=>$search, 'status'=>$status_filter, 'grade_level'=>$grade_filter]));

$page_title = 'Student Status Management • USAT Admin';
$page_css = <<<'CSS'
    .status-pill { display:inline-flex; align-items:center; gap:.35rem; padding:.25rem .65rem; border-radius:999px; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
    .status-pill::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; }
    .status-Active { background: var(--green-soft); color: var(--green); }
    .status-Pending { background: var(--amber-soft); color: var(--amber); }
    .status-Graduated { background: var(--accent-soft); color: var(--accent); }
    .status-Promoted { background: #e0e7ff; color: #4338ca; }
    .status-Stopped { background: var(--red-soft); color: var(--red); }
    .status-Dropped { background: var(--red-soft); color: var(--red); }
    .status-Transferred { background: var(--surface-2); color: var(--text-2); border:1px solid var(--border); }
    
    .stat-card.is-link { text-decoration: none; cursor: pointer; transition: transform .15s, box-shadow .15s; }
    .stat-card.is-link:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
    .stat-card.is-active { outline: 2px solid var(--accent); outline-offset: -2px; }

    .action-btn { padding: .35rem .6rem; font-size: .75rem; }
    
    .history-timeline { position: relative; padding-left: 1.5rem; margin: 0; list-style: none; }
    .history-timeline::before { content:''; position:absolute; left:6px; top:0; bottom:0; width:2px; background:var(--border); }
    .history-item { position: relative; margin-bottom: 1.25rem; }
    .history-item::before { content:''; position:absolute; left:-1.5rem; top:4px; width:10px; height:10px; border-radius:50%; background:var(--surface); border:2px solid var(--accent); z-index:1; }
    .history-date { font-size: .7rem; font-weight: 700; color: var(--text-2); margin-bottom: .2rem; }
    .history-change { font-size: .85rem; font-weight: 600; color: var(--text); }
    .history-reason { font-size: .8rem; color: var(--text-2); margin-top: .2rem; font-style: italic; }
    .history-user { font-size: .75rem; color: var(--accent); margin-top: .2rem; }
CSS;

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/sidebar.php';
?>

<div class="main-content" id="mainContent">

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h2>Student Status Management</h2>
                <p class="sub">Review and update enrollment statuses across all students</p>
            </div>
        </div>
        <button class="theme-btn" id="themeToggle"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>">
            <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
            <span><?= htmlspecialchars($flash['msg']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Status Stats Grid -->
    <div class="stat-grid">
        <?php 
        $tone_map = [
            'Active'=>'tone-green', 'Pending'=>'tone-amber', 'Graduated'=>'tone-blue', 
            'Promoted'=>'tone-blue', 'Stopped'=>'tone-red', 'Dropped'=>'tone-red', 'Transferred'=>'tone-purple'
        ];
        foreach ($status_counts as $st => $cnt): 
            $is_active = ($status_filter === $st);
            $link = $is_active ? '?' : '?status=' . urlencode($st);
        ?>
            <a href="<?= $link ?>" class="stat-card is-link <?= $tone_map[$st] ?> <?= $is_active ? 'is-active' : '' ?>">
                <div class="stat-icon"><i class="bi bi-<?= $st === 'Active' ? 'person-check-fill' : ($st === 'Pending' ? 'hourglass-split' : ($st === 'Graduated' ? 'mortarboard-fill' : ($st === 'Dropped' ? 'person-x-fill' : 'circle-fill'))) ?>"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($cnt) ?></div>
                    <div class="stat-label"><?= $st ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form class="filter-bar" method="GET">
                <div class="field">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Name, LRN, or ID" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="field">
                    <label>Grade Level</label>
                    <select name="grade_level">
                        <option value="">All Grades</option>
                        <?php foreach($grades as $g): ?>
                            <option value="<?= htmlspecialchars($g['grade_level']) ?>" <?= $grade_filter === $g['grade_level'] ? 'selected' : '' ?>><?= htmlspecialchars($g['grade_level']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach(array_keys($status_counts) as $st): ?>
                            <option value="<?= $st ?>" <?= $status_filter === $st ? 'selected' : '' ?>><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Filter</button>
                    <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle me-1"></i> Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-person-lines-fill"></i> Students <span class="hint">(<?= number_format($total_records) ?>)</span></h3>
            <?= dir_view_toggle($__view) ?>
        </div>
        
        <div class="card-body no-padding <?= dir_view_class($__view) ?>" data-page-key="status_management">
            <?php if (empty($students)): ?>
                <div class="empty-note">No students found matching the current filters.</div>
            <?php else: ?>
                
                <!-- TABLE VIEW -->
                <div class="dir-table-wrap">
                    <div class="table-scroll">
                        <table class="table-admin">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Grade / Section</th>
                                    <th>School Year</th>
                                    <th>Current Status</th>
                                    <th style="text-align:right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($students as $s): 
                                    $name = $s['last_name'] . ', ' . $s['first_name'];
                                    $initials = strtoupper(substr($s['first_name'], 0, 1) . substr($s['last_name'], 0, 1));
                                ?>
                                <tr>
                                    <td>
                                        <div class="student-info-cell">
                                            <div class="student-avatar-mini"><?= htmlspecialchars($initials) ?></div>
                                            <div class="student-info-text">
                                                <span class="name"><?= htmlspecialchars($name) ?></span>
                                                <span class="meta">ID: <?= htmlspecialchars($s['student_id_number'] ?: '—') ?> · LRN: <?= htmlspecialchars($s['lrn'] ?: '—') ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="cell-strong"><?= htmlspecialchars($s['grade_level']) ?></div>
                                        <div class="cell-muted" style="font-size:.75rem;"><?= htmlspecialchars($s['section'] ?: 'No Section') ?></div>
                                    </td>
                                    <td class="cell-muted"><?= htmlspecialchars($s['school_year']) ?></td>
                                    <td>
                                        <span class="status-pill status-<?= htmlspecialchars($s['status']) ?>"><?= htmlspecialchars($s['status']) ?></span>
                                    </td>
                                    <td style="text-align:right">
                                        <button class="btn btn-sm btn-outline-primary action-btn me-1" 
                                                onclick="openStatusModal(<?= htmlspecialchars(json_encode($s)) ?>)">
                                            <i class="bi bi-arrow-repeat"></i> Update
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary action-btn"
                                                onclick="viewHistory(<?= (int)$s['student_id'] ?>, '<?= htmlspecialchars($name) ?>')">
                                            <i class="bi bi-clock-history"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- CARDS VIEW -->
                <div class="dir-cards-wrap">
                    <div class="student-cards-grid">
                        <?php foreach($students as $s): 
                            $name = $s['last_name'] . ', ' . $s['first_name'];
                            $initials = strtoupper(substr($s['first_name'], 0, 1) . substr($s['last_name'], 0, 1));
                        ?>
                            <div class="student-card">
                                <div class="student-card-header">
                                    <div class="student-avatar-mini"><?= htmlspecialchars($initials) ?></div>
                                    <div class="student-card-info">
                                        <div class="student-card-name"><?= htmlspecialchars($name) ?></div>
                                        <div class="student-card-meta">
                                            <span><?= htmlspecialchars($s['grade_level']) ?></span>
                                            <span class="sep">·</span>
                                            <span><?= htmlspecialchars($s['section'] ?: 'No Section') ?></span>
                                            <span class="sep">·</span>
                                            <span>SY <?= htmlspecialchars($s['school_year']) ?></span>
                                        </div>
                                    </div>
                                    <span class="status-pill status-<?= htmlspecialchars($s['status']) ?>"><?= htmlspecialchars($s['status']) ?></span>
                                </div>
                                
                                <div class="student-card-footer">
                                    <span class="student-card-strand" style="font-size:.75rem;">LRN: <?= htmlspecialchars($s['lrn'] ?: '—') ?></span>
                                    <div class="d-flex gap-1">
                                        <button class="btn btn-sm btn-outline-secondary action-btn" 
                                                onclick="viewHistory(<?= (int)$s['student_id'] ?>, '<?= htmlspecialchars($name) ?>')">
                                            <i class="bi bi-clock-history"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary action-btn" 
                                                onclick="openStatusModal(<?= htmlspecialchars(json_encode($s)) ?>)">
                                            <i class="bi bi-arrow-repeat"></i> Update
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-bar" style="padding:1.25rem; border-top:1px solid var(--border);">
                    <div class="page-info">Showing <strong><?= number_format($offset + 1) ?>–<?= number_format(min($offset + $limit, $total_records)) ?></strong> of <strong><?= number_format($total_records) ?></strong></div>
                    <div class="pagination">
                        <?php $qs = $pagination_qs ? '&' . $pagination_qs : ''; ?>
                        <a href="?page=1<?= $qs ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-left"></i></a>
                        <a href="?page=<?= max(1, $page - 1) ?><?= $qs ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-left"></i></a>
                        <span class="page-btn active" style="background:transparent;color:var(--text);">Page <?= $page ?> of <?= $total_pages ?></span>
                        <a href="?page=<?= min($total_pages, $page + 1) ?><?= $qs ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
                        <a href="?page=<?= $total_pages ?><?= $qs ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-right"></i></a>
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

<!-- Update Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px; border:none; overflow:hidden; box-shadow:var(--shadow-lg);">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="update_status" value="1">
                <input type="hidden" name="student_id" id="sm_student_id">
                <input type="hidden" name="enrollment_id" id="sm_enrollment_id">
                
                <div class="modal-header" style="background:var(--surface-2); border-bottom:1px solid var(--border); padding:1.25rem;">
                    <h5 class="modal-title" style="font-weight:700;"><i class="bi bi-arrow-repeat me-2 text-primary"></i>Update Student Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body" style="padding:1.5rem;">
                    <div class="mb-3">
                        <label class="form-label text-muted" style="font-size:.7rem; font-weight:700; text-transform:uppercase;">Student</label>
                        <div id="sm_student_name" style="font-size:1.1rem; font-weight:700; color:var(--text);"></div>
                        <div id="sm_student_meta" style="font-size:.8rem; color:var(--text-2); margin-top:.2rem;"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted" style="font-size:.7rem; font-weight:700; text-transform:uppercase;">Current Status</label>
                        <div id="sm_current_status" style="margin-top:.2rem;"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:.75rem; font-weight:700; text-transform:uppercase;">New Status</label>
                        <select name="new_status" id="sm_new_status" class="form-select" required style="background:var(--surface-2); border-radius:10px; padding:.6rem;">
                            <option value="">— Select new status —</option>
                            <option value="Active">Active</option>
                            <option value="Dropped">Dropped</option>
                            <option value="Transferred">Transferred</option>
                            <option value="Stopped">Stopped</option>
                            <option value="Graduated">Graduated</option>
                        </select>
                    </div>

                    <div class="mb-0">
                        <label class="form-label" style="font-size:.75rem; font-weight:700; text-transform:uppercase;">Reason for Change (Required)</label>
                        <textarea name="reason" class="form-control" rows="3" required placeholder="Explain why the status is being changed..." style="background:var(--surface-2); border-radius:10px; padding:.6rem;"></textarea>
                    </div>
                </div>

                <div class="modal-footer" style="background:var(--surface-2); border-top:1px solid var(--border); padding:1rem 1.5rem;">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Apply Change</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px; border:none; overflow:hidden; box-shadow:var(--shadow-lg);">
            <div class="modal-header" style="background:var(--surface-2); border-bottom:1px solid var(--border); padding:1.25rem;">
                <h5 class="modal-title" style="font-weight:700;"><i class="bi bi-clock-history me-2 text-primary"></i>Status History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:1.5rem; max-height:70vh; overflow-y:auto;">
                <h6 id="hm_student_name" style="font-weight:700; margin-bottom:1.25rem;"></h6>
                <div id="hm_loading" class="text-center py-4"><span class="spinner-border text-primary"></span></div>
                <ul class="history-timeline" id="hm_timeline" style="display:none;"></ul>
                <div id="hm_empty" class="text-center text-muted py-4" style="display:none;">No status changes recorded yet.</div>
            </div>
        </div>
    </div>
</div>

<script>
const statusModalEl = document.getElementById('statusModal');
const statusModal = new bootstrap.Modal(statusModalEl);

function openStatusModal(student) {
    document.getElementById('sm_student_id').value = student.student_id;
    document.getElementById('sm_enrollment_id').value = student.enrollment_id;
    document.getElementById('sm_student_name').textContent = student.last_name + ', ' + student.first_name;
    document.getElementById('sm_student_meta').textContent = 'ID: ' + (student.student_id_number || '—') + ' · ' + student.grade_level + ' · ' + (student.school_year || '—');
    
    const statusPill = `<span class="status-pill status-${student.status}">${student.status}</span>`;
    document.getElementById('sm_current_status').innerHTML = statusPill;
    
    document.getElementById('sm_new_status').value = '';
    statusModal.show();
}

const historyModalEl = document.getElementById('historyModal');
const historyModal = new bootstrap.Modal(historyModalEl);

async function viewHistory(studentId, studentName) {
    document.getElementById('hm_student_name').textContent = studentName;
    document.getElementById('hm_timeline').style.display = 'none';
    document.getElementById('hm_empty').style.display = 'none';
    document.getElementById('hm_loading').style.display = 'block';
    
    historyModal.show();

    try {
        const res = await fetch(`<?= URL_PREFIX ?>/status_management.php?history=1&student_id=${studentId}`);
        const data = await res.json();
        
        document.getElementById('hm_loading').style.display = 'none';
        
        if (!data.ok || !data.history || data.history.length === 0) {
            document.getElementById('hm_empty').style.display = 'block';
            return;
        }

        const timeline = document.getElementById('hm_timeline');
        timeline.innerHTML = '';
        
        data.history.forEach(h => {
            const li = document.createElement('li');
            li.className = 'history-item';
            
            const date = new Date(h.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
            
            li.innerHTML = `
                <div class="history-date">${date}</div>
                <div class="history-change">
                    <span class="status-pill status-${h.old_status}" style="padding:.15rem .4rem; font-size:.65rem;">${h.old_status}</span>
                    <i class="bi bi-arrow-right mx-1 text-muted"></i>
                    <span class="status-pill status-${h.new_status}" style="padding:.15rem .4rem; font-size:.65rem;">${h.new_status}</span>
                </div>
                <div class="history-reason">"${h.reason}"</div>
                <div class="history-user"><i class="bi bi-person-fill"></i> Changed by: ${h.changed_by_name || 'System'}</div>
            `;
            timeline.appendChild(li);
        });
        
        timeline.style.display = 'block';

    } catch (err) {
        document.getElementById('hm_loading').style.display = 'none';
        document.getElementById('hm_empty').style.display = 'block';
        document.getElementById('hm_empty').textContent = 'Failed to load history.';
    }
}
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>