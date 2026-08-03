<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BudgetLine extends TenantModel
{
    use HasFactory;

    protected $table = 'budget_lines';

    protected $fillable = [
        'association_id',
        'compte_id',
        'exercice',
        'montant_prevu',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'montant_prevu' => 'decimal:2',
            'exercice' => 'integer',
            'compte_id' => 'integer',
        ];
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_id');
    }

    /**
     * @param  Builder<BudgetLine>  $query
     */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->where('exercice', $exercice);
    }
}
