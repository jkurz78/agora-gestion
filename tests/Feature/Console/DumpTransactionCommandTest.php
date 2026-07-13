<?php

declare(strict_types=1);

/**
 * Tests compta:dump-transaction
 *
 * [A] Tx introuvable → exit 1 + message d'erreur
 * [B] Tx avec ventilation sans écriture PD → tableau affiché, total D=0 / C=0
 * [C] Tx recette comptant chèque post-backfill → 4+ lignes, paire 411 lettrée
 * [D] Tx T4 remise → section « Sources consolidées » présente
 * [E] Tx encaissement T2 → section « Transactions liées » mentionne T1 source
 * [F] Multi-tenant : --asso=2 ne voit pas Tx de l'asso 1
 * [G] Autodétection asso si --asso non fourni
 */

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\ReglementOperationService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function setupDumpTransactionContext(object $ctx): void
{
    $ctx->association = Association::factory()->create();
    $ctx->user = User::factory()->create();
    $ctx->user->associations()->attach($ctx->association->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::boot($ctx->association);
    session(['current_association_id' => $ctx->association->id]);
    $ctx->actingAs($ctx->user);
    Config::set('compta.use_partie_double', true);

    SystemeSeeder::seed();
    Compte::factory()->numero('530')->create([
        'association_id' => (int) $ctx->association->id,
        'intitule' => 'Caisse (espèces)',
        'est_systeme' => true,
        'lettrable' => true,
    ]);

    $ctx->iban = 'FR7612345000012345678901234';
    $ctx->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => (int) $ctx->association->id,
        'iban' => $ctx->iban,
    ]);
    BancairesSeeder::seed();
    $ctx->compte512X = Compte::where('compte_bancaire_id', (int) $ctx->compteBancaire->id)->firstOrFail();
    $ctx->compte706 = Compte::factory()->numero('706')->create([
        'association_id' => (int) $ctx->association->id,
        'intitule' => 'Cotisations et adhésions',
    ]);
}

// ---------------------------------------------------------------------------
// Helper local : Tx avec ventilation mais sans écriture PD
// ---------------------------------------------------------------------------

function makeUnpostedTx(object $ctx): Transaction
{
    $tx = Transaction::create([
        'association_id' => $ctx->association->id,
        'type' => TypeTransaction::Recette->value,
        'date' => '2026-01-15',
        'libelle' => 'Cotisation ancienne',
        'montant_total' => 50.00,
        'mode_paiement' => ModePaiement::Virement->value,
        'equilibree' => false,
        'type_ecriture' => 'normale',
    ]);

    // Une ventilation compte-first sans écriture PD : débit/crédit à 0.
    DB::table('transaction_lignes')->insert([
        'transaction_id' => $tx->id,
        'compte_id' => $ctx->compte706->id,
        'montant' => 50.00,
        'debit' => 0.00,
        'credit' => 0.00,
    ]);

    return $tx;
}

// ---------------------------------------------------------------------------
// [A] Tx introuvable → exit 1
// ---------------------------------------------------------------------------

test('[A] dump-transaction : Tx introuvable → exit 1 + message erreur', function (): void {
    setupDumpTransactionContext($this);

    $this->artisan('compta:dump-transaction', [
        'id' => 99999,
        '--asso' => $this->association->id,
    ])
        ->expectsOutputToContain('introuvable')
        ->assertExitCode(1);
})->group('dump_tx');

// ---------------------------------------------------------------------------
// [B] Tx avec ventilation sans lignes PD → tableau affiché, totaux 0/0
// ---------------------------------------------------------------------------

test('[B] dump-transaction : Tx avec ventilation sans lignes PD → tableau vide lettrage', function (): void {
    setupDumpTransactionContext($this);

    $tx = makeUnpostedTx($this);

    // On vérifie exit 0 + qu'un contenu caractéristique apparaît.
    // Note : expectsOutputToContain utilise Mockery first-match, donc on limite à 1 assertion output.
    $this->artisan('compta:dump-transaction', [
        'id' => $tx->id,
        '--asso' => $this->association->id,
    ])
        ->expectsOutputToContain('Lettrages actifs')
        ->assertExitCode(0);
})->group('dump_tx');

// ---------------------------------------------------------------------------
// [C] Tx recette comptant chèque post-backfill → 4+ lignes, lettrage 411
// ---------------------------------------------------------------------------

test('[C] dump-transaction : Tx recette chèque PD → lignes + lettrage 411 affichés', function (): void {
    setupDumpTransactionContext($this);

    /** @var TransactionService $service */
    $service = app(TransactionService::class);

    $tiers = Tiers::create([
        'association_id' => $this->association->id,
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'pour_recettes' => true,
        'pour_depenses' => false,
    ]);

    $tx = $service->create(
        [
            'type' => TypeTransaction::Recette->value,
            'date' => '2026-04-15',
            'libelle' => 'Cotisation Marie Dupont',
            'montant_total' => 100.00,
            'mode_paiement' => ModePaiement::Cheque->value,
            'tiers_id' => $tiers->id,
            'compte_id' => $this->compteBancaire->id,
        ],
        [
            ['compte_id' => $this->compte706->id, 'montant' => 100.00],
        ]
    );

    $this->artisan('compta:dump-transaction', [
        'id' => $tx->id,
        '--asso' => $this->association->id,
    ])
        ->expectsOutputToContain('411')
        ->expectsOutputToContain('Lettrages actifs')
        ->assertExitCode(0);
})->group('dump_tx');

// ---------------------------------------------------------------------------
// [D] Tx T4 remise → section « Sources consolidées »
// ---------------------------------------------------------------------------

test('[D] dump-transaction : Tx T4 remise → section Sources consolidées', function (): void {
    setupDumpTransactionContext($this);

    /** @var TransactionService $service */
    $service = app(TransactionService::class);

    $tiers1 = Tiers::create([
        'association_id' => $this->association->id,
        'nom' => 'Martin',
        'prenom' => 'Jean',
        'pour_recettes' => true,
        'pour_depenses' => false,
    ]);
    $tiers2 = Tiers::create([
        'association_id' => $this->association->id,
        'nom' => 'Durand',
        'prenom' => 'Paul',
        'pour_recettes' => true,
        'pour_depenses' => false,
    ]);

    // Créer 2 recettes chèque (T1 sources)
    $tx1 = $service->create(
        [
            'type' => TypeTransaction::Recette->value,
            'date' => '2026-04-10',
            'libelle' => 'Cotisation Jean',
            'montant_total' => 80.00,
            'mode_paiement' => ModePaiement::Cheque->value,
            'tiers_id' => $tiers1->id,
            'compte_id' => $this->compteBancaire->id,
        ],
        [['compte_id' => $this->compte706->id, 'montant' => 80.00]]
    );
    $tx2 = $service->create(
        [
            'type' => TypeTransaction::Recette->value,
            'date' => '2026-04-10',
            'libelle' => 'Cotisation Paul',
            'montant_total' => 150.00,
            'mode_paiement' => ModePaiement::Cheque->value,
            'tiers_id' => $tiers2->id,
            'compte_id' => $this->compteBancaire->id,
        ],
        [['compte_id' => $this->compte706->id, 'montant' => 150.00]]
    );

    // Créer la remise bancaire
    $remise = RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 1,
        'date' => '2026-04-11',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteBancaire->id,
        'libelle' => 'Remise chèques avril',
        'saisi_par' => $this->user->id,
    ]);

    // Rattacher T1 sources à la remise
    $tx1->remise_id = $remise->id;
    $tx1->save();
    $tx2->remise_id = $remise->id;
    $tx2->save();

    // Chantier 2a — depuis 2a, le portage 5112 n'est plus sur T1 mais sur leur T2 séparées.
    // On résout les T2 via ReglementOperationService::trouverEncaissementT2, puis on :
    //   1. Pose remise_id sur les T2 (pour que estT4Remise() puisse remonter la source).
    //   2. Récupère la ligne 5112 D non lettrée sur chaque T2.
    //   3. Appelle pourRemiseBancaire directement (sans passer par comptabiliser() qui poserait
    //      remise_id sur la T4, faisant échouer le garde "si remise_id != null → T1" de estT4Remise).
    /** @var ReglementOperationService $reglementService */
    $reglementService = app(ReglementOperationService::class);

    $compte5112 = Compte::where('numero_pcg', '5112')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $lignes5112Sources = collect();
    foreach ([$tx1, $tx2] as $txSource) {
        $t2 = $reglementService->trouverEncaissementT2($txSource);
        if ($t2 !== null) {
            // Rattacher la T2 à la remise : estT4Remise() remonte T4 → 5112C lettrée →
            // contrepartie 5112D sur T2 → vérifie remise_id de T2 pour confirmer que c'est une source.
            $t2->update(['remise_id' => $remise->id]);

            $ligne = TransactionLigne::where('transaction_id', $t2->id)
                ->where('compte_id', $compte5112->id)
                ->whereNull('lettrage_code')
                ->whereNull('tiers_id')
                ->where('debit', '>', 0)
                ->first();
            if ($ligne !== null) {
                $lignes5112Sources->push($ligne);
            }
        }
    }

    /** @var EcritureGenerator $ecritureGenerator */
    $ecritureGenerator = app(EcritureGenerator::class);
    $t4 = $ecritureGenerator->pourRemiseBancaire($remise, $lignes5112Sources);

    $this->artisan('compta:dump-transaction', [
        'id' => $t4->id,
        '--asso' => $this->association->id,
    ])
        ->expectsOutputToContain('Sources consolid')
        ->assertExitCode(0);
})->group('dump_tx');

// ---------------------------------------------------------------------------
// [E] Tx T2 encaissement créance → section « Transactions liées »
// ---------------------------------------------------------------------------

test('[E] dump-transaction : Tx T2 encaissement → section Transactions liées avec T1', function (): void {
    setupDumpTransactionContext($this);

    /** @var TransactionService $service */
    $service = app(TransactionService::class);

    /** @var EcritureGenerator $ecritureGenerator */
    $ecritureGenerator = app(EcritureGenerator::class);

    $tiers = Tiers::create([
        'association_id' => $this->association->id,
        'nom' => 'Legrand',
        'prenom' => 'Sophie',
        'pour_recettes' => true,
        'pour_depenses' => false,
    ]);

    // T1 — recette à crédit (créance ouverte) : mode_paiement null = pas encore payé
    $t1 = $service->create(
        [
            'type' => TypeTransaction::Recette->value,
            'date' => '2026-04-01',
            'libelle' => 'Facture à encaisser',
            'montant_total' => 200.00,
            'mode_paiement' => null,   // null = créance (pas encore encaissée)
            'tiers_id' => $tiers->id,
            'compte_id' => null,
        ],
        [['compte_id' => $this->compte706->id, 'montant' => 200.00]]
    );

    // T2 — encaissement via EcritureGenerator directement
    $t2 = $ecritureGenerator->pourEncaissementCreance(
        $t1->fresh(),
        ModePaiement::Virement,
        $this->compte512X,
        new DateTime('2026-04-15'),
        'Encaissement virement Sophie'
    );

    // Vérifier l'output sur T2 : doit mentionner la T1
    $this->artisan('compta:dump-transaction', [
        'id' => $t2->id,
        '--asso' => $this->association->id,
    ])
        ->expectsOutputToContain('Transactions li')
        ->expectsOutputToContain((string) $t1->id)
        ->assertExitCode(0);
})->group('dump_tx');

// ---------------------------------------------------------------------------
// [F] Multi-tenant : --asso=2 ne voit pas Tx de asso 1
// ---------------------------------------------------------------------------

test('[F] dump-transaction : multi-tenant isolation — --asso=2 ne voit pas Tx asso 1', function (): void {
    setupDumpTransactionContext($this);

    $tx = makeUnpostedTx($this);
    $txId = $tx->id;
    $asso1Id = $this->association->id;

    // Créer une deuxième association
    TenantContext::clear();
    $asso2 = Association::factory()->create();
    TenantContext::boot($asso2);

    // Demander Tx de asso1 avec --asso=asso2 → doit être introuvable
    $this->artisan('compta:dump-transaction', [
        'id' => $txId,
        '--asso' => $asso2->id,
    ])
        ->expectsOutputToContain('introuvable')
        ->assertExitCode(1);
})->group('dump_tx');

// ---------------------------------------------------------------------------
// [G] Autodétection asso si --asso non fourni
// ---------------------------------------------------------------------------

test('[G] dump-transaction : autodétection asso → trouve la bonne asso', function (): void {
    setupDumpTransactionContext($this);

    $tx = makeUnpostedTx($this);

    // Sans --asso, la commande doit trouver la transaction via withoutGlobalScopes
    $this->artisan('compta:dump-transaction', [
        'id' => $tx->id,
    ])
        ->expectsOutputToContain((string) $tx->id)
        ->assertExitCode(0);
})->group('dump_tx');
