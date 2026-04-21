<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\UsageComptable;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Services\ExerciceService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class GestionDashboard extends Component
{
    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->current();
        $range = $exerciceService->dateRange($exercice);

        // Opérations : toutes pour l'exercice (including those with null date_fin), triées par date_debut
        $operations = Operation::query()
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
            ->orderBy('date_debut')
            ->get();

        // Dernières adhésions (cotisations)
        $cotSousCategorieIds = SousCategorie::forUsage(UsageComptable::Cotisation)->pluck('id');
        $dernieresAdhesions = Transaction::where('type', 'recette')
            ->forExercice($exercice)
            ->whereHas('lignes', fn ($q) => $q->whereIn('sous_categorie_id', $cotSousCategorieIds))
            ->with('tiers')
            ->latest('date')->latest('id')
            ->take(10)
            ->get();

        // Derniers dons
        $donSousCategorieIds = SousCategorie::forUsage(UsageComptable::Don)->pluck('id');
        $derniersDons = Transaction::where('type', 'recette')
            ->forExercice($exercice)
            ->whereHas('lignes', fn ($q) => $q->whereIn('sous_categorie_id', $donSousCategorieIds))
            ->with('tiers')
            ->latest('date')->latest('id')
            ->take(10)
            ->get();

        return view('livewire.gestion-dashboard', [
            'operations' => $operations,
            'dernieresAdhesions' => $dernieresAdhesions,
            'derniersDons' => $derniersDons,
        ]);
    }
}
