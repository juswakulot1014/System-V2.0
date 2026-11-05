<?php
declare(strict_types=1);

/**
 * Formal document shell — single source of truth for letterhead, signatures, and footers.
 */

function school_identity(): array
{
    static $identity = null;
    if ($identity !== null) return $identity;

    $defaults = [
        'country'    => 'Republic of the Philippines',
        'department' => 'Department of Education',
        'region'     => '',
        'division'   => '',
        'school'     => 'USAT College Sagay City, Inc.',
        'address'    => 'Sagay City, Negros Occidental',
        'logo_path'  => null,
        'signatories' => [
            'registrar' => ['name' => '', 'position' => 'University Registrar'],
            'principal' => ['name' => '', 'position' => 'School President'],
        ],
    ];

    $override = __DIR__ . '/../../config/school.php';
    if (is_file($override)) {
        $custom = require $override;
        if (is_array($custom)) {
            $defaults = array_replace_recursive($defaults, $custom);
        }
    }
    return $identity = $defaults;
}

function school_logo_data_uri(): ?string
{
    static $cached = null;
    static $resolved = false;
    if ($resolved) return $cached;
    $resolved = true;

    $ident = school_identity();
    $candidates = [];
    if (!empty($ident['logo_path'])) {
        $candidates[] = $ident['logo_path'];
    }
    $candidates = array_merge($candidates, [
        __DIR__ . '/../../assets/img/usat.jpg',
        __DIR__ . '/../../assets/img/usat.png',
        __DIR__ . '/../../assets/img/logo.jpg',
        __DIR__ . '/../../assets/img/logo.png',
    ]);

    foreach ($candidates as $path) {
        if (!is_file($path)) continue;
        $data = @file_get_contents($path);
        if ($data === false || $data === '') continue;
        $mime = @mime_content_type($path) ?: 'image/jpeg';
        return $cached = 'data:' . $mime . ';base64,' . base64_encode($data);
    }
    return $cached = null;
}

function doc_e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function doc_paper_css(string $paper): string
{
    return '@page { margin: 15mm 14mm 18mm 14mm; }';
}

function doc_paper_points(string $paper, string $orientation = 'portrait'): array
{
    $sizes = [
        'A4'     => [595.28, 841.89],
        'folio'  => [612, 936],
        'legal'  => [612, 1008],
        'letter' => [612, 792],
    ];
    [$w, $h] = $sizes[$paper] ?? $sizes['A4'];
    if ($orientation === 'landscape') [$w, $h] = [$h, $w];
    return [$w, $h];
}

function doc_paper_word(string $paper, string $orientation = 'portrait'): string
{
    $sizes = [
        'A4'     => ['21.00cm', '29.70cm'],
        'folio'  => ['21.59cm', '33.02cm'],
        'legal'  => ['21.59cm', '35.56cm'],
        'letter' => ['21.59cm', '27.94cm'],
    ];
    [$w, $h] = $sizes[$paper] ?? $sizes['A4'];
    $pageOrient = 'portrait';
    if ($orientation === 'landscape') {
        [$w, $h] = [$h, $w];
        $pageOrient = 'landscape';
    }
    return "@page WordSection1 {"
         . " size: {$w} {$h};"
         . " margin: 1.5cm 1.4cm 1.8cm 1.4cm;"
         . " mso-header-margin: 1.0cm;"
         . " mso-footer-margin: 1.0cm;"
         . " mso-paper-source: 0;"
         . " mso-page-orientation: {$pageOrient};"
         . "}"
         . " div.WordSection1 { page: WordSection1; }";
}

function render_document(array $doc): string
{
    $doc += [
        'title'        => 'Document',
        'subtitle'     => '',
        'paper'        => 'A4',
        'orientation'  => 'portrait',
        'body'         => '',
        'signatories'  => [],
        'control_no'   => null,
        'copy_label'   => null,
        'show_letterhead' => true,
        'mode'         => 'html',
        'generated_at' => new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')),
    ];

    $ident  = school_identity();
    $isWord = ($doc['mode'] === 'word');

    ob_start();
    require __DIR__ . '/partials/letterhead.php';
    $letterhead = ob_get_clean();

    ob_start();
    require __DIR__ . '/partials/signatures.php';
    $signatures = ob_get_clean();

    if ($isWord) {
        ob_start();
        require __DIR__ . '/partials/page-footer-inline.php';
        $footerInline = ob_get_clean();
        $footerFixed  = '';
    } else {
        ob_start();
        require __DIR__ . '/partials/page-footer.php';
        $footerFixed  = ob_get_clean();
        $footerInline = '';
    }

    $copyLabelHtml = $doc['copy_label'] !== null
        ? '<div class="copy-label">' . doc_e($doc['copy_label']) . '</div>'
        : '';

    $titleBlock = '';
    if ($doc['title'] !== '') {
        $titleBlock .= '<h1 class="doc-title">' . doc_e($doc['title']) . '</h1>';
        $titleBlock .= '<div class="title-divider"></div>';
    }
    if ($doc['subtitle'] !== '') {
        $titleBlock .= '<p class="doc-subtitle">' . doc_e($doc['subtitle']) . '</p>';
    }

    $bodyOpen  = $isWord ? '<div class="WordSection1">' : '';
    $bodyClose = $isWord ? '</div>' : '';

    $htmlAttr = $isWord
        ? 'xmlns:o="urn:schemas-microsoft-com:office:office" '
        . 'xmlns:w="urn:schemas-microsoft-com:office:word" '
        . 'xmlns="http://www.w3.org/TR/REC-html40" lang="en"'
        : 'lang="en"';

    $wordHead = $isWord ? <<<META
<!--[if gte mso 9]>
<xml>
  <w:WordDocument>
    <w:View>Print</w:View>
    <w:Zoom>100</w:Zoom>
    <w:DoNotOptimizeForBrowser/>
  </w:WordDocument>
</xml>
<![endif]-->
META : '';

    $paperCss  = doc_paper_css($doc['paper']);
    $wordPage  = $isWord ? doc_paper_word($doc['paper'], $doc['orientation']) : '';

    return <<<HTML
<!DOCTYPE html>
<html {$htmlAttr}>
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>{$doc['title']}</title>
{$wordHead}
<style>
{$paperCss}
{$wordPage}
* { box-sizing: border-box; }
body {
    font-family: "Times New Roman", Times, Georgia, serif;
    font-size: 11pt;
    line-height: 1.4;
    color: #0a0a0a;
    margin: 0;
}
.doc-title {
    text-align: center;
    font-weight: bold;
    font-size: 15pt;
    letter-spacing: .05em;
    text-transform: uppercase;
    margin: 16pt 0 4pt;
    color: #0a0a0a;
}
.title-divider {
    width: 100%;
    height: 2pt;
    background: #0a0a0a;
    margin: 0 auto 12pt;
}
.doc-subtitle {
    text-align: center;
    font-size: 10.5pt;
    font-style: italic;
    color: #4b5563;
    margin: -6pt 0 16pt;
}
table { border-collapse: collapse; width: 100%; }
table.grid { margin: 8pt 0 14pt; }
table.grid th, table.grid td {
    border: 1px solid #9ca3af;
    padding: 6pt 8pt;
    font-size: 9.5pt;
    vertical-align: top;
}
table.grid th {
    background: #f3f4f6;
    color: #111827;
    font-weight: bold;
    text-align: left;
    text-transform: uppercase;
    letter-spacing: .5pt;
    font-size: 8.5pt;
}
table.grid td.num { text-align: center; font-variant-numeric: tabular-nums; }
table.grid thead { display: table-header-group; }
table.grid tr { page-break-inside: avoid; mso-row-break: avoid; }

.section-title {
    display: block;
    font-weight: bold;
    font-size: 10.5pt;
    letter-spacing: 1pt;
    text-transform: uppercase;
    padding: 4pt 0;
    margin: 16pt 0 8pt;
    border-bottom: 1.5pt solid #374151;
    color: #111827;
}

.meta-block {
    background: #f9fafb;
    border: 1px solid #d1d5db;
    border-left: 4pt solid #374151;
    padding: 8pt 12pt;
    margin: 0 0 12pt;
    font-size: 9pt;
    line-height: 1.5;
    color: #374151;
}
.meta-block strong { color: #111827; }

.signatures { margin-top: 36pt; page-break-inside: avoid; }
.signatures table { border: 0; }
.signatures td { border: 0; padding: 0; vertical-align: bottom; }
.sig-item { text-align: center; }
.sig-line {
    border-top: 1.5pt solid #0a0a0a;
    margin: 0 auto 4pt;
    width: 220pt;
    padding-top: 4pt;
    font-weight: bold;
    font-size: 10pt;
    letter-spacing: .5pt;
    text-transform: uppercase;
    color: #0a0a0a;
}
.sig-label { font-size: 9.5pt; color: #111827; font-weight: bold; margin-top: 2pt; }
.sig-role { font-size: 8.5pt; color: #6b7280; font-style: italic; margin-top: 1pt; }

.footer {
    position: fixed;
    bottom: -14mm;
    left: 0; right: 0;
    font-size: 8pt;
    color: #6b7280;
    border-top: 1px solid #d1d5db;
    padding-top: 4pt;
}
.footer-inline {
    margin-top: 24pt;
    padding-top: 8pt;
    border-top: 1px solid #d1d5db;
    font-size: 8pt;
    color: #6b7280;
}
.copy-label {
    font-size: 8pt;
    text-align: right;
    color: #111827;
    font-weight: bold;
    margin-bottom: 4pt;
    letter-spacing: .5pt;
    text-transform: uppercase;
}
</style>
</head>
<body>
{$bodyOpen}
{$footerFixed}
{$letterhead}
{$copyLabelHtml}
{$titleBlock}
{$doc['body']}
{$signatures}
{$footerInline}
{$bodyClose}
</body>
</html>
HTML;
}