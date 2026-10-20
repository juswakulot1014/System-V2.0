<?php
// config/bootstrap.php
// Include this FIRST in every entry point. Nothing else goes above it.
declare(strict_types=1);

/* ───────────────────────────────────────────────────────────────
   URL namespace constants
   
   For /admin/dashboard.php            →  URL_PREFIX = /admin
   For /project/admin/students/x.php   →  URL_PREFIX = /project/admin
   For /teacher/admin_login.php        →  URL_PREFIX = /teacher
   
   ASSET_BASE is the parent (used for /assets/ links):
   For URL_PREFIX = /admin             →  ASSET_BASE = ''
   For URL_PREFIX = /project/admin     →  ASSET_BASE = /project
   ─────────────────────────────────────────────────────────────── */
if (!defined('URL_PREFIX')) {
    $__sn = $_SERVER['SCRIPT_NAME'] ?? '';
    // FIXED: Added 'teacher' to the regex
    if (preg_match('#^(.*/(?:admin|registrar|teacher))(/|$)#', $__sn, $__m)) {
        define('URL_PREFIX', $__m[1]);
    } else {
        define('URL_PREFIX', '');
    }
}

if (!defined('ASSET_BASE')) {
    define('ASSET_BASE', preg_replace('#/(admin|registrar|teacher)$#', '', URL_PREFIX));
}

if (!defined('LOGIN_URL')) {
    define('LOGIN_URL', URL_PREFIX . '/admin_login.php');
}

if (!defined('DASHBOARD_URL')) {
    define('DASHBOARD_URL', URL_PREFIX . '/dashboard.php');
}

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/db.php';

secure_session_start();
send_security_headers();