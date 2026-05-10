<?php

declare(strict_types=1);

use App\Enums\HelloAssoEnvironnement;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\FormuleAdhesion;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\HelloAssoSyncService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $association = Association::firstOrCreate(['id' => 1], ['nom' => 'Asso test', 'slug' => 'test-asso']);
    TenantContext::boot($association);

    $compte = CompteBancaire::factory()->create();
    $sc = SousCategorie::factory()->pourCotisations()->create();
    $this->parametres = HelloAssoParametres::factory()->create([
        'association_id' => 1,
        'environnement' => HelloAssoEnvironnement::Sandbox,
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'organisation_slug' => 'mon-asso',
        'compte_helloasso_id' => $compte->id,
        'compte_versement_id' => $compte->id,
    ]);

    $this->tiers = Tiers::factory()->create([
        'helloasso_nom' => 'DUPONT',
        'helloasso_prenom' => 'Jean',
        'est_helloasso' => true,
    ]);

    $this->formMapping = HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'cotisation-custom',
        'form_type' => 'Membership',
        'form_title' => 'Cotisation Custom',
        'sous_categorie_id' => $sc->id,
    ]);
});

it('Custom utilise les dates du form HelloAsso (pas l\'exercice asso)', function (): void {
    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Membership/cotisation-custom' => Http::response([
            'formSlug' => 'cotisation-custom',
            'validityType' => 'Custom',
            'startDate' => '2025-03-01',
            'endDate' => '2026-02-28',
            'tiers' => [['id' => 1, 'label' => 'Adulte', 'price' => 3000, 'isEligibleTaxReceipt' => false]],
        ]),
    ]);

    $order = [
        'id' => 9001,
        'date' => '2025-04-15T10:00:00Z',
        'formSlug' => 'cotisation-custom',
        'formType' => 'Membership',
        'payments' => [['id' => 7777, 'paymentMeans' => 'Card']],
        'user' => null,
        'payer' => ['firstName' => 'Jean', 'lastName' => 'DUPONT'],
        'items' => [['id' => 1234, 'amount' => 3000, 'type' => 'Membership', 'tierId' => 1, 'user' => ['firstName' => 'Jean', 'lastName' => 'DUPONT']]],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);

    $formule = FormuleAdhesion::first();
    expect($formule->mode)->toBe('duree'); // Custom → mode=duree avec dates
    expect($formule->helloasso_start_date?->toDateString())->toBe('2025-03-01');
    expect($formule->helloasso_end_date?->toDateString())->toBe('2026-02-28');

    $adhesion = Adhesion::first();
    expect($adhesion->date_debut?->toDateString())->toBe('2025-03-01');
    expect($adhesion->date_fin?->toDateString())->toBe('2026-02-28');
    expect($adhesion->exercice)->toBeNull();
});
