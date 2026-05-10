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
use App\Services\HelloAssoSyncService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Http;

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
});

it('sync agrège item.amount + options.amount (régression HA-55698)', function (): void {
    // Reproduit exactement HA-55698 : Cotisation 35€ + discount 35€ + option 12€
    // → item.amount=0, options=1200c, payment=12€. La ligne doit valoir 12€.
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
    expect((float) $tx->montant_total)->toBe(12.00); // payment réel
    expect($tx->lignes()->count())->toBe(1);

    $ligne = $tx->lignes()->first();
    expect((float) $ligne->montant)->toBe(12.00); // item.amount (0) + options.amount (12)
    expect((int) $ligne->helloasso_item_id)->toBe(87070);
});

it('sync sans options garde item.amount tel quel (non-régression)', function (): void {
    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Membership/un-an-glissant/public' => Http::response([
            'formSlug' => 'un-an-glissant',
            'tiers' => [['id' => 18595, 'label' => 'Cotisation', 'price' => 3500]],
        ]),
    ]);

    $order = [
        'id' => 86449,
        'date' => '2025-10-16T10:00:00Z',
        'formSlug' => 'un-an-glissant',
        'formType' => 'Membership',
        'payments' => [['id' => 55699, 'amount' => 3500, 'paymentMeans' => 'Card']],
        'user' => null,
        'payer' => ['firstName' => 'Anne', 'lastName' => 'VAN DER HOEVEN'],
        'items' => [
            ['id' => 87071, 'amount' => 3500, 'type' => 'Membership', 'tierId' => 18595, 'user' => ['firstName' => 'Anne', 'lastName' => 'VAN DER HOEVEN']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);

    $ligne = Transaction::first()->lignes()->first();
    expect((float) $ligne->montant)->toBe(35.00);
});
