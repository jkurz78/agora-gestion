<?php

declare(strict_types=1);

namespace App\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! TenantContext::hasBooted()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where(
            $model->getTable().'.association_id',
            TenantContext::currentId()
        );
    }
}
