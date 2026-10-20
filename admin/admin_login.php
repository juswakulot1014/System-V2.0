<?php
// admin/admin_login.php — admin login.
// Real logic is handled by shared/auth/login_page.php

// FIXED: Allow superadmin, registrar, and teacher to log in here.
$LOGIN_ROLE     = ['superadmin', 'registrar', 'teacher'];
$LOGIN_REDIRECT = 'dashboard.php';

require __DIR__ . '/../shared/auth/login_page.php';