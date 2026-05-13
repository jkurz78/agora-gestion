<?php

declare(strict_types=1);

use App\Enums\HelloAssoEnvironnement;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\HelloAssoSyncService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $association = Association::firstOrCreate(['id' => 1], [
        'nom' => 'Asso test',
        'slug' => 'test-asso',
    ]);
    TenantContext::boot($association);

    $compte = CompteBancaire::factory()->create();
    $this->sc = SousCategorie::factory()->pourCotisations()->create();
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
});

function buildOrderIgnore(string $formSlug = 'cotisation-2025'): array
{
    return [
        'id' => 9001,
        'date' => '2025-10-15T10:00:00Z',
        'formSlug' => $formSlug,
        'formType' => 'Membership',
        'payments' => [['id' => 7777, 'paymentMeans' => 'Card']],
        'user' => null,
        'payer' => ['firstName' => 'Jean', 'lastName' => 'DUPONT'],
        'items' => [['id' => 1234, 'amount' => 3000, 'type' => 'Membership', 'tierId' => 1, 'user' => ['firstName' => 'Jean', 'lastName' => 'DUPONT']]],
    ];
}

it('skip un form en état ignore', function (): void {
    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'cotisation-2025',
        'form_type' => 'Membership',
        'form_title' => 'Cotisation 2025',
        'sous_categorie_id' => $this->sc->id,
        'ignore' => true,
    ]);

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([buildOrderIgnore()], 2025);

    expect(Transaction::count())->toBe(0);
    expect(Adhesion::count())->toBe(0);
});

it('skip un form Membership sans sous_categorie_id', function (): void {
    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'cotisation-2025',
        'form_type' => 'Membership',
        'form_title' => 'Cotisation 2025',
        'sous_categorie_id' => null,
    ]);

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([buildOrderIgnore()], 2025);

    expect(Transaction::count())->toBe(0);
});
