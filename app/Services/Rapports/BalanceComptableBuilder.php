<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Models\Compte;
use App\Models\TransactionLigne;
use App\Services\Rapports\Concerns\AnalysePeriodeComptable;
use Carbon\CarbonImmutable;

final class BalanceComptableBuilder
{
    use AnalysePeriodeComptable;

    /**
     * @param  array<int, string>  $prefixesComptes
     * @param  bool  $uniquementNonSoldes  N'affiche que les comptes (et tiers
     *                                     auxiliaires) dont le solde de fin
     *                                     n'est pas nul.
     * @param  bool  $detailParTiers  Balance auxiliaire (une ligne par tiers sur
     *                                les comptes collectifs 401/411) au lieu de
     *                                la balance générale.
     * @return array{date_debut: string, date_fin: string, prefixes_comptes: array<int, string>, uniquement_non_soldes: bool, detail_par_tiers: bool, lignes: list<array<string, mixed>>, totaux: array<string, int>}
     */
    public function balance(
        string $dateDebut,
        string $dateFin,
        array $prefixesComptes = [],
        bool $uniquementNonSoldes = false,
        bool $detailParTiers = false,
    ): array {
        $debut = CarbonImmutable::parse($dateDebut)->startOfDay();
        $fin = CarbonImmutable::parse($dateFin)->startOfDay();
        $prefixes = $this->normaliserPrefixes($prefixesComptes);
        $coupure = $this->dateCoupureANouveau($debut);

        $lignes = $this->requeteLignes($fin, $coupure, $prefixes)->get();
        $groupes = [];

        foreach ($lignes as $ligne) {
            $transaction = $ligne->transaction;
            $compte = $ligne->compte;
            if ($transaction === null || $compte === null) {
                continue;
            }

            $estOuverture = $this->estOuverture($ligne, $debut, $coupure);
            $estMouvement = $this->estMouvement($ligne, $debut, $fin, $coupure);

            if (! $estOuverture && ! $estMouvement) {
                continue;
            }

            $cle = $this->cleRegroupement($ligne, $detailParTiers);
            $groupes[$cle] ??= $this->ligneVide($ligne, $detailParTiers);

            if ($estOuverture) {
                $groupes[$cle]['ouverture_debit_centimes'] += $this->centimes($ligne->debit);
                $groupes[$cle]['ouverture_credit_centimes'] += $this->centimes($ligne->credit);
            }

            if ($estMouvement) {
                $groupes[$cle]['mouvement_debit_centimes'] += $this->centimes($ligne->debit);
                $groupes[$cle]['mouvement_credit_centimes'] += $this->centimes($ligne->credit);
            }
        }

        $lignesBalance = collect($groupes)
            ->map(fn (array $ligne): array => $this->finaliserLigne($ligne))
            ->filter(fn (array $ligne): bool => $this->ligneNonNulle($ligne))
            ->filter(fn (array $ligne): bool => ! $uniquementNonSoldes
                || (int) $ligne['solde_fin_centimes'] !== 0)
            ->sort(fn (array $a, array $b): int => $this->comparerLignes($a, $b))
            ->values()
            ->all();

        return [
            'date_debut' => $debut->toDateString(),
            'date_fin' => $fin->toDateString(),
            'prefixes_comptes' => $prefixes,
            'uniquement_non_soldes' => $uniquementNonSoldes,
            'detail_par_tiers' => $detailParTiers,
            'lignes' => $lignesBalance,
            // Les totaux portent sur la sélection affichée, comme pour le
            // filtre par préfixe de comptes.
            'totaux' => $this->totaux($lignesBalance),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ligneVide(TransactionLigne $ligne, bool $detailParTiers): array
    {
        /** @var Compte $compte */
        $compte = $ligne->compte;
        // En balance générale, le compte collectif ne porte aucun tiers : sans
        // cette remise à zéro, le premier tiers rencontré étiquetterait à tort
        // l'ensemble du regroupement.
        $tiersId = $this->tiersAuxiliaire($ligne, $detailParTiers);

        return [
            'compte_id' => (int) $compte->id,
            'numero_compte' => (string) $compte->numero_pcg,
            'intitule_compte' => (string) $compte->intitule,
            'tiers_id' => $tiersId !== 0 ? $tiersId : null,
            'tiers' => $tiersId !== 0 ? $ligne->tiers?->displayName() : null,
            'ouverture_debit_centimes' => 0,
            'ouverture_credit_centimes' => 0,
            'mouvement_debit_centimes' => 0,
            'mouvement_credit_centimes' => 0,
            'solde_ouverture_centimes' => 0,
            'solde_fin_centimes' => 0,
            'solde_ouverture_debit_centimes' => 0,
            'solde_ouverture_credit_centimes' => 0,
            'solde_fin_debit_centimes' => 0,
            'solde_fin_credit_centimes' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $ligne
     * @return array<string, mixed>
     */
    private function finaliserLigne(array $ligne): array
    {
        $soldeOuverture = (int) $ligne['ouverture_debit_centimes']
            - (int) $ligne['ouverture_credit_centimes'];
        $soldeFin = $soldeOuverture
            + (int) $ligne['mouvement_debit_centimes']
            - (int) $ligne['mouvement_credit_centimes'];

        $ligne['solde_ouverture_centimes'] = $soldeOuverture;
        $ligne['solde_fin_centimes'] = $soldeFin;
        $ligne['solde_ouverture_debit_centimes'] = max($soldeOuverture, 0);
        $ligne['solde_ouverture_credit_centimes'] = max(-$soldeOuverture, 0);
        $ligne['solde_fin_debit_centimes'] = max($soldeFin, 0);
        $ligne['solde_fin_credit_centimes'] = max(-$soldeFin, 0);

        return $ligne;
    }

    /**
     * @param  array<string, mixed>  $ligne
     */
    private function ligneNonNulle(array $ligne): bool
    {
        return (int) $ligne['ouverture_debit_centimes'] !== 0
            || (int) $ligne['ouverture_credit_centimes'] !== 0
            || (int) $ligne['mouvement_debit_centimes'] !== 0
            || (int) $ligne['mouvement_credit_centimes'] !== 0;
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return array<string, int>
     */
    private function totaux(array $lignes): array
    {
        return [
            'ouverture_debit_centimes' => array_sum(array_column($lignes, 'ouverture_debit_centimes')),
            'ouverture_credit_centimes' => array_sum(array_column($lignes, 'ouverture_credit_centimes')),
            'mouvement_debit_centimes' => array_sum(array_column($lignes, 'mouvement_debit_centimes')),
            'mouvement_credit_centimes' => array_sum(array_column($lignes, 'mouvement_credit_centimes')),
            'solde_fin_debit_centimes' => array_sum(array_column($lignes, 'solde_fin_debit_centimes')),
            'solde_fin_credit_centimes' => array_sum(array_column($lignes, 'solde_fin_credit_centimes')),
        ];
    }

    /**
     * Trie les numéros de comptes comme des codes comptables, et non comme
     * des nombres : 5112 doit précéder 530.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function comparerLignes(array $a, array $b): int
    {
        return strcmp((string) $a['numero_compte'], (string) $b['numero_compte'])
            ?: strcmp((string) ($a['tiers'] ?? ''), (string) ($b['tiers'] ?? ''));
    }
}
