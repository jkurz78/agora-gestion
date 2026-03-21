<?php

declare(strict_types=1);

use App\Enums\HelloAssoEnvironnement;
use App\Models\HelloAssoParametres;
use App\Services\HelloAssoService;
use App\Services\HelloAssoTestResult;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->service = app(HelloAssoService::class);
});

function makeParametres(string $env = 'production'): HelloAssoParametres
{
    $p = new HelloAssoParametres();
    $p->client_id         = 'mon-client-id';
    $p->client_secret     = 'mon-client-secret';
    $p->organisation_slug = 'association-svs';
    $p->environnement     = HelloAssoEnvironnement::from($env);
    return $p;
}

it('retourne succès avec nom organisation quand connexion OK', function () {
    Http::fake([
        'api.helloasso.com/oauth2/token' => Http::response(['access_token' => 'tok123'], 200),
        'api.helloasso.com/v5/organizations/association-svs' => Http::response(['name' => 'SVS'], 200),
    ]);

    $result = $this->service->testerConnexion(makeParametres());

    expect($result)->toBeInstanceOf(HelloAssoTestResult::class);
    expect($result->success)->toBeTrue();
    expect($result->organisationNom)->toBe('SVS');
    expect($result->erreur)->toBeNull();
});

it('retourne erreur si token OAuth2 échoue (401)', function () {
    Http::fake([
        'api.helloasso.com/oauth2/token' => Http::response([], 401),
    ]);

    $result = $this->service->testerConnexion(makeParametres());

    expect($result->success)->toBeFalse();
    expect($result->erreur)->toContain('401');
});

it('retourne erreur si slug introuvable (404)', function () {
    Http::fake([
        'api.helloasso.com/oauth2/token' => Http::response(['access_token' => 'tok123'], 200),
        'api.helloasso.com/v5/organizations/association-svs' => Http::response([], 404),
    ]);

    $result = $this->service->testerConnexion(makeParametres());

    expect($result->success)->toBeFalse();
    expect($result->erreur)->toContain('404');
});

it('retourne erreur réseau si connexion impossible', function () {
    Http::fake([
        'api.helloasso.com/*' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        },
    ]);

    $result = $this->service->testerConnexion(makeParametres());

    expect($result->success)->toBeFalse();
    expect($result->erreur)->toContain('réseau');
});

it('utilise la bonne URL pour le sandbox', function () {
    Http::fake([
        'api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok-sb'], 200),
        'api.helloasso-sandbox.com/v5/organizations/association-svs' => Http::response(['name' => 'SVS Sandbox'], 200),
    ]);

    $result = $this->service->testerConnexion(makeParametres('sandbox'));

    expect($result->success)->toBeTrue();
    expect($result->organisationNom)->toBe('SVS Sandbox');
});

it('retourne erreur si la réponse OAuth2 ne contient pas de token', function () {
    Http::fake([
        'api.helloasso.com/oauth2/token' => Http::response(['error' => 'invalid'], 200),
    ]);

    $result = $this->service->testerConnexion(makeParametres());

    expect($result->success)->toBeFalse();
    expect($result->erreur)->toContain('token');
});
