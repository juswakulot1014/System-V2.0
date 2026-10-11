<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/audit.php';

$current_page = basename($_SERVER['PHP_SELF']);

function strandInitials(string $strand): string {
    $stopwords = ['and','of','the','for','in','on','to','a','an','at','by','with','or'];
    $words = preg_split('/\s+/', trim($strand));
    $out = '';
    foreach ($words as $w) {
        $w = preg_replace('/[^A-Za-z0-9]/', '', $w);
        if ($w === '') continue;
        if (in_array(strtolower($w), $stopwords, true)) continue;
        $out .= strtoupper($w[0]);
    }
    return $out !== '' ? $out : 'GEN';
}

function formatStudentName(array $s): string {
    $mi  = !empty($s['middle_name']) ? ' ' . strtoupper(substr(trim($s['middle_name']), 0, 1)) . '.' : '';
    $ext = !empty($s['ext_name'])    ? ' ' . trim($s['ext_name']) : '';
    return trim(($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? '') . $mi . $ext);
}

/* ═══════════════════════════════════════════════════════════
   POST: UPDATE SCHOOL YEAR AFTER PROMOTION
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_school_year'])) {
    verify_csrf();

    $old_sy = trim($_POST['old_school_year'] ?? '');
    $new_sy = trim($_POST['new_school_year'] ?? '');

    if (empty($old_sy) || empty($new_sy)) {
        $_SESSION['error'] = "Both old and new school years are required.";
    } elseif ($old_sy === $new_sy) {
        $_SESSION['error'] = "New school year must be different from the old one.";
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE enrollment_form SET school_year = ? WHERE school_year = ? AND grade_level IN ('Grade 12', 'Graduated')");
            $stmt->bind_param("ss", $new_sy, $old_sy);
            $stmt->execute();
            $updated = $stmt->affected_rows;
            $stmt->close();
            $conn->commit();

            audit_log($conn, 'schoolyear.bulk_update', ['type'=>'school_year','from'=>$old_sy,'to'=>$new_sy,'affected'=>$updated]);

            if ($updated > 0) {
                $_SESSION['success'] = "School year updated! $updated promoted student(s) moved from $old_sy to $new_sy.";
            } else {
                $_SESSION['info'] = "No promoted students found with school year $old_sy.";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Update failed: " . $e->getMessage();
        }
    }
    header("Location: student_profile.php?school_year=" . urlencode($new_sy));
    exit();
}

/* ═══════════════════════════════════════════════════════════
   POST: PROMOTE SELECTED STUDENTS
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_selected'])) {
    verify_csrf();

    $selected_ids    = $_POST['selected_students'] ?? [];
    $promote_sy      = trim($_POST['promote_school_year'] ?? '');
    $new_school_year = trim($_POST['new_school_year'] ?? '');

    if (empty($selected_ids)) {
        $_SESSION['error'] = "No students selected for promotion.";
    } elseif (empty($promote_sy)) {
        $_SESSION['error'] = "School Year is required.";
    } elseif (empty($new_school_year)) {
        $_SESSION['error'] = "New School Year is required.";
    } else {
        $conn->begin_transaction();
        try {
            $promoted_11    = 0;
            $graduated_12   = 0;
            $admin_id = (int)($_SESSION['admin_id'] ?? 0);

            foreach ($selected_ids as $student_id) {
                $sid = (int)$student_id;

                $check = $conn->prepare("SELECT grade_level, status FROM enrollment_form WHERE student_id = ? AND school_year = ? AND (status = 'Active' OR status IS NULL) LIMIT 1");
                $check->bind_param("is", $sid, $promote_sy);
                $check->execute();
                $result = $check->get_result()->fetch_assoc();
                $check->close();

                if ($result) {
                    if ($result['grade_level'] === 'Grade 11') {
                        $upd = $conn->prepare("UPDATE enrollment_form SET grade_level = 'Grade 12', school_year = ? WHERE student_id = ? AND school_year = ?");
                        $upd->bind_param("sis", $new_school_year, $sid, $promote_sy);
                        $upd->execute();
                        if ($upd->affected_rows > 0) $promoted_11++;
                        $upd->close();
                    } elseif ($result['grade_level'] === 'Grade 12') {
                        $old_status = $result['status'] ?? 'Active';

                        $upd = $conn->prepare("UPDATE enrollment_form SET status = 'Graduated', school_year = ? WHERE student_id = ? AND school_year = ? AND grade_level = 'Grade 12'");
                        $upd->bind_param("sis", $new_school_year, $sid, $promote_sy);
                        $upd->execute();
                        if ($upd->affected_rows > 0) {
                            $graduated_12++;

                            $hist = $conn->prepare("
                                INSERT INTO student_status_history
                                    (student_id, old_status, new_status, reason, changed_by)
                                VALUES (?, ?, 'Graduated', ?, ?)
                            ");
                            $reason = "Graduated via batch promotion ($promote_sy → $new_school_year)";
                            $hist->bind_param("issi", $sid, $old_status, $reason, $admin_id);
                            $hist->execute();
                            $hist->close();
                        }
                        $upd->close();
                    }
                }
            }

            $conn->commit();

            audit_log($conn, 'student.promote', ['type'=>'batch','from_sy'=>$promote_sy,'to_sy'=>$new_school_year,'promoted'=>$promoted_11,'graduated'=>$graduated_12]);

            $msg = [];
            if ($promoted_11 > 0)  $msg[] = "$promoted_11 → Grade 12";
            if ($graduated_12 > 0) $msg[] = "$graduated_12 → Graduated";

            if (!empty($msg)) {
                $_SESSION['success'] = "Promotion completed! " . implode(" • ", $msg) . ". School year updated to $new_school_year.";
            } else {
                $_SESSION['error'] = "No eligible active students were promoted.";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Promotion failed: " . $e->getMessage();
        }
    }
    header("Location: student_profile.php?school_year=" . urlencode($new_school_year ?: $promote_sy));
    exit();
}

/* ═══════════════════════════════════════════════════════════
   POST: PROMOTE ALL (bulk by grade level) — CSRF protected
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_students'])) {
    verify_csrf();

    $promote_sy      = trim($_POST['school_year']     ?? '');
    $promote_type    = trim($_POST['promote_type']    ?? '');
    $new_school_year = trim($_POST['new_school_year'] ?? '');

    if (empty($promote_sy)) {
        $_SESSION['error'] = "Please select a School Year to promote students.";
    } elseif (empty($new_school_year)) {
        $_SESSION['error'] = "Please provide a New School Year.";
    } else {
        $conn->begin_transaction();
        try {
            $total_promoted = 0;
            $admin_id = (int)($_SESSION['admin_id'] ?? 0);

            if ($promote_type === 'grade11') {
                $stmt = $conn->prepare("UPDATE enrollment_form SET grade_level = 'Grade 12', school_year = ? WHERE grade_level = 'Grade 11' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $stmt->bind_param("ss", $new_school_year, $promote_sy);
                $stmt->execute();
                $total_promoted = $stmt->affected_rows;
                $stmt->close();
                $_SESSION['success'] = $total_promoted > 0 ? "$total_promoted student(s) promoted from Grade 11 to Grade 12. School year updated to $new_school_year." : "No active Grade 11 students found.";

            } elseif ($promote_type === 'grade12') {
                $aff = $conn->prepare("SELECT DISTINCT student_id FROM enrollment_form WHERE grade_level = 'Grade 12' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $aff->bind_param("s", $promote_sy);
                $aff->execute();
                $affected_ids = array_column($aff->get_result()->fetch_all(MYSQLI_ASSOC), 'student_id');
                $aff->close();

                $stmt = $conn->prepare("UPDATE enrollment_form SET status = 'Graduated', school_year = ? WHERE grade_level = 'Grade 12' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $stmt->bind_param("ss", $new_school_year, $promote_sy);
                $stmt->execute();
                $total_promoted = $stmt->affected_rows;
                $stmt->close();

                if (!empty($affected_ids)) {
                    $hist = $conn->prepare("
                        INSERT INTO student_status_history
                            (student_id, old_status, new_status, reason, changed_by)
                        VALUES (?, 'Active', 'Graduated', ?, ?)
                    ");
                    $reason = "Bulk graduated via Promote All ($promote_sy → $new_school_year)";
                    foreach ($affected_ids as $aid) {
                        $aid = (int)$aid;
                        $hist->bind_param("isi", $aid, $reason, $admin_id);
                        $hist->execute();
                    }
                    $hist->close();
                }
                $_SESSION['success'] = $total_promoted > 0 ? "$total_promoted student(s) graduated. School year updated to $new_school_year." : "No active Grade 12 students found.";

            } elseif ($promote_type === 'both') {
                $stmt = $conn->prepare("UPDATE enrollment_form SET grade_level = 'Grade 12', school_year = ? WHERE grade_level = 'Grade 11' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $stmt->bind_param("ss", $new_school_year, $promote_sy); $stmt->execute(); $p11 = $stmt->affected_rows; $stmt->close();

                $aff = $conn->prepare("SELECT DISTINCT student_id FROM enrollment_form WHERE grade_level = 'Grade 12' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $aff->bind_param("s", $promote_sy);
                $aff->execute();
                $affected_ids = array_column($aff->get_result()->fetch_all(MYSQLI_ASSOC), 'student_id');
                $aff->close();

                $stmt = $conn->prepare("UPDATE enrollment_form SET status = 'Graduated', school_year = ? WHERE grade_level = 'Grade 12' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $stmt->bind_param("ss", $new_school_year, $promote_sy); $stmt->execute(); $p12 = $stmt->affected_rows; $stmt->close();

                if (!empty($affected_ids)) {
                    $hist = $conn->prepare("
                        INSERT INTO student_status_history
                            (student_id, old_status, new_status, reason, changed_by)
                        VALUES (?, 'Active', 'Graduated', ?, ?)
                    ");
                    $reason = "Bulk graduated via Promote Both ($promote_sy → $new_school_year)";
                    foreach ($affected_ids as $aid) {
                        $aid = (int)$aid;
                        $hist->bind_param("isi", $aid, $reason, $admin_id);
                        $hist->execute();
                    }
                    $hist->close();
                }

                $total_promoted = $p11 + $p12;
                $msg = [];
                if ($p11 > 0) $msg[] = "$p11 → Grade 12";
                if ($p12 > 0) $msg[] = "$p12 → Graduated";
                $_SESSION['success'] = !empty($msg) ? "Promotion completed! " . implode(" • ", $msg) . ". School year updated to $new_school_year." : "No active students found.";
            }

            $conn->commit();

            audit_log($conn, 'student.promote_all', ['type'=>'batch','from_sy'=>$promote_sy,'to_sy'=>$new_school_year,'mode'=>$promote_type,'affected'=>$total_promoted]);

        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Promotion failed: " . $e->getMessage();
        }
    }
    header("Location: student_profile.php?school_year=" . urlencode($new_school_year ?: $promote_sy));
    exit();
}

/* ═══════════════════════════════════════════════════════════
   FILTER INPUTS
   ═══════════════════════════════════════════════════════════ */
$search       = trim($_GET['search']       ?? $_POST['search']       ?? '');
$grade_level  = trim($_GET['grade_level']  ?? $_POST['grade_level']  ?? '');
$school_year  = trim($_GET['school_year']  ?? $_POST['school_year']  ?? '');
$status       = trim($_GET['status']       ?? $_POST['status']       ?? '');

/* ═══════════════════════════════════════════════════════════
   PAGINATION
   ═══════════════════════════════════════════════════════════ */
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;

/* ═══════════════════════════════════════════════════════════
   POST: AUTO SECTION ASSIGNMENT — CSRF protected
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_assign'])) {
    verify_csrf();

    $grade_level = trim($_POST['grade_level'] ?? '');
    $school_year = trim($_POST['school_year'] ?? '');

    if (empty($grade_level) || empty($school_year)) {
        $_SESSION['error'] = "Please select Grade Level and School Year to auto-assign sections.";
    } else {
        $target_size      = max(5, min(60, (int)($_POST['section_size'] ?? 35)));
        $replace_existing = !empty($_POST['replace_sections']);
        $max_sections     = 26;

        $stmt = $conn->prepare("
            SELECT e.enrollment_id, e.strand, e.program, e.section,
                   s.last_name, s.first_name, s.sex
            FROM enrollment_form e
            INNER JOIN students_info s ON e.student_id = s.student_id
            WHERE e.grade_level = ?
              AND e.school_year = ?
              AND COALESCE(e.status, 'Active') = 'Active'
            ORDER BY e.strand ASC, s.last_name ASC, s.first_name ASC
        ");
        $stmt->bind_param("ss", $grade_level, $school_year);
        $stmt->execute();
        $all_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($all_rows)) {
            $_SESSION['info'] = "No active students found for Grade $grade_level - SY $school_year.";
        } else {
            $groups           = [];
            $skipped_groups   = [];
            $skipped_students = 0;

            foreach ($all_rows as $r) {
                $strand = trim((string)($r['strand'] ?? ''));
                if ($strand === '') $strand = trim((string)($r['program'] ?? ''));
                if ($strand === '') $strand = 'General';
                $groups[$strand][] = $r;
            }

            if (!$replace_existing) {
                foreach ($groups as $strand => $rows_in_group) {
                    $has_existing = false;
                    foreach ($rows_in_group as $r) {
                        if (!empty($r['section'])) { $has_existing = true; break; }
                    }
                    if ($has_existing) {
                        $skipped_groups[] = $strand;
                        $skipped_students += count($rows_in_group);
                        unset($groups[$strand]);
                    }
                }
            }

            if (empty($groups)) {
                $_SESSION['info'] = "Nothing to assign — every strand in $grade_level / $school_year already has sections. Use Replace mode to force a full reassignment.";
            } else {
                $upd = $conn->prepare("UPDATE enrollment_form SET section = ? WHERE enrollment_id = ?");
                if (!$upd) {
                    $_SESSION['error'] = "Prepare failed: " . $conn->error;
                } else {
                    $total_assigned = 0;
                    $total_sections = 0;
                    $capped_groups  = [];

                    $conn->begin_transaction();
                    try {
                        foreach ($groups as $strand => $students) {
                            $base = strandInitials($strand);
                            if ($base === '') $base = 'GEN';

                            $males = []; $females = [];
                            foreach ($students as $s) {
                                $sx = strtoupper(trim($s['sex'] ?? ''));
                                if ($sx === 'M' || $sx === 'MALE') $males[] = $s;
                                else $females[] = $s;
                            }

                            $ordered = [];
                            $mi = 0; $fi = 0;
                            $n_m = count($males); $n_f = count($females);
                            $n_total = $n_m + $n_f;
                            for ($i = 0; $i < $n_total; $i++) {
                                $pick_male = false;
                                if ($mi < $n_m && $fi < $n_f) {
                                    $pick_male = ($mi * $n_f) <= ($fi * $n_m);
                                } elseif ($mi < $n_m) {
                                    $pick_male = true;
                                }
                                $ordered[] = $pick_male ? $males[$mi++] : $females[$fi++];
                            }

                            $n = count($ordered);
                            $num_sections = (int)ceil($n / $target_size);
                            if ($num_sections < 1) $num_sections = 1;

                            if ($num_sections > $max_sections) {
                                $capped_groups[] = $base . " (needed $num_sections, capped at $max_sections)";
                                $num_sections = $max_sections;
                            }

                            $per_section = (int)ceil($n / $num_sections);

                            $idx = 0;
                            for ($sec = 0; $sec < $num_sections; $sec++) {
                                $letter = chr(65 + $sec);
                                $section_name = $base . ' ' . $letter;

                                for ($i = 0; $i < $per_section && $idx < $n; $i++, $idx++) {
                                    $upd->bind_param("si", $section_name, $ordered[$idx]['enrollment_id']);
                                    if (!$upd->execute()) {
                                        throw new Exception("Failed to update section: " . $upd->error);
                                    }
                                    $total_assigned++;
                                }
                                $total_sections++;
                            }
                        }
                        $upd->close();
                        $conn->commit();

                        audit_log($conn, 'section.auto_assign', ['type'=>'batch','grade'=>$grade_level,'sy'=>$school_year,'assigned'=>$total_assigned,'sections'=>$total_sections]);

                        $msg = "Auto-section complete: $total_assigned student(s) assigned to $total_sections section(s) for $grade_level - SY $school_year.";
                        if ($skipped_students > 0) {
                            $msg .= " Skipped " . count($skipped_groups) . " strand(s) (" . $skipped_students . " student(s)) that already had sections.";
                        }
                        if (!empty($capped_groups)) {
                            $msg .= " Capped at $max_sections sections for: " . implode(', ', $capped_groups) . ".";
                        }
                        $_SESSION['success'] = $msg;
                    } catch (Exception $e) {
                        $conn->rollback();
                        $upd->close();
                        $_SESSION['error'] = "Auto-section failed: " . $e->getMessage();
                    }
                }
            }
        }
    }
    header("Location: student_profile.php?grade_level=" . urlencode($grade_level) . "&school_year=" . urlencode($school_year));
    exit();
}

/* ═══════════════════════════════════════════════════════════
   MAIN STUDENTS QUERY
   ═══════════════════════════════════════════════════════════ */
$sql = "SELECT s.student_id, s.student_id_number, s.lrn, s.last_name, s.first_name, s.middle_name, s.ext_name, s.sex, s.age, e.grade_level, e.school_year, e.section, e.strand, e.track, e.program, e.term, COALESCE(e.status, 'Active') AS status, COUNT(*) OVER() as total_count FROM students_info s INNER JOIN enrollment_form e ON s.student_id = e.student_id WHERE 1=1";

$params = []; $types = "";
if ($search !== '')      { $sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)"; $like = "%$search%"; $params = [$like,$like,$like,$like]; $types = "ssss"; }
if ($grade_level !== '') { $sql .= " AND e.grade_level = ?"; $params[] = $grade_level; $types .= "s"; }
if ($school_year !== '') { $sql .= " AND e.school_year = ?"; $params[] = $school_year; $types .= "s"; }
if ($status !== '' && $status !== 'All') { $sql .= " AND COALESCE(e.status, 'Active') = ?"; $params[] = $status; $types .= "s"; }
$sql .= " ORDER BY s.last_name ASC, s.first_name ASC LIMIT ? OFFSET ?";
$params[] = $per_page; $types .= "i";
$params[] = $offset;   $types .= "i";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$all_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$total_students = !empty($all_students) ? (int)$all_students[0]['total_count'] : 0;
$total_pages = ceil($total_students / $per_page);
$stmt->close();

// Gender Stats
$gsql = "SELECT SUM(CASE WHEN UPPER(TRIM(s.sex)) IN ('MALE','M') THEN 1 ELSE 0 END) AS boys, SUM(CASE WHEN UPPER(TRIM(s.sex)) IN ('FEMALE','F') THEN 1 ELSE 0 END) AS girls FROM students_info s INNER JOIN enrollment_form e ON s.student_id = e.student_id WHERE 1=1";
$gp = []; $gt = "";
if ($search !== '')      { $gsql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ?)"; $gp = [$like,$like,$like]; $gt = "sss"; }
if ($grade_level !== '') { $gsql .= " AND e.grade_level = ?"; $gp[] = $grade_level; $gt .= "s"; }
if ($school_year !== '') { $gsql .= " AND e.school_year = ?"; $gp[] = $school_year; $gt .= "s"; }
if ($status !== '' && $status !== 'All') { $gsql .= " AND COALESCE(e.status, 'Active') = ?"; $gp[] = $status; $gt .= "s"; }
$gs = $conn->prepare($gsql); if(!empty($gp)) $gs->bind_param($gt,...$gp); $gs->execute(); $st = $gs->get_result()->fetch_assoc(); $gs->close();
$boys = (int)($st['boys']??0); $girls = (int)($st['girls']??0);

// Section count — used only for the stat tile
$sec_count_row = $conn->query("SELECT COUNT(DISTINCT section) AS c FROM enrollment_form WHERE section IS NOT NULL AND section != ''")->fetch_assoc();
$sec_count = (int)($sec_count_row['c'] ?? 0);

// Promote preview counts
$promote_preview_11 = 0; $promote_preview_12 = 0;
if ($school_year) {
    $ps = $conn->prepare("SELECT COUNT(*) as cnt FROM enrollment_form WHERE grade_level = 'Grade 11' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
    $ps->bind_param("s", $school_year); $ps->execute(); $pr = $ps->get_result()->fetch_assoc(); $promote_preview_11 = (int)($pr['cnt']??0); $ps->close();
    $ps = $conn->prepare("SELECT COUNT(*) as cnt FROM enrollment_form WHERE grade_level = 'Grade 12' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
    $ps->bind_param("s", $school_year); $ps->execute(); $pr = $ps->get_result()->fetch_assoc(); $promote_preview_12 = (int)($pr['cnt']??0); $ps->close();
}

$show_promote_mode = isset($_GET['show_promotable']) && $school_year;

// Dropdowns
$grade_levels = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form WHERE grade_level IS NOT NULL AND grade_level != '' ORDER BY grade_level ASC");
$school_years = $conn->query("SELECT DISTINCT school_year FROM enrollment_form WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC");
$possible_statuses = ['Active', 'Transferred', 'Stopped', 'Dropped', 'Graduated'];
$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';

$base_url = "?search=" . urlencode($search) . "&grade_level=" . urlencode($grade_level) . "&school_year=" . urlencode($school_year) . "&status=" . urlencode($status);
if ($show_promote_mode) $base_url .= "&show_promotable=1";

$next_sy = '';
$parts = explode('-', $school_year);
if (count($parts) === 2) {
    $next_sy = ((int)$parts[0] + 1) . '-' . ((int)$parts[1] + 1);
}

// Sections visible in current filtered set — used by promote-selection filter
$selection_sections = [];
if ($show_promote_mode) {
    foreach ($all_students as $s) {
        $sec = trim((string)($s['section'] ?? ''));
        if ($sec !== '' && !in_array($sec, $selection_sections, true)) {
            $selection_sections[] = $sec;
        }
    }
    sort($selection_sections, SORT_NATURAL | SORT_FLAG_CASE);
}

$csrf_token = csrf_token();
$can_bulk   = ($school_year !== '' && ($promote_preview_11 > 0 || $promote_preview_12 > 0));
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profiles • USAT Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --bg: #f4f6fb; --surface: #ffffff; --surface-2: #f8fafc;
            --text: #0f172a; --text-2: #64748b; --text-3: #94a3b8;
            --border: #e5e7eb; --border-strong: #d1d5db;
            --accent: #4f46e5; --accent-2: #6366f1; --accent-soft: #eef2ff;
            --green: #059669; --green-soft: #d1fae5;
            --red: #dc2626; --red-soft: #fee2e2;
            --amber: #d97706; --amber-soft: #fef3c7;
            --purple: #7c3aed; --purple-soft: #ede9fe;
            --blue: #2563eb;
            --shadow-xs: 0 1px 2px rgba(15,23,42,0.04);
            --shadow-sm: 0 1px 3px rgba(15,23,42,0.06), 0 1px 2px rgba(15,23,42,0.04);
            --shadow-md: 0 4px 12px rgba(15,23,42,0.06);
            --shadow-lg: 0 12px 32px rgba(15,23,42,0.10);
            --radius-sm: 8px; --radius: 12px; --radius-lg: 16px;
            --sidebar-w: 264px;
        }
        [data-bs-theme="dark"] {
            --bg: #0b1220; --surface: #131c2e; --surface-2: #1a2439;
            --text: #f1f5f9; --text-2: #94a3b8; --text-3: #64748b;
            --border: #1f2a44; --border-strong: #2d3b57;
            --accent: #818cf8; --accent-2: #6366f1; --accent-soft: #1e2140;
            --green-soft: #052e24; --red-soft: #3f1517; --amber-soft: #3d2a08; --purple-soft: #2e1a54;
            --shadow-xs: 0 1px 2px rgba(0,0,0,0.3);
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.35);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 12px 32px rgba(0,0,0,0.55);
        }

        *{margin:0;padding:0;box-sizing:border-box}
        html,body{height:100%}
        body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased}

        .sidebar{position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);z-index:200;display:flex;flex-direction:column;transition:transform .28s}
        .sidebar-brand{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem}
        .sidebar-brand img{width:38px;height:38px;border-radius:10px;object-fit:cover;box-shadow:0 0 0 3px var(--accent-soft)}
        .sidebar-brand .brand-text{display:flex;flex-direction:column;line-height:1.15}
        .sidebar-brand .brand-name{font-weight:700;font-size:.95rem}
        .sidebar-brand .brand-sub{font-size:.68rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;font-weight:600}
        .sidebar-nav{flex:1;padding:.75rem .75rem 1rem;overflow-y:auto}
        .sidebar-nav .nav-label{font-size:.65rem;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.8px;padding:.75rem .75rem .35rem}
        .sidebar-nav a{display:flex;align-items:center;gap:.75rem;padding:.6rem .75rem;border-radius:10px;color:var(--text-2);text-decoration:none;font-weight:500;font-size:.875rem;margin-bottom:.1rem;transition:background .15s,color .15s}
        .sidebar-nav a:hover{background:var(--surface-2);color:var(--text)}
        .sidebar-nav a.active{background:var(--accent);color:#fff}
        .sidebar-nav a i{font-size:1.05rem;width:20px;text-align:center;flex-shrink:0}
        .sidebar-footer{padding:.75rem;border-top:1px solid var(--border)}

        .main-content{margin-left:var(--sidebar-w);padding:1.5rem clamp(1rem,2.5vw,2rem) 2rem;min-height:100vh;max-width:1600px}
        .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap}
        .topbar h2{font-size:1.35rem;font-weight:700;letter-spacing:-.02em;margin:0}
        .topbar .sub{font-size:.85rem;color:var(--text-2);margin:0}
        .menu-toggle{display:none;width:40px;height:40px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;align-items:center;justify-content:center}

        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
        .stat-card{position:relative;background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow-sm);padding:1.1rem 1.25rem 1.1rem 1.4rem;display:flex;align-items:center;gap:1rem;overflow:hidden}
        .stat-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--accent)}
        .stat-card.tone-green::before{background:var(--green)}
        .stat-card.tone-red::before{background:var(--red)}
        .stat-card.tone-purple::before{background:var(--purple)}
        .stat-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;background:var(--accent-soft);color:var(--accent)}
        .tone-green .stat-icon{background:var(--green-soft);color:var(--green)}
        .tone-red .stat-icon{background:var(--red-soft);color:var(--red)}
        .tone-purple .stat-icon{background:var(--purple-soft);color:var(--purple)}
        .stat-value{font-size:1.55rem;font-weight:700;letter-spacing:-.02em;font-variant-numeric:tabular-nums;line-height:1.1}
        .stat-label{font-size:.7rem;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;margin-top:.2rem;font-weight:600}

        .card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow-sm);margin-bottom:1.25rem;overflow:hidden}
        .card-header{padding:.9rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
        .card-header h3{margin:0;font-size:.9rem;font-weight:600;display:flex;align-items:center;gap:.5rem}
        .card-header h3 i{color:var(--accent)}
        .card-body{padding:1.25rem}
        .card-body.no-padding{padding:0}

        .filter-bar{display:grid;grid-template-columns:minmax(200px,1.6fr) minmax(140px,1fr) minmax(140px,1fr) minmax(140px,1fr) auto;gap:.75rem;align-items:end;width:100%}
        .filter-bar .field{display:flex;flex-direction:column;gap:.3rem;min-width:0}
        .filter-bar label{font-size:.68rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px}
        .filter-bar input,.filter-bar select{background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:.5rem .8rem;font-size:.85rem;color:var(--text);font-family:inherit;width:100%}
        .filter-bar input:focus,.filter-bar select:focus{border-color:var(--accent);background:var(--surface);outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%,transparent)}
        .filter-bar .actions{display:flex;gap:.5rem;flex-wrap:nowrap}

        .bulk-actions{background:linear-gradient(135deg,var(--accent-soft),color-mix(in srgb,var(--accent-soft) 40%,var(--surface)));border:1px solid color-mix(in srgb,var(--accent) 20%,transparent);border-radius:var(--radius-lg);padding:1.1rem 1.25rem;margin-bottom:1.25rem;display:grid;grid-template-columns:1fr 1fr;gap:1rem}
        [data-bs-theme="dark"] .bulk-actions{background:linear-gradient(135deg,#1e2140,color-mix(in srgb,#1e2140 60%,var(--surface)))}
        .bulk-action{display:flex;align-items:flex-start;gap:.9rem;padding:1rem 1.1rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);transition:all .15s}
        .bulk-action:hover{border-color:var(--accent);box-shadow:var(--shadow-md);transform:translateY(-1px)}
        .bulk-action-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;background:var(--green-soft);color:var(--green)}
        .bulk-action-icon.promote-icon{background:linear-gradient(135deg,#fbbf24,#d97706);color:#fff}
        .bulk-action-body{flex:1;min-width:0}
        .bulk-action-title{font-size:.9rem;font-weight:700;color:var(--text);margin-bottom:.15rem}
        .bulk-action-desc{font-size:.78rem;color:var(--text-2);line-height:1.5;margin-bottom:.75rem}
        .bulk-action-btn{display:inline-flex;align-items:center;gap:.35rem;padding:.5rem 1rem;border-radius:9px;border:none;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .15s;font-family:inherit}
        .bulk-action-btn.primary{background:var(--green);color:#fff}
        .bulk-action-btn.primary:hover{background:#047857;transform:translateY(-1px)}
        .bulk-action-btn.promote{background:linear-gradient(135deg,#fbbf24,#d97706);color:#fff}
        .bulk-action-btn.promote:hover{box-shadow:0 6px 16px -4px rgba(217,119,6,.5);transform:translateY(-1px)}
        .bulk-action-btn:disabled{opacity:.5;cursor:not-allowed;transform:none!important;box-shadow:none!important}

        .table-scroll{overflow-x:auto}
        .table-admin{width:100%;border-collapse:separate;border-spacing:0}
        .table-admin thead th{background:var(--surface-2);font-size:.66rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px;padding:.7rem 1rem;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
        .table-admin tbody td{padding:.65rem 1rem;border-bottom:1px solid var(--border);font-size:.85rem;vertical-align:middle;transition:background .12s}
        .table-admin tbody tr:hover td{background:color-mix(in srgb,var(--accent) 4%,transparent)}
        .table-admin tbody tr:last-child td{border-bottom:none}
        .cell-strong{font-weight:600;color:var(--text)}
        .cell-muted{color:var(--text-2);font-size:.82rem}
        .cell-center{text-align:center}
        .cell-num{font-variant-numeric:tabular-nums;font-weight:600;color:var(--text)}
        .cell-male{color:var(--blue);font-weight:600;font-variant-numeric:tabular-nums}
        .cell-female{color:var(--red);font-weight:600;font-variant-numeric:tabular-nums}

        .student-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1rem}
        .student-card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow-xs);padding:1.1rem 1.15rem;display:flex;gap:.9rem;align-items:flex-start;position:relative;transition:transform .18s,box-shadow .18s,border-color .18s}
        .student-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);border-color:var(--border-strong)}
        .student-card.selected{border-color:var(--amber);box-shadow:0 0 0 3px color-mix(in srgb,var(--amber) 22%,transparent);background:color-mix(in srgb,var(--amber) 5%,var(--surface))}
        .student-card .select-check{position:absolute;top:.85rem;right:.85rem;width:20px;height:20px;accent-color:var(--amber);cursor:pointer;z-index:2}
        .student-avatar{width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent-2));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1.05rem;flex-shrink:0;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 15%,transparent)}
        .student-info{flex:1;min-width:0}
        .student-info h4{font-size:.92rem;font-weight:600;margin:0 0 .15rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding-right:1.6rem;color:var(--text)}
        .student-info .meta{font-size:.74rem;color:var(--text-2);font-variant-numeric:tabular-nums}
        .student-info .tags{display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.6rem}

        .tag{display:inline-flex;align-items:center;gap:.25rem;padding:.18rem .55rem;border-radius:999px;font-size:.68rem;font-weight:600;background:var(--surface-2);color:var(--text-2);border:1px solid var(--border);white-space:nowrap}
        .tag.accent{background:var(--accent-soft);color:var(--accent);border-color:color-mix(in srgb,var(--accent) 22%,transparent)}
        .tag.promotable{background:var(--amber-soft);color:var(--amber);border-color:color-mix(in srgb,var(--amber) 25%,transparent)}
        .tag.initials{background:var(--purple-soft);color:var(--purple);border-color:color-mix(in srgb,var(--purple) 22%,transparent);font-weight:700;letter-spacing:.4px}

        .pill{display:inline-flex;align-items:center;gap:.4rem;padding:.22rem .6rem;border-radius:999px;font-size:.7rem;font-weight:600;border:1px solid transparent}
        .pill::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;opacity:.9}
        .pill.success{background:var(--green-soft);color:var(--green)}
        .pill.warning{background:var(--amber-soft);color:var(--amber)}
        .pill.danger {background:var(--red-soft);color:var(--red)}
        .pill.muted  {background:var(--surface-2);color:var(--text-2)}

        .promote-bar{position:sticky;top:.75rem;z-index:50;background:linear-gradient(135deg,#fbbf24,#d97706);color:#fff;border-radius:var(--radius);padding:.85rem 1.15rem;margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;box-shadow:0 8px 24px -8px rgba(217,119,6,.6)}
        .promote-bar .selected-count{font-weight:700;font-size:1.15rem;line-height:1}
        .promote-bar .selected-label{font-size:.78rem;opacity:.9;margin-top:.15rem}
        .promote-bar input.form-control{background:rgba(255,255,255,.95);border:1px solid rgba(0,0,0,.08);color:#0f172a;font-weight:500}
        .promote-bar input.form-control:focus{box-shadow:0 0 0 3px rgba(255,255,255,.35);border-color:transparent}
        .btn-promote{background:#0f172a;color:#fff;border:none;font-weight:600;transition:all .15s}
        .btn-promote:hover:not(:disabled){background:#1e293b;color:#fff;transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.25)}
        .btn-promote:disabled{opacity:.5;cursor:not-allowed}

        .selection-filters{background:linear-gradient(135deg,color-mix(in srgb,var(--accent-soft) 60%,var(--surface)),var(--surface));border:1px solid color-mix(in srgb,var(--accent) 22%,transparent);border-radius:var(--radius);padding:1rem 1.15rem;margin-bottom:1rem}
        [data-bs-theme="dark"] .selection-filters{background:linear-gradient(135deg,color-mix(in srgb,#1e2140 60%,var(--surface)),var(--surface))}
        .sf-head{display:flex;align-items:flex-start;gap:.7rem;margin-bottom:.85rem}
        .sf-head-icon{width:34px;height:34px;border-radius:9px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
        .sf-head-title{font-size:.85rem;font-weight:700;color:var(--text);line-height:1.25;margin-bottom:.1rem}
        .sf-head-desc{font-size:.75rem;color:var(--text-2);line-height:1.4}
        .sf-grid{display:grid;grid-template-columns:1fr 1fr 2fr auto;gap:.65rem;align-items:end}
        .sf-field{display:flex;flex-direction:column;gap:.3rem;min-width:0}
        .sf-field label{font-size:.68rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:.3rem}
        .sf-field label i{color:var(--accent);font-size:.75rem}
        .sf-field select,.sf-field input{background:var(--surface);border:1px solid var(--border);border-radius:9px;padding:.5rem .75rem;font-size:.83rem;color:var(--text);font-family:inherit;width:100%;transition:border-color .15s,box-shadow .15s}
        .sf-field select:focus,.sf-field input:focus{border-color:var(--accent);outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%,transparent)}
        .sf-field-action{padding-bottom:0}
        .sf-field-action .btn{padding:.5rem .75rem}
        .sf-footer{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-top:.65rem;padding-top:.65rem;border-top:1px dashed color-mix(in srgb,var(--accent) 25%,transparent)}
        .sf-summary{font-size:.78rem;font-weight:600;color:var(--accent);font-variant-numeric:tabular-nums}
        .sf-summary.muted{color:var(--text-2);font-weight:500}

        .btn{font-weight:500;border-radius:9px;font-size:.82rem;transition:all .15s}
        .btn-primary{background:var(--accent);border-color:var(--accent)}
        .btn-primary:hover{background:var(--accent-2);border-color:var(--accent-2)}
        .btn-outline-primary{color:var(--accent);border-color:var(--border-strong)}
        .btn-outline-primary:hover{background:var(--accent);border-color:var(--accent);color:#fff}
        .btn-outline-secondary{color:var(--text-2);border-color:var(--border)}
        .btn-outline-secondary:hover{background:var(--surface-2);color:var(--text);border-color:var(--border-strong)}
        .btn-icon{width:32px;height:32px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:9px}
        .theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text-2);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s}
        .theme-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}

        .pagination{display:inline-flex;align-items:center;gap:.25rem;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:.25rem}
        .pagination .btn{border:none;color:var(--text-2);min-width:34px;padding:.35rem .55rem}
        .pagination .btn:hover:not(:disabled){background:var(--surface-2);color:var(--text)}
        .pagination .btn:disabled{opacity:.4;cursor:not-allowed}
        .pagination .page-info{font-size:.78rem;color:var(--text-2);padding:0 .65rem;font-weight:500}

        .empty{padding:3rem 1.5rem;text-align:center;color:var(--text-2)}
        .empty .empty-icon{width:64px;height:64px;border-radius:50%;background:var(--surface-2);color:var(--text-3);display:inline-flex;align-items:center;justify-content:center;font-size:1.6rem;margin-bottom:1rem}
        .empty .empty-title{font-weight:600;color:var(--text);margin-bottom:.25rem}
        .empty .empty-sub{font-size:.85rem}

        .flash{display:flex;align-items:center;gap:.65rem;padding:.75rem 1rem;border-radius:var(--radius);margin-bottom:1rem;font-size:.88rem;font-weight:500;border:1px solid transparent}
        .flash.success{background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb,var(--green) 25%,transparent)}
        .flash.error  {background:var(--red-soft);color:var(--red);border-color:color-mix(in srgb,var(--red) 25%,transparent)}
        .flash.info   {background:var(--accent-soft);color:var(--accent);border-color:color-mix(in srgb,var(--accent) 25%,transparent)}
        .flash .btn-close{margin-left:auto;opacity:.6}

        .promote-modal .modal-content{border-radius:20px;overflow:hidden;border:none;box-shadow:0 24px 60px rgba(0,0,0,.25)}
        .promote-modal .modal-dialog{max-width:640px}
        .pm-hero{padding:1.75rem 1.75rem 1.5rem;background:linear-gradient(135deg,#fbbf24,#d97706);color:#fff;position:relative;overflow:hidden}
        [data-bs-theme="dark"] .pm-hero{background:linear-gradient(135deg,#78350f,#92400e)}
        .pm-hero::after{content:'';position:absolute;right:-50px;top:-50px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.12)}
        .pm-hero-inner{position:relative;z-index:1;display:flex;align-items:center;gap:1rem}
        .pm-hero .close-btn{position:absolute;top:1rem;right:1rem;z-index:2;width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.25);border:none;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s}
        .pm-hero .close-btn:hover{background:rgba(255,255,255,.4)}
        .pm-hero-icon{width:56px;height:56px;border-radius:16px;background:rgba(255,255,255,.22);border:2px solid rgba(255,255,255,.35);display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0;backdrop-filter:blur(4px)}
        .pm-hero-info h4{margin:0 0 .3rem;font-size:1.15rem;font-weight:700;letter-spacing:-.01em}
        .pm-hero-info p{margin:0;font-size:.85rem;opacity:.92}
        .pm-body{padding:1.75rem}
        .pm-section{margin-bottom:1.5rem}
        .pm-section:last-child{margin-bottom:0}
        .pm-section-label{font-size:.7rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.8px;margin-bottom:.75rem;display:flex;align-items:center;gap:.4rem}
        .pm-section-label i{color:var(--accent)}
        .pm-mode-grid{display:grid;grid-template-columns:1fr 1fr;gap:.65rem}
        .pm-mode-option{display:flex;align-items:flex-start;gap:.75rem;padding:.9rem 1rem;border:1.5px solid var(--border);border-radius:12px;background:var(--surface-2);cursor:pointer;transition:all .15s;position:relative}
        .pm-mode-option:hover{border-color:color-mix(in srgb,var(--accent) 40%,transparent);background:color-mix(in srgb,var(--accent) 4%,var(--surface-2))}
        .pm-mode-option.selected{border-color:var(--accent);background:var(--accent-soft);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 15%,transparent)}
        .pm-mode-option input{position:absolute;opacity:0;pointer-events:none}
        .pm-mode-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;background:var(--surface);color:var(--text-2)}
        .pm-mode-option.selected .pm-mode-icon{background:var(--accent);color:#fff}
        .pm-mode-body{flex:1;min-width:0}
        .pm-mode-title{font-size:.85rem;font-weight:700;color:var(--text);line-height:1.25;margin-bottom:.15rem}
        .pm-mode-desc{font-size:.72rem;color:var(--text-2);line-height:1.4}
        .pm-mode-count{display:inline-block;margin-top:.35rem;padding:.15rem .55rem;border-radius:999px;background:var(--surface);border:1px solid var(--border);font-size:.68rem;font-weight:700;color:var(--accent);font-variant-numeric:tabular-nums}
        .pm-mode-option.selected .pm-mode-count{background:#fff;color:var(--accent);border-color:var(--accent)}
        .pm-field{margin-bottom:1rem}
        .pm-field label{display:block;font-size:.75rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:.4rem}
        .pm-field input,.pm-field select{width:100%;background:var(--surface-2);border:1.5px solid var(--border);border-radius:11px;padding:.7rem .9rem;font-size:.92rem;color:var(--text);font-family:inherit;transition:border-color .15s,box-shadow .15s}
        .pm-field input:focus,.pm-field select:focus{border-color:var(--accent);background:var(--surface);outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%,transparent)}
        .pm-field .hint{font-size:.72rem;color:var(--text-3);margin-top:.3rem}
        .pm-preview{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1.5px solid rgba(5,150,105,.25);border-radius:12px;padding:1rem 1.15rem}
        [data-bs-theme="dark"] .pm-preview{background:linear-gradient(135deg,#052e24,#064e3b)}
        .pm-preview-head{display:flex;align-items:center;gap:.5rem;font-size:.72rem;font-weight:700;color:var(--green);text-transform:uppercase;letter-spacing:.7px;margin-bottom:.6rem}
        .pm-preview-head i{font-size:.9rem}
        .pm-preview-line{display:flex;align-items:center;justify-content:space-between;gap:1rem;font-size:.85rem;color:var(--text);padding:.35rem 0}
        .pm-preview-line + .pm-preview-line{border-top:1px dashed rgba(5,150,105,.2)}
        .pm-preview-line .num{font-weight:700;font-variant-numeric:tabular-nums;color:var(--green)}
        .pm-warn{display:flex;align-items:flex-start;gap:.65rem;padding:.85rem 1rem;background:var(--amber-soft);color:#92400e;border:1px solid rgba(217,119,6,.3);border-radius:11px;font-size:.82rem;line-height:1.55;margin-top:1rem}
        [data-bs-theme="dark"] .pm-warn{color:#fcd34d}
        .pm-warn i{font-size:1.05rem;flex-shrink:0;margin-top:1px}
        .pm-warn strong{font-weight:700}
        .pm-footer{padding:1.15rem 1.75rem;background:var(--surface-2);border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap}
        .pm-footer .btn{padding:.6rem 1.25rem;font-weight:600;font-size:.85rem;border-radius:10px}
        .pm-btn-confirm{background:linear-gradient(135deg,#059669,#047857);border:none;color:#fff;box-shadow:0 4px 12px -4px rgba(5,150,105,.5);transition:all .15s}
        .pm-btn-confirm:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 8px 20px -4px rgba(5,150,105,.6);color:#fff}
        .pm-btn-confirm:disabled{opacity:.5;cursor:not-allowed;transform:none}

        .aa-modal .modal-content{border-radius:20px;overflow:hidden;border:none}
        .aa-modal .modal-dialog{max-width:520px}
        .aa-hero{padding:1.5rem;background:linear-gradient(135deg,#059669,#047857);color:#fff}
        .aa-hero-icon{width:50px;height:50px;border-radius:14px;background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.35);display:flex;align-items:center;justify-content:center;font-size:1.3rem;margin-bottom:.9rem}
        .aa-hero h4{margin:0 0 .25rem;font-size:1.1rem;font-weight:700}
        .aa-hero p{margin:0;font-size:.85rem;opacity:.92}
        .aa-body{padding:1.5rem}
        .aa-check{display:flex;align-items:flex-start;gap:.65rem;padding:.85rem 1rem;border:1.5px solid var(--border);border-radius:11px;background:var(--surface-2);cursor:pointer;transition:all .15s}
        .aa-check:hover{border-color:var(--border-strong)}
        .aa-check input{margin-top:.2rem;width:16px;height:16px;accent-color:var(--accent);flex-shrink:0}
        .aa-check-body{flex:1}
        .aa-check-title{font-size:.85rem;font-weight:700;color:var(--text);margin-bottom:.15rem}
        .aa-check-desc{font-size:.75rem;color:var(--text-2);line-height:1.45}

        @media (max-width:1280px){
            .filter-bar{grid-template-columns:minmax(200px,1.6fr) 1fr 1fr auto}
            .filter-bar .actions{grid-column:1/-1;justify-content:flex-end;margin-top:.25rem}
        }
        @media (max-width:1024px){
            .sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}
            .main-content{margin-left:0}.menu-toggle{display:flex}
        }
        @media (max-width:900px){
            .filter-bar{grid-template-columns:1fr 1fr}
            .filter-bar .actions{grid-column:span 2;justify-content:stretch}
            .filter-bar .actions .btn{flex:1}
            .bulk-actions{grid-template-columns:1fr}
            .sf-grid{grid-template-columns:1fr 1fr}
            .sf-field-wide{grid-column:1 / -1}
            .sf-field-action{grid-column:1 / -1}
        }
        @media (max-width:640px){
            .stat-grid{grid-template-columns:repeat(2,1fr);gap:.75rem}
            .main-content{padding:1rem}
            .filter-bar{grid-template-columns:1fr}
            .student-grid{grid-template-columns:1fr}
            .pm-mode-grid{grid-template-columns:1fr}
            .sf-grid{grid-template-columns:1fr}
        }
    </style>
</head>
<body>

<?php
    $__current = basename($_SERVER['PHP_SELF']);
    $__super   = (($_SESSION['admin_role'] ?? '') === 'superadmin');
    $__student_pages = ['student_profile.php','view_student.php','edit_student.php','export_students.php','sections_list.php','sf10.php'];
    $__act = in_array($__current, $__student_pages, true) ? 'student_profile.php' : $__current;
    $__a   = fn($f) => $f === $__act ? ' class="active"' : '';
?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="../assets/img/usat.jpg" alt="USAT" onerror="this.style.background='var(--accent)'">
        <div class="brand-text">
            <span class="brand-name">USAT Admin</span>
            <span class="brand-sub">Enrollment System</span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Overview</div>
        <a href="dashboard.php"<?= $__a('dashboard.php') ?>><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="student_profile.php"<?= $__a('student_profile.php') ?>><i class="bi bi-people-fill"></i> Students</a>
        <?php $__pending_count = (int)($conn->query("SELECT COUNT(*) c FROM enrollment_form WHERE status='Pending'")->fetch_assoc()['c'] ?? 0); ?>
        <a href="pending_enrollments.php"<?= $__a('pending_enrollments.php') ?>>
            <i class="bi bi-hourglass-split"></i> Pending
            <?php if ($__pending_count > 0): ?>
                <span style="margin-left:auto;background:#d97706;color:#fff;font-size:.68rem;padding:.15rem .5rem;border-radius:999px;font-weight:700;line-height:1"><?= $__pending_count ?></span>
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
    <?php if(isset($_SESSION['info'])): ?>
        <div class="flash info">
            <i class="bi bi-info-circle-fill"></i>
            <span><?= htmlspecialchars($_SESSION['info']) ?></span>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['info']); endif; ?>

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h2>Student Profiles</h2>
                <p class="sub">Manage enrolled students, sections, and promotions</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="sections_list.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-diagram-3 me-1"></i> Sections List
            </a>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="stat-value"><?= number_format($total_students) ?></div>
                <div class="stat-label">Total Students</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-gender-male"></i></div>
            <div>
                <div class="stat-value"><?= number_format($boys) ?></div>
                <div class="stat-label">Male</div>
            </div>
        </div>
        <div class="stat-card tone-red">
            <div class="stat-icon"><i class="bi bi-gender-female"></i></div>
            <div>
                <div class="stat-value"><?= number_format($girls) ?></div>
                <div class="stat-label">Female</div>
            </div>
        </div>
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="bi bi-diagram-3"></i></div>
            <div>
                <div class="stat-value"><?= number_format($sec_count) ?></div>
                <div class="stat-label">Sections</div>
            </div>
        </div>
    </div>

    <!-- FILTERS -->
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-funnel"></i> Filters</h3>
            <?php if ($search || $grade_level || $school_year || $status): ?>
                <span class="cell-muted" style="font-size:.75rem"><i class="bi bi-funnel-fill me-1"></i> Active filters applied</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form class="filter-bar" method="GET" id="filterForm">
                <input type="hidden" name="page" value="1">

                <div class="field">
                    <label for="f_search">Search</label>
                    <input type="text" id="f_search" name="search" placeholder="Name, LRN, or student ID" value="<?= htmlspecialchars($search) ?>">
                </div>

                <div class="field">
                    <label for="f_grade">Grade Level</label>
                    <select id="f_grade" name="grade_level">
                        <option value="">All Grades</option>
                        <?php while($gl=$grade_levels->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($gl['grade_level']) ?>" <?= $gl['grade_level']===$grade_level?'selected':'' ?>>
                                <?= htmlspecialchars($gl['grade_level']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="f_sy">School Year</label>
                    <select id="f_sy" name="school_year">
                        <option value="">All Years</option>
                        <?php while($sy=$school_years->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $sy['school_year']===$school_year?'selected':'' ?>>
                                <?= htmlspecialchars($sy['school_year']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="f_status">Status</label>
                    <select id="f_status" name="status">
                        <option value="">All Status</option>
                        <?php foreach($possible_statuses as $st): ?>
                            <option value="<?= $st ?>" <?= $st===$status?'selected':'' ?>><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel me-1"></i> Filter
                    </button>
                    <a href="student_profile.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- BULK ACTIONS -->
    <div class="bulk-actions">
        <div class="bulk-action">
            <div class="bulk-action-icon"><i class="bi bi-magic"></i></div>
            <div class="bulk-action-body">
                <div class="bulk-action-title">Auto Assign Sections</div>
                <div class="bulk-action-desc">Distribute students into balanced sections automatically. Requires a Grade Level and School Year filter.</div>
                <button type="button"
                        class="bulk-action-btn primary"
                        id="openAutoAssignBtn"
                        data-grade="<?= htmlspecialchars($grade_level) ?>"
                        data-sy="<?= htmlspecialchars($school_year) ?>">
                    <i class="bi bi-magic"></i> Auto Assign
                </button>
            </div>
        </div>

        <div class="bulk-action">
            <div class="bulk-action-icon promote-icon"><i class="bi bi-rocket-takeoff"></i></div>
            <div class="bulk-action-body">
                <div class="bulk-action-title">Promote Students</div>
                <div class="bulk-action-desc">
                    <?php if ($can_bulk): ?>
                        Advance <strong><?= $promote_preview_11 ?></strong> G11 student(s) and graduate <strong><?= $promote_preview_12 ?></strong> G12 student(s) for <?= htmlspecialchars($school_year) ?>.
                    <?php else: ?>
                        Filter to a specific School Year to enable batch promotion.
                    <?php endif; ?>
                </div>
                <button type="button"
                        class="bulk-action-btn promote"
                        id="openPromoteBtn"
                        data-sy="<?= htmlspecialchars($school_year) ?>"
                        data-g11="<?= $promote_preview_11 ?>"
                        data-g12="<?= $promote_preview_12 ?>"
                        data-next-sy="<?= htmlspecialchars($next_sy) ?>"
                        <?= $can_bulk ? '' : 'disabled' ?>>
                    <i class="bi bi-rocket-takeoff"></i> Promote Students
                </button>
            </div>
        </div>
    </div>

    <!-- STUDENTS -->
    <div class="card">
        <div class="card-header">
            <h3>
                <i class="bi bi-people"></i> Students
                <span class="cell-muted" style="font-weight:500;font-size:.85rem">(<?= number_format($total_students) ?>)</span>
                <?php if ($show_promote_mode): ?>
                    <span class="tag promotable ms-2"><i class="bi bi-rocket-takeoff"></i> Selection mode</span>
                <?php endif; ?>
            </h3>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <?php if ($show_promote_mode): ?>
                    <span class="cell-muted" id="visibleCounter" style="font-size:.78rem;font-weight:600"></span>
                    <button class="btn btn-outline-secondary btn-sm" onclick="selectAllVisible()">
                        <i class="bi bi-check-all me-1"></i> Select Visible
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="deselectAll()">
                        <i class="bi bi-x-circle me-1"></i> Deselect All
                    </button>
                    <a href="?school_year=<?= urlencode($school_year) ?>&grade_level=<?= urlencode($grade_level) ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i> Exit
                    </a>
                <?php endif; ?>

                <?php if ($total_pages > 1): ?>
                    <div class="pagination ms-md-2">
                        <a href="<?= $base_url ?>&page=1" class="btn btn-sm <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-left"></i></a>
                        <a href="<?= $base_url ?>&page=<?= max(1,$page-1) ?>" class="btn btn-sm <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-left"></i></a>
                        <span class="page-info">Page <?= $page ?> of <?= $total_pages ?></span>
                        <a href="<?= $base_url ?>&page=<?= min($total_pages,$page+1) ?>" class="btn btn-sm <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
                        <a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="btn btn-sm <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-right"></i></a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <?php if ($show_promote_mode): ?>
            <div class="promote-bar" id="promoteBar" style="display:none">
                <div>
                    <div class="selected-count" id="selectedCount">0</div>
                    <div class="selected-label">student(s) selected for promotion</div>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <input type="text" id="promoteNewSY" class="form-control form-control-sm"
                           placeholder="New SY (e.g. <?= htmlspecialchars($next_sy) ?>)"
                           value="<?= htmlspecialchars($next_sy) ?>" style="width:180px">
                    <button class="btn btn-promote btn-sm" id="promoteSelectedBtn" disabled onclick="submitPromoteSelected()">
                        <i class="bi bi-rocket-takeoff me-1"></i> Promote
                    </button>
                </div>
                <form method="POST" id="promoteForm" style="display:none">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="promote_selected" value="1">
                    <input type="hidden" name="promote_school_year" value="<?= htmlspecialchars($school_year) ?>">
                    <input type="hidden" name="new_school_year" id="promoteNewSYHidden">
                    <div id="selectedIdsContainer"></div>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($show_promote_mode): ?>
            <div class="selection-filters" id="selectionFilters">
                <div class="sf-head">
                    <div class="sf-head-icon"><i class="bi bi-funnel-fill"></i></div>
                    <div class="sf-head-text">
                        <div class="sf-head-title">Filter students to promote</div>
                        <div class="sf-head-desc">Narrow down who's visible below. <strong>Select Visible</strong> only affects the students currently shown.</div>
                    </div>
                </div>
                <div class="sf-grid">
                    <div class="sf-field">
                        <label for="sfSection"><i class="bi bi-columns-gap"></i> Section</label>
                        <select id="sfSection" onchange="applySelectionFilters()">
                            <option value="">All sections</option>
                            <?php foreach ($selection_sections as $sec): ?>
                                <option value="<?= htmlspecialchars($sec) ?>"><?= htmlspecialchars($sec) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sf-field">
                        <label for="sfGrade"><i class="bi bi-mortarboard"></i> Grade Level</label>
                        <select id="sfGrade" onchange="applySelectionFilters()">
                            <option value="">All grades</option>
                            <option value="Grade 11">Grade 11</option>
                            <option value="Grade 12">Grade 12</option>
                        </select>
                    </div>
                    <div class="sf-field sf-field-wide">
                        <label for="sfStudent"><i class="bi bi-search"></i> Specific Student</label>
                        <input type="text" id="sfStudent"
                               placeholder="Search by name, LRN, or student ID"
                               oninput="applySelectionFilters()">
                    </div>
                    <div class="sf-field sf-field-action">
                        <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="clearSelectionFilters()">
                            <i class="bi bi-x-circle me-1"></i> Clear
                        </button>
                    </div>
                </div>
                <div class="sf-footer">
                    <span class="sf-summary" id="sfSummary">Showing all students</span>
                </div>
            </div>
            <?php endif; ?>

            <?php if($total_students > 0): ?>
                <div class="student-grid">
                    <?php foreach($all_students as $s):
                        $name = htmlspecialchars(formatStudentName($s));
                        $initials = strtoupper(substr($s['first_name']??'',0,1).substr($s['last_name']??'',0,1));
                        $st = $s['status'] ?? 'Active';
                        $isPromotable = ($st === 'Active') && in_array($s['grade_level']??'', ['Grade 11', 'Grade 12']);
                        $strand_txt = $s['strand'] ?? '';
                        $strand_init = $strand_txt !== '' ? strandInitials($strand_txt) : '—';
                        $pillClass = $st === 'Active' ? 'success' : ($st === 'Graduated' ? 'danger' : ($st === 'Dropped' ? 'danger' : 'warning'));
                        $search_blob = trim(($s['last_name'] ?? '') . ' ' . ($s['first_name'] ?? '') . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['lrn'] ?? '') . ' ' . ($s['student_id_number'] ?? ''));
                    ?>
                    <div class="student-card"
                         data-student-id="<?= (int)$s['student_id'] ?>"
                         data-section="<?= htmlspecialchars($s['section'] ?? '') ?>"
                         data-grade="<?= htmlspecialchars($s['grade_level'] ?? '') ?>"
                         data-search="<?= htmlspecialchars($search_blob) ?>">
                        <?php if ($show_promote_mode && $isPromotable): ?>
                            <input type="checkbox" class="select-check student-checkbox" value="<?= (int)$s['student_id'] ?>" onchange="updateSelection()">
                        <?php endif; ?>
                        <div class="student-avatar"><?= htmlspecialchars($initials) ?></div>
                        <div class="student-info">
                            <h4 title="<?= $name ?>"><?= $name ?></h4>
                            <div class="meta">
                                LRN <?= htmlspecialchars($s['lrn']??'—') ?>
                                <span style="opacity:.5">·</span>
                                ID <?= htmlspecialchars($s['student_id_number']??'—') ?>
                            </div>
                            <div class="tags">
                                <span class="tag accent"><?= htmlspecialchars($s['grade_level']??'—') ?></span>
                                <span class="tag"><?= htmlspecialchars($s['section']??'No Section') ?></span>
                                <?php if ($strand_txt !== ''): ?>
                                    <span class="tag" title="<?= htmlspecialchars($strand_txt) ?>">
                                        <?= htmlspecialchars(strlen($strand_txt) > 24 ? substr($strand_txt, 0, 22) . '…' : $strand_txt) ?>
                                    </span>
                                    <span class="tag initials" title="Strand initials"><?= htmlspecialchars($strand_init) ?></span>
                                <?php else: ?>
                                    <span class="tag">—</span>
                                <?php endif; ?>
                                <?php if ($show_promote_mode && $isPromotable): ?>
                                    <span class="tag promotable">
                                        <i class="bi bi-arrow-up-circle"></i>
                                        <?= $s['grade_level']=='Grade 11' ? '→ Grade 12' : '→ Graduate' ?>
                                    </span>
                                <?php endif; ?>
                                <span class="pill <?= $pillClass ?>"><?= htmlspecialchars($st) ?></span>
                            </div>
                            <div class="mt-2">
                                <a href="view_student.php?id=<?= (int)$s['student_id'] ?>" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye me-1"></i> View Profile
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-center mt-4">
                        <div class="pagination">
                            <a href="<?= $base_url ?>&page=1" class="btn btn-sm <?= $page <= 1 ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-left"></i></a>
                            <a href="<?= $base_url ?>&page=<?= max(1,$page-1) ?>" class="btn btn-sm <?= $page <= 1 ? 'disabled' : '' ?>">Prev</a>
                            <span class="page-info">Page <?= $page ?> of <?= $total_pages ?></span>
                            <a href="<?= $base_url ?>&page=<?= min($total_pages,$page+1) ?>" class="btn btn-sm <?= $page >= $total_pages ? 'disabled' : '' ?>">Next</a>
                            <a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="btn btn-sm <?= $page >= $total_pages ? 'disabled' : '' ?>"><i class="bi bi-chevron-double-right"></i></a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-people"></i></div>
                    <div class="empty-title">No students found</div>
                    <div class="empty-sub">Try adjusting your filters or search terms.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════
     PROMOTE MODAL
     ══════════════════════════════════════════════════ -->
<div class="modal fade promote-modal" id="promoteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="student_profile.php" id="promoteFormMain">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="promote_students" value="1">
                <input type="hidden" name="school_year" id="pm_sy">
                <input type="hidden" name="promote_type" id="pm_type" value="both">

                <div class="pm-hero">
                    <button type="button" class="close-btn" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i></button>
                    <div class="pm-hero-inner">
                        <div class="pm-hero-icon"><i class="bi bi-rocket-takeoff"></i></div>
                        <div class="pm-hero-info">
                            <h4>Promote Students</h4>
                            <p id="pm_hero_sy">School Year —</p>
                        </div>
                    </div>
                </div>

                <div class="pm-body">
                    <div class="pm-section">
                        <div class="pm-section-label"><i class="bi bi-diagram-2"></i> Select promotion mode</div>
                        <div class="pm-mode-grid">
                            <label class="pm-mode-option" data-mode="grade11">
                                <input type="radio" name="_pm_mode" value="grade11">
                                <div class="pm-mode-icon"><i class="bi bi-arrow-up-circle"></i></div>
                                <div class="pm-mode-body">
                                    <div class="pm-mode-title">Grade 11 → 12</div>
                                    <div class="pm-mode-desc">Advance active Grade 11 students.</div>
                                    <div class="pm-mode-count" id="pm_count_11">0 students</div>
                                </div>
                            </label>
                            <label class="pm-mode-option" data-mode="grade12">
                                <input type="radio" name="_pm_mode" value="grade12">
                                <div class="pm-mode-icon"><i class="bi bi-mortarboard"></i></div>
                                <div class="pm-mode-body">
                                    <div class="pm-mode-title">Grade 12 → Graduate</div>
                                    <div class="pm-mode-desc">Mark Grade 12 students as Graduated.</div>
                                    <div class="pm-mode-count" id="pm_count_12">0 students</div>
                                </div>
                            </label>
                        </div>
                        <div style="margin-top:.6rem">
                            <label class="pm-mode-option" data-mode="both" style="display:flex">
                                <input type="radio" name="_pm_mode" value="both">
                                <div class="pm-mode-icon"><i class="bi bi-rocket-takeoff"></i></div>
                                <div class="pm-mode-body">
                                    <div class="pm-mode-title">Promote Both</div>
                                    <div class="pm-mode-desc">Advance Grade 11 <em>and</em> graduate Grade 12 in one operation.</div>
                                    <div class="pm-mode-count" id="pm_count_both">0 students</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="pm-section">
                        <div class="pm-section-label"><i class="bi bi-calendar-range"></i> New School Year</div>
                        <div class="pm-field">
                            <input type="text" id="pm_new_sy" name="new_school_year" placeholder="e.g. 2027-2028" required>
                            <div class="hint">Suggested: <strong id="pm_suggested">—</strong></div>
                        </div>
                    </div>

                    <div class="pm-section">
                        <div class="pm-preview" id="pm_preview">
                            <div class="pm-preview-head"><i class="bi bi-eye"></i> What will happen</div>
                            <div class="pm-preview-line">
                                <span>Grade 11 students advancing to Grade 12</span>
                                <span class="num" id="pm_preview_11">0</span>
                            </div>
                            <div class="pm-preview-line">
                                <span>Grade 12 students marked as Graduated</span>
                                <span class="num" id="pm_preview_12">0</span>
                            </div>
                        </div>

                        <div class="pm-warn">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <div>
                                <strong>This action cannot be undone.</strong>
                                Students' grade levels and statuses will be permanently changed.
                                You can still edit individual students afterwards, but there is no bulk undo.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pm-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn pm-btn-confirm" id="pm_confirm">
                        <i class="bi bi-check-lg me-1"></i> Confirm Promotion
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════
     AUTO-ASSIGN MODAL
     ══════════════════════════════════════════════════ -->
<div class="modal fade aa-modal" id="autoAssignModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="student_profile.php" id="autoAssignForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="auto_assign" value="1">
                <input type="hidden" name="grade_level" id="aa_grade">
                <input type="hidden" name="school_year" id="aa_sy">

                <div class="aa-hero">
                    <div class="aa-hero-icon"><i class="bi bi-magic"></i></div>
                    <h4>Auto Assign Sections</h4>
                    <p>Distribute students into balanced sections.</p>
                </div>

                <div class="aa-body">
                    <div class="pm-field">
                        <label>Target section size</label>
                        <input type="number" name="section_size" id="aa_size" value="35" min="5" max="60" required>
                        <div class="hint">Recommended: 30–40 students per section.</div>
                    </div>

                    <label class="aa-check" for="aa_replace">
                        <input type="checkbox" name="replace_sections" id="aa_replace" value="1">
                        <span class="aa-check-body">
                            <span class="aa-check-title">Replace existing sections</span>
                            <span class="aa-check-desc">By default, strands that already have sections are skipped. Check this to reassign everyone.</span>
                        </span>
                    </label>
                </div>

                <div class="pm-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn pm-btn-confirm">
                        <i class="bi bi-check-lg me-1"></i> Assign Sections
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);backdrop-filter:blur(2px);z-index:199"
     onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
    const m = document.cookie.match(/admin_theme=([^;]+)/);
    st(m ? decodeURIComponent(m[1]) : 'light');
})();
tb.addEventListener('click', () => st(h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));

// ═══════════════════════════════════════════════
// PROMOTE MODAL
// ═══════════════════════════════════════════════
const promoteModalEl = document.getElementById('promoteModal');
const promoteModal   = new bootstrap.Modal(promoteModalEl);

document.getElementById('openPromoteBtn').addEventListener('click', function() {
    const sy      = this.dataset.sy;
    const g11     = parseInt(this.dataset.g11, 10) || 0;
    const g12     = parseInt(this.dataset.g12, 10) || 0;
    const nextSy  = this.dataset.nextSy || '';

    if (!sy) return;

    document.getElementById('pm_hero_sy').textContent = 'School Year ' + sy;
    document.getElementById('pm_sy').value = sy;

    document.getElementById('pm_count_11').textContent = g11 + ' student' + (g11 === 1 ? '' : 's');
    document.getElementById('pm_count_12').textContent = g12 + ' student' + (g12 === 1 ? '' : 's');
    document.getElementById('pm_count_both').textContent = (g11 + g12) + ' student' + ((g11 + g12) === 1 ? '' : 's');

    document.getElementById('pm_suggested').textContent = nextSy || '—';
    document.getElementById('pm_new_sy').value = nextSy || '';

    let defaultMode = 'both';
    if (g11 === 0 && g12 > 0) defaultMode = 'grade12';
    else if (g12 === 0 && g11 > 0) defaultMode = 'grade11';

    setPromoteMode(defaultMode, g11, g12);

    document.querySelectorAll('.pm-mode-option').forEach(opt => {
        const mode = opt.dataset.mode;
        const count = mode === 'grade11' ? g11 : mode === 'grade12' ? g12 : g11 + g12;
        const radio = opt.querySelector('input');
        if (count === 0) {
            opt.style.opacity = '.45';
            opt.style.pointerEvents = 'none';
            radio.disabled = true;
        } else {
            opt.style.opacity = '';
            opt.style.pointerEvents = '';
            radio.disabled = false;
        }
    });

    promoteModal.show();
});

function setPromoteMode(mode, g11, g12) {
    document.getElementById('pm_type').value = mode;
    document.querySelectorAll('.pm-mode-option').forEach(o => {
        o.classList.toggle('selected', o.dataset.mode === mode);
        const r = o.querySelector('input');
        if (r) r.checked = (o.dataset.mode === mode);
    });

    let p11 = 0, p12 = 0;
    if (mode === 'grade11' || mode === 'both') p11 = g11;
    if (mode === 'grade12' || mode === 'both') p12 = g12;
    document.getElementById('pm_preview_11').textContent = p11;
    document.getElementById('pm_preview_12').textContent = p12;
}

document.querySelectorAll('.pm-mode-option').forEach(opt => {
    opt.addEventListener('click', function(e) {
        e.preventDefault();
        const btn = document.getElementById('openPromoteBtn');
        const g11 = parseInt(btn.dataset.g11, 10) || 0;
        const g12 = parseInt(btn.dataset.g12, 10) || 0;
        setPromoteMode(this.dataset.mode, g11, g12);
    });
});

document.getElementById('promoteFormMain').addEventListener('submit', function(e) {
    const sy    = document.getElementById('pm_sy').value;
    const nsy   = document.getElementById('pm_new_sy').value.trim();

    if (!nsy) {
        e.preventDefault();
        Swal.fire({ icon: 'warning', title: 'Missing School Year', text: 'Please enter the new school year.', confirmButtonColor: '#1565c0' });
        return;
    }
    if (nsy === sy) {
        e.preventDefault();
        Swal.fire({ icon: 'warning', title: 'Same School Year', text: 'New school year must be different from the current one.', confirmButtonColor: '#1565c0' });
        return;
    }

    const btn = document.getElementById('pm_confirm');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
});

// ═══════════════════════════════════════════════
// AUTO-ASSIGN MODAL
// ═══════════════════════════════════════════════
const aaModalEl = document.getElementById('autoAssignModal');
const aaModal   = new bootstrap.Modal(aaModalEl);

document.getElementById('openAutoAssignBtn').addEventListener('click', function() {
    const grade = this.dataset.grade;
    const sy    = this.dataset.sy;

    if (!grade || !sy) {
        Swal.fire({
            icon: 'warning',
            title: 'Filters required',
            html: 'Please select a <strong>Grade Level</strong> and <strong>School Year</strong> in the filter bar first, then apply.',
            confirmButtonColor: '#1565c0'
        });
        return;
    }

    document.getElementById('aa_grade').value = grade;
    document.getElementById('aa_sy').value    = sy;
    document.getElementById('aa_size').value  = '35';
    document.getElementById('aa_replace').checked = false;

    aaModal.show();
});

// ═══════════════════════════════════════════════
// SELECTION (promote mode)
// ═══════════════════════════════════════════════
function updateSelection() {
    const c = document.querySelectorAll('.student-checkbox:checked');
    const n = c.length;
    const b = document.getElementById('promoteBar');
    const t = document.getElementById('promoteSelectedBtn');
    const l = document.getElementById('selectedCount');
    const p = document.getElementById('selectedIdsContainer');

    if (b) b.style.display = n > 0 ? 'flex' : 'none';
    if (l) l.textContent = n;
    if (t) t.disabled = n === 0;
    if (p) {
        p.innerHTML = '';
        c.forEach(cb => {
            const i = document.createElement('input');
            i.type = 'hidden';
            i.name = 'selected_students[]';
            i.value = cb.value;
            p.appendChild(i);
        });
    }
    document.querySelectorAll('.student-checkbox').forEach(cb => {
        const cd = cb.closest('.student-card');
        if (cd) cd.classList.toggle('selected', cb.checked);
    });
}

function selectAllVisible() {
    document.querySelectorAll('.student-card').forEach(card => {
        if (card.style.display === 'none') return;
        const cb = card.querySelector('.student-checkbox');
        if (cb) cb.checked = true;
    });
    updateSelection();
}

function deselectAll() {
    document.querySelectorAll('.student-checkbox').forEach(cb => { cb.checked = false; });
    updateSelection();
}

function submitPromoteSelected() {
    const n = document.querySelectorAll('.student-checkbox:checked').length;
    if (n === 0) { alert('No students selected.'); return; }
    const nsy = document.getElementById('promoteNewSY').value.trim();
    if (!nsy) { alert('Please enter the new school year.'); return; }
    document.getElementById('promoteNewSYHidden').value = nsy;
    if (confirm(`Promote ${n} selected student(s) to new SY ${nsy}?\n\n⚠️ This cannot be undone.`)) {
        document.getElementById('promoteForm').submit();
    }
}

function applySelectionFilters() {
    const sectionEl = document.getElementById('sfSection');
    const gradeEl   = document.getElementById('sfGrade');
    const searchEl  = document.getElementById('sfStudent');
    if (!sectionEl || !gradeEl || !searchEl) return;

    const section = sectionEl.value.trim().toLowerCase();
    const grade   = gradeEl.value.trim().toLowerCase();
    const query   = searchEl.value.trim().toLowerCase();

    const cards = document.querySelectorAll('.student-card');
    let visible = 0;

    cards.forEach(card => {
        const cardSection = (card.dataset.section || '').toLowerCase();
        const cardGrade   = (card.dataset.grade   || '').toLowerCase();
        const haystack    = (card.dataset.search  || '').toLowerCase();

        let show = true;
        if (section && cardSection !== section)   show = false;
        if (grade   && cardGrade   !== grade)     show = false;
        if (query   && !haystack.includes(query)) show = false;

        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    const total     = cards.length;
    const counterEl = document.getElementById('visibleCounter');
    const summaryEl = document.getElementById('sfSummary');

    if (counterEl) {
        counterEl.textContent = visible + ' of ' + total + ' visible';
    }

    if (summaryEl) {
        const filters = [];
        if (section) filters.push('Section: ' + sectionEl.options[sectionEl.selectedIndex].text);
        if (grade)   filters.push('Grade: '   + gradeEl.options[gradeEl.selectedIndex].text);
        if (query)   filters.push('Search: "' + searchEl.value.trim() + '"');

        if (filters.length === 0) {
            summaryEl.textContent = 'Showing all ' + total + ' student' + (total === 1 ? '' : 's');
            summaryEl.classList.add('muted');
        } else {
            summaryEl.textContent = 'Showing ' + visible + ' of ' + total + ' — ' + filters.join(' · ');
            summaryEl.classList.remove('muted');
        }
    }
}

function clearSelectionFilters() {
    const sectionEl = document.getElementById('sfSection');
    const gradeEl   = document.getElementById('sfGrade');
    const searchEl  = document.getElementById('sfStudent');
    if (sectionEl) sectionEl.value = '';
    if (gradeEl)   gradeEl.value   = '';
    if (searchEl)  searchEl.value  = '';
    applySelectionFilters();
}

document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('selectionFilters')) {
        applySelectionFilters();
    }
    updateSelection();
});
</script>
</body>
</html>