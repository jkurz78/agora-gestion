<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Livewire\Parametres\Concerns\AutoriseEcranParametre;
use App\Support\CurrentAssociation;
use Illuminate\View\View;
use Livewire\Component;

final class LiensPublicsForm extends Component
{
    use AutoriseEcranParametre;

    public ?string $url_site_web = null;

    public ?string $url_renouvellement_adhesion = null;

    public ?string $url_nouveau_don = null;

    protected function cleEcranParametre(): string
    {
        return 'liens-publics';
    }

    public function mount(): void
    {
        $association = CurrentAssociation::get();

        $this->url_site_web = $association->url_site_web;
        $this->url_renouvellement_adhesion = $association->url_renouvellement_adhesion;
        $this->url_nouveau_don = $association->url_nouveau_don;
    }

    public function save(): void
    {
        $this->validate([
            'url_site_web' => ['nullable', 'string', 'url', 'max:255'],
            'url_renouvellement_adhesion' => ['nullable', 'string', 'url', 'max:255'],
            'url_nouveau_don' => ['nullable', 'string', 'url', 'max:255'],
        ]);

        CurrentAssociation::get()->fill([
            'url_site_web' => $this->url_site_web ?: null,
            'url_renouvellement_adhesion' => $this->url_renouvellement_adhesion ?: null,
            'url_nouveau_don' => $this->url_nouveau_don ?: null,
        ])->save();

        session()->flash('success', 'Liens publics mis à jour.');
    }

    public function render(): View
    {
        return view('livewire.parametres.liens-publics-form');
    }
}
