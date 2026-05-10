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
    $association = Association::firstOrCreate(['id' => 1], [
        'nom' => 'Asso test',
        'slug' => 'test-asso',
    ]);
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

    $this->sc = $sc;
    $this->tiers = Tiers::factory()->create([
        'helloasso_nom' => 'DUPONT',
        'helloasso_prenom' => 'Jean',
        'est_helloasso' => true,
    ]);

    $this->formMapping = HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'cotisation-2025',
        'form_type' => 'Membership',
        'form_title' => 'Cotisation 2025',
        'sous_categorie_id' => $sc->id,
    ]);
});

function buildOrder3d(string $formSlug, int $tierId, int $itemId): array
{
    return [
        'id' => 9001,
        'date' => '2025-10-15T10:00:00Z',
        'formSlug' => $formSlug,
        'formType' => 'Membership',
        'payments' => [['id' => 7777, 'paymentMeans' => 'Card']],
        'user' => null,
        'payer' => ['firstName' => 'Jean', 'lastName' => 'DUPONT'],
        'items' => [
            [
                'id' => $itemId,
                'amount' => 5000,
                'type' => 'Membership',
                'tierId' => $tierId,
                'user' => ['firstName' => 'Jean', 'lastName' => 'DUPONT'],
            ],
        ],
    ];
}

it('sync auto-crée une formule HelloAsso (mode durée pour MovingYear)', function (): void {
    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Membership/cotisation-2025/public' => Http::response([
            'formSlug' => 'cotisation-2025',
            'formType' => 'Membership',
            'validityType' => 'MovingYear',
            'tiers' => [
                ['id' => 1, 'label' => 'Adulte', 'price' => 5000, 'isEligibleTaxReceipt' => false],
            ],
        ]),
    ]);

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([buildOrder3d('cotisation-2025', 1, 1234)], 2025);

    $formule = FormuleAdhesion::first();
    expect($formule->est_helloasso)->toBeTrue();
    expect($formule->helloasso_form_slug)->toBe('cotisation-2025');
    expect($formule->helloasso_tier_id)->toBe(1);
    expect($formule->mode)->toBe('duree');
    expect($formule->duree_mois)->toBe(12);
    expect((float) $formule->montant_par_defaut)->toBe(50.00);

    $adhesion = Adhesion::first();
    expect($adhesion->formule_adhesion_id)->toBe($formule->id);
    expect($adhesion->mode)->toBe('duree');
    expect((float) $adhesion->montant_facial)->toBe(50.00);
});

it('sync mode illimite (validity_type Illimited)', function (): void {
    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Membership/cotisation-2025/public' => Http::response([
            'formSlug' => 'cotisation-2025',
            'validityType' => 'Illimited',
            'tiers' => [
                ['id' => 1, 'label' => 'Membre à vie', 'price' => 10000, 'isEligibleTaxReceipt' => true],
            ],
        ]),
    ]);

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([buildOrder3d('cotisation-2025', 1, 1234)], 2025);

    $formule = FormuleAdhesion::first();
    expect($formule->mode)->toBe('illimite');
    expect($formule->duree_mois)->toBeNull();
    expect($formule->deductible_fiscal)->toBeTrue();

    $adhesion = Adhesion::first();
    expect($adhesion->mode)->toBe('illimite');
    expect($adhesion->date_fin)->toBeNull();
});

it('sync pose imported_at à la 1re importation', function (): void {
    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Membership/cotisation-2025/public' => Http::response([
            'formSlug' => 'cotisation-2025',
            'validityType' => 'Custom',
            'tiers' => [['id' => 1, 'label' => 'Adulte', 'price' => 3000]],
        ]),
    ]);

    expect($this->formMapping->fresh()->imported_at)->toBeNull();

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([buildOrder3d('cotisation-2025', 1, 1234)], 2025);

    expect($this->formMapping->fresh()->imported_at)->not->toBeNull();
});
