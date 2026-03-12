<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CompteBancaire;
use App\Models\VirementInterne;
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
        $this->date = now()->format('Y-m-d');
    }

    #[On('edit-virement')]
    public function edit(int $id): void
    {
        $virement = VirementInterne::findOrFail($id);

        $this->virementId = $virement->id;
        $this->date = $virement->date->format('Y-m-d');
        $this->montant = (string) $virement->montant;
        $this->compte_source_id = $virement->compte_source_id;
        $this->compte_destination_id = $virement->compte_destination_id;
        $this->reference = $virement->reference;
        $this->notes = $virement->notes;

        $this->showForm = true;
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
        $this->validate([
            'date' => ['required', 'date'],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'compte_source_id' => ['required', 'exists:comptes_bancaires,id'],
            'compte_destination_id' => [
                'required',
                'exists:comptes_bancaires,id',
                'different:compte_source_id',
            ],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
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
