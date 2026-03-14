<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\TiersService;
use Illuminate\View\View;
use Livewire\Attributes\Modelable;
use Livewire\Component;

final class TiersAutocomplete extends Component
{
    #[Modelable]
    public ?int $tiersId = null;

    public string $filtre = 'tous'; // 'depenses' | 'recettes' | 'dons' | 'tous'

    public string $search = '';

    public bool $open = false;

    public ?string $selectedLabel = null;

    public ?string $selectedType = null;

    // For inline creation modal
    public bool $showCreateModal = false;

    public string $newNom = '';

    public string $newType = 'entreprise';

    public bool $newPourDepenses = true;

    public bool $newPourRecettes = false;

    /** @var array<int, array{id: int, label: string, sub: string}> */
    public array $results = [];

    public function mount(): void
    {
        if ($this->tiersId !== null) {
            $tiers = Tiers::find($this->tiersId);
            $this->selectedLabel = $tiers?->displayName();
            $this->selectedType = $tiers?->type;
        }
    }

    public function updatedSearch(): void
    {
        $this->doSearch();
    }

    public function doSearch(): void
    {
        $query = Tiers::query();

        match ($this->filtre) {
            'depenses' => $query->where('pour_depenses', true),
            'recettes' => $query->where('pour_recettes', true),
            'dons' => $query->where('pour_recettes', true), // dons utilisent le flag pour_recettes
            default => null,
        };

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('nom', 'like', '%'.$this->search.'%')
                    ->orWhere('prenom', 'like', '%'.$this->search.'%');
            });
        }

        $this->results = $query->limit(8)->get()->map(fn (Tiers $t): array => [
            'id'    => $t->id,
            'label' => $t->type === 'entreprise' ? $t->nom : trim($t->prenom.' '.$t->nom),
            'type'  => $t->type,
            'sub'   => '',
        ])->toArray();

        $this->open = true;
    }

    public function selectTiers(int $id): void
    {
        $tiers = Tiers::findOrFail($id);
        $this->tiersId = $tiers->id;
        $this->selectedLabel = $tiers->type === 'entreprise'
            ? $tiers->nom
            : trim($tiers->prenom.' '.$tiers->nom);
        $this->selectedType = $tiers->type;
        $this->search = '';
        $this->open = false;
        $this->results = [];
    }

    public function clearTiers(): void
    {
        $this->tiersId = null;
        $this->selectedLabel = null;
        $this->selectedType = null;
        $this->search = '';
        $this->open = false;
        $this->results = [];
    }

    public function openCreateModal(): void
    {
        $this->newNom = $this->search;
        $this->showCreateModal = true;
        $this->open = false;
    }

    public function confirmCreate(): void
    {
        $this->validate([
            'newNom' => ['required', 'string', 'max:150'],
            'newType' => ['required', 'in:entreprise,particulier'],
        ]);

        $tiers = app(TiersService::class)->create([
            'nom' => $this->newNom,
            'type' => $this->newType,
            'pour_depenses' => $this->newPourDepenses,
            'pour_recettes' => $this->newPourRecettes,
        ]);

        $this->selectTiers($tiers->id);
        $this->showCreateModal = false;
        $this->newNom = '';
    }

    public function render(): View
    {
        return view('livewire.tiers-autocomplete');
    }
}
