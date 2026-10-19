<?php
// admin/view_student.php — student profile (superadmin).
// Real logic: shared/pages/view_student.php
$REQUIRE_ROLE = ['registrar', 'superadmin'];
require __DIR__ . '/../shared/pages/view_student.php';