<?php
/**
 * config/document_content_verify.php
 *
 * Verifies uploaded enrollment documents.
 *
 *   verify_name_in_document()  → OCR / text-based identity check (JHS Certificate Front)
 *   verify_2x2_photo()         → Lightweight format check (2×2 picture, no OCR)
 *
 * PDF strategy (fastest first):
 *   1. pdftotext   — digital PDFs with a text layer    → ~150 ms, 100% accurate
 *   2. pdftoppm    — scanned PDFs (Poppler at C:\poppler) → ~3 s via OCR
 *   3. Imagick     — last resort if installed          → same as above
 */

if (!defined('DOC_VERIFY_DEBUG')) {
    define('DOC_VERIFY_DEBUG', false);
}

if (!function_exists('verify_name_in_document')) {

    /* ══════════════════════════════════════════════════════════════
       PUBLIC ENTRY POINT — IDENTITY VERIFICATION
       ══════════════════════════════════════════════════════════════ */
    function verify_name_in_document(string $file_path, string $mime, array $form_data): array
    {
        if (!is_file($file_path) || filesize($file_path) === 0) {
            return build_unverifiable_response('Uploaded file is missing or empty.');
        }

        $cleanup_paths = [];
        $image_path    = $file_path;
        $is_pdf = stripos($mime, 'pdf') !== false
               || strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) === 'pdf';

        /* ─── PDF handling — two-tier strategy ─── */
        if ($is_pdf) {

            // FAST PATH: pdftotext (digital PDFs)
            $direct_text = extract_pdf_text_with_pdftotext($file_path);

            if ($direct_text !== null && strlen(trim($direct_text)) >= 50) {
                if (DOC_VERIFY_DEBUG) error_log('[doc_verify] PDF → pdftotext fast path (' . strlen($direct_text) . ' chars)');

                $extracted_lrn  = extract_lrn($direct_text);
                $extracted_name = extract_name($direct_text);

                $lrn_result  = verify_lrn($form_data['lrn'] ?? '', $extracted_lrn);
                $name_result = verify_name(
                    $form_data['first_name']  ?? '',
                    $form_data['middle_name'] ?? '',
                    $form_data['last_name']   ?? '',
                    $extracted_name
                );

                $final = combine_verdicts($name_result, $lrn_result);

                return [
                    'status'    => $final['status'],
                    'message'   => $final['message'],
                    'extracted' => $direct_text,
                    'rotation'  => 0,
                    'score'     => 0,
                    'source'    => 'pdftotext',
                    'parts'     => $name_result['parts'],
                    'name'      => [
                        'status'  => $name_result['status'],
                        'parts'   => $name_result['parts'],
                        'message' => $name_result['message'],
                    ],
                    'lrn'       => [
                        'status'   => $lrn_result['status'],
                        'expected' => $lrn_result['expected'],
                        'best'     => $lrn_result['best'],
                        'distance' => $lrn_result['distance'],
                        'message'  => $lrn_result['message'],
                    ],
                ];
            }

            // SLOW PATH: Scanned PDF — rasterize with Poppler
            $converted = convert_pdf_first_page_to_png($file_path);
            if ($converted === null) {
                return build_unverifiable_response(
                    'Could not read this PDF. Ensure Poppler is installed at C:\\poppler or upload a JPG/PNG instead.'
                );
            }
            $image_path = $converted;
            $cleanup_paths[] = $converted;
        }

        /* ─── Image OCR pipeline ─── */
        $ocr = run_tesseract_multi_orientation($image_path);
        foreach ($ocr['cleanup'] ?? [] as $p) $cleanup_paths[] = $p;
        $raw_text = $ocr['text'] ?? '';

        if (trim($raw_text) === '') {
            cleanup_files($cleanup_paths);
            return build_unverifiable_response(
                'OCR could not read any text from this document. '
              . 'Tesseract path: ' . (find_tesseract_binary() ?: 'NOT FOUND')
            );
        }

        $extracted_lrn  = extract_lrn($raw_text);
        $extracted_name = extract_name($raw_text);

        $lrn_result  = verify_lrn($form_data['lrn'] ?? '', $extracted_lrn);
        $name_result = verify_name(
            $form_data['first_name']  ?? '',
            $form_data['middle_name'] ?? '',
            $form_data['last_name']   ?? '',
            $extracted_name
        );

        $final = combine_verdicts($name_result, $lrn_result);
        cleanup_files($cleanup_paths);

        return [
            'status'    => $final['status'],
            'message'   => $final['message'],
            'extracted' => $raw_text,
            'rotation'  => $ocr['rotation'] ?? 0,
            'score'     => $ocr['score']    ?? 0,
            'source'    => 'ocr',
            'parts'     => $name_result['parts'],
            'name'      => [
                'status'  => $name_result['status'],
                'parts'   => $name_result['parts'],
                'message' => $name_result['message'],
            ],
            'lrn'       => [
                'status'   => $lrn_result['status'],
                'expected' => $lrn_result['expected'],
                'best'     => $lrn_result['best'],
                'distance' => $lrn_result['distance'],
                'message'  => $lrn_result['message'],
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════
       PUBLIC ENTRY POINT — 2×2 PHOTO CHECK
       ══════════════════════════════════════════════════════════════ */
    function verify_2x2_photo(string $file_path, string $mime): array
    {
        if (!is_file($file_path) || filesize($file_path) === 0) {
            return ['status' => 'mismatch', 'message' => 'The 2×2 picture is missing or empty.'];
        }
        if (stripos($mime, 'pdf') !== false) {
            return ['status' => 'mismatch', 'message' => 'The 2×2 picture must be a photo (JPG or PNG), not a PDF.'];
        }
        if (stripos($mime, 'image/') !== 0) {
            return ['status' => 'mismatch', 'message' => 'The 2×2 picture must be a JPG or PNG image.'];
        }

        $info = @getimagesize($file_path);
        if (!$info || empty($info[0]) || empty($info[1])) {
            return ['status' => 'unverifiable', 'message' => 'Could not read the 2×2 picture dimensions.'];
        }

        $w = (int)$info[0];
        $h = (int)$info[1];

        if ($w < 300 || $h < 300) {
            return ['status' => 'mismatch', 'message' => "The 2×2 picture is too small ({$w}×{$h}px). Minimum is 300×300px."];
        }
        if ($w > 4000 || $h > 4000) {
            return ['status' => 'unverifiable', 'message' => "The 2×2 picture is very large ({$w}×{$h}px)."];
        }

        $ratio = $w / max($h, 1);
        if ($ratio < 0.7 || $ratio > 1.4) {
            return ['status' => 'partial', 'message' => 'The 2×2 picture is not square (ratio ' . round($ratio, 2) . ').'];
        }

        return ['status' => 'match', 'message' => "Valid 2×2 photo ({$w}×{$h}px)."];
    }

    /* ══════════════════════════════════════════════════════════════
       BINARY DISCOVERY
       ══════════════════════════════════════════════════════════════ */

    function find_tesseract_binary(): ?string
    {
        $is_windows = stripos(PHP_OS_FAMILY, 'win') !== false;

        if ($is_windows) {
            $candidates = [
                'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
                'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
                'C:\\laragon\\bin\\tesseract\\tesseract.exe',
            ];
            foreach ($candidates as $c) if (is_file($c)) return $c;
            return null;
        }

        $which = @shell_exec('command -v tesseract 2>/dev/null');
        if (is_string($which) && trim($which) !== '') return trim($which);
        foreach (['/usr/bin/tesseract','/usr/local/bin/tesseract'] as $c) {
            if (is_file($c) && is_executable($c)) return $c;
        }
        return null;
    }

    /**
     * Locate a PDF utility binary by name.
     * UPDATED: includes C:\poppler\Library\bin\ (your install location).
     */
    function find_pdf_binary(string $name): ?string
    {
        $is_windows = stripos(PHP_OS_FAMILY, 'win') !== false;

        if ($is_windows) {
            $exe = $name . '.exe';
            $candidates = [
                // ── YOUR INSTALL (found via `find` command) ──
                'C:\\poppler\\Library\\bin\\' . $exe,
                'C:\\poppler\\bin\\' . $exe,

                // ── Laragon typical locations ──
                'C:\\laragon\\bin\\poppler\\Library\\bin\\' . $exe,
                'C:\\laragon\\bin\\poppler\\bin\\' . $exe,

                // ── Program Files locations ──
                'C:\\Program Files\\poppler\\Library\\bin\\' . $exe,
                'C:\\Program Files\\poppler\\bin\\' . $exe,

                // ── Git's bundled Xpdf (pdftotext only) ──
                'C:\\Program Files\\Git\\mingw64\\bin\\' . $exe,
                'C:\\Program Files (x86)\\Git\\mingw64\\bin\\' . $exe,
            ];
            foreach ($candidates as $c) if (is_file($c)) return $c;

            // Fallback: ask Windows (may fail if not in PATH)
            $where = @shell_exec('where ' . $exe . ' 2>NUL');
            if (is_string($where) && trim($where) !== '') {
                $first = strtok(trim($where), "\r\n");
                if ($first && is_file($first)) return $first;
            }
            return null;
        }

        $which = @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null');
        if (is_string($which) && trim($which) !== '') return trim($which);
        foreach (['/usr/bin/' . $name, '/usr/local/bin/' . $name, '/opt/homebrew/bin/' . $name] as $c) {
            if (is_file($c) && is_executable($c)) return $c;
        }
        return null;
    }

    /* ══════════════════════════════════════════════════════════════
       PDF HANDLING
       ══════════════════════════════════════════════════════════════ */

    /**
     * Extract embedded text from a PDF using pdftotext.
     */
    function extract_pdf_text_with_pdftotext(string $pdf_path): ?string
    {
        $bin = find_pdf_binary('pdftotext');
        if ($bin === null) return null;

        $cmd = '"' . $bin . '" -layout -enc UTF-8 '
             . '"' . $pdf_path . '" - 2>&1';

        $out = @shell_exec($cmd);
        if (!is_string($out) || trim($out) === '') return null;

        $clean = preg_replace('/^(Syntax Warning|Error):[^\n]*\n/m', '', $out);

        return is_string($clean) ? $clean : null;
    }

    /**
     * Rasterize first page of a PDF into a PNG using Poppler (or Imagick fallback).
     */
    function convert_pdf_first_page_to_png(string $pdf_path): ?string
    {
        // 1. pdftoppm (Poppler — preferred)
        $bin = find_pdf_binary('pdftoppm');
        if ($bin !== null) {
            $tmp_base = tempnam(sys_get_temp_dir(), 'ppm_');
            @unlink($tmp_base);

            $cmd = '"' . $bin . '" -png -r 300 -f 1 -l 1 '
                 . '"' . $pdf_path . '" "' . $tmp_base . '" 2>&1';
            $out = @shell_exec($cmd);

            if (DOC_VERIFY_DEBUG) error_log('[doc_verify] pdftoppm cmd: ' . $cmd . ' → ' . trim((string)$out));

            foreach (["{$tmp_base}-1.png", "{$tmp_base}-01.png", "{$tmp_base}-001.png", "{$tmp_base}.png"] as $candidate) {
                if (is_file($candidate) && filesize($candidate) > 0) {
                    if (DOC_VERIFY_DEBUG) error_log('[doc_verify] pdftoppm produced: ' . $candidate);
                    return $candidate;
                }
            }
        }

        // 2. pdftopng (Xpdf)
        $bin2 = find_pdf_binary('pdftopng');
        if ($bin2 !== null) {
            $tmp_base = tempnam(sys_get_temp_dir(), 'png_');
            @unlink($tmp_base);

            $cmd = '"' . $bin2 . '" -r 300 -f 1 -l 1 '
                 . '"' . $pdf_path . '" "' . $tmp_base . '" 2>&1';
            @shell_exec($cmd);

            foreach (["{$tmp_base}-000001.png", "{$tmp_base}-1.png", "{$tmp_base}.png"] as $candidate) {
                if (is_file($candidate) && filesize($candidate) > 0) return $candidate;
            }
        }

        // 3. Imagick (last resort)
        if (extension_loaded('imagick')) {
            try {
                $img = new Imagick();
                $img->setResolution(300, 300);
                $img->readImage($pdf_path . '[0]');
                $img->setImageFormat('png');
                $img->setImageBackgroundColor('white');
                $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $img->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $out = tempnam(sys_get_temp_dir(), 'im_') . '.png';
                $img->writeImage($out);
                $img->clear();
                $img->destroy();
                if (is_file($out)) return $out;
            } catch (Throwable $e) {
                error_log('[doc_verify] Imagick PDF fallback failed: ' . $e->getMessage());
            }
        }

        return null;
    }

    /* ══════════════════════════════════════════════════════════════
       TESSERACT OCR
       ══════════════════════════════════════════════════════════════ */
    function run_tesseract_cmd(string $image_path, int $psm = 6): string
    {
        $tess_bin = find_tesseract_binary();
        if ($tess_bin === null) return '';

        $cmd = '"' . $tess_bin . '" "' . $image_path . '" stdout'
             . ' --psm ' . $psm
             . ' --oem 1'
             . ' --dpi 300'
             . ' -c tessedit_do_invert=0'
             . ' -l eng'
             . ' 2>&1';

        $out = @shell_exec($cmd);
        return is_string($out) ? $out : '';
    }

    /* ══════════════════════════════════════════════════════════════
       FAST ORIENTATION + PREPROCESSING + PROGRESSIVE OCR
       ══════════════════════════════════════════════════════════════ */
    function run_tesseract_multi_orientation(string $image_path): array
    {
        $best = ['text' => '', 'rotation' => 0, 'psm' => 6, 'score' => -1, 'cleanup' => []];
        $to_cleanup = [];

        $base = $image_path;
        $exif = apply_exif_orientation_gd($image_path);
        if ($exif !== null) {
            $base = $exif;
            $to_cleanup[] = $exif;
        }

        $prepped = preprocess_image_gd($base);
        if ($prepped !== null) {
            $to_cleanup[] = $prepped;
            $base = $prepped;
        }

        $attempts = [
            ['rot' => 0,   'psm' => 6],
            ['rot' => 0,   'psm' => 11],
            ['rot' => -90, 'psm' => 6],
            ['rot' => 90,  'psm' => 6],
        ];

        foreach ($attempts as $a) {
            $test_path = $base;
            if ($a['rot'] !== 0) {
                $r = rotate_image_gd($base, $a['rot']);
                if ($r === null) continue;
                $to_cleanup[] = $r;
                $test_path = $r;
            }

            $t0    = microtime(true);
            $txt   = run_tesseract_cmd($test_path, $a['psm']);
            $ms    = (int)round((microtime(true) - $t0) * 1000);
            $score = score_ocr_text($txt);

            if (DOC_VERIFY_DEBUG) {
                error_log("[doc_verify] rot={$a['rot']} psm={$a['psm']} score={$score} time={$ms}ms");
            }

            if ($score > $best['score']) {
                $best['text']     = $txt;
                $best['rotation'] = $a['rot'];
                $best['psm']      = $a['psm'];
                $best['score']    = $score;
            }

            if ($score >= 40) break;
        }

        $best['cleanup'] = $to_cleanup;
        return $best;
    }

    function score_ocr_text(string $text): int
    {
        if (trim($text) === '') return 0;

        $signal_words = [
            'Republic','Philippines','Department','Education','School','Student',
            'Name','LRN','Learner','Grade','Section','Track','Strand','Certificate',
            'Birth','Date','Age','Sex','Principal','Teacher','Parent','Guardian',
            'Signature','Junior','High','Senior','Enrollment','Form',
        ];

        $score = 0;
        foreach ($signal_words as $w) {
            $score += preg_match_all('/\b' . preg_quote($w, '/') . '\b/i', $text) * 5;
        }
        if (preg_match('/\b\d{12}\b/', $text)) $score += 30;
        if (preg_match('/[A-Z]{2,},\s+[A-Z]{2,}/', $text)) $score += 20;
        $score += min(10, (int)(strlen($text) / 200));

        return $score;
    }

    /* ══════════════════════════════════════════════════════════════
       GD IMAGE OPERATIONS
       ══════════════════════════════════════════════════════════════ */
    function apply_exif_orientation_gd(string $src): ?string
    {
        if (!function_exists('exif_read_data') || !extension_loaded('gd')) return null;

        $info = @getimagesize($src);
        if (!$info || $info['mime'] !== 'image/jpeg') return null;

        $exif = @exif_read_data($src);
        if (!$exif || empty($exif['Orientation'])) return null;

        $o = (int)$exif['Orientation'];
        if ($o === 1) return null;

        $im = @imagecreatefromjpeg($src);
        if (!$im) return null;

        $angle = 0;
        if ($o === 3)      $angle = 180;
        elseif ($o === 6)  $angle = -90;
        elseif ($o === 8)  $angle = 90;

        if ($angle !== 0) {
            $r = @imagerotate($im, $angle, 0xFFFFFF);
            imagedestroy($im);
            if (!$r) return null;
            $im = $r;
        }

        $out = tempnam(sys_get_temp_dir(), 'exif_') . '.png';
        imagepng($im, $out, 6);
        imagedestroy($im);
        return is_file($out) ? $out : null;
    }

    function preprocess_image_gd(string $src): ?string
    {
        if (!extension_loaded('gd')) return null;

        $info = @getimagesize($src);
        if (!$info) return null;

        $im = null;
        switch ($info['mime']) {
            case 'image/jpeg': $im = @imagecreatefromjpeg($src); break;
            case 'image/png':  $im = @imagecreatefrompng($src);  break;
            case 'image/webp': $im = @imagecreatefromwebp($src); break;
        }
        if (!$im) return null;

        $w = imagesx($im);
        $h = imagesy($im);
        $long = max($w, $h);
        $target = 1600;

        if ($long > $target) {
            $scale = $target / $long;
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
           $small = @imagescale($im, $nw, $nh, IMG_BILINEAR_FIXED);
            if ($small) { imagedestroy($im); $im = $small; }
        }

        @imagefilter($im, IMG_FILTER_GRAYSCALE);
        @imagefilter($im, IMG_FILTER_CONTRAST, -30);

        $out = tempnam(sys_get_temp_dir(), 'pre_') . '.png';
        imagepng($im, $out, 6);
        imagedestroy($im);
        return is_file($out) ? $out : null;
    }

    function rotate_image_gd(string $src, int $angle): ?string
    {
        if (!extension_loaded('gd')) return null;

        $info = @getimagesize($src);
        if (!$info) return null;

        $im = null;
        switch ($info['mime']) {
            case 'image/jpeg': $im = @imagecreatefromjpeg($src); break;
            case 'image/png':  $im = @imagecreatefrompng($src);  break;
            case 'image/webp': $im = @imagecreatefromwebp($src); break;
        }
        if (!$im) return null;

        $r = @imagerotate($im, $angle, 0xFFFFFF);
        imagedestroy($im);
        if (!$r) return null;

        $out = tempnam(sys_get_temp_dir(), 'rot_') . '.png';
        imagepng($r, $out, 6);
        imagedestroy($r);
        return is_file($out) ? $out : null;
    }

    /* ══════════════════════════════════════════════════════════════
       FIELD EXTRACTION
       ══════════════════════════════════════════════════════════════ */
    function extract_lrn(string $text): ?string
    {
        $flat = preg_replace('/\s+/', ' ', $text);

        if (preg_match('/\bLRN\b[^0-9]{0,20}(\d[\d\s\-]{10,18}\d)/i', $flat, $m)) {
            $d = preg_replace('/\D/', '', $m[1]);
            if (strlen($d) >= 12) return substr($d, 0, 12);
        }
        if (preg_match('/Learner[^0-9]{0,40}(\d[\d\s\-]{10,18}\d)/i', $flat, $m)) {
            $d = preg_replace('/\D/', '', $m[1]);
            if (strlen($d) >= 12) return substr($d, 0, 12);
        }
        if (preg_match('/\b(\d{12})\b/', $flat, $m)) return $m[1];

        return null;
    }

    function extract_name(string $text): ?string
    {
        $n = preg_replace('/[ \t]+/', ' ', $text);
        $n = str_replace("\r", "\n", $n);

        if (preg_match('/\bName\b[^\nA-Za-z]{0,4}[:\-]?\s*([A-Z][A-Za-z\.,\'\- ]{4,80})/i', $n, $m)) {
            $c = trim(preg_replace('/\s{2,}/', ' ', $m[1]));
            $c = preg_replace('/\s*(Age|Sex|Grade|Birth|Date|LRN|Section|School|Track|Strand).*$/i', '', $c);
            if (strlen($c) >= 4) return $c;
        }
        if (preg_match('/Name of (?:the )?Student[^\nA-Za-z]{0,4}[:\-]?\s*([A-Z][A-Za-z\.,\'\- ]{4,80})/i', $n, $m)) {
            $c = trim($m[1]);
            $c = preg_replace('/\s*(Age|Sex|Grade|Birth|Date|LRN|Section).*$/i', '', $c);
            if (strlen($c) >= 4) return $c;
        }
        if (preg_match_all('/^([A-Z][A-Z\-\']{1,30},\s+[A-Z][A-Za-z\-\'\. ]{2,60})$/m', $n, $m)) {
            foreach ($m[1] as $c) if (strlen(trim($c)) >= 6) return trim($c);
        }

        return null;
    }

    /* ══════════════════════════════════════════════════════════════
       LRN + NAME VERIFICATION
       ══════════════════════════════════════════════════════════════ */
    function verify_lrn(string $expected, ?string $found): array
    {
        $expected = trim($expected);

        if ($expected === '') {
            return ['status'=>'skipped','expected'=>'','best'=>$found,'distance'=>null,'message'=>'No LRN provided.'];
        }
        if ($found === null || $found === '') {
            return ['status'=>'unverifiable','expected'=>$expected,'best'=>null,'distance'=>null,
                    'message'=>'No LRN could be read from the document.'];
        }

        $d = levenshtein($expected, $found);
        if ($d === 0) {
            return ['status'=>'match','expected'=>$expected,'best'=>$found,'distance'=>0,
                    'message'=>'LRN matches the document exactly.'];
        }
        if ($d <= 2) {
            return ['status'=>'close','expected'=>$expected,'best'=>$found,'distance'=>$d,
                    'message'=>"LRN is very close ({$d} digit" . ($d === 1 ? '' : 's') . " off)."];
        }
        return ['status'=>'mismatch','expected'=>$expected,'best'=>$found,'distance'=>$d,
                'message'=>"LRN does not match ({$d} digits different)."];
    }

    function verify_name(string $first, string $middle, string $last, ?string $ocr_name): array
    {
        $empty = ['first'=>false,'middle'=>null,'last'=>false];

        if ($ocr_name === null || trim($ocr_name) === '') {
            return ['status'=>'unverifiable','parts'=>$empty,'message'=>'No name could be read from the document.'];
        }

        $clean = str_replace([',', '.', '-'], ' ', $ocr_name);
        $clean = preg_replace('/\s+/', ' ', strtoupper(trim($clean)));
        $tokens = array_values(array_filter(explode(' ', $clean), fn($t) => $t !== ''));

        if (empty($tokens)) {
            return ['status'=>'unverifiable','parts'=>$empty,'message'=>'Name was empty.'];
        }

        $ff = tokenize_name_field($first);
        $fm = tokenize_name_field($middle);
        $fl = tokenize_name_field($last);

        $parts = [
            'first'  => match_token_group($ff, $tokens, 1),
            'middle' => null,
            'last'   => match_token_group($fl, $tokens, 1),
        ];
        if (!empty($fm)) $parts['middle'] = match_token_group($fm, $tokens, 1, true);

        $first_ok  = $parts['first']  === true;
        $last_ok   = $parts['last']   === true;
        $middle_ok = $parts['middle'] === true;
        $middle_na = $parts['middle'] === null;

        if ($first_ok && $last_ok && ($middle_ok || $middle_na)) {
            return ['status'=>'match','parts'=>$parts,'message'=>'First and last name matched the document.'];
        }

        if ($first_ok || $last_ok) {
            $missing = [];
            if (!$first_ok) $missing[] = 'first name';
            if (!$last_ok)  $missing[] = 'last name';
            if ($parts['middle'] === false) $missing[] = 'middle name';
            return ['status'=>'partial','parts'=>$parts,
                    'message'=>'Partial match. Missing: ' . implode(', ', $missing) . '.'];
        }

        return ['status'=>'mismatch','parts'=>$parts,
                'message'=>'Neither first nor last name appear in the document.'];
    }

    function tokenize_name_field(string $field): array
    {
        $field = strtoupper(trim($field));
        if ($field === '') return [];
        $field = preg_replace('/[^A-Z\s\-]/', ' ', $field);
        $field = preg_replace('/\s+/', ' ', $field);
        return array_values(array_filter(explode(' ', $field), fn($t) => $t !== ''));
    }

    function match_token_group(array $needles, array $haystack, int $max = 1, bool $initial = false): bool
    {
        if (empty($needles)) return false;

        foreach ($needles as $needle) {
            $found = false;
            foreach ($haystack as $h) {
                if (levenshtein($needle, $h) <= $max) { $found = true; break; }
                if (strlen($needle) >= 3 && strpos($h, $needle) !== false) { $found = true; break; }
                if ($initial && strlen($h) === 1 && strlen($needle) >= 2 && $h[0] === $needle[0]) { $found = true; break; }
            }
            if (!$found) return false;
        }
        return true;
    }

    /* ══════════════════════════════════════════════════════════════
       FINAL VERDICT + UTILITIES
       ══════════════════════════════════════════════════════════════ */
    function combine_verdicts(array $name, array $lrn): array
    {
        $n = $name['status'];
        $l = $lrn['status'];

        if ($n === 'match' && ($l === 'match' || $l === 'skipped')) {
            return ['status'=>'match','message'=>'Name matches the document.'];
        }
        if ($n === 'match' && $l === 'close') {
            return ['status'=>'match','message'=>'Name matches; LRN very close.'];
        }
        if ($n === 'match' && $l === 'unverifiable') {
            return ['status'=>'partial','message'=>'Name matches but LRN could not be read.'];
        }
        if (($l === 'match' || $l === 'close') && $n === 'partial') {
            return ['status'=>'partial','message'=>'LRN matches but some name parts missing.'];
        }
        if ($n === 'mismatch' || $l === 'mismatch') {
            $bits = [];
            if ($n === 'mismatch') $bits[] = 'name';
            if ($l === 'mismatch') $bits[] = 'LRN';
            return ['status'=>'mismatch',
                    'message'=>'The document does not match the ' . implode(' or ', $bits) . '.'];
        }
        return ['status'=>'partial','message'=>'Document partially matches; manual review recommended.'];
    }

    function build_unverifiable_response(string $reason): array
    {
        return [
            'status'    => 'unverifiable',
            'message'   => $reason,
            'extracted' => '',
            'rotation'  => 0,
            'score'     => 0,
            'source'    => 'none',
            'parts'     => ['first'=>false,'middle'=>null,'last'=>false],
            'name'      => [
                'status'  => 'unverifiable',
                'parts'   => ['first'=>false,'middle'=>null,'last'=>false],
                'message' => $reason,
            ],
            'lrn'       => [
                'status'   => 'unverifiable',
                'expected' => '',
                'best'     => null,
                'distance' => null,
                'message'  => $reason,
            ],
        ];
    }

    function cleanup_files(array $paths): void
    {
        foreach ($paths as $p) if (is_string($p) && is_file($p)) @unlink($p);
    }
}