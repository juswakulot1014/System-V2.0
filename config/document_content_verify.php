<?php
/**
 * config/document_content_verify.php
 *
 * Cross-platform text extraction + name + LRN verification for staged documents.
 *
 * Windows-specific notes:
 *   - `command -v` is a bash builtin and fails under cmd.exe; we use `where`.
 *   - The `timeout(1)` binary does not ship with Windows; we skip it there.
 *   - stderr redirection differs (`2>NUL` vs `2>/dev/null`).
 *
 * Dependencies (optional — code degrades gracefully if missing):
 *   - tesseract   (images) : https://github.com/UB-Mannheim/tesseract/wiki
 *   - pdftotext   (PDFs)   : https://github.com/oschwartz10612/poppler-windows/releases
 */

/* ============================================================
   BINARY LOOKUP
   ============================================================ */
if (!function_exists('find_binary')) {
    function find_binary(string $name): ?string
    {
        static $cache = [];
        if (array_key_exists($name, $cache)) {
            return $cache[$name];
        }

        $is_windows = stripos(PHP_OS_FAMILY, 'Windows') !== false;

        if ($is_windows) {
            $out = @shell_exec('where ' . escapeshellarg($name) . ' 2>NUL');
            if (is_string($out) && trim($out) !== '') {
                foreach (preg_split('/\r?\n/', trim($out)) as $line) {
                    $line = trim($line);
                    if ($line !== '' && is_file($line)) {
                        return $cache[$name] = $line;
                    }
                }
            }

            $fallbacks = [
                'tesseract' => [
                    'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
                    'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
                    getenv('LOCALAPPDATA') . '\\Programs\\Tesseract-OCR\\tesseract.exe',
                ],
                'pdftotext' => [
                    'C:\\poppler\\Library\\bin\\pdftotext.exe',
                    'C:\\poppler\\bin\\pdftotext.exe',
                    'C:\\Program Files\\poppler\\Library\\bin\\pdftotext.exe',
                    'C:\\Program Files\\poppler\\bin\\pdftotext.exe',
                ],
            ];
            foreach ($fallbacks[$name] ?? [] as $p) {
                if ($p && is_file($p)) {
                    return $cache[$name] = $p;
                }
            }
        } else {
            $out = @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null');
            if (is_string($out) && trim($out) !== '') {
                $first = trim(strtok(trim($out), "\n"));
                if ($first !== '' && @is_executable($first)) {
                    return $cache[$name] = $first;
                }
            }
        }

        return $cache[$name] = null;
    }
}

/* ============================================================
   SAFE COMMAND BUILDER
   ============================================================ */
if (!function_exists('build_safe_command')) {
    function build_safe_command(string $bin, string $args, int $timeout = 30): string
    {
        $is_windows = stripos(PHP_OS_FAMILY, 'Windows') !== false;
        $cmd = escapeshellarg($bin) . ' ' . $args;

        if ($is_windows) {
            return $cmd . ' 2>NUL';
        }

        return 'timeout ' . $timeout . ' ' . $cmd . ' 2>/dev/null';
    }
}

/* ============================================================
   TEXT EXTRACTION
   ============================================================ */
if (!function_exists('extract_document_text')) {
    function extract_document_text(string $path, string $mime): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        if ($mime === 'application/pdf') {
            $bin = find_binary('pdftotext');
            if ($bin === null) return null;

            $out = @shell_exec(build_safe_command(
                $bin, '-layout ' . escapeshellarg($path) . ' -'
            ));
            if (is_string($out) && trim($out) !== '') return $out;

            $out = @shell_exec(build_safe_command(
                $bin, escapeshellarg($path) . ' -'
            ));
            return (is_string($out) && trim($out) !== '') ? $out : null;
        }

        if ($mime === 'image/jpeg' || $mime === 'image/png') {
            $bin = find_binary('tesseract');
            if ($bin === null) return null;

            $out = @shell_exec(build_safe_command(
                $bin, escapeshellarg($path) . ' stdout -l eng --psm 6'
            ));
            return (is_string($out) && trim($out) !== '') ? $out : null;
        }

        return null;
    }
}

/* ============================================================
   TEXT NORMALIZATION
   ============================================================ */
if (!function_exists('normalize_name_text')) {
    function normalize_name_text(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^\p{L}\s]+/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', (string)$s);
        return trim((string)$s);
    }
}

/* ============================================================
   LRN HELPERS
   ============================================================ */
if (!function_exists('extract_digit_string')) {
    /**
     * Collapse all digit runs in a text into a single contiguous string.
     * "LRN: 1366-4203-0065" → "136642030065"
     */
    function extract_digit_string(string $text): string
    {
        return preg_replace('/\D+/', '', $text);
    }
}

if (!function_exists('find_best_lrn_match')) {
    /**
     * Find the closest 12-digit window in $digits matching $target.
     */
    function find_best_lrn_match(string $digits, string $target): array
    {
        $target     = preg_replace('/\D+/', '', $target);
        $target_len = strlen($target);

        if ($target_len === 0) {
            return ['match' => 'none', 'best' => null, 'distance' => null, 'position' => -1];
        }

        // Fast path: exact substring.
        $pos = strpos($digits, $target);
        if ($pos !== false) {
            return ['match' => 'exact', 'best' => $target, 'distance' => 0, 'position' => $pos];
        }

        $text_len = strlen($digits);

        // Text too short — compare the whole thing.
        if ($text_len < $target_len) {
            $dist = levenshtein($digits, $target);
            return [
                'match'    => $dist <= 1 ? 'close' : 'none',
                'best'     => $digits,
                'distance' => $dist,
                'position' => 0,
            ];
        }

        // Sliding window.
        $best_dist  = PHP_INT_MAX;
        $best_slice = '';
        $best_pos   = -1;

        for ($i = 0; $i <= $text_len - $target_len; $i++) {
            $slice = substr($digits, $i, $target_len);
            $dist  = levenshtein($slice, $target);
            if ($dist < $best_dist) {
                $best_dist  = $dist;
                $best_slice = $slice;
                $best_pos   = $i;
                if ($dist === 0) break;
            }
        }

        return [
            'match'    => $best_dist === 0 ? 'exact' : ($best_dist <= 1 ? 'close' : 'none'),
            'best'     => $best_slice,
            'distance' => $best_dist,
            'position' => $best_pos,
        ];
    }
}

/* ============================================================
   NAME + LRN VERIFICATION
   ============================================================ */
if (!function_exists('verify_name_in_document')) {
    function verify_name_in_document(string $doc_path, string $mime, array $name_parts): array
    {
        $text = extract_document_text($doc_path, $mime);

        // ---- No text at all ----
        if ($text === null) {
            return [
                'status'     => 'unverifiable',
                'confidence' => 0,
                'message'    => 'Could not read the document text automatically. '
                              . 'Please verify the details manually.',
                'extracted'  => null,
                'name'       => [
                    'status'  => 'unverifiable',
                    'parts'   => ['first' => null, 'middle' => null, 'last' => null],
                    'message' => 'Name could not be verified.',
                ],
                'lrn' => [
                    'status'   => 'unverifiable',
                    'expected' => (string)($name_parts['lrn'] ?? ''),
                    'best'     => null,
                    'distance' => null,
                    'message'  => 'LRN could not be verified.',
                ],
            ];
        }

        /* ---------------------------------------------------------
           NAME CHECK
           --------------------------------------------------------- */
        $norm   = normalize_name_text($text);
        $first  = normalize_name_text((string)($name_parts['first_name']  ?? ''));
        $middle = normalize_name_text((string)($name_parts['middle_name'] ?? ''));
        $last   = normalize_name_text((string)($name_parts['last_name']   ?? ''));

        $has_first = $first  !== '' && mb_strpos($norm, $first)  !== false;
        $has_last  = $last   !== '' && mb_strpos($norm, $last)   !== false;
        $has_mid   = $middle !== '' ? (mb_strpos($norm, $middle) !== false) : null;

        if ($first === '' || $last === '') {
            $name_status  = 'unverifiable';
            $name_message = 'Student name fields are incomplete.';
        } elseif ($has_first && $has_last && ($has_mid === null || $has_mid === true)) {
            $name_status  = 'match';
            $name_message = 'The student name on the document matches the form.';
        } elseif ($has_first || $has_last || $has_mid === true) {
            $name_status  = 'partial';
            $missing = [];
            if (!$has_first)        $missing[] = 'first name';
            if (!$has_last)         $missing[] = 'last name';
            if ($has_mid === false) $missing[] = 'middle name';
            $name_message = 'Only part of the name was found. Missing: ' . implode(', ', $missing) . '.';
        } else {
            $name_status  = 'mismatch';
            $name_message = 'The student name was not found on the document.';
        }

        /* ---------------------------------------------------------
           LRN CHECK
           --------------------------------------------------------- */
        $lrn_expected = preg_replace('/\D+/', '', (string)($name_parts['lrn'] ?? ''));
        $lrn_status   = 'skipped';
        $lrn_best     = null;
        $lrn_distance = null;
        $lrn_message  = 'No LRN provided on the form.';

        if (strlen($lrn_expected) === 12) {
            $digits = extract_digit_string($text);
            $m      = find_best_lrn_match($digits, $lrn_expected);

            $lrn_best     = $m['best'];
            $lrn_distance = $m['distance'];

            if ($m['match'] === 'exact') {
                $lrn_status  = 'match';
                $lrn_message = 'The LRN on the document matches the form exactly.';
            } elseif ($m['match'] === 'close') {
                $lrn_status  = 'close';
                $lrn_message = 'LRN on the document is off by ' . $m['distance']
                             . ' digit (likely OCR noise). Expected ' . $lrn_expected
                             . ', found ' . $m['best'] . '.';
            } else {
                $lrn_status  = 'mismatch';
                $lrn_message = ($m['best'] === null || $m['best'] === '')
                             ? 'No 12-digit number was found on the document.'
                             : 'LRN on the document does not match. Expected '
                               . $lrn_expected . ', best match found: ' . $m['best']
                               . ' (' . $m['distance'] . ' digits differ).';
            }
        } elseif ($lrn_expected !== '') {
            $lrn_status  = 'invalid';
            $lrn_message = 'LRN on the form is not 12 digits.';
        }

        /* ---------------------------------------------------------
           COMBINE
           --------------------------------------------------------- */
        $name_ok = $name_status === 'match';
        $lrn_ok  = $lrn_status === 'match' || $lrn_status === 'skipped';

        if ($name_ok && $lrn_ok) {
            $overall = 'match';
        } elseif ($name_status === 'mismatch'
                  && ($lrn_status === 'mismatch' || $lrn_status === 'skipped')) {
            $overall = 'mismatch';
        } else {
            $overall = 'partial';
        }

        $confidence = 0;
        if ($name_status === 'match')         $confidence += 60;
        elseif ($name_status === 'partial')   $confidence += 30;
        if ($lrn_status === 'match')          $confidence += 40;
        elseif ($lrn_status === 'close')      $confidence += 30;
        elseif ($lrn_status === 'skipped')    $confidence += 20;

        return [
            'status'     => $overall,
            'confidence' => $confidence,
            'message'    => trim($name_message . ' ' . $lrn_message),
            'extracted'  => mb_substr(trim($text), 0, 800),
            'name'       => [
                'status'  => $name_status,
                'parts'   => ['first' => $has_first, 'middle' => $has_mid, 'last' => $has_last],
                'message' => $name_message,
            ],
            'lrn' => [
                'status'   => $lrn_status,
                'expected' => $lrn_expected,
                'best'     => $lrn_best,
                'distance' => $lrn_distance,
                'message'  => $lrn_message,
            ],
        ];
    }
}

/* ============================================================
   ADAPTER — matches the shape expected by store_encrypted_file()
   in enrollment/process_enroll.php
   ============================================================ */
if (!function_exists('verify_document_content')) {
    /**
     * @param string $path       Staged file path
     * @param string $doc_label  Human label (e.g. "Form 138")
     * @param array  $context    ['mime' => '...', 'name_parts' => [...]]
     */
    function verify_document_content(string $path, string $doc_label = '', array $context = []): array
    {
        // ---- Defaults if caller didn't pass a context ----
        $mime = $context['mime'] ?? null;
        if (!$mime && is_file($path)) {
            $mime = @mime_content_type($path) ?: null;
        }

        $name_parts = $context['name_parts'] ?? [
            'first_name'  => '',
            'middle_name' => '',
            'last_name'   => '',
            'lrn'         => '',
        ];

        // ---- If the real verifier isn't available, degrade gracefully ----
        if (!function_exists('verify_name_in_document')) {
            return [
                'status'       => 'skipped',
                'score'        => 0,
                'engine'       => null,
                'matched'      => [],
                'notes'        => ['Name/LRN verifier not loaded.'],
                'text_excerpt' => null,
            ];
        }

        $r = verify_name_in_document($path, (string)$mime, $name_parts);

        // ---- Map the rich result onto the simple status enum ----
        $status = match ($r['status'] ?? 'unverifiable') {
            'match'    => 'passed',
            'mismatch' => 'failed',
            default    => 'needs_review',   // 'partial' | 'unverifiable'
        };

        // ---- Which sub-checks succeeded ----
        $matched = [];
        if (!empty($r['name']['parts']['first']))   $matched[] = 'first_name';
        if (!empty($r['name']['parts']['middle']))  $matched[] = 'middle_name';
        if (!empty($r['name']['parts']['last']))    $matched[] = 'last_name';
        if (($r['lrn']['status'] ?? '') === 'match') $matched[] = 'lrn';

        return [
            'status'       => $status,
            'score'        => (int)($r['confidence'] ?? 0),
            'engine'       => 'name-lrn-verify',
            'matched'      => $matched,
            'notes'        => array_filter([$r['message'] ?? null]),
            'text_excerpt' => $r['extracted'] ?? null,
            'detail'       => $r,
        ];
    }
}