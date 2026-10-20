<?php
declare(strict_types=1);

/**
 * /errors/index.php
 * Prevent the errors folder itself from showing a listing or bouncing to login.
 */

header('Location: /', true, 302);
exit;