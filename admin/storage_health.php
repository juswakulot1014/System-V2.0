<?php
$REQUIRE_ROLE = 'superadmin';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/paths.php';

/* ───────────────────────────────────────────────
   POST handlers — purge staging / delete orphan
   ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $type_map = [
        'document' => DOCUMENTS_DIR,
        'photo'    => STUDENTS_PHOTO_DIR,
        'staging'  => STAGING_DIR,
    ];

    // Purge all stale staging files (>2h old)
    if (isset($_POST['purge_staging'])) {
        $n = 0; $freed = 0;
        foreach (glob(STAGING_DIR . '*') ?: [] as $f) {
            if (!is_file($f)) continue;
            if (time() - filemtime($f) < 7200) continue;
            $freed += filesize($f);
            if (@unlink($f)) $n++;
        }
        audit_log($conn, 'storage.purge_staging', ['files' => $n, 'freed' => $freed]);
        $_SESSION['success'] = "Purged $n stale staging file(s) · freed " . humanSize($freed);
        header('Location: storage_health.php'); exit;
    }

    // Delete a single file
    if (isset($_POST['delete_file'])) {
        $type = $_POST['type'] ?? '';
        $file = $_POST['file'] ?? '';

        // basename() check guarantees no path components — simple and airtight
        if (!isset($type_map[$type]) || $file === '' || basename($file) !== $file) {
            $_SESSION['error'] = 'Invalid file reference.';
            header('Location: storage_health.php'); exit;
        }

        $abs = $type_map[$type] . $file;
        if (!is_file($abs)) {
            $_SESSION['error'] = 'File already gone.';
            header('Location: storage_health.php'); exit;
        }

        @unlink($abs);
        audit_log($conn, 'storage.orphan_delete', ['type' => $type, 'file' => $file]);
        $_SESSION['success'] = "Deleted: $file";
        header('Location: storage_health.php'); exit;
    }

    // Bulk delete all orphans in a category
    if (isset($_POST['delete_all_orphans'])) {
        $type = $_POST['type'] ?? '';
        if (!isset($type_map[$type])) {
            $_SESSION['error'] = 'Invalid type.';
            header('Location: storage_health.php'); exit;
        }
        // Rebuild orphan list server-side (never trust the client list)
        $orphans = collect_orphans($conn, $type);
        $n = 0; $freed = 0;
        foreach ($orphans as $f) {
            $abs = $type_map[$type] . $f;
            if (!is_file($abs)) continue;
            $freed += filesize($abs);
            if (@unlink($abs)) $n++;
        }
        audit_log($conn, 'storage.orphan_bulk_delete', ['type' => $type, 'files' => $n, 'freed' => $freed]);
        $_SESSION['success'] = "Deleted $n orphan(s) · freed " . humanSize($freed);
        header('Location: storage_health.php'); exit;
    }
}

/* ───────────────────────────────────────────────
   Helpers
   ─────────────────────────────────────────────── */
function humanSize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return number_format($bytes / 1048576, 2) . ' MB';
    return number_format($bytes / 1073741824, 2) . ' GB';
}

function dir_stats(string $dir): array {
    $count = 0; $size = 0; $newest = 0; $oldest = PHP_INT_MAX;
    foreach (glob($dir . '*') ?: [] as $f) {
        if (!is_file($f)) continue;
        $count++;
        $size += filesize($f);
        $m = filemtime($f);
        if ($m > $newest) $newest = $m;
        if ($m < $oldest) $oldest = $m;
    }
    return [
        'count'   => $count,
        'size'    => $size,
        'newest'  => $newest ?: null,
        'oldest'  => $oldest === PHP_INT_MAX ? null : $oldest,
        'exists'  => is_dir($dir),
        'writable'=> is_writable($dir),
    ];
}

/** Files on disk with no matching DB row. */
function collect_orphans(mysqli $conn, string $type): array {
    if ($type === 'document') {
        $dir = DOCUMENTS_DIR;
        $known = [];
        $res = $conn->query("SELECT file_path FROM entrance_documents WHERE file_path IS NOT NULL AND file_path != ''");
        while ($r = $res->fetch_assoc()) $known[basename($r['file_path'])] = true;

        $orphans = [];
        foreach (glob($dir . '*.enc') ?: [] as $f) {
            $b = basename($f);
            if (!isset($known[$b])) $orphans[] = $b;
        }
        return $orphans;
    }

    if ($type === 'photo') {
        $dir = STUDENTS_PHOTO_DIR;
        $known = [];
        $res = $conn->query("SELECT photo FROM students_info WHERE photo IS NOT NULL AND photo != ''");
        while ($r = $res->fetch_assoc()) $known[basename($r['photo'])] = true;

        $orphans = [];
        foreach (glob($dir . '*') ?: [] as $f) {
            if (!is_file($f)) continue;
            $b = basename($f);
            if (!isset($known[$b])) $orphans[] = $b;
        }
        return $orphans;
    }

    if ($type === 'staging') {
        // Every staging file is "orphan" by definition — they're transient.
        $orphans = [];
        foreach (glob(STAGING_DIR . '*') ?: [] as $f) {
            if (!is_file($f)) continue;
            $orphans[] = basename($f);
        }
        return $orphans;
    }

    return [];
}

/** DB rows pointing to files that don't exist on disk. */
function collect_missing(mysqli $conn, string $type): array {
    $missing = [];
    if ($type === 'document') {
        $res = $conn->query("
            SELECT d.entrance_id, d.student_id, d.document_name, d.file_path,
                   s.last_name, s.first_name
            FROM entrance_documents d
            LEFT JOIN students_info s ON s.student_id = d.student_id
            WHERE d.file_path IS NOT NULL AND d.file_path != ''
            ORDER BY d.entrance_id DESC
        ");
        while ($r = $res->fetch_assoc()) {
            $abs = DOCUMENTS_DIR . basename($r['file_path']);
            if (!is_file($abs)) $missing[] = $r;
        }
    }
    if ($type === 'photo') {
        $res = $conn->query("
            SELECT student_id, last_name, first_name, photo
            FROM students_info
            WHERE photo IS NOT NULL AND photo != ''
            ORDER BY student_id DESC
        ");
        while ($r = $res->fetch_assoc()) {
            $abs = STUDENTS_PHOTO_DIR . basename($r['photo']);
            if (!is_file($abs)) $missing[] = $r;
        }
    }
    return $missing;
}

/* ───────────────────────────────────────────────
   Gather stats
   ─────────────────────────────────────────────── */
$docs_stats    = dir_stats(DOCUMENTS_DIR);
$photos_stats  = dir_stats(STUDENTS_PHOTO_DIR);
$staging_stats = dir_stats(STAGING_DIR);

$orphan_docs   = collect_orphans($conn, 'document');
$orphan_photos = collect_orphans($conn, 'photo');
$missing_docs  = collect_missing($conn, 'document');
$missing_photos= collect_missing($conn, 'photo');

// Stale staging = older than 2h
$stale_staging = [];
foreach (glob(STAGING_DIR . '*') ?: [] as $f) {
    if (!is_file($f)) continue;
    if (time() - filemtime($f) > 7200) {
        $stale_staging[basename($f)] = filesize($f);
    }
}

// Total size on disk
$total_size = $docs_stats['size'] + $photos_stats['size'] + $staging_stats['size'];

$total_issues = count($orphan_docs) + count($orphan_photos)
              + count($missing_docs) + count($missing_photos)
              + count($stale_staging);

$csrf = csrf_token();
$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Storage Health • USAT Admin</title>

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
            --green:#059669;--green-soft:#d1fae5;
            --red:#dc2626;--red-soft:#fee2e2;
            --amber:#d97706;--amber-soft:#fef3c7;
            --purple:#7c3aed;--purple-soft:#ede9fe;
            --blue:#2563eb;--blue-soft:#dbeafe;
            --shadow-sm:0 1px 3px rgba(15,23,42,.06),0 1px 2px rgba(15,23,42,.04);
            --shadow-lg:0 12px 32px rgba(15,23,42,.10);
            --radius:12px;--radius-lg:16px;--sidebar-w:264px;
        }
        [data-bs-theme="dark"]{
            --bg:#0b1220;--surface:#131c2e;--surface-2:#1a2439;
            --text:#f1f5f9;--text-2:#94a3b8;--text-3:#64748b;
            --border:#1f2a44;--border-strong:#2d3b57;
            --accent:#818cf8;--accent-2:#6366f1;--accent-soft:#1e2140;
            --green-soft:#052e24;--red-soft:#3f1517;--amber-soft:#3d2a08;
            --purple-soft:#2e1a54;--blue-soft:#1e3a5f;
            --shadow-sm:0 1px 3px rgba(0,0,0,.35);
            --shadow-lg:0 12px 32px rgba(0,0,0,.55);
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
        .sidebar-nav a{display:flex;align-items:center;gap:.75rem;padding:.6rem .75rem;border-radius:10px;color:var(--text-2);text-decoration:none;font-weight:500;font-size:.875rem;margin-bottom:.1rem;transition:all .15s}
        .sidebar-nav a:hover{background:var(--surface-2);color:var(--text)}
        .sidebar-nav a.active{background:var(--accent);color:#fff;box-shadow:0 4px 12px -4px rgba(79,70,229,.5)}
        .sidebar-nav a i{font-size:1.05rem;width:20px;text-align:center;flex-shrink:0}
        .sidebar-footer{padding:.75rem;border-top:1px solid var(--border)}

        .main-content{margin-left:var(--sidebar-w);padding:1.5rem clamp(1rem,2.5vw,2rem) 2rem;min-height:100vh;max-width:1400px}
        .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap}
        .topbar h2{font-size:1.35rem;font-weight:700;letter-spacing:-.02em;margin:0}
        .topbar .sub{font-size:.85rem;color:var(--text-2);margin:0}
        .menu-toggle{display:none;width:40px;height:40px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;align-items:center;justify-content:center}

        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
        .stat-card{position:relative;background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow-sm);padding:1.1rem 1.25rem 1.1rem 1.4rem;display:flex;align-items:center;gap:1rem;overflow:hidden}
        .stat-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--accent)}
        .stat-card.tone-green::before{background:var(--green)}
        .stat-card.tone-amber::before{background:var(--amber)}
        .stat-card.tone-red::before  {background:var(--red)}
        .stat-card.tone-purple::before{background:var(--purple)}
        .stat-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;background:var(--accent-soft);color:var(--accent)}
        .tone-green .stat-icon{background:var(--green-soft);color:var(--green)}
        .tone-amber .stat-icon{background:var(--amber-soft);color:var(--amber)}
        .tone-red   .stat-icon{background:var(--red-soft);color:var(--red)}
        .tone-purple .stat-icon{background:var(--purple-soft);color:var(--purple)}
        .stat-value{font-size:1.55rem;font-weight:700;letter-spacing:-.02em;font-variant-numeric:tabular-nums;line-height:1.1}
        .stat-label{font-size:.7rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;margin-top:.2rem;font-weight:600}
        .stat-hint{font-size:.72rem;color:var(--text-3);margin-top:.3rem;font-weight:500}

        .card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow-sm);margin-bottom:1.25rem;overflow:hidden}
        .card-header{padding:.9rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
        .card-header h3{margin:0;font-size:.9rem;font-weight:600;display:flex;align-items:center;gap:.5rem}
        .card-header h3 i{color:var(--accent);font-size:1rem}
        .card-header .hint{font-size:.75rem;color:var(--text-2);font-weight:500}
        .card-body{padding:1.25rem}
        .card-body.no-padding{padding:0}

        .table-scroll{overflow-x:auto}
        .table-admin{width:100%;border-collapse:separate;border-spacing:0}
        .table-admin thead th{background:var(--surface-2);font-size:.68rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;padding:.7rem 1rem;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
        .table-admin tbody td{padding:.65rem 1rem;border-bottom:1px solid var(--border);font-size:.85rem;vertical-align:middle}
        .table-admin tbody tr:hover td{background:color-mix(in srgb,var(--accent) 4%,transparent)}
        .table-admin tbody tr:last-child td{border-bottom:none}
        .cell-mono{font-family:ui-monospace,'SF Mono',monospace;font-size:.78rem;word-break:break-all}
        .cell-strong{font-weight:600;color:var(--text)}
        .cell-muted{color:var(--text-2);font-size:.82rem}
        .cell-num{font-variant-numeric:tabular-nums;font-weight:600}

        .btn{font-weight:500;border-radius:9px;font-size:.82rem;transition:all .15s}
        .btn-primary{background:var(--accent);border-color:var(--accent)}
        .btn-primary:hover{background:var(--accent-2);border-color:var(--accent-2)}
        .btn-outline-secondary{color:var(--text-2);border-color:var(--border)}
        .btn-outline-secondary:hover{background:var(--surface-2);color:var(--text);border-color:var(--border-strong)}
        .btn-outline-danger{color:var(--red);border-color:color-mix(in srgb,var(--red) 35%,transparent)}
        .btn-outline-danger:hover{background:var(--red);color:#fff;border-color:var(--red)}
        .btn-outline-warning{color:var(--amber);border-color:color-mix(in srgb,var(--amber) 40%,transparent)}
        .btn-outline-warning:hover{background:var(--amber);color:#fff;border-color:var(--amber)}
        .btn-xs{padding:.25rem .6rem;font-size:.72rem;border-radius:8px}
        .theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text-2);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s}
        .theme-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}

        .pill{display:inline-flex;align-items:center;gap:.35rem;padding:.22rem .65rem;border-radius:999px;font-size:.7rem;font-weight:600;border:1px solid transparent}
        .pill.success{background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb,var(--green) 22%,transparent)}
        .pill.warning{background:var(--amber-soft);color:var(--amber);border-color:color-mix(in srgb,var(--amber) 22%,transparent)}
        .pill.danger {background:var(--red-soft);color:var(--red);border-color:color-mix(in srgb,var(--red) 22%,transparent)}
        .pill.info   {background:var(--blue-soft);color:var(--blue);border-color:color-mix(in srgb,var(--blue) 22%,transparent)}
        .pill.muted  {background:var(--surface-2);color:var(--text-2);border-color:var(--border)}

        .flash{display:flex;align-items:center;gap:.65rem;padding:.75rem 1rem;border-radius:var(--radius);margin-bottom:1rem;font-size:.88rem;font-weight:500;border:1px solid transparent}
        .flash.success{background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb,var(--green) 25%,transparent)}
        .flash.error  {background:var(--red-soft);color:var(--red);border-color:color-mix(in srgb,var(--red) 25%,transparent)}
        .flash .btn-close{margin-left:auto;opacity:.6}

        .path-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .7rem;border-radius:8px;background:var(--surface-2);border:1px solid var(--border);font-family:ui-monospace,monospace;font-size:.72rem;color:var(--text-2);word-break:break-all}
        .path-chip i{color:var(--accent)}

        .empty{padding:2.5rem 1.5rem;text-align:center;color:var(--text-2)}
        .empty .empty-icon{width:56px;height:56px;border-radius:50%;background:var(--surface-2);color:var(--text-3);display:inline-flex;align-items:center;justify-content:center;font-size:1.4rem;margin-bottom:.85rem}
        .empty .empty-title{font-weight:600;color:var(--text);margin-bottom:.2rem}
        .empty .empty-sub{font-size:.82rem}

        @media (max-width:1024px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0}.menu-toggle{display:flex}}
        @media (max-width:640px){.main-content{padding:1rem}.stat-grid{grid-template-columns:repeat(2,1fr)}}
    </style>
</head>
<body>

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
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="student_profile.php"><i class="bi bi-people-fill"></i> Students</a>
        <a href="reports.php"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>
        <div class="nav-label" style="margin-top:.5rem">Administration</div>
        <a href="create_account.php"><i class="bi bi-person-plus"></i> Accounts</a>
        <a href="audit_log.php"><i class="bi bi-shield-check"></i> Audit Log</a>
        <a href="storage_health.php" class="active"><i class="bi bi-hdd-stack"></i> Storage</a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php" class="btn btn-outline-danger btn-sm w-100">
            <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
    </div>
</aside>

<div class="main-content" id="mainContent">

    <?php if(isset($_SESSION['success'])): ?>
        <div class="flash success">
            <i class="bi bi-check-circle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['success']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <div class="flash error">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['error']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h2>Storage Health</h2>
                <p class="sub">Monitor file storage, orphans, and missing files</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="pill <?= $total_issues === 0 ? 'success' : 'warning' ?>">
                <i class="bi bi-<?= $total_issues === 0 ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <?= $total_issues === 0 ? 'All clean' : $total_issues . ' issue' . ($total_issues === 1 ? '' : 's') ?>
            </span>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <!-- Paths -->
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-folder2-open"></i> Storage Paths</h3>
        </div>
        <div class="card-body">
            <div class="d-flex flex-column gap-2">
                <div>
                    <div class="cell-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.3rem">Documents (encrypted)</div>
                    <span class="path-chip"><i class="bi bi-lock-fill"></i><?= htmlspecialchars(DOCUMENTS_DIR) ?></span>
                </div>
                <div>
                    <div class="cell-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.3rem">Student photos</div>
                    <span class="path-chip"><i class="bi bi-person-badge"></i><?= htmlspecialchars(STUDENTS_PHOTO_DIR) ?></span>
                </div>
                <div>
                    <div class="cell-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.3rem">Staging (temp)</div>
                    <span class="path-chip"><i class="bi bi-hourglass-split"></i><?= htmlspecialchars(STAGING_DIR) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="stat-grid">
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="bi bi-file-earmark-lock"></i></div>
            <div>
                <div class="stat-value"><?= number_format($docs_stats['count']) ?></div>
                <div class="stat-label">Documents</div>
                <div class="stat-hint"><?= humanSize($docs_stats['size']) ?> · encrypted</div>
            </div>
        </div>
        <div class="stat-card tone-green">
            <div class="stat-icon"><i class="bi bi-image"></i></div>
            <div>
                <div class="stat-value"><?= number_format($photos_stats['count']) ?></div>
                <div class="stat-label">Photos</div>
                <div class="stat-hint"><?= humanSize($photos_stats['size']) ?></div>
            </div>
        </div>
        <div class="stat-card tone-amber">
            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="stat-value"><?= number_format($staging_stats['count']) ?></div>
                <div class="stat-label">Staging</div>
                <div class="stat-hint"><?= count($stale_staging) ?> stale (&gt;2h)</div>
            </div>
        </div>
        <div class="stat-card tone-blue">
            <div class="stat-icon"><i class="bi bi-hdd-fill"></i></div>
            <div>
                <div class="stat-value"><?= humanSize($total_size) ?></div>
                <div class="stat-label">Total on Disk</div>
                <div class="stat-hint"><?= number_format($docs_stats['count'] + $photos_stats['count'] + $staging_stats['count']) ?> files</div>
            </div>
        </div>
    </div>

    <!-- Stale staging -->
    <?php if(!empty($stale_staging)): ?>
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-hourglass-bottom"></i> Stale Staging <span class="hint">(<?= count($stale_staging) ?>)</span></h3>
            <form method="POST" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <button name="purge_staging" value="1" class="btn btn-outline-warning btn-sm">
                    <i class="bi bi-stars me-1"></i> Purge All
                </button>
            </form>
        </div>
        <div class="card-body no-padding">
            <div class="table-scroll">
                <table class="table-admin">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th style="width:120px" class="cell-num">Size</th>
                            <th style="width:160px">Age</th>
                            <th style="width:100px;text-align:right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($stale_staging as $name => $size): ?>
                        <tr>
                            <td class="cell-mono"><?= htmlspecialchars($name) ?></td>
                            <td class="cell-num"><?= humanSize($size) ?></td>
                            <td class="cell-muted"><?= floor((time() - filemtime(STAGING_DIR.$name)) / 60) ?> min old</td>
                            <td style="text-align:right">
                                <form method="POST" class="m-0 d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="type" value="staging">
                                    <input type="hidden" name="file" value="<?= htmlspecialchars($name) ?>">
                                    <button name="delete_file" value="1" class="btn btn-outline-danger btn-xs"
                                            onclick="return confirm('Delete <?= htmlspecialchars($name) ?>?')">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Orphaned documents -->
    <div class="card">
        <div class="card-header">
            <h3>
                <i class="bi bi-file-earmark-x"></i> Orphaned Documents
                <span class="hint">(<?= count($orphan_docs) ?>) — files on disk with no DB row</span>
            </h3>
            <?php if(!empty($orphan_docs)): ?>
                <form method="POST" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="type" value="document">
                    <button name="delete_all_orphans" value="1" class="btn btn-outline-danger btn-sm"
                            onclick="return confirm('Delete ALL <?= count($orphan_docs) ?> orphaned document(s)?')">
                        <i class="bi bi-trash3 me-1"></i> Delete All
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card-body no-padding">
            <?php if(empty($orphan_docs)): ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-check2-circle"></i></div>
                    <div class="empty-title">No orphaned documents</div>
                    <div class="empty-sub">Every encrypted file on disk is referenced by a DB row.</div>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th style="width:120px" class="cell-num">Size</th>
                                <th style="width:160px">Modified</th>
                                <th style="width:100px;text-align:right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($orphan_docs as $name):
                            $abs = DOCUMENTS_DIR . $name;
                            $size = is_file($abs) ? filesize($abs) : 0;
                            $mtime = is_file($abs) ? filemtime($abs) : 0;
                        ?>
                            <tr>
                                <td class="cell-mono"><?= htmlspecialchars($name) ?></td>
                                <td class="cell-num"><?= humanSize($size) ?></td>
                                <td class="cell-muted"><?= $mtime ? date('M d, Y H:i', $mtime) : '—' ?></td>
                                <td style="text-align:right">
                                    <form method="POST" class="m-0 d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="type" value="document">
                                        <input type="hidden" name="file" value="<?= htmlspecialchars($name) ?>">
                                        <button name="delete_file" value="1" class="btn btn-outline-danger btn-xs"
                                                onclick="return confirm('Delete <?= htmlspecialchars($name) ?>?')">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Orphaned photos -->
    <div class="card">
        <div class="card-header">
            <h3>
                <i class="bi bi-person-x"></i> Orphaned Photos
                <span class="hint">(<?= count($orphan_photos) ?>) — files on disk with no DB row</span>
            </h3>
            <?php if(!empty($orphan_photos)): ?>
                <form method="POST" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="type" value="photo">
                    <button name="delete_all_orphans" value="1" class="btn btn-outline-danger btn-sm"
                            onclick="return confirm('Delete ALL <?= count($orphan_photos) ?> orphaned photo(s)?')">
                        <i class="bi bi-trash3 me-1"></i> Delete All
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card-body no-padding">
            <?php if(empty($orphan_photos)): ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-check2-circle"></i></div>
                    <div class="empty-title">No orphaned photos</div>
                    <div class="empty-sub">Every photo on disk is referenced by a student row.</div>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th style="width:120px" class="cell-num">Size</th>
                                <th style="width:160px">Modified</th>
                                <th style="width:100px;text-align:right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($orphan_photos as $name):
                            $abs = STUDENTS_PHOTO_DIR . $name;
                            $size = is_file($abs) ? filesize($abs) : 0;
                            $mtime = is_file($abs) ? filemtime($abs) : 0;
                        ?>
                            <tr>
                                <td class="cell-mono"><?= htmlspecialchars($name) ?></td>
                                <td class="cell-num"><?= humanSize($size) ?></td>
                                <td class="cell-muted"><?= $mtime ? date('M d, Y H:i', $mtime) : '—' ?></td>
                                <td style="text-align:right">
                                    <form method="POST" class="m-0 d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="type" value="photo">
                                        <input type="hidden" name="file" value="<?= htmlspecialchars($name) ?>">
                                        <button name="delete_file" value="1" class="btn btn-outline-danger btn-xs"
                                                onclick="return confirm('Delete <?= htmlspecialchars($name) ?>?')">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Missing documents -->
    <div class="card">
        <div class="card-header">
            <h3>
                <i class="bi bi-file-earmark-break"></i> Missing Documents
                <span class="hint">(<?= count($missing_docs) ?>) — DB rows with no file on disk</span>
            </h3>
        </div>
        <div class="card-body no-padding">
            <?php if(empty($missing_docs)): ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-check2-circle"></i></div>
                    <div class="empty-title">No missing documents</div>
                    <div class="empty-sub">Every DB row points to an existing file.</div>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Document</th>
                                <th>Expected Path</th>
                                <th style="width:90px;text-align:right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($missing_docs as $m): ?>
                            <tr>
                                <td class="cell-strong">
                                    <?= htmlspecialchars(trim(($m['last_name'] ?? '') . ', ' . ($m['first_name'] ?? ''), ', ')) ?>
                                </td>
                                <td><?= htmlspecialchars($m['document_name']) ?></td>
                                <td class="cell-mono cell-muted"><?= htmlspecialchars($m['file_path']) ?></td>
                                <td style="text-align:right">
                                    <a href="view_student.php?id=<?= (int)$m['student_id'] ?>"
                                       class="btn btn-outline-secondary btn-xs">
                                        <i class="bi bi-arrow-right-short"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Missing photos -->
    <div class="card">
        <div class="card-header">
            <h3>
                <i class="bi bi-person-bounding-box"></i> Missing Photos
                <span class="hint">(<?= count($missing_photos) ?>) — DB rows with no file on disk</span>
            </h3>
        </div>
        <div class="card-body no-padding">
            <?php if(empty($missing_photos)): ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-check2-circle"></i></div>
                    <div class="empty-title">No missing photos</div>
                    <div class="empty-sub">Every photo reference resolves to a real file.</div>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Expected File</th>
                                <th style="width:90px;text-align:right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($missing_photos as $m): ?>
                            <tr>
                                <td class="cell-strong">
                                    <?= htmlspecialchars(trim(($m['last_name'] ?? '') . ', ' . ($m['first_name'] ?? ''), ', ')) ?>
                                </td>
                                <td class="cell-mono cell-muted"><?= htmlspecialchars($m['photo']) ?></td>
                                <td style="text-align:right">
                                    <a href="view_student.php?id=<?= (int)$m['student_id'] ?>"
                                       class="btn btn-outline-secondary btn-xs">
                                        <i class="bi bi-arrow-right-short"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
document.getElementById('menuToggle')?.addEventListener('click', () => {
    sb.classList.toggle('open');
    ov.style.display = sb.classList.contains('open') ? 'block' : 'none';
});

const tb = document.getElementById('themeToggle');
const ti = document.getElementById('themeIcon');
const h  = document.documentElement;
function st(t) {
    h.setAttribute('data-bs-theme', t);
    ti.className = 'bi bi-' + (t === 'dark' ? 'sun-fill' : 'moon-stars-fill');
    document.cookie = 'admin_theme=' + t + ';path=/;max-age=' + (60*60*24*365);
}
(function(){ const m = document.cookie.match(/(?:^|; )admin_theme=([^;]+)/); st(m ? decodeURIComponent(m[1]) : 'light'); })();
tb.addEventListener('click', () => st(h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));
</script>
</body>
</html>