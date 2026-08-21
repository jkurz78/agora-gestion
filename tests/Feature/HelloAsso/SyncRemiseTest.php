<?php

declare(strict_types=1);

/**
 * Tâche 9 (709A) — ventilation brute + ligne de remise.
 *
 * La synchro HelloAsso doit :
 *   1. porter la ligne parent au montant BRUT (item.initialAmount ?? item.amount),
 *      jamais au net — une commande entièrement remisée a item.amount = 0 et ne
 *      peut pas porter le produit (bug bloquant la clôture 2025-2026, tx #120) ;
 *   2. créer une ligne de remise au débit (compte usage Gratuite, ex. 709A) dès
 *      qu'un item porte discount.amount > 0, avec le même operation_id que la
 *      ligne parente et le code promo tracé sur helloasso_discount_code ;
 *   3. dériver le libellé de la remise du type réel de l'item, jamais figé sur
 *      « Cotisation ».
 *
 * Fixture de référence : transaction 120 en production — commande HelloAsso sur
 * un formulaire Event, item Registration entièrement couvert par le code promo
 * W6BGU5YY (-50 €).
 */

use App\Enums\HelloAssoEnvironnement;
use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Services\HelloAssoSyncService;
use App\Services\UsagesComptablesService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Http;

/*
 * NB : tests/Pest.php boote TenantContext globalement (Association::factory())
 * mais garde l'association dans une variable locale au closure — $this->association
 * n'existe pas ici. On s'appuie sur TenantContext::currentId() partout, comme le
 * font déjà les factories (association_id => TenantContext::currentId() ?? 1).
 */
beforeEach(function (): void {
    $compteBancaire = CompteBancaire::factory()->create();

    // Compte de ventilation "participation" (usage Inscription), classe 7 —
    // celui vers lequel le mapping de formulaire route les items Registration.
    $this->scParticipation = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '706EV',
        'intitule' => 'Participations événements',
        'classe' => 7,
        'actif' => true,
    ]);
    $this->scParticipation->usages()->create(['usage' => UsageComptable::Inscription->value]);

    // Compte de contrepartie des gratuités (709A) — posé via UsagesComptablesService,
    // exactement comme le ferait l'écran Paramètres → Comptabilité → Usages comptables.
    // firstOrCreate : la migration 2026_08_20_100000 crée déjà 709A pour toute
    // association, y compris celle du bootstrap de tests.
    $this->scGratuite = Compte::firstOrCreate(
        [
            'association_id' => TenantContext::currentId(),
            'numero_pcg' => '709A',
        ],
        [
            'intitule' => 'Gratuités accordées',
            'classe' => 7,
            'actif' => true,
        ],
    );
    app(UsagesComptablesService::class)->setGratuite($this->scGratuite->id);

    $this->typeOperation = TypeOperation::factory()->create(['compte_id' => $this->scParticipation->id]);
    $this->operation = Operation::factory()->create(['type_operation_id' => $this->typeOperation->id]);

    $this->parametres = HelloAssoParametres::factory()->create([
        'environnement' => HelloAssoEnvironnement::Sandbox,
        'organisation_slug' => 'mon-asso',
        'compte_helloasso_id' => $compteBancaire->id,
        'compte_versement_id' => $compteBancaire->id,
    ]);

    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'journee-sensibilisation',
        'form_type' => 'Event',
        'form_title' => 'Journée de sensibilisation',
        'operation_id' => $this->operation->id,
    ]);

    Tiers::factory()->create([
        'helloasso_nom' => 'SURPIN',
        'helloasso_prenom' => 'Charles',
        'est_helloasso' => true,
    ]);

    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Event/journee-sensibilisation/public' => Http::response([
            'formSlug' => 'journee-sensibilisation',
            'formType' => 'Event',
            'tiers' => [
                ['id' => 19069055, 'label' => 'Journée de sensibilisation', 'price' => 5000, 'isEligibleTaxReceipt' => false],
            ],
        ]),
    ]);
});

it('remise totale : ligne parent au brut, ligne de remise au débit, net à 0', function (): void {
    $order = [
        'id' => 178678765,
        'date' => '2026-04-08T10:00:00Z',
        'formSlug' => 'journee-sensibilisation',
        'formType' => 'Event',
        // Une commande entièrement remisée ne porte AUCUN paiement.
        'payments' => [],
        'amount' => ['total' => 0, 'discount' => 5000, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
        'items' => [
            [
                'id' => 178678765,
                'amount' => 0,
                'initialAmount' => 5000,
                'type' => 'Registration',
                'tierId' => 19069055,
                'name' => 'Journée de sensibilisation',
                'discount' => ['code' => 'W6BGU5YY', 'amount' => 5000],
                'user' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);

    $tx = Transaction::where('helloasso_order_id', 178678765)->first();
    expect($tx)->not->toBeNull();
    expect((float) $tx->montant_total)->toBe(0.00);

    $parent = TransactionLigne::where('helloasso_item_id', 178678765)
        ->where('helloasso_line_key', 'parent')->first();
    expect($parent)->not->toBeNull();
    expect((float) $parent->credit)->toBe(50.00);
    expect((float) $parent->debit)->toBe(0.00);
    expect($parent->compte_id)->not->toBeNull();
    expect((int) $parent->compte_id)->toBe((int) $this->scParticipation->id);

    $remise = TransactionLigne::where('helloasso_item_id', 178678765)
        ->where('helloasso_line_key', 'discount')->first();
    expect($remise)->not->toBeNull();
    expect((float) $remise->debit)->toBe(50.00);
    expect((float) $remise->credit)->toBe(0.00);
    expect((int) $remise->compte_id)->toBe((int) $this->scGratuite->id);
    expect($remise->helloasso_discount_code)->toBe('W6BGU5YY');
    expect($remise->operation_id)->toBe($parent->operation_id);
    // Libellé dérivé du type réel de l'item (Registration → Inscription), jamais
    // « Cotisation » figé — c'est exactement le bug de la transaction 120 en prod.
    expect($remise->notes)->toBe('Inscription offerte par code promo : W6BGU5YY');
});

it('remise partielle : montant_total reflète le net après remise', function (): void {
    $order = [
        'id' => 178678800,
        'date' => '2026-04-08T10:00:00Z',
        'formSlug' => 'journee-sensibilisation',
        'formType' => 'Event',
        'payments' => [['id' => 900800, 'amount' => 3000, 'paymentMeans' => 'Card']],
        'amount' => ['total' => 3000, 'discount' => 2000, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
        'items' => [
            [
                'id' => 178678801,
                'amount' => 3000,
                'initialAmount' => 5000,
                'type' => 'Registration',
                'tierId' => 19069055,
                'name' => 'Journée de sensibilisation',
                'discount' => ['code' => 'W6BGU5YY', 'amount' => 2000],
                'user' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);

    $tx = Transaction::where('helloasso_order_id', 178678800)->first();
    expect($tx)->not->toBeNull();
    expect((float) $tx->montant_total)->toBe(30.00);

    $parent = TransactionLigne::where('helloasso_item_id', 178678801)
        ->where('helloasso_line_key', 'parent')->first();
    expect((float) $parent->credit)->toBe(50.00); // brut (initialAmount), pas le net 3000

    $remise = TransactionLigne::where('helloasso_item_id', 178678801)
        ->where('helloasso_line_key', 'discount')->first();
    expect((float) $remise->debit)->toBe(20.00);
});

it('HA-55698 non-régression : remise totale + option, montant_total = option seule', function (): void {
    $order = [
        'id' => 178678900,
        'date' => '2026-04-08T10:00:00Z',
        'formSlug' => 'journee-sensibilisation',
        'formType' => 'Event',
        'payments' => [['id' => 900900, 'amount' => 1200, 'paymentMeans' => 'Card']],
        'amount' => ['total' => 1200, 'discount' => 3500, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
        'items' => [
            [
                'id' => 178678901,
                'amount' => 0,
                'initialAmount' => 3500,
                'type' => 'Registration',
                'tierId' => 19069055,
                'name' => 'Journée de sensibilisation',
                'discount' => ['code' => 'W6BGU5YY', 'amount' => 3500],
                'options' => [
                    ['name' => 'Assurance annulation', 'amount' => 1200, 'priceCategory' => 'Fixed', 'isRequired' => false, 'optionId' => 990001],
                ],
                'user' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);

    $tx = Transaction::where('helloasso_order_id', 178678900)->first();
    expect($tx)->not->toBeNull();
    expect((float) $tx->montant_total)->toBe(12.00);
    expect(TransactionLigne::where('helloasso_item_id', 178678901)->count())->toBe(3);
});

it('commande sans remise : pas de ligne discount, montant_total = amount (fallback)', function (): void {
    $order = [
        'id' => 178679100,
        'date' => '2026-04-08T10:00:00Z',
        'formSlug' => 'journee-sensibilisation',
        'formType' => 'Event',
        'payments' => [['id' => 900910, 'amount' => 3500, 'paymentMeans' => 'Card']],
        'amount' => ['total' => 3500, 'discount' => 0, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
        'items' => [
            [
                'id' => 178679101,
                'amount' => 3500,
                'type' => 'Registration',
                'tierId' => 19069055,
                'name' => 'Journée de sensibilisation',
                'user' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);

    $tx = Transaction::where('helloasso_order_id', 178679100)->first();
    expect($tx)->not->toBeNull();
    expect((float) $tx->montant_total)->toBe(35.00);

    $parent = TransactionLigne::where('helloasso_item_id', 178679101)
        ->where('helloasso_line_key', 'parent')->first();
    expect((float) $parent->credit)->toBe(35.00); // amount + 0 remise = brut

    expect(TransactionLigne::where('helloasso_item_id', 178679101)
        ->where('helloasso_line_key', 'discount')->exists())->toBeFalse();
});

it('deux bénéficiaires dont un item remisé : l\'invariant est au niveau de la commande', function (): void {
    Tiers::factory()->create([
        'helloasso_nom' => 'DUBOIS',
        'helloasso_prenom' => 'Alice',
        'est_helloasso' => true,
    ]);

    $order = [
        'id' => 178679200,
        'date' => '2026-04-08T10:00:00Z',
        'formSlug' => 'journee-sensibilisation',
        'formType' => 'Event',
        'payments' => [['id' => 900920, 'amount' => 5000, 'paymentMeans' => 'Card']],
        'amount' => ['total' => 5000, 'discount' => 5000, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
        'items' => [
            [
                'id' => 178679201,
                'amount' => 0,
                'initialAmount' => 5000,
                'type' => 'Registration',
                'tierId' => 19069055,
                'name' => 'Journée de sensibilisation',
                'discount' => ['code' => 'W6BGU5YY', 'amount' => 5000],
                'user' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
            ],
            [
                'id' => 178679202,
                'amount' => 5000,
                'type' => 'Registration',
                'tierId' => 19069055,
                'name' => 'Journée de sensibilisation',
                'user' => ['firstName' => 'Alice', 'lastName' => 'DUBOIS'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);

    // groupItemsByBeneficiary sépare les 2 items (users distincts) en 2 transactions.
    $transactions = Transaction::where('helloasso_order_id', 178679200)->get();
    expect($transactions)->toHaveCount(2);

    // L'invariant vit au niveau de la COMMANDE (Σ des transactions), pas d'une
    // transaction isolée — l'item remisé de Charles a un net de 0, celui d'Alice
    // porte le net réel (50,00 €). order.amount.total / 100 = 50,00 €.
    $sommeCommande = (float) $transactions->sum('montant_total');
    expect($sommeCommande)->toBe(50.00);
});

it('idempotence : deux synchronisations consécutives ne créent qu\'une seule ligne de remise', function (): void {
    $order = [
        'id' => 178679300,
        'date' => '2026-04-08T10:00:00Z',
        'formSlug' => 'journee-sensibilisation',
        'formType' => 'Event',
        'payments' => [],
        'amount' => ['total' => 0, 'discount' => 5000, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
        'items' => [
            [
                'id' => 178679301,
                'amount' => 0,
                'initialAmount' => 5000,
                'type' => 'Registration',
                'tierId' => 19069055,
                'name' => 'Journée de sensibilisation',
                'discount' => ['code' => 'W6BGU5YY', 'amount' => 5000],
                'user' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);
    $service->synchroniser([$order], 2025);

    expect(TransactionLigne::where('helloasso_item_id', 178679301)
        ->where('helloasso_line_key', 'discount')->count())->toBe(1);
    expect(Transaction::where('helloasso_order_id', 178679300)->count())->toBe(1);
});

it('don : initialAmount à 0 avec la clé présente ne doit pas vider la ligne', function (): void {
    // RÉGRESSION — mesurée sur le clone de production le 2026-08-20.
    //
    // Une version antérieure calculait le brut par `initialAmount ?? amount`, en
    // supposant qu'initialAmount n'est absent que sur les items sans remise. Les DONS
    // démentent cette hypothèse : n'ayant pas de tarif de palier, ils portent
    // `initialAmount: 0` avec la clé PRÉSENTE — et l'opérateur `??` ne se déclenche
    // que sur null, jamais sur 0.
    //
    // Conséquence observée : deux dons (25 € et 200 €) ont vu leur ligne de
    // ventilation ramenée à 0 et privée de son compte, laissant leur ligne 411
    // orpheline et la transaction déséquilibrée.
    $order = [
        'id' => 178679400,
        'date' => '2026-04-08T10:00:00Z',
        'formSlug' => 'journee-sensibilisation',
        'formType' => 'Event',
        'payments' => [['id' => 900940, 'amount' => 2500, 'paymentMeans' => 'Card']],
        'amount' => ['total' => 2500, 'discount' => 0, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
        'items' => [
            [
                'id' => 178679401,
                'amount' => 2500,
                'initialAmount' => 0,   // ← la clé EST présente, à zéro
                'type' => 'Registration',
                'tierId' => 19069055,
                'name' => 'Don de soutien',
                'user' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([$order], 2025);

    $parent = TransactionLigne::where('helloasso_item_id', 178679401)
        ->where('helloasso_line_key', 'parent')->first();

    expect($parent)->not->toBeNull()
        ->and((float) $parent->credit)->toBe(25.00)
        ->and($parent->compte_id)->not->toBeNull('La ligne ne doit pas perdre son compte.');

    // Le montant métier aussi, pas seulement le crédit : c'est lui que lisent les
    // écrans opérationnels, et c'est lui qui était tombé à 0.
    expect((float) $parent->montant)->toBe(25.00);

    $tx = Transaction::where('helloasso_order_id', 178679400)->first();
    expect((float) $tx->montant_total)->toBe(25.00);

    // Aucune ligne de remise : l'item n'en porte pas. Un initialAmount à 0 ne doit
    // jamais être confondu avec une gratuité.
    expect(TransactionLigne::where('helloasso_item_id', 178679401)
        ->where('helloasso_line_key', 'discount')->exists())->toBeFalse();
});
