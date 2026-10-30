<?php
declare(strict_types=1);

/**
 * process_enroll.php — Finalize a public enrollment submission.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/crypto.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/file_verify.php';
require_once __DIR__ . '/../config/document_content_verify.php';

/* ── Method check ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: enroll_form.php');
    exit();
}

/* ── Rate limit ───────────────────────────────────────────────── */
$rl = rate_limit_session(
    'process_enroll_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
    50,
    60
);
if (!$rl['allowed']) {
    security_log('process_enroll.ratelimited', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    $_SESSION['error'] = 'Too many submissions. Please wait a minute and try again.';
    header('Location: enroll_form.php');
    exit();
}

/* ── CSRF ─────────────────────────────────────────────────────── */
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
    error_log(sprintf(
        '[process_enroll] CSRF mismatch ip=%s posted_set=%s posted_len=%d session_set=%s session_len=%d cookie_set=%s',
        $_SERVER['REMOTE_ADDR'] ?? '?',
        isset($_POST['csrf_token']) ? 'y' : 'n',
        strlen((string)($_POST['csrf_token'] ?? '')),
        isset($_SESSION['csrf_token']) ? 'y' : 'n',
        strlen((string)($_SESSION['csrf_token'] ?? '')),
        isset($_COOKIE[session_name()]) ? 'y' : 'n'
    ));

    if (!isset($_SESSION['form_data']) && !empty($_POST)) {
        $restore = $_POST;
        unset($restore['csrf_token']);
        $_SESSION['form_data'] = $restore;
    }

    security_log('process_enroll.csrf_fail', [
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
        'has_cookie'  => isset($_COOKIE[session_name()]),
        'posted_set'  => !empty($_POST['csrf_token']),
        'session_set' => !empty($_SESSION['csrf_token']),
        'same_host'   => ($_SERVER['HTTP_HOST'] ?? '') === ($_SERVER['SERVER_NAME'] ?? ''),
    ]);

    $_SESSION['error'] = 'Your session expired. Please review and submit again.';
    header('Location: enroll_form.php?edit=1');
    exit();
}

/**
 * Auto-detect types and bind params safely.
 */
function bind_params(mysqli_stmt $stmt, array $values): void
{
    $expected = mysqli_stmt_param_count($stmt);
    $got      = count($values);
    if ($expected !== $got) {
        throw new InvalidArgumentException(sprintf(
            'bind_params: statement expects %d placeholders, got %d values',
            $expected,
            $got
        ));
    }

    $types = '';
    foreach ($values as $v) {
        $types .= match (true) {
            is_int($v)   => 'i',
            is_float($v) => 'd',
            default      => 's',
        };
    }

    // FIX: Pass by reference to prevent "Cannot pass parameter by reference" fatal errors
    $refs = [];
    foreach ($values as $i => $_) {
        $refs[$i] = &$values[$i];
    }
    
    $stmt->bind_param($types, ...$refs);
}

/**
 * Assign a provisional section for a new enrollee.
 */
function assignProvisionalSection(
    mysqli $conn,
    string $strand,
    string $grade_level,
    string $school_year,
    int $target_size = 35
): string {
    $strand = trim($strand);
    if ($strand === '') $strand = 'General';

    $stmt = $conn->prepare("
        SELECT section, COUNT(*) AS n
        FROM enrollment_form
        WHERE strand = ?
          AND grade_level = ?
          AND school_year = ?
          AND section IS NOT NULL
          AND section != ''
        GROUP BY section
        ORDER BY n DESC, section ASC
    ");
    if (!$stmt) return $strand . ' A';

    $stmt->bind_param("sss", $strand, $grade_level, $school_year);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($rows)) return $strand . ' A';

    foreach ($rows as $r) {
        if ((int)$r['n'] < $target_size) return $r['section'];
    }

    $used_letters = [];
    foreach ($rows as $r) {
        if (preg_match('/\s+([A-Z])\s*$/i', $r['section'], $m)) {
            $used_letters[strtoupper($m[1])] = true;
        }
    }

    for ($i = 0; $i < 26; $i++) {
        $letter = chr(65 + $i);
        if (!isset($used_letters[$letter])) return $strand . ' ' . $letter;
    }

    return $strand . ' Z';
}

/**
 * Turn a raw exception message into [user_message, action_hint, step_number].
 */
function classify_enrollment_error(string $msg, string $corr): array
{
    $m = strtolower($msg);

    if (str_contains($m, 'data truncated')) {
        return [
            'The enrollment form could not save because of a data format issue.',
            'Please try again in a moment. If it keeps failing, contact the registrar with reference: ' . $corr,
            5,
        ];
    }

    if (preg_match('/\[([a-z_]+):([a-z_]+)\]/i', $msg, $tag)) {
        $table = strtolower($tag[1]);

        if (str_contains($m, 'unknown column') || str_contains($m, "doesn't exist")) {
            return [
                'The enrollment form is temporarily unable to save your information.',
                'This is a configuration issue on our side. Please contact the registrar and quote reference: ' . $corr,
                5,
            ];
        }
        if (str_contains($m, 'duplicate entry')) {
            return [
                'This record has already been submitted.',
                'It looks like your enrollment was already saved. Please refresh the page or contact the registrar with reference: ' . $corr,
                5,
            ];
        }
        if ($table === 'file') {
            return [
                'One of your uploaded documents could not be saved.',
                'Please re-upload the affected document and submit again. If it keeps failing, contact the registrar with reference: ' . $corr,
                5,
            ];
        }
        return [
            'A system error occurred while saving your enrollment (' . $table . ').',
            'Please try again in a moment. If it persists, contact the registrar with reference: ' . $corr,
            5,
        ];
    }

    if (str_contains($m, 'file verification failed')) {
        return ['One of your uploaded documents was rejected by the file checker.',
                'Please make sure the file is a valid PDF, JPG, or PNG under the size limit, then re-upload it.', 5];
    }
    if (str_contains($m, 'staged file missing')) {
        return ['One of your uploaded documents could not be found.',
                'Please re-upload the affected document and submit again.', 5];
    }
    if (str_contains($m, 'empty file')) {
        return ['One of your uploaded documents is empty or unreadable.',
                'Please re-upload a valid copy of the document.', 5];
    }
    if (str_contains($m, 'too many files')) {
        return ['You tried to upload too many documents.',
                'Please keep the total number of uploads to 10 or fewer.', 5];
    }
    if (str_contains($m, 'cannot write encrypted file') || str_contains($m, 'storage write failed')) {
        return ['The system could not save your uploaded documents.',
                'Please try again in a moment. If it keeps failing, contact the registrar with reference: ' . $corr, 5];
    }
    if (str_contains($m, 'upload at least one')) {
        return ['You did not upload any entrance documents.',
                'Please tick at least one document checkbox and upload the file for it.', 5];
    }
    if (str_contains($m, 'unsupported file type') || str_contains($m, 'not valid for')) {
        return ['One of your files is the wrong type for that document slot.',
                'Photos accept only JPG/PNG. Certificates accept PDF, JPG, or PNG.', 5];
    }
    if (str_contains($m, 'document verification failed') || str_contains($m, 'jhs')) {
        return ['Your JHS Certificate could not be verified.',
                'Please re-upload a clearer copy of the Junior High School Certificate (Front).', 5];
    }
    if (str_contains($m, 'birth date')) {
        return ['Your birth date is missing or invalid.',
                'Please return to Step 1 and enter a valid birth date.', 1];
    }
    if (str_contains($m, 'age must be')) {
        return ['Your age is outside the allowed range (14–25).',
                'Check your birth date — the age field is calculated from it automatically.', 1];
    }
    if (str_contains($m, 'student id') && str_contains($m, 'unique')) {
        return ['The system could not assign a unique Student ID.',
                'Please try submitting again. If it keeps failing, contact the registrar with reference: ' . $corr, 1];
    }
    if (str_contains($m, 'lrn already exists') || str_contains($m, 'lrn registered')) {
        return ['This LRN has already been registered.',
                'If you believe this is an error, contact the registrar with reference: ' . $corr, 1];
    }
    if (str_contains($m, 'prepare failed') || str_contains($m, 'execute failed') || str_contains($m, 'column')) {
        return ['A system error occurred while saving your enrollment.',
                'Please try submitting again in a moment. If the problem persists, contact the registrar with reference: ' . $corr, 1];
    }

    return ['We could not complete your enrollment.',
            'Please review your entries carefully and submit again. If it keeps failing, contact the registrar with reference: ' . $corr, 5];
}

/* ══════════════════════════════════════════════════════════════════
   VERIFICATION MAPPING HELPERS
   ══════════════════════════════════════════════════════════════════ */

function build_document_content_verdict(string $doc_key): ?array
{
    if ($doc_key === 'jhs_certificate_front') {
        return build_jhs_front_verdict();
    }
    if ($doc_key === 'pictures_2x2') {
        return build_photo_2x2_verdict();
    }
    return null;
}

function build_jhs_front_verdict(): ?array
{
    $nv = $_SESSION['name_verify'] ?? null;
    if (!is_array($nv) || empty($nv)) return null;

    $name_parts = $nv['name']['parts'] ?? $nv['parts'] ?? [];

    $map = [
        'match'        => 'verified',
        'partial'      => 'needs_review',
        'unverifiable' => 'needs_review',
        'mismatch'     => 'failed',
    ];
    $internal  = $nv['status'] ?? 'unverifiable';
    $db_status = $map[$internal] ?? 'needs_review';

    $matched_payload = [
        'name_parts' => [
            'first'  => $name_parts['first']  ?? null,
            'middle' => $name_parts['middle'] ?? null,
            'last'   => $name_parts['last']   ?? null,
        ],
        'lrn' => [
            'expected' => $nv['lrn']['expected'] ?? null,
            'found'    => $nv['lrn']['best']     ?? null,
            'distance' => $nv['lrn']['distance'] ?? null,
        ],
        'ocr_rotation'   => (int)($nv['rotation'] ?? 0),
        'ocr_psm'        => (int)($nv['psm']      ?? 6),
        'ocr_score'      => (int)($nv['score']    ?? 0),
        'detection_path' => $nv['source']         ?? null,
    ];

    $notes = [];
    if (!empty($nv['message']))          $notes[] = $nv['message'];
    if (!empty($nv['name']['message']))  $notes[] = 'Name: ' . $nv['name']['message'];
    if (!empty($nv['lrn']['message']))   $notes[] = 'LRN: '  . $nv['lrn']['message'];

    return [
        'status'       => $db_status,
        'score'        => (int)($nv['score'] ?? 0),
        'engine'       => $nv['source'] ?? null,
        'text_excerpt' => mb_substr((string)($nv['extracted'] ?? ''), 0, 4000),
        'matched'      => $matched_payload,
        'notes'        => $notes,
    ];
}

function build_photo_2x2_verdict(): ?array
{
    $p2 = $_SESSION['photo_2x2_check'] ?? null;
    if (!is_array($p2) || empty($p2)) return null;

    $map = [
        'match'        => 'verified',
        'partial'      => 'needs_review',
        'unverifiable' => 'needs_review',
        'mismatch'     => 'failed',
    ];
    $db_status = $map[$p2['status'] ?? 'unverifiable'] ?? 'needs_review';

    return [
        'status'       => $db_status,
        'score'        => 0,
        'engine'       => 'format_check',
        'text_excerpt' => $p2['message'] ?? '',
        'matched'      => [
            'check'   => 'aspect_ratio_and_dimensions',
            'result'  => $p2['status'] ?? 'unknown',
            'message' => $p2['message'] ?? '',
        ],
        'notes'        => [$p2['message'] ?? ''],
    ];
}

function store_encrypted_file(
    string $staging_path,
    int $student_id,
    string $doc_label = '',
    ?array $precomputed_content = null
): array {
    if (!is_file($staging_path)) {
        throw new Exception('[file:missing] Staged file missing.');
    }

    $verify = verify_document_file($staging_path, $doc_label);
    if (!is_array($verify) || empty($verify['ok'])) {
        $reason = is_array($verify) ? ($verify['error'] ?? 'unknown') : 'checker returned non-array';
        throw new Exception('[file:verify] File verification failed: ' . $reason);
    }

    if ($precomputed_content !== null) {
        $content = $precomputed_content;
    } elseif (function_exists('verify_document_content')) {
        $content = verify_document_content($staging_path, $doc_label);
    } else {
        $content = ['status'=>'skipped','score'=>0,'engine'=>null,'matched'=>[],'notes'=>['No content verification available.']];
    }

    // Normalize to known set: verified / needs_review / failed / skipped
    $allowed_content_status = ['verified', 'needs_review', 'failed', 'skipped'];
    $content_status = $content['status'] ?? 'skipped';
    if (!in_array($content_status, $allowed_content_status, true)) {
        error_log(sprintf(
            'store_encrypted_file: unknown content status "%s" for "%s" — defaulting to needs_review',
            $content_status,
            $doc_label
        ));
        $content_status = 'needs_review';
    }

    if ($content_status === 'failed') {
        error_log(sprintf(
            'store_encrypted_file: content mismatch for "%s" (score=%d) — downgraded to needs_review.',
            $doc_label,
            (int)($content['score'] ?? 0)
        ));
        $content_status = 'needs_review';
        $content['notes'] = array_merge(
            (array)($content['notes'] ?? []),
            ['Auto-flagged by system: content did not match expected keywords.']
        );
    }

    $content['status'] = $content_status;

    $plain = file_get_contents($staging_path);
    if ($plain === false || $plain === '') {
        throw new Exception('[file:read] Empty file after verification.');
    }

    $blob = doc_encrypt($plain);

    try {
        $token = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        $token = bin2hex(openssl_random_pseudo_bytes(16));
    }

    $ext_map = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];
    $ext = $ext_map[$verify['mime']] ?? 'bin';

    $filename = "enroll_{$student_id}_{$token}.{$ext}.enc";
    $abs_path = DOCUMENTS_DIR . $filename;

    if (file_put_contents($abs_path, $blob, LOCK_EX) === false) {
        throw new Exception('[file:write] Cannot write encrypted file to ' . $abs_path);
    }
    @chmod($abs_path, 0600);

    // NOTE: staged file is unlinked by the caller AFTER commit succeeds.
    // This lets the student re-submit on failure without re-uploading.

    return [
        'filename' => $filename,
        'mime'     => $verify['mime'],
        'size'     => strlen($plain),
        'hash'     => $verify['hash'],
        'width'    => $verify['width']  ?? 0,
        'height'   => $verify['height'] ?? 0,
        'notes'    => array_merge($verify['notes'] ?? [], $content['notes'] ?? []),
        'content'  => $content,
    ];
}

/* ══════════════════════════════════════════════════════════════════
   MAIN FLOW
   ══════════════════════════════════════════════════════════════════ */

$written_files = [];
$staged_paths_to_cleanup = [];

// ── Server-side age recompute ──
if (empty($_POST['birth_date'])) {
    $_SESSION['error'] = 'Birth date is required.';
    header('Location: enroll_form.php');
    exit();
}
$birth = DateTime::createFromFormat('Y-m-d', (string)$_POST['birth_date']);
if (!$birth) {
    $_SESSION['error'] = 'Invalid birth date.';
    header('Location: enroll_form.php');
    exit();
}
$real_age = (new DateTime())->diff($birth)->y;
if ($real_age < 14 || $real_age > 25) {
    $_SESSION['error'] = 'Age must be between 14 and 25.';
    header('Location: enroll_form.php');
    exit();
}
$_POST['age'] = $real_age;

// ── Require at least one uploaded document ──
$staged_files = [];
if (!empty($_SESSION['uploaded_files']) && is_array($_SESSION['uploaded_files'])) {
    foreach ($_SESSION['uploaded_files'] as $key => $f) {
        $path = $f['staging_path'] ?? '';
        if ($path !== '' && is_file($path)) {
            $staged_files[$key] = $f;
        }
    }
    $_SESSION['uploaded_files'] = $staged_files;
}

if (empty($staged_files)) {
    $_SESSION['error'] = 'Please upload at least one required document before submitting the enrollment form.';
    header('Location: enroll_form.php');
    exit();
}

// ── Serialize concurrent enrollments ──
$got_lock = false;
$lock_res = $conn->query("SELECT GET_LOCK('enroll_global', 10) AS ok");
if ($lock_res) {
    $lock_row = $lock_res->fetch_assoc();
    $got_lock = ((int)($lock_row['ok'] ?? 0) === 1);
    $lock_res->free();
}
if (!$got_lock) {
    security_log('process_enroll.lock_timeout', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    $_SESSION['error'] = 'System busy, please try again in a moment.';
    header('Location: enroll_form.php');
    exit();
}

$conn->begin_transaction();

try {
    /* ── 0. LRN uniqueness re-check INSIDE the transaction ── */
    $lrn_final = clean_string($_POST['lrn'] ?? '', 12);
    if ($lrn_final !== '') {
        $dup = $conn->prepare("SELECT student_id, first_name, last_name FROM students_info WHERE lrn = ? LIMIT 1");
        if (!$dup) throw new Exception('[lrn:prepare] ' . $conn->error);
        $dup->bind_param('s', $lrn_final);
        $dup->execute();
        $dup_row = $dup->get_result()->fetch_assoc();
        $dup->close();
        if ($dup_row) {
            throw new Exception('[lrn:duplicate] LRN already exists.');
        }
    }

    /* ── 1. students_info ── */
    $stmt = $conn->prepare("INSERT INTO students_info 
        (lrn, first_name, last_name, middle_name, nick_name, ext_name, sex, birth_date, age, 
         civil_status, nationality, religion, height, weight, email, phone, special_skills, photo) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) throw new Exception('[students_info:prepare] ' . $conn->error);

    $lrn            = clean_string($_POST['lrn'] ?? '', 12);
    $first_name     = clean_string($_POST['first_name'] ?? '', 100);
    $last_name      = clean_string($_POST['last_name'] ?? '', 100);
    $middle_name    = clean_string($_POST['middle_name'] ?? '', 100) ?: null;
    $nick_name      = clean_string($_POST['nick_name'] ?? '', 50) ?: null;
    $ext_name       = clean_string($_POST['ext_name'] ?? '', 20) ?: null;
    $sex            = clean_string($_POST['sex'] ?? '', 10);
    $birth_date     = clean_string($_POST['birth_date'] ?? '', 10);
    $age            = (int)($_POST['age'] ?? 0);
    $civil_status   = clean_string($_POST['civil_status'] ?? '', 30) ?: null;
    $nationality    = clean_string($_POST['nationality'] ?? '', 50) ?: null;
    $religion       = clean_string($_POST['religion'] ?? '', 100) ?: null;
    $height         = ($_POST['height'] ?? '') !== '' ? (float)$_POST['height'] : null;
    $weight         = ($_POST['weight'] ?? '') !== '' ? (float)$_POST['weight'] : null;
    $email          = clean_string($_POST['email'] ?? '', 150) ?: null;
    $phone          = clean_string($_POST['phone'] ?? '', 15) ?: null;
    $special_skills = clean_string($_POST['special_skills'] ?? '', 500) ?: null;
    $photo          = null;

    bind_params($stmt, [
        $lrn, $first_name, $last_name, $middle_name, $nick_name, $ext_name,
        $sex, $birth_date, $age, $civil_status, $nationality, $religion,
        $height, $weight, $email, $phone, $special_skills, $photo,
    ]);

    if (!$stmt->execute()) throw new Exception('[students_info:execute] ' . $stmt->error);

    $student_info_id = $conn->insert_id;
    $stmt->close();

    /* ── 2. Student ID number (Sequential Generation) ── */
    $school_year = clean_string($_POST['school_year'] ?? '', 9);
    $sy_parts = explode('-', $school_year);
    $year_enrolled = (int)($sy_parts[0] ?? 0);

    if ($year_enrolled < 2000 || $year_enrolled > 2100) {
        $year_enrolled = (int)date('Y');
    }

    $prefix = "UCSCI-{$year_enrolled}-";
    
    // Get the highest existing ID for this year
    $seq_stmt = $conn->prepare("SELECT student_id_number FROM students_info WHERE student_id_number LIKE ? ORDER BY student_id_number DESC LIMIT 1");
    if (!$seq_stmt) throw new Exception('[student_id_number:prepare] ' . $conn->error);
    
    $like_prefix = $prefix . '%';
    $seq_stmt->bind_param("s", $like_prefix);
    $seq_stmt->execute();
    $res = $seq_stmt->get_result()->fetch_assoc();
    $seq_stmt->close();

    $next_seq = 1;
    if ($res && !empty($res['student_id_number'])) {
        $parts = explode('-', $res['student_id_number']);
        $last_seq = (int)end($parts);
        $next_seq = $last_seq + 1;
    }

    $student_id_number = $prefix . str_pad((string)$next_seq, 6, '0', STR_PAD_LEFT);

    // Safety check for collisions (fallback)
    $attempts = 0;
    $collision = true;
    while ($collision && $attempts < 10) {
        $check = $conn->prepare("SELECT 1 FROM students_info WHERE student_id_number = ? LIMIT 1");
        if (!$check) throw new Exception('[student_id_number:prepare] ' . $conn->error);
        $check->bind_param("s", $student_id_number);
        $check->execute();
        $collision = $check->get_result()->num_rows > 0;
        $check->close();

        if ($collision) {
            $next_seq++;
            $student_id_number = $prefix . str_pad((string)$next_seq, 6, '0', STR_PAD_LEFT);
            $attempts++;
        }
    }

    if ($collision) {
        throw new Exception('[student_id_number:generate] Could not generate a unique Student ID after 10 attempts.');
    }

    $update_stmt = $conn->prepare("UPDATE students_info SET student_id_number = ? WHERE student_id = ?");
    if (!$update_stmt) throw new Exception('[student_id_number:update_prepare] ' . $conn->error);

    bind_params($update_stmt, [$student_id_number, $student_info_id]);

    if (!$update_stmt->execute()) {
        throw new Exception('[student_id_number:update_execute] ' . $update_stmt->error);
    }
    $update_stmt->close();

    /* ── 3. addresses ── */
    $stmt = $conn->prepare("INSERT INTO addresses 
        (student_id, purok_street, barangay, town_city, province, region, district, postal_code) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) throw new Exception('[addresses:prepare] ' . $conn->error);

    $purok_street = clean_string($_POST['purok_street'] ?? '', 200) ?: null;
    $barangay     = clean_string($_POST['barangay'] ?? '', 100)     ?: null;
    $town_city    = clean_string($_POST['town_city'] ?? '', 100)    ?: null;
    $province     = clean_string($_POST['province'] ?? '', 100)     ?: null;
    $region       = clean_string($_POST['region'] ?? '', 100)       ?: null;
    $district     = clean_string($_POST['district'] ?? '', 100)     ?: null;
    $postal_code  = clean_string($_POST['postal_code'] ?? '', 10)   ?: null;

    bind_params($stmt, [
        $student_info_id, $purok_street, $barangay, $town_city,
        $province, $region, $district, $postal_code,
    ]);

    if (!$stmt->execute()) throw new Exception('[addresses:execute] ' . $stmt->error);
    $stmt->close();

    /* ── 4. parents_info ── */
    $stmt = $conn->prepare("INSERT INTO parents_info 
        (student_id, father_name, father_occupation, father_contact,
         mother_maiden_name, mother_occupation, mother_contact, ave_family_income, 
         is_4ps, guardian_fullname, guardian_relation, guardian_contact, household_id) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) throw new Exception('[parents_info:prepare] ' . $conn->error);

    $father_name         = clean_string($_POST['father_name'] ?? '', 150)         ?: null;
    $father_occupation   = clean_string($_POST['father_occupation'] ?? '', 100)   ?: null;
    $father_contact      = clean_string($_POST['father_contact'] ?? '', 15)       ?: null;
    $mother_maiden_name  = clean_string($_POST['mother_maiden_name'] ?? '', 150)  ?: null;
    $mother_occupation   = clean_string($_POST['mother_occupation'] ?? '', 100)   ?: null;
    $mother_contact      = clean_string($_POST['mother_contact'] ?? '', 15)       ?: null;
    $ave_family_income   = ($_POST['ave_family_income'] ?? '') !== '' ? (float)$_POST['ave_family_income'] : null;
    $is_4ps              = isset($_POST['is_4ps']) ? (int)$_POST['is_4ps'] : 0;
    $guardian_fullname   = clean_string($_POST['guardian_fullname'] ?? '', 150)   ?: null;
    $guardian_relation   = clean_string($_POST['guardian_relation'] ?? '', 60)    ?: null;
    $guardian_contact    = clean_string($_POST['guardian_contact'] ?? '', 15)     ?: null;
    $parent_household_id = clean_string($_POST['household_id'] ?? '', 50)         ?: null;

    bind_params($stmt, [
        $student_info_id, $father_name, $father_occupation, $father_contact,
        $mother_maiden_name, $mother_occupation, $mother_contact,
        $ave_family_income, $is_4ps, $guardian_fullname, $guardian_relation,
        $guardian_contact, $parent_household_id,
    ]);

    if (!$stmt->execute()) throw new Exception('[parents_info:execute] ' . $stmt->error);
    $stmt->close();

    /* ── 5. educational_history ── */
    if (isset($_POST['edu_level']) && is_array($_POST['edu_level']) && count($_POST['edu_level']) > 0) {
        $stmt = $conn->prepare("INSERT INTO educational_history 
            (student_id, level, school_name, school_address, year_completed) 
            VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) throw new Exception('[educational_history:prepare] ' . $conn->error);

        foreach ($_POST['edu_level'] as $i => $level) {
            $level = clean_string((string)$level, 60);
            if ($level === '') continue;

            $school_name    = clean_string($_POST['school_name'][$i]    ?? '', 200);
            $school_address = clean_string($_POST['school_address'][$i] ?? '', 300);
            $year_completed = clean_string($_POST['year_completed'][$i] ?? '', 20);

            bind_params($stmt, [
                $student_info_id, $level, $school_name, $school_address, $year_completed,
            ]);

            if (!$stmt->execute()) {
                throw new Exception('[educational_history:execute] entry ' . $i . ': ' . $stmt->error);
            }
        }
        $stmt->close();
    }

    /* ── 6. enrollment_form ── */
    $stmt = $conn->prepare("INSERT INTO enrollment_form 
        (student_id, school_year, grade_level, term, track, strand, program, section,
         household_id, is_transferred, previous_school_name, previous_school_address, 
         previous_track, previous_strand, previous_program, previous_year_completed, 
         voucher_status, cct_4ps, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
    if (!$stmt) throw new Exception('[enrollment_form:prepare] ' . $conn->error);

    $grade_level = clean_string($_POST['grade_level'] ?? '', 20);
    $term        = clean_string($_POST['term'] ?? '', 20);
    $track       = clean_string($_POST['track'] ?? 'TECHPRO ELECTIVES', 60);
    $strand      = clean_string($_POST['strand'] ?? '', 150);
    if ($strand === '') $strand = clean_string($_POST['program'] ?? '', 150);
    if ($strand === '') $strand = 'General';
    $program     = clean_string($_POST['program'] ?? '', 150);

    if (!empty($_POST['section'])) {
        $section = clean_string($_POST['section'], 50);
    } else {
        $section = assignProvisionalSection($conn, $strand, $grade_level, $school_year, 35);
    }

    $household_id            = clean_string($_POST['household_id'] ?? '', 50)            ?: null;
    $is_transferred          = isset($_POST['is_transferred']) ? 1 : 0;
    $previous_school_name    = clean_string($_POST['previous_school_name'] ?? '', 200)    ?: null;
    $previous_school_address = clean_string($_POST['previous_school_address'] ?? '', 300) ?: null;
    $previous_track          = clean_string($_POST['previous_track'] ?? '', 60)           ?: null;
    $previous_strand         = clean_string($_POST['previous_strand'] ?? '', 150)         ?: null;
    $previous_program        = clean_string($_POST['previous_program'] ?? '', 150)        ?: null;
    $previous_year_completed = !empty($_POST['previous_year_completed']) ? (int)$_POST['previous_year_completed'] : null;
    $voucher_status = null;
    if (isset($_POST['voucher_qualified'])) {
        $voucher_status = ($_POST['voucher_qualified'] == '1') ? 'Qualified' : 'Not Qualified';
    }
    $cct_4ps = isset($_POST['is_4ps']) ? (int)$_POST['is_4ps'] : 0;

    bind_params($stmt, [
        $student_info_id, $school_year, $grade_level, $term, $track, $strand, $program, $section,
        $household_id, $is_transferred, $previous_school_name, $previous_school_address,
        $previous_track, $previous_strand, $previous_program, $previous_year_completed,
        $voucher_status, $cct_4ps,
    ]);

    if (!$stmt->execute()) throw new Exception('[enrollment_form:execute] ' . $stmt->error);
    $stmt->close();

    /* ── 7. entrance_documents — checkboxes without files ── */
    $uploaded_labels = [];
    if (!empty($_SESSION['uploaded_files']) && is_array($_SESSION['uploaded_files'])) {
        foreach ($_SESSION['uploaded_files'] as $f) {
            $lbl = trim((string)($f['label'] ?? ''));
            if ($lbl !== '') $uploaded_labels[$lbl] = true;
        }
    }

    if (!empty($_POST['entrance_data']) && is_array($_POST['entrance_data'])) {
        $doc_stmt = $conn->prepare("INSERT INTO entrance_documents 
            (student_id, document_name, submitted) 
            VALUES (?, ?, ?)");

        if ($doc_stmt) {
            foreach ($_POST['entrance_data'] as $document) {
                $document = clean_string((string)$document, 150);
                if ($document === '') continue;
                if (isset($uploaded_labels[$document])) continue;

                $submitted = 0;
                bind_params($doc_stmt, [$student_info_id, $document, $submitted]);
                $doc_stmt->execute();
            }
            $doc_stmt->close();
        }
    }

    /* ── 8. entrance_documents — encrypted files ── */
    if (!empty($_SESSION['uploaded_files']) && is_array($_SESSION['uploaded_files'])) {

        if (count($_SESSION['uploaded_files']) > 10) {
            throw new Exception('[files:count] Too many files uploaded (max 10).');
        }

        $uploaded_by = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;

        if ($uploaded_by !== null) {
            $file_stmt = $conn->prepare("INSERT INTO entrance_documents 
                (student_id, document_name, submitted, uploaded_at,
                 file_path, file_hash, file_size, file_width, file_height,
                 verification_notes, file_mime, file_name, uploaded_by,
                 content_status, content_score, content_engine, content_excerpt,
                 content_matched, content_checked_at)
                VALUES (?, ?, 1, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, NOW())");
        } else {
            $file_stmt = $conn->prepare("INSERT INTO entrance_documents 
                (student_id, document_name, submitted, uploaded_at,
                 file_path, file_hash, file_size, file_width, file_height,
                 verification_notes, file_mime, file_name,
                 content_status, content_score, content_engine, content_excerpt,
                 content_matched, content_checked_at)
                VALUES (?, ?, 1, NOW(), ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, NOW())");
        }

        if (!$file_stmt) throw new Exception('[entrance_documents:prepare] ' . $conn->error);

        foreach ($_SESSION['uploaded_files'] as $doc_key => $info) {
            $label   = $info['label'] ?? $doc_key;
            $staging = $info['staging_path'] ?? '';
            $name    = $info['name'] ?? $label;

            if ($staging === '' || !is_file($staging)) continue;

            $precomputed = build_document_content_verdict($doc_key);

            $stored = store_encrypted_file($staging, $student_info_id, $label, $precomputed);
            $written_files[] = DOCUMENTS_DIR . $stored['filename'];
            $staged_paths_to_cleanup[] = $staging;

            $safe_name = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $name);
            if ($safe_name === '' || $safe_name === null) $safe_name = $stored['filename'];

            $notes_json   = !empty($stored['notes']) ? json_encode($stored['notes']) : null;
            $content      = $stored['content'] ?? [];
            $content_stat = $content['status'] ?? 'skipped';
            $content_sc   = (int)($content['score'] ?? 0);
            $content_eng  = $content['engine'] ?? null;
            $content_exc  = $content['text_excerpt'] ?? null;
            $content_mt   = !empty($content['matched']) ? json_encode($content['matched']) : null;

            if ($uploaded_by !== null) {
                bind_params($file_stmt, [
                    $student_info_id, $label, $stored['filename'],
                    $stored['hash'], $stored['size'],
                    (int)($stored['width'] ?? 0), (int)($stored['height'] ?? 0),
                    $notes_json,
                    $stored['mime'], $safe_name, (int)$uploaded_by,
                    $content_stat, $content_sc, $content_eng, $content_exc, $content_mt,
                ]);
            } else {
                bind_params($file_stmt, [
                    $student_info_id, $label, $stored['filename'],
                    $stored['hash'], $stored['size'],
                    (int)($stored['width'] ?? 0), (int)($stored['height'] ?? 0),
                    $notes_json,
                    $stored['mime'], $safe_name,
                    $content_stat, $content_sc, $content_eng, $content_exc, $content_mt,
                ]);
            }

            if (!$file_stmt->execute()) {
                throw new Exception('[entrance_documents:execute] ' . $file_stmt->error);
            }
        }
        $file_stmt->close();
    }

    /* ── COMMIT ── */
    $conn->commit();
    $conn->query("SELECT RELEASE_LOCK('enroll_global')");

    /* ── Cleanup staged files AFTER commit succeeds ── */
    foreach ($staged_paths_to_cleanup as $p) {
        if (is_file($p)) @unlink($p);
    }

    unset($_SESSION['__pending_count'], $_SESSION['__pending_count_at']);

    if (!empty($_SESSION['admin_id'])) {
        require_once __DIR__ . '/../config/audit.php';
        audit_log($conn, 'student.enroll', [
            'type'       => 'student',
            'id'         => $student_info_id,
            'student_id' => $student_id_number,
            'sy'         => $school_year,
            'grade'      => $grade_level,
            'term'       => $term,
        ]);
    }

    unset(
        $_SESSION['form_data'],
        $_SESSION['uploaded_files'],
        $_SESSION['enroll_error'],
        $_SESSION['name_verify'],
        $_SESSION['photo_2x2_check'],
        $_SESSION['success_message']
    );

    $_SESSION['success'] = true;
    $_SESSION['success_data'] = [
        'student_id_number' => $student_id_number,
        'lrn'               => $lrn,
        'full_name'         => trim($first_name . ' ' . $last_name),
        'school_year'       => $school_year,
        'grade_level'       => $grade_level,
        'strand'            => $strand,
        'email'             => $email ?: null,
    ];

    header('Location: enroll_form.php');
    exit();

} catch (Exception $e) {
    $conn->rollback();
    $conn->query("SELECT RELEASE_LOCK('enroll_global')");

    // Clean up encrypted files written during this failed attempt
    foreach ($written_files as $abs) {
        if (file_exists($abs)) @unlink($abs);
    }

    $corr    = bin2hex(random_bytes(4));
    $raw_msg = $e->getMessage();

    error_log("Enrollment Error [{$corr}]: " . $raw_msg);
    error_log("Error Trace [{$corr}]: " . $e->getTraceAsString());

    $log_dir = dirname(__DIR__) . '/logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0700, true);
    }
    if (is_dir($log_dir) && is_writable($log_dir)) {
        @file_put_contents(
            $log_dir . '/enroll_debug.log',
            date('c') . " [{$corr}]\n"
            . "MESSAGE: " . $raw_msg . "\n"
            . "FILE:    " . $e->getFile() . ":" . $e->getLine() . "\n"
            . "TRACE:\n" . $e->getTraceAsString() . "\n\n",
            FILE_APPEND
        );
    }

    security_log('process_enroll.failed', [
        'ref'   => $corr,
        'error' => $raw_msg,
        'ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    if (!isset($_SESSION['form_data']) && !empty($_POST)) {
        $_SESSION['form_data'] = $_POST;
    }

    if (!empty($_SESSION['uploaded_files']) && is_array($_SESSION['uploaded_files'])) {
        $survivors = [];
        foreach ($_SESSION['uploaded_files'] as $k => $f) {
            $p = $f['staging_path'] ?? '';
            if ($p !== '' && is_file($p)) {
                $survivors[$k] = $f;
            }
        }
        $_SESSION['uploaded_files'] = $survivors;
    }

    [$user_msg, $user_action, $error_step] = classify_enrollment_error($raw_msg, $corr);

    $_SESSION['enroll_error'] = [
        'ref'     => $corr,
        'message' => $user_msg,
        'action'  => $user_action,
        'step'    => $error_step,
    ];

    header('Location: enroll_form.php?error=1');
    exit();
}