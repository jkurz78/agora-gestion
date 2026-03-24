<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Enums\HelloAssoEnvironnement;
use App\Models\HelloAssoParametres;
use App\Services\HelloAssoService;
use Illuminate\View\View;
use Livewire\Component;

// Nom en minuscules intentionnel : Livewire résout <livewire:parametres.helloasso-form /> en HelloassoForm, pas HelloAssoForm.
final class HelloassoForm extends Component
{
    public string $clientId = '';

    public string $clientSecret = '';

    public string $organisationSlug = '';

    public string $environnement = 'production';

    /** @var array{success: bool, organisationNom: ?string, erreur: ?string}|null */
    public ?array $testResult = null;

    public bool $secretDejaEnregistre = false;

    public ?string $callbackToken = null;

    public function mount(): void
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();
        if ($p !== null) {
            $this->clientId = $p->client_id ?? '';
            $this->organisationSlug = $p->organisation_slug ?? '';
            $this->environnement = $p->environnement->value;
            if ($p->client_secret !== null) {
                $this->secretDejaEnregistre = true;
            }
            $this->callbackToken = $p->callback_token;
        }
    }

    public function sauvegarder(): void
    {
        $this->validate([
            'clientId' => ['nullable', 'string', 'max:255'],
            'clientSecret' => ['nullable', 'string'],
            'organisationSlug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]*$/'],
            'environnement' => ['required', 'in:production,sandbox'],
        ]);

        $payload = [
            'client_id' => $this->clientId ?: null,
            'organisation_slug' => $this->organisationSlug ?: null,
            'environnement' => $this->environnement,
        ];

        if ($this->clientSecret !== '') {
            $payload['client_secret'] = $this->clientSecret;
        }

        $parametres = HelloAssoParametres::updateOrCreate(
            ['association_id' => 1],
            $payload,
        );

        // Générer le token callback si absent
        if ($parametres->callback_token === null) {
            $token = bin2hex(random_bytes(32));
            $parametres->update(['callback_token' => $token]);
            $this->callbackToken = $token;
        } else {
            $this->callbackToken = $parametres->callback_token;
        }

        if ($this->clientSecret !== '') {
            $this->secretDejaEnregistre = true;
        }

        $this->testResult = null;
        session()->flash('success', 'Paramètres HelloAsso enregistrés.');
    }

    public function regenererToken(): void
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();
        if ($p !== null) {
            $token = bin2hex(random_bytes(32));
            $p->update(['callback_token' => $token]);
            $this->callbackToken = $token;
        }
    }

    public function getCallbackUrl(): ?string
    {
        if ($this->callbackToken === null) {
            return null;
        }
        return url("/api/helloasso/callback/{$this->callbackToken}");
    }

    public function testerConnexion(): void
    {
        $this->validate([
            'clientId' => ['required', 'string'],
            'clientSecret' => $this->secretDejaEnregistre ? ['nullable', 'string'] : ['required', 'string'],
            'organisationSlug' => ['required', 'string'],
            'environnement' => ['required', 'in:production,sandbox'],
        ]);

        $secret = $this->clientSecret;
        if ($secret === '' && $this->secretDejaEnregistre) {
            $enBase = HelloAssoParametres::where('association_id', 1)->first();
            $secret = $enBase?->client_secret ?? '';
        }

        $parametres = new HelloAssoParametres;
        $parametres->client_id = $this->clientId;
        $parametres->client_secret = $secret;
        $parametres->organisation_slug = $this->organisationSlug;
        $parametres->environnement = HelloAssoEnvironnement::from($this->environnement);

        $result = app(HelloAssoService::class)->testerConnexion($parametres);

        // Stocker en tableau pour la sérialisabilité Livewire 4
        $this->testResult = [
            'success' => $result->success,
            'organisationNom' => $result->organisationNom,
            'erreur' => $result->erreur,
        ];
    }

    public function render(): View
    {
        return view('livewire.parametres.helloasso-form');
    }
}
