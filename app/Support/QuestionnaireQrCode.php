<?php

declare(strict_types=1);

namespace App\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

final class QuestionnaireQrCode
{
    public static function dataUri(string $url): string
    {
        $builder = new Builder(
            writer: new PngWriter,
            size: 600,
            margin: 12,
        );

        $result = $builder->build(data: $url);

        return $result->getDataUri();
    }
}
