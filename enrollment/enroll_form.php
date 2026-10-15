<?php
declare(strict_types=1);

/**
 * enroll_form.php — Public student enrollment wizard.
 *
 * FIXES applied:
 *   - Name pattern accepts periods and Latin-1 accents (Ma., José, Sta.)
 *   - LRN is normalized before validation (strips hidden characters).
 *   - Wizard uses explicit checks (no reliance on native checkValidity()).
 *   - bfcache guard forces a fresh reload after POST → redirect.
 *   - Stale error / enroll_error cleared when arriving via ?edit=1 or ?review=1.
 *   - Enhanced confirmation popup reads $_SESSION['success_data'] and
 *     clearly marks the applicant as EXPECTED (not yet enrolled).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('USAT_SESSID');
    session_start();
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Vary: Cookie');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    http_response_code(413);
    $_SESSION['errors'] = [
        'upload' => 'Your submission was too large and was rejected before processing. '
                  . 'Please reduce the size of your uploaded files and try again.',
    ];
    header('Location: ' . basename(__FILE__));
    exit;
}

include_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/paths.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/file_verify.php';
require_once __DIR__ . '/../config/document_content_verify.php';

send_public_security_headers();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

const SUPPORTED_LANGS = ['en', 'fil'];

if (isset($_GET['lang']) && in_array((string)$_GET['lang'], SUPPORTED_LANGS, true)) {
    $_SESSION['lang'] = (string)$_GET['lang'];
}
if (!isset($_SESSION['lang']) || !in_array((string)$_SESSION['lang'], SUPPORTED_LANGS, true)) {
    $accept = strtolower(trim((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')));
    $_SESSION['lang'] = (str_starts_with($accept, 'tl') || str_starts_with($accept, 'fil')) ? 'fil' : 'en';
}
$lang = $_SESSION['lang'];

$t = [
    'en' => [
        'school' => 'USAT COLLEGE SAGAY CITY INC.',
        'form' => 'Student Enrollment Form',
        'ay' => 'Academic Year 2026–2027',
        'student' => 'Student Information',
        'parents' => 'Parents & Guardian Information',
        'address' => 'Complete Home Address',
        'education' => 'Educational History',
        'enrollment' => 'Enrollment Details',
        'documents' => 'Entrance Documents',
        'voucher_question' => 'Are you a CCT/4Ps recipient? *',
        'yes' => 'YES', 'no' => 'NO',
        'entrance_data' => 'Entrance Data Presented (select at least one) *',
        'upload_docs' => 'Upload or Capture Entrance Documents',
        'choose_file' => 'Choose file',
        'no_file' => 'No file chosen',
        'capture' => 'Capture Photo',
        'good_moral' => 'Good Moral Certificate',
        'jhs_certificate_front' => 'Junior High School Certificate (Front)',
        'jhs_certificate_back'  => 'Junior High School Certificate (Back)',
        'birth_certificate' => 'NSO/PSA Birth Certificate (Original)',
        'pictures_2x2' => '2 pcs. 2×2 picture',
        'lrn' => 'LRN *', 'lname' => 'Last Name *', 'fname' => 'First Name *', 'mname' => 'Middle Name',
        'nick' => 'Nickname', 'ext' => 'Extension Name', 'sex' => 'Sex *',
        'male' => 'Male', 'female' => 'Female',
        'bdate' => 'Birth Date *', 'age' => 'Age *',
        'civil' => 'Civil Status', 'nation' => 'Nationality', 'religion' => 'Religion',
        'height' => 'Height (cm)', 'weight' => 'Weight (kg)',
        'email' => 'Email', 'phone' => 'Phone',
        'skills' => 'Special Skills',
        'father' => 'Father Name',
        'fjob' => 'Father Occupation',
        'fcontact' => 'Father Contact Number',
        'mother' => 'Mother Maiden Name',
        'mjob' => 'Mother Occupation',
        'mcontact' => 'Mother Contact Number',
        'income' => 'Family Income',
        'guardian' => 'Guardian Information',
        'guardian_name' => 'Guardian Full Name',
        'guardian_relation' => 'Relationship to Student',
        'guardian_contact' => 'Guardian Contact Number',
        'street' => 'Purok / Street', 'barangay' => 'Barangay', 'city' => 'Town / City',
        'province' => 'Province', 'region' => 'Region', 'district' => 'District', 'postal' => 'Postal Code',
        'elem' => 'Elementary', 'jhs' => 'Junior High School', 'trans' => 'Transferred',
        'school_name' => 'School Name', 'school_addr' => 'School Address', 'year_comp' => 'Year Completed',
        'add_school' => 'Add School', 'sy' => 'School Year *', 'grade' => 'Grade Level *',
        'term' => 'Term *', 'track' => 'Track', 'strand' => 'Strand *', 'program' => 'Program *',
        'household' => 'Household ID',
        'submit' => 'Submit Enrollment', 'back' => 'Back',
        'next' => 'Next', 'previous' => 'Previous', 'step_of' => 'Step %d of %d',
        'required' => 'This field is required',
        'invalid_lrn' => 'LRN must be 12 digits',
        'invalid_name' => 'Invalid name format',
        'invalid_age' => 'Age must be between 14 and 25',
        'invalid_email' => 'Invalid email format',
        'invalid_phone' => 'Phone must be 11 digits (09XXXXXXXXX)',
        'invalid_height' => 'Height must be between 100 and 250 cm',
        'invalid_weight' => 'Weight must be between 20 and 300 kg',
        'lrn_exists' => 'This LRN is already registered',
        'lrn_available' => 'LRN is available',
        'checking' => 'Checking…',
        'name_duplicate_title' => 'Similar name found',
        'name_duplicate_msg' => 'A student with the same name already exists. Is this a different person?',
        'name_duplicate_yes' => 'Yes, enroll anyway',
        'name_duplicate_no' => 'Cancel',
        'select_one_entrance' => 'Please select at least one entrance document',
        'transferred_in' => 'Transferred IN from another school',
        'prev_school_name' => 'Name of Previous School',
        'prev_school_address' => 'Address of Previous School',
        'prev_track' => 'Previous TRACK',
        'prev_strand' => 'Previous STRAND',
        'prev_program' => 'Previous PROGRAM',
        'prev_year_completed' => 'Year Completed (Previous School)',
        'voucher_qualified' => 'Voucher Status (for transferee)',
        'qualified_voucher' => 'Qualified Voucher Recipient',
        'not_qualified_voucher' => 'Not Qualified Voucher Recipient',
        'skip_to_content' => 'Skip to main content',
        'language_switch' => 'Language selection',
        'english' => 'English',
        'filipino' => 'Filipino',
        'progress_label' => 'Form progress',
        'step' => 'Step',
        'current_step' => 'Current step',
        'completed_step' => 'Completed step',
        'incomplete_step' => 'Incomplete - needs attention',
        'error_summary' => 'Please fix the following before continuing:',
        'upload_problem' => 'File upload problem:',
        'review_confirm' => 'Review & Confirm',
        'review_help' => 'Please review your information before submitting',
        'edit_btn' => 'Edit',
        'confirm_save' => 'Confirm & Save Enrollment',
        'docs_selected' => 'Documents Selected',
        'staged_files' => 'Staged Files',
        'no_files' => 'No files attached. You may still submit and upload them later.',
        'none_selected' => 'None selected',
        'camera_modal_title' => 'Capture document photo',
        'camera_capture' => 'Capture',
        'camera_cancel' => 'Cancel',
        'photo_preview' => 'Photo preview',
        'upload_file' => 'Upload File',
        'or_capture' => 'or Capture',
        'document_label' => 'Document Label',
        'already_staged' => 'Already staged — re-upload to replace',
        'level_label' => 'Level',
        'close' => 'Close',
        'close_alert' => 'Close this message',
        'back_to_login' => 'Back to Login',
        'select_placeholder' => '— Select —',
        'other_specify' => 'Other (specify)',
        'religion_other_placeholder' => 'Enter religion',
    ],
    'fil' => [
        'school' => 'USAT COLLEGE SAGAY CITY INC.',
        'form' => 'Porma ng Pagpaparehistro ng Mag-aaral',
        'ay' => 'Taong Panuruan 2026–2027',
        'student' => 'Impormasyon ng Mag-aaral',
        'parents' => 'Impormasyon ng mga Magulang at Guardian',
        'address' => 'Tirahan',
        'education' => 'Kasaysayang Pang-edukasyon',
        'enrollment' => 'Detalye ng Pag-enroll',
        'documents' => 'Mga Dokumento sa Pagpasok',
        'voucher_question' => 'Ikaw ba ay tumatanggap ng CCT/4Ps? *',
        'yes' => 'OO', 'no' => 'HINDI',
        'entrance_data' => 'Datos na Iniharap sa Pagpasok (pumili ng kahit isa) *',
        'upload_docs' => 'Mag-upload o Kumuha ng Larawan ng mga Dokumento',
        'choose_file' => 'Pumili ng file',
        'no_file' => 'Walang napiling file',
        'capture' => 'Kumuha ng Larawan',
        'good_moral' => 'Sertipiko ng Mabuting Asal',
        'jhs_certificate_front' => 'Sertipiko mula sa Junior High School (Harap)',
        'jhs_certificate_back'  => 'Sertipiko mula sa Junior High School (Likod)',
        'birth_certificate' => 'NSO/PSA Sertipiko ng Kapanganakan (Orihinal)',
        'pictures_2x2' => '2 piraso na 2×2 larawan',
        'lrn' => 'LRN *', 'lname' => 'Apelyido *', 'fname' => 'Pangalan *', 'mname' => 'Gitnang Pangalan',
        'nick' => 'Palayaw', 'ext' => 'Karugtong ng Pangalan', 'sex' => 'Kasarian *',
        'male' => 'Lalaki', 'female' => 'Babae',
        'bdate' => 'Araw ng Kapanganakan *', 'age' => 'Edad *',
        'civil' => 'Katayuang Sibil', 'nation' => 'Nasyonalidad', 'religion' => 'Relihiyon',
        'height' => 'Taas (cm)', 'weight' => 'Timbang (kg)',
        'email' => 'Email', 'phone' => 'Telepono',
        'skills' => 'Espesyal na Kasanayan',
        'father' => 'Pangalan ng Ama',
        'fjob' => 'Hanapbuhay ng Ama',
        'fcontact' => 'Numero ng Contact ng Ama',
        'mother' => 'Apelyido ng Ina sa Dalaga',
        'mjob' => 'Hanapbuhay ng Ina',
        'mcontact' => 'Numero ng Contact ng Ina',
        'income' => 'Kita ng Pamilya',
        'guardian' => 'Impormasyon ng Guardian',
        'guardian_name' => 'Buong Pangalan ng Guardian',
        'guardian_relation' => 'Relasyon sa Mag-aaral',
        'guardian_contact' => 'Numero ng Contact ng Guardian',
        'street' => 'Purok / Kalye', 'barangay' => 'Barangay', 'city' => 'Bayan / Lungsod',
        'province' => 'Lalawigan', 'region' => 'Rehiyon', 'district' => 'Distrito', 'postal' => 'Postal Code',
        'elem' => 'Elementarya', 'jhs' => 'Junior High School', 'trans' => 'Lumipat',
        'school_name' => 'Pangalan ng Paaralan', 'school_addr' => 'Address ng Paaralan', 'year_comp' => 'Taong Natapos',
        'add_school' => 'Magdagdag ng Paaralan', 'sy' => 'Taon ng Paaralan *', 'grade' => 'Antas ng Baitang *',
        'term' => 'Termino *', 'track' => 'Track', 'strand' => 'Strand *', 'program' => 'Programa *',
        'household' => 'Household ID',
        'submit' => 'Ipasa ang Pagpaparehistro', 'back' => 'Bumalik',
        'next' => 'Susunod', 'previous' => 'Bumalik', 'step_of' => 'Hakbang %d ng %d',
        'required' => 'Kailangan ang patlang na ito',
        'invalid_lrn' => 'Ang LRN ay dapat 12 digits',
        'invalid_name' => 'Hindi wasto ang format ng pangalan',
        'invalid_age' => 'Ang edad ay dapat nasa pagitan ng 14 at 25',
        'invalid_email' => 'Hindi wasto ang format ng email',
        'invalid_phone' => 'Ang telepono ay dapat 11 digits (09XXXXXXXXX)',
        'invalid_height' => 'Ang taas ay dapat nasa pagitan ng 100 at 250 cm',
        'invalid_weight' => 'Ang timbang ay dapat nasa pagitan ng 20 at 300 kg',
        'lrn_exists' => 'Ang LRN na ito ay nakarehistro na',
        'lrn_available' => 'Available ang LRN',
        'checking' => 'Sinusuri…',
        'name_duplicate_title' => 'May katulad na pangalan',
        'name_duplicate_msg' => 'May mag-aaral na may katulad na pangalan. Ibang tao ba ito?',
        'name_duplicate_yes' => 'Oo, i-enroll pa rin',
        'name_duplicate_no' => 'Kanselahin',
        'select_one_entrance' => 'Pumili ng kahit isang dokumento sa pagpasok',
        'transferred_in' => 'Lumipat mula sa ibang paaralan (Transferred IN)',
        'prev_school_name' => 'Pangalan ng Dating Paaralan',
        'prev_school_address' => 'Address ng Dating Paaralan',
        'prev_track' => 'Dating TRACK',
        'prev_strand' => 'Dating STRAND',
        'prev_program' => 'Dating PROGRAMA',
        'prev_year_completed' => 'Taong Natapos sa Dating Paaralan',
        'voucher_qualified' => 'Katayuan ng Voucher (para sa lumipat)',
        'qualified_voucher' => 'Qualified Voucher Recipient',
        'not_qualified_voucher' => 'Hindi Qualified Voucher Recipient',
        'skip_to_content' => 'Lumaktaw sa pangunahing nilalaman',
        'language_switch' => 'Pagpili ng wika',
        'english' => 'Ingles',
        'filipino' => 'Filipino',
        'progress_label' => 'Progreso ng porma',
        'step' => 'Hakbang',
        'current_step' => 'Kasalukuyang hakbang',
        'completed_step' => 'Natapos na hakbang',
        'incomplete_step' => 'Hindi kumpleto - kailangan ng atensyon',
        'error_summary' => 'Pakiayos ang mga sumusunod bago magpatuloy:',
        'upload_problem' => 'Problema sa pag-upload ng file:',
        'review_confirm' => 'Suriin at Kumpirmahin',
        'review_help' => 'Pakisuri ang iyong impormasyon bago magsumite',
        'edit_btn' => 'I-edit',
        'confirm_save' => 'Kumpirmahin at I-save',
        'docs_selected' => 'Mga Napiling Dokumento',
        'staged_files' => 'Mga Naka-staging na File',
        'no_files' => 'Walang nakalakip na file. Maaari ka pa ring magsumite at mag-upload mamaya.',
        'none_selected' => 'Walang napili',
        'camera_modal_title' => 'Kumuha ng larawan ng dokumento',
        'camera_capture' => 'Kunan',
        'camera_cancel' => 'Kanselahin',
        'photo_preview' => 'Preview ng larawan',
        'upload_file' => 'Mag-upload ng File',
        'or_capture' => 'o Kunan',
        'document_label' => 'Label ng Dokumento',
        'already_staged' => 'Naka-staging na — mag-upload muli upang palitan',
        'level_label' => 'Antas',
        'close' => 'Isara',
        'close_alert' => 'Isara ang mensaheng ito',
        'back_to_login' => 'Bumalik sa Login',
        'select_placeholder' => '— Pumili —',
        'other_specify' => 'Iba pa (ispesipiko)',
        'religion_other_placeholder' => 'Ilagay ang relihiyon',
    ]
];

/* =========================================================
   MASTER DROPDOWN LISTS
   ========================================================= */
$CIVIL_STATUS_OPTIONS = ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'];
$NATIONALITY_OPTIONS  = ['Filipino', 'American', 'Chinese', 'Japanese', 'Korean', 'Australian', 'British', 'Canadian', 'Indian', 'Spanish', 'Other'];
$RELIGION_OPTIONS     = [
    'Roman Catholic', 'Islam', 'Iglesia ni Cristo', 'Protestant',
    'Born Again Christian', 'Seventh-Day Adventist', "Jehovah's Witnesses",
    'Bible Baptist', 'Mormon (LDS)', 'Buddhism', 'Hinduism', 'Atheist',
];
$TRACK_OPTIONS = ['TECHPRO ELECTIVES', 'ACADEMIC', 'TVL', 'SPORTS', 'ARTS & DESIGN'];
$STRAND_PROGRAMS = [
    'Automotive and Small Engine Technologies' => [
        'Driving and Automotive Servicing',
        'Automotive Servicing (Electrical Repair)',
        'Automotive Servicing (Engine and Chassis Repairs)',
    ],
    'Construction and Building Technologies' => [
        'Carpentry',
        'Manual Metal Arc Welding',
    ],
    'ICT Support and Computer Programming Technologies' => [
        'Computer Programming (Java)',
        'Computer Programming (.NET)',
        'Computer System Servicing',
    ],
    'Industrial Technologies' => [
        'Electronics Product Assembly and Servicing',
    ],
    'Agri-Fishery Business and Food Innovation' => [
        'Agricultural Crops Production',
    ],
    'Hospitality and Tourism' => [
        'Food and Beverage Operation',
        'Hotel Operation (Housekeeping)',
    ],
];

$current_year = (int)date('Y');
$SY_OPTIONS = [];
for ($y = $current_year - 1; $y <= $current_year + 2; $y++) {
    $SY_OPTIONS[] = $y . '-' . ($y + 1);
}

/* =========================================================
   SHARED HELPERS
   ========================================================= */

function jhs_can_submit(?array $nv): bool
{
    if (!is_array($nv) || $nv === []) return true;
    $status = (string)($nv['status'] ?? 'unverifiable');
    return in_array($status, ['match', 'partial'], true);
}

function photo_can_submit(?array $p2): bool
{
    if (!is_array($p2) || $p2 === []) return true;
    $status = (string)($p2['status'] ?? 'match');
    return in_array($status, ['match', 'partial'], true);
}

function db_prepare_or_log(mysqli $conn, string $sql, string $ctx): ?mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log("[enroll] prepare() failed ({$ctx}): " . $conn->error);
        return null;
    }
    return $stmt;
}

function mask_lrn(string $lrn): string
{
    $lrn = trim($lrn);
    if ($lrn === '') return '';
    if (strlen($lrn) <= 4) return str_repeat('*', strlen($lrn));
    return str_repeat('*', strlen($lrn) - 4) . substr($lrn, -4);
}

/* =========================================================
   AJAX ENDPOINTS
   ========================================================= */
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $action = (string)$_GET['action'];
    $allowed_actions = ['check_lrn', 'check_name', 'strand_trends'];
    if (!in_array($action, $allowed_actions, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    }

    $client_ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    $needs_csrf = in_array($action, ['check_lrn', 'check_name'], true);
    if ($needs_csrf) {
        $token = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if ($token === '' || !hash_equals((string)$_SESSION['csrf_token'], $token)) {
            security_log('ajax.csrf_fail', ['action' => $action, 'ip' => $client_ip]);
            http_response_code(403);
            echo json_encode(['error' => 'csrf']);
            exit;
        }
    }

    $rl_limits = [
        'check_lrn'     => [15, 60],
        'check_name'    => [30, 60],
        'strand_trends' => [60, 60],
    ];
    [$limit, $window] = $rl_limits[$action];
    $rl = rate_limit_session('ajax_' . $action . '_' . $client_ip, $limit, $window);
    if (empty($rl['allowed'])) {
        security_log('ajax.ratelimited', ['action' => $action, 'ip' => $client_ip]);
        http_response_code(429);
        echo json_encode(['error' => 'rate_limited']);
        exit;
    }

    if ($action === 'check_lrn') {
        $lrn = preg_replace('/\D/', '', (string)($_POST['lrn'] ?? ''));
        if (!is_valid_lrn($lrn)) {
            echo json_encode(['valid' => false, 'exists' => false, 'message' => 'LRN must be 12 digits']);
            exit;
        }
        $stmt = db_prepare_or_log($conn, "
            SELECT s.student_id, s.first_name, s.middle_name, s.last_name, e.grade_level, e.school_year
            FROM students_info s
            LEFT JOIN enrollment_form e ON s.student_id = e.student_id
            WHERE s.lrn = ? ORDER BY e.enrollment_id DESC LIMIT 1
        ", 'check_lrn');
        if ($stmt === null) {
            http_response_code(500);
            echo json_encode(['error' => 'server']);
            exit;
        }
        $stmt->bind_param("s", $lrn);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $existing = null;
        if ($row) {
            $existing = [
                'student_id'  => (int)$row['student_id'],
                'name'        => trim(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '')),
                'grade_level' => $row['grade_level'] ?? null,
                'school_year' => $row['school_year'] ?? null,
            ];
        }
        echo json_encode(['valid' => true, 'exists' => $row !== null, 'existing' => $existing]);
        exit;
    }

    if ($action === 'check_name') {
        $fn = trim((string)($_POST['first_name'] ?? ''));
        $mn = trim((string)($_POST['middle_name'] ?? ''));
        $ln = trim((string)($_POST['last_name'] ?? ''));
        if ($fn === '' || $ln === '') {
            echo json_encode(['checked' => false, 'matches' => []]);
            exit;
        }

        if ($mn !== '') {
            $stmt = db_prepare_or_log($conn, "
                SELECT s.student_id, s.lrn, s.first_name, s.middle_name, s.last_name,
                       e.grade_level, e.school_year
                FROM students_info s
                LEFT JOIN enrollment_form e ON s.student_id = e.student_id
                WHERE LOWER(s.first_name) = LOWER(?)
                  AND LOWER(s.last_name)  = LOWER(?)
                  AND LOWER(COALESCE(s.middle_name, '')) = LOWER(?)
                ORDER BY e.enrollment_id DESC LIMIT 5
            ", 'check_name_mn');
            if ($stmt === null) {
                http_response_code(500);
                echo json_encode(['error' => 'server']);
                exit;
            }
            $stmt->bind_param("sss", $fn, $ln, $mn);
        } else {
            $stmt = db_prepare_or_log($conn, "
                SELECT s.student_id, s.lrn, s.first_name, s.middle_name, s.last_name,
                       e.grade_level, e.school_year
                FROM students_info s
                LEFT JOIN enrollment_form e ON s.student_id = e.student_id
                WHERE LOWER(s.first_name) = LOWER(?) AND LOWER(s.last_name) = LOWER(?)
                ORDER BY e.enrollment_id DESC LIMIT 5
            ", 'check_name_nomn');
            if ($stmt === null) {
                http_response_code(500);
                echo json_encode(['error' => 'server']);
                exit;
            }
            $stmt->bind_param("ss", $fn, $ln);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $matches = [];
        while ($r = $res->fetch_assoc()) {
            $matches[] = [
                'student_id'  => (int)$r['student_id'],
                'name'        => trim(($r['last_name'] ?? '') . ', ' . ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '')),
                'lrn_masked'  => mask_lrn((string)($r['lrn'] ?? '')),
                'grade_level' => $r['grade_level'] ?? null,
                'school_year' => $r['school_year'] ?? null,
            ];
        }
        $stmt->close();
        echo json_encode(['checked' => true, 'matches' => $matches]);
        exit;
    }

    if ($action === 'strand_trends') {
        $res = $conn->query("
            SELECT school_year FROM enrollment_form
            WHERE school_year IS NOT NULL AND school_year != ''
            ORDER BY school_year DESC LIMIT 1
        ");
        if ($res === false) {
            error_log('[enroll] strand_trends latest query failed: ' . $conn->error);
            echo json_encode(['sy' => null, 'total' => 0, 'trends' => []]);
            exit;
        }
        $latest_row = $res->fetch_assoc();
        $latest_sy = $latest_row['school_year'] ?? null;
        $trends = []; $total = 0;

        if ($latest_sy) {
            $prev_sy = null;
            if (preg_match('/^(\d{4})-(\d{4})$/', (string)$latest_sy, $m)) {
                $prev_sy = ((int)$m[1] - 1) . '-' . ((int)$m[2] - 1);
            }
            $current_counts = [];
            $stmt = db_prepare_or_log($conn, "
                SELECT strand, COUNT(DISTINCT student_id) AS enrolled
                FROM enrollment_form
                WHERE school_year = ? AND strand IS NOT NULL AND strand != ''
                GROUP BY strand
            ", 'trends_current');
            if ($stmt === null) {
                echo json_encode(['sy' => $latest_sy, 'total' => 0, 'trends' => []]);
                exit;
            }
            $stmt->bind_param('s', $latest_sy);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $current_counts[$r['strand']] = (int)$r['enrolled'];
            $stmt->close();
            $total = array_sum($current_counts);

            $prev_counts = [];
            if ($prev_sy) {
                $stmt = db_prepare_or_log($conn, "
                    SELECT strand, COUNT(DISTINCT student_id) AS enrolled
                    FROM enrollment_form
                    WHERE school_year = ? AND strand IS NOT NULL AND strand != ''
                    GROUP BY strand
                ", 'trends_prev');
                if ($stmt !== null) {
                    $stmt->bind_param('s', $prev_sy);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($r = $res->fetch_assoc()) $prev_counts[$r['strand']] = (int)$r['enrolled'];
                    $stmt->close();
                }
            }

            foreach ($current_counts as $strand => $curr) {
                $prev = $prev_counts[$strand] ?? 0;
                $growth = null;
                if ($prev > 0) $growth = round((($curr - $prev) / $prev) * 100, 1);
                elseif ($curr > 0) $growth = 'new';
                $trends[$strand] = [
                    'current'  => $curr,
                    'previous' => $prev,
                    'pct'      => $total > 0 ? round(($curr / $total) * 100, 1) : 0,
                    'growth'   => $growth,
                ];
            }
        }
        echo json_encode(['sy' => $latest_sy, 'total' => $total, 'trends' => $trends]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

/* =========================================================
   VALIDATION RULES
   ========================================================= */
$errors = [];
$fields = [
    'lrn' => ['required' => true, 'pattern' => '/^\d{12}$/', 'msg' => 'invalid_lrn'],
    'last_name'  => ['required' => true, 'pattern' => "/^[A-Za-zÀ-ÿ\s\-'.]{2,}$/u", 'msg' => 'invalid_name'],
    'first_name' => ['required' => true, 'pattern' => "/^[A-Za-zÀ-ÿ\s\-'.]{2,}$/u", 'msg' => 'invalid_name'],
    'sex' => ['required' => true],
    'birth_date' => ['required' => true],
    'school_year' => ['required' => true],
    'grade_level' => ['required' => true],
    'term' => ['required' => true],
    'strand' => ['required' => true],
    'program' => ['required' => true],
    'is_4ps' => ['required' => true]
];

const HEIGHT_MIN = 100;
const HEIGHT_MAX = 250;
const WEIGHT_MIN = 20;
const WEIGHT_MAX = 300;

function purge_old_staging(): void
{
    if (!is_dir(STAGING_DIR)) return;
    $now = time();
    foreach (glob(STAGING_DIR . '*') as $f) {
        if (is_file($f) && ($now - filemtime($f)) > 7200) @unlink($f);
    }
}

function stage_file(string $tmp_path, string $orig_name, string $label, ?string &$err = null): ?array
{
    try {
        $info = validate_upload($tmp_path, $orig_name, [
            'max_bytes'   => 5 * 1024 * 1024,
            'allowed'     => ['application/pdf', 'image/jpeg', 'image/png'],
            'image_min_w' => 100,
            'image_min_h' => 100,
        ]);

        $staged = move_upload_securely($tmp_path, STAGING_DIR, $info['ext']);

        return [
            'label'        => $label,
            'staging_path' => $staged,
            'mime'         => $info['mime'],
            'name'         => $info['safe_original'],
            'size'         => $info['size'],
            'sha256'       => $info['sha256'],
        ];
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
        security_log('upload.rejected', [
            'label'  => $label,
            'reason' => $e->getMessage(),
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        return null;
    }
}

function stage_bytes(string $bin, string $label, string $orig_name, ?string &$err = null): ?array
{
    $tmp = null;
    try {
        if ($bin === '' || strlen($bin) > 5 * 1024 * 1024) {
            throw new RuntimeException('Invalid capture size.');
        }

        $head = substr($bin, 0, 8192);
        if (preg_match('/<\?(php|=|\s)/i', $head)
            || stripos($head, '<script') !== false
            || stripos($head, '<svg') !== false) {
            throw new RuntimeException('Capture contains disallowed markup.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'cap_');
        if ($tmp === false || file_put_contents($tmp, $bin) === false) {
            throw new RuntimeException('Cannot stage capture.');
        }
        @chmod($tmp, 0600);

        $info = validate_upload($tmp, $orig_name, [
            'max_bytes'   => 5 * 1024 * 1024,
            'allowed'     => ['image/jpeg', 'image/png'],
            'image_min_w' => 100,
            'image_min_h' => 100,
        ]);

        $staged = move_upload_securely($tmp, STAGING_DIR, $info['ext']);

        return [
            'label'        => $label,
            'staging_path' => $staged,
            'mime'         => $info['mime'],
            'name'         => $info['safe_original'],
            'size'         => $info['size'],
            'sha256'       => $info['sha256'],
        ];
    } catch (RuntimeException $e) {
        if ($tmp !== null && is_file($tmp)) @unlink($tmp);
        $err = $e->getMessage();
        security_log('capture.rejected', ['label' => $label, 'reason' => $e->getMessage()]);
        return null;
    }
}

$doc_options = [
    'Good Moral Certificate'                    => 'good_moral',
    'Junior High School Certificate (Front)'    => 'jhs_certificate_front',
    'Junior High School Certificate (Back)'     => 'jhs_certificate_back',
    'NSO/PSA Birth Certificate (Original)'      => 'birth_certificate',
    '2 pcs 2×2 picture'                         => 'pictures_2x2'
];

/* =========================================================
   POST HANDLING
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    purge_old_staging();

    $rl_key = 'enroll_submit_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rl = rate_limit_session($rl_key, 5, 300);
    if (empty($rl['allowed'])) {
        security_log('enroll.ratelimited', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        $_SESSION['error'] = 'Too many submission attempts. Please wait a few minutes.';
        header('Location: enroll_form.php');
        exit;
    }

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        security_log('enroll.csrf_fail', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        die('Invalid CSRF token');
    }

    if (isset($_POST['lrn'])) {
        $_POST['lrn'] = preg_replace('/\D/', '', (string)$_POST['lrn']);
    }

    foreach ($fields as $f => $rules) {
        $val = $_POST[$f] ?? '';
        if ($f === 'is_4ps') {
            if (($val === '' || $val === null) && $rules['required']) $errors[$f] = $t[$lang]['required'];
        } else {
            $val = trim((string)$val);
            if ($rules['required'] && empty($val)) $errors[$f] = $t[$lang]['required'];
            elseif (!empty($val) && isset($rules['pattern']) && !preg_match($rules['pattern'], $val)) $errors[$f] = $t[$lang][$rules['msg']];
        }
    }

    if (($_POST['religion'] ?? '') === '__other__') {
        $other = clean_string($_POST['religion_other'] ?? '', 100);
        $_POST['religion'] = $other;
    }

    if (!empty($_POST['height'])) {
        $h = (float)$_POST['height'];
        if ($h < HEIGHT_MIN || $h > HEIGHT_MAX) $errors['height'] = $t[$lang]['invalid_height'];
    }

    if (!empty($_POST['weight'])) {
        $w = (float)$_POST['weight'];
        if ($w < WEIGHT_MIN || $w > WEIGHT_MAX) $errors['weight'] = $t[$lang]['invalid_weight'];
    }

    if (!empty($_POST['birth_date'])) {
        $birth = DateTime::createFromFormat('Y-m-d', (string)$_POST['birth_date']);
        if (!$birth) $errors['birth_date'] = $t[$lang]['required'];
        else {
            $age = (new DateTime())->diff($birth)->y;
            if ($age < 14 || $age > 25) $errors['birth_date'] = $t[$lang]['invalid_age'];
            $_POST['age'] = $age;
        }
    }

    foreach (['phone', 'father_contact', 'mother_contact', 'guardian_contact'] as $field) {
        if (!empty($_POST[$field]) && !is_valid_ph_mobile((string)$_POST[$field])) {
            $errors[$field] = $t[$lang]['invalid_phone'];
        }
    }

    if (empty($_POST['entrance_data'] ?? [])) $errors['entrance_data'] = $t[$lang]['select_one_entrance'];
    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = $t[$lang]['invalid_email'];

    if (empty($errors['lrn']) && !empty($_POST['lrn'])) {
        $lrn_check = db_prepare_or_log($conn, "SELECT s.student_id, s.first_name, s.last_name FROM students_info s WHERE s.lrn = ? LIMIT 1", 'post_lrn_dup');
        if ($lrn_check !== null) {
            $lrn_check->bind_param("s", $_POST['lrn']);
            $lrn_check->execute();
            $dup = $lrn_check->get_result()->fetch_assoc();
            $lrn_check->close();
            if ($dup) {
                $errors['lrn'] = $t[$lang]['lrn_exists'] . ': ' . trim(($dup['last_name'] ?? '') . ', ' . ($dup['first_name'] ?? ''));
            }
        }
    }

    if (empty($errors['first_name']) && empty($errors['last_name'])) {
        $fn = trim((string)($_POST['first_name'] ?? ''));
        $mn = trim((string)($_POST['middle_name'] ?? ''));
        $ln = trim((string)($_POST['last_name'] ?? ''));

        if ($fn !== '' && $ln !== '') {
            if ($mn !== '') {
                $nc = db_prepare_or_log($conn, "SELECT student_id FROM students_info WHERE LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?) AND LOWER(COALESCE(middle_name,''))=LOWER(?) LIMIT 1", 'post_name_dup_mn');
                if ($nc !== null) $nc->bind_param("sss", $fn, $ln, $mn);
            } else {
                $nc = db_prepare_or_log($conn, "SELECT student_id FROM students_info WHERE LOWER(first_name)=LOWER(?) AND LOWER(last_name)=LOWER(?) LIMIT 1", 'post_name_dup_nomn');
                if ($nc !== null) $nc->bind_param("ss", $fn, $ln);
            }
            if ($nc !== null) {
                $nc->execute();
                $name_dup_exists = $nc->get_result()->num_rows > 0;
                $nc->close();
                if ($name_dup_exists && empty($_POST['acknowledge_duplicate_name'])) $errors['name_duplicate'] = 'duplicate_name';
            }
        }
    }

    $prev = $_SESSION['uploaded_files'] ?? [];
    $uploaded_files = [];
    $checked_labels = $_POST['entrance_data'] ?? [];

    foreach ($prev as $doc_key => $info) {
        $label = $info['label'] ?? '';
        $path  = $info['staging_path'] ?? '';
        if (in_array($label, $checked_labels, true) && $path !== '' && is_file($path)) $uploaded_files[$doc_key] = $info;
        elseif ($path !== '' && is_file($path)) @unlink($path);
    }

    $upload_errors = [];

    if (!empty($_FILES['entrance_files']['name']) && is_array($_FILES['entrance_files']['name'])) {
        foreach ($_FILES['entrance_files']['name'] as $doc_key => $name) {
            if ($_FILES['entrance_files']['error'][$doc_key] !== UPLOAD_ERR_OK || $name === '') continue;
            $label = array_search($doc_key, $doc_options, true) ?: $doc_key;
            $err = null;
            $meta = stage_file($_FILES['entrance_files']['tmp_name'][$doc_key], (string)$name, (string)$label, $err);
            if ($meta) {
                if (!empty($uploaded_files[$doc_key]['staging_path'])) @unlink($uploaded_files[$doc_key]['staging_path']);
                $uploaded_files[$doc_key] = $meta;
            } else {
                $upload_errors[] = ($label ?: $doc_key) . ': ' . ($err ?: 'Upload failed');
            }
        }
    }

    if (!empty($_POST['camera_data']) && is_array($_POST['camera_data'])) {
        foreach ($_POST['camera_data'] as $doc_key => $data) {
            if (empty($data) || strpos((string)$data, 'data:image/') !== 0) continue;
            $comma = strpos((string)$data, ',');
            if ($comma === false) continue;
            $bin = base64_decode(substr((string)$data, $comma + 1), true);
            if ($bin === false || $bin === '') continue;
            $label = array_search($doc_key, $doc_options, true) ?: $doc_key;
            $err = null;
            $meta = stage_bytes($bin, (string)$label, $label . '.jpg', $err);
            if ($meta) {
                if (!empty($uploaded_files[$doc_key]['staging_path'])) @unlink($uploaded_files[$doc_key]['staging_path']);
                $uploaded_files[$doc_key] = $meta;
            } else {
                $upload_errors[] = ($label ?: $doc_key) . ': ' . ($err ?: 'Capture failed');
            }
        }
    }

    if (!empty($upload_errors)) {
        $errors['upload'] = implode(' · ', $upload_errors);
    }

    unset($_SESSION['name_verify']);
    $jhs_key = 'jhs_certificate_front';

    if (!empty($uploaded_files[$jhs_key]['staging_path'])
        && is_file($uploaded_files[$jhs_key]['staging_path'])) {
        $_SESSION['name_verify'] = verify_name_in_document(
            $uploaded_files[$jhs_key]['staging_path'],
            (string)($uploaded_files[$jhs_key]['mime'] ?? ''),
            [
                'first_name'  => $_POST['first_name']  ?? '',
                'middle_name' => $_POST['middle_name'] ?? '',
                'last_name'   => $_POST['last_name']   ?? '',
                'lrn'         => $_POST['lrn']         ?? '',
            ]
        );
    }

    if (!jhs_can_submit($_SESSION['name_verify'] ?? null)) {
        $nv_status = (string)($_SESSION['name_verify']['status'] ?? 'unverifiable');
        $errors['document_mismatch'] = $nv_status === 'mismatch'
            ? 'The Junior High School Certificate (Front) you uploaded does not match your name or LRN. Please re-upload a valid document before submitting.'
            : 'The Junior High School Certificate (Front) could not be verified automatically. Please re-upload a clearer copy.';
    }

    unset($_SESSION['photo_2x2_check']);
    $photo_key = 'pictures_2x2';

    if (!empty($uploaded_files[$photo_key]['staging_path'])
        && is_file($uploaded_files[$photo_key]['staging_path'])) {
        $_SESSION['photo_2x2_check'] = verify_2x2_photo(
            $uploaded_files[$photo_key]['staging_path'],
            (string)($uploaded_files[$photo_key]['mime'] ?? '')
        );
    }

    if (!photo_can_submit($_SESSION['photo_2x2_check'] ?? null)) {
        $errors['photo_2x2'] = $_SESSION['photo_2x2_check']['message'] ?? 'The 2×2 picture failed its format check.';
    }

    if (empty($errors)) {
        $form_data = $_POST;
        unset($form_data['camera_data']);
        $_SESSION['form_data'] = $form_data;
        $_SESSION['uploaded_files'] = $uploaded_files;
        header('Location: ' . basename(__FILE__) . '?review=1');
        exit;
    } else {
        if (isset($errors['name_duplicate'])) $_SESSION['name_dup_pending'] = true;
        $_SESSION['errors'] = $errors;
        $old = $_POST;
        unset($old['camera_data']);
        $_SESSION['old'] = $old;
        $_SESSION['uploaded_files'] = $uploaded_files;
        header('Location: ' . basename(__FILE__));
        exit;
    }
}

/* =========================================================
   DISPLAY MODES
   ========================================================= */
if (isset($_GET['edit'])) {
    $mode = 'form';
    if (isset($_SESSION['form_data'])) $_POST = $_SESSION['form_data'];
} elseif (isset($_GET['review']) && isset($_SESSION['form_data'])) {
    $mode = 'review';
    $_POST = $_SESSION['form_data'];
} else {
    $mode = 'form';
    if (isset($_SESSION['old'])) {
        $_POST = $_SESSION['old'];
        unset($_SESSION['old']);
    } elseif (isset($_SESSION['form_data'])) {
        $_POST = $_SESSION['form_data'];
    }
    if (isset($_SESSION['errors'])) {
        $errors = $_SESSION['errors'];
        unset($_SESSION['errors']);
    }
}

$name_dup_pending = false;
if (!empty($_SESSION['name_dup_pending'])) {
    $name_dup_pending = true;
    unset($_SESSION['name_dup_pending']);
    unset($errors['name_duplicate']);
}

$edu_history = [];
if (isset($_POST['edu_level']) && is_array($_POST['edu_level'])) {
    foreach ($_POST['edu_level'] as $i => $level) {
        $edu_history[] = [
            'level' => $level,
            'school_name' => $_POST['school_name'][$i] ?? '',
            'school_address' => $_POST['school_address'][$i] ?? '',
            'year_completed' => $_POST['year_completed'][$i] ?? ''
        ];
    }
}
if (empty($edu_history)) {
    $edu_history[] = ['level' => 'Elementary', 'school_name' => '', 'school_address' => '', 'year_completed' => ''];
}

$display_files = $_SESSION['uploaded_files'] ?? [];

$field_has_error = function(string $name) use ($errors): bool {
    return isset($errors[$name]);
};

$filled_class = static function ($v): string {
    if (is_array($v)) return '';
    return ($v !== null && trim((string)$v) !== '') ? ' filled' : '';
};

$field_step_map = [
    'lrn' => 1, 'last_name' => 1, 'first_name' => 1, 'middle_name' => 1,
    'nick_name' => 1, 'ext_name' => 1, 'sex' => 1, 'birth_date' => 1,
    'age' => 1, 'civil_status' => 1, 'nationality' => 1, 'religion' => 1,
    'height' => 1, 'weight' => 1, 'email' => 1, 'phone' => 1, 'special_skills' => 1,
    'father_name' => 2, 'father_occupation' => 2, 'father_contact' => 2,
    'mother_maiden_name' => 2, 'mother_occupation' => 2, 'mother_contact' => 2,
    'ave_family_income' => 2, 'is_4ps' => 2,
    'guardian_fullname' => 2, 'guardian_relation' => 2, 'guardian_contact' => 2,
    'purok_street' => 3, 'barangay' => 3, 'town_city' => 3, 'province' => 3,
    'region' => 3, 'district' => 3, 'postal_code' => 3,
    'edu_level' => 4, 'school_name' => 4, 'school_address' => 4, 'year_completed' => 4,
    'school_year' => 5, 'grade_level' => 5, 'term' => 5, 'strand' => 5, 'program' => 5,
    'household_id' => 5, 'entrance_data' => 5, 'upload' => 5,
    'voucher_qualified' => 5, 'previous_school_name' => 5,
    'document_mismatch' => 5, 'photo_2x2' => 5,
];

$total_steps = 5;

$is_edit_mode = isset($_GET['edit']) || isset($_GET['review']);

$enroll_error = $_SESSION['enroll_error'] ?? null;
unset($_SESSION['enroll_error']);

if ($is_edit_mode) {
    $enroll_error = null;
    unset($_SESSION['error'], $_SESSION['name_dup_pending']);
}

$initial_step = 1;
if (isset($_GET['edit'])) {
    $initial_step = 5;
}
if (!empty($_GET['step'])) {
    $initial_step = max(1, min($total_steps, (int)$_GET['step']));
}
if ($enroll_error && !empty($enroll_error['step'])) {
    $initial_step = max(1, min($total_steps, (int)$enroll_error['step']));
} elseif (!empty($errors)) {
    $first_step = PHP_INT_MAX;
    foreach ($errors as $field => $msg) {
        $s = $field_step_map[$field] ?? 1;
        if ($s < $first_step) $first_step = $s;
    }
    if ($first_step !== PHP_INT_MAX) $initial_step = $first_step;
}

$cur_civil_status = trim((string)($_POST['civil_status'] ?? ''));
$cur_nationality  = trim((string)($_POST['nationality']  ?? ''));
$cur_religion     = trim((string)($_POST['religion']     ?? ''));
$cur_sy           = trim((string)($_POST['school_year']  ?? ($SY_OPTIONS[1] ?? '')));
$cur_prev_track   = trim((string)($_POST['previous_track'] ?? ''));
$cur_prev_strand  = trim((string)($_POST['previous_strand'] ?? ''));
$cur_prev_program = trim((string)($_POST['previous_program'] ?? ''));

$religion_is_known = in_array($cur_religion, $RELIGION_OPTIONS, true);
$religion_select_value = $religion_is_known ? $cur_religion : ($cur_religion !== '' ? '__other__' : '');

$JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($t[$lang]['form'], ENT_QUOTES, 'UTF-8') ?> | USAT College</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;color:#1a202c}

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                radial-gradient(circle at 20% 30%, rgba(255,255,255,.05) 0, transparent 42%),
                radial-gradient(circle at 78% 68%, rgba(255,255,255,.035) 0, transparent 45%),
                radial-gradient(circle at 50% 90%, rgba(255,255,255,.025) 0, transparent 55%);
            pointer-events: none;
            z-index: -1;
        }

        :focus-visible { outline: 3px solid #ffd700 !important; outline-offset: 2px !important; border-radius: 4px; }
        .form-group input:focus-visible,
        .form-group select:focus-visible,
        .form-group textarea:focus-visible { outline: 3px solid #1565c0 !important; outline-offset: 1px !important; }

        .skip-link { position: absolute; top: -100px; left: 1rem; background: #1a202c; color: #fff; padding: 0.75rem 1.25rem; border-radius: 0 0 8px 8px; font-weight: 600; z-index: 10000; text-decoration: none; transition: top 0.2s; }
        .skip-link:focus { top: 0; color: #fff; }

        .hero-header{background:linear-gradient(135deg,rgba(30,136,229,0.95),rgba(21,101,192,0.95));backdrop-filter:blur(10px);padding:2rem 1rem;text-align:center;color:white;border-bottom:4px solid #ffd700;margin-bottom:1.5rem;position:relative;overflow:hidden}
        .hero-header img{max-width:90px;border-radius:50%;border:4px solid #ffd700;box-shadow:0 10px 30px rgba(0,0,0,0.2)}
        .hero-header h1{font-size:1.75rem;font-weight:700;margin-top:.85rem}
        .hero-header p{font-size:1rem;opacity:0.95}
        .form-container{max-width:1100px;margin:0 auto;padding:0 1.25rem 2rem}

        .wizard-progress-wrap{background:rgba(255,255,255,0.98);border-radius:999px;padding:1rem 1.5rem;margin-bottom:1.5rem;box-shadow:0 12px 30px -12px rgba(0,0,0,0.18);position:sticky;top:12px;z-index:100}
        .wizard-progress{display:flex;justify-content:space-between;align-items:center;gap:0.5rem;list-style:none;margin:0;padding:0}
        .wizard-step-indicator{flex:1;display:flex;flex-direction:column;align-items:center;gap:0.4rem;padding:0.4rem 0.25rem;cursor:pointer;transition:transform 0.2s ease;position:relative;background:none;border:none;font-family:inherit;user-select:none}
        .wizard-step-indicator:disabled{cursor:not-allowed;opacity:0.6}
        .wizard-step-indicator:not(:disabled):hover .wizard-step-num{transform:scale(1.08)}
        .wizard-step-num{width:38px;height:38px;border-radius:50%;background:#e2e8f0;color:#4a5568;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;transition:all 0.3s ease;box-shadow:0 0 0 4px rgba(226,232,240,0.45)}
        .wizard-step-label{font-size:0.72rem;font-weight:600;color:#64748b;letter-spacing:0.2px;text-align:center;white-space:nowrap}
        .wizard-step-indicator.completed .wizard-step-num{background:linear-gradient(135deg,#10b981,#047857);color:#fff;box-shadow:0 0 0 4px rgba(16,185,129,0.22)}
        .wizard-step-indicator.completed .wizard-step-label{color:#047857}
        .wizard-step-indicator.active .wizard-step-num{background:linear-gradient(135deg,#1e88e5,#1565c0);color:#fff;box-shadow:0 0 0 6px rgba(30,136,229,0.22),0 4px 14px rgba(30,136,229,0.35);transform:scale(1.08)}
        .wizard-step-indicator.active .wizard-step-label{color:#1565c0;font-weight:800}
        .wizard-step-indicator.invalid .wizard-step-num{background:linear-gradient(135deg,#ef4444,#b91c1c);color:#fff;box-shadow:0 0 0 4px rgba(239,68,68,0.22)}
        .wizard-step-indicator.invalid .wizard-step-label{color:#b91c1c}

        .wizard-step-content{display:none}
        .wizard-step-content.active{display:block;animation:stepFade 0.25s ease}
        @keyframes stepFade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

        .section-card{background:rgba(255,255,255,0.98);border-radius:20px;padding:1.75rem;margin-bottom:1.25rem;box-shadow:0 20px 40px -15px rgba(0,0,0,0.15)}
        .section-header{display:flex;align-items:center;gap:12px;margin-bottom:1.25rem;padding-bottom:1rem;border-bottom:2px solid #e2e8f0}
        .section-header i{font-size:1.6rem;color:#1e88e5}
        .section-header h2{font-size:1.35rem;font-weight:600;color:#1a202c}

        .form-group{position:relative;margin-bottom:1rem}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:0.85rem 1rem;border:2px solid #e2e8f0;border-radius:12px;font-size:0.95rem;background:white}
        .form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:#1e88e5;box-shadow:0 0 0 3px rgba(30,136,229,0.1);outline:none}
        .form-group label{position:absolute;left:1rem;top:0.85rem;color:#718096;font-size:0.95rem;pointer-events:none;transition:all 0.3s ease;background:white;padding:0 0.4rem;font-weight:500;z-index:1}
        .form-group.filled label,.form-group input:focus+label,.form-group select:focus+label,.form-group textarea:focus+label{top:-0.6rem;font-size:0.75rem;color:#1e88e5;font-weight:600}

        .form-group input:not([type="date"]):not([type="file"]):not([type="checkbox"]):not([type="radio"]):not(:placeholder-shown) + label,
        .form-group textarea:not(:placeholder-shown) + label,
        .form-group select:has(option:checked:not([value=""])) + label {
            top: -0.6rem;
            font-size: 0.75rem;
            color: #1e88e5;
            font-weight: 600;
        }

        .form-group select{appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'><path fill='none' stroke='%23718096' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' d='M1 1.5l5 5 5-5'/></svg>");background-repeat:no-repeat;background-position:right 1rem center;background-size:12px 8px;padding-right:2.4rem;cursor:pointer}

        .checkbox-container{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:0.8rem;margin:1rem 0}
        .checkbox-item input[type="checkbox"]{width:auto;margin-right:0.5rem}
        .checkbox-item label{position:static;display:inline;pointer-events:auto}
        .transferee-section{margin-top:1.5rem;padding:1.5rem;background:linear-gradient(135deg,#f8f9fa 0%,#e9ecef 100%);border-radius:16px;border-left:4px solid #1e88e5}

        .review-container{background:white;border-radius:20px;padding:2rem;box-shadow:0 20px 40px -15px rgba(0,0,0,0.1)}
        .review-section{margin-bottom:2rem;padding:1.5rem;background:#f8f9fa;border-radius:12px;border-left:4px solid #1e88e5}
        .review-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:0.8rem}
        .review-item{padding:0.5rem;border-bottom:1px solid #dee2e6}
        .review-item strong{color:#2d3748;font-weight:600}

        .lang-switch{position:fixed;top:20px;right:20px;z-index:1000;background:white;border-radius:50px;padding:0.5rem;box-shadow:0 2px 10px rgba(0,0,0,0.1);display:flex;gap:0.25rem}
        .lang-btn{padding:0.5rem 1rem;border:none;background:none;font-weight:600;transition:all 0.3s ease;border-radius:50px;cursor:pointer}
        .lang-btn.active{background:#1e88e5;color:white}

        .back-to-login{position:fixed;top:20px;left:20px;z-index:1000;display:inline-flex;align-items:center;gap:0.5rem;padding:0.6rem 1.15rem;background:rgba(255,255,255,0.98);color:#1e293b;font-weight:600;font-size:0.85rem;text-decoration:none;border-radius:50px;box-shadow:0 4px 14px rgba(0,0,0,0.12);border:1px solid rgba(0,0,0,0.05);transition:transform 0.2s ease,box-shadow 0.2s ease,background 0.2s ease;backdrop-filter:blur(8px)}
        .back-to-login:hover{background:#ffffff;color:#1565c0;transform:translateY(-1px);box-shadow:0 8px 22px rgba(0,0,0,0.18)}
        .back-to-login:active{transform:translateY(0)}
        .back-to-login i{font-size:0.9rem;color:#1565c0;transition:transform 0.2s ease}
        .back-to-login:hover i{transform:translateX(-2px)}

        .form-group.error input,.form-group.error select,.form-group.error textarea { border-color: #dc3545 !important; background: #fff5f5 !important; animation: shake 0.3s ease-in-out; }
        .form-group.error label { color: #dc3545 !important; }
        .form-group.error > input + label,.form-group.error > select + label,.form-group.error > textarea + label { top: -0.6rem; font-size: 0.75rem; }
        .form-group.error::after { content: '\f06a'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 1rem; top: 0.85rem; color: #dc3545; font-size: 1rem; pointer-events: none; }
        .error-msg { display: flex; align-items: flex-start; gap: 0.35rem; color: #b71c1c; font-size: 0.8rem; margin-top: 0.35rem; font-weight: 600; line-height: 1.3; animation: slideDown 0.2s ease-out; }
        .error-msg::before { content: '\f071'; font-family: 'Font Awesome 6 Free'; font-weight: 900; font-size: 0.75rem; flex-shrink: 0; margin-top: 1px; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes shake { 0%,100% { transform: translateX(0); } 25% { transform: translateX(-4px); } 75% { transform: translateX(4px); } }

        .check-hint { display: flex; align-items: center; gap: 0.35rem; font-size: 0.78rem; font-weight: 600; margin-top: 0.35rem; min-height: 1.1em; }
        .check-hint.pending { color: #64748b; }
        .check-hint.ok      { color: #059669; }
        .check-hint.error   { color: #dc2626; }
        .check-hint.warn    { color: #d97706; }
        .check-hint .existing-name { font-weight: 500; color: #64748b; margin-left: 0.15rem; }

        .name-dup-alert { grid-column: 1 / -1; display: flex; align-items: flex-start; gap: 0.6rem; padding: 0.85rem 1.1rem; border-radius: 12px; background: linear-gradient(135deg, #fff7ed, #fed7aa); border-left: 4px solid #d97706; color: #92400e; font-size: 0.85rem; font-weight: 500; line-height: 1.45; margin-top: 0.5rem; }
        .name-dup-alert i { font-size: 1.1rem; color: #d97706; margin-top: 2px; flex-shrink: 0; }
        .name-dup-alert ul { margin: 0.35rem 0 0 1.15rem; padding: 0; }
        .name-dup-alert li { margin-bottom: 0.15rem; }
        .name-dup-alert li strong { color: #78350f; }

        .name-verify-card { border-left-width: 4px; }
        .name-verify-head { display:flex; align-items:center; gap:.85rem; flex-wrap:wrap; }
        .name-verify-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; background: rgba(255,255,255,.85); box-shadow: 0 0 0 1px rgba(0,0,0,.05) inset; }
        .name-verify-titles { flex: 1; min-width: 0; }
        .name-verify-sub { font-size: .78rem; color: #4a5568; margin-top: .15rem; }
        .name-verify-badge { font-size: .72rem; font-weight: 700; padding: .28rem .7rem; border-radius: 999px; text-transform: uppercase; letter-spacing: .4px; background: rgba(255,255,255,.85); border: 1px solid rgba(0,0,0,.06); }
        .name-verify-message { margin: .85rem 0 0; font-size: .9rem; line-height: 1.55; color: #1f2937; }
        .name-verify-grid { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .85rem; }
        .name-verify-part { display: inline-flex; align-items: center; gap: .4rem; padding: .35rem .75rem; border-radius: 999px; font-size: .76rem; font-weight: 600; background: #fff; border: 1px solid rgba(0,0,0,.06); }
        .name-verify-part i { font-size: .72rem; }
        .name-verify-part.ok   { color: #047857; background: #ecfdf5; border-color: rgba(5,150,105,.25); }
        .name-verify-part.miss { color: #b91c1c; background: #fef2f2; border-color: rgba(220,38,38,.25); }
        .name-verify-part.skip { color: #64748b; background: #f1f5f9; border-color: rgba(100,116,139,.2); }

        .name-verify-details { margin-top: .85rem; border-radius: 10px; background: rgba(255,255,255,.75); border: 1px solid rgba(0,0,0,.05); overflow: hidden; }
        .name-verify-details summary { cursor: pointer; padding: .55rem .85rem; font-size: .8rem; font-weight: 600; color: #334155; list-style: none; }
        .name-verify-details summary::-webkit-details-marker { display: none; }
        .name-verify-details summary::before { content: '\f0da'; font-family: 'Font Awesome 6 Free'; font-weight: 900; display: inline-block; margin-right: .45rem; transition: transform .15s; color: #94a3b8; }
        .name-verify-details[open] summary::before { transform: rotate(90deg); }
        .name-verify-pre { margin: 0; padding: .85rem 1rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .76rem; line-height: 1.5; color: #334155; background: #f8fafc; border-top: 1px solid rgba(0,0,0,.05); max-height: 220px; overflow: auto; white-space: pre-wrap; word-break: break-word; }

        .name-verify-warn { display: flex; gap: .6rem; align-items: flex-start; margin-top: .85rem; padding: .7rem .9rem; border-radius: 10px; font-size: .82rem; line-height: 1.5; background: #fffbeb; color: #92400e; border: 1px solid rgba(217,119,6,.3); }
        .name-verify-warn i { color: #d97706; margin-top: 2px; flex-shrink: 0; }

        .verify-block { margin-top: 1rem; padding: .9rem 1rem; background: rgba(255,255,255,.55); border-radius: 12px; border: 1px solid rgba(0,0,0,.05); }
        .verify-block-title { display: flex; align-items: center; gap: .55rem; font-size: .82rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: .4px; margin-bottom: .55rem; }
        .verify-block-verdict { display: inline-flex; align-items: center; gap: .3rem; font-size: .68rem; font-weight: 700; padding: .2rem .55rem; border-radius: 999px; text-transform: uppercase; letter-spacing: .4px; margin-left: auto; }
        .verify-block-verdict.ok   { background: #d1fae5; color: #047857; }
        .verify-block-verdict.warn { background: #fef3c7; color: #b45309; }
        .verify-block-verdict.miss { background: #fee2e2; color: #b91c1c; }
        .verify-block-verdict.skip { background: #e2e8f0; color: #475569; }
        .verify-block-msg { font-size: .8rem; color: #4a5568; line-height: 1.5; margin-top: .55rem; }
        .lrn-distance { margin-left: .35rem; font-size: .68rem; font-weight: 600; color: #b45309; }

        .name-verify-card.nv-match        { background: linear-gradient(135deg, #f0fdf4, #dcfce7); border-left-color: #059669; }
        .name-verify-card.nv-match        .name-verify-icon  { color: #047857; }
        .name-verify-card.nv-match        .name-verify-badge { color: #047857; }
        .name-verify-card.nv-partial      { background: linear-gradient(135deg, #fffbeb, #fef3c7); border-left-color: #d97706; }
        .name-verify-card.nv-partial      .name-verify-icon  { color: #b45309; }
        .name-verify-card.nv-partial      .name-verify-badge { color: #b45309; }
        .name-verify-card.nv-mismatch     { background: linear-gradient(135deg, #fef2f2, #fee2e2); border-left-color: #dc2626; }
        .name-verify-card.nv-mismatch     .name-verify-icon  { color: #b91c1c; }
        .name-verify-card.nv-mismatch     .name-verify-badge { color: #b91c1c; }
        .name-verify-card.nv-unverifiable { background: linear-gradient(135deg, #f1f5f9, #e2e8f0); border-left-color: #64748b; }
        .name-verify-card.nv-unverifiable .name-verify-icon  { color: #475569; }
        .name-verify-card.nv-unverifiable .name-verify-badge { color: #475569; }

        .error-banner { background: linear-gradient(135deg, #fef2f2, #fee2e2); border-left: 5px solid #dc3545; color: #7f1d1d; padding: 1rem 1.25rem; border-radius: 14px; margin-bottom: 1.5rem; display: flex; align-items: flex-start; gap: 0.75rem; box-shadow: 0 4px 16px rgba(220,53,69,0.12); animation: slideDown 0.3s ease-out; }
        .error-banner > i { font-size: 1.4rem; color: #dc3545; margin-top: 2px; flex-shrink: 0; }
        .error-banner strong { display: block; font-size: 0.95rem; margin-bottom: 0.35rem; color: #7f1d1d; }
        .error-banner ul { margin: 0; padding-left: 1.25rem; font-size: 0.88rem; }
        .error-banner li { margin-bottom: 0.15rem; }

        .upload-row { background: linear-gradient(135deg, #f8fafc, #f1f5f9); border: 2px solid #e2e8f0; border-radius: 14px; padding: 1.1rem; margin-bottom: 0.75rem; transition: all 0.2s; }
        .upload-row:hover { border-color: #cbd5e1; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .upload-row.has-file { border-color: #059669; background: linear-gradient(135deg, #f0fdf4, #dcfce7); }
        .upload-row .form-label { font-size: 0.7rem; font-weight: 700; color: #475569; letter-spacing: 0.5px; margin-bottom: 0.4rem; text-transform: uppercase; }
        .preview-thumb{width:80px;height:60px;object-fit:cover;border-radius:8px;border:2px solid #e5e7eb}
        .camera-modal{position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.9);display:flex;align-items:center;justify-content:center}
        .camera-container{background:#000;border-radius:16px;overflow:hidden;max-width:600px;width:95%}
        .camera-container video{width:100%;display:block}
        .camera-container .camera-controls{padding:1rem;display:flex;gap:0.75rem;justify-content:center;background:#111}
        .camera-container .camera-controls button{padding:0.6rem 1.5rem;border-radius:50px;font-weight:600}
        .doc-upload-row{display:none; margin-top:0.5rem; padding-left:1rem; border-left:3px solid #0d6efd;}
        .doc-upload-row.visible{display:block;}

        .doc-mismatch-banner{display:flex;gap:1rem;align-items:flex-start;padding:1.15rem 1.3rem;margin-bottom:1.25rem;border-radius:16px;background:linear-gradient(135deg,#fef2f2,#fee2e2);border-left:5px solid #dc2626;box-shadow:0 8px 24px -8px rgba(220,38,38,.35);animation:slideDown 0.3s ease-out;}
        .doc-mismatch-icon{width:44px;height:44px;border-radius:50%;background:#fff;color:#dc2626;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;box-shadow:0 0 0 3px rgba(220,38,38,.15);}
        .doc-mismatch-body{flex:1;min-width:0}
        .doc-mismatch-title{font-size:1.05rem;font-weight:800;color:#7f1d1d;letter-spacing:-.01em;margin-bottom:.35rem;}
        .doc-mismatch-text{font-size:.9rem;line-height:1.55;color:#991b1b;margin:0 0 .85rem;}
        .doc-mismatch-details{background:rgba(255,255,255,.7);border:1px solid rgba(220,38,38,.18);border-radius:10px;padding:.7rem .9rem;margin-bottom:.75rem;}
        .doc-mismatch-row{display:flex;gap:.75rem;align-items:baseline;font-size:.82rem;line-height:1.5;padding:.15rem 0;}
        .doc-mismatch-row + .doc-mismatch-row{border-top:1px dashed rgba(220,38,38,.15);padding-top:.35rem;margin-top:.2rem;}
        .doc-mismatch-key{color:#7f1d1d;font-weight:700;flex-shrink:0;min-width:170px;}
        .doc-mismatch-val{color:#1f2937;font-weight:600;word-break:break-word;}
        .doc-mismatch-val.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.8rem;}
        .doc-mismatch-val i{margin:0 .15rem}
        .doc-mismatch-distance{color:#b45309;font-weight:700;font-size:.74rem;margin-left:.35rem;}
        .doc-mismatch-hint{display:flex;gap:.5rem;align-items:center;font-size:.82rem;color:#7f1d1d;font-weight:600;padding:.55rem .75rem;background:rgba(255,255,255,.55);border-radius:8px;border:1px dashed rgba(220,38,38,.25);}
        .doc-mismatch-hint i{color:#dc2626}
        .doc-mismatch-meta{display:flex;gap:1rem;flex-wrap:wrap;margin-top:.6rem;font-size:.72rem;color:#94a3b8;}
        .doc-mismatch-meta span{display:inline-flex;align-items:center;gap:.3rem;font-family:ui-monospace,monospace;}

        .photo-warn-banner{display:flex;gap:1rem;align-items:flex-start;padding:1.05rem 1.25rem;margin-bottom:1.25rem;border-radius:16px;background:linear-gradient(135deg,#fffbeb,#fef3c7);border-left:5px solid #d97706;box-shadow:0 8px 24px -8px rgba(217,119,6,.25);animation:slideDown 0.3s ease-out;}
        .photo-warn-icon{width:42px;height:42px;border-radius:50%;background:#fff;color:#d97706;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;box-shadow:0 0 0 3px rgba(217,119,6,.15);}
        .photo-warn-title{font-size:1rem;font-weight:800;color:#78350f;margin-bottom:.3rem;}
        .photo-warn-text{font-size:.88rem;line-height:1.55;color:#92400e;margin:0;}
        .photo-warn-hint{display:flex;gap:.5rem;align-items:center;font-size:.8rem;color:#78350f;font-weight:600;margin-top:.6rem;padding:.5rem .75rem;background:rgba(255,255,255,.55);border-radius:8px;border:1px dashed rgba(217,119,6,.3);}
        .photo-warn-hint i{color:#d97706}

        .review-blocker-banner {
            display: flex; gap: 1rem; align-items: flex-start;
            padding: 1.15rem 1.3rem;
            margin: 2rem 0 1rem;
            border-radius: 16px;
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border-left: 5px solid #dc2626;
            box-shadow: 0 8px 24px -8px rgba(220, 38, 38, .35);
            animation: slideDown 0.3s ease-out;
        }
        .review-blocker-icon {
            width: 44px; height: 44px; border-radius: 50%;
            background: #fff; color: #dc2626;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, .15);
        }
        .review-blocker-body { flex: 1; min-width: 0; }
        .review-blocker-title {
            font-size: 1.05rem; font-weight: 800;
            color: #7f1d1d; letter-spacing: -.01em;
            margin-bottom: .4rem;
        }
        .review-blocker-list {
            margin: 0 0 .75rem; padding-left: 1.25rem;
            font-size: .88rem; line-height: 1.55; color: #991b1b;
        }
        .review-blocker-list li { margin-bottom: .25rem; }
        .review-blocker-hint {
            display: flex; gap: .5rem; align-items: center;
            font-size: .82rem; color: #7f1d1d; font-weight: 600;
            padding: .55rem .75rem;
            background: rgba(255,255,255,.55);
            border-radius: 8px;
            border: 1px dashed rgba(220, 38, 38, .25);
        }
        .review-blocker-hint i { color: #dc2626; }

        fieldset.doc-fieldset { border: 0; padding: 0; margin: 0; }
        fieldset.doc-fieldset > legend { font-size: 0.95rem; font-weight: 600; color: #2d3748; margin-bottom: 0.5rem; padding: 0; width: auto; float: none; }

        .swal2-popup { border-radius: 20px !important; padding: 2rem 1.5rem !important; }
        .swal2-title { font-size: 1.4rem !important; font-weight: 700 !important; }
        .swal2-html-container { font-size: 0.95rem !important; line-height: 1.6 !important; }
        .swal2-confirm { border-radius: 50px !important; padding: 0.7rem 2rem !important; font-weight: 600 !important; }

        .sr-only { position: absolute !important; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }

        .wizard-nav { display:flex;justify-content:space-between;align-items:center;gap:0.75rem;margin-top:1.25rem;flex-wrap:wrap; }
        .wizard-nav .spacer { flex:1; }
        .wizard-nav .btn { padding:0.75rem 1.75rem;border-radius:50px;font-weight:600;font-size:0.95rem;display:inline-flex;align-items:center;gap:0.5rem;transition:all 0.25s ease;border:none; }
        .wizard-nav .btn-back { background:#e2e8f0;color:#2d3748; }
        .wizard-nav .btn-back:hover { background:#cbd5e1;transform:translateY(-1px); }
        .wizard-nav .btn-next { background:linear-gradient(135deg,#1e88e5,#1565c0);color:#fff;box-shadow:0 6px 16px -4px rgba(30,136,229,0.5); }
        .wizard-nav .btn-next:hover { transform:translateY(-1px);box-shadow:0 10px 22px -4px rgba(30,136,229,0.6); }
        .wizard-nav .btn-submit { background:linear-gradient(135deg,#10b981,#047857);color:#fff;box-shadow:0 6px 16px -4px rgba(16,185,129,0.5); }
        .wizard-nav .btn-submit:hover { transform:translateY(-1px);box-shadow:0 10px 22px -4px rgba(16,185,129,0.6); }
        .wizard-nav .btn-submit:disabled { opacity:0.7;cursor:not-allowed;transform:none; }
        .wizard-nav .btn-back.placeholder { visibility:hidden;pointer-events:none; }

        #strandRecommendation { margin-top: 1rem; animation: recFade .25s ease; }
        @keyframes recFade { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }

        .rec-card { display: flex; gap: .85rem; padding: 1rem 1.15rem; border-radius: 14px; border: 1.5px solid #e2e8f0; background: #f8fafc; align-items: flex-start; transition: all .2s ease; }
        .rec-icon { width: 40px; height: 40px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; flex-shrink: 0; }
        .rec-body { flex: 1; min-width: 0; }
        .rec-title { font-size: .9rem; font-weight: 700; color: #1a202c; line-height: 1.3; margin-bottom: .3rem; }
        .rec-message { font-size: .82rem; color: #4a5568; line-height: 1.55; }
        .rec-suggestion { display: inline-flex; align-items: center; gap: .4rem; margin-top: .55rem; padding: .35rem .75rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 999px; font-size: .74rem; font-weight: 600; color: #4a5568; }
        .rec-suggestion i { color: #7c3aed; }
        .rec-suggestion strong { color: #7c3aed; font-weight: 700; }

        .rec-card.rec-aligned,
        .rec-card.rec-strong { background: linear-gradient(135deg, #f0fdf4, #dcfce7); border-color: rgba(5, 150, 105, .3); }
        .rec-card.rec-aligned .rec-icon,
        .rec-card.rec-strong .rec-icon { background: #fff; color: #059669; box-shadow: 0 0 0 1px rgba(5, 150, 105, .25); }

        .rec-card.rec-changed,
        .rec-card.rec-alternative { background: linear-gradient(135deg, #fffbeb, #fef3c7); border-color: rgba(217, 119, 6, .3); }
        .rec-card.rec-changed .rec-icon,
        .rec-card.rec-alternative .rec-icon { background: #fff; color: #d97706; box-shadow: 0 0 0 1px rgba(217, 119, 6, .25); }

        .rec-card.rec-mismatch { background: linear-gradient(135deg, #fef2f2, #fee2e2); border-color: rgba(220, 38, 38, .3); }
        .rec-card.rec-mismatch .rec-icon { background: #fff; color: #dc2626; box-shadow: 0 0 0 1px rgba(220, 38, 38, .25); }

        .rec-card.rec-insufficient { background: #f1f5f9; border-color: #e2e8f0; }
        .rec-card.rec-insufficient .rec-icon { background: #eef2ff; color: #4f46e5; }

        .rec-trends { margin-top: .85rem; padding: .85rem 1rem; background: rgba(255,255,255,0.7); border-radius: 12px; border: 1px solid rgba(0,0,0,0.05); }
        .rec-trends-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; flex-wrap: wrap; margin-bottom: .6rem; }
        .rec-trends-title { font-size: .72rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: .5px; }
        .rec-growth { display: inline-flex; align-items: center; gap: .3rem; padding: .2rem .6rem; border-radius: 999px; font-size: .7rem; font-weight: 700; }
        .rec-growth.up   { background: #d1fae5; color: #059669; }
        .rec-growth.down { background: #fee2e2; color: #dc2626; }
        .rec-growth.new  { background: #ede9fe; color: #7c3aed; }
        .rec-growth.flat { background: #e2e8f0; color: #64748b; }

        .rec-bars { display: flex; flex-direction: column; gap: .55rem; }
        .rec-bar-row .rec-bar-label { display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-size: .72rem; font-weight: 600; color: #4a5568; margin-bottom: .25rem; }
        .rec-bar-row.is-chosen .rec-bar-label span:first-child { color: #4f46e5; font-weight: 700; }
        .rec-bar-count { font-variant-numeric: tabular-nums; color: #1a202c; font-weight: 700; }
        .rec-bar-track { width: 100%; height: 8px; border-radius: 999px; background: #f1f5f9; overflow: hidden; border: 1px solid #e2e8f0; }
        .rec-bar-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, #4f46e5, #6366f1); transition: width .3s; }
        .rec-bar-row.is-chosen .rec-bar-fill { background: linear-gradient(90deg, #7c3aed, #a78bfa); }

        @media (max-width: 640px) {
            .wizard-step-label { display: none; }
            .wizard-step-num { width: 32px; height: 32px; font-size: 0.85rem; }
            .wizard-progress-wrap { padding: 0.75rem 0.9rem; }
            .wizard-nav .btn { flex: 1; justify-content: center; }
            .wizard-nav .spacer { display: none; }
            .back-to-login { top: 14px; left: 14px; padding: 0.5rem 0.9rem; font-size: 0.78rem; }
            .back-to-login span { display: none; }
            .back-to-login i { font-size: 1rem; margin: 0; }
            .doc-mismatch-banner { flex-direction: column; }
            .doc-mismatch-key { min-width: auto; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: 0.001ms !important; animation-iteration-count: 1 !important; transition-duration: 0.001ms !important; scroll-behavior: auto !important; }
        }

        /* ============================================================
           ENHANCED ENROLLMENT CONFIRMATION POPUP
           ============================================================ */
        .swal2-popup.swal-enroll-popup {
            border-radius: 24px !important;
            padding: 0 !important;
            width: 580px !important;
            max-width: 95vw !important;
            overflow: hidden;
            background: #fff !important;
            box-shadow: 0 32px 72px -16px rgba(15, 23, 42, 0.45) !important;
        }
        .swal-enroll-popup .swal2-html-container {
            margin: 0 !important;
            padding: 0 !important;
            overflow: visible !important;
            color: inherit !important;
            font-size: inherit !important;
        }
        .swal-enroll-popup .swal2-actions {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }
        .swal-enroll-popup .swal2-close {
            color: #ffffff !important;
            opacity: 0.7;
            z-index: 10;
        }
        .swal-enroll-popup .swal2-close:hover { opacity: 1; color: #ffffff !important; }

        .ec-hero {
            position: relative;
            padding: 2.4rem 2rem 1.9rem;
            background:
                radial-gradient(circle at 85% 15%, rgba(251, 191, 36, 0.22) 0%, transparent 55%),
                radial-gradient(circle at 15% 85%, rgba(147, 197, 253, 0.20) 0%, transparent 55%),
                linear-gradient(135deg, #1e88e5 0%, #1565c0 55%, #0d47a1 100%);
            color: #fff;
            text-align: center;
            overflow: hidden;
        }
        .ec-hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(circle at 12% 20%, rgba(255,255,255,.09) 0, transparent 40%),
                radial-gradient(circle at 92% 88%, rgba(255,255,255,.07) 0, transparent 42%);
            pointer-events: none;
        }
        .ec-hero-icon {
            position: relative;
            width: 84px; height: 84px;
            margin: 0 auto 1rem;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.18);
            border: 2px solid rgba(255, 255, 255, 0.35);
            display: flex; align-items: center; justify-content: center;
            font-size: 2.2rem;
            color: #ffffff;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            box-shadow: 0 14px 36px -10px rgba(0, 0, 0, 0.4);
            animation: ec-pop 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            z-index: 1;
        }
        @keyframes ec-pop {
            0%   { transform: scale(0.4); opacity: 0; }
            60%  { transform: scale(1.12); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }
        .ec-hero-icon::after {
            content: '';
            position: absolute; inset: -10px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.22);
            animation: ec-ring 2.2s ease-out infinite;
        }
        @keyframes ec-ring {
            0%   { transform: scale(0.9); opacity: 1; }
            100% { transform: scale(1.5); opacity: 0; }
        }

        .ec-hero-title {
            position: relative;
            font-size: 1.45rem; font-weight: 800;
            letter-spacing: -0.02em;
            margin: 0 0 0.35rem;
            color: #fff; line-height: 1.2; z-index: 1;
        }
        .ec-hero-subtitle {
            position: relative;
            font-size: 0.9rem; opacity: 0.94;
            margin: 0 auto; max-width: 420px;
            line-height: 1.55;
            color: rgba(255, 255, 255, 0.94);
            z-index: 1;
        }

        .ec-status-badge {
            position: relative;
            display: inline-flex; align-items: center; gap: 0.55rem;
            margin-top: 1.25rem;
            padding: 0.5rem 1.15rem;
            border-radius: 999px;
            background: rgba(251, 191, 36, 0.22);
            border: 1px solid rgba(251, 191, 36, 0.5);
            font-size: 0.72rem; font-weight: 800;
            letter-spacing: 1.2px; text-transform: uppercase;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            color: #fef3c7; z-index: 1;
        }
        .ec-status-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #fbbf24;
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.35);
            animation: ec-pulse-dot 1.6s ease-in-out infinite;
        }
        @keyframes ec-pulse-dot {
            0%, 100% { box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.35); }
            50%      { box-shadow: 0 0 0 7px rgba(251, 191, 36, 0.10); }
        }

        .ec-body {
            padding: 1.5rem 1.85rem 1.75rem;
            background: #fff;
            text-align: left;
        }

        .ec-section-title {
            font-size: 0.68rem; font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.9px;
            margin: 0 0 0.75rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .ec-section-title i { color: #1e88e5; font-size: 0.85rem; }
        .ec-section-title + .ec-section-title { margin-top: 1.4rem; }

        .ec-details {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.1rem 1.2rem;
            margin-bottom: 1.35rem;
        }
        .ec-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.85rem 1.1rem;
        }
        .ec-detail-item { min-width: 0; }
        .ec-detail-item.is-wide { grid-column: 1 / -1; }
        .ec-detail-label {
            font-size: 0.66rem; font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.55px;
            margin-bottom: 0.2rem;
        }
        .ec-detail-value {
            font-size: 0.9rem; font-weight: 700;
            color: #0f172a;
            word-break: break-word;
            line-height: 1.3;
        }
        .ec-detail-value.is-id {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            color: #1e88e5;
            letter-spacing: 0.02em;
            font-size: 0.88rem;
        }

        .ec-expected-panel {
            display: flex; gap: 0.9rem; align-items: flex-start;
            padding: 1rem 1.15rem;
            border-radius: 14px;
            background: linear-gradient(135deg, #fff7ed, #fed7aa);
            border: 1px solid rgba(217, 119, 6, 0.28);
            border-left: 4px solid #d97706;
            margin-bottom: 1.4rem;
        }
        .ec-expected-icon {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: #ffffff;
            color: #d97706;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.15rem; flex-shrink: 0;
            box-shadow: 0 0 0 1px rgba(217, 119, 6, 0.15);
        }
        .ec-expected-body { flex: 1; min-width: 0; }
        .ec-expected-title {
            font-size: 0.9rem; font-weight: 800;
            color: #78350f;
            letter-spacing: -0.01em;
            margin: 0 0 0.3rem;
            text-transform: none;
            line-height: 1.3;
        }
        .ec-expected-title .expected-word {
            background: #d97706;
            color: #fff;
            padding: 0.08rem 0.45rem;
            border-radius: 5px;
            font-size: 0.72rem;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            margin-right: 0.4rem;
            vertical-align: middle;
        }
        .ec-expected-text {
            font-size: 0.82rem;
            color: #92400e;
            line-height: 1.55;
            margin: 0;
        }
        .ec-expected-text strong { color: #78350f; font-weight: 700; }

        .ec-timeline-list {
            display: flex; flex-direction: column;
            gap: 0; margin-bottom: 1.4rem;
        }
        .ec-timeline-item {
            display: flex; gap: 0.9rem;
            padding-bottom: 1rem;
            position: relative;
        }
        .ec-timeline-item:last-child { padding-bottom: 0; }
        .ec-timeline-item:not(:last-child)::before {
            content: '';
            position: absolute;
            left: 14px; top: 34px; bottom: 4px;
            width: 2px; background: #e2e8f0;
        }
        .ec-timeline-icon {
            width: 30px; height: 30px;
            border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.72rem; z-index: 1;
            background: #e2e8f0; color: #94a3b8;
            border: 2px solid #fff;
            box-shadow: 0 0 0 1px #e2e8f0;
        }
        .ec-timeline-item.is-done .ec-timeline-icon {
            background: linear-gradient(135deg, #10b981, #047857);
            color: #fff;
            box-shadow: 0 0 0 1px #10b981;
        }
        .ec-timeline-item.is-active .ec-timeline-icon {
            background: linear-gradient(135deg, #fbbf24, #d97706);
            color: #fff;
            box-shadow: 0 0 0 1px #fbbf24, 0 0 0 5px rgba(251, 191, 36, 0.15);
            animation: ec-pulse-icon 1.6s ease-in-out infinite;
        }
        @keyframes ec-pulse-icon {
            0%, 100% { box-shadow: 0 0 0 1px #fbbf24, 0 0 0 5px rgba(251, 191, 36, 0.15); }
            50%      { box-shadow: 0 0 0 1px #fbbf24, 0 0 0 10px rgba(251, 191, 36, 0.05); }
        }
        .ec-timeline-body { flex: 1; min-width: 0; padding-top: 2px; }
        .ec-timeline-title {
            font-size: 0.85rem; font-weight: 700;
            color: #0f172a; line-height: 1.25;
            margin: 0 0 0.2rem;
        }
        .ec-timeline-item.is-active .ec-timeline-title { color: #b45309; }
        .ec-timeline-desc {
            font-size: 0.76rem; color: #64748b;
            line-height: 1.45; margin: 0;
        }
        .ec-timeline-desc strong { color: #334155; font-weight: 600; }

        .ec-note {
            display: flex; gap: 0.65rem; align-items: flex-start;
            padding: 0.85rem 1.05rem;
            border-radius: 12px;
            background: #f1f5f9;
            border-left: 3px solid #94a3b8;
            font-size: 0.78rem;
            color: #475569;
            line-height: 1.55;
        }
        .ec-note i {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .ec-note strong { color: #0f172a; font-weight: 700; }

        .swal-enroll-confirm {
            display: block;
            width: 100%;
            margin: 0 !important;
            padding: 1.05rem 1.75rem;
            border: none;
            border-radius: 0 0 24px 24px;
            background: linear-gradient(135deg, #1e88e5, #1565c0);
            color: #fff;
            font-size: 0.95rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s ease;
            letter-spacing: 0.3px;
        }
        .swal-enroll-confirm:hover {
            background: linear-gradient(135deg, #1976d2, #0d47a1);
            letter-spacing: 0.45px;
        }
        .swal-enroll-confirm:active { transform: translateY(1px); }
        .swal-enroll-confirm i { margin-right: 0.45rem; }

        @media (max-width: 640px) {
            .swal2-popup.swal-enroll-popup { width: 95vw !important; }
            .ec-hero { padding: 2rem 1.4rem 1.6rem; }
            .ec-hero-icon { width: 72px; height: 72px; font-size: 1.9rem; }
            .ec-hero-title { font-size: 1.25rem; }
            .ec-hero-subtitle { font-size: 0.85rem; }
            .ec-body { padding: 1.3rem 1.25rem 1.4rem; }
            .ec-details-grid { grid-template-columns: 1fr; }
            .swal-enroll-confirm { padding: 0.95rem 1.3rem; font-size: 0.9rem; }
        }
    </style>
</head>
<body>

<a href="#main-content" class="skip-link"><?= htmlspecialchars($t[$lang]['skip_to_content'], ENT_QUOTES, 'UTF-8') ?></a>

<nav class="lang-switch" aria-label="<?= htmlspecialchars($t[$lang]['language_switch'], ENT_QUOTES, 'UTF-8') ?>">
    <button type="button" class="lang-btn <?= $lang == 'en' ? 'active' : '' ?>" onclick="switchLang('en')" aria-label="<?= htmlspecialchars($t[$lang]['english'], ENT_QUOTES, 'UTF-8') ?>" aria-pressed="<?= $lang == 'en' ? 'true' : 'false' ?>" lang="en">EN</button>
    <button type="button" class="lang-btn <?= $lang == 'fil' ? 'active' : '' ?>" onclick="switchLang('fil')" aria-label="<?= htmlspecialchars($t[$lang]['filipino'], ENT_QUOTES, 'UTF-8') ?>" aria-pressed="<?= $lang == 'fil' ? 'true' : 'false' ?>" lang="fil">FIL</button>
</nav>

<a href="../admin/admin_login.php" class="back-to-login" aria-label="<?= htmlspecialchars($t[$lang]['back_to_login'], ENT_QUOTES, 'UTF-8') ?>">
    <i class="fas fa-arrow-left" aria-hidden="true"></i>
    <span><?= htmlspecialchars($t[$lang]['back_to_login'], ENT_QUOTES, 'UTF-8') ?></span>
</a>

<header class="hero-header" role="banner">
    <img src="../assets/img/usat.jpg" alt="USAT College logo">
    <h1><?= htmlspecialchars($t[$lang]['school'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars($t[$lang]['form'], ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($t[$lang]['ay'], ENT_QUOTES, 'UTF-8') ?></p>
</header>

<main id="main-content" class="form-container" role="main">

    <?php if ($mode === 'form'): ?>

        <?php if (!empty($errors)): ?>
            <?php
            $error_count = 0;
            foreach ($errors as $k => $v) { if (in_array($k, ['upload', 'document_mismatch', 'photo_2x2'], true)) continue; $error_count++; }
            ?>
            <?php if ($error_count > 0): ?>
                <div class="error-banner" role="alert" aria-live="assertive" tabindex="-1" id="errorSummary">
                    <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                    <div>
                        <strong><?= htmlspecialchars($t[$lang]['error_summary'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <ul>
                            <?php foreach ($errors as $field => $msg): ?>
                                <?php if (in_array($field, ['upload', 'name_duplicate', 'document_mismatch', 'photo_2x2'], true)) continue; ?>
                                <li><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($errors['upload'])): ?>
            <div class="error-banner" role="alert" aria-live="assertive">
                <i class="fas fa-file-circle-exclamation" aria-hidden="true"></i>
                <div>
                    <strong><?= htmlspecialchars($t[$lang]['upload_problem'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <ul><li><?= htmlspecialchars($errors['upload'], ENT_QUOTES, 'UTF-8') ?></li></ul>
                </div>
            </div>
        <?php endif; ?>

        <div class="wizard-progress-wrap">
            <nav class="wizard-progress" id="wizardProgress" aria-label="<?= htmlspecialchars($t[$lang]['progress_label'], ENT_QUOTES, 'UTF-8') ?>">
                <button type="button" class="wizard-step-indicator active" data-step="1" aria-current="step">
                    <span class="wizard-step-num" aria-hidden="true">1</span>
                    <span class="wizard-step-label">Student Info</span>
                </button>
                <button type="button" class="wizard-step-indicator" data-step="2">
                    <span class="wizard-step-num" aria-hidden="true">2</span>
                    <span class="wizard-step-label">Parents &amp; Guardian</span>
                </button>
                <button type="button" class="wizard-step-indicator" data-step="3">
                    <span class="wizard-step-num" aria-hidden="true">3</span>
                    <span class="wizard-step-label">Address</span>
                </button>
                <button type="button" class="wizard-step-indicator" data-step="4">
                    <span class="wizard-step-num" aria-hidden="true">4</span>
                    <span class="wizard-step-label">Education</span>
                </button>
                <button type="button" class="wizard-step-indicator" data-step="5">
                    <span class="wizard-step-num" aria-hidden="true">5</span>
                    <span class="wizard-step-label">Enrollment</span>
                </button>
            </nav>
        </div>

        <form method="POST" action="" id="enrollForm" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="acknowledge_duplicate_name" id="acknowledgeDuplicateName" value="">

            <!-- ============ STEP 1 ============ -->
            <div class="wizard-step-content active" data-step-content="1">
                <section class="section-card" aria-labelledby="sectionStudentHeading">
                    <div class="section-header"><i class="fas fa-user-graduate" aria-hidden="true"></i><h2 id="sectionStudentHeading"><?= htmlspecialchars($t[$lang]['student'], ENT_QUOTES, 'UTF-8') ?></h2></div>
                    <div class="row g-3">
                        <div class="col-md-6 form-group <?= $field_has_error('lrn') ? 'error' : '' ?><?= $filled_class($_POST['lrn'] ?? null) ?>">
                            <input type="text" name="lrn" id="lrn" placeholder=" " value="<?= htmlspecialchars($_POST['lrn'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required maxlength="12" inputmode="numeric" autocomplete="off" aria-required="true">
                            <label for="lrn"><i class="fas fa-id-card me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['lrn'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('lrn')): ?><span class="error-msg"><?= htmlspecialchars($errors['lrn'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                            <div class="check-hint" id="lrnLengthHint" aria-live="polite"></div>
                            <div class="check-hint" id="lrnHint" aria-live="polite"></div>
                        </div>
                        <div class="col-md-6 form-group <?= $field_has_error('last_name') ? 'error' : '' ?><?= $filled_class($_POST['last_name'] ?? null) ?>">
                            <input type="text" name="last_name" id="last_name" placeholder=" " value="<?= htmlspecialchars($_POST['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required autocomplete="family-name" aria-required="true">
                            <label for="last_name"><i class="fas fa-user me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['lname'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('last_name')): ?><span class="error-msg"><?= htmlspecialchars($errors['last_name'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="col-md-6 form-group <?= $field_has_error('first_name') ? 'error' : '' ?><?= $filled_class($_POST['first_name'] ?? null) ?>">
                            <input type="text" name="first_name" id="first_name" placeholder=" " value="<?= htmlspecialchars($_POST['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required autocomplete="given-name" aria-required="true">
                            <label for="first_name"><i class="fas fa-user me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['fname'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('first_name')): ?><span class="error-msg"><?= htmlspecialchars($errors['first_name'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="col-md-6 form-group <?= $filled_class($_POST['middle_name'] ?? null) ?>">
                            <input type="text" name="middle_name" id="middle_name" placeholder=" " value="<?= htmlspecialchars($_POST['middle_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="additional-name">
                            <label for="middle_name"><i class="fas fa-user me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['mname'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>

                        <div class="col-12" id="nameDupContainer" style="display:none;">
                            <div class="name-dup-alert" id="nameDupAlert"></div>
                        </div>

                        <div class="col-md-4 form-group <?= $filled_class($_POST['nick_name'] ?? null) ?>">
                            <input type="text" name="nick_name" id="nick_name" placeholder=" " value="<?= htmlspecialchars($_POST['nick_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="nickname">
                            <label for="nick_name"><i class="fas fa-smile me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['nick'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-4 form-group <?= $filled_class($_POST['ext_name'] ?? null) ?>">
                            <input type="text" name="ext_name" id="ext_name" placeholder=" " value="<?= htmlspecialchars($_POST['ext_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="honorific-suffix">
                            <label for="ext_name"><i class="fas fa-tag me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['ext'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-4 form-group <?= $field_has_error('sex') ? 'error' : '' ?> <?= $filled_class($_POST['sex'] ?? null) ?>">
                            <select name="sex" id="sex" required aria-required="true">
                                <option value="" disabled <?= empty($_POST['sex']) ? 'selected' : '' ?>></option>
                                <option value="Male" <?= ($_POST['sex'] ?? '') == 'Male' ? 'selected' : '' ?>><?= htmlspecialchars($t[$lang]['male'], ENT_QUOTES, 'UTF-8') ?></option>
                                <option value="Female" <?= ($_POST['sex'] ?? '') == 'Female' ? 'selected' : '' ?>><?= htmlspecialchars($t[$lang]['female'], ENT_QUOTES, 'UTF-8') ?></option>
                            </select>
                            <label for="sex"><i class="fas fa-venus-mars me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['sex'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('sex')): ?><span class="error-msg"><?= htmlspecialchars($errors['sex'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="col-md-4 form-group <?= $field_has_error('birth_date') ? 'error' : '' ?> <?= $filled_class($_POST['birth_date'] ?? null) ?>">
                            <input type="date" id="birth_date" name="birth_date" value="<?= htmlspecialchars($_POST['birth_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required autocomplete="bday" aria-required="true">
                            <label for="birth_date"><i class="fas fa-calendar-alt me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['bdate'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('birth_date')): ?><span class="error-msg"><?= htmlspecialchars($errors['birth_date'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="col-md-4 form-group <?= $filled_class($_POST['age'] ?? null) ?>">
                            <input type="number" id="age" name="age" placeholder=" " value="<?= htmlspecialchars($_POST['age'] ?? '', ENT_QUOTES, 'UTF-8') ?>" min="14" max="25" readonly style="background:#f8f9fa" aria-readonly="true">
                            <label for="age"><i class="fas fa-birthday-cake me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['age'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>

                        <div class="col-md-4 form-group <?= $field_has_error('civil_status') ? 'error' : '' ?> <?= $filled_class($cur_civil_status) ?>">
                            <select name="civil_status" id="civil_status" style="background:white">
                                <option value="" <?= $cur_civil_status === '' ? 'selected' : '' ?>>— <?= htmlspecialchars($t[$lang]['select_placeholder'], ENT_QUOTES, 'UTF-8') ?> —</option>
                                <?php foreach ($CIVIL_STATUS_OPTIONS as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>" <?= $cur_civil_status === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="civil_status"><i class="fas fa-ring me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['civil'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>

                        <div class="col-md-4 form-group <?= $field_has_error('nationality') ? 'error' : '' ?> <?= $filled_class($cur_nationality) ?>">
                            <select name="nationality" id="nationality" style="background:white">
                                <option value="" <?= $cur_nationality === '' ? 'selected' : '' ?>>— <?= htmlspecialchars($t[$lang]['select_placeholder'], ENT_QUOTES, 'UTF-8') ?> —</option>
                                <?php foreach ($NATIONALITY_OPTIONS as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>" <?= $cur_nationality === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="nationality"><i class="fas fa-flag me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['nation'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>

                        <div class="col-md-4 form-group <?= $field_has_error('religion') ? 'error' : '' ?> <?= $filled_class($cur_religion) ?>">
                            <select name="religion" id="religion_select" style="background:white" onchange="syncReligion()">
                                <option value="" <?= $religion_select_value === '' ? 'selected' : '' ?>>— <?= htmlspecialchars($t[$lang]['select_placeholder'], ENT_QUOTES, 'UTF-8') ?> —</option>
                                <?php foreach ($RELIGION_OPTIONS as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>" <?= $religion_select_value === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                                <option value="__other__" <?= $religion_select_value === '__other__' ? 'selected' : '' ?>><?= htmlspecialchars($t[$lang]['other_specify'], ENT_QUOTES, 'UTF-8') ?></option>
                            </select>
                            <label for="religion_select"><i class="fas fa-church me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['religion'], ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="text" id="religion_other" class="form-control mt-2"
                                   placeholder="<?= htmlspecialchars($t[$lang]['religion_other_placeholder'], ENT_QUOTES, 'UTF-8') ?>"
                                   maxlength="100"
                                   style="<?= $religion_select_value === '__other__' ? '' : 'display:none;' ?>border:2px solid #e2e8f0;border-radius:12px;padding:0.7rem 1rem;width:100%"
                                   value="<?= $religion_select_value === '__other__' ? htmlspecialchars($cur_religion, ENT_QUOTES, 'UTF-8') : '' ?>">
                        </div>

                        <div class="col-md-3 form-group <?= $field_has_error('height') ? 'error' : '' ?> <?= $filled_class($_POST['height'] ?? null) ?>">
                            <input type="number" name="height" id="height" placeholder=" " step="0.1" min="<?= HEIGHT_MIN ?>" max="<?= HEIGHT_MAX ?>" value="<?= htmlspecialchars($_POST['height'] ?? '', ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal" autocomplete="off">
                            <label for="height"><i class="fas fa-arrow-up me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['height'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('height')): ?><span class="error-msg"><?= htmlspecialchars($errors['height'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="col-md-3 form-group <?= $field_has_error('weight') ? 'error' : '' ?> <?= $filled_class($_POST['weight'] ?? null) ?>">
                            <input type="number" name="weight" id="weight" placeholder=" " step="0.1" min="<?= WEIGHT_MIN ?>" max="<?= WEIGHT_MAX ?>" value="<?= htmlspecialchars($_POST['weight'] ?? '', ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal" autocomplete="off">
                            <label for="weight"><i class="fas fa-weight-hanging me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['weight'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('weight')): ?><span class="error-msg"><?= htmlspecialchars($errors['weight'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="col-md-6 form-group <?= $field_has_error('email') ? 'error' : '' ?> <?= $filled_class($_POST['email'] ?? null) ?>">
                            <input type="email" name="email" id="email" placeholder=" " value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="email" maxlength="150">
                            <label for="email"><i class="fas fa-envelope me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['email'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('email')): ?><span class="error-msg"><?= htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="col-md-6 form-group <?= $field_has_error('phone') ? 'error' : '' ?> <?= $filled_class($_POST['phone'] ?? null) ?>">
                            <input type="tel" name="phone" id="phone" placeholder=" " value="<?= htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" pattern="09\d{9}" maxlength="11" inputmode="numeric" autocomplete="tel">
                            <label for="phone"><i class="fas fa-phone-alt me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['phone'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('phone')): ?><span class="error-msg"><?= htmlspecialchars($errors['phone'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="col-12 form-group <?= $filled_class($_POST['special_skills'] ?? null) ?>">
                            <textarea name="special_skills" id="special_skills" rows="2" placeholder=" " maxlength="500"><?= htmlspecialchars($_POST['special_skills'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            <label for="special_skills"><i class="fas fa-lightbulb me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['skills'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                    </div>
                </section>

                <div class="wizard-nav">
                    <span class="btn btn-back placeholder" aria-hidden="true"><i class="fas fa-arrow-left"></i> <?= htmlspecialchars($t[$lang]['previous'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="spacer"></span>
                    <button type="button" class="btn btn-next" data-next="2"><?= htmlspecialchars($t[$lang]['next'], ENT_QUOTES, 'UTF-8') ?> <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>

            <!-- ============ STEP 2 ============ -->
            <div class="wizard-step-content" data-step-content="2">
                <section class="section-card" aria-labelledby="sectionParentsHeading">
                    <div class="section-header"><i class="fas fa-users" aria-hidden="true"></i><h2 id="sectionParentsHeading"><?= htmlspecialchars($t[$lang]['parents'], ENT_QUOTES, 'UTF-8') ?></h2></div>
                    <div class="row g-3">
                        <div class="col-md-6 form-group <?= $filled_class($_POST['father_name'] ?? null) ?>">
                            <input type="text" name="father_name" id="father_name" placeholder=" " value="<?= htmlspecialchars($_POST['father_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="150">
                            <label for="father_name"><i class="fas fa-male me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['father'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-6 form-group <?= $field_has_error('father_contact') ? 'error' : '' ?> <?= $filled_class($_POST['father_contact'] ?? null) ?>">
                            <input type="tel" name="father_contact" id="father_contact" placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($_POST['father_contact'] ?? '', ENT_QUOTES, 'UTF-8') ?>" pattern="09\d{9}" maxlength="11" inputmode="numeric">
                            <label for="father_contact"><i class="fas fa-phone me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['fcontact'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('father_contact')): ?><span class="error-msg"><?= htmlspecialchars($errors['father_contact'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="col-md-6 form-group <?= $filled_class($_POST['father_occupation'] ?? null) ?>">
                            <input type="text" name="father_occupation" id="father_occupation" placeholder=" " value="<?= htmlspecialchars($_POST['father_occupation'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="100">
                            <label for="father_occupation"><i class="fas fa-briefcase me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['fjob'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-6 form-group <?= $filled_class($_POST['mother_maiden_name'] ?? null) ?>">
                            <input type="text" name="mother_maiden_name" id="mother_maiden_name" placeholder=" " value="<?= htmlspecialchars($_POST['mother_maiden_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="150">
                            <label for="mother_maiden_name"><i class="fas fa-female me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['mother'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-6 form-group <?= $field_has_error('mother_contact') ? 'error' : '' ?> <?= $filled_class($_POST['mother_contact'] ?? null) ?>">
                            <input type="tel" name="mother_contact" id="mother_contact" placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($_POST['mother_contact'] ?? '', ENT_QUOTES, 'UTF-8') ?>" pattern="09\d{9}" maxlength="11" inputmode="numeric">
                            <label for="mother_contact"><i class="fas fa-phone me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['mcontact'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('mother_contact')): ?><span class="error-msg"><?= htmlspecialchars($errors['mother_contact'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="col-md-6 form-group <?= $filled_class($_POST['mother_occupation'] ?? null) ?>">
                            <input type="text" name="mother_occupation" id="mother_occupation" placeholder=" " value="<?= htmlspecialchars($_POST['mother_occupation'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="100">
                            <label for="mother_occupation"><i class="fas fa-briefcase me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['mjob'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-6 form-group <?= $filled_class($_POST['ave_family_income'] ?? null) ?>">
                            <input type="text" name="ave_family_income" id="ave_family_income" placeholder=" " value="<?= htmlspecialchars($_POST['ave_family_income'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <label for="ave_family_income"><i class="fas fa-money-bill-wave me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['income'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-6 form-group <?= $field_has_error('is_4ps') ? 'error' : '' ?> <?= (isset($_POST['is_4ps']) && $_POST['is_4ps'] !== '') ? 'filled' : '' ?>">
                            <select name="is_4ps" id="is_4ps" required aria-required="true">
                                <option value="" disabled <?= !isset($_POST['is_4ps']) || $_POST['is_4ps'] === '' ? 'selected' : '' ?>></option>
                                <option value="1" <?= (isset($_POST['is_4ps']) && $_POST['is_4ps'] == '1') ? 'selected' : '' ?>><?= htmlspecialchars($t[$lang]['yes'], ENT_QUOTES, 'UTF-8') ?></option>
                                <option value="0" <?= (isset($_POST['is_4ps']) && $_POST['is_4ps'] == '0') ? 'selected' : '' ?>><?= htmlspecialchars($t[$lang]['no'], ENT_QUOTES, 'UTF-8') ?></option>
                            </select>
                            <label for="is_4ps"><i class="fas fa-hand-holding-usd me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['voucher_question'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('is_4ps')): ?><span class="error-msg"><?= htmlspecialchars($errors['is_4ps'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>

                        <div class="col-12 mt-4">
                            <div class="section-header" style="border-bottom:none;padding-bottom:0">
                                <i class="fas fa-shield-alt" aria-hidden="true"></i>
                                <h3 class="text-primary mb-0" style="font-size:1.1rem;"><?= htmlspecialchars($t[$lang]['guardian'], ENT_QUOTES, 'UTF-8') ?></h3>
                            </div>
                        </div>
                        <div class="col-md-6 form-group <?= $filled_class($_POST['guardian_fullname'] ?? null) ?>">
                            <input type="text" name="guardian_fullname" id="guardian_fullname" placeholder=" " value="<?= htmlspecialchars($_POST['guardian_fullname'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="150">
                            <label for="guardian_fullname"><i class="fas fa-user-shield me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['guardian_name'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-6 form-group <?= $filled_class($_POST['guardian_relation'] ?? null) ?>">
                            <input type="text" name="guardian_relation" id="guardian_relation" placeholder=" " value="<?= htmlspecialchars($_POST['guardian_relation'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="60">
                            <label for="guardian_relation"><i class="fas fa-handshake me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['guardian_relation'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-6 form-group <?= $field_has_error('guardian_contact') ? 'error' : '' ?> <?= $filled_class($_POST['guardian_contact'] ?? null) ?>">
                            <input type="tel" name="guardian_contact" id="guardian_contact" placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($_POST['guardian_contact'] ?? '', ENT_QUOTES, 'UTF-8') ?>" pattern="09\d{9}" maxlength="11" inputmode="numeric">
                            <label for="guardian_contact"><i class="fas fa-phone-alt me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['guardian_contact'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('guardian_contact')): ?><span class="error-msg"><?= htmlspecialchars($errors['guardian_contact'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                    </div>
                </section>

                <div class="wizard-nav">
                    <button type="button" class="btn btn-back" data-back="1"><i class="fas fa-arrow-left"></i> <?= htmlspecialchars($t[$lang]['previous'], ENT_QUOTES, 'UTF-8') ?></button>
                    <span class="spacer"></span>
                    <button type="button" class="btn btn-next" data-next="3"><?= htmlspecialchars($t[$lang]['next'], ENT_QUOTES, 'UTF-8') ?> <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>

            <!-- ============ STEP 3 ============ -->
            <div class="wizard-step-content" data-step-content="3">
                <section class="section-card" aria-labelledby="sectionAddressHeading">
                    <div class="section-header"><i class="fas fa-map-marker-alt" aria-hidden="true"></i><h2 id="sectionAddressHeading"><?= htmlspecialchars($t[$lang]['address'], ENT_QUOTES, 'UTF-8') ?></h2></div>
                    <div class="row g-3">
                        <div class="col-md-6 form-group <?= $filled_class($_POST['purok_street'] ?? null) ?>">
                            <input type="text" name="purok_street" id="purok_street" placeholder=" " value="<?= htmlspecialchars($_POST['purok_street'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="street-address" maxlength="200">
                            <label for="purok_street"><i class="fas fa-road me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['street'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-6 form-group <?= $filled_class($_POST['barangay'] ?? null) ?>">
                            <input type="text" name="barangay" id="barangay" placeholder=" " value="<?= htmlspecialchars($_POST['barangay'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="100">
                            <label for="barangay"><i class="fas fa-home me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['barangay'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-6 form-group <?= $filled_class($_POST['town_city'] ?? null) ?>">
                            <input type="text" name="town_city" id="town_city" placeholder=" " value="<?= htmlspecialchars($_POST['town_city'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="address-level2" maxlength="100">
                            <label for="town_city"><i class="fas fa-city me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['city'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-6 form-group <?= $filled_class($_POST['province'] ?? null) ?>">
                            <input type="text" name="province" id="province" placeholder=" " value="<?= htmlspecialchars($_POST['province'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="address-level1" maxlength="100">
                            <label for="province"><i class="fas fa-globe-asia me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['province'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-6 form-group <?= $filled_class($_POST['region'] ?? null) ?>">
                            <input type="text" name="region" id="region" placeholder=" " value="<?= htmlspecialchars($_POST['region'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="100">
                            <label for="region"><i class="fas fa-map me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['region'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-6 form-group <?= $filled_class($_POST['district'] ?? null) ?>">
                            <input type="text" name="district" id="district" placeholder=" " value="<?= htmlspecialchars($_POST['district'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="100">
                            <label for="district"><i class="fas fa-chart-line me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['district'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div class="col-md-6 form-group <?= $filled_class($_POST['postal_code'] ?? null) ?>">
                            <input type="text" name="postal_code" id="postal_code" placeholder=" " value="<?= htmlspecialchars($_POST['postal_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric" autocomplete="postal-code" maxlength="10">
                            <label for="postal_code"><i class="fas fa-mail-bulk me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['postal'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                    </div>
                </section>

                <div class="wizard-nav">
                    <button type="button" class="btn btn-back" data-back="2"><i class="fas fa-arrow-left"></i> <?= htmlspecialchars($t[$lang]['previous'], ENT_QUOTES, 'UTF-8') ?></button>
                    <span class="spacer"></span>
                    <button type="button" class="btn btn-next" data-next="4"><?= htmlspecialchars($t[$lang]['next'], ENT_QUOTES, 'UTF-8') ?> <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>

            <!-- ============ STEP 4 ============ -->
            <div class="wizard-step-content" data-step-content="4">
                <section class="section-card" aria-labelledby="sectionEducationHeading">
                    <div class="section-header"><i class="fas fa-graduation-cap" aria-hidden="true"></i><h2 id="sectionEducationHeading"><?= htmlspecialchars($t[$lang]['education'], ENT_QUOTES, 'UTF-8') ?></h2></div>
                    <div id="edu-container">
                        <?php foreach ($edu_history as $idx => $edu): ?>
                            <div class="edu-entry row g-3 align-items-end" role="group" aria-label="Education entry <?= $idx + 1 ?>">
                                <div class="col-md-3 form-group filled">
                                    <select name="edu_level[]" class="edu-level" id="edu_level_<?= $idx ?>" style="background:white">
                                        <option value="Elementary" <?= ($edu['level']=='Elementary')?'selected':'' ?>><?= htmlspecialchars($t[$lang]['elem'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <option value="JHS" <?= ($edu['level']=='JHS')?'selected':'' ?>><?= htmlspecialchars($t[$lang]['jhs'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <option value="Transferred" <?= ($edu['level']=='Transferred')?'selected':'' ?>><?= htmlspecialchars($t[$lang]['trans'], ENT_QUOTES, 'UTF-8') ?></option>
                                    </select>
                                    <label for="edu_level_<?= $idx ?>"><?= htmlspecialchars($t[$lang]['level_label'], ENT_QUOTES, 'UTF-8') ?></label>
                                </div>
                                <div class="col-md-3 form-group <?= $filled_class($edu['school_name']) ?>">
                                    <input type="text" name="school_name[]" id="school_name_<?= $idx ?>" placeholder=" " value="<?= htmlspecialchars($edu['school_name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="200">
                                    <label for="school_name_<?= $idx ?>"><?= htmlspecialchars($t[$lang]['school_name'], ENT_QUOTES, 'UTF-8') ?></label>
                                </div>
                                <div class="col-md-3 form-group <?= $filled_class($edu['school_address']) ?>">
                                    <input type="text" name="school_address[]" id="school_address_<?= $idx ?>" placeholder=" " value="<?= htmlspecialchars($edu['school_address'], ENT_QUOTES, 'UTF-8') ?>" maxlength="300">
                                    <label for="school_address_<?= $idx ?>"><?= htmlspecialchars($t[$lang]['school_addr'], ENT_QUOTES, 'UTF-8') ?></label>
                                </div>
                                <div class="col-md-3 form-group <?= $filled_class($edu['year_completed']) ?>">
                                    <input type="text" name="year_completed[]" id="year_completed_<?= $idx ?>" placeholder=" " value="<?= htmlspecialchars($edu['year_completed'], ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric" maxlength="20">
                                    <label for="year_completed_<?= $idx ?>"><?= htmlspecialchars($t[$lang]['year_comp'], ENT_QUOTES, 'UTF-8') ?></label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-add mt-3" id="addEduBtn" style="background:linear-gradient(135deg,#28a745,#20c997);color:#fff;border:none;padding:.7rem 1.5rem;border-radius:50px;font-weight:600"><i class="fas fa-plus me-2" aria-hidden="true"></i><?= htmlspecialchars($t[$lang]['add_school'], ENT_QUOTES, 'UTF-8') ?></button>
                </section>

                <div class="wizard-nav">
                    <button type="button" class="btn btn-back" data-back="3"><i class="fas fa-arrow-left"></i> <?= htmlspecialchars($t[$lang]['previous'], ENT_QUOTES, 'UTF-8') ?></button>
                    <span class="spacer"></span>
                    <button type="button" class="btn btn-next" data-next="5"><?= htmlspecialchars($t[$lang]['next'], ENT_QUOTES, 'UTF-8') ?> <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>

            <!-- ============ STEP 5 ============ -->
            <div class="wizard-step-content" data-step-content="5">
                <?php if (!empty($errors['document_mismatch']) && is_array($_SESSION['name_verify'] ?? null)): ?>
                    <?php
                        $nv       = $_SESSION['name_verify'];
                        $nv_name  = $nv['name'] ?? [];
                        $nv_parts = $nv_name['parts'] ?? [];
                        $nv_lrn   = $nv['lrn'] ?? [];
                        $nv_rot   = (int)($nv['rotation'] ?? 0);
                        $nv_score = (int)($nv['score'] ?? 0);
                    ?>
                    <div class="doc-mismatch-banner" role="alert" aria-live="assertive">
                        <div class="doc-mismatch-icon"><i class="fas fa-circle-xmark" aria-hidden="true"></i></div>
                        <div class="doc-mismatch-body">
                            <div class="doc-mismatch-title">Cannot submit — JHS Certificate does not match</div>
                            <p class="doc-mismatch-text">The <strong>Junior High School Certificate (Front)</strong> you uploaded does not match the student name or LRN entered in Step 1. Please <strong>re-upload a valid copy</strong> of the correct document below before submitting for review.</p>
                            <div class="doc-mismatch-details">
                                <?php if (!empty($nv_parts)): ?>
                                    <div class="doc-mismatch-row">
                                        <span class="doc-mismatch-key">Name on document:</span>
                                        <span class="doc-mismatch-val">
                                            <i class="fas <?= !empty($nv_parts['first']) ? 'fa-check text-success' : 'fa-xmark text-danger' ?>"></i> First
                                            <i class="fas <?= ($nv_parts['middle'] === true ? 'fa-check text-success' : ($nv_parts['middle'] === false ? 'fa-xmark text-danger' : 'fa-minus text-secondary')) ?>"></i> Middle
                                            <i class="fas <?= !empty($nv_parts['last']) ? 'fa-check text-success' : 'fa-xmark text-danger' ?>"></i> Last
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($nv_lrn['expected'])): ?>
                                    <div class="doc-mismatch-row">
                                        <span class="doc-mismatch-key">LRN expected:</span>
                                        <span class="doc-mismatch-val mono"><?= htmlspecialchars((string)$nv_lrn['expected'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <?php if (!empty($nv_lrn['best'])): ?>
                                        <div class="doc-mismatch-row">
                                            <span class="doc-mismatch-key">LRN found on document:</span>
                                            <span class="doc-mismatch-val mono">
                                                <?= htmlspecialchars((string)$nv_lrn['best'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (!empty($nv_lrn['distance'])): ?>
                                                    <span class="doc-mismatch-distance">(<?= (int)$nv_lrn['distance'] ?> digit<?= (int)$nv_lrn['distance'] === 1 ? '' : 's' ?> off)</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <div class="doc-mismatch-row">
                                            <span class="doc-mismatch-key">LRN found on document:</span>
                                            <span class="doc-mismatch-val"><em>Could not be read</em></span>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="doc-mismatch-hint"><i class="fas fa-arrow-down" aria-hidden="true"></i>Scroll down to the <strong>Entrance Data Presented</strong> section and re-upload the correct JHS Certificate (Front).</div>
                            <div class="doc-mismatch-meta">
                                <span><i class="fas fa-rotate"></i> Detected rotation: <?= $nv_rot ?>°</span>
                                <span><i class="fas fa-gauge-high"></i> OCR score: <?= $nv_score ?></span>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors['photo_2x2']) && is_array($_SESSION['photo_2x2_check'] ?? null)): ?>
                    <?php $p2 = $_SESSION['photo_2x2_check']; ?>
                    <div class="photo-warn-banner" role="alert" aria-live="assertive">
                        <div class="photo-warn-icon"><i class="fas fa-camera-retro" aria-hidden="true"></i></div>
                        <div style="flex:1;min-width:0">
                            <div class="photo-warn-title">2×2 picture needs attention</div>
                            <p class="photo-warn-text"><?= htmlspecialchars((string)($p2['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="photo-warn-hint"><i class="fas fa-arrow-down" aria-hidden="true"></i>Re-upload a proper 2×2 photo (square, at least 300×300px, JPG or PNG) below.</div>
                        </div>
                    </div>
                <?php endif; ?>

                <section class="section-card" aria-labelledby="sectionEnrollmentHeading">
                    <div class="section-header"><i class="fas fa-clipboard-list" aria-hidden="true"></i><h2 id="sectionEnrollmentHeading"><?= htmlspecialchars($t[$lang]['enrollment'], ENT_QUOTES, 'UTF-8') ?></h2></div>
                    <div class="row g-3">
                        <div class="col-md-3 form-group <?= $field_has_error('school_year') ? 'error' : '' ?> filled">
                            <select name="school_year" id="school_year" required aria-required="true" style="background:white">
                                <?php foreach ($SY_OPTIONS as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>" <?= $cur_sy === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="school_year"><i class="fas fa-calendar me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['sy'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('school_year')): ?><span class="error-msg"><?= htmlspecialchars($errors['school_year'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="col-md-3 form-group <?= $field_has_error('grade_level') ? 'error' : '' ?> <?= $filled_class($_POST['grade_level'] ?? null) ?>">
                            <select name="grade_level" id="grade_level" required aria-required="true">
                                <option value="" disabled <?= empty($_POST['grade_level']) ? 'selected' : '' ?>></option>
                                <option value="Grade 11" <?= ($_POST['grade_level'] ?? '') == 'Grade 11' ? 'selected' : '' ?>>Grade 11</option>
                                <option value="Grade 12" <?= ($_POST['grade_level'] ?? '') == 'Grade 12' ? 'selected' : '' ?>>Grade 12</option>
                            </select>
                            <label for="grade_level"><i class="fas fa-layer-group me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['grade'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('grade_level')): ?><span class="error-msg"><?= htmlspecialchars($errors['grade_level'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="col-md-3 form-group <?= $field_has_error('term') ? 'error' : '' ?> <?= $filled_class($_POST['term'] ?? null) ?>">
                            <select name="term" id="term" required aria-required="true">
                                <option value="" disabled <?= empty($_POST['term']) ? 'selected' : '' ?>></option>
                                <option value="1st Term" <?= ($_POST['term'] ?? '') == '1st Term' ? 'selected' : '' ?>>1st Term</option>
                                <option value="2nd Term" <?= ($_POST['term'] ?? '') == '2nd Term' ? 'selected' : '' ?>>2nd Term</option>
                                <option value="3rd Term" <?= ($_POST['term'] ?? '') == '3rd Term' ? 'selected' : '' ?>>3rd Term</option>
                            </select>
                            <label for="term"><i class="fas fa-chart-simple me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['term'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('term')): ?><span class="error-msg"><?= htmlspecialchars($errors['term'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="col-md-3 form-group filled">
                            <input type="text" name="track" id="track" value="TECHPRO ELECTIVES" readonly required style="background:#f8f9fa;cursor:not-allowed" aria-readonly="true">
                            <label for="track"><i class="fas fa-road me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['track'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                    </div>
                    <div class="row g-3 mt-2">
                        <div class="col-md-6 form-group <?= $field_has_error('strand') ? 'error' : '' ?> <?= $filled_class($_POST['strand'] ?? null) ?>">
                            <select name="strand" id="strand" required aria-required="true">
                                <option value="" disabled selected></option>
                            </select>
                            <label for="strand"><i class="fas fa-tree me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['strand'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('strand')): ?><span class="error-msg"><?= htmlspecialchars($errors['strand'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <div class="col-md-6 form-group <?= $field_has_error('program') ? 'error' : '' ?> <?= $filled_class($_POST['program'] ?? null) ?>">
                            <select name="program" id="program" required aria-required="true">
                                <option value="" disabled selected></option>
                            </select>
                            <label for="program"><i class="fas fa-code-branch me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['program'], ENT_QUOTES, 'UTF-8') ?></label>
                            <?php if($field_has_error('program')): ?><span class="error-msg"><?= htmlspecialchars($errors['program'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                    </div>

                    <div id="strandRecommendation" style="display:none;"></div>

                    <div class="form-group mt-3 <?= $filled_class($_POST['household_id'] ?? null) ?>">
                        <input type="text" name="household_id" id="household_id" placeholder=" " value="<?= htmlspecialchars($_POST['household_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="50">
                        <label for="household_id"><i class="fas fa-home me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['household'], ENT_QUOTES, 'UTF-8') ?></label>
                    </div>
                    <div class="mt-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_transferred" id="is_transferred" value="1" style="width:18px;height:18px" <?= !empty($_POST['is_transferred']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold ms-2" for="is_transferred"><i class="fas fa-exchange-alt me-1 text-primary" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['transferred_in'], ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <div id="transferee-fields" class="transferee-section" style="display:<?= !empty($_POST['is_transferred']) ? 'block' : 'none' ?>">
                            <div class="row g-3">
                                <div class="col-md-6 form-group <?= $filled_class($_POST['previous_school_name'] ?? null) ?>">
                                    <input type="text" name="previous_school_name" id="previous_school_name" placeholder=" " value="<?= htmlspecialchars($_POST['previous_school_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="200">
                                    <label for="previous_school_name"><?= htmlspecialchars($t[$lang]['prev_school_name'], ENT_QUOTES, 'UTF-8') ?></label>
                                </div>
                                <div class="col-md-6 form-group <?= $filled_class($_POST['previous_school_address'] ?? null) ?>">
                                    <textarea name="previous_school_address" id="previous_school_address" rows="2" placeholder=" " maxlength="300"><?= htmlspecialchars($_POST['previous_school_address'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                    <label for="previous_school_address"><?= htmlspecialchars($t[$lang]['prev_school_address'], ENT_QUOTES, 'UTF-8') ?></label>
                                </div>
                                <div class="col-md-4 form-group <?= $filled_class($cur_prev_track) ?>">
                                    <select name="previous_track" id="previous_track" style="background:white">
                                        <option value="" <?= $cur_prev_track === '' ? 'selected' : '' ?>>— <?= htmlspecialchars($t[$lang]['select_placeholder'], ENT_QUOTES, 'UTF-8') ?> —</option>
                                        <?php foreach ($TRACK_OPTIONS as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>" <?= $cur_prev_track === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label for="previous_track"><?= htmlspecialchars($t[$lang]['prev_track'], ENT_QUOTES, 'UTF-8') ?></label>
                                </div>
                                <div class="col-md-4 form-group <?= $filled_class($cur_prev_strand) ?>">
                                    <select name="previous_strand" id="previous_strand" style="background:white">
                                        <option value="" <?= $cur_prev_strand === '' ? 'selected' : '' ?>>— <?= htmlspecialchars($t[$lang]['select_placeholder'], ENT_QUOTES, 'UTF-8') ?> —</option>
                                        <?php foreach (array_keys($STRAND_PROGRAMS) as $s): ?>
                                            <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>" <?= $cur_prev_strand === $s ? 'selected' : '' ?>><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label for="previous_strand"><?= htmlspecialchars($t[$lang]['prev_strand'], ENT_QUOTES, 'UTF-8') ?></label>
                                </div>
                                <div class="col-md-4 form-group <?= $filled_class($cur_prev_program) ?>">
                                    <select name="previous_program" id="previous_program" style="background:white">
                                        <option value="" <?= $cur_prev_program === '' ? 'selected' : '' ?>>— <?= htmlspecialchars($t[$lang]['select_placeholder'], ENT_QUOTES, 'UTF-8') ?> —</option>
                                        <?php
                                        if ($cur_prev_strand !== '' && isset($STRAND_PROGRAMS[$cur_prev_strand])) {
                                            foreach ($STRAND_PROGRAMS[$cur_prev_strand] as $p) {
                                                echo '<option value="' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '"' . ($cur_prev_program === $p ? ' selected' : '') . '>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                    <label for="previous_program"><?= htmlspecialchars($t[$lang]['prev_program'], ENT_QUOTES, 'UTF-8') ?></label>
                                </div>
                                <div class="col-md-4 form-group <?= $filled_class($_POST['previous_year_completed'] ?? null) ?>">
                                    <input type="number" name="previous_year_completed" id="previous_year_completed" placeholder=" " value="<?= htmlspecialchars($_POST['previous_year_completed'] ?? '', ENT_QUOTES, 'UTF-8') ?>" min="2000" max="<?= date('Y') ?>">
                                    <label for="previous_year_completed"><?= htmlspecialchars($t[$lang]['prev_year_completed'], ENT_QUOTES, 'UTF-8') ?></label>
                                </div>
                            </div>
                            <fieldset class="mt-4" style="border:0;padding:0;">
                                <legend class="form-label fw-bold mb-2" style="font-size:0.95rem;float:none;width:auto;"><?= htmlspecialchars($t[$lang]['voucher_qualified'], ENT_QUOTES, 'UTF-8') ?></legend>
                                <div class="d-flex gap-4 flex-wrap">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="voucher_qualified" id="vq_yes" value="1" <?= ($_POST['voucher_qualified'] ?? '') == '1' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="vq_yes"><?= htmlspecialchars($t[$lang]['qualified_voucher'], ENT_QUOTES, 'UTF-8') ?></label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="voucher_qualified" id="vq_no" value="0" <?= ($_POST['voucher_qualified'] ?? '') == '0' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="vq_no"><?= htmlspecialchars($t[$lang]['not_qualified_voucher'], ENT_QUOTES, 'UTF-8') ?></label>
                                    </div>
                                </div>
                            </fieldset>
                        </div>
                    </div>
                </section>

                <section class="section-card" aria-labelledby="sectionDocsHeading">
                    <div class="section-header"><i class="fas fa-folder-open" aria-hidden="true"></i><h2 id="sectionDocsHeading"><?= htmlspecialchars($t[$lang]['entrance_data'], ENT_QUOTES, 'UTF-8') ?></h2></div>
                    <fieldset class="doc-fieldset">
                        <legend class="sr-only"><?= htmlspecialchars($t[$lang]['entrance_data'], ENT_QUOTES, 'UTF-8') ?></legend>
                        <div class="checkbox-container">
                            <?php foreach ($doc_options as $doc_name => $doc_key): ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="entrance_data[]" value="<?= htmlspecialchars($doc_name, ENT_QUOTES, 'UTF-8') ?>" id="<?= $doc_key ?>" <?= in_array($doc_name, $_POST['entrance_data'] ?? [], true) ? 'checked' : '' ?>>
                                    <label for="<?= $doc_key ?>"><i class="fas fa-check-circle me-2 text-primary" aria-hidden="true"></i><?= htmlspecialchars($t[$lang][$doc_key], ENT_QUOTES, 'UTF-8') ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <?php if(isset($errors['entrance_data'])): ?>
                        <span class="error-msg" role="alert"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i><?= htmlspecialchars($errors['entrance_data'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>

                    <div class="mt-3">
                        <?php foreach ($doc_options as $doc_name => $doc_key):
                            $file_info = $display_files[$doc_key] ?? null;
                            $has_upload = $file_info !== null;
                            $is_checked = in_array($doc_name, $_POST['entrance_data'] ?? [], true);
                            $show_row = $is_checked || $has_upload;
                            $row_id = 'uploadRow_' . $doc_key;
                            $file_input_id = 'file_' . $doc_key;
                        ?>
                            <div class="doc-upload-row <?= $show_row ? 'visible' : '' ?>" id="<?= $row_id ?>">
                                <div class="upload-row row g-3 align-items-end <?= $has_upload ? 'has-file' : '' ?>">
                                    <div class="col-md-4">
                                        <label class="form-label small fw-semibold" for="label_<?= $doc_key ?>"><?= htmlspecialchars($t[$lang]['document_label'], ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="text" class="form-control" id="label_<?= $doc_key ?>" value="<?= htmlspecialchars($doc_name, ENT_QUOTES, 'UTF-8') ?>" readonly>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold" for="<?= $file_input_id ?>"><?= htmlspecialchars($t[$lang]['upload_file'], ENT_QUOTES, 'UTF-8') ?></label>
                                        <input type="file" id="<?= $file_input_id ?>" name="entrance_files[<?= $doc_key ?>]" class="form-control" accept=".pdf,.jpg,.jpeg,.png" <?php if ($has_upload): ?>disabled style="background:#e9ecef"<?php endif; ?>>
                                        <?php if ($has_upload): ?>
                                            <small class="text-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($t[$lang]['already_staged'], ENT_QUOTES, 'UTF-8') ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-3">
                                        <span class="form-label small fw-semibold"><?= htmlspecialchars($t[$lang]['or_capture'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <button type="button" class="btn btn-sm w-100" style="background:linear-gradient(135deg,#0d6efd,#6610f2);color:#fff;border-radius:50px;font-weight:600" onclick="openCamera('<?= htmlspecialchars($doc_key, ENT_QUOTES, 'UTF-8') ?>')">
                                            <i class="fas fa-camera me-1" aria-hidden="true"></i> <?= htmlspecialchars($t[$lang]['capture'], ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    </div>
                                    <div class="col-md-2">
                                        <?php if ($has_upload): ?>
                                            <span class="badge bg-success" style="word-break:break-all;"><i class="fas fa-check-circle" aria-hidden="true"></i> <?= htmlspecialchars((string)$file_info['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                        <div class="preview-thumb" id="preview_<?= $doc_key ?>" style="display:none">
                                            <img src="" alt="<?= htmlspecialchars($t[$lang]['photo_preview'], ENT_QUOTES, 'UTF-8') ?>" id="previewImg_<?= $doc_key ?>">
                                        </div>
                                        <input type="hidden" name="camera_data[<?= $doc_key ?>]" id="cameraData_<?= $doc_key ?>" value="">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="wizard-nav">
                    <button type="button" class="btn btn-back" data-back="4"><i class="fas fa-arrow-left"></i> <?= htmlspecialchars($t[$lang]['previous'], ENT_QUOTES, 'UTF-8') ?></button>
                    <span class="spacer"></span>
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-paper-plane"></i> <?= htmlspecialchars($t[$lang]['submit'], ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </div>
        </form>

    <?php else: ?>
        <!-- ================= REVIEW MODE ================= -->
        <?php
            $full_name = trim(($_POST['first_name'] ?? '') . ' ' . ($_POST['middle_name'] ?? '') . ' ' . ($_POST['last_name'] ?? '') . ' ' . ($_POST['ext_name'] ?? ''));
            $full_name_display = $full_name !== '' ? preg_replace('/\s+/', ' ', $full_name) : '—';
            $birth_display = !empty($_POST['birth_date']) ? date('F d, Y', strtotime((string)$_POST['birth_date'])) : '—';
            $vq = $_POST['voucher_qualified'] ?? '';
            $voucher_display = $vq === '1' ? $t[$lang]['qualified_voucher'] : ($vq === '0' ? $t[$lang]['not_qualified_voucher'] : '—');
            $section_display = !empty($_POST['section']) ? $_POST['section'] : ($_POST['strand'] ?? '—');

            $jhs_status = $_SESSION['name_verify']['status']     ?? null;
            $p2_status  = $_SESSION['photo_2x2_check']['status'] ?? null;

            $jhs_valid  = jhs_can_submit($_SESSION['name_verify']     ?? null);
            $p2_valid   = photo_can_submit($_SESSION['photo_2x2_check'] ?? null);
            $docs_valid = $jhs_valid && $p2_valid;

            $doc_blockers = [];
            if (!$jhs_valid) {
                $doc_blockers[] = match ($jhs_status) {
                    'partial'      => 'The JHS Certificate only partially matched the student name or LRN.',
                    'unverifiable' => 'The JHS Certificate could not be read automatically — please re-upload a clearer copy.',
                    'mismatch'     => 'The JHS Certificate does not match the student name or LRN.',
                    default        => 'The JHS Certificate could not be verified.',
                };
            }
            if (!$p2_valid) {
                $doc_blockers[] = match ($p2_status) {
                    'partial'      => 'The 2×2 picture is not square — please upload a proper 2×2 photo.',
                    'unverifiable' => 'The 2×2 picture could not be validated automatically.',
                    'mismatch'     => 'The 2×2 picture is invalid (wrong format or too small).',
                    default        => 'The 2×2 picture failed its format check.',
                };
            }
        ?>

        <div class="review-container">
            <div class="text-center mb-4">
                <i class="fas fa-check-circle" style="font-size:4rem;color:#047857" aria-hidden="true"></i>
                <h1 class="mt-3" style="color:#1565c0"><?= htmlspecialchars($t[$lang]['form'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($t[$lang]['review_confirm'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="text-muted"><?= htmlspecialchars($t[$lang]['review_help'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <section class="review-section" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-left-color:#4f46e5">
                <div class="row g-3">
                    <div class="col-md-8">
                        <h2 class="mb-2" style="color:#1e3c72;font-size:1.4rem;"><i class="fas fa-user-graduate me-2"></i><?= htmlspecialchars($full_name_display, ENT_QUOTES, 'UTF-8') ?></h2>
                        <div class="d-flex flex-wrap gap-3 small">
                            <?php if (!empty($_POST['lrn'])): ?><span><strong>LRN:</strong> <?= htmlspecialchars((string)$_POST['lrn'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                            <?php if (!empty($_POST['sex'])): ?><span><strong>Sex:</strong> <?= htmlspecialchars((string)$_POST['sex'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                            <?php if (!empty($_POST['age'])): ?><span><strong>Age:</strong> <?= htmlspecialchars((string)$_POST['age'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="small">
                            <div><strong>Grade Level:</strong> <?= htmlspecialchars((string)($_POST['grade_level'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><strong>Strand:</strong> <?= htmlspecialchars((string)($_POST['strand'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><strong>School Year:</strong> <?= htmlspecialchars((string)($_POST['school_year'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="review-section">
                <h2 style="font-size:1.2rem;"><i class="fas fa-user-graduate me-2"></i><?= htmlspecialchars($t[$lang]['student'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="review-grid">
                    <?php
                    $sf = ['lrn'=>'LRN','last_name'=>'Last Name','first_name'=>'First Name','middle_name'=>'Middle Name','nick_name'=>'Nickname','ext_name'=>'Extension Name','sex'=>'Sex','birth_date'=>'Birth Date','age'=>'Age','civil_status'=>'Civil Status','nationality'=>'Nationality','religion'=>'Religion','height'=>'Height','weight'=>'Weight','email'=>'Email','phone'=>'Phone','special_skills'=>'Special Skills'];
                    foreach ($sf as $k => $l):
                        $val = $_POST[$k] ?? '';
                        if ($k === 'birth_date' && $val !== '') $val = $birth_display;
                        if ($k === 'height' && $val !== '') $val .= ' cm';
                        if ($k === 'weight' && $val !== '') $val .= ' kg';
                    ?>
                        <div class="review-item"><strong><?= htmlspecialchars($l, ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($val !== '' ? (string)$val : '—', ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="review-section">
                <h2 style="font-size:1.2rem;"><i class="fas fa-users me-2"></i><?= htmlspecialchars($t[$lang]['parents'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="review-grid">
                    <?php
                    $pf = ['father_name'=>'Father Name','father_occupation'=>'Father Occupation','father_contact'=>'Father Contact','mother_maiden_name'=>'Mother Maiden Name','mother_occupation'=>'Mother Occupation','mother_contact'=>'Mother Contact','ave_family_income'=>'Average Family Income'];
                    foreach ($pf as $k => $l):
                        $val = $_POST[$k] ?? '';
                    ?>
                        <div class="review-item"><strong><?= htmlspecialchars($l, ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($val !== '' ? (string)$val : '—', ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endforeach; ?>
                    <div class="review-item"><strong>CCT/4Ps Recipient:</strong> <?= (isset($_POST['is_4ps']) && $_POST['is_4ps'] == '1') ? htmlspecialchars($t[$lang]['yes'], ENT_QUOTES, 'UTF-8') : htmlspecialchars($t[$lang]['no'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </section>

            <?php if (!empty($_POST['guardian_fullname']) || !empty($_POST['guardian_relation']) || !empty($_POST['guardian_contact'])): ?>
            <section class="review-section">
                <h2 style="font-size:1.2rem;"><i class="fas fa-shield-alt me-2"></i><?= htmlspecialchars($t[$lang]['guardian'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="review-grid">
                    <div class="review-item"><strong>Guardian Name:</strong> <?= htmlspecialchars((string)($_POST['guardian_fullname'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Relationship:</strong> <?= htmlspecialchars((string)($_POST['guardian_relation'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Contact Number:</strong> <?= htmlspecialchars((string)($_POST['guardian_contact'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </section>
            <?php endif; ?>

            <section class="review-section">
                <h2 style="font-size:1.2rem;"><i class="fas fa-map-marker-alt me-2"></i><?= htmlspecialchars($t[$lang]['address'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="review-grid">
                    <?php
                    $af = ['purok_street'=>'Purok / Street','barangay'=>'Barangay','town_city'=>'Town / City','province'=>'Province','region'=>'Region','district'=>'District','postal_code'=>'Postal Code'];
                    foreach ($af as $k => $l):
                        $val = $_POST[$k] ?? '';
                    ?>
                        <div class="review-item"><strong><?= htmlspecialchars($l, ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($val !== '' ? (string)$val : '—', ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="review-section">
                <h2 style="font-size:1.2rem;"><i class="fas fa-graduation-cap me-2"></i><?= htmlspecialchars($t[$lang]['education'], ENT_QUOTES, 'UTF-8') ?></h2>
                <?php
                $has_edu = false;
                if (isset($_POST['edu_level']) && is_array($_POST['edu_level'])):
                    foreach ($_POST['edu_level'] as $i => $level):
                        $sn = $_POST['school_name'][$i] ?? '';
                        $sa = $_POST['school_address'][$i] ?? '';
                        $yc = $_POST['year_completed'][$i] ?? '';
                        if ($level === '' && $sn === '' && $sa === '' && $yc === '') continue;
                        $has_edu = true;
                ?>
                    <div style="margin-bottom:1rem;padding:1rem;background:white;border-radius:8px;border-left:3px solid #1e88e5">
                        <strong style="color:#1565c0"><?= htmlspecialchars((string)($level ?: '—'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <div class="mt-2 small">
                            <div><strong>School:</strong> <?= htmlspecialchars((string)($sn ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><strong>Address:</strong> <?= htmlspecialchars((string)($sa ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><strong>Year Completed:</strong> <?= htmlspecialchars((string)($yc ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
                <?php if (!$has_edu): ?><p class="text-muted mb-0">No educational history provided.</p><?php endif; ?>
            </section>

            <section class="review-section">
                <h2 style="font-size:1.2rem;"><i class="fas fa-clipboard-list me-2"></i><?= htmlspecialchars($t[$lang]['enrollment'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="review-grid">
                    <div class="review-item"><strong>School Year:</strong> <?= htmlspecialchars((string)($_POST['school_year'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Grade Level:</strong> <?= htmlspecialchars((string)($_POST['grade_level'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Term:</strong> <?= htmlspecialchars((string)($_POST['term'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Track:</strong> <?= htmlspecialchars((string)($_POST['track'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Strand:</strong> <?= htmlspecialchars((string)($_POST['strand'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Program:</strong> <?= htmlspecialchars((string)($_POST['program'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Section:</strong> <?= htmlspecialchars((string)$section_display, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Household ID:</strong> <?= htmlspecialchars((string)($_POST['household_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Voucher Status:</strong> <?= htmlspecialchars($voucher_display, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Transferee:</strong> <?= !empty($_POST['is_transferred']) ? 'Yes' : 'No' ?></div>
                </div>
            </section>

            <?php if (!empty($_POST['is_transferred'])): ?>
            <section class="review-section">
                <h2 style="font-size:1.2rem;"><i class="fas fa-exchange-alt me-2"></i><?= htmlspecialchars($t[$lang]['transferred_in'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="review-grid">
                    <div class="review-item"><strong>Previous School:</strong> <?= htmlspecialchars((string)($_POST['previous_school_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Previous Address:</strong> <?= htmlspecialchars((string)($_POST['previous_school_address'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Previous Track:</strong> <?= htmlspecialchars((string)($_POST['previous_track'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Previous Strand:</strong> <?= htmlspecialchars((string)($_POST['previous_strand'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Previous Program:</strong> <?= htmlspecialchars((string)($_POST['previous_program'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="review-item"><strong>Year Completed:</strong> <?= htmlspecialchars((string)($_POST['previous_year_completed'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </section>
            <?php endif; ?>

            <section class="review-section">
                <h2 style="font-size:1.2rem;"><i class="fas fa-folder-open me-2"></i><?= htmlspecialchars($t[$lang]['entrance_data'], ENT_QUOTES, 'UTF-8') ?></h2>
                <h3 class="mt-2 mb-2" style="font-size:1rem;"><i class="fas fa-check-circle text-success me-2"></i><?= htmlspecialchars($t[$lang]['docs_selected'], ENT_QUOTES, 'UTF-8') ?></h3>
                <ul class="list-unstyled mb-3">
                    <?php if (!empty($_POST['entrance_data'] ?? [])): ?>
                        <?php foreach (($_POST['entrance_data'] ?? []) as $d): ?>
                            <li class="mb-1"><i class="fas fa-check text-success me-2"></i><?= htmlspecialchars((string)$d, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="text-muted"><?= htmlspecialchars($t[$lang]['none_selected'], ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endif; ?>
                </ul>
                <?php if (!empty($display_files)): ?>
                    <h3 class="mt-3 mb-2" style="font-size:1rem;"><i class="fas fa-paperclip text-primary me-2"></i><?= htmlspecialchars($t[$lang]['staged_files'], ENT_QUOTES, 'UTF-8') ?> (<?= count($display_files) ?>)</h3>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($display_files as $dk => $f):
                            $is_img = strpos((string)($f['mime'] ?? ''), 'image/') === 0;
                        ?>
                            <li class="mb-2 d-flex align-items-center gap-2 flex-wrap">
                                <?php if ($is_img): ?><i class="fas fa-image text-primary"></i><?php else: ?><i class="fas fa-file-pdf text-danger"></i><?php endif; ?>
                                <strong><?= htmlspecialchars((string)$f['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span class="badge bg-light text-dark"><?= htmlspecialchars((string)($f['mime'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="text-muted small">(<?= round(((int)($f['size'] ?? 0)) / 1024, 1) ?> KB)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted mb-0 mt-3"><em><?= htmlspecialchars($t[$lang]['no_files'], ENT_QUOTES, 'UTF-8') ?></em></p>
                <?php endif; ?>
            </section>

            <?php
            $nv = $_SESSION['name_verify'] ?? null;
            if (is_array($nv)):
                $status      = $nv['status']     ?? 'unverifiable';
                $extracted   = $nv['extracted']  ?? null;
                $nv_name     = $nv['name'] ?? ['status' => 'unverifiable', 'parts' => [], 'message' => ''];
                $nv_lrn      = $nv['lrn']  ?? ['status' => 'unverifiable', 'expected' => '', 'best' => null, 'distance' => null, 'message' => ''];
                $nv_name_parts = $nv_name['parts'] ?? [];
                $nv_rotation = (int)($nv['rotation'] ?? 0);
                $nv_score    = (int)($nv['score'] ?? 0);

                $theme = [
                    'match'        => ['cls' => 'nv-match',        'icon' => 'fa-circle-check',         'label' => 'Verified',            'sub' => 'Form details match the document.'],
                    'partial'      => ['cls' => 'nv-partial',      'icon' => 'fa-triangle-exclamation', 'label' => 'Partial match',       'sub' => 'Some details were not found on the document.'],
                    'mismatch'     => ['cls' => 'nv-mismatch',     'icon' => 'fa-circle-xmark',         'label' => 'Mismatch',            'sub' => 'The document does not match the form.'],
                    'unverifiable' => ['cls' => 'nv-unverifiable', 'icon' => 'fa-circle-info',          'label' => 'Manual check needed', 'sub' => 'Automatic reading was not possible.'],
                ][$status] ?? ['cls' => 'nv-unverifiable', 'icon' => 'fa-circle-info', 'label' => 'Verification', 'sub' => ''];

                $lrn_status_map = [
                    'match'        => ['cls' => 'ok',   'icon' => 'fa-check', 'label' => 'LRN verified'],
                    'close'        => ['cls' => 'warn', 'icon' => 'fa-check', 'label' => 'LRN close match'],
                    'mismatch'     => ['cls' => 'miss', 'icon' => 'fa-xmark', 'label' => 'LRN mismatch'],
                    'invalid'      => ['cls' => 'miss', 'icon' => 'fa-xmark', 'label' => 'LRN invalid'],
                    'unverifiable' => ['cls' => 'skip', 'icon' => 'fa-minus', 'label' => 'LRN not readable'],
                    'skipped'      => ['cls' => 'skip', 'icon' => 'fa-minus', 'label' => 'LRN not provided'],
                ];
                $ls = $lrn_status_map[$nv_lrn['status']] ?? $lrn_status_map['unverifiable'];
            ?>
            <section class="review-section name-verify-card <?= $theme['cls'] ?>" role="status" aria-live="polite">
                <div class="name-verify-head">
                    <div class="name-verify-icon"><i class="fas <?= $theme['icon'] ?>"></i></div>
                    <div class="name-verify-titles">
                        <h2 style="font-size:1.15rem;margin:0;"><i class="fas fa-id-card-clip me-2"></i>JHS Certificate (Front) — Identity Check</h2>
                        <div class="name-verify-sub"><?= htmlspecialchars($theme['sub'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <span class="name-verify-badge"><?= htmlspecialchars($theme['label'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <div class="verify-block">
                    <div class="verify-block-title">
                        <i class="fas fa-user me-1"></i>Name
                        <?php if ($nv_name['status'] === 'match'): ?>
                            <span class="verify-block-verdict ok"><i class="fas fa-check"></i> Matched</span>
                        <?php elseif ($nv_name['status'] === 'partial'): ?>
                            <span class="verify-block-verdict warn"><i class="fas fa-exclamation"></i> Partial</span>
                        <?php elseif ($nv_name['status'] === 'mismatch'): ?>
                            <span class="verify-block-verdict miss"><i class="fas fa-xmark"></i> Mismatch</span>
                        <?php else: ?>
                            <span class="verify-block-verdict skip"><i class="fas fa-minus"></i> Unverified</span>
                        <?php endif; ?>
                    </div>
                    <div class="name-verify-grid">
                        <div class="name-verify-part <?= !empty($nv_name_parts['first']) ? 'ok' : 'miss' ?>"><i class="fas <?= !empty($nv_name_parts['first']) ? 'fa-check' : 'fa-xmark' ?>"></i> First name</div>
                        <div class="name-verify-part <?= ($nv_name_parts['middle'] === true ? 'ok' : ($nv_name_parts['middle'] === false ? 'miss' : 'skip')) ?>"><i class="fas <?= ($nv_name_parts['middle'] === true ? 'fa-check' : ($nv_name_parts['middle'] === false ? 'fa-xmark' : 'fa-minus')) ?>"></i> Middle name</div>
                        <div class="name-verify-part <?= !empty($nv_name_parts['last']) ? 'ok' : 'miss' ?>"><i class="fas <?= !empty($nv_name_parts['last']) ? 'fa-check' : 'fa-xmark' ?>"></i> Last name</div>
                    </div>
                    <div class="verify-block-msg"><?= htmlspecialchars($nv_name['message'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>

                <div class="verify-block">
                    <div class="verify-block-title">
                        <i class="fas fa-id-card me-1"></i>LRN
                        <span class="verify-block-verdict <?= $ls['cls'] ?>"><i class="fas <?= $ls['icon'] ?>"></i> <?= htmlspecialchars($ls['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="name-verify-grid">
                        <div class="name-verify-part <?= $nv_lrn['status'] === 'match' ? 'ok' : ($nv_lrn['status'] === 'skipped' ? 'skip' : ($nv_lrn['status'] === 'close' ? 'ok' : 'miss')) ?>"><i class="fas fa-arrow-right"></i>Expected: <strong style="margin-left:.2rem"><?= htmlspecialchars((string)($nv_lrn['expected'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                        <?php if (!empty($nv_lrn['best'])): ?>
                            <div class="name-verify-part <?= $nv_lrn['status'] === 'match' ? 'ok' : 'miss' ?>"><i class="fas fa-magnifying-glass"></i>Found: <strong style="margin-left:.2rem"><?= htmlspecialchars((string)$nv_lrn['best'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($nv_lrn['distance'] !== null && $nv_lrn['distance'] > 0): ?><span class="lrn-distance">(<?= (int)$nv_lrn['distance'] ?> digit<?= $nv_lrn['distance'] == 1 ? '' : 's' ?> off)</span><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="verify-block-msg"><?= htmlspecialchars((string)$nv_lrn['message'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>

                <div class="verify-block" style="background:rgba(255,255,255,.4)">
                    <div class="verify-block-title"><i class="fas fa-gears me-1"></i>OCR Diagnostics</div>
                    <div class="name-verify-grid">
                        <div class="name-verify-part skip"><i class="fas fa-rotate"></i>Rotation used: <strong style="margin-left:.2rem"><?= $nv_rotation ?>°</strong></div>
                        <div class="name-verify-part skip"><i class="fas fa-gauge-high"></i>Keyword score: <strong style="margin-left:.2rem"><?= $nv_score ?></strong></div>
                    </div>
                </div>

                <?php if ($extracted !== null && $extracted !== ''): ?>
                    <details class="name-verify-details">
                        <summary><i class="fas fa-file-lines me-1"></i>Show text extracted from the document</summary>
                        <pre class="name-verify-pre"><?= htmlspecialchars((string)$extracted, ENT_QUOTES, 'UTF-8') ?></pre>
                    </details>
                <?php endif; ?>

                <?php if ($status === 'mismatch' || $status === 'partial'): ?>
                    <div class="name-verify-warn"><i class="fas fa-triangle-exclamation"></i><span>Please double-check the uploaded file. If this is genuinely the correct document, you can still submit — but a registrar will manually review it.</span></div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php
            $p2 = $_SESSION['photo_2x2_check'] ?? null;
            if (is_array($p2) && ($p2['status'] ?? '') !== 'match'):
            ?>
            <section class="review-section name-verify-card nv-partial" role="status" aria-live="polite">
                <div class="name-verify-head">
                    <div class="name-verify-icon"><i class="fas fa-camera-retro"></i></div>
                    <div class="name-verify-titles">
                        <h2 style="font-size:1.15rem;margin:0;"><i class="fas fa-image me-2"></i>2×2 Picture — Format Check</h2>
                        <div class="name-verify-sub"><?= $p2['status'] === 'partial' ? 'Photo will be accepted but may need adjustment.' : 'Photo could not be validated automatically.' ?></div>
                    </div>
                    <span class="name-verify-badge"><?= htmlspecialchars(strtoupper((string)$p2['status']), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="verify-block"><div class="verify-block-msg"><?= htmlspecialchars((string)($p2['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div></div>
            </section>
            <?php endif; ?>

            <?php if (!$docs_valid): ?>
                <div class="review-blocker-banner" role="alert" aria-live="assertive">
                    <div class="review-blocker-icon"><i class="fas fa-lock" aria-hidden="true"></i></div>
                    <div class="review-blocker-body">
                        <div class="review-blocker-title">Cannot submit — documents need attention</div>
                        <ul class="review-blocker-list"><?php foreach ($doc_blockers as $b): ?><li><?= htmlspecialchars($b, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
                        <div class="review-blocker-hint"><i class="fas fa-arrow-down" aria-hidden="true"></i>Click <strong>Edit</strong> below to go back to the form and re-upload the highlighted documents.</div>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="process_enroll.php" class="text-center mt-5" id="confirmForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <?php
                $skip_keys = ['existing_file', 'keep_existing_file', 'camera_data', 'entrance_files', 'csrf_token'];
                foreach ($_POST as $k => $v):
                    if (in_array($k, $skip_keys, true)) continue;
                    if (is_array($v)):
                        foreach ($v as $val):
                            if (is_array($val)) continue;
                ?>
                        <input type="hidden" name="<?= htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') ?>[]" value="<?= htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') ?>">
                <?php
                        endforeach;
                    else:
                ?>
                        <input type="hidden" name="<?= htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') ?>">
                <?php
                    endif;
                endforeach;
                ?>

                <button type="submit"
                        class="btn-custom"
                        id="confirmSaveBtn"
                        <?= $docs_valid ? '' : 'disabled aria-disabled="true"' ?>
                        <?= $docs_valid ? '' : 'title="Documents must be verified before submitting."' ?>
                        style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;padding:.9rem 2rem;border-radius:50px;font-weight:600;<?= $docs_valid ? '' : 'opacity:0.5;cursor:not-allowed;filter:grayscale(60%);' ?>">
                    <i class="fas fa-save me-2"></i> <?= htmlspecialchars($t[$lang]['confirm_save'], ENT_QUOTES, 'UTF-8') ?>
                </button>
            </form>

            <?php if (!$docs_valid): ?>
                <p class="text-center mt-3" style="color:#991b1b;font-weight:600;font-size:0.9rem;"><i class="fas fa-circle-exclamation me-1"></i>Submission is disabled until the documents are corrected.</p>
            <?php endif; ?>

            <div class="text-center mt-3">
                <a href="enroll_form.php?edit=1" id="editEnrollmentBtn" class="btn-custom" style="background:linear-gradient(135deg,#6c757d,#5a6268);color:#fff;text-decoration:none;padding:.8rem 1.75rem;border-radius:50px;font-weight:600;display:inline-block;cursor:pointer">
                    <i class="fas fa-edit me-2"></i> <?= htmlspecialchars($t[$lang]['edit_btn'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        </div>

        <script>
        (function(){
            const form = document.getElementById('confirmForm');
            const btn  = document.getElementById('confirmSaveBtn');
            if (!form || !btn || !btn.disabled) return;
            form.addEventListener('submit', function(e){
                e.preventDefault();
                e.stopPropagation();
                if (typeof Swal !== 'undefined' && Swal.fire) {
                    Swal.fire({ icon: 'error', title: 'Documents not verified', html: 'Please go back and re-upload the highlighted documents before submitting.', confirmButtonColor: '#dc3545' }).then(() => setTimeout(ensurePageUnfrozen, 250));
                } else { alert('Documents must be verified before submitting.'); }
                return false;
            }, true);
        })();
        </script>
    <?php endif; ?>
</main>

<!-- CAMERA MODAL -->
<div id="cameraModal" class="camera-modal" style="display:none" role="dialog" aria-modal="true" aria-label="<?= htmlspecialchars($t[$lang]['camera_modal_title'], ENT_QUOTES, 'UTF-8') ?>">
    <div class="camera-container">
        <video id="cameraVideo" autoplay playsinline></video>
        <div class="camera-controls">
            <button type="button" class="btn btn-light" id="cameraCaptureBtn" onclick="capturePhoto()"><i class="fas fa-camera me-2"></i><?= htmlspecialchars($t[$lang]['camera_capture'], ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="btn btn-outline-light" onclick="closeCamera()"><i class="fas fa-times me-2"></i><?= htmlspecialchars($t[$lang]['camera_cancel'], ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>
    <canvas id="cameraCanvas" style="display:none"></canvas>
</div>

<script>
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'], $JSON_FLAGS) ?>;

window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
        console.log('[Wizard] bfcache restore detected — forcing fresh reload');
        window.location.reload();
    }
});

function ensurePageUnfrozen() {
    const stillShowing = document.querySelector('.swal2-container');
    if (stillShowing) return;
    document.body.classList.remove('swal2-showing', 'swal2-shown', 'swal2-height-auto', 'swal2-no-backdrop');
    document.documentElement.classList.remove('swal2-shown');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    document.body.style.removeProperty('padding-left');
}
document.addEventListener('DOMContentLoaded', function () {
    let n = 0;
    const iv = setInterval(function () { ensurePageUnfrozen(); if (++n >= 20) clearInterval(iv); }, 500);
});
window.addEventListener('pageshow', function (e) {
    ensurePageUnfrozen();
    if (e.persisted) {
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn && submitBtn.disabled) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + <?= json_encode($t[$lang]['submit'], $JSON_FLAGS) ?>;
        }
    }
});
document.addEventListener('visibilitychange', function () { if (!document.hidden) ensurePageUnfrozen(); });

const STRAND_PROGRAMS = <?= json_encode($STRAND_PROGRAMS, JSON_UNESCAPED_UNICODE) ?>;
const strandKeywords = {
    "Automotive and Small Engine Technologies": ["automotive","car","cars","auto ","engine","mechanic","motorcycle","motor ","driving","truck","vehicle","diesel"],
    "Construction and Building Technologies": ["carpentry","carpenter","weld","welder","welding","construction","building","masonry","mason","plumbing","plumber","woodwork"],
    "ICT Support and Computer Programming Technologies": ["computer","programming","programmer","coding","coder","java",".net","software","network","networking","web design","web development","html","css","python","php","systems","database","server"],
    "Industrial Technologies": ["electronics","electronic","electrical","circuit","soldering","solder","assembly","industrial","machine operator"],
    "Agri-Fishery Business and Food Innovation": ["agriculture","agricultural","farming","farmer","fishery","fishing","fisherman","crops","gardening","gardener","livestock","poultry","aquaculture","planting"],
    "Hospitality and Tourism": ["cooking","cook","baking","baker","culinary","chef","hotel","hospitality","tourism","tourist","restaurant","waiter","waitress","server","barista","food service","food and beverage","housekeeping"]
};
const progs = STRAND_PROGRAMS;

let liveStrandTrends = { trends: {}, sy: null, total: 0 };

function switchLang(lang){const url=new URL(window.location.href);url.searchParams.set('lang',lang);window.location.href=url.toString()}
function capitalize(str){if(!str||typeof str!=='string')return str;return str.replace(/\b\w+/g,function(w){return w.charAt(0).toUpperCase()+w.slice(1).toLowerCase()})}
const nameFields=['last_name','first_name','middle_name','nick_name','ext_name','father_name','mother_maiden_name','guardian_fullname'];

function initFloatingLabels(container=document){
    container.querySelectorAll('.form-group').forEach(group=>{
        const input=group.querySelector('input,select,textarea');
        if(!input)return;
        const uf=()=>{if(input.value&&input.value.trim()!=='')group.classList.add('filled');else group.classList.remove('filled')};
        input.addEventListener('focus',()=>group.classList.add('filled'));
        input.addEventListener('blur',uf);
        input.addEventListener('input',uf);
        input.addEventListener('change',uf);
        uf();
    });
}

const bd=document.getElementById('birth_date'),ai=document.getElementById('age');
function calcAge(){
    if(!bd||!bd.value)return;
    const b=new Date(bd.value),t=new Date();
    let a=t.getFullYear()-b.getFullYear();
    if(t.getMonth()<b.getMonth()||(t.getMonth()===b.getMonth()&&t.getDate()<b.getDate()))a--;
    if(ai)ai.value=a;
}
if(bd){bd.addEventListener('change',calcAge);window.addEventListener('load',calcAge)}

document.addEventListener('DOMContentLoaded',()=>{
    nameFields.forEach(f=>{
        const i=document.querySelector(`input[name="${f}"]`);
        if(i){
            i.addEventListener('input',()=>{if(i.value.length>0)i.value=capitalize(i.value)});
            i.addEventListener('blur',()=>{if(i.value.trim()!=='')i.value=capitalize(i.value.trim())});
        }
    });
    initFloatingLabels();
});

function syncReligion(){
    const sel = document.getElementById('religion_select');
    const inp = document.getElementById('religion_other');
    if (!sel || !inp) return;
    if (sel.value === '__other__') { inp.style.display = 'block'; setTimeout(() => inp.focus(), 50); }
    else { inp.style.display = 'none'; inp.value = ''; }
}
document.addEventListener('DOMContentLoaded', syncReligion);

(function(){
    const lrnEl = document.getElementById('lrn');
    const hint  = document.getElementById('lrnHint');
    const lenHint = document.getElementById('lrnLengthHint');
    if (!lrnEl || !hint) return;

    let lrnTimer = null;
    let lastChecked = '';

    function setHint(state, html){
        hint.className = 'check-hint' + (state ? ' ' + state : '');
        hint.innerHTML = html || '';
    }
    function setLenHint(n){
        if (!lenHint) return;
        if (n === 0) { lenHint.textContent = ''; lenHint.className = 'check-hint'; return; }
        if (n < 12)  { lenHint.textContent = n + ' / 12 digits (need ' + (12 - n) + ' more)'; lenHint.className = 'check-hint warn'; return; }
        lenHint.textContent = '12 / 12 digits — OK';
        lenHint.className = 'check-hint ok';
    }
    function normalize(){
        const before = lrnEl.value;
        const digits = before.replace(/\D/g, '').slice(0, 12);
        if (digits !== before) lrnEl.value = digits;
        return digits;
    }

    lrnEl.addEventListener('paste', function(e){
        e.preventDefault();
        const txt = (e.clipboardData || window.clipboardData).getData('text') || '';
        lrnEl.value = txt.replace(/\D/g, '').slice(0, 12);
        lrnEl.dispatchEvent(new Event('input', { bubbles: true }));
    });

    lrnEl.addEventListener('input', function(){
        const v = normalize();
        setLenHint(v.length);
        clearTimeout(lrnTimer);
        if (!v) { setHint('', ''); lastChecked = ''; return; }
        if (v.length < 12) { setHint('warn', '<i class="fas fa-hourglass-half"></i> Waiting for a 12-digit LRN…'); lastChecked = ''; return; }
        if (v === lastChecked) return;

        lrnTimer = setTimeout(async () => {
            lastChecked = v;
            setHint('pending', '<i class="fas fa-spinner fa-spin"></i> <?= htmlspecialchars($t[$lang]['checking'], ENT_QUOTES, "UTF-8") ?>');
            try {
                const fd = new FormData();
                fd.append('lrn', v);
                fd.append('csrf_token', CSRF_TOKEN);
                const r = await fetch('?action=check_lrn', { method: 'POST', body: fd, credentials: 'same-origin' });
                if (!r.ok) { if (r.status === 429) setHint('warn', '<i class="fas fa-clock"></i> Too many checks. Please wait.'); else setHint('', ''); return; }
                const j = await r.json();
                if (lrnEl.value !== v) return;
                if (!j.valid) { setHint('error', '<i class="fas fa-exclamation-circle"></i> ' + (j.message || 'Invalid LRN')); return; }
                if (j.exists) {
                    const nm = j.existing?.name || '';
                    setHint('error', '<i class="fas fa-times-circle"></i> <?= htmlspecialchars($t[$lang]['lrn_exists'], ENT_QUOTES, "UTF-8") ?>' + (nm ? ' <span class="existing-name">(' + nm + ')</span>' : ''));
                } else setHint('ok', '<i class="fas fa-check-circle"></i> <?= htmlspecialchars($t[$lang]['lrn_available'], ENT_QUOTES, "UTF-8") ?>');
            } catch (e) { setHint('', ''); }
        }, 400);
    });

    setLenHint(normalize().length);
})();

(function(){
    const fnEl = document.getElementById('first_name');
    const mnEl = document.getElementById('middle_name');
    const lnEl = document.getElementById('last_name');
    const container = document.getElementById('nameDupContainer');
    const alertEl   = document.getElementById('nameDupAlert');
    if (!fnEl || !lnEl || !container || !alertEl) return;

    let nameTimer = null;
    let lastSig = '';
    let currentMatches = [];

    function clearAlert(){ container.style.display = 'none'; alertEl.innerHTML = ''; currentMatches = []; }

    function renderMatches(matches){
        currentMatches = matches;
        if (!matches || matches.length === 0){ clearAlert(); return; }
        const items = matches.map(m => {
            let line = '<strong>' + m.name + '</strong>';
            if (m.lrn_masked) line += ' · LRN: ' + m.lrn_masked;
            if (m.grade_level) line += ' · ' + m.grade_level;
            if (m.school_year) line += ' (' + m.school_year + ')';
            return '<li>' + line + '</li>';
        }).join('');
        alertEl.innerHTML = '<i class="fas fa-exclamation-triangle"></i><div><div><strong>Possible duplicate name detected.</strong> These existing students have the same name:</div><ul>' + items + '</ul><div style="margin-top:.4rem;font-size:.8rem;opacity:.85">If this is a different person, you can continue — you\'ll be asked to confirm on submit.</div></div>';
        container.style.display = 'block';
    }

    function checkNames(){
        const fn = fnEl.value.trim();
        const mn = mnEl.value.trim();
        const ln = lnEl.value.trim();
        if (fn.length < 2 || ln.length < 2){ clearAlert(); lastSig = ''; return; }
        const sig = (fn + '|' + mn + '|' + ln).toLowerCase();
        if (sig === lastSig) return;
        clearTimeout(nameTimer);
        nameTimer = setTimeout(async () => {
            lastSig = sig;
            try {
                const fd = new FormData();
                fd.append('first_name', fn);
                fd.append('middle_name', mn);
                fd.append('last_name', ln);
                fd.append('csrf_token', CSRF_TOKEN);
                const r = await fetch('?action=check_name', { method: 'POST', body: fd, credentials: 'same-origin' });
                if (!r.ok) return;
                const j = await r.json();
                const curSig = (fnEl.value.trim() + '|' + mnEl.value.trim() + '|' + lnEl.value.trim()).toLowerCase();
                if (curSig !== sig) return;
                if (j.checked && Array.isArray(j.matches) && j.matches.length) renderMatches(j.matches);
                else clearAlert();
            } catch (e) {}
        }, 500);
    }

    [fnEl, mnEl, lnEl].forEach(el => { if (el) el.addEventListener('input', checkNames); });
    window.__getNameMatches = function(){ return currentMatches; };
})();

(function(){
    const ss = document.getElementById('strand');
    const ps = document.getElementById('program');
    if (!ss || !ps) return;

    ss.innerHTML = '<option value="" disabled selected></option>';
    Object.keys(progs).forEach(k => {
        const o = document.createElement("option");
        o.value = o.textContent = k;
        ss.appendChild(o);
    });
    ss.addEventListener("change", () => {
        ps.innerHTML = '<option value="" disabled selected></option>';
        if (progs[ss.value]) progs[ss.value].forEach(p => {
            const o = document.createElement("option");
            o.value = o.textContent = p;
            ps.appendChild(o);
        });
        initFloatingLabels(ps.parentElement);
        if (typeof renderStrandRecommendation === 'function') renderStrandRecommendation();
    });

    const cs = <?= json_encode($_POST['strand'] ?? '', $JSON_FLAGS) ?>;
    const cp = <?= json_encode($_POST['program'] ?? '', $JSON_FLAGS) ?>;
    if (cs) {
        ss.value = cs;
        ss.dispatchEvent(new Event('change'));
        if (cp) {
            const chk = () => {
                if (ps.options.length > 1) {
                    ps.value = cp;
                    if (ps.value) ps.parentElement.classList.add('filled');
                    if (typeof renderStrandRecommendation === 'function') renderStrandRecommendation();
                } else setTimeout(chk, 10);
            };
            chk();
        }
    }
})();

(function(){
    const ps = document.getElementById('previous_strand');
    const pp = document.getElementById('previous_program');
    if (!ps || !pp) return;
    ps.addEventListener('change', () => {
        pp.innerHTML = '<option value="" selected>— Select —</option>';
        if (progs[ps.value]) progs[ps.value].forEach(p => {
            const o = document.createElement('option');
            o.value = o.textContent = p;
            pp.appendChild(o);
        });
        initFloatingLabels(pp.parentElement);
    });
})();

async function loadStrandTrends(){
    try {
        const r = await fetch('?action=strand_trends', { cache: 'no-store', credentials: 'same-origin' });
        if (!r.ok) return;
        const j = await r.json();
        liveStrandTrends = { trends: j.trends || {}, sy: j.sy || null, total: j.total || 0 };
    } catch (e) {}
}

function escapeRec(s){ return String(s || '').replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])); }

function analyzeStrand(curStrand, curProgram, skills, isTransferred, prevStrand){
    const sk  = (skills || '').toLowerCase().trim();
    const cur = (curStrand || '').trim();
    const prog = (curProgram || '').trim();
    const trends   = (liveStrandTrends && liveStrandTrends.trends) ? liveStrandTrends.trends : {};
    const trendsSy = (liveStrandTrends && liveStrandTrends.sy)     ? liveStrandTrends.sy     : null;

    if (cur && prog && progs[cur] && progs[cur].indexOf(prog) === -1) {
        return { status:'mismatch', icon:'bi bi-exclamation-triangle-fill', title:'Program does not match strand',
                 message:`"${prog}" is not a standard program under ${cur}. Please review your selection.`, suggestion:null };
    }
    if (isTransferred && prevStrand) {
        if (prevStrand.toLowerCase() === cur.toLowerCase()) {
            return { status:'aligned', icon:'bi bi-check2-circle', title:'Aligned with your previous strand',
                     message:`You're continuing from ${prevStrand}. This is a strong continuity choice.`, suggestion:null };
        }
        return { status:'changed', icon:'bi bi-arrow-left-right', title:'Different from your previous strand',
                 message:`You were in ${prevStrand} at your previous school. Confirm this change is intentional before submitting.`, suggestion:null };
    }
    const t = trends[cur];
    if (t && t.current <= 1 && trendsSy) {
        return { status:'changed', icon:'bi bi-exclamation-triangle-fill', title:'Low enrollment for this strand',
                 message:`Only ${t.current} student(s) enrolled in ${cur} for ${trendsSy}. Verify this strand will run.`, suggestion:null };
    }
    if (t && typeof t.growth === 'number' && t.growth < -30) {
        return { status:'changed', icon:'bi bi-graph-down-arrow', title:'Strand trending downward',
                 message:`${cur} enrollment dropped ${Math.abs(t.growth)}% vs last year (${t.previous} → ${t.current}). Verify capacity.`, suggestion:null };
    }
    if (sk) {
        let bestMatch = null, bestScore = 0, currentScore = 0;
        for (const strand in strandKeywords) {
            let score = 0;
            for (const kw of strandKeywords[strand]) if (kw !== '' && sk.indexOf(kw) !== -1) score++;
            if (strand === cur) currentScore = score;
            if (score > bestScore) { bestScore = score; bestMatch = strand; }
        }
        if (currentScore >= 2 && bestMatch === cur) {
            let extra = '';
            if (t && typeof t.growth === 'number' && t.growth > 20) extra = ` Enrollment in this strand is also up ${t.growth}% year-over-year.`;
            return { status:'strong', icon:'bi bi-star-fill', title:'Strong fit for your chosen strand',
                     message:`Your listed skills align well with ${cur}.${extra}`, suggestion:null };
        }
        if (bestScore >= 2 && bestMatch && bestMatch !== cur) {
            const altT = trends[bestMatch];
            let note = '';
            if (altT && typeof altT.growth === 'number' && altT.growth > 20) note = ` (up ${altT.growth}% YoY)`;
            return { status:'alternative', icon:'bi bi-lightbulb-fill', title:'Consider: ' + bestMatch,
                     message:`Based on your skills, ${bestMatch} may be a better fit than ${cur || 'your current choice'}${note}. You can switch above or continue with your current selection.`, suggestion:bestMatch };
        }
    }
    return { status:'insufficient', icon:'bi bi-info-circle', title:'Add your skills in Step 1 for a recommendation',
             message:'We can suggest a strand based on your special skills. Go back to Step 1 and list a few, then return here.', suggestion:null };
}

function renderStrandRecommendation(){
    const container = document.getElementById('strandRecommendation');
    if (!container) return;
    const strandEl   = document.getElementById('strand');
    const programEl  = document.getElementById('program');
    const skillsEl   = document.getElementById('special_skills');
    const transferEl = document.getElementById('is_transferred');
    const prevEl     = document.getElementById('previous_strand');
    const cur  = strandEl ? strandEl.value : '';
    const prog = programEl ? programEl.value : '';
    const skills = skillsEl ? skillsEl.value : '';
    const isTransferred = transferEl ? transferEl.checked : false;
    const prevStrand = prevEl ? prevEl.value : '';
    if (!cur) { container.style.display = 'none'; return; }
    container.style.display = 'block';
    const rec = analyzeStrand(cur, prog, skills, isTransferred, prevStrand);

    let suggestionBlock = '';
    if (rec.suggestion) {
        suggestionBlock = `<div class="rec-suggestion"><i class="fas fa-arrow-right"></i> Suggested: <strong>${escapeRec(rec.suggestion)}</strong></div>`;
    }

    let trendsBlock = '';
    const t = liveStrandTrends && liveStrandTrends.trends ? liveStrandTrends.trends[cur] : null;
    if (t && liveStrandTrends.sy) {
        let growthChip = '<span class="rec-growth flat"><i class="fas fa-minus"></i>No prior data</span>';
        if (t.growth === 'new') { growthChip = '<span class="rec-growth new"><i class="fas fa-star"></i>New strand</span>'; }
        else if (typeof t.growth === 'number') {
            const up = t.growth >= 0;
            growthChip = `<span class="rec-growth ${up ? 'up' : 'down'}"><i class="fas fa-arrow-${up ? 'up' : 'down'}"></i>${up ? '+' : ''}${t.growth}% YoY</span>`;
        }
        const sorted = Object.entries(liveStrandTrends.trends).sort((a, b) => b[1].current - a[1].current).slice(0, 3);
        let maxCurr = Math.max(t.current, ...sorted.map(([_, d]) => d.current), 1);
        let bars = '';
        sorted.forEach(([name, data]) => {
            const w = Math.max(8, Math.round((data.current / maxCurr) * 100));
            const isChosen = name === cur;
            bars += `<div class="rec-bar-row ${isChosen ? 'is-chosen' : ''}"><div class="rec-bar-label"><span>${escapeRec(name)}</span><span class="rec-bar-count">${data.current}</span></div><div class="rec-bar-track"><div class="rec-bar-fill" style="width:${w}%"></div></div></div>`;
        });
        trendsBlock = `<div class="rec-trends"><div class="rec-trends-head"><span class="rec-trends-title"><i class="fas fa-chart-bar me-1"></i>Live enrollment (${escapeRec(liveStrandTrends.sy)})</span>${growthChip}</div><div class="rec-bars">${bars}</div></div>`;
    }

    container.innerHTML = `<div class="rec-card rec-${rec.status}"><div class="rec-icon"><i class="fas ${rec.icon.replace('bi bi-','')}"></i></div><div class="rec-body"><div class="rec-title">${escapeRec(rec.title)}</div><div class="rec-message">${escapeRec(rec.message)}</div>${suggestionBlock}${trendsBlock}</div></div>`;
}

(function bindRecommendation(){
    const strandEl   = document.getElementById('strand');
    const skillsEl   = document.getElementById('special_skills');
    const programEl  = document.getElementById('program');
    const transferEl = document.getElementById('is_transferred');
    const prevEl     = document.getElementById('previous_strand');
    if (strandEl)   strandEl.addEventListener('change', renderStrandRecommendation);
    if (skillsEl)   skillsEl.addEventListener('input', renderStrandRecommendation);
    if (programEl)  programEl.addEventListener('change', renderStrandRecommendation);
    if (transferEl) transferEl.addEventListener('change', renderStrandRecommendation);
    if (prevEl)     prevEl.addEventListener('change', renderStrandRecommendation);
    document.addEventListener('DOMContentLoaded', async () => { await loadStrandTrends(); renderStrandRecommendation(); });
    setTimeout(renderStrandRecommendation, 300);
})();

function addEdu(){
    const c=document.getElementById('edu-container'),idx=c.querySelectorAll('.edu-entry').length,t=document.createElement('div');
    t.className='edu-entry row g-3 align-items-end';
    t.setAttribute('role','group');
    t.innerHTML=`<div class="col-md-3 form-group filled"><select name="edu_level[]" class="edu-level" id="edu_level_${idx}" style="background:white"><option value="Elementary" selected>Elementary</option><option value="JHS">Junior High School</option><option value="Transferred">Transferred</option></select><label for="edu_level_${idx}">Level</label></div><div class="col-md-3 form-group"><input type="text" name="school_name[]" id="school_name_${idx}" placeholder=" " maxlength="200"><label for="school_name_${idx}">School Name</label></div><div class="col-md-3 form-group"><input type="text" name="school_address[]" id="school_address_${idx}" placeholder=" " maxlength="300"><label for="school_address_${idx}">School Address</label></div><div class="col-md-3 form-group"><input type="text" name="year_completed[]" id="year_completed_${idx}" placeholder=" " inputmode="numeric" maxlength="20"><label for="year_completed_${idx}">Year Completed</label></div>`;
    c.appendChild(t);
    initFloatingLabels(t);
}
document.getElementById('addEduBtn')?.addEventListener('click',addEdu);

const tc=document.getElementById('is_transferred'),ts=document.getElementById('transferee-fields');
if(tc&&ts){
    const tg=()=>{ts.style.display=tc.checked?'block':'none';if(tc.checked)initFloatingLabels(ts)};
    tc.addEventListener('change',tg);
    window.addEventListener('load',tg);
}

document.querySelectorAll('input[name="entrance_data[]"]').forEach(cb=>{
    cb.addEventListener('change',function(){
        const rowId='uploadRow_'+this.id,row=document.getElementById(rowId);
        if(!row)return;
        if(this.checked){ row.classList.add('visible'); }
        else {
            row.classList.remove('visible');
            const fi=row.querySelector('input[type="file"]');
            if(fi){fi.value='';fi.disabled=false;}
            const pv=row.querySelector('.preview-thumb');
            if(pv)pv.style.display='none';
            const cd=row.querySelector('input[name^="camera_data"]');
            if(cd)cd.value='';
        }
    });
});

let currentRow=null,stream=null,lastFocusedElement=null;
async function openCamera(rowId){
    currentRow=rowId;
    lastFocusedElement=document.activeElement;
    const modal=document.getElementById('cameraModal'),video=document.getElementById('cameraVideo');
    modal.style.display='flex';
    setTimeout(()=>document.getElementById('cameraCaptureBtn')?.focus(),50);
    try{
        stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'},audio:false});
        video.srcObject=stream;
    }catch(e){ alert('Cannot access camera. Please check permissions.'); closeCamera(); }
}
function closeCamera(){
    const modal=document.getElementById('cameraModal');
    modal.style.display='none';
    if(stream){stream.getTracks().forEach(t=>t.stop());stream=null;}
    if(lastFocusedElement&&typeof lastFocusedElement.focus==='function')lastFocusedElement.focus();
}

function capturePhoto(){
    const video=document.getElementById('cameraVideo'),canvas=document.getElementById('cameraCanvas');
    const MAX_DIM = 1600;
    const srcW = video.videoWidth || 640;
    const srcH = video.videoHeight || 480;
    const scale = Math.min(1, MAX_DIM / Math.max(srcW, srcH));
    canvas.width  = Math.round(srcW * scale);
    canvas.height = Math.round(srcH * scale);
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    let quality = 0.85;
    let dataUrl = canvas.toDataURL('image/jpeg', quality);
    const HARD_CAP = 4 * 1024 * 1024;
    while (dataUrl.length > HARD_CAP && quality > 0.4) { quality -= 0.15; dataUrl = canvas.toDataURL('image/jpeg', quality); }

    const dataInput=document.getElementById('cameraData_'+currentRow);
    if(dataInput){
        dataInput.value=dataUrl;
        const preview=document.getElementById('preview_'+currentRow);
        if(preview){ const img=preview.querySelector('img'); if(img){img.src=dataUrl;preview.style.display='block';} }
        const row=document.querySelector('#uploadRow_'+currentRow);
        if(row){ const fi=row.querySelector('input[type="file"]'); if(fi)fi.value=''; }
    }
    closeCamera();
}

document.addEventListener('keydown', e => {
    const modal = document.getElementById('cameraModal');
    const isOpen = modal && modal.style.display !== 'none' && modal.style.display !== '';
    if (!isOpen) return;
    if (e.key === 'Escape') { e.preventDefault(); closeCamera(); return; }
    if (e.key !== 'Tab') return;
    const focusables = modal.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
    if (focusables.length === 0) return;
    const first = focusables[0];
    const last  = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
});
document.addEventListener('DOMContentLoaded',()=>{
    document.querySelectorAll('input[name="entrance_data[]"]:checked').forEach(cb=>{
        const row=document.getElementById('uploadRow_'+cb.id);
        if(row)row.classList.add('visible');
    });
});

(function(){
    'use strict';
    const TOTAL_STEPS = 5;
    const form = document.getElementById('enrollForm');
    if (!form) return;
    const stepContents = form.querySelectorAll('.wizard-step-content');
    const indicators   = document.querySelectorAll('.wizard-step-indicator');

    let currentStep = <?= (int)$initial_step ?>;
    const visited   = new Set([1]);

    const STEP_NAMES = { 1: 'Student Info', 2: 'Parents & Guardian', 3: 'Address', 4: 'Education', 5: 'Enrollment & Documents' };

    function digitsOnly(s){ return String(s || '').replace(/\D/g, ''); }

    function documentHasAnyFile() {
        const checks = form.querySelectorAll('input[name="entrance_data[]"]:checked');
        if (checks.length === 0) return false;
        let hasAny = false;
        checks.forEach(cb => {
            const row = document.getElementById('uploadRow_' + cb.id);
            if (!row) return;
            if (row.querySelector('.upload-row.has-file')) { hasAny = true; return; }
            const fi = row.querySelector('input[type="file"]');
            if (fi && fi.files && fi.files.length > 0) { hasAny = true; return; }
            const cd = row.querySelector('input[name^="camera_data"]');
            if (cd && cd.value && cd.value.trim() !== '') { hasAny = true; }
        });
        return hasAny;
    }

    function markError(el, msg, missing) {
        const group = el.closest('.form-group');
        if (group) group.classList.add('error');
        el.setAttribute('aria-invalid', 'true');
        if (msg) missing.push(msg);
    }
    function clearError(el) {
        const group = el.closest('.form-group');
        if (group) group.classList.remove('error');
        el.removeAttribute('aria-invalid');
    }

    function getStepError(step) {
        const missing = [];
        const stepNode = form.querySelector('[data-step-content="' + step + '"]');
        if (stepNode) { stepNode.querySelectorAll('.form-group.error').forEach(g => g.classList.remove('error')); }

        if (step === 1) {
            const lrn = form.querySelector('#lrn');
            if (lrn) {
                clearError(lrn);
                const raw    = String(lrn.value || '');
                const digits = digitsOnly(raw);
                if (digits.length === 0) markError(lrn, 'LRN is required', missing);
                else if (digits.length !== 12) markError(lrn, 'LRN must be exactly 12 digits (yours has ' + digits.length + ')', missing);
                else if (raw !== digits) lrn.value = digits;
            }

            const ln = form.querySelector('#last_name');
            if (ln) { clearError(ln); const v = String(ln.value || '').trim(); if (v === '') markError(ln, 'Last Name is required', missing); else if (!/^[A-Za-zÀ-ÿ\s\-'.]{2,}$/u.test(v)) markError(ln, 'Last Name contains invalid characters', missing); }

            const fn = form.querySelector('#first_name');
            if (fn) { clearError(fn); const v = String(fn.value || '').trim(); if (v === '') markError(fn, 'First Name is required', missing); else if (!/^[A-Za-zÀ-ÿ\s\-'.]{2,}$/u.test(v)) markError(fn, 'First Name contains invalid characters', missing); }

            const sx = form.querySelector('#sex');
            if (sx) { clearError(sx); if (String(sx.value || '') === '') markError(sx, 'Sex is required', missing); }

            const bd = form.querySelector('#birth_date');
            if (bd) { clearError(bd); if (String(bd.value || '') === '') markError(bd, 'Birth Date is required', missing); }

            const h = form.querySelector('#height');
            if (h && String(h.value || '') !== '') { clearError(h); const hv = parseFloat(h.value); if (isNaN(hv) || hv < <?= HEIGHT_MIN ?> || hv > <?= HEIGHT_MAX ?>) markError(h, 'Height must be between <?= HEIGHT_MIN ?> and <?= HEIGHT_MAX ?> cm', missing); }

            const w = form.querySelector('#weight');
            if (w && String(w.value || '') !== '') { clearError(w); const wv = parseFloat(w.value); if (isNaN(wv) || wv < <?= WEIGHT_MIN ?> || wv > <?= WEIGHT_MAX ?>) markError(w, 'Weight must be between <?= WEIGHT_MIN ?> and <?= WEIGHT_MAX ?> kg', missing); }
        }

        if (step === 2) {
            const i4 = form.querySelector('#is_4ps');
            if (i4) { clearError(i4); if (String(i4.value || '') === '') markError(i4, 'Please answer the CCT/4Ps question (Yes or No)', missing); }
        }

        if (step === 5) {
            const sy = form.querySelector('#school_year');
            if (sy) { clearError(sy); if (String(sy.value || '') === '') markError(sy, 'School Year is required', missing); }
            const gl = form.querySelector('#grade_level');
            if (gl) { clearError(gl); if (String(gl.value || '') === '') markError(gl, 'Grade Level is required', missing); }
            const tm = form.querySelector('#term');
            if (tm) { clearError(tm); if (String(tm.value || '') === '') markError(tm, 'Term is required', missing); }
            const st = form.querySelector('#strand');
            if (st) { clearError(st); if (String(st.value || '') === '') markError(st, 'Strand is required', missing); }
            const pr = form.querySelector('#program');
            if (pr) { clearError(pr); if (String(pr.value || '') === '') markError(pr, 'Program is required', missing); }

            const checks = form.querySelectorAll('input[name="entrance_data[]"]:checked');
            if (checks.length === 0) missing.push('Tick at least one document in "Entrance Data Presented"');
            else if (!documentHasAnyFile()) missing.push('Attach a file for at least one ticked document before submitting');
        }

        if (missing.length > 0) {
            return { title: 'Please check ' + (STEP_NAMES[step] || ('Step ' + step)),
                     html:  'The following need attention before you can continue:<br><br><strong>' + missing.map(m => '• ' + m).join('<br>') + '</strong>' };
        }
        return null;
    }

    function stepIsComplete(step) {
        const stepNode = form.querySelector('[data-step-content="' + step + '"]');
        if (!stepNode) return visited.has(step);
        const saved = [];
        stepNode.querySelectorAll('.form-group.error').forEach(g => { saved.push(g); g.classList.remove('error'); });
        const err = getStepError(step);
        saved.forEach(g => g.classList.add('error'));
        return err === null;
    }

    function showStep(n) {
        if (n < 1 || n > TOTAL_STEPS) return;
        currentStep = n;
        visited.add(n);
        stepContents.forEach(el => { const s = parseInt(el.dataset.stepContent, 10); el.classList.toggle('active', s === n); });
        indicators.forEach(el => {
            const s = parseInt(el.dataset.step, 10);
            el.classList.remove('active','completed','invalid');
            el.removeAttribute('aria-current');
            if (s === n) { el.classList.add('active'); el.setAttribute('aria-current','step'); }
            else if (stepIsComplete(s)) el.classList.add('completed');
            else if (visited.has(s)) el.classList.add('invalid');
            el.disabled = !(s <= n || visited.has(s));
        });
        if (n === 5 && typeof renderStrandRecommendation === 'function') setTimeout(renderStrandRecommendation, 50);
        const target = document.querySelector('.wizard-progress-wrap');
        if (target) { const y = target.getBoundingClientRect().top + window.pageYOffset - 20; window.scrollTo({ top: y, behavior: 'smooth' }); }
    }

    function alertError(err) {
        console.warn('[Wizard] blocked:', err.title, '|', err.html.replace(/<[^>]+>/g, ''));
        if (typeof Swal !== 'undefined' && Swal.fire) {
            Swal.fire({ icon: 'warning', title: err.title, html: err.html, confirmButtonColor: '#1565c0' }).then(() => setTimeout(ensurePageUnfrozen, 250));
        } else {
            alert(err.title + '\n\n' + err.html.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, ''));
        }
    }

    form.addEventListener('click', function(e) {
        const nextBtn = e.target.closest('.btn-next');
        const backBtn = e.target.closest('.btn-back');
        if (nextBtn && form.contains(nextBtn)) {
            e.preventDefault();
            e.stopPropagation();
            const target = parseInt(nextBtn.dataset.next, 10);
            const from   = target - 1;
            let err = null;
            try { err = getStepError(from); }
            catch (ex) { console.error('Wizard: getStepError threw', ex); }
            if (err) { alertError(err); return; }
            showStep(target);
            return;
        }
        if (backBtn && form.contains(backBtn) && !backBtn.classList.contains('placeholder')) {
            e.preventDefault();
            e.stopPropagation();
            const target = parseInt(backBtn.dataset.back, 10);
            showStep(target);
        }
    });

    indicators.forEach(el => {
        el.addEventListener('click', () => {
            const s = parseInt(el.dataset.step, 10);
            if (s <= currentStep || visited.has(s)) showStep(s);
        });
    });

    form.addEventListener('submit', async function(e){
        let firstBadStep = null;
        let firstErr     = null;
        for (let s = 1; s <= TOTAL_STEPS; s++) {
            const err = getStepError(s);
            if (err && firstBadStep === null) { firstBadStep = s; firstErr = err; }
        }
        if (firstBadStep !== null) { e.preventDefault(); showStep(firstBadStep); alertError(firstErr); return; }

        const relSel   = document.getElementById('religion_select');
        const relOther = document.getElementById('religion_other');
        if (relSel && relOther && relSel.value === '__other__') {
            let hidden = document.getElementById('religion_actual');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'religion';
                hidden.id = 'religion_actual';
                form.appendChild(hidden);
            }
            hidden.value = relOther.value.trim();
            relSel.removeAttribute('name');
        }

        const ack = document.getElementById('acknowledgeDuplicateName');
        if (ack && ack.value === '1') {
            const btn = document.getElementById('submitBtn');
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...'; }
            return;
        }

        const matches = (typeof window.__getNameMatches === 'function') ? window.__getNameMatches() : [];

        if (matches && matches.length > 0) {
            e.preventDefault();
            const items = matches.map(m => {
                let line = '<strong>' + m.name + '</strong>';
                if (m.lrn_masked) line += ' · LRN: ' + m.lrn_masked;
                if (m.grade_level) line += ' · ' + m.grade_level;
                return '<li style="text-align:left">' + line + '</li>';
            }).join('');

            if (typeof Swal !== 'undefined' && Swal.fire) {
                const res = await Swal.fire({
                    icon: 'warning',
                    title: '<?= htmlspecialchars($t[$lang]['name_duplicate_title'], ENT_QUOTES, "UTF-8") ?>',
                    html: '<p style="margin-bottom:.5rem"><?= htmlspecialchars($t[$lang]['name_duplicate_msg'], ENT_QUOTES, "UTF-8") ?></p><ul style="display:inline-block;margin:0;padding-left:1.2rem;font-size:.9rem">' + items + '</ul>',
                    showCancelButton: true,
                    confirmButtonText: '<?= htmlspecialchars($t[$lang]['name_duplicate_yes'], ENT_QUOTES, "UTF-8") ?>',
                    cancelButtonText: '<?= htmlspecialchars($t[$lang]['name_duplicate_no'], ENT_QUOTES, "UTF-8") ?>',
                    confirmButtonColor: '#d97706',
                    cancelButtonColor: '#6c757d',
                    reverseButtons: true
                });
                setTimeout(ensurePageUnfrozen, 250);
                if (res.isConfirmed) { if (ack) ack.value = '1'; form.submit(); }
            } else if (confirm('<?= htmlspecialchars($t[$lang]['name_duplicate_msg'], ENT_QUOTES, "UTF-8") ?>')) {
                if (ack) ack.value = '1';
                form.submit();
            }
            return;
        }

        const btn = document.getElementById('submitBtn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...'; }
    });

    form.addEventListener('input', () => {
        indicators.forEach(el => {
            const s = parseInt(el.dataset.step, 10);
            if (s === currentStep) return;
            el.classList.remove('completed','invalid');
            if (stepIsComplete(s)) el.classList.add('completed');
            else if (visited.has(s)) el.classList.add('invalid');
        });
    }, { passive: true });

    showStep(currentStep);
    console.log('[Wizard] ready — starting at step', currentStep);
    console.log('[Wizard] LRN length on load:', (form.querySelector('#lrn')?.value || '').length);

    <?php if ($name_dup_pending): ?>
    setTimeout(() => { form.requestSubmit(); }, 300);
    <?php endif; ?>
})();

/* ============================================================
   SUCCESS / ERROR POPUPS
   ============================================================ */
document.addEventListener('DOMContentLoaded', function () {

    <?php if (isset($_SESSION['success']) && $_SESSION['success'] === true): ?>
        <?php
            $sd = $_SESSION['success_data'] ?? [];

            $sd_safe = [
                'student_id_number' => (string)($sd['student_id_number'] ?? '—'),
                'lrn'               => (string)($sd['lrn']               ?? '—'),
                'full_name'         => (string)($sd['full_name']         ?? '—'),
                'school_year'       => (string)($sd['school_year']       ?? '—'),
                'grade_level'       => (string)($sd['grade_level']       ?? '—'),
                'strand'            => (string)($sd['strand']            ?? '—'),
                'email'             => (string)($sd['email']             ?? ''),
            ];

            $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

            $popup_html =
                  '<div class="ec-hero">'
                .   '<div class="ec-hero-icon"><i class="fas fa-file-circle-check"></i></div>'
                .   '<h2 class="ec-hero-title">Application Received</h2>'
                .   '<p class="ec-hero-subtitle">Your enrollment application is now in the review queue. A registrar will verify your information and uploaded documents.</p>'
                .   '<div class="ec-status-badge"><span class="ec-status-dot"></span>Expected &mdash; Pending Review</div>'
                . '</div>'

                . '<div class="ec-body">'

                .   '<div class="ec-section-title"><i class="fas fa-clipboard-list"></i>Application Summary</div>'
                .   '<div class="ec-details"><div class="ec-details-grid">'
                .     '<div class="ec-detail-item is-wide"><div class="ec-detail-label">Full Name</div><div class="ec-detail-value">' . $esc($sd_safe['full_name']) . '</div></div>'
                .     '<div class="ec-detail-item"><div class="ec-detail-label">Student ID</div><div class="ec-detail-value is-id">' . $esc($sd_safe['student_id_number']) . '</div></div>'
                .     '<div class="ec-detail-item"><div class="ec-detail-label">LRN</div><div class="ec-detail-value is-id">' . $esc($sd_safe['lrn']) . '</div></div>'
                .     '<div class="ec-detail-item"><div class="ec-detail-label">Grade Level</div><div class="ec-detail-value">' . $esc($sd_safe['grade_level']) . '</div></div>'
                .     '<div class="ec-detail-item"><div class="ec-detail-label">School Year</div><div class="ec-detail-value">' . $esc($sd_safe['school_year']) . '</div></div>'
                .     '<div class="ec-detail-item is-wide"><div class="ec-detail-label">Strand</div><div class="ec-detail-value">' . $esc($sd_safe['strand']) . '</div></div>'
                .     ($sd_safe['email'] !== ''
                            ? '<div class="ec-detail-item is-wide"><div class="ec-detail-label">Notification Email</div><div class="ec-detail-value">' . $esc($sd_safe['email']) . '</div></div>'
                            : '')
                .   '</div></div>'

                .   '<div class="ec-section-title"><i class="fas fa-hourglass-half"></i>Enrollment Status</div>'
                .   '<div class="ec-expected-panel">'
                .     '<div class="ec-expected-icon"><i class="fas fa-triangle-exclamation"></i></div>'
                .     '<div class="ec-expected-body">'
                .       '<p class="ec-expected-title"><span class="expected-word">Expected</span>Not yet officially enrolled</p>'
                .       '<p class="ec-expected-text">You are <strong>expected</strong> to be enrolled once a registrar approves this application. '
                .         'Your record is <strong>pending review</strong> and will remain in the queue until the verification is completed. '
                .         'Do not consider yourself enrolled until you receive a confirmation.</p>'
                .     '</div>'
                .   '</div>'

                .   '<div class="ec-section-title"><i class="fas fa-route"></i>What Happens Next</div>'
                .   '<div class="ec-timeline-list">'

                .     '<div class="ec-timeline-item is-done">'
                .       '<div class="ec-timeline-icon"><i class="fas fa-check"></i></div>'
                .       '<div class="ec-timeline-body">'
                .         '<p class="ec-timeline-title">Application received</p>'
                .         '<p class="ec-timeline-desc">Your form and uploaded documents were submitted successfully.</p>'
                .       '</div>'
                .     '</div>'

                .     '<div class="ec-timeline-item is-active">'
                .       '<div class="ec-timeline-icon"><i class="fas fa-clock"></i></div>'
                .       '<div class="ec-timeline-body">'
                .         '<p class="ec-timeline-title">Registrar review &mdash; in progress</p>'
                .         '<p class="ec-timeline-desc">Our registrar is verifying your information and uploaded documents.</p>'
                .       '</div>'
                .     '</div>'

                .     '<div class="ec-timeline-item">'
                .       '<div class="ec-timeline-icon"><i class="fas fa-envelope"></i></div>'
                .       '<div class="ec-timeline-body">'
                .         '<p class="ec-timeline-title">Email notification</p>'
                .         '<p class="ec-timeline-desc">'
                .           ($sd_safe['email'] !== ''
                                ? 'You&rsquo;ll be notified at <strong>' . $esc($sd_safe['email']) . '</strong> once a decision is made.'
                                : 'You&rsquo;ll receive a confirmation once a decision is made.')
                .         '</p>'
                .       '</div>'
                .     '</div>'

                .     '<div class="ec-timeline-item">'
                .       '<div class="ec-timeline-icon"><i class="fas fa-graduation-cap"></i></div>'
                .       '<div class="ec-timeline-body">'
                .         '<p class="ec-timeline-title">Officially enrolled</p>'
                .         '<p class="ec-timeline-desc">After approval, you may log in to view your section and schedule.</p>'
                .       '</div>'
                .     '</div>'

                .   '</div>'

                .   '<div class="ec-note">'
                .     '<i class="fas fa-info-circle"></i>'
                .     '<span>Keep your Student ID <strong>' . $esc($sd_safe['student_id_number']) . '</strong> for reference. '
                .       'Your status will remain <strong>Expected &mdash; Pending Review</strong> until the registrar completes the verification.</span>'
                .   '</div>'

                . '</div>';
        ?>
        Swal.fire({
            html: <?= json_encode($popup_html, $JSON_FLAGS) ?>,
            width: 580,
            padding: 0,
            showConfirmButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Got it, thank you!',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showCloseButton: false,
            customClass: {
                popup:         'swal-enroll-popup',
                confirmButton: 'swal-enroll-confirm'
            },
            buttonsStyling: false
        }).then(function () {
            window.location.href = 'enroll_form.php';
        });
        <?php unset($_SESSION['success'], $_SESSION['success_data'], $_SESSION['success_message']); ?>

    <?php elseif (!empty($enroll_error)): ?>
        <?php
        $swal_html = '<div style="text-align:left;padding:.5rem 0">'
            . '<p style="margin:0 0 .85rem;font-size:1rem;font-weight:600;color:#0f172a;line-height:1.45">'
            . htmlspecialchars((string)$enroll_error['message'], ENT_QUOTES, 'UTF-8')
            . '</p>'
            . '<div style="background:#fef2f2;border-left:4px solid #dc3545;padding:.85rem 1rem;border-radius:8px;font-size:.9rem;color:#7f1d1d;line-height:1.55">'
            . htmlspecialchars((string)$enroll_error['action'], ENT_QUOTES, 'UTF-8')
            . '</div>'
            . '<p style="margin:1rem 0 0;font-size:.7rem;color:#94a3b8;text-align:center;font-family:ui-monospace,monospace">'
            . 'Reference: ' . htmlspecialchars((string)$enroll_error['ref'], ENT_QUOTES, 'UTF-8')
            . '</p>'
            . '</div>';
        ?>
        Swal.fire({
            icon: 'error',
            title: 'Enrollment could not be completed',
            html: <?= json_encode($swal_html, $JSON_FLAGS) ?>,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Fix and Retry',
            allowOutsideClick: false,
            width: 520
        }).then(function () { setTimeout(ensurePageUnfrozen, 350); });

    <?php elseif (isset($_SESSION['error'])): ?>
        Swal.fire({
            icon: 'error',
            title: 'Enrollment Failed',
            html: '<div style="text-align:left;padding:0.5rem 0"><p style="margin-bottom:0.75rem">We couldn\'t complete the enrollment.</p><div style="background:#fef2f2;border-left:4px solid #dc3545;padding:0.75rem 1rem;border-radius:8px;font-size:0.9rem;color:#991b1b">' + <?= json_encode($_SESSION['error'], $JSON_FLAGS) ?> + '</div></div>',
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Try Again'
        }).then(function () { setTimeout(ensurePageUnfrozen, 350); });
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
});
</script>
</body>
</html>