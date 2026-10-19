<?php
// admin/sections_list.php — sections & rosters (superadmin).
// Real logic: shared/pages/sections_list.php
$REQUIRE_ROLE = ['registrar', 'superadmin'];
require __DIR__ . '/../shared/pages/sections_list.php';