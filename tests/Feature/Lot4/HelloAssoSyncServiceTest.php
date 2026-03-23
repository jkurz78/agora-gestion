<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\HelloAssoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

    $this->compte = CompteBancaire::factory()->create(['nom' => 'HelloAsso']);
    $this->scDon = SousCategorie::where('pour_dons', true)->first()
        ?? SousCategorie::factory()->create(['pour_dons' => true, 'nom' => 'Don']);
    $this->scCot = SousCategorie::where('pour_cotisations', true)->first()
        ?? SousCategorie::factory()->create(['pour_cotisations' => true, 'nom' => 'Cotisation']);

    $this->parametres = HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'test',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => $this->compte->id,
        'sous_categorie_don_id' => $this->scDon->id,
        'sous_categorie_cotisation_id' => $this->scCot->id,
    ]);

    $this->tiers = Tiers::factory()->avecHelloasso()->create([
        'email' => 'jean@test.com',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
    ]);
});

it('imports a simple donation order', function () {
    $orders = [
        [
            'id' => 100,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 5000,
            'formSlug' => 'dons-libres',
            'formType' => 'Donation',
            'items' => [
                ['id' => 1001, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don libre'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [
                ['id' => 201, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card', 'cashOutState' => 'CashedOut'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->transactionsCreated)->toBe(1);
    expect($result->lignesCreated)->toBe(1);
    expect($result->errors)->toBeEmpty();

    $tx = Transaction::where('helloasso_order_id', 100)->first();
    expect($tx)->not->toBeNull();
    expect($tx->tiers_id)->toBe($this->tiers->id);
    expect((float) $tx->montant_total)->toBe(50.00);
    expect($tx->compte_id)->toBe($this->compte->id);
    expect($tx->type->value)->toBe('recette');
    expect($tx->mode_paiement)->toBe(ModePaiement::Cb);

    $ligne = $tx->lignes()->first();
    expect($ligne->helloasso_item_id)->toBe(1001);
    expect((float) $ligne->montant)->toBe(50.00);
    expect($ligne->sous_categorie_id)->toBe($this->scDon->id);
});

it('imports a membership order with exercice', function () {
    $orders = [
        [
            'id' => 101,
            'date' => '2025-11-01T10:00:00+01:00',
            'amount' => 3000,
            'formSlug' => 'adhesion-2025',
            'formType' => 'Membership',
            'items' => [
                ['id' => 1002, 'amount' => 3000, 'state' => 'Processed', 'type' => 'Membership', 'name' => 'Adhésion'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [
                ['id' => 202, 'amount' => 3000, 'date' => '2025-11-01T10:00:00+01:00', 'paymentMeans' => 'Card'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->transactionsCreated)->toBe(1);

    $ligne = TransactionLigne::where('helloasso_item_id', 1002)->first();
    expect($ligne->sous_categorie_id)->toBe($this->scCot->id);
    expect($ligne->exercice)->toBe(2025);
});

it('groups items by beneficiary into one transaction', function () {
    $orders = [
        [
            'id' => 102,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 8000,
            'formSlug' => 'adhesion-2025',
            'formType' => 'Membership',
            'items' => [
                ['id' => 1003, 'amount' => 3000, 'state' => 'Processed', 'type' => 'Membership', 'name' => 'Adhésion'],
                ['id' => 1004, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don complémentaire'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [
                ['id' => 203, 'amount' => 8000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->transactionsCreated)->toBe(1);
    expect($result->lignesCreated)->toBe(2);

    $tx = Transaction::where('helloasso_order_id', 102)->first();
    expect((float) $tx->montant_total)->toBe(80.00);
    expect($tx->lignes)->toHaveCount(2);
});

it('skips orders with unknown tiers name', function () {
    $orders = [
        [
            'id' => 103,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 2000,
            'formSlug' => 'dons-libres',
            'formType' => 'Donation',
            'items' => [
                ['id' => 1005, 'amount' => 2000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don'],
            ],
            'user' => ['firstName' => 'Inconnu', 'lastName' => 'Personne', 'email' => 'inconnu@test.com'],
            'payer' => ['firstName' => 'Inconnu', 'lastName' => 'Personne', 'email' => 'inconnu@test.com'],
            'payments' => [['id' => 204, 'amount' => 2000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->transactionsCreated)->toBe(0);
    expect($result->ordersSkipped)->toBe(1);
    expect($result->errors)->toHaveCount(1);
    expect($result->errors[0])->toContain('Inconnu Personne');
});

it('is idempotent — re-importing same order updates instead of duplicating', function () {
    $orders = [
        [
            'id' => 104,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 5000,
            'formSlug' => 'dons-libres',
            'formType' => 'Donation',
            'items' => [
                ['id' => 1006, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [['id' => 205, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result1 = $service->synchroniser($orders, 2025);
    expect($result1->transactionsCreated)->toBe(1);

    $result2 = $service->synchroniser($orders, 2025);
    expect($result2->transactionsCreated)->toBe(0);
    expect($result2->transactionsUpdated)->toBe(1);

    expect(Transaction::where('helloasso_order_id', 104)->count())->toBe(1);
});

it('resolves operation from form mapping for Registration items', function () {
    $scInscr = SousCategorie::factory()->create(['pour_inscriptions' => true, 'nom' => 'Inscription']);
    $this->parametres->update(['sous_categorie_inscription_id' => $scInscr->id]);

    $operation = Operation::factory()->create(['nom' => 'Stage été 2026']);
    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'stage-ete-2026',
        'form_type' => 'Event',
        'form_title' => 'Stage été 2026',
        'operation_id' => $operation->id,
    ]);

    $orders = [
        [
            'id' => 105,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 15000,
            'formSlug' => 'stage-ete-2026',
            'formType' => 'Event',
            'items' => [
                ['id' => 1007, 'amount' => 15000, 'state' => 'Processed', 'type' => 'Registration', 'name' => 'Stage'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [['id' => 206, 'amount' => 15000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->transactionsCreated)->toBe(1);
    $ligne = TransactionLigne::where('helloasso_item_id', 1007)->first();
    expect($ligne->sous_categorie_id)->toBe($scInscr->id);
    expect($ligne->operation_id)->toBe($operation->id);
});

it('reports error for Registration item without mapped operation', function () {
    $scInscr = SousCategorie::factory()->create(['pour_inscriptions' => true, 'nom' => 'Inscription']);
    $this->parametres->update(['sous_categorie_inscription_id' => $scInscr->id]);
    // No form mapping created

    $orders = [
        [
            'id' => 106,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 10000,
            'formSlug' => 'stage-non-mappe',
            'formType' => 'Event',
            'items' => [
                ['id' => 1008, 'amount' => 10000, 'state' => 'Processed', 'type' => 'Registration', 'name' => 'Stage'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [['id' => 207, 'amount' => 10000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->errors)->toHaveCount(1);
    expect($result->errors[0])->toContain('stage-non-mappe');
    expect(Transaction::where('helloasso_order_id', 106)->count())->toBe(0);
});

it('maps payment means correctly', function () {
    $orders = [
        [
            'id' => 107,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 5000,
            'formSlug' => 'dons-libres',
            'formType' => 'Donation',
            'items' => [
                ['id' => 1009, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [['id' => 208, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Sepa']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser($orders, 2025);

    $tx = Transaction::where('helloasso_order_id', 107)->first();
    expect($tx->mode_paiement)->toBe(ModePaiement::Prelevement);
});

it('splits order with multiple beneficiaries into separate transactions', function () {
    $tiers2 = Tiers::factory()->avecHelloasso()->create([
        'email' => 'marie@test.com',
        'nom' => 'Martin',
        'prenom' => 'Marie',
    ]);

    $orders = [
        [
            'id' => 108,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 6000,
            'formSlug' => 'adhesion-2025',
            'formType' => 'Membership',
            'items' => [
                [
                    'id' => 1010, 'amount' => 3000, 'state' => 'Processed', 'type' => 'Membership', 'name' => 'Adhésion Jean',
                    'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont'],
                ],
                [
                    'id' => 1011, 'amount' => 3000, 'state' => 'Processed', 'type' => 'Membership', 'name' => 'Adhésion Marie',
                    'user' => ['firstName' => 'Marie', 'lastName' => 'Martin'],
                ],
            ],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [['id' => 209, 'amount' => 6000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->transactionsCreated)->toBe(2);
    expect(Transaction::where('helloasso_order_id', 108)->count())->toBe(2);
    expect(Transaction::where('helloasso_order_id', 108)->where('tiers_id', $this->tiers->id)->exists())->toBeTrue();
    expect(Transaction::where('helloasso_order_id', 108)->where('tiers_id', $tiers2->id)->exists())->toBeTrue();
});

it('imports zero-amount items normally', function () {
    $orders = [
        [
            'id' => 109,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 0,
            'formSlug' => 'dons-libres',
            'formType' => 'Donation',
            'items' => [
                ['id' => 1012, 'amount' => 0, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don libre'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [['id' => 210, 'amount' => 0, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->transactionsCreated)->toBe(1);
    $ligne = TransactionLigne::where('helloasso_item_id', 1012)->first();
    expect((float) $ligne->montant)->toBe(0.00);
});

it('defaults to cb for orders with empty payments array', function () {
    $orders = [
        [
            'id' => 110,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 5000,
            'formSlug' => 'dons-libres',
            'formType' => 'Donation',
            'items' => [
                ['id' => 1013, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser($orders, 2025);

    $tx = Transaction::where('helloasso_order_id', 110)->first();
    expect($tx->mode_paiement)->toBe(ModePaiement::Cb);
});
