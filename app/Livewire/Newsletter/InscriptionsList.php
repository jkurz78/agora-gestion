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

    public function openMergeModal(int $requestId, int $matchId): void
    {
        $req = SubscriptionRequest::findOrFail($requestId);
        $tiers = Tiers::findOrFail($matchId);

        $this->dispatch('open-tiers-merge',
            sourceData: [
                'email' => $req->email,
                'prenom' => $req->prenom,
                'nom' => $req->nom,
                'type' => 'particulier',
            ],
            tiersId: $tiers->id,
            sourceLabel: 'Inscription newsletter — '.$req->email,
            targetLabel: 'Tiers existant — '.$tiers->displayName(),
            confirmLabel: "Fusionner et lier l'inscription",
            context: 'newsletter_import',
            contextData: ['subscription_request_id' => $req->id],
        );
    }

    #[On('tiers-merge-confirmed')]
    public function onMergeConfirmed(int $tiersId, string $context, array $contextData): void
    {
        if ($context !== 'newsletter_import') {
            return;
        }
        if (! isset($contextData['subscription_request_id'])) {
            return;
        }

        $req = SubscriptionRequest::findOrFail((int) $contextData['subscription_request_id']);
        $tiers = Tiers::findOrFail($tiersId);
        app(BufferImportService::class)->linkBufferToExistingTiers($req, $tiers);

        unset($this->inscriptionsRows);
        $this->dispatch('toast', message: 'Inscription liée à '.$tiers->displayName().'.');
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
