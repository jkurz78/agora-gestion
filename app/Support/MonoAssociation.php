<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Association;

final class MonoAssociation
{
    private static ?bool $isActive = null;

    public static function isActive(): bool
    {
        return self::$isActive ??= Association::count() === 1;
    }

    public static function flush(): void
    {
        self::$isActive = null;
    }
}
