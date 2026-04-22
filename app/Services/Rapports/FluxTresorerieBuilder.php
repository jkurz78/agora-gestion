<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\ProvisionService;
use App\Services\SoldeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class FluxTresorerieBuilder
{
    /** @return array{string, string} */
    private function exerciceDates(int $exercice): array
    {
        return ["{$exercice}-09-01", ($exercice + 1).'-08-31'];
    }

    /**
     * État de flux de trésorerie consolidé.
     *
     * @return array{exercice: array, synthese: array, rapprochement: array, mensuel: list<array>, ecritures_non_pointees: list<array>}
     */
    public function fluxTresorerie(int $exercice): array
    {
        $soldeService = app(SoldeService::class);
        [$start, $end] = $this->exerciceDates($exercice);

        // --- Exercice info ---
        $exerciceModel = Exercice::where('annee', $exercice)->first();
        $exerciceInfo = [
            'annee' => $exercice,
            'label' => $exercice.'-'.($exercice + 1),
            'date_debut' => $start,
            'date_fin' => $end,
            'is_cloture' => $exerciceModel?->isCloture() ?? false,
            'date_cloture' => $exerciceModel?->date_cloture?->format('d/m/Y'),
        ];

        // --- Solde d'ouverture consolidé (tous comptes, y compris système) ---
        $comptes = CompteBancaire::all();
        $soldeOuverture = 0.0;

        foreach ($comptes as $compte) {
            $soldeActuelCompte = $soldeService->solde($compte);
            $recettesCompte = (float) $compte->recettes()->forExercice($exercice)->sum('montant_total');
            $depensesCompte = (float) $compte->depenses()->forExercice($exercice)->sum('montant_total');
            $virementsIn = (float) VirementInterne::where('compte_destination_id', $compte->id)
                ->forExercice($exercice)->sum('montant');
            $virementsOut = (float) VirementInterne::where('compte_source_id', $compte->id)
                ->forExercice($exercice)->sum('montant');

            $soldeOuverture += $soldeActuelCompte - $recettesCompte + $depensesCompte - $virementsIn + $virementsOut;
        }
        $soldeOuverture = round($soldeOuverture, 2);

        // --- Totaux consolidés (virements s'annulent) ---
        $totalRecettes = round((float) Transaction::where('type', 'recette')->forExercice($exercice)->sum('montant_total'), 2);
        $totalDepenses = round((float) Transaction::where('type', 'depense')->forExercice($exercice)->sum('montant_total'), 2);
        $variation = round($totalRecettes - $totalDepenses, 2);
        $soldeTheorique = round($soldeOuverture + $variation, 2);

        // --- Provisions de fin d'exercice ---
        $provisionService = app(ProvisionService::class);
        $totalProvisions = $provisionService->totalProvisions($exercice);
        $totalExtournes = $provisionService->totalExtournes($exercice);

        // --- Rapprochement (tous les comptes) ---
        $comptesReelsIds = CompteBancaire::pluck('id');
        $nonPointees = Transaction::whereNull('rapprochement_id')
            ->whereIn('compte_id', $comptesReelsIds)
            ->whereBetween('date', [$start, $end])
            ->selectRaw("
                SUM(CASE WHEN type = 'recette' THEN montant_total ELSE 0 END) as total_recettes,
                SUM(CASE WHEN type = 'depense' THEN montant_total ELSE 0 END) as total_depenses,
                SUM(CASE WHEN type = 'recette' THEN 1 ELSE 0 END) as nb_recettes,
                SUM(CASE WHEN type = 'depense' THEN 1 ELSE 0 END) as nb_depenses
            ")
            ->first();

        $recettesNonPointees = round((float) ($nonPointees->total_recettes ?? 0), 2);
        $depensesNonPointees = round((float) ($nonPointees->total_depenses ?? 0), 2);
        $nbRecettesNonPointees = (int) ($nonPointees->nb_recettes ?? 0);
        $nbDepensesNonPointees = (int) ($nonPointees->nb_depenses ?? 0);

        $soldeReel = round($soldeTheorique - $recettesNonPointees + $depensesNonPointees, 2);

        // --- Ventilation mensuelle ---
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $yearExpr = $isSqlite ? "CAST(strftime('%Y', date) AS INTEGER)" : 'YEAR(date)';
        $monthExpr = $isSqlite ? "CAST(strftime('%m', date) AS INTEGER)" : 'MONTH(date)';

        $mensuelRows = Transaction::forExercice($exercice)
            ->selectRaw("
                {$yearExpr} as annee, {$monthExpr} as mois_num,
                SUM(CASE WHEN type = 'recette' THEN montant_total ELSE 0 END) as recettes,
                SUM(CASE WHEN type = 'depense' THEN montant_total ELSE 0 END) as depenses
            ")
            ->groupByRaw("{$yearExpr}, {$monthExpr}")
            ->get()
            ->keyBy(fn ($row) => $row->annee.'-'.str_pad((string) $row->mois_num, 2, '0', STR_PAD_LEFT));

        $mensuel = [];
        $cumul = $soldeOuverture;
        for ($i = 0; $i < 12; $i++) {
            $moisNum = (($i + 8) % 12) + 1; // 9,10,11,12,1,2,3,4,5,6,7,8
            $annee = $moisNum >= 9 ? $exercice : $exercice + 1;
            $key = $annee.'-'.str_pad((string) $moisNum, 2, '0', STR_PAD_LEFT);

            $recettes = round((float) ($mensuelRows[$key]->recettes ?? 0), 2);
            $depenses = round((float) ($mensuelRows[$key]->depenses ?? 0), 2);
            $solde = round($recettes - $depenses, 2);
            $cumul = round($cumul + $solde, 2);

            $moisLabel = ucfirst(Carbon::create($annee, $moisNum, 1)->translatedFormat('F Y'));

            $mensuel[] = [
                'mois' => $moisLabel,
                'recettes' => $recettes,
                'depenses' => $depenses,
                'solde' => $solde,
                'cumul' => $cumul,
            ];
        }

        // --- Liste écritures non pointées (pour PDF lot 4, comptes réels uniquement) ---
        $ecrituresNonPointees = Transaction::whereNull('rapprochement_id')
            ->whereIn('compte_id', $comptesReelsIds)
            ->whereBetween('date', [$start, $end])
            ->with('tiers')
            ->orderBy('date')
            ->get()
            ->map(fn (Transaction $t) => [
                'numero_piece' => $t->numero_piece,
                'date' => $t->date->format('d/m/Y'),
                'tiers' => $t->tiers?->displayName() ?? '—',
                'libelle' => $t->libelle,
                'type' => $t->type->value,
                'montant' => (float) $t->montant_total,
            ])
            ->values()
            ->all();

        return [
            'exercice' => $exerciceInfo,
            'synthese' => [
                'solde_ouverture' => $soldeOuverture,
                'total_recettes' => $totalRecettes,
                'total_depenses' => $totalDepenses,
                'variation' => $variation,
                'solde_theorique' => $soldeTheorique,
                'total_provisions' => $totalProvisions,
                'total_extournes' => $totalExtournes,
            ],
            'rapprochement' => [
                'solde_theorique' => $soldeTheorique,
                'recettes_non_pointees' => $recettesNonPointees,
                'nb_recettes_non_pointees' => $nbRecettesNonPointees,
                'depenses_non_pointees' => $depensesNonPointees,
                'nb_depenses_non_pointees' => $nbDepensesNonPointees,
                'solde_reel' => $soldeReel,
            ],
            'mensuel' => $mensuel,
            'ecritures_non_pointees' => $ecrituresNonPointees,
        ];
    }
}
