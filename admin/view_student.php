<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/audit.php';
require_once __DIR__ . '/../config/crypto.php';

// ==================== REQUIRED DOCUMENTS CONFIG ====================
$REQUIRED_DOCS = [
    'Good Moral Certificate',
    'Junior High School Certificate (Front)',
    'Junior High School Certificate (Back)',
    'NSO/PSA Birth Certificate (Original)',
    '2 pcs. 2×2 picture',
];

// ==================== HELPERS ====================
function getCurrentTerm(): string {
    $today = new DateTime();
    $month = (int)$today->format('n');
    $day   = (int)$today->format('j');

    if (($month === 6 && $day >= 8) || $month === 7 || $month === 8 || ($month === 9 && $day <= 15)) return '1st Term';
    if (($month === 9 && $day >= 16) || $month === 10 || $month === 11 || ($month === 12 && $day <= 18)) return '2nd Term';
    if (($month === 1 && $day >= 4) || $month === 2 || $month === 3 || ($month === 4 && $day <= 8)) return '3rd Term';
    return '1st Term';
}

function getTerms(): array {
    return ['1st Term', '2nd Term', '3rd Term'];
}

function docKey($s): string {
    $s = strtolower(trim((string)$s));
    $s = str_replace('.', '', $s);
    return preg_replace('/\s+/', ' ', $s);
}

/**
 * Build display name: LastName, FirstName MiddleName Ext.
 */
function formatStudentName(array $s): string {
    $mn  = !empty($s['middle_name']) ? ' ' . trim($s['middle_name']) : '';
    $ext = !empty($s['ext_name'])    ? ' ' . trim($s['ext_name']) : '';
    return trim(($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? '') . $mn . $ext);
}

function statSizeClass($val) {
    $len = mb_strlen((string)$val);
    if ($len > 30) return 'xs';
    if ($len > 14) return 'sm';
    return '';
}

/**
 * Derive remarks from a numeric grade. 75 is the passing threshold.
 */
function derive_remarks($grade): ?string {
    if ($grade === null || $grade === '') return null;
    if (!is_numeric($grade))          return null;
    return ((float)$grade >= 75) ? 'Passed' : 'Failed';
}

/**
 * Encrypt and store a document file.
 */
function store_encrypted_document(string $tmp_path, int $student_id, int $entrance_id): array {
    if (!is_file($tmp_path)) {
        throw new Exception('Uploaded file missing.');
    }
    $size = filesize($tmp_path);
    if ($size === false || $size === 0) throw new Exception('Empty file.');
    if ($size > 5 * 1024 * 1024)        throw new Exception('File too large (max 5 MB).');

    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $tmp_path);
    finfo_close($fi);

    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];
    if (!isset($allowed[$mime])) {
        throw new Exception('Unsupported file type. Allowed: PDF, JPG, PNG.');
    }

    $plain = file_get_contents($tmp_path);
    if ($plain === false || $plain === '') throw new Exception('Cannot read file.');

    $blob  = doc_encrypt($plain);
    $token = bin2hex(random_bytes(16));
    $stored_name = "doc_{$student_id}_{$entrance_id}_{$token}.{$allowed[$mime]}.enc";
    $abs = DOCUMENTS_DIR . $stored_name;

    if (file_put_contents($abs, $blob, LOCK_EX) === false) {
        throw new Exception('Storage write failed.');
    }
    @chmod($abs, 0600);

    return [
        'filename' => $stored_name,
        'mime'     => $mime,
        'size'     => $size,
    ];
}

// ==================== WORD EXPORT ====================
$export_word = isset($_GET['export_word']) && $_GET['export_word'] == 1 && isset($_GET['id']);

if ($export_word) {
    $student_id = (int)$_GET['id'];

    $stmt = $conn->prepare("
        SELECT s.*,
               p.father_name, p.father_occupation, p.father_contact,
               p.mother_maiden_name, p.mother_occupation, p.mother_contact,
               p.guardian_fullname, p.guardian_relation, p.guardian_contact,
               p.ave_family_income, p.is_4ps,
               a.purok_street, a.barangay, a.town_city, a.province, a.region, a.district, a.postal_code,
               e.school_year, e.grade_level, e.track, e.strand, e.program, e.section, e.term,
               e.status, e.voucher_status, e.household_id
        FROM students_info s
        LEFT JOIN parents_info p ON s.student_id = p.student_id
        LEFT JOIN addresses a ON s.student_id = a.student_id
        LEFT JOIN enrollment_form e ON s.student_id = e.student_id
        WHERE s.student_id = ?
        ORDER BY e.enrollment_id DESC LIMIT 1
    ");
    $stmt->bind_param("i", $student_id); $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$student) die("Student not found.");

    $enrollment = !empty($student['school_year']) ? $student : [];
    $parents = $student;
    $address = $student;

    $edu_stmt = $conn->prepare("SELECT level, school_name, school_address, year_completed FROM educational_history WHERE student_id = ? ORDER BY level");
    $edu_stmt->bind_param("i", $student_id); $edu_stmt->execute(); $education = $edu_stmt->get_result()->fetch_all(MYSQLI_ASSOC); $edu_stmt->close();

    $doc_stmt = $conn->prepare("SELECT document_name, submitted, file_path FROM entrance_documents WHERE student_id = ?");
    $doc_stmt->bind_param("i", $student_id); $doc_stmt->execute(); $documents = $doc_stmt->get_result()->fetch_all(MYSQLI_ASSOC); $doc_stmt->close();

    $grades_stmt = $conn->prepare("
        SELECT sg.term, sg.grade_level, sg.school_year,
               sg.subject_code, sg.subject_name, sg.grade, sg.remarks
        FROM student_grades sg
        WHERE sg.student_id = ?
        ORDER BY sg.school_year DESC, sg.grade_level ASC, sg.term ASC, sg.subject_name ASC
    ");
    $grades_stmt->bind_param("i", $student_id); $grades_stmt->execute();
    $grades = $grades_stmt->get_result()->fetch_all(MYSQLI_ASSOC); $grades_stmt->close();

    header("Content-Type: application/msword");
    header("Content-Disposition: attachment; filename=student_" . ($student['lrn'] ?? 'profile') . "_" . date('Y-m-d') . ".doc");
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Student Profile</title><style>body{font-family:Arial;margin:2cm}h1{color:#1e3c72;text-align:center}h2{color:#2b4c8c;margin-top:20px;border-bottom:2px solid #2b4c8c}table{width:100%;border-collapse:collapse;margin:15px 0}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f2f2f2;width:30%}</style></head><body>';
    echo '<h1>USAT College - Student Information</h1><p>Generated: '.date('F d, Y h:i A').'</p>';
    echo '<h2>Personal Info</h2><table>';
    $f=['Student ID Number'=>$student['student_id_number']??'—','LRN'=>$student['lrn']??'—','Name'=>formatStudentName($student),'Sex'=>$student['sex']??'—','Birth Date'=>$student['birth_date']??'—','Age'=>$student['age']??'—','Civil Status'=>$student['civil_status']??'—','Nationality'=>$student['nationality']??'—','Religion'=>$student['religion']??'—','Email'=>$student['email']??'—','Phone'=>$student['phone']??'—'];
    foreach($f as $l=>$v) echo "<tr><th>$l</th><td>".htmlspecialchars((string)$v)."</td></tr>";
    echo '</table>';
    if(!empty($enrollment)){echo '<h2>Enrollment</h2><table>';foreach(['school_year'=>'School Year','grade_level'=>'Grade Level','term'=>'Term','section'=>'Section','track'=>'Track','strand'=>'Strand','program'=>'Program','status'=>'Status','voucher_status'=>'Voucher'] as $k=>$l)echo "<tr><th>$l</th><td>".htmlspecialchars($enrollment[$k]??'—')."</td></tr>";echo '</table>';}
    if(!empty($grades)){
        echo '<h2>Academic Record</h2>';
        $t=0;$c=0;
        foreach($grades as $g){
            echo '<p><strong>'.htmlspecialchars(($g['school_year']??'—').' - '.($g['grade_level']??'—').' - '.($g['term']??'')).'</strong>: '.htmlspecialchars($g['subject_code']).' '.htmlspecialchars($g['subject_name']).' - '.($g['grade']!==null?number_format($g['grade'],2):'—').'</p>';
            if($g['grade']!==null&&is_numeric($g['grade'])){$t+=(float)$g['grade'];$c++;}
        }
        echo '<p><strong>Overall GWA: '.($c>0?number_format($t/$c,2):'N/A').'</strong></p>';
    }
    echo '</body></html>';
    exit;
}

// ====================== HANDLERS ======================

// --- Upload document (encrypted) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    verify_csrf();

    $eid = (int)($_POST['entrance_id'] ?? 0);
    $sid = (int)($_POST['student_id'] ?? 0);

    if ($eid <= 0 || $sid <= 0) {
        $_SESSION['error'] = 'Invalid document or student.';
        header("Location: view_student.php?id=$sid"); exit();
    }

    // Ownership check
    $chk = $conn->prepare("SELECT entrance_id, file_path FROM entrance_documents WHERE entrance_id = ? AND student_id = ? LIMIT 1");
    $chk->bind_param("ii", $eid, $sid);
    $chk->execute();
    $doc = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$doc) {
        $_SESSION['error'] = 'Document not found for this student.';
        header("Location: view_student.php?id=$sid"); exit();
    }

    $f = $_FILES['document_file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $_SESSION['error'] = 'No file uploaded.';
        header("Location: view_student.php?id=$sid"); exit();
    }

    try {
        $stored = store_encrypted_document($f['tmp_name'], $sid, $eid);

        // Delete previous encrypted file if any
        if (!empty($doc['file_path'])) {
            $old = DOCUMENTS_DIR . basename($doc['file_path']);
            if (is_file($old)) @unlink($old);
        }

        $safe_name = preg_replace('/[^A-Za-z0-9._\- ]/', '_', basename((string)$f['name']));
        if ($safe_name === '' || $safe_name === null) $safe_name = 'document';
        $safe_name = mb_substr($safe_name, 0, 200);

        $upd = $conn->prepare("
            UPDATE entrance_documents
            SET submitted = 1, file_path = ?, file_mime = ?, file_name = ?,
                file_data = NULL, uploaded_by = ?, uploaded_at = NOW()
            WHERE entrance_id = ? AND student_id = ?
        ");
        $upd->bind_param("sssiii",
            $stored['filename'], $stored['mime'], $safe_name,
            $_SESSION['admin_id'], $eid, $sid);
        $upd->execute();
        $upd->close();

        audit_log($conn, 'document.upload', [
            'type'       => 'document',
            'id'         => $eid,
            'student_id' => $sid,
        ]);

        $_SESSION['success'] = 'Document uploaded and encrypted.';
    } catch (Exception $e) {
        error_log('view_student upload: ' . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
    }

    header("Location: view_student.php?id=$sid"); exit();
}

// --- Delete document ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document'])) {
    verify_csrf();

    $eid = (int)($_POST['entrance_id'] ?? 0);
    $sid = (int)($_POST['student_id'] ?? 0);

    $chk = $conn->prepare("SELECT file_path FROM entrance_documents WHERE entrance_id = ? AND student_id = ?");
    $chk->bind_param("ii", $eid, $sid);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($row && !empty($row['file_path'])) {
        $abs  = DOCUMENTS_DIR . basename($row['file_path']);
        $root = realpath(DOCUMENTS_DIR);
        $real = realpath($abs);
        if ($real && $root && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            @unlink($real);
        }
    }

    $upd = $conn->prepare("
        UPDATE entrance_documents
        SET submitted = 0, file_path = NULL, file_mime = NULL, file_name = NULL,
            file_data = NULL, uploaded_by = NULL, uploaded_at = NULL
        WHERE entrance_id = ? AND student_id = ?
    ");
    $upd->bind_param("ii", $eid, $sid);
    $upd->execute();
    $upd->close();

    audit_log($conn, 'document.delete', [
        'type'       => 'document',
        'id'         => $eid,
        'student_id' => $sid,
    ]);

    $_SESSION['success'] = 'Document removed.';
    header("Location: view_student.php?id=$sid"); exit();
}

// --- Upload student photo ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo'])) {
    verify_csrf();

    $sid = (int)$_POST['student_id'];

    if (isset($_FILES['student_photo']) && $_FILES['student_photo']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['student_photo']['name'], PATHINFO_EXTENSION));
        $fi  = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $_FILES['student_photo']['tmp_name']);
        finfo_close($fi);

        if (in_array($mime, ['image/jpeg', 'image/png'], true)
            && $_FILES['student_photo']['size'] <= 2 * 1024 * 1024) {

            $dir = STUDENTS_PHOTO_DIR;
            if (!is_dir($dir)) mkdir($dir, 0750, true);

            // Delete old photo
            $oq = $conn->prepare("SELECT photo FROM students_info WHERE student_id = ?");
            $oq->bind_param("i", $sid);
            $oq->execute();
            $old = $oq->get_result()->fetch_assoc();
            $oq->close();
            if (!empty($old['photo'])) {
                $oabs = student_photo_abs_path($old['photo']);
                if (is_file($oabs)) @unlink($oabs);
            }

            $fn = "student_{$sid}_" . time() . ".{$ext}";
            if (move_uploaded_file($_FILES['student_photo']['tmp_name'], $dir . $fn)) {
                @chmod($dir . $fn, 0640);
                $s = $conn->prepare("UPDATE students_info SET photo = ? WHERE student_id = ?");
                $s->bind_param("si", $fn, $sid);
                $s->execute();
                $s->close();

                audit_log($conn, 'student.photo_update', [
                    'type'       => 'student',
                    'id'         => $sid,
                    'filename'   => $fn,
                ]);

                $_SESSION['success'] = 'Photo updated.';
            } else {
                $_SESSION['error'] = 'Photo save failed.';
            }
        } else {
            $_SESSION['error'] = 'JPG/PNG under 2 MB only.';
        }
    } else {
        $_SESSION['error'] = 'No file.';
    }
    header("Location: view_student.php?id=$sid"); exit();
}

// --- Delete student photo ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_photo'])) {
    verify_csrf();

    $sid = (int)($_POST['student_id'] ?? 0);

    if ($sid <= 0) {
        $_SESSION['error'] = 'Invalid student.';
        header("Location: student_profile.php"); exit();
    }

    $q = $conn->prepare("SELECT photo FROM students_info WHERE student_id = ?");
    $q->bind_param("i", $sid);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$row) {
        $_SESSION['error'] = 'Student not found.';
        header("Location: student_profile.php"); exit();
    }

    $old_photo = $row['photo'] ?? null;

    $u = $conn->prepare("UPDATE students_info SET photo = NULL WHERE student_id = ?");
    $u->bind_param("i", $sid);
    $u->execute();
    $u->close();

    if (!empty($old_photo)) {
        $abs  = student_photo_abs_path($old_photo);
        $root = realpath(STUDENTS_PHOTO_DIR);
        $real = $abs ? realpath($abs) : false;
        if ($real && $root && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            @unlink($real);
        }
    }

    audit_log($conn, 'student.photo_delete', [
        'type'     => 'student',
        'id'       => $sid,
        'filename' => $old_photo,
    ]);

    $_SESSION['success'] = 'Profile photo removed.';
    header("Location: view_student.php?id=$sid"); exit();
}

// --- Delete student (with file cleanup) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    verify_csrf();

    $sid = (int)$_POST['student_id'];

    // Collect file paths BEFORE deleting rows
    $files = [];
    $dq = $conn->prepare("SELECT file_path FROM entrance_documents WHERE student_id = ? AND file_path IS NOT NULL");
    $dq->bind_param("i", $sid);
    $dq->execute();
    $dres = $dq->get_result();
    while ($d = $dres->fetch_assoc()) {
        if (!empty($d['file_path'])) {
            $files[] = DOCUMENTS_DIR . basename($d['file_path']);
        }
    }
    $dq->close();

    // Photo path
    $pq = $conn->prepare("SELECT photo FROM students_info WHERE student_id = ?");
    $pq->bind_param("i", $sid);
    $pq->execute();
    $prow = $pq->get_result()->fetch_assoc();
    $pq->close();
    $photo_abs = !empty($prow['photo']) ? student_photo_abs_path($prow['photo']) : null;

    $conn->begin_transaction();
    try {
        foreach (['student_grades', 'entrance_documents', 'educational_history', 'addresses', 'parents_info', 'enrollment_form'] as $t) {
            $s = $conn->prepare("DELETE FROM `$t` WHERE student_id = ?");
            $s->bind_param("i", $sid);
            $s->execute();
            $s->close();
        }
        $s = $conn->prepare("DELETE FROM students_info WHERE student_id = ?");
        $s->bind_param("i", $sid);
        $s->execute();
        $s->close();
        $conn->commit();

        // Only unlink after commit succeeds
        $root = realpath(DOCUMENTS_DIR);
        foreach ($files as $abs) {
            $real = realpath($abs);
            if ($real && $root && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                @unlink($real);
            }
        }
        if ($photo_abs && is_file($photo_abs)) @unlink($photo_abs);

        audit_log($conn, 'student.delete', ['type' => 'student', 'id' => $sid]);
        $_SESSION['success'] = 'Student deleted.';
        header("Location: student_profile.php"); exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Delete failed.';
        header("Location: view_student.php?id=$sid"); exit();
    }
}

// --- Add grade ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_grade'])) {
    verify_csrf();

    $sid  = (int)$_POST['student_id'];
    $term = trim($_POST['term'] ?? '');
    $sc   = trim($_POST['subject_code'] ?? '');
    $sn   = trim($_POST['subject_name'] ?? '');
    $gr   = ($_POST['grade'] ?? '') !== '' ? (float)$_POST['grade'] : null;
    $rem  = trim($_POST['remarks'] ?? '');

    // Auto-derive remarks from the grade (75 = passing threshold).
    if ($gr !== null) {
        $rem = derive_remarks($gr);
    }

    if (!in_array($term, getTerms(), true)) {
        $_SESSION['error'] = 'Invalid term selected.';
        header("Location: view_student.php?id=$sid"); exit();
    }

    $es = $conn->prepare("SELECT enrollment_id, grade_level, school_year FROM enrollment_form WHERE student_id = ? ORDER BY enrollment_id DESC LIMIT 1");
    $es->bind_param("i", $sid);
    $es->execute();
    $er = $es->get_result()->fetch_assoc();
    $es->close();

    if (!$er) {
        $_SESSION['error'] = 'No enrollment record — enroll the student first.';
        header("Location: view_student.php?id=$sid"); exit();
    }

    if ($term === '' || $sc === '' || $sn === '') {
        $_SESSION['error'] = 'Fill required fields.';
    } else {
        $s = $conn->prepare("INSERT INTO student_grades
            (student_id, enrollment_id, term, grade_level, school_year, subject_code, subject_name, grade, remarks)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $s->bind_param("iisssssds",
            $sid, $er['enrollment_id'], $term,
            $er['grade_level'], $er['school_year'],
            $sc, $sn, $gr, $rem);

        if ($s->execute()) {
            audit_log($conn, 'grade.create', [
                'type'       => 'grade',
                'id'         => $conn->insert_id,
                'student_id' => $sid,
                'term'       => $term,
                'subject'    => $sc,
                'grade'      => $gr,
            ]);
            $_SESSION['success'] = 'Grade added.';
        } else {
            $_SESSION['error'] = 'Failed to add grade.';
        }
        $s->close();
    }
    header("Location: view_student.php?id=$sid"); exit();
}

// --- Edit grade ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_grade'])) {
    verify_csrf();

    $gid  = (int)$_POST['grade_id'];
    $sid  = (int)$_POST['student_id'];
    $term = trim($_POST['term'] ?? '');
    $sc   = trim($_POST['subject_code'] ?? '');
    $sn   = trim($_POST['subject_name'] ?? '');
    $gr   = ($_POST['grade'] ?? '') !== '' ? (float)$_POST['grade'] : null;
    $rem  = trim($_POST['remarks'] ?? '');

    if ($gr !== null) {
        $rem = derive_remarks($gr);
    }

    if (!in_array($term, getTerms(), true)) {
        $_SESSION['error'] = 'Invalid term selected.';
        header("Location: view_student.php?id=$sid"); exit();
    }

    $old_stmt = $conn->prepare("SELECT term, subject_code, subject_name, grade, remarks
                                FROM student_grades WHERE grade_id = ? AND student_id = ?");
    $old_stmt->bind_param("ii", $gid, $sid);
    $old_stmt->execute();
    $old_grade = $old_stmt->get_result()->fetch_assoc() ?: [];
    $old_stmt->close();

    $s = $conn->prepare("UPDATE student_grades SET term=?, subject_code=?, subject_name=?, grade=?, remarks=? WHERE grade_id=? AND student_id=?");
    $s->bind_param("sssdsii", $term, $sc, $sn, $gr, $rem, $gid, $sid);

    if ($s->execute()) {
        audit_diff($conn, 'grade.update', $old_grade, [
            'term'         => $term,
            'subject_code' => $sc,
            'subject_name' => $sn,
            'grade'        => $gr,
            'remarks'      => $rem,
        ], 'grade', $gid);
        $_SESSION['success'] = 'Grade updated.';
    } else {
        $_SESSION['error'] = 'Failed to update.';
    }
    $s->close();
    header("Location: view_student.php?id=$sid"); exit();
}

// --- Delete grade ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_grade'])) {
    verify_csrf();

    $gid = (int)$_POST['grade_id'];
    $sid = (int)$_POST['student_id'];

    $lookup = $conn->prepare("SELECT subject_code, subject_name, grade FROM student_grades WHERE grade_id = ? AND student_id = ?");
    $lookup->bind_param("ii", $gid, $sid);
    $lookup->execute();
    $deleted = $lookup->get_result()->fetch_assoc() ?: [];
    $lookup->close();

    $s = $conn->prepare("DELETE FROM student_grades WHERE grade_id = ? AND student_id = ?");
    $s->bind_param("ii", $gid, $sid);

    if ($s->execute()) {
        audit_log($conn, 'grade.delete', [
            'type'       => 'grade',
            'id'         => $gid,
            'student_id' => $sid,
            'subject'    => $deleted['subject_code'] ?? null,
            'name'       => $deleted['subject_name'] ?? null,
            'grade'      => $deleted['grade'] ?? null,
        ]);
        $_SESSION['success'] = 'Grade deleted.';
    } else {
        $_SESSION['error'] = 'Failed to delete.';
    }
    $s->close();
    header("Location: view_student.php?id=$sid"); exit();
}

// --- Add education ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_education'])) {
    verify_csrf();

    $sid  = (int)$_POST['student_id'];
    $lvl  = trim($_POST['level'] ?? '');
    $schn = trim($_POST['school_name'] ?? '');
    $scha = trim($_POST['school_address'] ?? '');
    $yr   = trim($_POST['year_completed'] ?? '');

    if ($lvl === '' || $schn === '') {
        $_SESSION['error'] = 'Required fields missing.';
    } else {
        $s = $conn->prepare("INSERT INTO educational_history (student_id, level, school_name, school_address, year_completed) VALUES (?, ?, ?, ?, ?)");
        $s->bind_param("issss", $sid, $lvl, $schn, $scha, $yr);

        if ($s->execute()) {
            audit_log($conn, 'education.create', [
                'type'       => 'education',
                'id'         => $conn->insert_id,
                'student_id' => $sid,
                'level'      => $lvl,
                'school'     => $schn,
            ]);
            $_SESSION['success'] = 'Education added.';
        } else {
            $_SESSION['error'] = 'Failed to add.';
        }
        $s->close();
    }
    header("Location: view_student.php?id=$sid"); exit();
}

// --- Edit education ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_education'])) {
    verify_csrf();

    $eid  = (int)$_POST['edu_id'];
    $sid  = (int)$_POST['student_id'];
    $lvl  = trim($_POST['level'] ?? '');
    $schn = trim($_POST['school_name'] ?? '');
    $scha = trim($_POST['school_address'] ?? '');
    $yr   = trim($_POST['year_completed'] ?? '');

    $old_stmt = $conn->prepare("SELECT level, school_name, school_address, year_completed
                                FROM educational_history WHERE edu_id = ? AND student_id = ?");
    $old_stmt->bind_param("ii", $eid, $sid);
    $old_stmt->execute();
    $old_edu = $old_stmt->get_result()->fetch_assoc() ?: [];
    $old_stmt->close();

    $s = $conn->prepare("UPDATE educational_history SET level=?, school_name=?, school_address=?, year_completed=? WHERE edu_id=? AND student_id=?");
    $s->bind_param("ssssii", $lvl, $schn, $scha, $yr, $eid, $sid);

    if ($s->execute()) {
        audit_diff($conn, 'education.update', $old_edu, [
            'level'          => $lvl,
            'school_name'    => $schn,
            'school_address' => $scha,
            'year_completed' => $yr,
        ], 'education', $eid);
        $_SESSION['success'] = 'Education updated.';
    } else {
        $_SESSION['error'] = 'Failed to update.';
    }
    $s->close();
    header("Location: view_student.php?id=$sid"); exit();
}

// --- Delete education ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_education'])) {
    verify_csrf();

    $eid = (int)$_POST['edu_id'];
    $sid = (int)$_POST['student_id'];

    $lookup = $conn->prepare("SELECT level, school_name FROM educational_history WHERE edu_id = ? AND student_id = ?");
    $lookup->bind_param("ii", $eid, $sid);
    $lookup->execute();
    $deleted_edu = $lookup->get_result()->fetch_assoc() ?: [];
    $lookup->close();

    $s = $conn->prepare("DELETE FROM educational_history WHERE edu_id = ? AND student_id = ?");
    $s->bind_param("ii", $eid, $sid);

    if ($s->execute()) {
        audit_log($conn, 'education.delete', [
            'type'       => 'education',
            'id'         => $eid,
            'student_id' => $sid,
            'level'      => $deleted_edu['level'] ?? null,
            'school'     => $deleted_edu['school_name'] ?? null,
        ]);
        $_SESSION['success'] = 'Education deleted.';
    } else {
        $_SESSION['error'] = 'Failed to delete.';
    }
    $s->close();
    header("Location: view_student.php?id=$sid"); exit();
}

// ====================== FETCH DATA ======================
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = 'No student ID.';
    header("Location: student_profile.php"); exit();
}
$student_id = (int)$_GET['id'];

// ---------------------------------------------------------------------------
// NOTE: `p.mother_name` was removed from this SELECT. It is never written by
// edit_student.php or process_enroll.php — only `mother_maiden_name` is. If
// your schema happens to define `mother_name`, no harm; if it doesn't, the
// previous query threw a fatal error on this page.
// ---------------------------------------------------------------------------
$s = $conn->prepare("
    SELECT s.*,
           p.father_name, p.father_occupation, p.father_contact,
           p.mother_maiden_name, p.mother_occupation, p.mother_contact,
           p.guardian_fullname, p.guardian_relation, p.guardian_contact,
           p.ave_family_income, p.is_4ps, p.household_id as parent_household_id,
           a.purok_street, a.barangay, a.town_city, a.province,
           a.region, a.district, a.postal_code,
           e.enrollment_id, e.grade_level, e.track, e.strand, e.program,
           e.section, e.school_year, e.term, e.status, e.voucher_status,
           e.household_id, e.is_transferred, e.previous_school_name,
           e.previous_school_address, e.previous_track, e.previous_strand,
           e.previous_program, e.previous_year_completed, e.cct_4ps
    FROM students_info s
    LEFT JOIN parents_info p ON s.student_id = p.student_id
    LEFT JOIN addresses a ON s.student_id = a.student_id
    LEFT JOIN enrollment_form e ON s.student_id = e.student_id
    WHERE s.student_id = ?
    ORDER BY e.enrollment_id DESC LIMIT 1
");
$s->bind_param("i", $student_id);
$s->execute();
$student = $s->get_result()->fetch_assoc();
$s->close();

if (!$student) {
    $_SESSION['error'] = 'Student not found.';
    header("Location: student_profile.php"); exit();
}

$enrollment = !empty($student['enrollment_id']) ? $student : [];
$parents = $student;
$address = $student;

// Auto-seed required documents (idempotent)
$check = $conn->prepare("SELECT entrance_id, document_name FROM entrance_documents WHERE student_id = ?");
$check->bind_param("i", $student_id);
$check->execute();
$existing_norm = [];
$cres = $check->get_result();
while ($c = $cres->fetch_assoc()) {
    $key = docKey($c['document_name']);
    if (!isset($existing_norm[$key])) {
        $existing_norm[$key] = (int)$c['entrance_id'];
    }
}
$check->close();

$insert = $conn->prepare("INSERT INTO entrance_documents (student_id, document_name, submitted) VALUES (?, ?, 0)");
foreach ($REQUIRED_DOCS as $docName) {
    if (!isset($existing_norm[docKey($docName)])) {
        $insert->bind_param("is", $student_id, $docName);
        $insert->execute();
    }
}
$insert->close();

// Education
$s = $conn->prepare("SELECT edu_id, level, school_name, school_address, year_completed FROM educational_history WHERE student_id = ? ORDER BY CASE level WHEN 'Elementary' THEN 1 WHEN 'JHS' THEN 2 ELSE 3 END");
$s->bind_param("i", $student_id);
$s->execute();
$education = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$s->close();

// Documents
$s = $conn->prepare("
    SELECT d.entrance_id, d.document_name, d.submitted, d.file_path,
           d.uploaded_at, d.file_name, d.file_mime,
           CASE WHEN d.file_data IS NOT NULL THEN 1 ELSE 0 END AS has_blob,
           LENGTH(d.file_data) AS blob_size,
           a.fullname AS uploader_name
    FROM entrance_documents d
    LEFT JOIN admins a ON d.uploaded_by = a.id
    WHERE d.student_id = ?
    ORDER BY
        (CASE WHEN d.file_data IS NOT NULL THEN 1 ELSE 0 END
       + CASE WHEN d.file_path IS NOT NULL AND d.file_path != '' THEN 1 ELSE 0 END) DESC,
        d.submitted DESC,
        d.uploaded_at DESC
");
$s->bind_param("i", $student_id);
$s->execute();
$raw_docs = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$s->close();

$best_by_norm = [];
foreach ($raw_docs as $d) {
    $key = docKey($d['document_name']);
    if (!isset($best_by_norm[$key])) {
        $best_by_norm[$key] = $d;
    }
}

$documents = [];
$used = [];
foreach ($REQUIRED_DOCS as $req) {
    $key = docKey($req);
    if (isset($best_by_norm[$key]) && !isset($used[$key])) {
        $documents[] = $best_by_norm[$key];
        $used[$key] = true;
    }
}
foreach ($raw_docs as $d) {
    $key = docKey($d['document_name']);
    if (!isset($used[$key]) && isset($best_by_norm[$key])) {
        $documents[] = $best_by_norm[$key];
        $used[$key] = true;
    }
}

$docs_total = count($documents);
$docs_ok = 0;
foreach ($documents as $d) {
    if (empty($d['submitted'])) continue;
    $hasBlob = !empty($d['has_blob']);
    $hasPath = !empty($d['file_path']) && is_file(document_abs_path($d['file_path']));
    if ($hasBlob || $hasPath) $docs_ok++;
}

// Grades
$grades_stmt = $conn->prepare("
    SELECT sg.grade_id, sg.term, sg.grade_level, sg.school_year,
           sg.subject_code, sg.subject_name, sg.grade, sg.remarks
    FROM student_grades sg
    WHERE sg.student_id = ?
    ORDER BY sg.school_year DESC, sg.grade_level ASC, sg.term ASC, sg.subject_name ASC
");
$grades_stmt->bind_param("i", $student_id);
$grades_stmt->execute();
$grades_result = $grades_stmt->get_result();

$academic_record = [];
$all_grades_flat = [];
while ($row = $grades_result->fetch_assoc()) {
    $sy = $row['school_year'] ?? 'Unknown SY';
    $gl = $row['grade_level'] ?? 'Unknown Grade';
    $tm = $row['term'] ?? 'Unknown Term';
    $academic_record[$sy][$gl][$tm][] = $row;
    $all_grades_flat[] = $row;
}
$grades_stmt->close();

$total_grade_points = 0; $grade_count = 0;
foreach ($all_grades_flat as $g) {
    if ($g['grade'] !== null && is_numeric($g['grade'])) {
        $total_grade_points += (float)$g['grade'];
        $grade_count++;
    }
}
$overall_gwa = $grade_count > 0 ? round($total_grade_points / $grade_count, 2) : null;

$gwa_per_year = [];
foreach ($academic_record as $sy => $levels) {
    $yt = 0; $yc = 0;
    foreach ($levels as $gl => $terms) {
        foreach ($terms as $tm => $subjects) {
            foreach ($subjects as $subj) {
                if ($subj['grade'] !== null && is_numeric($subj['grade'])) {
                    $yt += (float)$subj['grade'];
                    $yc++;
                }
            }
        }
    }
    $gwa_per_year[$sy] = $yc > 0 ? round($yt / $yc, 2) : null;
}

$term_gwa = [];
foreach ($academic_record as $sy => $levels) {
    foreach ($levels as $gl => $terms) {
        foreach ($terms as $tm => $subjects) {
            $tt = 0; $tc = 0;
            foreach ($subjects as $subj) {
                if ($subj['grade'] !== null && is_numeric($subj['grade'])) {
                    $tt += (float)$subj['grade'];
                    $tc++;
                }
            }
            $term_gwa[$sy][$gl][$tm] = $tc > 0 ? round($tt / $tc, 2) : null;
        }
    }
}

$stat_grade   = $enrollment['grade_level'] ?? '—';
$stat_section = $enrollment['section']     ?? '—';
$stat_strand  = $enrollment['strand']      ?? '—';
$stat_program = $enrollment['program']     ?? '';
$strand_fallback = false;
if ($stat_strand !== '' && $stat_section === $stat_strand && $stat_program !== '') {
    $stat_strand = $stat_program;
    $strand_fallback = true;
}

$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';
$initials = strtoupper(substr($student['first_name'] ?? '', 0, 1) . substr($student['last_name'] ?? '', 0, 1));

$photo_abs = !empty($student['photo']) ? student_photo_abs_path($student['photo']) : '';
// mtime cache-buster so the browser picks up a new image after upload/removal.
$photo = ($photo_abs && is_file($photo_abs))
    ? 'photo.php?id=' . (int)$student_id . '&v=' . filemtime($photo_abs)
    : '';

$fullName = htmlspecialchars(formatStudentName($student));

$currentTerm = getCurrentTerm();
$terms       = getTerms();
$csrf_token  = csrf_token();
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $fullName ?> • USAT Admin</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons — served from cdnjs because CSP font-src allows it -->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        /* ============================================================
           Design tokens (matches dashboard.php / student_profile.php)
           ============================================================ */
        :root {
            --bg: #f4f6fb;
            --surface: #ffffff;
            --surface-2: #f8fafc;
            --text: #0f172a;
            --text-2: #64748b;
            --text-3: #94a3b8;
            --border: #e5e7eb;
            --border-strong: #d1d5db;
            --accent: #4f46e5;
            --accent-2: #6366f1;
            --accent-soft: #eef2ff;
            --green: #059669;
            --green-soft: #d1fae5;
            --red: #dc2626;
            --red-soft: #fee2e2;
            --amber: #d97706;
            --amber-soft: #fef3c7;
            --purple: #7c3aed;
            --purple-soft: #ede9fe;
            --blue: #2563eb;
            --pink: #ec4899;

            --shadow-xs: 0 1px 2px rgba(15,23,42,0.04);
            --shadow-sm: 0 1px 3px rgba(15,23,42,0.06), 0 1px 2px rgba(15,23,42,0.04);
            --shadow-md: 0 4px 12px rgba(15,23,42,0.06);
            --shadow-lg: 0 12px 32px rgba(15,23,42,0.10);

            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;

            --sidebar-w: 264px;
        }

        [data-bs-theme="dark"] {
            --bg: #0b1220;
            --surface: #131c2e;
            --surface-2: #1a2439;
            --text: #f1f5f9;
            --text-2: #94a3b8;
            --text-3: #64748b;
            --border: #1f2a44;
            --border-strong: #2d3b57;
            --accent: #818cf8;
            --accent-2: #6366f1;
            --accent-soft: #1e2140;
            --green-soft: #052e24;
            --red-soft: #3f1517;
            --amber-soft: #3d2a08;
            --purple-soft: #2e1a54;

            --shadow-xs: 0 1px 2px rgba(0,0,0,0.3);
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.35);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 12px 32px rgba(0,0,0,0.55);
        }

        *{margin:0;padding:0;box-sizing:border-box}
        html,body{height:100%}
        body{
            font-family:'Inter',system-ui,-apple-system,sans-serif;
            background:var(--bg);color:var(--text);min-height:100vh;
            -webkit-font-smoothing:antialiased;
        }

        /* ============================================================
           Sidebar
           ============================================================ */
        .sidebar{
            position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-w);
            background:var(--surface);border-right:1px solid var(--border);
            z-index:200;display:flex;flex-direction:column;
            transition:transform .28s cubic-bezier(.4,0,.2,1);
        }
        .sidebar-brand{
            padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);
            display:flex;align-items:center;gap:.75rem;
        }
        .sidebar-brand img{
            width:38px;height:38px;border-radius:10px;object-fit:cover;
            box-shadow:0 0 0 3px var(--accent-soft);
        }
        .sidebar-brand .brand-text{display:flex;flex-direction:column;line-height:1.15}
        .sidebar-brand .brand-name{font-weight:700;font-size:.95rem;color:var(--text)}
        .sidebar-brand .brand-sub{font-size:.68rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;font-weight:600}

        .sidebar-nav{flex:1;padding:.75rem .75rem 1rem;overflow-y:auto}
        .sidebar-nav .nav-label{
            font-size:.65rem;font-weight:700;color:var(--text-3);
            text-transform:uppercase;letter-spacing:.8px;
            padding:.75rem .75rem .35rem;
        }
        .sidebar-nav a{
            display:flex;align-items:center;gap:.75rem;
            padding:.6rem .75rem;border-radius:10px;
            color:var(--text-2);text-decoration:none;
            font-weight:500;font-size:.875rem;
            transition:background .15s, color .15s;
            margin-bottom:.1rem;
        }
        .sidebar-nav a:hover{background:var(--surface-2);color:var(--text)}
        .sidebar-nav a.active{background:var(--accent);color:#fff;box-shadow:0 4px 12px -4px rgba(79,70,229,.5)}
        .sidebar-nav a.active:hover{background:var(--accent)}
        .sidebar-nav a i{font-size:1.05rem;width:20px;text-align:center;flex-shrink:0}
        .sidebar-footer{padding:.75rem;border-top:1px solid var(--border)}

        /* ============================================================
           Main content
           ============================================================ */
        .main-content{
            margin-left:var(--sidebar-w);
            padding:1.5rem clamp(1rem,2.5vw,2rem) 2rem;
            min-height:100vh;
            max-width:1600px;
        }
        .topbar{
            display:flex;align-items:center;justify-content:space-between;
            margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap;
        }
        .topbar h2{font-size:1.35rem;font-weight:700;letter-spacing:-.02em;margin:0}
        .topbar .sub{font-size:.85rem;color:var(--text-2);margin:0}
        .menu-toggle{
            display:none;width:40px;height:40px;border-radius:10px;
            border:1px solid var(--border);background:var(--surface);
            color:var(--text);cursor:pointer;
            align-items:center;justify-content:center;
            transition:background .15s;
        }
        .menu-toggle:hover{background:var(--surface-2)}
        .breadcrumb-nav{
            display:flex;align-items:center;gap:.4rem;
            font-size:.78rem;color:var(--text-2);
            margin-bottom:.25rem;
        }
        .breadcrumb-nav a{color:var(--accent);text-decoration:none;font-weight:500}
        .breadcrumb-nav a:hover{text-decoration:underline}

        /* ============================================================
           Cards
           ============================================================ */
        .card{
            background:var(--surface);border-radius:var(--radius-lg);
            border:1px solid var(--border);box-shadow:var(--shadow-sm);
            margin-bottom:1.25rem;overflow:hidden;
        }
        .card-header{
            padding:.9rem 1.25rem;border-bottom:1px solid var(--border);
            display:flex;align-items:center;justify-content:space-between;
            gap:1rem;flex-wrap:wrap;background:var(--surface);
        }
        .card-header h3{
            margin:0;font-size:.9rem;font-weight:600;
            display:flex;align-items:center;gap:.5rem;color:var(--text);
        }
        .card-header h3 i{color:var(--accent);font-size:1rem}
        .card-header .hint{font-size:.75rem;color:var(--text-2);font-weight:500}
        .card-body{padding:1.25rem}
        .card-body.no-padding{padding:0}

        /* ============================================================
           Hero (student header)
           ============================================================ */
        .hero{
            display:flex;gap:1.75rem;align-items:center;flex-wrap:wrap;
            padding:1.5rem 1.75rem;
        }
        .hero-avatar{position:relative;flex-shrink:0}
        .hero-avatar img,
        .hero-avatar .avatar-fallback{
            width:104px;height:104px;border-radius:50%;object-fit:cover;
            box-shadow:0 0 0 4px var(--surface), 0 0 0 6px var(--accent-soft);
        }
        .hero-avatar .avatar-fallback{
            background:linear-gradient(135deg,var(--accent),var(--accent-2));
            display:flex;align-items:center;justify-content:center;
            color:#fff;font-size:2.3rem;font-weight:800;letter-spacing:-.03em;
        }
        .hero-avatar .camera-btn{
            position:absolute;bottom:2px;right:2px;
            width:32px;height:32px;border-radius:50%;
            background:var(--surface);border:2px solid var(--border);
            cursor:pointer;display:flex;align-items:center;justify-content:center;
            color:var(--accent);transition:all .15s;
        }
        .hero-avatar .camera-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
        .hero-avatar .trash-btn{
            position:absolute;bottom:2px;left:2px;
            width:32px;height:32px;border-radius:50%;
            background:var(--surface);border:2px solid var(--border);
            cursor:pointer;display:flex;align-items:center;justify-content:center;
            color:var(--red);transition:all .15s;
        }
        .hero-avatar .trash-btn:hover{background:var(--red);color:#fff;border-color:var(--red)}

        .hero-info{flex:1;min-width:0}
        .hero-info h1{
            font-size:1.5rem;font-weight:700;letter-spacing:-.02em;
            margin:0 0 .6rem;word-break:break-word;
        }
        .hero-badges{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.75rem}

        /* ============================================================
           Pills / Badges
           ============================================================ */
        .pill{
            display:inline-flex;align-items:center;gap:.35rem;
            padding:.25rem .65rem;border-radius:999px;
            font-size:.72rem;font-weight:600;
            border:1px solid transparent;
            white-space:nowrap;
        }
        .pill i{font-size:.75rem}
        .pill.primary{background:var(--accent-soft);color:var(--accent);border-color:color-mix(in srgb, var(--accent) 22%, transparent)}
        .pill.success{background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb, var(--green) 22%, transparent)}
        .pill.info   {background:#dbeafe;color:#1e40af;border-color:rgba(30,64,175,.15)}
        .pill.warning{background:var(--amber-soft);color:var(--amber);border-color:color-mix(in srgb, var(--amber) 22%, transparent)}
        .pill.danger {background:var(--red-soft);color:var(--red);border-color:color-mix(in srgb, var(--red) 22%, transparent)}
        .pill.muted  {background:var(--surface-2);color:var(--text-2);border-color:var(--border)}
        [data-bs-theme="dark"] .pill.info{background:#1e3a5f;color:#93c5fd;border-color:rgba(147,197,253,.15)}

        .status-dot{
            display:inline-flex;align-items:center;gap:.4rem;
            font-size:.75rem;font-weight:600;
        }
        .status-dot::before{
            content:'';width:7px;height:7px;border-radius:50%;
            flex-shrink:0;background:currentColor;opacity:.9;
        }
        .status-dot.success{color:var(--green)}
        .status-dot.danger {color:var(--red)}
        .status-dot.warning{color:var(--amber)}

        /* ============================================================
           Stat grid
           ============================================================ */
        .stat-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
            gap:.85rem;
        }
        .stat-card{
            position:relative;
            background:var(--surface);border-radius:var(--radius);
            border:1px solid var(--border);box-shadow:var(--shadow-xs);
            padding:.95rem 1rem .95rem 1.15rem;
            overflow:hidden;
            transition:transform .15s, box-shadow .15s;
        }
        .stat-card::before{
            content:'';position:absolute;left:0;top:0;bottom:0;width:3px;
            background:var(--accent);
        }
        .stat-card.tone-green::before {background:var(--green)}
        .stat-card.tone-purple::before{background:var(--purple)}
        .stat-card.tone-amber::before {background:var(--amber)}
        .stat-card.tone-red::before   {background:var(--red)}
        .stat-card.tone-blue::before  {background:var(--accent)}
        .stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}

        .stat-value{
            font-size:1.25rem;font-weight:700;line-height:1.15;
            color:var(--text);letter-spacing:-.015em;
            word-break:break-word;font-variant-numeric:tabular-nums;
        }
        .stat-value.sm{font-size:1rem}
        .stat-value.xs{font-size:.82rem;font-weight:600}
        .stat-label{
            font-size:.68rem;color:var(--text-2);text-transform:uppercase;
            letter-spacing:.6px;margin-top:.25rem;font-weight:600;
        }

        /* ============================================================
           Info grid (key/value cells)
           ============================================================ */
        .info-grid{
            display:grid;
            grid-template-columns:repeat(auto-fill,minmax(210px,1fr));
            gap:0;
            border-top:1px solid var(--border);
            border-left:1px solid var(--border);
        }
        .info-cell{
            padding:.7rem 1rem;
            border-right:1px solid var(--border);
            border-bottom:1px solid var(--border);
            background:var(--surface);
        }
        .info-cell .label{
            font-size:.66rem;font-weight:700;color:var(--text-2);
            text-transform:uppercase;letter-spacing:.6px;
            margin-bottom:.2rem;
        }
        .info-cell .value{
            font-weight:500;font-size:.85rem;color:var(--text);
            word-break:break-word;
        }
        .info-cell .value.accent{color:var(--accent);font-weight:700}
        .info-cell.span-2{grid-column:span 2;background:var(--surface-2)}
        .info-cell.span-full{grid-column:1 / -1;background:var(--surface-2)}

        /* ============================================================
           Table
           ============================================================ */
        .table-scroll{overflow-x:auto}
        .table-admin{width:100%;border-collapse:separate;border-spacing:0}
        .table-admin thead th{
            background:var(--surface-2);
            font-size:.66rem;font-weight:700;color:var(--text-2);
            text-transform:uppercase;letter-spacing:.6px;
            padding:.65rem .9rem;text-align:left;
            border-bottom:1px solid var(--border);
            white-space:nowrap;
        }
        .table-admin tbody td{
            padding:.65rem .9rem;border-bottom:1px solid var(--border);
            font-size:.83rem;vertical-align:middle;
            transition:background .12s;
        }
        .table-admin tbody tr:hover td{background:color-mix(in srgb, var(--accent) 4%, transparent)}
        .table-admin tbody tr:last-child td{border-bottom:none}
        .cell-strong{font-weight:600;color:var(--text)}
        .cell-muted{color:var(--text-2);font-size:.82rem}
        .cell-accent{color:var(--accent);font-weight:600}
        .cell-num{
            font-variant-numeric:tabular-nums;font-weight:600;
            color:var(--text);font-size:.85rem;
        }
        .cell-right{text-align:right}

        /* ============================================================
           Document row — file metadata
           ============================================================ */
        .file-meta{
            font-size:.72rem;color:var(--text-2);
            display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;
            margin-top:.2rem;
        }
        .file-meta .dot{opacity:.5}
        .file-name{
            font-weight:600;font-size:.82rem;color:var(--text);
            display:flex;align-items:center;gap:.35rem;
            max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
        }
        .file-name i{color:var(--accent);flex-shrink:0}

        /* ============================================================
           Academic record
           ============================================================ */
        .sy-header{
            background:var(--surface-2);
            padding:.75rem 1.25rem;
            border-bottom:1px solid var(--border);
            display:flex;align-items:center;justify-content:space-between;
            flex-wrap:wrap;gap:.5rem;
        }
        .sy-header h6{
            margin:0;font-size:.85rem;font-weight:700;
            color:var(--accent);
            display:flex;align-items:center;gap:.5rem;
        }
        .grade-level-band{
            padding:.55rem 1.25rem;
            background:color-mix(in srgb, var(--accent) 5%, transparent);
            border-bottom:1px solid var(--border);
        }
        .grade-level-band h6{
            margin:0;font-size:.78rem;font-weight:700;
            color:var(--text);
            display:flex;align-items:center;gap:.5rem;
        }
        .grade-level-band i{color:var(--accent)}

        .term-block{
            margin:.75rem 1rem;
            padding:.75rem .85rem;
            border-radius:var(--radius);
            border-left:4px solid var(--accent);
            background:var(--accent-soft);
        }
        .term-block.term-1{background:var(--accent-soft);border-left-color:var(--accent)}
        .term-block.term-2{background:#dbeafe;border-left-color:#2563eb}
        .term-block.term-3{background:var(--purple-soft);border-left-color:var(--purple)}
        [data-bs-theme="dark"] .term-block.term-2{background:#1e3a5f}
        [data-bs-theme="dark"] .term-block.term-3{background:var(--purple-soft)}

        .term-head{
            display:flex;align-items:center;justify-content:space-between;
            margin-bottom:.6rem;flex-wrap:wrap;gap:.5rem;
        }
        .term-title{
            font-size:.78rem;font-weight:700;
            display:flex;align-items:center;gap:.4rem;
        }
        .term-block.term-1 .term-title{color:var(--accent)}
        .term-block.term-2 .term-title{color:#1e40af}
        .term-block.term-3 .term-title{color:var(--purple)}

        .term-gwa{
            font-size:.68rem;font-weight:600;
            background:var(--surface);
            padding:.2rem .55rem;border-radius:999px;
            border:1px solid var(--border);
        }
        .term-block.term-1 .term-gwa{color:var(--accent)}
        .term-block.term-2 .term-gwa{color:#1e40af}
        .term-block.term-3 .term-gwa{color:var(--purple)}

        .term-block .table-admin{
            background:var(--surface);
            border-radius:8px;overflow:hidden;
        }
        .term-block .table-admin th{
            font-size:.62rem;padding:.5rem .7rem;
        }
        .term-block .table-admin td{
            font-size:.78rem;padding:.5rem .7rem;
        }
        .grade-pass{color:var(--green);font-weight:700;font-variant-numeric:tabular-nums}
        .grade-fail{color:var(--red);font-weight:700;font-variant-numeric:tabular-nums}

        /* ============================================================
           Buttons
           ============================================================ */
        .btn{font-weight:500;border-radius:9px;font-size:.82rem;transition:all .15s}
        .btn-primary{background:var(--accent);border-color:var(--accent)}
        .btn-primary:hover{background:var(--accent-2);border-color:var(--accent-2)}
        .btn-outline-primary{color:var(--accent);border-color:var(--border-strong)}
        .btn-outline-primary:hover{background:var(--accent);border-color:var(--accent);color:#fff}
        .btn-outline-secondary{color:var(--text-2);border-color:var(--border)}
        .btn-outline-secondary:hover{background:var(--surface-2);color:var(--text);border-color:var(--border-strong)}
        .btn-outline-success{color:var(--green);border-color:color-mix(in srgb, var(--green) 35%, transparent)}
        .btn-outline-success:hover{background:var(--green);color:#fff;border-color:var(--green)}
        .btn-outline-warning{color:var(--amber);border-color:color-mix(in srgb, var(--amber) 40%, transparent)}
        .btn-outline-warning:hover{background:var(--amber);color:#fff;border-color:var(--amber)}
        .btn-outline-danger{color:var(--red);border-color:color-mix(in srgb, var(--red) 35%, transparent)}
        .btn-outline-danger:hover{background:var(--red);color:#fff;border-color:var(--red)}
        .btn-icon{
            width:32px;height:32px;padding:0;
            display:inline-flex;align-items:center;justify-content:center;
            border-radius:9px;
        }
        .btn-xs{padding:.25rem .6rem;font-size:.72rem;border-radius:8px}
        .theme-btn{
            width:38px;height:38px;border-radius:10px;
            border:1px solid var(--border);background:var(--surface);
            color:var(--text-2);cursor:pointer;
            display:flex;align-items:center;justify-content:center;
            transition:all .15s;
        }
        .theme-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
        .btn-group-actions{display:flex;gap:.35rem;flex-wrap:wrap}

        /* ============================================================
           Modals
           ============================================================ */
        .modal-content{border-radius:var(--radius-lg);border:none;box-shadow:var(--shadow-lg)}
        .modal-header{
            padding:1rem 1.25rem;border-bottom:1px solid var(--border);
            border-top-left-radius:var(--radius-lg);border-top-right-radius:var(--radius-lg);
        }
        .modal-header.bg-danger{background:var(--red) !important}
        .modal-header .modal-title{font-size:.92rem}
        .modal-body{padding:1.25rem}
        .modal-footer{padding:.85rem 1.25rem;border-top:1px solid var(--border)}
        .form-label{font-size:.78rem;font-weight:600;color:var(--text-2);margin-bottom:.35rem}
        .form-control,.form-select{
            background:var(--surface-2);border:1px solid var(--border);
            border-radius:10px;padding:.5rem .8rem;font-size:.85rem;
            color:var(--text);transition:border-color .15s, box-shadow .15s;
        }
        .form-control:focus,.form-select:focus{
            border-color:var(--accent);background:var(--surface);
            box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
        }
        .form-text{font-size:.72rem;color:var(--text-3)}

        /* Read-only auto-derived remarks field */
        .form-control[readonly]{
            background:var(--surface-2);
            color:var(--text-2);
            cursor:not-allowed;
        }

        /* ============================================================
           Grade modal — enhanced form
           ============================================================ */
        .modal-subtitle{
            font-size:.75rem;color:var(--text-2);font-weight:500;
            margin-top:.15rem;
        }
        .form-label .req{color:var(--red);font-weight:700;margin-left:.1rem}

        .grade-context{
            display:flex;align-items:center;gap:.75rem;
            padding:.65rem .85rem;
            background:var(--accent-soft);
            border:1px solid color-mix(in srgb, var(--accent) 18%, transparent);
            border-radius:var(--radius);
            margin-bottom:1rem;
        }
        .grade-context-icon{
            width:38px;height:38px;border-radius:50%;
            background:var(--surface);color:var(--accent);
            display:flex;align-items:center;justify-content:center;
            font-size:1.15rem;flex-shrink:0;
        }
        .grade-context-body{min-width:0;flex:1}
        .grade-context-name{
            font-weight:700;font-size:.88rem;color:var(--text);
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
        }
        .grade-context-meta{
            display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;
            font-size:.72rem;color:var(--text-2);font-weight:500;
            margin-top:.15rem;
        }
        .grade-context-meta i{color:var(--accent);font-size:.75rem;margin-right:.2rem}
        .grade-context-meta .dot{
            width:3px;height:3px;border-radius:50%;
            background:var(--text-3);opacity:.6;
        }

        /* Segmented term control */
        .term-segments{
            display:grid;
            grid-template-columns:repeat(3,1fr);
            gap:.3rem;
            background:var(--surface-2);
            padding:.3rem;
            border-radius:11px;
            border:1px solid var(--border);
        }
        .term-segments input[type=radio]{
            position:absolute;opacity:0;pointer-events:none;
        }
        .term-segments label{
            display:flex;align-items:center;justify-content:center;
            padding:.5rem .25rem;
            font-size:.78rem;font-weight:600;
            color:var(--text-2);
            border-radius:8px;
            cursor:pointer;
            transition:background .15s, color .15s, box-shadow .15s;
            user-select:none;text-align:center;
            margin:0;
        }
        .term-segments label:hover{
            color:var(--text);
            background:color-mix(in srgb, var(--accent) 8%, transparent);
        }
        .term-segments input[type=radio]:checked + label{
            background:var(--accent);color:#fff;
            box-shadow:0 2px 8px -2px rgba(79,70,229,.5);
        }
        .term-segments input[type=radio]:focus-visible + label{
            outline:2px solid var(--accent);outline-offset:2px;
        }

        /* Grade input with live pass/fail feedback */
        .grade-input-wrap{position:relative}
        .grade-input-wrap .form-control{
            padding-right:78px;
            font-size:1.02rem;font-weight:700;
            font-variant-numeric:tabular-nums;
            letter-spacing:-.01em;
            transition:border-color .15s, box-shadow .15s;
        }
        .grade-input-wrap .grade-badge{
            position:absolute;right:8px;top:50%;
            transform:translateY(-50%);
            font-size:.68rem;font-weight:700;
            padding:.2rem .55rem;border-radius:999px;
            pointer-events:none;
            display:none;
            text-transform:uppercase;letter-spacing:.4px;
        }
        .grade-input-wrap.has-value .grade-badge{display:inline-block}
        .grade-input-wrap.is-pass .form-control{
            border-color:var(--green);
            box-shadow:0 0 0 3px color-mix(in srgb, var(--green) 16%, transparent);
        }
        .grade-input-wrap.is-pass .grade-badge{
            background:var(--green-soft);color:var(--green);
        }
        .grade-input-wrap.is-fail .form-control{
            border-color:var(--red);
            box-shadow:0 0 0 3px color-mix(in srgb, var(--red) 16%, transparent);
        }
        .grade-input-wrap.is-fail .grade-badge{
            background:var(--red-soft);color:var(--red);
        }

        /* ============================================================
           Empty state
           ============================================================ */
        .empty{
            padding:2.5rem 1.5rem;text-align:center;color:var(--text-2);
        }
        .empty .empty-icon{
            width:56px;height:56px;border-radius:50%;
            background:var(--surface-2);color:var(--text-3);
            display:inline-flex;align-items:center;justify-content:center;
            font-size:1.4rem;margin-bottom:.85rem;
        }
        .empty .empty-title{font-weight:600;color:var(--text);margin-bottom:.2rem}
        .empty .empty-sub{font-size:.82rem;color:var(--text-2)}

        /* ============================================================
           Flash alerts
           ============================================================ */
        .flash{
            display:flex;align-items:center;gap:.65rem;
            padding:.75rem 1rem;border-radius:var(--radius);
            margin-bottom:1rem;font-size:.88rem;font-weight:500;
            border:1px solid transparent;
        }
        .flash.success{background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb, var(--green) 25%, transparent)}
        .flash.error  {background:var(--red-soft);  color:var(--red);  border-color:color-mix(in srgb, var(--red) 25%, transparent)}
        .flash .btn-close{margin-left:auto;opacity:.6}

        /* ============================================================
           Responsive
           ============================================================ */
        @media (max-width:1024px){
            .sidebar{transform:translateX(-100%)}
            .sidebar.open{transform:translateX(0)}
            .main-content{margin-left:0}
            .menu-toggle{display:flex}
            .hero{flex-direction:column;text-align:center;padding:1.5rem 1rem}
            .hero-badges{justify-content:center}
            .hero-info h1{font-size:1.3rem}
        }
        @media (max-width:640px){
            .main-content{padding:1rem}
            .stat-grid{grid-template-columns:repeat(2,1fr)}
            .info-grid{grid-template-columns:1fr}
            .info-cell.span-2{grid-column:span 1}
            .term-block{margin:.5rem .5rem}
            .grade-level-band{padding:.55rem .75rem}
            .sy-header{padding:.65rem .75rem}
        }
    </style>
</head>
<body>

<?php
    $__current = basename($_SERVER['PHP_SELF']);
    $__super   = (($_SESSION['admin_role'] ?? '') === 'superadmin');
    // Student sub-pages all highlight "Students"
    $__student_pages = ['student_profile.php','view_student.php','edit_student.php','export_students.php','sections_list.php','sf10.php'];
    $__act = in_array($__current, $__student_pages, true) ? 'student_profile.php' : $__current;
    $__a   = fn($f) => $f === $__act ? ' class="active"' : '';
?>
<!-- ================= Sidebar ================= -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="../assets/img/usat.jpg" alt="USAT" onerror="this.style.background='var(--accent)'">
        <div>
            <div class="brand-name">USAT Admin</div>
            <div class="brand-sub">Enrollment System</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Overview</div>
        <a href="dashboard.php"<?= $__a('dashboard.php') ?>><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="student_profile.php"<?= $__a('student_profile.php') ?>><i class="bi bi-people-fill"></i> Students</a>
        <?php
    $__pending_count = (int)($conn->query("SELECT COUNT(*) c FROM enrollment_form WHERE status='Pending'")->fetch_assoc()['c'] ?? 0);
?>
<a href="pending_enrollments.php"<?= $__a('pending_enrollments.php') ?>>
    <i class="bi bi-hourglass-split"></i> Pending
    <?php if ($__pending_count > 0): ?>
        <span style="margin-left:auto;background:#d97706;color:#fff;font-size:.68rem;padding:.15rem .5rem;border-radius:999px;font-weight:700;line-height:1">
            <?= $__pending_count ?>
        </span>
    <?php endif; ?>
</a>
        <a href="reports.php"<?= $__a('reports.php') ?>><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>

        <?php if ($__super): ?>
        <div class="nav-label" style="margin-top:.5rem">Administration</div>
        <a href="create_account.php"<?= $__a('create_account.php') ?>><i class="bi bi-person-plus"></i> Accounts</a>
        <a href="audit_log.php"<?= $__a('audit_log.php') ?>><i class="bi bi-shield-check"></i> Audit Log</a>
        <?php endif; ?>

        <div class="nav-label" style="margin-top:.5rem">Account</div>
        <a href="profile.php"<?= $__a('profile.php') ?>><i class="bi bi-person-gear"></i> My Profile</a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php" class="btn btn-outline-danger btn-sm w-100">
            <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
    </div>
</aside>

<div class="main-content" id="mainContent">

    <!-- ================= Flash alerts ================= -->
    <?php if(isset($_SESSION['success'])): ?>
        <div class="flash success">
            <i class="bi bi-check-circle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['success']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <div class="flash error">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['error']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

    <!-- ================= Topbar ================= -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <div class="breadcrumb-nav">
                    <a href="student_profile.php">Students</a>
                    <i class="bi bi-chevron-right" style="font-size:.7rem;opacity:.6"></i>
                    <span>Profile</span>
                </div>
                <h2>Student Profile</h2>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="sf10.php?id=<?= (int)$student_id ?>" class="btn btn-outline-primary btn-sm" target="_blank">
                <i class="bi bi-file-earmark-text me-1"></i> SF10
            </a>
            <a href="export_students.php?student_id=<?= (int)$student_id ?>" class="btn btn-outline-success btn-sm">
                <i class="bi bi-download me-1"></i> Export
            </a>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print
            </button>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <!-- ================= Hero ================= -->
    <div class="card">
        <div class="hero">
            <div class="hero-avatar">
                <?php if($photo): ?>
                    <img src="<?= $photo ?>" alt="Photo">
                    <button class="trash-btn" data-bs-toggle="modal" data-bs-target="#deletePhotoModal" title="Remove photo">
                        <i class="bi bi-trash3-fill" style="font-size:.72rem"></i>
                    </button>
                <?php else: ?>
                    <div class="avatar-fallback"><?= htmlspecialchars($initials) ?></div>
                <?php endif; ?>
                <button class="camera-btn" data-bs-toggle="modal" data-bs-target="#uploadPhotoModal" title="Change photo">
                    <i class="bi bi-camera-fill" style="font-size:.75rem"></i>
                </button>
            </div>
            <div class="hero-info">
                <h1><?= $fullName ?></h1>
                <div class="hero-badges">
                    <span class="pill primary">
                        <i class="bi bi-upc-scan"></i>
                        LRN: <?= htmlspecialchars($student['lrn'] ?? 'N/A') ?>
                    </span>
                    <span class="pill info">
                        <i class="bi bi-person-badge"></i>
                        <?= htmlspecialchars($student['student_id_number'] ?? 'No ID') ?>
                    </span>
                    <?php if($overall_gwa !== null): ?>
                        <span class="pill success">
                            <i class="bi bi-star-fill"></i>
                            GWA: <?= number_format($overall_gwa, 2) ?>
                        </span>
                    <?php endif; ?>
                    <span class="pill <?= ($enrollment['status'] ?? 'Active') === 'Active' ? 'success' : 'warning' ?>">
                        <?= htmlspecialchars($enrollment['status'] ?? 'Active') ?>
                    </span>
                </div>
                <div class="btn-group-actions">
                    <a href="edit_student.php?id=<?= (int)$student_id ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pencil-square me-1"></i> Edit
                    </a>
                    <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteStudentModal">
                        <i class="bi bi-trash3 me-1"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= Stat grid ================= -->
    <div class="stat-grid">
        <div class="stat-card tone-blue">
            <div class="stat-value <?= statSizeClass($stat_grade) ?>" title="<?= htmlspecialchars($stat_grade) ?>"><?= htmlspecialchars($stat_grade) ?></div>
            <div class="stat-label">Grade</div>
        </div>
        <div class="stat-card tone-purple">
            <div class="stat-value <?= statSizeClass($stat_section) ?>" title="<?= htmlspecialchars($stat_section) ?>"><?= htmlspecialchars($stat_section) ?></div>
            <div class="stat-label">Section</div>
        </div>
        <div class="stat-card tone-green">
            <div class="stat-value <?= statSizeClass($stat_strand) ?>" title="<?= htmlspecialchars($stat_strand) ?>"><?= htmlspecialchars($stat_strand) ?></div>
            <div class="stat-label"><?= $strand_fallback ? 'Program' : 'Strand' ?></div>
        </div>
        <div class="stat-card tone-amber">
            <div class="stat-value"><?= htmlspecialchars($student['age'] ?? '—') ?></div>
            <div class="stat-label">Age</div>
        </div>
        <div class="stat-card tone-blue">
            <div class="stat-value"><?= htmlspecialchars($student['sex'] ?? '—') ?></div>
            <div class="stat-label">Sex</div>
        </div>
        <div class="stat-card tone-green">
            <div class="stat-value"><?= $overall_gwa !== null ? number_format($overall_gwa, 2) : '—' ?></div>
            <div class="stat-label">GWA</div>
        </div>
    </div>

    <!-- ================= Personal + Enrollment + Address ================= -->
    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <h3><i class="bi bi-person-vcard"></i> Personal Information</h3>
                </div>
                <div class="card-body no-padding">
                    <div class="info-grid">
                        <div class="info-cell"><div class="label">Birth Date</div><div class="value"><?= htmlspecialchars($student['birth_date'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Civil Status</div><div class="value"><?= htmlspecialchars($student['civil_status'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Nationality</div><div class="value"><?= htmlspecialchars($student['nationality'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Religion</div><div class="value"><?= htmlspecialchars($student['religion'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Height</div><div class="value"><?= htmlspecialchars($student['height'] ?? '—') ?> cm</div></div>
                        <div class="info-cell"><div class="label">Weight</div><div class="value"><?= htmlspecialchars($student['weight'] ?? '—') ?> kg</div></div>
                        <div class="info-cell"><div class="label">Email</div><div class="value"><?= htmlspecialchars($student['email'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Phone</div><div class="value"><?= htmlspecialchars($student['phone'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Nickname</div><div class="value"><?= htmlspecialchars($student['nick_name'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Extension</div><div class="value"><?= htmlspecialchars($student['ext_name'] ?? '—') ?></div></div>
                        <div class="info-cell span-2"><div class="label">Special Skills</div><div class="value"><?= nl2br(htmlspecialchars($student['special_skills'] ?? 'None')) ?></div></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h3><i class="bi bi-mortarboard"></i> Enrollment Details</h3>
                </div>
                <div class="card-body no-padding">
                    <?php if(!empty($enrollment)): ?>
                    <div class="info-grid">
                        <div class="info-cell"><div class="label">School Year</div><div class="value"><?= htmlspecialchars($enrollment['school_year'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Grade Level</div><div class="value"><?= htmlspecialchars($enrollment['grade_level'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Term</div><div class="value"><?= htmlspecialchars($enrollment['term'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Section</div><div class="value accent"><?= htmlspecialchars($enrollment['section'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Track</div><div class="value"><?= htmlspecialchars($enrollment['track'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Strand</div><div class="value"><?= htmlspecialchars($enrollment['strand'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Program</div><div class="value"><?= htmlspecialchars($enrollment['program'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Voucher Status</div><div class="value"><?= htmlspecialchars($enrollment['voucher_status'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">4Ps ID</div><div class="value"><?= htmlspecialchars($enrollment['household_id'] ?? '—') ?></div></div>
                        <div class="info-cell">
                            <div class="label">Status</div>
                            <div class="value">
                                <span class="status-dot <?= ($enrollment['status'] ?? 'Active') === 'Active' ? 'success' : 'warning' ?>">
                                    <?= htmlspecialchars($enrollment['status'] ?? 'Active') ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                        <div class="empty">
                            <div class="empty-icon"><i class="bi bi-mortarboard"></i></div>
                            <div class="empty-title">No enrollment record</div>
                            <div class="empty-sub">This student hasn't been enrolled yet.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card mt-3">
                <div class="card-header">
                    <h3><i class="bi bi-geo-alt"></i> Address</h3>
                </div>
                <div class="card-body no-padding">
                    <?php if(!empty($address)): ?>
                    <div class="info-grid">
                        <div class="info-cell"><div class="label">Purok/Street</div><div class="value"><?= htmlspecialchars($address['purok_street'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Barangay</div><div class="value"><?= htmlspecialchars($address['barangay'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Town/City</div><div class="value"><?= htmlspecialchars($address['town_city'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Province</div><div class="value"><?= htmlspecialchars($address['province'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">Region</div><div class="value"><?= htmlspecialchars($address['region'] ?? '—') ?></div></div>
                        <div class="info-cell"><div class="label">District</div><div class="value"><?= htmlspecialchars($address['district'] ?? '—') ?></div></div>
                        <div class="info-cell span-2"><div class="label">Postal Code</div><div class="value"><?= htmlspecialchars($address['postal_code'] ?? '—') ?></div></div>
                    </div>
                    <?php else: ?>
                        <div class="empty">
                            <div class="empty-icon"><i class="bi bi-geo-alt"></i></div>
                            <div class="empty-title">No address</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= Parents & Guardian ================= -->
    <div class="card mt-3">
        <div class="card-header">
            <h3><i class="bi bi-people"></i> Parents &amp; Guardian</h3>
        </div>
        <div class="card-body no-padding">
            <?php if(!empty($parents)): ?>
            <div class="info-grid">
                <div class="info-cell"><div class="label">Father Name</div><div class="value"><?= htmlspecialchars($parents['father_name'] ?? '—') ?></div></div>
                <div class="info-cell"><div class="label">Father Occupation</div><div class="value"><?= htmlspecialchars($parents['father_occupation'] ?? '—') ?></div></div>
                <div class="info-cell"><div class="label">Father Contact</div><div class="value"><?= htmlspecialchars($parents['father_contact'] ?? '—') ?></div></div>
                <div class="info-cell"><div class="label">Mother (Maiden)</div><div class="value"><?= htmlspecialchars($parents['mother_maiden_name'] ?? '—') ?></div></div>
                <div class="info-cell"><div class="label">Mother Occupation</div><div class="value"><?= htmlspecialchars($parents['mother_occupation'] ?? '—') ?></div></div>
                <div class="info-cell"><div class="label">Mother Contact</div><div class="value"><?= htmlspecialchars($parents['mother_contact'] ?? '—') ?></div></div>
                <div class="info-cell"><div class="label">Family Income</div><div class="value" style="font-weight:700"><?= $parents['ave_family_income'] ? '₱' . number_format($parents['ave_family_income'], 2) : '—' ?></div></div>
                <div class="info-cell">
                    <div class="label">4Ps</div>
                    <div class="value">
                        <span class="status-dot <?= !empty($parents['is_4ps']) ? 'success' : 'danger' ?>">
                            <?= !empty($parents['is_4ps']) ? 'Yes' : 'No' ?>
                        </span>
                    </div>
                </div>
                <div class="info-cell"><div class="label">Household ID</div><div class="value"><?= htmlspecialchars($parents['parent_household_id'] ?? $parents['household_id'] ?? '—') ?></div></div>
                <div class="info-cell span-full">
                    <div class="label">Guardian</div>
                    <div class="value">
                        <?= htmlspecialchars($parents['guardian_fullname'] ?? '—') ?>
                        <?php if (!empty($parents['guardian_relation'])): ?>
                            <span style="color:var(--text-2);font-weight:500"> (<?= htmlspecialchars($parents['guardian_relation']) ?>)</span>
                        <?php endif; ?>
                        <?php if (!empty($parents['guardian_contact'])): ?>
                            <span style="color:var(--text-3)"> — </span><?= htmlspecialchars($parents['guardian_contact']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-people"></i></div>
                    <div class="empty-title">No parent/guardian data</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================= Required Documents ================= -->
    <div class="card mt-3" id="documents">
        <div class="card-header">
            <h3>
                <i class="bi bi-file-earmark-check"></i> Required Documents
                <?php if($docs_total > 0): ?>
                    <span class="pill <?= $docs_ok === $docs_total ? 'success' : 'primary' ?>" style="margin-left:.5rem">
                        <?= $docs_ok ?> / <?= $docs_total ?> Submitted
                    </span>
                <?php endif; ?>
            </h3>
        </div>
        <div class="card-body no-padding">
            <?php if(!empty($documents)): ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th>Document</th>
                                <th style="width:120px">Status</th>
                                <th>File</th>
                                <th style="width:170px;text-align:right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($documents as $d):
                            $hasBlob  = !empty($d['has_blob']);
                            $hasPath  = !empty($d['file_path']) && file_exists(document_abs_path($d['file_path']));
                            $ok       = !empty($d['submitted']) && ($hasBlob || $hasPath);

                            if ($hasPath) { $size = filesize(document_abs_path($d['file_path'])); }
                            elseif ($hasBlob) { $size = (int)$d['blob_size']; }
                            else { $size = 0; }

                            if (!empty($d['file_name'])) { $fname = $d['file_name']; }
                            elseif ($hasPath) { $fname = basename($d['file_path']); }
                            else { $fname = 'document'; }

                            $probe    = $hasPath ? $d['file_path'] : $fname;
                            $isImage  = $ok && preg_match('/\.(jpe?g|png|gif|webp)(\.enc)?$/i', $probe);
                            $isPdf    = $ok && preg_match('/\.pdf(\.enc)?$/i', $probe);
                            $source   = $hasBlob ? 'in database' : ($hasPath ? 'encrypted on disk' : '');
                        ?>
                            <tr>
                                <td>
                                    <div class="file-name">
                                        <i class="bi bi-file-earmark-text"></i>
                                        <?= htmlspecialchars($d['document_name']) ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if($ok): ?>
                                        <span class="status-dot success">Submitted</span>
                                    <?php elseif(!empty($d['submitted']) && !$ok): ?>
                                        <span class="status-dot warning" title="Record exists but the file is missing">File lost</span>
                                    <?php else: ?>
                                        <span class="status-dot danger">Missing</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($ok): ?>
                                        <div class="file-name" title="<?= htmlspecialchars($fname) ?>">
                                            <?php if($isImage): ?><i class="bi bi-file-earmark-image"></i>
                                            <?php elseif($isPdf): ?><i class="bi bi-file-earmark-pdf"></i>
                                            <?php else: ?><i class="bi bi-file-earmark"></i><?php endif; ?>
                                            <?= htmlspecialchars($fname) ?>
                                        </div>
                                        <div class="file-meta">
                                            <span><?= number_format($size / 1024, 1) ?> KB</span>
                                            <?php if(!empty($d['uploaded_at'])): ?><span class="dot">·</span><span><?= date('M d, Y g:i A', strtotime($d['uploaded_at'])) ?></span><?php endif; ?>
                                            <?php if(!empty($d['uploader_name'])): ?><span class="dot">·</span><span>by <?= htmlspecialchars($d['uploader_name']) ?></span><?php endif; ?>
                                            <?php if($source): ?><span class="dot">·</span><span><?= $source ?></span><?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="cell-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right">
                                    <?php if($ok): ?>
                                        <div class="btn-group-actions" style="justify-content:flex-end">
                                            <a href="view_document.php?id=<?= (int)$d['entrance_id'] ?>"
                                               class="btn btn-icon btn-outline-primary" target="_blank" title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="view_document.php?id=<?= (int)$d['entrance_id'] ?>&dl=1"
                                               class="btn btn-icon btn-outline-secondary" title="Download">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <button class="btn btn-icon btn-outline-danger delete-doc-btn"
                                                    data-eid="<?= (int)$d['entrance_id'] ?>"
                                                    data-name="<?= htmlspecialchars($d['document_name']) ?>"
                                                    title="Delete">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <button class="btn btn-primary btn-xs upload-doc-btn"
                                                data-eid="<?= (int)$d['entrance_id'] ?>"
                                                data-name="<?= htmlspecialchars($d['document_name']) ?>">
                                            <i class="bi bi-upload me-1"></i> Upload
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-file-earmark"></i></div>
                    <div class="empty-title">No documents configured</div>
                    <div class="empty-sub">Documents are created automatically when this page loads.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================= Educational History ================= -->
    <div class="card mt-3">
        <div class="card-header">
            <h3><i class="bi bi-book"></i> Educational History</h3>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addEducationModal">
                <i class="bi bi-plus-lg me-1"></i> Add
            </button>
        </div>
        <div class="card-body no-padding">
            <?php if(!empty($education)): ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th>Level</th>
                                <th>School Name</th>
                                <th>Address</th>
                                <th style="width:90px">Year</th>
                                <th style="width:100px;text-align:right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($education as $e): ?>
                            <tr>
                                <td class="cell-strong"><?= htmlspecialchars($e['level']) ?></td>
                                <td><?= htmlspecialchars($e['school_name']) ?></td>
                                <td class="cell-muted"><?= htmlspecialchars($e['school_address'] ?? '—') ?></td>
                                <td class="cell-muted"><?= htmlspecialchars($e['year_completed'] ?? '—') ?></td>
                                <td style="text-align:right">
                                    <div class="btn-group-actions" style="justify-content:flex-end">
                                        <button class="btn btn-icon btn-outline-warning edit-edu-btn"
                                                data-bs-toggle="modal" data-bs-target="#editEducationModal"
                                                data-id="<?= (int)$e['edu_id'] ?>"
                                                data-lvl="<?= htmlspecialchars($e['level']) ?>"
                                                data-sch="<?= htmlspecialchars($e['school_name']) ?>"
                                                data-adr="<?= htmlspecialchars($e['school_address'] ?? '') ?>"
                                                data-yr="<?= htmlspecialchars($e['year_completed'] ?? '') ?>"
                                                title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-icon btn-outline-danger del-edu-btn"
                                                data-id="<?= (int)$e['edu_id'] ?>"
                                                data-sch="<?= htmlspecialchars($e['school_name']) ?>"
                                                title="Delete">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-book"></i></div>
                    <div class="empty-title">No records</div>
                    <div class="empty-sub">Add educational background for this student.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ================= Academic Record & Grades ================= -->
    <div class="card mt-3">
        <div class="card-header">
            <h3><i class="bi bi-journal-text"></i> Academic Record &amp; Grades</h3>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <?php if($overall_gwa !== null): ?>
                    <span class="pill primary">
                        Overall GWA: <strong style="margin-left:.2rem"><?= number_format($overall_gwa, 2) ?></strong>
                    </span>
                <?php endif; ?>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addGradeModal">
                    <i class="bi bi-plus-lg me-1"></i> Add Grade
                </button>
            </div>
        </div>
        <div class="card-body no-padding">
            <?php if(!empty($academic_record)): ?>
                <?php
                $termClass = [
                    '1st Term'     => 'term-1',
                    '2nd Term'     => 'term-2',
                    '3rd Term'     => 'term-3',
                    'Unknown Term' => '',
                ];
                foreach($academic_record as $school_year => $grade_levels): ?>
                <div>
                    <div class="sy-header">
                        <h6><i class="bi bi-calendar-range"></i> School Year <?= htmlspecialchars($school_year) ?></h6>
                        <?php if(isset($gwa_per_year[$school_year])): ?>
                            <span class="pill primary">SY GWA: <?= number_format($gwa_per_year[$school_year], 2) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php foreach($grade_levels as $grade_level => $terms_in_level): ?>
                        <div class="grade-level-band">
                            <h6><i class="bi bi-mortarboard"></i> <?= htmlspecialchars($grade_level) ?></h6>
                        </div>

                        <?php foreach($terms_in_level as $term => $subjects):
                            $tg = $term_gwa[$school_year][$grade_level][$term] ?? null;
                            $tc = $termClass[$term] ?? '';
                        ?>
                        <div class="term-block <?= $tc ?>">
                            <div class="term-head">
                                <span class="term-title">
                                    <i class="bi bi-caret-right-fill"></i>
                                    <?= htmlspecialchars($term) ?>
                                    <span style="color:var(--text-2);font-weight:500;margin-left:.25rem">
                                        (<?= count($subjects) ?>)
                                    </span>
                                </span>
                                <?php if($tg !== null): ?>
                                    <span class="term-gwa">Term GWA: <?= number_format($tg, 2) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="table-scroll">
                                <table class="table-admin">
                                    <thead>
                                        <tr>
                                            <th style="width:90px">Code</th>
                                            <th>Subject</th>
                                            <th style="width:80px">Grade</th>
                                            <th style="width:120px">Remarks</th>
                                            <th style="width:90px;text-align:right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach($subjects as $g): ?>
                                        <tr>
                                            <td class="cell-strong"><?= htmlspecialchars($g['subject_code']) ?></td>
                                            <td><?= htmlspecialchars($g['subject_name']) ?></td>
                                            <td class="<?= ($g['grade'] ?? 0) >= 75 ? 'grade-pass' : 'grade-fail' ?>">
                                                <?= $g['grade'] !== null ? number_format($g['grade'], 2) : '—' ?>
                                            </td>
                                            <td class="cell-muted"><?= htmlspecialchars($g['remarks'] ?? '—') ?></td>
                                            <td style="text-align:right">
                                                <div class="btn-group-actions" style="justify-content:flex-end">
                                                    <button class="btn btn-icon btn-outline-warning edit-grd-btn"
                                                            data-bs-toggle="modal" data-bs-target="#editGradeModal"
                                                            data-id="<?= (int)$g['grade_id'] ?>"
                                                            data-term="<?= htmlspecialchars($g['term']) ?>"
                                                            data-code="<?= htmlspecialchars($g['subject_code']) ?>"
                                                            data-name="<?= htmlspecialchars($g['subject_name']) ?>"
                                                            data-grd="<?= $g['grade'] ?? '' ?>"
                                                            data-rem="<?= htmlspecialchars($g['remarks'] ?? '') ?>"
                                                            title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn btn-icon btn-outline-danger del-grd-btn"
                                                            data-id="<?= (int)$g['grade_id'] ?>"
                                                            data-name="<?= htmlspecialchars($g['subject_name']) ?>"
                                                            title="Delete">
                                                        <i class="bi bi-trash3"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-journal-text"></i></div>
                    <div class="empty-title">No grades recorded yet</div>
                    <div class="empty-sub">Add the student's first grade to begin tracking.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);backdrop-filter:blur(2px);z-index:199"
     onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<!-- ================= MODALS ================= -->

<!-- Upload photo -->
<div class="modal fade" id="uploadPhotoModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="bi bi-camera me-2"></i>Change Photo</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="upload_photo" value="1">
                    <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
                    <input type="file" name="student_photo" class="form-control" accept="image/*" required>
                    <div class="form-text mt-2">JPG or PNG · Max 2 MB</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary btn-sm">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Remove photo -->
<div class="modal fade" id="deletePhotoModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title fw-bold">
                    <i class="bi bi-trash3 me-2"></i>Remove Photo
                </h6>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    Remove the profile photo for <strong><?= $fullName ?></strong>?
                    This cannot be undone.
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="delete_photo" value="1">
                    <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
                    <button class="btn btn-danger btn-sm">Remove</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Upload document -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="bi bi-cloud-upload me-2"></i>Upload Document</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="docForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="upload_document" value="1">
                    <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
                    <input type="hidden" name="entrance_id" id="m_eid">
                    <p class="fw-semibold small mb-2" id="m_dname" style="color:var(--text)"></p>
                    <input type="file" name="document_file" id="m_file" class="form-control" required>
                    <div id="m_msg" class="mt-2"></div>
                    <div class="form-text mt-2">Max 5 MB · PDF, JPG, PNG · Encrypted at rest</div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary btn-sm" id="btnUpDoc">Upload</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete document -->
<div class="modal fade" id="deleteDocModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Delete Document</h6>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Delete <strong id="del_dname"></strong>? This cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="delete_document" value="1">
                    <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
                    <input type="hidden" name="entrance_id" id="del_eid">
                    <button class="btn btn-danger btn-sm">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete student -->
<div class="modal fade" id="deleteStudentModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Delete Student</h6>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Permanently delete <strong><?= $fullName ?></strong> and all related records? This cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="delete_student" value="1">
                    <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
                    <button class="btn btn-danger btn-sm">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Add grade -->
<div class="modal fade" id="addGradeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h6 class="modal-title fw-bold">
                        <i class="bi bi-plus-circle me-2"></i>Add Grade
                    </h6>
                    <div class="modal-subtitle">Record a new subject grade for this student</div>
                </div>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addGradeForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">

                    <div class="grade-context">
                        <div class="grade-context-icon"><i class="bi bi-person-circle"></i></div>
                        <div class="grade-context-body">
                            <div class="grade-context-name"><?= $fullName ?></div>
                            <div class="grade-context-meta">
                                <span><i class="bi bi-mortarboard"></i><?= htmlspecialchars($enrollment['grade_level'] ?? '—') ?></span>
                                <span class="dot"></span>
                                <span><i class="bi bi-calendar3"></i><?= htmlspecialchars($enrollment['school_year'] ?? '—') ?></span>
                                <span class="dot"></span>
                                <span><i class="bi bi-upc-scan"></i><?= htmlspecialchars($student['lrn'] ?? 'N/A') ?></span>
                            </div>
                        </div>
                    </div>

                    <label class="form-label">Term <span class="req">*</span></label>
                    <div class="term-segments">
                        <?php foreach($terms as $i => $term): ?>
                            <input type="radio" name="term" id="ag_term_<?= $i ?>"
                                   value="<?= htmlspecialchars($term) ?>"
                                   <?= $currentTerm === $term ? 'checked' : '' ?> required>
                            <label for="ag_term_<?= $i ?>"><?= htmlspecialchars($term) ?></label>
                        <?php endforeach; ?>
                    </div>

                    <div class="row g-2 mt-1">
                        <div class="col-sm-4">
                            <label class="form-label">Subject Code <span class="req">*</span></label>
                            <input name="subject_code" class="form-control form-control-sm"
                                   placeholder="e.g. GEN-MATH" required maxlength="20" autocomplete="off">
                        </div>
                        <div class="col-sm-8">
                            <label class="form-label">Subject Name <span class="req">*</span></label>
                            <input name="subject_name" class="form-control form-control-sm"
                                   placeholder="e.g. General Mathematics" required maxlength="100" autocomplete="off">
                        </div>
                    </div>

                    <div class="row g-2 mt-1">
                        <div class="col-sm-5">
                            <label class="form-label">Grade <span class="req">*</span></label>
                            <div class="grade-input-wrap" id="ag_grp">
                                <input type="number" step="0.01" min="0" max="100"
                                       name="grade" id="ag_grd"
                                       class="form-control form-control-sm"
                                       placeholder="0 – 100"
                                       oninput="autoRemarks(this, 'ag_rem', 'ag_grp')" required>
                                <span class="grade-badge" id="ag_badge"></span>
                            </div>
                        </div>
                        <div class="col-sm-7">
                            <label class="form-label">Remarks</label>
                            <input name="remarks" id="ag_rem"
                                   class="form-control form-control-sm"
                                   placeholder="Auto-derived from grade" readonly>
                        </div>
                    </div>

                    <div class="form-text mt-2 d-flex align-items-center gap-1">
                        <i class="bi bi-info-circle"></i>
                        <span>Passing mark is <strong>75</strong>. Remarks are set automatically.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button name="add_grade" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2 me-1"></i> Save Grade
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit grade -->
<div class="modal fade" id="editGradeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h6 class="modal-title fw-bold">
                        <i class="bi bi-pencil me-2"></i>Edit Grade
                    </h6>
                    <div class="modal-subtitle">Update this subject's details</div>
                </div>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editGradeForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="grade_id" id="eg_id">
                    <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">

                    <div class="grade-context">
                        <div class="grade-context-icon"><i class="bi bi-person-circle"></i></div>
                        <div class="grade-context-body">
                            <div class="grade-context-name"><?= $fullName ?></div>
                            <div class="grade-context-meta">
                                <span><i class="bi bi-mortarboard"></i><?= htmlspecialchars($enrollment['grade_level'] ?? '—') ?></span>
                                <span class="dot"></span>
                                <span><i class="bi bi-calendar3"></i><?= htmlspecialchars($enrollment['school_year'] ?? '—') ?></span>
                                <span class="dot"></span>
                                <span><i class="bi bi-upc-scan"></i><?= htmlspecialchars($student['lrn'] ?? 'N/A') ?></span>
                            </div>
                        </div>
                    </div>

                    <label class="form-label">Term <span class="req">*</span></label>
                    <div class="term-segments" id="eg_terms">
                        <?php foreach($terms as $i => $term): ?>
                            <input type="radio" name="term" id="eg_term_<?= $i ?>"
                                   value="<?= htmlspecialchars($term) ?>" required>
                            <label for="eg_term_<?= $i ?>"><?= htmlspecialchars($term) ?></label>
                        <?php endforeach; ?>
                    </div>

                    <div class="row g-2 mt-1">
                        <div class="col-sm-4">
                            <label class="form-label">Subject Code <span class="req">*</span></label>
                            <input name="subject_code" id="eg_code"
                                   class="form-control form-control-sm" required maxlength="20">
                        </div>
                        <div class="col-sm-8">
                            <label class="form-label">Subject Name <span class="req">*</span></label>
                            <input name="subject_name" id="eg_name"
                                   class="form-control form-control-sm" required maxlength="100">
                        </div>
                    </div>

                    <div class="row g-2 mt-1">
                        <div class="col-sm-5">
                            <label class="form-label">Grade <span class="req">*</span></label>
                            <div class="grade-input-wrap" id="eg_grp">
                                <input type="number" step="0.01" min="0" max="100"
                                       name="grade" id="eg_grd"
                                       class="form-control form-control-sm"
                                       oninput="autoRemarks(this, 'eg_rem', 'eg_grp')" required>
                                <span class="grade-badge" id="eg_badge"></span>
                            </div>
                        </div>
                        <div class="col-sm-7">
                            <label class="form-label">Remarks</label>
                            <input name="remarks" id="eg_rem"
                                   class="form-control form-control-sm" readonly>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button name="edit_grade" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2 me-1"></i> Update Grade
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete grade -->
<div class="modal fade" id="deleteGradeModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title fw-bold"><i class="bi bi-trash3 me-2"></i>Delete Grade</h6>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Delete <strong id="dg_name"></strong>?</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="grade_id" id="dg_id">
                    <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
                    <button name="delete_grade" class="btn btn-danger btn-sm">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Add education -->
<div class="modal fade" id="addEducationModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Add Education</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
                    <input name="level" class="form-control form-control-sm mb-2" placeholder="Level" required>
                    <input name="school_name" class="form-control form-control-sm mb-2" placeholder="School Name" required>
                    <input name="school_address" class="form-control form-control-sm mb-2" placeholder="Address">
                    <input name="year_completed" class="form-control form-control-sm" placeholder="Year">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button name="add_education" class="btn btn-primary btn-sm">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit education -->
<div class="modal fade" id="editEducationModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Edit Education</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="edu_id" id="ee_id">
                    <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
                    <input name="level" id="ee_lvl" class="form-control form-control-sm mb-2" required>
                    <input name="school_name" id="ee_sch" class="form-control form-control-sm mb-2" required>
                    <input name="school_address" id="ee_adr" class="form-control form-control-sm mb-2">
                    <input name="year_completed" id="ee_yr" class="form-control form-control-sm">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button name="edit_education" class="btn btn-primary btn-sm">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete education -->
<div class="modal fade" id="deleteEducationModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title fw-bold"><i class="bi bi-trash3 me-2"></i>Delete Education</h6>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Delete <strong id="de_sch"></strong>?</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="edu_id" id="de_id">
                    <input type="hidden" name="student_id" value="<?= (int)$student_id ?>">
                    <button name="delete_education" class="btn btn-danger btn-sm">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
// ---------- Sidebar ----------
const sb = document.getElementById('sidebar');
const ov = document.getElementById('sidebarOverlay');
document.getElementById('menuToggle').addEventListener('click', () => {
    sb.classList.toggle('open');
    ov.style.display = sb.classList.contains('open') ? 'block' : 'none';
});

// ---------- Theme ----------
const tb = document.getElementById('themeToggle');
const ti = document.getElementById('themeIcon');
const h  = document.documentElement;
function st(t) {
    h.setAttribute('data-bs-theme', t);
    ti.className = 'bi bi-' + (t === 'dark' ? 'sun-fill' : 'moon-stars-fill');
    document.cookie = 'admin_theme=' + t + ';path=/;max-age=' + (60*60*24*365);
}
(function () {
    const m = document.cookie.match(/(?:^|; )admin_theme=([^;]+)/);
    st(m ? decodeURIComponent(m[1]) : 'light');
})();
tb.addEventListener('click', () => st(h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));

// ---------- Document modals ----------
$('.upload-doc-btn').click(function () {
    $('#m_eid').val($(this).data('eid'));
    $('#m_dname').text($(this).data('name'));
    $('#m_msg').html('');
    $('#m_file').val('');
    $('#btnUpDoc').prop('disabled', false).html('Upload');
    $('#uploadDocumentModal').modal('show');
});

$('.delete-doc-btn').click(function () {
    $('#del_eid').val($(this).data('eid'));
    $('#del_dname').text($(this).data('name'));
    $('#deleteDocModal').modal('show');
});

$('#btnUpDoc').click(function () {
    var f = new FormData($('#docForm')[0]);
    $.ajax({
        url: 'view_student.php',
        type: 'POST',
        data: f,
        contentType: false,
        processData: false,
        beforeSend: function () {
            $('#btnUpDoc').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
        },
        success: function () { location.reload(); },
        error: function () {
            $('#m_msg').html('<div class="flash error py-1 small mt-2" style="margin:0">Upload failed.</div>');
            $('#btnUpDoc').prop('disabled', false).html('Upload');
        }
    });
});

// ---------- Grade modals ----------
const PASSING_GRADE = 75;

// Live-derive remarks AND drive the pass/fail visual state on the input wrapper.
function autoRemarks(gradeEl, remarksId, wrapperId) {
    const el = document.getElementById(remarksId);
    const wrap = wrapperId ? document.getElementById(wrapperId) : null;
    const badge = wrap ? wrap.querySelector('.grade-badge') : null;
    if (!el) return;

    const v = (gradeEl.value || '').trim();
    const clear = () => {
        el.value = '';
        if (wrap) wrap.classList.remove('has-value', 'is-pass', 'is-fail');
        if (badge) badge.textContent = '';
    };

    if (v === '') return clear();
    const g = parseFloat(v);
    if (isNaN(g)) return clear();

    const passed = g >= PASSING_GRADE;
    el.value = passed ? 'Passed' : 'Failed';

    if (wrap) {
        wrap.classList.add('has-value');
        wrap.classList.toggle('is-pass', passed);
        wrap.classList.toggle('is-fail', !passed);
    }
    if (badge) badge.textContent = passed ? 'Passed' : 'Failed';
}

// Edit grade: prefill fields, select the matching term radio, recompute remarks.
$('.edit-grd-btn').click(function () {
    var b = $(this);
    $('#eg_id').val(b.data('id'));
    $('#eg_code').val(b.data('code'));
    $('#eg_name').val(b.data('name'));
    $('#eg_grd').val(b.data('grd'));
    $('#eg_rem').val(b.data('rem'));

    // Select the correct term in the segmented control.
    var term = b.data('term');
    $('#eg_terms input[type=radio]').each(function () {
        $(this).prop('checked', $(this).val() === term);
    });

    // Re-derive from the grade itself — never trust the stored remark.
    autoRemarks(document.getElementById('eg_grd'), 'eg_rem', 'eg_grp');
});

// Add grade: when the modal opens, sync the visual state to whatever
// is currently in the grade field.
$('#addGradeModal').on('shown.bs.modal', function () {
    const g = document.getElementById('ag_grd');
    if (g) autoRemarks(g, 'ag_rem', 'ag_grp');
});

$('.del-grd-btn').click(function () {
    var b = $(this);
    $('#dg_id').val(b.data('id'));
    $('#dg_name').text(b.data('name'));
    $('#deleteGradeModal').modal('show');
});

// ---------- Education modals ----------
$('.edit-edu-btn').click(function () {
    var b = $(this);
    $('#ee_id').val(b.data('id'));
    $('#ee_lvl').val(b.data('lvl'));
    $('#ee_sch').val(b.data('sch'));
    $('#ee_adr').val(b.data('adr'));
    $('#ee_yr').val(b.data('yr'));
});

$('.del-edu-btn').click(function () {
    var b = $(this);
    $('#de_id').val(b.data('id'));
    $('#de_sch').text(b.data('sch'));
    $('#deleteEducationModal').modal('show');
});
</script>
</body>
</html>