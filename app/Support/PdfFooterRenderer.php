<?php

declare(strict_types=1);

namespace App\Support;

use Barryvdh\DomPDF\PDF;
use Dompdf\Dompdf;

/**
 * Inject a uniform textual footer on every page of a DomPDF document.
 *
 * This service writes two text strings on every page via `$canvas->page_text()`
 * (which DomPDF repeats on every page, unlike `$canvas->image()` which only
 * writes on the current page). The rendered strings are :
 *
 *  - Center : pagination ("Page X / Y").
 *  - Right  : "AgoraGestion · dd/mm/YYYY HH:ii".
 *
 * Logos (association on the left, AgoraGestion on the right) are handled by
 * the Blade template directly via the `pdf.partials.footer-logos` include,
 * which renders them as `position: fixed` divs — the only reliable way to get
 * images to appear on every page with DomPDF.
 *
 * Usage (controller) :
 *   $pdf = Pdf::loadView(...)->setPaper('a4', 'portrait');
 *   PdfFooterRenderer::render($pdf);
 *   return $pdf->stream($filename);
 *
 * Usage (Blade template) :
 *
 *   @include('pdf.partials.footer-logos')
 *   // body { margin: 15mm 15mm 25mm 15mm; } — reserve footer space
 *
 * Templates MUST reserve at least 25mm of bottom margin so the footer does
 * not overlap the content.
 */
final class PdfFooterRenderer
{
    /** Footer baseline from bottom of the page (in pt). */
    private const FOOTER_Y_OFFSET = 36;

    /** Side margin (in pt — ~15mm). */
    private const SIDE_MARGIN = 42;

    /** Space reserved on the right for the AgoraGestion logo (in pt). */
    private const APP_LOGO_RESERVED = 40;

    /** Text size (pt). */
    private const TEXT_SIZE = 8;

    /** Grey text color. */
    private const TEXT_COLOR = [0.6, 0.6, 0.6];

    /**
     * @param  string|null  $rightText  Override the right-aligned footer text. When null,
     *                                  defaults to "AgoraGestion · dd/mm/YYYY HH:ii" used
     *                                  on internal documents. External documents (factures,
     *                                  devis) typically pass "Généré par AgoraGestion le …
     *                                  à HHhMM" via {@see self::generatedByText()}.
     */
    public static function render(PDF $pdf, ?string $rightText = null): void
    {
        // Defensive: when the PDF is mocked in tests, getDomPDF() may not be
        // wired up. Silently skip footer injection in that case — the
        // production path always returns a real Dompdf instance.
        try {
            $domPdf = $pdf->getDomPDF();
        } catch (\Throwable) {
            return;
        }
        if (! $domPdf instanceof Dompdf) {
            return;
        }

        $domPdf->render();
        $canvas = $domPdf->getCanvas();
        $fontMetrics = $domPdf->getFontMetrics();
        $font = $fontMetrics->getFont('DejaVu Sans');

        $pageWidth = $canvas->get_width();
        $y = $canvas->get_height() - self::FOOTER_Y_OFFSET;

        // Center : pagination. Use a fixed-width reference so centering does
        // not shift across pages with different numbers of digits.
        $pageText = 'Page {PAGE_NUM} / {PAGE_COUNT}';
        $referenceWidth = $fontMetrics->getTextWidth('Page 00 / 00', $font, self::TEXT_SIZE);
        $canvas->page_text(
            ($pageWidth - $referenceWidth) / 2,
            $y,
            $pageText,
            $font,
            self::TEXT_SIZE,
            self::TEXT_COLOR,
        );

        // Right : default "AgoraGestion · dd/mm/YYYY HH:ii", or caller-provided
        // text. Positioned to the left of the AgoraGestion logo reserved zone.
        $rightText ??= 'AgoraGestion '."\xC2\xB7".' '.now()->format('d/m/Y H:i');
        $rightWidth = $fontMetrics->getTextWidth($rightText, $font, self::TEXT_SIZE);
        $textX = $pageWidth - self::SIDE_MARGIN - self::APP_LOGO_RESERVED - $rightWidth;
        $canvas->page_text($textX, $y, $rightText, $font, self::TEXT_SIZE, self::TEXT_COLOR);
    }

    /**
     * Build the "Généré par AgoraGestion le … à HHhMM" string used on external
     * documents (factures, devis) so the recipient knows when the document was
     * produced.
     */
    public static function generatedByText(): string
    {
        $now = now();

        return 'Généré par AgoraGestion le '.$now->format('d/m/Y').' à '.$now->format('H\hi');
    }
}
