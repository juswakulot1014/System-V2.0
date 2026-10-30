#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * bin/process_email_queue.php
 *
 * CLI worker for grades_email_queue.
 * Runs one-shot (--once) or loops (--loop).
 *
 * IMPORTANT: This file bootstraps a fake "system" session BEFORE
 * loading any project config file. Project files like auth.php
 * exit() when there's no $_SESSION['admin_id'], which would kill
 * the worker silently. Do not move this block below the requires.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

/* ══════════════════════════════════════════════════════════
   FAKE SESSION — must run before any require_once
   ══════════════════════════════════════════════════════════ */
if (!isset($_SESSION)) {
    $_SESSION = [];
}
$_SESSION['admin_id']   = 0;
$_SESSION['admin_role'] = 'system';
$_SESSION['admin_name'] = 'Email Worker';
$_SESSION['login_time'] = time();
$_SESSION['user_agent'] = 'cli-worker/' . PHP_VERSION;
$_SESSION['ip_address'] = '127.0.0.1';
$_SESSION['csrf_token'] = 'cli-worker-token-' . PHP_VERSION;

/* ══════════════════════════════════════════════════════════
   Error reporting — set to display so boot failures aren't silent.
   Change display_errors to 0 once the worker is stable.
   ══════════════════════════════════════════════════════════ */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/* ══════════════════════════════════════════════════════════
   Parse CLI args
   ══════════════════════════════════════════════════════════ */
$opts   = getopt('', ['once', 'job::', 'limit::', 'loop', 'help']);
$once   = isset($opts['once']);
$loop   = isset($opts['loop']) || (!$once);
$job_id = isset($opts['job'])   ? (int)$opts['job']   : 0;
$limit  = isset($opts['limit']) ? max(1, min(100, (int)$opts['limit'])) : 25;

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php process_email_queue.php [--once] [--job=N] [--limit=N] [--loop]\n");
    exit(0);
}

/* ══════════════════════════════════════════════════════════
   Boot project config
   ══════════════════════════════════════════════════════════ */
$root = dirname(__DIR__);

$requires = [
    'config/db.php',
    'config/paths.php',
    'config/mailer.php',
    'shared/lib/grades_email.php',
    'shared/lib/email_queue.php',
];

foreach ($requires as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        fwrite(STDERR, "FATAL: missing {$rel} (expected at {$path})\n");
        exit(1);
    }
    require_once $path;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "FATAL: \$conn not available after config/db.php\n");
    exit(1);
}

/* ══════════════════════════════════════════════════════════
   Single-worker file lock
   ══════════════════════════════════════════════════════════ */
$lock_path = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'usat_email_worker.lock';
$lock_fp   = @fopen($lock_path, 'c');
if (!$lock_fp) {
    fwrite(STDERR, "FATAL: cannot open lock file {$lock_path}\n");
    exit(1);
}
if (!flock($lock_fp, LOCK_EX | LOCK_NB)) {
    if ($once) {
        fwrite(STDOUT, "[worker] another worker holds the lock; exiting\n");
        exit(0);
    }
    flock($lock_fp, LOCK_EX);
}
ftruncate($lock_fp, 0);
fwrite($lock_fp, getmypid() . ' ' . date('c') . "\n");
fflush($lock_fp);

/* ══════════════════════════════════════════════════════════
   Worker identity + logging
   ══════════════════════════════════════════════════════════ */
$hostname  = gethostname() ?: 'host';
$worker_id = substr('w-' . $hostname . '-' . getmypid() . '-' . bin2hex(random_bytes(3)), 0, 64);

function wlog(string $msg): void {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n");
    fflush(STDOUT);
}

/* ══════════════════════════════════════════════════════════
   Process one job
   ══════════════════════════════════════════════════════════ */
function process_one(mysqli $conn, array $row, string $worker_id): void {
    $job_id       = (int)$row['job_id'];
    $attempts     = (int)$row['attempts'];
    $max_attempts = (int)$row['max_attempts'];

    $payload = json_decode((string)$row['payload_json'], true);
    if (!is_array($payload)) {
        eq_mark_failed($conn, $job_id, 'Corrupt payload_json', false);
        wlog("job={$job_id} FAILED corrupt payload");
        return;
    }

    if (empty($payload['student']) || empty($payload['grades']) || empty($payload['term'])) {
        eq_mark_failed($conn, $job_id, 'Incomplete payload', false);
        wlog("job={$job_id} FAILED incomplete payload");
        return;
    }

    $t0 = microtime(true);

    $result = send_grades_email(
        $payload['student'],
        $payload['grades'],
        (string)$payload['term'],
        (string)$payload['sy'],
        (string)($payload['section'] ?? ''),
        (string)($payload['grade_level'] ?? ''),
        (string)($payload['strand'] ?? ''),
        isset($payload['gwa']) ? (float)$payload['gwa'] : null,
        (string)$row['recipient']
    );

    $elapsed_ms = (int)round((microtime(true) - $t0) * 1000);

    if (!empty($result['ok'])) {
        eq_mark_sent($conn, $job_id);
        wlog("job={$job_id} SENT to {$row['recipient']} in {$elapsed_ms}ms (attempt {$attempts}/{$max_attempts})");
    } else {
        $err = trim((string)($result['error'] ?? 'unknown'));
        $can_retry = $attempts < $max_attempts;
        eq_mark_failed($conn, $job_id, $err, $can_retry);
        $state = $can_retry ? 'RETRY' : 'FAILED';
        wlog("job={$job_id} {$state} after {$elapsed_ms}ms (attempt {$attempts}/{$max_attempts}): {$err}");
    }
}

/* ══════════════════════════════════════════════════════════
   Main loop
   ══════════════════════════════════════════════════════════ */
wlog("worker={$worker_id} starting (mode=" . ($loop ? 'loop' : 'once')
   . ", job=" . ($job_id ?: 'any') . ", limit={$limit})");

$processed = 0;
$started   = time();

do {
    if ($job_id > 0) {
        $row = eq_claim_by_id($conn, $job_id, $worker_id);
        if ($row) {
            process_one($conn, $row, $worker_id);
            $processed++;
        } else {
            wlog("job={$job_id} not claimable (already claimed or done) — exiting");
        }
        break;
    }

    $batch = 0;
    while ($batch < $limit) {
        $row = eq_claim_next($conn, $worker_id);
        if (!$row) break;
        process_one($conn, $row, $worker_id);
        $batch++;
        $processed++;
    }

    if (!$loop) break;
    if ($batch === 0) sleep(5);

    if (mt_rand(1, 60) === 1) {
        eq_purge_old($conn, 30);
    }

    if (time() - $started > 3600) {
        wlog("worker exceeded 1h runtime — restarting");
        break;
    }
} while (true);

wlog("worker={$worker_id} done ({$processed} processed)");

@flock($lock_fp, LOCK_UN);
@fclose($lock_fp);
exit(0);