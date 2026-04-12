<?php

declare(strict_types=1);

namespace App\Livewire\Exercices;

use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\ClotureCheckService;
use App\Services\ExerciceService;
use App\Services\ProvisionService;
use App\Services\RapprochementBancaireService;
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
        $rapprochementService = app(RapprochementBancaireService::class);
        $comptes = CompteBancaire::orderBy('nom')->get();
        $range = app(ExerciceService::class)->dateRange($this->annee);
        $start = $range['start']->toDateString();
        $end = $range['end']->toDateString();

        // Per-account data
        $comptesData = [];
        $totalSoldeOuverture = 0.0;
        $totalSoldeReel = 0.0;
        $totalMouvements = 0.0;
        $totalSoldeRapprochement = 0.0;

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

            // Opening balance = current - all exercice movements
            $soldeOuverture = round($soldeReel - $recettesCompte + $depensesCompte - $virementsIn + $virementsOut, 2);
            $mouvements = round($recettesCompte - $depensesCompte + $virementsIn - $virementsOut, 2);

            // Solde dernier rapprochement verrouillé
            $soldeRapprochement = $rapprochementService->calculerSoldeOuverture($compte);

            $comptesData[] = [
                'nom' => $compte->nom,
                'solde_ouverture' => $soldeOuverture,
                'mouvements' => $mouvements,
                'solde_reel' => $soldeReel,
                'solde_rapprochement' => $soldeRapprochement,
            ];

            $totalSoldeOuverture += $soldeOuverture;
            $totalSoldeReel += $soldeReel;
            $totalMouvements += $mouvements;
            $totalSoldeRapprochement += $soldeRapprochement;
        }

        $totalSoldeOuverture = round($totalSoldeOuverture, 2);
        $totalSoldeReel = round($totalSoldeReel, 2);
        $totalMouvements = round($totalMouvements, 2);
        $totalSoldeRapprochement = round($totalSoldeRapprochement, 2);

        // Total recettes / dépenses of the exercice (across all accounts)
        $totalRecettes = (float) Transaction::where('type', 'recette')->forExercice($this->annee)->sum('montant_total');
        $totalDepenses = (float) Transaction::where('type', 'depense')->forExercice($this->annee)->sum('montant_total');
        $resultat = round($totalRecettes - $totalDepenses, 2);

        // Écritures non pointées (transactions)
        $nonPointeesTx = Transaction::whereNull('rapprochement_id')
            ->whereBetween('date', [$start, $end])
            ->selectRaw("
                COUNT(*) as nombre,
                SUM(CASE WHEN type = 'recette' THEN montant_total ELSE 0 END) as total_recettes,
                SUM(CASE WHEN type = 'depense' THEN montant_total ELSE 0 END) as total_depenses
            ")
            ->first();

        $nombreNonPointeesTx = (int) $nonPointeesTx->nombre;
        $montantNetNonPointeesTx = round((float) $nonPointeesTx->total_recettes - (float) $nonPointeesTx->total_depenses, 2);

        // Virements non pointés (côté source ou destination)
        $virementsNonPointesSource = VirementInterne::whereNull('rapprochement_source_id')
            ->forExercice($this->annee)->count();
        $virementsNonPointesDest = VirementInterne::whereNull('rapprochement_destination_id')
            ->forExercice($this->annee)->count();
        $nombreNonPointeesVir = $virementsNonPointesSource + $virementsNonPointesDest;

        $nombreNonPointees = $nombreNonPointeesTx + $nombreNonPointeesVir;

        // Réconciliation : solde rapprochement + non pointées = solde comptable actuel
        $ecartReconciliation = round($totalSoldeReel - $totalSoldeRapprochement - $montantNetNonPointeesTx, 2);

        $provisionService = app(ProvisionService::class);
        $provisions = $provisionService->provisionsExercice($this->annee);
        $totalProvisions = $provisionService->totalProvisions($this->annee);

        return [
            'comptesData' => $comptesData,
            'totalSoldeOuverture' => $totalSoldeOuverture,
            'totalSoldeReel' => $totalSoldeReel,
            'totalMouvements' => $totalMouvements,
            'totalSoldeRapprochement' => $totalSoldeRapprochement,
            'totalRecettes' => $totalRecettes,
            'totalDepenses' => $totalDepenses,
            'resultat' => $resultat,
            'nombreNonPointees' => $nombreNonPointees,
            'montantNetNonPointeesTx' => $montantNetNonPointeesTx,
            'ecartReconciliation' => $ecartReconciliation,
            'provisions' => $provisions,
            'totalProvisions' => $totalProvisions,
            'nbProvisions' => $provisions->count(),
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
