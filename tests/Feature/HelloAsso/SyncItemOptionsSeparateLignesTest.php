<?php

declare(strict_types=1);

use App\Enums\HelloAssoEnvironnement;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\HelloAssoSyncService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $association = Association::firstOrCreate(['id' => 1], [
        'nom' => 'Asso test',
        'slug' => 'test-asso',
    ]);
    TenantContext::boot($association);

    $compte = CompteBancaire::factory()->create();
    $this->scCotisation = SousCategorie::factory()->pourCotisations()->create();

    $this->parametres = HelloAssoParametres::factory()->create([
        'association_id' => 1,
        'environnement' => HelloAssoEnvironnement::Sandbox,
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'organisation_slug' => 'mon-asso',
        'compte_helloasso_id' => $compte->id,
        'compte_versement_id' => $compte->id,
    ]);

    Tiers::factory()->create([
        'helloasso_nom' => 'VAN DER HOEVEN',
        'helloasso_prenom' => 'Anne',
        'est_helloasso' => true,
    ]);

    $this->formMembership = HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'un-an-glissant',
        'form_type' => 'Membership',
        'form_title' => 'Un an glissant',
        'sous_categorie_id' => $this->scCotisation->id,
    ]);

    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Membership/un-an-glissant/public' => Http::response([
            'formSlug' => 'un-an-glissant',
            'formType' => 'Membership',
            'validityType' => 'MovingYear',
            'tiers' => [
                ['id' => 18595, 'label' => 'Cotisation payante', 'price' => 3500, 'isEligibleTaxReceipt' => false],
            ],
        ]),
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('split HA-55698 : 1 item + 1 option → 2 lignes (cotisation 0€ + option 12€)', function (): void {
    $order = [
        'id' => 86448,
        'date' => '2025-10-15T10:00:00Z',
        'formSlug' => 'un-an-glissant',
        'formType' => 'Membership',
        'payments' => [['id' => 55698, 'amount' => 1200, 'paymentMeans' => 'Card']],
        'amount' => ['total' => 1200, 'discount' => 3500, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Anne', 'lastName' => 'VAN DER HOEVEN'],
        'items' => [
            [
                'id' => 87070,
                'amount' => 0,
                'initialAmount' => 3500,
                'type' => 'Membership',
                'tierId' => 18595,
                'name' => 'Cotisation payante',
                'discount' => ['code' => '2026 : -35,00€', 'amount' => 3500],
                'options' => [
                    ['name' => 'option assurante', 'amount' => 1200, 'priceCategory' => 'Fixed', 'isRequired' => false, 'optionId' => 18596],
                ],
                'user' => ['firstName' => 'Anne', 'lastName' => 'VAN DER HOEVEN'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);

    $tx = Transaction::first();
    expect($tx)->not->toBeNull();
    expect((float) $tx->montant_total)->toBe(12.00);
    expect($tx->lignes()->count())->toBe(2);

    $lignParent = $tx->lignes()->whereNull('helloasso_option_id')->first();
    expect($lignParent)->not->toBeNull();
    expect((int) $lignParent->helloasso_item_id)->toBe(87070);
    expect((float) $lignParent->montant)->toBe(0.00);
    expect($lignParent->helloasso_option_id)->toBeNull();
    expect((int) $lignParent->helloasso_tier_id)->toBe(18595);
    expect($lignParent->notes)->toContain('offerte');
    expect($lignParent->notes)->toContain('2026 : -35,00€');

    $ligneOption = $tx->lignes()->whereNotNull('helloasso_option_id')->first();
    expect($ligneOption)->not->toBeNull();
    expect((int) $ligneOption->helloasso_item_id)->toBe(87070);
    expect((int) $ligneOption->helloasso_option_id)->toBe(18596);
    expect((float) $ligneOption->montant)->toBe(12.00);
    expect($ligneOption->notes)->toContain('option assurante');
});

it('1 item + 2 options → 3 lignes (1 parent + 2 options)', function (): void {
    $order = [
        'id' => 86449,
        'date' => '2025-10-16T10:00:00Z',
        'formSlug' => 'un-an-glissant',
        'formType' => 'Membership',
        'payments' => [['id' => 55699, 'amount' => 2700, 'paymentMeans' => 'Card']],
        'amount' => ['total' => 2700, 'discount' => 0, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Anne', 'lastName' => 'VAN DER HOEVEN'],
        'items' => [
            [
                'id' => 87071,
                'amount' => 500,
                'type' => 'Membership',
                'tierId' => 18595,
                'name' => 'Cotisation',
                'options' => [
                    ['name' => 'option A', 'amount' => 1200, 'priceCategory' => 'Fixed', 'isRequired' => false, 'optionId' => 18600],
                    ['name' => 'option B', 'amount' => 1000, 'priceCategory' => 'Fixed', 'isRequired' => false, 'optionId' => 18601],
                ],
                'user' => ['firstName' => 'Anne', 'lastName' => 'VAN DER HOEVEN'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);

    $tx = Transaction::first();
    expect($tx->lignes()->count())->toBe(3);

    $parent = $tx->lignes()->whereNull('helloasso_option_id')->first();
    expect((float) $parent->montant)->toBe(5.00);
    expect($parent->notes)->toBeNull();

    $optionA = $tx->lignes()->where('helloasso_option_id', 18600)->first();
    expect((float) $optionA->montant)->toBe(12.00);
    expect($optionA->notes)->toContain('option A');

    $optionB = $tx->lignes()->where('helloasso_option_id', 18601)->first();
    expect((float) $optionB->montant)->toBe(10.00);
    expect($optionB->notes)->toContain('option B');
});

it('1 item sans options → 1 ligne parent uniquement (non-régression)', function (): void {
    $order = [
        'id' => 86450,
        'date' => '2025-10-17T10:00:00Z',
        'formSlug' => 'un-an-glissant',
        'formType' => 'Membership',
        'payments' => [['id' => 55700, 'amount' => 3500, 'paymentMeans' => 'Card']],
        'amount' => ['total' => 3500, 'discount' => 0, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Anne', 'lastName' => 'VAN DER HOEVEN'],
        'items' => [
            [
                'id' => 87072,
                'amount' => 3500,
                'type' => 'Membership',
                'tierId' => 18595,
                'name' => 'Cotisation',
                'user' => ['firstName' => 'Anne', 'lastName' => 'VAN DER HOEVEN'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);

    $tx = Transaction::first();
    expect($tx->lignes()->count())->toBe(1);

    $ligne = $tx->lignes()->first();
    expect((float) $ligne->montant)->toBe(35.00);
    expect($ligne->helloasso_option_id)->toBeNull();
    expect($ligne->notes)->toBeNull();
});

it('idempotence re-sync : counts inchangés après deuxième synchronisation', function (): void {
    $order = [
        'id' => 86448,
        'date' => '2025-10-15T10:00:00Z',
        'formSlug' => 'un-an-glissant',
        'formType' => 'Membership',
        'payments' => [['id' => 55698, 'amount' => 1200, 'paymentMeans' => 'Card']],
        'amount' => ['total' => 1200, 'discount' => 3500, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Anne', 'lastName' => 'VAN DER HOEVEN'],
        'items' => [
            [
                'id' => 87070,
                'amount' => 0,
                'initialAmount' => 3500,
                'type' => 'Membership',
                'tierId' => 18595,
                'name' => 'Cotisation payante',
                'discount' => ['code' => '2026 : -35,00€', 'amount' => 3500],
                'options' => [
                    ['name' => 'option assurante', 'amount' => 1200, 'priceCategory' => 'Fixed', 'isRequired' => false, 'optionId' => 18596],
                ],
                'user' => ['firstName' => 'Anne', 'lastName' => 'VAN DER HOEVEN'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);

    // Deuxième synchronisation
    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Membership/un-an-glissant/public' => Http::response([
            'formSlug' => 'un-an-glissant',
            'formType' => 'Membership',
            'validityType' => 'MovingYear',
            'tiers' => [
                ['id' => 18595, 'label' => 'Cotisation payante', 'price' => 3500, 'isEligibleTaxReceipt' => false],
            ],
        ]),
    ]);
    $service2 = new HelloAssoSyncService($this->parametres->fresh());
    $service2->synchroniser([$order], 2025);

    expect(Transaction::count())->toBe(1);
    expect(TransactionLigne::count())->toBe(2);
});
