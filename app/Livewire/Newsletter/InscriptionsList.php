<?php

declare(strict_types=1);

namespace App\Livewire\Newsletter;

use App\Models\Newsletter\SubscriptionRequest;
use App\Models\Tiers;
use App\Services\Newsletter\BufferImportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
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

    /** @return Collection<int, array{request: SubscriptionRequest, match: ?Tiers}> */
    #[Computed]
    public function inscriptionsRows(): Collection
    {
        $service = app(BufferImportService::class);

        return SubscriptionRequest::query()
            ->inscriptionsAtraiter()
            ->orderByDesc('confirmed_at')
            ->get()
            ->map(fn (SubscriptionRequest $req) => [
                'request' => $req,
                'match' => $service->suggestMatch($req),
            ]);
    }

    public function openCreateModal(int $requestId): void
    {
        $this->dispatch('open-newsletter-create-tiers', requestId: $requestId);
    }

    #[On('newsletter-tiers-created')]
    public function onTiersCreated(): void
    {
        unset($this->inscriptionsRows);
    }

    public function ignore(int $requestId): void
    {
        $req = SubscriptionRequest::findOrFail($requestId);
        app(BufferImportService::class)->ignore($req);
        unset($this->inscriptionsRows);
        $this->dispatch('toast', message: 'Demande ignorée.');
    }

    public function render(): View
    {
        return view('livewire.newsletter.inscriptions-list')
            ->layout('layouts.app-sidebar', ['title' => 'Inscriptions newsletter']);
    }
}
