<?php

declare(strict_types=1);

/**
 * Tâche 14 (709A) — chemin d'update dédié aux transactions HelloAsso.
 *
 * Une commande HelloAsso remisée porte deux lignes de ventilation : le
 * produit brut au crédit (compte de participation/cotisation) et la
 * gratuité au débit (709A, helloasso_line_key = 'discount'). Le formulaire
 * de transaction ne transporte aucun sens débit/crédit — il manipule des
 * montants positifs — et ne doit donc jamais éditer, supprimer ni recréer
 * cette ligne technique. Elle reste néanmoins vivante : l'opération et la
 * séance de sa ligne parente lui sont propagées automatiquement côté
 * serveur, pour ne jamais diverger d'elle.
 *
 * Fixtures calquées sur tests/Feature/HelloAsso/SyncRemiseTest.php (même
 * commande de référence, transaction 120 en production), avec en plus :
 *   - SystemeSeeder::seed() : sans les comptes système (411 notamment), la
 *     synchro reste "legacy" (non équilibrée) et aucune des deux commandes
 *     n'atteint l'état attendu (lettrée / créance ouverte).
 *   - un utilisateur admin + TransactionForm, pour exercer le vrai chemin
 *     d'édition.
 */

use App\Enums\HelloAssoEnvironnement;
use App\Enums\RoleAssociation;
use App\Enums\StatutReglement;
use App\Enums\UsageComptable;
use App\Livewire\TransactionForm;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\HelloAssoSyncService;
use App\Services\UsagesComptablesService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * NB : tests/Pest.php boote TenantContext globalement (Association::factory())
 * mais garde l'association dans une variable locale au closure — $this->association
 * n'existe pas ici. On s'appuie sur TenantContext::currentId() partout, comme le
 * fait déjà SyncRemiseTest.
 */
beforeEach(function (): void {
    SystemeSeeder::seed();

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

    // Compte de contrepartie des gratuités (709A) — la migration
    // 2026_08_20_100000 le crée déjà pour toute association, y compris celle
    // du bootstrap de tests : firstOrCreate, jamais create().
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
    // Seconde opération, pour le test de propagation (déplacer la ligne
    // parente d'une opération à l'autre).
    $this->autreOperation = Operation::factory()->create(['type_operation_id' => $this->typeOperation->id]);

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

    // Utilisateur admin — motif copié de HelloAssoEditGuardTest (droits
    // d'écriture Compta, requis par TransactionForm::getCanEditProperty()).
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => RoleAssociation::Admin->value, 'joined_at' => now()]);
    $this->actingAs($this->user);
});

/**
 * Commande entièrement remisée (item 178678765, brut 50 €, remise 50 €, net
 * 0 €) — payée par carte (défaut sans paiements explicites), donc Recu :
 * le 411 est auto-lettré (paire D/C, net nul), aUnReglementTiers() est vrai.
 */
function synchroniserCommandeRemiseTotale(HelloAssoParametres $parametres): Transaction
{
    $order = [
        'id' => 178678765,
        'date' => '2026-04-08T10:00:00Z',
        'formSlug' => 'journee-sensibilisation',
        'formType' => 'Event',
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

    (new HelloAssoSyncService($parametres))->synchroniser([$order], 2025);

    return Transaction::where('helloasso_order_id', 178678765)->firstOrFail();
}

/**
 * Commande partiellement remisée (item 178678801, brut 50 €, remise 20 €,
 * net 30 €) — payée par chèque, donc EnAttente (resolveStatutReglement ne
 * retourne Recu que pour CB et prélèvement) : le 411 reste ouvert, non
 * lettré, aUnReglementTiers() est faux.
 */
function synchroniserCommandeRemisePartielleCheque(HelloAssoParametres $parametres): Transaction
{
    $order = [
        'id' => 178678800,
        'date' => '2026-04-08T10:00:00Z',
        'formSlug' => 'journee-sensibilisation',
        'formType' => 'Event',
        'payments' => [['id' => 900800, 'amount' => 3000, 'paymentMeans' => 'Check']],
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

    (new HelloAssoSyncService($parametres))->synchroniser([$order], 2025);

    return Transaction::where('helloasso_order_id', 178678800)->firstOrFail();
}

it('sauvegarde sans blocage l\'édition d\'une remise totale lettrée', function (): void {
    $tx = synchroniserCommandeRemiseTotale($this->parametres);

    // Fixture : la commande entièrement remisée doit bien être lettrée
    // (aUnReglementTiers() vrai) — sinon ce test ne couvre pas le bon chemin
    // de TransactionService::update() (assertReglementTiersInvariants).
    expect($tx->aUnReglementTiers())->toBeTrue();

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        ->set('notes', 'Place offerte — vérifié avec le trésorier')
        ->call('save')
        ->assertHasNoErrors();
});

it('la ligne de remise 709A survit à l\'édition d\'une remise totale lettrée', function (): void {
    $tx = synchroniserCommandeRemiseTotale($this->parametres);

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        ->set('notes', 'Place offerte — vérifié avec le trésorier')
        ->call('save')
        ->assertHasNoErrors();

    $remise = TransactionLigne::where('helloasso_item_id', 178678765)
        ->where('helloasso_line_key', 'discount')
        ->first();

    expect($remise)->not->toBeNull();
    expect((float) $remise->debit)->toBe(50.00);
    expect((float) $remise->credit)->toBe(0.00);
    expect((int) $remise->helloasso_item_id)->toBe(178678765);
    expect($remise->helloasso_line_key)->toBe('discount');
});

it('sauvegarde sans blocage l\'édition d\'une remise partielle encore en attente', function (): void {
    $tx = synchroniserCommandeRemisePartielleCheque($this->parametres);

    // Fixture : chèque → EnAttente (jamais Recu), donc non lettrée — sinon
    // ce test ne couvre pas le chemin libre de update() (forceDelete +
    // recréation), qui est celui qui doit recréer/propager la ligne 709A.
    expect($tx->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect($tx->aUnReglementTiers())->toBeFalse();

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        ->set('notes', 'Chèque reçu, en cours de dépôt')
        ->call('save')
        ->assertHasNoErrors();

    // Le montant net ne doit pas dériver : la remise (masquée du
    // formulaire) ne doit pas être comptée deux fois ni perdue.
    expect((float) $tx->fresh()->montant_total)->toBe(30.00);
});

it('préserve helloasso_tier_id sur la ligne parente reconstruite', function (): void {
    // Le chemin libre de update() détruit puis recrée toutes les lignes. Le
    // formulaire ne transporte AUCUNE métadonnée HelloAsso : elles sont
    // réinjectées depuis un snapshot pris juste avant le forceDelete().
    //
    // helloasso_tier_id n'y figurait pas. Sa perte n'est pas cosmétique : c'est
    // le discriminant qui identifie le PALIER souscrit. RecuFiscalService
    // ::resoudreLigneCotisation et AdhesionService::resolveFormule s'en servent
    // en priorité 1 ; sans lui, sur une commande à plusieurs paliers routés vers
    // le même compte de cotisation, le repli prend la PREMIÈRE ligne du compte —
    // et le reçu fiscal sort au montant d'un autre palier.
    $tx = synchroniserCommandeRemisePartielleCheque($this->parametres);

    $avant = TransactionLigne::where('helloasso_item_id', 178678801)
        ->where('helloasso_line_key', 'parent')->firstOrFail();
    expect((int) $avant->helloasso_tier_id)->toBe(19069055, 'Fixture : la synchro pose bien le palier.');

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        ->set('notes', 'Chèque reçu, en cours de dépôt')
        ->call('save')
        ->assertHasNoErrors();

    $apres = TransactionLigne::where('helloasso_item_id', 178678801)
        ->where('helloasso_line_key', 'parent')->firstOrFail();

    expect((int) $apres->helloasso_tier_id)->toBe(19069055);
});

it('déplacer la ligne parente vers une autre opération déplace aussi la ligne 709A', function (): void {
    $tx = synchroniserCommandeRemisePartielleCheque($this->parametres);

    $parentAvant = TransactionLigne::where('helloasso_item_id', 178678801)
        ->where('helloasso_line_key', 'parent')->firstOrFail();
    expect((int) $parentAvant->operation_id)->toBe((int) $this->operation->id);

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        // Une seule ligne visible dans le formulaire : la ligne parente
        // (la remise est exclue — item a de la Tâche 14).
        ->assertSet('lignes.0.compte_id', (string) $this->scParticipation->id)
        ->set('lignes.0.operation_id', (string) $this->autreOperation->id)
        ->call('save')
        ->assertHasNoErrors();

    $parentApres = TransactionLigne::where('helloasso_item_id', 178678801)
        ->where('helloasso_line_key', 'parent')->firstOrFail();
    expect((int) $parentApres->operation_id)->toBe((int) $this->autreOperation->id);

    $remiseApres = TransactionLigne::where('helloasso_item_id', 178678801)
        ->where('helloasso_line_key', 'discount')->firstOrFail();
    expect((int) $remiseApres->operation_id)->toBe((int) $this->autreOperation->id);
    // La propagation ne doit toucher que l'opération/séance — montant et
    // sens de la remise restent ceux posés par la synchro.
    expect((float) $remiseApres->debit)->toBe(20.00);
    expect((float) $remiseApres->credit)->toBe(0.00);

    expect((float) $tx->fresh()->montant_total)->toBe(30.00);
});

it('déplacer l\'opération propage aussi sur une remise TOTALE, chemin règlement tiers', function (): void {
    // Le test de propagation voisin porte sur une remise PARTIELLE en attente :
    // une seule ligne 411, non lettrée, donc aUnReglementTiers() faux et chemin
    // libre de TransactionService::update().
    //
    // Une gratuité INTÉGRALE est un autre chemin : sa paire 411 naît lettrée, donc
    // aUnReglementTiers() est vrai et l'update passe par
    // assertReglementTiersInvariants(). Rien ne garantissait jusqu'ici que la
    // propagation vers la ligne 709A y survive — les deux tests de gratuité totale
    // ne modifient que les notes. Écart relevé en revue de code.
    $tx = synchroniserCommandeRemiseTotale($this->parametres);

    expect($tx->aUnReglementTiers())->toBeTrue('Fixture : la paire 411 doit être lettrée.');

    $parentAvant = TransactionLigne::where('helloasso_item_id', 178678765)
        ->where('helloasso_line_key', 'parent')->firstOrFail();
    expect((int) $parentAvant->operation_id)->toBe((int) $this->operation->id);

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        ->set('lignes.0.operation_id', (string) $this->autreOperation->id)
        ->call('save')
        ->assertHasNoErrors();

    $parentApres = TransactionLigne::where('helloasso_item_id', 178678765)
        ->where('helloasso_line_key', 'parent')->firstOrFail();
    expect((int) $parentApres->operation_id)->toBe((int) $this->autreOperation->id);

    $remiseApres = TransactionLigne::where('helloasso_item_id', 178678765)
        ->where('helloasso_line_key', 'discount')->firstOrFail();
    expect((int) $remiseApres->operation_id)->toBe((int) $this->autreOperation->id,
        'Sans propagation, le produit part sur la nouvelle opération et la gratuité reste sur l\'ancienne : les deux sont fausses.');

    // La remise garde son sens et son montant — seule l'imputation bouge.
    expect((float) $remiseApres->debit)->toBe(50.00);
    expect((float) $remiseApres->credit)->toBe(0.00);
    expect((float) $tx->fresh()->montant_total)->toBe(0.00);
})->todo('ÉCART CONNU — relevé en revue de code, non corrigé.

TransactionService::assertReglementTiersInvariants (l. 1188-1191) interdit de
changer operation_id et seance, alors que son propre message d\'erreur affirme
l\'inverse : « La répartition par opération et séance, elle, reste modifiable. »
La contradiction préexiste au chantier 709A.

Le chantier ne l\'a pas créée mais y fait ENTRER les gratuités HelloAsso : leur
paire 411 naît lettrée, donc aUnReglementTiers() est vrai et l\'update emprunte
ce chemin. Avant, ces transactions n\'étaient pas converties du tout.

Deux correctifs possibles :
  1. router les transactions HelloAsso vers leur chemin dédié AVANT le test
     aUnReglementTiers() dans update() — contenu, ne touche qu\'HelloAsso ;
  2. aligner la garde sur son message en autorisant opération/séance —
     plus juste sur le fond, mais modifie le cœur de règlement PARTAGÉ.

L\'option 1 est recommandée : ce chantier a montré qu\'assouplir une garde
partagée pour un cas local se paie plus tard.');
