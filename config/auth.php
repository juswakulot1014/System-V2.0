<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/* ── Public scripts (skip auth) — must be at the namespace root ── */
$__public_scripts = [
    'admin_login.php',
    'logout.php',
];

$__current_script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$__current_dir    = basename(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));

$__is_public = in_array($__current_script, $__public_scripts, true)
            && in_array($__current_dir, ['admin', 'registrar'], true);

if (!$__is_public) {
    $__required_role = $REQUIRE_ROLE ?? null;

    if (!is_string($__required_role) && !is_array($__required_role)) {
        $__required_role = null;
    }
    if (is_string($__required_role) && $__required_role === '') {
        $__required_role = null;
    }
    if (is_array($__required_role) && empty($__required_role)) {
        $__required_role = null;
    }

    require_role($__required_role);
}

// Always initialise CSRF token
csrf_token();

/* ───────────────────────────────────────────────────────────────
   get_pending_count() — 30-second session-cached pending counter.
   Called by shared/partials/sidebar.php for the badge.
   ─────────────────────────────────────────────────────────────── */
if (!function_exists('get_pending_count')) {
    function get_pending_count(mysqli $conn): int
    {
        if (isset($_SESSION['__pending_count'], $_SESSION['__pending_count_at'])
            && (time() - (int)$_SESSION['__pending_count_at']) < 30) {
            return (int)$_SESSION['__pending_count'];
        }
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM enrollment_form WHERE status = ?");
        if (!$stmt) return 0;
        $pending = 'Pending';
        $stmt->bind_param('s', $pending);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        $_SESSION['__pending_count']    = $n;
        $_SESSION['__pending_count_at'] = time();
        return $n;
    }
}