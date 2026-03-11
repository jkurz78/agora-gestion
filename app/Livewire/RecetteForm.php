<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Enums\TypeCategorie;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Recette;
use App\Models\SousCategorie;
use App\Services\RecetteService;
use Livewire\Attributes\On;
use Livewire\Component;

final class RecetteForm extends Component
{
    public ?int $recetteId = null;

    public string $date = '';
    public string $libelle = '';
    public string $montant_total = '';
    public string $mode_paiement = '';
    public ?string $payeur = null;
    public ?string $reference = null;
    public ?int $compte_id = null;
    public ?string $notes = null;

    /** @var array<int, array{sous_categorie_id: string, operation_id: string, seance: string, montant: string, notes: string}> */
    public array $lignes = [];

    public bool $showForm = false;

    public function addLigne(): void
    {
        $this->lignes[] = [
            'sous_categorie_id' => '',
            'operation_id' => '',
            'seance' => '',
            'montant' => '',
            'notes' => '',
        ];
    }

    public function removeLigne(int $index): void
    {
        unset($this->lignes[$index]);
        $this->lignes = array_values($this->lignes);
    }

    #[On('edit-recette')]
    public function edit(int $id): void
    {
        $recette = Recette::with('lignes')->findOrFail($id);

        $this->recetteId = $recette->id;
        $this->date = $recette->date->format('Y-m-d');
        $this->libelle = $recette->libelle;
        $this->montant_total = (string) $recette->montant_total;
        $this->mode_paiement = $recette->mode_paiement->value;
        $this->payeur = $recette->payeur;
        $this->reference = $recette->reference;
        $this->compte_id = $recette->compte_id;
        $this->notes = $recette->notes;

        $this->lignes = $recette->lignes->map(fn ($ligne) => [
            'sous_categorie_id' => (string) $ligne->sous_categorie_id,
            'operation_id' => (string) ($ligne->operation_id ?? ''),
            'seance' => (string) ($ligne->seance ?? ''),
            'montant' => (string) $ligne->montant,
            'notes' => (string) ($ligne->notes ?? ''),
        ])->toArray();

        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->reset([
            'recetteId', 'date', 'libelle', 'montant_total', 'mode_paiement',
            'payeur', 'reference', 'compte_id', 'notes', 'lignes', 'showForm',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate([
            'date' => ['required', 'date'],
            'libelle' => ['required', 'string', 'max:255'],
            'montant_total' => ['required', 'numeric', 'min:0.01'],
            'mode_paiement' => ['required', 'in:virement,cheque,especes,cb,prelevement'],
            'compte_id' => ['nullable', 'exists:comptes_bancaires,id'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.sous_categorie_id' => ['required', 'exists:sous_categories,id'],
            'lignes.*.montant' => ['required', 'numeric', 'min:0.01'],
            'lignes.*.operation_id' => ['nullable'],
            'lignes.*.seance' => ['nullable', 'integer', 'min:1'],
            'lignes.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        // Custom validation: sum of lignes must equal montant_total
        $lignesSum = collect($this->lignes)->sum(fn ($l) => (float) $l['montant']);
        if (round($lignesSum, 2) !== round((float) $this->montant_total, 2)) {
            $this->addError('lignes', 'Le total des lignes doit être égal au montant total.');

            return;
        }

        $data = [
            'date' => $this->date,
            'libelle' => $this->libelle,
            'montant_total' => $this->montant_total,
            'mode_paiement' => $this->mode_paiement,
            'payeur' => $this->payeur ?: null,
            'reference' => $this->reference ?: null,
            'compte_id' => $this->compte_id,
            'notes' => $this->notes ?: null,
        ];

        $lignes = collect($this->lignes)->map(fn ($l) => [
            'sous_categorie_id' => (int) $l['sous_categorie_id'],
            'operation_id' => $l['operation_id'] !== '' ? (int) $l['operation_id'] : null,
            'seance' => $l['seance'] !== '' ? (int) $l['seance'] : null,
            'montant' => $l['montant'],
            'notes' => $l['notes'] ?: null,
        ])->toArray();

        $service = app(RecetteService::class);

        if ($this->recetteId) {
            $recette = Recette::findOrFail($this->recetteId);
            $service->update($recette, $data, $lignes);
        } else {
            $service->create($data, $lignes);
        }

        $this->dispatch('recette-saved');
        $this->resetForm();
    }

    public function render()
    {
        $sousCategories = SousCategorie::with('categorie')
            ->whereHas('categorie', fn ($q) => $q->where('type', TypeCategorie::Recette))
            ->orderBy('nom')
            ->get();

        return view('livewire.recette-form', [
            'comptes' => CompteBancaire::orderBy('nom')->get(),
            'sousCategories' => $sousCategories,
            'operations' => Operation::orderBy('nom')->get(),
            'modesPaiement' => ModePaiement::cases(),
        ]);
    }
}
