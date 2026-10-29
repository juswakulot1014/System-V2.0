<?php
// shared/pages/view_student.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/crypto.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../lib/teacher_scope.php'; 

$__nav_section = 'students';

/* ── Role gate (Allows teacher) ── */
if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin', 'teacher'], true)) {
    http_response_code(403);
    security_log('view_student.forbidden', [
        'admin_id' => (int)($_SESSION['admin_id'] ?? 0),
        'ip'       => client_ip(),
    ]);
    exit('Forbidden');
}

$is_teacher = ($_SESSION['admin_role'] ?? '') === 'teacher';

/* ═══════════════════════════════════════════════════════════
   REQUIRED DOCUMENTS CONFIG
   ═══════════════════════════════════════════════════════════ */
$REQUIRED_DOCS = [
    'Good Moral Certificate',
    'Junior High School Certificate (Front)',
    'Junior High School Certificate (Back)',
    'NSO/PSA Birth Certificate (Original)',
    '2 pcs. 2×2 picture',
];

/* ═══════════════════════════════════════════════════════════
   HELPERS (guarded)
   ═══════════════════════════════════════════════════════════ */
if (!function_exists('getCurrentTerm')) {
    function getCurrentTerm(): string {
        $today = new DateTime();
        $month = (int)$today->format('n');
        $day   = (int)$today->format('j');

        if (($month === 6 && $day >= 8) || $month === 7 || $month === 8 || ($month === 9 && $day <= 15)) return '1st Term';
        if (($month === 9 && $day >= 16) || $month === 10 || $month === 11 || ($month === 12 && $day <= 18)) return '2nd Term';
        if (($month === 1 && $day >= 4) || $month === 2 || $month === 3 || ($month === 4 && $day <= 8)) return '3rd Term';
        return '1st Term';
    }
}

if (!function_exists('getTerms')) {
    function getTerms(): array {
        return ['1st Term', '2nd Term', '3rd Term'];
    }
}

if (!function_exists('docKey')) {
    function docKey($s): string {
        $s = strtolower(trim((string)$s));
        $s = str_replace('.', '', $s);
        return preg_replace('/\s+/', ' ', $s);
    }
}

if (!function_exists('formatStudentName')) {
    function formatStudentName(array $s): string {
        $mn  = !empty($s['middle_name']) ? ' ' . trim($s['middle_name']) : '';
        $ext = !empty($s['ext_name'])    ? ' ' . trim($s['ext_name']) : '';
        return trim(($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? '') . $mn . $ext);
    }
}

if (!function_exists('statSizeClass')) {
    function statSizeClass($val) {
        $len = mb_strlen((string)$val);
        if ($len > 30) return 'xs';
        if ($len > 14) return 'sm';
        return '';
    }
}

if (!function_exists('derive_remarks')) {
    function derive_remarks($grade): ?string {
        if ($grade === null || $grade === '') return null;
        if (!is_numeric($grade))          return null;
        return ((float)$grade >= 75) ? 'Passed' : 'Failed';
    }
}

if (!function_exists('store_encrypted_document')) {
    function store_encrypted_document(string $tmp_path, string $orig_name, int $student_id, int $entrance_id): array {
        $info = validate_upload($tmp_path, $orig_name, [
            'max_bytes'   => 5 * 1024 * 1024,
            'allowed'     => ['application/pdf', 'image/jpeg', 'image/png'],
            'image_min_w' => 150,
            'image_min_h' => 150,
        ]);

        $plain = file_get_contents($tmp_path);
        if ($plain === false || $plain === '') throw new Exception('Cannot read file.');

        $blob  = doc_encrypt($plain);
        $token = bin2hex(random_bytes(16));
        $stored_name = "doc_{$student_id}_{$entrance_id}_{$token}.{$info['ext']}.enc";
        
        // FIXED: Safe directory separator
        $abs = rtrim(DOCUMENTS_DIR, '/\\') . DIRECTORY_SEPARATOR . $stored_name;

        if (file_put_contents($abs, $blob, LOCK_EX) === false) {
            throw new Exception('Storage write failed.');
        }
        @chmod($abs, 0600);

        return [
            'filename' => $stored_name,
            'mime'     => $info['mime'],
            'size'     => $info['size'],
        ];
    }
}

/* ═══════════════════════════════════════════════════════════
   WORD EXPORT (unchanged logic)
   ═══════════════════════════════════════════════════════════ */
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

    $edu_stmt = $conn->prepare("SELECT level, school_name, school_address, year_completed FROM educational_history WHERE student_id = ? ORDER BY level");
    $edu_stmt->bind_param("i", $student_id); $edu_stmt->execute();
    $education = $edu_stmt->get_result()->fetch_all(MYSQLI_ASSOC); $edu_stmt->close();

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

/* ═══════════════════════════════════════════════════════════
   POST HANDLER — Upload document (encrypted)
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    if ($is_teacher) { $_SESSION['error'] = 'Teachers cannot upload documents.'; header("Location: " . URL_PREFIX . "/view_student.php?id=" . (int)$_POST['student_id']); exit(); }
    verify_csrf();

    $eid = (int)($_POST['entrance_id'] ?? 0);
    $sid = (int)($_POST['student_id'] ?? 0);

    if ($eid <= 0 || $sid <= 0) {
        $_SESSION['error'] = 'Invalid document or student.';
        header("Location: " . URL_PREFIX . "/view_student.php?id=$sid"); exit();
    }

    $chk = $conn->prepare("SELECT entrance_id, file_path FROM entrance_documents WHERE entrance_id = ? AND student_id = ? LIMIT 1");
    $chk->bind_param("ii", $eid, $sid);
    $chk->execute();
    $doc = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$doc) {
        $_SESSION['error'] = 'Document not found for this student.';
        header("Location: " . URL_PREFIX . "/view_student.php?id=$sid"); exit();
    }

    $f = $_FILES['document_file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        $_SESSION['error'] = 'No file uploaded.';
        header("Location: " . URL_PREFIX . "/view_student.php?id=$sid"); exit();
    }

    try {
        $stored = store_encrypted_document($f['tmp_name'], $f['name'], $sid, $eid);

        if (!empty($doc['file_path'])) {
            try {
                $old = safe_path(DOCUMENTS_DIR, (string)$doc['file_path']);
                if (is_file($old)) @unlink($old);
            } catch (RuntimeException $e) {
                security_log('view_student.old_file_containment', [
                    'candidate' => (string)$doc['file_path'],
                    'student'   => $sid,
                ]);
            }
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

    header("Location: " . URL_PREFIX . "/view_student.php?id=$sid"); exit();
}

/* ═══════════════════════════════════════════════════════════
   POST HANDLER — Delete document
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document'])) {
    if ($is_teacher) { $_SESSION['error'] = 'Teachers cannot delete documents.'; header("Location: " . URL_PREFIX . "/view_student.php?id=" . (int)$_POST['student_id']); exit(); }
    verify_csrf();

    $eid = (int)($_POST['entrance_id'] ?? 0);
    $sid = (int)($_POST['student_id'] ?? 0);

    $chk = $conn->prepare("SELECT file_path FROM entrance_documents WHERE entrance_id = ? AND student_id = ?");
    $chk->bind_param("ii", $eid, $sid);
    $chk->execute();
    $row = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($row && !empty($row['file_path'])) {
        try {
            $abs = safe_path(DOCUMENTS_DIR, (string)$row['file_path']);
            if (is_file($abs)) @unlink($abs);
        } catch (RuntimeException $e) {
            security_log('view_student.delete_containment', [
                'candidate' => (string)$row['file_path'],
                'student'   => $sid,
            ]);
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
    header("Location: " . URL_PREFIX . "/view_student.php?id=$sid"); exit();
}

/* ═══════════════════════════════════════════════════════════
   POST HANDLER — Upload student photo
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo'])) {
    if ($is_teacher) { $_SESSION['error'] = 'Teachers cannot upload photos.'; header("Location: " . URL_PREFIX . "/view_student.php?id=" . (int)$_POST['student_id']); exit(); }
    verify_csrf();

    $sid = (int)$_POST['student_id'];

    if (isset($_FILES['student_photo']) && $_FILES['student_photo']['error'] === 0) {
        try {
            $info = validate_upload($_FILES['student_photo']['tmp_name'], $_FILES['student_photo']['name'], [
                'max_bytes'   => 2 * 1024 * 1024,
                'allowed'     => ['image/jpeg', 'image/png'],
                'image_min_w' => 200,
                'image_min_h' => 200,
            ]);

            $dir = STUDENTS_PHOTO_DIR;
            if (!is_dir($dir)) mkdir($dir, 0750, true);

            $oq = $conn->prepare("SELECT photo FROM students_info WHERE student_id = ?");
            $oq->bind_param("i", $sid);
            $oq->execute();
            $old = $oq->get_result()->fetch_assoc();
            $oq->close();
            if (!empty($old['photo'])) {
                try {
                    $oabs = safe_path(STUDENTS_PHOTO_DIR, (string)$old['photo']);
                    if (is_file($oabs)) @unlink($oabs);
                } catch (RuntimeException $e) {
                    security_log('view_student.old_photo_containment', [
                        'candidate' => (string)$old['photo'],
                        'student'   => $sid,
                    ]);
                }
            }

            $random_abs = move_upload_securely($_FILES['student_photo']['tmp_name'], $dir, $info['ext']);
            $fn         = "student_{$sid}_" . bin2hex(random_bytes(8)) . ".{$info['ext']}";
            $final      = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $fn;

            if (!@rename($random_abs, $final)) {
                @unlink($random_abs);
                throw new RuntimeException('Could not finalize photo.');
            }
            @chmod($final, 0640);

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
        } catch (RuntimeException $e) {
            security_log('view_student.photo_rejected', [
                'student_id' => $sid,
                'reason'     => $e->getMessage(),
                'ip'         => client_ip(),
            ]);
            $_SESSION['error'] = $e->getMessage();
        }
    } else {
        $_SESSION['error'] = 'No file.';
    }
    header("Location: " . URL_PREFIX . "/view_student.php?id=$sid"); exit();
}

/* ═══════════════════════════════════════════════════════════
   POST HANDLER — Delete student photo
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_photo'])) {
    if ($is_teacher) { $_SESSION['error'] = 'Teachers cannot delete photos.'; header("Location: " . URL_PREFIX . "/view_student.php?id=" . (int)$_POST['student_id']); exit(); }
    verify_csrf();

    $sid = (int)($_POST['student_id'] ?? 0);

    if ($sid <= 0) {
        $_SESSION['error'] = 'Invalid student.';
        header("Location: " . URL_PREFIX . "/student_profile.php"); exit();
    }

    $q = $conn->prepare("SELECT photo FROM students_info WHERE student_id = ?");
    $q->bind_param("i", $sid);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$row) {
        $_SESSION['error'] = 'Student not found.';
        header("Location: " . URL_PREFIX . "/student_profile.php"); exit();
    }

    $old_photo = $row['photo'] ?? null;

    $u = $conn->prepare("UPDATE students_info SET photo = NULL WHERE student_id = ?");
    $u->bind_param("i", $sid);
    $u->execute();
    $u->close();

    if (!empty($old_photo)) {
        try {
            $abs = safe_path(STUDENTS_PHOTO_DIR, (string)$old_photo);
            if (is_file($abs)) @unlink($abs);
        } catch (RuntimeException $e) {
            security_log('view_student.photo_delete_containment', [
                'candidate' => (string)$old_photo,
                'student'   => $sid,
            ]);
        }
    }

    audit_log($conn, 'student.photo_delete', [
        'type'     => 'student',
        'id'       => $sid,
        'filename' => $old_photo,
    ]);

    $_SESSION['success'] = 'Profile photo removed.';
    header("Location: " . URL_PREFIX . "/view_student.php?id=$sid"); exit();
}

/* ═══════════════════════════════════════════════════════════
   POST HANDLER — Delete student (with file cleanup)
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    if ($is_teacher) { $_SESSION['error'] = 'Teachers cannot delete students.'; header("Location: " . URL_PREFIX . "/view_student.php?id=" . (int)$_POST['student_id']); exit(); }
    verify_csrf();

    $sid = (int)$_POST['student_id'];

    $files = [];
    $dq = $conn->prepare("SELECT file_path FROM entrance_documents WHERE student_id = ? AND file_path IS NOT NULL");
    $dq->bind_param("i", $sid);
    $dq->execute();
    $dres = $dq->get_result();
    while ($d = $dres->fetch_assoc()) {
        if (!empty($d['file_path'])) {
            try {
                $files[] = safe_path(DOCUMENTS_DIR, (string)$d['file_path']);
            } catch (RuntimeException $e) {
                security_log('view_student.del_doc_containment', [
                    'candidate' => (string)$d['file_path'],
                    'student'   => $sid,
                ]);
            }
        }
    }
    $dq->close();

    $pq = $conn->prepare("SELECT photo FROM students_info WHERE student_id = ?");
    $pq->bind_param("i", $sid);
    $pq->execute();
    $prow = $pq->get_result()->fetch_assoc();
    $pq->close();

    $photo_abs = null;
    if (!empty($prow['photo'])) {
        try {
            $photo_abs = safe_path(STUDENTS_PHOTO_DIR, (string)$prow['photo']);
        } catch (RuntimeException $e) {
            $photo_abs = null;
        }
    }

    $conn->begin_transaction();
    try {
        $child_tables = ['student_grades', 'entrance_documents', 'educational_history', 'addresses', 'parents_info', 'enrollment_form'];
        foreach ($child_tables as $t) {
            $safe_t = sql_identifier($t);
            $s = $conn->prepare("DELETE FROM `{$safe_t}` WHERE student_id = ?");
            $s->bind_param("i", $sid);
            $s->execute();
            $s->close();
        }
        $s = $conn->prepare("DELETE FROM students_info WHERE student_id = ?");
        $s->bind_param("i", $sid);
        $s->execute();
        $s->close();
        $conn->commit();

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
        header("Location: " . URL_PREFIX . "/student_profile.php"); exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Delete failed.';
        header("Location: " . URL_PREFIX . "/view_student.php?id=$sid"); exit();
    }
}

/* ═══════════════════════════════════════════════════════════
   POST HANDLER — Add education
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_education'])) {
    if ($is_teacher) { $_SESSION['error'] = 'Teachers cannot add education.'; header("Location: " . URL_PREFIX . "/view_student.php?id=" . (int)$_POST['student_id']); exit(); }
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
                'id'         => $s->insert_id,
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
    header("Location: " . URL_PREFIX . "/view_student.php?id=$sid"); exit();
}

/* ═══════════════════════════════════════════════════════════
   POST HANDLER — Edit education
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_education'])) {
    if ($is_teacher) { $_SESSION['error'] = 'Teachers cannot edit education.'; header("Location: " . URL_PREFIX . "/view_student.php?id=" . (int)$_POST['student_id']); exit(); }
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
    header("Location: " . URL_PREFIX . "/view_student.php?id=$sid"); exit();
}

/* ═══════════════════════════════════════════════════════════
   POST HANDLER — Delete education
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_education'])) {
    if ($is_teacher) { $_SESSION['error'] = 'Teachers cannot delete education.'; header("Location: " . URL_PREFIX . "/view_student.php?id=" . (int)$_POST['student_id']); exit(); }
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
    header("Location: " . URL_PREFIX . "/view_student.php?id=$sid"); exit();
}

/* ═══════════════════════════════════════════════════════════
   FETCH DATA
   ═══════════════════════════════════════════════════════════ */
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = 'No student ID.';
    header("Location: " . URL_PREFIX . "/student_profile.php"); exit();
}
$student_id = (int)$_GET['id'];

$s = $conn->prepare("
    SELECT s.*,
           p.father_name, p.father_occupation, p.father_contact,
           p.mother_maiden_name, p.mother_occupation, p.mother_contact,
           p.guardian_fullname, p.guardian_relation, p.guardian_contact,
           p.ave_family_income, p.is_4ps, p.household_id as parent_household_id,
           a.purok_street, a.barangay, a.town_city, a.province,
           a.region, a.district, a.postal_code
    FROM students_info s
    LEFT JOIN parents_info p ON s.student_id = p.student_id
    LEFT JOIN addresses a ON s.student_id = a.student_id
    WHERE s.student_id = ?
    LIMIT 1
");
$s->bind_param("i", $student_id);
$s->execute();
$student = $s->get_result()->fetch_assoc();
$s->close();

if (!$student) {
    $_SESSION['error'] = 'Student not found.';
    header("Location: " . URL_PREFIX . "/student_profile.php"); exit();
}

/* All enrollments */
$all_enrollments = [];
$es = $conn->prepare("
    SELECT e.enrollment_id, e.grade_level, e.track, e.strand, e.program,
           e.section, e.school_year, e.term, e.status, e.voucher_status,
           e.household_id, e.is_transferred, e.previous_school_name,
           e.previous_school_address, e.previous_track, e.previous_strand,
           e.previous_program, e.previous_year_completed, e.cct_4ps
    FROM enrollment_form e
    WHERE e.student_id = ?
    ORDER BY e.school_year DESC, e.term DESC, e.enrollment_id DESC
");
$es->bind_param("i", $student_id);
$es->execute();
$enr_res = $es->get_result();
while ($row = $enr_res->fetch_assoc()) {
    $all_enrollments[(int)$row['enrollment_id']] = $row;
}
$es->close();

/* Group by grade level */
$enrollments_by_grade = ['Grade 11' => [], 'Grade 12' => []];
foreach ($all_enrollments as $en) {
    $gl = strtolower(trim((string)$en['grade_level']));
    if (strpos($gl, '12') !== false)      $enrollments_by_grade['Grade 12'][] = $en;
    elseif (strpos($gl, '11') !== false)  $enrollments_by_grade['Grade 11'][] = $en;
}

/* Determine active grade */
$active_grade = trim((string)($_GET['grade'] ?? ''));

if (!in_array($active_grade, ['Grade 11', 'Grade 12'], true) || empty($enrollments_by_grade[$active_grade])) {
    $active_grade = '';
    $first_en = reset($all_enrollments);
    if ($first_en) {
        $gl = strtolower(trim((string)$first_en['grade_level']));
        if (strpos($gl, '12') !== false)      $active_grade = 'Grade 12';
        elseif (strpos($gl, '11') !== false)  $active_grade = 'Grade 11';
    }
    if ($active_grade === '') {
        foreach (['Grade 12', 'Grade 11'] as $candidate) {
            if (!empty($enrollments_by_grade[$candidate])) { $active_grade = $candidate; break; }
        }
    }
}

$enrollment = !empty($enrollments_by_grade[$active_grade])
    ? $enrollments_by_grade[$active_grade][0]
    : [];

if (!empty($enrollment)) {
    $student = array_merge($student, $enrollment);
}

$parents = $student;
$address = $student;

$has_grade_11    = !empty($enrollments_by_grade['Grade 11']);
$has_grade_12    = !empty($enrollments_by_grade['Grade 12']);
$show_grade_tabs = $has_grade_11 && $has_grade_12;

$other_grade = ($active_grade === 'Grade 11') ? 'Grade 12' : 'Grade 11';
$has_other   = !empty($enrollments_by_grade[$other_grade]);

/* Auto-seed required documents */
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

/* Education */
$s = $conn->prepare("SELECT edu_id, level, school_name, school_address, year_completed FROM educational_history WHERE student_id = ? ORDER BY CASE level WHEN 'Elementary' THEN 1 WHEN 'JHS' THEN 2 ELSE 3 END");
$s->bind_param("i", $student_id);
$s->execute();
$education = $s->get_result()->fetch_all(MYSQLI_ASSOC);
$s->close();

/* Documents */
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

/* Grades */
$grades_stmt = $conn->prepare("
    SELECT sg.grade_id, sg.term, sg.grade_level, sg.school_year, sg.enrollment_id,
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

/* Active-grade GWA */
$active_grade_gwa = null;
$active_grade_points = 0; $active_grade_count = 0;
foreach ($all_grades_flat as $g) {
    $gl = strtolower(trim((string)($g['grade_level'] ?? '')));
    $matches = ($active_grade === 'Grade 11' && strpos($gl, '11') !== false)
            || ($active_grade === 'Grade 12' && strpos($gl, '12') !== false);
    if ($matches && $g['grade'] !== null && is_numeric($g['grade'])) {
        $active_grade_points += (float)$g['grade'];
        $active_grade_count++;
    }
}
if ($active_grade_count > 0) {
    $active_grade_gwa = round($active_grade_points / $active_grade_count, 2);
}

/* GWA per year */
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

/* Term GWA */
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

/* Filter academic record to active grade */
$filtered_academic_record = [];
foreach ($academic_record as $sy => $levels) {
    foreach ($levels as $gl => $terms) {
        $gl_low = strtolower(trim((string)$gl));
        $matches = ($active_grade === 'Grade 11' && strpos($gl_low, '11') !== false)
                || ($active_grade === 'Grade 12' && strpos($gl_low, '12') !== false);
        if ($matches) {
            $filtered_academic_record[$sy][$gl] = $terms;
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

$initials = strtoupper(substr($student['first_name'] ?? '', 0, 1) . substr($student['last_name'] ?? '', 0, 1));

$photo_abs = !empty($student['photo']) ? student_photo_abs_path($student['photo']) : '';
$photo = ($photo_abs && is_file($photo_abs))
    ? URL_PREFIX . '/photo.php?id=' . (int)$student_id . '&v=' . filemtime($photo_abs)
    : '';

$fullName = htmlspecialchars(formatStudentName($student));

$currentTerm = getCurrentTerm();
$terms       = getTerms();
$csrf_token  = csrf_token();

/* ═══════════════════════════════════════════════════════════
   RENDER
   ═══════════════════════════════════════════════════════════ */
$page_title = $fullName . ' • USAT Admin';

$page_css = <<<'CSS'
    .stat-hint { font-size:.68rem; color:var(--text-3); margin-top:.35rem; font-weight:500; display:flex; align-items:center; gap:.25rem; }
    .stat-hint i { color:var(--accent); }

    /* Grade tabs */
    .grade-tabs { display:inline-flex; gap:.3rem; background:var(--surface-2); padding:.28rem; border-radius:11px; border:1px solid var(--border); }
    .grade-tab { display:inline-flex; align-items:center; gap:.4rem; padding:.42rem .9rem; border-radius:8px; font-size:.78rem; font-weight:600; color:var(--text-2); text-decoration:none; transition:all .15s; white-space:nowrap; cursor:pointer; border:none; background:transparent; font-family:inherit; }
    .grade-tab:hover { background:var(--surface); color:var(--text); }
    .grade-tab.active { background:var(--accent); color:#fff; box-shadow:0 2px 8px -2px rgba(79,70,229,.5); }
    .grade-tab.active:hover { background:var(--accent); color:#fff; }
    .grade-tab i { font-size:.85rem; }
    .grade-tab .tab-count { display:inline-block; margin-left:.2rem; padding:.05rem .4rem; border-radius:999px; font-size:.65rem; font-weight:700; background:rgba(255,255,255,.25); color:inherit; }
    .grade-tab:not(.active) .tab-count { background:var(--surface); color:var(--text-2); border:1px solid var(--border); }

    .grade-record-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.5rem .9rem; border-radius:9px; font-size:.82rem; font-weight:600; font-family:inherit; text-decoration:none; cursor:pointer; transition:all .15s; white-space:nowrap; border:1px solid; position:relative; }
    .grade-record-btn i { font-size:.95rem; }
    .grade-record-btn.g11 { background:var(--accent-soft); color:var(--accent); border-color:color-mix(in srgb,var(--accent) 30%,transparent); }
    .grade-record-btn.g11:hover { background:var(--accent); color:#fff; border-color:var(--accent); transform:translateY(-1px); box-shadow:0 4px 12px -4px rgba(79,70,229,.5); }
    .grade-record-btn.g12 { background:var(--purple-soft); color:var(--purple); border-color:color-mix(in srgb,var(--purple) 30%,transparent); }
    .grade-record-btn.g12:hover { background:var(--purple); color:#fff; border-color:var(--purple); transform:translateY(-1px); box-shadow:0 4px 12px -4px rgba(124,58,237,.5); }
    .grade-record-btn.active { color:#fff; box-shadow:0 4px 12px -4px rgba(0,0,0,.25); }
    .grade-record-btn.g11.active { background:var(--accent); border-color:var(--accent); }
    .grade-record-btn.g12.active { background:var(--purple); border-color:var(--purple); }
    .grade-record-btn.active::after { content:''; position:absolute; top:-3px; right:-3px; width:10px; height:10px; border-radius:50%; background:var(--green); border:2px solid var(--surface); }
    .grade-record-btn .rec-count { display:inline-block; padding:.05rem .4rem; border-radius:999px; font-size:.65rem; font-weight:700; background:rgba(255,255,255,.45); margin-left:.15rem; }
    .grade-record-btn:not(.active) .rec-count { background:var(--surface); border:1px solid var(--border); color:var(--text-2); }

    /* Hero */
    .hero{display:flex;gap:1.75rem;align-items:center;flex-wrap:wrap;padding:1.5rem 1.75rem}
    .hero-avatar{position:relative;flex-shrink:0}
    .hero-avatar img,.hero-avatar .avatar-fallback{width:104px;height:104px;border-radius:50%;object-fit:cover;box-shadow:0 0 0 4px var(--surface), 0 0 0 6px var(--accent-soft)}
    .hero-avatar .avatar-fallback{background:linear-gradient(135deg,var(--accent),var(--accent-2));display:flex;align-items:center;justify-content:center;color:#fff;font-size:2.3rem;font-weight:800;letter-spacing:-.03em}
    .hero-avatar .camera-btn{position:absolute;bottom:2px;right:2px;width:32px;height:32px;border-radius:50%;background:var(--surface);border:2px solid var(--border);cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--accent);transition:all .15s}
    .hero-avatar .camera-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
    .hero-avatar .trash-btn{position:absolute;bottom:2px;left:2px;width:32px;height:32px;border-radius:50%;background:var(--surface);border:2px solid var(--border);cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--red);transition:all .15s}
    .hero-avatar .trash-btn:hover{background:var(--red);color:#fff;border-color:var(--red)}

    .hero-info{flex:1;min-width:0}
    .hero-info h1{font-size:1.5rem;font-weight:700;letter-spacing:-.02em;margin:0 0 .6rem;word-break:break-word}
    .hero-badges{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.75rem}

    .pill{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .65rem;border-radius:999px;font-size:.72rem;font-weight:600;border:1px solid transparent;white-space:nowrap}
    .pill i{font-size:.75rem}
    .pill.primary{background:var(--accent-soft);color:var(--accent);border-color:color-mix(in srgb, var(--accent) 22%, transparent)}
    .pill.success{background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb, var(--green) 22%, transparent)}
    .pill.info   {background:#dbeafe;color:#1e40af;border-color:rgba(30,64,175,.15)}
    .pill.warning{background:var(--amber-soft);color:var(--amber);border-color:color-mix(in srgb, var(--amber) 22%, transparent)}
    .pill.danger {background:var(--red-soft);color:var(--red);border-color:color-mix(in srgb, var(--red) 22%, transparent)}
    .pill.muted  {background:var(--surface-2);color:var(--text-2);border-color:var(--border)}
    [data-bs-theme="dark"] .pill.info{background:#1e3a5f;color:#93c5fd;border-color:rgba(147,197,253,.15)}

    .status-dot{display:inline-flex;align-items:center;gap:.4rem;font-size:.75rem;font-weight:600}
    .status-dot::before{content:'';width:7px;height:7px;border-radius:50%;flex-shrink:0;background:currentColor;opacity:.9}
    .status-dot.success{color:var(--green)}
    .status-dot.danger {color:var(--red)}
    .status-dot.warning{color:var(--amber)}

    .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.85rem}
    .stat-card{position:relative;background:var(--surface);border-radius:var(--radius);border:1px solid var(--border);box-shadow:var(--shadow-xs);padding:.95rem 1rem .95rem 1.15rem;overflow:hidden;transition:transform .15s, box-shadow .15s}
    .stat-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--accent)}
    .stat-card.tone-green::before {background:var(--green)}
    .stat-card.tone-purple::before{background:var(--purple)}
    .stat-card.tone-amber::before {background:var(--amber)}
    .stat-card.tone-red::before   {background:var(--red)}
    .stat-card.tone-blue::before  {background:var(--accent)}
    .stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
    .stat-value{font-size:1.25rem;font-weight:700;line-height:1.15;color:var(--text);letter-spacing:-.015em;word-break:break-word;font-variant-numeric:tabular-nums}
    .stat-value.sm{font-size:1rem}
    .stat-value.xs{font-size:.82rem;font-weight:600}
    .stat-label{font-size:.68rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;margin-top:.25rem;font-weight:600}

    .info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:0;border-top:1px solid var(--border);border-left:1px solid var(--border)}
    .info-cell{padding:.7rem 1rem;border-right:1px solid var(--border);border-bottom:1px solid var(--border);background:var(--surface)}
    .info-cell .label{font-size:.66rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;margin-bottom:.2rem}
    .info-cell .value{font-weight:500;font-size:.85rem;color:var(--text);word-break:break-word}
    .info-cell .value.accent{color:var(--accent);font-weight:700}
    .info-cell.span-2{grid-column:span 2;background:var(--surface-2)}
    .info-cell.span-full{grid-column:1 / -1;background:var(--surface-2)}

    .file-meta{font-size:.72rem;color:var(--text-2);display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;margin-top:.2rem}
    .file-meta .dot{opacity:.5}
    .file-name{font-weight:600;font-size:.82rem;color:var(--text);display:flex;align-items:center;gap:.35rem;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .file-name i{color:var(--accent);flex-shrink:0}

    .sy-header{background:var(--surface-2);padding:.75rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}
    .sy-header h6{margin:0;font-size:.85rem;font-weight:700;color:var(--accent);display:flex;align-items:center;gap:.5rem}
    .grade-level-band{padding:.55rem 1.25rem;background:color-mix(in srgb, var(--accent) 5%, transparent);border-bottom:1px solid var(--border)}
    .grade-level-band h6{margin:0;font-size:.78rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:.5rem}
    .grade-level-band i{color:var(--accent)}

    .term-block{margin:.75rem 1rem;padding:.75rem .85rem;border-radius:var(--radius);border-left:4px solid var(--accent);background:var(--accent-soft)}
    .term-block.term-1{background:var(--accent-soft);border-left-color:var(--accent)}
    .term-block.term-2{background:#dbeafe;border-left-color:#2563eb}
    .term-block.term-3{background:var(--purple-soft);border-left-color:var(--purple)}
    [data-bs-theme="dark"] .term-block.term-2{background:#1e3a5f}
    [data-bs-theme="dark"] .term-block.term-3{background:var(--purple-soft)}

    .term-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem;flex-wrap:wrap;gap:.5rem}
    .term-title{font-size:.78rem;font-weight:700;display:flex;align-items:center;gap:.4rem}
    .term-block.term-1 .term-title{color:var(--accent)}
    .term-block.term-2 .term-title{color:#1e40af}
    .term-block.term-3 .term-title{color:var(--purple)}

    .term-gwa{font-size:.68rem;font-weight:600;background:var(--surface);padding:.2rem .55rem;border-radius:999px;border:1px solid var(--border)}
    .term-block.term-1 .term-gwa{color:var(--accent)}
    .term-block.term-2 .term-gwa{color:#1e40af}
    .term-block.term-3 .term-gwa{color:var(--purple)}

    .term-block .table-admin{background:var(--surface);border-radius:8px;overflow:hidden}
    .term-block .table-admin th{font-size:.62rem;padding:.5rem .7rem}
    .term-block .table-admin td{font-size:.78rem;padding:.5rem .7rem}
    .grade-pass{color:var(--green);font-weight:700;font-variant-numeric:tabular-nums}
    .grade-fail{color:var(--red);font-weight:700;font-variant-numeric:tabular-nums}

    .btn{font-weight:500;border-radius:9px;font-size:.82rem;transition:all .15s}
    .btn-outline-warning{color:var(--amber);border-color:color-mix(in srgb, var(--amber) 40%, transparent)}
    .btn-outline-warning:hover{background:var(--amber);color:#fff;border-color:var(--amber)}
    .btn-xs{padding:.25rem .6rem;font-size:.72rem;border-radius:8px}
    .btn-group-actions{display:flex;gap:.35rem;flex-wrap:wrap}

    .modal-content{border-radius:var(--radius-lg);border:none;box-shadow:var(--shadow-lg)}
    .modal-header{padding:1rem 1.25rem;border-bottom:1px solid var(--border);border-top-left-radius:var(--radius-lg);border-top-right-radius:var(--radius-lg)}
    .modal-header.bg-danger{background:var(--red) !important}
    .modal-header .modal-title{font-size:.92rem}
    .modal-body{padding:1.25rem}
    .modal-footer{padding:.85rem 1.25rem;border-top:1px solid var(--border)}
    .modal-subtitle{font-size:.75rem;color:var(--text-2);font-weight:500;margin-top:.15rem}
    .form-label .req{color:var(--red);font-weight:700;margin-left:.1rem}

    @media (max-width:1024px){
        .hero{flex-direction:column;text-align:center;padding:1.5rem 1rem}
        .hero-badges{justify-content:center}
        .hero-info h1{font-size:1.3rem}
    }
    @media (max-width:640px){
        .stat-grid{grid-template-columns:repeat(2,1fr)}
        .info-grid{grid-template-columns:1fr}
        .info-cell.span-2{grid-column:span 1}
        .term-block{margin:.5rem .5rem}
        .grade-level-band{padding:.55rem .75rem}
        .sy-header{padding:.65rem .75rem}
        .grade-tabs{width:100%;justify-content:center}
        .grade-record-btn span.rec-label { display: none; }
        .grade-record-btn { padding: .5rem .7rem; }
    }
CSS;

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/sidebar.php';
?>

<div class="main-content" id="mainContent">

    <?php if (isset($_SESSION['success'])): ?>
        <div class="flash success">
            <i class="bi bi-check-circle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['success']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="flash error">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['error']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle" aria-label="Open menu">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <h2>Student Profile</h2>
                <p class="sub"><?= $fullName ?></p>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">

            <?php if ($has_grade_11): ?>
                <a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$student_id ?>&grade=Grade+11"
                   class="grade-record-btn g11 <?= $active_grade === 'Grade 11' ? 'active' : '' ?>"
                   title="View Grade 11 records">
                    <i class="bi bi-mortarboard-fill"></i>
                    <span class="rec-label">Grade 11 Records</span>
                    <span class="rec-count"><?= count($enrollments_by_grade['Grade 11']) ?></span>
                </a>
            <?php endif; ?>

            <?php if ($has_grade_12): ?>
                <a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$student_id ?>&grade=Grade+12"
                   class="grade-record-btn g12 <?= $active_grade === 'Grade 12' ? 'active' : '' ?>"
                   title="View Grade 12 records">
                    <i class="bi bi-mortarboard-fill"></i>
                    <span class="rec-label">Grade 12 Records</span>
                    <span class="rec-count"><?= count($enrollments_by_grade['Grade 12']) ?></span>
                </a>
            <?php endif; ?>

            <a href="<?= URL_PREFIX ?>/add_grades.php?student_id=<?= (int)$student_id ?>&grade=<?= urlencode($active_grade) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-journal-plus me-1"></i> Add / Edit Grades
            </a>

            <?php if (!$is_teacher): ?>
                <a href="<?= URL_PREFIX ?>/sf10.php?id=<?= (int)$student_id ?>" class="btn btn-outline-primary btn-sm" target="_blank">
                    <i class="bi bi-file-earmark-text me-1"></i> SF10
                </a>
                <a href="<?= URL_PREFIX ?>/export_students.php?student_id=<?= (int)$student_id ?>" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-download me-1"></i> Export
                </a>
            <?php endif; ?>

            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print
            </button>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <!-- Hero -->
    <div class="card">
        <div class="hero">
            <div class="hero-avatar">
                <?php if($photo): ?>
                    <img src="<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') ?>" alt="Photo">
                    <?php if (!$is_teacher): ?>
                        <button class="trash-btn" data-bs-toggle="modal" data-bs-target="#deletePhotoModal" title="Remove photo">
                            <i class="bi bi-trash3-fill" style="font-size:.72rem"></i>
                        </button>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="avatar-fallback"><?= htmlspecialchars($initials) ?></div>
                <?php endif; ?>
                <?php if (!$is_teacher): ?>
                    <button class="camera-btn" data-bs-toggle="modal" data-bs-target="#uploadPhotoModal" title="Change photo">
                        <i class="bi bi-camera-fill" style="font-size:.75rem"></i>
                    </button>
                <?php endif; ?>
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
                    <?php if($active_grade_gwa !== null): ?>
                        <span class="pill success">
                            <i class="bi bi-star-fill"></i>
                            <?= htmlspecialchars($active_grade) ?> GWA: <?= number_format($active_grade_gwa, 2) ?>
                        </span>
                    <?php endif; ?>
                    <span class="pill <?= ($enrollment['status'] ?? 'Active') === 'Active' ? 'success' : 'warning' ?>">
                        <?= htmlspecialchars($enrollment['status'] ?? 'Active') ?>
                    </span>
                </div>
                
                <?php if (!$is_teacher): ?>
                <div class="btn-group-actions">
                    <a href="<?= URL_PREFIX ?>/edit_student.php?id=<?= (int)$student_id ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-pencil-square me-1"></i> Edit Student Info
                    </a>
                    <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteStudentModal">
                        <i class="bi bi-trash3 me-1"></i> Delete Student
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Stat grid -->
    <div class="stat-grid">
        <div class="stat-card tone-blue">
            <div class="stat-value <?= statSizeClass($stat_grade) ?>" title="<?= htmlspecialchars($stat_grade) ?>"><?= htmlspecialchars($stat_grade) ?></div>
            <div class="stat-label">Grade</div>
            <?php if ($show_grade_tabs): ?>
                <div class="stat-hint"><i class="bi bi-info-circle"></i>Viewing <?= htmlspecialchars($active_grade) ?></div>
            <?php endif; ?>
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
            <div class="stat-value"><?= $active_grade_gwa !== null ? number_format($active_grade_gwa, 2) : '—' ?></div>
            <div class="stat-label"><?= htmlspecialchars($active_grade ?: 'GWA') ?> GWA</div>
        </div>
    </div>

    <!-- Personal + Enrollment + Address -->
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
                    <h3>
                        <i class="bi bi-mortarboard"></i> Enrollment Details
                        <?php if (!empty($enrollment)): ?>
                            <span class="pill primary" style="margin-left:.5rem;text-transform:none;letter-spacing:0;">
                                <?= htmlspecialchars($active_grade) ?>
                            </span>
                        <?php endif; ?>
                    </h3>

                    <?php if ($show_grade_tabs && $has_other): ?>
                        <span class="hint" style="margin-left:auto;margin-right:.5rem;">
                            <i class="bi bi-info-circle"></i>
                            Also has <?= htmlspecialchars($other_grade) ?> record
                        </span>
                    <?php endif; ?>

                    <?php if ($show_grade_tabs): ?>
                        <div class="grade-tabs" role="tablist" aria-label="Switch grade level view">
                            <a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$student_id ?>&grade=Grade+11"
                               class="grade-tab <?= $active_grade === 'Grade 11' ? 'active' : '' ?>"
                               role="tab"
                               aria-selected="<?= $active_grade === 'Grade 11' ? 'true' : 'false' ?>">
                                <i class="bi bi-mortarboard-fill"></i>
                                Grade 11
                                <span class="tab-count"><?= count($enrollments_by_grade['Grade 11']) ?></span>
                            </a>
                            <a href="<?= URL_PREFIX ?>/view_student.php?id=<?= (int)$student_id ?>&grade=Grade+12"
                               class="grade-tab <?= $active_grade === 'Grade 12' ? 'active' : '' ?>"
                               role="tab"
                               aria-selected="<?= $active_grade === 'Grade 12' ? 'true' : 'false' ?>">
                                <i class="bi bi-mortarboard-fill"></i>
                                Grade 12
                                <span class="tab-count"><?= count($enrollments_by_grade['Grade 12']) ?></span>
                            </a>
                        </div>
                    <?php endif; ?>
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

    <!-- Parents & Guardian -->
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

    <!-- Required Documents -->
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
                                            <a href="<?= URL_PREFIX ?>/view_document.php?id=<?= (int)$d['entrance_id'] ?>"
                                               class="btn btn-icon btn-outline-primary" target="_blank" title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="<?= URL_PREFIX ?>/view_document.php?id=<?= (int)$d['entrance_id'] ?>&dl=1"
                                               class="btn btn-icon btn-outline-secondary" title="Download">
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <?php if (!$is_teacher): ?>
                                                <button class="btn btn-icon btn-outline-danger delete-doc-btn"
                                                        data-eid="<?= (int)$d['entrance_id'] ?>"
                                                        data-name="<?= htmlspecialchars($d['document_name']) ?>"
                                                        title="Delete">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <?php if (!$is_teacher): ?>
                                            <button class="btn btn-primary btn-xs upload-doc-btn"
                                                    data-eid="<?= (int)$d['entrance_id'] ?>"
                                                    data-name="<?= htmlspecialchars($d['document_name']) ?>">
                                                <i class="bi bi-upload me-1"></i> Upload
                                            </button>
                                        <?php endif; ?>
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

    <!-- Educational History -->
    <div class="card mt-3">
        <div class="card-header">
            <h3><i class="bi bi-book"></i> Educational History</h3>
            <?php if (!$is_teacher): ?>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addEducationModal">
                    <i class="bi bi-plus-lg me-1"></i> Add
                </button>
            <?php endif; ?>
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
                                <?php if (!$is_teacher): ?>
                                    <th style="width:100px;text-align:right">Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($education as $e): ?>
                            <tr>
                                <td class="cell-strong"><?= htmlspecialchars($e['level']) ?></td>
                                <td><?= htmlspecialchars($e['school_name']) ?></td>
                                <td class="cell-muted"><?= htmlspecialchars($e['school_address'] ?? '—') ?></td>
                                <td class="cell-muted"><?= htmlspecialchars($e['year_completed'] ?? '—') ?></td>
                                <?php if (!$is_teacher): ?>
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
                                <?php endif; ?>
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

    <!-- Academic Record -->
    <div class="card mt-3">
        <div class="card-header">
            <h3>
                <i class="bi bi-journal-text"></i>
                Academic Record &amp; Grades
                <?php if ($active_grade): ?>
                    <span class="pill primary" style="margin-left:.5rem;text-transform:none;letter-spacing:0;">
                        <?= htmlspecialchars($active_grade) ?>
                    </span>
                <?php endif; ?>
            </h3>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <?php if($active_grade_gwa !== null): ?>
                    <span class="pill primary">
                        <?= htmlspecialchars($active_grade) ?> GWA: <strong style="margin-left:.2rem"><?= number_format($active_grade_gwa, 2) ?></strong>
                    </span>
                <?php endif; ?>
                <a href="<?= URL_PREFIX ?>/add_grades.php?student_id=<?= (int)$student_id ?>&grade=<?= urlencode($active_grade) ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i> Manage Grades
                </a>
            </div>
        </div>
        <div class="card-body no-padding">
            <?php if(!empty($filtered_academic_record)): ?>
                <?php
                $termClass = [
                    '1st Term'     => 'term-1',
                    '2nd Term'     => 'term-2',
                    '3rd Term'     => 'term-3',
                    'Unknown Term' => '',
                ];
                foreach($filtered_academic_record as $school_year => $grade_levels): ?>
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
                    <div class="empty-sub">Click "Manage Grades" to begin tracking academic performance.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php if (!$is_teacher): ?>
<!-- ALL MODALS FOR ADMIN/REGISTRAR ONLY -->
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

<div class="modal fade" id="deletePhotoModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title fw-bold"><i class="bi bi-trash3 me-2"></i>Remove Photo</h6>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Remove the profile photo for <strong><?= $fullName ?></strong>? This cannot be undone.</p>
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

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
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
        url: '<?= URL_PREFIX ?>/view_student.php',
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
<?php endif; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>