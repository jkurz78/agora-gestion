<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\Operation;
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

        // Dernières adhésions (cotisations) — DC-8 : filtre par compte_id
        // (posé par le pipeline PD sur toutes les lignes de ventilation).
        $cotCompteIds = Compte::forUsage(UsageComptable::Cotisation)->pluck('id');
        $dernieresAdhesions = Transaction::where('type', 'recette')
            ->operationnel()
            ->forExercice($exercice)
            ->whereHas('lignes', fn ($q) => $q->whereIn('compte_id', $cotCompteIds))
            ->with('tiers')
            ->latest('date')->latest('id')
            ->take(10)
            ->get();

        // Derniers dons
        $donCompteIds = Compte::forUsage(UsageComptable::Don)->pluck('id');
        $derniersDons = Transaction::where('type', 'recette')
            ->operationnel()
            ->forExercice($exercice)
            ->whereHas('lignes', fn ($q) => $q->whereIn('compte_id', $donCompteIds))
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
