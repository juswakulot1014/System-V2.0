<?php
// registrar/admin_login.php — registrar login.
// Real logic is handled by shared/auth/login_page.php

// FIXED: Allow registrar, superadmin, and teacher to log in here.
$LOGIN_ROLE     = ['registrar', 'superadmin', 'teacher'];
$LOGIN_REDIRECT = 'dashboard.php';

require __DIR__ . '/../shared/auth/login_page.php';