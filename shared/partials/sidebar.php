<?php
// shared/partials/sidebar.php
$role = $_SESSION['admin_role'] ?? '';
$nav_section = $__nav_section ?? '';
$current_page = basename($_SERVER['PHP_SELF']);

// Fallback if has_role() isn't defined in auth.php
if (!function_exists('has_role')) {
    function has_role(array $roles): bool {
        return in_array($_SESSION['admin_role'] ?? '', $roles, true);
    }
}

// Dynamically resolve the logo path based on your bootstrap's ASSET_BASE
$logo_url = (defined('ASSET_BASE') ? ASSET_BASE : '') . '/assets/img/usat.jpg';

// Get pending enrollments count for the badge
$pending_count = 0;
if (isset($conn) && function_exists('get_pending_count')) {
    $pending_count = get_pending_count($conn);
}

/* ── Does the current admin have a profile photo? (cached per request) ── */
$__sidebar_has_photo = false;
if (isset($conn) && function_exists('current_admin_id')) {
    static $__sidebar_photo_checked = false;
    static $__sidebar_photo_exists  = false;
    if (!$__sidebar_photo_checked) {
        $__sidebar_photo_checked = true;
        $__sidebar_aid = (int)current_admin_id();
        if ($__sidebar_aid > 0) {
            $__sidebar_ps = $conn->prepare("SELECT photo FROM admins WHERE id = ? LIMIT 1");
            if ($__sidebar_ps) {
                $__sidebar_ps->bind_param('i', $__sidebar_aid);
                $__sidebar_ps->execute();
                $__sidebar_prow = $__sidebar_ps->get_result()->fetch_assoc();
                $__sidebar_ps->close();
                $__sidebar_photo_exists = !empty($__sidebar_prow['photo']);
            }
        }
    }
    $__sidebar_has_photo = $__sidebar_photo_exists;
}
$__sidebar_avatar_url = $__sidebar_has_photo
    ? URL_PREFIX . '/profile.php?photo=1&v=' . time()
    : '';

$__sidebar_initial = strtoupper(substr($_SESSION['admin_name'] ?? 'U', 0, 1));
$__sidebar_name    = htmlspecialchars($_SESSION['admin_name'] ?? 'User', ENT_QUOTES, 'UTF-8');
$__sidebar_role    = ucfirst(htmlspecialchars((string)$role, ENT_QUOTES, 'UTF-8'));
?>
<style>
    /* Enhanced Sidebar Styling */
    .sidebar { background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; height: 100vh; position: fixed; top: 0; left: 0; width: 260px; z-index: 1040; transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 4px 0 24px rgba(0, 0, 0, 0.02); }
    .sidebar-brand { display: flex; align-items: center; gap: 0.85rem; padding: 1.5rem 1.25rem; text-decoration: none; border-bottom: 1px solid var(--border); }
    .sidebar-logo-wrap { width: 46px; height: 46px; border-radius: 50%; background: #fff; padding: 2px; box-shadow: 0 0 0 2px var(--accent-soft), 0 4px 12px rgba(0,0,0,0.1); flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; }
    .sidebar-logo-wrap img { width: 100%; height: 100%; object-fit: contain; }
    .brand-text { display: flex; flex-direction: column; line-height: 1.15; }
    .brand-title { font-size: 1rem; font-weight: 800; color: var(--text); letter-spacing: -0.3px; }
    .brand-sub { font-size: 0.65rem; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; margin-top: 0.15rem; }

    /* Clickable user block → profile */
    .sidebar-user {
        display: flex; align-items: center; gap: 0.75rem;
        padding: 1rem; margin: 1.25rem 1rem 0.5rem;
        background: var(--surface-2); border-radius: 12px;
        border: 1px solid var(--border);
        text-decoration: none; color: inherit;
        transition: background 0.15s ease, border-color 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
        position: relative;
    }
    .sidebar-user:hover {
        background: var(--surface);
        border-color: var(--accent);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px -4px rgba(79, 70, 229, 0.25);
        color: inherit;
    }
    .sidebar-user:focus-visible {
        outline: 2px solid var(--accent);
        outline-offset: 2px;
    }
    .sidebar-user-arrow {
        font-size: 0.8rem;
        color: var(--text-3);
        transition: color 0.15s ease, transform 0.15s ease;
        margin-left: auto;
        flex-shrink: 0;
    }
    .sidebar-user:hover .sidebar-user-arrow {
        color: var(--accent);
        transform: translateX(2px);
    }

    .user-avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, var(--accent), var(--purple)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.1rem; flex-shrink: 0; overflow: hidden; }
    .user-avatar.img { background: var(--surface); }
    .user-avatar.img img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .user-info { display: flex; flex-direction: column; overflow: hidden; min-width: 0; flex: 1; }
    .user-name { font-size: 0.85rem; font-weight: 700; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .user-role { font-size: 0.7rem; color: var(--accent); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }

    .sidebar-nav { flex: 1; overflow-y: auto; padding: 0.5rem 1rem 1rem; }
    .sidebar-nav::-webkit-scrollbar { width: 4px; }
    .sidebar-nav::-webkit-scrollbar-thumb { background: var(--border); border-radius: 10px; }
    .nav-section { font-size: 0.65rem; font-weight: 800; color: var(--text-3); text-transform: uppercase; letter-spacing: 1px; margin: 1.5rem 0 0.5rem 0.5rem; }
    .sidebar-nav .nav-link { display: flex; align-items: center; gap: 0.85rem; padding: 0.7rem 1rem; margin-bottom: 0.25rem; border-radius: 10px; color: var(--text-2); font-size: 0.88rem; font-weight: 600; text-decoration: none; transition: all 0.2s ease; position: relative; }
    .sidebar-nav .nav-link i { font-size: 1.15rem; transition: transform 0.2s ease; color: var(--text-3); }
    .sidebar-nav .nav-link:hover { background: var(--surface-2); color: var(--accent); }
    .sidebar-nav .nav-link:hover i { transform: scale(1.1); color: var(--accent); }
    .sidebar-nav .nav-link.active { background: var(--accent); color: #fff; box-shadow: 0 4px 12px -4px rgba(79, 70, 229, 0.4); }
    .sidebar-nav .nav-link.active i { color: #fff; }
    [data-bs-theme="dark"] .sidebar-nav .nav-link.active { box-shadow: 0 4px 12px -4px rgba(0, 0, 0, 0.4); }
    .nav-badge { margin-left: auto; background: #ef4444; color: #fff; font-size: 0.65rem; font-weight: 800; padding: 0.15rem 0.45rem; border-radius: 999px; line-height: 1; }
    .sidebar-nav .nav-link.active .nav-badge { background: #fff; color: var(--accent); }

    .sidebar-footer { padding: 1rem; border-top: 1px solid var(--border); }
    .sidebar-footer .nav-link { color: #ef4444; padding: 0.7rem 1rem; border-radius: 10px; display: flex; align-items: center; gap: 0.85rem; font-weight: 600; font-size: 0.88rem; text-decoration: none; transition: background 0.2s ease; }
    .sidebar-footer .nav-link i { font-size: 1.15rem; }
    .sidebar-footer .nav-link:hover { background: rgba(239, 68, 68, 0.1); }

    @media (max-width: 1024px) { .sidebar { transform: translateX(-100%); } .sidebar.show { transform: translateX(0); } }
</style>

<aside class="sidebar" id="sidebar">
    <a href="<?= URL_PREFIX ?>/dashboard.php" class="sidebar-brand">
        <div class="sidebar-logo-wrap">
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="USAT Logo">
        </div>
        <div class="brand-text">
            <span class="brand-title">USAT College</span>
            <span class="brand-sub">Enrollment System</span>
        </div>
    </a>

    <!-- Clickable user block → My Profile -->
    <a href="<?= URL_PREFIX ?>/profile.php"
       class="sidebar-user"
       title="Open my profile"
       aria-label="Open my profile">
        <?php if ($__sidebar_avatar_url !== ''): ?>
            <div class="user-avatar img">
                <img src="<?= htmlspecialchars($__sidebar_avatar_url, ENT_QUOTES, 'UTF-8') ?>" alt="">
            </div>
        <?php else: ?>
            <div class="user-avatar"><?= $__sidebar_initial ?></div>
        <?php endif; ?>
        <div class="user-info">
            <span class="user-name"><?= $__sidebar_name ?></span>
            <span class="user-role"><?= $__sidebar_role ?></span>
        </div>
        <i class="bi bi-chevron-right sidebar-user-arrow" aria-hidden="true"></i>
    </a>

    <nav class="sidebar-nav">
        <div class="nav-section">Main</div>

        <a href="<?= URL_PREFIX ?>/dashboard.php" class="nav-link <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i> Dashboard
        </a>

        <a href="<?= URL_PREFIX ?>/student_profile.php" class="nav-link <?= $current_page === 'student_profile.php' || $current_page === 'view_student.php' ? 'active' : '' ?>">
            <i class="bi bi-people-fill"></i> Students
        </a>

        <a href="<?= URL_PREFIX ?>/sections_list.php" class="nav-link <?= $current_page === 'sections_list.php' ? 'active' : '' ?>">
            <i class="bi bi-diagram-3-fill"></i> Sections
        </a>

        <?php if (has_role(['teacher', 'registrar', 'superadmin'])): ?>
            <a href="<?= URL_PREFIX ?>/grades.php" class="nav-link <?= $current_page === 'grades.php' || $current_page === 'add_grades.php' ? 'active' : '' ?>">
                <i class="bi bi-journal-check"></i> Grades
            </a>

            <a href="<?= URL_PREFIX ?>/reports.php" class="nav-link <?= $current_page === 'reports.php' ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-bar-graph-fill"></i> Reports
            </a>
        <?php endif; ?>

        <?php if (has_role(['registrar', 'superadmin'])): ?>
            <div class="nav-section">Administration</div>

            <a href="<?= URL_PREFIX ?>/pending_enrollments.php" class="nav-link <?= $current_page === 'pending_enrollments.php' ? 'active' : '' ?>">
                <i class="bi bi-hourglass-split"></i> Pending Enrollments
                <?php if ($pending_count > 0): ?>
                    <span class="nav-badge"><?= $pending_count ?></span>
                <?php endif; ?>
            </a>

            <a href="<?= URL_PREFIX ?>/promote.php" class="nav-link <?= $current_page === 'promote.php' ? 'active' : '' ?>">
                <i class="bi bi-rocket-takeoff-fill"></i> Promote Students
            </a>

            <a href="<?= URL_PREFIX ?>/graduates.php" class="nav-link <?= $current_page === 'graduates.php' ? 'active' : '' ?>">
                <i class="bi bi-mortarboard-fill"></i> Graduates
            </a>

            <a href="<?= URL_PREFIX ?>/teacher_assignments.php" class="nav-link <?= $current_page === 'teacher_assignments.php' ? 'active' : '' ?>">
                <i class="bi bi-person-workspace"></i> Teacher Assignments
            </a>
        <?php endif; ?>

        <?php if (has_role(['superadmin'])): ?>
            <div class="nav-section">System</div>

            <a href="<?= URL_PREFIX ?>/create_account.php" class="nav-link <?= $current_page === 'create_account.php' ? 'active' : '' ?>">
                <i class="bi bi-shield-lock-fill"></i> Admin Accounts
            </a>

            <a href="<?= URL_PREFIX ?>/audit_log.php" class="nav-link <?= $current_page === 'audit_log.php' ? 'active' : '' ?>">
                <i class="bi bi-clock-history"></i> Audit Log
            </a>
        <?php endif; ?>

        <div class="nav-section">My Account</div>
        <a href="<?= URL_PREFIX ?>/profile.php" class="nav-link <?= $current_page === 'profile.php' ? 'active' : '' ?>">
            <i class="bi bi-person-circle"></i> My Profile
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="<?= URL_PREFIX ?>/logout.php" class="nav-link">
            <i class="bi bi-box-arrow-left"></i> Logout
        </a>
    </div>
</aside>