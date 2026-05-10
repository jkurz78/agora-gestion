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

it('split HA-55698 : 1 item + 1 option → 2 lignes séparées (cotisation 0€ + option 12€)', function (): void {
    // B1 : l'agrégation est remplacée par le split — la cotisation 0€ et l'option 12€
    // sont des lignes distinctes. Le montant_total de la tx = 12€ (somme des lignes).
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
    expect((float) $tx->montant_total)->toBe(12.00); // somme des 2 lignes
    expect($tx->lignes()->count())->toBe(2);

    $parent = $tx->lignes()->whereNull('helloasso_option_id')->first();
    expect((float) $parent->montant)->toBe(0.00); // item.amount = 0 (discount total)
    expect((int) $parent->helloasso_item_id)->toBe(87070);

    $option = $tx->lignes()->whereNotNull('helloasso_option_id')->first();
    expect((float) $option->montant)->toBe(12.00); // option.amount = 1200c
    expect((int) $option->helloasso_option_id)->toBe(18596);
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
