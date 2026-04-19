<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Models\Association;
use App\Services\Portail\AuthSessionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\View\View;
use Livewire\Component;

final class ChooseTiers extends Component
{
    public Association $association;

    public function mount(Association $association, AuthSessionService $authSession): void
    {
        $this->association = $association;
        if (! $authSession->hasPendingChoice()) {
            $this->redirectRoute('portail.login', ['association' => $association->slug]);
        }
    }

    public function choose(int $tiersId, AuthSessionService $authSession): void
    {
        try {
            $authSession->chooseTiers($tiersId);
        } catch (AuthorizationException) {
            abort(403);
        }
        $this->redirectRoute('portail.home', ['association' => $this->association->slug]);
    }

    public function render(AuthSessionService $authSession): View
    {
        return view('livewire.portail.choose-tiers', [
            'tiers' => $authSession->pendingTiers(),
        ])->layout('portail.layouts.app');
    }
}
