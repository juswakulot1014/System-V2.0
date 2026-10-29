<?php
declare(strict_types=1);

/**
 * shared/pages/photo.php — Stream a student's profile photo.
 *
 * Hardening applied:
 *   - Teacher scope: teachers can only view photos of assigned students (404 on denial)
 *   - Path containment via safe_path() (blocks traversal)
 *   - MIME allow-list on response
 *   - CSP + X-Frame-Options + nosniff
 *   - Structured security logging on escape attempts
 *
 * Auth: enforced by config/auth.php (photo.php is not a public script).
 */

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../lib/teacher_scope.php';

/* ── 1. Validate ID ───────────────────────────────────────────── */
$sid = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$sid || $sid < 1) {
    http_response_code(400);
    exit('Bad request');
}

/* ── 1b. Teacher scope gate — 404 (not 403) to avoid existence leak ── */
if (($_SESSION['admin_role'] ?? '') === 'teacher') {
    if (!teacher_has_student($conn, (int)$_SESSION['admin_id'], $sid)) {
        security_log('photo.teacher_scope_denied', [
            'teacher_id' => (int)$_SESSION['admin_id'],
            'student_id' => $sid,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        http_response_code(404);
        exit('No photo');
    }
}

/* ── 2. Fetch photo reference ─────────────────────────────────── */
$stmt = $conn->prepare("SELECT photo FROM students_info WHERE student_id = ? LIMIT 1");
if (!$stmt) {
    error_log('photo.php: prepare failed: ' . $conn->error);
    http_response_code(500);
    exit('Server error');
}
$stmt->bind_param('i', $sid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || empty($row['photo'])) {
    http_response_code(404);
    exit('No photo');
}

/* ── 3. Resolve path with strict containment ──────────────────── */
try {
    $abs = safe_path(STUDENTS_PHOTO_DIR, (string)$row['photo']);
} catch (RuntimeException $e) {
    security_log('photo.path_escape', [
        'student_id' => $sid,
        'candidate'  => (string)$row['photo'],
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    http_response_code(404);
    exit('Photo missing');
}

if (!is_file($abs) || !is_readable($abs)) {
    http_response_code(404);
    exit('Photo missing');
}

/* ── 4. Determine MIME ────────────────────────────────────────── */
$fi = finfo_open(FILEINFO_MIME_TYPE);
$mime = $fi ? (string)finfo_file($fi, $abs) : 'application/octet-stream';
if ($fi) finfo_close($fi);

$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    $mime = 'application/octet-stream';
}

/* ── 5. Stream with hardened headers ──────────────────────────── */
while (ob_get_level()) ob_end_clean();

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox;");

readfile($abs);
exit;