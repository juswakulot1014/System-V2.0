<?php
declare(strict_types=1);

/**
 * shared/pages/approve_enrollment.php — Approve or reject a pending enrollment.
 *
 * Structure:
 *   1. Helpers (defined first, so every code path can use them)
 *   2. Role gate, CSRF, input validation
 *   3. Load enrollment
 *   4. REJECT  — decline email, audit, delete in one transaction
 *   5. APPROVE — status change in one transaction, then best-effort
 *                audit / registrar notice / student email
 *
 * Logo in emails: set APP_PUBLIC_URL (e.g. https://enrollment.yourdomain.edu.ph)
 * and place usat-email.png at <web root>/assets/img/usat-email.png
 */

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/mailer.php';
require_once __DIR__ . '/../../config/security.php';

/* =========================================================
   1. HELPERS
   ========================================================= */

if (!function_exists('usat_e')) {
    /** Escape a value for HTML output, with a fallback for empty values. */
    function usat_e(?string $v, string $fallback = '—'): string
    {
        $v = trim((string)$v);
        return htmlspecialchars($v === '' ? $fallback : $v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('format_email_failure_note')) {
    function format_email_failure_note(string $to, array $result): string
    {
        $attempts = (int)($result['attempts'] ?? 0);
        $err      = trim((string)($result['error'] ?? '')) ?: 'no error message';
        $kind     = !empty($result['transient']) ? 'transient' : 'permanent';

        if (strlen($err) > 200) {
            $err = substr($err, 0, 197) . '...';
        }

        return sprintf(
            ' (Warning: email to %s failed after %d attempt(s) [%s] — %s)',
            htmlspecialchars($to, ENT_QUOTES, 'UTF-8'),
            $attempts,
            $kind,
            htmlspecialchars($err, ENT_QUOTES, 'UTF-8')
        );
    }
}

if (!function_exists('log_email')) {
    function log_email(mysqli $conn, int $eid, int $sid, string $to, string $subject, array $r, int $admin_id): void
    {
        $status = !empty($r['ok']) ? 'sent' : 'failed';

        $err = null;
        if ($status === 'failed') {
            $err = trim((string)($r['error'] ?? 'unknown'));
            if (isset($r['attempts'])) {
                $err = sprintf(
                    '[attempts=%d, transient=%s] %s',
                    (int)$r['attempts'],
                    !empty($r['transient']) ? 'yes' : 'no',
                    $err
                );
            }
        }

        $stmt = $conn->prepare("
            INSERT INTO enrollment_email_log
                (enrollment_id, student_id, recipient, subject, status, error_message, sent_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            error_log('log_email: prepare failed: ' . $conn->error);
            return;
        }
        $stmt->bind_param('iissssi', $eid, $sid, $to, $subject, $status, $err, $admin_id);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('usat_logo_html')) {
    /** Logo <img> for emails, or '' when APP_PUBLIC_URL is not configured. */
    function usat_logo_html(): string
    {
        $base = trim((string)(getenv('APP_PUBLIC_URL') ?: ''));
        if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $src = rtrim($base, '/') . '/assets/img/usat-email.png';
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" width="96" height="96"'
             . ' alt="USAT College Sagay City Inc. logo"'
             . ' style="display:block;margin:0 auto 14px;width:96px;height:auto;border:0;outline:none;text-decoration:none">';
    }
}

if (!function_exists('usat_detail_table')) {
    /**
     * Two-column detail card. Keys are plain labels (escaped here);
     * values must already be HTML-safe (use usat_e()).
     */
    function usat_detail_table(array $rows): string
    {
        $html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"'
               . ' style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;border-collapse:separate;overflow:hidden">';
        $count = count($rows);
        $i     = 0;
        foreach ($rows as $label => $value) {
            $rule = $i < $count - 1 ? 'border-bottom:1px solid #e2e8f0;' : '';
            $html .= '<tr>'
                   . '<td style="padding:12px 16px;width:40%;color:#64748b;font-size:13px;font-weight:600;' . $rule . '">'
                   .     htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '</td>'
                   . '<td style="padding:12px 16px;color:#0f172a;font-size:14px;' . $rule . '">' . $value . '</td>'
                   . '</tr>';
            $i++;
        }
        return $html . '</table>';
    }
}

if (!function_exists('usat_email_layout')) {
    /**
     * Shared shell for every student-facing email: header with logo,
     * body, and footer. $accent is the header colour; $bodyHtml must be
     * HTML-safe.
     */
    function usat_email_layout(string $accent, string $title, string $subtitle, string $bodyHtml): string
    {
        $t    = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $s    = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
        $logo = usat_logo_html();
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$t}</title>
</head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:'Segoe UI',Arial,Helvetica,sans-serif;color:#0f172a">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;padding:32px 12px">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0"
           style="max-width:600px;width:100%;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 14px 36px rgba(15,23,42,.12)">

      <tr><td style="background:{$accent};padding:30px 32px 26px;text-align:center;color:#ffffff">
        {$logo}
        <div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;opacity:.85">USAT College Sagay City Inc.</div>
        <div style="font-size:22px;font-weight:700;margin-top:8px;line-height:1.3">{$t}</div>
        <div style="font-size:13px;opacity:.9;margin-top:6px">{$s}</div>
      </td></tr>

      <tr><td style="padding:32px">
        {$bodyHtml}
      </td></tr>

      <tr><td style="background:#f8fafc;padding:20px 32px;text-align:center;font-size:12px;color:#64748b;border-top:1px solid #e5e7eb;line-height:1.6">
        This is an automated message from the USAT Enrollment System. Please do not reply to this email.<br>
        &copy; {$year} USAT College Sagay City Inc.
      </td></tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }
}

if (!function_exists('approval_email_html')) {
    function approval_email_html(string $name, array $e): string
    {
        $rows = [
            'Student ID'  => '<strong>' . usat_e($e['student_id_number'] ?? null) . '</strong>',
            'LRN'         => usat_e($e['lrn'] ?? null),
            'School Year' => usat_e($e['school_year'] ?? null),
            'Grade Level' => usat_e($e['grade_level'] ?? null),
            'Strand'      => usat_e($e['strand'] ?? null),
            'Program'     => usat_e($e['program'] ?? null),
            'Section'     => usat_e($e['section'] ?? null, 'To be announced'),
        ];

        $body = ''
            . '<p style="margin:0 0 14px;font-size:16px;line-height:1.5">Dear <strong>' . usat_e($name) . '</strong>,</p>'
            . '<p style="margin:0 0 18px;font-size:15px;line-height:1.7;color:#334155">'
            .     'Congratulations! Your enrollment has been <strong style="color:#059669">officially approved</strong>. '
            .     'We are glad to welcome you to USAT College Sagay City Inc.'
            . '</p>'
            . '<div style="margin:0 0 20px">'
            .     '<span style="display:inline-block;background:#d1fae5;color:#047857;padding:6px 14px;border-radius:999px;'
            .     'font-size:12px;font-weight:700;letter-spacing:.6px">&#10004; ENROLLMENT APPROVED</span>'
            . '</div>'
            . '<p style="margin:0 0 10px;font-size:13px;font-weight:700;color:#334155;letter-spacing:.4px;text-transform:uppercase">Your enrollment details</p>'
            . usat_detail_table($rows)
            . '<p style="margin:26px 0 10px;font-size:13px;font-weight:700;color:#334155;letter-spacing:.4px;text-transform:uppercase">Next steps</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            .   '<tr><td style="padding:6px 0;font-size:14px;line-height:1.6;color:#334155">'
            .     '<strong style="color:#2563eb">1.</strong>&nbsp; Keep this email for your records.</td></tr>'
            .   '<tr><td style="padding:6px 0;font-size:14px;line-height:1.6;color:#334155">'
            .     '<strong style="color:#2563eb">2.</strong>&nbsp; Bring a printed copy of this email on your first day.</td></tr>'
            .   '<tr><td style="padding:6px 0;font-size:14px;line-height:1.6;color:#334155">'
            .     '<strong style="color:#2563eb">3.</strong>&nbsp; Watch for updates from the registrar about your schedule and section.</td></tr>'
            . '</table>';

        return usat_email_layout(
            'linear-gradient(135deg,#047857,#10b981)',
            'Enrollment Approved',
            'Welcome to USAT College Sagay City Inc.',
            $body
        );
    }
}

if (!function_exists('rejection_email_html')) {
    function rejection_email_html(string $name, array $e, string $reason): string
    {
        $rows = [
            'Student ID'  => usat_e($e['student_id_number'] ?? null),
            'LRN'         => usat_e($e['lrn'] ?? null),
            'School Year' => usat_e($e['school_year'] ?? null),
            'Grade Level' => usat_e($e['grade_level'] ?? null),
            'Strand'      => usat_e($e['strand'] ?? null),
        ];

        $body = ''
            . '<p style="margin:0 0 14px;font-size:16px;line-height:1.5">Dear <strong>' . usat_e($name) . '</strong>,</p>'
            . '<p style="margin:0 0 18px;font-size:15px;line-height:1.7;color:#334155">'
            .     'Thank you for applying to USAT College Sagay City Inc. After reviewing your enrollment application, '
            .     'we regret to inform you that it has been <strong style="color:#b91c1c">declined</strong>.'
            . '</p>'
            . '<div style="margin:0 0 20px">'
            .     '<span style="display:inline-block;background:#fee2e2;color:#b91c1c;padding:6px 14px;border-radius:999px;'
            .     'font-size:12px;font-weight:700;letter-spacing:.6px">&#10006; APPLICATION DECLINED</span>'
            . '</div>'
            . '<p style="margin:0 0 10px;font-size:13px;font-weight:700;color:#334155;letter-spacing:.4px;text-transform:uppercase">Application details</p>'
            . usat_detail_table($rows)
            . '<div style="margin:22px 0 0;background:#fef2f2;border-left:4px solid #dc2626;border-radius:10px;padding:16px 18px">'
            .     '<div style="font-size:12px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Reason</div>'
            .     '<div style="font-size:14px;line-height:1.6;color:#7f1d1d">' . usat_e($reason) . '</div>'
            . '</div>'
            . '<p style="margin:26px 0 10px;font-size:13px;font-weight:700;color:#334155;letter-spacing:.4px;text-transform:uppercase">What you can do</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            .   '<tr><td style="padding:6px 0;font-size:14px;line-height:1.6;color:#334155">'
            .     '<strong style="color:#2563eb">1.</strong>&nbsp; Contact the registrar&rsquo;s office if you have questions about this decision.</td></tr>'
            .   '<tr><td style="padding:6px 0;font-size:14px;line-height:1.6;color:#334155">'
            .     '<strong style="color:#2563eb">2.</strong>&nbsp; Correct the issue described above and submit a new application.</td></tr>'
            . '</table>'
            . '<p style="margin:22px 0 0;font-size:13px;line-height:1.6;color:#64748b">'
            .     'Your application record has been removed from our system. Submitting a new application is required to re-apply.'
            . '</p>';

        return usat_email_layout(
            'linear-gradient(135deg,#991b1b,#dc2626)',
            'Enrollment Application Declined',
            'Please read the details below',
            $body
        );
    }
}

if (!function_exists('registrar_notification_html')) {
    function registrar_notification_html(
        string $student_name,
        array $e,
        string $action,
        string $admin_name,
        ?string $reason = null
    ): string {
        $student_name = htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8');
        $admin_name   = htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8');
        $idno         = usat_e($e['student_id_number'] ?? null);
        $lrn          = usat_e($e['lrn'] ?? null);
        $sy           = usat_e($e['school_year'] ?? null);
        $grade        = usat_e($e['grade_level'] ?? null);
        $strand       = usat_e($e['strand'] ?? null);
        $program      = usat_e($e['program'] ?? null);
        $section      = usat_e($e['section'] ?? null, 'TBA');
        $email        = usat_e($e['email'] ?? null, '(no email on file)');
        $when         = date('F d, Y g:i A');
        $year         = date('Y');
        $isApprove    = ($action === 'approved');

        $color   = $isApprove ? '#059669' : '#dc2626';
        $bgColor = $isApprove ? '#d1fae5' : '#fee2e2';
        $label   = $isApprove ? 'APPROVED' : 'REJECTED';
        $verb    = $isApprove ? 'approved' : 'declined and deleted';

        $reasonBlock = '';
        if (!$isApprove && $reason !== null && $reason !== '') {
            $reasonEsc   = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
            $reasonBlock = <<<HTML
          <tr><td colspan="2" style="padding:14px 12px;background:#fef2f2;border-left:4px solid #dc2626;border-radius:8px">
            <strong style="color:#991b1b;display:block;margin-bottom:6px;font-size:13px">Rejection Reason</strong>
            <div style="color:#7f1d1d;font-size:14px">{$reasonEsc}</div>
          </td></tr>
HTML;
        }

        $studentNote = $isApprove
            ? "The student has been sent a confirmation email at <strong>{$email}</strong>."
            : "The student has been sent a decline notification email at <strong>{$email}</strong>. "
            . "The student record and all related data have been <strong>removed from the system</strong>.";

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:Arial,sans-serif;color:#0f172a">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:32px 0">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 12px 32px rgba(15,23,42,.1)">
      <tr><td style="background:#0f172a;padding:20px 32px;color:#fff">
        <div style="font-size:11px;letter-spacing:1.5px;opacity:.7;text-transform:uppercase">USAT Enrollment System</div>
        <div style="font-size:16px;font-weight:700;margin-top:4px">Registrar Notification</div>
      </td></tr>
      <tr><td style="padding:28px 32px">
        <div style="display:inline-block;background:{$bgColor};color:{$color};padding:6px 14px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.5px;margin-bottom:16px">
          {$label}
        </div>
        <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#334155">
          <strong>{$student_name}</strong> was <strong>{$verb}</strong> by <strong>{$admin_name}</strong> on {$when}.
        </p>
        <table width="100%" cellpadding="10" cellspacing="0" style="background:#f8fafc;border-radius:12px;margin:16px 0">
          <tr><td style="width:38%;color:#64748b;font-size:13px;font-weight:600">Student ID</td><td style="font-weight:600">{$idno}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;font-weight:600">LRN</td><td>{$lrn}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;font-weight:600">School Year</td><td>{$sy}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;font-weight:600">Grade Level</td><td>{$grade}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;font-weight:600">Strand</td><td>{$strand}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;font-weight:600">Program</td><td>{$program}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;font-weight:600">Section</td><td>{$section}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;font-weight:600">Student Email</td><td>{$email}</td></tr>
          {$reasonBlock}
        </table>
        <p style="margin:20px 0 0;font-size:13px;color:#64748b;line-height:1.6">
          {$studentNote}
        </p>
      </td></tr>
      <tr><td style="background:#f8fafc;padding:16px 32px;text-align:center;font-size:12px;color:#64748b;border-top:1px solid #e5e7eb">
        Automated notification from the USAT Enrollment System · &copy; {$year}
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
    }
}

if (!function_exists('notify_registrar')) {
    function notify_registrar(string $student_name, array $enr, string $action, ?string $reason): void
    {
        $raw = trim((string)(getenv('REGISTRAR_NOTIFY_EMAIL') ?: ''));
        if ($raw === '') {
            return;
        }

        $recipients = array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        );
        if (empty($recipients)) {
            error_log('notify_registrar: no valid recipients in REGISTRAR_NOTIFY_EMAIL');
            return;
        }

        $admin_name = (string)($_SESSION['admin_name'] ?? 'Registrar');
        $verb_title = $action === 'approved' ? 'Approved' : 'Rejected';
        $subject    = "[USAT] Enrollment {$verb_title}: {$student_name} — " . ($enr['grade_level'] ?? '');
        $body       = registrar_notification_html($student_name, $enr, $action, $admin_name, $reason);

        foreach ($recipients as $rcpt) {
            $r = send_mail($rcpt, $subject, $body);
            if (empty($r['ok'])) {
                error_log(sprintf(
                    'notify_registrar: failed to %s after %d attempt(s) — %s',
                    $rcpt,
                    (int)($r['attempts'] ?? 0),
                    $r['error'] ?? 'unknown'
                ));
            }
        }
    }
}

/* =========================================================
   2. ROLE GATE, CSRF, INPUT
   ========================================================= */

if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)) {
    http_response_code(403);
    security_log('approve_enrollment.forbidden', [
        'admin_id' => (int)($_SESSION['admin_id'] ?? 0),
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    exit('Forbidden');
}

$__pending_url = URL_PREFIX . '/pending_enrollments.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . $__pending_url);
    exit;
}
verify_csrf();

$enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
$action        = clean_string((string)($_POST['action'] ?? ''), 20);
$reason        = clean_string((string)($_POST['reason'] ?? ''), 1000);

if ($enrollment_id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: ' . $__pending_url);
    exit;
}

/* =========================================================
   3. LOAD ENROLLMENT
   ========================================================= */

$stmt = $conn->prepare("
    SELECT e.enrollment_id, e.student_id, e.school_year, e.grade_level, e.term,
           e.track, e.strand, e.program, e.section, e.status, e.enrolled_at,
           s.student_id_number, s.lrn, s.first_name, s.middle_name, s.last_name, s.email
    FROM enrollment_form e
    INNER JOIN students_info s ON s.student_id = e.student_id
    WHERE e.enrollment_id = ?
    LIMIT 1
");
if (!$stmt) {
    error_log('approve_enrollment: load prepare failed: ' . $conn->error);
    $_SESSION['error'] = 'Could not load the enrollment. Please try again.';
    header('Location: ' . $__pending_url);
    exit;
}
$stmt->bind_param('i', $enrollment_id);
$stmt->execute();
$enr = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$enr) {
    $_SESSION['error'] = 'Enrollment not found.';
    header('Location: ' . $__pending_url);
    exit;
}

if (($enr['status'] ?? '') !== 'Pending') {
    $_SESSION['error'] = 'This enrollment has already been processed.';
    header('Location: ' . $__pending_url);
    exit;
}

$admin_id        = (int)$_SESSION['admin_id'];
$student_id      = (int)$enr['student_id'];
$student_name    = trim(($enr['first_name'] ?? '') . ' ' . ($enr['last_name'] ?? ''));
$email_target    = trim((string)($enr['email'] ?? ''));
$has_valid_email = $email_target !== '' && filter_var($email_target, FILTER_VALIDATE_EMAIL) !== false;

/* =========================================================
   4. REJECT
   ========================================================= */

if ($action === 'reject') {
    if ($reason === '') {
        $_SESSION['error'] = 'A rejection reason is required.';
        header('Location: ' . $__pending_url);
        exit;
    }

    /* ── 4a. Collect file paths BEFORE anything is deleted ── */
    $doc_files = [];
    $dq = $conn->prepare("SELECT file_path FROM entrance_documents WHERE student_id = ? AND file_path IS NOT NULL");
    if ($dq) {
        $dq->bind_param('i', $student_id);
        $dq->execute();
        $dres = $dq->get_result();
        while ($d = $dres->fetch_assoc()) {
            if (!empty($d['file_path'])) {
                try {
                    $doc_files[] = safe_path(DOCUMENTS_DIR, (string)$d['file_path']);
                } catch (RuntimeException $e) {
                    security_log('approve.reject.path_escape', [
                        'doc'     => (string)$d['file_path'],
                        'student' => $student_id,
                    ]);
                }
            }
        }
        $dq->close();
    } else {
        error_log('approve_enrollment: document lookup prepare failed: ' . $conn->error);
    }

    $photo_abs = null;
    $pq = $conn->prepare("SELECT photo FROM students_info WHERE student_id = ?");
    if ($pq) {
        $pq->bind_param('i', $student_id);
        $pq->execute();
        $prow = $pq->get_result()->fetch_assoc();
        $pq->close();
        if (!empty($prow['photo'])) {
            try {
                $photo_abs = safe_path(STUDENTS_PHOTO_DIR, (string)$prow['photo']);
            } catch (RuntimeException $e) {
                $photo_abs = null;
            }
        }
    }

    /* ── 4b. Decline email (student row still exists here) ── */
    $email_note = '';
    $email_sent = false;

    if ($has_valid_email) {
        try {
            $subject = 'Enrollment Application Declined — USAT College Sagay City Inc.';
            $body    = rejection_email_html($student_name, $enr, $reason);
            $result  = send_mail($email_target, $subject, $body);
            log_email($conn, $enrollment_id, $student_id, $email_target, $subject, $result, $admin_id);

            if (!empty($result['ok'])) {
                $email_sent = true;
                $email_note = " A decline notification was sent to {$email_target}.";
            } else {
                $email_note = format_email_failure_note($email_target, $result);
            }
        } catch (Throwable $e) {
            error_log('approve_enrollment: decline email failed: ' . $e->getMessage());
            $email_note = ' (Warning: the decline email could not be sent.)';
        }
    } else {
        $email_note = ' (No valid email on file — the student was not notified.)';
    }

    /* ── 4c. Registrar notice (best effort) ── */
    try {
        notify_registrar($student_name, $enr, 'rejected', $reason);
    } catch (Throwable $e) {
        error_log('approve_enrollment: registrar notice failed: ' . $e->getMessage());
    }

    /* ── 4d. Audit BEFORE the row is gone ── */
    try {
        audit_log($conn, 'enrollment.reject', [
            'type'          => 'student',
            'id'            => $student_id,
            'enrollment_id' => $enrollment_id,
            'reason'        => $reason,
            'email'         => $has_valid_email ? $email_target : null,
            'email_sent'    => $email_sent,
            'student_name'  => $student_name,
            'student_id_no' => $enr['student_id_number'] ?? null,
            'school_year'   => $enr['school_year'] ?? null,
            'grade_level'   => $enr['grade_level'] ?? null,
            'strand'        => $enr['strand'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('approve_enrollment: audit (reject) failed: ' . $e->getMessage());
    }

    /* ── 4e. Delete all related rows in one transaction ── */
    $child_tables = [
        'student_grades',
        'entrance_documents',
        'educational_history',
        'addresses',
        'parents_info',
        'enrollment_email_log',
        'document_access_log',
        'student_status_history',
        'enrollment_form',
    ];

    try {
        $conn->begin_transaction();

        foreach ($child_tables as $tbl) {
            $safe_tbl = sql_identifier($tbl);
            $del = $conn->prepare("DELETE FROM `{$safe_tbl}` WHERE student_id = ?");
            if (!$del) {
                // Table may not exist in every deployment; log and continue.
                error_log("approve_enrollment: DELETE prepare failed on {$tbl}: " . $conn->error);
                continue;
            }
            $del->bind_param('i', $student_id);
            $del->execute();
            $del->close();
        }

        $del = $conn->prepare("DELETE FROM students_info WHERE student_id = ?");
        if (!$del) {
            throw new RuntimeException('DELETE students_info prepare failed: ' . $conn->error);
        }
        $del->bind_param('i', $student_id);
        $del->execute();
        $deleted_rows = $del->affected_rows;
        $del->close();

        if ($deleted_rows === 0) {
            throw new RuntimeException('Student row was not found when deleting.');
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('approve_enrollment: reject deletion failed: ' . $e->getMessage());
        security_log('approve.reject_delete_failed', [
            'student_id'    => $student_id,
            'enrollment_id' => $enrollment_id,
            'error'         => $e->getMessage(),
        ]);

        $_SESSION['error'] = 'The decline email was processed, but the student record could not be '
                           . 'fully deleted. Please check the error log or contact the administrator.';
        header('Location: ' . $__pending_url);
        exit;
    }

    /* ── 4f. Delete files only after the commit succeeded ── */
    $root_docs   = realpath(DOCUMENTS_DIR);
    $root_photos = realpath(STUDENTS_PHOTO_DIR);

    foreach ($doc_files as $abs) {
        $real = realpath($abs);
        if ($real && $root_docs && str_starts_with($real, $root_docs . DIRECTORY_SEPARATOR)) {
            @unlink($real);
        }
    }
    if ($photo_abs && is_file($photo_abs)) {
        $real = realpath($photo_abs);
        if ($real && $root_photos && str_starts_with($real, $root_photos . DIRECTORY_SEPARATOR)) {
            @unlink($real);
        }
    }

    try {
        audit_log($conn, 'enrollment.reject_delete', [
            'type'          => 'student',
            'id'            => $student_id,
            'enrollment_id' => $enrollment_id,
            'reason'        => $reason,
            'files_deleted' => count($doc_files) + ($photo_abs ? 1 : 0),
        ]);
    } catch (Throwable $e) {
        error_log('approve_enrollment: audit (reject_delete) failed: ' . $e->getMessage());
    }

    $_SESSION['success'] = "Enrollment declined for {$student_name}.{$email_note} "
                         . 'The student record and all related data have been removed from the system.';
    header('Location: ' . $__pending_url);
    exit;
}

/* =========================================================
   5. APPROVE
   ========================================================= */

/* ── 5a. Status change: the only step that must succeed ── */
try {
    $conn->begin_transaction();

    $chk = $conn->prepare("SELECT status FROM enrollment_form WHERE enrollment_id = ? FOR UPDATE");
    if (!$chk) {
        throw new RuntimeException('status check prepare failed: ' . $conn->error);
    }
    $chk->bind_param('i', $enrollment_id);
    $chk->execute();
    $cur = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$cur || ($cur['status'] ?? '') !== 'Pending') {
        $conn->rollback();
        $_SESSION['error'] = 'This enrollment was already processed by another user.';
        header('Location: ' . $__pending_url);
        exit;
    }

    $upd = $conn->prepare("
        UPDATE enrollment_form
           SET status = 'Active', approved_by = ?, approved_at = NOW(), rejection_reason = NULL
         WHERE enrollment_id = ? AND status = 'Pending'
    ");
    if (!$upd) {
        throw new RuntimeException('approve update prepare failed: ' . $conn->error);
    }
    $upd->bind_param('ii', $admin_id, $enrollment_id);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    if ($affected === 0) {
        $conn->rollback();
        $_SESSION['error'] = 'This enrollment was already processed by another user.';
        header('Location: ' . $__pending_url);
        exit;
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('approve_enrollment: approve failed: ' . $e->getMessage());
    security_log('approve.failed', [
        'enrollment_id' => $enrollment_id,
        'error'         => $e->getMessage(),
    ]);
    $_SESSION['error'] = 'Approval failed. Please try again.';
    header('Location: ' . $__pending_url);
    exit;
}

/* ── 5b. The approval is saved. Everything below is best-effort. ── */

try {
    $hist = $conn->prepare("
        INSERT INTO student_status_history
            (student_id, old_status, new_status, reason, changed_by)
        VALUES (?, 'Pending', 'Active', ?, ?)
    ");
    if ($hist) {
        $hist_reason = 'Enrollment approved by registrar';
        $hist->bind_param('isi', $student_id, $hist_reason, $admin_id);
        $hist->execute();
        $hist->close();
    } else {
        error_log('approve_enrollment: status history prepare failed: ' . $conn->error);
    }
} catch (Throwable $e) {
    error_log('approve_enrollment: status history skipped: ' . $e->getMessage());
}

try {
    audit_log($conn, 'enrollment.approve', [
        'type'          => 'student',
        'id'            => $student_id,
        'enrollment_id' => $enrollment_id,
        'sy'            => $enr['school_year'] ?? null,
        'grade'         => $enr['grade_level'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('approve_enrollment: audit (approve) failed: ' . $e->getMessage());
}

try {
    notify_registrar($student_name, $enr, 'approved', null);
} catch (Throwable $e) {
    error_log('approve_enrollment: registrar notice failed: ' . $e->getMessage());
}

/* ── 5c. Student confirmation email ── */
$email_note = '';

if ($has_valid_email) {
    try {
        $subject = 'You are officially enrolled — USAT College Sagay City Inc.';
        $body    = approval_email_html($student_name, $enr);
        $result  = send_mail($email_target, $subject, $body);
        log_email($conn, $enrollment_id, $student_id, $email_target, $subject, $result, $admin_id);

        $email_note = !empty($result['ok'])
            ? " Confirmation email sent to {$email_target}."
            : format_email_failure_note($email_target, $result);
    } catch (Throwable $e) {
        error_log('approve_enrollment: approval email failed: ' . $e->getMessage());
        $email_note = ' (Warning: the confirmation email could not be sent.)';
    }
} else {
    $email_note = ' (No valid email on file — student was not notified.)';
}

$_SESSION['success'] = "Enrollment approved for {$student_name}.{$email_note}";
header('Location: ' . $__pending_url);
exit;