<?php
// shared/pages/promote.php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/audit.php';
require_once __DIR__ . '/../../config/security.php';

$__nav_section = 'grading';

if (!in_array($_SESSION['admin_role'] ?? '', ['registrar', 'superadmin'], true)) {
    http_response_code(403);
    security_log('promote.forbidden', [
        'admin_id' => (int)($_SESSION['admin_id'] ?? 0),
        'ip'       => client_ip(),
    ]);
    exit('Forbidden');
}

const PROMOTABLE_GRADES = ['Grade 11', 'Grade 12'];

if (!function_exists('formatStudentName')) {
    function formatStudentName(array $s): string {
        $mi  = !empty($s['middle_name']) ? ' ' . strtoupper(substr(trim($s['middle_name']), 0, 1)) . '.' : '';
        $ext = !empty($s['ext_name'])    ? ' ' . trim($s['ext_name']) : '';
        return trim(($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? '') . $mi . $ext);
    }
}

/* ═══ POST: promote selected ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_promote'])) {
    verify_csrf();

    $selected_ids      = $_POST['selected_students'] ?? [];
    $promote_sy        = trim((string)($_POST['promote_school_year'] ?? ''));
    $new_school_year   = trim((string)($_POST['new_school_year'] ?? ''));
    $redirect_qs       = trim((string)($_POST['redirect_qs'] ?? ''));
    $filter_grade_post = clean_string($_POST['filter_grade'] ?? '', 20);

    $err = '';
    if (empty($selected_ids) || !is_array($selected_ids)) {
        $err = "No students selected for promotion.";
    } elseif (!in_array($filter_grade_post, PROMOTABLE_GRADES, true)) {
        $err = "Please promote from the Grade 11 or Grade 12 tab.";
    } elseif ($promote_sy === '') {
        $err = "Source School Year is required.";
    } elseif ($new_school_year === '') {
        $err = "New School Year is required.";
    } elseif (!preg_match('/^\d{4}-\d{4}$/', $new_school_year)) {
        $err = "New school year must be in the format YYYY-YYYY.";
    } elseif ($promote_sy === $new_school_year) {
        $err = "New school year must be different from the current one.";
    }

    if ($err !== '') {
        $_SESSION['error'] = $err;
        $url = URL_PREFIX . '/promote.php' . ($redirect_qs !== '' ? '?' . $redirect_qs : '');
        header("Location: $url"); exit;
    }

    $selected_ids = array_values(array_unique(
        array_filter(array_map('intval', (array)$selected_ids), fn($v) => $v > 0)
    ));

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

        $close_g11 = $conn->prepare("
            UPDATE enrollment_form SET status = 'Promoted'
             WHERE enrollment_id = ? AND grade_level = 'Grade 11'
        ");

        $hist_promoted = $conn->prepare("
            INSERT INTO student_status_history
                (student_id, old_status, new_status, reason, changed_by)
            VALUES (?, ?, 'Promoted', ?, ?)
        ");

        foreach ($selected_ids as $sid) {
            $check->bind_param("is", $sid, $promote_sy);
            $check->execute();
            $enr = $check->get_result()->fetch_assoc();

            if (!$enr) { $skipped++; continue; }
            if ($enr['grade_level'] !== $filter_grade_post) { $skipped++; continue; }

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

                    $reason = "Promoted Grade 11 → Grade 12 ($promote_sy → $new_school_year)";
                    $hist_active->bind_param("issi", $sid, $old_status, $reason, $admin_id);
                    $hist_active->execute();

                    $close_g11->bind_param("i", $enr['enrollment_id']);
                    $close_g11->execute();

                    $reason_g11 = "Closed as Promoted to Grade 12 ($promote_sy → $new_school_year)";
                    $hist_promoted->bind_param("issi", $sid, $old_status, $reason_g11, $admin_id);
                    $hist_promoted->execute();
                }
            } elseif ($enr['grade_level'] === 'Grade 12') {
                $grad12->bind_param("i", $enr['enrollment_id']);
                $grad12->execute();

                if ($grad12->affected_rows > 0) {
                    $graduated_12++;
                    $old_status = $enr['status'] ?? 'Active';
                    $reason     = "Graduated via promotion page ($promote_sy → $new_school_year)";
                    $hist->bind_param("issi", $sid, $old_status, $reason, $admin_id);
                    $hist->execute();
                }
            } else {
                $skipped++;
            }
        }

        $check->close(); $dup_check->close(); $ins12->close();
        $grad12->close(); $hist->close(); $hist_active->close();
        $close_g11->close(); $hist_promoted->close();

        $conn->commit();

        audit_log($conn, 'student.promote', [
            'type'      => 'batch',
            'grade'     => $filter_grade_post,
            'from_sy'   => $promote_sy,
            'to_sy'     => $new_school_year,
            'promoted'  => $promoted_11,
            'graduated' => $graduated_12,
            'skipped'   => $skipped,
        ]);

        $msg = [];
        if ($promoted_11 > 0)  $msg[] = "$promoted_11 promoted to Grade 12";
        if ($graduated_12 > 0) $msg[] = "$graduated_12 marked Graduated";
        if ($skipped > 0)      $msg[] = "$skipped skipped (not eligible or already promoted)";

        $_SESSION['success'] = !empty($msg)
            ? "Promotion completed! " . implode(" • ", $msg) . ". Previous grade-level records preserved."
            : "No eligible active students were promoted.";

    } catch (Exception $e) {
        $conn->rollback();
        error_log('promote.php failed: ' . $e->getMessage());
        $_SESSION['error'] = "Promotion failed: " . $e->getMessage();
    }

    $redirect_url = URL_PREFIX . '/promote.php';
    if ($redirect_qs !== '') $redirect_url .= '?' . $redirect_qs;
    header("Location: $redirect_url"); exit;
}

/* ═══ Filters ═══ */
$filter_sy     = clean_string($_GET['school_year'] ?? '', 9);
$filter_grade  = clean_string($_GET['grade_level'] ?? '', 20);
$filter_status = clean_string($_GET['status'] ?? '', 20);

if ($filter_sy === '') {
    $row = $conn->query("
        SELECT school_year FROM enrollment_form
        WHERE school_year IS NOT NULL AND school_year != ''
        ORDER BY school_year DESC LIMIT 1
    ")->fetch_assoc();
    $filter_sy = $row['school_year'] ?? '';
}
if (!in_array($filter_grade, PROMOTABLE_GRADES, true)) $filter_grade = 'Grade 11';
if ($filter_status === '') $filter_status = 'Active';

$where  = ["e.school_year = ?", "e.grade_level = ?"];
$params = [$filter_sy, $filter_grade];
$types  = 'ss';

if ($filter_status !== 'All') {
    $where[]  = "COALESCE(e.status, 'Active') = ?";
    $params[] = $filter_status;
    $types   .= 's';
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

/* ═══ Fetch matching students ═══ */
$sql = "
    SELECT s.student_id, s.student_id_number, s.lrn,
           s.first_name, s.middle_name, s.last_name, s.ext_name,
           s.sex, s.age,
           e.enrollment_id, e.grade_level, e.school_year, e.term,
           e.section, e.strand, e.program, e.track,
           COALESCE(e.status, 'Active') AS status
    FROM students_info s
    INNER JOIN enrollment_form e ON s.student_id = e.student_id
    $where_sql
    ORDER BY e.section ASC, s.last_name ASC, s.first_name ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$count_total = count($students);
$count_active = 0; $count_boys = 0; $count_girls = 0;
foreach ($students as $s) {
    if (($s['status'] ?? '') === 'Active') $count_active++;
    if (strcasecmp($s['sex'] ?? '', 'Male') === 0) $count_boys++;
    if (strcasecmp($s['sex'] ?? '', 'Female') === 0) $count_girls++;
}

$tab_counts = ['Grade 11' => 0, 'Grade 12' => 0];
if ($filter_sy !== '') {
    $cst = $conn->prepare("
        SELECT grade_level, COUNT(*) AS c
        FROM enrollment_form
        WHERE school_year = ?
          AND COALESCE(status, 'Active') = 'Active'
          AND grade_level IN ('Grade 11', 'Grade 12')
        GROUP BY grade_level
    ");
    $cst->bind_param('s', $filter_sy);
    $cst->execute();
    foreach ($cst->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $tab_counts[$r['grade_level']] = (int)$r['c'];
    }
    $cst->close();
}

$sy_list = $conn->query("
    SELECT DISTINCT school_year FROM enrollment_form
    WHERE school_year IS NOT NULL AND school_year != ''
    ORDER BY school_year DESC
")->fetch_all(MYSQLI_ASSOC) ?: [];

$next_sy = '';
$parts = explode('-', $filter_sy);
if (count($parts) === 2) {
    $next_sy = ((int)$parts[0] + 1) . '-' . ((int)$parts[1] + 1);
}

$base_params = ['school_year' => $filter_sy, 'status' => $filter_status];
$redirect_qs = http_build_query(array_merge($base_params, ['grade_level' => $filter_grade]));
$tab_url = fn(string $g): string => URL_PREFIX . '/promote.php?' . http_build_query(array_merge($base_params, ['grade_level' => $g]));

$is_g11        = ($filter_grade === 'Grade 11');
$promote_label = $is_g11 ? 'Promote to Grade 12' : 'Mark as Graduated';
$promote_icon  = $is_g11 ? 'bi-arrow-up-circle-fill' : 'bi-mortarboard-fill';

$csrf_token = csrf_token();

$page_title = 'Promote Students • USAT Admin';
$page_head  = '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';

$page_css = <<<'CSS'
    /* Hero */
    .hero-banner{position:relative;background:radial-gradient(circle at 100% 0%, rgba(124,58,237,.25) 0%, transparent 40%),radial-gradient(circle at 0% 100%, rgba(217,119,6,.18) 0%, transparent 45%),linear-gradient(135deg, var(--accent) 0%, var(--accent-2) 60%, var(--purple) 100%);color:#fff;border-radius:var(--radius-xl);padding:1.75rem 2rem;margin-bottom:1.5rem;overflow:hidden;box-shadow:var(--shadow-lg)}
    .hero-banner::before{content:'';position:absolute;right:-60px;top:-60px;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle, rgba(255,255,255,.14) 0%, transparent 60%);pointer-events:none}
    .hero-inner{position:relative;z-index:1;display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap}
    .hero-icon{width:64px;height:64px;border-radius:18px;background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.35);display:flex;align-items:center;justify-content:center;font-size:1.75rem;flex-shrink:0;backdrop-filter:blur(8px)}
    .hero-body{flex:1;min-width:0}
    .hero-body h1{font-size:1.5rem;font-weight:800;letter-spacing:-.02em;margin:0 0 .4rem}
    .hero-body p{margin:0;font-size:.88rem;opacity:.92;line-height:1.5}
    .hero-chips{display:flex;gap:.5rem;margin-top:.75rem;flex-wrap:wrap}
    .hero-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.32rem .8rem;border-radius:999px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.2);font-size:.75rem;font-weight:600;backdrop-filter:blur(6px)}
    .hero-chip i{font-size:.8rem;opacity:.9}

    /* Grade tabs */
    .grade-tabs{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem}
    .grade-tab{display:flex;align-items:center;gap:1rem;padding:1.25rem 1.5rem;background:var(--surface);border:2px solid var(--border);border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);text-decoration:none;color:var(--text);transition:all .2s cubic-bezier(.4,0,.2,1);position:relative;overflow:hidden}
    .grade-tab::before{content:'';position:absolute;left:0;top:0;bottom:0;width:5px;background:linear-gradient(180deg,var(--amber),#f59e0b);transition:width .25s}
    .grade-tab.g12::before{background:linear-gradient(180deg,var(--purple),var(--pink))}
    .grade-tab:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);border-color:var(--border-strong)}
    .grade-tab:hover::before{width:6px}
    .grade-tab.active{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%,transparent), var(--shadow-lg);transform:translateY(-2px)}
    .grade-tab.active::before{width:6px}
    .gt-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.35rem;flex-shrink:0;background:linear-gradient(135deg,#fbbf24,#d97706);color:#fff;box-shadow:0 8px 20px -8px rgba(217,119,6,.6);transition:transform .2s}
    .grade-tab.g12 .gt-icon{background:linear-gradient(135deg,var(--purple),var(--pink));box-shadow:0 8px 20px -8px rgba(124,58,237,.6)}
    .grade-tab:hover .gt-icon{transform:scale(1.08) rotate(-3deg)}
    .gt-body{flex:1;min-width:0}
    .gt-title{font-weight:800;font-size:1.02rem;letter-spacing:-.01em;line-height:1.2}
    .gt-sub{font-size:.76rem;color:var(--text-2);margin-top:.25rem}
    .gt-count-wrap{display:flex;flex-direction:column;align-items:flex-end;gap:.15rem;flex-shrink:0}
    .gt-count{font-size:1.75rem;font-weight:800;font-variant-numeric:tabular-nums;line-height:1;color:var(--amber);letter-spacing:-.03em}
    .grade-tab.g12 .gt-count{color:var(--purple)}
    .gt-count-lbl{font-size:.62rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px}
    .gt-active-badge{position:absolute;top:.85rem;right:.85rem;display:inline-flex;align-items:center;gap:.25rem;padding:.15rem .55rem;border-radius:999px;background:var(--accent-soft);color:var(--accent);font-size:.62rem;font-weight:800;letter-spacing:.4px;text-transform:uppercase}
    .grade-tab.active .gt-active-badge{background:var(--accent);color:#fff}

    /* Selection bar */
    .selection-bar{position:sticky;top:1rem;z-index:50;background:radial-gradient(circle at 100% 0%, rgba(255,255,255,.15) 0%, transparent 40%),linear-gradient(135deg,var(--accent),var(--purple));color:#fff;border-radius:var(--radius-lg);padding:1rem 1.5rem;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;box-shadow:0 20px 45px -18px rgba(79,70,229,.65);animation:slideDown .25s ease}
    @keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
    .selection-bar .count-wrap{display:flex;align-items:baseline;gap:.6rem}
    .selection-bar .selected-count{font-weight:800;font-size:1.75rem;line-height:1;font-variant-numeric:tabular-nums;letter-spacing:-.03em;text-shadow:0 2px 8px rgba(0,0,0,.15)}
    .selection-bar .selected-label{font-size:.85rem;opacity:.95;font-weight:500}
    .selection-bar .actions{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}
    .selection-bar .actions > span{font-size:.78rem;opacity:.9;font-weight:500}
    .selection-bar input[type="text"]{background:rgba(255,255,255,.97);border:1.5px solid rgba(255,255,255,.35);color:#0f172a;font-weight:600;border-radius:10px;padding:.55rem .9rem;font-size:.88rem;width:180px;font-family:inherit;font-variant-numeric:tabular-nums;transition:all .15s}
    .selection-bar input[type="text"]:focus{outline:none;border-color:#fff;box-shadow:0 0 0 4px rgba(255,255,255,.3)}
    .btn-promote-now{background:#0f172a;color:#fff;border:none;font-weight:700;padding:.65rem 1.35rem;border-radius:10px;display:inline-flex;align-items:center;gap:.45rem;font-size:.85rem;cursor:pointer;transition:all .2s;box-shadow:0 8px 20px -8px rgba(0,0,0,.4);font-family:inherit}
    .btn-promote-now:hover:not(:disabled){background:#1e293b;transform:translateY(-2px);box-shadow:0 12px 24px -8px rgba(0,0,0,.5)}
    .btn-promote-now:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none}

    /* Table */
    .table-admin tbody tr:nth-child(even) td{background:color-mix(in srgb,var(--surface-2) 50%,transparent)}
    .table-admin tbody tr.selected td{background:color-mix(in srgb,var(--amber) 10%,var(--surface));box-shadow:inset 0 1px 0 var(--border)}
    .table-admin tbody tr.selected td:first-child{box-shadow:inset 3px 0 0 var(--amber)}
    .table-admin tbody tr.not-promotable{opacity:.65}
    .table-admin tbody tr.not-promotable:hover td{background:transparent}
    .table-admin thead th{position:sticky;top:0;z-index:2}

    .cell-checkbox{width:52px;text-align:center}
    .cell-checkbox input[type="checkbox"]{width:18px;height:18px;accent-color:var(--accent);cursor:pointer;transition:transform .1s}
    .cell-checkbox input[type="checkbox"]:hover:not(:disabled){transform:scale(1.15)}
    .cell-checkbox input[type="checkbox"]:disabled{opacity:.35;cursor:not-allowed}

    .student-cell{display:flex;align-items:center;gap:.8rem;min-width:0}
    .avatar-sm{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent-2));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.78rem;flex-shrink:0;box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 15%,transparent);transition:transform .2s}
    tr:hover .avatar-sm{transform:scale(1.05)}
    .student-cell .info{min-width:0}
    .student-cell .nm{font-weight:600;color:var(--text);line-height:1.25;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px}
    .student-cell .mt{font-size:.72rem;color:var(--text-2);margin-top:.2rem;display:flex;align-items:center;gap:.4rem}
    .student-cell .mt .dot{width:3px;height:3px;border-radius:50%;background:var(--text-3);opacity:.7}

    .tag{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .55rem;border-radius:7px;font-size:.68rem;font-weight:700;background:var(--surface-2);color:var(--text-2);border:1px solid var(--border);white-space:nowrap}
    .tag.accent{background:var(--accent-soft);color:var(--accent);border-color:color-mix(in srgb,var(--accent) 22%,transparent)}

    .target-badge{display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .7rem;border-radius:8px;font-size:.7rem;font-weight:700;white-space:nowrap;background:linear-gradient(135deg,var(--amber-soft),#fde68a);color:var(--amber);border:1px solid color-mix(in srgb,var(--amber) 25%,transparent);transition:transform .15s}
    [data-bs-theme="dark"] .target-badge{background:linear-gradient(135deg,var(--amber-soft),#78350f);color:#fbbf24}
    .target-badge i{font-size:.78rem}
    .target-badge.grad{background:linear-gradient(135deg,var(--purple-soft),#ddd6fe);color:var(--purple);border-color:color-mix(in srgb,var(--purple) 25%,transparent)}
    [data-bs-theme="dark"] .target-badge.grad{background:linear-gradient(135deg,var(--purple-soft),#4c1d95);color:#c4b5fd}

    .filter-bar{grid-template-columns:minmax(200px,1fr) minmax(200px,1fr) auto}

    /* SweetAlert2 theme */
    .sw-popup{position:relative;width:min(440px,92vw)!important;padding:0!important;border-radius:var(--radius-xl)!important;background:var(--surface)!important;color:var(--text)!important;border:1px solid var(--border);box-shadow:var(--shadow-lg)!important;overflow:hidden;font-family:'Inter',system-ui,sans-serif!important}
    .sw-popup::before{content:'';position:absolute;left:0;right:0;top:0;height:5px;background:linear-gradient(90deg,var(--sw-a),var(--sw-b))}
    .sw-promote{--sw-a:var(--accent);--sw-b:var(--accent-2);--sw-glow:rgba(79,70,229,.4)}
    .sw-graduate{--sw-a:var(--purple);--sw-b:var(--pink);--sw-glow:rgba(124,58,237,.4)}
    .sw-warn{--sw-a:var(--amber);--sw-b:#f59e0b;--sw-glow:rgba(217,119,6,.4)}

    .sw-html{margin:0!important;padding:2rem 1.6rem 0!important;text-align:center}
    .sw-hero{width:68px;height:68px;margin:0 auto;border-radius:22px;background:linear-gradient(135deg,var(--sw-a),var(--sw-b));color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.7rem;box-shadow:0 14px 30px -12px var(--sw-glow)}
    .sw-hero .spinner-border{width:1.6rem;height:1.6rem;border-width:.2em}
    .sw-title-txt{margin:1.15rem 0 .35rem;font-size:1.2rem;font-weight:800;letter-spacing:-.02em;color:var(--text)}
    .sw-sub{margin:0;color:var(--text-2);font-size:.88rem;line-height:1.5}
    .sw-summary{margin-top:1.25rem;text-align:left;border:1px solid var(--border);border-radius:14px;background:var(--surface-2);overflow:hidden}
    .sw-row{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.72rem 1rem;border-bottom:1px solid var(--border)}
    .sw-row:last-child{border-bottom:none}
    .sw-row span{font-size:.68rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.6px}
    .sw-row b{font-size:.86rem;font-weight:700;color:var(--text);font-variant-numeric:tabular-nums;text-align:right}
    .sw-note{margin-top:1rem;display:flex;gap:.6rem;align-items:flex-start;text-align:left;padding:.75rem .9rem;border-radius:12px;background:color-mix(in srgb,var(--sw-a) 10%,transparent);color:var(--text-2);font-size:.8rem;line-height:1.45}
    .sw-note i{color:var(--sw-a);margin-top:.1rem;flex-shrink:0}
    .sw-actions{width:100%!important;margin:1.5rem 0 0!important;padding:0 1.6rem 1.6rem!important;display:flex!important;gap:.6rem!important}
    .sw-actions-hidden{display:none!important}
    .sw-btn{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:.45rem;padding:.78rem 1rem;border-radius:12px;border:none;cursor:pointer;font-family:inherit;font-size:.88rem;font-weight:700;transition:transform .15s,box-shadow .15s,background .15s;margin:0!important}
    .sw-btn:hover{transform:translateY(-1px)}
    .sw-btn-cancel{background:var(--surface-2);color:var(--text-2);border:1px solid var(--border)!important}
    .sw-btn-cancel:hover{background:var(--surface);color:var(--text)}
    .sw-btn-confirm{background:linear-gradient(135deg,var(--sw-a),var(--sw-b));color:#fff;box-shadow:0 10px 22px -10px var(--sw-glow)}
    .sw-btn-confirm:hover{box-shadow:0 14px 26px -10px var(--sw-glow)}
    .sw-close{color:var(--text-3)!important;top:.75rem!important;right:.85rem!important;font-size:1.5rem!important}
    .sw-close:hover{color:var(--text)!important}

    @media (max-width:768px){
        .grade-tabs{grid-template-columns:1fr}
        .filter-bar{grid-template-columns:1fr}
        .selection-bar{flex-direction:column;align-items:stretch}
        .selection-bar .actions{justify-content:stretch}
        .selection-bar input[type="text"]{width:100%}
        .selection-bar .actions .btn-promote-now{flex:1;justify-content:center}
        .hero-inner{gap:1rem}
        .hero-icon{width:54px;height:54px;font-size:1.4rem}
        .hero-body h1{font-size:1.25rem}
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
                <h2>Promote Students</h2>
                <p class="sub">Advance Grade 11 to Grade 12, or mark Grade 12 as Graduated</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="<?= URL_PREFIX ?>/graduates.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-mortarboard me-1"></i> View Graduates
            </a>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <div class="hero-banner">
        <div class="hero-inner">
            <div class="hero-icon"><i class="bi bi-rocket-takeoff-fill"></i></div>
            <div class="hero-body">
                <h1><?= htmlspecialchars($filter_grade) ?> Promotion</h1>
                <p>
                    <?php if ($is_g11): ?>
                        Select active Grade 11 students to advance them to Grade 12 for the next school year. Their Grade 11 records will be preserved.
                    <?php else: ?>
                        Select active Grade 12 students to mark them as Graduated. Their full academic record will be retained.
                    <?php endif; ?>
                </p>
                <div class="hero-chips">
                    <span class="hero-chip"><i class="bi bi-calendar-range"></i> SY <?= htmlspecialchars($filter_sy ?: '—') ?></span>
                    <span class="hero-chip"><i class="bi bi-people-fill"></i> <?= number_format($count_total) ?> candidate<?= $count_total === 1 ? '' : 's' ?></span>
                    <span class="hero-chip"><i class="bi bi-shield-check"></i> Non-destructive</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-funnel-fill"></i> Filter Candidates</h3>
        </div>
        <div class="card-body">
            <form class="filter-bar" method="GET" id="filterForm">
                <input type="hidden" name="grade_level" value="<?= htmlspecialchars($filter_grade) ?>">
                <div class="field">
                    <label for="f_sy"><i class="bi bi-calendar3"></i> School Year</label>
                    <select id="f_sy" name="school_year" onchange="this.form.submit()">
                        <option value="">— Select School Year —</option>
                        <?php foreach ($sy_list as $sy): ?>
                            <option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $filter_sy === $sy['school_year'] ? 'selected' : '' ?>>
                                SY <?= htmlspecialchars($sy['school_year']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_status"><i class="bi bi-tag"></i> Status</label>
                    <select id="f_status" name="status" onchange="this.form.submit()">
                        <option value="Active"      <?= $filter_status === 'Active'      ? 'selected' : '' ?>>Active only</option>
                        <option value="Promoted"    <?= $filter_status === 'Promoted'    ? 'selected' : '' ?>>Promoted</option>
                        <option value="All"         <?= $filter_status === 'All'         ? 'selected' : '' ?>>All statuses</option>
                        <option value="Pending"     <?= $filter_status === 'Pending'     ? 'selected' : '' ?>>Pending</option>
                        <option value="Transferred" <?= $filter_status === 'Transferred' ? 'selected' : '' ?>>Transferred</option>
                        <option value="Stopped"     <?= $filter_status === 'Stopped'     ? 'selected' : '' ?>>Stopped</option>
                        <option value="Dropped"     <?= $filter_status === 'Dropped'     ? 'selected' : '' ?>>Dropped</option>
                        <option value="Graduated"   <?= $filter_status === 'Graduated'   ? 'selected' : '' ?>>Graduated</option>
                    </select>
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Apply</button>
                    <a href="<?= URL_PREFIX ?>/promote.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle me-1"></i> Reset</a>
                </div>
            </form>
        </div>
    </div>

    <nav class="grade-tabs" aria-label="Grade level">
        <a href="<?= htmlspecialchars($tab_url('Grade 11')) ?>" class="grade-tab <?= $is_g11 ? 'active' : '' ?>">
            <?php if ($is_g11): ?>
                <span class="gt-active-badge"><i class="bi bi-check-circle-fill"></i> Active</span>
            <?php endif; ?>
            <div class="gt-icon"><i class="bi bi-arrow-up-circle-fill"></i></div>
            <div class="gt-body">
                <div class="gt-title">Grade 11 → Grade 12</div>
                <div class="gt-sub">Advance active students to Grade 12</div>
            </div>
            <div class="gt-count-wrap">
                <div class="gt-count"><?= number_format($tab_counts['Grade 11']) ?></div>
                <div class="gt-count-lbl">Active</div>
            </div>
        </a>
        <a href="<?= htmlspecialchars($tab_url('Grade 12')) ?>" class="grade-tab g12 <?= !$is_g11 ? 'active' : '' ?>">
            <?php if (!$is_g11): ?>
                <span class="gt-active-badge"><i class="bi bi-check-circle-fill"></i> Active</span>
            <?php endif; ?>
            <div class="gt-icon"><i class="bi bi-mortarboard-fill"></i></div>
            <div class="gt-body">
                <div class="gt-title">Grade 12 → Graduate</div>
                <div class="gt-sub">Mark active seniors as Graduated</div>
            </div>
            <div class="gt-count-wrap">
                <div class="gt-count"><?= number_format($tab_counts['Grade 12']) ?></div>
                <div class="gt-count-lbl">Active</div>
            </div>
        </a>
    </nav>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="stat-value"><?= number_format($count_total) ?></div>
                <div class="stat-label">Matched</div>
            </div>
        </div>
        <div class="stat-card tone-green">
            <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="stat-value"><?= number_format($count_active) ?></div>
                <div class="stat-label">Active</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-gender-male"></i></div>
            <div>
                <div class="stat-value"><?= number_format($count_boys) ?></div>
                <div class="stat-label">Male</div>
            </div>
        </div>
        <div class="stat-card tone-red">
            <div class="stat-icon"><i class="bi bi-gender-female"></i></div>
            <div>
                <div class="stat-value"><?= number_format($count_girls) ?></div>
                <div class="stat-label">Female</div>
            </div>
        </div>
    </div>

    <form method="POST" id="promoteForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="do_promote" value="1">
        <input type="hidden" name="promote_school_year" value="<?= htmlspecialchars($filter_sy) ?>">
        <input type="hidden" name="redirect_qs" value="<?= htmlspecialchars($redirect_qs) ?>">
        <input type="hidden" name="filter_grade" value="<?= htmlspecialchars($filter_grade) ?>">
        <input type="hidden" name="new_school_year" id="newSchoolYearInput">
        <div id="selectedIdsContainer"></div>

        <div class="selection-bar" id="selectionBar" style="display:none">
            <div class="count-wrap">
                <span class="selected-count" id="selectedCount">0</span>
                <span class="selected-label">student(s) selected</span>
            </div>
            <div class="actions">
                <span><?= $is_g11 ? 'New school year:' : 'Graduating in:' ?></span>
                <input type="text" id="newSchoolYear" placeholder="e.g. <?= htmlspecialchars($next_sy) ?>"
                       value="<?= htmlspecialchars($next_sy) ?>" pattern="\d{4}-\d{4}" aria-label="School year">
                <button type="submit" class="btn-promote-now" id="promoteNowBtn" disabled>
                    <i class="bi <?= $promote_icon ?>"></i> <?= htmlspecialchars($promote_label) ?>
                </button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-header">
            <h3>
                <i class="bi bi-list-check"></i>
                <?= htmlspecialchars($filter_grade) ?> Candidates
                <span class="cell-muted" style="font-weight:600;font-size:.85rem">(<?= number_format($count_total) ?>)</span>
                <?php if ($filter_sy !== ''): ?>
                    <span class="tag accent"><i class="bi bi-calendar3"></i> SY <?= htmlspecialchars($filter_sy) ?></span>
                <?php endif; ?>
                <span class="tag accent"><i class="bi bi-tag"></i> <?= htmlspecialchars($filter_status) ?></span>
            </h3>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <?php if ($count_total > 0): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAll(true)">
                        <i class="bi bi-check-all me-1"></i> Select All
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAll(false)">
                        <i class="bi bi-x-circle me-1"></i> Deselect All
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-body no-padding">
            <?php if (empty($students)): ?>
                <div class="empty">
                    <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                    <div class="empty-title">No <?= htmlspecialchars($filter_grade) ?> students match</div>
                    <div class="empty-sub">Try a different school year or status filter above.</div>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table-admin">
                        <thead>
                            <tr>
                                <th class="cell-checkbox">
                                    <input type="checkbox" id="masterCheckbox" onchange="toggleAll(this.checked)" title="Select all" aria-label="Select all">
                                </th>
                                <th style="width:240px">Student</th>
                                <th style="width:150px">LRN / ID</th>
                                <th>Strand / Program</th>
                                <th style="width:120px">Section</th>
                                <th style="width:120px">Status</th>
                                <th style="width:150px">Promotion Target</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($students as $s):
                            $full_name     = formatStudentName($s);
                            $initials      = strtoupper(substr($s['first_name'] ?? '', 0, 1) . substr($s['last_name'] ?? '', 0, 1));
                            $status        = $s['status'] ?? 'Active';
                            $is_active     = ($status === 'Active');
                            $is_promotable = $is_active && in_array($s['grade_level'], PROMOTABLE_GRADES, true);

                            $is_senior    = $s['grade_level'] === 'Grade 12';
                            $target_label = $is_senior ? 'Graduate' : 'Grade 12';
                            $target_class = $is_senior ? 'grad' : '';
                            $target_icon  = $is_senior ? 'bi-mortarboard-fill' : 'bi-arrow-up-circle-fill';

                            $pillClass = match ($status) {
                                'Active'      => 'success',
                                'Pending'     => 'warning',
                                'Promoted'    => 'info',
                                'Graduated'   => 'purple',
                                'Transferred' => 'info',
                                'Dropped', 'Stopped' => 'danger',
                                default       => 'muted',
                            };

                            $strand_display = trim((string)($s['strand'] ?? '')) ?: '—';
                            $strand_short   = mb_strlen($strand_display) > 42
                                ? mb_substr($strand_display, 0, 40) . '…'
                                : $strand_display;
                            $program_txt = trim((string)($s['program'] ?? ''));
                        ?>
                            <tr class="<?= $is_promotable ? '' : 'not-promotable' ?>">
                                <td class="cell-checkbox">
                                    <?php if ($is_promotable): ?>
                                        <input type="checkbox" class="student-checkbox"
                                               value="<?= (int)$s['student_id'] ?>"
                                               onchange="updateSelection()"
                                               aria-label="Select <?= htmlspecialchars($full_name) ?>">
                                    <?php else: ?>
                                        <input type="checkbox" disabled title="Only Active students can be promoted">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="student-cell">
                                        <div class="avatar-sm"><?= htmlspecialchars($initials) ?></div>
                                        <div class="info">
                                            <div class="nm"><?= htmlspecialchars($full_name) ?></div>
                                            <div class="mt">
                                                <span><?= htmlspecialchars($s['sex'] ?? '—') ?></span>
                                                <span class="dot"></span>
                                                <span>Age <?= htmlspecialchars((string)($s['age'] ?? '—')) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-family:ui-monospace,monospace;font-size:.78rem;font-weight:600"><?= htmlspecialchars($s['lrn'] ?? '—') ?></div>
                                    <div class="cell-muted" style="font-size:.72rem;margin-top:.15rem"><?= htmlspecialchars($s['student_id_number'] ?? '—') ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:600;font-size:.83rem;line-height:1.35"><?= htmlspecialchars($strand_short) ?></div>
                                    <?php if ($program_txt !== ''): ?>
                                        <div class="cell-muted" style="font-size:.72rem;margin-top:.2rem"><?= htmlspecialchars($program_txt) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($s['section'])): ?>
                                        <span class="tag accent"><i class="bi bi-columns-gap"></i> <?= htmlspecialchars($s['section']) ?></span>
                                    <?php else: ?>
                                        <span class="cell-muted" style="font-style:italic">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="pill <?= $pillClass ?>"><?= htmlspecialchars($status) ?></span></td>
                                <td>
                                    <span class="target-badge <?= $target_class ?>">
                                        <i class="bi <?= $target_icon ?>"></i> <?= htmlspecialchars($target_label) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const IS_G11       = <?= json_encode($is_g11) ?>;
const SRC_SY       = <?= json_encode($filter_sy) ?>;
const PROMOTE_ICON = <?= json_encode($promote_icon) ?>;

function esc(s){
    return String(s).replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}

function swalClass(tone = 'sw-promote'){
    return {
        popup:         `sw-popup ${tone}`,
        htmlContainer: 'sw-html',
        actions:       'sw-actions',
        confirmButton: 'sw-btn sw-btn-confirm',
        cancelButton:  'sw-btn sw-btn-cancel',
        closeButton:   'sw-close'
    };
}

function warn(title, text){
    return Swal.fire({
        html: `
            <div class="sw-hero"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <h2 class="sw-title-txt">${esc(title)}</h2>
            <p class="sw-sub">${esc(text)}</p>`,
        buttonsStyling: false,
        showCancelButton: false,
        confirmButtonText: '<i class="bi bi-check2"></i> Got it',
        customClass: swalClass('sw-warn'),
        focusConfirm: true
    });
}

function getSelectableCheckboxes(){
    return Array.from(document.querySelectorAll('.student-checkbox:not(:disabled)'));
}
function getCheckedBoxes(){
    return getSelectableCheckboxes().filter(cb => cb.checked);
}

function updateSelection(){
    const checked = getCheckedBoxes();
    const n = checked.length;

    const bar     = document.getElementById('selectionBar');
    const countEl = document.getElementById('selectedCount');
    const btn     = document.getElementById('promoteNowBtn');
    const idsEl   = document.getElementById('selectedIdsContainer');
    const master  = document.getElementById('masterCheckbox');

    if (bar)     bar.style.display = n > 0 ? 'flex' : 'none';
    if (countEl) countEl.textContent = n;
    if (btn)     btn.disabled = n === 0;

    idsEl.innerHTML = '';
    checked.forEach(cb => {
        const i = document.createElement('input');
        i.type  = 'hidden';
        i.name  = 'selected_students[]';
        i.value = cb.value;
        idsEl.appendChild(i);
    });

    document.querySelectorAll('.student-checkbox').forEach(cb => {
        const row = cb.closest('tr');
        if (row) row.classList.toggle('selected', cb.checked);
    });

    if (master){
        const all = getSelectableCheckboxes();
        const allChecked = all.length > 0 && all.every(cb => cb.checked);
        const anyChecked = all.some(cb => cb.checked);
        master.checked = allChecked;
        master.indeterminate = !allChecked && anyChecked;
    }
}

function toggleAll(state){
    getSelectableCheckboxes().forEach(cb => cb.checked = state);
    updateSelection();
}

document.getElementById('promoteForm').addEventListener('submit', function(e){
    e.preventDefault();
    updateSelection();

    const checked = getCheckedBoxes();
    if (checked.length === 0){
        return warn('No students selected', 'Check at least one student to continue.');
    }

    const nsy = document.getElementById('newSchoolYear').value.trim();
    if (!nsy){
        return warn('Missing school year', 'Enter the school year to continue.');
    }
    if (!/^\d{4}-\d{4}$/.test(nsy)){
        return warn('Invalid format', 'School year must be in the format YYYY-YYYY.');
    }
    if (nsy === SRC_SY){
        return warn('Same school year', 'The new school year must be different from the source school year.');
    }

    document.getElementById('newSchoolYearInput').value = nsy;

    const count = checked.length;
    const noun  = count === 1 ? 'student' : 'students';
    const tone  = IS_G11 ? 'sw-promote' : 'sw-graduate';

    const rows = IS_G11
        ? [
            ['Students', `${count} Grade 11 ${noun}`],
            ['From',     `SY ${SRC_SY}`],
            ['To',       `Grade 12 · SY ${nsy}`]
          ]
        : [
            ['Students',    `${count} Grade 12 ${noun}`],
            ['School year', `SY ${SRC_SY}`],
            ['Result',      'Marked Graduated']
          ];

    const title = IS_G11 ? 'Confirm promotion' : 'Confirm graduation';
    const sub   = IS_G11
        ? `Advance ${count} ${noun} to Grade 12 for SY ${nsy}.`
        : `Mark ${count} ${noun} as Graduated.`;
    const note  = IS_G11
        ? 'Grade 11 records are kept and marked Promoted. Each student gets a new Grade 12 record.'
        : 'Grade 12 records are kept. Graduates appear on the Graduates page by school year.';

    const summaryHtml = rows.map(([k, v]) =>
        `<div class="sw-row"><span>${esc(k)}</span><b>${esc(v)}</b></div>`
    ).join('');

    Swal.fire({
        html: `
            <div class="sw-hero"><i class="bi ${esc(PROMOTE_ICON)}"></i></div>
            <h2 class="sw-title-txt">${esc(title)}</h2>
            <p class="sw-sub">${esc(sub)}</p>
            <div class="sw-summary">${summaryHtml}</div>
            <div class="sw-note"><i class="bi bi-shield-check"></i><span>${esc(note)}</span></div>`,
        showCancelButton: true,
        showCloseButton: true,
        buttonsStyling: false,
        reverseButtons: true,
        focusCancel: true,
        confirmButtonText: `<i class="bi ${esc(PROMOTE_ICON)}"></i> ${IS_G11 ? 'Yes, promote' : 'Yes, graduate'}`,
        cancelButtonText: '<i class="bi bi-x-lg"></i> Cancel',
        customClass: swalClass(tone)
    }).then(res => {
        if (!res.isConfirmed) return;

        Swal.fire({
            html: `
                <div class="sw-hero"><span class="spinner-border"></span></div>
                <h2 class="sw-title-txt">Processing…</h2>
                <p class="sw-sub">Please keep this page open.</p>`,
            showConfirmButton: false,
            showCancelButton: false,
            showCloseButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false,
            buttonsStyling: false,
            customClass: { ...swalClass(tone), actions: 'sw-actions sw-actions-hidden' }
        });

        document.getElementById('promoteForm').submit();
    });
});

document.addEventListener('DOMContentLoaded', updateSelection);
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>