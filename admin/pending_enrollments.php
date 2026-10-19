<?php
// admin/pending_enrollments.php — pending review queue (superadmin).
$REQUIRE_ROLE = ['registrar', 'superadmin'];
require __DIR__ . '/../shared/pages/pending_enrollments.php';