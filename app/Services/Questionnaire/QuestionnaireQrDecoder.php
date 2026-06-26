<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Services\Questionnaire\Contracts\QrDecoderContract;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\PdfToImage\Enums\OutputFormat;
use Spatie\PdfToImage\Pdf as PdfToImage;
use Throwable;
use Zxing\QrReader;

final class QuestionnaireQrDecoder implements QrDecoderContract
{
    public function decodeFromPath(string $path, string $mime): ?string
    {
        if ($mime === 'application/pdf') {
            return $this->decodeFromPdf($path);
        }

        return $this->decodeFromImage($path);
    }

    private function decodeFromPdf(string $pdfPath): ?string
    {
        $tempPng = storage_path(
            'app/private/temp/questionnaire-scan/'.Str::uuid()->toString().'.png'
        );
        File::ensureDirectoryExists(dirname($tempPng));

        try {
            try {
                (new PdfToImage($pdfPath))
                    ->selectPage(1)
                    ->resolution(150)
                    ->format(OutputFormat::Png)
                    ->save($tempPng);
            } catch (Throwable) {
                return null;
            }

            if (! file_exists($tempPng)) {
                return null;
            }

            return $this->decodeFromImage($tempPng);
        } finally {
            if (file_exists($tempPng)) {
                @unlink($tempPng);
            }
        }
    }

    private function decodeFromImage(string $imagePath): ?string
    {
        try {
            $decoded = (new QrReader($imagePath))->text();
        } catch (Throwable) {
            return null;
        }

        if ($decoded === null || $decoded === false || $decoded === '') {
            return null;
        }

        return $this->extractTokenFromUrl((string) $decoded);
    }

    private function extractTokenFromUrl(string $url): ?string
    {
        // Accept: https://example.com/q/{token} or https://example.com/q/{token}/consentement
        // The token is the path segment right after /q/
        if (preg_match('#/q/([A-Za-z0-9]{20,})#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
