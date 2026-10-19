<?php
declare(strict_types=1);

/**
 * shared/auth/public_stats.php
 * Public, unauthenticated stats endpoint for the login page's live counter.
 * Returns aggregate counts only — no PII, no record-level data.
 *
 * Rate limit : 40 requests per 60s per IP
 * Server cache: 30 seconds (file-based, in sys_get_temp_dir())
 */

require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// ── Rate limit ──────────────────────────────────────────────
$rl_key = 'public_stats_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rl = rate_limit_check($rl_key, 40, 60, $conn);
if ($rl['locked']) {
    http_response_code(429);
    header('Retry-After: ' . max(1, (int)$rl['window_left']));
    echo json_encode(['error' => 'rate_limited']);
    exit;
}
rate_limit_hit($rl_key, 60, $conn);

// ── Server-side cache (30s) ─────────────────────────────────
$cache_file = sys_get_temp_dir() . '/usat_public_stats_v1.json';
$cache_ttl  = 30;

if (is_readable($cache_file)) {
    $raw = @file_get_contents($cache_file);
    if ($raw !== false) {
        $cache = @json_decode($raw, true);
        if (is_array($cache)
            && isset($cache['expires'], $cache['data'])
            && (int)$cache['expires'] > time()) {
            echo json_encode($cache['data']);
            exit;
        }
    }
}

// ── Query ───────────────────────────────────────────────────
try {
    $students = (int)($conn->query("
        SELECT COUNT(*) AS c FROM students_info s
        WHERE NOT EXISTS (
            SELECT 1 FROM enrollment_form e
            WHERE e.student_id = s.student_id
              AND e.status IN ('Graduated', 'Pending')
        )
    ")->fetch_assoc()['c'] ?? 0);

    $pending = (int)($conn->query("
        SELECT COUNT(*) AS c FROM enrollment_form WHERE status = 'Pending'
    ")->fetch_assoc()['c'] ?? 0);

    $sections = (int)($conn->query("
        SELECT COUNT(DISTINCT section) AS c FROM enrollment_form
        WHERE section IS NOT NULL AND section != ''
          AND COALESCE(status, 'Active') NOT IN ('Graduated', 'Pending')
    ")->fetch_assoc()['c'] ?? 0);

    $data = [
        'students' => $students,
        'pending'  => $pending,
        'sections' => $sections,
        'ts'       => time(),
    ];

    @file_put_contents(
        $cache_file,
        json_encode(['expires' => time() + $cache_ttl, 'data' => $data]),
        LOCK_EX
    );

    echo json_encode($data);
} catch (Throwable $e) {
    error_log('public_stats: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal']);
}