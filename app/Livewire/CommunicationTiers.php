<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Models\Association;
use App\Models\CampagneEmail;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Services\ExerciceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class CommunicationTiers extends Component
{
    // ── Filters ──────────────────────────────────────────────────────────────

    public string $search = '';

    /** @var 'et'|'ou' */
    public string $modeFiltres = 'et';

    /** @var null|'exercice'|'tous' */
    public ?string $filtreDonateurs = null;

    /** @var null|'exercice'|'tous' */
    public ?string $filtreAdherents = null;

    public bool $filtreFournisseurs = false;

    public bool $filtreClients = false;

    /** @var array<int> */
    public array $filtreTypeOperationIds = [];

    /** @var null|'exercice'|'tous' */
    public ?string $filtreParticipantsScope = null;

    // ── Selection ────────────────────────────────────────────────────────────

    public bool $selectAll = false;

    /** @var array<int> */
    public array $selectedTiersIds = [];

    // ── Composition (stubs — implemented in Task 7) ───────────────────────

    public string $objet = '';

    public string $corps = '';

    // ─────────────────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->role->canWrite(Espace::Gestion)) {
            abort(403);
        }
    }

    /**
     * Build the filtered Tiers query.
     *
     * @return Builder<Tiers>
     */
    private function buildQuery(): Builder
    {
        $query = Tiers::query();

        // Text search: always AND, any of nom/prenom/entreprise/email
        if ($this->search !== '') {
            $s = $this->search;
            $query->where(function (Builder $q) use ($s): void {
                $q->where('nom', 'like', "%{$s}%")
                    ->orWhere('prenom', 'like', "%{$s}%")
                    ->orWhere('entreprise', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%");
            });
        }

        $activeFilters = $this->collectActiveFilters();

        if (empty($activeFilters)) {
            return $query;
        }

        if ($this->modeFiltres === 'ou') {
            // OR: tiers matching at least one filter
            $query->where(function (Builder $q) use ($activeFilters): void {
                foreach ($activeFilters as $filter) {
                    $q->orWhere(function (Builder $inner) use ($filter): void {
                        $filter($inner);
                    });
                }
            });
        } else {
            // AND (default): tiers matching all active filters
            foreach ($activeFilters as $filter) {
                $filter($query);
            }
        }

        return $query;
    }

    /**
     * Returns an array of closures, one per active role/type filter.
     * Each closure applies its constraint to a Builder<Tiers>.
     *
     * @return array<int, callable(Builder<Tiers>): void>
     */
    private function collectActiveFilters(): array
    {
        $filters = [];
        $exercice = app(ExerciceService::class)->current();

        if ($this->filtreFournisseurs) {
            $filters[] = fn (Builder $q) => $q->where('pour_depenses', true);
        }

        if ($this->filtreClients) {
            $filters[] = fn (Builder $q) => $q->where('pour_recettes', true);
        }

        if ($this->filtreDonateurs !== null) {
            $donSousCategorieIds = SousCategorie::where('pour_dons', true)->pluck('id');
            $ex = $this->filtreDonateurs === 'exercice' ? $exercice : null;

            $filters[] = function (Builder $q) use ($donSousCategorieIds, $ex): void {
                $q->whereHas('transactions', function (Builder $tq) use ($donSousCategorieIds, $ex): void {
                    $tq->where('type', 'recette');
                    if ($ex !== null) {
                        $tq->forExercice($ex);
                    }
                    $tq->whereHas('lignes', function (Builder $lq) use ($donSousCategorieIds): void {
                        $lq->whereIn('sous_categorie_id', $donSousCategorieIds);
                    });
                });
            };
        }

        if ($this->filtreAdherents !== null) {
            $cotSousCategorieIds = SousCategorie::where('pour_cotisations', true)->pluck('id');
            $ex = $this->filtreAdherents === 'exercice' ? $exercice : null;

            $filters[] = function (Builder $q) use ($cotSousCategorieIds, $ex): void {
                $q->whereHas('transactions', function (Builder $tq) use ($cotSousCategorieIds, $ex): void {
                    $tq->where('type', 'recette');
                    if ($ex !== null) {
                        $tq->forExercice($ex);
                    }
                    $tq->whereHas('lignes', function (Builder $lq) use ($cotSousCategorieIds): void {
                        $lq->whereIn('sous_categorie_id', $cotSousCategorieIds);
                    });
                });
            };
        }

        if ($this->filtreParticipantsScope !== null && ! empty($this->filtreTypeOperationIds)) {
            $typeOpIds = $this->filtreTypeOperationIds;
            $ex = $this->filtreParticipantsScope === 'exercice' ? $exercice : null;

            $filters[] = function (Builder $q) use ($typeOpIds, $ex): void {
                $q->whereHas('participants', function (Builder $pq) use ($typeOpIds, $ex): void {
                    $pq->whereHas('operation', function (Builder $oq) use ($typeOpIds, $ex): void {
                        $oq->whereIn('type_operation_id', $typeOpIds);
                        if ($ex !== null) {
                            $oq->forExercice($ex);
                        }
                    });
                });
            };
        } elseif ($this->filtreParticipantsScope !== null) {
            // Scope set but no type filter: match any participant
            $ex = $this->filtreParticipantsScope === 'exercice' ? $exercice : null;

            $filters[] = function (Builder $q) use ($ex): void {
                $q->whereHas('participants', function (Builder $pq) use ($ex): void {
                    $pq->whereHas('operation', function (Builder $oq) use ($ex): void {
                        if ($ex !== null) {
                            $oq->forExercice($ex);
                        }
                    });
                });
            };
        }

        return $filters;
    }

    /**
     * Toggle select all: selects all filtered tiers that have an email and are not opted out.
     */
    public function toggleSelectAll(): void
    {
        if ($this->selectAll) {
            $this->selectAll = false;
            $this->selectedTiersIds = [];

            return;
        }

        $ids = $this->buildQuery()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('email_optout', false)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $this->selectAll = true;
        $this->selectedTiersIds = $ids;
    }

    public function render(): View
    {
        $tiersList = $this->buildQuery()
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get();

        $emailCount = $tiersList
            ->filter(fn (Tiers $t) => ! empty($t->getRawOriginal('email')) && ! $t->email_optout)
            ->count();

        $emailFrom = Association::find(1)?->email_from ?? '';

        $typesOperation = TypeOperation::actif()->orderBy('nom')->get();

        $campagnes = CampagneEmail::whereNull('operation_id')
            ->with('envoyePar')
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.communication-tiers', compact(
            'tiersList',
            'emailCount',
            'emailFrom',
            'typesOperation',
            'campagnes',
        ));
    }
}
