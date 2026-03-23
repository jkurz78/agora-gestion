<?php

declare(strict_types=1);

use App\Livewire\Parametres\HelloassoSync;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Models\VirementInterne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

    $compteHA = CompteBancaire::factory()->create(['nom' => 'HelloAsso']);
    $compteCourant = CompteBancaire::factory()->create(['nom' => 'Compte courant']);
    $scDon = SousCategorie::where('pour_dons', true)->first()
        ?? SousCategorie::factory()->create(['pour_dons' => true, 'nom' => 'Don']);

    HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'mon-asso',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => $compteHA->id,
        'compte_versement_id' => $compteCourant->id,
        'sous_categorie_don_id' => $scDon->id,
    ]);

    User::factory()->create();
    Tiers::factory()->avecHelloasso()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
});

it('syncs orders and cashouts together and displays full report', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'id' => 100, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00',
                        'formSlug' => 'dons-libres', 'formType' => 'Donation',
                        'items' => [['id' => 1001, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don']],
                        'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont'],
                        'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
                        'payments' => [['id' => 201, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
                    ],
                ],
                'pagination' => [],
            ])
            ->push(['data' => [], 'pagination' => []]),
        '*/v5/organizations/mon-asso/payments*' => Http::sequence()
            ->push([
                'data' => [
                    ['id' => 201, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00',
                        'idCashOut' => 5001, 'cashOutDate' => '2025-10-20T10:00:00+02:00', 'cashOutState' => 'CashedOut'],
                ],
                'pagination' => [],
            ])
            ->push(['data' => [], 'pagination' => []]),
    ]);

    Livewire::test(HelloassoSync::class)
        ->call('synchroniser')
        ->assertSee('1 créée')
        ->assertSee('Virements')
        ->assertSee('Synchronisation terminée');

    expect(VirementInterne::where('helloasso_cashout_id', 5001)->count())->toBe(1);
});

it('skips cashout sync when compte_versement_id is not configured', function () {
    HelloAssoParametres::where('association_id', 1)->update(['compte_versement_id' => null]);

    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push(['data' => [], 'pagination' => []])
            ->push(['data' => [], 'pagination' => []]),
    ]);

    Livewire::test(HelloassoSync::class)
        ->call('synchroniser')
        ->assertSee('Synchronisation terminée')
        ->assertSee('compte de versement');
});
