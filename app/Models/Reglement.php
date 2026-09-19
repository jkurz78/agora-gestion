<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModePaiement;
use App\Tenant\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Reglement extends Model
{
    use HasFactory;

    protected $fillable = [
        'participant_id',
        'seance_id',
        'mode_paiement',
        'montant_prevu',
        'remise_id',
    ];

    protected function casts(): array
    {
        return [
            'mode_paiement' => ModePaiement::class,
            'montant_prevu' => 'decimal:2',
            'remise_id' => 'integer',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function seance(): BelongsTo
    {
        return $this->belongsTo(Seance::class);
    }

    public function remise(): BelongsTo
    {
        return $this->belongsTo(RemiseBancaire::class, 'remise_id');
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class, 'reglement_id');
    }

    /**
     * Règlements prêts à comptabiliser : montant prévu, mode de paiement
     * renseigné, et pas encore de transaction (une transaction supprimée ne
     * compte pas). Seule définition de « prêt » — le service de
     * comptabilisation et la grille des règlements s'en servent tous deux.
     *
     * Colonnes qualifiées : `mode_paiement` existe aussi sur `transactions`,
     * qualifier lève l'ambiguïté en cas de jointure future.
     *
     * Le scope tenant de Transaction est retiré de la sous-requête « a une
     * transaction » : ce fait ne dépend pas de l'association active (la
     * transaction liée est nécessairement de la même association, via le
     * participant) — sans ce retrait, en l'absence d'association active
     * (TenantContext non booté) la réponse s'inverserait.
     *
     * @param  Builder<Reglement>  $query
     * @return Builder<Reglement>
     */
    public function scopeAComptabiliser(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('montant_prevu'), '>', 0)
            ->whereNotNull($query->qualifyColumn('mode_paiement'))
            ->whereDoesntHave('transaction', fn (Builder $q) => $q->withoutGlobalScope(TenantScope::class));
    }

    /**
     * Vrai dès qu'une transaction non supprimée pointe vers ce règlement :
     * sa case est alors verrouillée dans la grille.
     *
     * Le scope tenant de Transaction est retiré : ce fait ne dépend pas de
     * l'association active (la transaction liée est nécessairement de la
     * même association, via le participant) — sans ce retrait, en l'absence
     * d'association active la réponse s'inverserait (false pour un règlement
     * pourtant comptabilisé).
     */
    public function estComptabilise(): bool
    {
        return $this->transaction()->withoutGlobalScope(TenantScope::class)->exists();
    }
}
