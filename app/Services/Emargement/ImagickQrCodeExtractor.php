<?php

declare(strict_types=1);

namespace App\Services\Emargement;

use App\Services\Emargement\Contracts\QrCodeExtractor;
use App\Support\EmargementQrCode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\PdfToImage\Enums\OutputFormat;
use Spatie\PdfToImage\Pdf as PdfToImage;
use Throwable;
use Zxing\QrReader;

final class ImagickQrCodeExtractor implements QrCodeExtractor
{
    /**
     * @param  string|null  $zbarBin  null disables zbar, which is how production runs (zbar is not installed there)
     */
    public function __construct(
        private readonly ?string $zbarBin = '/usr/bin/zbarimg',
    ) {}

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

            // 2. Decode QR from the rasterized PNG (zbar first, Zxing fallback)
            $decoded = $this->decodeViaZbar($tempPng);

            if ($decoded === null) {
                try {
                    $decoded = $this->decodeViaZxing($tempPng);
                } catch (Throwable $e) {
                    return QrExtractionResult::failure('qr_unreadable', $e->getMessage());
                }
            }

            if ($decoded === null) {
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

    private function decodeViaZbar(string $imagePath): ?string
    {
        if ($this->zbarBin === null || ! file_exists($this->zbarBin)) {
            return null;
        }

        $output = [];
        $exitCode = 0;
        exec(escapeshellarg($this->zbarBin).' --raw -q '.escapeshellarg($imagePath).' 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        return trim(implode("\n", $output));
    }

    /**
     * Zxing's default pass misses some intact QR codes on the real sheet
     * (seances 12, 24, 35 and 131 at 150 dpi); a TRY_HARDER pass reads them.
     * It only runs when the default pass found nothing.
     */
    private function decodeViaZxing(string $imagePath): ?string
    {
        foreach ([null, ['TRY_HARDER' => true]] as $hints) {
            $result = (new QrReader($imagePath))->text($hints);

            if ($result !== null && $result !== false && $result !== '') {
                return (string) $result;
            }
        }

        return null;
    }
}
