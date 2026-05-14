<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatutOperation;
use App\Enums\UsageComptable;
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

        // Budget résumé — agrégation par catégorie pour sous-totaux
        $budgetLines = BudgetLine::forExercice($exercice)
            ->with('sousCategorie.categorie')
            ->get();

        $totalPrevu = (float) $budgetLines->sum('montant_prevu');
        $totalRealise = 0.0;
        $budgetParCategorie = []; // ['categorieNom' => ['type' => 'depense|recette', 'prevu' => float, 'realise' => float]]
        foreach ($budgetLines as $line) {
            $r = (float) $budgetService->realise($line->sous_categorie_id, $exercice);
            $totalRealise += $r;
            $cat = $line->sousCategorie?->categorie;
            $catKey = $cat?->nom ?? '—';
            if (! isset($budgetParCategorie[$catKey])) {
                $budgetParCategorie[$catKey] = [
                    'type' => $cat?->type ?? 'depense',
                    'prevu' => 0.0,
                    'realise' => 0.0,
                ];
            }
            $budgetParCategorie[$catKey]['prevu'] += (float) $line->montant_prevu;
            $budgetParCategorie[$catKey]['realise'] += $r;
        }
        // Tri : recettes en premier, puis dépenses, alpha dans chaque groupe
        uksort($budgetParCategorie, function ($a, $b) use ($budgetParCategorie): int {
            $ta = $budgetParCategorie[$a]['type'] === 'recette' ? 0 : 1;
            $tb = $budgetParCategorie[$b]['type'] === 'recette' ? 0 : 1;

            return $ta <=> $tb ?: strcasecmp($a, $b);
        });

        // Dernières dépenses (avec tiers)
        $dernieresDepenses = Transaction::where('type', 'depense')->forExercice($exercice)
            ->with('tiers')
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Dernières recettes (avec tiers)
        $dernieresRecettes = Transaction::where('type', 'recette')->forExercice($exercice)
            ->with('tiers')
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Derniers dons — transactions ayant au moins une ligne avec sous-cat pour_dons
        $donSousCategorieIds = SousCategorie::forUsage(UsageComptable::Don)->pluck('id');
        $derniersDons = Transaction::where('type', 'recette')
            ->forExercice($exercice)
            ->whereHas('lignes', fn ($q) => $q->whereIn('sous_categorie_id', $donSousCategorieIds))
            ->with(['tiers', 'lignes' => fn ($q) => $q->whereIn('sous_categorie_id', $donSousCategorieIds), 'lignes.sousCategorie'])
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Dernières adhésions (cotisations) — avec adhésions et formule pour afficher label + dates
        $cotSousCategorieIds = SousCategorie::forUsage(UsageComptable::Cotisation)->pluck('id');
        $dernieresAdhesions = Transaction::where('type', 'recette')
            ->forExercice($exercice)
            ->whereHas('lignes', fn ($q) => $q->whereIn('sous_categorie_id', $cotSousCategorieIds))
            ->with(['tiers', 'adhesions.formuleAdhesion'])
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
            'budgetParCategorie' => $budgetParCategorie,
            'dernieresDepenses' => $dernieresDepenses,
            'dernieresRecettes' => $dernieresRecettes,
            'derniersDons' => $derniersDons,
            'dernieresAdhesions' => $dernieresAdhesions,
            'operations' => $operations,
            'comptesAvecSolde' => $comptesAvecSolde,
        ]);
    }
}
