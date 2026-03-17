<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Enums\StatutOperation;
use App\Enums\TypeCategorie;
use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\DepenseLigneAffectation;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Services\DepenseService;
use App\Services\ExerciceService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class DepenseForm extends Component
{
    public ?int $depenseId = null;

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

    /** @var array<int, array{operation_id: string, seance: string, montant: string, notes: string}> */
    public array $affectations = [];

    public function getMontantTotalProperty(): float
    {
        return round(collect($this->lignes)->sum(fn ($l) => (float) ($l['montant'] ?? 0)), 2);
    }

    public function showNewForm(): void
    {
        $this->reset(['depenseId', 'date', 'libelle', 'mode_paiement',
            'tiers_id', 'reference', 'compte_id', 'notes', 'lignes',
            'ventilationLigneId', 'affectations']);
        $this->isLocked = false;
        $this->resetValidation();

        $this->showForm = true;
        $this->date = app(ExerciceService::class)->defaultDate();

        $this->compte_id = Depense::where('saisi_par', auth()->id())
            ->whereNotNull('compte_id')
            ->latest()
            ->value('compte_id');

        $this->addLigne();
    }

    public function addLigne(): void
    {
        $this->lignes[] = [
            'id'               => null,
            'sous_categorie_id' => '',
            'operation_id'     => '',
            'seance'           => '',
            'montant'          => '',
            'notes'            => '',
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

        $ligne = DepenseLigne::with('affectations')->findOrFail($ligneId);
        $this->ventilationLigneId = $ligneId;

        if ($ligne->affectations->isEmpty()) {
            $this->affectations = [[
                'operation_id' => (string) ($ligne->operation_id ?? ''),
                'seance'       => (string) ($ligne->seance ?? ''),
                'montant'      => (string) $ligne->montant,
                'notes'        => (string) ($ligne->notes ?? ''),
            ]];
        } else {
            $this->affectations = $ligne->affectations->map(fn ($a) => [
                'operation_id' => (string) ($a->operation_id ?? ''),
                'seance'       => (string) ($a->seance ?? ''),
                'montant'      => (string) $a->montant,
                'notes'        => (string) ($a->notes ?? ''),
            ])->toArray();
        }
    }

    public function fermerVentilation(): void
    {
        $this->ventilationLigneId = null;
        $this->affectations = [];
    }

    public function addAffectation(): void
    {
        $this->affectations[] = ['operation_id' => '', 'seance' => '', 'montant' => '', 'notes' => ''];
    }

    public function removeAffectation(int $index): void
    {
        array_splice($this->affectations, $index, 1);
    }

    public function saveVentilation(): void
    {
        $allowedIds = collect($this->lignes)->pluck('id')->filter()->map(fn ($id) => (int) $id)->toArray();
        if ($this->ventilationLigneId === null || ! in_array($this->ventilationLigneId, $allowedIds, true)) {
            abort(403);
        }

        $this->validate([
            'affectations'                  => ['required', 'array', 'min:1'],
            'affectations.*.montant'        => ['required', 'numeric', 'min:0.01'],
            'affectations.*.operation_id'   => ['nullable'],
            'affectations.*.seance'         => ['nullable', 'integer', 'min:1'],
            'affectations.*.notes'          => ['nullable', 'string', 'max:255'],
        ]);

        $ligneMontantCents = (int) round((float) DepenseLigne::findOrFail($this->ventilationLigneId)->montant * 100);
        $affectationCents  = (int) round(collect($this->affectations)->sum(fn ($a) => (float) ($a['montant'] ?? 0)) * 100);
        if ($ligneMontantCents !== $affectationCents) {
            $this->addError('affectations', 'La somme des affectations doit être égale au montant de la ligne.');
            return;
        }

        $ligne = DepenseLigne::findOrFail($this->ventilationLigneId);

        app(DepenseService::class)->affecterLigne(
            $ligne,
            collect($this->affectations)->map(fn ($a) => [
                'operation_id' => $a['operation_id'] !== '' ? (int) $a['operation_id'] : null,
                'seance'       => $a['seance'] !== '' ? (int) $a['seance'] : null,
                'montant'      => $a['montant'],
                'notes'        => $a['notes'] ?: null,
            ])->toArray()
        );

        $this->fermerVentilation();
        $this->dispatch('depense-saved');
    }

    public function supprimerVentilation(): void
    {
        $allowedIds = collect($this->lignes)->pluck('id')->filter()->map(fn ($id) => (int) $id)->toArray();
        if ($this->ventilationLigneId === null || ! in_array($this->ventilationLigneId, $allowedIds, true)) {
            abort(403);
        }

        $ligne = DepenseLigne::findOrFail($this->ventilationLigneId);
        app(DepenseService::class)->supprimerAffectations($ligne);
        $this->fermerVentilation();
        $this->dispatch('depense-saved');
    }

    #[On('edit-depense')]
    public function edit(int $id): void
    {
        $depense = Depense::with('lignes')->findOrFail($id);

        $this->depenseId = $depense->id;
        $this->date = $depense->date->format('Y-m-d');
        $this->libelle = $depense->libelle;
        $this->mode_paiement = $depense->mode_paiement->value;
        $this->tiers_id = $depense->tiers_id;
        $this->reference = $depense->reference;
        $this->compte_id = $depense->compte_id;
        $this->notes = $depense->notes;

        $this->lignes = $depense->lignes->map(fn ($ligne) => [
            'id'               => $ligne->id,
            'sous_categorie_id' => (string) $ligne->sous_categorie_id,
            'operation_id'     => (string) ($ligne->operation_id ?? ''),
            'seance'           => (string) ($ligne->seance ?? ''),
            'montant'          => (string) $ligne->montant,
            'notes'            => (string) ($ligne->notes ?? ''),
        ])->toArray();

        $this->isLocked = $depense->isLockedByRapprochement();
        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->reset([
            'depenseId', 'date', 'libelle', 'mode_paiement',
            'tiers_id', 'reference', 'compte_id', 'notes', 'lignes', 'showForm', 'isLocked',
            'ventilationLigneId', 'affectations',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($exerciceService->current());
        $dateDebut = $range['start']->toDateString();
        $dateFin = $range['end']->toDateString();

        $isLocked = $this->depenseId
            ? Depense::findOrFail($this->depenseId)->loadMissing('rapprochement')->isLockedByRapprochement()
            : false;

        $this->validate(
            [
                'date' => $isLocked
                    ? ['required', 'date']
                    : ['required', 'date', 'after_or_equal:'.$dateDebut, 'before_or_equal:'.$dateFin],
                'libelle'   => ['nullable', 'string', 'max:255'],
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
            'id'               => isset($l['id']) ? (int) $l['id'] : null,
            'sous_categorie_id' => (int) $l['sous_categorie_id'],
            'operation_id'     => $l['operation_id'] !== '' ? (int) $l['operation_id'] : null,
            'seance'           => $l['seance'] !== '' ? (int) $l['seance'] : null,
            'montant'          => $l['montant'],
            'notes'            => $l['notes'] ?: null,
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

    public function render(): View
    {
        $sousCategories = SousCategorie::with('categorie')
            ->whereHas('categorie', fn ($q) => $q->where('type', TypeCategorie::Depense))
            ->orderBy('nom')
            ->get();

        return view('livewire.depense-form', [
            'comptes' => CompteBancaire::where('actif_recettes_depenses', true)->orderBy('nom')->get(),
            'sousCategories' => $sousCategories,
            'operations' => Operation::where('statut', StatutOperation::EnCours)->orderBy('nom')->get(),
            'modesPaiement' => ModePaiement::cases(),
            'depense_numero_piece' => $this->depenseId
                ? Depense::select('id', 'numero_piece')->find($this->depenseId)?->numero_piece
                : null,
            'lignesAffectations' => $this->depenseId
                ? DepenseLigneAffectation::whereIn(
                    'depense_ligne_id',
                    collect($this->lignes)->pluck('id')->filter()->toArray()
                )->pluck('depense_ligne_id')->unique()->toArray()
                : [],
            'ligneSrcVentilation' => $this->ventilationLigneId
                ? DepenseLigne::with('sousCategorie')->find($this->ventilationLigneId)
                : null,
        ]);
    }
}
