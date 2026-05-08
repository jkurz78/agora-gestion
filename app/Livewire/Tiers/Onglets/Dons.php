<?php

declare(strict_types=1);

namespace App\Livewire\Tiers\Onglets;

use App\Models\Tiers;
use Illuminate\View\View;
use Livewire\Component;

final class Dons extends Component
{
    public Tiers $tiers;

    public function mount(Tiers $tiers): void
    {
        $this->tiers = $tiers;
    }

    public function render(): View
    {
        return view('livewire.tiers.onglets.dons');
    }
}
