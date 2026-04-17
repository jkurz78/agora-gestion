<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Models\Association;
use App\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Wizard extends Component
{
    public const TOTAL_STEPS = 9;

    public int $currentStep = 1;

    public array $state = [];

    public function mount(): void
    {
        $association = $this->currentAssociation();

        $this->currentStep = max(1, (int) $association->wizard_current_step);
        $this->state = $association->wizard_state ?? [];
    }

    public function goToStep(int $step): void
    {
        if ($step < 1 || $step > self::TOTAL_STEPS) {
            return;
        }

        if ($step > $this->currentStep) {
            return;
        }

        $this->currentStep = $step;
    }

    protected function currentAssociation(): Association
    {
        $association = TenantContext::current();

        if ($association === null) {
            abort(404, 'Tenant non résolu pour l\'onboarding.');
        }

        return $association;
    }

    public function render(): View
    {
        return view('livewire.onboarding.wizard');
    }
}
