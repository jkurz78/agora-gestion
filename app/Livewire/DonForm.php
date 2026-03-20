<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Don;
use App\Models\Operation;
use App\Services\DonService;
use App\Services\ExerciceService;
use Livewire\Attributes\On;
use Livewire\Component;

final class DonForm extends Component
{
    public ?int $donId = null;

    public string $date = '';

    public string $montant = '';

    public string $mode_paiement = '';

    public ?string $objet = null;

    public ?int $tiers_id = null;

    public ?int $sous_categorie_id = null;

    public ?int $operation_id = null;

    public ?int $seance = null;

    public ?int $compte_id = null;

    public bool $showForm = false;

    #[On('open-don-form')]
    public function open(?int $id = null): void
    {
        $this->resetForm();
        if ($id !== null) {
            $don = Don::findOrFail($id);
            $this->donId = $don->id;
            $this->date = $don->date->format('Y-m-d');
            $this->montant = (string) $don->montant;
            $this->mode_paiement = $don->mode_paiement->value;
            $this->objet = $don->objet ?? null;
            $this->tiers_id = $don->tiers_id;
            $this->sous_categorie_id = $don->sous_categorie_id;
            $this->operation_id = $don->operation_id;
            $this->seance = $don->seance ?? null;
            $this->compte_id = $don->compte_id;
        } else {
            $this->date = app(ExerciceService::class)->defaultDate();
        }
        $this->showForm = true;
    }

    public function applyStoredDefaults(?int $sous_categorie_id, string $mode_paiement, ?int $compte_id): void
    {
        if ($sous_categorie_id) {
            $this->sous_categorie_id = $sous_categorie_id;
        }
        if ($mode_paiement !== '') {
            $this->mode_paiement = $mode_paiement;
        }
        if ($compte_id) {
            $this->compte_id = $compte_id;
        }
    }

    public function resetForm(): void
    {
        $this->reset([
            'donId', 'date', 'montant', 'mode_paiement', 'objet',
            'tiers_id', 'sous_categorie_id', 'operation_id', 'seance', 'compte_id', 'showForm',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($exerciceService->current());
        $dateDebut = $range['start']->toDateString();
        $dateFin = $range['end']->toDateString();

        $rules = [
            'date' => ['required', 'date', 'after_or_equal:'.$dateDebut, 'before_or_equal:'.$dateFin],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'mode_paiement' => ['required', 'in:virement,cheque,especes,cb,prelevement'],
            'objet' => ['nullable', 'string', 'max:255'],
            'sous_categorie_id' => ['required', 'exists:sous_categories,id'],
            'tiers_id' => ['nullable', 'exists:tiers,id'],
            'operation_id' => ['nullable'],
            'seance' => ['nullable', 'integer', 'min:1'],
            'compte_id' => ['nullable', 'exists:comptes_bancaires,id'],
        ];

        $this->validate($rules, [
            'date.after_or_equal' => 'La date doit être dans l\'exercice en cours (à partir du '.$range['start']->format('d/m/Y').').',
            'date.before_or_equal' => 'La date doit être dans l\'exercice en cours (jusqu\'au '.$range['end']->format('d/m/Y').').',
        ]);

        // Validate seance against operation nombre_seances
        if ($this->operation_id && $this->seance) {
            $operation = Operation::find($this->operation_id);
            if ($operation && $operation->nombre_seances && $this->seance > $operation->nombre_seances) {
                $this->addError('seance', 'La séance doit être entre 1 et '.$operation->nombre_seances.'.');

                return;
            }
        }

        $data = [
            'date' => $this->date,
            'montant' => $this->montant,
            'mode_paiement' => $this->mode_paiement,
            'objet' => $this->objet ?: null,
            'sous_categorie_id' => $this->sous_categorie_id,
            'tiers_id' => $this->tiers_id,
            'operation_id' => $this->operation_id,
            'seance' => $this->seance,
            'compte_id' => $this->compte_id,
        ];

        $service = app(DonService::class);

        if ($this->donId) {
            $don = Don::findOrFail($this->donId);
            $service->update($don, $data);
        } else {
            $service->create($data);
        }

        $this->dispatch('don-saved',
            sous_categorie_id: $this->sous_categorie_id,
            mode_paiement: $this->mode_paiement,
            compte_id: $this->compte_id,
        );
        $this->resetForm();
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.don-form', [
            'naturesdon'   => \App\Models\SousCategorie::where('pour_dons', true)->orderBy('nom')->get(),
            'operations'   => Operation::orderBy('nom')->get(),
            'comptes'      => CompteBancaire::where('actif_dons_cotisations', true)->orderBy('nom')->get(),
            'modesPaiement' => ModePaiement::cases(),
        ]);
    }
}
