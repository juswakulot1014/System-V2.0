<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/crypto.php';

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: enroll_form.php');
    exit();
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['error'] = 'Invalid security token. Please try again.';
    header('Location: enroll_form.php');
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
 * Encrypt and store a staged file.
 */
function store_encrypted_file(
    string $staging_path,
    int $student_id,
    int $max_bytes = 5 * 1024 * 1024
): array {
    if (!is_file($staging_path)) throw new Exception("Staged file missing");
    if (filesize($staging_path) > $max_bytes) throw new Exception("File too large");

    $plain = file_get_contents($staging_path);
    if ($plain === false || $plain === '') throw new Exception("Empty file");

    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $staging_path);
    finfo_close($fi);

    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];
    if (!isset($allowed[$mime])) throw new Exception("Invalid file type: {$mime}");

    $blob = doc_encrypt($plain);

    try {
        $token = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        $token = bin2hex(openssl_random_pseudo_bytes(16));
    }

    $filename = "enroll_{$student_id}_{$token}.{$allowed[$mime]}.enc";
    $abs_path = DOCUMENTS_DIR . $filename;

    if (file_put_contents($abs_path, $blob, LOCK_EX) === false) {
        throw new Exception("Cannot write encrypted file");
    }
    @chmod($abs_path, 0600);
    @unlink($staging_path);

    return [
        'filename' => $filename,
        'mime'     => $mime,
        'size'     => strlen($plain),
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
    // Drop stale entries whose staging file no longer exists
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
    //    Format: UCSCI-{year enrolled}-{6 random digits}
    //    Example: UCSCI-2026-483920
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
    // 6. INSERT INTO enrollment_form
    //    Uses `term` column (not `semester`)
    // ============================================
    $stmt = $conn->prepare("INSERT INTO enrollment_form 
        (student_id, school_year, grade_level, term, track, strand, program, section,
         household_id, is_transferred, previous_school_name, previous_school_address, 
         previous_track, previous_strand, previous_program, previous_year_completed, 
         voucher_status, cct_4ps, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");

    if (!$stmt) throw new Exception("Prepare failed for enrollment_form: " . $conn->error);

    $grade_level = $_POST['grade_level'] ?? '';
    $term = $_POST['term'] ?? '';
    $track = $_POST['track'] ?? 'TECHPRO ELECTIVES';
    $strand = trim((string)($_POST['strand'] ?? ''));
    if ($strand === '') $strand = trim((string)($_POST['program'] ?? ''));
    if ($strand === '') $strand = 'General';
    $program = $_POST['program'] ?? '';

    // Section assignment
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
                (student_id, document_name, submitted, uploaded_at, file_path, file_mime, file_name, uploaded_by) 
                VALUES (?, ?, 1, NOW(), ?, ?, ?, ?)");
        } else {
            $file_stmt = $conn->prepare("INSERT INTO entrance_documents 
                (student_id, document_name, submitted, uploaded_at, file_path, file_mime, file_name) 
                VALUES (?, ?, 1, NOW(), ?, ?, ?)");
        }

        if (!$file_stmt) throw new Exception("Prepare failed for file insert: " . $conn->error);

        foreach ($_SESSION['uploaded_files'] as $doc_key => $info) {
            $label   = $info['label'] ?? $doc_key;
            $staging = $info['staging_path'] ?? '';
            $name    = $info['name'] ?? $label;

            if ($staging === '' || !is_file($staging)) continue;

            $stored = store_encrypted_file($staging, $student_info_id);
            $written_files[] = DOCUMENTS_DIR . $stored['filename'];

            $safe_name = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $name);
            if ($safe_name === '' || $safe_name === null) $safe_name = $stored['filename'];

            if ($uploaded_by !== null) {
                bind_params($file_stmt, [
                    $student_info_id, $label, $stored['filename'],
                    $stored['mime'], $safe_name, (int)$uploaded_by,
                ]);
            } else {
                bind_params($file_stmt, [
                    $student_info_id, $label, $stored['filename'],
                    $stored['mime'], $safe_name,
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

    // Audit log — only if an admin is logged in (student submissions skip)
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

    // Rotate CSRF token
    unset($_SESSION['csrf_token']);

    $_SESSION['success'] = true;
    $_SESSION['success_message'] = "Student successfully enrolled!<br><br>
        <strong>Student ID:</strong> " . htmlspecialchars($student_id_number) . "<br>
        <strong>LRN:</strong> " . htmlspecialchars($lrn) . "<br>
        <strong>Name:</strong> " . htmlspecialchars($first_name . ' ' . $last_name) . "<br>
        <strong>Birth Date:</strong> " . htmlspecialchars($birth_date) . "<br>
        <strong>School Year:</strong> " . htmlspecialchars($school_year) . "<br>
        <strong>Grade Level:</strong> " . htmlspecialchars($grade_level) . "<br>
        <strong>Term:</strong> " . htmlspecialchars($term) . "<br>
        <strong>Strand:</strong> " . htmlspecialchars($strand) . "<br>
        <strong>Section:</strong> " . htmlspecialchars($section);

    unset($_SESSION['form_data'], $_SESSION['uploaded_files']);

    header('Location: enroll_form.php');
    exit();

} catch (Exception $e) {
    $conn->rollback();
    $conn->query("SELECT RELEASE_LOCK('enroll_global')");

    // Clean up encrypted files written before the failure
    foreach ($written_files as $abs) {
        if (file_exists($abs)) @unlink($abs);
    }

    $corr = bin2hex(random_bytes(4));
    error_log("Enrollment Error [{$corr}]: " . $e->getMessage());
    error_log("Error Trace [{$corr}]: " . $e->getTraceAsString());

    $_SESSION['error'] = 'Failed to enroll student. Please try again. Reference: ' . $corr;

    header('Location: enroll_form.php');
    exit();
}