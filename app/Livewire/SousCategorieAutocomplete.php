<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\UsageComptable;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\Famille;
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

    public ?string $sousCategorieFlag = null; // 'pour_dons' | 'pour_cotisations' | 'pour_inscriptions' — external string API, converted internally to UsageComptable

    public string $search = '';

    public bool $open = false;

    public ?string $selectedLabel = null;

    public ?string $selectedFamilleLabel = null;

    public bool $showCreateModal = false;

    public string $newNom = '';

    public ?int $newCategorieId = null;

    public string $newCodeCerfa = '';

    /**
     * @var array<int, array{famille_code: string, famille_label: string, items: array<int, array{id: int, numero_pcg: string, intitule: string}>}>
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
            $compte = Compte::find($id);
            $this->selectedLabel = $compte?->intitule;
            $this->selectedFamilleLabel = $compte?->famille()?->libelle();
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
        $flagToUsage = [
            'pour_dons' => UsageComptable::Don,
            'pour_cotisations' => UsageComptable::Cotisation,
            'pour_inscriptions' => UsageComptable::Inscription,
        ];
        $usage = isset($flagToUsage[$this->sousCategorieFlag]) ? $flagToUsage[$this->sousCategorieFlag] : null;

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

    public function selectSousCategorie(int $id): void
    {
        $compte = Compte::findOrFail($id);
        $this->sousCategorieId = $compte->id;
        $this->selectedLabel = $compte->intitule;
        $this->selectedFamilleLabel = $compte->famille()?->libelle();
        $this->search = '';
        $this->open = false;
        $this->results = [];
    }

    public function clearSousCategorie(): void
    {
        $this->sousCategorieId = null;
        $this->selectedLabel = null;
        $this->selectedFamilleLabel = null;
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
            'newCodeCerfa' => ['required', 'string', 'max:20'],
        ]);

        $sc = SousCategorie::create([
            'categorie_id' => $this->newCategorieId,
            'nom' => $this->newNom,
            'code_cerfa' => $this->newCodeCerfa,
        ]);

        $compte = Compte::where('numero_pcg', $sc->code_cerfa)->firstOrFail();

        $this->selectSousCategorie($compte->id);
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
