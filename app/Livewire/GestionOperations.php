<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Services\ExerciceService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class GestionOperations extends Component
{
    #[Url(as: 'id')]
    public ?int $selectedOperationId = null;

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

        $selectedOperation = $this->selectedOperationId
            ? Operation::find($this->selectedOperationId)
            : null;

        return view('livewire.gestion-operations', [
            'operations' => $operations,
            'selectedOperation' => $selectedOperation,
        ]);
    }
}
