<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Facture extends TenantModel
{
    protected $fillable = [
        'association_id', 'numero', 'date', 'statut', 'tiers_id', 'compte_bancaire_id',
        'conditions_reglement', 'mentions_legales', 'montant_total',
        'numero_avoir', 'date_annulation', 'notes', 'saisi_par', 'exercice',
        'devis_id', 'mode_paiement_prevu',
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
            'devis_id' => 'integer',
            'mode_paiement_prevu' => ModePaiement::class,
        ];
    }

    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
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

    /**
     * Transactions du pivot générées par cette facture (via des lignes MontantManuel).
     *
     * Une transaction est dite "générée" ssi au moins une FactureLigne de cette facture
     * de type MontantManuel possède un transaction_ligne_id pointant vers une TransactionLigne
     * de cette transaction. Ces deux ensembles (générées / référencées) sont disjoints par
     * construction : les lignes MontantManuel créent toujours une transaction neuve.
     *
     * @return Collection<int, Transaction>
     */
    public function transactionsGenereesParLignesManuelles(): Collection
    {
        // IDs des TransactionLignes référencées par les FactureLignes MontantManuel de cette facture
        $txLigneIds = FactureLigne::where('facture_id', $this->id)
            ->where('type', TypeLigneFacture::MontantManuel->value)
            ->whereNotNull('transaction_ligne_id')
            ->pluck('transaction_ligne_id');

        if ($txLigneIds->isEmpty()) {
            return new Collection;
        }

        // Transactions du pivot dont au moins une TransactionLigne est dans cet ensemble
        $txIds = TransactionLigne::whereIn('id', $txLigneIds)
            ->pluck('transaction_id')
            ->unique();

        return $this->transactions()
            ->whereIn('transactions.id', $txIds)
            ->get();
    }

    /**
     * Transactions du pivot référencées par cette facture (via des lignes Montant).
     *
     * Complémentaire de transactionsGenereesParLignesManuelles() — retourne les transactions
     * du pivot qui ne sont PAS générées par des lignes MontantManuel. Ces transactions
     * préexistaient à la facture et ont été rattachées par l'utilisateur.
     *
     * @return Collection<int, Transaction>
     */
    public function transactionsReferencees(): Collection
    {
        $genereesIds = $this->transactionsGenereesParLignesManuelles()
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        if ($genereesIds->isEmpty()) {
            return $this->transactions()->get();
        }

        return $this->transactions()
            ->whereNotIn('transactions.id', $genereesIds)
            ->get();
    }
}
