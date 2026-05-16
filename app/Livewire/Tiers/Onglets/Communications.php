<?php

declare(strict_types=1);

namespace App\Livewire\Tiers\Onglets;

use App\Models\EmailLog;
use App\Models\Tiers;
use App\Services\Tiers\TiersCommunicationsTimelineService;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class Communications extends Component
{
    use WithPagination;

    public Tiers $tiers;

    public ?string $filtreCategorie = null;

    public ?int $selectedEmailId = null;

    public function mount(Tiers $tiers): void
    {
        $this->tiers = $tiers;
    }

    public function setFiltre(?string $categorie): void
    {
        $this->filtreCategorie = $categorie === '' ? null : $categorie;
        $this->resetPage();
    }

    public function openDetail(int $id): void
    {
        $this->selectedEmailId = $id;
    }

    public function closeDetail(): void
    {
        $this->selectedEmailId = null;
    }

    public function render(): View
    {
        $service = app(TiersCommunicationsTimelineService::class);
        $timeline = $service->forTiers(
            $this->tiers,
            filtreCategorie: $this->filtreCategorie,
            page: $this->getPage(),
        );

        $selected = null;
        if ($this->selectedEmailId !== null) {
            $selected = EmailLog::query()
                ->with([
                    'participant:id,tiers_id',
                    'participant.tiers:id,nom,prenom',
                    'operation:id,nom',
                    'campagne:id,nom',
                    'envoyePar:id,nom',
                    'opens',
                    'emailTemplate:id,nom',
                ])
                ->find($this->selectedEmailId);

            // Garde-fou tenant : l'email doit appartenir au tiers (via tiers_id direct ou participant)
            if ($selected !== null) {
                $appartient = (int) $selected->tiers_id === (int) $this->tiers->id
                    || ($selected->participant_id !== null
                        && $this->tiers->participants()->whereKey($selected->participant_id)->exists());
                if (! $appartient) {
                    $selected = null;
                    $this->selectedEmailId = null;
                }
            }
        }

        return view('livewire.tiers.onglets.communications', [
            'timeline' => $timeline,
            'selected' => $selected,
        ]);
    }
}
