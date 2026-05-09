<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Adhesion;
use App\Models\Tiers;
use App\Services\AdhesionService;
use App\Services\ExerciceService;
use DomainException;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class OffrirAdhesionModal extends Component
{
    public bool $visible = false;

    public ?int $tiersId = null;

    public ?int $exercice = null;

    public string $motif = '';

    #[On('offrir-adhesion')]
    public function open(): void
    {
        $this->reset('tiersId', 'motif');
        $this->exercice = app(ExerciceService::class)->current();
        $this->visible = true;
    }

    public function close(): void
    {
        $this->visible = false;
    }

    public function submit(AdhesionService $service): void
    {
        $this->validate([
            'tiersId' => ['required', 'integer'],
            'exercice' => ['required', 'integer'],
            'motif' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $tiers = Tiers::findOrFail((int) $this->tiersId);

        try {
            $service->creerGratuite($tiers, (int) $this->exercice, $this->motif, auth()->user());
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('success', 'Adhésion gratuite créée avec succès.');
        $this->dispatch('adhesion-creee');
        $this->visible = false;
    }

    public function render(): View
    {
        $availableYears = app(ExerciceService::class)->availableYears();

        // Suggestions de motifs : valeurs distinctes déjà saisies (auto-convergence
        // sans paramétrage). Limité à 50 suggestions pour rester léger.
        $motifsSuggestions = Adhesion::query()
            ->whereNotNull('motif_gratuite')
            ->where('motif_gratuite', '!=', '')
            ->distinct()
            ->orderBy('motif_gratuite')
            ->limit(50)
            ->pluck('motif_gratuite')
            ->all();

        return view('livewire.offrir-adhesion-modal', compact('availableYears', 'motifsSuggestions'));
    }
}
