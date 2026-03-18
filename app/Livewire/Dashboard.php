<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\BudgetLine;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Don;
use App\Models\Transaction;
use App\Models\Tiers;
use App\Services\BudgetService;
use App\Services\ExerciceService;
use App\Services\SoldeService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Dashboard extends Component
{
    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);
        $budgetService = app(BudgetService::class);
        $exercice = $exerciceService->current();

        $range = $exerciceService->dateRange($exercice);
        $startDate = $range['start']->toDateString();
        $endDate = $range['end']->toDateString();

        // Solde général
        $totalRecettes = (float) Transaction::where('type', 'recette')->forExercice($exercice)->sum('montant_total');
        $totalDepenses = (float) Transaction::where('type', 'depense')->forExercice($exercice)->sum('montant_total');
        $soldeGeneral = $totalRecettes - $totalDepenses;

        // Budget résumé
        $budgetLines = BudgetLine::forExercice($exercice)
            ->with('sousCategorie.categorie')
            ->get();

        $totalPrevu = (float) $budgetLines->sum('montant_prevu');
        $totalRealise = 0.0;
        foreach ($budgetLines as $line) {
            $totalRealise += $budgetService->realise($line->sous_categorie_id, $exercice);
        }

        // Dernières dépenses
        $dernieresDepenses = Transaction::where('type', 'depense')->forExercice($exercice)
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Dernières recettes
        $dernieresRecettes = Transaction::where('type', 'recette')->forExercice($exercice)
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Derniers dons
        $derniersDons = Don::forExercice($exercice)
            ->with('tiers')
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Membres sans cotisation pour l'exercice courant
        $membresSansCotisation = Tiers::whereHas('cotisations')->whereDoesntHave('cotisations', function ($q) use ($exercice) {
            $q->where('exercice', $exercice);
        })->orderBy('nom')->get();

        // Comptes bancaires avec soldes courants
        $soldeService = app(SoldeService::class);
        $comptesAvecSolde = CompteBancaire::orderBy('nom')->get()
            ->map(fn (CompteBancaire $c) => [
                'compte' => $c,
                'solde' => $soldeService->solde($c),
            ]);

        return view('livewire.dashboard', [
            'soldeGeneral' => $soldeGeneral,
            'totalRecettes' => $totalRecettes,
            'totalDepenses' => $totalDepenses,
            'totalPrevu' => $totalPrevu,
            'totalRealise' => $totalRealise,
            'dernieresDepenses' => $dernieresDepenses,
            'dernieresRecettes' => $dernieresRecettes,
            'derniersDons' => $derniersDons,
            'membresSansCotisation' => $membresSansCotisation,
            'comptesAvecSolde' => $comptesAvecSolde,
        ]);
    }
}
