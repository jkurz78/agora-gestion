<?php

declare(strict_types=1);

use App\Livewire\Parametres\HelloassoForm;
use App\Models\HelloAssoParametres;
use App\Models\User;
use App\Services\HelloAssoService;
use App\Services\HelloAssoTestResult;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    // Créer l'association id=1 via DB car 'id' n'est pas dans $fillable du modèle Association
    DB::table('association')->insert(['id' => 1, 'nom' => 'SVS', 'created_at' => now(), 'updated_at' => now()]);
});

it('monte sans configuration existante', function () {
    Livewire::test(HelloassoForm::class)
        ->assertSet('clientId', '')
        ->assertSet('organisationSlug', '')
        ->assertSet('environnement', 'production')
        ->assertSet('secretDejaEnregistre', false);
});

it('monte avec configuration existante et ne pré-remplit pas le secret', function () {
    HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'cid-123',
        'client_secret' => 'secret-xyz',
        'organisation_slug' => 'association-svs',
        'environnement' => 'production',
    ]);

    Livewire::test(HelloassoForm::class)
        ->assertSet('clientId', 'cid-123')
        ->assertSet('clientSecret', '')
        ->assertSet('organisationSlug', 'association-svs')
        ->assertSet('secretDejaEnregistre', true);
});

it('sauvegarde une nouvelle configuration', function () {
    Livewire::test(HelloassoForm::class)
        ->set('clientId', 'new-cid')
        ->set('clientSecret', 'new-secret')
        ->set('organisationSlug', 'asso-test')
        ->set('environnement', 'production')
        ->call('sauvegarder')
        ->assertHasNoErrors();

    $p = HelloAssoParametres::where('association_id', 1)->first();
    expect($p)->not->toBeNull();
    expect($p->client_id)->toBe('new-cid');
    expect($p->client_secret)->toBe('new-secret');
    expect($p->organisation_slug)->toBe('asso-test');
});

it('conserve le secret existant si le champ est laissé vide à la sauvegarde', function () {
    HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'cid',
        'client_secret' => 'ancien-secret',
        'organisation_slug' => 'asso',
        'environnement' => 'production',
    ]);

    Livewire::test(HelloassoForm::class)
        ->set('clientId', 'cid-modifie')
        ->set('clientSecret', '')
        ->call('sauvegarder')
        ->assertHasNoErrors();

    $p = HelloAssoParametres::where('association_id', 1)->first();
    expect($p->client_id)->toBe('cid-modifie');
    expect($p->client_secret)->toBe('ancien-secret');
});

it('rejette un slug avec des caractères invalides à la sauvegarde', function () {
    Livewire::test(HelloassoForm::class)
        ->set('clientId', 'cid')
        ->set('organisationSlug', 'SLUG INVALIDE!')
        ->call('sauvegarder')
        ->assertHasErrors(['organisationSlug']);
});

it('appelle le service et stocke le succès en tableau', function () {
    $mock = Mockery::mock(HelloAssoService::class);
    $mock->shouldReceive('testerConnexion')
        ->once()
        ->andReturn(new HelloAssoTestResult(success: true, organisationNom: 'SVS'));
    app()->instance(HelloAssoService::class, $mock);

    Livewire::test(HelloassoForm::class)
        ->set('clientId', 'cid')
        ->set('clientSecret', 'secret')
        ->set('organisationSlug', 'asso-svs')
        ->call('testerConnexion')
        ->assertSet('testResult.success', true)
        ->assertSet('testResult.organisationNom', 'SVS');
});

it('stocke l\'erreur en tableau si le test échoue', function () {
    $mock = Mockery::mock(HelloAssoService::class);
    $mock->shouldReceive('testerConnexion')
        ->once()
        ->andReturn(new HelloAssoTestResult(success: false, erreur: "Erreur d'authentification (HTTP 401)"));
    app()->instance(HelloAssoService::class, $mock);

    Livewire::test(HelloassoForm::class)
        ->set('clientId', 'cid')
        ->set('clientSecret', 'mauvais-secret')
        ->set('organisationSlug', 'asso-svs')
        ->call('testerConnexion')
        ->assertSet('testResult.success', false)
        ->assertSet('testResult.erreur', "Erreur d'authentification (HTTP 401)");
});

it('utilise le secret en base si clientSecret est vide pour le test', function () {
    HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'cid',
        'client_secret' => 'secret-en-base',
        'organisation_slug' => 'asso-svs',
        'environnement' => 'production',
    ]);

    $mock = Mockery::mock(HelloAssoService::class);
    $mock->shouldReceive('testerConnexion')
        ->once()
        ->withArgs(function (HelloAssoParametres $p) {
            return $p->client_secret === 'secret-en-base';
        })
        ->andReturn(new HelloAssoTestResult(success: true, organisationNom: 'SVS'));
    app()->instance(HelloAssoService::class, $mock);

    Livewire::test(HelloassoForm::class)
        ->set('clientId', 'cid')
        ->set('clientSecret', '')
        ->set('organisationSlug', 'asso-svs')
        ->call('testerConnexion')
        ->assertSet('testResult.success', true);
});
