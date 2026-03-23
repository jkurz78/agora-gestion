<?php

declare(strict_types=1);

use App\Livewire\Parametres\HelloassoTiersRapprochement;
use App\Models\HelloAssoParametres;
use App\Models\Tiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('association')->insert(['id' => 1, 'nom' => 'SVS', 'created_at' => now(), 'updated_at' => now()]);

    HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test-id',
        'client_secret' => 'test-secret',
        'organisation_slug' => 'mon-asso',
        'environnement' => 'sandbox',
    ]);
});

function fakeHelloAssoOrders(array $orders = []): void
{
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push(['data' => $orders, 'pagination' => ['continuationToken' => 'next']])
            ->push(['data' => [], 'pagination' => []]),
    ]);
}

it('renders the component', function () {
    Livewire::test(HelloassoTiersRapprochement::class)
        ->assertStatus(200);
});

it('fetches and displays unlinked persons', function () {
    fakeHelloAssoOrders([
        [
            'id' => 1, 'amount' => 5000,
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'items' => [], 'payments' => [],
        ],
    ]);

    Livewire::test(HelloassoTiersRapprochement::class)
        ->call('fetchTiers')
        ->assertSee('Jean')
        ->assertSee('Dupont')
        ->assertSee('jean@test.com');
});

it('associates a person to an existing tiers via select', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => 'jean-ancien@test.com']);

    fakeHelloAssoOrders([
        [
            'id' => 1, 'amount' => 5000,
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'items' => [], 'payments' => [],
        ],
    ]);

    Livewire::test(HelloassoTiersRapprochement::class)
        ->call('fetchTiers')
        ->set('selectedTiers.jean@test.com', $tiers->id)
        ->call('associer', 'jean@test.com');

    $tiers->refresh();
    expect($tiers->est_helloasso)->toBeTrue();
    expect($tiers->email)->toBe('jean@test.com');
});

it('pre-selects suggested tiers in the select', function () {
    Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => 'jean@test.com', 'est_helloasso' => false]);

    fakeHelloAssoOrders([
        [
            'id' => 1, 'amount' => 5000,
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'items' => [], 'payments' => [],
        ],
    ]);

    $component = Livewire::test(HelloassoTiersRapprochement::class)
        ->call('fetchTiers');

    $selectedTiers = $component->get('selectedTiers');
    expect($selectedTiers['jean@test.com'])->not->toBeNull();
});

it('creates a new tiers from HelloAsso person', function () {
    fakeHelloAssoOrders([
        [
            'id' => 1, 'amount' => 5000,
            'user' => ['firstName' => 'Marie', 'lastName' => 'Martin', 'email' => 'marie@test.com'],
            'payer' => ['firstName' => 'Marie', 'lastName' => 'Martin', 'email' => 'marie@test.com', 'address' => '5 rue A', 'city' => 'Lyon', 'zipCode' => '69001', 'country' => 'FRA'],
            'items' => [], 'payments' => [],
        ],
    ]);

    Livewire::test(HelloassoTiersRapprochement::class)
        ->call('fetchTiers')
        ->call('creer', 'marie@test.com');

    $tiers = Tiers::where('email', 'marie@test.com')->first();
    expect($tiers)->not->toBeNull();
    expect($tiers->nom)->toBe('Martin');
    expect($tiers->prenom)->toBe('Marie');
    expect($tiers->est_helloasso)->toBeTrue();
    expect($tiers->pour_recettes)->toBeTrue();
});

it('shows unlinked persons before linked ones', function () {
    Tiers::factory()->create(['nom' => 'Ancien', 'prenom' => 'Lié', 'email' => 'lie@test.com', 'est_helloasso' => true]);

    fakeHelloAssoOrders([
        [
            'id' => 1, 'amount' => 5000,
            'user' => ['firstName' => 'Lié', 'lastName' => 'Ancien', 'email' => 'lie@test.com'],
            'payer' => ['firstName' => 'Lié', 'lastName' => 'Ancien', 'email' => 'lie@test.com'],
            'items' => [], 'payments' => [],
        ],
        [
            'id' => 2, 'amount' => 3000,
            'user' => ['firstName' => 'Nouveau', 'lastName' => 'Inconnu', 'email' => 'nouveau@test.com'],
            'payer' => ['firstName' => 'Nouveau', 'lastName' => 'Inconnu', 'email' => 'nouveau@test.com'],
            'items' => [], 'payments' => [],
        ],
    ]);

    $component = Livewire::test(HelloassoTiersRapprochement::class)
        ->call('fetchTiers');

    $persons = $component->get('persons');
    // Unlinked (nouveau) should be first
    expect($persons[0]['email'])->toBe('nouveau@test.com');
    expect($persons[0]['tiers_id'])->toBeNull();
    // Linked (lie) should be second
    expect($persons[1]['email'])->toBe('lie@test.com');
    expect($persons[1]['tiers_id'])->not->toBeNull();
});
