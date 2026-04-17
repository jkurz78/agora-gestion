<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\URL;

final class TenantAsset
{
    public static function url(string $path, ?int $expiresInMinutes = 30): string
    {
        return URL::temporarySignedRoute(
            'tenant-assets',
            now()->addMinutes($expiresInMinutes),
            ['path' => $path],
        );
    }
}
