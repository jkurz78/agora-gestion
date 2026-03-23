<?php

declare(strict_types=1);

use App\Livewire\Parametres\HelloassoSync;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    \DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

    $compte = CompteBancaire::factory()->create(['nom' => 'HelloAsso']);
    $scDon = SousCategorie::where('pour_dons', true)->first()
        ?? SousCategorie::factory()->create(['pour_dons' => true, 'nom' => 'Don']);
    $scCot = SousCategorie::where('pour_cotisations', true)->first()
        ?? SousCategorie::factory()->create(['pour_cotisations' => true, 'nom' => 'Cotisation']);

    HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'mon-asso',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => $compte->id,
        'sous_categorie_don_id' => $scDon->id,
        'sous_categorie_cotisation_id' => $scCot->id,
    ]);

    Tiers::factory()->avecHelloasso()->create(['email' => 'jean@test.com', 'nom' => 'Dupont', 'prenom' => 'Jean']);
});

it('renders the component', function () {
    Livewire::test(HelloassoSync::class)
        ->assertStatus(200);
});

it('runs sync and displays report', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'id' => 100, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00',
                        'formSlug' => 'dons-libres', 'formType' => 'Donation',
                        'items' => [['id' => 1001, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don']],
                        'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
                        'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
                        'payments' => [['id' => 201, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
                    ],
                ],
                'pagination' => [],
            ])
            ->push(['data' => [], 'pagination' => []]),
    ]);

    Livewire::test(HelloassoSync::class)
        ->call('synchroniser')
        ->assertSee('1 créée')
        ->assertSee('Synchronisation terminée');

    expect(Transaction::where('helloasso_order_id', 100)->count())->toBe(1);
});

it('shows error when config is incomplete', function () {
    HelloAssoParametres::where('association_id', 1)->update(['compte_helloasso_id' => null]);

    Livewire::test(HelloassoSync::class)
        ->call('synchroniser')
        ->assertSee('Compte HelloAsso non configuré');
});
