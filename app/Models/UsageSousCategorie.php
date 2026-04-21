<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UsageComptable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UsageSousCategorie extends TenantModel
{
    use HasFactory;

    protected $table = 'usages_sous_categories';

    protected $fillable = [
        'association_id',
        'sous_categorie_id',
        'usage',
    ];

    protected function casts(): array
    {
        return [
            'association_id' => 'integer',
            'sous_categorie_id' => 'integer',
            'usage' => UsageComptable::class,
        ];
    }

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class);
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }
}
