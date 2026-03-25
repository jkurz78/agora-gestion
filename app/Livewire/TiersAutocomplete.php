<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use Illuminate\View\View;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\On;
use Livewire\Component;

final class TiersAutocomplete extends Component
{
    #[Modelable]
    public ?int $tiersId = null;

    public string $filtre = 'tous'; // 'depenses' | 'recettes' | 'dons' | 'tous'

    public string $typeFiltre = ''; // '' | 'particulier' | 'entreprise'

    public string $context = ''; // passed to TiersForm when creating new tiers

    public string $search = '';

    public bool $open = false;

    public ?string $selectedLabel = null;

    public ?string $selectedType = null;

    /** @var array{id: int, label: string, type: string}|null */
    public ?array $existingTiers = null;

    public bool $showActivateModal = false;

    /** @var array<int, array{id: int, label: string, type: string}> */
    public array $results = [];

    public function mount(): void
    {
        if ($this->tiersId !== null) {
            $tiers = Tiers::find($this->tiersId);
            $this->selectedLabel = $tiers?->displayName();
            $this->selectedType = $tiers?->type;
        }
    }

    public function updatedTiersId(mixed $value): void
    {
        $id = ($value !== '' && $value !== null) ? (int) $value : null;
        $this->tiersId = $id;
        if ($id !== null) {
            $tiers = Tiers::find($id);
            $this->selectedLabel = $tiers?->displayName();
            $this->selectedType = $tiers?->type;
        } else {
            $this->selectedLabel = null;
            $this->selectedType = null;
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
            'dons' => $query->where('pour_recettes', true),
            default => null,
        };

        if ($this->typeFiltre !== '') {
            $query->where('type', $this->typeFiltre);
        }

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('nom', 'like', '%'.$this->search.'%')
                    ->orWhere('prenom', 'like', '%'.$this->search.'%')
                    ->orWhere('entreprise', 'like', '%'.$this->search.'%');
            });
        }

        $this->results = $query->limit(8)->get()->map(fn (Tiers $t): array => [
            'id' => $t->id,
            'label' => $t->displayName(),
            'type' => $t->type,
        ])->toArray();

        $this->open = true;
    }

    public function selectTiers(int $id): void
    {
        $tiers = Tiers::findOrFail($id);
        $this->tiersId = $tiers->id;
        $this->selectedLabel = $tiers->displayName();
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
        $this->existingTiers = null;
        $this->showActivateModal = false;
    }

    public function openCreateModal(): void
    {
        $search = $this->search;
        $existing = Tiers::where(function ($q) use ($search): void {
            $q->where('nom', 'like', '%'.$search.'%')
                ->orWhere('prenom', 'like', '%'.$search.'%')
                ->orWhere('entreprise', 'like', '%'.$search.'%');
        })->first();

        $excludedByFilter = $existing && match ($this->filtre) {
            'depenses' => ! $existing->pour_depenses,
            'recettes', 'dons' => ! $existing->pour_recettes,
            default => false,
        };

        if ($excludedByFilter) {
            $this->existingTiers = [
                'id' => $existing->id,
                'label' => $existing->displayName(),
                'type' => $existing->type,
            ];
            $this->showActivateModal = true;
            $this->open = false;
        } else {
            $this->dispatch('open-tiers-form', prefill: [
                'nom' => $this->search,
                'pour_recettes' => in_array($this->filtre, ['recettes', 'dons']),
                'pour_depenses' => $this->filtre === 'depenses',
                'context' => $this->context,
            ])->to(TiersForm::class);
            $this->open = false;
        }
    }

    public function activateTiers(): void
    {
        if ($this->existingTiers === null) {
            return;
        }

        $tiers = Tiers::findOrFail($this->existingTiers['id']);

        $updates = match ($this->filtre) {
            'depenses' => ['pour_depenses' => true],
            'recettes', 'dons' => ['pour_recettes' => true],
            default => [],
        };

        if (! empty($updates)) {
            $tiers->update($updates);
        }

        $this->selectTiers($tiers->id);
        $this->showActivateModal = false;
        $this->existingTiers = null;
    }

    #[On('tiers-saved')]
    public function onTiersSaved(int $id): void
    {
        $this->selectTiers($id);
    }

    public function render(): View
    {
        return view('livewire.tiers-autocomplete');
    }
}
