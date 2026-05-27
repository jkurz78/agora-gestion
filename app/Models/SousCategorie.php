<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UsageComptable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * NOTE : modèle conservé temporairement post Step 37 (slice 1d).
 *
 * Le rename SousCategorie → Compte est PARQUÉ post-cutover v5.0 :
 * - 6 tables (budget_lines, formules_adhesion, facture_lignes, note_de_frais_lignes,
 *   devis_lignes, usages_sous_categorie) ont une FK `sous_categorie_id` qui pointe
 *   vers `sous_categories.id`.
 * - Migrer ces FK sur `compte_id` nécessite 6 migrations supplémentaires hors scope
 *   du Step 36 (qui ne traite que transaction_lignes).
 * - Drop de SousCategorie (Step 39 du plan) reporté à un programme dédié post-prod.
 *
 * Cette classe coexiste avec `App\Models\Compte` (le nouveau modèle PCG du slice 1).
 * Voir : memory/project_compta_v5_sous_slice_1d.md section « Phase I — partielle ».
 */
final class SousCategorie extends TenantModel
{
    use HasFactory;

    protected $table = 'sous_categories';

    protected $fillable = [
        'association_id',
        'categorie_id',
        'nom',
        'code_cerfa',
    ];

    protected function casts(): array
    {
        return [
            'categorie_id' => 'integer',
        ];
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class);
    }

    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'sous_categorie_id');
    }

    public function transactionLignes(): HasMany
    {
        return $this->hasMany(TransactionLigne::class, 'sous_categorie_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(UsageSousCategorie::class);
    }

    public function hasUsage(UsageComptable $usage): bool
    {
        return $this->usages()->where('usage', $usage->value)->exists();
    }

    public function scopeForUsage(Builder $query, UsageComptable $usage): Builder
    {
        return $query->whereHas('usages', fn (Builder $q) => $q->where('usage', $usage->value));
    }

    public function formulesAdhesion(): HasMany
    {
        return $this->hasMany(FormuleAdhesion::class, 'sous_categorie_id');
    }

    public function formuleAdhesionActive(): ?FormuleAdhesion
    {
        return $this->formulesAdhesion()->where('actif', true)->first();
    }
}
