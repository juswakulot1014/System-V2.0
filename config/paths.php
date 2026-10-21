<?php
// config/paths.php
//
// Loads .env, defines storage constants, validates the encryption key,
// and provides path helpers used throughout the app.

declare(strict_types=1);

// ────────────────────────────────────────────────
// Load .env from project root (simple parser, no library)
// ────────────────────────────────────────────────
$__strip_env_comment = function (string $v): string {
    if ($v === '' || $v[0] === '"' || $v[0] === "'") return $v;
    if (($pos = strpos($v, ' #')) !== false) {
        $v = rtrim(substr($v, 0, $pos));
    }
    return $v;
};

$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[0] === substr($v, -1)) {
            $v = substr($v, 1, -1);
        } else {
            $v = $__strip_env_comment($v);
        }
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k]    = $v;
            $_SERVER[$k] = $v;
        }
    }
}

// ────────────────────────────────────────────────
// Storage layout (Local Disk vs Cloudinary)
// ────────────────────────────────────────────────
$isCloud = getenv('CLOUDINARY_URL') !== false;

if ($isCloud) {
    define('USE_CLOUDINARY', true);
    // Define dummy temp paths to prevent "undefined constant" errors
    define('STORAGE_DIR', sys_get_temp_dir());
    define('DOCUMENTS_DIR', sys_get_temp_dir() . DIRECTORY_SEPARATOR);
    define('STUDENTS_PHOTO_DIR', sys_get_temp_dir() . DIRECTORY_SEPARATOR);
    define('STAGING_DIR', sys_get_temp_dir() . DIRECTORY_SEPARATOR);
} else {
    define('USE_CLOUDINARY', false);
    
    $storageRoot = getenv('STORAGE_ROOT');
    if ($storageRoot === false || $storageRoot === '') {
        // config/ → project/ → htdocs/ → parent-of-htdocs/
        $storageRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'enrollment_storage';
    }
    define('STORAGE_DIR', rtrim($storageRoot, '/\\'));

    define('DOCUMENTS_DIR',      STORAGE_DIR . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR);
    define('STUDENTS_PHOTO_DIR', STORAGE_DIR . DIRECTORY_SEPARATOR . 'students'  . DIRECTORY_SEPARATOR);
    define('STAGING_DIR',        STORAGE_DIR . DIRECTORY_SEPARATOR . 'staging'   . DIRECTORY_SEPARATOR);

    foreach ([DOCUMENTS_DIR, STUDENTS_PHOTO_DIR, STAGING_DIR] as $d) {
        if (!is_dir($d)) {
            if (!@mkdir($d, 0700, true) && !is_dir($d)) {
                throw new RuntimeException("Cannot create storage directory: {$d}");
            }
        }
    }

    // Guard: refuse to run if storage ended up inside the webroot.
    $docRoot     = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $storageReal = realpath(STORAGE_DIR);
    if ($docRoot !== false && $storageReal !== false) {
        if (str_starts_with($storageReal, $docRoot . DIRECTORY_SEPARATOR)
            || $storageReal === $docRoot) {
            throw new RuntimeException(
                "SECURITY: STORAGE_DIR is inside the webroot ({$storageReal}). "
              . "Move it outside htdocs, or set STORAGE_ROOT in .env."
            );
        }
    }
}

// ────────────────────────────────────────────────
// Encryption key (must be 64 hex chars = 32 bytes)
// ────────────────────────────────────────────────
$encKey = getenv('DOC_ENC_KEY');
if (!$encKey || strlen($encKey) !== 64 || !ctype_xdigit($encKey)) {
    throw new RuntimeException(
        'DOC_ENC_KEY env var missing or invalid (need 64 hex chars). '
      . 'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
    );
}
define('DOC_ENC_KEY', $encKey);

// ────────────────────────────────────────────────
// Path helpers
// ────────────────────────────────────────────────
function document_abs_path(string $stored): string {
    return DOCUMENTS_DIR . basename($stored);
}

function student_photo_abs_path(string $stored): string {
    return STUDENTS_PHOTO_DIR . basename($stored);
}