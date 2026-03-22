<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Categorie;
use App\Models\SousCategorie;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Modelable;
use Livewire\Component;

final class SousCategorieAutocomplete extends Component
{
    #[Modelable]
    public int|string|null $sousCategorieId = null;

    public string $filtre = 'tous'; // 'depense' | 'recette' | 'tous'

    public ?string $sousCategorieFlag = null; // 'pour_dons' | 'pour_cotisations' | 'pour_inscriptions'

    public string $search = '';

    public bool $open = false;

    public ?string $selectedLabel = null;

    public ?string $selectedCategorieLabel = null;

    public bool $showCreateModal = false;

    public string $newNom = '';

    public ?int $newCategorieId = null;

    public string $newCodeCerfa = '';

    /**
     * @var array<int, array{categorie_id: int, categorie_nom: string, items: array<int, array{id: int, nom: string, code_cerfa: string|null}>}>
     */
    public array $results = [];

    public function mount(): void
    {
        // Normalise: empty string from lignes array → null
        $id = ($this->sousCategorieId !== '' && $this->sousCategorieId !== null)
            ? (int) $this->sousCategorieId
            : null;
        $this->sousCategorieId = $id;

        if ($id !== null) {
            $sc = SousCategorie::with('categorie')->find($id);
            $this->selectedLabel = $sc?->nom;
            $this->selectedCategorieLabel = $sc?->categorie?->nom;
        }
    }

    public function updatedSousCategorieId(mixed $value): void
    {
        $this->sousCategorieId = ($value !== '' && $value !== null) ? (int) $value : null;
    }

    public function updatedSearch(): void
    {
        $this->doSearch();
    }

    public function doSearch(): void
    {
        $allowedFlags = ['pour_dons', 'pour_cotisations', 'pour_inscriptions'];
        $flag = in_array($this->sousCategorieFlag, $allowedFlags, true) ? $this->sousCategorieFlag : null;

        $query = SousCategorie::with('categorie')
            ->whereHas('categorie', function ($q): void {
                if ($this->filtre !== 'tous') {
                    $q->where('type', $this->filtre);
                }
            })
            ->when($flag, fn ($q) => $q->where($flag, true));

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('nom', 'like', '%'.$this->search.'%')
                    ->orWhereHas('categorie', fn ($q) => $q->where('nom', 'like', '%'.$this->search.'%'));
            });
        }

        $this->results = $query
            ->orderBy('categorie_id')
            ->orderBy('nom')
            ->limit(30)
            ->get()
            ->groupBy('categorie_id')
            ->map(function (Collection $items): array {
                return [
                    'categorie_id' => (int) $items->first()->categorie_id,
                    'categorie_nom' => $items->first()->categorie->nom,
                    'items' => $items->map(fn (SousCategorie $sc): array => [
                        'id' => $sc->id,
                        'nom' => $sc->nom,
                        'code_cerfa' => $sc->code_cerfa,
                    ])->toArray(),
                ];
            })
            ->values()
            ->toArray();

        $this->open = true;
    }

    public function selectSousCategorie(int $id): void
    {
        $sc = SousCategorie::with('categorie')->findOrFail($id);
        $this->sousCategorieId = $sc->id;
        $this->selectedLabel = $sc->nom;
        $this->selectedCategorieLabel = $sc->categorie->nom;
        $this->search = '';
        $this->open = false;
        $this->results = [];
    }

    public function clearSousCategorie(): void
    {
        $this->sousCategorieId = null;
        $this->selectedLabel = null;
        $this->selectedCategorieLabel = null;
        $this->search = '';
        $this->open = false;
        $this->results = [];
    }

    public function openCreateModal(): void
    {
        $this->newNom = $this->search;
        $this->newCategorieId = null;
        $this->newCodeCerfa = '';
        $this->showCreateModal = true;
        $this->open = false;
    }

    public function confirmCreate(): void
    {
        $this->validate([
            'newNom' => ['required', 'string', 'max:150'],
            'newCategorieId' => ['required', 'integer', 'exists:categories,id'],
            'newCodeCerfa' => ['nullable', 'string', 'max:20'],
        ]);

        $sc = SousCategorie::create([
            'categorie_id' => $this->newCategorieId,
            'nom' => $this->newNom,
            'code_cerfa' => $this->newCodeCerfa !== '' ? $this->newCodeCerfa : null,
        ]);

        $this->selectSousCategorie($sc->id);
        $this->showCreateModal = false;
        $this->newNom = '';
        $this->newCodeCerfa = '';
    }

    public function render(): View
    {
        $categories = $this->showCreateModal
            ? Categorie::when($this->filtre !== 'tous', fn ($q) => $q->where('type', $this->filtre))
                ->orderBy('nom')
                ->get()
            : collect();

        return view('livewire.sous-categorie-autocomplete', compact('categories'));
    }
}
