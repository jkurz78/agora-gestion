<?php

declare(strict_types=1);

namespace App\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

final class EmargementQrCode
{
    private const PREFIX = 'emargement:';

    public static function generateBase64Png(int $seanceId): string
    {
        $result = (new Builder(
            writer: new PngWriter,
            data: self::buildContent($seanceId),
            size: 180,
            margin: 10,
        ))->build();

        return base64_encode($result->getString());
    }

    public static function parseContent(string $content): ?int
    {
        $prefix = self::PREFIX.config('app.env').':';

        if (! str_starts_with($content, $prefix)) {
            return null;
        }

        $id = substr($content, strlen($prefix));

        if ($id === '' || ! ctype_digit($id)) {
            return null;
        }

        return (int) $id;
    }

    public static function buildContent(int $seanceId): string
    {
        return self::PREFIX.config('app.env').':'.$seanceId;
    }
}
