<?php
declare(strict_types=1);

/**
 * shared/pages/approve_enrollment.php — Approve or reject a pending enrollment.
 */

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/mailer.php';
require_once __DIR__ . '/../../config/security.php';

/* =========================================================
   1. HELPERS
   ========================================================= */

if (!function_exists('usat_e')) {
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
        if (strlen($err) > 200) { $err = substr($err, 0, 197) . '...'; }
        return sprintf(
            ' (Warning: email to %s failed after %d attempt(s) [%s] — %s)',
            htmlspecialchars($to, ENT_QUOTES, 'UTF-8'),
            $attempts, $kind, htmlspecialchars($err, ENT_QUOTES, 'UTF-8')
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
                $err = sprintf('[attempts=%d, transient=%s] %s', (int)$r['attempts'], !empty($r['transient']) ? 'yes' : 'no', $err);
            }
        }
        $stmt = $conn->prepare("INSERT INTO enrollment_email_log (enrollment_id, student_id, recipient, subject, status, error_message, sent_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) { error_log('log_email: prepare failed: ' . $conn->error); return; }
        $stmt->bind_param('iissssi', $eid, $sid, $to, $subject, $status, $err, $admin_id);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('usat_logo_html')) {
    /** Formal Logo <img> for emails */
    function usat_logo_html(): string
    {
        $base = trim((string)(getenv('APP_PUBLIC_URL') ?: ''));
        if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false) {
            return ''; // Return empty if no URL is set
        }
        // Point to the logo image
        $src = rtrim($base, '/') . '/assets/img/usat.jpg';
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" width="90" height="90"'
             . ' alt="USAT College Logo"'
             . ' style="display:block;margin:0 auto 12px;width:90px;height:90px;border-radius:50%;object-fit:cover;border:3px solid #ffffff;box-shadow:0 4px 6px rgba(0,0,0,0.1);">';
    }
}

if (!function_exists('usat_detail_table')) {
    function usat_detail_table(array $rows): string
    {
        $html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;border-collapse:separate;overflow:hidden;margin-bottom:20px;">';
        $count = count($rows);
        $i     = 0;
        foreach ($rows as $label => $value) {
            $rule = $i < $count - 1 ? 'border-bottom:1px solid #e2e8f0;' : '';
            $bg = $i % 2 === 0 ? '#f8fafc' : '#ffffff';
            $html .= '<tr style="background-color:' . $bg . ';">'
                   . '<td style="padding:12px 16px;width:35%;color:#475569;font-size:13px;font-weight:600;' . $rule . '">'
                   .     htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '</td>'
                   . '<td style="padding:12px 16px;color:#0f172a;font-size:14px;font-weight:500;' . $rule . '">' . $value . '</td>'
                   . '</tr>';
            $i++;
        }
        return $html . '</table>';
    }
}

if (!function_exists('usat_email_layout')) {
    /** Formal Corporate Layout */
    function usat_email_layout(string $headerColor, string $title, string $subtitle, string $bodyHtml): string
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
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Arial,sans-serif;color:#1e293b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 12px;">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 4px 20px rgba(15,23,42,.08);border:1px solid #e2e8f0;">

      <!-- Header -->
      <tr><td style="background:{$headerColor};padding:40px 30px 30px;text-align:center;color:#ffffff;border-bottom:4px solid #fbbf24;">
        {$logo}
        <div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;opacity:.9;font-weight:600;margin-bottom:8px">Republic of the Philippines</div>
        <div style="font-family:Georgia,serif;font-size:24px;font-weight:bold;margin-top:4px;line-height:1.2">USAT College Sagay City Inc.</div>
        <div style="font-size:14px;opacity:.9;margin-top:8px;font-weight:500">{$s}</div>
      </td></tr>

      <!-- Body -->
      <tr><td style="padding:40px 40px 30px;font-family:'Segoe UI',Arial,sans-serif;">
        {$bodyHtml}
      </td></tr>

      <!-- Footer -->
      <tr><td style="background:#f8fafc;padding:24px 40px;text-align:center;font-size:12px;color:#64748b;border-top:1px solid #e2e8f0;line-height:1.6;">
        <strong>Office of the Registrar</strong><br>
        USAT College Sagay City Inc., Negros Occidental<br>
        This is an automated message. Please do not reply directly to this email.<br>
        &copy; {$year} USAT College Sagay City Inc. All rights reserved.
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
            'Student ID'  => '<strong style="color:#047857;font-size:16px;">' . usat_e($e['student_id_number'] ?? null) . '</strong>',
            'LRN'         => usat_e($e['lrn'] ?? null),
            'School Year' => usat_e($e['school_year'] ?? null),
            'Grade Level' => usat_e($e['grade_level'] ?? null),
            'Strand'      => usat_e($e['strand'] ?? null),
            'Program'     => usat_e($e['program'] ?? null),
            'Section'     => usat_e($e['section'] ?? null, '<em>To be announced</em>'),
        ];

        $body = ''
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.6;">Dear <strong>' . usat_e($name) . '</strong>,</p>'
            . '<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#334155;">'
            .     'We are pleased to inform you that your enrollment application for the upcoming academic term has been <strong>officially approved</strong>. Welcome to USAT College Sagay City Inc.!'
            . '</p>'
            
            // Status Banner
            . '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:30px;border-radius:6px;overflow:hidden;border:1px solid #059669;">'
            . '<tr><td style="background:#059669;color:#ffffff;padding:12px 20px;font-size:13px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;text-align:center;">'
            . '✓ Application Status: Approved'
            . '</td></tr></table>'

            . '<p style="margin:0 0 12px;font-size:14px;font-weight:700;color:#0f172a;">Your Enrollment Details</p>'
            . usat_detail_table($rows)
            
            . '<p style="margin:30px 0 12px;font-size:14px;font-weight:700;color:#0f172a;">Important Next Steps</p>'
            . '<ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.7;color:#334155;">'
            .   '<li style="margin-bottom:8px;">Please keep this email as your official proof of enrollment.</li>'
            .   '<li style="margin-bottom:8px;">Bring a printed copy of this email on the first day of classes.</li>'
            .   '<li style="margin-bottom:8px;">Monitor your email and the official USAT social media pages for updates regarding schedules and orientation.</li>'
            . '</ul>'

            . '<p style="margin:30px 0 0;font-size:15px;line-height:1.6;color:#334155;">Sincerely,</p>'
            . '<p style="margin:5px 0 0;font-size:15px;line-height:1.6;color:#334155;font-weight:bold;">Office of the University Registrar</p>'
            . '<p style="margin:0;font-size:14px;color:#64748b;">USAT College Sagay City Inc.</p>';

        return usat_email_layout(
            '#1e3a8a', // Formal Navy Blue
            'Enrollment Approved',
            'Official Notice of Admission',
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
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.6;">Dear <strong>' . usat_e($name) . '</strong>,</p>'
            . '<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#334155;">'
            .     'Thank you for your interest in USAT College Sagay City Inc. We have carefully reviewed your enrollment application. Regrettably, we are unable to approve your application at this time.'
            . '</p>'
            
            // Status Banner
            . '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:30px;border-radius:6px;overflow:hidden;border:1px solid #dc2626;">'
            . '<tr><td style="background:#dc2626;color:#ffffff;padding:12px 20px;font-size:13px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;text-align:center;">'
            . '✗ Application Status: Declined'
            . '</td></tr></table>'

            . '<p style="margin:0 0 12px;font-size:14px;font-weight:700;color:#0f172a;">Application Details</p>'
            . usat_detail_table($rows)
            
            . '<div style="margin:24px 0;background:#fef2f2;border-left:4px solid #dc2626;padding:16px 20px;border-radius:0 6px 6px 0;">'
            .     '<div style="font-size:12px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">Reason for Decision</div>'
            .     '<div style="font-size:14px;line-height:1.6;color:#7f1d1d;font-weight:500;">' . usat_e($reason) . '</div>'
            . '</div>'

            . '<p style="margin:30px 0 12px;font-size:14px;font-weight:700;color:#0f172a;">What you can do:</p>'
            . '<ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.7;color:#334155;">'
            .   '<li style="margin-bottom:8px;">If you believe this is a mistake, please contact the Registrar\'s Office immediately.</li>'
            .   '<li style="margin-bottom:8px;">You may correct the issue mentioned above and submit a new application.</li>'
            . '</ul>'
            . '<p style="margin:30px 0 0;font-size:13px;line-height:1.6;color:#64748b;font-style:italic;">Note: Your application record has been securely removed from our system. A new application is required to re-apply.</p>'

            . '<p style="margin:30px 0 0;font-size:15px;line-height:1.6;color:#334155;">Sincerely,</p>'
            . '<p style="margin:5px 0 0;font-size:15px;line-height:1.6;color:#334155;font-weight:bold;">Office of the University Registrar</p>'
            . '<p style="margin:0;font-size:14px;color:#64748b;">USAT College Sagay City Inc.</p>';

        return usat_email_layout(
            '#0f172a', // Dark Slate
            'Enrollment Application Declined',
            'Official Notice of Application Status',
            $body
        );
    }
}

if (!function_exists('registrar_notification_html')) {
    function registrar_notification_html(string $student_name, array $e, string $action, string $admin_name, ?string $reason = null): string
    {
        $student_name = htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8');
        $admin_name   = htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8');
        $idno         = usat_e($e['student_id_number'] ?? null);
        $lrn          = usat_e($e['lrn'] ?? null);
        $sy           = usat_e($e['school_year'] ?? null);
        $grade        = usat_e($e['grade_level'] ?? null);
        $strand       = usat_e($e['strand'] ?? null);
        $section      = usat_e($e['section'] ?? null, 'TBA');
        $email        = usat_e($e['email'] ?? null, '(no email)');
        $when         = date('F d, Y g:i A');
        
        $isApprove    = ($action === 'approved');
        $color        = $isApprove ? '#059669' : '#dc2626';
        $bgColor      = $isApprove ? '#ecfdf5' : '#fef2f2';
        $label        = $isApprove ? 'APPROVED' : 'REJECTED';
        $verb         = $isApprove ? 'approved' : 'declined';

        $reasonBlock = '';
        if (!$isApprove && $reason !== null && $reason !== '') {
            $reasonBlock = '<tr><td colspan="2" style="padding:12px 16px;background:#fef2f2;border-top:1px solid #fecaca;color:#991b1b;font-size:13px;"><strong>Reason:</strong> ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Arial,sans-serif;color:#1e293b;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:30px 12px;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 4px 12px rgba(0,0,0,.05);">
      
      <tr><td style="background:#0f172a;padding:20px 30px;color:#ffffff;">
        <div style="font-size:11px;letter-spacing:1px;opacity:.8;text-transform:uppercase;font-weight:600;">Internal Memo</div>
        <div style="font-size:18px;font-weight:bold;margin-top:4px;">Enrollment Action Notification</div>
      </td></tr>
      
      <tr><td style="padding:30px;">
        
        <table width="100%" style="margin-bottom:20px;">
          <tr>
            <td style="font-size:14px;color:#475569;line-height:1.6;">
              <strong>{$student_name}</strong> was <strong style="color:{$color}">{$verb}</strong> by <strong>{$admin_name}</strong>.<br>
              <span style="font-size:12px;color:#94a3b8;">{$when}</span>
            </td>
            <td align="right" valign="top">
              <span style="display:inline-block;background:{$bgColor};color:{$color};border:1px solid {$color};padding:4px 12px;border-radius:4px;font-size:11px;font-weight:bold;letter-spacing:1px;">{$label}</span>
            </td>
          </tr>
        </table>

        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #cbd5e1;border-radius:6px;overflow:hidden;font-size:13px;">
          <tr><td style="background:#f8fafc;padding:10px 16px;color:#64748b;font-weight:600;width:35%;border-bottom:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">Student ID</td><td style="padding:10px 16px;border-bottom:1px solid #e2e8f0;font-weight:600;">{$idno}</td></tr>
          <tr><td style="background:#f8fafc;padding:10px 16px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">LRN</td><td style="padding:10px 16px;border-bottom:1px solid #e2e8f0;">{$lrn}</td></tr>
          <tr><td style="background:#f8fafc;padding:10px 16px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">School Year</td><td style="padding:10px 16px;border-bottom:1px solid #e2e8f0;">{$sy}</td></tr>
          <tr><td style="background:#f8fafc;padding:10px 16px;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;border-right:1px solid #e2e8f0;">Grade & Strand</td><td style="padding:10px 16px;border-bottom:1px solid #e2e8f0;">{$grade} - {$strand}</td></tr>
          <tr><td style="background:#f8fafc;padding:10px 16px;color:#64748b;font-weight:600;border-right:1px solid #e2e8f0;">Section</td><td style="padding:10px 16px;">{$section}</td></tr>
          {$reasonBlock}
        </table>
        
        <p style="margin:20px 0 0;font-size:12px;color:#64748b;">
          <em>The student was notified via email at {$email}.</em>
        </p>
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
        if ($raw === '') { return; }

        $recipients = array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        );
        if (empty($recipients)) { return; }

        $admin_name = (string)($_SESSION['admin_name'] ?? 'Registrar');
        $verb_title = $action === 'approved' ? 'Approved' : 'Rejected';
        $subject    = "[USAT] Enrollment {$verb_title}: {$student_name} — " . ($enr['grade_level'] ?? '');
        $body       = registrar_notification_html($student_name, $enr, $action, $admin_name, $reason);

        foreach ($recipients as $rcpt) {
            $r = send_mail($rcpt, $subject, $body);
            if (empty($r['ok'])) {
                error_log(sprintf('notify_registrar: failed to %s — %s', $rcpt, $r['error'] ?? 'unknown'));
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

    $doc_files = [];
    $dq = $conn->prepare("SELECT file_path FROM entrance_documents WHERE student_id = ? AND file_path IS NOT NULL");
    if ($dq) {
        $dq->bind_param('i', $student_id);
        $dq->execute();
        $dres = $dq->get_result();
        while ($d = $dres->fetch_assoc()) {
            if (!empty($d['file_path'])) {
                try { $doc_files[] = safe_path(DOCUMENTS_DIR, (string)$d['file_path']); } catch (RuntimeException $e) { }
            }
        }
        $dq->close();
    }

    $photo_abs = null;
    $pq = $conn->prepare("SELECT photo FROM students_info WHERE student_id = ?");
    if ($pq) {
        $pq->bind_param('i', $student_id);
        $pq->execute();
        $prow = $pq->get_result()->fetch_assoc();
        $pq->close();
        if (!empty($prow['photo'])) {
            try { $photo_abs = safe_path(STUDENTS_PHOTO_DIR, (string)$prow['photo']); } catch (RuntimeException $e) { }
        }
    }

    $email_note = '';
    $email_sent = false;

    if ($has_valid_email) {
        try {
            $subject = 'Enrollment Application Declined — USAT College';
            $body    = rejection_email_html($student_name, $enr, $reason);
            $result  = send_mail($email_target, $subject, $body);
            log_email($conn, $enrollment_id, $student_id, $email_target, $subject, $result, $admin_id);

            if (!empty($result['ok'])) {
                $email_sent = true;
                $email_note = " A formal decline notification was sent to {$email_target}.";
            } else {
                $email_note = format_email_failure_note($email_target, $result);
            }
        } catch (Throwable $e) {
            $email_note = ' (Warning: the decline email could not be sent.)';
        }
    } else {
        $email_note = ' (No valid email on file — the student was not notified.)';
    }

    try { notify_registrar($student_name, $enr, 'rejected', $reason); } catch (Throwable $e) { }

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
    } catch (Throwable $e) { }

    $child_tables = ['student_grades', 'entrance_documents', 'educational_history', 'addresses', 'parents_info', 'enrollment_email_log', 'document_access_log', 'student_status_history', 'enrollment_form'];

    try {
        $conn->begin_transaction();

        foreach ($child_tables as $tbl) {
            $safe_tbl = sql_identifier($tbl);
            $del = $conn->prepare("DELETE FROM `{$safe_tbl}` WHERE student_id = ?");
            if (!$del) continue;
            $del->bind_param('i', $student_id);
            $del->execute();
            $del->close();
        }

        $del = $conn->prepare("DELETE FROM students_info WHERE student_id = ?");
        if (!$del) throw new RuntimeException('DELETE students_info prepare failed');
        $del->bind_param('i', $student_id);
        $del->execute();
        $deleted_rows = $del->affected_rows;
        $del->close();

        if ($deleted_rows === 0) throw new RuntimeException('Student row not found');

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['error'] = 'The decline email was processed, but the student record could not be fully deleted. Please check the error log.';
        header('Location: ' . $__pending_url);
        exit;
    }

    $root_docs   = realpath(DOCUMENTS_DIR);
    $root_photos = realpath(STUDENTS_PHOTO_DIR);

    foreach ($doc_files as $abs) {
        $real = realpath($abs);
        if ($real && $root_docs && str_starts_with($real, $root_docs . DIRECTORY_SEPARATOR)) { @unlink($real); }
    }
    if ($photo_abs && is_file($photo_abs)) {
        $real = realpath($photo_abs);
        if ($real && $root_photos && str_starts_with($real, $root_photos . DIRECTORY_SEPARATOR)) { @unlink($real); }
    }

    $_SESSION['success'] = "Enrollment declined for {$student_name}.{$email_note} The student record and all related data have been removed from the system.";
    header('Location: ' . $__pending_url);
    exit;
}

/* =========================================================
   5. APPROVE
   ========================================================= */

try {
    $conn->begin_transaction();

    $chk = $conn->prepare("SELECT status FROM enrollment_form WHERE enrollment_id = ? FOR UPDATE");
    if (!$chk) throw new RuntimeException('status check prepare failed');
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

    $upd = $conn->prepare("UPDATE enrollment_form SET status = 'Active', approved_by = ?, approved_at = NOW(), rejection_reason = NULL WHERE enrollment_id = ? AND status = 'Pending'");
    if (!$upd) throw new RuntimeException('approve update prepare failed');
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
    $_SESSION['error'] = 'Approval failed. Please try again.';
    header('Location: ' . $__pending_url);
    exit;
}

try {
    $hist = $conn->prepare("INSERT INTO student_status_history (student_id, old_status, new_status, reason, changed_by) VALUES (?, 'Pending', 'Active', ?, ?)");
    if ($hist) {
        $hist_reason = 'Enrollment approved by registrar';
        $hist->bind_param('isi', $student_id, $hist_reason, $admin_id);
        $hist->execute();
        $hist->close();
    }
} catch (Throwable $e) { }

try {
    audit_log($conn, 'enrollment.approve', [
        'type'          => 'student',
        'id'            => $student_id,
        'enrollment_id' => $enrollment_id,
        'sy'            => $enr['school_year'] ?? null,
        'grade'         => $enr['grade_level'] ?? null,
    ]);
} catch (Throwable $e) { }

try { notify_registrar($student_name, $enr, 'approved', null); } catch (Throwable $e) { }

$email_note = '';

if ($has_valid_email) {
    try {
        $subject = 'Official Enrollment Confirmation — USAT College Sagay City Inc.';
        $body    = approval_email_html($student_name, $enr);
        $result  = send_mail($email_target, $subject, $body);
        log_email($conn, $enrollment_id, $student_id, $email_target, $subject, $result, $admin_id);

        $email_note = !empty($result['ok'])
            ? " A formal confirmation email was sent to {$email_target}."
            : format_email_failure_note($email_target, $result);
    } catch (Throwable $e) {
        $email_note = ' (Warning: the confirmation email could not be sent.)';
    }
} else {
    $email_note = ' (No valid email on file — student was not notified.)';
}

$_SESSION['success'] = "Enrollment approved for {$student_name}.{$email_note}";
header('Location: ' . $__pending_url);
exit;