<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Don;
use App\Models\Donateur;
use App\Models\Operation;
use App\Services\DonService;
use Livewire\Attributes\On;
use Livewire\Component;

final class DonForm extends Component
{
    public ?int $donId = null;

    public string $date = '';
    public string $montant = '';
    public string $mode_paiement = '';
    public ?string $objet = null;
    public ?int $donateur_id = null;
    public ?int $operation_id = null;
    public ?int $seance = null;
    public ?int $compte_id = null;

    public bool $creatingDonateur = false;
    public string $new_donateur_nom = '';
    public string $new_donateur_prenom = '';
    public ?string $new_donateur_email = null;
    public ?string $new_donateur_adresse = null;

    public bool $showForm = false;

    #[On('edit-don')]
    public function edit(int $id): void
    {
        $don = Don::findOrFail($id);

        $this->donId = $don->id;
        $this->date = $don->date->format('Y-m-d');
        $this->montant = (string) $don->montant;
        $this->mode_paiement = $don->mode_paiement->value;
        $this->objet = $don->objet;
        $this->donateur_id = $don->donateur_id;
        $this->operation_id = $don->operation_id;
        $this->seance = $don->seance;
        $this->compte_id = $don->compte_id;

        $this->creatingDonateur = false;
        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->reset([
            'donId', 'date', 'montant', 'mode_paiement', 'objet',
            'donateur_id', 'operation_id', 'seance', 'compte_id',
            'creatingDonateur', 'new_donateur_nom', 'new_donateur_prenom',
            'new_donateur_email', 'new_donateur_adresse', 'showForm',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $rules = [
            'date' => ['required', 'date'],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'mode_paiement' => ['required', 'in:virement,cheque,especes,cb,prelevement'],
            'objet' => ['nullable', 'string', 'max:255'],
            'operation_id' => ['nullable'],
            'seance' => ['nullable', 'integer', 'min:1'],
            'compte_id' => ['nullable', 'exists:comptes_bancaires,id'],
        ];

        if ($this->creatingDonateur) {
            $rules['new_donateur_nom'] = ['required', 'string', 'max:255'];
            $rules['new_donateur_prenom'] = ['required', 'string', 'max:255'];
            $rules['new_donateur_email'] = ['nullable', 'email', 'max:255'];
            $rules['new_donateur_adresse'] = ['nullable', 'string', 'max:500'];
        } else {
            $rules['donateur_id'] = ['nullable', 'exists:donateurs,id'];
        }

        $this->validate($rules);

        // Validate seance against operation nombre_seances
        if ($this->operation_id && $this->seance) {
            $operation = Operation::find($this->operation_id);
            if ($operation && $operation->nombre_seances && $this->seance > $operation->nombre_seances) {
                $this->addError('seance', 'La séance doit être entre 1 et ' . $operation->nombre_seances . '.');

                return;
            }
        }

        $data = [
            'date' => $this->date,
            'montant' => $this->montant,
            'mode_paiement' => $this->mode_paiement,
            'objet' => $this->objet ?: null,
            'donateur_id' => $this->creatingDonateur ? null : $this->donateur_id,
            'operation_id' => $this->operation_id,
            'seance' => $this->seance,
            'compte_id' => $this->compte_id,
        ];

        $newDonateur = null;
        if ($this->creatingDonateur) {
            $newDonateur = [
                'nom' => $this->new_donateur_nom,
                'prenom' => $this->new_donateur_prenom,
                'email' => $this->new_donateur_email ?: null,
                'adresse' => $this->new_donateur_adresse ?: null,
            ];
        }

        $service = app(DonService::class);

        if ($this->donId) {
            $don = Don::findOrFail($this->donId);
            $service->update($don, $data);
        } else {
            $service->create($data, $newDonateur);
        }

        $this->dispatch('don-saved');
        $this->resetForm();
    }

    public function render()
    {
        return view('livewire.don-form', [
            'donateurs' => Donateur::orderBy('nom')->orderBy('prenom')->get(),
            'operations' => Operation::orderBy('nom')->get(),
            'comptes' => CompteBancaire::orderBy('nom')->get(),
            'modesPaiement' => ModePaiement::cases(),
        ]);
    }
}
