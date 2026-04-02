<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class OperationDetail extends Component
{
    public Operation $operation;

    public string $activeTab = 'participants';

    public function mount(Operation $operation): void
    {
        $this->operation = $operation->loadMissing('typeOperation.sousCategorie');
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function render(): View
    {
        $operation = $this->operation;

        // Financial summary (same logic as old GestionOperations)
        $totalDepenses = (float) $operation->transactionLignes()
            ->whereHas('transaction', fn ($q) => $q->where('type', 'depense'))
            ->sum('montant');
        $totalRecettes = (float) $operation->transactionLignes()
            ->whereHas('transaction', fn ($q) => $q->where('type', 'recette'))
            ->whereDoesntHave('sousCategorie', fn ($q) => $q->where('pour_dons', true))
            ->sum('montant');
        $totalDons = (float) $operation->transactionLignes()
            ->whereHas('transaction', fn ($q) => $q->where('type', 'recette'))
            ->whereHas('sousCategorie', fn ($q) => $q->where('pour_dons', true))
            ->sum('montant');
        $solde = ($totalRecettes + $totalDons) - $totalDepenses;

        $participantsCount = $operation->participants()->count();
        $seancesCount = $operation->seances()->count();

        // Build metadata string for breadcrumb
        $dateParts = [];
        if ($operation->date_debut) {
            $dateParts[] = $operation->date_debut->format('d/m');
        }
        if ($operation->date_fin) {
            $dateParts[] = $operation->date_fin->format('d/m');
        }
        $operationMeta = implode(' — ', $dateParts);
        if ($participantsCount > 0) {
            $operationMeta .= " · {$participantsCount} participant".($participantsCount > 1 ? 's' : '');
        }
        if ($seancesCount > 0) {
            $operationMeta .= " · {$seancesCount} séance".($seancesCount > 1 ? 's' : '');
        }

        return view('livewire.operation-detail', [
            'totalDepenses' => $totalDepenses,
            'totalRecettes' => $totalRecettes,
            'totalDons' => $totalDons,
            'solde' => $solde,
            'participantsCount' => $participantsCount,
            'operationMeta' => $operationMeta,
        ]);
    }
}
