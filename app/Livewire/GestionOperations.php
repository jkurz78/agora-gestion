<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Models\TypeOperation;
use App\Services\ExerciceService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class GestionOperations extends Component
{
    #[Url(as: 'id')]
    public ?int $selectedOperationId = null;

    #[Url(as: 'type')]
    public ?int $filterTypeId = null;

    public string $activeTab = 'details';

    public function selectOperation(int $id): void
    {
        $this->selectedOperationId = $id;
        $this->activeTab = 'details';
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->current();
        $range = $exerciceService->dateRange($exercice);

        $operationsQuery = Operation::query()
            ->with('typeOperation')
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
            });

        if ($this->filterTypeId !== null) {
            $operationsQuery->where('type_operation_id', $this->filterTypeId);
        }

        $operations = $operationsQuery->orderBy('date_debut')->get();

        $groupedOperations = $operations->groupBy(function (Operation $op): string {
            return $op->typeOperation
                ? $op->typeOperation->code.' — '.$op->typeOperation->nom
                : 'Sans type';
        })->sortKeys();

        $hasMissingTypes = Operation::whereNull('type_operation_id')->exists();

        $typeOperations = TypeOperation::actif()->orderBy('nom')->get();

        $selectedOperation = $this->selectedOperationId
            ? Operation::find($this->selectedOperationId)
            : null;

        $totalDepenses = 0;
        $totalRecettes = 0;
        $totalDons = 0;
        $solde = 0;

        if ($selectedOperation) {
            $totalDepenses = (float) $selectedOperation->transactionLignes()
                ->whereHas('transaction', fn ($q) => $q->where('type', 'depense'))
                ->sum('montant');
            $totalRecettes = (float) $selectedOperation->transactionLignes()
                ->whereHas('transaction', fn ($q) => $q->where('type', 'recette'))
                ->whereDoesntHave('sousCategorie', fn ($q) => $q->where('pour_dons', true))
                ->sum('montant');
            $totalDons = (float) $selectedOperation->transactionLignes()
                ->whereHas('transaction', fn ($q) => $q->where('type', 'recette'))
                ->whereHas('sousCategorie', fn ($q) => $q->where('pour_dons', true))
                ->sum('montant');
            $solde = ($totalRecettes + $totalDons) - $totalDepenses;
        }

        return view('livewire.gestion-operations', [
            'operations' => $operations,
            'groupedOperations' => $groupedOperations,
            'hasMissingTypes' => $hasMissingTypes,
            'typeOperations' => $typeOperations,
            'selectedOperation' => $selectedOperation,
            'totalDepenses' => $totalDepenses,
            'totalRecettes' => $totalRecettes,
            'totalDons' => $totalDons,
            'solde' => $solde,
        ]);
    }
}
