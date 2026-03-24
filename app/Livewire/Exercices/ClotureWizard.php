<?php

declare(strict_types=1);

namespace App\Livewire\Exercices;

use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\ClotureCheckService;
use App\Services\ExerciceService;
use App\Services\SoldeService;
use Illuminate\View\View;
use Livewire\Component;

final class ClotureWizard extends Component
{
    public int $step = 1;

    public int $annee;

    public bool $peutCloturer = false;

    public function mount(): void
    {
        $exerciceService = app(ExerciceService::class);
        $this->annee = $exerciceService->current();

        $exercice = $exerciceService->exerciceAffiche();
        if ($exercice?->isCloture()) {
            $this->redirect(route('exercices.changer'));

            return;
        }

        $this->runChecks();
    }

    public function runChecks(): void
    {
        $result = app(ClotureCheckService::class)->executer($this->annee);
        $this->peutCloturer = $result->peutCloturer();
    }

    public function suite(): void
    {
        if ($this->step === 1) {
            $this->runChecks();
            if (! $this->peutCloturer) {
                return;
            }
            $this->step = 2;

            return;
        }

        if ($this->step === 2) {
            $this->step = 3;
        }
    }

    public function goToStep(int $step): void
    {
        if ($step < $this->step) {
            $this->step = $step;
            if ($step === 1) {
                $this->runChecks();
            }
        }
    }

    public function cloturer(): void
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->exerciceAffiche();

        if ($exercice === null || $exercice->isCloture()) {
            return;
        }

        $exerciceService->cloturer($exercice, auth()->user());

        session()->flash('success', "L'exercice {$exercice->label()} a été clôturé avec succès.");
        $this->redirect(route('exercices.changer'));
    }

    /**
     * Compute the financial summary data for step 2.
     *
     * @return array<string, mixed>
     */
    private function computeFinancialSummary(): array
    {
        $soldeService = app(SoldeService::class);
        $comptes = CompteBancaire::orderBy('nom')->get();

        // Per-account data
        $comptesData = [];
        $totalSoldeOuverture = 0.0;
        $totalSoldeReel = 0.0;
        $totalMouvements = 0.0;

        foreach ($comptes as $compte) {
            $soldeReel = $soldeService->solde($compte);

            // Recettes and dépenses in the exercice for this account
            $recettesCompte = (float) $compte->recettes()->forExercice($this->annee)->sum('montant_total');
            $depensesCompte = (float) $compte->depenses()->forExercice($this->annee)->sum('montant_total');

            // Virements in exercice for this account
            $virementsIn = (float) VirementInterne::where('compte_destination_id', $compte->id)
                ->forExercice($this->annee)
                ->sum('montant');
            $virementsOut = (float) VirementInterne::where('compte_source_id', $compte->id)
                ->forExercice($this->annee)
                ->sum('montant');

            // Opening balance = current - recettes + depenses - virements_in + virements_out
            $soldeOuverture = round($soldeReel - $recettesCompte + $depensesCompte - $virementsIn + $virementsOut, 2);
            $mouvements = round($recettesCompte - $depensesCompte + $virementsIn - $virementsOut, 2);

            $comptesData[] = [
                'nom' => $compte->nom,
                'solde_ouverture' => $soldeOuverture,
                'mouvements' => $mouvements,
                'solde_reel' => $soldeReel,
            ];

            $totalSoldeOuverture += $soldeOuverture;
            $totalSoldeReel += $soldeReel;
            $totalMouvements += $mouvements;
        }

        $totalSoldeOuverture = round($totalSoldeOuverture, 2);
        $totalSoldeReel = round($totalSoldeReel, 2);
        $totalMouvements = round($totalMouvements, 2);

        // Total recettes / dépenses of the exercice (across all accounts)
        $totalRecettes = (float) Transaction::where('type', 'recette')->forExercice($this->annee)->sum('montant_total');
        $totalDepenses = (float) Transaction::where('type', 'depense')->forExercice($this->annee)->sum('montant_total');
        $resultat = round($totalRecettes - $totalDepenses, 2);

        // Solde théorique = opening balances + résultat (virements cancel out, so result drives the change from pure recettes/depenses)
        // But mouvements include virements too, so: solde_theorique = totalSoldeOuverture + totalMouvements
        $soldeTheorique = round($totalSoldeOuverture + $totalMouvements, 2);

        // Écritures non pointées (transactions)
        $nonPointeesTx = Transaction::whereNull('rapprochement_id')
            ->forExercice($this->annee)
            ->selectRaw("
                COUNT(*) as nombre,
                SUM(CASE WHEN type = 'recette' THEN montant_total ELSE 0 END) as total_recettes,
                SUM(CASE WHEN type = 'depense' THEN montant_total ELSE 0 END) as total_depenses
            ")
            ->first();

        $nombreNonPointeesTx = (int) $nonPointeesTx->nombre;
        $montantNetNonPointeesTx = round((float) $nonPointeesTx->total_recettes - (float) $nonPointeesTx->total_depenses, 2);

        // Écritures non pointées (virements) - a virement can be non-pointed on source, destination, or both
        $virementsNonPointesSource = VirementInterne::whereNull('rapprochement_source_id')
            ->forExercice($this->annee)
            ->count();
        $virementsNonPointesDest = VirementInterne::whereNull('rapprochement_destination_id')
            ->forExercice($this->annee)
            ->count();
        $nombreNonPointeesVir = $virementsNonPointesSource + $virementsNonPointesDest;

        // Net impact of non-pointed virements on bank balance:
        // Non-pointed source-side: money left the account but bank doesn't see it yet → bank has more (+montant)
        // Non-pointed dest-side: money arrived but bank doesn't see it yet → bank has less (-montant)
        $montantNonPointesVirSource = (float) VirementInterne::whereNull('rapprochement_source_id')
            ->forExercice($this->annee)
            ->sum('montant');
        $montantNonPointesVirDest = (float) VirementInterne::whereNull('rapprochement_destination_id')
            ->forExercice($this->annee)
            ->sum('montant');
        // Virements are internal transfers, they net to 0 in aggregate. The non-pointed ones just explain
        // per-account discrepancies but don't affect the total reconciliation.
        $montantNetNonPointeesVir = 0.0;

        $nombreNonPointees = $nombreNonPointeesTx + $nombreNonPointeesVir;
        $montantNetNonPointees = round($montantNetNonPointeesTx + $montantNetNonPointeesVir, 2);

        // Écart résiduel: solde_theorique - (solde_reel + non_pointees_net)
        $ecartResiduel = round($soldeTheorique - $totalSoldeReel - $montantNetNonPointees, 2);

        return [
            'comptesData' => $comptesData,
            'totalSoldeOuverture' => $totalSoldeOuverture,
            'totalSoldeReel' => $totalSoldeReel,
            'totalMouvements' => $totalMouvements,
            'totalRecettes' => $totalRecettes,
            'totalDepenses' => $totalDepenses,
            'resultat' => $resultat,
            'soldeTheorique' => $soldeTheorique,
            'nombreNonPointees' => $nombreNonPointees,
            'montantNetNonPointees' => $montantNetNonPointees,
            'ecartResiduel' => $ecartResiduel,
        ];
    }

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);
        $checkResult = app(ClotureCheckService::class)->executer($this->annee);

        $viewData = [
            'exerciceLabel' => $exerciceService->label($this->annee),
            'checkResult' => $checkResult,
        ];

        if ($this->step === 2) {
            $viewData['summary'] = $this->computeFinancialSummary();
        }

        return view('livewire.exercices.cloture-wizard', $viewData);
    }
}
