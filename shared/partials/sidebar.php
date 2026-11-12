<?php
// shared/partials/sidebar.php
declare(strict_types=1);

$__sb_role = (string)($_SESSION['admin_role'] ?? '');
$__sb_id   = (int)($_SESSION['admin_id'] ?? 0);
$__sb_name = (string)($_SESSION['admin_name'] ?? 'User');

$__sb_is_superadmin = ($__sb_role === 'superadmin');
$__sb_is_registrar  = ($__sb_role === 'registrar');
$__sb_is_teacher    = ($__sb_role === 'teacher');
$__sb_is_admin_like = $__sb_is_superadmin || $__sb_is_registrar;
$__sb_can_manage    = $__sb_is_superadmin || $__sb_is_registrar;

$__sb_photo = '';
try {
    $stmt = $conn->prepare("SELECT photo FROM admins WHERE admin_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $__sb_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!empty($row['photo'])) {
            $__sb_photo = URL_PREFIX . '/profile.php?photo=1&v=' . rawurlencode((string)$row['photo']);
        }
    }
} catch (\Throwable $e) { error_log('sidebar.php: ' . $e->getMessage()); }

$__sb_initials = '';
if ($__sb_photo === '' && $__sb_name !== '') {
    foreach (preg_split('/\s+/', trim($__sb_name)) as $part) {
        if ($part !== '') $__sb_initials .= mb_strtoupper(mb_substr($part, 0, 1));
        if (mb_strlen($__sb_initials) >= 2) break;
    }
}
if ($__sb_initials === '') $__sb_initials = 'A';

$__sb_pending = 0;
if ($__sb_can_manage && function_exists('get_pending_count')) {
    try { $__sb_pending = (int)get_pending_count($conn); } catch (\Throwable $e) {}
}

$__sb_role_label = match($__sb_role) {
    'superadmin' => 'Super Admin',
    'registrar'  => 'Registrar',
    'teacher'    => 'Teacher',
    'admin'      => 'Admin',
    default      => ucfirst($__sb_role ?: 'User'),
};

$__sb_current_file = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$__sb_aliases = [
    'view_student.php'       => 'student_profile.php',
    'edit_student.php'       => 'student_profile.php',
    'export_students.php'    => 'student_profile.php',
    'sf10.php'               => 'student_profile.php',
    'add_grades.php'         => 'grades.php',
    'approve_enrollment.php' => 'pending_enrollments.php',
    'walkthrough_process.php'=> 'walkthrough_enrollment.php',
];
if (str_contains(URL_PREFIX, '/teacher')) {
    $__sb_aliases['teacher_dashboard.php'] = 'dashboard.php';
}

$__sb_link = static function (string $href, string $icon, string $label, ?int $badge = null) use ($__sb_current_file, $__sb_aliases): void {
    $href_file = basename(parse_url($href, PHP_URL_PATH) ?? '');
    $is_active = ($href_file === $__sb_current_file);
    if (!$is_active && isset($__sb_aliases[$__sb_current_file])) {
        if ($__sb_aliases[$__sb_current_file] === $href_file) $is_active = true;
    }
    $cls = 'sidebar-link' . ($is_active ? ' active' : '');
    echo '<a class="' . $cls . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
       . '<i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . ' sidebar-icon"></i>'
       . '<span class="sidebar-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
       . ($badge !== null && $badge > 0 ? '<span class="sidebar-badge">' . number_format($badge) . '</span>' : '')
       . '</a>';
};
?>
<style>
.sidebar{position:fixed;top:0;left:0;bottom:0;width:260px;background:var(--surface,#fff);border-right:1px solid var(--border,#e5e7eb);display:flex;flex-direction:column;z-index:1000;transition:transform .25s ease;font-family:'Inter',system-ui,sans-serif;overflow-y:auto;scrollbar-width:thin}
.sidebar::-webkit-scrollbar{width:6px}.sidebar::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
.sidebar-brand{display:flex;align-items:center;gap:.75rem;padding:1.1rem 1.25rem;border-bottom:1px solid var(--border);text-decoration:none;color:var(--text);flex-shrink:0}
.sidebar-brand:hover{color:var(--text)}
.sidebar-brand-logo{width:40px;height:40px;border-radius:10px;object-fit:cover;flex-shrink:0;box-shadow:0 0 0 2px var(--accent-soft)}
.sidebar-brand-text{display:flex;flex-direction:column;min-width:0;line-height:1.2}
.sidebar-brand-title{font-size:.82rem;font-weight:800;letter-spacing:-.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sidebar-brand-sub{font-size:.68rem;color:var(--text-2);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.sidebar-user{display:flex;align-items:center;gap:.65rem;padding:.9rem 1.1rem;background:var(--surface-2);border-bottom:1px solid var(--border);flex-shrink:0}
.sidebar-avatar{width:40px;height:40px;border-radius:50%;overflow:hidden;flex-shrink:0;background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;box-shadow:0 0 0 2px var(--accent-soft)}
.sidebar-avatar img{width:100%;height:100%;object-fit:cover;display:block}
.sidebar-user-info{min-width:0;flex:1}
.sidebar-user-name{font-size:.82rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.2}
.sidebar-user-role{font-size:.68rem;font-weight:600;color:var(--text-2);text-transform:uppercase;letter-spacing:.4px;margin-top:2px}
.sidebar-nav{flex:1;padding:.75rem .6rem 1rem;display:flex;flex-direction:column;gap:2px}
.sidebar-group{font-size:.62rem;font-weight:800;color:var(--text-3);text-transform:uppercase;letter-spacing:.8px;padding:.85rem .75rem .35rem;margin-top:.35rem}
.sidebar-group:first-child{margin-top:0}
.sidebar-link{display:flex;align-items:center;justify-content:flex-start;gap:.75rem;padding:.6rem .75rem;border-radius:9px;font-size:.85rem;font-weight:600;color:var(--text-2);text-decoration:none;transition:background .15s,color .15s;position:relative;line-height:1.25;margin-bottom:2px}
.sidebar-link:hover{background:var(--surface-2);color:var(--text);text-decoration:none}
.sidebar-link.active{background:#4f46e5;color:#fff;box-shadow:0 4px 10px rgba(79,70,229,.3)}
.sidebar-link.active .sidebar-icon{color:#fff}
.sidebar-icon{font-size:1.1rem;color:var(--text-3);flex-shrink:0;width:1.25rem;text-align:center;transition:color .15s}
.sidebar-link:hover .sidebar-icon{color:var(--text-2)}
.sidebar-label{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:left}
.sidebar-badge{font-size:.68rem;font-weight:800;padding:.12rem .5rem;border-radius:999px;background:var(--red-soft);color:var(--red);min-width:1.5rem;text-align:center;line-height:1.2}
.sidebar-link.active .sidebar-badge{background:rgba(255,255,255,.25);color:#fff}
.sidebar-footer{border-top:1px solid var(--border);padding:.85rem .6rem;flex-shrink:0}
.sidebar-footer-link{display:flex;align-items:center;justify-content:flex-start;gap:.75rem;padding:.6rem .75rem;border-radius:9px;font-size:.82rem;font-weight:600;color:var(--text-2);text-decoration:none;transition:background .15s,color .15s}
.sidebar-footer-link:hover{background:var(--red-soft);color:var(--red);text-decoration:none}
@media (max-width:992px){.sidebar{transform:translateX(-100%)}body.sidebar-open .sidebar{transform:translateX(0);box-shadow:0 20px 50px rgba(0,0,0,.25)}}
@media (min-width:993px){.main-content{margin-left:260px}}
</style>

<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <a class="sidebar-brand" href="<?= URL_PREFIX ?>/dashboard.php">
        <img class="sidebar-brand-logo" src="<?= ASSET_BASE ?>/assets/img/usat.jpg" alt="USAT Logo" onerror="this.style.display='none'">
        <div class="sidebar-brand-text">
            <span class="sidebar-brand-title">USAT College</span>
            <span class="sidebar-brand-sub">Enrollment System</span>
        </div>
    </a>

    <div class="sidebar-user">
        <div class="sidebar-avatar">
            <?php if ($__sb_photo !== ''): ?>
                <img src="<?= htmlspecialchars($__sb_photo, ENT_QUOTES, 'UTF-8') ?>" alt="Profile">
            <?php else: ?>
                <?= htmlspecialchars($__sb_initials, ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?= htmlspecialchars($__sb_name, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="sidebar-user-role"><?= htmlspecialchars($__sb_role_label, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </div>

    <nav class="sidebar-nav" aria-label="Primary">
        <div class="sidebar-group">Main</div>
        <?php $__sb_link(URL_PREFIX . '/dashboard.php', 'bi-speedometer2', 'Dashboard'); ?>

        <?php if ($__sb_is_admin_like || $__sb_is_teacher): ?>
            <div class="sidebar-group">Students</div>
            <?php
            $__sb_link(URL_PREFIX . '/student_profile.php', 'bi-people', 'Students');
            $__sb_link(URL_PREFIX . '/sections_list.php',   'bi-diagram-3', 'Sections');
            ?>
        <?php endif; ?>

        <?php if ($__sb_can_manage): ?>
            <div class="sidebar-group">Enrollment</div>
            <?php
            $__sb_link(
                URL_PREFIX . '/pending_enrollments.php',
                'bi-hourglass-split',
                'Pending Enrollments',
                $__sb_pending > 0 ? $__sb_pending : null
            );
            // ⬇⬇ NEW: Walkthrough Enrollment (registrar + superadmin only)
            $__sb_link(
                URL_PREFIX . '/walkthrough_enrollment.php',
                'bi-person-vcard',
                'Walkthrough Enrollment'
            );
            ?>
        <?php endif; ?>

        <?php if ($__sb_is_admin_like || $__sb_is_teacher): ?>
            <div class="sidebar-group">Academic</div>
            <?php
            $__sb_link(URL_PREFIX . '/grades.php', 'bi-journal-check', 'Grades');
            if ($__sb_can_manage) {
                $__sb_link(URL_PREFIX . '/promote.php',  'bi-rocket-takeoff', 'Promote');
                $__sb_link(URL_PREFIX . '/graduates.php','bi-mortarboard',    'Graduates');
            }
            ?>
        <?php endif; ?>

        <?php if ($__sb_is_admin_like): ?>
            <div class="sidebar-group">Reports</div>
            <?php $__sb_link(URL_PREFIX . '/reports.php', 'bi-file-earmark-text', 'Reports'); ?>
        <?php endif; ?>

        <?php if ($__sb_is_superadmin || $__sb_is_registrar): ?>
            <div class="sidebar-group">Administration</div>
            <?php
            if ($__sb_is_superadmin) {
                $__sb_link(URL_PREFIX . '/create_account.php', 'bi-shield-lock', 'Accounts');
            }
            $__sb_link(URL_PREFIX . '/teacher_assignments.php', 'bi-person-workspace', 'Teacher Assignments');
            if ($__sb_is_superadmin) {
                $__sb_link(URL_PREFIX . '/change_user_password.php', 'bi-key', 'Change Password');
                $__sb_link(URL_PREFIX . '/audit_log.php',             'bi-list-ul',    'Audit Log');
                $__sb_link(URL_PREFIX . '/storage_health.php',        'bi-hdd-stack',  'Storage Health');
            }
            ?>
        <?php endif; ?>

        <div class="sidebar-group">Account</div>
        <?php $__sb_link(URL_PREFIX . '/profile.php', 'bi-person-circle', 'My Profile'); ?>
    </nav>

    <div class="sidebar-footer">
        <a class="sidebar-footer-link" href="<?= URL_PREFIX ?>/logout.php">
            <i class="bi bi-box-arrow-right sidebar-icon"></i>
            <span class="sidebar-label">Sign Out</span>
        </a>
    </div>
</aside>