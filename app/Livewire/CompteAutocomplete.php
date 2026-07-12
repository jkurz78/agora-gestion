<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\Famille;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Modelable;
use Livewire\Component;

final class CompteAutocomplete extends Component
{
    #[Modelable]
    public int|string|null $compteId = null;

    public string $filtre = 'tous'; // 'depense' | 'recette' | 'tous'

    public ?string $usageFlag = null; // 'pour_dons' | 'pour_cotisations' | 'pour_inscriptions' — external string API, converted internally to UsageComptable

    public string $search = '';

    public bool $open = false;

    public ?string $selectedLabel = null;

    public ?string $selectedFamilleLabel = null;

    public bool $showCreateModal = false;

    public string $newIntitule = '';

    public string $newNumeroPcg = '';

    /**
     * @var array<int, array{famille_code: string, famille_label: string, items: array<int, array{id: int, numero_pcg: string, intitule: string}>}>
     */
    public array $results = [];

    public function mount(): void
    {
        // Normalise: empty string from lignes array → null
        $id = ($this->compteId !== '' && $this->compteId !== null)
            ? (int) $this->compteId
            : null;
        $this->compteId = $id;

        if ($id !== null) {
            $compte = Compte::find($id);
            $this->selectedLabel = $compte?->intitule;
            $this->selectedFamilleLabel = $compte?->famille()?->libelle();
        }
    }

    public function updatedCompteId(mixed $value): void
    {
        $this->compteId = ($value !== '' && $value !== null) ? (int) $value : null;
    }

    public function updatedSearch(): void
    {
        $this->doSearch();
    }

    public function doSearch(): void
    {
        $flagToUsage = [
            'pour_dons' => UsageComptable::Don,
            'pour_cotisations' => UsageComptable::Cotisation,
            'pour_inscriptions' => UsageComptable::Inscription,
        ];
        $usage = isset($flagToUsage[$this->usageFlag]) ? $flagToUsage[$this->usageFlag] : null;

        $classes = match ($this->filtre) {
            'depense' => [6],
            'recette' => [7],
            default => [6, 7],
        };

        $query = Compte::whereIn('classe', $classes)
            ->where('actif', true)
            ->when($usage, fn ($q) => $q->forUsage($usage));

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('numero_pcg', 'like', '%'.$this->search.'%')
                    ->orWhere('intitule', 'like', '%'.$this->search.'%');
            });
        }

        $comptes = $query
            ->orderBy('numero_pcg')
            ->limit(30)
            ->get();

        $familles = Famille::pourComptes($comptes);

        $this->results = $comptes
            ->groupBy(fn (Compte $c): string => $c->code_famille)
            ->sortKeys()
            ->map(function (Collection $items, string $code) use ($familles): array {
                $famille = $familles->get($code);

                return [
                    'famille_code' => $code,
                    'famille_label' => $famille?->libelle() ?? $code,
                    'items' => $items->map(fn (Compte $c): array => [
                        'id' => $c->id,
                        'numero_pcg' => $c->numero_pcg,
                        'intitule' => $c->intitule,
                    ])->toArray(),
                ];
            })
            ->values()
            ->toArray();

        $this->open = true;
    }

    public function selectCompte(int $id): void
    {
        $compte = Compte::findOrFail($id);
        $this->compteId = $compte->id;
        $this->selectedLabel = $compte->intitule;
        $this->selectedFamilleLabel = $compte->famille()?->libelle();
        $this->search = '';
        $this->open = false;
        $this->results = [];
    }

    public function clearCompte(): void
    {
        $this->compteId = null;
        $this->selectedLabel = null;
        $this->selectedFamilleLabel = null;
        $this->search = '';
        $this->open = false;
        $this->results = [];
    }

    public function openCreateModal(): void
    {
        $this->newIntitule = $this->search;
        $this->newNumeroPcg = '';
        $this->showCreateModal = true;
        $this->open = false;
    }

    public function confirmCreate(): void
    {
        $this->validate([
            'newIntitule' => ['required', 'string', 'max:150'],
            'newNumeroPcg' => ['required', 'string', 'max:20'],
        ]);

        $numero = trim($this->newNumeroPcg);
        $classesAttendues = match ($this->filtre) {
            'depense' => [6],
            'recette' => [7],
            default => [6, 7],
        };

        $classe = $numero !== '' ? (int) substr($numero, 0, 1) : 0;
        if (! in_array($classe, $classesAttendues, true)) {
            $this->addError(
                'newNumeroPcg',
                'Le numéro de compte doit être de classe '.implode(' ou ', $classesAttendues).' pour ce type d\'écriture.'
            );

            return;
        }

        $associationId = TenantContext::currentId();

        // Réutilise un compte existant plutôt que de heurter l'unicité
        // (association_id, numero_pcg) — plus accueillant qu'une violation SQL.
        $compte = Compte::withoutGlobalScopes()
            ->where('association_id', $associationId)
            ->where('numero_pcg', $numero)
            ->first();

        if ($compte === null) {
            $compte = Compte::create([
                'association_id' => $associationId,
                'numero_pcg' => $numero,
                'intitule' => $this->newIntitule,
                'classe' => $classe,
                'actif' => true,
                'est_systeme' => false,
                'lettrable' => false,
            ]);
        }

        $this->selectCompte($compte->id);
        $this->showCreateModal = false;
        $this->newIntitule = '';
        $this->newNumeroPcg = '';
    }

    public function render(): View
    {
        return view('livewire.compte-autocomplete');
    }
}
