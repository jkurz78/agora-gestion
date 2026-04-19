<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use Illuminate\View\View;
use Livewire\Component;

final class ChooseTiers extends Component
{
    public function render(): View
    {
        return view('livewire.portail.choose-tiers')
            ->layout('portail.layouts.app');
    }
}
