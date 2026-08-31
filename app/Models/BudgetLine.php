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
        'operation_id',
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
            'operation_id' => 'integer',
        ];
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'operation_id');
    }

    /**
     * @param  Builder<BudgetLine>  $query
     */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->where('exercice', $exercice);
    }

    /**
     * L'enveloppe du compte : la ligne non ventilée.
     *
     * Tout calcul de budget GLOBAL — total, par compte, par famille — passe par
     * ce scope. Sans lui, l'enveloppe et ses ventilations se cumulent.
     *
     * @param  Builder<BudgetLine>  $query
     */
    public function scopeEnveloppes(Builder $query): Builder
    {
        return $query->whereNull('operation_id');
    }

    /**
     * Les ventilations : les lignes rattachées à une opération.
     *
     * @param  Builder<BudgetLine>  $query
     */
    public function scopeVentilations(Builder $query): Builder
    {
        return $query->whereNotNull('operation_id');
    }
}
