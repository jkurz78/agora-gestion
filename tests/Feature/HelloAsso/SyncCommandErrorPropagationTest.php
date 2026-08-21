<?php

declare(strict_types=1);

/**
 * Tâche 12 (709A) — remontée des erreurs de conversion jusqu'au code de sortie.
 *
 * Avant ce correctif, un échec de TransactionConverter::convertir() dans
 * HelloAssoSyncService::processOrder était avalé en simple Log::warning, sans
 * jamais alimenter $result['errors'] : `helloasso:sync` affichait "Sync OK —
 * … 0 erreurs" et sortait en SUCCESS alors que la transaction restait legacy.
 * C'est un prérequis à la reprise de production — sans cette remontée, la
 * reprise n'est pas vérifiable.
 *
 * Pour forcer l'échec proprement, on supprime le compte système 411 juste
 * avant la synchro. Le paiement est en chèque (mode_paiement=Cheque) donc
 * statut_reglement=EnAttente : TransactionConverter::convertir() emprunte
 * alors le chemin direct « 411 D / 7x C seulement » (pas de résolution de
 * compte de trésorerie avant), qui résout 411 en tout premier — contrairement
 * au chemin CB/Prélèvement où un compte de trésorerie manquant se solderait
 * par un simple `return false` silencieux (pas d'exception à remonter).
 */

use App\Enums\HelloAssoEnvironnement;
use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Http;

/*
 * NB : tests/Pest.php boote TenantContext globalement (Association::factory())
 * mais garde l'association dans une variable locale au closure — $this->association
 * n'existe pas ici. On s'appuie sur TenantContext::currentId() partout.
 */
beforeEach(function (): void {
    // Comptes système (411/401/5112/530) — baseline réaliste, puis 411 est
    // supprimé dans le test pour forcer l'échec de conversion.
    SystemeSeeder::seed();

    $this->scParticipation = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '706EV2',
        'intitule' => 'Participations événements',
        'classe' => 7,
        'actif' => true,
    ]);
    $this->scParticipation->usages()->create(['usage' => UsageComptable::Inscription->value]);

    $this->typeOperation = TypeOperation::factory()->create(['compte_id' => $this->scParticipation->id]);
    $this->operation = Operation::factory()->create(['type_operation_id' => $this->typeOperation->id]);

    $this->parametres = HelloAssoParametres::factory()->create([
        'environnement' => HelloAssoEnvironnement::Sandbox,
        'organisation_slug' => 'mon-asso',
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

    $order = [
        'id' => 178699000,
        'date' => '2026-04-08T10:00:00Z',
        'formSlug' => 'journee-sensibilisation',
        'formType' => 'Event',
        'payments' => [['id' => 900999, 'amount' => 3500, 'paymentMeans' => 'Check']],
        'amount' => ['total' => 3500, 'discount' => 0, 'vat' => 0],
        'user' => null,
        'payer' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
        'items' => [
            [
                'id' => 178699001,
                'amount' => 3500,
                'type' => 'Registration',
                'name' => 'Journée de sensibilisation',
                'user' => ['firstName' => 'Charles', 'lastName' => 'SURPIN'],
            ],
        ],
    ];

    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        // fetchPaginated boucle tant que `data` n'est pas vide : une réponse unique
        // non vide bouclerait indéfiniment (pas de continuationToken pour la
        // stopper). Une séquence — 1 page avec la commande, puis une page vide —
        // termine la pagination normalement.
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push(['data' => [$order]])
            ->push(['data' => []]),
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('une conversion en échec fait sortir helloasso:sync en erreur (code de sortie non nul)', function (): void {
    // Supprime le compte système 411 : EcritureGenerator::pourRecetteACredit()
    // échoue dès sa résolution, TransactionConverter::convertir() lève, et la
    // transaction reste legacy (equilibree=false).
    Compte::where('numero_pcg', '411')->where('est_systeme', true)->first()->delete();

    // La commande tourne en CLI : simuler l'absence de TenantContext ambiant,
    // comme le fait déjà SyncCommandTenantScopeTest — chaque tenant est booté
    // individuellement par la commande elle-même.
    TenantContext::clear();

    $this->artisan('helloasso:sync', ['--exercice' => 2025])
        ->assertFailed();
});
