<?php

declare(strict_types=1);

namespace App\Services\IncomingDocuments;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Spatie\PdfToImage\Enums\OutputFormat;
use Spatie\PdfToImage\Pdf as PdfToImage;
use Throwable;

final class IncomingDocumentThumbnailGenerator
{
    /**
     * Génère une vignette JPEG de la page 1 du PDF.
     * Retourne true en cas de succès, false sur erreur (loggée).
     */
    public function generate(string $sourcePdfPath, string $destJpegPath): bool
    {
        if (! file_exists($sourcePdfPath)) {
            Log::warning('ThumbnailGenerator: source PDF introuvable', ['path' => $sourcePdfPath]);

            return false;
        }

        File::ensureDirectoryExists(dirname($destJpegPath));

        try {
            (new PdfToImage($sourcePdfPath))
                ->selectPage(1)
                ->resolution(72)
                ->format(OutputFormat::Jpg)
                ->save($destJpegPath);
        } catch (Throwable $e) {
            Log::warning('ThumbnailGenerator: échec de génération', [
                'source' => $sourcePdfPath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! file_exists($destJpegPath)) {
            Log::warning('ThumbnailGenerator: fichier non créé sans exception', [
                'dest' => $destJpegPath,
            ]);

            return false;
        }

        return true;
    }
}
