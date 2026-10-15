<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/security.php';

/* ── Role gate ────────────────────────────────────────────────── */
if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)) {
    http_response_code(403);
    security_log('graduates.forbidden', [
        'admin_id' => (int)($_SESSION['admin_id'] ?? 0),
        'ip'       => client_ip(),
    ]);
    exit('Forbidden');
}

function formatStudentName(array $s): string {
    $mi  = !empty($s['middle_name']) ? ' ' . strtoupper(substr(trim($s['middle_name']), 0, 1)) . '.' : '';
    $ext = !empty($s['ext_name'])    ? ' ' . trim($s['ext_name']) : '';
    return trim(($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? '') . $mi . $ext);
}

/* ── Filter: optional search by name ── */
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

$csrf_token = csrf_token();
$theme      = ($_COOKIE['admin_theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Graduates • USAT Admin</title>
    <meta name="robots" content="noindex, nofollow">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root {
            --bg:#f4f6fb;--surface:#fff;--surface-2:#f8fafc;
            --text:#0f172a;--text-2:#64748b;--text-3:#94a3b8;
            --border:#e5e7eb;--border-strong:#d1d5db;
            --accent:#4f46e5;--accent-2:#6366f1;--accent-soft:#eef2ff;
            --purple:#7c3aed;--purple-soft:#ede9fe;
            --shadow-sm:0 1px 3px rgba(15,23,42,0.06),0 1px 2px rgba(15,23,42,0.04);
            --radius:12px;--radius-lg:16px;
            --sidebar-w:264px;
        }
        [data-bs-theme="dark"]{
            --bg:#0b1220;--surface:#131c2e;--surface-2:#1a2439;
            --text:#f1f5f9;--text-2:#94a3b8;--text-3:#64748b;
            --border:#1f2a44;--border-strong:#2d3b57;
            --accent:#818cf8;--accent-2:#6366f1;--accent-soft:#1e2140;
            --purple-soft:#2e1a54;
            --shadow-sm:0 1px 3px rgba(0,0,0,0.35);
        }
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased}

        .sidebar{position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);z-index:200;display:flex;flex-direction:column;transition:transform .28s}
        .sidebar-brand{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem}
        .sidebar-brand img{width:38px;height:38px;border-radius:10px;object-fit:cover;box-shadow:0 0 0 3px var(--accent-soft)}
        .sidebar-brand .brand-name{font-weight:700;font-size:.95rem}
        .sidebar-brand .brand-sub{font-size:.68rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;font-weight:600}
        .sidebar-nav{flex:1;padding:.75rem .75rem 1rem;overflow-y:auto}
        .sidebar-nav .nav-label{font-size:.65rem;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.8px;padding:.75rem .75rem .35rem}
        .sidebar-nav a{display:flex;align-items:center;gap:.75rem;padding:.6rem .75rem;border-radius:10px;color:var(--text-2);text-decoration:none;font-weight:500;font-size:.875rem;margin-bottom:.1rem;transition:background .15s,color .15s}
        .sidebar-nav a:hover{background:var(--surface-2);color:var(--text)}
        .sidebar-nav a.active{background:var(--accent);color:#fff}
        .sidebar-nav a i{font-size:1.05rem;width:20px;text-align:center;flex-shrink:0}
        .sidebar-footer{padding:.75rem;border-top:1px solid var(--border)}

        .main-content{margin-left:var(--sidebar-w);padding:1.5rem clamp(1rem,2.5vw,2rem) 4rem;min-height:100vh;max-width:1400px}
        .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap}
        .topbar h2{font-size:1.35rem;font-weight:700;letter-spacing:-.02em;margin:0}
        .topbar .sub{font-size:.85rem;color:var(--text-2);margin:0}
        .menu-toggle{display:none;width:40px;height:40px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;align-items:center;justify-content:center}
        .breadcrumb-nav{display:flex;align-items:center;gap:.4rem;font-size:.78rem;color:var(--text-2);margin-bottom:.25rem}
        .breadcrumb-nav a{color:var(--accent);text-decoration:none;font-weight:500}
        .theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text-2);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s}
        .theme-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}

        .btn{font-weight:500;border-radius:9px;font-size:.82rem}
        .btn-primary{background:var(--accent);border-color:var(--accent)}
        .btn-primary:hover{background:var(--accent-2);border-color:var(--accent-2)}
        .btn-outline-secondary{color:var(--text-2);border-color:var(--border)}
        .btn-outline-secondary:hover{background:var(--surface-2);color:var(--text);border-color:var(--border-strong)}

        .card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow-sm);margin-bottom:1.25rem;overflow:hidden}
        .card-header{padding:.9rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
        .card-header h3{margin:0;font-size:.9rem;font-weight:600;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
        .card-header h3 i{color:var(--accent)}
        .card-body{padding:1.25rem}

        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.25rem}
        .stat-card{position:relative;background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow-sm);padding:1rem 1.15rem 1rem 1.3rem;display:flex;align-items:center;gap:.9rem;overflow:hidden}
        .stat-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--purple)}
        .stat-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;background:var(--purple-soft);color:var(--purple)}
        .stat-value{font-size:1.45rem;font-weight:700;letter-spacing:-.02em;font-variant-numeric:tabular-nums;line-height:1.1}
        .stat-label{font-size:.68rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;margin-top:.2rem;font-weight:600}

        .search-input{background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:.5rem .8rem;font-size:.85rem;color:var(--text);font-family:inherit;width:260px;max-width:100%}
        .search-input:focus{border-color:var(--accent);background:var(--surface);outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%,transparent)}

        .sy-group{border:1px solid var(--border);border-radius:var(--radius);margin-bottom:.75rem;overflow:hidden;background:var(--surface)}
        .sy-group summary{padding:.85rem 1.1rem;cursor:pointer;font-weight:600;background:var(--surface-2);display:flex;align-items:center;gap:.6rem;list-style:none}
        .sy-group summary::-webkit-details-marker{display:none}
        .sy-group summary::after{content:'\F282';font-family:'bootstrap-icons';margin-left:auto;color:var(--text-2);transition:transform .2s}
        .sy-group[open] summary::after{transform:rotate(180deg)}
        .sy-group summary i{color:var(--purple)}
        .sy-count{margin-left:.5rem;font-size:.7rem;font-weight:600;padding:.15rem .55rem;border-radius:6px;background:var(--purple-soft);color:var(--purple)}

        .table-scroll{overflow-x:auto}
        .table-admin{width:100%;border-collapse:separate;border-spacing:0}
        .table-admin thead th{background:var(--surface-2);font-size:.66rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;padding:.7rem 1rem;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
        .table-admin tbody td{padding:.65rem 1rem;border-bottom:1px solid var(--border);font-size:.85rem;vertical-align:middle}
        .table-admin tbody tr:hover td{background:color-mix(in srgb,var(--accent) 4%,transparent)}
        .table-admin tbody tr:last-child td{border-bottom:none}
        .cell-muted{color:var(--text-2);font-size:.82rem}
        .cell-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}

        .avatar-sm{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--purple),var(--accent-2));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.75rem;flex-shrink:0}
        .student-cell{display:flex;align-items:center;gap:.7rem;min-width:0}
        .student-cell .nm{font-weight:600;color:var(--text);line-height:1.2}
        .student-cell .mt{font-size:.72rem;color:var(--text-2);margin-top:.15rem}

        .tag{display:inline-flex;align-items:center;gap:.25rem;padding:.15rem .5rem;border-radius:6px;font-size:.68rem;font-weight:600;background:var(--surface-2);color:var(--text-2);border:1px solid var(--border);white-space:nowrap}

        .empty{padding:4rem 1.5rem;text-align:center;color:var(--text-2)}
        .empty .empty-icon{width:64px;height:64px;border-radius:50%;background:var(--surface-2);color:var(--text-3);display:inline-flex;align-items:center;justify-content:center;font-size:1.6rem;margin-bottom:1rem}
        .empty .empty-title{font-weight:600;color:var(--text);margin-bottom:.25rem}

        @media (max-width:1024px){
            .sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}
            .main-content{margin-left:0}.menu-toggle{display:flex}
        }
        @media (max-width:768px){
            .stat-grid{grid-template-columns:repeat(2,1fr)}
            .search-input{width:100%}
        }
    </style>
</head>
<body>

<?php
    $__current = basename($_SERVER['PHP_SELF']);
    $__super   = (($_SESSION['admin_role'] ?? '') === 'superadmin');
    $__student_pages = ['student_profile.php','view_student.php','edit_student.php','export_students.php','sections_list.php','sf10.php'];
    $__act = in_array($__current, $__student_pages, true) ? 'student_profile.php' : $__current;
    $__a   = fn($f) => $f === $__act ? ' class="active"' : '';
    $__pending_count = (int)($conn->query("SELECT COUNT(*) c FROM enrollment_form WHERE status='Pending'")->fetch_assoc()['c'] ?? 0);
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="../assets/img/usat.jpg" alt="USAT" onerror="this.style.background='var(--accent)'">
        <div>
            <div class="brand-name">USAT Admin</div>
            <div class="brand-sub">Enrollment System</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Overview</div>
        <a href="dashboard.php"<?= $__a('dashboard.php') ?>><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="student_profile.php"<?= $__a('student_profile.php') ?>><i class="bi bi-people-fill"></i> Students</a>
        <a href="pending_enrollments.php"<?= $__a('pending_enrollments.php') ?>>
            <i class="bi bi-hourglass-split"></i> Pending
            <?php if ($__pending_count > 0): ?>
                <span style="margin-left:auto;background:#d97706;color:#fff;font-size:.68rem;padding:.15rem .5rem;border-radius:999px;font-weight:700;line-height:1"><?= $__pending_count ?></span>
            <?php endif; ?>
        </a>
        <a href="promote.php"<?= $__a('promote.php') ?>><i class="bi bi-rocket-takeoff"></i> Promote</a>
        <a href="graduates.php"<?= $__a('graduates.php') ?>><i class="bi bi-mortarboard-fill"></i> Graduates</a>
        <a href="reports.php"<?= $__a('reports.php') ?>><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>

        <?php if ($__super): ?>
        <div class="nav-label" style="margin-top:.5rem">Administration</div>
        <a href="create_account.php"<?= $__a('create_account.php') ?>><i class="bi bi-person-plus"></i> Accounts</a>
        <a href="audit_log.php"<?= $__a('audit_log.php') ?>><i class="bi bi-shield-check"></i> Audit Log</a>
        <?php endif; ?>

        <div class="nav-label" style="margin-top:.5rem">Account</div>
        <a href="profile.php"<?= $__a('profile.php') ?>><i class="bi bi-person-gear"></i> My Profile</a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php" class="btn btn-outline-danger btn-sm w-100">
            <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
    </div>
</aside>

<div class="main-content" id="mainContent">

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <div class="breadcrumb-nav">
                    <a href="promote.php">Promote</a>
                    <i class="bi bi-chevron-right" style="font-size:.7rem;opacity:.6"></i>
                    <span>Graduates</span>
                </div>
                <h2>Graduates</h2>
                <p class="sub">Students who completed Grade 12, grouped by school year</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="promote.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Promote
            </a>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
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
                    <a href="graduates.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle"></i></a>
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
                                            <a href="view_student.php?id=<?= (int)$g['student_id'] ?>" class="btn btn-outline-secondary btn-sm">
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

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);backdrop-filter:blur(2px);z-index:199"
     onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sb = document.getElementById('sidebar');
const ov = document.getElementById('sidebarOverlay');
document.getElementById('menuToggle').addEventListener('click', () => {
    sb.classList.toggle('open');
    ov.style.display = sb.classList.contains('open') ? 'block' : 'none';
});

const tb = document.getElementById('themeToggle');
const ti = document.getElementById('themeIcon');
const h  = document.documentElement;
function st(t){
    h.setAttribute('data-bs-theme', t);
    ti.className = 'bi bi-' + (t === 'dark' ? 'sun-fill' : 'moon-stars-fill');
    document.cookie = 'admin_theme=' + t + ';path=/;max-age=' + (60*60*24*365);
}
(function(){
    const m = document.cookie.match(/(?:^|; )admin_theme=([^;]+)/);
    st(m ? decodeURIComponent(m[1]) : 'light');
})();
tb.addEventListener('click', () => st(h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));
</script>
</body>
</html>