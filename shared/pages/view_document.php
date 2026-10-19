<?php
declare(strict_types=1);

/**
 * shared/pages/view_document.php — Decrypt and stream an entrance document.
 *
 * Hardening applied:
 *   - Role check (registrar / superadmin only) via config/auth.php
 *   - Path containment via safe_path()
 *   - Full HTTP hardening (CSP sandbox, X-Frame-Options, nosniff)
 *   - Structured security logging
 *   - Access audit trail preserved
 */

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/crypto.php';
require_once __DIR__ . '/../../config/security.php';

/* ── 1. Role gate ─────────────────────────────────────────────── */
if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)) {
    http_response_code(403);
    security_log('document.forbidden', [
        'admin_id' => (int)($_SESSION['admin_id'] ?? 0),
        'doc_id'   => (int)($_GET['id'] ?? 0),
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    exit('Forbidden');
}

$admin_id = (int)$_SESSION['admin_id'];

/* ── 2. Validate ID ───────────────────────────────────────────── */
$doc_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$doc_id || $doc_id < 1) {
    http_response_code(400);
    exit('Invalid document id');
}

$force_download = isset($_GET['dl']) && $_GET['dl'] === '1';

/* ── 3. Fetch document row ────────────────────────────────────── */
$stmt = $conn->prepare("
    SELECT d.entrance_id, d.student_id, d.document_name,
           d.file_path, d.file_mime, d.file_name,
           s.student_id_number, s.first_name, s.last_name
    FROM entrance_documents d
    JOIN students_info s ON s.student_id = d.student_id
    WHERE d.entrance_id = ? AND d.submitted = 1 AND d.file_path IS NOT NULL
    LIMIT 1
");
if (!$stmt) {
    error_log('view_document: prepare failed: ' . $conn->error);
    http_response_code(500);
    exit('Server error');
}
$stmt->bind_param('i', $doc_id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc) {
    http_response_code(404);
    exit('Document not found');
}

/* ── 4. Path containment ──────────────────────────────────────── */
try {
    $abs_path = safe_path(DOCUMENTS_DIR, (string)$doc['file_path']);
} catch (RuntimeException $e) {
    security_log('document.path_escape', [
        'doc_id'    => $doc_id,
        'candidate' => (string)$doc['file_path'],
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    http_response_code(404);
    exit('Document not found');
}

/* ── 5. Decrypt ───────────────────────────────────────────────── */
$plain = doc_read_decrypt($abs_path);
if ($plain === false || $plain === '') {
    error_log("view_document: decrypt failed for doc {$doc_id}");
    http_response_code(500);
    exit('Cannot read document');
}

/* ── 6. Access audit ──────────────────────────────────────────── */
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

$log = $conn->prepare("
    INSERT INTO document_access_log
        (document_id, student_id, admin_id, action, ip_address, user_agent, accessed_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
");
if ($log) {
    $action = $force_download ? 'download' : 'view';
    $log->bind_param('iiisss',
        $doc_id,
        $doc['student_id'],
        $admin_id,
        $action,
        $ip,
        $ua
    );
    $log->execute();
    $log->close();
}

/* ── 7. Stream with hardened headers ──────────────────────────── */
$ext_map = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];

$allowed_mimes = array_keys($ext_map);
$mime = (string)$doc['file_mime'];
if (!in_array($mime, $allowed_mimes, true)) {
    $mime = 'application/octet-stream';
}

$raw_name = $doc['file_name']
    ?: ($doc['document_name'] . '.' . ($ext_map[$mime] ?? 'bin'));

$safe_name = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $raw_name);
$safe_name = str_replace(["\r", "\n", '"'], '', (string)$safe_name);
$safe_name = trim($safe_name);
if ($safe_name === '') {
    $safe_name = 'document.' . ($ext_map[$mime] ?? 'bin');
}

$disposition = $force_download ? 'attachment' : 'inline';

while (ob_get_level()) ob_end_clean();

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($plain));
header("Content-Disposition: {$disposition}; filename=\"{$safe_name}\"");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; object-src 'none'; script-src 'none';");

echo $plain;
exit;