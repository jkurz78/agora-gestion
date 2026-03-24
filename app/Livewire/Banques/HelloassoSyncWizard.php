<?php

declare(strict_types=1);

namespace App\Livewire\Banques;

use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoSyncWizard extends Component
{
    public int $step = 1;

    public function render(): View
    {
        return view('livewire.banques.helloasso-sync-wizard');
    }
}
