<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Enums\StatutOperation;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Services\ExerciceService;
use App\Services\TransactionService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class TransactionForm extends Component
{
    public ?int $transactionId = null;

    public string $type = '';

    public string $date = '';

    public ?string $libelle = null;

    public string $mode_paiement = '';

    public ?int $tiers_id = null;

    public ?string $reference = null;

    public ?int $compte_id = null;

    public ?string $notes = null;

    /** @var array<int, array{sous_categorie_id: string, operation_id: string, seance: string, montant: string, notes: string}> */
    public array $lignes = [];

    public bool $showForm = false;

    public bool $isLocked = false;

    // État du panneau de ventilation
    public ?int $ventilationLigneId = null;

    public string $ventilationLigneSousCategorie = '';

    public string $ventilationLigneMontant = '';

    /** @var array<int, array{operation_id: string, seance: string, montant: string, notes: string}> */
    public array $affectations = [];

    public bool $ventilationHasAffectations = false;

    public function getMontantTotalProperty(): float
    {
        return round(collect($this->lignes)->sum(fn ($l) => (float) ($l['montant'] ?? 0)), 2);
    }

    public function showNewForm(string $type): void
    {
        $this->reset(['transactionId', 'type', 'date', 'libelle', 'mode_paiement',
            'tiers_id', 'reference', 'compte_id', 'notes', 'lignes',
            'ventilationLigneId', 'ventilationLigneSousCategorie', 'ventilationLigneMontant', 'affectations',
            'ventilationHasAffectations']);
        $this->type = $type;
        $this->isLocked = false;
        $this->resetValidation();
        $this->showForm = true;
        $this->date = app(ExerciceService::class)->defaultDate();
        $this->compte_id = Transaction::where('saisi_par', auth()->id())
            ->whereNotNull('compte_id')
            ->latest()
            ->value('compte_id');
        $this->addLigne();
    }

    #[On('open-transaction-form')]
    public function openForm(string $type, ?int $id = null): void
    {
        if ($id !== null) {
            $this->edit($id);
        } else {
            $this->showNewForm($type);
        }
    }

    public function addLigne(): void
    {
        $this->lignes[] = [
            'id' => null,
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

    public function ouvrirVentilation(int $ligneId): void
    {
        $allowedIds = collect($this->lignes)->pluck('id')->filter()->map(fn ($id) => (int) $id)->toArray();
        if (! in_array($ligneId, $allowedIds, true)) {
            abort(403);
        }

        $ligne = TransactionLigne::with('affectations', 'sousCategorie')->findOrFail($ligneId);
        $this->ventilationLigneId = $ligneId;
        $this->ventilationLigneSousCategorie = $ligne->sousCategorie->nom ?? '';
        $this->ventilationLigneMontant = (string) $ligne->montant;
        $this->ventilationHasAffectations = $ligne->affectations->isNotEmpty();

        if ($ligne->affectations->isEmpty()) {
            $this->affectations = [[
                'operation_id' => (string) ($ligne->operation_id ?? ''),
                'seance' => (string) ($ligne->seance ?? ''),
                'montant' => (string) $ligne->montant,
                'notes' => (string) ($ligne->notes ?? ''),
            ]];
        } else {
            $this->affectations = $ligne->affectations->map(fn ($a) => [
                'operation_id' => (string) ($a->operation_id ?? ''),
                'seance' => (string) ($a->seance ?? ''),
                'montant' => (string) $a->montant,
                'notes' => (string) ($a->notes ?? ''),
            ])->toArray();
        }
    }

    public function fermerVentilation(): void
    {
        $this->ventilationLigneId = null;
        $this->ventilationLigneSousCategorie = '';
        $this->ventilationLigneMontant = '';
        $this->affectations = [];
        $this->ventilationHasAffectations = false;
    }

    public function addAffectation(): void
    {
        $this->affectations[] = ['operation_id' => '', 'seance' => '', 'montant' => '', 'notes' => ''];
    }

    public function removeAffectation(int $index): void
    {
        if ($this->ventilationLigneId === null) {
            return;
        }
        if (! isset($this->affectations[$index])) {
            return;
        }
        array_splice($this->affectations, $index, 1);
    }

    public function saveVentilation(): void
    {
        $allowedIds = collect($this->lignes)->pluck('id')->filter()->map(fn ($id) => (int) $id)->toArray();
        if ($this->ventilationLigneId === null || ! in_array($this->ventilationLigneId, $allowedIds, true)) {
            abort(403);
        }

        $this->validate([
            'affectations' => ['required', 'array', 'min:1'],
            'affectations.*.montant' => ['required', 'numeric', 'min:0.01'],
            'affectations.*.operation_id' => ['nullable'],
            'affectations.*.seance' => ['nullable', 'integer', 'min:1'],
            'affectations.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $ligne = TransactionLigne::findOrFail($this->ventilationLigneId);
        $ligneMontantCents = (int) round((float) $ligne->montant * 100);
        $affectationCents = (int) round(collect($this->affectations)->sum(fn ($a) => (float) ($a['montant'] ?? 0)) * 100);
        if ($ligneMontantCents !== $affectationCents) {
            $this->addError('affectations', 'La somme des affectations doit être égale au montant de la ligne.');

            return;
        }

        app(TransactionService::class)->affecterLigne(
            $ligne,
            collect($this->affectations)->map(fn ($a) => [
                'operation_id' => $a['operation_id'] !== '' ? (int) $a['operation_id'] : null,
                'seance' => $a['seance'] !== '' ? (int) $a['seance'] : null,
                'montant' => $a['montant'],
                'notes' => $a['notes'] ?: null,
            ])->toArray()
        );

        $this->fermerVentilation();
        $this->dispatch('transaction-saved');
    }

    public function supprimerVentilation(): void
    {
        $allowedIds = collect($this->lignes)->pluck('id')->filter()->map(fn ($id) => (int) $id)->toArray();
        if ($this->ventilationLigneId === null || ! in_array($this->ventilationLigneId, $allowedIds, true)) {
            abort(403);
        }

        $ligne = TransactionLigne::findOrFail($this->ventilationLigneId);
        app(TransactionService::class)->supprimerAffectations($ligne);
        $this->fermerVentilation();
        $this->dispatch('transaction-saved');
    }

    #[On('edit-transaction')]
    public function edit(int $id): void
    {
        $this->ventilationLigneId = null;
        $this->ventilationLigneSousCategorie = '';
        $this->ventilationLigneMontant = '';
        $this->affectations = [];
        $this->ventilationHasAffectations = false;

        $transaction = Transaction::with('lignes')->findOrFail($id);

        $this->transactionId = $transaction->id;
        $this->type = $transaction->type->value;
        $this->date = $transaction->date->format('Y-m-d');
        $this->libelle = $transaction->libelle;
        $this->mode_paiement = $transaction->mode_paiement->value;
        $this->tiers_id = $transaction->tiers_id;
        $this->reference = $transaction->reference;
        $this->compte_id = $transaction->compte_id;
        $this->notes = $transaction->notes;

        $this->lignes = $transaction->lignes->map(fn ($ligne) => [
            'id' => $ligne->id,
            'sous_categorie_id' => (string) $ligne->sous_categorie_id,
            'operation_id' => (string) ($ligne->operation_id ?? ''),
            'seance' => (string) ($ligne->seance ?? ''),
            'montant' => (string) $ligne->montant,
            'notes' => (string) ($ligne->notes ?? ''),
        ])->toArray();

        $this->isLocked = $transaction->isLockedByRapprochement();
        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->reset([
            'transactionId', 'type', 'date', 'libelle', 'mode_paiement',
            'tiers_id', 'reference', 'compte_id', 'notes', 'lignes', 'showForm', 'isLocked',
            'ventilationLigneId', 'ventilationLigneSousCategorie', 'ventilationLigneMontant', 'affectations',
            'ventilationHasAffectations',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($exerciceService->current());
        $dateDebut = $range['start']->toDateString();
        $dateFin = $range['end']->toDateString();

        $isLocked = $this->transactionId
            ? Transaction::findOrFail($this->transactionId)->loadMissing('rapprochement')->isLockedByRapprochement()
            : false;

        $this->validate(
            [
                'date' => $isLocked
                    ? ['required', 'date']
                    : ['required', 'date', 'after_or_equal:'.$dateDebut, 'before_or_equal:'.$dateFin],
                'libelle' => ['nullable', 'string', 'max:255'],
                'reference' => ['required', 'string', 'max:100'],
                'mode_paiement' => ['required', 'in:virement,cheque,especes,cb,prelevement'],
                'tiers_id' => ['nullable', 'exists:tiers,id'],
                'compte_id' => ['nullable', 'exists:comptes_bancaires,id'],
                'lignes' => ['required', 'array', 'min:1'],
                'lignes.*.sous_categorie_id' => ['required', 'exists:sous_categories,id'],
                'lignes.*.montant' => ['required', 'numeric', 'min:0.01'],
                'lignes.*.operation_id' => ['nullable'],
                'lignes.*.seance' => ['nullable', 'integer', 'min:1'],
                'lignes.*.notes' => ['nullable', 'string', 'max:255'],
            ],
            [
                'date.after_or_equal' => 'La date doit être dans l\'exercice en cours (à partir du '.$range['start']->format('d/m/Y').').',
                'date.before_or_equal' => 'La date doit être dans l\'exercice en cours (jusqu\'au '.$range['end']->format('d/m/Y').').',
            ]
        );

        $data = [
            'type' => $this->type,
            'date' => $this->date,
            'libelle' => $this->libelle,
            'montant_total' => $this->montantTotal,
            'mode_paiement' => $this->mode_paiement,
            'tiers_id' => $this->tiers_id,
            'reference' => $this->reference,
            'compte_id' => $this->compte_id,
            'notes' => $this->notes ?: null,
        ];

        $lignes = collect($this->lignes)->map(fn ($l) => [
            'id' => isset($l['id']) ? (int) $l['id'] : null,
            'sous_categorie_id' => (int) $l['sous_categorie_id'],
            'operation_id' => $l['operation_id'] !== '' ? (int) $l['operation_id'] : null,
            'seance' => $l['seance'] !== '' ? (int) $l['seance'] : null,
            'montant' => $l['montant'],
            'notes' => $l['notes'] ?: null,
        ])->toArray();

        $service = app(TransactionService::class);

        if ($this->transactionId) {
            $transaction = Transaction::findOrFail($this->transactionId);
            $service->update($transaction, $data, $lignes);
        } else {
            $service->create($data, $lignes);
        }

        $this->dispatch('transaction-saved');
        $this->resetForm();
    }

    public function render(): View
    {
        $sousCategories = SousCategorie::with('categorie')
            ->when($this->type !== '', fn ($q) => $q->whereHas('categorie', fn ($q2) => $q2->where('type', $this->type)))
            ->orderBy('nom')
            ->get();

        return view('livewire.transaction-form', [
            'comptes' => CompteBancaire::where('actif_recettes_depenses', true)->orderBy('nom')->get(),
            'sousCategories' => $sousCategories,
            'operations' => Operation::where('statut', StatutOperation::EnCours)->orderBy('nom')->get(),
            'modesPaiement' => ModePaiement::cases(),
            'transaction_numero_piece' => $this->transactionId
                ? Transaction::select('id', 'numero_piece')->find($this->transactionId)?->numero_piece
                : null,
            'lignesAffectations' => $this->transactionId
                ? TransactionLigneAffectation::whereIn(
                    'transaction_ligne_id',
                    collect($this->lignes)->pluck('id')->filter()->toArray()
                )->pluck('transaction_ligne_id')->unique()->toArray()
                : [],
        ]);
    }
}
