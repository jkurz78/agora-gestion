<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CompteBancaire;
use App\Models\VirementInterne;
use App\Services\ExerciceService;
use App\Services\VirementInterneService;
use Livewire\Attributes\On;
use Livewire\Component;

final class VirementInterneForm extends Component
{
    public ?int $virementId = null;

    public string $date = '';

    public string $montant = '';

    public ?int $compte_source_id = null;

    public ?int $compte_destination_id = null;

    public ?string $reference = null;

    public ?string $notes = null;

    public bool $showForm = false;

    public function showNewForm(): void
    {
        $this->reset(['virementId', 'date', 'montant', 'compte_source_id',
            'compte_destination_id', 'reference', 'notes']);
        $this->resetValidation();
        $this->showForm = true;
        $this->date = app(ExerciceService::class)->defaultDate();
    }

    #[On('open-virement-form')]
    public function open(?int $id = null): void
    {
        $this->resetForm();
        if ($id !== null) {
            $virement = VirementInterne::findOrFail($id);
            $this->virementId = $virement->id;
            $this->date = $virement->date->format('Y-m-d');
            $this->montant = (string) $virement->montant;
            $this->compte_source_id = $virement->compte_source_id;
            $this->compte_destination_id = $virement->compte_destination_id;
            $this->reference = $virement->reference;
            $this->notes = $virement->notes;
        } else {
            $this->date = app(ExerciceService::class)->defaultDate();
        }
        $this->showForm = true;
    }

    #[On('edit-virement')]
    public function editVirement(int $id): void
    {
        $this->open($id);
    }

    public function resetForm(): void
    {
        $this->reset([
            'virementId', 'date', 'montant', 'compte_source_id',
            'compte_destination_id', 'reference', 'notes', 'showForm',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($exerciceService->current());
        $dateDebut = $range['start']->toDateString();
        $dateFin = $range['end']->toDateString();

        $this->validate([
            'date' => ['required', 'date', 'after_or_equal:'.$dateDebut, 'before_or_equal:'.$dateFin],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'compte_source_id' => ['required', 'exists:comptes_bancaires,id'],
            'compte_destination_id' => [
                'required',
                'exists:comptes_bancaires,id',
                'different:compte_source_id',
            ],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'date.after_or_equal' => 'La date doit être dans l\'exercice en cours (à partir du '.$range['start']->format('d/m/Y').').',
            'date.before_or_equal' => 'La date doit être dans l\'exercice en cours (jusqu\'au '.$range['end']->format('d/m/Y').').',
        ]);

        $data = [
            'date' => $this->date,
            'montant' => $this->montant,
            'compte_source_id' => $this->compte_source_id,
            'compte_destination_id' => $this->compte_destination_id,
            'reference' => $this->reference ?: null,
            'notes' => $this->notes ?: null,
        ];

        $service = app(VirementInterneService::class);

        if ($this->virementId) {
            $virement = VirementInterne::findOrFail($this->virementId);
            $service->update($virement, $data);
        } else {
            $service->create($data);
        }

        $this->dispatch('virement-saved');
        $this->resetForm();
    }

    public function render()
    {
        return view('livewire.virement-interne-form', [
            'comptes' => CompteBancaire::orderBy('nom')->get(),
        ]);
    }
}
