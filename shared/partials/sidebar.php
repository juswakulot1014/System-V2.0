<?php
/**
 * shared/partials/sidebar.php
 *
 * Expected in scope:
 *   $__nav_section  string  — dashboard | students | enrollment | grading | reports | system | profile
 *   $conn           mysqli
 *
 * Uses constants: URL_PREFIX, ASSET_BASE.
 */

$__current_section = (string)($__nav_section ?? '');
$__current_script  = basename($_SERVER['SCRIPT_NAME'] ?? '');
$__super           = has_role('superadmin');
$__pending_count   = get_pending_count($conn);

$__accounts_scripts = ['create_account.php', 'change_user_password.php', 'storage_health.php'];
$__audit_scripts    = ['audit_log.php'];

$__accounts_active = in_array($__current_script, $__accounts_scripts, true);
$__audit_active    = in_array($__current_script, $__audit_scripts, true);

$__a = fn(string $sec): string =>
    $sec === $__current_section ? ' class="active"' : '';
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="<?= ASSET_BASE ?>/assets/img/usat.jpg" alt="USAT"
             onerror="this.style.background='var(--accent)'">
        <div class="brand-text">
            <span class="brand-name">USAT <?= $__super ? 'Admin' : 'Registrar' ?></span>
            <span class="brand-sub">Enrollment System</span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Overview</div>

        <a href="<?= URL_PREFIX ?>/dashboard.php"<?= $__a('dashboard') ?>>
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>

        <a href="<?= URL_PREFIX ?>/student_profile.php"<?= $__a('students') ?>>
            <i class="bi bi-people-fill"></i> Students
        </a>

        <a href="<?= URL_PREFIX ?>/pending_enrollments.php"<?= $__a('enrollment') ?>>
            <i class="bi bi-hourglass-split"></i> Pending
            <?php if ($__pending_count > 0): ?>
                <span style="margin-left:auto;background:#d97706;color:#fff;font-size:.68rem;padding:.15rem .5rem;border-radius:999px;font-weight:700;line-height:1">
                    <?= $__pending_count ?>
                </span>
            <?php endif; ?>
        </a>

        <a href="<?= URL_PREFIX ?>/promote.php"<?= $__a('grading') ?>>
            <i class="bi bi-rocket-takeoff"></i> Promote
        </a>

        <a href="<?= URL_PREFIX ?>/graduates.php"<?= $__a('grading') ?>>
            <i class="bi bi-mortarboard-fill"></i> Graduates
        </a>

        <a href="<?= URL_PREFIX ?>/reports.php"<?= $__a('reports') ?>>
            <i class="bi bi-file-earmark-bar-graph"></i> Reports
        </a>

        <?php if ($__super): ?>
        <div class="nav-label" style="margin-top:.5rem">Administration</div>
        <a href="<?= URL_PREFIX ?>/create_account.php"<?= $__accounts_active ? ' class="active"' : '' ?>>
            <i class="bi bi-person-plus"></i> Accounts
        </a>
        <a href="<?= URL_PREFIX ?>/audit_log.php"<?= $__audit_active ? ' class="active"' : '' ?>>
            <i class="bi bi-shield-check"></i> Audit Log
        </a>
        <?php endif; ?>

        <div class="nav-label" style="margin-top:.5rem">Account</div>
        <a href="<?= URL_PREFIX ?>/profile.php"<?= $__a('profile') ?>>
            <i class="bi bi-person-gear"></i> My Profile
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="<?= URL_PREFIX ?>/logout.php" class="btn btn-outline-danger btn-sm w-100">
            <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
    </div>
</aside>