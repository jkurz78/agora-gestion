<?php

declare(strict_types=1);

use App\Enums\HelloAssoEnvironnement;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\ReclassementLigneService;
use App\Services\HelloAssoSyncService;
use App\Services\UsagesComptablesService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Http;

/*
 * Une imputation corrigée à la main doit survivre à la synchronisation
 * suivante. Sans cette règle, HelloAsso réécrit le compte et l'opération de la
 * ligne depuis le paramétrage du formulaire : la correction disparaîtrait au
 * passage suivant, sans aucun signal. Le reste (montant, libellés) continue
 * d'être synchronisé.
 */

beforeEach(function (): void {
    $association = Association::firstOrCreate(['id' => 1], [
        'nom' => 'Asso test',
        'slug' => 'test-asso',
    ]);
    TenantContext::boot($association);

    $compte = CompteBancaire::factory()->create();

    $this->compteDon = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '754RC',
        'intitule' => 'Dons manuels',
        'classe' => 7,
        'actif' => true,
    ]);
    // Un item de type Donation se ventile par l'usage « don », pas par le
    // paramétrage du formulaire.
    $this->compteDon->usages()->create(['usage' => UsageComptable::Don->value]);

    $this->comptePrestation = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '706RC',
        'intitule' => 'Parcours thérapeutiques',
        'classe' => 7,
        'actif' => true,
    ]);
    $this->compteGratuite = Compte::firstOrCreate(
        ['association_id' => TenantContext::currentId(), 'numero_pcg' => '709A'],
        ['intitule' => 'Gratuités accordées', 'classe' => 7, 'actif' => true],
    );
    app(UsagesComptablesService::class)->setGratuite($this->compteGratuite->id);

    $this->parametres = HelloAssoParametres::factory()->create([
        'association_id' => $association->id,
        'environnement' => HelloAssoEnvironnement::Sandbox,
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'organisation_slug' => 'mon-asso',
        'compte_helloasso_id' => $compte->id,
        'compte_versement_id' => $compte->id,
        'compte_don_id' => $this->compteDon->id,
    ]);

    Tiers::factory()->create([
        'helloasso_nom' => 'DUPONT',
        'helloasso_prenom' => 'Aurore',
        'est_helloasso' => true,
    ]);

    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'adhesion-saison',
        'form_type' => 'Membership',
        'form_title' => 'Adhésion saison',
        'compte_id' => $this->compteDon->id,
    ]);

    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Membership/adhesion-saison/public' => Http::response([
            'formSlug' => 'adhesion-saison',
            'formType' => 'Membership',
            'validityType' => 'MovingYear',
            'tiers' => [],
        ]),
    ]);
});

/** @return array<string, mixed> la commande HelloAsso, au montant demandé (en centimes) */
function commandeReclassementTest(int $montantCentimes): array
{
    return [
        'id' => 90001,
        'date' => '2025-10-15T10:00:00Z',
        'formSlug' => 'adhesion-saison',
        'formType' => 'Membership',
        'payments' => [['id' => 70001, 'amount' => $montantCentimes, 'paymentMeans' => 'Card']],
        'amount' => ['total' => $montantCentimes, 'discount' => 0, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Aurore', 'lastName' => 'DUPONT'],
        'items' => [
            [
                'id' => 95001,
                'amount' => $montantCentimes,
                'initialAmount' => $montantCentimes,
                'type' => 'Donation',
                'name' => 'Don additionnel',
                'user' => ['firstName' => 'Aurore', 'lastName' => 'DUPONT'],
            ],
        ],
    ];
}

function synchroniserCommandeReclassementTest(object $ctx, int $montantCentimes): void
{
    (new HelloAssoSyncService($ctx->parametres))->synchroniser([commandeReclassementTest($montantCentimes)], 2025);
}

it('garde le compte choisi à la main et met quand même le montant à jour', function (): void {
    synchroniserCommandeReclassementTest($this, 17500);

    $ligne = TransactionLigne::where('helloasso_item_id', 95001)->sole();
    expect((int) $ligne->compte_id)->toBe((int) $this->compteDon->id);

    app(ReclassementLigneService::class)->reclasser(
        ligne: $ligne,
        compteId: (int) $this->comptePrestation->id,
        operationId: null,
        seance: null,
        motif: 'Règlement de parcours saisi en don par le payeur',
        auteur: $this->user ?? User::factory()->create(),
    );

    // Second passage : HelloAsso annonce un montant corrigé.
    synchroniserCommandeReclassementTest($this, 18000);

    $ligne->refresh();
    expect((int) $ligne->compte_id)->toBe((int) $this->comptePrestation->id)
        ->and($ligne->estReclassee())->toBeTrue()
        ->and((float) $ligne->credit)->toBe(180.00);
});

it('réécrit normalement le compte d\'une ligne jamais reclassée', function (): void {
    synchroniserCommandeReclassementTest($this, 17500);

    $ligne = TransactionLigne::where('helloasso_item_id', 95001)->sole();
    $ligne->forceFill(['compte_id' => (int) $this->comptePrestation->id])->save();

    synchroniserCommandeReclassementTest($this, 17500);

    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compteDon->id);
});
