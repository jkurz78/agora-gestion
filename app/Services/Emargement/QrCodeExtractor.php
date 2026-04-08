<?php

declare(strict_types=1);

namespace App\Services\Emargement;

use App\Support\EmargementQrCode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\PdfToImage\Enums\OutputFormat;
use Spatie\PdfToImage\Pdf as PdfToImage;
use Throwable;
use Zxing\QrReader;

class QrCodeExtractor
{
    public function extractSeanceIdFromPdf(string $pdfPath): QrExtractionResult
    {
        $tempPng = storage_path(
            'app/private/temp/emargement-ingestion/'.Str::uuid()->toString().'.png'
        );
        File::ensureDirectoryExists(dirname($tempPng));

        try {
            // 1. Rasterize page 1 via Imagick (spatie/pdf-to-image v3.2 API)
            try {
                (new PdfToImage($pdfPath))
                    ->selectPage(1)
                    ->resolution(150)
                    ->format(OutputFormat::Png)
                    ->save($tempPng);
            } catch (Throwable $e) {
                return QrExtractionResult::failure('pdf_unreadable', $e->getMessage());
            }

            if (! file_exists($tempPng)) {
                return QrExtractionResult::failure('pdf_unreadable', 'Rasterisation a échoué sans exception');
            }

            // 2. Decode QR from the rasterized PNG
            try {
                $decoded = (new QrReader($tempPng))->text();
            } catch (Throwable $e) {
                return QrExtractionResult::failure('qr_unreadable', $e->getMessage());
            }

            // QrReader::text() returns string on success, false on decode failure, or null.
            if ($decoded === null || $decoded === false || $decoded === '') {
                return QrExtractionResult::failure('qr_not_found', null);
            }

            // 3. Parse via the helper (validates prefix AND env)
            $seanceId = EmargementQrCode::parseContent($decoded);

            if ($seanceId === null) {
                // Decoded something but it's not a valid emargement QR for this env.
                if (! str_starts_with($decoded, 'emargement:')) {
                    return QrExtractionResult::failure(
                        'qr_unreadable',
                        'Contenu inattendu : '.substr($decoded, 0, 50),
                    );
                }

                return QrExtractionResult::failure(
                    'qr_wrong_environment',
                    'QR détecté : '.$decoded,
                );
            }

            return QrExtractionResult::ok($seanceId);
        } finally {
            if (file_exists($tempPng)) {
                @unlink($tempPng);
            }
        }
    }
}
