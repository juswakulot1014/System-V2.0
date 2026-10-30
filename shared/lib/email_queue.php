<?php
declare(strict_types=1);

/**
 * shared/lib/email_queue.php
 *
 * Producer + consumer helpers for the grades email queue.
 * Includes inline fallback that works even when exec()/popen() are disabled.
 */

require_once __DIR__ . '/grades_email.php';

/* ══════════════════════════════════════════════════════════════
   Resolve the real PHP CLI binary path.

   Under Apache mod_php (Laragon default), PHP_BINARY points to
   httpd.exe — useless. PHP_BINDIR points to the *directory* that
   contains php.exe — that's what we want.
   ══════════════════════════════════════════════════════════════ */
if (!function_exists('php_cli_path')) {
    function php_cli_path(): string {
        // 1. Explicit override — set PHP_CLI_PATH env var if all else fails
        $env = getenv('PHP_CLI_PATH');
        if ($env && is_file($env)) return $env;

        // 2. PHP_BINDIR (directory). Correct under Apache, FPM, and CLI.
        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
            $bin = rtrim(PHP_BINDIR, '/\\') . DIRECTORY_SEPARATOR
                 . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');
            if (is_file($bin)) return $bin;
        }

        // 3. PHP_BINARY only if it looks like php
        if (defined('PHP_BINARY') && PHP_BINARY !== ''
            && stripos(basename(PHP_BINARY), 'php') === 0) {
            return PHP_BINARY;
        }

        // 4. Fallback to PATH
        return DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php';
    }
}

/* ══════════════════════════════════════════════════════════════
   Flush the current response to the client and keep PHP running.

   After this returns, the browser has the JSON and has disconnected.
   PHP continues for up to `max_execution_time` (default 30s), which
   is enough to run one SMTP send.
   ══════════════════════════════════════════════════════════════ */
if (!function_exists('flush_http_response_to_client')) {
    function flush_http_response_to_client(): void {
        // Must be set BEFORE flushing — otherwise PHP kills the script
        // when the client disconnects.
        ignore_user_abort(true);

        if (function_exists('fastcgi_finish_request')) {
            // PHP-FPM (recommended — Laragon has a toggle for this)
            fastcgi_finish_request();
            return;
        }

        if (function_exists('litespeed_finish_request')) {
            // LiteSpeed
            litespeed_finish_request();
            return;
        }

        // Apache mod_php / CGI fallback
        if (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_flush();
        @flush();
    }
}

/* ── Producer: enqueue a job ──────────────────────────────────── */
if (!function_exists('eq_enqueue')) {
    function eq_enqueue(mysqli $conn, array $job): array {
        $required = ['student_id', 'term', 'school_year', 'recipient', 'subject', 'payload'];
        foreach ($required as $k) {
            if (empty($job[$k])) {
                return ['ok' => false, 'error' => "Missing {$k}", 'job_id' => 0];
            }
        }

        $student_id    = (int)$job['student_id'];
        $enrollment_id = isset($job['enrollment_id']) ? (int)$job['enrollment_id'] : null;
        $term          = (string)$job['term'];
        $sy            = (string)$job['school_year'];
        $recipient     = (string)$job['recipient'];
        $subject       = (string)$job['subject'];
        $payload_json  = json_encode($job['payload'], JSON_UNESCAPED_UNICODE);
        $created_by    = isset($job['created_by']) ? (int)$job['created_by'] : 0;
        $max_attempts  = isset($job['max_attempts']) ? max(1, min(5, (int)$job['max_attempts'])) : 3;

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid recipient email', 'job_id' => 0];
        }
        if ($payload_json === false) {
            return ['ok' => false, 'error' => 'Payload encode failed', 'job_id' => 0];
        }

        $stmt = $conn->prepare("
            INSERT INTO grades_email_queue
                (student_id, enrollment_id, term, school_year, recipient, subject,
                 payload_json, max_attempts, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            return ['ok' => false, 'error' => 'prepare: ' . $conn->error, 'job_id' => 0];
        }
        $stmt->bind_param(
            'iisssssii',
            $student_id, $enrollment_id, $term, $sy, $recipient, $subject,
            $payload_json, $max_attempts, $created_by
        );
        $ok  = $stmt->execute();
        $jid = $ok ? (int)$stmt->insert_id : 0;
        $err = $ok ? null : $stmt->error;
        $stmt->close();

        return $ok
            ? ['ok' => true, 'error' => null, 'job_id' => $jid]
            : ['ok' => false, 'error' => $err ?? 'insert failed', 'job_id' => 0];
    }
}

/* ── Consumer: claim next queued job atomically ────────────────── */
if (!function_exists('eq_claim_next')) {
    function eq_claim_next(mysqli $conn, string $worker_id): ?array {
        $conn->query("
            UPDATE grades_email_queue
               SET status = 'queued', claimed_at = NULL, claimed_by = NULL
             WHERE status = 'processing'
               AND claimed_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");

        $stmt = $conn->prepare("
            UPDATE grades_email_queue
               SET status     = 'processing',
                   claimed_at = NOW(),
                   claimed_by = ?,
                   attempts   = attempts + 1,
                   job_id     = LAST_INSERT_ID(job_id)
             WHERE status = 'queued'
               AND scheduled_at <= NOW()
               AND attempts < max_attempts
             ORDER BY job_id ASC
             LIMIT 1
        ");
        if (!$stmt) return null;
        $stmt->bind_param('s', $worker_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) return null;

        $res = $conn->query("SELECT * FROM grades_email_queue WHERE job_id = LAST_INSERT_ID()");
        if (!$res) return null;
        $row = $res->fetch_assoc();
        $res->free();
        return $row ?: null;
    }
}

/* ── Consumer: claim a specific job by id ─────────────────────── */
if (!function_exists('eq_claim_by_id')) {
    function eq_claim_by_id(mysqli $conn, int $job_id, string $worker_id): ?array {
        $stmt = $conn->prepare("
            UPDATE grades_email_queue
               SET status     = 'processing',
                   claimed_at = NOW(),
                   claimed_by = ?,
                   attempts   = attempts + 1
             WHERE job_id = ?
               AND status = 'queued'
               AND attempts < max_attempts
        ");
        if (!$stmt) return null;
        $stmt->bind_param('si', $worker_id, $job_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) return null;

        $stmt = $conn->prepare("SELECT * FROM grades_email_queue WHERE job_id = ?");
        $stmt->bind_param('i', $job_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

/* ── Consumer: mark result ────────────────────────────────────── */
if (!function_exists('eq_mark_sent')) {
    function eq_mark_sent(mysqli $conn, int $job_id): void {
        $stmt = $conn->prepare("
            UPDATE grades_email_queue
               SET status = 'sent', sent_at = NOW(), last_error = NULL
             WHERE job_id = ?
        ");
        if (!$stmt) return;
        $stmt->bind_param('i', $job_id);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('eq_mark_failed')) {
    function eq_mark_failed(mysqli $conn, int $job_id, string $error, bool $can_retry): void {
        $error = mb_substr($error, 0, 2000);
        if ($can_retry) {
            $stmt = $conn->prepare("
                UPDATE grades_email_queue
                   SET status       = 'queued',
                       claimed_at   = NULL,
                       claimed_by   = NULL,
                       last_error   = ?,
                       scheduled_at = DATE_ADD(NOW(), INTERVAL POW(2, attempts) * 30 SECOND)
                 WHERE job_id = ?
            ");
        } else {
            $stmt = $conn->prepare("
                UPDATE grades_email_queue
                   SET status     = 'failed',
                       last_error = ?
                 WHERE job_id = ?
            ");
        }
        if (!$stmt) return;
        $stmt->bind_param('si', $error, $job_id);
        $stmt->execute();
        $stmt->close();
    }
}

/* ── Poller: read job status ──────────────────────────────────── */
if (!function_exists('eq_get_job')) {
    function eq_get_job(mysqli $conn, int $job_id): ?array {
        $stmt = $conn->prepare("
            SELECT job_id, student_id, term, school_year, recipient, status,
                   attempts, max_attempts, sent_at, last_error, created_at
              FROM grades_email_queue
             WHERE job_id = ?
             LIMIT 1
        ");
        if (!$stmt) return null;
        $stmt->bind_param('i', $job_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

/* ══════════════════════════════════════════════════════════════
   Spawn external worker. Uses the FIXED php path detection.
   ══════════════════════════════════════════════════════════════ */
if (!function_exists('spawn_worker_async')) {
    function spawn_worker_async(int $job_id = 0): void {
        $php    = php_cli_path();
        $worker = realpath(__DIR__ . '/../../bin/process_email_queue.php');

        $debug = defined('WORKER_SPAWN_DEBUG') && WORKER_SPAWN_DEBUG;
        if ($debug) {
            error_log('[spawn_worker] php=' . $php . ' worker=' . ($worker ?: 'NOT_FOUND'));
        }

        if (!$worker || !is_file($worker)) {
            if ($debug) error_log('[spawn_worker] worker file missing');
            return;
        }

        $arg = $job_id > 0 ? '--once --job=' . $job_id : '--once';

        if (DIRECTORY_SEPARATOR === '\\') {
            if (!function_exists('popen')) {
                if ($debug) error_log('[spawn_worker] popen disabled');
                return;
            }
            $cmd = 'start /B "" '
                 . escapeshellarg($php) . ' '
                 . escapeshellarg($worker) . ' ' . $arg
                 . ' > NUL 2>&1';
            if ($debug) error_log('[spawn_worker] cmd=' . $cmd);
            @pclose(@popen($cmd, 'r'));
        } else {
            if (!function_exists('exec')) {
                if ($debug) error_log('[spawn_worker] exec disabled');
                return;
            }
            $cmd = escapeshellarg($php) . ' '
                 . escapeshellarg($worker) . ' ' . $arg
                 . ' > /dev/null 2>&1 &';
            if ($debug) error_log('[spawn_worker] cmd=' . $cmd);
            @exec($cmd);
        }
    }
}

/* ══════════════════════════════════════════════════════════════
   Process a specific job RIGHT NOW in the current PHP process.
   Used as fallback when spawn fails (Apache mod_php on Windows).
   ══════════════════════════════════════════════════════════════ */
if (!function_exists('eq_process_now')) {
    function eq_process_now(mysqli $conn, int $job_id): bool {
        $claim = eq_claim_by_id($conn, $job_id, 'inline-' . getmypid());
        if (!$claim) {
            return false; // someone else got it first
        }

        $payload = json_decode((string)$claim['payload_json'], true);
        if (!is_array($payload)
            || empty($payload['student'])
            || empty($payload['grades'])) {
            eq_mark_failed($conn, $job_id, 'Corrupt payload', false);
            return false;
        }

        $result = send_grades_email(
            $payload['student'],
            $payload['grades'],
            (string)($payload['term'] ?? ''),
            (string)($payload['sy'] ?? ''),
            (string)($payload['section'] ?? ''),
            (string)($payload['grade_level'] ?? ''),
            (string)($payload['strand'] ?? ''),
            isset($payload['gwa']) ? (float)$payload['gwa'] : null,
            (string)$claim['recipient']
        );

        if (!empty($result['ok'])) {
            eq_mark_sent($conn, $job_id);
            return true;
        }

        $err = trim((string)($result['error'] ?? 'unknown'));
        eq_mark_failed($conn, $job_id, $err, false);
        return false;
    }
}

/* ── Purge old jobs ──────────────────────────────────────────── */
if (!function_exists('eq_purge_old')) {
    function eq_purge_old(mysqli $conn, int $days = 30): void {
        $days = max(1, $days);
        $conn->query("
            DELETE FROM grades_email_queue
             WHERE status IN ('sent','failed')
               AND created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)
        ");
    }
}