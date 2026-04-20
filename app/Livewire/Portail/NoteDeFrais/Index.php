<?php

declare(strict_types=1);

namespace App\Livewire\Portail\NoteDeFrais;

use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\NoteDeFrais;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class Index extends Component
{
    use WithPortailTenant;

    public Association $association;

    public function mount(Association $association): void
    {
        $this->association = $association;
    }

    public function render(): View
    {
        $tiers = Auth::guard('tiers-portail')->user();

        $notes = NoteDeFrais::where('tiers_id', $tiers->id)
            ->with('lignes')
            ->orderByDesc('date')
            ->get();

        return view('livewire.portail.note-de-frais.index', [
            'notes' => $notes,
        ])->layout('portail.layouts.app');
    }
}
