<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\PdfToImage\Enums\OutputFormat;
use Spatie\PdfToImage\Pdf as PdfToImage;
use Throwable;

/**
 * Rasterise un document scanné (PDF multi-pages ou image) puis mesure les
 * barres « ressenti » page par page, dans l'ordre de lecture du document.
 */
final class RessentiScanMeasurer
{
    public function __construct(private readonly RessentiMarkDetector $detecteur) {}

    /**
     * @return list<array{pct: float|null, nbTraits: int}>|null null si le document n'a pas pu être rasterisé
     */
    public function mesurerDocument(string $path, string $mime): ?array
    {
        if ($mime !== 'application/pdf') {
            return $this->detecteur->mesurer($path);
        }

        $dossierTemp = storage_path('app/private/temp/questionnaire-scan');
        File::ensureDirectoryExists($dossierTemp);
        $prefixe = $dossierTemp.'/'.Str::uuid()->toString();
        $pages = [];

        try {
            $pdf = (new PdfToImage($path))->resolution(200)->format(OutputFormat::Png);
            $mesures = [];
            for ($page = 1; $page <= $pdf->pageCount(); $page++) {
                $png = $prefixe.'-p'.$page.'.png';
                $pdf->selectPage($page)->save($png);
                $pages[] = $png;
                foreach ($this->detecteur->mesurer($png) as $mesure) {
                    $mesures[] = $mesure;
                }
            }

            return $mesures;
        } catch (Throwable) {
            return null;
        } finally {
            foreach ($pages as $png) {
                @unlink($png);
            }
        }
    }
}
