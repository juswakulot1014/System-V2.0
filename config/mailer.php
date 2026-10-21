<?php
// config/mailer.php — SMTP wrapper using PHPMailer.
//
// Fixes the intermittent Gmail delivery problem:
//   - Validates config and fails fast with a clear reason
//   - Normalizes MAIL_ENCRYPTION (accepts 'starttls', 'STARTTLS', 'ssl', etc.)
//   - Auto-corrects port <-> encryption mismatches (465->ssl, 587->tls)
//   - Retries transient SMTP failures (4xx codes, timeouts, dropped sockets)
//   - Adds headers that help with Gmail's spam heuristics
//   - Returns attempt count + transient flag so callers can surface real errors
//   - Optional SMTP transcript via MAIL_SMTP_DEBUG=2 for deep debugging
//
// Never throws; callers decide what to do with failure.

declare(strict_types=1);

$__autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($__autoload)) {
    require_once $__autoload;
}

/**
 * Normalize MAIL_ENCRYPTION to what PHPMailer actually accepts:
 * 'tls', 'ssl', or '' (plaintext — not recommended).
 */
function mailer_encryption(): string
{
    $v = strtolower(trim((string)getenv('MAIL_ENCRYPTION')));
    return match ($v) {
        '', 'none', 'null', 'false', '0' => '',
        'tls', 'starttls', 'start_tls'   => 'tls',
        'ssl', 'smtps', 'smtp+tls'       => 'ssl',
        default                          => $v,
    };
}

/**
 * Redacted config snapshot — safe for logs / the debug page.
 */
function mailer_config_snapshot(): array
{
    $pw = (string)getenv('MAIL_PASSWORD');
    return [
        'host'             => (string)(getenv('MAIL_HOST') ?: 'smtp.gmail.com'),
        'port'             => (int)(getenv('MAIL_PORT') ?: 587),
        'encryption_raw'   => (string)getenv('MAIL_ENCRYPTION'),
        'encryption_used'  => mailer_encryption(),
        'username'         => (string)getenv('MAIL_USERNAME'),
        'from_address'     => (string)(getenv('MAIL_FROM_ADDRESS') ?: getenv('MAIL_USERNAME')),
        'from_name'        => (string)(getenv('MAIL_FROM_NAME')    ?: 'USAT College Sagay City Inc.'),
        'password_length'  => strlen($pw),
        'password_is_app'  => strlen($pw) === 16 && preg_match('/^[a-z]{16}$/i', $pw) === 1,
    ];
}

/**
 * Build a fully configured PHPMailer instance.
 *
 * @return array{0:?\PHPMailer\PHPMailer\PHPMailer, 1:?string}  [mailer, error]
 */
function mailer_build(): array
{
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return [null, 'PHPMailer not installed — run: composer require phpmailer/phpmailer'];
    }

    $username = (string)getenv('MAIL_USERNAME');
    $password = (string)getenv('MAIL_PASSWORD');

    if ($username === '' || $password === '') {
        return [null, 'MAIL_USERNAME or MAIL_PASSWORD is not set'];
    }

    $host = (string)(getenv('MAIL_HOST') ?: 'smtp.gmail.com');
    $port = (int)(getenv('MAIL_PORT') ?: 587);
    $enc  = mailer_encryption();

    // Auto-correct the most common port <-> encryption mismatches.
    if ($port === 465 && $enc !== 'ssl') {
        $enc = 'ssl';
    } elseif ($port === 587 && $enc === 'ssl') {
        $enc = 'tls';
    }

    $fromAddr = (string)(getenv('MAIL_FROM_ADDRESS') ?: $username);
    $fromName = (string)(getenv('MAIL_FROM_NAME')    ?: 'USAT College Sagay City Inc.');

    // Gmail rewrites or spam-files mail where From != authenticated account
    // unless From is a verified "Send mail as" alias.
    if (stripos($host, 'gmail') !== false && strcasecmp($fromAddr, $username) !== 0) {
        error_log(sprintf(
            'mailer: WARNING From (%s) != SMTP username (%s). Gmail will rewrite or spam-file this.',
            $fromAddr, $username
        ));
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host          = $host;
        $mail->SMTPAuth      = true;
        $mail->Username      = $username;
        $mail->Password      = $password;
        $mail->SMTPSecure    = $enc;
        $mail->Port          = $port;
        $mail->CharSet       = 'UTF-8';
        $mail->Encoding      = 'base64';
        $mail->XMailer       = 'USAT Enrollment System';
        $mail->Timeout       = 30;
        $mail->SMTPKeepAlive = false;
        $mail->SMTPAutoTLS   = true;

        $dbg = (int)getenv('MAIL_SMTP_DEBUG');
        if ($dbg > 0) {
            $mail->SMTPDebug   = $dbg;
            $mail->Debugoutput = static function ($str, $level) {
                error_log("phpmailer[$level]: " . rtrim((string)$str));
            };
        }

        $mail->setFrom($fromAddr, $fromName);
        $mail->addReplyTo($fromAddr, $fromName);

        // Headers that help with Gmail's bulk-mail heuristics.
        $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
        $mail->addCustomHeader('X-Auto-Response-Suppress', 'OOF, AutoReply');
        // Precedence: bulk REMOVED (causes transactional emails to be spam filtered)

        return [$mail, null];
    } catch (\Throwable $e) {
        return [null, $e->getMessage()];
    }
}

/**
 * Detect whether an SMTP error is worth retrying.
 *
 * Retryable: 4xx reply codes, timeouts, connection drops.
 * Not retryable: 5xx (auth failures, invalid recipient, blocked sender).
 */
function mailer_is_transient(string $err): bool
{
    // Match an SMTP reply code at a word boundary. SMTP codes are always
    // 3 digits; we look at the FIRST one in the string, since PHPMailer
    // puts the primary code first ("421 4.7.0 Try again later").
    if (preg_match('/(?:^|\s)(4\d{2})(?:\s|$)/', $err, $m)) {
        return true;
    }

    $needles = [
        'timed out', 'timeout', 'connection reset', 'broken pipe',
        'smtp connect() failed', 'could not connect', 'connection refused',
        'stream_socket_client', 'unable to connect',
    ];
    foreach ($needles as $n) {
        if (stripos($err, $n) !== false) return true;
    }
    return false;
}

/**
 * Send an HTML email. Retries transient failures automatically.
 *
 * @return array{
 *   ok:bool,
 *   error:?string,
 *   transport:string,
 *   attempts:int,
 *   transient:bool
 * }
 */
function send_mail(string $to, string $subject, string $htmlBody, string $altBody = ''): array
{
    $out = [
        'ok'        => false,
        'error'     => null,
        'transport' => 'phpmailer',
        'attempts'  => 0,
        'transient' => false,
    ];

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $out['error']     = 'Invalid recipient address';
        $out['transport'] = 'none';
        return $out;
    }

    $altBody = $altBody !== '' ? $altBody : trim(strip_tags($htmlBody));

    // Backoff schedule. First attempt is immediate, then wait 2s, then 5s.
    $backoffMs   = [0, 2000, 5000];
    $maxAttempts = count($backoffMs);

    for ($i = 0; $i < $maxAttempts; $i++) {
        if ($backoffMs[$i] > 0) {
            usleep($backoffMs[$i] * 1000);
        }
        $out['attempts'] = $i + 1;

        // Fresh mailer per attempt — a broken SMTP socket cannot be
        // safely reused after an error.
        [$mail, $buildErr] = mailer_build();
        if ($mail === null) {
            $out['error']     = $buildErr;
            $out['transport'] = 'none';
            return $out;
        }

        try {
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $altBody;
            $mail->send();

            $out['ok']        = true;
            $out['error']     = null;
            $out['transient'] = false;
            return $out;

        } catch (\Throwable $e) {
            $err = $mail->ErrorInfo ?: $e->getMessage();
            $out['error']     = $err;
            $out['transient'] = mailer_is_transient($err);

            if (!$out['transient']) {
                error_log("mailer: hard failure to {$to}: {$err}");
                return $out;
            }

            error_log(sprintf(
                'mailer: transient failure (attempt %d/%d) to %s: %s',
                $out['attempts'], $maxAttempts, $to, $err
            ));
        }
    }

    return $out;
}