<?php
// registrar/student_profile.php — student list (registrar).
// Real logic: shared/pages/student_profile.php
$REQUIRE_ROLE = ['registrar', 'superadmin'];
require __DIR__ . '/../shared/pages/student_profile.php';