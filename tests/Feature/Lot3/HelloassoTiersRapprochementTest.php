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
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'items' => [
                ['user' => ['firstName' => 'Jean', 'lastName' => 'Dupont']],
            ],
            'payments' => [],
        ],
    ]);

    Livewire::test(HelloassoTiersRapprochement::class)
        ->call('fetchTiers')
        ->assertSee('Jean')
        ->assertSee('Dupont')
        ->assertSee('jean@test.com');
});

it('associates a person to an existing tiers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => 'jean-ancien@test.com']);

    fakeHelloAssoOrders([
        [
            'id' => 1, 'amount' => 5000,
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'items' => [
                ['user' => ['firstName' => 'Jean', 'lastName' => 'Dupont']],
            ],
            'payments' => [],
        ],
    ]);

    Livewire::test(HelloassoTiersRapprochement::class)
        ->call('fetchTiers')
        ->set('selectedTiers.0', $tiers->id)
        ->call('associer', 0);

    $tiers->refresh();
    expect($tiers->est_helloasso)->toBeTrue();
    // Email is NOT overwritten — associer only sets est_helloasso
    expect($tiers->email)->toBe('jean-ancien@test.com');
});

it('pre-selects suggested tiers by index', function () {
    Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'est_helloasso' => false]);

    fakeHelloAssoOrders([
        [
            'id' => 1, 'amount' => 5000,
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'items' => [
                ['user' => ['firstName' => 'Jean', 'lastName' => 'Dupont']],
            ],
            'payments' => [],
        ],
    ]);

    $component = Livewire::test(HelloassoTiersRapprochement::class)
        ->call('fetchTiers');

    $selectedTiers = $component->get('selectedTiers');
    expect($selectedTiers[0])->not->toBeNull();
});

it('creates a new tiers from HelloAsso person', function () {
    fakeHelloAssoOrders([
        [
            'id' => 1, 'amount' => 5000,
            'payer' => ['firstName' => 'Marie', 'lastName' => 'Martin', 'email' => 'marie@test.com', 'address' => '5 rue A', 'city' => 'Lyon', 'zipCode' => '69001', 'country' => 'FRA'],
            'items' => [
                ['user' => ['firstName' => 'Marie', 'lastName' => 'Martin']],
            ],
            'payments' => [],
        ],
    ]);

    Livewire::test(HelloassoTiersRapprochement::class)
        ->call('fetchTiers')
        ->call('creer', 0);

    $tiers = Tiers::where('nom', 'Martin')->where('prenom', 'Marie')->first();
    expect($tiers)->not->toBeNull();
    expect($tiers->email)->toBe('marie@test.com');
    expect($tiers->est_helloasso)->toBeTrue();
    expect($tiers->pour_recettes)->toBeTrue();
    expect($tiers->ville)->toBe('Lyon');
});

it('shows unlinked persons before linked ones', function () {
    Tiers::factory()->create(['nom' => 'Ancien', 'prenom' => 'Lié', 'email' => 'lie@test.com', 'est_helloasso' => true]);

    fakeHelloAssoOrders([
        [
            'id' => 1, 'amount' => 5000,
            'payer' => ['firstName' => 'Lié', 'lastName' => 'Ancien', 'email' => 'lie@test.com'],
            'items' => [
                ['user' => ['firstName' => 'Lié', 'lastName' => 'Ancien']],
            ],
            'payments' => [],
        ],
        [
            'id' => 2, 'amount' => 3000,
            'payer' => ['firstName' => 'Nouveau', 'lastName' => 'Inconnu', 'email' => 'nouveau@test.com'],
            'items' => [
                ['user' => ['firstName' => 'Nouveau', 'lastName' => 'Inconnu']],
            ],
            'payments' => [],
        ],
    ]);

    $component = Livewire::test(HelloassoTiersRapprochement::class)
        ->call('fetchTiers');

    $persons = $component->get('persons');
    // Unlinked (nouveau) should be first
    expect($persons[0]['lastName'])->toBe('Inconnu');
    expect($persons[0]['tiers_id'])->toBeNull();
    // Linked (ancien) should be second
    expect($persons[1]['lastName'])->toBe('Ancien');
    expect($persons[1]['tiers_id'])->not->toBeNull();
});
