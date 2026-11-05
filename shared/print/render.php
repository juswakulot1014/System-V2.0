<?php
declare(strict_types=1);

require_once __DIR__ . '/document.php';

/** Emit HTML for browser print preview. */
function output_document_html(array $doc): void
{
    $doc['mode'] = 'html';
    header('Content-Type: text/html; charset=utf-8');
    echo render_document($doc);
}

/** Emit formal HTML and auto-trigger the browser print dialog. */
function output_document_print(array $doc): void
{
    $doc['mode'] = 'html';
    $html = render_document($doc);
    
    // Inject auto-print script
    $script = '<script>window.addEventListener("load", function(){ setTimeout(function(){ window.print(); }, 300); });</script>';
    $html = str_replace('</body>', $script . '</body>', $html);
    
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

/** Emit HTML for Word .doc download. */
function output_document_word(array $doc, string $filename = 'document.doc'): void
{
    $doc['mode'] = 'word';
    $html = render_document($doc);

    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    echo "\xEF\xBB\xBF";
    echo $html;
    exit;
}

/** Render via Dompdf 3.x and stream. */
function output_document_pdf(array $doc, string $filename = 'document.pdf'): void
{
    $doc['mode'] = 'pdf';

    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!is_file($autoload)) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div style="font-family:Arial;padding:1rem;background:#fee2e2;color:#991b1b;border-left:4px solid #dc2626">';
        echo '<strong>Dompdf not installed.</strong> Run <code>composer install</code>.';
        echo '</div>';
        return;
    }
    require_once $autoload;

    if (!class_exists('Dompdf\\Dompdf')) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div style="font-family:Arial;padding:1rem;background:#fee2e2;color:#991b1b;border-left:4px solid #dc2626">';
        echo '<strong>Dompdf class not found.</strong> Run <code>composer dump-autoload</code>.';
        echo '</div>';
        return;
    }

    $html = render_document($doc);

    [$pw, $ph] = doc_paper_points($doc['paper'] ?? 'A4', $doc['orientation'] ?? 'portrait');

    $pageNumX = $pw / 2 - 60;
    $pageNumY = $ph - 28;
    $script = sprintf(
        '<script type="text/php">' .
        'if (isset($pdf)) {' .
        '  $font = $fontMetrics->getFont("Times-Roman", "normal");' .
        '  $pdf->page_text(%.2F, %.2F, "Page {PAGE_NUM} of {PAGE_COUNT}", $font, 9, [0.4, 0.4, 0.4]);' .
        '}' .
        '</script>',
        $pageNumX,
        $pageNumY
    );
    $html = str_replace('</body>', $script . '</body>', $html);

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Times-Roman');
    $options->set('chroot', dirname(__DIR__, 2));

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->setPaper([0, 0, $pw, $ph]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}