<?php

// app/Livewire/TiersForm.php
declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\TiersService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class TiersForm extends Component
{
    public ?int $tiersId = null;

    public string $type = 'particulier';

    public string $nom = '';

    public ?string $prenom = null;

    public ?string $email = null;

    public ?string $telephone = null;

    public ?string $adresse_ligne1 = null;

    public bool $pour_depenses = false;

    public bool $pour_recettes = false;

    public bool $showForm = false;

    public function showNewForm(): void
    {
        $this->reset([
            'tiersId', 'type', 'nom', 'prenom', 'email',
            'telephone', 'adresse_ligne1', 'pour_depenses', 'pour_recettes',
        ]);
        $this->type = 'particulier';
        $this->resetValidation();
        $this->showForm = true;
    }

    #[On('edit-tiers')]
    public function edit(int $id): void
    {
        $tiers = Tiers::findOrFail($id);

        $this->tiersId = $tiers->id;
        $this->type = $tiers->type;
        $this->nom = $tiers->nom;
        $this->prenom = $tiers->prenom;
        $this->email = $tiers->email;
        $this->telephone = $tiers->telephone;
        $this->adresse_ligne1 = $tiers->adresse_ligne1;
        $this->pour_depenses = $tiers->pour_depenses;
        $this->pour_recettes = $tiers->pour_recettes;
        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->reset([
            'tiersId', 'type', 'nom', 'prenom', 'email',
            'telephone', 'adresse_ligne1', 'pour_depenses', 'pour_recettes', 'showForm',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'type' => ['required', 'in:entreprise,particulier'],
            'nom' => ['required', 'string', 'max:150'],
            'prenom' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'adresse_ligne1' => ['nullable', 'string', 'max:500'],
            'pour_depenses' => ['boolean'],
            'pour_recettes' => ['boolean'],
        ], [
            'nom.required' => 'Le nom est obligatoire.',
        ]);

        // Au moins un flag doit être coché
        if (! $this->pour_depenses && ! $this->pour_recettes) {
            $this->addError('pour_depenses', 'Cochez au moins une utilisation (depenses ou recettes).');

            return;
        }

        $service = app(TiersService::class);

        if ($this->tiersId) {
            $tiers = Tiers::findOrFail($this->tiersId);
            $service->update($tiers, $validated);
        } else {
            $service->create($validated);
        }

        $this->dispatch('tiers-saved');
        $this->resetForm();
    }

    public function render(): View
    {
        return view('livewire.tiers-form');
    }
}
