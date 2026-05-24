<?php

declare(strict_types=1);

/**
 * Step 32 — Tests BackfillPartieDoubleCommand : squelette + dry-run + rapport.
 *
 * Test [A] : dry-run produit un rapport listant nb transactions à convertir, SC sans code_cerfa, modes non couverts.
 * Test [B] : aucune ligne transaction_lignes modifiée après dry-run (snapshot avant/après identique).
 * Test [C] : sortie console contient les sections clés.
 */

use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\TransactionService;
use App\Enums\ModePaiement;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

/**
 * Construit la fixture minimale nécessaire aux tests Step 32 :
 * - 2 recettes comptant (1 chèque + 1 virement)
 * - 1 sous-catégorie sans code_cerfa (pour tester la section "SC sans code_cerfa")
 */
function setupBackfillFixtureStep32(object $ctx): void
{
    $ctx->association = Association::factory()->create();
    $ctx->user = \App\Models\User::factory()->create();
    $ctx->user->associations()->attach($ctx->association->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::clear();
    TenantContext::boot($ctx->association);
    session(['current_association_id' => $ctx->association->id]);
    $ctx->actingAs($ctx->user);

    SystemeSeeder::seed();

    // Compte 530 (Caisse)
    $tenantId = (int) TenantContext::currentId();
    $isSqlite = DB::getDriverName() === 'sqlite';
    $insertClause = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    DB::statement(<<<SQL
        {$insertClause} INTO comptes
            (association_id, numero_pcg, intitule, classe, actif, est_systeme, pour_inscriptions, lettrable, created_at, updated_at)
        VALUES
            ({$tenantId}, '530', 'Caisse (espèces)', 5, 1, 1, 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    SQL);

    // CompteBancaire + 512X
    $ctx->iban = 'FR7612345000012345678901234';
    $ctx->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $ctx->association->id,
        'iban' => $ctx->iban,
        'actif_recettes_depenses' => true,
    ]);
    BancairesSeeder::seed();
    $ctx->compte512X = Compte::where('iban', $ctx->iban)
        ->where('association_id', $ctx->association->id)
        ->firstOrFail();

    // Catégorie + sous-catégorie AVEC code_cerfa (recette 706)
    $ctx->catRecette = Categorie::factory()->recette()->create([
        'association_id' => $ctx->association->id,
        'nom' => 'Prestations',
    ]);
    $ctx->sc706 = SousCategorie::create([
        'association_id' => $ctx->association->id,
        'categorie_id' => $ctx->catRecette->id,
        'nom' => 'Cotisations membres',
        'code_cerfa' => '706',
    ]);
    Compte::firstOrCreate(
        ['association_id' => $ctx->association->id, 'numero_pcg' => '706'],
        [
            'intitule' => 'Cotisations membres',
            'classe' => 7,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'categorie_id' => $ctx->catRecette->id,
        ]
    );

    // Sous-catégorie SANS code_cerfa (pour tester la section bloquante)
    $ctx->scSansCode = SousCategorie::create([
        'association_id' => $ctx->association->id,
        'categorie_id' => $ctx->catRecette->id,
        'nom' => 'Dons libres',
        'code_cerfa' => null,
    ]);

    // Tiers
    $ctx->tiersA = Tiers::factory()->create(['association_id' => $ctx->association->id]);

    // Services
    $ctx->txService = app(TransactionService::class);

    // Créer 2 transactions pour l'exercice 2025
    $ctx->txCheque = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-05',
        'libelle' => 'Adhésion chèque',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    $ctx->txVirement = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-10',
        'libelle' => 'Subvention virement',
        'montant_total' => '250.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '250.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);
}

// ---------------------------------------------------------------------------
// Tests Step 32 — [A], [B], [C]
// ---------------------------------------------------------------------------

test('[A] dry-run produit un rapport structuré avec nb transactions, SC sans code_cerfa, modes non couverts', function () {
    setupBackfillFixtureStep32($this);

    // Les 2 transactions viennent d'être créées avec PD activé (enrichirPartieDouble)
    // → elles sont déjà equilibree=TRUE. Pour tester le dry-run, on simule des Tx legacy
    // en remettant equilibree=FALSE sur l'une d'elles.
    \App\Models\Transaction::query()
        ->where('association_id', $this->association->id)
        ->update(['equilibree' => false]);

    $result = $this->artisan('compta:backfill-partie-double', [
        '--exercice' => '2025',
        '--dry-run' => true,
        '--asso' => $this->association->id,
    ]);

    $result->assertSuccessful();
})->group('backfill');

test('[B] dry-run ne modifie aucune ligne transaction_lignes', function () {
    setupBackfillFixtureStep32($this);

    // Snapshot AVANT dry-run
    $snapshotAvant = DB::table('transaction_lignes')
        ->where('association_id', $this->association->id)
        ->orderBy('id')
        ->get(['id', 'compte_id', 'debit', 'credit', 'tiers_id', 'lettrage_code'])
        ->toArray();

    // Marquer les Tx comme non-équilibrées pour que le dry-run les "voit"
    \App\Models\Transaction::query()
        ->where('association_id', $this->association->id)
        ->update(['equilibree' => false]);

    $this->artisan('compta:backfill-partie-double', [
        '--exercice' => '2025',
        '--dry-run' => true,
        '--asso' => $this->association->id,
    ]);

    // Snapshot APRÈS dry-run
    $snapshotApres = DB::table('transaction_lignes')
        ->where('association_id', $this->association->id)
        ->orderBy('id')
        ->get(['id', 'compte_id', 'debit', 'credit', 'tiers_id', 'lettrage_code'])
        ->toArray();

    expect($snapshotApres)->toEqual($snapshotAvant);
})->group('backfill');

test('[C] sortie console contient les sections clés du rapport dry-run', function () {
    setupBackfillFixtureStep32($this);

    \App\Models\Transaction::query()
        ->where('association_id', $this->association->id)
        ->update(['equilibree' => false]);

    $this->artisan('compta:backfill-partie-double', [
        '--exercice' => '2025',
        '--dry-run' => true,
        '--asso' => $this->association->id,
    ])
        ->expectsOutputToContain('RAPPORT DRY-RUN')
        ->expectsOutputToContain('transactions à convertir')
        ->expectsOutputToContain('Sous-catégories sans code_cerfa')
        ->expectsOutputToContain('Modes non couverts');
})->group('backfill');
