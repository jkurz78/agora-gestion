<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Enums\StatutOperation;
use App\Enums\TypeCategorie;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\RecetteLigneAffectation;
use App\Models\SousCategorie;
use App\Services\ExerciceService;
use App\Services\RecetteService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class RecetteForm extends Component
{
    public ?int $recetteId = null;

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
        $this->reset(['recetteId', 'date', 'libelle', 'mode_paiement',
            'tiers_id', 'reference', 'compte_id', 'notes', 'lignes']);
        $this->isLocked = false;
        $this->resetValidation();

        $this->showForm = true;
        $this->date = app(ExerciceService::class)->defaultDate();

        $this->compte_id = Recette::where('saisi_par', auth()->id())
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
        $ligne = RecetteLigne::with('affectations')->findOrFail($ligneId);
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
        $this->validate([
            'affectations'                  => ['required', 'array', 'min:1'],
            'affectations.*.montant'        => ['required', 'numeric', 'min:0.01'],
            'affectations.*.operation_id'   => ['nullable'],
            'affectations.*.seance'         => ['nullable', 'integer', 'min:1'],
            'affectations.*.notes'          => ['nullable', 'string', 'max:255'],
        ]);

        $ligne = RecetteLigne::findOrFail($this->ventilationLigneId);

        app(RecetteService::class)->affecterLigne(
            $ligne,
            collect($this->affectations)->map(fn ($a) => [
                'operation_id' => $a['operation_id'] !== '' ? (int) $a['operation_id'] : null,
                'seance'       => $a['seance'] !== '' ? (int) $a['seance'] : null,
                'montant'      => $a['montant'],
                'notes'        => $a['notes'] ?: null,
            ])->toArray()
        );

        $this->fermerVentilation();
        $this->dispatch('recette-saved');
    }

    public function supprimerVentilation(): void
    {
        $ligne = RecetteLigne::findOrFail($this->ventilationLigneId);
        app(RecetteService::class)->supprimerAffectations($ligne);
        $this->fermerVentilation();
        $this->dispatch('recette-saved');
    }

    #[On('edit-recette')]
    public function edit(int $id): void
    {
        $recette = Recette::with('lignes')->findOrFail($id);

        $this->recetteId = $recette->id;
        $this->date = $recette->date->format('Y-m-d');
        $this->libelle = $recette->libelle;
        $this->mode_paiement = $recette->mode_paiement->value;
        $this->tiers_id = $recette->tiers_id;
        $this->reference = $recette->reference;
        $this->compte_id = $recette->compte_id;
        $this->notes = $recette->notes;

        $this->lignes = $recette->lignes->map(fn ($ligne) => [
            'id'               => $ligne->id,
            'sous_categorie_id' => (string) $ligne->sous_categorie_id,
            'operation_id'     => (string) ($ligne->operation_id ?? ''),
            'seance'           => (string) ($ligne->seance ?? ''),
            'montant'          => (string) $ligne->montant,
            'notes'            => (string) ($ligne->notes ?? ''),
        ])->toArray();

        $this->isLocked = $recette->isLockedByRapprochement();
        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->reset([
            'recetteId', 'date', 'libelle', 'mode_paiement',
            'tiers_id', 'reference', 'compte_id', 'notes', 'lignes', 'showForm', 'isLocked',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($exerciceService->current());
        $dateDebut = $range['start']->toDateString();
        $dateFin = $range['end']->toDateString();

        $isLocked = $this->recetteId
            ? Recette::findOrFail($this->recetteId)->loadMissing('rapprochement')->isLockedByRapprochement()
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

    public function render(): View
    {
        $sousCategories = SousCategorie::with('categorie')
            ->whereHas('categorie', fn ($q) => $q->where('type', TypeCategorie::Recette))
            ->orderBy('nom')
            ->get();

        return view('livewire.recette-form', [
            'comptes' => CompteBancaire::where('actif_recettes_depenses', true)->orderBy('nom')->get(),
            'sousCategories' => $sousCategories,
            'operations' => Operation::where('statut', StatutOperation::EnCours)->orderBy('nom')->get(),
            'modesPaiement' => ModePaiement::cases(),
            'recette_numero_piece' => $this->recetteId
                ? Recette::select('id', 'numero_piece')->find($this->recetteId)?->numero_piece
                : null,
            'lignesAffectations'  => $this->recetteId
                ? RecetteLigneAffectation::whereIn(
                    'recette_ligne_id',
                    collect($this->lignes)->pluck('id')->filter()->toArray()
                )->pluck('recette_ligne_id')->unique()->toArray()
                : [],
        ]);
    }
}
