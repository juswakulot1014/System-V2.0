<?php
// shared/pages/storage_health.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/paths.php';

$__nav_section = 'system';

/* ═══ Human size helper ═══ */
if (!function_exists('humanSize')) {
    function humanSize(int $bytes): string {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return number_format($bytes / 1048576, 2) . ' MB';
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
}

/* ═══ Directory stats ═══ */
if (!function_exists('dir_stats')) {
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
}

/* ═══ Collect orphan files (on disk, not in DB) ═══ */
if (!function_exists('collect_orphans')) {
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
            $orphans = [];
            foreach (glob(STAGING_DIR . '*') ?: [] as $f) {
                if (!is_file($f)) continue;
                $orphans[] = basename($f);
            }
            return $orphans;
        }

        return [];
    }
}

/* ═══ Collect missing files (in DB, not on disk) ═══ */
if (!function_exists('collect_missing')) {
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
}

/* ═══ POST handlers ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $type_map = [
        'document' => DOCUMENTS_DIR,
        'photo'    => STUDENTS_PHOTO_DIR,
        'staging'  => STAGING_DIR,
    ];

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
        header('Location: ' . URL_PREFIX . '/storage_health.php'); exit;
    }

    if (isset($_POST['delete_file'])) {
        $type = $_POST['type'] ?? '';
        $file = $_POST['file'] ?? '';

        if (!isset($type_map[$type]) || $file === '' || basename($file) !== $file) {
            $_SESSION['error'] = 'Invalid file reference.';
            header('Location: ' . URL_PREFIX . '/storage_health.php'); exit;
        }

        $abs = $type_map[$type] . $file;
        if (!is_file($abs)) {
            $_SESSION['error'] = 'File already gone.';
            header('Location: ' . URL_PREFIX . '/storage_health.php'); exit;
        }

        @unlink($abs);
        audit_log($conn, 'storage.orphan_delete', ['type' => $type, 'file' => $file]);
        $_SESSION['success'] = "Deleted: $file";
        header('Location: ' . URL_PREFIX . '/storage_health.php'); exit;
    }

    if (isset($_POST['delete_all_orphans'])) {
        $type = $_POST['type'] ?? '';
        if (!isset($type_map[$type])) {
            $_SESSION['error'] = 'Invalid type.';
            header('Location: ' . URL_PREFIX . '/storage_health.php'); exit;
        }
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
        header('Location: ' . URL_PREFIX . '/storage_health.php'); exit;
    }
}

/* ═══ Gather stats ═══ */
$docs_stats    = dir_stats(DOCUMENTS_DIR);
$photos_stats  = dir_stats(STUDENTS_PHOTO_DIR);
$staging_stats = dir_stats(STAGING_DIR);

$orphan_docs   = collect_orphans($conn, 'document');
$orphan_photos = collect_orphans($conn, 'photo');
$missing_docs  = collect_missing($conn, 'document');
$missing_photos= collect_missing($conn, 'photo');

$stale_staging = [];
foreach (glob(STAGING_DIR . '*') ?: [] as $f) {
    if (!is_file($f)) continue;
    if (time() - filemtime($f) > 7200) {
        $stale_staging[basename($f)] = filesize($f);
    }
}

$total_size = $docs_stats['size'] + $photos_stats['size'] + $staging_stats['size'];

$total_issues = count($orphan_docs) + count($orphan_photos)
              + count($missing_docs) + count($missing_photos)
              + count($stale_staging);

$csrf = csrf_token();

$page_title = 'Storage Health • USAT Admin';

$page_css = <<<'CSS'
    .path-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .7rem;border-radius:8px;background:var(--surface-2);border:1px solid var(--border);font-family:ui-monospace,monospace;font-size:.72rem;color:var(--text-2);word-break:break-all}
    .path-chip i{color:var(--accent)}

    .cell-mono{font-family:ui-monospace,'SF Mono',monospace;font-size:.78rem;word-break:break-all}

    .btn-xs{padding:.25rem .6rem;font-size:.72rem;border-radius:8px}
    .btn-outline-warning{color:var(--amber);border-color:color-mix(in srgb,var(--amber) 40%,transparent)}
    .btn-outline-warning:hover{background:var(--amber);color:#fff;border-color:var(--amber)}
CSS;

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/sidebar.php';
?>

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
            <button class="menu-toggle" id="menuToggle" aria-label="Open menu">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <h2>Storage Health</h2>
                <p class="sub">Monitor file storage, orphans, and missing files</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="pill <?= $total_issues === 0 ? 'success' : 'warning' ?>" style="text-transform:none;letter-spacing:0;">
                <i class="bi bi-<?= $total_issues === 0 ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <?= $total_issues === 0 ? 'All clean' : $total_issues . ' issue' . ($total_issues === 1 ? '' : 's') ?>
            </span>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

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
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-hdd-fill"></i></div>
            <div>
                <div class="stat-value"><?= humanSize($total_size) ?></div>
                <div class="stat-label">Total on Disk</div>
                <div class="stat-hint"><?= number_format($docs_stats['count'] + $photos_stats['count'] + $staging_stats['count']) ?> files</div>
            </div>
        </div>
    </div>

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
                                    <a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$m['student_id'] ?>"
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
                                    <a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$m['student_id'] ?>"
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

<?php require __DIR__ . '/../partials/footer.php'; ?>