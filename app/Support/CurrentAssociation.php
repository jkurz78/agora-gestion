<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Association;
use App\Tenant\TenantContext;

final class CurrentAssociation
{
    public static function get(): Association
    {
        return TenantContext::requireCurrent();
    }

    public static function tryGet(): ?Association
    {
        return TenantContext::current();
    }

    public static function id(): int
    {
        return TenantContext::requireCurrent()->id;
    }
}
