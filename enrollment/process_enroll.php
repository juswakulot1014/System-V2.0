<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/crypto.php';
require_once __DIR__ . '/../config/file_verify.php';
require_once __DIR__ . '/../config/document_content_verify.php';

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: enroll_form.php');
    exit();
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    // Preserve the user's work so they don't lose their form data.
    if (!isset($_SESSION['form_data']) && !empty($_POST)) {
        $restore = $_POST;
        unset($restore['csrf_token']);                 // don't cache the stale token
        $_SESSION['form_data'] = $restore;
    }

    // Refresh the session token so the next attempt has a valid one.
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $_SESSION['error'] = 'Your session expired. Please review and submit again.';
    header('Location: enroll_form.php?edit=1');
    exit();
}

/**
 * Auto-detect types and bind params safely.
 */
function bind_params(mysqli_stmt $stmt, array $values): void
{
    $types = '';
    foreach ($values as $v) {
        $types .= match (true) {
            is_int($v)   => 'i',
            is_float($v) => 'd',
            default      => 's',
        };
    }
    $stmt->bind_param($types, ...$values);
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

    // ---- Document / upload problems → step 5 ----
    if (str_contains($m, 'file verification failed')) {
        return [
            'One of your uploaded documents was rejected by the file checker.',
            'Please make sure the file is a valid PDF, JPG, or PNG under the size limit, then re-upload it.',
            5,
        ];
    }
    if (str_contains($m, 'staged file missing')) {
        return [
            'One of your uploaded documents could not be found.',
            'Please re-upload the affected document and submit again.',
            5,
        ];
    }
    if (str_contains($m, 'empty file')) {
        return [
            'One of your uploaded documents is empty or unreadable.',
            'Please re-upload a valid copy of the document.',
            5,
        ];
    }
    if (str_contains($m, 'too many files')) {
        return [
            'You tried to upload too many documents.',
            'Please keep the total number of uploads to 10 or fewer.',
            5,
        ];
    }
    if (str_contains($m, 'cannot write encrypted file') || str_contains($m, 'storage write failed')) {
        return [
            'The system could not save your uploaded documents.',
            'Please try again in a moment. If it keeps failing, contact the registrar with reference: ' . $corr,
            5,
        ];
    }
    if (str_contains($m, 'upload at least one')) {
        return [
            'You did not upload any entrance documents.',
            'Please tick at least one document checkbox and upload the file for it.',
            5,
        ];
    }
    if (str_contains($m, 'unsupported file type') || str_contains($m, 'not valid for')) {
        return [
            'One of your files is the wrong type for that document slot.',
            'Photos accept only JPG/PNG. Certificates accept PDF, JPG, or PNG.',
            5,
        ];
    }
    if (str_contains($m, 'document verification failed') || str_contains($m, 'jhs')) {
        return [
            'Your JHS Certificate could not be verified.',
            'Please re-upload a clearer copy of the Junior High School Certificate (Front).',
            5,
        ];
    }

    // ---- Personal info problems → step 1 ----
    if (str_contains($m, 'birth date')) {
        return [
            'Your birth date is missing or invalid.',
            'Please return to Step 1 and enter a valid birth date.',
            1,
        ];
    }
    if (str_contains($m, 'age must be')) {
        return [
            'Your age is outside the allowed range (14–25).',
            'Check your birth date — the age field is calculated from it automatically.',
            1,
        ];
    }

    // ---- Student ID generation — system issue ----
    if (str_contains($m, 'student id') && str_contains($m, 'unique')) {
        return [
            'The system could not assign a unique Student ID.',
            'Please try submitting again. If it keeps failing, contact the registrar with reference: ' . $corr,
            1,
        ];
    }

    // ---- SQL / DB layer — user cannot fix these ----
    if (str_contains($m, 'prepare failed') || str_contains($m, 'execute failed') || str_contains($m, 'column')) {
        return [
            'A system error occurred while saving your enrollment.',
            'Please try submitting again in a moment. If the problem persists, contact the registrar with reference: ' . $corr,
            1,
        ];
    }

    // ---- Fallback ----
    return [
        'We could not complete your enrollment.',
        'Please review your entries carefully and submit again. If it keeps failing, contact the registrar with reference: ' . $corr,
        1,
    ];
}

/* ══════════════════════════════════════════════════════════════════
   VERIFICATION MAPPING HELPERS
   ─────────────────────────────────────────────────────────────────
   Convert the in-session verification verdicts (produced by
   document_content_verify.php and stored on the Review page) into the
   exact shape that entrance_documents expects.
   ══════════════════════════════════════════════════════════════════ */

/**
 * Route a document by its key to the appropriate verdict builder.
 * Returns null for documents that aren't content-verified.
 */
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

/**
 * JHS Certificate (Front) — OCR / pdftotext identity check.
 */
function build_jhs_front_verdict(): ?array
{
    $nv = $_SESSION['name_verify'] ?? null;
    if (!is_array($nv) || empty($nv)) {
        return null;
    }

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
            'first'  => $nv['parts']['first']  ?? null,
            'middle' => $nv['parts']['middle'] ?? null,
            'last'   => $nv['parts']['last']   ?? null,
        ],
        'lrn' => [
            'expected' => $nv['lrn']['expected'] ?? null,
            'found'    => $nv['lrn']['best']     ?? null,
            'distance' => $nv['lrn']['distance'] ?? null,
        ],
        'ocr_rotation'   => (int)($nv['rotation'] ?? 0),
        'ocr_psm'        => (int)($nv['psm']     ?? 6),
        'ocr_score'      => (int)($nv['score']   ?? 0),
        'detection_path' => $nv['source']        ?? null,
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

/**
 * 2×2 Picture — lightweight format check.
 */
function build_photo_2x2_verdict(): ?array
{
    $p2 = $_SESSION['photo_2x2_check'] ?? null;
    if (!is_array($p2) || empty($p2)) {
        return null;
    }

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

/**
 * Verify, encrypt, and store a staged file.
 *
 * The optional $precomputed_content parameter carries the verdict that
 * already ran on the Review page. When supplied, it replaces the internal
 * verify_document_content() call and is written verbatim to the DB.
 */
function store_encrypted_file(
    string $staging_path,
    int $student_id,
    string $doc_label = '',
    ?array $precomputed_content = null
): array {
    if (!is_file($staging_path)) {
        throw new Exception("Staged file missing.");
    }

    $verify = verify_document_file($staging_path, $doc_label);
    if (!$verify['ok']) {
        throw new Exception("File verification failed: " . ($verify['error'] ?? 'unknown'));
    }

    // ── Content check ──
    if ($precomputed_content !== null) {
        $content = $precomputed_content;
    } elseif (function_exists('verify_document_content')) {
        $content = verify_document_content($staging_path, $doc_label);
    } else {
        error_log('store_encrypted_file: no precomputed content and no verify_document_content() — skipped.');
        $content = [
            'status'  => 'skipped',
            'score'   => 0,
            'engine'  => null,
            'matched' => [],
            'notes'   => ['No verification data available at save time.'],
        ];
    }

    $content_status = $content['status'] ?? 'skipped';

    if ($content_status === 'failed') {
        error_log(sprintf(
            'store_encrypted_file: content mismatch for "%s" (score=%d) — downgraded to needs_review.',
            $doc_label,
            (int)($content['score'] ?? 0)
        ));
        $content['status'] = 'needs_review';
        $content['notes']  = array_merge(
            (array)($content['notes'] ?? []),
            ['Auto-flagged by system: content did not match expected keywords.']
        );
    }

    $plain = file_get_contents($staging_path);
    if ($plain === false || $plain === '') {
        throw new Exception("Empty file after verification.");
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
        throw new Exception("Cannot write encrypted file.");
    }
    @chmod($abs_path, 0600);
    @unlink($staging_path);

    return [
        'filename' => $filename,
        'mime'     => $verify['mime'],
        'size'     => strlen($plain),
        'hash'     => $verify['hash'],
        'width'    => $verify['width'],
        'height'   => $verify['height'],
        'notes'    => array_merge($verify['notes'] ?? [], $content['notes'] ?? []),
        'content'  => $content,
    ];
}

// Track files written to disk for cleanup on rollback
$written_files = [];

// ── Server-side age recompute ──
if (empty($_POST['birth_date'])) {
    $_SESSION['error'] = 'Birth date is required.';
    header('Location: enroll_form.php');
    exit();
}
$birth = DateTime::createFromFormat('Y-m-d', $_POST['birth_date']);
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
    $_SESSION['error'] = 'System busy, please try again in a moment.';
    header('Location: enroll_form.php');
    exit();
}

$conn->begin_transaction();

try {
    // ============================================
    // 1. INSERT INTO students_info
    // ============================================
    $stmt = $conn->prepare("INSERT INTO students_info 
        (lrn, first_name, last_name, middle_name, nick_name, ext_name, sex, birth_date, age, 
         civil_status, nationality, religion, height, weight, email, phone, special_skills, photo) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$stmt) throw new Exception("Prepare failed for students_info: " . $conn->error);

    $lrn = $_POST['lrn'] ?? '';
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $middle_name = !empty($_POST['middle_name']) ? $_POST['middle_name'] : null;
    $nick_name = !empty($_POST['nick_name']) ? $_POST['nick_name'] : null;
    $ext_name = !empty($_POST['ext_name']) ? $_POST['ext_name'] : null;
    $sex = $_POST['sex'] ?? '';
    $birth_date = $_POST['birth_date'] ?? '';
    $age = (int)($_POST['age'] ?? 0);
    $civil_status = !empty($_POST['civil_status']) ? $_POST['civil_status'] : null;
    $nationality = !empty($_POST['nationality']) ? $_POST['nationality'] : null;
    $religion = !empty($_POST['religion']) ? $_POST['religion'] : null;
    $height = !empty($_POST['height']) ? floatval($_POST['height']) : null;
    $weight = !empty($_POST['weight']) ? floatval($_POST['weight']) : null;
    $email = !empty($_POST['email']) ? $_POST['email'] : null;
    $phone = !empty($_POST['phone']) ? $_POST['phone'] : null;
    $special_skills = !empty($_POST['special_skills']) ? $_POST['special_skills'] : null;
    $photo = null;

    bind_params($stmt, [
        $lrn, $first_name, $last_name, $middle_name, $nick_name, $ext_name,
        $sex, $birth_date, $age, $civil_status, $nationality, $religion,
        $height, $weight, $email, $phone, $special_skills, $photo,
    ]);

    if (!$stmt->execute()) throw new Exception("Execute failed for students_info: " . $stmt->error);

    $student_info_id = $conn->insert_id;
    $stmt->close();

    // ============================================
    // 2. GENERATE STUDENT ID NUMBER
    // ============================================
    $school_year = $_POST['school_year'] ?? '';
    $sy_parts = explode('-', $school_year);
    $year_enrolled = (int)($sy_parts[0] ?? 0);

    if ($year_enrolled < 2000 || $year_enrolled > 2100) {
        $year_enrolled = (int)date('Y');
    }

    $student_id_number = '';
    $attempts = 0;
    $collision = true;

    do {
        $random6 = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $student_id_number = "UCSCI-{$year_enrolled}-{$random6}";

        $check = $conn->prepare("SELECT 1 FROM students_info WHERE student_id_number = ? LIMIT 1");
        $check->bind_param("s", $student_id_number);
        $check->execute();
        $collision = $check->get_result()->num_rows > 0;
        $check->close();

        $attempts++;
    } while ($collision && $attempts < 10);

    if ($collision) {
        throw new Exception("Could not generate a unique Student ID after 10 attempts.");
    }

    $update_stmt = $conn->prepare("UPDATE students_info SET student_id_number = ? WHERE student_id = ?");
    if (!$update_stmt) throw new Exception("Prepare failed for student_id_number update: " . $conn->error);

    bind_params($update_stmt, [$student_id_number, $student_info_id]);

    if (!$update_stmt->execute()) {
        throw new Exception("Execute failed for student_id_number update: " . $update_stmt->error);
    }
    $update_stmt->close();

    // ============================================
    // 3. INSERT INTO addresses
    // ============================================
    $stmt = $conn->prepare("INSERT INTO addresses 
        (student_id, purok_street, barangay, town_city, province, region, district, postal_code) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$stmt) throw new Exception("Prepare failed for addresses: " . $conn->error);

    $purok_street = $_POST['purok_street'] ?? null;
    $barangay = $_POST['barangay'] ?? null;
    $town_city = $_POST['town_city'] ?? null;
    $province = $_POST['province'] ?? null;
    $region = $_POST['region'] ?? null;
    $district = $_POST['district'] ?? null;
    $postal_code = $_POST['postal_code'] ?? null;

    bind_params($stmt, [
        $student_info_id, $purok_street, $barangay, $town_city,
        $province, $region, $district, $postal_code,
    ]);

    if (!$stmt->execute()) throw new Exception("Execute failed for addresses: " . $stmt->error);
    $stmt->close();

    // ============================================
    // 4. INSERT INTO parents_info
    // ============================================
    $stmt = $conn->prepare("INSERT INTO parents_info 
        (student_id, father_name, father_occupation, father_contact, mother_name,
         mother_maiden_name, mother_occupation, mother_contact, ave_family_income, 
         is_4ps, guardian_fullname, guardian_relation, guardian_contact, household_id) 
        VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$stmt) throw new Exception("Prepare failed for parents_info: " . $conn->error);

    $father_name = $_POST['father_name'] ?? null;
    $father_occupation = $_POST['father_occupation'] ?? null;
    $father_contact = $_POST['father_contact'] ?? null;
    $mother_maiden_name = $_POST['mother_maiden_name'] ?? null;
    $mother_occupation = $_POST['mother_occupation'] ?? null;
    $mother_contact = $_POST['mother_contact'] ?? null;
    $ave_family_income = !empty($_POST['ave_family_income']) ? floatval($_POST['ave_family_income']) : null;
    $is_4ps = isset($_POST['is_4ps']) ? intval($_POST['is_4ps']) : 0;
    $guardian_fullname = $_POST['guardian_fullname'] ?? null;
    $guardian_relation = $_POST['guardian_relation'] ?? null;
    $guardian_contact = $_POST['guardian_contact'] ?? null;
    $parent_household_id = $_POST['household_id'] ?? null;

    bind_params($stmt, [
        $student_info_id, $father_name, $father_occupation, $father_contact,
        $mother_maiden_name, $mother_occupation, $mother_contact,
        $ave_family_income, $is_4ps, $guardian_fullname, $guardian_relation,
        $guardian_contact, $parent_household_id,
    ]);

    if (!$stmt->execute()) throw new Exception("Execute failed for parents_info: " . $stmt->error);
    $stmt->close();

    // ============================================
    // 5. INSERT INTO educational_history
    // ============================================
    if (isset($_POST['edu_level']) && is_array($_POST['edu_level']) && count($_POST['edu_level']) > 0) {
        $stmt = $conn->prepare("INSERT INTO educational_history 
            (student_id, level, school_name, school_address, year_completed) 
            VALUES (?, ?, ?, ?, ?)");

        if (!$stmt) throw new Exception("Prepare failed for educational_history: " . $conn->error);

        foreach ($_POST['edu_level'] as $i => $level) {
            if (empty($level)) continue;

            $school_name = $_POST['school_name'][$i] ?? null;
            $school_address = $_POST['school_address'][$i] ?? null;
            $year_completed = $_POST['year_completed'][$i] ?? null;

            bind_params($stmt, [
                $student_info_id, $level, $school_name, $school_address, $year_completed,
            ]);

            if (!$stmt->execute()) throw new Exception("Execute failed for educational_history entry $i: " . $stmt->error);
        }
        $stmt->close();
    }

    // ============================================
    // 6. INSERT INTO enrollment_form (status = Pending)
    // ============================================
    $stmt = $conn->prepare("INSERT INTO enrollment_form 
        (student_id, school_year, grade_level, term, track, strand, program, section,
         household_id, is_transferred, previous_school_name, previous_school_address, 
         previous_track, previous_strand, previous_program, previous_year_completed, 
         voucher_status, cct_4ps, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");

    if (!$stmt) throw new Exception("Prepare failed for enrollment_form: " . $conn->error);

    $grade_level = $_POST['grade_level'] ?? '';
    $term = $_POST['term'] ?? '';
    $track = $_POST['track'] ?? 'TECHPRO ELECTIVES';
    $strand = trim((string)($_POST['strand'] ?? ''));
    if ($strand === '') $strand = trim((string)($_POST['program'] ?? ''));
    if ($strand === '') $strand = 'General';
    $program = $_POST['program'] ?? '';

    if (!empty($_POST['section'])) {
        $section = trim($_POST['section']);
    } else {
        $section = assignProvisionalSection($conn, $strand, $grade_level, $school_year, 35);
    }

    $household_id = $_POST['household_id'] ?? null;
    $is_transferred = isset($_POST['is_transferred']) ? 1 : 0;
    $previous_school_name = $_POST['previous_school_name'] ?? null;
    $previous_school_address = $_POST['previous_school_address'] ?? null;
    $previous_track = $_POST['previous_track'] ?? null;
    $previous_strand = $_POST['previous_strand'] ?? null;
    $previous_program = $_POST['previous_program'] ?? null;
    $previous_year_completed = !empty($_POST['previous_year_completed']) ? intval($_POST['previous_year_completed']) : null;
    $voucher_status = null;
    if (isset($_POST['voucher_qualified'])) {
        $voucher_status = ($_POST['voucher_qualified'] == '1') ? 'Qualified' : 'Not Qualified';
    }
    $cct_4ps = isset($_POST['is_4ps']) ? intval($_POST['is_4ps']) : 0;

    bind_params($stmt, [
        $student_info_id,
        $school_year,
        $grade_level,
        $term,
        $track,
        $strand,
        $program,
        $section,
        $household_id,
        $is_transferred,
        $previous_school_name,
        $previous_school_address,
        $previous_track,
        $previous_strand,
        $previous_program,
        $previous_year_completed,
        $voucher_status,
        $cct_4ps,
    ]);

    if (!$stmt->execute()) throw new Exception("Execute failed for enrollment_form: " . $stmt->error);
    $stmt->close();

    // ============================================
    // 7. INSERT entrance document checkboxes (no file)
    // ============================================
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
            VALUES (?, ?, 0)");

        if ($doc_stmt) {
            foreach ($_POST['entrance_data'] as $document) {
                $document = trim((string)$document);
                if ($document === '') continue;
                if (isset($uploaded_labels[$document])) continue;

                bind_params($doc_stmt, [$student_info_id, $document, 0]);
                $doc_stmt->execute();
            }
            $doc_stmt->close();
        }
    }

    // ============================================
    // 8. SAVE STAGED FILES — ENCRYPTED AT REST
    // ============================================
    if (!empty($_SESSION['uploaded_files']) && is_array($_SESSION['uploaded_files'])) {

        if (count($_SESSION['uploaded_files']) > 10) {
            throw new Exception("Too many files uploaded (max 10).");
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

        if (!$file_stmt) throw new Exception("Prepare failed for file insert: " . $conn->error);

        foreach ($_SESSION['uploaded_files'] as $doc_key => $info) {
            $label   = $info['label'] ?? $doc_key;
            $staging = $info['staging_path'] ?? '';
            $name    = $info['name'] ?? $label;

            if ($staging === '' || !is_file($staging)) continue;

            // ★ NEW: Pull the pre-computed verdict for this specific document.
            // Returns null for docs that weren't content-verified.
            $precomputed = build_document_content_verdict($doc_key);

            $stored = store_encrypted_file($staging, $student_info_id, $label, $precomputed);
            $written_files[] = DOCUMENTS_DIR . $stored['filename'];

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
                throw new Exception("Execute failed for file insert: " . $file_stmt->error);
            }
        }
        $file_stmt->close();
    }

    // ============================================
    // COMMIT
    // ============================================
    $conn->commit();
    $conn->query("SELECT RELEASE_LOCK('enroll_global')");

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

    unset($_SESSION['csrf_token']);
    unset($_SESSION['form_data'], $_SESSION['uploaded_files'], $_SESSION['enroll_error']);
    unset($_SESSION['name_verify'], $_SESSION['photo_2x2_check']);

    $_SESSION['success'] = true;
    $_SESSION['success_message'] = "Your enrollment has been <strong>submitted for review</strong>.<br><br>
        <strong>Student ID:</strong> " . htmlspecialchars($student_id_number) . "<br>
        <strong>LRN:</strong> " . htmlspecialchars($lrn) . "<br>
        <strong>Name:</strong> " . htmlspecialchars($first_name . ' ' . $last_name) . "<br>
        <strong>School Year:</strong> " . htmlspecialchars($school_year) . "<br>
        <strong>Grade Level:</strong> " . htmlspecialchars($grade_level) . "<br>
        <strong>Strand:</strong> " . htmlspecialchars($strand) . "<br><br>
        <em>The registrar will review your documents. You'll receive a confirmation email at 
        <strong>" . htmlspecialchars($email ?: 'your registered email') . "</strong> once approved.</em>";

    header('Location: enroll_form.php');
    exit();

} catch (Exception $e) {
    $conn->rollback();
    $conn->query("SELECT RELEASE_LOCK('enroll_global')");

    // Clean up any encrypted files that were written before the failure
    foreach ($written_files as $abs) {
        if (file_exists($abs)) @unlink($abs);
    }

    $corr    = bin2hex(random_bytes(4));
    $raw_msg = $e->getMessage();

    error_log("Enrollment Error [{$corr}]: " . $raw_msg);
    error_log("Error Trace [{$corr}]: " . $e->getTraceAsString());

    @file_put_contents(
        __DIR__ . '/../enroll_debug.log',
        date('c') . " [{$corr}]\n"
        . "MESSAGE: " . $raw_msg . "\n"
        . "FILE:    " . $e->getFile() . ":" . $e->getLine() . "\n"
        . "TRACE:\n" . $e->getTraceAsString() . "\n\n",
        FILE_APPEND
    );

    // ---- Preserve the student's form data so they don't lose their work ----
    if (!isset($_SESSION['form_data']) && !empty($_POST)) {
        $_SESSION['form_data'] = $_POST;
    }

    // ---- Preserve staged files that still exist on disk ----
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

    // ---- Classify the error into a user-readable message + step to return to ----
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