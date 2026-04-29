<?php

declare(strict_types=1);

namespace App\Support;

final class Demo
{
    public static function isActive(): bool
    {
        return app()->environment('demo');
    }
}
