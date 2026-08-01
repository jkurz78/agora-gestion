<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class CompteBancaire extends TenantModel
{
    use HasFactory;

    protected $table = 'comptes_bancaires';

    protected $fillable = [
        'association_id',
        'nom',
        'iban',
        'bic',
        'domiciliation',
        'solde_initial',
        'date_solde_initial',
        'actif_recettes_depenses',
        'saisie_automatisee',
    ];

    protected function casts(): array
    {
        return [
            'solde_initial' => 'decimal:2',
            'date_solde_initial' => 'date',
            'actif_recettes_depenses' => 'boolean',
            'saisie_automatisee' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'compte_id');
    }

    /**
     * Le compte 512X du plan comptable, créé par CompteBancaireObserver à la
     * création de la fiche. Inverse de Compte::compteBancaire().
     */
    public function comptePlanComptable(): HasOne
    {
        return $this->hasOne(Compte::class, 'compte_bancaire_id');
    }

    public function depenses(): HasMany
    {
        return $this->transactions()->where('type', 'depense');
    }

    public function recettes(): HasMany
    {
        return $this->transactions()->where('type', 'recette');
    }

    /**
     * Comptes sélectionnables en saisie manuelle (création/édition
     * de transactions, factures, remises, virements).
     * Exclut les comptes archivés et ceux alimentés par intégration externe.
     */
    public function scopeSaisieManuelle(Builder $q): Builder
    {
        return $q->where('actif_recettes_depenses', true)
            ->where('saisie_automatisee', false);
    }
}
