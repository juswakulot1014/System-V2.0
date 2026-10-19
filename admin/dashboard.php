<?php
// admin/dashboard.php — superadmin dashboard.
// Real logic: shared/pages/dashboard.php
$REQUIRE_ROLE = ['registrar', 'superadmin'];
require __DIR__ . '/../shared/pages/dashboard.php';