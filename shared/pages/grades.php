<?php
// shared/pages/grades.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';

$__nav_section = 'grades';

/* ── Role gate ── */
if (!in_array($_SESSION['admin_role'] ?? '', ['superadmin', 'registrar', 'teacher'], true)) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: ' . URL_PREFIX . '/dashboard.php'); exit;
}

/* ═══════════════════════════════════════════════════════════
   FILTERS & PAGINATION SETUP
   ═══════════════════════════════════════════════════════════ */
$search         = trim((string)($_GET['search'] ?? ''));
$grade_filter   = trim((string)($_GET['grade_level'] ?? ''));
$section_filter = trim((string)($_GET['section'] ?? ''));

$page = max(1, (int)($_GET['page'] ?? 1));
$sections_per_page = 5; // Show 5 sections per page

/* ═══════════════════════════════════════════════════════════
   FETCH DATA
   ═══════════════════════════════════════════════════════════ */
$where = ["COALESCE(e.status, 'Active') = 'Active'"];
$params = [];
$types = "";

if ($search !== '') {
    $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
    $types .= "ssss";
}

if ($grade_filter !== '') {
    $where[] = "e.grade_level = ?";
    $params[] = $grade_filter;
    $types .= "s";
}

if ($section_filter !== '') {
    $where[] = "e.section LIKE ?";
    $params[] = "%$section_filter%";
    $types .= "s";
}

$where_sql = implode(' AND ', $where);

$sql = "
    SELECT s.student_id, s.student_id_number, s.first_name, s.last_name, s.middle_name, s.lrn, 
           e.grade_level, e.section, e.strand, e.school_year
    FROM students_info s
    INNER JOIN enrollment_form e ON e.student_id = s.student_id
    WHERE $where_sql
    ORDER BY e.section ASC, s.last_name ASC, s.first_name ASC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$all_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Group Students by Section */
$students_by_section = [];
foreach ($all_students as $s) {
    $sec_name = !empty($s['section']) ? $s['section'] : 'Unassigned Section';
    $students_by_section[$sec_name][] = $s;
}

/* Calculate Pagination based on Sections */
$total_sections = count($students_by_section);
$total_pages    = max(1, (int)ceil($total_sections / $sections_per_page));
$page           = max(1, min($total_pages, $page)); // Recalculate after counting
$offset         = ($page - 1) * $sections_per_page;

$paginated_sections = array_slice($students_by_section, $offset, $sections_per_page, true);
$total_students     = count($all_students);

/* Base URL for Pagination Links */
$base_url = "?search=" . urlencode($search) 
          . "&grade_level=" . urlencode($grade_filter) 
          . "&section=" . urlencode($section_filter);

/* Fetch dropdown options */
$grades_data = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form WHERE grade_level IS NOT NULL AND grade_level != '' ORDER BY grade_level ASC")->fetch_all(MYSQLI_ASSOC);

$page_title = 'Manage Grades • USAT Admin';
$page_css = <<<'CSS'
    /* Stat Grid Hover */
    .stat-card { transition: transform .2s ease, box-shadow .2s ease; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

    /* Section Accordion */
    .section-group {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        margin-bottom: 1rem;
        overflow: hidden;
        box-shadow: var(--shadow-sm);
        transition: border-color .2s, box-shadow .2s;
    }
    .section-group:hover {
        border-color: var(--border-strong);
        box-shadow: var(--shadow-md);
    }
    
    .section-group > summary {
        list-style: none;
        cursor: pointer;
        background: linear-gradient(to right, var(--surface-2), var(--surface));
        padding: 1rem 1.25rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        user-select: none;
        border-bottom: 1px solid transparent;
        transition: background .2s;
    }
    .section-group > summary:hover { background: var(--surface-2); }
    .section-group[open] > summary { border-bottom-color: var(--border); }
    .section-group > summary::-webkit-details-marker { display: none; }
    
    /* Caret Icon */
    .section-caret {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 8px;
        background: var(--surface);
        border: 1px solid var(--border);
        color: var(--text-2);
        transition: transform .2s, background .2s, color .2s;
        flex-shrink: 0;
    }
    .section-group[open] .section-caret {
        transform: rotate(90deg);
        background: var(--accent);
        color: #fff;
        border-color: var(--accent);
    }

    .section-title-wrap { display: flex; align-items: center; gap: 1rem; flex: 1; min-width: 0; }
    
    /* Stacked Text Layout */
    .section-text-stack { display: flex; flex-direction: column; gap: 0.25rem; min-width: 0; flex: 1; }
    .section-top-row { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
    .section-name { font-size: 1.05rem; font-weight: 800; color: var(--text); letter-spacing: -0.01em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .section-bottom-row { display: flex; align-items: center; gap: 1rem; font-size: 0.78rem; color: var(--text-2); }
    
    .section-badge { font-size: 0.65rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 6px; background: var(--accent-soft); color: var(--accent); border: 1px solid color-mix(in srgb, var(--accent) 20%, transparent); }
    
    .section-meta { display: flex; gap: 0.75rem; align-items: center; font-size: 0.78rem; color: var(--text-2); font-weight: 600; }
    .section-meta i { color: var(--text-3); margin-right: 0.2rem; }

    .section-body { padding: 0; background: var(--surface); overflow-x: auto; }
    
    /* Table overrides inside sections */
    .section-body .table-admin { margin: 0; }
    .section-body .table-admin thead th { background: var(--surface); padding-top: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border); }
    .section-body .table-admin tbody td { padding: 0.7rem 1rem; border-bottom: 1px dashed var(--border); }
    .section-body .table-admin tbody tr:last-child td { border-bottom: none; }
    .section-body .table-admin tbody tr:hover td { background: var(--surface-2); }

    .student-avatar-mini {
        width: 32px; height: 32px; border-radius: 8px;
        background: linear-gradient(135deg, var(--accent), var(--purple));
        color: #fff; font-weight: 700; font-size: 0.75rem;
        display: inline-flex; align-items: center; justify-content: center;
        margin-right: 0.75rem; flex-shrink: 0;
    }

    /* Empty State */
    .empty-state {
        padding: 3rem 1.5rem; text-align: center;
        background: var(--surface); border: 1px dashed var(--border);
        border-radius: var(--radius-lg); margin-bottom: 1.5rem;
    }
    .empty-state-icon {
        width: 64px; height: 64px; border-radius: 50%;
        background: var(--surface-2); color: var(--text-3);
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 1.5rem; margin-bottom: 1rem;
    }
CSS;

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/sidebar.php';
?>

<div class="main-content" id="mainContent">
    <?php if (isset($_SESSION['success'])): ?><div class="flash success"><i class="bi bi-check-circle-fill"></i><span><?= htmlspecialchars($_SESSION['success']) ?></span><button class="btn-close" data-bs-dismiss="alert"></button></div><?php unset($_SESSION['success']); endif; ?>
    <?php if (isset($_SESSION['error'])): ?><div class="flash error"><i class="bi bi-exclamation-triangle-fill"></i><span><?= htmlspecialchars($_SESSION['error']) ?></span><button class="btn-close" data-bs-dismiss="alert"></button></div><?php unset($_SESSION['error']); endif; ?>

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h2>Manage Grades</h2>
                <p class="sub">Select a student to view or edit their academic records</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <button class="theme-btn" id="themeToggle" title="Toggle theme"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>
        </div>
    </div>

    <!-- Stat Grid -->
    <div class="stat-grid">
        <div class="stat-card tone-blue">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_students) ?></div>
                <div class="stat-label">Total Students Found</div>
            </div>
        </div>
        <div class="stat-card tone-green">
            <div class="stat-icon"><i class="bi bi-diagram-3-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_sections) ?></div>
                <div class="stat-label">Total Sections</div>
            </div>
        </div>
        <!-- Replaced the useless search card with a Filter Status card -->
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="bi bi-funnel-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value" style="font-size:1.1rem; margin-top:.25rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    <?php if ($search !== '' || $grade_filter !== '' || $section_filter !== ''): ?>
                        Filtered
                    <?php else: ?>
                        All Active
                    <?php endif; ?>
                </div>
                <div class="stat-label">Current View</div>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card mb-4">
        <div class="card-header"><h3><i class="bi bi-funnel"></i> Filters & Search</h3></div>
        <div class="card-body">
            <form class="filter-bar" method="GET" style="grid-template-columns: minmax(200px,1fr) minmax(150px,1fr) minmax(200px,1fr) auto;">
                <div class="field">
                    <label>Student Search</label>
                    <input type="text" name="search" placeholder="Name, LRN, or Student ID..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="field">
                    <label>Grade Level</label>
                    <select name="grade_level">
                        <option value="">All Grades</option>
                        <?php foreach($grades_data as $gl): ?>
                            <option value="<?= htmlspecialchars($gl['grade_level']) ?>" <?= $gl['grade_level'] === $grade_filter ? 'selected' : '' ?>>
                                <?= htmlspecialchars($gl['grade_level']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Section Search</label>
                    <input type="text" name="section" placeholder="e.g. ICT A" value="<?= htmlspecialchars($section_filter) ?>">
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i> Search</button>
                    <a href="<?= URL_PREFIX ?>/grades.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle me-1"></i> Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Main Content -->
    <?php if (empty($paginated_sections)): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="bi bi-search"></i></div>
            <h4 style="font-weight:700; color:var(--text); margin-bottom:.5rem">No students found</h4>
            <p style="color:var(--text-2); font-size:.9rem;">Try adjusting your search terms or clearing the filters to see all active students.</p>
            <a href="<?= URL_PREFIX ?>/grades.php" class="btn btn-outline-primary btn-sm mt-3"><i class="bi bi-arrow-counterclockwise me-1"></i> Clear All Filters</a>
        </div>
    <?php else: ?>
        
        <!-- Toolbar for expanding/collapsing -->
        <div style="display:flex; justify-content:flex-end; margin-bottom:.75rem; gap:.5rem;">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.querySelectorAll('.section-group').forEach(d => d.open = true)">
                <i class="bi bi-arrows-expand me-1"></i> Expand All
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.querySelectorAll('.section-group').forEach(d => d.open = false)">
                <i class="bi bi-arrows-collapse me-1"></i> Collapse All
            </button>
        </div>

        <?php foreach ($paginated_sections as $section_name => $students): 
            $first_student = $students[0];
            $sec_grade = $first_student['grade_level'] ?? '—';
            $sec_strand = $first_student['strand'] ?? '—';
        ?>
            <details class="section-group" open>
                <summary style="padding: 1rem 1.25rem;">
                    <div style="display:flex; align-items:center; gap:1rem; flex:1; min-width:0;">
                        
                        <!-- Caret Icon -->
                        <div class="section-caret"><i class="bi bi-chevron-right"></i></div>
                        
                        <!-- Stacked Info -->
                        <div style="display:flex; flex-direction:column; gap:.25rem; min-width:0; flex:1;">
                            <!-- Line 1: Section Name + Grade -->
                            <div style="display:flex; align-items:center; gap:.75rem; flex-wrap:wrap;">
                                <span class="section-name">
                                    <?= htmlspecialchars($section_name) ?>
                                </span>
                                <span class="pill primary section-badge">
                                    <?= htmlspecialchars($sec_grade) ?>
                                </span>
                            </div>
                            
                            <!-- Line 2: Strand & Count -->
                            <div class="section-bottom-row">
                                <span style="display:flex; align-items:center; gap:.3rem;">
                                    <i class="bi bi-diagram-3"></i> <?= htmlspecialchars($sec_strand) ?>
                                </span>
                                <span style="display:flex; align-items:center; gap:.3rem; font-weight:600; color:var(--accent);">
                                    <i class="bi bi-people-fill"></i> <?= count($students) ?> students
                                </span>
                            </div>
                        </div>

                    </div>
                </summary>
                
                <div class="section-body">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th style="width:45%;">Student Name</th>
                                <th style="width:25%;">LRN / Student ID</th>
                                <th style="width:15%;">Status</th>
                                <th style="width:15%;text-align:right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $s): 
                                $name = trim(($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? '') . (!empty($s['middle_name']) ? ' ' . substr($s['middle_name'], 0, 1) . '.' : ''));
                                $initials = strtoupper(substr($s['first_name'] ?? '', 0, 1) . substr($s['last_name'] ?? '', 0, 1));
                            ?>
                            <tr>
                                <td>
                                    <div style="display:flex; align-items:center;">
                                        <div class="student-avatar-mini"><?= htmlspecialchars($initials) ?></div>
                                        <div>
                                            <div class="cell-strong" style="font-size:.9rem"><?= htmlspecialchars($name) ?></div>
                                            <div style="font-size:.72rem; color:var(--text-3); margin-top:.15rem">ID: <?= htmlspecialchars($s['student_id_number'] ?: '—') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="cell-muted" style="font-variant-numeric:tabular-nums">
                                    <?= htmlspecialchars($s['lrn'] ?: '—') ?>
                                </td>
                                <td>
                                    <span class="pill success" style="font-size:.65rem"><i class="bi bi-circle-fill"></i> Active</span>
                                </td>
                                <td style="text-align:right">
                                    <a href="<?= URL_PREFIX ?>/add_grades.php?student_id=<?= (int)$s['student_id'] ?>" class="btn btn-sm btn-primary" style="padding:.4rem .8rem; font-size:.8rem; border-radius:8px;">
                                        <i class="bi bi-journal-check me-1"></i> Manage Grades
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endforeach; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-wrap" style="display:flex; justify-content:space-between; align-items:center; padding:1.25rem 0 0; margin-top:1.5rem; border-top:1px solid var(--border); flex-wrap:wrap; gap:1rem;">
            <div style="font-size:.85rem; color:var(--text-2); font-weight:500;">
                Showing Sections <strong><?= number_format($offset + 1) ?>–<?= number_format(min($offset + $sections_per_page, $total_sections)) ?></strong> of <strong><?= number_format($total_sections) ?></strong>
            </div>
            <div class="pagination" style="display:inline-flex; align-items:center; gap:.25rem; background:var(--surface-2); border:1px solid var(--border); border-radius:10px; padding:.25rem;">
                <a href="<?= $base_url ?>&page=1" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" style="display:inline-flex; align-items:center; justify-content:center; min-width:36px; height:36px; border-radius:8px; border:none; background:transparent; color:var(--text-2); text-decoration:none; transition:all .15s; <?= $page <= 1 ? 'opacity:.4; cursor:not-allowed;' : 'cursor:pointer;' ?>">
                    <i class="bi bi-chevron-double-left"></i>
                </a>
                <a href="<?= $base_url ?>&page=<?= max(1, $page - 1) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" style="display:inline-flex; align-items:center; justify-content:center; min-width:36px; height:36px; border-radius:8px; border:none; background:transparent; color:var(--text-2); text-decoration:none; transition:all .15s; <?= $page <= 1 ? 'opacity:.4; cursor:not-allowed;' : 'cursor:pointer;' ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
                
                <?php 
                $start_page = max(1, $page - 2); 
                $end_page = min($total_pages, $page + 2); 
                for ($i = $start_page; $i <= $end_page; $i++): 
                ?>
                    <a href="<?= $base_url ?>&page=<?= $i ?>" class="page-btn" style="display:inline-flex; align-items:center; justify-content:center; min-width:36px; height:36px; border-radius:8px; border:none; background:<?= $i === $page ? 'var(--accent)' : 'transparent' ?>; color:<?= $i === $page ? '#fff' : 'var(--text-2)' ?>; font-size:.85rem; font-weight:600; text-decoration:none; transition:all .15s;">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                
                <a href="<?= $base_url ?>&page=<?= min($total_pages, $page + 1) ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>" style="display:inline-flex; align-items:center; justify-content:center; min-width:36px; height:36px; border-radius:8px; border:none; background:transparent; color:var(--text-2); text-decoration:none; transition:all .15s; <?= $page >= $total_pages ? 'opacity:.4; cursor:not-allowed;' : 'cursor:pointer;' ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
                <a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>" style="display:inline-flex; align-items:center; justify-content:center; min-width:36px; height:36px; border-radius:8px; border:none; background:transparent; color:var(--text-2); text-decoration:none; transition:all .15s; <?= $page >= $total_pages ? 'opacity:.4; cursor:not-allowed;' : 'cursor:pointer;' ?>">
                    <i class="bi bi-chevron-double-right"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>