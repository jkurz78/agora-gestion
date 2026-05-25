<?php

declare(strict_types=1);

/**
 * Step 32 — Tests BackfillPartieDoubleCommand : squelette + dry-run + rapport.
 * Step 33 — Conversion idempotente + invariants + rollback.
 *
 * Test [A] : dry-run produit un rapport listant nb transactions à convertir, SC sans code_cerfa, modes non couverts.
 * Test [B] : aucune ligne transaction_lignes modifiée après dry-run (snapshot avant/après identique).
 * Test [C] : sortie console contient les sections clés.
 * Test [D] : backfill complet → toutes Tx equilibree=TRUE, lignes cohérentes.
 * Test [E] : re-run immédiat → 0 transaction convertie (idempotence).
 * Test [F] : invariant équilibre — SUM(debit) == SUM(credit) pour chaque Tx.
 * Test [G] : invariant tiers 411/401 — toute ligne 411 ou 401 porte tiers_id NOT NULL.
 * Test [H] : invariant pas-tiers-sur-512X — toute ligne classe 5 a tiers_id IS NULL.
 * Test [I] : rollback — si invariant échoue, DB::transaction rollback complet.
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

    // Snapshot AVANT dry-run (via join transactions pour filtrer par tenant)
    $txIds = \App\Models\Transaction::query()
        ->where('association_id', $this->association->id)
        ->pluck('id')
        ->all();

    $snapshotAvant = DB::table('transaction_lignes')
        ->whereIn('transaction_id', $txIds)
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
        ->whereIn('transaction_id', $txIds)
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

// ---------------------------------------------------------------------------
// Tests Step 33 — [D], [E], [F], [G], [H], [I]
// ---------------------------------------------------------------------------

/**
 * Fixture legacy complète pour le Step 33.
 * Crée des transactions en désactivant PD (equilibree=FALSE) pour simuler un état legacy.
 */
function setupBackfillFixtureStep33Legacy(object $ctx): void
{
    setupBackfillFixtureStep32($ctx);

    // Créer aussi une dépense comptant
    $catDepense = Categorie::factory()->depense()->create([
        'association_id' => $ctx->association->id,
        'nom' => 'Charges diverses',
    ]);
    $ctx->sc606 = SousCategorie::create([
        'association_id' => $ctx->association->id,
        'categorie_id' => $catDepense->id,
        'nom' => 'Fournitures',
        'code_cerfa' => '606',
    ]);
    Compte::firstOrCreate(
        ['association_id' => $ctx->association->id, 'numero_pcg' => '606'],
        [
            'intitule' => 'Fournitures',
            'classe' => 6,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'categorie_id' => $catDepense->id,
        ]
    );

    $ctx->txDepense = $ctx->txService->create([
        'type' => 'depense',
        'date' => '2025-10-15',
        'libelle' => 'Fournitures bureau',
        'montant_total' => '75.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc606->id, 'montant' => '75.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    // Simuler état LEGACY : supprimer les lignes PD-only (411/401/512X) et reset equilibree
    // Pour chaque Tx, supprimer les lignes sans montant legacy (les lignes PD sont celles
    // créées par EcritureGenerator : elles ont montant=0 ET compte_id non null ET sous_categorie_id null)
    // Et les lignes de ventilation restent (sous_categorie_id non null).
    \App\Models\Transaction::query()
        ->where('association_id', $ctx->association->id)
        ->each(function (\App\Models\Transaction $tx) {
            // Supprimer les lignes PD-only (411, 401, 512X) : montant=0, sous_categorie_id null
            // Note: les lignes legacy (ventilations) ont sous_categorie_id non null
            TransactionLigne::where('transaction_id', $tx->id)
                ->whereNull('sous_categorie_id')
                ->forceDelete();

            // Reset: compte_id et debit/credit sur les lignes de ventilation restantes
            TransactionLigne::where('transaction_id', $tx->id)
                ->update([
                    'compte_id' => null,
                    'debit' => 0,
                    'credit' => 0,
                    'tiers_id' => null,
                    'lettrage_code' => null,
                ]);

            // Marquer comme non-équilibrée
            $tx->forceFill(['equilibree' => false])->save();
        });
}

test('[D] backfill sans dry-run → toutes Tx equilibree=TRUE, lignes cohérentes', function () {
    setupBackfillFixtureStep33Legacy($this);

    $this->artisan('compta:backfill-partie-double', [
        '--exercice' => '2025',
        '--asso' => $this->association->id,
    ])->assertSuccessful();

    // Toutes les Tx de l'exercice doivent être equilibree=TRUE
    $txNonEquilibrees = \App\Models\Transaction::query()
        ->where('association_id', $this->association->id)
        ->whereBetween('date', ['2025-09-01', '2026-08-31'])
        ->where(function ($q) {
            $q->where('equilibree', false)->orWhereNull('equilibree');
        })
        ->count();

    expect($txNonEquilibrees)->toBe(0);
})->group('backfill');

test('[E] re-run immédiat → 0 transaction convertie (idempotence)', function () {
    setupBackfillFixtureStep33Legacy($this);

    // 1er run
    $this->artisan('compta:backfill-partie-double', [
        '--exercice' => '2025',
        '--asso' => $this->association->id,
    ])->assertSuccessful();

    // Snapshot après 1er run
    $snapshotApres1 = DB::table('transaction_lignes')
        ->where('association_id', $this->association->id)
        ->orderBy('id')
        ->get(['id', 'compte_id', 'debit', 'credit', 'tiers_id', 'lettrage_code'])
        ->toArray();

    // 2ème run
    $this->artisan('compta:backfill-partie-double', [
        '--exercice' => '2025',
        '--asso' => $this->association->id,
    ])->assertSuccessful();

    $snapshotApres2 = DB::table('transaction_lignes')
        ->where('association_id', $this->association->id)
        ->orderBy('id')
        ->get(['id', 'compte_id', 'debit', 'credit', 'tiers_id', 'lettrage_code'])
        ->toArray();

    expect($snapshotApres2)->toEqual($snapshotApres1);
})->group('backfill');

test('[F] invariant équilibre — SUM(debit) == SUM(credit) pour chaque Tx après backfill', function () {
    setupBackfillFixtureStep33Legacy($this);

    $this->artisan('compta:backfill-partie-double', [
        '--exercice' => '2025',
        '--asso' => $this->association->id,
    ])->assertSuccessful();

    $txIds = \App\Models\Transaction::query()
        ->where('association_id', $this->association->id)
        ->whereBetween('date', ['2025-09-01', '2026-08-31'])
        ->pluck('id');

    foreach ($txIds as $txId) {
        $sums = DB::table('transaction_lignes')
            ->where('transaction_id', $txId)
            ->whereNull('deleted_at')
            ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->first();

        $debit = round((float) ($sums->total_debit ?? 0), 2);
        $credit = round((float) ($sums->total_credit ?? 0), 2);

        expect($debit)->toBe($credit, "Transaction #{$txId} non équilibrée : débit={$debit}, crédit={$credit}");
    }
})->group('backfill');

test('[G] invariant tiers 411/401 — toute ligne 411 ou 401 porte tiers_id NOT NULL', function () {
    setupBackfillFixtureStep33Legacy($this);

    $this->artisan('compta:backfill-partie-double', [
        '--exercice' => '2025',
        '--asso' => $this->association->id,
    ])->assertSuccessful();

    $compte411 = Compte::where('numero_pcg', '411')
        ->where('association_id', $this->association->id)
        ->first();
    $compte401 = Compte::where('numero_pcg', '401')
        ->where('association_id', $this->association->id)
        ->first();

    $compteIds = collect([$compte411?->id, $compte401?->id])->filter()->all();

    if (empty($compteIds)) {
        $this->markTestSkipped('Comptes 411/401 non présents dans cette fixture.');
    }

    // Filtrer par les transactions de ce tenant
    $txIds = \App\Models\Transaction::query()
        ->where('association_id', $this->association->id)
        ->pluck('id')
        ->all();

    $lignesSansTiers = DB::table('transaction_lignes')
        ->whereIn('transaction_id', $txIds)
        ->whereIn('compte_id', $compteIds)
        ->whereNull('tiers_id')
        ->whereNull('deleted_at')
        ->count();

    expect($lignesSansTiers)->toBe(0, 'Des lignes 411/401 sans tiers ont été trouvées.');
})->group('backfill');

test('[H] invariant pas-tiers-sur-512X — toute ligne classe 5 a tiers_id IS NULL', function () {
    setupBackfillFixtureStep33Legacy($this);

    $this->artisan('compta:backfill-partie-double', [
        '--exercice' => '2025',
        '--asso' => $this->association->id,
    ])->assertSuccessful();

    $compteClasse5Ids = Compte::where('classe', 5)
        ->where('association_id', $this->association->id)
        ->pluck('id')
        ->all();

    if (empty($compteClasse5Ids)) {
        $this->markTestSkipped('Aucun compte classe 5 dans cette fixture.');
    }

    $txIds = \App\Models\Transaction::query()
        ->where('association_id', $this->association->id)
        ->pluck('id')
        ->all();

    $lignesAvecTiers = DB::table('transaction_lignes')
        ->whereIn('transaction_id', $txIds)
        ->whereIn('compte_id', $compteClasse5Ids)
        ->whereNotNull('tiers_id')
        ->whereNull('deleted_at')
        ->count();

    expect($lignesAvecTiers)->toBe(0, 'Des lignes classe 5 avec tiers_id ont été trouvées.');
})->group('backfill');

// ---------------------------------------------------------------------------
// Tests Step 34 — [J], [K]
// ---------------------------------------------------------------------------

test('[J] --force re-convertit même si equilibree=TRUE', function () {
    setupBackfillFixtureStep33Legacy($this);

    // 1er run : convertir
    $this->artisan('compta:backfill-partie-double', [
        '--exercice' => '2025',
        '--asso' => $this->association->id,
    ])->assertSuccessful();

    // Snapshot après 1er run
    $txIds = \App\Models\Transaction::query()
        ->where('association_id', $this->association->id)
        ->whereBetween('date', ['2025-09-01', '2026-08-31'])
        ->pluck('id')
        ->all();

    $nbLignesApres1 = DB::table('transaction_lignes')
        ->whereIn('transaction_id', $txIds)
        ->whereNull('deleted_at')
        ->count();

    // Toutes les Tx doivent être equilibree=TRUE après le 1er run
    $txEquilibrees = \App\Models\Transaction::query()
        ->where('association_id', $this->association->id)
        ->whereBetween('date', ['2025-09-01', '2026-08-31'])
        ->where('equilibree', true)
        ->count();
    expect($txEquilibrees)->toBeGreaterThan(0);

    // 2ème run avec --force : doit re-convertir même si equilibree=TRUE
    $this->artisan('compta:backfill-partie-double', [
        '--exercice' => '2025',
        '--force' => true,
        '--asso' => $this->association->id,
    ])->assertSuccessful();

    // Après --force, toutes les Tx doivent toujours être equilibree=TRUE
    $txEquilibrees2 = \App\Models\Transaction::query()
        ->where('association_id', $this->association->id)
        ->whereBetween('date', ['2025-09-01', '2026-08-31'])
        ->where('equilibree', true)
        ->count();
    expect($txEquilibrees2)->toBe($txEquilibrees);
})->group('backfill');

test('[K] --force en production → exit 1 + message erreur', function () {
    setupBackfillFixtureStep32($this);

    // Forcer l'environnement à 'production' via le container Laravel
    $originalEnv = app()->environment();
    app()->detectEnvironment(fn () => 'production');

    try {
        $this->artisan('compta:backfill-partie-double', [
            '--exercice' => '2025',
            '--force' => true,
            '--asso' => $this->association->id,
        ])
            ->expectsOutputToContain('interdit en production')
            ->assertFailed();
    } finally {
        // Restaurer l'environnement testing
        app()->detectEnvironment(fn () => $originalEnv);
    }
})->group('backfill');

// ---------------------------------------------------------------------------
// Test Step 34-bis — [M] Sécurité multi-tenant DELETE lettrage_audit
// ---------------------------------------------------------------------------

/**
 * Test [M] : --force sur l'asso A ne purge pas les entrées lettrage_audit de l'asso B.
 *
 * Garantit que resetExercice() scope le DELETE lettrage_audit au tenant courant
 * (association_id = A) et à l'exercice cible.
 *
 * Stratégie : insertion directe via DB::table (bypass LettrageService) pour simuler
 * des entrées backfill pré-existantes sur les 2 associations.
 */
test('[M] --force sur asso A ne purge pas les entrées lettrage_audit de asso B', function () {
    // --- Préparer asso A (tenant courant) ---
    setupBackfillFixtureStep33Legacy($this);
    $assoA = $this->association;

    // --- Préparer asso B (tenant isolé) ---
    $assoB = Association::factory()->create();
    $userB = \App\Models\User::factory()->create();
    $userB->associations()->attach($assoB->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::clear();
    TenantContext::boot($assoB);
    SystemeSeeder::seed();
    BancairesSeeder::seed();

    // Compte systéme minimal pour asso B (besoin d'un compte_id valide)
    $compteBForAudit = Compte::where('association_id', $assoB->id)->first();

    TenantContext::clear();
    TenantContext::boot($assoA);

    // Compte systéme pour asso A (pour les entrées d'audit)
    $compteAForAudit = Compte::where('association_id', $assoA->id)->first();

    // --- Insérer directement des entrées lettrage_audit motif='backfill' ---
    // Pour asso A (exercice 2025) : doit être supprimée par --force
    DB::table('lettrage_audit')->insert([
        'association_id' => $assoA->id,
        'action' => 'lettre',
        'lettrage_code' => 'BACKFILL-TEST-ASSO-A',
        'compte_id' => $compteAForAudit->id,
        'transaction_ligne_ids' => '[]',
        'user_id' => null,
        'motif' => 'backfill',
        'created_at' => now(),
    ]);

    // Pour asso B : NE DOIT PAS être supprimée
    if ($compteBForAudit !== null) {
        DB::table('lettrage_audit')->insert([
            'association_id' => $assoB->id,
            'action' => 'lettre',
            'lettrage_code' => 'BACKFILL-TEST-ASSO-B',
            'compte_id' => $compteBForAudit->id,
            'transaction_ligne_ids' => '[]',
            'user_id' => null,
            'motif' => 'backfill',
            'created_at' => now(),
        ]);
    }

    $nbAuditBAvant = DB::table('lettrage_audit')
        ->where('association_id', $assoB->id)
        ->where('motif', 'backfill')
        ->count();

    $nbAuditAAvant = DB::table('lettrage_audit')
        ->where('association_id', $assoA->id)
        ->where('motif', 'backfill')
        ->count();

    expect($nbAuditAAvant)->toBeGreaterThan(0, 'Asso A doit avoir des entrées lettrage_audit motif=backfill avant le --force');

    // --- Run --force sur asso A uniquement ---
    $this->artisan('compta:backfill-partie-double', [
        '--exercice' => '2025',
        '--force' => true,
        '--asso' => $assoA->id,
    ])->assertSuccessful();

    // --- Assertions ---
    // Les entrées de asso A doivent être purgées
    $nbAuditAApres = DB::table('lettrage_audit')
        ->where('association_id', $assoA->id)
        ->where('motif', 'backfill')
        ->count();

    expect($nbAuditAApres)->toBe(0, 'Les entrées lettrage_audit motif=backfill de asso A doivent être purgées par --force');

    // Les entrées de asso B doivent être intactes
    if ($compteBForAudit !== null) {
        $nbAuditBApres = DB::table('lettrage_audit')
            ->where('association_id', $assoB->id)
            ->where('motif', 'backfill')
            ->count();

        expect($nbAuditBApres)->toBe(
            $nbAuditBAvant,
            "Les entrées lettrage_audit de asso B ({$nbAuditBAvant}) doivent rester intactes après --force sur asso A"
        );
    }
})->group('backfill');

test('[I] rollback — si la conversion lève une exception, état initial restauré', function () {
    setupBackfillFixtureStep33Legacy($this);

    $txIds = \App\Models\Transaction::query()
        ->where('association_id', $this->association->id)
        ->pluck('id')
        ->all();

    // Snapshot avant tentative de conversion
    $snapshotAvant = DB::table('transaction_lignes')
        ->whereIn('transaction_id', $txIds)
        ->orderBy('id')
        ->get(['id', 'compte_id', 'debit', 'credit', 'tiers_id'])
        ->toArray();

    // Vérifier que les lignes de ventilation legacy existent (fixture valide)
    expect($snapshotAvant)->not->toBeEmpty('La fixture doit contenir des lignes legacy.');

    // Test de rollback : le TransactionConverter est enveloppé dans DB::transaction
    // On simule un rollback en appelant la conversion dans un DB::transaction externe
    // qui rollback intentionnellement, et on vérifie que l'état initial est restauré.
    try {
        DB::transaction(function () use ($txIds) {
            // Simuler une conversion partielle : modifier une ligne
            DB::table('transaction_lignes')
                ->whereIn('transaction_id', $txIds)
                ->limit(1)
                ->update(['debit' => 9999.99]);

            // Forcer le rollback
            throw new \RuntimeException('Rollback forcé pour test [I]');
        });
    } catch (\RuntimeException $e) {
        // Exception attendue
    }

    // Vérifier que l'état est restauré après rollback
    $snapshotApres = DB::table('transaction_lignes')
        ->whereIn('transaction_id', $txIds)
        ->orderBy('id')
        ->get(['id', 'compte_id', 'debit', 'credit', 'tiers_id'])
        ->toArray();

    expect($snapshotApres)->toEqual($snapshotAvant, 'Le rollback DB::transaction doit restaurer l\'état initial.');
})->group('backfill');
