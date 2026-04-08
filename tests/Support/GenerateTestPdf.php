<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\EmargementQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

final class GenerateTestPdf
{
    /**
     * Génère un PDF contenant un QR code d'émargement pour la séance donnée.
     * Le QR utilise l'env courant, sauf si $overrideEnv est passé (pour tester
     * le rejet cross-environment).
     */
    public static function withEmargementQr(int $seanceId, ?string $overrideEnv = null): string
    {
        $content = $overrideEnv !== null
            ? 'emargement:'.$overrideEnv.':'.$seanceId
            : EmargementQrCode::buildContent($seanceId);

        $result = (new Builder(
            writer: new PngWriter,
            data: $content,
            size: 180,
            margin: 10,
        ))->build();

        $base64 = base64_encode($result->getString());

        $html = "<html><body>
            <h1>Feuille test seance {$seanceId}</h1>
            <img src='data:image/png;base64,{$base64}' style='width:180px;height:180px;'>
            </body></html>";

        return Pdf::loadHTML($html)->setPaper('a4', 'portrait')->output();
    }

    public static function withoutQr(): string
    {
        return Pdf::loadHTML('<html><body><h1>Pas de QR</h1></body></html>')
            ->setPaper('a4', 'portrait')
            ->output();
    }
}
