<?php
// shared/pages/teacher_assignments.php
// Registrar / superadmin UI for assigning teachers to section clusters.
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/mailer.php';
require_once __DIR__ . '/../lib/teacher_scope.php';
require_once __DIR__ . '/../lib/dir_view.php';

$__nav_section = 'system';

if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$__view = dir_view('teacher_assignments');

$flash = $_SESSION['ta_flash'] ?? null;
unset($_SESSION['ta_flash']);

/* ═══ COLUMN LIMITS ═══ */
const TA_SECTION_MAXLEN = 255;
const TA_GRADE_MAXLEN   = 64;
const TA_SY_MAXLEN      = 20;
const TA_PROGRAM_MAXLEN = 128;

/* ═══ HELPER: dashboard URL without trusting Host header ═══ */
if (!function_exists('ta_dashboard_url')) {
    function ta_dashboard_url(): string {
        if (defined('APP_BASE_URL') && APP_BASE_URL !== '') {
            return rtrim(APP_BASE_URL, '/') . '/dashboard.php';
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $port   = $_SERVER['SERVER_PORT'] ?? '';
        if ($port !== '' && !(($scheme === 'http' && $port === '80') || ($scheme === 'https' && $port === '443'))) {
            $host .= ':' . $port;
        }
        return $scheme . '://' . $host . URL_PREFIX . '/dashboard.php';
    }
}

/* ═══ HELPER: FLATTEN LOGO TO TEMP FILE FOR CID ATTACHMENT ═══ */
if (!function_exists('create_email_logo_temp')) {
    function create_email_logo_temp(string $dir): ?string {
        if (!extension_loaded('gd')) return null;

        $files = glob($dir . 'usat.*');
        if (empty($files)) return null;

        usort($files, static fn($a, $b) => @filemtime($b) <=> @filemtime($a));
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

        $temp = tempnam(sys_get_temp_dir(), 'email_logo_');
        if ($temp === false) {
            imagedestroy($im);
            imagedestroy($new_im);
            return null;
        }
        @unlink($temp);
        $temp .= '.jpg';

        $ok = imagejpeg($new_im, $temp, 90);

        imagedestroy($im);
        imagedestroy($new_im);

        return $ok ? $temp : null;
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
    if ($teacher_id <= 0) {
        $err = 'Pick a teacher.';
    } elseif ($section === '' || $grade === '' || $sy === '') {
        $err = 'Section, grade, and school year are required.';
    } elseif (mb_strlen($section) > TA_SECTION_MAXLEN) {
        $err = 'Section name is too long.';
    } elseif (mb_strlen($grade) > TA_GRADE_MAXLEN) {
        $err = 'Grade level is too long.';
    } elseif (mb_strlen($sy) > TA_SY_MAXLEN) {
        $err = 'School year is too long.';
    } elseif (mb_strlen($program) > TA_PROGRAM_MAXLEN) {
        $err = 'Program name is too long.';
    }

    $teacher_data = null;
    if ($err === '') {
        try {
            $chk = $conn->prepare("SELECT fullname, email, role FROM admins WHERE admin_id = ? LIMIT 1");
            $chk->bind_param('i', $teacher_id);
            $chk->execute();
            $teacher_data = $chk->get_result()->fetch_assoc();
            $chk->close();
        } catch (\mysqli_sql_exception $e) {
            error_log('teacher.assign lookup failed: ' . $e->getMessage());
            $teacher_data = null;
        }

        if (!$teacher_data) {
            $err = 'Teacher not found.';
        } elseif ($teacher_data['role'] !== 'teacher') {
            $err = 'Selected user is not a teacher.';
        }
    }

    $is_new_assignment = false;
    if ($err === '') {
        $actor = (int)($_SESSION['admin_id'] ?? 0);
        try {
            $conn->begin_transaction();

            if ($is_advisory) {
                $clear = $conn->prepare("
                    UPDATE teacher_sections
                       SET is_advisory = 0
                     WHERE section = ? AND grade_level = ? AND school_year = ?
                       AND COALESCE(program,'') = ? AND teacher_id != ?
                ");
                $clear->bind_param('ssssi', $section, $grade, $sy, $program, $teacher_id);
                $clear->execute();
                $clear->close();
            }

            $ins = $conn->prepare("
                INSERT INTO teacher_sections
                    (teacher_id, section, grade_level, school_year, program, is_advisory, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE is_advisory = VALUES(is_advisory), created_by = VALUES(created_by)
            ");
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
                'is_advisory' => $is_advisory,
            ]);

            $conn->commit();
        } catch (\mysqli_sql_exception $e) {
            try { $conn->rollback(); } catch (\Throwable $_) {}
            error_log('teacher.section_assign failed: ' . $e->getMessage());
            $err = 'Could not save the assignment. Please try again.';
        } catch (\Throwable $e) {
            try { $conn->rollback(); } catch (\Throwable $_) {}
            error_log('teacher.section_assign unexpected: ' . $e->getMessage());
            $err = 'Could not save the assignment. Please try again.';
        }
    }

    $email_note = '';
    if ($err === '' && $is_new_assignment && !empty($teacher_data['email'])
        && filter_var($teacher_data['email'], FILTER_VALIDATE_EMAIL)) {
        $temp_logo = null;
        try {
            $subject = "[USAT] New Class Assignment: {$section} ({$grade})";

            $prog_html     = $program !== '' ? htmlspecialchars($program) : 'N/A';
            $teacher_name  = htmlspecialchars($teacher_data['fullname']);
            $section_safe  = htmlspecialchars($section);
            $grade_safe    = htmlspecialchars($grade);
            $sy_safe       = htmlspecialchars($sy);
            $dashboard_url = htmlspecialchars(ta_dashboard_url(), ENT_QUOTES);

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
            if ($mail === null) {
                throw new \RuntimeException($buildErr ?: 'mailer_build() returned null');
            }

            $mail->addAddress($teacher_data['email']);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</tr>', '</h2>', '</div>'], "\n", $html));
            $mail->addCustomHeader('X-Entity-Ref-ID', uniqid('usat_', true));

            if ($temp_logo) {
                $mail->addEmbeddedImage($temp_logo, 'usat_logo');
            }

            if (!$mail->send()) {
                throw new \RuntimeException($mail->ErrorInfo ?: 'send() returned false');
            }

            $email_note = " A notification email was sent to {$teacher_data['email']}.";
        } catch (\Throwable $e) {
            error_log('Teacher assignment email failed: ' . $e->getMessage());
            $email_note = ' (Warning: Email failed to send.)';
        } finally {
            if ($temp_logo && is_file($temp_logo)) {
                @unlink($temp_logo);
            }
        }
    } elseif ($err === '' && $is_new_assignment) {
        $email_note = ' (No valid email on file — teacher was not notified.)';
    }

    if ($err === '') {
        $_SESSION['ta_flash'] = ['type' => 'success', 'msg' => 'Assignment saved.' . $email_note];
    } else {
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
        try {
            $del = $conn->prepare("DELETE FROM teacher_sections WHERE assignment_id = ?");
            $del->bind_param('i', $id);
            $del->execute();
            $deleted = $del->affected_rows;
            $del->close();

            if ($deleted > 0) {
                audit_log($conn, 'teacher.section_unassign', [
                    'type' => 'teacher_section',
                    'id'   => $id,
                ]);
                $_SESSION['ta_flash'] = ['type' => 'success', 'msg' => 'Assignment removed.'];
            } else {
                $_SESSION['ta_flash'] = ['type' => 'error', 'msg' => 'Assignment not found.'];
            }
        } catch (\mysqli_sql_exception $e) {
            error_log('teacher.section_unassign failed: ' . $e->getMessage());
            $_SESSION['ta_flash'] = ['type' => 'error', 'msg' => 'Could not remove the assignment. Please try again.'];
        }
    }
    header('Location: ' . URL_PREFIX . '/teacher_assignments.php');
    exit;
}

/* ═══ FILTER: TEACHER ═══ */
$filter_teacher_id = (int)($_GET['teacher_id'] ?? 0);

/* ═══ PAGINATION + DATA ═══ */
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;

$total_assignments = 0;
$total_pages       = 1;
$offset            = 0;
$teachers          = [];
$assignments       = [];
$clusters          = [];
$teacher_counts    = [];
$filtered_teacher  = null;
$filtered_adv_sections = '';
$filtered_sub_count    = 0;

try {
    /* ── Counts per teacher (for the picker + context bar) ── */
    $tc_result = $conn->query("
        SELECT teacher_id,
               SUM(CASE WHEN is_advisory = 1 THEN 1 ELSE 0 END) AS adv_ct,
               SUM(CASE WHEN is_advisory = 0 THEN 1 ELSE 0 END) AS sub_ct,
               GROUP_CONCAT(CASE WHEN is_advisory = 1 THEN section END SEPARATOR ', ') AS adv_sections
          FROM teacher_sections
         GROUP BY teacher_id
    ");
    while ($tc = $tc_result->fetch_assoc()) {
        $teacher_counts[(int)$tc['teacher_id']] = [
            'adv'          => (int)$tc['adv_ct'],
            'sub'          => (int)$tc['sub_ct'],
            'adv_sections' => (string)($tc['adv_sections'] ?? ''),
        ];
    }

    /* ── Filtered context ── */
    if ($filter_teacher_id > 0) {
        $ctx_stmt = $conn->prepare("SELECT admin_id, fullname, username FROM admins WHERE admin_id = ? AND role = 'teacher' LIMIT 1");
        $ctx_stmt->bind_param('i', $filter_teacher_id);
        $ctx_stmt->execute();
        $filtered_teacher = $ctx_stmt->get_result()->fetch_assoc();
        $ctx_stmt->close();

        if ($filtered_teacher) {
            $filtered_adv_sections = $teacher_counts[$filter_teacher_id]['adv_sections'] ?? '';
            $filtered_sub_count    = (int)($teacher_counts[$filter_teacher_id]['sub'] ?? 0);
        } else {
            $filter_teacher_id = 0;
        }
    }

    /* ── Total count (respects filter) ── */
    if ($filter_teacher_id > 0) {
        $cnt_stmt = $conn->prepare("SELECT COUNT(*) FROM teacher_sections WHERE teacher_id = ?");
        $cnt_stmt->bind_param('i', $filter_teacher_id);
        $cnt_stmt->execute();
        $total_assignments = (int)($cnt_stmt->get_result()->fetch_row()[0] ?? 0);
        $cnt_stmt->close();
    } else {
        $total_assignments = (int)($conn->query("SELECT COUNT(*) FROM teacher_sections")->fetch_row()[0] ?? 0);
    }

    $total_pages = max(1, (int)ceil($total_assignments / $limit));
    $page        = min($page, $total_pages);
    $offset      = ($page - 1) * $limit;

    /* ── Teacher roster ── */
    $teachers = $conn->query("
        SELECT admin_id, fullname, username, email
          FROM admins
         WHERE role = 'teacher'
         ORDER BY fullname
    ")->fetch_all(MYSQLI_ASSOC);

    /* ── Assignments (respects filter; advisory first) ── */
    $assign_sql = "
        SELECT ts.assignment_id, ts.teacher_id, ts.section, ts.grade_level, ts.school_year, ts.program, ts.is_advisory,
               a.fullname AS teacher_name, a.username
          FROM teacher_sections ts
          JOIN admins a ON a.admin_id = ts.teacher_id
    ";
    if ($filter_teacher_id > 0) $assign_sql .= " WHERE ts.teacher_id = ? ";
    $assign_sql .= " ORDER BY a.fullname ASC, ts.is_advisory DESC, ts.grade_level ASC, ts.section ASC LIMIT ? OFFSET ?";

    $assign_stmt = $conn->prepare($assign_sql);
    if ($filter_teacher_id > 0) {
        $assign_stmt->bind_param('iii', $filter_teacher_id, $limit, $offset);
    } else {
        $assign_stmt->bind_param('ii', $limit, $offset);
    }
    $assign_stmt->execute();
    $assignments = $assign_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $assign_stmt->close();

    /* ── Section clusters available for new assignments ── */
    $clusters = $conn->query("
        SELECT DISTINCT section, grade_level, school_year, COALESCE(program,'') AS program
          FROM enrollment_form
         WHERE section IS NOT NULL AND section <> ''
           AND COALESCE(status,'Active') NOT IN ('Graduated','Pending')
         ORDER BY school_year DESC, grade_level ASC, section ASC
    ")->fetch_all(MYSQLI_ASSOC);
} catch (\mysqli_sql_exception $e) {
    error_log('teacher_assignments load failed: ' . $e->getMessage());
    $flash = ['type' => 'error', 'msg' => 'Could not load assignments right now. Please try again shortly.'];
    $teachers = $assignments = $clusters = $teacher_counts = [];
    $total_assignments = 0;
    $total_pages = 1;
    $offset = 0;
    $page = 1;
    $filtered_teacher = null;
}

/* Pagination string preserves the teacher filter */
$pagination_qs = $filter_teacher_id > 0 ? 'teacher_id=' . $filter_teacher_id . '&' : '';

$page_title = 'Teacher Assignments • USAT Admin';
$page_css = <<<'CSS'
    .empty-note { padding: 2.5rem 1.5rem; text-align:center; color: var(--text-2); }
    .cluster-pill { display:inline-block; padding:.15rem .55rem; border-radius:6px; font-size:.7rem; font-weight:600; background:var(--surface-2); border:1px solid var(--border); color:var(--text-2); }

    .pagination-bar { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem; border-top: 1px solid var(--border); flex-wrap: wrap; gap: 1rem; }
    .page-info { font-size: .85rem; color: var(--text-2); font-weight: 500; }
    .pagination { display: inline-flex; align-items: center; gap: .25rem; background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; padding: .25rem; }
    .page-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; border-radius: 8px; border: none; background: transparent; color: var(--text-2); font-size: .85rem; font-weight: 600; text-decoration: none; transition: all .15s; }
    .page-btn:hover:not(.disabled) { background: var(--surface); color: var(--accent); box-shadow: 0 2px 6px rgba(0,0,0,.05); }
    .page-btn.disabled { opacity: .4; cursor: not-allowed; }
    .page-btn.active { background: var(--accent); color: #fff; }
    .page-btn.active:hover { background: var(--accent); color: #fff; }

    /* Teacher filter picker */
    .ta-filter-select {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: .5rem .85rem;
        font-size: .85rem;
        color: var(--text);
        font-family: inherit;
        max-width: 380px;
        cursor: pointer;
    }
    .ta-filter-select:focus {
        outline: none;
        border-color: var(--accent);
        background: var(--surface);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
    }

    /* Filtered context bar */
    .ta-context {
        display: flex;
        align-items: center;
        gap: .85rem;
        flex-wrap: wrap;
        padding: .9rem 1.25rem;
        background: linear-gradient(90deg, var(--accent-soft), transparent);
        border-bottom: 1px solid var(--border);
    }
    .ta-context-avatar {
        width: 42px; height: 42px; border-radius: 12px;
        background: linear-gradient(135deg, var(--accent), var(--accent-2));
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: .9rem; flex-shrink: 0;
        box-shadow: 0 4px 12px -4px rgba(79,70,229,.5);
    }
    .ta-context-info { flex: 1; min-width: 0; }
    .ta-context-name {
        font-weight: 700; font-size: .95rem; color: var(--text);
        line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .ta-context-meta {
        font-size: .75rem; color: var(--text-2); margin-top: .2rem;
        display: flex; gap: .5rem; flex-wrap: wrap;
    }
    .ta-context-meta .sep { opacity: .4; }
    .ta-context-meta strong { color: var(--text); }
    .ta-context-clear {
        font-size: .78rem; font-weight: 600; color: var(--accent);
        text-decoration: none; white-space: nowrap;
        padding: .35rem .7rem; border-radius: 8px;
        transition: background .15s;
    }
    .ta-context-clear:hover { background: var(--accent-soft); color: var(--accent); text-decoration: none; }

    /* Role pills in table */
    .role-pill {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .25rem .65rem; border-radius: 999px;
        font-size: .7rem; font-weight: 700; white-space: nowrap;
        border: 1px solid transparent;
    }
    .role-pill.advisory {
        background: var(--amber-soft); color: var(--amber);
        border-color: color-mix(in srgb, var(--amber) 28%, transparent);
    }
    .role-pill.subject {
        background: var(--accent-soft); color: var(--accent);
        border-color: color-mix(in srgb, var(--accent) 22%, transparent);
    }
    .role-pill i { font-size: .75rem; }
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
                <form method="POST" class="row g-3" id="assignForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="assign" value="1">

                    <div class="col-md-5">
                        <label class="form-label">Teacher</label>
                        <select name="teacher_id" id="teacherSelect" class="form-select" required>
                            <option value="">— Select teacher —</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= (int)$t['admin_id'] ?>" <?= $filter_teacher_id === (int)$t['admin_id'] ? 'selected' : '' ?>>
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
                <i class="bi bi-list-check"></i>
                <?php if ($filtered_teacher): ?>
                    <?= htmlspecialchars($filtered_teacher['fullname']) ?>'s Assignments
                <?php else: ?>
                    Current Assignments
                <?php endif; ?>
                <span class="hint">(<?= number_format($total_assignments) ?>)</span>
            </h3>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <?php if (!empty($teachers)): ?>
                    <select class="ta-filter-select" onchange="if(this.value){window.location='?teacher_id='+encodeURIComponent(this.value)}else{window.location='<?= URL_PREFIX ?>/teacher_assignments.php'}">
                        <option value="">— All Teachers —</option>
                        <?php foreach ($teachers as $t):
                            $tid  = (int)$t['admin_id'];
                            $tc   = $teacher_counts[$tid] ?? ['adv' => 0, 'sub' => 0, 'adv_sections' => ''];
                            $bits = [];
                            if ($tc['adv'] > 0) {
                                $bits[] = 'advisor: ' . ($tc['adv_sections'] ?: 'yes');
                            }
                            if ($tc['sub'] > 0) {
                                $bits[] = $tc['sub'] . ' subject section' . ($tc['sub'] === 1 ? '' : 's');
                            }
                            if (empty($bits)) $bits[] = 'no assignments';
                        ?>
                            <option value="<?= $tid ?>" <?= $filter_teacher_id === $tid ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['fullname']) ?> (@<?= htmlspecialchars($t['username']) ?>) — <?= htmlspecialchars(implode(' · ', $bits)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if (!empty($assignments)): ?>
                    <?= dir_view_toggle($__view) ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($filtered_teacher): ?>
            <?php
            $ctx_initials = '';
            foreach (preg_split('/\s+/', trim((string)$filtered_teacher['fullname'])) as $part) {
                if ($part !== '') $ctx_initials .= mb_strtoupper(mb_substr($part, 0, 1));
                if (mb_strlen($ctx_initials) >= 2) break;
            }
            if ($ctx_initials === '') $ctx_initials = 'T';
            ?>
            <div class="ta-context">
                <div class="ta-context-avatar"><?= htmlspecialchars($ctx_initials) ?></div>
                <div class="ta-context-info">
                    <div class="ta-context-name"><?= htmlspecialchars($filtered_teacher['fullname']) ?></div>
                    <div class="ta-context-meta">
                        <span>@<?= htmlspecialchars($filtered_teacher['username']) ?></span>
                        <?php if ($filtered_adv_sections !== ''): ?>
                            <span class="sep">·</span>
                            <span><strong>Advisory:</strong> <?= htmlspecialchars($filtered_adv_sections) ?></span>
                        <?php else: ?>
                            <span class="sep">·</span>
                            <span style="font-style:italic">No advisory section</span>
                        <?php endif; ?>
                        <span class="sep">·</span>
                        <span><strong><?= $filtered_sub_count ?></strong> subject section<?= $filtered_sub_count === 1 ? '' : 's' ?></span>
                    </div>
                </div>
                <a href="<?= URL_PREFIX ?>/teacher_assignments.php" class="ta-context-clear">
                    <i class="bi bi-x-circle"></i> Clear filter
                </a>
            </div>
        <?php endif; ?>

        <div class="card-body no-padding <?= dir_view_class($__view) ?>" data-page-key="teacher_assignments">
            <?php if (empty($assignments)): ?>
                <div class="empty-note">
                    <?php if ($filtered_teacher): ?>
                        No assignments for <strong><?= htmlspecialchars($filtered_teacher['fullname']) ?></strong> yet.
                        Use the form above to assign them a section.
                    <?php else: ?>
                        No assignments yet. Teachers see an empty dashboard until you assign them a section.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="dir-table-wrap">
                    <div class="table-scroll">
                        <table class="table-admin">
                            <thead>
                                <tr>
                                    <th>Teacher</th>
                                    <th>Section</th>
                                    <th>Grade</th>
                                    <th>School Year</th>
                                    <th>Program</th>
                                    <th style="width:110px">Role</th>
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
                                    <td>
                                        <?php if (!empty($a['is_advisory'])): ?>
                                            <span class="role-pill advisory"><i class="bi bi-star-fill"></i> Advisory</span>
                                        <?php else: ?>
                                            <span class="role-pill subject"><i class="bi bi-person-check"></i> Subject</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right">
                                        <form method="POST" class="m-0 d-inline"
                                              onsubmit="return confirm('Remove this assignment?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                            <input type="hidden" name="unassign" value="1">
                                            <input type="hidden" name="id" value="<?= (int)$a['assignment_id'] ?>">
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
                </div>

                <div class="dir-cards-wrap">
                    <div class="student-cards-grid">
                        <?php foreach ($assignments as $a):
                            $teacher_name = $a['teacher_name'] ?? 'Teacher';
                            $name_parts   = preg_split('/\s+/', trim($teacher_name));
                            $initials     = strtoupper(
                                substr($name_parts[0] ?? 'T', 0, 1) .
                                substr($name_parts[count($name_parts) - 1] ?? '', 0, 1)
                            );
                            if ($initials === '' || $initials === 'T') $initials = 'T';
                            $is_adv = !empty($a['is_advisory']);
                        ?>
                            <div class="student-card">
                                <div class="student-card-header">
                                    <div class="student-avatar-mini"><?= htmlspecialchars($initials) ?></div>
                                    <div class="student-card-info">
                                        <div class="student-card-name"><?= htmlspecialchars($teacher_name) ?></div>
                                        <div class="student-card-meta">
                                            <span>@<?= htmlspecialchars($a['username']) ?></span>
                                            <span class="sep">·</span>
                                            <span>#<?= (int)$a['assignment_id'] ?></span>
                                        </div>
                                    </div>
                                    <?php if ($is_adv): ?>
                                        <span class="role-pill advisory" style="font-size:.65rem;"><i class="bi bi-star-fill"></i> Advisory</span>
                                    <?php else: ?>
                                        <span class="role-pill subject" style="font-size:.65rem;"><i class="bi bi-person-check"></i> Subject</span>
                                    <?php endif; ?>
                                </div>

                                <div class="student-card-tags">
                                    <span class="s-tag section"><i class="bi bi-columns-gap"></i> <?= htmlspecialchars($a['section']) ?></span>
                                    <span class="s-tag grade"><i class="bi bi-mortarboard"></i> <?= htmlspecialchars($a['grade_level']) ?></span>
                                    <span class="s-tag grade"><i class="bi bi-calendar3"></i> SY <?= htmlspecialchars($a['school_year']) ?></span>
                                    <?php if ($a['program'] !== ''): ?>
                                        <span class="s-tag section"><i class="bi bi-bookmark"></i> <?= htmlspecialchars($a['program']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="student-card-footer">
                                    <span class="student-card-strand">
                                        <?php if ($is_adv): ?>
                                            <i class="bi bi-star-fill" style="color:var(--amber);"></i> Advisory teacher
                                        <?php else: ?>
                                            <i class="bi bi-person-check"></i> Subject teacher
                                        <?php endif; ?>
                                    </span>
                                    <form method="POST" class="m-0" onsubmit="return confirm('Remove this assignment?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="unassign" value="1">
                                        <input type="hidden" name="id" value="<?= (int)$a['assignment_id'] ?>">
                                        <button class="btn btn-outline-danger btn-xs" title="Remove">
                                            <i class="bi bi-trash3"></i> Remove
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="pagination-bar">
                    <div class="page-info">
                        Showing assignments <strong><?= number_format($offset + 1) ?>–<?= number_format(min($offset + $limit, $total_assignments)) ?></strong> of <strong><?= number_format($total_assignments) ?></strong>
                    </div>
                    <div class="pagination">
                        <a href="?<?= $pagination_qs ?>page=1" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-left"></i></a>
                        <a href="?<?= $pagination_qs ?>page=<?= max(1, $page - 1) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-left"></i></a>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page   = min($total_pages, $page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a href="?<?= $pagination_qs ?>page=<?= $i ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>

                        <a href="?<?= $pagination_qs ?>page=<?= min($total_pages, $page + 1) ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
                        <a href="?<?= $pagination_qs ?>page=<?= $total_pages ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-right"></i></a>
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

document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-prefill-advisory]');
    if (!btn) return;
    const teacherId = btn.getAttribute('data-prefill-advisory');
    const form = document.getElementById('assignForm');
    if (!form) return;
    const teacherSel = document.getElementById('teacherSelect');
    if (teacherSel && teacherId) teacherSel.value = teacherId;
    const cb = document.getElementById('is_advisory');
    if (cb) cb.checked = true;
    const cluster = document.getElementById('clusterSelect');
    if (cluster) cluster.focus();
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
});
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>