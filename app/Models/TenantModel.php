<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenant\TenantContext;
use App\Tenant\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

abstract class TenantModel extends Model
{
    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if ($model->association_id === null && TenantContext::hasBooted()) {
                $model->association_id = TenantContext::currentId();
            }
        });
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }
}
