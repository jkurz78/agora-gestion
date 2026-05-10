<?php

declare(strict_types=1);

use App\Enums\HelloAssoEnvironnement;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TypeOperation;
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
    $this->scDon = SousCategorie::factory()->pourDons()->create();

    $this->parametres = HelloAssoParametres::factory()->create([
        'association_id' => 1,
        'environnement' => HelloAssoEnvironnement::Sandbox,
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'organisation_slug' => 'mon-asso',
        'compte_helloasso_id' => $compte->id,
        'compte_versement_id' => $compte->id,
        'sous_categorie_don_id' => $this->scDon->id,
    ]);

    $this->tiers = Tiers::factory()->create([
        'helloasso_nom' => 'DUPONT',
        'helloasso_prenom' => 'Jean',
        'est_helloasso' => true,
    ]);

    $this->formMembership = HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'cotisation-2025',
        'form_type' => 'Membership',
        'form_title' => 'Cotisation 2025',
        'sous_categorie_id' => $this->scCotisation->id,
    ]);
});

it('un don additionnel dans un order Membership tombe dans la sous-cat fallback Don', function (): void {
    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Membership/cotisation-2025/public' => Http::response([
            'formSlug' => 'cotisation-2025',
            'formType' => 'Membership',
            'validityType' => 'Custom',
            'startDate' => '2025-09-01',
            'endDate' => '2026-08-31',
            'tiers' => [
                ['id' => 1, 'label' => 'Adulte', 'price' => 2500, 'isEligibleTaxReceipt' => false],
            ],
        ]),
    ]);

    $order = [
        'id' => 9001,
        'date' => '2025-10-15T10:00:00Z',
        'formSlug' => 'cotisation-2025',
        'formType' => 'Membership',
        'payments' => [['id' => 7777, 'paymentMeans' => 'Card']],
        'user' => null,
        'payer' => ['firstName' => 'Jean', 'lastName' => 'DUPONT'],
        'items' => [
            ['id' => 1234, 'amount' => 2500, 'type' => 'Membership', 'tierId' => 1, 'user' => ['firstName' => 'Jean', 'lastName' => 'DUPONT']],
            ['id' => 1235, 'amount' => 1000, 'type' => 'Donation', 'user' => ['firstName' => 'Jean', 'lastName' => 'DUPONT']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);

    $tx = Transaction::first();
    expect($tx->lignes()->count())->toBe(2);

    $ligneCotisation = $tx->lignes()->where('helloasso_item_id', 1234)->first();
    expect((int) $ligneCotisation->sous_categorie_id)->toBe((int) $this->scCotisation->id);

    $ligneDon = $tx->lignes()->where('helloasso_item_id', 1235)->first();
    expect((int) $ligneDon->sous_categorie_id)->toBe((int) $this->scDon->id);
});

it('un don additionnel dans un order Event tombe dans la sous-cat fallback Don (pas formation) tout en restant rattaché à l\'opération pour la traçabilité', function (): void {
    // Reproduit le bug observé sur HA-52045 : un don de 20€ joint à une inscription
    // formation tombait dans la sous-cat de l'opération (formation), alors qu'il
    // devrait suivre la sous-cat fallback Don. La catégorisation fiscale est portée
    // par sous_categorie_id ; operation_id reste setté pour conserver la traçabilité
    // (le don est venu via cette opération).
    $scFormation = SousCategorie::factory()->create();
    $typeFormation = TypeOperation::factory()->create(['sous_categorie_id' => $scFormation->id]);
    $operation = Operation::factory()->create(['type_operation_id' => $typeFormation->id]);

    $formEvent = HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'stage-reiki',
        'form_type' => 'Event',
        'form_title' => 'Stage Reiki',
        'operation_id' => $operation->id,
    ]);

    $order = [
        'id' => 52045,
        'date' => '2025-10-15T10:00:00Z',
        'formSlug' => 'stage-reiki',
        'formType' => 'Event',
        'payments' => [['id' => 8001, 'paymentMeans' => 'Card']],
        'user' => null,
        'payer' => ['firstName' => 'Jean', 'lastName' => 'DUPONT'],
        'items' => [
            ['id' => 2001, 'amount' => 8000, 'type' => 'Registration', 'user' => ['firstName' => 'Jean', 'lastName' => 'DUPONT']],
            ['id' => 2002, 'amount' => 2000, 'type' => 'Donation', 'user' => ['firstName' => 'Jean', 'lastName' => 'DUPONT']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);

    $tx = Transaction::first();
    expect($tx->lignes()->count())->toBe(2);

    $ligneInscription = $tx->lignes()->where('helloasso_item_id', 2001)->first();
    expect((int) $ligneInscription->sous_categorie_id)->toBe((int) $scFormation->id)
        ->and((int) $ligneInscription->operation_id)->toBe((int) $operation->id);

    $ligneDon = $tx->lignes()->where('helloasso_item_id', 2002)->first();
    expect((int) $ligneDon->sous_categorie_id)->toBe((int) $this->scDon->id) // fallback Don
        ->and((int) $ligneDon->operation_id)->toBe((int) $operation->id); // traçabilité conservée
});

it('échoue si fallback Don non configuré et un don additionnel apparaît sans sous-cat form', function (): void {
    $this->parametres->update(['sous_categorie_don_id' => null]);

    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Membership/cotisation-2025/public' => Http::response([
            'formSlug' => 'cotisation-2025',
            'tiers' => [['id' => 1, 'label' => 'Adulte', 'price' => 2500]],
        ]),
    ]);

    // Order avec uniquement un item Donation (pas de Membership)
    // → le form_mapping.sous_categorie_id (Cotisations) ne s'applique pas au Donation
    // et sous_categorie_don_id est null → exception
    $order = [
        'id' => 9002,
        'date' => '2025-10-15T10:00:00Z',
        'formSlug' => 'cotisation-2025',
        'formType' => 'Membership',
        'payments' => [['id' => 7778, 'paymentMeans' => 'Card']],
        'user' => null,
        'payer' => ['firstName' => 'Jean', 'lastName' => 'DUPONT'],
        'items' => [
            ['id' => 1235, 'amount' => 1000, 'type' => 'Donation', 'user' => ['firstName' => 'Jean', 'lastName' => 'DUPONT']],
        ],
    ];

    // Changer la sous_categorie_id du form mapping à null pour que le fallback final échoue aussi
    $this->formMembership->update(['sous_categorie_id' => null]);

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser([$order], 2025);

    // L'order est skipped car sous_categorie_id = null sur le form ET pas de fallback don
    expect(Transaction::count())->toBe(0);
});
