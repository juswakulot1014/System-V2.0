<?php
// shared/pages/audit_log.php
require_once __DIR__ . '/../../config/auth.php';

$__nav_section = 'system';

/* ═══════════════════════════════════════════════════════════
   AUTO-CLEANUP: Delete logs older than 30 days
   ═══════════════════════════════════════════════════════════ */
if (mt_rand(1, 50) === 1) {
    try {
        $conn->query("DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    } catch (Throwable $e) {
        error_log("Audit log auto-cleanup failed: " . $e->getMessage());
    }
}

$limit         = max(10, min(1000, (int)($_GET['limit'] ?? 100)));
$action_filter = trim($_GET['action'] ?? '');
$admin_filter  = (int)($_GET['admin_id'] ?? 0);

$where  = [];
$params = [];
$types  = "";

if ($action_filter !== '') {
    $where[] = "a.action LIKE ?";
    $params[] = $action_filter . '%';
    $types .= "s";
}
if ($admin_filter > 0) {
    $where[] = "a.admin_id = ?";
    $params[] = $admin_filter;
    $types .= "i";
}
$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

/* ═══ Fetch log rows ═══ */
$stmt = $conn->prepare("
    SELECT a.id, a.created_at, a.action, a.target_type, a.target_id,
           a.ip_address, a.user_agent, a.meta,
           adm.fullname AS admin_name,
           adm.username AS admin_username
    FROM audit_log a
    LEFT JOIN admins adm ON a.admin_id = adm.id
    $where_sql
    ORDER BY a.created_at DESC
    LIMIT {$limit}
");
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ═══ Batch-fetch entity names ═══ */
$ids_by_type = [];
foreach ($rows as $r) {
    if (!empty($r['target_type']) && !empty($r['target_id'])) {
        $ids_by_type[$r['target_type']][] = (int)$r['target_id'];
    }
}

$lookup = [];

/* students_info (type: student, user, enrollee) */
if (!empty($ids_by_type['student']) || !empty($ids_by_type['user']) || !empty($ids_by_type['enrollee'])) {
    $ids = array_values(array_unique(array_merge(
        $ids_by_type['student']  ?? [],
        $ids_by_type['user']     ?? [],
        $ids_by_type['enrollee'] ?? []
    )));
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("
            SELECT student_id, student_id_number, lrn, first_name, middle_name, last_name
            FROM students_info
            WHERE student_id IN ($ph)
        ");
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($s = $res->fetch_assoc()) {
            $name = trim(($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? ''));
            $info = [
                'name'  => $name ?: '(unnamed)',
                'id_no' => $s['student_id_number'] ?? '',
                'lrn'   => $s['lrn'] ?? '',
            ];
            $lookup['student'][(int)$s['student_id']]  = $info;
            $lookup['user'][(int)$s['student_id']]     = $info;
            $lookup['enrollee'][(int)$s['student_id']] = $info;
        }
        $stmt->close();
    }
}

/* student_grades (type: grade) */
if (!empty($ids_by_type['grade'])) {
    $ids = array_values(array_unique($ids_by_type['grade']));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
        SELECT sg.grade_id, sg.subject_code, sg.subject_name, sg.term, sg.grade,
               s.student_id, s.first_name, s.last_name, s.student_id_number, s.lrn
        FROM student_grades sg
        LEFT JOIN students_info s ON s.student_id = sg.student_id
        WHERE sg.grade_id IN ($ph)
    ");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($g = $res->fetch_assoc()) {
        $subject = trim(($g['subject_code'] ?? '') . ' ' . ($g['subject_name'] ?? ''));
        $student = trim(($g['last_name'] ?? '') . ', ' . ($g['first_name'] ?? ''));
        $lookup['grade'][(int)$g['grade_id']] = [
            'subject'       => $subject ?: 'Subject',
            'student_name'  => $student ?: null,
            'student_id_no' => $g['student_id_number'] ?? '',
            'lrn'           => $g['lrn'] ?? '',
            'term'          => $g['term'] ?? '',
            'value'         => $g['grade'],
        ];
    }
    $stmt->close();
}

/* entrance_documents (type: document, file) */
if (!empty($ids_by_type['document']) || !empty($ids_by_type['file'])) {
    $ids = array_values(array_unique(array_merge(
        $ids_by_type['document'] ?? [],
        $ids_by_type['file']     ?? []
    )));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
        SELECT d.entrance_id, d.document_name, d.submitted,
               s.student_id, s.first_name, s.last_name, s.student_id_number, s.lrn
        FROM entrance_documents d
        LEFT JOIN students_info s ON s.student_id = d.student_id
        WHERE d.entrance_id IN ($ph)
    ");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($d = $res->fetch_assoc()) {
        $student = trim(($d['last_name'] ?? '') . ', ' . ($d['first_name'] ?? ''));
        $info = [
            'document_name' => $d['document_name'] ?? 'Document',
            'student_name'  => $student ?: null,
            'student_id_no' => $d['student_id_number'] ?? '',
            'lrn'           => $d['lrn'] ?? '',
            'submitted'     => (int)$d['submitted'],
        ];
        $lookup['document'][(int)$d['entrance_id']] = $info;
        $lookup['file'][(int)$d['entrance_id']]     = $info;
    }
    $stmt->close();
}

/* educational_history (type: education) */
if (!empty($ids_by_type['education'])) {
    $ids = array_values(array_unique($ids_by_type['education']));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
        SELECT e.edu_id, e.level, e.school_name,
               s.student_id, s.first_name, s.last_name, s.student_id_number, s.lrn
        FROM educational_history e
        LEFT JOIN students_info s ON s.student_id = e.student_id
        WHERE e.edu_id IN ($ph)
    ");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($e = $res->fetch_assoc()) {
        $student = trim(($e['last_name'] ?? '') . ', ' . ($e['first_name'] ?? ''));
        $lookup['education'][(int)$e['edu_id']] = [
            'level'         => $e['level'] ?? 'Level',
            'school'        => $e['school_name'] ?? '',
            'student_name'  => $student ?: null,
            'student_id_no' => $e['student_id_number'] ?? '',
            'lrn'           => $e['lrn'] ?? '',
        ];
    }
    $stmt->close();
}

/* admins (type: admin, account) */
if (!empty($ids_by_type['admin']) || !empty($ids_by_type['account'])) {
    $ids = array_values(array_unique(array_merge(
        $ids_by_type['admin']   ?? [],
        $ids_by_type['account'] ?? []
    )));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
        SELECT id, fullname, username, role
        FROM admins
        WHERE id IN ($ph)
    ");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($a = $res->fetch_assoc()) {
        $info = [
            'name'     => $a['fullname'] ?? '(unnamed)',
            'username' => $a['username'] ?? '',
            'role'     => $a['role'] ?? '',
        ];
        $lookup['admin'][(int)$a['id']]   = $info;
        $lookup['account'][(int)$a['id']] = $info;
    }
    $stmt->close();
}

/* enrollment_form (type: enrollment, section-assignment) */
if (!empty($ids_by_type['enrollment'])) {
    $ids = array_values(array_unique($ids_by_type['enrollment']));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("
        SELECT e.enrollment_id, e.school_year, e.grade_level, e.strand, e.section, e.status,
               s.student_id, s.first_name, s.last_name, s.student_id_number, s.lrn
        FROM enrollment_form e
        LEFT JOIN students_info s ON s.student_id = e.student_id
        WHERE e.enrollment_id IN ($ph)
    ");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($e = $res->fetch_assoc()) {
        $student = trim(($e['last_name'] ?? '') . ', ' . ($e['first_name'] ?? ''));
        $lookup['enrollment'][(int)$e['enrollment_id']] = [
            'student_name'  => $student ?: null,
            'student_id_no' => $e['student_id_number'] ?? '',
            'lrn'           => $e['lrn'] ?? '',
            'school_year'   => $e['school_year'] ?? '',
            'grade_level'   => $e['grade_level'] ?? '',
            'strand'        => $e['strand'] ?? '',
            'section'       => $e['section'] ?? '',
            'status'        => $e['status'] ?? '',
        ];
    }
    $stmt->close();
}

/* ═══ Filter dropdown data ═══ */
$action_list = $conn->query("
    SELECT DISTINCT action FROM audit_log ORDER BY action ASC
")->fetch_all(MYSQLI_ASSOC) ?: [];

$admin_list = $conn->query("
    SELECT DISTINCT adm.id, adm.fullname, adm.username
    FROM audit_log a
    INNER JOIN admins adm ON a.admin_id = adm.id
    ORDER BY adm.fullname ASC
")->fetch_all(MYSQLI_ASSOC) ?: [];

/* ═══ Stats ═══ */
$stats = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN action LIKE 'admin.login%' THEN 1 ELSE 0 END) AS logins,
        SUM(CASE WHEN action LIKE '%.create' THEN 1 ELSE 0 END) AS creates,
        SUM(CASE WHEN action LIKE '%.update' THEN 1 ELSE 0 END) AS updates,
        SUM(CASE WHEN action LIKE '%.delete' THEN 1 ELSE 0 END) AS deletes,
        COUNT(DISTINCT CASE WHEN admin_id > 0 THEN admin_id END) AS unique_admins
    FROM audit_log
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
")->fetch_assoc() ?: [];

/* ═══ Helpers ═══ */
if (!function_exists('action_badge_class')) {
    function action_badge_class(string $action): string {
        if (str_contains($action, 'login_failed')) return 'danger';
        if (str_contains($action, 'login') || str_contains($action, 'logout')) return 'info';
        if (str_contains($action, 'delete')) return 'danger';
        if (str_contains($action, 'create') || str_contains($action, 'enroll')) return 'success';
        if (str_contains($action, 'update') || str_contains($action, 'edit') || str_contains($action, 'change')) return 'warning';
        if (str_contains($action, 'export')) return 'primary';
        if (str_contains($action, 'view') || str_contains($action, 'print')) return 'muted';
        return 'secondary';
    }
}

if (!function_exists('action_icon')) {
    function action_icon(string $action): string {
        if (str_contains($action, 'login_failed')) return 'bi-shield-x';
        if (str_contains($action, 'login'))   return 'bi-box-arrow-in-right';
        if (str_contains($action, 'logout'))  return 'bi-box-arrow-right';
        if (str_contains($action, 'delete'))  return 'bi-trash3';
        if (str_contains($action, 'create') || str_contains($action, 'enroll')) return 'bi-plus-circle';
        if (str_contains($action, 'update') || str_contains($action, 'edit') || str_contains($action, 'change')) return 'bi-pencil';
        if (str_contains($action, 'export'))  return 'bi-download';
        if (str_contains($action, 'view') || str_contains($action, 'print')) return 'bi-eye';
        if (str_contains($action, 'promote')) return 'bi-arrow-up-circle';
        if (str_contains($action, 'section')) return 'bi-diagram-3';
        return 'bi-activity';
    }
}

if (!function_exists('pretty_action')) {
    function pretty_action(string $action): string {
        $map = [
            'admin.login' => 'Admin Login',
            'admin.logout' => 'Admin Logout',
            'admin.login_failed' => 'Login Failed',
            'student.enroll' => 'Student Enrolled',
            'student.walk_in_enroll' => 'Walk-in Enrollment',
            'student.update' => 'Student Updated',
            'student.delete' => 'Student Deleted',
            'student.promote' => 'Student Promoted',
            'student.promote_all' => 'Bulk Promotion',
            'student.status_change' => 'Status Changed',
            'grade.create' => 'Grade Added',
            'grade.update' => 'Grade Updated',
            'grade.delete' => 'Grade Deleted',
            'education.create' => 'Education Added',
            'education.update' => 'Education Updated',
            'education.delete' => 'Education Deleted',
            'document.upload' => 'Document Uploaded',
            'document.delete' => 'Document Deleted',
            'password.self_change' => 'Password Changed',
            'password.change' => 'Password Reset',
            'password.change_denied' => 'Password Change Denied',
            'enrollment.approve' => 'Enrollment Approved',
            'enrollment.reject' => 'Enrollment Rejected',
            'section.auto_assign' => 'Sections Auto-Assigned',
            'export.csv' => 'CSV Exported',
            'export.word' => 'Word Exported',
        ];
        return $map[$action] ?? ucwords(str_replace(['.', '_'], ' ', $action));
    }
}

if (!function_exists('pretty_meta')) {
    function pretty_meta(?string $json): array {
        if (!$json) return [];
        $data = json_decode($json, true);
        if (!is_array($data)) return [];
        unset($data['type'], $data['id']);
        return $data;
    }
}

if (!function_exists('friendly_label')) {
    function friendly_label(string $key): string {
        static $map = [
            'page'          => 'Page',
            'username'      => 'Username',
            'lrn'           => 'LRN',
            'student_id'    => 'Student ID',
            'student_id_no' => 'Student ID',
            'student_name'  => 'Student Name',
            'sy'            => 'School Year',
            'school_year'   => 'School Year',
            'grade'         => 'Grade',
            'grade_level'   => 'Grade Level',
            'term'          => 'Term',
            'mode'          => 'Mode',
            'scope'         => 'Scope',
            'students'      => 'Students Count',
            'from_sy'       => 'From SY',
            'to_sy'         => 'To SY',
            'from'          => 'From',
            'to'            => 'To',
            'affected'      => 'Affected Rows',
            'promoted'      => 'Promoted to Gr 12',
            'graduated'     => 'Graduated',
            'skipped'       => 'Skipped',
            'assigned'      => 'Assigned Students',
            'sections'      => 'Sections Created',
            'filename'      => 'File Name',
            'level'         => 'Level',
            'school'        => 'School',
            'subject'       => 'Subject',
            'name'          => 'Name',
            'fullname'      => 'Full Name',
            'role'          => 'Role',
            'type_of'       => 'Promotion Type',
            'count'         => 'Selected Count',
            'section'       => 'Section',
            'strand'        => 'Strand',
            'program'       => 'Program',
            'status'        => 'Status',
            'email'         => 'Email',
            'reason'        => 'Reason',
            'ip'            => 'IP Address',
            'reason'        => 'Reason',
            'logout_others' => 'Logout Others',
            'email_sent'    => 'Email Sent',
        ];
        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}

if (!function_exists('friendly_value')) {
    function friendly_value($v): string {
        if ($v === null || $v === '') return '—';
        if (is_bool($v)) return $v ? 'Yes' : 'No';
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_SLASHES);
        return (string)$v;
    }
}

if (!function_exists('render_target')) {
    function render_target(array $row, array $lookup): string
    {
        $type = (string)($row['target_type'] ?? '');
        $id   = (int)($row['target_id'] ?? 0);
        $meta = pretty_meta($row['meta'] ?? null);

        if ($type !== '' && $id > 0) {
            $ctx = $lookup[$type][$id] ?? null;
            if ($ctx) {
                return render_target_from_context($type, $ctx);
            }
        }

        $meta_render = render_target_from_meta($type, $id, $meta);
        if ($meta_render !== null) {
            return $meta_render;
        }

        if ($type === '' && $id === 0) {
            return '<span class="meta-empty">—</span>';
        }
        return '<span class="target-pill">' . htmlspecialchars($type ?: 'entity') . ' #' . $id . '</span>';
    }
}

if (!function_exists('render_target_from_context')) {
    function render_target_from_context(string $type, array $ctx): string
    {
        switch ($type) {
            case 'student':
            case 'user':
            case 'enrollee':
                return target_box($ctx['name'] ?? '(unnamed)', $ctx['id_no'] ?: null, 'bi-person-fill');

            case 'grade':
                $sec = $ctx['student_name'] ?: null;
                if (!empty($ctx['term']))   $sec = ($sec ? $sec . ' · ' : '') . $ctx['term'];
                if ($ctx['value'] !== null && $ctx['value'] !== '') {
                    $sec = ($sec ? $sec . ' · ' : '') . 'Grade: ' . number_format((float)$ctx['value'], 2);
                }
                return target_box($ctx['subject'] ?? 'Grade', $sec, 'bi-journal-check');

            case 'document':
            case 'file':
                $sec = $ctx['student_name'] ?: null;
                if (!empty($ctx['student_id_no'])) $sec = ($sec ? $sec . ' · ' : '') . $ctx['student_id_no'];
                return target_box($ctx['document_name'] ?? 'Document', $sec, 'bi-file-earmark-text');

            case 'education':
                $sub = ($ctx['level'] ?? 'Level') . (!empty($ctx['school']) ? ' — ' . $ctx['school'] : '');
                return target_box($sub, $ctx['student_name'] ?: null, 'bi-book');

            case 'admin':
            case 'account':
                $sec = !empty($ctx['username']) ? '@' . $ctx['username'] : null;
                if (!empty($ctx['role'])) $sec = ($sec ? $sec . ' · ' : '') . ucfirst($ctx['role']);
                return target_box($ctx['name'] ?? '(unnamed)', $sec, 'bi-person-badge');

            case 'enrollment':
                $primary = $ctx['student_name'] ?: ('Enrollment #' . ($ctx['enrollment_id'] ?? ''));
                $sec_bits = array_filter([
                    $ctx['student_id_no'] ?? '',
                    $ctx['grade_level']   ?? '',
                    $ctx['strand']        ?? '',
                    $ctx['section']       ?? '',
                ]);
                $sec = $sec_bits ? implode(' · ', $sec_bits) : null;
                return target_box($primary, $sec, 'bi-clipboard-check');

            case 'section':
                $sec_bits = array_filter([
                    $ctx['strand']      ?? '',
                    $ctx['grade_level'] ?? '',
                    $ctx['school_year'] ?? '',
                ]);
                return target_box($ctx['name'] ?? 'Section', $sec_bits ? implode(' · ', $sec_bits) : null, 'bi-diagram-3');

            case 'subject':
                $sub = trim(($ctx['code'] ?? '') . ' ' . ($ctx['name'] ?? ''));
                return target_box($sub ?: 'Subject', null, 'bi-journal-bookmark');
        }

        return target_box($ctx['name'] ?? $type, null, 'bi-database');
    }
}

if (!function_exists('render_target_from_meta')) {
    function render_target_from_meta(string $type, int $id, array $meta): ?string
    {
        $student_id_no  = $meta['student_id_no']  ?? $meta['student_id']  ?? null;
        $student_name   = $meta['student_name']   ?? null;
        $username       = $meta['username']       ?? null;
        $name           = $meta['name']           ?? $meta['fullname']    ?? null;
        $sy             = $meta['sy']             ?? $meta['school_year'] ?? null;
        $grade          = $meta['grade_level']    ?? $meta['grade']       ?? null;
        $term           = $meta['term']           ?? null;
        $section        = $meta['section']        ?? null;

        $context_bits = array_filter([
            $student_id_no, $grade, $section, $term, $sy,
        ]);

        $primary = null;
        $icon    = 'bi-record-circle';

        switch ($type) {
            case 'student':
            case 'user':
            case 'enrollee':
                $primary = $student_name ?: ($student_id_no ?: null);
                $icon    = 'bi-person-fill';
                break;
            case 'admin':
            case 'account':
                $primary = $name ?: ($username ? '@' . $username : null);
                $icon    = 'bi-person-badge';
                break;
            case 'document':
            case 'file':
                $primary = $meta['document_name'] ?? $meta['filename'] ?? null;
                $icon    = 'bi-file-earmark-text';
                break;
            case 'education':
                $primary = $meta['level'] ?? null;
                $icon    = 'bi-book';
                break;
            case 'section':
                $primary = $section;
                $icon    = 'bi-diagram-3';
                break;
            case 'grade':
                $primary = $meta['subject'] ?? null;
                $icon    = 'bi-journal-check';
                break;
            case 'enrollment':
                $primary = $student_name ?: ('Enrollment #' . $id);
                $icon    = 'bi-clipboard-check';
                break;
            case 'school_year':
            case 'batch':
                $primary = $sy ? "SY {$sy}" : null;
                $icon    = 'bi-calendar-range';
                break;
            default:
                $primary = $name ?: $student_name ?: $student_id_no;
        }

        if ($primary === null && empty($context_bits)) {
            return null;
        }

        if ($primary === null) {
            $primary = ucfirst(str_replace('_', ' ', $type)) . ($id > 0 ? ' #' . $id : '');
        }

        return target_box($primary, $context_bits ? implode(' · ', $context_bits) : null, $icon);
    }
}

if (!function_exists('target_box')) {
    function target_box(string $primary, ?string $secondary = null, ?string $icon = null): string {
        $html = '<div class="target-display">';
        if ($icon) $html .= '<i class="bi ' . $icon . ' target-icon"></i>';
        $html .= '<div class="target-text">';
        $html .= '<div class="target-primary">' . htmlspecialchars($primary) . '</div>';
        if ($secondary !== null && $secondary !== '') {
            $html .= '<div class="target-secondary">' . htmlspecialchars($secondary) . '</div>';
        }
        $html .= '</div></div>';
        return $html;
    }
}

if (!function_exists('render_audit_details')) {
    function render_audit_details(string $action, array $meta): string {
        if (empty($meta)) {
            return '<span class="meta-empty">—</span>';
        }

        // If it's a diff (update action)
        if (isset($meta['changes']) && is_array($meta['changes'])) {
            $html = '<div class="meta-label" style="margin-bottom:.5rem;color:var(--text-3);">Changes Made:</div>';
            foreach ($meta['changes'] as $field => $diff) {
                $from = friendly_value($diff['from'] ?? null);
                $to   = friendly_value($diff['to']   ?? null);
                $html .= '<div class="change-row">'
                      .    '<span class="key">' . htmlspecialchars(friendly_label((string)$field)) . ':</span>'
                      .    '<span class="from">' . htmlspecialchars($from) . '</span>'
                      .    '<span class="arrow"><i class="bi bi-arrow-right"></i></span>'
                      .    '<span class="to">'   . htmlspecialchars($to)   . '</span>'
                      .  '</div>';
            }
            $rest = $meta;
            unset($rest['changes']);
            if (!empty($rest)) {
                $html .= '<div class="kv-list" style="margin-top:.5rem">';
                foreach ($rest as $k => $v) {
                    if ($v === null || $v === '') continue;
                    $html .= '<div class="kv"><span class="kv-k">' . htmlspecialchars(friendly_label((string)$k)) . ':</span> '
                           .      '<span class="kv-v">' . htmlspecialchars(friendly_value($v)) . '</span></div>';
                }
                $html .= '</div>';
            }
            return $html;
        }

        // Standard details
        $html = '<div class="kv-list">';
        foreach ($meta as $k => $v) {
            if ($k === 'page' || $v === null || $v === '') continue;
            
            // Highlight specific important fields
            $is_important = in_array($k, ['name', 'student_name', 'role', 'reason', 'status', 'filename']);
            $val_class = $is_important ? 'kv-v highlight' : 'kv-v';
            
            $html .= '<div class="kv"><span class="kv-k">' . htmlspecialchars(friendly_label((string)$k)) . ':</span> '
                   .      '<span class="' . $val_class . '">' . htmlspecialchars(friendly_value($v)) . '</span></div>';
        }
        $html .= '</div>';

        return $html;
    }
}

$page_title = 'Audit Log • USAT Admin';

$page_css = <<<'CSS'
    .filter-bar{grid-template-columns:minmax(180px,1.6fr) minmax(200px,1.6fr) minmax(110px,auto) auto;align-items:end}

    .table-scroll{max-height:70vh}

    .action-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .7rem;border-radius:999px;font-size:.72rem;font-weight:700;white-space:nowrap;border:1px solid transparent}
    .action-badge i{font-size:.75rem}
    .action-badge.danger   {background:var(--red-soft);color:var(--red);border-color:color-mix(in srgb, var(--red) 22%, transparent)}
    .action-badge.success  {background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb, var(--green) 22%, transparent)}
    .action-badge.warning  {background:var(--amber-soft);color:var(--amber);border-color:color-mix(in srgb, var(--amber) 22%, transparent)}
    .action-badge.info     {background:#dbeafe;color:#2563eb;border-color:rgba(37,99,235,.22)}
    .action-badge.primary  {background:var(--accent-soft);color:var(--accent);border-color:color-mix(in srgb, var(--accent) 22%, transparent)}
    .action-badge.muted    {background:var(--surface-2);color:var(--text-2);border-color:var(--border)}
    .action-badge.secondary{background:var(--border);color:var(--text)}

    .target-pill{display:inline-block;padding:.2rem .55rem;border-radius:6px;font-size:.72rem;font-weight:600;background:var(--surface-2);color:var(--text-2);border:1px solid var(--border);font-family:ui-monospace,monospace}
    .target-display{display:flex;align-items:flex-start;gap:.5rem;min-width:0}
    .target-icon{color:var(--accent);font-size:.95rem;margin-top:2px;flex-shrink:0}
    .target-text{min-width:0}
    .target-primary{font-weight:600;font-size:.82rem;color:var(--text);line-height:1.25;word-break:break-word}
    .target-secondary{font-size:.72rem;color:var(--text-2);line-height:1.3;margin-top:.1rem;word-break:break-word}

    .admin-cell{display:flex;align-items:center;gap:.55rem;min-width:0}
    .admin-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex-shrink:0;box-shadow:0 0 0 2px color-mix(in srgb, var(--accent) 15%, transparent)}
    .admin-info{min-width:0}
    .admin-info .nm{font-weight:600;font-size:.82rem;line-height:1.2;color:var(--text)}
    .admin-info .un{font-size:.72rem;color:var(--text-2);font-variant-numeric:tabular-nums}

    .meta-cell{max-width:440px}
    .meta-empty{color:var(--text-2);font-style:italic;font-size:.78rem}
    .meta-label{font-size:.66rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:.35rem}

    .kv-list{display:flex;flex-direction:column;gap:.25rem}
    .kv{display:flex;gap:.4rem;align-items:baseline;font-size:.78rem;line-height:1.4}
    .kv-k{color:var(--text-2);font-weight:500;flex-shrink:0;min-width:100px}
    .kv-v{color:var(--text);font-weight:600;word-break:break-word;font-size:.76rem;background:var(--surface-2);padding:.1rem .4rem;border-radius:6px;border:1px solid var(--border)}
    .kv-v.highlight { color: var(--accent); border-color: color-mix(in srgb, var(--accent) 30%, transparent); background: var(--accent-soft); }

    .change-row{display:flex;gap:.5rem;align-items:baseline;font-size:.78rem;padding:.3rem 0;border-bottom:1px dashed var(--border);line-height:1.4}
    .change-row:last-child{border-bottom:none}
    .change-row .key{font-weight:600;color:var(--text-2);flex-shrink:0;min-width:90px;}
    .change-row .from{color:var(--red);text-decoration:line-through;opacity:.75;font-size:.74rem; background:var(--red-soft); padding:.1rem .3rem; border-radius:4px;}
    .change-row .to{color:var(--green);font-weight:600;font-size:.74rem; background:var(--green-soft); padding:.1rem .3rem; border-radius:4px;}
    .change-row .arrow{color:var(--text-3); font-size:.7rem;}

    .time-cell{white-space:nowrap}
    .time-cell .d{font-size:.78rem;font-weight:600;color:var(--text)}
    .time-cell .t{font-size:.72rem;color:var(--text-2);font-variant-numeric:tabular-nums}

    .restricted-pill{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .7rem;border-radius:999px;font-size:.72rem;font-weight:600;background:var(--red-soft);color:var(--red);border:1px solid color-mix(in srgb, var(--red) 22%, transparent)}

    @media (max-width:1100px){
        .filter-bar{grid-template-columns:1fr 1fr}
        .filter-bar .actions{grid-column:1 / -1;justify-content:flex-end}
    }
CSS;

require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/sidebar.php';
?>

<div class="main-content" id="mainContent">

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle" aria-label="Open menu">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <h2>Audit Log</h2>
                <p class="sub">
                    System activity trail
                    <?php if ($action_filter): ?>
                        · filtered by <strong><?= htmlspecialchars($action_filter) ?>*</strong>
                    <?php endif; ?>
                    <?php if ($admin_filter): ?>
                        <?php
                        $filtered_name = '';
                        foreach ($admin_list as $adm) {
                            if ((int)$adm['id'] === $admin_filter) { $filtered_name = $adm['fullname']; break; }
                        }
                        ?>
                        · filtered by <strong><?= htmlspecialchars($filtered_name ?: "admin #$admin_filter") ?></strong>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <span class="restricted-pill"><i class="bi bi-shield-lock"></i> Restricted Area</span>
            <button class="theme-btn" id="themeToggle" title="Toggle theme">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-activity"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format((int)($stats['total'] ?? 0)) ?></div>
                <div class="stat-label">Events (30 days)</div>
            </div>
        </div>
        <div class="stat-card tone-green">
            <div class="stat-icon"><i class="bi bi-box-arrow-in-right"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format((int)($stats['logins'] ?? 0)) ?></div>
                <div class="stat-label">Logins</div>
            </div>
        </div>
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="bi bi-plus-circle"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format((int)($stats['creates'] ?? 0)) ?></div>
                <div class="stat-label">Creates</div>
            </div>
        </div>
        <div class="stat-card tone-amber">
            <div class="stat-icon"><i class="bi bi-pencil"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format((int)($stats['updates'] ?? 0)) ?></div>
                <div class="stat-label">Updates</div>
            </div>
        </div>
        <div class="stat-card tone-red">
            <div class="stat-icon"><i class="bi bi-trash3"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format((int)($stats['deletes'] ?? 0)) ?></div>
                <div class="stat-label">Deletes</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-person-check"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format((int)($stats['unique_admins'] ?? 0)) ?></div>
                <div class="stat-label">Active Admins</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-funnel"></i> Filters</h3>
            <span class="hint">Showing last <?= (int)$limit ?> of <?= (int)count($rows) ?> events</span>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-bar">
                <div class="field">
                    <label for="f_action">Action</label>
                    <select name="action" id="f_action">
                        <option value="">All actions</option>
                        <?php foreach ($action_list as $a): ?>
                            <option value="<?= htmlspecialchars($a['action']) ?>" <?= $action_filter === $a['action'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(pretty_action($a['action'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_admin">Admin</label>
                    <select name="admin_id" id="f_admin">
                        <option value="">All admins</option>
                        <?php foreach ($admin_list as $adm): ?>
                            <option value="<?= (int)$adm['id'] ?>" <?= $admin_filter === (int)$adm['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($adm['fullname']) ?>
                                <?php if (!empty($adm['username'])): ?>(@<?= htmlspecialchars($adm['username']) ?>)<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_limit">Limit</label>
                    <select name="limit" id="f_limit">
                        <?php foreach ([50, 100, 250, 500, 1000] as $n): ?>
                            <option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= $n ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check2 me-1"></i> Apply
                    </button>
                    <a href="<?= URL_PREFIX ?>/audit_log.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <?php if (empty($rows)): ?>
            <div class="empty">
                <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                <div class="empty-title">No audit events found</div>
                <div class="empty-sub">Try adjusting the filters or widening the time window.</div>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table-admin">
                    <thead>
                        <tr>
                            <th style="width:130px">When</th>
                            <th style="width:200px">Admin</th>
                            <th style="width:200px">Action</th>
                            <th style="width:260px">Target</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $badge_class = action_badge_class($r['action']);
                        $icon        = action_icon($r['action']);
                        $meta        = pretty_meta($r['meta'] ?? null);
                        $initials    = '?';
                        if (!empty($r['admin_name'])) {
                            $parts = explode(' ', trim($r['admin_name']));
                            $initials = strtoupper(substr($parts[0] ?? '?', 0, 1) . substr(end($parts) ?: '', 0, 1));
                        }
                    ?>
                        <tr>
                            <td class="time-cell">
                                <div class="d"><?= htmlspecialchars(date('M d, Y', strtotime($r['created_at']))) ?></div>
                                <div class="t"><?= htmlspecialchars(date('H:i:s', strtotime($r['created_at']))) ?></div>
                            </td>
                            <td>
                                <div class="admin-cell">
                                    <div class="admin-avatar"><?= htmlspecialchars($initials) ?></div>
                                    <div class="admin-info">
                                        <div class="nm"><?= htmlspecialchars($r['admin_name'] ?? 'System / Public') ?></div>
                                        <?php if (!empty($r['admin_username'])): ?>
                                            <div class="un">@<?= htmlspecialchars($r['admin_username']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="action-badge <?= $badge_class ?>">
                                    <i class="bi <?= $icon ?>"></i>
                                    <?= htmlspecialchars(pretty_action($r['action'])) ?>
                                </span>
                            </td>
                            <td><?= render_target($r, $lookup) ?></td>
                            <td class="meta-cell"><?= render_audit_details($r['action'], $meta) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>