<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Adhesion extends TenantModel
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'association_id',
        'tiers_id',
        'exercice',
        'transaction_id',
        'gratuite',
        'motif_gratuite',
    ];

    protected $casts = [
        'exercice' => 'integer',
        'gratuite' => 'boolean',
    ];

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function scopeForExercice(Builder $query, int $annee): Builder
    {
        return $query->where('exercice', $annee);
    }
}
