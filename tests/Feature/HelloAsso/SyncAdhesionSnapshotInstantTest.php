<?php

declare(strict_types=1);

/**
 * Tâche 11 (709A) — instant du snapshot de l'adhésion.
 *
 * AdhesionTransactionLigneObserver::saved se déclenche à CHAQUE TransactionLigne
 * sauvée. Avant ce correctif, l'upsert de la ligne parente HelloAsso (compte de
 * cotisation, crédit BRUT) déclenchait la création de l'adhésion AVANT que la
 * ligne de remise et les options n'existent — montant_facial figeait alors le
 * brut et n'était plus jamais corrigé.
 *
 * Fixture de référence : HA-55698 — Membership 35 €, option 12 €, remise 35 €
 * → net 12,00 €. Avant correctif, montant_facial restait figé à 35,00 € (la
 * ligne parente seule), faisant sonner compta:check-integrity CHECK 4.
 */

use App\Enums\HelloAssoEnvironnement;
use App\Enums\UsageComptable;
use App\Models\Adhesion;
use App\Models\Compte;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\HelloAssoSyncService;
use App\Services\UsagesComptablesService;
use App\Tenant\TenantContext;

/*
 * NB : tests/Pest.php boote TenantContext globalement (Association::factory())
 * mais garde l'association dans une variable locale au closure — $this->association
 * n'existe pas ici. On s'appuie sur TenantContext::currentId() partout.
 */
beforeEach(function (): void {
    // Compte de cotisation (usage Cotisation), classe 7 — celui vers lequel le
    // mapping de formulaire Membership route l'item parent et ses options.
    $this->scCotisation = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '756HA',
        'intitule' => 'Cotisations HelloAsso',
        'classe' => 7,
        'actif' => true,
    ]);
    $this->scCotisation->usages()->create(['usage' => UsageComptable::Cotisation->value]);

    // Compte de contrepartie des gratuités (709A) — posé via UsagesComptablesService,
    // comme le ferait l'écran Paramètres → Comptabilité → Usages comptables.
    // firstOrCreate : la migration 2026_08_20_100000 crée déjà 709A pour toute
    // association, y compris celle du bootstrap de tests — ne jamais Compte::create ici.
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

    $this->parametres = HelloAssoParametres::factory()->create([
        'environnement' => HelloAssoEnvironnement::Sandbox,
        'organisation_slug' => 'mon-asso',
    ]);

    // Mapping Membership : ventilation par compte_id (DC-10a), pas d'operation_id
    // — pas de tierId sur l'item ci-dessous, donc pas d'appel HTTP fetchFormDetail
    // (auto-création de formule) déclenché par HelloAssoSyncService::processOrder.
    $this->formMapping = HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'cotisation-2025-2026',
        'form_type' => 'Membership',
        'form_title' => 'Cotisation 2025-2026',
        'compte_id' => $this->scCotisation->id,
    ]);

    Tiers::factory()->create([
        'helloasso_nom' => 'SURPIN',
        'helloasso_prenom' => 'Charles',
        'est_helloasso' => true,
    ]);
});

function buildOrderHa55698(): array
{
    return [
        'id' => 178690000,
        'date' => '2026-04-08T10:00:00Z',
        'formSlug' => 'cotisation-2025-2026',
        'formType' => 'Membership',
        'payments' => [['id' => 900950, 'amount' => 1200, 'paymentMeans' => 'Card']],
        'amount' => ['total' => 1200, 'discount' => 3500, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
        'items' => [
            [
                'id' => 178690001,
                'amount' => 0,
                'initialAmount' => 3500,
                'type' => 'Membership',
                'discount' => ['code' => 'W6BGU5YY', 'amount' => 3500],
                'options' => [
                    ['name' => 'Option repas', 'amount' => 1200, 'priceCategory' => 'Fixed', 'isRequired' => false, 'optionId' => 990001],
                ],
                'user' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
            ],
        ],
    ];
}

it('HA-55698 : montant_facial vaut le net final (35€ parent − 35€ remise + 12€ option = 12€), pas le brut figé à la ligne parente', function (): void {
    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([buildOrderHa55698()], 2025);

    $tx = Transaction::where('helloasso_order_id', 178690000)->first();
    expect($tx)->not->toBeNull();
    expect((float) $tx->montant_total)->toBe(12.00);

    $adhesion = Adhesion::where('transaction_id', $tx->id)->first();
    expect($adhesion)->not->toBeNull();
    // Avant correctif (Tâche 11) : montant_facial restait figé à 35,00 € (la
    // valeur brute de la ligne parente, seule ligne présente au moment où
    // l'observer se déclenchait). Après correctif : le net final, 12,00 €.
    expect((float) $adhesion->montant_facial)->toBe(12.00);
});

it('compta:check-integrity reste vert après une synchro Membership remise + option (CHECK 4)', function (): void {
    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser([buildOrderHa55698()], 2025);

    expect(Adhesion::count())->toBe(1);

    $this->artisan('compta:check-integrity', ['--quiet-ok' => true])
        ->assertExitCode(0);
});
