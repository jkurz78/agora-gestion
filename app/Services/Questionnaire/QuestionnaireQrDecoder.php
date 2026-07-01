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
        $decoded = $this->decodeViaZbar($imagePath)
            ?? $this->decodeViaZxing($imagePath);

        if ($decoded === null || $decoded === '') {
            // Zxing struggles with large smartphone photos — resize and retry
            $resized = $this->resizeForQr($imagePath);
            if ($resized !== null) {
                try {
                    $decoded = $this->decodeViaZbar($resized)
                        ?? $this->decodeViaZxing($resized);
                } finally {
                    @unlink($resized);
                }
            }
        }

        if ($decoded === null || $decoded === '') {
            return null;
        }

        return $this->extractTokenFromUrl($decoded);
    }

    private function decodeViaZbar(string $imagePath): ?string
    {
        $zbarBin = '/usr/bin/zbarimg';
        if (! file_exists($zbarBin)) {
            return null;
        }

        $output = [];
        $exitCode = 0;
        exec($zbarBin.' --raw -q '.escapeshellarg($imagePath).' 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        return trim(implode("\n", $output));
    }

    private function decodeViaZxing(string $imagePath): ?string
    {
        try {
            $decoded = (new QrReader($imagePath))->text();
        } catch (Throwable) {
            return null;
        }

        if ($decoded === null || $decoded === false || $decoded === '') {
            return null;
        }

        return (string) $decoded;
    }

    private function resizeForQr(string $imagePath): ?string
    {
        try {
            $info = getimagesize($imagePath);
            if ($info === false) {
                return null;
            }
            [$w, $h] = $info;

            if ($w <= 1200 && $h <= 1200) {
                return null;
            }

            $scale = min(1200 / $w, 1200 / $h);
            $nw = (int) round($w * $scale);
            $nh = (int) round($h * $scale);

            $src = match ($info[2]) {
                IMAGETYPE_JPEG => imagecreatefromjpeg($imagePath),
                IMAGETYPE_PNG => imagecreatefrompng($imagePath),
                IMAGETYPE_WEBP => imagecreatefromwebp($imagePath),
                default => null,
            };

            if ($src === null || $src === false) {
                return null;
            }

            $dst = imagecreatetruecolor($nw, $nh);
            if ($dst === false) {
                imagedestroy($src);

                return null;
            }

            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src);

            $tempPath = storage_path('app/private/temp/questionnaire-scan/'.uniqid('qr-resize-').'.png');
            File::ensureDirectoryExists(dirname($tempPath));
            imagepng($dst, $tempPath);
            imagedestroy($dst);

            return $tempPath;
        } catch (Throwable) {
            return null;
        }
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
