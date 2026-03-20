<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Livewire\Concerns\WithPerPage;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Don;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\CotisationService;
use App\Services\DonService;
use App\Services\ExerciceService;
use App\Services\TransactionService;
use App\Services\TransactionUniverselleService;
use App\Services\VirementInterneService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

final class TransactionUniverselle extends Component
{
    use WithPagination, WithPerPage;

    protected string $paginationTheme = 'bootstrap';

    // === Props verrouillées (injectées via mount depuis la page) ===
    public ?int $compteId = null;   // compte fixe (vue par compte)

    public ?int $tiersId = null;   // tiers fixe (vue par tiers)

    /** @var array<string>|null */
    public ?array $lockedTypes = null; // types autorisés (null = tous)

    public ?int $exercice = null;  // exercice fixe (null = courant)

    // === Filtres libres (manipulables par l'utilisateur) ===
    /** @var array<string> */
    public array $filterTypes = []; // [] = "Toutes"

    public string $filterDateDebut = '';

    public string $filterDateFin = '';

    public string $filterTiers = '';

    public string $filterReference = '';

    public string $filterLibelle = '';

    public string $filterNumeroPiece = '';

    public string $filterModePaiement = '';

    public string $filterPointe = ''; // '' | '1' | '0'

    public ?int $filterCompteId = null; // libre seulement si compteId prop est null

    // === Expansion de lignes ===
    /** @var array<string, mixed> */
    public array $expandedDetails = []; // clé: "source_type:id"

    // === Tri ===
    public string $sortColumn = 'date';

    public string $sortDirection = 'desc';

    public function mount(
        ?int $compteId = null,
        ?int $tiersId = null,
        ?array $lockedTypes = null,
        ?int $exercice = null,
    ): void {
        $this->compteId = $compteId;
        $this->tiersId = $tiersId;
        $this->lockedTypes = $lockedTypes;
        $this->exercice = $exercice;

        // Initialiser plage dates sur l'exercice courant
        $exerciceService = app(ExerciceService::class);
        $ex = $exercice ?? $exerciceService->current();
        $range = $exerciceService->dateRange($ex);
        $this->filterDateDebut = $range['start']->toDateString();
        $this->filterDateFin = $range['end']->toDateString();
    }

    // Presets date
    public function applyDatePreset(string $preset): void
    {
        $exerciceService = app(ExerciceService::class);
        $now = now();
        match ($preset) {
            'exercice' => (function () use ($exerciceService) {
                $ex = $this->exercice ?? $exerciceService->current();
                $range = $exerciceService->dateRange($ex);
                $this->filterDateDebut = $range['start']->toDateString();
                $this->filterDateFin = $range['end']->toDateString();
            })(),
            'mois' => (function () use ($now) {
                $this->filterDateDebut = $now->copy()->startOfMonth()->toDateString();
                $this->filterDateFin = $now->copy()->endOfMonth()->toDateString();
            })(),
            'trimestre' => (function () use ($now) {
                $this->filterDateDebut = $now->copy()->startOfQuarter()->toDateString();
                $this->filterDateFin = $now->copy()->endOfQuarter()->toDateString();
            })(),
            'all' => (function () {
                $this->filterDateDebut = '';
                $this->filterDateFin = '';
            })(),
            default => null,
        };
        $this->resetPage();
    }

    // Toggle d'un type dans filterTypes (boutons au-dessus du tableau)
    public function toggleType(string $type): void
    {
        if (in_array($type, $this->filterTypes)) {
            $this->filterTypes = array_values(array_filter($this->filterTypes, fn ($t) => $t !== $type));
        } else {
            $this->filterTypes[] = $type;
            $allTypes = $this->lockedTypes ?? ['depense', 'recette', 'don', 'cotisation', 'virement'];
            if (! array_diff($allTypes, $this->filterTypes)) {
                $this->filterTypes = [];
            }
        }
        $this->resetPage();
    }

    // updatedX → resetPage
    public function updatedFilterTypes(): void
    {
        $this->resetPage();
    }

    public function updatedFilterDateDebut(): void
    {
        $this->resetPage();
    }

    public function updatedFilterDateFin(): void
    {
        $this->resetPage();
    }

    public function updatedFilterTiers(): void
    {
        $this->resetPage();
    }

    public function updatedFilterReference(): void
    {
        $this->resetPage();
    }

    public function updatedFilterLibelle(): void
    {
        $this->resetPage();
    }

    public function updatedFilterNumeroPiece(): void
    {
        $this->resetPage();
    }

    public function updatedFilterModePaiement(): void
    {
        $this->resetPage();
    }

    public function updatedFilterPointe(): void
    {
        $this->resetPage();
    }

    public function updatedFilterCompteId(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        $allowed = ['id', 'date', 'numero_piece', 'reference', 'tiers', 'libelle',
            'categorie_label', 'nb_lignes', 'compte_id', 'compte_nom', 'mode_paiement',
            'montant', 'pointe', 'source_type'];
        if (! in_array($column, $allowed, true)) {
            return;
        }
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    public function openEdit(string $sourceType, int $id): void
    {
        $allowed = ['depense', 'recette', 'don', 'cotisation', 'virement_sortant', 'virement_entrant'];
        if (! in_array($sourceType, $allowed, true)) {
            return;
        }
        match ($sourceType) {
            'depense', 'recette' => $this->dispatch('open-transaction-form', type: $sourceType, id: $id),
            'don' => $this->dispatch('open-don-form', id: $id),
            'cotisation' => $this->dispatch('open-cotisation-form', id: $id),
            'virement_sortant', 'virement_entrant' => $this->dispatch('open-virement-form', id: $id),
        };
    }

    // Expansion de ligne
    public function toggleDetail(string $sourceType, int $id): void
    {
        $key = "{$sourceType}:{$id}";
        if (isset($this->expandedDetails[$key])) {
            unset($this->expandedDetails[$key]);
        } else {
            $this->expandedDetails[$key] = $this->fetchDetail($sourceType, $id);
        }
    }

    private function fetchDetail(string $sourceType, int $id): array
    {
        return match ($sourceType) {
            'depense', 'recette' => $this->fetchTransactionDetail($id),
            'don' => $this->fetchDonDetail($id),
            'cotisation' => $this->fetchCotisationDetail($id),
            'virement_sortant', 'virement_entrant' => [],
            default => [],
        };
    }

    private function fetchTransactionDetail(int $id): array
    {
        $tx = Transaction::with(['lignes.sousCategorie.categorie', 'lignes.operation'])->find($id);
        if (! $tx) {
            return [];
        }

        return [
            'lignes' => $tx->lignes->map(fn ($l) => [
                'sous_categorie' => $l->sousCategorie?->nom,
                'operation' => $l->operation?->nom,
                'seance' => $l->seance,
                'montant' => (float) $l->montant,
                'notes' => $l->notes,
            ])->toArray(),
        ];
    }

    private function fetchDonDetail(int $id): array
    {
        $don = Don::with(['sousCategorie', 'operation'])->find($id);
        if (! $don) {
            return [];
        }

        return [
            'sous_categorie' => $don->sousCategorie?->nom,
            'operation' => $don->operation?->nom,
            'seance' => $don->seance,
            'objet' => $don->objet,
        ];
    }

    private function fetchCotisationDetail(int $id): array
    {
        $cot = Cotisation::with('sousCategorie')->find($id);
        if (! $cot) {
            return [];
        }

        return [
            'sous_categorie' => $cot->sousCategorie?->nom,
            'exercice' => $cot->exercice,
        ];
    }

    // Suppression
    public function deleteRow(string $sourceType, int $id): void
    {
        $allowed = ['depense', 'recette', 'don', 'cotisation', 'virement_sortant', 'virement_entrant'];
        if (! in_array($sourceType, $allowed, true)) {
            return;
        }
        match ($sourceType) {
            'depense', 'recette' => $this->deleteTransaction($id),
            'don' => $this->deleteDon($id),
            'cotisation' => $this->deleteCotisation($id),
            'virement_sortant', 'virement_entrant' => $this->deleteVirement($id),
            default => null,
        };
    }

    private function deleteTransaction(int $id): void
    {
        $tx = Transaction::find($id);
        if (! $tx || $tx->pointe || $tx->isLockedByRapprochement()) {
            return;
        }
        try {
            app(TransactionService::class)->delete($tx);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    private function deleteDon(int $id): void
    {
        $don = Don::find($id);
        if (! $don || $don->isLockedByRapprochement()) {
            return;
        }
        app(DonService::class)->delete($don);
    }

    private function deleteCotisation(int $id): void
    {
        $cot = Cotisation::find($id);
        if (! $cot || $cot->isLockedByRapprochement()) {
            return;
        }
        app(CotisationService::class)->delete($cot);
    }

    private function deleteVirement(int $id): void
    {
        $v = VirementInterne::find($id);
        if (! $v || $v->isLockedByRapprochement()) {
            return;
        }
        app(VirementInterneService::class)->delete($v);
    }

    // Écouter les événements des modaux pour rafraîchir la liste
    #[On('transaction-saved')]
    public function onTransactionSaved(): void {}

    #[On('don-saved')]
    public function onDonSaved(): void {}

    #[On('cotisation-saved')]
    public function onCotisationSaved(): void {}

    #[On('virement-saved')]
    public function onVirementSaved(): void {}

    public function render(): View
    {
        $activeCompteId = $this->compteId ?? $this->filterCompteId;

        // Déterminer les types effectivement inclus
        $typesScope = $this->lockedTypes; // null = tous, ou subset limité
        $typesFilter = empty($this->filterTypes) ? null : $this->filterTypes;

        // Intersection des types scope et filter
        $effectiveTypes = null;
        if ($typesScope !== null && $typesFilter !== null) {
            $effectiveTypes = array_values(array_intersect($typesScope, $typesFilter));
        } elseif ($typesScope !== null) {
            $effectiveTypes = $typesScope;
        } elseif ($typesFilter !== null) {
            $effectiveTypes = $typesFilter;
        }

        // showSolde : compte unique + tous types scope + aucun filtre hors dates
        $showSolde = $activeCompteId !== null
            && empty($this->filterTypes)
            && $this->filterTiers === '' && $this->filterReference === ''
            && $this->filterLibelle === '' && $this->filterNumeroPiece === ''
            && $this->filterModePaiement === '' && $this->filterPointe === '';

        $sortDirection = ($showSolde && $this->sortColumn === 'date')
            ? 'asc'
            : $this->sortDirection;

        $result = app(TransactionUniverselleService::class)->paginate(
            compteId: $activeCompteId,
            tiersId: $this->tiersId,
            types: $effectiveTypes,
            dateDebut: $this->filterDateDebut ?: null,
            dateFin: $this->filterDateFin ?: null,
            searchTiers: $this->filterTiers ?: null,
            searchLibelle: $this->filterLibelle ?: null,
            searchReference: $this->filterReference ?: null,
            searchNumeroPiece: $this->filterNumeroPiece ?: null,
            modePaiement: $this->filterModePaiement ?: null,
            pointe: $this->filterPointe !== '' ? ($this->filterPointe === '1') : null,
            computeSolde: $showSolde,
            sortColumn: $this->sortColumn,
            sortDirection: $sortDirection,
            perPage: $this->effectivePerPage(),
            page: $this->getPage(),
        );

        $rows = collect($result['paginator']->items());
        if ($showSolde && $result['soldeAvantPage'] !== null) {
            $solde = $result['soldeAvantPage'];
            foreach ($rows as $row) {
                $solde += (float) $row->montant;
                $row->solde_courant = $solde;
            }
        }

        return view('livewire.transaction-universelle', [
            'rows' => $rows,
            'paginator' => $result['paginator'],
            'showSolde' => $showSolde,
            'comptes' => $this->compteId === null ? CompteBancaire::orderBy('nom')->get() : collect(),
            'modesPaiement' => ModePaiement::cases(),
            'availableTypes' => $this->lockedTypes ?? ['depense', 'recette', 'don', 'cotisation', 'virement'],
            'showCompteCol' => $this->compteId === null,
            'showTiersCol' => $this->tiersId === null,
        ]);
    }
}
