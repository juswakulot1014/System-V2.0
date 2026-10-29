<?php
// shared/pages/grades.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../lib/teacher_scope.php';   // PATCH 1: helper

$__nav_section = 'grades';

// PATCH 2: teacher scope state
$__is_teacher = (($_SESSION['admin_role'] ?? '') === 'teacher');
$__teacher_id = $__is_teacher ? (int)($_SESSION['admin_id'] ?? 0) : 0;

/* ── Role gate ── */
if (!in_array($_SESSION['admin_role'] ?? '', ['superadmin', 'registrar', 'teacher'], true)) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: ' . URL_PREFIX . '/dashboard.php'); exit;
}

/* ═══════════════════════════════════════════════════════════
   FILTERS & PAGINATION SETUP
   ═══════════════════════════════════════════════════════════ */
$search = trim((string)($_GET['search'] ?? ''));

// Default to the latest School Year if not explicitly set in the URL
if (isset($_GET['school_year'])) {
    $sy_filter = trim((string)$_GET['school_year']);
} else {
    $latest_sy_q = $conn->query("SELECT school_year FROM enrollment_form WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC LIMIT 1");
    $sy_filter = ($latest_sy_q && $row = $latest_sy_q->fetch_assoc()) ? $row['school_year'] : '';
}

$grade_filter   = trim((string)($_GET['grade_level'] ?? ''));
$section_filter = trim((string)($_GET['section'] ?? ''));

$page = max(1, (int)($_GET['page'] ?? 1));
$sections_per_page = 5;

/* ═══════════════════════════════════════════════════════════
   BUILD WHERE CLAUSE (Applies to both counts and data fetch)
   ═══════════════════════════════════════════════════════════ */
$where = ["COALESCE(e.status, 'Active') = 'Active'"];
$params = [];
$types = "";

// PATCH 3: teacher scope FIRST
if ($__is_teacher) {
    $ts = teacher_scope_filter($conn, $__teacher_id, 'e');
    $where[] = $ts['sql'];
    $params = array_merge($params, $ts['params']);
    $types .= $ts['types'];
}

if ($search !== '') {
    $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
    $types .= "ssss";
}

if ($sy_filter !== '') {
    $where[] = "e.school_year = ?";
    $params[] = $sy_filter;
    $types .= "s";
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

/* ═══════════════════════════════════════════════════════════
   STEP 1: COUNT DISTINCT SECTIONS (For Pagination)
   ═══════════════════════════════════════════════════════════ */
$count_sql = "
    SELECT COUNT(DISTINCT e.section, e.grade_level) AS total
    FROM students_info s
    INNER JOIN enrollment_form e ON e.student_id = s.student_id
    WHERE $where_sql
";
$stmt = $conn->prepare($count_sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total_sections = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$total_pages = max(1, (int)ceil($total_sections / $sections_per_page));
$page = max(1, min($total_pages, $page));
$offset = ($page - 1) * $sections_per_page;

/* ═══════════════════════════════════════════════════════════
   STEP 2: FETCH ONLY THE SECTIONS FOR THIS PAGE
   ═══════════════════════════════════════════════════════════ */
$paginated_sections = [];
$total_students = 0;

if ($total_sections > 0) {
    $sec_sql = "
        SELECT e.section, e.grade_level, MAX(e.strand) AS strand, COUNT(DISTINCT s.student_id) AS student_count
        FROM students_info s
        INNER JOIN enrollment_form e ON e.student_id = s.student_id
        WHERE $where_sql
        GROUP BY e.section, e.grade_level
        ORDER BY e.grade_level ASC, e.section ASC
        LIMIT ? OFFSET ?
    ";
    $sec_params = array_merge($params, [$sections_per_page, $offset]);
    $sec_types = $types . "ii";
    
    $stmt = $conn->prepare($sec_sql);
    if (!empty($sec_params)) $stmt->bind_param($sec_types, ...$sec_params);
    $stmt->execute();
    $section_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!empty($section_rows)) {
        // Build an IN clause for the sections we just fetched
        $sec_names = array_column($section_rows, 'section');
        $in_placeholders = implode(',', array_fill(0, count($sec_names), '?'));
        $in_types = $types . str_repeat('s', count($sec_names));
        $in_params = array_merge($params, $sec_names);

        // Fetch students ONLY for these specific sections
        $stu_sql = "
            SELECT s.student_id, s.student_id_number, s.first_name, s.last_name, s.middle_name, s.lrn, 
                   e.grade_level, e.section, e.strand, e.school_year
            FROM students_info s
            INNER JOIN enrollment_form e ON e.student_id = s.student_id
            WHERE $where_sql AND e.section IN ($in_placeholders)
            ORDER BY e.section ASC, s.last_name ASC, s.first_name ASC
        ";
        $stmt = $conn->prepare($stu_sql);
        $stmt->bind_param($in_types, ...$in_params);
        $stmt->execute();
        $students_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Group students into their sections
        $grouped_students = [];
        foreach ($students_raw as $stu) {
            $grouped_students[$stu['section']][] = $stu;
            $total_students++;
        }

        // Build the final paginated array with section metadata
        foreach ($section_rows as $row) {
            $sec = $row['section'];
            $paginated_sections[$sec] = [
                'grade_level' => $row['grade_level'],
                'strand'      => $row['strand'],
                'students'    => $grouped_students[$sec] ?? []
            ];
        }
    }
}

/* Base URL for Pagination Links */
$base_url = "?search=" . urlencode($search) 
          . "&school_year=" . urlencode($sy_filter)
          . "&grade_level=" . urlencode($grade_filter) 
          . "&section=" . urlencode($section_filter);

/* ═══════════════════════════════════════════════════════════
   PATCH 4: Filter Dropdowns (Scoped for teachers)
   ═══════════════════════════════════════════════════════════ */
if ($__is_teacher) {
    $ts = teacher_scope_filter($conn, $__teacher_id, 'enrollment_form');

    // School Years
    $sys = $conn->prepare("SELECT DISTINCT school_year FROM enrollment_form WHERE school_year IS NOT NULL AND school_year != '' AND " . $ts['sql'] . " ORDER BY school_year DESC");
    if ($sys) {
        if (!empty($ts['params'])) $sys->bind_param($ts['types'], ...$ts['params']);
        $sys->execute();
        $sy_data = $sys->get_result()->fetch_all(MYSQLI_ASSOC);
        $sys->close();
    } else { $sy_data = []; }

    // Grade Levels
    $gdsql = $conn->prepare("SELECT DISTINCT grade_level FROM enrollment_form WHERE grade_level IS NOT NULL AND grade_level != '' AND " . $ts['sql'] . " ORDER BY grade_level ASC");
    if ($gdsql) {
        if (!empty($ts['params'])) $gdsql->bind_param($ts['types'], ...$ts['params']);
        $gdsql->execute();
        $grades_data = $gdsql->get_result()->fetch_all(MYSQLI_ASSOC);
        $gdsql->close();
    } else { $grades_data = []; }
} else {
    $sy_data = $conn->query("SELECT DISTINCT school_year FROM enrollment_form WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC")->fetch_all(MYSQLI_ASSOC);
    $grades_data = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form WHERE grade_level IS NOT NULL AND grade_level != '' ORDER BY grade_level ASC")->fetch_all(MYSQLI_ASSOC);
}

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
    
    .section-caret {
        display: inline-flex; align-items: center; justify-content: center;
        width: 28px; height: 28px; border-radius: 8px;
        background: var(--surface); border: 1px solid var(--border);
        color: var(--text-2); transition: transform .2s, background .2s, color .2s;
        flex-shrink: 0;
    }
    .section-group[open] .section-caret {
        transform: rotate(90deg); background: var(--accent); color: #fff; border-color: var(--accent);
    }

    .section-title-wrap { display: flex; align-items: center; gap: 1rem; flex: 1; min-width: 0; }
    .section-text-stack { display: flex; flex-direction: column; gap: 0.25rem; min-width: 0; flex: 1; }
    .section-top-row { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
    .section-name { font-size: 1.05rem; font-weight: 800; color: var(--text); letter-spacing: -0.01em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .section-bottom-row { display: flex; align-items: center; gap: 1rem; font-size: 0.78rem; color: var(--text-2); }
    .section-badge { font-size: 0.65rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 6px; background: var(--accent-soft); color: var(--accent); border: 1px solid color-mix(in srgb, var(--accent) 20%, transparent); }

    .section-body { padding: 0; background: var(--surface); overflow-x: auto; }
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

    /* Pagination */
    .pagination-bar { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 0 0; margin-top: 1.5rem; border-top: 1px solid var(--border); flex-wrap: wrap; gap: 1rem; }
    .page-info { font-size: .85rem; color: var(--text-2); font-weight: 500; }
    .pagination { display: inline-flex; align-items: center; gap: .25rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; padding: .25rem; }
    .page-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; border-radius: 8px; border: none; background: transparent; color: var(--text-2); font-size: .85rem; font-weight: 600; text-decoration: none; transition: all .15s; }
    .page-btn:hover:not(.disabled) { background: var(--surface); color: var(--accent); box-shadow: 0 2px 6px rgba(0,0,0,.05); }
    .page-btn.disabled { opacity: .4; cursor: not-allowed; }
    .page-btn.active { background: var(--accent); color: #fff; }
    .page-btn.active:hover { background: var(--accent); color: #fff; }
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
                <div class="stat-label">Students (Current Page)</div>
            </div>
        </div>
        <div class="stat-card tone-green">
            <div class="stat-icon"><i class="bi bi-diagram-3-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_sections) ?></div>
                <div class="stat-label">Total Sections Found</div>
            </div>
        </div>
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="bi bi-funnel-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value" style="font-size:1.1rem; margin-top:.25rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    <?php if ($search !== '' || $sy_filter !== '' || $grade_filter !== '' || $section_filter !== ''): ?>
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
            <form class="filter-bar" method="GET" style="grid-template-columns: minmax(200px,1fr) minmax(140px,1fr) minmax(140px,1fr) minmax(200px,1fr) auto;">
                <div class="field">
                    <label>Student Search</label>
                    <input type="text" name="search" placeholder="Name, LRN, or ID..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="field">
                    <label>School Year</label>
                    <select name="school_year">
                        <option value="">All Years</option>
                        <?php foreach($sy_data as $sy): ?>
                            <option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $sy['school_year'] === $sy_filter ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sy['school_year']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
            <a href="<?= URL_PREFIX ?>/grades.php?school_year=" class="btn btn-outline-primary btn-sm mt-3"><i class="bi bi-arrow-counterclockwise me-1"></i> Clear All Filters</a>
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

        <?php foreach ($paginated_sections as $section_name => $section_data): 
            $sec_grade = $section_data['grade_level'] ?? '—';
            $sec_strand = $section_data['strand'] ?? '—';
            $students = $section_data['students'];
        ?>
            <!-- REMOVED the 'open' attribute here so they start collapsed -->
            <details class="section-group">
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
        <div class="pagination-bar">
            <div class="page-info">
                Showing Sections <strong><?= number_format($offset + 1) ?>–<?= number_format(min($offset + $sections_per_page, $total_sections)) ?></strong> of <strong><?= number_format($total_sections) ?></strong>
            </div>
            <div class="pagination">
                <a href="<?= $base_url ?>&page=1" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-left"></i></a>
                <a href="<?= $base_url ?>&page=<?= max(1, $page - 1) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-left"></i></a>
                
                <?php 
                $start_page = max(1, $page - 2); 
                $end_page = min($total_pages, $page + 2); 
                for ($i = $start_page; $i <= $end_page; $i++): 
                ?>
                    <a href="<?= $base_url ?>&page=<?= $i ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                
                <a href="<?= $base_url ?>&page=<?= min($total_pages, $page + 1) ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
                <a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-right"></i></a>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>