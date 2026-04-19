<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use Illuminate\View\View;
use Livewire\Component;

final class Login extends Component
{
    public function render(): View
    {
        return view('livewire.portail.login')
            ->layout('portail.layouts.app');
    }
}
