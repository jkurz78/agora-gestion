<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeTransaction;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use Illuminate\Database\Eloquent\Collection;

final class EncadrementMatrixBuilder
{
    /**
     * @return array{
     *     animateurs: array<int, array{
     *         tiersId: int,
     *         tiersName: string,
     *         sousCategories: array<int, array{
     *             scId: int,
     *             scName: string,
     *             previsionIds: array<int, int|null>,
     *             prevuParSeance: array<int, float>,
     *             realiseParSeance: array<int, float>,
     *             transactionIdsParSeance: array<int, array<int>>,
     *             numeroPiecesParSeance: array<int, array<string>>,
     *             hasRealise: bool,
     *             totalPrevu: float,
     *             totalRealise: float,
     *         }>,
     *         totalPrevuParSeance: array<int, float>,
     *         totalRealiseParSeance: array<int, float>,
     *         totalPrevu: float,
     *         totalRealise: float,
     *     }>,
     *     seancePrevuTotaux: array<int, float>,
     *     seanceRealiseTotaux: array<int, float>,
     *     grandPrevu: float,
     *     grandRealise: float,
     *     orphanRealiseHorsSeance: array<int, float>,
     * }
     */
    public function build(Operation $operation): array
    {
        /** @var Collection<int, Seance> $seances */
        $seances = Seance::where('operation_id', $operation->id)->orderBy('numero')->get();
        $seanceIdByNumero = $seances->pluck('id', 'numero'); // numero => id

        $animateurs = [];
        $seancePrevuTotaux = [];
        $seanceRealiseTotaux = [];
        $orphanRealiseHorsSeance = [];

        // 1) Charger les prévisions
        $previsions = EncadrementPrevision::with(['tiers', 'sousCategorie'])
            ->where('operation_id', $operation->id)
            ->get();

        foreach ($previsions as $p) {
            $tId = (int) $p->tiers_id;
            $scId = (int) $p->sous_categorie_id;
            $sId = (int) $p->seance_id;
            $montant = (float) $p->montant_prevu;

            $this->ensureAnimateur($animateurs, $p->tiers);
            $this->ensureSousCategorie($animateurs, $tId, $p->sousCategorie);

            $animateurs[$tId]['sousCategories'][$scId]['previsionIds'][$sId] = (int) $p->id;
            $animateurs[$tId]['sousCategories'][$scId]['prevuParSeance'][$sId] = $montant;
            $animateurs[$tId]['sousCategories'][$scId]['totalPrevu'] += $montant;
            $animateurs[$tId]['totalPrevuParSeance'][$sId] = ($animateurs[$tId]['totalPrevuParSeance'][$sId] ?? 0.0) + $montant;
            $animateurs[$tId]['totalPrevu'] += $montant;
            $seancePrevuTotaux[$sId] = ($seancePrevuTotaux[$sId] ?? 0.0) + $montant;
        }

        // 2) Charger les réalisés (transaction_lignes Dépense sur l'opération)
        $lignes = TransactionLigne::query()
            ->whereHas('transaction', fn ($q) => $q->where('type', TypeTransaction::Depense))
            ->where('operation_id', $operation->id)
            ->with(['transaction.tiers', 'sousCategorie'])
            ->get();

        foreach ($lignes as $ligne) {
            $tx = $ligne->transaction;
            if ($tx === null || $tx->tiers === null) {
                continue;
            }

            $tId = (int) $tx->tiers_id;
            $scId = (int) $ligne->sous_categorie_id;
            $montant = (float) $ligne->montant;
            $seanceNumero = $ligne->seance;

            $this->ensureAnimateur($animateurs, $tx->tiers);

            if ($seanceNumero === null) {
                $orphanRealiseHorsSeance[$tId] = ($orphanRealiseHorsSeance[$tId] ?? 0.0) + $montant;
                $animateurs[$tId]['totalRealise'] += $montant;

                continue;
            }

            $sId = (int) ($seanceIdByNumero->get($seanceNumero) ?? 0);
            if ($sId === 0) {
                // Stale séance number (séance supprimée) : on route vers orphan plutôt
                // que de perdre le montant silencieusement.
                $orphanRealiseHorsSeance[$tId] = ($orphanRealiseHorsSeance[$tId] ?? 0.0) + $montant;
                $animateurs[$tId]['totalRealise'] += $montant;

                continue;
            }

            $this->ensureSousCategorie($animateurs, $tId, $ligne->sousCategorie);
            $animateurs[$tId]['sousCategories'][$scId]['realiseParSeance'][$sId] = ($animateurs[$tId]['sousCategories'][$scId]['realiseParSeance'][$sId] ?? 0.0) + $montant;
            $animateurs[$tId]['sousCategories'][$scId]['transactionIdsParSeance'][$sId][] = (int) $tx->id;
            if ($tx->numero_piece) {
                $animateurs[$tId]['sousCategories'][$scId]['numeroPiecesParSeance'][$sId][] = (string) $tx->numero_piece;
            }
            $animateurs[$tId]['sousCategories'][$scId]['totalRealise'] += $montant;
            $animateurs[$tId]['sousCategories'][$scId]['hasRealise'] = true;
            $animateurs[$tId]['totalRealiseParSeance'][$sId] = ($animateurs[$tId]['totalRealiseParSeance'][$sId] ?? 0.0) + $montant;
            $animateurs[$tId]['totalRealise'] += $montant;
            $seanceRealiseTotaux[$sId] = ($seanceRealiseTotaux[$sId] ?? 0.0) + $montant;
        }

        // 3) Trier les encadrants par nom
        uasort($animateurs, fn (array $a, array $b): int => strcasecmp($a['tiersName'], $b['tiersName']));

        return [
            'animateurs' => $animateurs,
            'seancePrevuTotaux' => $seancePrevuTotaux,
            'seanceRealiseTotaux' => $seanceRealiseTotaux,
            'grandPrevu' => array_sum($seancePrevuTotaux),
            'grandRealise' => array_sum($seanceRealiseTotaux) + array_sum($orphanRealiseHorsSeance),
            'orphanRealiseHorsSeance' => $orphanRealiseHorsSeance,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $animateurs
     */
    private function ensureAnimateur(array &$animateurs, Tiers $tiers): void
    {
        $tId = (int) $tiers->id;
        if (isset($animateurs[$tId])) {
            return;
        }

        $animateurs[$tId] = [
            'tiersId' => $tId,
            'tiersName' => $tiers->displayName(),
            'sousCategories' => [],
            'totalPrevuParSeance' => [],
            'totalRealiseParSeance' => [],
            'totalPrevu' => 0.0,
            'totalRealise' => 0.0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $animateurs
     */
    private function ensureSousCategorie(array &$animateurs, int $tiersId, ?SousCategorie $sousCategorie): void
    {
        if ($sousCategorie === null) {
            return;
        }
        $scId = (int) $sousCategorie->id;
        if (isset($animateurs[$tiersId]['sousCategories'][$scId])) {
            return;
        }

        $animateurs[$tiersId]['sousCategories'][$scId] = [
            'scId' => $scId,
            'scName' => $sousCategorie->nom,
            'previsionIds' => [],
            'prevuParSeance' => [],
            'realiseParSeance' => [],
            'transactionIdsParSeance' => [],
            'numeroPiecesParSeance' => [],
            'hasRealise' => false,
            'totalPrevu' => 0.0,
            'totalRealise' => 0.0,
        ];
    }
}
