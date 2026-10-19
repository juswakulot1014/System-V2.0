<?php
// admin/edit_student.php — edit student (superadmin).
// Real logic: shared/pages/edit_student.php
$REQUIRE_ROLE = ['registrar', 'superadmin'];
require __DIR__ . '/../shared/pages/edit_student.php';