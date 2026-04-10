<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatutOperation;
use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Models\BudgetLine;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Services\BudgetService;
use App\Services\ExerciceService;
use App\Services\SoldeService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Dashboard extends Component
{
    use RespectsExerciceCloture;

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

        // Derniers dons — transactions ayant au moins une ligne avec sous-cat pour_dons
        $donSousCategorieIds = SousCategorie::where('pour_dons', true)->pluck('id');
        $derniersDons = Transaction::where('type', 'recette')
            ->forExercice($exercice)
            ->whereHas('lignes', fn ($q) => $q->whereIn('sous_categorie_id', $donSousCategorieIds))
            ->with('tiers')
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Dernières adhésions (cotisations)
        $cotSousCategorieIds = SousCategorie::where('pour_cotisations', true)->pluck('id');
        $dernieresAdhesions = Transaction::where('type', 'recette')
            ->forExercice($exercice)
            ->whereHas('lignes', fn ($q) => $q->whereIn('sous_categorie_id', $cotSousCategorieIds))
            ->with('tiers')
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Opérations de l'exercice (hors terminées)
        $range = $exerciceService->dateRange($exercice);
        $operations = Operation::query()
            ->with(['typeOperation.sousCategorie.categorie'])
            ->withCount('participants')
            ->where('statut', '!=', StatutOperation::Cloturee)
            ->where(function ($q) use ($range): void {
                $q->where(function ($inner) use ($range): void {
                    $inner->whereNotNull('date_debut')
                        ->whereNotNull('date_fin')
                        ->where('date_debut', '<=', $range['end']->toDateString())
                        ->where('date_fin', '>=', $range['start']->toDateString());
                })->orWhere(function ($inner) use ($range): void {
                    $inner->whereNotNull('date_debut')
                        ->whereNull('date_fin')
                        ->where('date_debut', '<=', $range['end']->toDateString())
                        ->where('date_debut', '>=', $range['start']->toDateString());
                });
            })
            ->get()
            ->sortBy([
                fn ($a, $b) => ($a->typeOperation?->sousCategorie?->nom ?? '') <=> ($b->typeOperation?->sousCategorie?->nom ?? ''),
                fn ($a, $b) => ($a->typeOperation?->nom ?? '') <=> ($b->typeOperation?->nom ?? ''),
                fn ($a, $b) => $a->nom <=> $b->nom,
            ])->values();

        // Comptes bancaires avec soldes courants
        $soldeService = app(SoldeService::class);
        $comptesAvecSolde = CompteBancaire::where('est_systeme', false)->orderBy('nom')->get()
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
            'dernieresAdhesions' => $dernieresAdhesions,
            'operations' => $operations,
            'comptesAvecSolde' => $comptesAvecSolde,
        ]);
    }
}
