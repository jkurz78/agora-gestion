<?php

declare(strict_types=1);

use App\Models\HelloAssoParametres;
use App\Services\HelloAssoApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('association')->insert(['id' => 1, 'nom' => 'Mon Asso', 'created_at' => now(), 'updated_at' => now()]);

    $this->parametres = HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test-client-id',
        'client_secret' => 'test-secret',
        'organisation_slug' => 'mon-asso',
        'environnement' => 'sandbox',
    ]);
});

it('fetches orders with pagination', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'id' => 1,
                        'date' => '2025-10-15T10:00:00+02:00',
                        'amount' => 5000,
                        'formSlug' => 'adhesion',
                        'formType' => 'Membership',
                        'items' => [['id' => 101, 'amount' => 5000, 'state' => 'Processed', 'tierType' => 'Membership']],
                        'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
                        'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
                        'payments' => [['id' => 201, 'amount' => 5000, 'paymentMeans' => 'Card', 'cashOutState' => 'CashedOut']],
                    ],
                ],
                'pagination' => ['continuationToken' => 'token-page2', 'pageSize' => 20],
            ])
            ->push([
                'data' => [],
                'pagination' => ['continuationToken' => null, 'pageSize' => 20],
            ]),
    ]);

    $client = new HelloAssoApiClient($this->parametres);
    $orders = $client->fetchOrders('2025-09-01', '2026-08-31');

    expect($orders)->toHaveCount(1);
    expect($orders[0]['id'])->toBe(1);
    expect($orders[0]['user']['email'])->toBe('jean@test.com');
});

it('fetches multiple pages of orders', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push([
                'data' => [['id' => 1, 'amount' => 1000, 'items' => [], 'user' => null, 'payer' => ['firstName' => 'A', 'lastName' => 'B', 'email' => 'a@b.com'], 'payments' => []]],
                'pagination' => ['continuationToken' => 'page2'],
            ])
            ->push([
                'data' => [['id' => 2, 'amount' => 2000, 'items' => [], 'user' => null, 'payer' => ['firstName' => 'C', 'lastName' => 'D', 'email' => 'c@d.com'], 'payments' => []]],
                'pagination' => ['continuationToken' => 'page3'],
            ])
            ->push([
                'data' => [],
                'pagination' => [],
            ]),
    ]);

    $client = new HelloAssoApiClient($this->parametres);
    $orders = $client->fetchOrders('2025-09-01', '2026-08-31');

    expect($orders)->toHaveCount(2);
});

it('fetches organization forms', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/forms*' => Http::sequence()
            ->push([
                'data' => [
                    ['formSlug' => 'adhesion-2025', 'formType' => 'Membership', 'title' => 'Adhésion 2025', 'state' => 'Public'],
                    ['formSlug' => 'dons-libres', 'formType' => 'Donation', 'title' => 'Dons libres', 'state' => 'Public'],
                ],
                'pagination' => [],
            ])
            ->push(['data' => [], 'pagination' => []]),
    ]);

    $client = new HelloAssoApiClient($this->parametres);
    $forms = $client->fetchForms();

    expect($forms)->toHaveCount(2);
    expect($forms[0]['formSlug'])->toBe('adhesion-2025');
});

it('extracts cash-outs from payments by grouping by idCashOut', function () {
    $payments = [
        ['id' => 101, 'amount' => 3000, 'idCashOut' => 500, 'cashOutDate' => '2025-10-20T10:00:00+02:00', 'cashOutState' => 'CashedOut'],
        ['id' => 102, 'amount' => 2000, 'idCashOut' => 500, 'cashOutDate' => '2025-10-20T10:00:00+02:00', 'cashOutState' => 'CashedOut'],
        ['id' => 103, 'amount' => 1500, 'idCashOut' => 501, 'cashOutDate' => '2025-10-25T10:00:00+02:00', 'cashOutState' => 'CashedOut'],
        ['id' => 104, 'amount' => 1000, 'idCashOut' => null, 'cashOutState' => 'MoneyIn'],
    ];

    $cashOuts = HelloAssoApiClient::extractCashOutsFromPayments($payments);

    expect($cashOuts)->toHaveCount(2);

    $co500 = collect($cashOuts)->firstWhere('id', 500);
    expect($co500['amount'])->toBe(5000);
    expect($co500['payments'])->toHaveCount(2);

    $co501 = collect($cashOuts)->firstWhere('id', 501);
    expect($co501['amount'])->toBe(1500);
    expect($co501['payments'])->toHaveCount(1);
});

it('ignores non-CashedOut payments when extracting cash-outs', function () {
    $payments = [
        ['id' => 101, 'amount' => 3000, 'idCashOut' => 500, 'cashOutDate' => '2025-10-20', 'cashOutState' => 'TransferInProgress'],
        ['id' => 102, 'amount' => 2000, 'idCashOut' => 500, 'cashOutDate' => '2025-10-20', 'cashOutState' => 'CashedOut'],
    ];

    $cashOuts = HelloAssoApiClient::extractCashOutsFromPayments($payments);

    expect($cashOuts)->toHaveCount(1);
    expect($cashOuts[0]['amount'])->toBe(2000);
    expect($cashOuts[0]['payments'])->toHaveCount(1);
});

it('throws on authentication failure', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    $client = new HelloAssoApiClient($this->parametres);
    $client->fetchOrders('2025-09-01', '2026-08-31');
})->throws(RuntimeException::class, 'Authentification HelloAsso échouée');
