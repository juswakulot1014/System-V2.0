<?php
// shared/pages/graduates.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/security.php';

$__nav_section = 'grading';

if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)) {
    http_response_code(403);
    security_log('graduates.forbidden', [
        'admin_id' => (int)($_SESSION['admin_id'] ?? 0),
        'ip'       => client_ip(),
    ]);
    exit('Forbidden');
}

if (!function_exists('formatStudentName')) {
    function formatStudentName(array $s): string {
        $mi  = !empty($s['middle_name']) ? ' ' . strtoupper(substr(trim($s['middle_name']), 0, 1)) . '.' : '';
        $ext = !empty($s['ext_name'])    ? ' ' . trim($s['ext_name']) : '';
        return trim(($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? '') . $mi . $ext);
    }
}

$search = clean_string($_GET['q'] ?? '', 80);

$sql = "
    SELECT s.student_id, s.student_id_number, s.lrn,
           s.first_name, s.middle_name, s.last_name, s.ext_name, s.sex,
           e.school_year, e.track, e.strand, e.program, e.section
    FROM enrollment_form e
    INNER JOIN students_info s ON s.student_id = e.student_id
    WHERE e.grade_level = 'Grade 12' AND e.status = 'Graduated'
";
$params = [];
$types  = '';
if ($search !== '') {
    $sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like];
    $types  = 'ssss';
}
$sql .= " ORDER BY e.school_year DESC, s.last_name ASC, s.first_name ASC";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$grad_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$grad_by_sy = [];
foreach ($grad_rows as $g) {
    $grad_by_sy[$g['school_year']][] = $g;
}
$total_grads = count($grad_rows);
$sy_count    = count($grad_by_sy);

$page_title = 'Graduates • USAT Admin';

$page_css = <<<'CSS'
    .search-input{background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:.5rem .8rem;font-size:.85rem;color:var(--text);font-family:inherit;width:260px;max-width:100%}
    .search-input:focus{border-color:var(--accent);background:var(--surface);outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%,transparent)}

    .sy-group{border:1px solid var(--border);border-radius:var(--radius);margin-bottom:.75rem;overflow:hidden;background:var(--surface)}
    .sy-group summary{padding:.85rem 1.1rem;cursor:pointer;font-weight:600;background:var(--surface-2);display:flex;align-items:center;gap:.6rem;list-style:none}
    .sy-group summary::-webkit-details-marker{display:none}
    .sy-group summary::after{content:'\F282';font-family:'bootstrap-icons';margin-left:auto;color:var(--text-2);transition:transform .2s}
    .sy-group[open] summary::after{transform:rotate(180deg)}
    .sy-group summary i{color:var(--purple)}
    .sy-count{margin-left:.5rem;font-size:.7rem;font-weight:600;padding:.15rem .55rem;border-radius:6px;background:var(--purple-soft);color:var(--purple)}

    .cell-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
    .avatar-sm{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--purple),var(--accent-2));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.75rem;flex-shrink:0}
    .student-cell{display:flex;align-items:center;gap:.7rem;min-width:0}
    .student-cell .nm{font-weight:600;color:var(--text);line-height:1.2}
    .student-cell .mt{font-size:.72rem;color:var(--text-2);margin-top:.15rem}

    .tag{display:inline-flex;align-items:center;gap:.25rem;padding:.15rem .5rem;border-radius:6px;font-size:.68rem;font-weight:600;background:var(--surface-2);color:var(--text-2);border:1px solid var(--border);white-space:nowrap}

    @media (max-width:768px){
        .search-input{width:100%}
    }
CSS;

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/sidebar.php';
?>

<div class="main-content" id="mainContent">

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle" aria-label="Open menu">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <div style="display:flex;align-items:center;gap:.4rem;font-size:.78rem;color:var(--text-2);margin-bottom:.25rem">
                    <a href="<?= URL_PREFIX ?>/promote.php" style="color:var(--accent);text-decoration:none;font-weight:500">Promote</a>
                    <i class="bi bi-chevron-right" style="font-size:.7rem;opacity:.6"></i>
                    <span>Graduates</span>
                </div>
                <h2>Graduates</h2>
                <p class="sub">Students who completed Grade 12, grouped by school year</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="<?= URL_PREFIX ?>/promote.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Promote
            </a>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="bi bi-mortarboard-fill"></i></div>
            <div>
                <div class="stat-value"><?= number_format($total_grads) ?></div>
                <div class="stat-label">Total Graduates</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-calendar3"></i></div>
            <div>
                <div class="stat-value"><?= number_format($sy_count) ?></div>
                <div class="stat-label">School Years</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-calendar3"></i> By School Year</h3>
            <form method="GET" class="d-flex gap-2 align-items-center">
                <input type="text" name="q" class="search-input"
                       placeholder="Search name, LRN, or ID…"
                       value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
                <?php if ($search !== ''): ?>
                    <a href="<?= URL_PREFIX ?>/graduates.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle"></i></a>
                <?php endif; ?>
            </form>
        </div>
        <div class="card-body">
            <?php if (empty($grad_by_sy)): ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-mortarboard"></i></div>
                    <div class="empty-title"><?= $search !== '' ? 'No graduates match your search' : 'No graduates yet' ?></div>
                    <div class="empty-sub">Students promoted from Grade 12 will appear here by school year.</div>
                </div>
            <?php else: ?>
                <?php $first = true; foreach ($grad_by_sy as $sy => $list): ?>
                    <details class="sy-group" <?= ($first || $search !== '') ? 'open' : '' ?>>
                        <summary>
                            <i class="bi bi-calendar3"></i> SY <?= htmlspecialchars($sy) ?>
                            <span class="sy-count"><?= count($list) ?> graduate<?= count($list) === 1 ? '' : 's' ?></span>
                        </summary>
                        <div class="table-scroll">
                            <table class="table-admin">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th style="width:150px">LRN / ID</th>
                                        <th>Track / Strand</th>
                                        <th style="width:110px">Section</th>
                                        <th style="width:110px">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($list as $g):
                                    $gname   = formatStudentName($g);
                                    $ginit   = strtoupper(substr($g['first_name'] ?? '', 0, 1) . substr($g['last_name'] ?? '', 0, 1));
                                    $gstrand = trim((string)($g['strand'] ?? '')) ?: '—';
                                ?>
                                    <tr>
                                        <td>
                                            <div class="student-cell">
                                                <div class="avatar-sm"><?= htmlspecialchars($ginit) ?></div>
                                                <div>
                                                    <div class="nm"><?= htmlspecialchars($gname) ?></div>
                                                    <div class="mt"><?= htmlspecialchars($g['sex'] ?? '—') ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="cell-mono" style="font-size:.78rem"><?= htmlspecialchars($g['lrn'] ?? '—') ?></div>
                                            <div class="cell-muted" style="font-size:.72rem"><?= htmlspecialchars($g['student_id_number'] ?? '—') ?></div>
                                        </td>
                                        <td>
                                            <div style="font-weight:600;font-size:.82rem"><?= htmlspecialchars($gstrand) ?></div>
                                            <?php if (!empty($g['track'])): ?>
                                                <div class="cell-muted" style="font-size:.72rem"><?= htmlspecialchars($g['track']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($g['section'] ?? '—') ?></td>
                                        <td>
                                            <a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$g['student_id'] ?>" class="btn btn-outline-secondary btn-sm">
                                                <i class="bi bi-eye me-1"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php $first = false; endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>