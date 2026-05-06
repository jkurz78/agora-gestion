<?php

declare(strict_types=1);

namespace App\Livewire\Newsletter;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

final class InscriptionsList extends Component
{
    public string $tab = 'inscriptions';

    public function mount(): void
    {
        if (! Gate::allows('access-newsletter-inbox')) {
            throw new AuthorizationException;
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['inscriptions', 'desinscriptions'], true) ? $tab : 'inscriptions';
    }

    public function render(): View
    {
        return view('livewire.newsletter.inscriptions-list')
            ->layout('layouts.app-sidebar', ['title' => 'Inscriptions newsletter']);
    }
}
