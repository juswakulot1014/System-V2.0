<?php
// registrar/admin_login.php — registrar login.
// Real logic: shared/auth/login_page.php
// (superadmin may also log in here — allowed by login_allowed_roles())
$LOGIN_ROLE     = 'registrar';
$LOGIN_REDIRECT = 'dashboard.php';
require __DIR__ . '/../shared/auth/login_page.php';