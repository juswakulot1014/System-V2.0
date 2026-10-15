<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/audit.php';

$current_page = basename($_SERVER['PHP_SELF']);

// ────────────────────────────────────────────────
// Cached pending-enrollment counter
// ────────────────────────────────────────────────
if (!function_exists('get_pending_count')) {
    function get_pending_count(mysqli $conn): int
    {
        if (isset($_SESSION['__pending_count'], $_SESSION['__pending_count_at'])
            && (time() - (int)$_SESSION['__pending_count_at']) < 30) {
            return (int)$_SESSION['__pending_count'];
        }
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM enrollment_form WHERE status = ?");
        $pending = 'Pending';
        $stmt->bind_param('s', $pending);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        $_SESSION['__pending_count']    = $n;
        $_SESSION['__pending_count_at'] = time();
        return $n;
    }
}

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
   POST: UPDATE SCHOOL YEAR (kept as backend fallback)
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_school_year'])) {
    verify_csrf();

    $old_sy = trim((string)($_POST['old_school_year'] ?? ''));
    $new_sy = trim((string)($_POST['new_school_year'] ?? ''));

    if (empty($old_sy) || empty($new_sy)) {
        $_SESSION['error'] = "Both old and new school years are required.";
    } elseif ($old_sy === $new_sy) {
        $_SESSION['error'] = "New school year must be different from the old one.";
    } elseif (!preg_match('/^\d{4}-\d{4}$/', $new_sy)) {
        $_SESSION['error'] = "New school year must be in the format YYYY-YYYY.";
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("
                UPDATE enrollment_form
                   SET school_year = ?
                 WHERE school_year = ?
                   AND status = 'Graduated'
            ");
            $stmt->bind_param("ss", $new_sy, $old_sy);
            $stmt->execute();
            $updated = $stmt->affected_rows;
            $stmt->close();
            $conn->commit();

            audit_log($conn, 'schoolyear.bulk_update', [
                'type'     => 'school_year',
                'from'     => $old_sy,
                'to'       => $new_sy,
                'affected' => $updated,
            ]);

            if ($updated > 0) {
                $_SESSION['success'] = "School year updated! $updated Graduated record(s) moved from $old_sy to $new_sy.";
            } else {
                $_SESSION['info'] = "No Graduated records with school year $old_sy.";
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
   POST: PROMOTE SELECTED (kept as backend fallback — no UI
   now triggers this; use promote.php instead)
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_selected'])) {
    verify_csrf();

    $selected_ids    = $_POST['selected_students'] ?? [];
    $promote_sy      = trim((string)($_POST['promote_school_year'] ?? ''));
    $new_school_year = trim((string)($_POST['new_school_year'] ?? ''));

    if (empty($selected_ids) || !is_array($selected_ids)) {
        $_SESSION['error'] = "No students selected for promotion.";
    } elseif (empty($promote_sy)) {
        $_SESSION['error'] = "School Year is required.";
    } elseif (empty($new_school_year)) {
        $_SESSION['error'] = "New School Year is required.";
    } elseif (!preg_match('/^\d{4}-\d{4}$/', $new_school_year)) {
        $_SESSION['error'] = "New school year must be in the format YYYY-YYYY.";
    } elseif ($promote_sy === $new_school_year) {
        $_SESSION['error'] = "New school year must be different from the current one.";
    } else {
        $selected_ids = array_values(array_filter(array_map('intval', $selected_ids), fn($v) => $v > 0));

        $conn->begin_transaction();
        try {
            $promoted_11  = 0;
            $graduated_12 = 0;
            $skipped      = 0;
            $admin_id     = (int)($_SESSION['admin_id'] ?? 0);

            $check = $conn->prepare("
                SELECT enrollment_id, grade_level, term, track, strand, program, section, status
                FROM enrollment_form
                WHERE student_id = ?
                  AND school_year = ?
                  AND (status = 'Active' OR status IS NULL)
                ORDER BY enrollment_id DESC
                LIMIT 1
            ");

            $dup_check = $conn->prepare("
                SELECT 1 FROM enrollment_form
                WHERE student_id = ? AND school_year = ? AND grade_level = 'Grade 12'
                LIMIT 1
            ");

            $ins12 = $conn->prepare("
                INSERT INTO enrollment_form
                    (student_id, school_year, grade_level, term, track, strand, program, section, status)
                VALUES (?, ?, 'Grade 12', ?, ?, ?, ?, ?, 'Active')
            ");

            $grad12 = $conn->prepare("
                UPDATE enrollment_form
                   SET status = 'Graduated'
                 WHERE enrollment_id = ? AND grade_level = 'Grade 12'
            ");

            $hist = $conn->prepare("
                INSERT INTO student_status_history
                    (student_id, old_status, new_status, reason, changed_by)
                VALUES (?, ?, 'Graduated', ?, ?)
            ");

            $hist_active = $conn->prepare("
                INSERT INTO student_status_history
                    (student_id, old_status, new_status, reason, changed_by)
                VALUES (?, ?, 'Active', ?, ?)
            ");

            foreach ($selected_ids as $sid) {
                $check->bind_param("is", $sid, $promote_sy);
                $check->execute();
                $enr = $check->get_result()->fetch_assoc();

                if (!$enr) { $skipped++; continue; }

                if ($enr['grade_level'] === 'Grade 11') {
                    $dup_check->bind_param("is", $sid, $new_school_year);
                    $dup_check->execute();
                    $already = $dup_check->get_result()->num_rows > 0;
                    if ($already) { $skipped++; continue; }

                    $ins12->bind_param(
                        "issssss",
                        $sid, $new_school_year,
                        $enr['term'], $enr['track'], $enr['strand'],
                        $enr['program'], $enr['section']
                    );
                    if ($ins12->execute()) {
                        $promoted_11++;
                        $old_status = $enr['status'] ?? 'Active';
                        $reason     = "Promoted Grade 11 → Grade 12 ($promote_sy → $new_school_year)";
                        $hist_active->bind_param("issi", $sid, $old_status, $reason, $admin_id);
                        $hist_active->execute();
                    }
                } elseif ($enr['grade_level'] === 'Grade 12') {
                    $grad12->bind_param("i", $enr['enrollment_id']);
                    $grad12->execute();
                    if ($grad12->affected_rows > 0) {
                        $graduated_12++;
                        $old_status = $enr['status'] ?? 'Active';
                        $reason     = "Graduated via batch promotion ($promote_sy → $new_school_year)";
                        $hist->bind_param("issi", $sid, $old_status, $reason, $admin_id);
                        $hist->execute();
                    }
                }
            }

            $check->close(); $dup_check->close(); $ins12->close();
            $grad12->close(); $hist->close(); $hist_active->close();

            $conn->commit();

            audit_log($conn, 'student.promote', [
                'type'      => 'batch',
                'from_sy'   => $promote_sy,
                'to_sy'     => $new_school_year,
                'promoted'  => $promoted_11,
                'graduated' => $graduated_12,
                'skipped'   => $skipped,
            ]);

            $msg = [];
            if ($promoted_11 > 0)  $msg[] = "$promoted_11 promoted to Grade 12";
            if ($graduated_12 > 0) $msg[] = "$graduated_12 marked Graduated";
            if ($skipped > 0)      $msg[] = "$skipped skipped (already have target SY)";

            $_SESSION['success'] = !empty($msg)
                ? "Promotion completed! " . implode(" • ", $msg) . ". Previous grade-level records preserved."
                : "No eligible active students were promoted.";

        } catch (Exception $e) {
            $conn->rollback();
            error_log('promote_selected failed: ' . $e->getMessage());
            $_SESSION['error'] = "Promotion failed: " . $e->getMessage();
        }
    }
    header("Location: student_profile.php?school_year=" . urlencode($new_school_year ?: $promote_sy));
    exit();
}

/* ═══════════════════════════════════════════════════════════
   POST: PROMOTE ALL (kept as backend fallback)
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_students'])) {
    verify_csrf();

    $promote_sy      = trim((string)($_POST['school_year']     ?? ''));
    $promote_type    = trim((string)($_POST['promote_type']    ?? ''));
    $new_school_year = trim((string)($_POST['new_school_year'] ?? ''));

    if (empty($promote_sy)) {
        $_SESSION['error'] = "Please select a School Year to promote students.";
    } elseif (empty($new_school_year)) {
        $_SESSION['error'] = "Please provide a New School Year.";
    } elseif (!preg_match('/^\d{4}-\d{4}$/', $new_school_year)) {
        $_SESSION['error'] = "New school year must be in the format YYYY-YYYY.";
    } elseif (!in_array($promote_type, ['grade11','grade12','both'], true)) {
        $_SESSION['error'] = "Invalid promotion type.";
    } elseif ($promote_sy === $new_school_year) {
        $_SESSION['error'] = "New school year must be different from the current one.";
    } else {
        $conn->begin_transaction();
        try {
            $admin_id = (int)($_SESSION['admin_id'] ?? 0);
            $p11 = 0; $p12 = 0;

            if ($promote_type === 'grade11' || $promote_type === 'both') {
                $sel = $conn->prepare("
                    SELECT enrollment_id, student_id, term, track, strand, program, section, status
                    FROM enrollment_form
                    WHERE grade_level = 'Grade 11'
                      AND school_year = ?
                      AND (status = 'Active' OR status IS NULL)
                ");
                $sel->bind_param("s", $promote_sy);
                $sel->execute();
                $rows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
                $sel->close();

                $ins12 = $conn->prepare("
                    INSERT INTO enrollment_form
                        (student_id, school_year, grade_level, term, track, strand, program, section, status)
                    VALUES (?, ?, 'Grade 12', ?, ?, ?, ?, ?, 'Active')
                ");
                $hist = $conn->prepare("
                    INSERT INTO student_status_history
                        (student_id, old_status, new_status, reason, changed_by)
                    VALUES (?, ?, 'Active', ?, ?)
                ");

                foreach ($rows as $r) {
                    $ins12->bind_param(
                        "issssss",
                        $r['student_id'], $new_school_year,
                        $r['term'], $r['track'], $r['strand'],
                        $r['program'], $r['section']
                    );
                    if ($ins12->execute()) {
                        $p11++;
                        $old_status = $r['status'] ?? 'Active';
                        $reason     = "Bulk promoted Grade 11 → Grade 12 ($promote_sy → $new_school_year)";
                        $hist->bind_param("issi", $r['student_id'], $old_status, $reason, $admin_id);
                        $hist->execute();
                    }
                }
                $ins12->close(); $hist->close();
            }

            if ($promote_type === 'grade12' || $promote_type === 'both') {
                $sel = $conn->prepare("
                    SELECT enrollment_id, student_id, status
                    FROM enrollment_form
                    WHERE grade_level = 'Grade 12'
                      AND school_year = ?
                      AND (status = 'Active' OR status IS NULL)
                ");
                $sel->bind_param("s", $promote_sy);
                $sel->execute();
                $rows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
                $sel->close();

                $upd = $conn->prepare("UPDATE enrollment_form SET status = 'Graduated' WHERE enrollment_id = ?");
                $hist = $conn->prepare("
                    INSERT INTO student_status_history
                        (student_id, old_status, new_status, reason, changed_by)
                    VALUES (?, ?, 'Graduated', ?, ?)
                ");

                foreach ($rows as $r) {
                    $upd->bind_param("i", $r['enrollment_id']);
                    if ($upd->execute()) {
                        $p12++;
                        $old_status = $r['status'] ?? 'Active';
                        $reason     = "Bulk graduated ($promote_sy → $new_school_year)";
                        $hist->bind_param("issi", $r['student_id'], $old_status, $reason, $admin_id);
                        $hist->execute();
                    }
                }
                $upd->close(); $hist->close();
            }

            $conn->commit();

            audit_log($conn, 'student.promote_all', [
                'type' => 'batch', 'from_sy' => $promote_sy, 'to_sy' => $new_school_year,
                'mode' => $promote_type, 'g11' => $p11, 'g12' => $p12,
            ]);

            $msg = [];
            if ($p11 > 0) $msg[] = "$p11 promoted to Grade 12";
            if ($p12 > 0) $msg[] = "$p12 marked Graduated";

            $_SESSION['success'] = !empty($msg)
                ? "Promotion completed! " . implode(" • ", $msg) . ". All previous records preserved."
                : "No active students found for the selected grade(s).";

        } catch (Exception $e) {
            $conn->rollback();
            error_log('promote_all failed: ' . $e->getMessage());
            $_SESSION['error'] = "Promotion failed: " . $e->getMessage();
        }
    }
    header("Location: student_profile.php?school_year=" . urlencode($new_school_year ?: $promote_sy));
    exit();
}

/* ═══════════════════════════════════════════════════════════
   FILTER INPUTS
   ═══════════════════════════════════════════════════════════ */
$search       = trim((string)($_GET['search']       ?? ''));
$grade_level  = trim((string)($_GET['grade_level']  ?? ''));
$school_year  = trim((string)($_GET['school_year']  ?? ''));
$status       = trim((string)($_GET['status']       ?? ''));

/* ═══════════════════════════════════════════════════════════
   VIEW MODE — GRADUATED STUDENTS
   ═══════════════════════════════════════════════════════════ */
$view_graduated = isset($_GET['view']) && $_GET['view'] === 'graduated';
$grad_sy        = trim((string)($_GET['grad_sy'] ?? ''));

$grad_sy_list      = [];
$grad_students     = [];
$grad_boys         = 0;
$grad_girls        = 0;
$grad_total        = 0;

if ($view_graduated) {
    $grad_sy_list = $conn->query("
        SELECT e.school_year, COUNT(DISTINCT e.student_id) AS cnt
        FROM enrollment_form e
        WHERE e.status = 'Graduated'
          AND e.school_year IS NOT NULL AND e.school_year != ''
        GROUP BY e.school_year
        ORDER BY e.school_year DESC
    ")->fetch_all(MYSQLI_ASSOC) ?: [];

    if ($grad_sy === '' && !empty($grad_sy_list)) {
        $grad_sy = $grad_sy_list[0]['school_year'];
    }

    $gsql = "
        SELECT s.student_id, s.student_id_number, s.lrn,
               s.first_name, s.middle_name, s.last_name, s.ext_name,
               s.sex, s.age,
               e.grade_level, e.strand, e.program, e.section, e.school_year, e.term
        FROM students_info s
        INNER JOIN enrollment_form e ON s.student_id = e.student_id
        WHERE e.status = 'Graduated'
    ";
    $gparams = []; $gtypes = "";
    if ($grad_sy !== '') {
        $gsql .= " AND e.school_year = ?";
        $gparams[] = $grad_sy; $gtypes .= "s";
    }
    $gsql .= " ORDER BY s.last_name ASC, s.first_name ASC";

    $gstmt = $conn->prepare($gsql);
    if (!empty($gparams)) $gstmt->bind_param($gtypes, ...$gparams);
    $gstmt->execute();
    $grad_students = $gstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $gstmt->close();

    foreach ($grad_students as $gs_stu) {
        if (strcasecmp($gs_stu['sex'] ?? '', 'Male')   === 0) $grad_boys++;
        if (strcasecmp($gs_stu['sex'] ?? '', 'Female') === 0) $grad_girls++;
    }
    $grad_total = count($grad_students);
}

/* ═══════════════════════════════════════════════════════════
   PAGINATION
   ═══════════════════════════════════════════════════════════ */
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;

/* ═══════════════════════════════════════════════════════════
   POST: AUTO SECTION ASSIGNMENT
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_assign'])) {
    verify_csrf();

    $grade_level = trim((string)($_POST['grade_level'] ?? ''));
    $school_year = trim((string)($_POST['school_year'] ?? ''));

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
$all_students        = [];
$total_students      = 0;
$total_pages         = 0;
$total_all_students  = 0;
$boys                = 0;
$girls               = 0;
$sec_count           = 0;

if (!$view_graduated) {
    $sql = "SELECT s.student_id, s.student_id_number, s.lrn, s.last_name, s.first_name, s.middle_name, s.ext_name, s.sex, s.age, e.grade_level, e.school_year, e.section, e.strand, e.track, e.program, e.term, COALESCE(e.status, 'Active') AS status, COUNT(*) OVER() as total_count FROM students_info s INNER JOIN enrollment_form e ON s.student_id = e.student_id WHERE 1=1";

    $params = []; $types = "";
    if ($search !== '')      {
        $like = "%$search%";
        $sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)";
        array_push($params, $like, $like, $like, $like);
        $types .= "ssss";
    }
    if ($grade_level !== '') { $sql .= " AND e.grade_level = ?"; $params[] = $grade_level; $types .= "s"; }
    if ($school_year !== '') { $sql .= " AND e.school_year = ?"; $params[] = $school_year; $types .= "s"; }

    if ($status === '' || $status === 'All') {
        $sql .= " AND COALESCE(e.status, 'Active') NOT IN ('Graduated', 'Pending', 'Promoted')";
    } else {
        $sql .= " AND COALESCE(e.status, 'Active') = ?";
        $params[] = $status; $types .= "s";
    }

    $sql .= " ORDER BY s.last_name ASC, s.first_name ASC LIMIT ? OFFSET ?";
    $params[] = $per_page; $types .= "i";
    $params[] = $offset;   $types .= "i";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $all_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $total_students = !empty($all_students) ? (int)$all_students[0]['total_count'] : 0;
    $total_pages = (int)ceil($total_students / $per_page);
    $stmt->close();

    $tsql = "SELECT COUNT(*) AS c
             FROM students_info s
             INNER JOIN enrollment_form e ON s.student_id = e.student_id
             WHERE COALESCE(e.status, 'Active') NOT IN ('Graduated', 'Pending', 'Promoted')";
    $tp = []; $tt = "";
    if ($search !== '') {
        $tlike = "%$search%";
        $tsql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)";
        array_push($tp, $tlike, $tlike, $tlike, $tlike);
        $tt .= "ssss";
    }
    if ($grade_level !== '') { $tsql .= " AND e.grade_level = ?"; $tp[] = $grade_level; $tt .= "s"; }
    if ($school_year !== '') { $tsql .= " AND e.school_year = ?"; $tp[] = $school_year; $tt .= "s"; }

    $ts = $conn->prepare($tsql);
    if (!empty($tp)) $ts->bind_param($tt, ...$tp);
    $ts->execute();
    $ts_row = $ts->get_result()->fetch_assoc();
    $ts->close();
    $total_all_students = (int)($ts_row['c'] ?? 0);

    $gsql = "SELECT
                SUM(CASE WHEN UPPER(TRIM(s.sex)) IN ('MALE','M') THEN 1 ELSE 0 END) AS boys,
                SUM(CASE WHEN UPPER(TRIM(s.sex)) IN ('FEMALE','F') THEN 1 ELSE 0 END) AS girls
             FROM students_info s
             INNER JOIN enrollment_form e ON s.student_id = e.student_id
             WHERE COALESCE(e.status, 'Active') NOT IN ('Graduated', 'Pending', 'Promoted')";
    $gp = []; $gt = "";
    if ($search !== '')      { $gsql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ?)"; $gp = [$like, $like, $like]; $gt = "sss"; }
    if ($grade_level !== '') { $gsql .= " AND e.grade_level = ?"; $gp[] = $grade_level; $gt .= "s"; }
    if ($school_year !== '') { $gsql .= " AND e.school_year = ?"; $gp[] = $school_year; $gt .= "s"; }
    $gs = $conn->prepare($gsql);
    if (!empty($gp)) $gs->bind_param($gt, ...$gp);
    $gs->execute();
    $st = $gs->get_result()->fetch_assoc();
    $gs->close();
    $boys  = (int)($st['boys']  ?? 0);
    $girls = (int)($st['girls'] ?? 0);

    $sec_count_row = $conn->query("
        SELECT COUNT(DISTINCT section) AS c
        FROM enrollment_form
        WHERE section IS NOT NULL AND section != ''
          AND COALESCE(status, 'Active') NOT IN ('Graduated', 'Pending', 'Promoted')
    ")->fetch_assoc();
    $sec_count = (int)($sec_count_row['c'] ?? 0);
}

$grade_levels = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form WHERE grade_level IS NOT NULL AND grade_level != '' ORDER BY grade_level ASC");
$school_years = $conn->query("SELECT DISTINCT school_year FROM enrollment_form WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC");
$possible_statuses = ['Active', 'Pending', 'Transferred', 'Stopped', 'Dropped', 'Graduated', 'Promoted'];
$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';

$base_url = "?search=" . urlencode($search) . "&grade_level=" . urlencode($grade_level) . "&school_year=" . urlencode($school_year) . "&status=" . urlencode($status);

$csrf_token = csrf_token();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $view_graduated ? 'Graduated Students' : 'Student Profiles' ?> • USAT Admin</title>

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
        .stat-card.tone-amber::before{background:var(--amber)}
        .stat-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;background:var(--accent-soft);color:var(--accent)}
        .tone-green .stat-icon{background:var(--green-soft);color:var(--green)}
        .tone-red .stat-icon{background:var(--red-soft);color:var(--red)}
        .tone-purple .stat-icon{background:var(--purple-soft);color:var(--purple)}
        .tone-amber .stat-icon{background:var(--amber-soft);color:var(--amber)}
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

        .bulk-actions{background:linear-gradient(135deg,var(--accent-soft),color-mix(in srgb,var(--accent-soft) 40%,var(--surface)));border:1px solid color-mix(in srgb,var(--accent) 20%,transparent);border-radius:var(--radius-lg);padding:1.1rem 1.25rem;margin-bottom:1.25rem;display:grid;grid-template-columns:1fr;gap:1rem}
        [data-bs-theme="dark"] .bulk-actions{background:linear-gradient(135deg,#1e2140,color-mix(in srgb,#1e2140 60%,var(--surface)))}
        .bulk-action{display:flex;align-items:flex-start;gap:.9rem;padding:1rem 1.1rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);transition:all .15s}
        .bulk-action:hover{border-color:var(--accent);box-shadow:var(--shadow-md);transform:translateY(-1px)}
        .bulk-action-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;background:var(--green-soft);color:var(--green)}
        .bulk-action-body{flex:1;min-width:0}
        .bulk-action-title{font-size:.9rem;font-weight:700;color:var(--text);margin-bottom:.15rem}
        .bulk-action-desc{font-size:.78rem;color:var(--text-2);line-height:1.5;margin-bottom:.75rem}
        .bulk-action-btn{display:inline-flex;align-items:center;gap:.35rem;padding:.5rem 1rem;border-radius:9px;border:none;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .15s;font-family:inherit}
        .bulk-action-btn.primary{background:var(--green);color:#fff}
        .bulk-action-btn.primary:hover{background:#047857;transform:translateY(-1px)}
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
        .student-avatar{width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent-2));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1.05rem;flex-shrink:0;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 15%,transparent)}
        .student-card.graduated .student-avatar{background:linear-gradient(135deg,#059669,#047857);box-shadow:0 0 0 3px rgba(5,150,105,.15)}
        .student-info{flex:1;min-width:0}
        .student-info h4{font-size:.92rem;font-weight:600;margin:0 0 .15rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text)}
        .student-info .meta{font-size:.74rem;color:var(--text-2);font-variant-numeric:tabular-nums}
        .student-info .tags{display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.6rem}

        .tag{display:inline-flex;align-items:center;gap:.25rem;padding:.18rem .55rem;border-radius:999px;font-size:.68rem;font-weight:600;background:var(--surface-2);color:var(--text-2);border:1px solid var(--border);white-space:nowrap}
        .tag.accent{background:var(--accent-soft);color:var(--accent);border-color:color-mix(in srgb,var(--accent) 22%,transparent)}
        .tag.initials{background:var(--purple-soft);color:var(--purple);border-color:color-mix(in srgb,var(--purple) 22%,transparent);font-weight:700;letter-spacing:.4px}
        .tag.grad-sy{background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb,var(--green) 25%,transparent);font-weight:700}

        .pill{display:inline-flex;align-items:center;gap:.4rem;padding:.22rem .6rem;border-radius:999px;font-size:.7rem;font-weight:600;border:1px solid transparent}
        .pill::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;opacity:.9}
        .pill.success{background:var(--green-soft);color:var(--green)}
        .pill.warning{background:var(--amber-soft);color:var(--amber)}
        .pill.danger {background:var(--red-soft);color:var(--red)}
        .pill.muted  {background:var(--surface-2);color:var(--text-2)}

        .btn{font-weight:500;border-radius:9px;font-size:.82rem;transition:all .15s}
        .btn-primary{background:var(--accent);border-color:var(--accent)}
        .btn-primary:hover{background:var(--accent-2);border-color:var(--accent-2)}
        .btn-outline-primary{color:var(--accent);border-color:var(--border-strong)}
        .btn-outline-primary:hover{background:var(--accent);border-color:var(--accent);color:#fff}
        .btn-outline-secondary{color:var(--text-2);border-color:var(--border)}
        .btn-outline-secondary:hover{background:var(--surface-2);color:var(--text);border-color:var(--border-strong)}
        .btn-outline-success{color:var(--green);border-color:color-mix(in srgb,var(--green) 35%,transparent)}
        .btn-outline-success:hover{background:var(--green);color:#fff;border-color:var(--green)}
        .btn-outline-warning{color:var(--amber);border-color:color-mix(in srgb,var(--amber) 40%,transparent)}
        .btn-outline-warning:hover{background:var(--amber);color:#fff;border-color:var(--amber)}
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

        /* Auto-assign modal */
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

        .pm-field{margin-bottom:1rem}
        .pm-field label{display:block;font-size:.75rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:.4rem}
        .pm-field input,.pm-field select{width:100%;background:var(--surface-2);border:1.5px solid var(--border);border-radius:11px;padding:.7rem .9rem;font-size:.92rem;color:var(--text);font-family:inherit;transition:border-color .15s,box-shadow .15s}
        .pm-field input:focus,.pm-field select:focus{border-color:var(--accent);background:var(--surface);outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%,transparent)}
        .pm-field .hint{font-size:.72rem;color:var(--text-3);margin-top:.3rem}
        .pm-footer{padding:1.15rem 1.75rem;background:var(--surface-2);border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap}
        .pm-footer .btn{padding:.6rem 1.25rem;font-weight:600;font-size:.85rem;border-radius:10px}
        .pm-btn-confirm{background:linear-gradient(135deg,#059669,#047857);border:none;color:#fff;box-shadow:0 4px 12px -4px rgba(5,150,105,.5);transition:all .15s}
        .pm-btn-confirm:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 8px 20px -4px rgba(5,150,105,.6);color:#fff}

        .grad-hero{
            background:linear-gradient(135deg,#059669,#047857);color:#fff;
            border-radius:var(--radius-lg);padding:1.35rem 1.5rem;margin-bottom:1.25rem;
            display:flex;align-items:center;gap:1rem;flex-wrap:wrap;
            position:relative;overflow:hidden;
            box-shadow:0 12px 32px -12px rgba(5,150,105,.55);
        }
        .grad-hero::after{content:'';position:absolute;right:-40px;top:-40px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.08)}
        .grad-hero-icon{
            width:56px;height:56px;border-radius:16px;
            background:rgba(255,255,255,.18);
            border:2px solid rgba(255,255,255,.3);
            display:flex;align-items:center;justify-content:center;
            font-size:1.5rem;flex-shrink:0;
            backdrop-filter:blur(4px);z-index:1;
        }
        .grad-hero-body{flex:1;min-width:0;z-index:1}
        .grad-hero-body h4{font-size:1.15rem;font-weight:700;margin:0 0 .25rem;letter-spacing:-.01em}
        .grad-hero-body p{margin:0;font-size:.85rem;opacity:.92}
        .grad-hero .btn-light{background:rgba(255,255,255,.95);color:var(--green);border:none;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,.15);z-index:1}
        .grad-hero .btn-light:hover{background:#fff;transform:translateY(-1px);color:var(--green)}

        .grad-filter{display:grid;grid-template-columns:minmax(240px,1fr) auto auto;gap:.75rem;align-items:end}
        @media (max-width:640px){ .grad-filter{grid-template-columns:1fr} }

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
        }
        @media (max-width:640px){
            .stat-grid{grid-template-columns:repeat(2,1fr);gap:.75rem}
            .main-content{padding:1rem}
            .filter-bar{grid-template-columns:1fr}
            .student-grid{grid-template-columns:1fr}
        }
    </style>
</head>
<body>

<?php
/* ── Sidebar include: shared across all admin pages ── */
$__current = basename($_SERVER['PHP_SELF']);
$__super   = (($_SESSION['admin_role'] ?? '') === 'superadmin');

// Sub-pages that should keep "Students" highlighted
$__student_pages = ['student_profile.php','view_student.php','edit_student.php','export_students.php','sections_list.php','sf10.php'];
// Graduate and promote pages share the same highlight group
$__promote_pages = ['promote.php','graduates.php'];

$__act = $__current;
if (in_array($__current, $__student_pages, true)) $__act = 'student_profile.php';

$__a = fn($f) => $f === $__act ? ' class="active"' : '';

$__pending_count = (int)($conn->query("SELECT COUNT(*) c FROM enrollment_form WHERE status='Pending'")->fetch_assoc()['c'] ?? 0);
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
        <a href="pending_enrollments.php"<?= $__a('pending_enrollments.php') ?>>
            <i class="bi bi-hourglass-split"></i> Pending
            <?php if ($__pending_count > 0): ?>
                <span style="margin-left:auto;background:#d97706;color:#fff;font-size:.68rem;padding:.15rem .5rem;border-radius:999px;font-weight:700;line-height:1">
                    <?= $__pending_count ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="promote.php"<?= $__a('promote.php') ?>><i class="bi bi-rocket-takeoff"></i> Promote</a>
        <a href="graduates.php"<?= $__a('graduates.php') ?>><i class="bi bi-mortarboard-fill"></i> Graduates</a>
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

<?php if ($view_graduated): ?>
    <!-- ═══════════════════════════════════════════════════════
         GRADUATED STUDENTS VIEW
         ═══════════════════════════════════════════════════════ -->

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h2>Graduated Students</h2>
                <p class="sub">Browse graduates by school year</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="student_profile.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Students
            </a>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <div class="grad-hero">
        <div class="grad-hero-icon"><i class="bi bi-mortarboard-fill"></i></div>
        <div class="grad-hero-body">
            <h4>Graduated Students</h4>
            <p>
                <?php if ($grad_sy !== ''): ?>
                    Showing graduates from <strong>School Year <?= htmlspecialchars($grad_sy) ?></strong>
                <?php else: ?>
                    Showing all graduates across every school year
                <?php endif; ?>
            </p>
        </div>
        <a href="export_students.php?status_filter=Graduated<?= $grad_sy !== '' ? '&school_year=' . urlencode($grad_sy) : '' ?>"
           class="btn btn-light btn-sm">
            <i class="bi bi-download me-1"></i> Export
        </a>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-funnel"></i> Filter by School Year</h3>
            <span class="cell-muted" style="font-size:.75rem">
                <i class="bi bi-mortarboard-fill me-1"></i> <?= number_format($grad_total) ?> graduate<?= $grad_total === 1 ? '' : 's' ?>
            </span>
        </div>
        <div class="card-body">
            <form method="GET" class="grad-filter">
                <input type="hidden" name="view" value="graduated">
                <div class="field" style="display:flex;flex-direction:column;gap:.3rem">
                    <label style="font-size:.68rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px">School Year</label>
                    <select name="grad_sy" onchange="this.form.submit()"
                            style="background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:.5rem .8rem;font-size:.85rem;color:var(--text);font-family:inherit;width:100%">
                        <?php if (empty($grad_sy_list)): ?>
                            <option value="">No graduates recorded</option>
                        <?php else: ?>
                            <?php foreach ($grad_sy_list as $g): ?>
                                <option value="<?= htmlspecialchars($g['school_year']) ?>" <?= $grad_sy === $g['school_year'] ? 'selected' : '' ?>>
                                    SY <?= htmlspecialchars($g['school_year']) ?> — <?= (int)$g['cnt'] ?> graduate<?= (int)$g['cnt'] === 1 ? '' : 's' ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-check2 me-1"></i> Apply
                </button>
                <a href="?view=graduated" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-circle me-1"></i> Reset
                </a>
            </form>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card tone-green">
            <div class="stat-icon"><i class="bi bi-mortarboard-fill"></i></div>
            <div>
                <div class="stat-value"><?= number_format($grad_total) ?></div>
                <div class="stat-label">Total Graduates</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-gender-male"></i></div>
            <div>
                <div class="stat-value"><?= number_format($grad_boys) ?></div>
                <div class="stat-label">Male</div>
            </div>
        </div>
        <div class="stat-card tone-red">
            <div class="stat-icon"><i class="bi bi-gender-female"></i></div>
            <div>
                <div class="stat-value"><?= number_format($grad_girls) ?></div>
                <div class="stat-label">Female</div>
            </div>
        </div>
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="bi bi-calendar-range"></i></div>
            <div>
                <div class="stat-value" style="font-size:1.15rem"><?= htmlspecialchars($grad_sy ?: 'All') ?></div>
                <div class="stat-label">School Year</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>
                <i class="bi bi-people"></i> Graduates
                <?php if ($grad_sy !== ''): ?>
                    <span class="cell-muted" style="font-weight:500;font-size:.85rem">— SY <?= htmlspecialchars($grad_sy) ?></span>
                <?php endif; ?>
                <span class="cell-muted" style="font-weight:500;font-size:.85rem">(<?= number_format($grad_total) ?>)</span>
            </h3>
        </div>
        <div class="card-body">
            <?php if (empty($grad_students)): ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-mortarboard"></i></div>
                    <div class="empty-title">No graduates found</div>
                    <div class="empty-sub">Try selecting a different school year, or check that graduates have been marked.</div>
                </div>
            <?php else: ?>
                <div class="student-grid">
                    <?php foreach ($grad_students as $g):
                        $name = htmlspecialchars(formatStudentName($g));
                        $strand_txt = $g['strand'] ?? '';
                        $strand_init = $strand_txt !== '' ? strandInitials($strand_txt) : '—';
                    ?>
                    <div class="student-card graduated">
                        <div class="student-avatar">
                            <i class="bi bi-mortarboard-fill"></i>
                        </div>
                        <div class="student-info">
                            <h4 title="<?= $name ?>"><?= $name ?></h4>
                            <div class="meta">
                                LRN <?= htmlspecialchars($g['lrn'] ?? '—') ?>
                                <span style="opacity:.5">·</span>
                                ID <?= htmlspecialchars($g['student_id_number'] ?? '—') ?>
                            </div>
                            <div class="tags">
                                <span class="tag grad-sy">
                                    <i class="bi bi-calendar-check"></i>
                                    SY <?= htmlspecialchars($g['school_year'] ?? '—') ?>
                                </span>
                                <span class="tag accent"><?= htmlspecialchars($g['grade_level'] ?? '—') ?></span>
                                <span class="tag"><?= htmlspecialchars($g['section'] ?? 'No Section') ?></span>
                                <?php if ($strand_txt !== ''): ?>
                                    <span class="tag" title="<?= htmlspecialchars($strand_txt) ?>">
                                        <?= htmlspecialchars(strlen($strand_txt) > 24 ? substr($strand_txt, 0, 22) . '…' : $strand_txt) ?>
                                    </span>
                                    <span class="tag initials" title="Strand initials"><?= htmlspecialchars($strand_init) ?></span>
                                <?php endif; ?>
                                <span class="pill success">Graduated</span>
                            </div>
                            <div class="mt-2">
                                <a href="view_student.php?id=<?= (int)$g['student_id'] ?>" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye me-1"></i> View Profile
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- ═══════════════════════════════════════════════════════
         NORMAL STUDENTS VIEW
         ═══════════════════════════════════════════════════════ -->

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h2>Student Profiles</h2>
                <p class="sub">Manage enrolled students, sections, and promotions</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="promote.php" class="btn btn-outline-warning btn-sm" title="Open the promotion page">
                <i class="bi bi-rocket-takeoff me-1"></i> Promote
            </a>
            <a href="?view=graduated" class="btn btn-outline-success btn-sm">
                <i class="bi bi-mortarboard-fill me-1"></i> View Graduated
            </a>
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
                <div class="stat-value"><?= number_format($total_all_students) ?></div>
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
                        <option value="">All (excl. Graduated, Pending &amp; Promoted)</option>
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
    </div>

    <div class="card">
        <div class="card-header">
            <h3>
                <i class="bi bi-people"></i> Students
                <span class="cell-muted" style="font-weight:500;font-size:.85rem">(<?= number_format($total_students) ?>)</span>
            </h3>
            <div class="d-flex gap-2 align-items-center flex-wrap">
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
            <?php if($total_students > 0): ?>
                <div class="student-grid">
                    <?php foreach($all_students as $s):
                        $name = htmlspecialchars(formatStudentName($s));
                        $initials = strtoupper(substr($s['first_name']??'',0,1).substr($s['last_name']??'',0,1));
                        $st = $s['status'] ?? 'Active';
                        $strand_txt = $s['strand'] ?? '';
                        $strand_init = $strand_txt !== '' ? strandInitials($strand_txt) : '—';
                        $pillClass = $st === 'Active' ? 'success' : ($st === 'Graduated' ? 'danger' : ($st === 'Dropped' ? 'danger' : 'warning'));
                    ?>
                    <div class="student-card <?= $st === 'Graduated' ? 'graduated' : '' ?>">
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

<?php endif; ?>

</div>

<?php if (!$view_graduated): ?>
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
<?php endif; ?>

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.5);backdrop-filter:blur(2px);z-index:199"
     onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sb = document.getElementById('sidebar');
const ov = document.getElementById('sidebarOverlay');
document.getElementById('menuToggle').addEventListener('click', () => {
    sb.classList.toggle('open');
    ov.style.display = sb.classList.contains('open') ? 'block' : 'none';
});

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

<?php if (!$view_graduated): ?>
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
<?php endif; ?>
</script>
</body>
</html>