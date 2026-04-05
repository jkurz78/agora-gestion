<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\ExerciceService;
use App\Services\TiersQuickViewService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class TiersQuickView extends Component
{
    public bool $visible = false;

    public ?int $tiersId = null;

    public int $exercice = 0;

    /** @var array<string, mixed> */
    public array $summary = [];

    #[On('open-tiers-quick-view')]
    public function loadTiers(int $tiersId): void
    {
        $this->tiersId = $tiersId;
        $this->exercice = app(ExerciceService::class)->current();
        $this->fetchSummary();
        $this->visible = true;
    }

    public function updatedExercice(): void
    {
        $this->fetchSummary();
    }

    public function close(): void
    {
        $this->visible = false;
        $this->tiersId = null;
        $this->summary = [];
    }

    public function render(): View
    {
        $tiers = $this->tiersId !== null ? Tiers::find($this->tiersId) : null;
        $availableYears = app(ExerciceService::class)->availableYears();

        return view('livewire.tiers-quick-view', compact('tiers', 'availableYears'));
    }

    private function fetchSummary(): void
    {
        if ($this->tiersId === null) {
            $this->summary = [];

            return;
        }

        $tiers = Tiers::find($this->tiersId);

        if ($tiers === null) {
            $this->summary = [];

            return;
        }

        $this->summary = app(TiersQuickViewService::class)->getSummary($tiers, $this->exercice);
    }
}
