<?php
declare(strict_types=1);

/**
 * shared/lib/grades_email.php
 *
 * Builds and sends the "your grades for this term" email.
 *
 * CLI-SAFE: This file requires ONLY config/mailer.php. It must NOT
 * require auth.php, security.php, or bootstrap.php — those call exit()
 * when there's no HTTP session, which would silently kill the CLI worker.
 *
 * Used by:
 *   - shared/pages/add_grades.php   (HTTP context)
 *   - bin/process_email_queue.php   (CLI context)
 */

require_once __DIR__ . '/../../config/mailer.php';

/* ══════════════════════════════════════════════════════════════
   Small helpers
   ══════════════════════════════════════════════════════════════ */

if (!function_exists('ge_e')) {
    function ge_e(?string $v, string $fallback = '—'): string {
        $v = trim((string)$v);
        return htmlspecialchars($v === '' ? $fallback : $v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ge_mask_lrn')) {
    function ge_mask_lrn(string $lrn): string {
        $lrn = trim($lrn);
        if ($lrn === '') return '';
        if (strlen($lrn) <= 4) return str_repeat('*', strlen($lrn));
        return str_repeat('*', strlen($lrn) - 4) . substr($lrn, -4);
    }
}

if (!function_exists('ge_format_name')) {
    function ge_format_name(array $s): string {
        $mi  = !empty($s['middle_name']) ? ' ' . strtoupper(substr(trim($s['middle_name']), 0, 1)) . '.' : '';
        $ext = !empty($s['ext_name'])    ? ' ' . trim($s['ext_name']) : '';
        return trim(($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? '') . $mi . $ext);
    }
}

/* ══════════════════════════════════════════════════════════════
   Cached logo flattener (survives across worker runs)
   ══════════════════════════════════════════════════════════════ */

if (!function_exists('ge_logo_cached')) {
    function ge_logo_cached(string $dir): ?string {
        if (!extension_loaded('gd')) return null;

        $files = glob($dir . 'usat.*');
        if (empty($files)) return null;

        $source       = $files[0];
        $source_mtime = @filemtime($source) ?: 0;
        $cache_path   = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
                      . 'usat_logo_' . md5($source . '|' . $source_mtime) . '.jpg';

        if (is_file($cache_path) && filesize($cache_path) > 0) {
            return $cache_path;
        }

        $info = @getimagesize($source);
        if (!$info) return null;

        $mime = $info['mime'];
        $w    = $info[0];
        $h    = $info[1];

        switch ($mime) {
            case 'image/jpeg': $im = @imagecreatefromjpeg($source); break;
            case 'image/png':  $im = @imagecreatefrompng($source);  break;
            case 'image/webp': $im = @imagecreatefromwebp($source); break;
            default: return null;
        }
        if (!$im) return null;

        $size  = 100;
        $new_w = $size;
        $new_h = (int)round(($h / $w) * $size);

        $new_im = imagecreatetruecolor($new_w, $new_h);
        $white  = imagecolorallocate($new_im, 255, 255, 255);
        imagefill($new_im, 0, 0, $white);
        imagecopyresampled($new_im, $im, 0, 0, 0, 0, $new_w, $new_h, $w, $h);

        $ok = @imagejpeg($new_im, $cache_path, 90);
        imagedestroy($im);
        imagedestroy($new_im);

        if (!$ok || !is_file($cache_path)) return null;
        @chmod($cache_path, 0600);

        return $cache_path;
    }
}

/* ══════════════════════════════════════════════════════════════
   HTML template
   ══════════════════════════════════════════════════════════════ */

if (!function_exists('ge_build_html')) {
    function ge_build_html(
        array $student,
        array $grades,
        string $term,
        string $sy,
        string $section,
        string $grade_level,
        string $strand,
        ?float $term_gwa
    ): string {
        $student_name = ge_e(ge_format_name($student));
        $lrn_display  = ge_e(ge_mask_lrn((string)($student['lrn'] ?? '')));
        $section_disp = ge_e($section);
        $grade_disp   = ge_e($grade_level);
        $strand_disp  = ge_e($strand);
        $term_safe    = ge_e($term);
        $sy_safe      = ge_e($sy);

        $gwa_display = $term_gwa !== null ? number_format($term_gwa, 2) : '—';
        $gwa_color   = $term_gwa === null ? '#64748b' : ($term_gwa >= 75 ? '#059669' : '#dc2626');
        $gwa_remark  = $term_gwa === null ? '—' : ($term_gwa >= 75 ? 'Passed' : 'Failed');

        $rows_html = '';
        $passed = 0; $failed = 0;
        foreach ($grades as $g) {
            $code = ge_e($g['subject_code'] ?? null);
            $name = ge_e($g['subject_name'] ?? null);
            $gv   = $g['grade'] !== null ? (float)$g['grade'] : null;
            $rem  = $g['remarks'] ?? ($gv !== null ? ($gv >= 75 ? 'Passed' : 'Failed') : '—');
            $is_pass = $gv !== null && $gv >= 75;
            if ($gv !== null) { $is_pass ? $passed++ : $failed++; }
            $gv_str    = $gv !== null ? number_format($gv, 2) : '—';
            $gv_color  = $gv === null ? '#64748b' : ($is_pass ? '#059669' : '#dc2626');
            $rem_color = $rem === 'Passed' ? '#059669' : ($rem === 'Failed' ? '#dc2626' : '#64748b');

            $rows_html .=
                '<tr>'
              . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#334155;font-weight:600;">' . $code . '</td>'
              . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;font-size:13px;color:#0f172a;font-weight:500;">' . $name . '</td>'
              . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:center;font-size:14px;font-weight:700;color:' . $gv_color . ';font-variant-numeric:tabular-nums;">' . $gv_str . '</td>'
              . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:center;font-size:12px;font-weight:600;color:' . $rem_color . ';">' . ge_e($rem) . '</td>'
              . '</tr>';
        }

        $logo = '<img src="cid:usat_logo" width="80" height="80" alt="USAT Logo" style="display:block;margin:0 auto 12px;border-radius:50%;object-fit:contain;border:3px solid #ffffff;background:#ffffff;box-shadow:0 4px 6px rgba(0,0,0,0.1);">';
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Term Grades — {$term_safe}</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:'Segoe UI',Arial,sans-serif;color:#1e293b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 12px;">
  <tr><td align="center">
    <table role="presentation" width="620" cellpadding="0" cellspacing="0" style="max-width:620px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 4px 20px rgba(15,23,42,.08);border:1px solid #e2e8f0;">
      <tr><td style="background:#1e3a8a;padding:40px 30px 30px;text-align:center;color:#ffffff;border-bottom:4px solid #fbbf24;">
        {$logo}
        <div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;opacity:.9;font-weight:600;margin-bottom:8px">Republic of the Philippines</div>
        <div style="font-family:Georgia,serif;font-size:24px;font-weight:bold;margin-top:4px;line-height:1.2">USAT College Sagay City Inc.</div>
        <div style="font-size:14px;opacity:.9;margin-top:8px;font-weight:500">Official Term Grade Report</div>
      </td></tr>
      <tr><td style="padding:36px 36px 28px;">
        <p style="margin:0 0 18px;font-size:15px;line-height:1.6;">Dear <strong>{$student_name}</strong>,</p>
        <p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#334155;">
          Your grades for <strong>{$term_safe}</strong> of School Year <strong>{$sy_safe}</strong> have been finalized. Please review the details below. If you believe any grade is incorrect, contact your teacher or the Registrar's Office within five (5) working days.
        </p>
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:6px;margin-bottom:22px;overflow:hidden;">
          <tr><td style="padding:10px 14px;background:#eef2ff;color:#1e3a72;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #cbd5e1;width:35%;">LRN</td><td style="padding:10px 14px;color:#0f172a;font-size:13px;font-weight:600;border-bottom:1px solid #cbd5e1;font-family:ui-monospace,monospace;">{$lrn_display}</td></tr>
          <tr><td style="padding:10px 14px;background:#eef2ff;color:#1e3a72;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #cbd5e1;">Grade Level</td><td style="padding:10px 14px;color:#0f172a;font-size:13px;font-weight:600;border-bottom:1px solid #cbd5e1;">{$grade_disp}</td></tr>
          <tr><td style="padding:10px 14px;background:#eef2ff;color:#1e3a72;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #cbd5e1;">Section</td><td style="padding:10px 14px;color:#0f172a;font-size:13px;font-weight:600;border-bottom:1px solid #cbd5e1;">{$section_disp}</td></tr>
          <tr><td style="padding:10px 14px;background:#eef2ff;color:#1e3a72;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Strand / Program</td><td style="padding:10px 14px;color:#0f172a;font-size:13px;font-weight:600;">{$strand_disp}</td></tr>
        </table>
        <p style="margin:0 0 10px;font-size:13px;font-weight:700;color:#0f172a;text-transform:uppercase;letter-spacing:.5px;">Grades — {$term_safe}</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #cbd5e1;border-radius:6px;overflow:hidden;margin-bottom:20px;">
          <thead><tr>
            <th style="padding:10px 12px;background:#eef2ff;color:#1e3a72;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid #cbd5e1;width:20%;">Code</th>
            <th style="padding:10px 12px;background:#eef2ff;color:#1e3a72;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid #cbd5e1;">Subject</th>
            <th style="padding:10px 12px;background:#eef2ff;color:#1e3a72;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;border-bottom:1px solid #cbd5e1;width:14%;">Grade</th>
            <th style="padding:10px 12px;background:#eef2ff;color:#1e3a72;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;border-bottom:1px solid #cbd5e1;width:16%;">Remarks</th>
          </tr></thead>
          <tbody>
            {$rows_html}
            <tr>
              <td colspan="2" style="padding:12px;background:#f1f5f9;text-align:right;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:.5px;">General Weighted Average (GWA)</td>
              <td style="padding:12px;background:#f1f5f9;text-align:center;font-size:16px;font-weight:800;color:{$gwa_color};font-variant-numeric:tabular-nums;">{$gwa_display}</td>
              <td style="padding:12px;background:#f1f5f9;text-align:center;font-size:12px;font-weight:700;color:{$gwa_color};">{$gwa_remark}</td>
            </tr>
          </tbody>
        </table>
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;"><tr>
          <td style="padding:12px 16px;background:#ecfdf5;border-left:4px solid #10b981;border-radius:0 6px 6px 0;"><div style="font-size:11px;font-weight:700;color:#047857;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Passed</div><div style="font-size:18px;font-weight:800;color:#047857;">{$passed}</div></td>
          <td style="width:12px;"></td>
          <td style="padding:12px 16px;background:#fef2f2;border-left:4px solid #dc2626;border-radius:0 6px 6px 0;"><div style="font-size:11px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Failed</div><div style="font-size:18px;font-weight:800;color:#991b1b;">{$failed}</div></td>
        </tr></table>
        <p style="margin:22px 0 12px;font-size:13px;font-weight:700;color:#0f172a;text-transform:uppercase;letter-spacing:.5px;">Next Steps</p>
        <ul style="margin:0;padding-left:20px;font-size:13px;line-height:1.7;color:#334155;">
          <li style="margin-bottom:6px;">Keep this email for your personal records.</li>
          <li style="margin-bottom:6px;">If any grade is incorrect, contact your teacher or the Registrar's Office immediately.</li>
        </ul>
        <p style="margin:28px 0 0;font-size:14px;line-height:1.6;color:#334155;">Sincerely,</p>
        <p style="margin:4px 0 0;font-size:14px;line-height:1.6;color:#334155;font-weight:bold;">Office of the Registrar</p>
        <p style="margin:0;font-size:13px;color:#64748b;">USAT College Sagay City Inc.</p>
      </td></tr>
      <tr><td style="background:#f8fafc;padding:22px 36px;text-align:center;font-size:11px;color:#64748b;border-top:1px solid #e2e8f0;line-height:1.6;">
        <strong>Office of the Registrar</strong><br>USAT College Sagay City Inc., Negros Occidental<br>
        This is an automated message. Please do not reply directly to this email.<br>
        &copy; {$year} USAT College Sagay City Inc. All rights reserved.
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }
}

/* ══════════════════════════════════════════════════════════════
   Send
   ══════════════════════════════════════════════════════════════ */

if (!function_exists('send_grades_email')) {
    function send_grades_email(
        array $student,
        array $grades,
        string $term,
        string $sy,
        string $section,
        string $grade_level,
        string $strand,
        ?float $term_gwa,
        string $to_email
    ): array {
        [$mail, $buildErr] = mailer_build();
        if ($mail === null) {
            return ['ok' => false, 'error' => (string)$buildErr];
        }

        // Fail fast on dead SMTP (default 300s is too long for a worker)
        $mail->Timeout     = 15;
        $mail->SMTPDebug   = 0;
        $mail->SMTPAutoTLS = true;

        $subject = "[USAT] Term Grade Report — {$term} · {$sy} · " . ge_format_name($student);
        $html    = ge_build_html($student, $grades, $term, $sy, $section, $grade_level, $strand, $term_gwa);

        try {
            $mail->addAddress($to_email);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = strip_tags(str_replace(
                ['<br>', '<br/>', '</p>', '</tr>', '</div>', '</td>', '</th>'],
                ["\n", "\n", "\n", "\n", "\n", ' ', ' '],
                $html
            ));
            $mail->addCustomHeader('X-Entity-Ref-ID', uniqid('usat_grades_', true));

            if (strpos($html, 'cid:usat_logo') !== false) {
                $cached_logo = ge_logo_cached(__DIR__ . '/../../assets/img/');
                if ($cached_logo && is_file($cached_logo)) {
                    $mail->addEmbeddedImage($cached_logo, 'usat_logo', 'USAT_Logo.jpg');
                }
            }

            try {
                $mail->send();
                return ['ok' => true, 'error' => null];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}