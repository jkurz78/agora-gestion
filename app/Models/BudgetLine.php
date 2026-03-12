<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BudgetLine extends Model
{
    use HasFactory;

    protected $table = 'budget_lines';

    protected $fillable = [
        'sous_categorie_id',
        'exercice',
        'montant_prevu',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'montant_prevu' => 'decimal:2',
        ];
    }

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class);
    }

    /**
     * @param  Builder<BudgetLine>  $query
     */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->where('exercice', $exercice);
    }
}
