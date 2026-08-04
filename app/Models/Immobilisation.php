<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

final class Immobilisation extends TenantModel
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'immobilisations';

    protected $fillable = [
        'association_id',
        'numero',
        'libelle',
        'quantite',
        'compte_id',
        'compte_amortissement_id',
        'montant_acquisition',
        'date_mise_en_service',
        'duree_mois',
        'transaction_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'duree_mois' => 'integer',
            'montant_acquisition' => 'decimal:2',
            'date_mise_en_service' => 'date',
        ];
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_id');
    }

    public function compteAmortissement(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_amortissement_id');
    }

    public function dotations(): HasMany
    {
        return $this->hasMany(ImmobilisationDotation::class)->orderBy('exercice');
    }

    /**
     * Transactions d'acquisition — au pluriel dès le lot 1, bien qu'adossée à un
     * unique FK. Les consommateurs (fiche, PDF, badge, verrou) sont ainsi écrits
     * contre une collection : le jour où une immobilisation portera plusieurs
     * achats, le passage 1:1 → 1:N ne touchera aucun site de lecture.
     *
     * @return Collection<int, Transaction>
     */
    public function transactionsAcquisition(): Collection
    {
        $transaction = $this->relationLoaded('transaction')
            ? $this->getRelation('transaction')
            : Transaction::find((int) $this->transaction_id);

        return $transaction === null ? collect() : collect([$transaction]);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    /** « 5 ans » quand la durée est un multiple de 12, « 30 mois » sinon. */
    public function getDureeLabelAttribute(): string
    {
        $mois = (int) $this->duree_mois;

        if ($mois % 12 !== 0) {
            return $mois.' mois';
        }

        $annees = intdiv($mois, 12);

        return $annees === 1 ? '1 an' : $annees.' ans';
    }

    /** Cumul des dotations réellement comptabilisées, en centimes. */
    public function cumulAmortiCentimes(): int
    {
        return (int) round(((float) $this->dotations()->sum('montant')) * 100);
    }

    public function montantAcquisitionCentimes(): int
    {
        return (int) round(((float) $this->montant_acquisition) * 100);
    }

    /** Valeur nette comptable, en centimes. */
    public function valeurNetteCentimes(): int
    {
        return $this->montantAcquisitionCentimes() - $this->cumulAmortiCentimes();
    }

    public function estEnService(): bool
    {
        return ! $this->date_mise_en_service->isFuture();
    }
}
