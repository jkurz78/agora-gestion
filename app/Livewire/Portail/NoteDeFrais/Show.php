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
use Livewire\Component;

final class Show extends Component
{
    use WithPortailTenant;

    public Association $association;

    public NoteDeFrais $noteDeFrais;

    public function mount(Association $association, NoteDeFrais $noteDeFrais): void
    {
        $this->association = $association;
        Gate::forUser(Auth::guard('tiers-portail')->user())->authorize('view', $noteDeFrais);
        $this->noteDeFrais = $noteDeFrais;
    }

    public function delete(): void
    {
        $ndf = $this->noteDeFrais;
        Gate::forUser(Auth::guard('tiers-portail')->user())->authorize('delete', $ndf);

        app(NoteDeFraisService::class)->delete($ndf);

        session()->flash('portail.success', 'Note de frais supprimée.');
        $this->redirectRoute('portail.ndf.index', ['association' => $this->association->slug]);
    }

    public function render(): View
    {
        $ndf = $this->noteDeFrais->load('lignes.sousCategorie', 'lignes.operation');

        return view('livewire.portail.note-de-frais.show', [
            'ndf' => $ndf,
        ])->layout('portail.layouts.app');
    }
}
