<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Enums\TypeCategorie;
use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Services\DepenseService;
use Livewire\Attributes\On;
use Livewire\Component;

final class DepenseForm extends Component
{
    public ?int $depenseId = null;

    public string $date = '';

    public string $libelle = '';

    public string $mode_paiement = '';

    public ?string $beneficiaire = null;

    public ?string $reference = null;

    public ?int $compte_id = null;

    public ?string $notes = null;

    /** @var array<int, array{sous_categorie_id: string, operation_id: string, seance: string, montant: string, notes: string}> */
    public array $lignes = [];

    public bool $showForm = false;

    public function getMontantTotalProperty(): float
    {
        return round(collect($this->lignes)->sum(fn ($l) => (float) ($l['montant'] ?? 0)), 2);
    }

    public function showNewForm(): void
    {
        $this->reset(['depenseId', 'date', 'libelle', 'mode_paiement',
            'beneficiaire', 'reference', 'compte_id', 'notes', 'lignes']);
        $this->resetValidation();

        $this->showForm = true;
        $this->date = now()->format('Y-m-d');

        $this->compte_id = Depense::where('saisi_par', auth()->id())
            ->whereNotNull('compte_id')
            ->latest()
            ->value('compte_id');

        $this->addLigne();
    }

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

    #[On('edit-depense')]
    public function edit(int $id): void
    {
        $depense = Depense::with('lignes')->findOrFail($id);

        $this->depenseId = $depense->id;
        $this->date = $depense->date->format('Y-m-d');
        $this->libelle = $depense->libelle;
        $this->mode_paiement = $depense->mode_paiement->value;
        $this->beneficiaire = $depense->beneficiaire;
        $this->reference = $depense->reference;
        $this->compte_id = $depense->compte_id;
        $this->notes = $depense->notes;

        $this->lignes = $depense->lignes->map(fn ($ligne) => [
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
            'depenseId', 'date', 'libelle', 'mode_paiement',
            'beneficiaire', 'reference', 'compte_id', 'notes', 'lignes', 'showForm',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate([
            'date' => ['required', 'date'],
            'libelle' => ['required', 'string', 'max:255'],
            'mode_paiement' => ['required', 'in:virement,cheque,especes,cb,prelevement'],
            'compte_id' => ['nullable', 'exists:comptes_bancaires,id'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.sous_categorie_id' => ['required', 'exists:sous_categories,id'],
            'lignes.*.montant' => ['required', 'numeric', 'min:0.01'],
            'lignes.*.operation_id' => ['nullable'],
            'lignes.*.seance' => ['nullable', 'integer', 'min:1'],
            'lignes.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $data = [
            'date' => $this->date,
            'libelle' => $this->libelle,
            'montant_total' => $this->montantTotal,
            'mode_paiement' => $this->mode_paiement,
            'beneficiaire' => $this->beneficiaire ?: null,
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

        $service = app(DepenseService::class);

        if ($this->depenseId) {
            $depense = Depense::findOrFail($this->depenseId);
            $service->update($depense, $data, $lignes);
        } else {
            $service->create($data, $lignes);
        }

        $this->dispatch('depense-saved');
        $this->resetForm();
    }

    public function render()
    {
        $sousCategories = SousCategorie::with('categorie')
            ->whereHas('categorie', fn ($q) => $q->where('type', TypeCategorie::Depense))
            ->orderBy('nom')
            ->get();

        return view('livewire.depense-form', [
            'comptes' => CompteBancaire::orderBy('nom')->get(),
            'sousCategories' => $sousCategories,
            'operations' => Operation::orderBy('nom')->get(),
            'modesPaiement' => ModePaiement::cases(),
        ]);
    }
}
