<?php
declare(strict_types=1);

/**
 * Root Landing Page
 */

require_once __DIR__ . '/config/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$script_name = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$base_path = str_replace('/index.php', '', $script_name);
if ($base_path === '/' || $base_path === '\\') {
    $base_path = '';
}

if (is_logged_in()) {
    $role = current_admin_role();
    
    $redirect = match ($role) {
        'superadmin' => $base_path . '/admin/dashboard.php',
        'registrar'  => $base_path . '/registrar/dashboard.php',
        'teacher'    => $base_path . '/teacher/dashboard.php', // TEACHER ROUTE
        default      => $base_path . '/admin/admin_login.php',
    };
    
    header('Location: ' . $redirect, true, 302);
    exit;
}

header('Location: ' . $base_path . '/admin/admin_login.php', true, 302);
exit;