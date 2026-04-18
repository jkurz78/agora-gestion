<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Models\Association;

final class TenantContext
{
    private static ?Association $current = null;

    public static function boot(Association $association): void
    {
        self::$current = $association;
    }

    public static function clear(): void
    {
        self::$current = null;
    }

    public static function current(): ?Association
    {
        return self::$current;
    }

    public static function currentId(): ?int
    {
        return self::$current?->id;
    }

    public static function hasBooted(): bool
    {
        return self::$current !== null;
    }

    public static function requireCurrent(): Association
    {
        if (self::$current === null) {
            throw new \RuntimeException('TenantContext not booted. Call TenantContext::boot($association) first.');
        }

        return self::$current;
    }
}
