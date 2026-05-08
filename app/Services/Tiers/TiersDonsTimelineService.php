<?php

declare(strict_types=1);

namespace App\Services\Tiers;

use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\Tiers\DTO\AnneeCivileDTO;
use App\Services\Tiers\DTO\DonLigneDTO;
use App\Services\Tiers\DTO\DonsTimelineDTO;
use App\Tenant\TenantContext;
use Illuminate\Support\Collection;

final class TiersDonsTimelineService
{
    public function forTiers(Tiers $tiers, ?int $anneeCivile = null): DonsTimelineDTO
    {
        $sousCategorieIds = SousCategorie::forUsage(UsageComptable::Don)->pluck('id');

        $query = TransactionLigne::query()
            ->whereHas('transaction', function ($q) use ($tiers, $anneeCivile) {
                $q->where('tiers_id', (int) $tiers->id)
                    ->where('type', TypeTransaction::Recette->value);
                if ($anneeCivile !== null) {
                    $q->whereYear('date', $anneeCivile);
                }
            })
            ->whereIn('sous_categorie_id', $sousCategorieIds)
            ->with(['transaction', 'sousCategorie'])
            ->orderByDesc('id');

        $dons = $query->get();

        $recusParLigne = RecuFiscalEmis::query()
            ->whereIn('transaction_ligne_id', $dons->pluck('id'))
            ->whereNull('annule_at')
            ->get()
            ->keyBy('transaction_ligne_id');

        $asso = Association::findOrFail(TenantContext::currentId());

        $raisonBlocageGlobal = $this->raisonBlocageGlobal($asso);
        $adresseTiersOk = ! empty($tiers->adresse_ligne1)
            && ! empty($tiers->code_postal)
            && ! empty($tiers->ville);

        $lignesDto = $dons->map(fn (TransactionLigne $don): DonLigneDTO => new DonLigneDTO(
            ligne: $don,
            recu: $recusParLigne->get($don->id),
            alertes: $this->alertesPourLigne($don, $asso, $tiers),
            peutTelecharger: $this->peutTelecharger($don, $asso, $adresseTiersOk),
            raisonBlocage: $this->raisonBlocagePourLigne($don, $asso, $adresseTiersOk),
        ));

        $groupes = $lignesDto
            ->groupBy(fn (DonLigneDTO $dto): int => (int) $dto->ligne->transaction->date->format('Y'))
            ->sortKeysDesc();

        $annees = [];
        foreach ($groupes as $annee => $items) {
            /** @var Collection<int, DonLigneDTO> $items */
            $annees[(int) $annee] = new AnneeCivileDTO(
                annee: (int) $annee,
                count: $items->count(),
                total: (string) $items->sum(fn (DonLigneDTO $d): float => (float) $d->ligne->montant),
                lignes: $items->values()->all(),
            );
        }

        return new DonsTimelineDTO(
            annees: $annees,
            totalCount: $lignesDto->count(),
            totalMontant: (string) $lignesDto->sum(fn (DonLigneDTO $d): float => (float) $d->ligne->montant),
            raisonBlocageGlobal: $raisonBlocageGlobal,
        );
    }

    private function raisonBlocageGlobal(Association $asso): ?string
    {
        if (! $asso->eligible_recu_fiscal) {
            return "Cette association n'est pas configurée pour émettre des reçus fiscaux.";
        }
        if (empty($asso->signataire_nom) || empty($asso->signataire_qualite)) {
            return "Le signataire des reçus fiscaux n'est pas configuré (nom et qualité requis).";
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function alertesPourLigne(TransactionLigne $don, Association $asso, Tiers $tiers): array
    {
        $alertes = [];

        if ($don->transaction->helloasso_payment_id !== null) {
            $alertes[] = 'helloasso';
        }

        if (
            ($asso->updated_at !== null && $asso->updated_at->gt($don->transaction->created_at))
            || ($tiers->updated_at !== null && $tiers->updated_at->gt($don->transaction->created_at))
        ) {
            $alertes[] = 'donnees_modifiees';
        }

        return $alertes;
    }

    private function peutTelecharger(TransactionLigne $don, Association $asso, bool $adresseTiersOk): bool
    {
        if (! $asso->eligible_recu_fiscal) {
            return false;
        }
        if (empty($asso->signataire_nom) || empty($asso->signataire_qualite)) {
            return false;
        }
        if (! $don->transaction->statut_reglement->isEncaisse()) {
            return false;
        }

        return $adresseTiersOk;
    }

    private function raisonBlocagePourLigne(TransactionLigne $don, Association $asso, bool $adresseTiersOk): ?string
    {
        if (! $asso->eligible_recu_fiscal || empty($asso->signataire_nom) || empty($asso->signataire_qualite)) {
            return 'Configuration association incomplète';
        }
        if (! $don->transaction->statut_reglement->isEncaisse()) {
            return 'Don non encaissé';
        }
        if (! $adresseTiersOk) {
            return 'Adresse du donateur incomplète';
        }

        return null;
    }
}
