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

// ────────────────────────────────────────────────
// UPDATE SCHOOL YEAR AFTER PROMOTION
// (does not touch status, no history needed)
// ────────────────────────────────────────────────
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

// ────────────────────────────────────────────────
// PROMOTE SELECTED STUDENTS
// ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_selected'])) {
    verify_csrf();

    $selected_ids = $_POST['selected_students'] ?? [];
    $promote_sy = trim($_POST['promote_school_year'] ?? '');
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
            $promoted_11 = 0;
            $graduated_12 = 0;
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
                        // Grade 11 → 12 is not a status change; no history row.
                    } elseif ($result['grade_level'] === 'Grade 12') {
                        $old_status = $result['status'] ?? 'Active';

                        $upd = $conn->prepare("UPDATE enrollment_form SET status = 'Graduated', school_year = ? WHERE student_id = ? AND school_year = ? AND grade_level = 'Grade 12'");
                        $upd->bind_param("sis", $new_school_year, $sid, $promote_sy);
                        $upd->execute();
                        if ($upd->affected_rows > 0) {
                            $graduated_12++;

                            // ═══ STATUS HISTORY ═══
                            $hist = $conn->prepare("
                                INSERT INTO student_status_history
                                    (student_id, old_status, new_status, reason, changed_by)
                                VALUES (?, ?, 'Graduated', ?, ?)
                            ");
                            $reason = "Graduated via batch promotion ($promote_sy → $new_school_year)";
                            $hist->bind_param("issi", $sid, $old_status, $reason, $admin_id);
                            $hist->execute();
                            $hist->close();
                            // ═════════════════════
                        }
                        $upd->close();
                    }
                }
            }

            $conn->commit();

            audit_log($conn, 'student.promote', ['type'=>'batch','from_sy'=>$promote_sy,'to_sy'=>$new_school_year,'promoted'=>$promoted_11,'graduated'=>$graduated_12]);

            $msg = [];
            if ($promoted_11 > 0) $msg[] = "$promoted_11 → Grade 12";
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

// ────────────────────────────────────────────────
// PROMOTE ALL STUDENTS
// ────────────────────────────────────────────────
if (isset($_GET['promote_students']) && isset($_GET['school_year']) && isset($_GET['promote_type'])) {
    $promote_sy = trim($_GET['school_year']);
    $promote_type = trim($_GET['promote_type']);
    $new_school_year = trim($_GET['new_school_year'] ?? '');

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
                // ═══ STATUS HISTORY ═══
                // Capture affected students BEFORE the bulk UPDATE, since a
                // single UPDATE cannot give us per-student old_status values.
                $aff = $conn->prepare("SELECT DISTINCT student_id FROM enrollment_form WHERE grade_level = 'Grade 12' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $aff->bind_param("s", $promote_sy);
                $aff->execute();
                $affected_ids = array_column($aff->get_result()->fetch_all(MYSQLI_ASSOC), 'student_id');
                $aff->close();
                // ═════════════════════

                $stmt = $conn->prepare("UPDATE enrollment_form SET status = 'Graduated', school_year = ? WHERE grade_level = 'Grade 12' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $stmt->bind_param("ss", $new_school_year, $promote_sy);
                $stmt->execute();
                $total_promoted = $stmt->affected_rows;
                $stmt->close();

                // ═══ STATUS HISTORY ═══
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
                // ═════════════════════

                $_SESSION['success'] = $total_promoted > 0 ? "$total_promoted student(s) graduated. School year updated to $new_school_year." : "No active Grade 12 students found.";
            } elseif ($promote_type === 'both') {
                $stmt = $conn->prepare("UPDATE enrollment_form SET grade_level = 'Grade 12', school_year = ? WHERE grade_level = 'Grade 11' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $stmt->bind_param("ss", $new_school_year, $promote_sy); $stmt->execute(); $p11 = $stmt->affected_rows; $stmt->close();

                // ═══ STATUS HISTORY ═══
                $aff = $conn->prepare("SELECT DISTINCT student_id FROM enrollment_form WHERE grade_level = 'Grade 12' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $aff->bind_param("s", $promote_sy);
                $aff->execute();
                $affected_ids = array_column($aff->get_result()->fetch_all(MYSQLI_ASSOC), 'student_id');
                $aff->close();
                // ═════════════════════

                $stmt = $conn->prepare("UPDATE enrollment_form SET status = 'Graduated', school_year = ? WHERE grade_level = 'Grade 12' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $stmt->bind_param("ss", $new_school_year, $promote_sy); $stmt->execute(); $p12 = $stmt->affected_rows; $stmt->close();

                // ═══ STATUS HISTORY ═══
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
                // ═════════════════════

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

// ────────────────────────────────────────────────
// FILTER INPUTS
// ────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$grade_level  = trim($_GET['grade_level'] ?? '');
$school_year  = trim($_GET['school_year'] ?? '');
$status       = trim($_GET['status'] ?? '');

// ────────────────────────────────────────────────
// PAGINATION
// ────────────────────────────────────────────────
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// ────────────────────────────────────────────────
// AUTO SECTION ASSIGNMENT
// ────────────────────────────────────────────────
if (isset($_GET['auto_assign'])) {
    if (empty($grade_level) || empty($school_year)) {
        $_SESSION['error'] = "Please select Grade Level and School Year to auto-assign sections.";
    } else {
        $target_size      = max(5, min(60, (int)($_GET['section_size'] ?? 35)));
        $replace_existing = !empty($_GET['replace_sections']);
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

// ────────────────────────────────────────────────
// MAIN STUDENTS QUERY
// ────────────────────────────────────────────────
$sql = "SELECT s.student_id, s.student_id_number, s.lrn, s.last_name, s.first_name, s.middle_name, s.ext_name, s.sex, s.age, e.grade_level, e.school_year, e.section, e.strand, e.track, e.program, e.term, COALESCE(e.status, 'Active') AS status, COUNT(*) OVER() as total_count FROM students_info s INNER JOIN enrollment_form e ON s.student_id = e.student_id WHERE 1=1";

$params = []; $types = "";
if ($search !== '') { $sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)"; $like = "%$search%"; $params = [$like,$like,$like,$like]; $types = "ssss"; }
if ($grade_level !== '') { $sql .= " AND e.grade_level = ?"; $params[] = $grade_level; $types .= "s"; }
if ($school_year !== '') { $sql .= " AND e.school_year = ?"; $params[] = $school_year; $types .= "s"; }
if ($status !== '' && $status !== 'All') { $sql .= " AND COALESCE(e.status, 'Active') = ?"; $params[] = $status; $types .= "s"; }
$sql .= " ORDER BY s.last_name ASC, s.first_name ASC LIMIT ? OFFSET ?";
$params[] = $per_page; $types .= "i";
$params[] = $offset; $types .= "i";

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
if ($search !== '') { $gsql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ?)"; $gp = [$like,$like,$like]; $gt = "sss"; }
if ($grade_level !== '') { $gsql .= " AND e.grade_level = ?"; $gp[] = $grade_level; $gt .= "s"; }
if ($school_year !== '') { $gsql .= " AND e.school_year = ?"; $gp[] = $school_year; $gt .= "s"; }
if ($status !== '' && $status !== 'All') { $gsql .= " AND COALESCE(e.status, 'Active') = ?"; $gp[] = $status; $gt .= "s"; }
$gs = $conn->prepare($gsql); if(!empty($gp)) $gs->bind_param($gt,...$gp); $gs->execute(); $st = $gs->get_result()->fetch_assoc(); $gs->close();
$boys = (int)($st['boys']??0); $girls = (int)($st['girls']??0);

// Section Stats
$ssql = "SELECT e.section, COUNT(*) AS total, SUM(CASE WHEN UPPER(TRIM(s.sex)) IN ('MALE','M') THEN 1 ELSE 0 END) AS boys, SUM(CASE WHEN UPPER(TRIM(s.sex)) IN ('FEMALE','F') THEN 1 ELSE 0 END) AS girls FROM enrollment_form e INNER JOIN students_info s ON e.student_id = s.student_id WHERE e.section IS NOT NULL AND e.section != ''";
$sp = []; $stt = "";
if ($search !== '') { $ssql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ?)"; $sp = [$like,$like,$like]; $stt = "sss"; }
if ($grade_level !== '') { $ssql .= " AND e.grade_level = ?"; $sp[] = $grade_level; $stt .= "s"; }
if ($school_year !== '') { $ssql .= " AND e.school_year = ?"; $sp[] = $school_year; $stt .= "s"; }
if ($status !== '' && $status !== 'All') { $ssql .= " AND COALESCE(e.status, 'Active') = ?"; $sp[] = $status; $stt .= "s"; }
$ssql .= " GROUP BY e.section ORDER BY e.section ASC";
$ss = $conn->prepare($ssql); if(!empty($sp)) $ss->bind_param($stt,...$sp); $ss->execute(); $all_sections = $ss->get_result()->fetch_all(MYSQLI_ASSOC); $ss->close();

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

    <style>
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

        .stat-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
            gap:1rem;margin-bottom:1.5rem;
        }
        .stat-card{
            position:relative;
            background:var(--surface);border-radius:var(--radius-lg);
            border:1px solid var(--border);box-shadow:var(--shadow-sm);
            padding:1.1rem 1.25rem 1.1rem 1.4rem;
            display:flex;align-items:center;gap:1rem;
            transition:transform .2s, box-shadow .2s, border-color .2s;
            overflow:hidden;
        }
        .stat-card::before{
            content:'';position:absolute;left:0;top:0;bottom:0;width:4px;
            background:var(--accent);
        }
        .stat-card.tone-blue::before{background:var(--accent)}
        .stat-card.tone-green::before{background:var(--green)}
        .stat-card.tone-red::before{background:var(--red)}
        .stat-card.tone-purple::before{background:var(--purple)}
        .stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);border-color:var(--border-strong)}

        .stat-icon{
            width:42px;height:42px;border-radius:12px;
            display:flex;align-items:center;justify-content:center;
            font-size:1.1rem;flex-shrink:0;
            background:var(--accent-soft);color:var(--accent);
        }
        .tone-green  .stat-icon{background:var(--green-soft);color:var(--green)}
        .tone-red    .stat-icon{background:var(--red-soft);color:var(--red)}
        .tone-purple .stat-icon{background:var(--purple-soft);color:var(--purple)}

        .stat-info{min-width:0;flex:1}
        .stat-info .stat-value{
            font-size:1.55rem;font-weight:700;line-height:1.1;
            letter-spacing:-.02em;font-variant-numeric:tabular-nums;
        }
        .stat-info .stat-label{
            font-size:.7rem;color:var(--text-2);text-transform:uppercase;
            letter-spacing:.6px;margin-top:.2rem;font-weight:600;
        }

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

        .filter-bar{
            display:grid;
            grid-template-columns:minmax(200px,1.6fr) minmax(140px,1fr) minmax(140px,1fr) minmax(140px,1fr) auto;
            gap:.75rem;align-items:end;width:100%;
        }
        .filter-bar .field{display:flex;flex-direction:column;gap:.3rem;min-width:0}
        .filter-bar label{
            font-size:.68rem;font-weight:700;color:var(--text-2);
            text-transform:uppercase;letter-spacing:.5px;
        }
        .filter-bar input,.filter-bar select{
            background:var(--surface-2);border:1px solid var(--border);
            border-radius:10px;padding:.5rem .8rem;font-size:.85rem;
            color:var(--text);font-family:inherit;width:100%;
            transition:border-color .15s, box-shadow .15s, background .15s;
        }
        .filter-bar input::placeholder{color:var(--text-3)}
        .filter-bar input:focus,.filter-bar select:focus{
            border-color:var(--accent);background:var(--surface);
            box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
            outline:none;
        }
        .filter-bar .actions{display:flex;gap:.5rem;flex-wrap:nowrap}
        .filter-extra{
            display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;
            margin-top:.85rem;padding-top:.85rem;border-top:1px dashed var(--border);
        }
        .promote-hint{
            font-size:.78rem;color:var(--text-2);
            display:flex;align-items:center;gap:.35rem;
            padding:.5rem .75rem;background:var(--surface-2);
            border-radius:10px;border:1px solid var(--border);
        }

        .table-scroll{overflow-x:auto}
        .table-admin{width:100%;border-collapse:separate;border-spacing:0}
        .table-admin thead th{
            background:var(--surface-2);
            font-size:.68rem;font-weight:700;color:var(--text-2);
            text-transform:uppercase;letter-spacing:.6px;
            padding:.7rem 1rem;text-align:left;
            border-bottom:1px solid var(--border);
            white-space:nowrap;
        }
        .table-admin tbody td{
            padding:.65rem 1rem;border-bottom:1px solid var(--border);
            font-size:.85rem;vertical-align:middle;
            transition:background .12s;
        }
        .table-admin tbody tr:hover td{background:color-mix(in srgb, var(--accent) 4%, transparent)}
        .table-admin tbody tr:last-child td{border-bottom:none}
        .cell-strong{font-weight:600;color:var(--text)}
        .cell-muted{color:var(--text-2);font-size:.82rem}
        .cell-center{text-align:center}
        .cell-num{
            font-variant-numeric:tabular-nums;font-weight:600;
            color:var(--text);font-size:.85rem;
        }
        .cell-male{color:var(--blue);font-weight:600;font-variant-numeric:tabular-nums}
        .cell-female{color:var(--red);font-weight:600;font-variant-numeric:tabular-nums}

        .student-grid{
            display:grid;
            grid-template-columns:repeat(auto-fill,minmax(340px,1fr));
            gap:1rem;
        }
        .student-card{
            background:var(--surface);border-radius:var(--radius-lg);
            border:1px solid var(--border);box-shadow:var(--shadow-xs);
            padding:1.1rem 1.15rem;
            display:flex;gap:.9rem;align-items:flex-start;
            position:relative;
            transition:transform .18s, box-shadow .18s, border-color .18s;
        }
        .student-card:hover{
            transform:translateY(-2px);
            box-shadow:var(--shadow-md);
            border-color:var(--border-strong);
        }
        .student-card.selected{
            border-color:var(--amber);
            box-shadow:0 0 0 3px color-mix(in srgb, var(--amber) 22%, transparent);
            background:color-mix(in srgb, var(--amber) 5%, var(--surface));
        }
        .student-card .select-check{
            position:absolute;top:.85rem;right:.85rem;
            width:20px;height:20px;
            accent-color:var(--amber);cursor:pointer;z-index:2;
        }

        .student-avatar{
            width:48px;height:48px;border-radius:50%;
            background:linear-gradient(135deg,var(--accent),var(--accent-2));
            display:flex;align-items:center;justify-content:center;
            color:#fff;font-weight:700;font-size:1.05rem;flex-shrink:0;
            box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 15%, transparent);
        }

        .student-info{flex:1;min-width:0}
        .student-info h4{
            font-size:.92rem;font-weight:600;margin:0 0 .15rem;
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
            padding-right:1.6rem;color:var(--text);
        }
        .student-info .meta{
            font-size:.74rem;color:var(--text-2);
            font-variant-numeric:tabular-nums;
        }
        .student-info .tags{
            display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.6rem;
        }

        .tag{
            display:inline-flex;align-items:center;gap:.25rem;
            padding:.18rem .55rem;border-radius:999px;
            font-size:.68rem;font-weight:600;
            background:var(--surface-2);color:var(--text-2);
            border:1px solid var(--border);
            white-space:nowrap;
        }
        .tag.accent{
            background:var(--accent-soft);color:var(--accent);
            border-color:color-mix(in srgb, var(--accent) 22%, transparent);
        }
        .tag.promotable{
            background:var(--amber-soft);color:var(--amber);
            border-color:color-mix(in srgb, var(--amber) 25%, transparent);
        }
        .tag.initials{
            background:var(--purple-soft);color:var(--purple);
            border-color:color-mix(in srgb, var(--purple) 22%, transparent);
            font-weight:700;letter-spacing:.4px;
        }

        .pill{
            display:inline-flex;align-items:center;gap:.4rem;
            padding:.22rem .6rem;border-radius:999px;
            font-size:.7rem;font-weight:600;
            border:1px solid transparent;
        }
        .pill::before{
            content:'';width:6px;height:6px;border-radius:50%;
            background:currentColor;opacity:.9;
        }
        .pill.success{background:var(--green-soft);color:var(--green)}
        .pill.warning{background:var(--amber-soft);color:var(--amber)}
        .pill.danger {background:var(--red-soft);  color:var(--red)}
        .pill.muted  {background:var(--surface-2); color:var(--text-2)}

        .promote-bar{
            position:sticky;top:.75rem;z-index:50;
            background:linear-gradient(135deg,#fbbf24,#d97706);
            color:#fff;
            border-radius:var(--radius);
            padding:.85rem 1.15rem;
            margin-bottom:1rem;
            display:flex;align-items:center;justify-content:space-between;
            gap:1rem;flex-wrap:wrap;
            box-shadow:0 8px 24px -8px rgba(217,119,6,.6);
            border:1px solid rgba(255,255,255,.15);
        }
        [data-bs-theme="dark"] .promote-bar{
            background:linear-gradient(135deg,#92400e,#78350f);
            border-color:rgba(251,191,36,.35);
        }
        .promote-bar .selected-count{font-weight:700;font-size:1.15rem;line-height:1}
        .promote-bar .selected-label{font-size:.78rem;opacity:.9;margin-top:.15rem}
        .promote-bar input.form-control{
            background:rgba(255,255,255,.95);border:1px solid rgba(0,0,0,.08);
            color:#0f172a;font-weight:500;
        }
        .promote-bar input.form-control:focus{
            box-shadow:0 0 0 3px rgba(255,255,255,.35);border-color:transparent;
        }

        .btn-promote{
            background:#0f172a;color:#fff;border:none;font-weight:600;
            transition:all .15s;
        }
        .btn-promote:hover:not(:disabled){background:#1e293b;color:#fff;transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.25)}
        .btn-promote:disabled{opacity:.5;cursor:not-allowed}

        .btn{font-weight:500;border-radius:9px;font-size:.82rem;transition:all .15s}
        .btn-primary{background:var(--accent);border-color:var(--accent)}
        .btn-primary:hover{background:var(--accent-2);border-color:var(--accent-2)}
        .btn-outline-primary{color:var(--accent);border-color:var(--border-strong)}
        .btn-outline-primary:hover{background:var(--accent);border-color:var(--accent);color:#fff}
        .btn-outline-secondary{color:var(--text-2);border-color:var(--border)}
        .btn-outline-secondary:hover{background:var(--surface-2);color:var(--text);border-color:var(--border-strong)}
        .btn-outline-warning{color:var(--amber);border-color:color-mix(in srgb, var(--amber) 40%, transparent)}
        .btn-outline-warning:hover{background:var(--amber);color:#fff;border-color:var(--amber)}
        .btn-success{background:var(--green);border-color:var(--green)}
        .btn-success:hover{background:#047857;border-color:#047857}
        .btn-icon{
            width:32px;height:32px;padding:0;
            display:inline-flex;align-items:center;justify-content:center;
            border-radius:9px;
        }
        .theme-btn{
            width:38px;height:38px;border-radius:10px;
            border:1px solid var(--border);background:var(--surface);
            color:var(--text-2);cursor:pointer;
            display:flex;align-items:center;justify-content:center;
            transition:all .15s;
        }
        .theme-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}

        .pagination{
            display:inline-flex;align-items:center;gap:.25rem;
            background:var(--surface);border:1px solid var(--border);
            border-radius:10px;padding:.25rem;
        }
        .pagination .btn{
            border:none;color:var(--text-2);
            min-width:34px;padding:.35rem .55rem;
        }
        .pagination .btn:hover:not(:disabled){background:var(--surface-2);color:var(--text)}
        .pagination .btn:disabled{opacity:.4;cursor:not-allowed}
        .pagination .page-info{
            font-size:.78rem;color:var(--text-2);
            padding:0 .65rem;font-weight:500;
        }

        .empty{
            padding:3rem 1.5rem;text-align:center;color:var(--text-2);
        }
        .empty .empty-icon{
            width:64px;height:64px;border-radius:50%;
            background:var(--surface-2);color:var(--text-3);
            display:inline-flex;align-items:center;justify-content:center;
            font-size:1.6rem;margin-bottom:1rem;
        }
        .empty .empty-title{font-weight:600;color:var(--text);margin-bottom:.25rem}
        .empty .empty-sub{font-size:.85rem;color:var(--text-2)}

        .flash{
            display:flex;align-items:center;gap:.65rem;
            padding:.75rem 1rem;border-radius:var(--radius);
            margin-bottom:1rem;font-size:.88rem;font-weight:500;
            border:1px solid transparent;
        }
        .flash.success{background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb, var(--green) 25%, transparent)}
        .flash.error  {background:var(--red-soft);  color:var(--red);  border-color:color-mix(in srgb, var(--red) 25%, transparent)}
        .flash.info   {background:var(--accent-soft);color:var(--accent);border-color:color-mix(in srgb, var(--accent) 25%, transparent)}
        .flash .btn-close{margin-left:auto;opacity:.6}

        .modal-content{border-radius:var(--radius-lg);border:none;box-shadow:var(--shadow-lg)}
        .modal-header.promote-head{
            background:linear-gradient(135deg,#fbbf24,#d97706);color:#fff;
            border-top-left-radius:var(--radius-lg);border-top-right-radius:var(--radius-lg);
            padding:1rem 1.25rem;border-bottom:none;
        }
        .modal-header.promote-head .btn-close{filter:brightness(0) invert(1);opacity:.85}
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

        @media (max-width:1280px){
            .filter-bar{grid-template-columns:minmax(200px,1.6fr) 1fr 1fr auto}
            .filter-bar .field:nth-child(4){grid-column:span 1}
            .filter-bar .actions{grid-column:1 / -1;justify-content:flex-end;margin-top:.25rem}
        }
        @media (max-width:1024px){
            .sidebar{transform:translateX(-100%)}
            .sidebar.open{transform:translateX(0)}
            .main-content{margin-left:0}
            .menu-toggle{display:flex}
        }
        @media (max-width:900px){
            .filter-bar{grid-template-columns:1fr 1fr}
            .filter-bar .actions{grid-column:span 2;justify-content:stretch}
            .filter-bar .actions .btn{flex:1}
        }
        @media (max-width:640px){
            .stat-grid{grid-template-columns:repeat(2,1fr);gap:.75rem}
            .stat-card{padding:1rem 1rem 1rem 1.1rem;gap:.75rem}
            .stat-icon{width:36px;height:36px;font-size:.95rem}
            .stat-info .stat-value{font-size:1.3rem}
            .filter-bar{grid-template-columns:1fr}
            .filter-bar .actions{grid-column:span 1}
            .student-grid{grid-template-columns:1fr}
            .promote-bar{flex-direction:column;align-items:stretch;text-align:center}
            .promote-bar .d-flex{justify-content:center;flex-wrap:wrap}
        }
    </style>
</head>
<body>

<?php
    $__current = basename($_SERVER['PHP_SELF']);
    $__super   = (($_SESSION['admin_role'] ?? '') === 'superadmin');
    // Student sub-pages all highlight "Students"
    $__student_pages = ['student_profile.php','view_student.php','edit_student.php','export_students.php','sections_list.php','sf10.php','student_documents.php'];
    $__act = in_array($__current, $__student_pages, true) ? 'student_profile.php' : $__current;
    $__a   = fn($f) => $f === $__act ? ' class="active"' : '';
?>
<!-- ================= Sidebar ================= -->
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
        <button class="theme-btn" id="themeToggle" title="Toggle theme">
            <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
        </button>
    </div>

    <div class="stat-grid">
        <div class="stat-card tone-blue">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($total_students) ?></div>
                <div class="stat-label">Total Students</div>
            </div>
        </div>
        <div class="stat-card tone-blue">
            <div class="stat-icon"><i class="bi bi-gender-male"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($boys) ?></div>
                <div class="stat-label">Male</div>
            </div>
        </div>
        <div class="stat-card tone-red">
            <div class="stat-icon"><i class="bi bi-gender-female"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format($girls) ?></div>
                <div class="stat-label">Female</div>
            </div>
        </div>
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="bi bi-diagram-3"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format(count($all_sections)) ?></div>
                <div class="stat-label">Sections</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-funnel"></i> Filters</h3>
            <?php if ($search || $grade_level || $school_year || $status): ?>
                <span class="hint"><i class="bi bi-funnel-fill me-1"></i> Active filters applied</span>
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

            <?php if ($school_year && ($promote_preview_11 > 0 || $promote_preview_12 > 0)): ?>
                <div class="filter-extra">
                    <button type="submit" form="filterForm" name="auto_assign" value="1" class="btn btn-success btn-sm">
                        <i class="bi bi-magic me-1"></i> Auto Assign Sections
                    </button>

                    <div class="dropdown">
                        <button class="btn btn-promote btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-rocket-takeoff me-1"></i> Promote All
                            <span class="badge bg-light text-dark ms-1"><?= $promote_preview_11 + $promote_preview_12 ?></span>
                        </button>
                        <ul class="dropdown-menu shadow-lg border-0 rounded-3 p-2">
                            <?php if ($promote_preview_11 > 0): ?>
                                <li>
                                    <a class="dropdown-item rounded-2 py-2" href="#" onclick="showPromoteModal('grade11');return false;">
                                        <i class="bi bi-arrow-up-circle text-warning me-2"></i>
                                        Promote Grade 11 → 12
                                        <span class="badge bg-warning text-dark ms-2"><?= $promote_preview_11 ?></span>
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($promote_preview_12 > 0): ?>
                                <li>
                                    <a class="dropdown-item rounded-2 py-2" href="#" onclick="showPromoteModal('grade12');return false;">
                                        <i class="bi bi-mortarboard text-success me-2"></i>
                                        Graduate Grade 12
                                        <span class="badge bg-success ms-2"><?= $promote_preview_12 ?></span>
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php if ($promote_preview_11 > 0 && $promote_preview_12 > 0): ?>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li>
                                    <a class="dropdown-item rounded-2 py-2" href="#" onclick="showPromoteModal('both');return false;">
                                        <i class="bi bi-rocket-takeoff text-primary me-2"></i>
                                        Promote Both
                                        <span class="badge bg-primary ms-2"><?= $promote_preview_11 + $promote_preview_12 ?></span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <a href="?school_year=<?= urlencode($school_year) ?>&grade_level=<?= urlencode($grade_level) ?>&status=Active&show_promotable=1" class="btn btn-outline-warning btn-sm">
                        <i class="bi bi-list-check me-1"></i> Select Manually
                    </a>

                    <div class="promote-hint ms-auto">
                        <i class="bi bi-info-circle"></i>
                        <span>
                            <?php if ($promote_preview_11 > 0): ?><strong><?= $promote_preview_11 ?></strong> G11 → 12<?php endif; ?>
                            <?php if ($promote_preview_11 > 0 && $promote_preview_12 > 0): ?> · <?php endif; ?>
                            <?php if ($promote_preview_12 > 0): ?><strong><?= $promote_preview_12 ?></strong> G12 → Grad<?php endif; ?>
                            ready for <strong><?= htmlspecialchars($school_year) ?></strong>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if(!empty($all_sections) && !$show_promote_mode): ?>
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-diagram-3"></i> Sections <span class="hint">(<?= count($all_sections) ?>)</span></h3>
        </div>
        <div class="card-body no-padding">
            <div class="table-scroll">
                <table class="table-admin">
                    <thead>
                        <tr>
                            <th>Section</th>
                            <th class="cell-center" style="width:100px">Total</th>
                            <th class="cell-center" style="width:100px">Male</th>
                            <th class="cell-center" style="width:100px">Female</th>
                            <th class="cell-center" style="width:90px">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($all_sections as $sec): ?>
                        <tr>
                            <td class="cell-strong"><?= htmlspecialchars($sec['section']) ?></td>
                            <td class="cell-center cell-num"><?= (int)$sec['total'] ?></td>
                            <td class="cell-center cell-male"><?= (int)($sec['boys']??0) ?></td>
                            <td class="cell-center cell-female"><?= (int)($sec['girls']??0) ?></td>
                            <td class="cell-center">
                                <a href="sections_list.php?section=<?= urlencode($sec['section']) ?>"
                                   class="btn btn-icon btn-outline-primary"
                                   title="View section">
                                    <i class="bi bi-arrow-right-short" style="font-size:1.15rem"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3>
                <i class="bi bi-people"></i> Students
                <span class="hint">(<?= number_format($total_students) ?>)</span>
                <?php if ($show_promote_mode): ?>
                    <span class="tag promotable ms-2"><i class="bi bi-rocket-takeoff"></i> Selection mode</span>
                <?php endif; ?>
            </h3>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <?php if ($show_promote_mode): ?>
                    <button class="btn btn-outline-secondary btn-sm" onclick="selectAll()">
                        <i class="bi bi-check-all me-1"></i> Select All
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
                        <a href="<?= $base_url ?>&page=1" class="btn btn-sm <?= $page <= 1 ? 'disabled' : '' ?>" <?= $page <= 1 ? 'tabindex="-1"' : '' ?>>
                            <i class="bi bi-chevron-double-left"></i>
                        </a>
                        <a href="<?= $base_url ?>&page=<?= max(1,$page-1) ?>" class="btn btn-sm <?= $page <= 1 ? 'disabled' : '' ?>" <?= $page <= 1 ? 'tabindex="-1"' : '' ?>>
                            <i class="bi bi-chevron-left"></i>
                        </a>
                        <span class="page-info">Page <?= $page ?> of <?= $total_pages ?></span>
                        <a href="<?= $base_url ?>&page=<?= min($total_pages,$page+1) ?>" class="btn btn-sm <?= $page >= $total_pages ? 'disabled' : '' ?>" <?= $page >= $total_pages ? 'tabindex="-1"' : '' ?>>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                        <a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="btn btn-sm <?= $page >= $total_pages ? 'disabled' : '' ?>" <?= $page >= $total_pages ? 'tabindex="-1"' : '' ?>>
                            <i class="bi bi-chevron-double-right"></i>
                        </a>
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
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="promote_school_year" value="<?= htmlspecialchars($school_year) ?>">
                    <input type="hidden" name="new_school_year" id="promoteNewSYHidden">
                    <div id="selectedIdsContainer"></div>
                </form>
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
                    ?>
                    <div class="student-card" data-student-id="<?= (int)$s['student_id'] ?>" data-promotable="<?= $isPromotable ? '1' : '0' ?>">
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
                            <a href="<?= $base_url ?>&page=1" class="btn btn-sm <?= $page <= 1 ? 'disabled' : '' ?>" <?= $page <= 1 ? 'tabindex="-1"' : '' ?>>
                                <i class="bi bi-chevron-double-left"></i>
                            </a>
                            <a href="<?= $base_url ?>&page=<?= max(1,$page-1) ?>" class="btn btn-sm <?= $page <= 1 ? 'disabled' : '' ?>" <?= $page <= 1 ? 'tabindex="-1"' : '' ?>>
                                <i class="bi bi-chevron-left"></i> Prev
                            </a>
                            <span class="page-info">Page <?= $page ?> of <?= $total_pages ?></span>
                            <a href="<?= $base_url ?>&page=<?= min($total_pages,$page+1) ?>" class="btn btn-sm <?= $page >= $total_pages ? 'disabled' : '' ?>" <?= $page >= $total_pages ? 'tabindex="-1"' : '' ?>>
                                Next <i class="bi bi-chevron-right"></i>
                            </a>
                            <a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="btn btn-sm <?= $page >= $total_pages ? 'disabled' : '' ?>" <?= $page >= $total_pages ? 'tabindex="-1"' : '' ?>>
                                <i class="bi bi-chevron-double-right"></i>
                            </a>
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

<div class="modal fade" id="promoteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header promote-head">
                <h6 class="modal-title fw-bold"><i class="bi bi-rocket-takeoff me-2"></i>Promote Students</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="promoteActionType">
                <div class="mb-3">
                    <label class="form-label">Current School Year</label>
                    <input type="text" class="form-control" id="currentSY" readonly style="background:var(--surface-2)">
                </div>
                <div class="mb-3">
                    <label class="form-label">New School Year <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="newSY" placeholder="e.g., 2026-2027" required>
                    <div class="form-text mt-1">Suggested: <strong id="suggestedSY"></strong></div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Promotion Details</label>
                    <div id="promoteDetails" class="small" style="color:var(--text-2)"></div>
                </div>
                <div class="flash warning mb-0" style="background:var(--amber-soft);color:var(--amber);border-color:color-mix(in srgb, var(--amber) 25%, transparent)">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>This will promote students AND update their school year. Cannot be undone.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-promote btn-sm" id="confirmPromoteBtn">
                    <i class="bi bi-rocket-takeoff me-1"></i> Promote &amp; Update
                </button>
            </div>
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
    st(m ? m[1] : 'light');
})();
tb.addEventListener('click', () => st(h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));

// ---------- Promote modal ----------
function showPromoteModal(type) {
    const sy = '<?= addslashes($school_year) ?>';
    const g11 = <?= $promote_preview_11 ?>;
    const g12 = <?= $promote_preview_12 ?>;

    document.getElementById('currentSY').value = sy;
    document.getElementById('promoteActionType').value = type;

    const p = sy.split('-');
    if (p.length === 2) {
        const ns = (parseInt(p[0]) + 1) + '-' + (parseInt(p[1]) + 1);
        document.getElementById('suggestedSY').textContent = ns;
        document.getElementById('newSY').value = ns;
    }

    let d = '';
    if (type === 'grade11') d = `<strong>${g11}</strong> Grade 11 → Grade 12`;
    else if (type === 'grade12') d = `<strong>${g12}</strong> Grade 12 → Graduated`;
    else {
        if (g11 > 0) d += `<strong>${g11}</strong> Grade 11 → Grade 12<br>`;
        if (g12 > 0) d += `<strong>${g12}</strong> Grade 12 → Graduated`;
    }
    document.getElementById('promoteDetails').innerHTML = d;

    new bootstrap.Modal(document.getElementById('promoteConfirmModal')).show();
}

document.getElementById('confirmPromoteBtn').addEventListener('click', function () {
    const t = document.getElementById('promoteActionType').value;
    const o = document.getElementById('currentSY').value;
    const n = document.getElementById('newSY').value.trim();
    if (!n) { alert('Please enter the new school year.'); return; }
    if (n === o) { alert('New school year must be different.'); return; }
    window.location.href = `student_profile.php?promote_students=1&school_year=${encodeURIComponent(o)}&promote_type=${t}&new_school_year=${encodeURIComponent(n)}`;
});

// ---------- Selection ----------
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

function selectAll() {
    document.querySelectorAll('.student-checkbox').forEach(cb => { cb.checked = true; });
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

document.addEventListener('DOMContentLoaded', updateSelection);
</script>
</body>
</html>