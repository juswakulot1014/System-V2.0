<?php
// registrar/export_students.php — CSV & Word export (registrar).
// Real logic: shared/pages/export_students.php
$REQUIRE_ROLE = ['registrar', 'superadmin'];
require __DIR__ . '/../shared/pages/export_students.php';