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
     * @return array{date_debut: string, date_fin: string, prefixes_comptes: array<int, string>, lignes: list<array<string, mixed>>, totaux: array<string, int>}
     */
    public function balance(string $dateDebut, string $dateFin, array $prefixesComptes = []): array
    {
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

            $cle = $this->cleRegroupement($ligne);
            $groupes[$cle] ??= $this->ligneVide($ligne);

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
            ->sort(fn (array $a, array $b): int => $this->comparerLignes($a, $b))
            ->values()
            ->all();

        return [
            'date_debut' => $debut->toDateString(),
            'date_fin' => $fin->toDateString(),
            'prefixes_comptes' => $prefixes,
            'lignes' => $lignesBalance,
            'totaux' => $this->totaux($lignesBalance),
        ];
    }

    private function cleRegroupement(TransactionLigne $ligne): string
    {
        $numero = $ligne->compte?->numero_pcg ?? '';
        $tiersId = in_array($numero, ['401', '411'], true) && $ligne->tiers_id !== null
            ? (int) $ligne->tiers_id
            : 0;

        return ((int) $ligne->compte_id).'|'.$tiersId;
    }

    /**
     * @return array<string, mixed>
     */
    private function ligneVide(TransactionLigne $ligne): array
    {
        /** @var Compte $compte */
        $compte = $ligne->compte;

        return [
            'compte_id' => (int) $compte->id,
            'numero_compte' => (string) $compte->numero_pcg,
            'intitule_compte' => (string) $compte->intitule,
            'tiers_id' => $ligne->tiers_id !== null ? (int) $ligne->tiers_id : null,
            'tiers' => $ligne->tiers?->displayName(),
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
