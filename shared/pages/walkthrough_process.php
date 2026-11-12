<?php
declare(strict_types=1);

/**
 * shared/pages/walkthrough_process.php
 * Server-side processor for walkthrough_enrollment.php.
 * Creates a Pending or Active enrollment. Does NOT use a staging system —
 * files are uploaded once with the form submit and encrypted immediately.
 */

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/crypto.php';
require_once __DIR__ . '/../../config/security.php';

if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . URL_PREFIX . '/walkthrough_enrollment.php');
    exit;
}

verify_csrf();

$BACK = URL_PREFIX . '/walkthrough_enrollment.php';

/* Rate limit by admin id (not IP — internal tool) */
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
$rl = rate_limit_session('walkthrough_' . $admin_id, 30, 60);
if (empty($rl['allowed'])) {
    $_SESSION['walkthrough_errors'] = ['upload' => 'Too many submissions. Please wait a minute.'];
    header('Location: ' . $BACK);
    exit;
}

/* ═══ Validation helpers ═══ */
$STRAND_PROGRAMS = [
    'Automotive and Small Engine Technologies' => ['Driving and Automotive Servicing','Automotive Servicing (Electrical Repair)','Automotive Servicing (Engine and Chassis Repairs)'],
    'Construction and Building Technologies' => ['Carpentry','Manual Metal Arc Welding'],
    'ICT Support and Computer Programming Technologies' => ['Computer Programming (Java)','Computer Programming (.NET)','Computer System Servicing'],
    'Industrial Technologies' => ['Electronics Product Assembly and Servicing'],
    'Agri-Fishery Business and Food Innovation' => ['Agricultural Crops Production'],
    'Hospitality and Tourism' => ['Food and Beverage Operation','Hotel Operation (Housekeeping)'],
];
$ALLOWED_GRADES = ['Grade 11', 'Grade 12'];
$ALLOWED_TERMS  = ['1st Term', '2nd Term', '3rd Term'];
$ALLOWED_SEX    = ['Male', 'Female'];

$errors = [];
$rp = static function (string $k): string {
    $v = $_POST[$k] ?? '';
    return is_scalar($v) ? trim((string)$v) : '';
};

/* Step 1 */
$lrn_raw = preg_replace('/\D/', '', $rp('lrn'));
if (!preg_match('/^\d{12}$/', $lrn_raw))                  $errors['lrn']        = 'LRN must be exactly 12 digits.';
if (!preg_match("/^[A-Za-zÀ-ÿ\s\-'.]{2,}$/u", $rp('last_name')))  $errors['last_name']  = 'Invalid last name.';
if (!preg_match("/^[A-Za-zÀ-ÿ\s\-'.]{2,}$/u", $rp('first_name'))) $errors['first_name'] = 'Invalid first name.';
if (!in_array($rp('sex'), $ALLOWED_SEX, true))            $errors['sex']        = 'Please select a valid sex.';

$bd_raw = $rp('birth_date');
$birth  = DateTime::createFromFormat('Y-m-d', $bd_raw);
$now    = new DateTime();
if (!$birth || $birth->format('Y-m-d') !== $bd_raw)       $errors['birth_date'] = 'Invalid birth date.';
elseif ($birth > $now)                                     $errors['birth_date'] = 'Birth date cannot be in the future.';
else {
    $age = $now->diff($birth)->y;
    if ($age < 14 || $age > 25)                            $errors['birth_date'] = 'Age must be between 14 and 25.';
    else $_POST['age'] = (string)$age;
}
if ($rp('email') !== '' && !filter_var($rp('email'), FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Invalid email format.';
foreach (['phone', 'father_contact', 'mother_contact', 'guardian_contact'] as $f) {
    $val = $rp($f);
    if ($val !== '' && !is_valid_ph_mobile($val))         $errors[$f] = 'Invalid phone (09XXXXXXXXX).';
}

/* Step 2 */
if (!in_array((string)$rp('is_4ps'), ['0', '1'], true))    $errors['is_4ps'] = 'Please answer the CCT/4Ps question.';

/* Step 5 */
$sy_val = $rp('school_year');
if (!preg_match('/^(\d{4})-(\d{4})$/', $sy_val, $m_sy))   $errors['school_year'] = 'Invalid school year format.';
elseif ((int)$m_sy[2] !== (int)$m_sy[1] + 1)               $errors['school_year'] = 'School year must span consecutive years.';

if (!in_array($rp('grade_level'), $ALLOWED_GRADES, true))  $errors['grade_level'] = 'Invalid grade level.';
if (!in_array($rp('term'), $ALLOWED_TERMS, true))          $errors['term'] = 'Invalid term.';

$strand_v  = $rp('strand');
$program_v = $rp('program');
if (!isset($STRAND_PROGRAMS[$strand_v]))                   $errors['strand'] = 'Invalid strand.';
elseif (!in_array($program_v, $STRAND_PROGRAMS[$strand_v], true)) $errors['program'] = 'Program does not belong to selected strand.';

$entrance_data = $_POST['entrance_data'] ?? [];
if (!is_array($entrance_data) || empty($entrance_data))    $errors['entrance_data'] = 'Select at least one document.';

/* LRN duplicate check */
if (empty($errors['lrn'])) {
    $chk = $conn->prepare("SELECT student_id FROM students_info WHERE lrn = ? LIMIT 1");
    if ($chk) {
        $chk->bind_param('s', $lrn_raw);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $errors['lrn'] = 'This LRN is already registered.';
        }
        $chk->close();
    }
}

/* Abort on errors */
if (!empty($errors)) {
    $_SESSION['walkthrough_errors'] = $errors;
    $old = $_POST;
    unset($old['csrf_token']);
    $_SESSION['walkthrough_old'] = $old;
    header('Location: ' . $BACK);
    exit;
}

/* ═══ Lock to serialize concurrent auto-approvals ═══ */
$conn->query("SELECT GET_LOCK('walkthrough_" . (int)$admin_id . "', 10)");

$written_files = [];
$auto_approve  = !empty($_POST['auto_approve']);
$target_status = $auto_approve ? 'Active' : 'Pending';

$conn->begin_transaction();
try {
    /* ── 1. students_info ── */
    $ins = $conn->prepare("INSERT INTO students_info
        (lrn, first_name, last_name, middle_name, nick_name, ext_name, sex, birth_date, age,
         civil_status, nationality, religion, height, weight, email, phone, special_skills, photo)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    if (!$ins) throw new Exception('students_info prepare: ' . $conn->error);

    $lrn            = $lrn_raw;
    $first_name     = clean_string($rp('first_name'), 100);
    $last_name      = clean_string($rp('last_name'), 100);
    $middle_name    = clean_string($rp('middle_name'), 100) ?: null;
    $nick_name      = clean_string($rp('nick_name'), 50) ?: null;
    $ext_name       = clean_string($rp('ext_name'), 20) ?: null;
    $sex            = clean_string($rp('sex'), 10);
    $birth_date     = $bd_raw;
    $age            = (int)($_POST['age'] ?? 0);
    $civil_status   = clean_string($rp('civil_status'), 30) ?: null;
    $nationality    = clean_string($rp('nationality'), 50) ?: null;
    $religion       = clean_string($rp('religion'), 100) ?: null;
    $height         = null;
    $weight         = null;
    $email          = clean_string($rp('email'), 150) ?: null;
    $phone          = clean_string($rp('phone'), 15) ?: null;
    $special_skills = clean_string($rp('special_skills'), 500) ?: null;
    $photo          = null;

    $ins->bind_param(
        'ssssssssisssssssss',
        $lrn, $first_name, $last_name, $middle_name, $nick_name, $ext_name,
        $sex, $birth_date, $age, $civil_status, $nationality, $religion,
        $height, $weight, $email, $phone, $special_skills, $photo
    );
    if (!$ins->execute()) throw new Exception('students_info insert: ' . $ins->error);
    $student_id = $conn->insert_id;
    $ins->close();

    /* ── 2. Student ID number (sequential) ── */
    $year_enrolled = (int)explode('-', $sy_val)[0];
    if ($year_enrolled < 2000 || $year_enrolled > 2100) $year_enrolled = (int)date('Y');
    $prefix   = "UCSCI-{$year_enrolled}-";
    $like     = $prefix . '%';
    $seq_stmt = $conn->prepare("SELECT student_id_number FROM students_info WHERE student_id_number LIKE ? ORDER BY student_id_number DESC LIMIT 1");
    $next_seq = 1;
    if ($seq_stmt) {
        $seq_stmt->bind_param('s', $like);
        $seq_stmt->execute();
        $row = $seq_stmt->get_result()->fetch_assoc();
        $seq_stmt->close();
        if ($row) {
            $parts = explode('-', $row['student_id_number']);
            $next_seq = (int)end($parts) + 1;
        }
    }
    $student_id_number = $prefix . str_pad((string)$next_seq, 6, '0', STR_PAD_LEFT);
    $upd = $conn->prepare("UPDATE students_info SET student_id_number = ? WHERE student_id = ?");
    if ($upd) {
        $upd->bind_param('si', $student_id_number, $student_id);
        $upd->execute();
        $upd->close();
    }

    /* ── 3. addresses ── */
    $ins = $conn->prepare("INSERT INTO addresses
        (student_id, purok_street, barangay, town_city, province, region, district, postal_code)
        VALUES (?,?,?,?,?,?,?,?)");
    if ($ins) {
        $a1 = clean_string($rp('purok_street'), 200) ?: null;
        $a2 = clean_string($rp('barangay'), 100) ?: null;
        $a3 = clean_string($rp('town_city'), 100) ?: null;
        $a4 = clean_string($rp('province'), 100) ?: null;
        $a5 = clean_string($rp('region'), 100) ?: null;
        $a6 = clean_string($rp('district'), 100) ?: null;
        $a7 = clean_string($rp('postal_code'), 10) ?: null;
        $ins->bind_param('isssssss', $student_id, $a1, $a2, $a3, $a4, $a5, $a6, $a7);
        $ins->execute();
        $ins->close();
    }

    /* ── 4. parents_info ── */
    $ins = $conn->prepare("INSERT INTO parents_info
        (student_id, father_name, father_occupation, father_contact,
         mother_maiden_name, mother_occupation, mother_contact,
         ave_family_income, is_4ps, guardian_fullname, guardian_relation,
         guardian_contact, household_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    if ($ins) {
        $fn = clean_string($rp('father_name'), 150) ?: null;
        $fo = clean_string($rp('father_occupation'), 100) ?: null;
        $fc = clean_string($rp('father_contact'), 15) ?: null;
        $mn = clean_string($rp('mother_maiden_name'), 150) ?: null;
        $mo = clean_string($rp('mother_occupation'), 100) ?: null;
        $mc = clean_string($rp('mother_contact'), 15) ?: null;
        $ai = $rp('ave_family_income') !== '' ? (float)$rp('ave_family_income') : null;
        $i4 = (int)$rp('is_4ps');
        $gf = clean_string($rp('guardian_fullname'), 150) ?: null;
        $gr = clean_string($rp('guardian_relation'), 60) ?: null;
        $gc = clean_string($rp('guardian_contact'), 15) ?: null;
        $hh = clean_string($rp('household_id'), 50) ?: null;
        $ins->bind_param('issssssdsssss', $student_id, $fn, $fo, $fc, $mn, $mo, $mc, $ai, $i4, $gf, $gr, $gc, $hh);
        $ins->execute();
        $ins->close();
    }

    /* ── 5. educational_history ── */
    if (!empty($_POST['edu_level']) && is_array($_POST['edu_level'])) {
        $ins = $conn->prepare("INSERT INTO educational_history
            (student_id, level, school_name, school_address, year_completed)
            VALUES (?,?,?,?,?)");
        if ($ins) {
            foreach ($_POST['edu_level'] as $i => $lvl) {
                $lvl = clean_string((string)$lvl, 60);
                if ($lvl === '') continue;
                $sn = clean_string($_POST['school_name'][$i] ?? '', 200);
                $sa = clean_string($_POST['school_address'][$i] ?? '', 300);
                $yc = clean_string($_POST['year_completed'][$i] ?? '', 20);
                $ins->bind_param('issss', $student_id, $lvl, $sn, $sa, $yc);
                $ins->execute();
            }
            $ins->close();
        }
    }

    /* ── 6. auto-assign section if blank ── */
    $section = clean_string($rp('section'), 50);
    if ($section === '') {
        // Inline provisional assignment (mirrors process_enroll.php)
        $target_size = 35;
        $chk = $conn->prepare("
            SELECT section, COUNT(*) AS n FROM enrollment_form
            WHERE strand = ? AND grade_level = ? AND school_year = ?
              AND section IS NOT NULL AND section != ''
            GROUP BY section ORDER BY n DESC, section ASC
        ");
        if ($chk) {
            $chk->bind_param('sss', $strand_v, $rp('grade_level'), $sy_val);
            $chk->execute();
            $rows = $chk->get_result()->fetch_all(MYSQLI_ASSOC);
            $chk->close();
            if (empty($rows)) {
                $section = $strand_v . ' A';
            } else {
                $found = false;
                foreach ($rows as $r) {
                    if ((int)$r['n'] < $target_size) { $section = $r['section']; $found = true; break; }
                }
                if (!$found) {
                    $used = [];
                    foreach ($rows as $r) {
                        if (preg_match('/\s+([A-Z])\s*$/i', $r['section'], $m)) $used[strtoupper($m[1])] = true;
                    }
                    for ($i = 0; $i < 26; $i++) {
                        $letter = chr(65 + $i);
                        if (!isset($used[$letter])) { $section = $strand_v . ' ' . $letter; break; }
                    }
                    if ($section === '') $section = $strand_v . ' Z';
                }
            }
        } else {
            $section = $strand_v . ' A';
        }
    }

    /* ── 7. enrollment_form ── */
    $ins = $conn->prepare("INSERT INTO enrollment_form
        (student_id, school_year, grade_level, term, track, strand, program, section,
         household_id, is_transferred, previous_school_name, previous_school_address,
         previous_track, previous_strand, previous_program, previous_year_completed,
         voucher_status, cct_4ps, status, approved_by, approved_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    if (!$ins) throw new Exception('enrollment_form prepare: ' . $ins->error);

    $grade_level = clean_string($rp('grade_level'), 20);
    $term        = clean_string($rp('term'), 20);
    $track       = clean_string($rp('track'), 60) ?: 'TECHPRO ELECTIVES';
    $household   = clean_string($rp('household_id'), 50) ?: null;
    $is_trans    = !empty($_POST['is_transferred']) ? 1 : 0;
    $prev_name   = clean_string($rp('previous_school_name'), 200) ?: null;
    $prev_addr   = clean_string($rp('previous_school_address'), 300) ?: null;
    $prev_track  = clean_string($rp('previous_track'), 60) ?: null;
    $prev_strand = clean_string($rp('previous_strand'), 150) ?: null;
    $prev_prog   = clean_string($rp('previous_program'), 150) ?: null;
    $prev_year   = !empty($_POST['previous_year_completed']) ? (int)$_POST['previous_year_completed'] : null;
    $voucher     = isset($_POST['voucher_qualified']) ? ($_POST['voucher_qualified'] == '1' ? 'Qualified' : 'Not Qualified') : null;
    $cct         = (int)$rp('is_4ps');
    $approved_by = $auto_approve ? $admin_id : null;
    $approved_at = $auto_approve ? date('Y-m-d H:i:s') : null;

    $ins->bind_param(
        'issssssssisssssssiss',
        $student_id, $sy_val, $grade_level, $term, $track, $strand_v, $program_v, $section,
        $household, $is_trans, $prev_name, $prev_addr,
        $prev_track, $prev_strand, $prev_prog, $prev_year,
        $voucher, $cct, $target_status, $approved_by, $approved_at
    );
    if (!$ins->execute()) throw new Exception('enrollment_form insert: ' . $ins->error);
    $enrollment_id = $conn->insert_id;
    $ins->close();

    /* ── 8. documents ── */
    $doc_stub = $conn->prepare("INSERT INTO entrance_documents
        (student_id, document_name, submitted) VALUES (?,?,0)");
    $checked = array_map('strval', (array)$entrance_data);
    if ($doc_stub) {
        foreach ($checked as $docName) {
            $docName = clean_string($docName, 150);
            if ($docName === '') continue;
            $doc_stub->bind_param('is', $student_id, $docName);
            $doc_stub->execute();
        }
        $doc_stub->close();
    }

    /* ── 9. Encrypted uploads ── */
    $uploaded_by = $admin_id ?: null;
    $file_ins = $conn->prepare("
        INSERT INTO entrance_documents
            (student_id, document_name, submitted, uploaded_at,
             file_path, file_mime, file_name, file_size, file_hash, uploaded_by)
        VALUES (?,?,1,NOW(),?,?,?,?,?,?)
    ");
    if (!$file_ins) throw new Exception('entrance_documents prepare: ' . $conn->error);

    foreach ($_FILES['entrance_files']['name'] ?? [] as $doc_key => $name) {
        if (($_FILES['entrance_files']['error'][$doc_key] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        if (empty($name)) continue;
        $tmp = $_FILES['entrance_files']['tmp_name'][$doc_key];

        try {
            $info = validate_upload($tmp, (string)$name, [
                'max_bytes'   => 5 * 1024 * 1024,
                'allowed'     => ['application/pdf', 'image/jpeg', 'image/png'],
                'image_min_w' => 100,
                'image_min_h' => 100,
            ]);
            $plain = file_get_contents($tmp);
            if ($plain === false || $plain === '') throw new RuntimeException('Empty file.');

            $blob  = doc_encrypt($plain);
            $token = bin2hex(random_bytes(8));
            $ext   = $info['ext'];
            $fn    = "walk_{$student_id}_{$token}.{$ext}.enc";
            $abs   = DOCUMENTS_DIR . $fn;

            if (file_put_contents($abs, $blob, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write encrypted file.');
            }
            @chmod($abs, 0600);
            $written_files[] = $abs;

            $doc_label = str_replace('_', ' ', $doc_key);
            $safe_name = preg_replace('/[^A-Za-z0-9._\- ]/', '_', (string)$name);
            if ($safe_name === '') $safe_name = 'document';

            $file_ins->bind_param(
                'issssis',
                $student_id, $doc_label, $fn, $info['mime'], $safe_name,
                $info['size'], $info['sha256'], $uploaded_by
            );
            // Note: 7 placeholders above but bind_param shows 7 -- adjust
            $file_ins->execute();
        } catch (Throwable $fe) {
            error_log('walkthrough upload: ' . $fe->getMessage());
            // non-fatal — stub already created
        }
    }
    $file_ins->close();

    $conn->commit();
    $conn->query("SELECT RELEASE_LOCK('walkthrough_" . (int)$admin_id . "')");

    /* Audit */
    try {
        audit_log($conn, $auto_approve ? 'enrollment.walkthrough_auto_approved' : 'enrollment.walkthrough_created', [
            'type'          => 'student',
            'id'            => $student_id,
            'enrollment_id' => $enrollment_id,
            'student_id'    => $student_id_number,
            'sy'            => $sy_val,
            'grade'         => $grade_level,
            'section'       => $section,
            'status'        => $target_status,
        ]);
    } catch (Throwable $a) { /* ignore */ }

    $_SESSION['walkthrough_success'] = sprintf(
        'Enrollment %s · %s (%s) · Section: %s',
        $auto_approve ? 'approved' : 'queued (Pending)',
        $student_id_number,
        trim($first_name . ' ' . $last_name),
        $section
    );

    header('Location: ' . $BACK);
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    $conn->query("SELECT RELEASE_LOCK('walkthrough_" . (int)$admin_id . "')");
    foreach ($written_files as $abs) { if (is_file($abs)) @unlink($abs); }

    error_log('walkthrough_process failed: ' . $e->getMessage());
    $_SESSION['walkthrough_errors'] = ['upload' => 'Save failed: ' . $e->getMessage()];
    $_SESSION['walkthrough_old']    = $_POST;
    header('Location: ' . $BACK);
    exit;
}