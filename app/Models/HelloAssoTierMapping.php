<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class HelloAssoTierMapping extends TenantModel
{
    use HasFactory;

    protected $table = 'helloasso_tier_mappings';

    protected $fillable = [
        'association_id',
        'helloasso_form_slug',
        'helloasso_tier_id',
        'helloasso_tier_label',
        'target_type',
        'target_id',
    ];

    protected $casts = [
        'association_id' => 'integer',
        'helloasso_tier_id' => 'integer',
        'target_id' => 'integer',
    ];

    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
