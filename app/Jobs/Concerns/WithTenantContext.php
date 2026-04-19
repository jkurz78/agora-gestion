<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Association;
use App\Tenant\TenantContext;
use Closure;

trait WithTenantContext
{
    /**
     * @template TReturn
     *
     * @param  Closure():TReturn  $callback
     * @return TReturn
     */
    protected function runWithTenantContext(Closure $callback): mixed
    {
        /** @var int $associationId */
        $associationId = $this->associationId ?? 0;
        $association = Association::findOrFail($associationId);

        TenantContext::boot($association);

        try {
            return $callback();
        } finally {
            TenantContext::clear();
        }
    }
}
