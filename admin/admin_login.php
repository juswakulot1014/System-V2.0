<?php
// admin/admin_login.php — superadmin-only login.
// Real logic: shared/auth/login_page.php
$LOGIN_ROLE     = 'superadmin';
$LOGIN_REDIRECT = 'dashboard.php';
require __DIR__ . '/../shared/auth/login_page.php';