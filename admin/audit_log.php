<?php
$REQUIRE_ROLE = 'superadmin';
require_once __DIR__ . '/../config/auth.php';

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

// --- Log entries ---
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

// ============================================================
// Batch-fetch entity names
// ============================================================

$ids_by_type = [];
foreach ($rows as $r) {
    if (!empty($r['target_type']) && !empty($r['target_id'])) {
        $ids_by_type[$r['target_type']][] = (int)$r['target_id'];
    }
}

$lookup = [];

if (!empty($ids_by_type['student'])) {
    $ids = array_values(array_unique($ids_by_type['student']));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
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
        $lookup['student'][(int)$s['student_id']] = [
            'name'  => $name ?: '(unnamed)',
            'id_no' => $s['student_id_number'] ?? '',
            'lrn'   => $s['lrn'] ?? '',
        ];
    }
    $stmt->close();
}

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

if (!empty($ids_by_type['document'])) {
    $ids = array_values(array_unique($ids_by_type['document']));
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
        $lookup['document'][(int)$d['entrance_id']] = [
            'document_name' => $d['document_name'] ?? 'Document',
            'student_name'  => $student ?: null,
            'student_id_no' => $d['student_id_number'] ?? '',
            'lrn'           => $d['lrn'] ?? '',
            'submitted'     => (int)$d['submitted'],
        ];
    }
    $stmt->close();
}

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

if (!empty($ids_by_type['admin'])) {
    $ids = array_values(array_unique($ids_by_type['admin']));
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
        $lookup['admin'][(int)$a['id']] = [
            'name'     => $a['fullname'] ?? '(unnamed)',
            'username' => $a['username'] ?? '',
            'role'     => $a['role'] ?? '',
        ];
    }
    $stmt->close();
}

// ============================================================
// Filter dropdown data
// ============================================================

$action_list = $conn->query("
    SELECT DISTINCT action FROM audit_log ORDER BY action ASC
")->fetch_all(MYSQLI_ASSOC) ?: [];

$admin_list = $conn->query("
    SELECT DISTINCT adm.id, adm.fullname, adm.username
    FROM audit_log a
    INNER JOIN admins adm ON a.admin_id = adm.id
    ORDER BY adm.fullname ASC
")->fetch_all(MYSQLI_ASSOC) ?: [];

$stats = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN action LIKE 'admin.login%' THEN 1 ELSE 0 END) AS logins,
        SUM(CASE WHEN action LIKE '%.create' THEN 1 ELSE 0 END) AS creates,
        SUM(CASE WHEN action LIKE '%.update' THEN 1 ELSE 0 END) AS updates,
        SUM(CASE WHEN action LIKE '%.delete' THEN 1 ELSE 0 END) AS deletes,
        COUNT(DISTINCT admin_id) AS unique_admins
    FROM audit_log
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
")->fetch_assoc() ?: [];

$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';

// ============================================================
// Helpers (unchanged)
// ============================================================

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

function pretty_action(string $action): string {
    return str_replace(['.', '_'], [' · ', ' '], $action);
}

function pretty_meta(?string $json): array {
    if (!$json) return [];
    $data = json_decode($json, true);
    if (!is_array($data)) return [];
    unset($data['type'], $data['id']);
    return $data;
}

function friendly_label(string $key): string {
    static $map = [
        'page'          => 'Page',
        'username'      => 'Username attempted',
        'lrn'           => 'LRN',
        'student_id'    => 'Student',
        'student_name'  => 'Student',
        'sy'            => 'School Year',
        'grade'         => 'Grade',
        'grade_level'   => 'Grade Level',
        'term'          => 'Term',
        'mode'          => 'Mode',
        'scope'         => 'Scope',
        'students'      => 'Students exported',
        'from_sy'       => 'From SY',
        'to_sy'         => 'To SY',
        'from'          => 'From',
        'to'            => 'To',
        'affected'      => 'Affected rows',
        'promoted'      => 'Promoted to Grade 12',
        'graduated'     => 'Graduated',
        'assigned'      => 'Assigned',
        'sections'      => 'Sections created',
        'filename'      => 'File',
        'level'         => 'Level',
        'school'        => 'School',
        'subject'       => 'Subject',
        'name'          => 'Name',
        'role'          => 'Role',
        'type_of'       => 'Promotion type',
        'count'         => 'Selected',
        'school_year'   => 'School Year',
    ];
    return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
}

function friendly_value($v): string {
    if ($v === null || $v === '') return '—';
    if (is_bool($v)) return $v ? 'Yes' : 'No';
    if (is_array($v)) return json_encode($v, JSON_UNESCAPED_SLASHES);
    return (string)$v;
}

function render_target(array $row, array $lookup): string {
    $type = (string)($row['target_type'] ?? '');
    $id   = (int)($row['target_id'] ?? 0);

    if ($type === '' || $id === 0) {
        return '<span class="meta-empty">—</span>';
    }

    $ctx = $lookup[$type][$id] ?? null;

    switch ($type) {
        case 'student':
            if ($ctx) {
                return target_box($ctx['name'], $ctx['id_no'] ?: null, 'bi-person-fill');
            }
            return target_box("(deleted student #$id)", null, 'bi-person-dash');

        case 'grade':
            if ($ctx) {
                $sec = $ctx['student_name'] ?: null;
                if ($ctx['term']) $sec = ($sec ? $sec . ' · ' : '') . $ctx['term'];
                if ($ctx['value'] !== null) $sec = ($sec ? $sec . ' · ' : '') . 'Grade: ' . number_format((float)$ctx['value'], 2);
                return target_box($ctx['subject'], $sec, 'bi-journal-check');
            }
            return target_box("(deleted grade #$id)", null, 'bi-journal-x');

        case 'document':
            if ($ctx) {
                $sec = $ctx['student_name'] ?: null;
                if ($ctx['student_id_no']) $sec = ($sec ? $sec . ' · ' : '') . $ctx['student_id_no'];
                return target_box($ctx['document_name'], $sec, 'bi-file-earmark-text');
            }
            return target_box("(deleted document #$id)", null, 'bi-file-earmark-x');

        case 'education':
            if ($ctx) {
                $sub = $ctx['level'] . ($ctx['school'] ? ' — ' . $ctx['school'] : '');
                $sec = $ctx['student_name'] ?: null;
                return target_box($sub, $sec, 'bi-book');
            }
            return target_box("(deleted education #$id)", null, 'bi-book');

        case 'admin':
            if ($ctx) {
                $sec = $ctx['username'] ? '@' . $ctx['username'] : null;
                if ($ctx['role']) $sec = ($sec ? $sec . ' · ' : '') . ucfirst($ctx['role']);
                return target_box($ctx['name'], $sec, 'bi-person-badge');
            }
            return target_box("(deleted admin #$id)", null, 'bi-person-dash');

        case 'page':
            return '<span class="meta-empty">—</span>';

        case 'batch':
        case 'school_year':
            return '<span class="target-pill">' . htmlspecialchars(str_replace('_', ' ', $type)) . '</span>';

        default:
            return '<span class="target-pill">' . htmlspecialchars($type) . '#' . $id . '</span>';
    }
}

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

function render_audit_details(string $action, array $meta): string {
    if (empty($meta)) {
        return '<span class="meta-empty">—</span>';
    }

    if (isset($meta['changes']) && is_array($meta['changes'])) {
        $html = '<div class="meta-label">Changes:</div>';
        foreach ($meta['changes'] as $field => $diff) {
            $from = friendly_value($diff['from'] ?? null);
            $to   = friendly_value($diff['to']   ?? null);
            $html .= '<div class="change-row">'
                  .    '<span class="key">' . htmlspecialchars(friendly_label((string)$field)) . ':</span>'
                  .    '<span class="from">' . htmlspecialchars($from) . '</span>'
                  .    '<span class="arrow">→</span>'
                  .    '<span class="to">'   . htmlspecialchars($to)   . '</span>'
                  .  '</div>';
        }
        $rest = $meta;
        unset($rest['changes']);
        if (!empty($rest)) {
            $html .= '<div class="kv-list" style="margin-top:.4rem">';
            foreach ($rest as $k => $v) {
                $html .= '<div class="kv"><span class="kv-k">' . htmlspecialchars(friendly_label((string)$k)) . ':</span> '
                       .      '<span class="kv-v">' . htmlspecialchars(friendly_value($v)) . '</span></div>';
            }
            $html .= '</div>';
        }
        return $html;
    }

    $html = '<div class="kv-list">';
    foreach ($meta as $k => $v) {
        if ($k === 'page') continue;
        $html .= '<div class="kv"><span class="kv-k">' . htmlspecialchars(friendly_label((string)$k)) . ':</span> '
               .      '<span class="kv-v">' . htmlspecialchars(friendly_value($v)) . '</span></div>';
    }
    $html .= '</div>';

    return $html;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log • USAT Admin</title>

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
           Design tokens
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
            --blue-soft: #dbeafe;

            --shadow-xs: 0 1px 2px rgba(15,23,42,0.04);
            --shadow-sm: 0 1px 3px rgba(15,23,42,0.06), 0 1px 2px rgba(15,23,42,0.04);
            --shadow-md: 0 4px 12px rgba(15,23,42,0.06);
            --shadow-lg: 0 12px 32px rgba(15,23,42,0.10);

            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;

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
            --blue-soft: #1e3a5f;

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
        .topbar .sub strong{color:var(--text);font-weight:600}
        .menu-toggle{
            display:none;width:40px;height:40px;border-radius:10px;
            border:1px solid var(--border);background:var(--surface);
            color:var(--text);cursor:pointer;
            align-items:center;justify-content:center;
            transition:background .15s;
        }
        .menu-toggle:hover{background:var(--surface-2)}

        /* ============================================================
           Stat cards
           ============================================================ */
        .stat-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
            gap:1rem;margin-bottom:1.5rem;
        }
        .stat-card{
            position:relative;
            background:var(--surface);border-radius:var(--radius-lg);
            border:1px solid var(--border);box-shadow:var(--shadow-sm);
            padding:1rem 1.1rem 1rem 1.3rem;
            display:flex;align-items:center;gap:.85rem;
            transition:transform .2s, box-shadow .2s, border-color .2s;
            overflow:hidden;
        }
        .stat-card::before{
            content:'';position:absolute;left:0;top:0;bottom:0;width:4px;
            background:var(--accent);
        }
        .stat-card.tone-blue::before  {background:var(--accent)}
        .stat-card.tone-green::before {background:var(--green)}
        .stat-card.tone-red::before   {background:var(--red)}
        .stat-card.tone-purple::before{background:var(--purple)}
        .stat-card.tone-amber::before {background:var(--amber)}
        .stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);border-color:var(--border-strong)}

        .stat-icon{
            width:38px;height:38px;border-radius:10px;
            display:flex;align-items:center;justify-content:center;
            font-size:1rem;flex-shrink:0;
            background:var(--accent-soft);color:var(--accent);
        }
        .tone-green  .stat-icon{background:var(--green-soft);color:var(--green)}
        .tone-red    .stat-icon{background:var(--red-soft);color:var(--red)}
        .tone-purple .stat-icon{background:var(--purple-soft);color:var(--purple)}
        .tone-amber  .stat-icon{background:var(--amber-soft);color:var(--amber)}

        .stat-info{min-width:0;flex:1}
        .stat-info .stat-value{
            font-size:1.45rem;font-weight:700;line-height:1.1;
            letter-spacing:-.02em;font-variant-numeric:tabular-nums;
            color:var(--text);
        }
        .stat-info .stat-label{
            font-size:.68rem;color:var(--text-2);text-transform:uppercase;
            letter-spacing:.6px;margin-top:.15rem;font-weight:600;
        }

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

        /* ============================================================
           Filter bar
           ============================================================ */
        .filter-bar{
            display:grid;
            grid-template-columns:minmax(180px,1.6fr) minmax(200px,1.6fr) minmax(110px,auto) auto;
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
        .filter-bar input:focus,.filter-bar select:focus{
            border-color:var(--accent);background:var(--surface);
            box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
            outline:none;
        }
        .filter-bar .actions{display:flex;gap:.5rem;flex-wrap:nowrap}

        /* ============================================================
           Log table
           ============================================================ */
        .table-scroll{overflow-x:auto;max-height:70vh;overflow-y:auto}
        .table-log{width:100%;border-collapse:separate;border-spacing:0}
        .table-log thead th{
            background:var(--surface-2);
            font-size:.66rem;font-weight:700;color:var(--text-2);
            text-transform:uppercase;letter-spacing:.6px;
            padding:.7rem 1rem;text-align:left;
            border-bottom:1px solid var(--border);
            white-space:nowrap;
            position:sticky;top:0;z-index:2;
        }
        .table-log tbody td{
            padding:.7rem 1rem;border-bottom:1px solid var(--border);
            font-size:.82rem;vertical-align:top;
            transition:background .12s;
        }
        .table-log tbody tr:hover td{background:color-mix(in srgb, var(--accent) 4%, transparent)}
        .table-log tbody tr:last-child td{border-bottom:none}

        /* ============================================================
           Action badges
           ============================================================ */
        .action-badge{
            display:inline-flex;align-items:center;gap:.35rem;
            padding:.3rem .7rem;border-radius:999px;
            font-size:.72rem;font-weight:700;
            font-family:ui-monospace,'SF Mono',monospace;
            white-space:nowrap;
            border:1px solid transparent;
        }
        .action-badge i{font-size:.75rem}
        .action-badge.danger   {background:var(--red-soft);color:var(--red);border-color:color-mix(in srgb, var(--red) 22%, transparent)}
        .action-badge.success  {background:var(--green-soft);color:var(--green);border-color:color-mix(in srgb, var(--green) 22%, transparent)}
        .action-badge.warning  {background:var(--amber-soft);color:var(--amber);border-color:color-mix(in srgb, var(--amber) 22%, transparent)}
        .action-badge.info     {background:var(--blue-soft);color:var(--blue);border-color:color-mix(in srgb, var(--blue) 22%, transparent)}
        .action-badge.primary  {background:var(--accent-soft);color:var(--accent);border-color:color-mix(in srgb, var(--accent) 22%, transparent)}
        .action-badge.muted    {background:var(--surface-2);color:var(--text-2);border-color:var(--border)}
        .action-badge.secondary{background:var(--border);color:var(--text)}

        /* ============================================================
           Target box
           ============================================================ */
        .target-pill{
            display:inline-block;padding:.2rem .55rem;border-radius:6px;
            font-size:.72rem;font-weight:600;
            background:var(--surface-2);color:var(--text-2);
            border:1px solid var(--border);
            font-family:ui-monospace,monospace;
        }
        .target-display{display:flex;align-items:flex-start;gap:.5rem;min-width:0}
        .target-icon{color:var(--accent);font-size:.95rem;margin-top:2px;flex-shrink:0}
        .target-text{min-width:0}
        .target-primary{font-weight:600;font-size:.82rem;color:var(--text);line-height:1.25;word-break:break-word}
        .target-secondary{font-size:.72rem;color:var(--text-2);line-height:1.3;margin-top:.1rem;word-break:break-word}

        /* ============================================================
           Admin cell
           ============================================================ */
        .admin-cell{display:flex;align-items:center;gap:.55rem;min-width:0}
        .admin-avatar{
            width:32px;height:32px;border-radius:50%;
            background:linear-gradient(135deg,var(--accent),var(--accent-2));
            color:#fff;display:flex;align-items:center;justify-content:center;
            font-size:.72rem;font-weight:700;flex-shrink:0;
            box-shadow:0 0 0 2px color-mix(in srgb, var(--accent) 15%, transparent);
        }
        .admin-info{min-width:0}
        .admin-info .nm{font-weight:600;font-size:.82rem;line-height:1.2;color:var(--text)}
        .admin-info .un{font-size:.72rem;color:var(--text-2);font-variant-numeric:tabular-nums}

        /* ============================================================
           Meta / details cell
           ============================================================ */
        .meta-cell{max-width:440px}
        .meta-empty{color:var(--text-2);font-style:italic;font-size:.78rem}
        .meta-label{
            font-size:.66rem;font-weight:700;color:var(--text-2);
            text-transform:uppercase;letter-spacing:.5px;
            margin-bottom:.35rem;
        }

        .kv-list{display:flex;flex-direction:column;gap:.2rem}
        .kv{display:flex;gap:.4rem;align-items:baseline;font-size:.78rem;line-height:1.4}
        .kv-k{color:var(--text-2);font-weight:500;flex-shrink:0}
        .kv-v{
            color:var(--text);font-weight:600;word-break:break-word;
            font-family:ui-monospace,'SF Mono',monospace;font-size:.74rem;
            background:var(--surface-2);padding:.05rem .35rem;
            border-radius:5px;border:1px solid var(--border);
        }

        .change-row{
            display:flex;gap:.4rem;align-items:baseline;
            font-size:.78rem;padding:.2rem 0;
            border-bottom:1px dashed var(--border);line-height:1.4;
        }
        .change-row:last-child{border-bottom:none}
        .change-row .key{font-weight:600;color:var(--text-2);flex-shrink:0}
        .change-row .from{
            color:var(--red);text-decoration:line-through;opacity:.75;
            font-family:ui-monospace,monospace;font-size:.74rem;
        }
        .change-row .to{
            color:var(--green);font-weight:600;
            font-family:ui-monospace,monospace;font-size:.74rem;
        }
        .change-row .arrow{color:var(--text-3)}

        /* ============================================================
           Time / IP
           ============================================================ */
        .ip{
            font-family:ui-monospace,'SF Mono',monospace;
            font-size:.75rem;color:var(--text-2);
        }
        .time-cell{white-space:nowrap}
        .time-cell .d{font-size:.78rem;font-weight:600;color:var(--text)}
        .time-cell .t{
            font-size:.72rem;color:var(--text-2);
            font-variant-numeric:tabular-nums;
        }

        /* ============================================================
           Buttons
           ============================================================ */
        .btn{font-weight:500;border-radius:9px;font-size:.82rem;transition:all .15s}
        .btn-primary{background:var(--accent);border-color:var(--accent)}
        .btn-primary:hover{background:var(--accent-2);border-color:var(--accent-2)}
        .btn-outline-secondary{color:var(--text-2);border-color:var(--border)}
        .btn-outline-secondary:hover{background:var(--surface-2);color:var(--text);border-color:var(--border-strong)}
        .theme-btn{
            width:38px;height:38px;border-radius:10px;
            border:1px solid var(--border);background:var(--surface);
            color:var(--text-2);cursor:pointer;
            display:flex;align-items:center;justify-content:center;
            transition:all .15s;
        }
        .theme-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}

        /* ============================================================
           Restricted badge
           ============================================================ */
        .restricted-pill{
            display:inline-flex;align-items:center;gap:.35rem;
            padding:.3rem .7rem;border-radius:999px;
            font-size:.72rem;font-weight:600;
            background:var(--red-soft);color:var(--red);
            border:1px solid color-mix(in srgb, var(--red) 22%, transparent);
        }

        /* ============================================================
           Empty state
           ============================================================ */
        .empty{
            padding:4rem 2rem;text-align:center;color:var(--text-2);
        }
        .empty .empty-icon{
            width:64px;height:64px;border-radius:50%;
            background:var(--surface-2);color:var(--text-3);
            display:inline-flex;align-items:center;justify-content:center;
            font-size:1.6rem;margin-bottom:1rem;
        }
        .empty .empty-title{font-weight:600;color:var(--text);margin-bottom:.25rem}
        .empty .empty-sub{font-size:.85rem;color:var(--text-2)}

        /* ============================================================
           Responsive
           ============================================================ */
        @media (max-width:1100px){
            .filter-bar{grid-template-columns:1fr 1fr}
            .filter-bar .actions{grid-column:1 / -1;justify-content:flex-end}
        }
        @media (max-width:1024px){
            .sidebar{transform:translateX(-100%)}
            .sidebar.open{transform:translateX(0)}
            .main-content{margin-left:0}
            .menu-toggle{display:flex}
        }
        @media (max-width:640px){
            .stat-grid{grid-template-columns:repeat(2,1fr);gap:.75rem}
            .stat-card{padding:.85rem .85rem .85rem 1rem;gap:.6rem}
            .stat-icon{width:32px;height:32px;font-size:.85rem}
            .stat-info .stat-value{font-size:1.2rem}
            .filter-bar{grid-template-columns:1fr}
            .filter-bar .actions{grid-column:span 1;justify-content:stretch}
            .filter-bar .actions .btn{flex:1}
            .main-content{padding:1rem}
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
        <div class="brand-text">
            <span class="brand-name">USAT Admin</span>
            <span class="brand-sub">Enrollment System</span>
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

    <!-- ================= Topbar ================= -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
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

    <!-- ================= Stat grid ================= -->
    <div class="stat-grid">
        <div class="stat-card tone-blue">
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
        <div class="stat-card tone-blue">
            <div class="stat-icon"><i class="bi bi-person-check"></i></div>
            <div class="stat-info">
                <div class="stat-value"><?= number_format((int)($stats['unique_admins'] ?? 0)) ?></div>
                <div class="stat-label">Active Admins</div>
            </div>
        </div>
    </div>

    <!-- ================= Filters ================= -->
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
                                <?= htmlspecialchars($a['action']) ?>
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
                    <a href="audit_log.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- ================= Log table ================= -->
    <div class="card">
        <?php if (empty($rows)): ?>
            <div class="empty">
                <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                <div class="empty-title">No audit events found</div>
                <div class="empty-sub">Try adjusting the filters or widening the time window.</div>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table-log">
                    <thead>
                        <tr>
                            <th style="width:130px">When</th>
                            <th style="width:200px">Admin</th>
                            <th style="width:210px">Action</th>
                            <th style="width:250px">Target</th>
                            <th style="width:120px">IP</th>
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
                                        <div class="nm"><?= htmlspecialchars($r['admin_name'] ?? '(anonymous)') ?></div>
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
                            <td class="ip"><?= htmlspecialchars($r['ip_address'] ?? '—') ?></td>
                            <td class="meta-cell"><?= render_audit_details($r['action'], $meta) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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
    const m = document.cookie.match(/(?:^|; )admin_theme=([^;]+)/);
    st(m ? decodeURIComponent(m[1]) : 'light');
})();
tb.addEventListener('click', () => st(h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));
</script>
</body>
</html>