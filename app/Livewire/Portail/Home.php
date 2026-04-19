<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Models\Association;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class Home extends Component
{
    public Association $association;

    public function mount(Association $association): void
    {
        $this->association = $association;
    }

    public function render(): View
    {
        return view('livewire.portail.home', [
            'tiers' => Auth::guard('tiers-portail')->user(),
        ])->layout('portail.layouts.app');
    }
}
