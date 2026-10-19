<?php
// admin/student_profile.php — student list (superadmin).
// Real logic: shared/pages/student_profile.php
$REQUIRE_ROLE = ['registrar', 'superadmin'];
require __DIR__ . '/../shared/pages/student_profile.php';