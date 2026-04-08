<?php

declare(strict_types=1);

namespace App\Services\Emargement\Contracts;

use App\Services\Emargement\QrExtractionResult;

interface QrCodeExtractor
{
    public function extractSeanceIdFromPdf(string $pdfPath): QrExtractionResult;
}
