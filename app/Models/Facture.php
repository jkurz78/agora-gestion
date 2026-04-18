<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Facture extends TenantModel
{
    protected $fillable = [
        'association_id', 'numero', 'date', 'statut', 'tiers_id', 'compte_bancaire_id',
        'conditions_reglement', 'mentions_legales', 'montant_total',
        'numero_avoir', 'date_annulation', 'notes', 'saisi_par', 'exercice',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'date_annulation' => 'date',
            'statut' => StatutFacture::class,
            'montant_total' => 'decimal:2',
            'exercice' => 'integer',
            'tiers_id' => 'integer',
            'compte_bancaire_id' => 'integer',
            'saisi_par' => 'integer',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function compteBancaire(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class);
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(FactureLigne::class)->orderBy('ordre');
    }

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class, 'facture_transaction');
    }

    /**
     * Montant total pour affichage : figé en base si validée, calculé depuis les lignes sinon.
     */
    public function montantCalcule(): float
    {
        if ($this->statut !== StatutFacture::Brouillon) {
            return (float) $this->montant_total;
        }

        return (float) $this->lignes()
            ->where('type', TypeLigneFacture::Montant)
            ->sum('montant');
    }

    public function montantRegle(): float
    {
        return (float) $this->transactions()
            ->whereIn('statut_reglement', [
                StatutReglement::Recu->value,
                StatutReglement::Pointe->value,
            ])
            ->sum('montant_total');
    }

    public function isAcquittee(): bool
    {
        return $this->statut === StatutFacture::Validee
            && $this->montantRegle() >= (float) $this->montant_total;
    }
}
