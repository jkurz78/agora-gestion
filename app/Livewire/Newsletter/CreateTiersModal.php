<?php

declare(strict_types=1);

namespace App\Livewire\Newsletter;

use App\Models\Newsletter\SubscriptionRequest;
use App\Services\Newsletter\BufferImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

final class CreateTiersModal extends Component
{
    public bool $showModal = false;

    public ?int $requestId = null;

    public string $type = 'particulier';

    public string $prenom = '';

    public string $nom = '';

    public string $email = '';

    public bool $pour_recettes = true;

    #[On('open-newsletter-create-tiers')]
    public function open(int $requestId): void
    {
        $req = SubscriptionRequest::findOrFail($requestId);

        $this->requestId = $req->id;
        $this->type = 'particulier';
        $this->prenom = (string) ($req->prenom ?? '');
        $this->nom = (string) ($req->nom ?? '');
        $this->email = (string) $req->email;
        $this->pour_recettes = true;
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'type' => ['required', Rule::in(['particulier', 'entreprise'])],
            'prenom' => ['nullable', 'string', 'max:100'],
            'nom' => ['required_without:prenom', 'nullable', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'pour_recettes' => ['boolean'],
        ]);

        $req = SubscriptionRequest::findOrFail($this->requestId);
        $tiers = app(BufferImportService::class)->createTiersFromBuffer($req, $data);

        $this->dispatch('newsletter-tiers-created', tiersId: $tiers->id);
        $this->dispatch('toast', message: 'Tiers '.$tiers->displayName().' créé.');
        $this->showModal = false;
        $this->reset(['requestId', 'prenom', 'nom', 'email']);
    }

    public function close(): void
    {
        $this->showModal = false;
        $this->reset(['requestId', 'prenom', 'nom', 'email']);
    }

    public function render(): View
    {
        return view('livewire.newsletter.create-tiers-modal');
    }
}
