<?php
// shared/pages/teacher_assignments.php
// Registrar / superadmin UI for assigning teachers to section clusters.
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/mailer.php';      
require_once __DIR__ . '/../lib/teacher_scope.php';

$__nav_section = 'system';

if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$flash = $_SESSION['ta_flash'] ?? null;
unset($_SESSION['ta_flash']);

/* ═══ HELPER: FLATTEN LOGO TO TEMP FILE FOR CID ATTACHMENT ═══ */
if (!function_exists('create_email_logo_temp')) {
    function create_email_logo_temp(string $dir): ?string {
        if (!extension_loaded('gd')) return null; 

        $files = glob($dir . 'usat.*');
        if (empty($files)) return null;
        
        $path = $files[0];
        $info = @getimagesize($path);
        if (!$info) return null;

        $mime = $info['mime'];
        $w = $info[0];
        $h = $info[1];

        switch ($mime) {
            case 'image/jpeg': $im = @imagecreatefromjpeg($path); break;
            case 'image/png':  $im = @imagecreatefrompng($path);  break;
            case 'image/webp': $im = @imagecreatefromwebp($path); break;
            default: return null;
        }
        if (!$im) return null;

        $size = 100;
        $new_w = $size;
        $new_h = (int)round(($h / $w) * $size);
        
        $new_im = imagecreatetruecolor($new_w, $new_h);
        $white = imagecolorallocate($new_im, 255, 255, 255);
        imagefill($new_im, 0, 0, $white);
        
        imagecopyresampled($new_im, $im, 0, 0, 0, 0, $new_w, $new_h, $w, $h);

        $temp = tempnam(sys_get_temp_dir(), 'email_logo_') . '.jpg';
        imagejpeg($new_im, $temp, 90); 

        imagedestroy($im);
        imagedestroy($new_im);

        return $temp;
    }
}

/* ═══ POST: ASSIGN ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign'])) {
    verify_csrf();

    $teacher_id  = (int)($_POST['teacher_id'] ?? 0);
    $section     = trim((string)($_POST['section'] ?? ''));
    $grade       = trim((string)($_POST['grade_level'] ?? ''));
    $sy          = trim((string)($_POST['school_year'] ?? ''));
    $program     = trim((string)($_POST['program'] ?? ''));
    $is_advisory = isset($_POST['is_advisory']) ? 1 : 0;

    $err = '';
    if ($teacher_id <= 0)                $err = 'Pick a teacher.';
    elseif ($section === '' || $grade === '' || $sy === '')
                                          $err = 'Section, grade, and school year are required.';

    if ($err === '') {
        $chk = $conn->prepare("SELECT fullname, email, role FROM admins WHERE id = ? LIMIT 1");
        if (!$chk) {
            $err = 'System error.';
        } else {
            $chk->bind_param('i', $teacher_id);
            $chk->execute();
            $teacher_data = $chk->get_result()->fetch_assoc();
            $chk->close();
            
            if (!$teacher_data) {
                $err = 'Teacher not found.';
            } elseif ($teacher_data['role'] !== 'teacher') {
                $err = 'Selected user is not a teacher.';
            }
        }
    }

    if ($err === '') {
        // If making this teacher advisory, remove advisory status from any other teacher in this exact cluster
        if ($is_advisory) {
            $clear = $conn->prepare("UPDATE teacher_sections SET is_advisory = 0 WHERE section = ? AND grade_level = ? AND school_year = ? AND program = ? AND teacher_id != ?");
            if ($clear) {
                $clear->bind_param('ssssi', $section, $grade, $sy, $program, $teacher_id);
                $clear->execute();
                $clear->close();
            }
        }

        $ins = $conn->prepare("
            INSERT INTO teacher_sections
                (teacher_id, section, grade_level, school_year, program, is_advisory, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_advisory = VALUES(is_advisory), created_by = VALUES(created_by)
        ");
        if (!$ins) {
            $err = 'System error.';
        } else {
            $actor = (int)($_SESSION['admin_id'] ?? 0);
            $ins->bind_param('issssii', $teacher_id, $section, $grade, $sy, $program, $is_advisory, $actor);
            $ins->execute();
            
            $is_new_assignment = ($ins->affected_rows === 1);
            $ins->close();

            audit_log($conn, 'teacher.section_assign', [
                'type'        => 'admin',
                'id'          => $teacher_id,
                'section'     => $section,
                'grade'       => $grade,
                'sy'          => $sy,
                'program'     => $program,
                'is_advisory' => $is_advisory
            ]);
            
            $email_note = '';
            
            if ($is_new_assignment && !empty($teacher_data['email']) && filter_var($teacher_data['email'], FILTER_VALIDATE_EMAIL)) {
                try {
                    $subject = "[USAT] New Class Assignment: {$section} ({$grade})";
                    
                    $prog_html = $program !== '' ? htmlspecialchars($program) : 'N/A';
                    $teacher_name = htmlspecialchars($teacher_data['fullname']);
                    $section_safe = htmlspecialchars($section);
                    $grade_safe = htmlspecialchars($grade);
                    $sy_safe = htmlspecialchars($sy);
                    
                    $dashboard_url = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . URL_PREFIX . "/dashboard.php";
                    
                    $temp_logo = create_email_logo_temp(__DIR__ . '/../../assets/img/');
                    $logo_html = '';
                    if ($temp_logo) {
                        $logo_html = '<img src="cid:usat_logo" width="100" height="auto" alt="USAT Logo" style="display:block;margin:0 auto 15px;border-radius:50%;border:3px solid #ffffff;background:#ffffff;box-shadow:0 4px 6px rgba(0,0,0,0.1);object-fit:contain;">';
                    }

                    $advisory_badge = '';
                    if ($is_advisory) {
                        $advisory_badge = '<div style="background:#fef3c7;color:#b45309;padding:10px;border-radius:6px;font-weight:bold;text-align:center;margin-bottom:20px;border:1px solid #fcd34d;">⭐ You have been assigned as the ADVISORY teacher for this section.</div>';
                    }

                    $html = <<<HTML
                    <!DOCTYPE html>
                    <html>
                    <head><meta charset="UTF-8"></head>
                    <body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Arial,sans-serif;color:#1e293b;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 12px;">
                    <tr><td align="center">
                        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 4px 20px rgba(15,23,42,.08);border:1px solid #e2e8f0;">
                            
                            <tr><td style="background:#1e3a8a;padding:30px;text-align:center;color:#ffffff;border-bottom:4px solid #fbbf24;">
                                {$logo_html}
                                <div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;opacity:.9;font-weight:600;margin-bottom:8px">USAT College Sagay City</div>
                                <div style="font-family:Georgia,serif;font-size:24px;font-weight:bold;margin-top:4px;">New Class Assignment</div>
                            </td></tr>

                            <tr><td style="padding:40px 40px 30px;">
                                <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">Dear <strong>{$teacher_name}</strong>,</p>
                                <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#334155;">
                                    You have been assigned to handle a new section for this academic term. Please review the details below and visit your dashboard to manage your students.
                                </p>

                                {$advisory_badge}

                                <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:6px;margin-bottom:30px;">
                                    <tr><td style="padding:16px;border-bottom:1px solid #e2e8f0;color:#475569;font-weight:600;width:35%;">Section</td><td style="padding:16px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-weight:700;font-size:16px;">{$section_safe}</td></tr>
                                    <tr><td style="padding:16px;border-bottom:1px solid #e2e8f0;color:#475569;font-weight:600;">Grade Level</td><td style="padding:16px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-weight:600;">{$grade_safe}</td></tr>
                                    <tr><td style="padding:16px;border-bottom:1px solid #e2e8f0;color:#475569;font-weight:600;">School Year</td><td style="padding:16px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-weight:600;">{$sy_safe}</td></tr>
                                    <tr><td style="padding:16px;color:#475569;font-weight:600;">Program</td><td style="padding:16px;color:#0f172a;font-weight:600;">{$prog_html}</td></tr>
                                </table>

                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr><td align="center">
                                        <a href="{$dashboard_url}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:14px 30px;border-radius:6px;font-weight:bold;font-size:15px;">Go to My Dashboard</a>
                                    </td></tr>
                                </table>
                                
                                <p style="margin:30px 0 0;font-size:12px;color:#64748b;text-align:center;line-height:1.5;">
                                    If you believe this is an error, please contact the Registrar's Office immediately.
                                </p>
                            </td></tr>

                            <tr><td style="background:#f8fafc;padding:20px 40px;text-align:center;font-size:11px;color:#64748b;border-top:1px solid #e2e8f0;">
                                This is an automated message from the USAT Enrollment System.<br>
                                &copy; 2026 USAT College Sagay City Inc.
                            </td></tr>
                        </table>
                    </td></tr>
                    </table>
                    </body>
                    </html>
                    HTML;

                    [$mail, $buildErr] = mailer_build();
                    $out = ['ok' => false, 'error' => null];
                    
                    if ($mail === null) {
                        $out['error'] = $buildErr;
                    } else {
                        $mail->addAddress($teacher_data['email']);
                        $mail->isHTML(true);
                        $mail->Subject = $subject;
                        $mail->Body    = $html;
                        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</tr>', '</h2>', '</div>'], "\n", $html));
                        $mail->addCustomHeader('X-Entity-Ref-ID', uniqid('usat_', true));

                        if ($temp_logo) {
                            $mail->addEmbeddedImage($temp_logo, 'usat_logo');
                        }
                        
                        try {
                            $mail->send();
                            $out['ok'] = true;
                        } catch (\Throwable $e) {
                            $out['error'] = $mail->ErrorInfo ?: $e->getMessage();
                        }
                    }

                    if ($temp_logo && is_file($temp_logo)) {
                        @unlink($temp_logo);
                    }

                    if (!$out['ok']) {
                        error_log("Teacher assignment email failed: " . ($out['error'] ?? 'unknown'));
                        $email_note = " (Warning: Email failed to send.)";
                    } else {
                        $email_note = " A notification email was sent to {$teacher_data['email']}.";
                    }

                } catch (Throwable $e) {
                    error_log("Teacher assignment email exception: " . $e->getMessage());
                    $email_note = " (Warning: Email exception occurred.)";
                }
            } elseif ($is_new_assignment) {
                $email_note = " (No valid email on file — teacher was not notified.)";
            }

            $_SESSION['ta_flash'] = ['type' => 'success', 'msg' => 'Assignment saved.' . $email_note];
        }
    }

    if ($err !== '') {
        $_SESSION['ta_flash'] = ['type' => 'error', 'msg' => $err];
    }

    header('Location: ' . URL_PREFIX . '/teacher_assignments.php');
    exit;
}

/* ═══ POST: UNASSIGN ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unassign'])) {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);

    if ($id > 0) {
        $del = $conn->prepare("DELETE FROM teacher_sections WHERE id = ?");
        if ($del) {
            $del->bind_param('i', $id);
            $del->execute();
            $del->close();
            audit_log($conn, 'teacher.section_unassign', [
                'type' => 'teacher_section',
                'id'   => $id,
            ]);
            $_SESSION['ta_flash'] = ['type' => 'success', 'msg' => 'Assignment removed.'];
        } else {
            $_SESSION['ta_flash'] = ['type' => 'error', 'msg' => 'System error.'];
        }
    }
    header('Location: ' . URL_PREFIX . '/teacher_assignments.php');
    exit;
}

/* ═══ PAGINATION LOGIC ═══ */
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$total_assignments = (int)$conn->query("SELECT COUNT(*) FROM teacher_sections")->fetch_row()[0];
$total_pages = max(1, (int)ceil($total_assignments / $limit));
$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;

/* ═══ DATA ═══ */
$teachers = $conn->query("
    SELECT id, fullname, username, email
      FROM admins
     WHERE role = 'teacher'
     ORDER BY fullname
")->fetch_all(MYSQLI_ASSOC);

$assignments = $conn->query("
    SELECT ts.id, ts.teacher_id, ts.section, ts.grade_level, ts.school_year, ts.program, ts.is_advisory,
           a.fullname AS teacher_name, a.username
      FROM teacher_sections ts
      JOIN admins a ON a.id = ts.teacher_id
     ORDER BY a.fullname ASC, ts.grade_level ASC, ts.section ASC
     LIMIT $limit OFFSET $offset
")->fetch_all(MYSQLI_ASSOC);

$clusters = $conn->query("
    SELECT DISTINCT section, grade_level, school_year, COALESCE(program,'') AS program
      FROM enrollment_form
     WHERE section IS NOT NULL AND section <> ''
       AND COALESCE(status,'Active') NOT IN ('Graduated','Pending')
     ORDER BY school_year DESC, grade_level ASC, section ASC
")->fetch_all(MYSQLI_ASSOC);

$page_title = 'Teacher Assignments • USAT Admin';
$page_css = <<<'CSS'
    .empty-note { padding: 2.5rem 1.5rem; text-align:center; color: var(--text-2); }
    .cluster-pill { display:inline-block; padding:.15rem .55rem; border-radius:6px; font-size:.7rem; font-weight:600; background:var(--surface-2); border:1px solid var(--border); color:var(--text-2); }
    
    /* Pagination */
    .pagination-bar { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem; border-top: 1px solid var(--border); flex-wrap: wrap; gap: 1rem; }
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

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h2>Teacher Section Assignments</h2>
                <p class="sub">Control which sections each teacher can access</p>
            </div>
        </div>
        <button class="theme-btn" id="themeToggle"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>">
            <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
            <span><?= htmlspecialchars($flash['msg']) ?></span>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-plus-circle"></i> Assign Teacher to Section</h3>
        </div>
        <div class="card-body">
            <?php if (empty($teachers)): ?>
                <div class="empty-note">
                    <i class="bi bi-info-circle me-1"></i>
                    No teacher accounts yet. Create one at
                    <a href="<?= URL_PREFIX ?>/create_account.php">Admin Accounts</a>.
                </div>
            <?php elseif (empty($clusters)): ?>
                <div class="empty-note">
                    <i class="bi bi-info-circle me-1"></i>
                    No sections with active enrollments yet. Assign sections to students first.
                </div>
            <?php else: ?>
                <form method="POST" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="assign" value="1">

                    <div class="col-md-5">
                        <label class="form-label">Teacher</label>
                        <select name="teacher_id" class="form-select" required>
                            <option value="">— Select teacher —</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= (int)$t['id'] ?>">
                                    <?= htmlspecialchars($t['fullname']) ?> (@<?= htmlspecialchars($t['username']) ?>)
                                    <?= empty($t['email']) ? ' [No Email]' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-7">
                        <label class="form-label">Section Cluster</label>
                        <select id="clusterSelect" class="form-select" onchange="fillCluster(this)">
                            <option value="">— Pick a section cluster —</option>
                            <?php foreach ($clusters as $c):
                                $val = json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
                                $label = $c['section'] . ' · ' . $c['grade_level'] . ' · SY ' . $c['school_year'];
                                if ($c['program'] !== '') $label .= ' · ' . $c['program'];
                            ?>
                                <option value="<?= htmlspecialchars($val, ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Section</label>
                        <input name="section" id="f_section" class="form-control" readonly required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Grade Level</label>
                        <input name="grade_level" id="f_grade" class="form-control" readonly required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">School Year</label>
                        <input name="school_year" id="f_sy" class="form-control" readonly required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Program</label>
                        <input name="program" id="f_program" class="form-control" readonly>
                    </div>

                    <div class="col-12 mt-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_advisory" value="1" id="is_advisory">
                            <label class="form-check-label fw-bold text-warning" for="is_advisory">
                                <i class="bi bi-star-fill me-1"></i> Set as Advisory Teacher for this section
                            </label>
                        </div>
                    </div>

                    <div class="col-12 mt-2">
                        <button class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i> Assign &amp; Notify Teacher
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>
                <i class="bi bi-list-check"></i> Current Assignments
                <span class="hint">(<?= number_format($total_assignments) ?>)</span>
            </h3>
        </div>
        <div class="card-body no-padding">
            <?php if (empty($assignments)): ?>
                <div class="empty-note">
                    No assignments yet. Teachers see an empty dashboard until you assign them a section.
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th>Teacher</th>
                                <th>Section</th>
                                <th>Grade</th>
                                <th>School Year</th>
                                <th>Program</th>
                                <th style="width:100px;text-align:right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($assignments as $a): ?>
                            <tr>
                                <td>
                                    <div class="cell-strong"><?= htmlspecialchars($a['teacher_name']) ?></div>
                                    <div class="cell-muted" style="font-size:.72rem">@<?= htmlspecialchars($a['username']) ?></div>
                                </td>
                                <td>
                                    <span class="pill info" style="text-transform:none;letter-spacing:0"><?= htmlspecialchars($a['section']) ?></span>
                                    <?php if (!empty($a['is_advisory'])): ?>
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem;" title="Advisory Teacher"><i class="bi bi-star-fill"></i> Advisory</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($a['grade_level']) ?></td>
                                <td><?= htmlspecialchars($a['school_year']) ?></td>
                                <td>
                                    <?php if ($a['program'] !== ''): ?>
                                        <span class="cluster-pill"><?= htmlspecialchars($a['program']) ?></span>
                                    <?php else: ?>
                                        <span class="cell-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right">
                                    <form method="POST" class="m-0 d-inline"
                                          onsubmit="return confirm('Remove this assignment?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="unassign" value="1">
                                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                        <button class="btn btn-outline-danger btn-xs" title="Remove">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="pagination-bar">
                    <div class="page-info">
                        Showing assignments <strong><?= number_format($offset + 1) ?>–<?= number_format(min($offset + $limit, $total_assignments)) ?></strong> of <strong><?= number_format($total_assignments) ?></strong>
                    </div>
                    <div class="pagination">
                        <a href="?page=1" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-left"></i></a>
                        <a href="?page=<?= max(1, $page - 1) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-left"></i></a>
                        
                        <?php 
                        $start_page = max(1, $page - 2); 
                        $end_page = min($total_pages, $page + 2); 
                        for ($i = $start_page; $i <= $end_page; $i++): 
                        ?>
                            <a href="?page=<?= $i ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        
                        <a href="?page=<?= min($total_pages, $page + 1) ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
                        <a href="?page=<?= $total_pages ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-right"></i></a>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function fillCluster(sel) {
    const v = sel.value;
    if (!v) {
        ['f_section','f_grade','f_sy','f_program'].forEach(id => document.getElementById(id).value = '');
        return;
    }
    const c = JSON.parse(v);
    document.getElementById('f_section').value = c.section || '';
    document.getElementById('f_grade').value   = c.grade_level || '';
    document.getElementById('f_sy').value      = c.school_year || '';
    document.getElementById('f_program').value = c.program || '';
}
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>