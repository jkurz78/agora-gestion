<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Trigger UI for the full-merge flow on the tiers detail page. Renders a
 * "Fusionner ce tiers vers…" button; when clicked, exposes a tiers
 * autocomplete to pick the survivor; once chosen, hands off to
 * `TiersMergeModal` (context `'merge_full'`) which handles arbitrage,
 * impact recap, and the destructive confirmation.
 */
final class TiersFusion extends Component
{
    public Tiers $tiers;

    public bool $showPicker = false;

    public ?int $targetTiersId = null;

    public function openPicker(): void
    {
        $this->targetTiersId = null;
        $this->showPicker = true;
    }

    public function closePicker(): void
    {
        $this->showPicker = false;
        $this->targetTiersId = null;
    }

    /**
     * Triggered by `<livewire:tiers-autocomplete>` once a target is picked.
     * Refuses to proceed if the user picks the same tiers as the source.
     */
    #[On('tiers-selected')]
    public function onTargetSelected(int $id): void
    {
        // Only react when the picker is open — `tiers-selected` is a global
        // Livewire event that any autocomplete on the page dispatches.
        if (! $this->showPicker) {
            return;
        }

        if ($id === $this->tiers->id) {
            session()->flash('error', 'Impossible de fusionner un tiers avec lui-même.');

            return;
        }

        $this->targetTiersId = $id;
        $this->showPicker = false;

        // Open the merge modal in 'merge_full' context. The modal loads
        // both source and target itself based on contextData['source_id']
        // and the explicit $tiersId (target).
        $this->dispatch('open-tiers-merge',
            sourceData: [],          // ignored when context === merge_full
            tiersId: $id,            // target / survivor
            sourceLabel: '',         // computed by the modal
            targetLabel: '',         // computed by the modal
            confirmLabel: '',        // computed by the modal
            context: 'merge_full',
            contextData: ['source_id' => $this->tiers->id, 'target_id' => $id],
        );
    }

    /**
     * Once the modal has fused source into target, redirect to the survivor's
     * detail page with a flash message recapping what moved.
     */
    #[On('tiers-merge-confirmed')]
    public function onMergeConfirmed(int $tiersId, string $context, array $contextData = []): void
    {
        if ($context !== 'merge_full') {
            return;
        }

        $report = $contextData['report'] ?? null;
        $totalMoved = is_array($report['counts'] ?? null) ? array_sum($report['counts']) : 0;

        $survivorName = Tiers::find($tiersId)?->displayName() ?? 'Tiers cible';

        session()->flash('message', "Fusion terminée : {$totalMoved} enregistrement(s) réaffecté(s) sur « {$survivorName} ». Le tiers source a été supprimé.");

        $this->redirect(route('tiers.transactions', $tiersId), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.tiers-fusion');
    }
}
