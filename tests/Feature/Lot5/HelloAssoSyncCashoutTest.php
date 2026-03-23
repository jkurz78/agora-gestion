<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\HelloAssoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

    // Ensure user ID 1 exists so saisi_par FK is valid (auth()->id() ?? 1)
    User::factory()->create();

    $this->compteHA = CompteBancaire::factory()->create(['nom' => 'HelloAsso']);
    $this->compteCourant = CompteBancaire::factory()->create(['nom' => 'Compte courant']);
    $this->scDon = SousCategorie::where('pour_dons', true)->first()
        ?? SousCategorie::factory()->create(['pour_dons' => true, 'nom' => 'Don']);

    $this->parametres = HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'test',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => $this->compteHA->id,
        'compte_versement_id' => $this->compteCourant->id,
        'sous_categorie_don_id' => $this->scDon->id,
    ]);

    $this->tiers = Tiers::factory()->avecHelloasso()->create([
        'nom' => 'Dupont', 'prenom' => 'Jean',
    ]);
});

it('creates a virement interne from a cashout', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'montant_total' => 50.00,
        'compte_id' => $this->compteHA->id,
        'tiers_id' => $this->tiers->id,
        'helloasso_order_id' => 100,
        'helloasso_payment_id' => 201,
    ]);

    $cashOuts = [
        [
            'id' => 5001,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 5000,
            'payments' => [['id' => 201, 'amount' => 5000]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    expect($result['virements_created'])->toBe(1);

    $virement = VirementInterne::where('helloasso_cashout_id', 5001)->first();
    expect($virement)->not->toBeNull();
    expect((float) $virement->montant)->toBe(50.00);
    expect($virement->compte_source_id)->toBe($this->compteHA->id);
    expect($virement->compte_destination_id)->toBe($this->compteCourant->id);
    expect($virement->reference)->toBe('HA-CO-5001');
});

it('marks transactions with cashout_id via payment link', function () {
    $tx = Transaction::factory()->create([
        'type' => 'recette',
        'montant_total' => 50.00,
        'compte_id' => $this->compteHA->id,
        'tiers_id' => $this->tiers->id,
        'helloasso_order_id' => 100,
        'helloasso_payment_id' => 201,
    ]);

    $cashOuts = [
        [
            'id' => 5001,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 5000,
            'payments' => [['id' => 201, 'amount' => 5000]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniserCashouts($cashOuts);

    expect($tx->fresh()->helloasso_cashout_id)->toBe(5001);
});

it('is idempotent — re-importing same cashout updates virement', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'montant_total' => 50.00,
        'compte_id' => $this->compteHA->id,
        'tiers_id' => $this->tiers->id,
        'helloasso_order_id' => 100,
        'helloasso_payment_id' => 201,
    ]);

    $cashOuts = [
        [
            'id' => 5001,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 5000,
            'payments' => [['id' => 201, 'amount' => 5000]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result1 = $service->synchroniserCashouts($cashOuts);
    expect($result1['virements_created'])->toBe(1);

    $result2 = $service->synchroniserCashouts($cashOuts);
    expect($result2['virements_created'])->toBe(0);
    expect($result2['virements_updated'])->toBe(1);

    expect(VirementInterne::where('helloasso_cashout_id', 5001)->count())->toBe(1);
});

it('reports integrity warning when amounts differ', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'montant_total' => 45.00,
        'compte_id' => $this->compteHA->id,
        'tiers_id' => $this->tiers->id,
        'helloasso_order_id' => 100,
        'helloasso_payment_id' => 201,
    ]);

    $cashOuts = [
        [
            'id' => 5001,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 5000,
            'payments' => [['id' => 201, 'amount' => 5000]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    expect($result['integrity_warnings'])->toHaveCount(1);
    expect($result['integrity_warnings'][0])->toContain('5001');
});

it('reports integrity warning when no transactions found for cashout', function () {
    $cashOuts = [
        [
            'id' => 5002,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 3000,
            'payments' => [['id' => 999, 'amount' => 3000]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    expect($result['virements_created'])->toBe(1);
    expect($result['integrity_warnings'])->toHaveCount(1);
});

it('restores soft-deleted virement on re-import', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'montant_total' => 50.00,
        'compte_id' => $this->compteHA->id,
        'tiers_id' => $this->tiers->id,
        'helloasso_order_id' => 100,
        'helloasso_payment_id' => 201,
    ]);

    $cashOuts = [
        [
            'id' => 5001,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 5000,
            'payments' => [['id' => 201, 'amount' => 5000]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniserCashouts($cashOuts);

    // Soft-delete the virement
    VirementInterne::where('helloasso_cashout_id', 5001)->first()->delete();
    expect(VirementInterne::where('helloasso_cashout_id', 5001)->count())->toBe(0);

    // Re-import should restore
    $result = $service->synchroniserCashouts($cashOuts);
    expect($result['virements_updated'])->toBe(1);
    expect(VirementInterne::where('helloasso_cashout_id', 5001)->count())->toBe(1);
});
