<?php
// admin/approve_enrollment.php — Approve or reject a pending enrollment.
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/mailer.php';

if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pending_enrollments.php');
    exit;
}
verify_csrf();

$enrollment_id = (int)($_POST['enrollment_id'] ?? 0);
$action        = $_POST['action'] ?? '';
$reason        = trim($_POST['reason'] ?? '');

if ($enrollment_id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: pending_enrollments.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT e.enrollment_id, e.student_id, e.school_year, e.grade_level, e.term,
           e.track, e.strand, e.program, e.section, e.status, e.enrolled_at,
           s.student_id_number, s.lrn, s.first_name, s.middle_name, s.last_name, s.email
    FROM enrollment_form e
    INNER JOIN students_info s ON s.student_id = e.student_id
    WHERE e.enrollment_id = ?
    LIMIT 1
");
$stmt->bind_param('i', $enrollment_id);
$stmt->execute();
$enr = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$enr) {
    $_SESSION['error'] = 'Enrollment not found.';
    header('Location: pending_enrollments.php');
    exit;
}

if ($enr['status'] !== 'Pending') {
    $_SESSION['error'] = 'This enrollment has already been processed.';
    header('Location: pending_enrollments.php');
    exit;
}

$admin_id     = (int)$_SESSION['admin_id'];
$student_name = trim(($enr['first_name'] ?? '') . ' ' . ($enr['last_name'] ?? ''));

// ─── REJECT ────────────────────────────────────────────────
if ($action === 'reject') {
    if ($reason === '') {
        $_SESSION['error'] = 'A rejection reason is required.';
        header('Location: pending_enrollments.php');
        exit;
    }

    $upd = $conn->prepare("
        UPDATE enrollment_form
        SET status = 'Dropped', rejection_reason = ?, approved_by = ?, approved_at = NOW()
        WHERE enrollment_id = ? AND status = 'Pending'
    ");
    $upd->bind_param('sii', $reason, $admin_id, $enrollment_id);
    $upd->execute();
    $upd->close();

    audit_log($conn, 'enrollment.reject', [
        'type'          => 'student',
        'id'            => (int)$enr['student_id'],
        'enrollment_id' => $enrollment_id,
        'reason'        => $reason,
    ]);

    notify_registrar($conn, $student_name, $enr, 'rejected', $reason);

    $email_note = '';
    if (!empty($enr['email'])) {
        $subject = 'Enrollment Application Update — USAT College';
        $body    = rejection_email_html($student_name, $enr, $reason);
        $r       = send_mail($enr['email'], $subject, $body);
        log_email($conn, $enrollment_id, (int)$enr['student_id'], $enr['email'], $subject, $r, $admin_id);

        $email_note = $r['ok']
            ? " Notification email sent to {$enr['email']}."
            : format_email_failure_note($enr['email'], $r);
    } else {
        $email_note = " (No email on file — student was not notified.)";
    }

    $_SESSION['success'] = "Enrollment rejected for {$student_name}.{$email_note}";
    header('Location: pending_enrollments.php');
    exit;
}

// ─── APPROVE ───────────────────────────────────────────────
$conn->begin_transaction();
try {
    $upd = $conn->prepare("
        UPDATE enrollment_form
        SET status = 'Active', approved_by = ?, approved_at = NOW(), rejection_reason = NULL
        WHERE enrollment_id = ? AND status = 'Pending'
    ");
    $upd->bind_param('ii', $admin_id, $enrollment_id);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    if ($affected === 0) {
        $conn->rollback();
        $_SESSION['error'] = 'This enrollment was already processed by another user.';
        header('Location: pending_enrollments.php');
        exit;
    }

    try {
        $hist = $conn->prepare("
            INSERT INTO student_status_history
                (student_id, old_status, new_status, reason, changed_by)
            VALUES (?, 'Pending', 'Active', ?, ?)
        ");
        if ($hist) {
            $hist_reason = 'Enrollment approved by registrar';
            $hist->bind_param('isi', $enr['student_id'], $hist_reason, $admin_id);
            $hist->execute();
            $hist->close();
        }
    } catch (\Throwable $ignore) {
        error_log('approve_enrollment: status history insert skipped: ' . $ignore->getMessage());
    }

    $conn->commit();

    audit_log($conn, 'enrollment.approve', [
        'type'          => 'student',
        'id'            => (int)$enr['student_id'],
        'enrollment_id' => $enrollment_id,
        'sy'            => $enr['school_year'],
        'grade'         => $enr['grade_level'],
    ]);

    notify_registrar($conn, $student_name, $enr, 'approved', null);

    $email_note = '';
    if (!empty($enr['email'])) {
        $subject = 'You are officially enrolled — USAT College Sagay City Inc.';
        $body    = approval_email_html($student_name, $enr);
        $result  = send_mail($enr['email'], $subject, $body);
        log_email($conn, $enrollment_id, (int)$enr['student_id'], $enr['email'], $subject, $result, $admin_id);

        $email_note = $result['ok']
            ? " Confirmation email sent to {$enr['email']}."
            : format_email_failure_note($enr['email'], $result);
    } else {
        $email_note = " (No email on file — student was not notified.)";
    }

    $_SESSION['success'] = "Enrollment approved for {$student_name}.{$email_note}";
} catch (\Throwable $e) {
    $conn->rollback();
    error_log('Approve enrollment failed: ' . $e->getMessage());
    $_SESSION['error'] = 'Approval failed. Please try again.';
}

header('Location: pending_enrollments.php');
exit;

// ═════════════════════════════════════════════════════════════
// HELPERS
// ═════════════════════════════════════════════════════════════

function format_email_failure_note(string $to, array $result): string
{
    $attempts = (int)($result['attempts'] ?? 0);
    $err      = trim((string)($result['error'] ?? '')) ?: 'no error message';
    $kind     = !empty($result['transient']) ? 'transient' : 'permanent';

    if (strlen($err) > 200) {
        $err = substr($err, 0, 197) . '...';
    }

    return sprintf(
        " (Warning: email to %s failed after %d attempt(s) [%s] — %s)",
        htmlspecialchars($to),
        $attempts,
        $kind,
        htmlspecialchars($err)
    );
}

function log_email(mysqli $conn, int $eid, int $sid, string $to, string $subject, array $r, int $admin_id): void
{
    $status = $r['ok'] ? 'sent' : 'failed';

    if (!$r['ok']) {
        $err = trim((string)($r['error'] ?? 'unknown'));
        if (isset($r['attempts'])) {
            $err = sprintf('[attempts=%d, transient=%s] %s',
                (int)$r['attempts'],
                !empty($r['transient']) ? 'yes' : 'no',
                $err
            );
        }
    } else {
        $err = null;
    }

    $stmt = $conn->prepare("
        INSERT INTO enrollment_email_log
            (enrollment_id, student_id, recipient, subject, status, error_message, sent_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) return;
    $stmt->bind_param('iissssi', $eid, $sid, $to, $subject, $status, $err, $admin_id);
    $stmt->execute();
    $stmt->close();
}

function notify_registrar(mysqli $conn, string $student_name, array $enr, string $action, ?string $reason): void
{
    $raw = trim(getenv('REGISTRAR_NOTIFY_EMAIL') ?: '');
    if ($raw === '') return;

    $recipients = array_filter(
        array_map('trim', explode(',', $raw)),
        fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
    );
    if (empty($recipients)) {
        error_log('notify_registrar: no valid recipients in REGISTRAR_NOTIFY_EMAIL');
        return;
    }

    $admin_name = $_SESSION['admin_name'] ?? 'Registrar';
    $verb_title = $action === 'approved' ? 'Approved' : 'Rejected';
    $subject    = "[USAT] Enrollment {$verb_title}: {$student_name} — " . ($enr['grade_level'] ?? '');

    $body = registrar_notification_html($student_name, $enr, $action, $admin_name, $reason);

    foreach ($recipients as $rcpt) {
        $r = send_mail($rcpt, $subject, $body);
        if (!$r['ok']) {
            error_log(sprintf(
                'notify_registrar: failed to %s after %d attempt(s) — %s',
                $rcpt,
                (int)($r['attempts'] ?? 0),
                $r['error'] ?? 'unknown'
            ));
        }
    }
}

function approval_email_html(string $name, array $e): string
{
    $sy      = htmlspecialchars($e['school_year']);
    $grade   = htmlspecialchars($e['grade_level']);
    $strand  = htmlspecialchars($e['strand'] ?: '—');
    $program = htmlspecialchars($e['program'] ?: '—');
    $section = htmlspecialchars($e['section'] ?: 'TBA');
    $idno    = htmlspecialchars($e['student_id_number']);
    $lrn     = htmlspecialchars($e['lrn']);
    $name    = htmlspecialchars($name);
    $year    = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:Arial,sans-serif;color:#0f172a">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:32px 0">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 12px 32px rgba(15,23,42,.1)">
      <tr><td style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:28px 32px;text-align:center;color:#fff">
        <h1 style="margin:0;font-size:20px;letter-spacing:.5px">USAT College Sagay City Inc.</h1>
        <p style="margin:6px 0 0;opacity:.9;font-size:13px">Enrollment Confirmation</p>
      </td></tr>
      <tr><td style="padding:32px">
        <p style="margin:0 0 16px;font-size:16px">Dear <strong>{$name}</strong>,</p>
        <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#334155">
          We are pleased to inform you that your enrollment has been <strong style="color:#059669">officially approved</strong>. Welcome to USAT College Sagay City Inc.!
        </p>
        <table width="100%" cellpadding="10" cellspacing="0" style="background:#f8fafc;border-radius:12px;margin:20px 0">
          <tr><td style="width:40%;color:#64748b;font-size:13px;font-weight:600">Student ID</td><td style="font-weight:700">{$idno}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;font-weight:600">LRN</td><td>{$lrn}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;font-weight:600">School Year</td><td>{$sy}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;font-weight:600">Grade Level</td><td>{$grade}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;font-weight:600">Strand</td><td>{$strand}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;font-weight:600">Program</td><td>{$program}</td></tr>
          <tr><td style="color:#64748b;font-size:13px;font-weight:600">Section</td><td>{$section}</td></tr>
        </table>
        <p style="margin:16px 0 0;font-size:14px;line-height:1.6;color:#334155">
          Please keep this email for your records. Bring a printed copy on your first day.
        </p>
      </td></tr>
      <tr><td style="background:#f8fafc;padding:20px 32px;text-align:center;font-size:12px;color:#64748b;border-top:1px solid #e5e7eb">
        This is an automated message. Please do not reply.<br>
        © {$year} USAT College Sagay City Inc.
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
}

function rejection_email_html(string $name, array $e, string $reason): string
{
    $name   = htmlspecialchars($name);
    $reason = htmlspecialchars($reason);
    $year   = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:Arial,sans-serif;color:#0f172a">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:32px 0">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden">
      <tr><td style="background:#dc2626;padding:24px 32px;text-align:center;color:#fff">
        <h1 style="margin:0;font-size:18px">Enrollment Update</h1>
      </td></tr>
      <tr><td style="padding:32px">
        <p style="margin:0 0 16px;font-size:16px">Dear <strong>{$name}</strong>,</p>
        <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155">
          After reviewing your enrollment application, we are unable to approve it at this time.
        </p>
        <div style="background:#fef2f2;border-left:4px solid #dc2626;padding:14px 18px;border-radius:8px;margin:20px 0">
          <strong style="color:#991b1b;display:block;margin-bottom:6px;font-size:13px">Reason</strong>
          <div style="color:#7f1d1d">{$reason}</div>
        </div>
        <p style="margin:0;font-size:14px;color:#334155">
          Please contact the registrar's office if you have questions or wish to resubmit.
        </p>
      </td></tr>
      <tr><td style="background:#f8fafc;padding:16px;text-align:center;font-size:12px;color:#64748b">
        © {$year} USAT College Sagay City Inc.
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
}

function registrar_notification_html(string $student_name, array $e, string $action, string $admin_name, ?string $reason = null): string
{
    $student_name = htmlspecialchars($student_name);
    $admin_name   = htmlspecialchars($admin_name);
    $idno         = htmlspecialchars($e['student_id_number'] ?? '—');
    $lrn          = htmlspecialchars($e['lrn'] ?? '—');
    $sy           = htmlspecialchars($e['school_year'] ?? '—');
    $grade        = htmlspecialchars($e['grade_level'] ?? '—');
    $strand       = htmlspecialchars($e['strand'] ?: '—');
    $program      = htmlspecialchars($e['program'] ?: '—');
    $section      = htmlspecialchars($e['section'] ?: 'TBA');
    $email        = htmlspecialchars($e['email'] ?: '(no email on file)');
    $when         = date('F d, Y g:i A');
    $year         = date('Y');
    $isApprove    = ($action === 'approved');

    $color   = $isApprove ? '#059669' : '#dc2626';
    $bgColor = $isApprove ? '#d1fae5' : '#fee2e2';
    $label   = $isApprove ? 'APPROVED' : 'REJECTED';
    $verb    = $isApprove ? 'approved' : 'rejected';

    $reasonBlock = '';
    if (!$isApprove && $reason !== null && $reason !== '') {
        $reasonEsc = htmlspecialchars($reason);
        $reasonBlock = <<<HTML
          <tr><td colspan="2" style="padding:14px 12px;background:#fef2f2;border-left:4px solid #dc2626;border-radius:8px">
            <strong style="color:#991b1b;display:block;margin-bottom:6px;font-size:13px">Rejection Reason</strong>
            <div style="color:#7f1d1d;font-size:14px">{$reasonEsc}</div>
          </td></tr>
        HTML;
    }

    $studentNote = $isApprove
        ? "The student has been sent a confirmation email at <strong>{$email}</strong>."
        : "The student has been sent a notification email at <strong>{$email}</strong>.";

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
        Automated notification from the USAT Enrollment System · © {$year}
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
}