<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\StatutOperation;
use App\Models\Operation;
use App\Models\TypeOperation;
use App\Services\ExerciceService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

final class OperationList extends Component
{
    #[Url(as: 'type')]
    public ?int $filterTypeId = null;

    #[Url(as: 'exercice')]
    public ?int $filterExercice = null;

    #[Url(as: 'periode')]
    public string $filterPeriode = '';

    // ── Modal properties ──
    public bool $showCreateModal = false;

    public bool $showEditModal = false;

    public ?int $editOperationId = null;

    public string $formNom = '';

    public string $formDescription = '';

    public string $formDateDebut = '';

    public string $formDateFin = '';

    public ?int $formNombreSeances = null;

    public ?int $formTypeOperationId = null;

    public function mount(): void
    {
        if ($this->filterExercice === null) {
            $this->filterExercice = app(ExerciceService::class)->current();
        }
    }

    public function getCanEditProperty(): bool
    {
        return Auth::user()->role->canWrite(Espace::Gestion);
    }

    public function openCreateModal(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $this->resetForm();
        $this->showCreateModal = true;
        $this->showEditModal = false;
    }

    public function openEditModal(int $operationId): void
    {
        if (! $this->canEdit) {
            return;
        }

        $operation = Operation::findOrFail($operationId);

        $this->editOperationId = $operation->id;
        $this->formNom = $operation->nom ?? '';
        $this->formDescription = $operation->description ?? '';
        $this->formDateDebut = $operation->date_debut?->format('Y-m-d') ?? '';
        $this->formDateFin = $operation->date_fin?->format('Y-m-d') ?? '';
        $this->formNombreSeances = $operation->nombre_seances;
        $this->formTypeOperationId = $operation->type_operation_id;

        $this->showEditModal = true;
        $this->showCreateModal = false;
    }

    public function closeModal(): void
    {
        $this->showCreateModal = false;
        $this->showEditModal = false;
        $this->resetForm();
    }

    public function saveOperation(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $validated = $this->validate([
            'formNom' => 'required|max:150',
            'formDateDebut' => 'required|date',
            'formDateFin' => 'required|date|after_or_equal:formDateDebut',
            'formTypeOperationId' => 'required|exists:type_operations,id',
            'formNombreSeances' => 'nullable|integer|min:1',
            'formDescription' => 'nullable|string',
        ]);

        $data = [
            'nom' => $validated['formNom'],
            'description' => $validated['formDescription'] ?? '',
            'date_debut' => $validated['formDateDebut'],
            'date_fin' => $validated['formDateFin'],
            'nombre_seances' => $validated['formNombreSeances'],
            'type_operation_id' => $validated['formTypeOperationId'],
        ];

        if ($this->editOperationId !== null) {
            $operation = Operation::findOrFail($this->editOperationId);
            $operation->update($data);
        } else {
            $data['statut'] = StatutOperation::EnCours;
            Operation::create($data);
        }

        $this->closeModal();
    }

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $this->filterExercice ?? $exerciceService->current();
        $range = $exerciceService->dateRange($exercice);

        $operationsQuery = Operation::query()
            ->with('typeOperation.sousCategorie')
            ->withCount('participants')
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

        $now = now()->toDateString();
        if ($this->filterPeriode === 'futur') {
            $operationsQuery->where('date_debut', '>', $now);
        } elseif ($this->filterPeriode === 'en_cours') {
            $operationsQuery->where('date_debut', '<=', $now)
                ->where(fn ($q) => $q->whereNull('date_fin')->orWhere('date_fin', '>=', $now));
        } elseif ($this->filterPeriode === 'termine') {
            $operationsQuery->whereNotNull('date_fin')->where('date_fin', '<', $now);
        }

        $operations = $operationsQuery->orderBy('date_debut')->get();

        $typeOperations = TypeOperation::actif()->orderBy('nom')->get();

        // Exercice years for dropdown: from now+1 down to now-3
        $currentYear = now()->year;
        $exerciceYears = range($currentYear + 1, $currentYear - 3);

        return view('livewire.operation-list', [
            'operations' => $operations,
            'typeOperations' => $typeOperations,
            'exerciceYears' => $exerciceYears,
            'exerciceService' => $exerciceService,
        ]);
    }

    private function resetForm(): void
    {
        $this->editOperationId = null;
        $this->formNom = '';
        $this->formDescription = '';
        $this->formDateDebut = '';
        $this->formDateFin = '';
        $this->formNombreSeances = null;
        $this->formTypeOperationId = null;
        $this->resetValidation();
    }
}
