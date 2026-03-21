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

    public ?string $entreprise = null;

    public ?string $email = null;

    public ?string $telephone = null;

    public ?string $adresse_ligne1 = null;

    public ?string $code_postal = null;

    public ?string $ville = null;

    public string $pays = 'France';

    public ?string $date_naissance = null;

    public bool $pour_depenses = false;

    public bool $pour_recettes = false;

    public bool $showForm = false;

    public bool $showDetails = false;

    public bool $showNewButton = false;

    public function showNewForm(): void
    {
        $this->reset([
            'tiersId', 'type', 'nom', 'prenom', 'entreprise', 'email', 'telephone',
            'adresse_ligne1', 'code_postal', 'ville', 'pays', 'date_naissance',
            'pour_depenses', 'pour_recettes', 'showDetails',
        ]);
        $this->type = 'particulier';
        $this->pays = 'France';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function updatedType(): void
    {
        if ($this->type === 'entreprise') {
            $this->entreprise = trim(($this->prenom ? $this->prenom . ' ' : '') . $this->nom);
            $this->nom = '';
            $this->prenom = null;
        }
    }

    #[On('edit-tiers')]
    public function edit(int $id): void
    {
        $tiers = Tiers::findOrFail($id);

        $this->tiersId        = $tiers->id;
        $this->type           = $tiers->type;
        $this->nom            = $tiers->nom;
        $this->prenom         = $tiers->prenom;
        $this->entreprise     = $tiers->entreprise;
        $this->email          = $tiers->email;
        $this->telephone      = $tiers->telephone;
        $this->adresse_ligne1 = $tiers->adresse_ligne1;
        $this->code_postal    = $tiers->code_postal;
        $this->ville          = $tiers->ville;
        $this->pays           = $tiers->pays ?? 'France';
        $this->date_naissance = $tiers->date_naissance?->format('Y-m-d');
        $this->pour_depenses  = $tiers->pour_depenses;
        $this->pour_recettes  = $tiers->pour_recettes;

        $this->showDetails = (bool) ($tiers->email || $tiers->telephone
            || $tiers->adresse_ligne1 || $tiers->code_postal
            || $tiers->ville || ($tiers->pays && $tiers->pays !== 'France')
            || $tiers->date_naissance);

        $this->showForm = true;
    }

    #[On('open-tiers-form')]
    public function openWithPrefill(array $prefill): void
    {
        $this->reset([
            'tiersId', 'type', 'nom', 'prenom', 'entreprise', 'email', 'telephone',
            'adresse_ligne1', 'code_postal', 'ville', 'pays', 'date_naissance',
            'pour_depenses', 'pour_recettes', 'showDetails',
        ]);
        $this->type          = 'particulier';
        $this->pays          = 'France';
        $this->nom           = $prefill['nom'] ?? '';
        $this->pour_recettes = (bool) ($prefill['pour_recettes'] ?? false);
        $this->pour_depenses = (bool) ($prefill['pour_depenses'] ?? false);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->reset([
            'tiersId', 'type', 'nom', 'prenom', 'entreprise', 'email', 'telephone',
            'adresse_ligne1', 'code_postal', 'ville', 'pays', 'date_naissance',
            'pour_depenses', 'pour_recettes', 'showForm', 'showDetails',
        ]);
        $this->pays = 'France';
        $this->resetValidation();
    }

    public function save(): void
    {
        $rules = [
            'type'           => ['required', 'in:entreprise,particulier'],
            'nom'            => $this->type === 'particulier'
                ? ['required', 'string', 'max:150']
                : ['nullable', 'string', 'max:150'],
            'prenom'         => ['nullable', 'string', 'max:100'],
            'entreprise'     => $this->type === 'entreprise'
                ? ['required', 'string', 'max:255']
                : ['nullable', 'string', 'max:255'],
            'email'          => ['nullable', 'email', 'max:255'],
            'telephone'      => ['nullable', 'string', 'max:30'],
            'adresse_ligne1' => ['nullable', 'string', 'max:500'],
            'code_postal'    => ['nullable', 'string', 'max:10'],
            'ville'          => ['nullable', 'string', 'max:100'],
            'pays'           => ['nullable', 'string', 'max:100'],
            'date_naissance' => ['nullable', 'date'],
            'pour_depenses'  => ['boolean'],
            'pour_recettes'  => ['boolean'],
        ];

        $validated = $this->validate($rules, [
            'nom.required'        => 'Le nom est obligatoire.',
            'entreprise.required' => 'La raison sociale est obligatoire.',
        ]);

        if (! $this->pour_depenses && ! $this->pour_recettes) {
            $this->addError('pour_depenses', 'Cochez au moins une utilisation (dépenses ou recettes).');
            return;
        }

        $service = app(TiersService::class);

        if ($this->tiersId) {
            $tiers = Tiers::findOrFail($this->tiersId);
            $tiers = $service->update($tiers, $validated);
        } else {
            $tiers = $service->create($validated);
        }

        $id = $tiers->id;
        $this->dispatch('tiers-saved', id: $id);
        $this->resetForm();
    }

    public function render(): View
    {
        return view('livewire.tiers-form');
    }
}
