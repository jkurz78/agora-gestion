<?php

declare(strict_types=1);

namespace App\Livewire\Portail\NoteDeFrais;

use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Services\Portail\NoteDeFrais\NoteDeFraisService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class Index extends Component
{
    use WithPortailTenant;

    public Association $association;

    #[Url(as: 'onglet')]
    public string $onglet = 'actives';

    public function mount(Association $association): void
    {
        $this->association = $association;
    }

    public function archiveNdf(int $ndfId): void
    {
        $ndf = NoteDeFrais::findOrFail($ndfId);
        Gate::forUser(Auth::guard('tiers-portail')->user())->authorize('archive', $ndf);

        app(NoteDeFraisService::class)->archive($ndf);

        session()->flash('portail.success', 'Note de frais archivée.');
    }

    public function render(): View
    {
        $tiers = Auth::guard('tiers-portail')->user();

        $query = NoteDeFrais::where('tiers_id', $tiers->id)
            ->with('lignes')
            ->orderByDesc('date');

        if ($this->onglet === 'actives') {
            $query->whereNull('archived_at');
        } elseif ($this->onglet === 'archivees') {
            $query->whereNotNull('archived_at');
        }
        // 'toutes' → pas de filtre archived_at

        $notes = $query->get();

        return view('livewire.portail.note-de-frais.index', [
            'notes' => $notes,
        ])->layout('portail.layouts.app');
    }
}
