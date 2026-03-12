<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\BudgetLine;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\Membre;
use App\Models\Recette;
use App\Services\BudgetService;
use App\Services\ExerciceService;
use App\Services\SoldeService;
use Livewire\Component;

final class Dashboard extends Component
{
    public int $exercice;

    public function mount(): void
    {
        $this->exercice = app(ExerciceService::class)->current();
    }

    public function render()
    {
        $exerciceService = app(ExerciceService::class);
        $budgetService = app(BudgetService::class);

        $range = $exerciceService->dateRange($this->exercice);
        $startDate = $range['start']->toDateString();
        $endDate = $range['end']->toDateString();

        // Solde général
        $totalRecettes = (float) Recette::forExercice($this->exercice)->sum('montant_total');
        $totalDepenses = (float) Depense::forExercice($this->exercice)->sum('montant_total');
        $soldeGeneral = $totalRecettes - $totalDepenses;

        // Budget résumé
        $budgetLines = BudgetLine::forExercice($this->exercice)
            ->with('sousCategorie.categorie')
            ->get();

        $totalPrevu = (float) $budgetLines->sum('montant_prevu');
        $totalRealise = 0.0;
        foreach ($budgetLines as $line) {
            $totalRealise += $budgetService->realise($line->sous_categorie_id, $this->exercice);
        }

        // Dernières dépenses
        $dernieresDepenses = Depense::forExercice($this->exercice)
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Dernières recettes
        $dernieresRecettes = Recette::forExercice($this->exercice)
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Derniers dons
        $derniersDons = Don::forExercice($this->exercice)
            ->with('donateur')
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Membres sans cotisation pour l'exercice courant
        $membresSansCotisation = Membre::whereDoesntHave('cotisations', function ($q) {
            $q->where('exercice', $this->exercice);
        })->orderBy('nom')->get();

        // Comptes bancaires avec soldes courants
        $soldeService = app(SoldeService::class);
        $comptesAvecSolde = CompteBancaire::orderBy('nom')->get()
            ->map(fn (CompteBancaire $c) => [
                'compte' => $c,
                'solde'  => $soldeService->solde($c),
            ]);

        return view('livewire.dashboard', [
            'exercices' => $exerciceService->available(),
            'exerciceService' => $exerciceService,
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
