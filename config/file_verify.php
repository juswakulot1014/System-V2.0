<?php
// config/file_verify.php — Uploaded document verification.

declare(strict_types=1);

const DOC_MIME_RULES = [
    'Good Moral Certificate'                     => ['application/pdf', 'image/jpeg', 'image/png'],
    'Junior High School Certificate (Front)'     => ['application/pdf', 'image/jpeg', 'image/png'],
    'Junior High School Certificate (Back)'      => ['application/pdf', 'image/jpeg', 'image/png'],
    'NSO/PSA Birth Certificate (Original)'       => ['application/pdf', 'image/jpeg', 'image/png'],
    '2 pcs 2×2 picture'                          => ['image/jpeg', 'image/png'],
    '2 pcs. 2×2 picture'                         => ['image/jpeg', 'image/png'],
];

const DOC_SIZE_LIMITS = [
    'Good Moral Certificate'                     => 5 * 1024 * 1024,
    'Junior High School Certificate (Front)'     => 5 * 1024 * 1024,
    'Junior High School Certificate (Back)'      => 5 * 1024 * 1024,
    'NSO/PSA Birth Certificate (Original)'       => 5 * 1024 * 1024,
    '2 pcs 2×2 picture'                          => 2 * 1024 * 1024,
    '2 pcs. 2×2 picture'                         => 2 * 1024 * 1024,
];

const MAX_IMAGE_WIDTH  = 5000;
const MAX_IMAGE_HEIGHT = 5000;
const MAX_IMAGE_PIXELS = 15_000_000;
const MIN_PHOTO_SIDE = 200;

const MAGIC_SIGNATURES = [
    'application/pdf' => "\x25\x50\x44\x46\x2D",
    'image/jpeg'      => "\xFF\xD8\xFF",
    'image/png'       => "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A",
];

const DANGEROUS_PREFIXES = [
    "\x3C\x3F\x70\x68\x70",
    "\x3C\x73\x63\x72\x69\x70\x74",
    "\x3C\x68\x74\x6D\x6C",
    "\x3C\x73\x76\x67",
    "\x3C\x21\x44\x4F\x43\x54\x59\x50\x45",
    "\x3C\x25",
    "\x23\x21",
];

function verify_document_file(string $abs_path, string $doc_label): array
{
    $result = [
        'ok'     => false,
        'error'  => null,
        'mime'   => null,
        'size'   => null,
        'width'  => null,
        'height' => null,
        'hash'   => null,
        'notes'  => [],
    ];

    if (!is_file($abs_path) || !is_readable($abs_path)) {
        $result['error'] = 'File is not accessible.';
        return $result;
    }

    $size = filesize($abs_path);
    if ($size === false || $size === 0) {
        $result['error'] = 'File is empty.';
        return $result;
    }
    $result['size'] = $size;

    $limit = DOC_SIZE_LIMITS[$doc_label] ?? (5 * 1024 * 1024);
    if ($size > $limit) {
        $result['error'] = 'File exceeds ' . round($limit / 1048576, 1) . ' MB limit for this document.';
        return $result;
    }

    $fh = @fopen($abs_path, 'rb');
    if (!$fh) {
        $result['error'] = 'Cannot open file for inspection.';
        return $result;
    }
    $header = fread($fh, 32);
    fclose($fh);
    if ($header === false || strlen($header) < 4) {
        $result['error'] = 'File header is unreadable.';
        return $result;
    }

    foreach (DANGEROUS_PREFIXES as $prefix) {
        if (strncmp($header, $prefix, strlen($prefix)) === 0) {
            $result['error'] = 'File contains code-like content and was rejected.';
            return $result;
        }
    }

    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $real_mime = $fi ? finfo_file($fi, $abs_path) : false;
    if ($fi) finfo_close($fi);
    if (!$real_mime) {
        $result['error'] = 'Could not detect file type.';
        return $result;
    }
    $result['mime'] = $real_mime;

    $allowed = DOC_MIME_RULES[$doc_label] ?? ['application/pdf', 'image/jpeg', 'image/png'];
    if (!in_array($real_mime, $allowed, true)) {
        $result['error'] = "A \"{$real_mime}\" file is not valid for \"{$doc_label}\".";
        return $result;
    }

    if (isset(MAGIC_SIGNATURES[$real_mime])) {
        $sig = MAGIC_SIGNATURES[$real_mime];
        if (strncmp($header, $sig, strlen($sig)) !== 0) {
            $result['error'] = 'File content does not match its declared type.';
            return $result;
        }
    }

    if ($real_mime === 'image/jpeg' || $real_mime === 'image/png') {
        $info = @getimagesize($abs_path);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            $result['error'] = 'Image is corrupted or unreadable.';
            return $result;
        }
        $w = (int)$info[0];
        $h = (int)$info[1];
        $result['width']  = $w;
        $result['height'] = $h;

        if ($w > MAX_IMAGE_WIDTH || $h > MAX_IMAGE_HEIGHT) {
            $result['error'] = "Image is too large ({$w}×{$h}px). Max " . MAX_IMAGE_WIDTH . '×' . MAX_IMAGE_HEIGHT . '.';
            return $result;
        }
        if ($w * $h > MAX_IMAGE_PIXELS) {
            $result['error'] = 'Image has too many pixels. Please resize and re-upload.';
            return $result;
        }

        if (stripos($doc_label, '2×2') !== false || stripos($doc_label, '2x2') !== false) {
            $ratio = $h > 0 ? $w / $h : 0;
            if ($ratio < 0.7 || $ratio > 1.4) {
                $result['notes'][] = "Photo is not square ({$w}×{$h}px). Typical 2×2 photos are square.";
            }
            if ($w < MIN_PHOTO_SIDE || $h < MIN_PHOTO_SIDE) {
                $result['error'] = "Photo is too small ({$w}×{$h}px). Minimum " . MIN_PHOTO_SIDE . '×' . MIN_PHOTO_SIDE . '.';
                return $result;
            }
        }
    }

    if ($real_mime === 'application/pdf') {
        $content = @file_get_contents($abs_path);
        if ($content === false) {
            $result['error'] = 'Cannot read PDF contents.';
            return $result;
        }

        if (strncmp($content, '%PDF-', 5) !== 0) {
            $result['error'] = 'Not a valid PDF (missing header).';
            return $result;
        }
        if (strpos($content, '%%EOF') === false) {
            $result['error'] = 'PDF appears truncated (no EOF marker).';
            return $result;
        }
        if (stripos($content, '/Type') === false && stripos($content, '/Page') === false) {
            $result['error'] = 'PDF has no page structure.';
            return $result;
        }

        if (stripos($content, '/JavaScript') !== false || preg_match('#/JS[\s\(<\[\{]#', $content)) {
            $result['notes'][] = 'PDF contains JavaScript — review carefully.';
        }
        if (stripos($content, '/EmbeddedFile') !== false || stripos($content, '/Filespec') !== false) {
            $result['notes'][] = 'PDF contains embedded files.';
        }
        if (stripos($content, '/Launch') !== false) {
            $result['notes'][] = 'PDF contains external launch actions.';
        }
    }

    $hash = @hash_file('sha256', $abs_path);
    if ($hash === false) {
        $result['error'] = 'Could not compute file hash.';
        return $result;
    }
    $result['hash'] = $hash;

    $result['ok'] = true;
    return $result;
}

function verify_stored_integrity(string $decrypted_content, ?string $stored_hash): array
{
    if ($stored_hash === null || $stored_hash === '') {
        return ['ok' => true, 'warning' => 'No stored hash — legacy document, integrity not verified.'];
    }
    $actual = hash('sha256', $decrypted_content);
    if (!hash_equals($stored_hash, $actual)) {
        return ['ok' => false, 'warning' => 'File integrity check failed — the document may have been tampered with after upload.'];
    }
    return ['ok' => true, 'warning' => null];
}

function fv_human_size(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return number_format($bytes / 1048576, 2) . ' MB';
    return number_format($bytes / 1073741824, 2) . ' GB';
}