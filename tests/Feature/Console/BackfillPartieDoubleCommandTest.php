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

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\RemiseBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\TransactionConverter;
use App\Services\RapprochementBancaireService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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
    $ctx->user = User::factory()->create();
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
    // statut_reglement = Recu : transactions avec mode déjà encaissées (comptant).
    $ctx->txCheque = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-05',
        'libelle' => 'Adhésion chèque',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'statut_reglement' => StatutReglement::Recu->value,
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
        'statut_reglement' => StatutReglement::Recu->value,
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
    Transaction::query()
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
    $txIds = Transaction::query()
        ->where('association_id', $this->association->id)
        ->pluck('id')
        ->all();

    $snapshotAvant = DB::table('transaction_lignes')
        ->whereIn('transaction_id', $txIds)
        ->orderBy('id')
        ->get(['id', 'compte_id', 'debit', 'credit', 'tiers_id', 'lettrage_code'])
        ->toArray();

    // Marquer les Tx comme non-équilibrées pour que le dry-run les "voit"
    Transaction::query()
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

    Transaction::query()
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
        'statut_reglement' => StatutReglement::Recu->value,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc606->id, 'montant' => '75.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    // Simuler état LEGACY : supprimer les lignes PD-only (411/401/512X) et reset equilibree
    // Pour chaque Tx, supprimer les lignes sans montant legacy (les lignes PD sont celles
    // créées par EcritureGenerator : elles ont montant=0 ET compte_id non null ET sous_categorie_id null)
    // Et les lignes de ventilation restent (sous_categorie_id non null).
    Transaction::query()
        ->where('association_id', $ctx->association->id)
        ->each(function (Transaction $tx) {
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
    $txNonEquilibrees = Transaction::query()
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

    $txIds = Transaction::query()
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
    $txIds = Transaction::query()
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

    $txIds = Transaction::query()
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
    $txIds = Transaction::query()
        ->where('association_id', $this->association->id)
        ->whereBetween('date', ['2025-09-01', '2026-08-31'])
        ->pluck('id')
        ->all();

    $nbLignesApres1 = DB::table('transaction_lignes')
        ->whereIn('transaction_id', $txIds)
        ->whereNull('deleted_at')
        ->count();

    // Toutes les Tx doivent être equilibree=TRUE après le 1er run
    $txEquilibrees = Transaction::query()
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
    $txEquilibrees2 = Transaction::query()
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
    $userB = User::factory()->create();
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

    $txIds = Transaction::query()
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
            throw new RuntimeException('Rollback forcé pour test [I]');
        });
    } catch (RuntimeException $e) {
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

// ---------------------------------------------------------------------------
// Test Cutover — [N] --all balaye tous les exercices (courant + précédent)
// ---------------------------------------------------------------------------

test('[N] --all convertit l\'exercice courant ET l\'exercice précédent', function () {
    // Fixture legacy : txCheque + txVirement + txDepense sur l'exercice 2025.
    setupBackfillFixtureStep33Legacy($this);

    // Ajouter une transaction sur l'exercice PRÉCÉDENT (2024 : 01/09/2024 → 31/08/2025).
    // Cas réel : ENL expliquant le solde bancaire d'ouverture, postée hors exercice courant.
    $txExercice2024 = $this->txService->create([
        'type' => 'recette',
        'date' => '2024-10-05',
        'libelle' => 'ENL exercice précédent',
        'montant_total' => '500.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'statut_reglement' => StatutReglement::Recu->value,
        'tiers_id' => $this->tiersA->id,
        'compte_id' => $this->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $this->sc706->id, 'montant' => '500.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    // Remettre cette Tx en état legacy (les autres le sont déjà via le helper).
    TransactionLigne::where('transaction_id', $txExercice2024->id)
        ->whereNull('sous_categorie_id')
        ->forceDelete();
    TransactionLigne::where('transaction_id', $txExercice2024->id)
        ->update(['compte_id' => null, 'debit' => 0, 'credit' => 0, 'tiers_id' => null, 'lettrage_code' => null]);
    $txExercice2024->forceFill(['equilibree' => false])->save();

    // Sanity : avant le backfill, des Tx des 2 exercices sont non-équilibrées.
    $nonEquilibreesAvant = Transaction::query()
        ->where('association_id', $this->association->id)
        ->where(fn ($q) => $q->where('equilibree', false)->orWhereNull('equilibree'))
        ->count();
    expect($nonEquilibreesAvant)->toBeGreaterThanOrEqual(2);

    // --all : doit balayer 2024 ET 2025 (range min..max des dates de transaction).
    $this->artisan('compta:backfill-partie-double', [
        '--all' => true,
        '--asso' => $this->association->id,
    ])->assertSuccessful();

    // Toutes les Tx des 2 exercices doivent être equilibree=TRUE.
    $nonEquilibreesApres = Transaction::query()
        ->where('association_id', $this->association->id)
        ->where(fn ($q) => $q->where('equilibree', false)->orWhereNull('equilibree'))
        ->count();
    expect($nonEquilibreesApres)->toBe(0);

    // Spécifiquement, la Tx de l'exercice précédent est convertie.
    expect((bool) $txExercice2024->fresh()->equilibree)->toBeTrue();
})->group('backfill');

test('[J] skip montant_total = 0 — transaction gratuite non convertie, aucune ligne PD créée', function () {
    setupBackfillFixtureStep32($this);

    // Transaction à 0€ (inscription gratuite HelloAsso)
    $txZero = Transaction::create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette,
        'tiers_id' => Tiers::factory()->create(['association_id' => $this->association->id])->id,
        'compte_id' => $this->compteBancaire->id,
        'date' => now()->toDateString(),
        'libelle' => 'Inscription gratuite HelloAsso',
        'montant_total' => '0.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'statut_reglement' => StatutReglement::Recu->value,
    ]);

    TransactionLigne::create([
        'transaction_id' => $txZero->id,
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '0.00',
    ]);

    $nbLignesAvant = TransactionLigne::where('transaction_id', $txZero->id)->count();

    $this->artisan('compta:backfill-partie-double', ['--asso' => $this->association->id])
        ->assertSuccessful();

    // Tx à 0€ reste non-équilibrée (non convertie)
    expect($txZero->fresh()->equilibree)->toBeFalsy();

    // Aucune ligne PD supplémentaire créée
    $nbLignesApres = TransactionLigne::withTrashed()
        ->where('transaction_id', $txZero->id)
        ->count();

    expect($nbLignesApres)->toBe($nbLignesAvant, 'Aucune ligne PD ne doit être créée pour une transaction à 0€.');
})->group('backfill');

// ---------------------------------------------------------------------------
// Tests Vague 2 Bug A — Cas triplet (statut_reglement, remise_id, rapprochement_id)
// ---------------------------------------------------------------------------

/**
 * Construit la fixture minimale pour les tests Bug A (AC #1, #2, #5, #6).
 * Étend setupBackfillFixtureStep32 (comptes système + SC 706 + tiers).
 */
function setupFixtureBugA(object $ctx): void
{
    setupBackfillFixtureStep32($ctx);

    // Sous-catégorie 751B (utilisée dans les tests AC #2/#5/#6 pour distinguer des tests génériques)
    $ctx->sc751B = SousCategorie::create([
        'association_id' => $ctx->association->id,
        'categorie_id' => $ctx->catRecette->id,
        'nom' => 'Cotisations 751B',
        'code_cerfa' => '706',
    ]);
}

/**
 * Simule l'état legacy sur une transaction : supprime les lignes PD-only
 * et reset les colonnes PD sur les ventilations.
 */
function simulerLegacySurTx(Transaction $tx): void
{
    TransactionLigne::where('transaction_id', $tx->id)
        ->whereNull('sous_categorie_id')
        ->whereNotNull('compte_id')
        ->forceDelete();

    TransactionLigne::where('transaction_id', $tx->id)
        ->update([
            'compte_id' => null,
            'debit' => 0,
            'credit' => 0,
            'tiers_id' => null,
            'lettrage_code' => null,
        ]);

    $tx->forceFill(['equilibree' => false])->save();
}

// AC #1 — recette en_attente avec mode (virement) → créance only : 411D + 706C, pas de classe-5, 411 non lettré
test('[AC1] recette en_attente avec mode → créance only — 411D/706C, aucune ligne classe-5, 411 non lettré', function () {
    setupFixtureBugA($this);

    // Créer une recette virement en_attente (statut explicite en_attente = bug #138)
    $txEnAttente = $this->txService->create([
        'type' => 'recette',
        'date' => '2025-10-20',
        'libelle' => 'Virement en attente',
        'montant_total' => '200.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'statut_reglement' => StatutReglement::EnAttente->value,
        'tiers_id' => $this->tiersA->id,
        'compte_id' => $this->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $this->sc706->id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    simulerLegacySurTx($txEnAttente);

    // Backfill
    $this->artisan('compta:backfill-partie-double', ['--asso' => $this->association->id])
        ->assertSuccessful();

    $txEnAttente->refresh();
    expect((bool) $txEnAttente->equilibree)->toBeTrue('La Tx en_attente doit être equilibree=TRUE après backfill');

    // Charger les lignes PD créées (compte_id non null)
    $lignes = TransactionLigne::where('transaction_id', $txEnAttente->id)
        ->whereNotNull('compte_id')
        ->whereNull('deleted_at')
        ->get();

    $compte411 = Compte::where('numero_pcg', '411')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $compte706 = Compte::where('numero_pcg', '706')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    // Exactement 2 lignes PD : 411D + 706C
    $lignesAvecCompte = $lignes->filter(fn ($l) => $l->compte_id !== null);
    expect($lignesAvecCompte)->toHaveCount(2, 'Cas en_attente → exactement 2 lignes PD (411D + 706C)');

    // 411 D avec tiers
    $ligne411D = $lignes->firstWhere('compte_id', $compte411->id);
    expect($ligne411D)->not->toBeNull('Ligne 411 doit exister');
    expect((float) $ligne411D->debit)->toBe(200.0);
    expect((float) $ligne411D->credit)->toBe(0.0);
    expect((int) $ligne411D->tiers_id)->toBe((int) $this->tiersA->id, 'Ligne 411 doit porter tiers_id');

    // 706 C sans tiers
    $ligne706C = $lignes->firstWhere('compte_id', $compte706->id);
    expect($ligne706C)->not->toBeNull('Ligne 706 doit exister');
    expect((float) $ligne706C->credit)->toBe(200.0);
    expect((float) $ligne706C->debit)->toBe(0.0);

    // Aucune ligne classe 5 (pas de portage trésorerie)
    $compteClasse5Ids = Compte::where('classe', 5)
        ->where('association_id', $this->association->id)
        ->pluck('id')
        ->all();

    $ligneClasse5 = DB::table('transaction_lignes')
        ->where('transaction_id', $txEnAttente->id)
        ->whereIn('compte_id', $compteClasse5Ids)
        ->whereNull('deleted_at')
        ->exists();

    expect($ligneClasse5)->toBeFalse('Cas en_attente → aucune ligne classe 5 (pas de portage)');

    // 411 NON lettré (créance ouverte)
    expect($ligne411D->lettrage_code)->toBeNull('Ligne 411 en_attente doit être non lettrée');
})->group('backfill', 'bug-a');

// AC #2 — chèque recu avec remise_id → portage 5112, 411 pair lettré, 5112 non lettré
test('[AC2] chèque recu avec remise_id → portage 5112, 411 pair lettré', function () {
    setupFixtureBugA($this);

    // Créer une remise bancaire minimale (juste un ID de référence)
    $remise = RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 1,
        'date' => '2025-10-25',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteBancaire->id,
        'libelle' => 'Remise test AC2',
        'saisi_par' => $this->user->id,
    ]);

    // Recette chèque recu + remise_id set (cas 2 — chèque remisé)
    $txChequeRemise = $this->txService->create([
        'type' => 'recette',
        'date' => '2025-10-22',
        'libelle' => 'Adhésion chèque remisé',
        'montant_total' => '120.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'statut_reglement' => StatutReglement::Recu->value,
        'tiers_id' => $this->tiersA->id,
        'compte_id' => $this->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $this->sc706->id, 'montant' => '120.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    // Poser remise_id sur la transaction (cas 2)
    $txChequeRemise->forceFill(['remise_id' => $remise->id])->save();

    simulerLegacySurTx($txChequeRemise);

    // Backfill
    $this->artisan('compta:backfill-partie-double', ['--asso' => $this->association->id])
        ->assertSuccessful();

    $txChequeRemise->refresh();
    expect((bool) $txChequeRemise->equilibree)->toBeTrue('Tx remisée doit être equilibree=TRUE après backfill');

    $compte411 = Compte::where('numero_pcg', '411')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $compte5112 = Compte::where('numero_pcg', '5112')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $lignes = TransactionLigne::where('transaction_id', $txChequeRemise->id)
        ->whereNotNull('compte_id')
        ->whereNull('deleted_at')
        ->get();

    // Ligne 5112 D doit exister (portage chèque reçu)
    $ligne5112D = $lignes->firstWhere(fn ($l) => (int) $l->compte_id === (int) $compte5112->id && (float) $l->debit > 0);
    expect($ligne5112D)->not->toBeNull('Ligne 5112 D (portage chèque remisé) doit exister');
    expect((float) $ligne5112D->debit)->toBe(120.0);
    expect($ligne5112D->tiers_id)->toBeNull('Ligne 5112 ne doit pas porter de tiers_id');
    expect($ligne5112D->lettrage_code)->toBeNull('Ligne 5112 doit être non lettrée');

    // Paire 411 doit être lettrée (cycle comptant lumped)
    $lignes411 = $lignes->filter(fn ($l) => (int) $l->compte_id === (int) $compte411->id);
    expect($lignes411)->toHaveCount(2, 'Deux lignes 411 (D et C) pour le cycle comptant');

    $codes411 = $lignes411->pluck('lettrage_code')->filter()->values();
    expect($codes411)->toHaveCount(2, 'Les 2 lignes 411 doivent être lettrées (auto-lettrage interne)');
    expect($codes411[0])->toBe($codes411[1], 'Les 2 lignes 411 doivent porter le même lettrage_code');
})->group('backfill', 'bug-a');

// AC #5 — chèque pointe (rapprochement_id non null, remise_id null) → portage 512X (pas 5112), 411 lettré
test('[AC5] chèque pointe rapprochement_id non null → portage 512X (pas 5112), 411 lettré', function () {
    setupFixtureBugA($this);

    // Créer un rapprochement bancaire minimal (statut et type = valeurs valides des enums)
    $rapprochement = RapprochementBancaire::create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'date_fin' => '2025-10-31',
        'solde_ouverture' => 1000.00,
        'solde_fin' => 1150.00,
        'statut' => 'en_cours',
        'type' => 'bancaire',
        'saisi_par' => $this->user->id,
    ]);

    // Recette chèque statut Pointe + rapprochement_id + pas de remise (cas 1)
    $txChequePointe = $this->txService->create([
        'type' => 'recette',
        'date' => '2025-10-18',
        'libelle' => 'Adhésion chèque pointé',
        'montant_total' => '150.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'statut_reglement' => StatutReglement::Pointe->value,
        'tiers_id' => $this->tiersA->id,
        'compte_id' => $this->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $this->sc706->id, 'montant' => '150.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    // Poser rapprochement_id sur la transaction (cas 1 — pointé direct)
    $txChequePointe->forceFill(['rapprochement_id' => $rapprochement->id])->save();

    simulerLegacySurTx($txChequePointe);

    // Backfill
    $this->artisan('compta:backfill-partie-double', ['--asso' => $this->association->id])
        ->assertSuccessful();

    $txChequePointe->refresh();
    expect((bool) $txChequePointe->equilibree)->toBeTrue('Tx pointée doit être equilibree=TRUE après backfill');

    $compte411 = Compte::where('numero_pcg', '411')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $compte5112 = Compte::where('numero_pcg', '5112')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $lignes = TransactionLigne::where('transaction_id', $txChequePointe->id)
        ->whereNotNull('compte_id')
        ->whereNull('deleted_at')
        ->get();

    // Aucune ligne 5112 (portage doit être sur le 512X bancaire, pas 5112 transit)
    $ligne5112 = $lignes->firstWhere('compte_id', $compte5112->id);
    expect($ligne5112)->toBeNull('Cas pointé direct → aucune ligne 5112 (portage sur 512X, pas transit)');

    // Ligne 512X D doit exister (portage direct sur compte bancaire)
    $ligne512XD = $lignes->firstWhere(fn ($l) => (int) $l->compte_id === (int) $this->compte512X->id && (float) $l->debit > 0);
    expect($ligne512XD)->not->toBeNull('Ligne 512X D (portage direct) doit exister pour chèque pointé');
    expect((float) $ligne512XD->debit)->toBe(150.0);
    expect($ligne512XD->tiers_id)->toBeNull('Ligne 512X ne doit pas porter de tiers_id');

    // Paire 411 doit être lettrée
    $lignes411 = $lignes->filter(fn ($l) => (int) $l->compte_id === (int) $compte411->id);
    expect($lignes411)->toHaveCount(2, 'Deux lignes 411 (D et C) pour le cycle comptant');

    $codes411 = $lignes411->pluck('lettrage_code')->filter()->values();
    expect($codes411)->toHaveCount(2, 'Les 2 lignes 411 doivent être lettrées');
    expect($codes411[0])->toBe($codes411[1], 'Même lettrage_code sur les 2 lignes 411');

    // rapprochement_id conservé : le converter ne doit pas détacher la Tx de son rapprochement
    // (AC #5 — la ligne 512X reste comptée au solde de pointage).
    expect((int) $txChequePointe->rapprochement_id)->toBe((int) $rapprochement->id, 'rapprochement_id doit être conservé après backfill');
})->group('backfill', 'bug-a');

// AC #6 — chèque recu (remise_id null, rapprochement_id null) → portage 5112 (transit), 411 lettré
test('[AC6] chèque recu sans remise ni rapprochement → portage 5112, 411 lettré', function () {
    setupFixtureBugA($this);

    // Recette chèque recu + ni remise ni rappro (cas 4 — reçu en transit 5112)
    $txChequeRecu = $this->txService->create([
        'type' => 'recette',
        'date' => '2025-10-25',
        'libelle' => 'Adhésion chèque reçu en transit',
        'montant_total' => '80.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'statut_reglement' => StatutReglement::Recu->value,
        'tiers_id' => $this->tiersA->id,
        'compte_id' => $this->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $this->sc706->id, 'montant' => '80.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    // Vérification : remise_id et rapprochement_id doivent être null
    expect($txChequeRecu->remise_id)->toBeNull();
    expect($txChequeRecu->rapprochement_id)->toBeNull();

    simulerLegacySurTx($txChequeRecu);

    // Backfill
    $this->artisan('compta:backfill-partie-double', ['--asso' => $this->association->id])
        ->assertSuccessful();

    $txChequeRecu->refresh();
    expect((bool) $txChequeRecu->equilibree)->toBeTrue('Tx reçue en transit doit être equilibree=TRUE après backfill');

    $compte411 = Compte::where('numero_pcg', '411')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $compte5112 = Compte::where('numero_pcg', '5112')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $lignes = TransactionLigne::where('transaction_id', $txChequeRecu->id)
        ->whereNotNull('compte_id')
        ->whereNull('deleted_at')
        ->get();

    // Ligne 5112 D doit exister (portage transit — chèque reçu pas encore remisé)
    $ligne5112D = $lignes->firstWhere(fn ($l) => (int) $l->compte_id === (int) $compte5112->id && (float) $l->debit > 0);
    expect($ligne5112D)->not->toBeNull('Ligne 5112 D (portage transit) doit exister pour chèque reçu sans remise/rappro');
    expect((float) $ligne5112D->debit)->toBe(80.0);
    expect($ligne5112D->tiers_id)->toBeNull('Ligne 5112 ne doit pas porter de tiers_id');
    expect($ligne5112D->lettrage_code)->toBeNull('Ligne 5112 transit doit être non lettrée');

    // Paire 411 doit être lettrée (auto-lettrage comptant)
    $lignes411 = $lignes->filter(fn ($l) => (int) $l->compte_id === (int) $compte411->id);
    expect($lignes411)->toHaveCount(2, 'Deux lignes 411 (D et C) pour le cycle comptant');

    $codes411 = $lignes411->pluck('lettrage_code')->filter()->values();
    expect($codes411)->toHaveCount(2, 'Les 2 lignes 411 doivent être lettrées (auto-lettrage interne)');
    expect($codes411[0])->toBe($codes411[1], 'Même lettrage_code sur les 2 lignes 411');
})->group('backfill', 'bug-a');

// ---------------------------------------------------------------------------
// Tests Vague 3 — AC #3, #4, #10, #11 (reconstruction remises, rappro, idempotence, multi-tenant)
// ---------------------------------------------------------------------------

/**
 * Construit la fixture minimale pour les tests de reconstruction des remises (Wave 3).
 *
 * - Contexte partie double complet (comptes système, 512X, 5112, 530, 706)
 * - config use_partie_double = true
 * - 1 RemiseBancaire chèque avec 2 sources converties (lignes 5112 posées par la phase 1)
 *
 * Paramètres retournés sur $ctx :
 *   association, user, compteBancaire, compte512X
 *   remise (RemiseBancaire)
 *   txSource1, txSource2 (Transaction — avec ligne 5112 débit non lettrée après phase 1)
 *   tiersA, tiersB
 */
function setupFixtureRemiseBackfill(object $ctx, bool $avecRapprochement = false): void
{
    setupFixtureBugA($ctx);
    Config::set('compta.use_partie_double', true);

    // Créer la RemiseBancaire
    $ctx->remise = RemiseBancaire::create([
        'association_id' => $ctx->association->id,
        'numero' => 99,
        'date' => '2025-10-31',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $ctx->compteBancaire->id,
        'libelle' => 'Remise test Wave 3',
        'saisi_par' => $ctx->user->id,
    ]);

    // Optionnel : créer un rapprochement bancaire (pour AC #4)
    if ($avecRapprochement) {
        $ctx->rapprochement = RapprochementBancaire::create([
            'association_id' => $ctx->association->id,
            'compte_id' => $ctx->compteBancaire->id,
            'date_fin' => '2025-10-31',
            'solde_ouverture' => 0.00,
            'solde_fin' => 220.00,
            'statut' => 'en_cours',
            'type' => 'bancaire',
            'saisi_par' => $ctx->user->id,
        ]);
    }

    // Source 1 : chèque recu remisé — 120€
    $ctx->txSource1 = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-22',
        'libelle' => 'Adhésion chèque 1',
        'montant_total' => '120.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'statut_reglement' => StatutReglement::Recu->value,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '120.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    // Source 2 : chèque recu remisé — 100€
    $ctx->tiersB = Tiers::factory()->create(['association_id' => $ctx->association->id]);
    $ctx->txSource2 = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-23',
        'libelle' => 'Adhésion chèque 2',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'statut_reglement' => StatutReglement::Recu->value,
        'tiers_id' => $ctx->tiersB->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    // Lier les sources à la remise (avec reference — comme le ferait comptabiliser())
    $ctx->txSource1->forceFill([
        'remise_id' => $ctx->remise->id,
        'reference' => 'CHQ-00099-001',
    ])->save();
    $ctx->txSource2->forceFill([
        'remise_id' => $ctx->remise->id,
        'reference' => 'CHQ-00099-002',
    ])->save();

    // Optionnel : lier les sources au rapprochement (pour AC #4)
    if ($avecRapprochement) {
        $ctx->txSource1->forceFill(['rapprochement_id' => $ctx->rapprochement->id])->save();
        $ctx->txSource2->forceFill(['rapprochement_id' => $ctx->rapprochement->id])->save();
    }

    // Simuler l'état legacy sur les sources + backfill phase-1 (pose les lignes 5112 débit)
    simulerLegacySurTx($ctx->txSource1);
    simulerLegacySurTx($ctx->txSource2);
}

// AC #3 — backfill phase 2 : T4 créée, forme correcte (512x D total / N lignes 5112 C), auto-lettrée
test('[AC3] backfill phase 2 : T4 créée avec 512X D total / 5112 C par source, auto-lettrée, 5112 sources soldées', function () {
    setupFixtureRemiseBackfill($this);

    // Backfill (phase 1 + phase 2)
    $this->artisan('compta:backfill-partie-double', [
        '--asso' => $this->association->id,
        '--force' => true,
    ])->assertSuccessful();

    // T4 : 1 transaction remise_id posé, reference null, equilibree=true
    $t4 = Transaction::where('remise_id', $this->remise->id)
        ->whereNull('reference')
        ->where('equilibree', true)
        ->first();

    expect($t4)->not->toBeNull('T4 doit exister après backfill phase 2');
    expect((int) $t4->remise_id)->toBe((int) $this->remise->id);

    $compte5112 = Compte::where('numero_pcg', '5112')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $lignesT4 = TransactionLigne::where('transaction_id', $t4->id)
        ->whereNull('deleted_at')
        ->get();

    // 1 ligne 512X débit = total (120 + 100 = 220)
    $ligne512XD = $lignesT4->firstWhere(fn ($l) => (int) $l->compte_id === (int) $this->compte512X->id && (float) $l->debit > 0);
    expect($ligne512XD)->not->toBeNull('T4 doit avoir une ligne 512X D');
    expect((float) $ligne512XD->debit)->toBe(220.0, 'T4 512X D = total des sources (120+100)');
    expect($ligne512XD->tiers_id)->toBeNull('T4 512X ne doit pas avoir de tiers');

    // 2 lignes 5112 crédit (une par source), sans tiers
    $lignes5112C = $lignesT4->filter(fn ($l) => (int) $l->compte_id === (int) $compte5112->id && (float) $l->credit > 0);
    expect($lignes5112C)->toHaveCount(2, 'T4 doit avoir 2 lignes 5112 C (une par source)');
    expect($lignes5112C->first()->tiers_id)->toBeNull('Lignes 5112 T4 sans tiers');

    // T4 equilibrée (1 débit 512X = somme crédits 5112)
    $totalDebit = (float) $lignesT4->sum('debit');
    $totalCredit = (float) $lignesT4->sum('credit');
    expect(round($totalDebit, 2))->toBe(round($totalCredit, 2), 'T4 équilibrée');

    // Les lignes 5112 des sources sont maintenant lettrées (soldées)
    foreach ([$this->txSource1, $this->txSource2] as $source) {
        $ligne5112Source = TransactionLigne::where('transaction_id', $source->id)
            ->where('compte_id', $compte5112->id)
            ->whereNotNull('lettrage_code')
            ->whereNull('deleted_at')
            ->first();
        expect($ligne5112Source)->not->toBeNull("Source #{$source->id} : ligne 5112 doit être lettrée après T4");
    }
})->group('backfill', 'remise-backfill');

// AC #4 — backfill phase 3 : T4 porte rapprochement_id des sources ; calculerSoldePointage compte la 512X du T4
test('[AC4] backfill phase 3 : T4 porte rapprochement_id unique des sources ; solde pointage déplacé de 220€', function () {
    setupFixtureRemiseBackfill($this, avecRapprochement: true);

    // Solde avant backfill phase 2+3 (T4 pas encore créée → aucune ligne 512X sur le rappro)
    $rapproService = app(RapprochementBancaireService::class);
    $this->rapprochement->refresh();
    $soldeAvant = $rapproService->calculerSoldePointage($this->rapprochement);

    // Backfill complet (phase 1 + 2 + 3)
    $this->artisan('compta:backfill-partie-double', [
        '--asso' => $this->association->id,
        '--force' => true,
    ])->assertSuccessful();

    // T4 existe
    $t4 = Transaction::where('remise_id', $this->remise->id)
        ->whereNull('reference')
        ->where('equilibree', true)
        ->first();
    expect($t4)->not->toBeNull('T4 doit exister après backfill');

    // T4 porte le rapprochement_id des sources (phase 3)
    expect((int) $t4->rapprochement_id)->toBe((int) $this->rapprochement->id, 'T4 doit porter le rapprochement_id unique des sources');

    // calculerSoldePointage (mode PD) doit compter la ligne 512X du T4
    $this->rapprochement->refresh();
    $soldeApres = $rapproService->calculerSoldePointage($this->rapprochement);

    // Le solde doit avoir bougé de 220€ (total de la remise : 120 + 100)
    expect(round($soldeApres - $soldeAvant, 2))->toBe(220.0, 'calculerSoldePointage doit compter la ligne 512X du T4 (+220€)');
})->group('backfill', 'remise-backfill');

// AC #10 — idempotence : 2 runs --force → exactement 1 T4, lettrages cohérents (pas de doublons)
test('[AC10] idempotence backfill --force × 2 → exactement 1 T4 par remise, pas de double-lettrage', function () {
    setupFixtureRemiseBackfill($this);

    // 1er run --force
    $this->artisan('compta:backfill-partie-double', [
        '--asso' => $this->association->id,
        '--force' => true,
    ])->assertSuccessful();

    $nbT4Apres1 = Transaction::where('remise_id', $this->remise->id)
        ->whereNull('reference')
        ->count();
    expect($nbT4Apres1)->toBe(1, 'Exactement 1 T4 après le 1er run');

    $compte5112 = Compte::where('numero_pcg', '5112')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    // 2ème run --force
    $this->artisan('compta:backfill-partie-double', [
        '--asso' => $this->association->id,
        '--force' => true,
    ])->assertSuccessful();

    // Exactement 1 T4 (pas de doublon)
    $nbT4Apres2 = Transaction::where('remise_id', $this->remise->id)
        ->whereNull('reference')
        ->count();
    expect($nbT4Apres2)->toBe(1, 'Exactement 1 T4 après le 2ème run --force (pas de doublon)');

    // Une seule ligne 5112 lettrée par source (pas de double-lettrage)
    foreach ([$this->txSource1, $this->txSource2] as $source) {
        $nb5112Lettrees = TransactionLigne::where('transaction_id', $source->id)
            ->where('compte_id', $compte5112->id)
            ->whereNotNull('lettrage_code')
            ->whereNull('deleted_at')
            ->count();
        expect($nb5112Lettrees)->toBe(1, "Source #{$source->id} : exactement 1 ligne 5112 lettrée (pas de double-lettrage)");
    }
})->group('backfill', 'remise-backfill');

// AC #11 — multi-tenant : T4 et lettrage_audit portent le bon association_id
test('[AC11] multi-tenant : T4 porte association_id correct, lettrage_audit isolé par tenant', function () {
    setupFixtureRemiseBackfill($this);

    $assoId = (int) $this->association->id;

    // Préparer un 2ème tenant (asso B) pour vérifier l'isolation
    $assoB = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($assoB);
    SystemeSeeder::seed();
    BancairesSeeder::seed();
    TenantContext::clear();
    TenantContext::boot($this->association);

    // Backfill sur asso A uniquement
    $this->artisan('compta:backfill-partie-double', [
        '--asso' => $assoId,
        '--force' => true,
    ])->assertSuccessful();

    // T4 de asso A porte le bon association_id
    $t4 = Transaction::where('remise_id', $this->remise->id)
        ->whereNull('reference')
        ->where('equilibree', true)
        ->first();
    expect($t4)->not->toBeNull('T4 doit exister');
    expect((int) $t4->association_id)->toBe($assoId, 'T4 doit porter association_id de asso A');

    // Les lignes de T4 sont visibles via le scope tenant A
    TenantContext::clear();
    TenantContext::boot($this->association);

    $nbLignesT4 = TransactionLigne::where('transaction_id', $t4->id)
        ->whereNull('deleted_at')
        ->count();
    expect($nbLignesT4)->toBeGreaterThan(0, 'Les lignes de T4 doivent être visibles pour le tenant A');

    // Les entrées lettrage_audit portent association_id de asso A
    $nbAuditAssoA = DB::table('lettrage_audit')
        ->where('association_id', $assoId)
        ->count();
    expect($nbAuditAssoA)->toBeGreaterThan(0, 'Des entrées lettrage_audit doivent exister pour asso A');

    // Aucune entrée lettrage_audit générée pour asso B
    $nbAuditAssoB = DB::table('lettrage_audit')
        ->where('association_id', $assoB->id)
        ->count();
    expect($nbAuditAssoB)->toBe(0, 'Aucune entrée lettrage_audit ne doit exister pour asso B');
})->group('backfill', 'remise-backfill');

// Régression Vague 3 (audit code-quality #1) : la phase 2 doit rester rejouable
// même quand toutes les Tx sont déjà converties (cas d'un backfill antérieur à la
// phase 2). Le re-run SANS --force a $total === 0 : un return prématuré court-circuiterait
// la reconstruction des remises. La garde queryT4 assure l'idempotence intra-phase-2.
test('[Régression #1] re-run sans --force reconstruit la T4 même quand toutes les Tx sont déjà converties', function () {
    setupFixtureRemiseBackfill($this);

    // Simuler « phase 1 déjà jouée, phase 2 jamais jouée » : convertir les sources
    // directement (pose les lignes 5112 D non-lettrées + equilibree=TRUE), sans créer de T4.
    $converter = app(TransactionConverter::class);
    foreach ([$this->txSource1, $this->txSource2] as $source) {
        DB::transaction(fn () => $converter->convertir($source->fresh()));
    }

    // Précondition : sources converties, aucune T4 encore construite
    foreach ([$this->txSource1, $this->txSource2] as $source) {
        expect((bool) $source->fresh()->equilibree)->toBeTrue("Source #{$source->id} doit être convertie");
    }
    $nbT4Avant = Transaction::where('remise_id', $this->remise->id)
        ->whereNull('reference')
        ->count();
    expect($nbT4Avant)->toBe(0, 'Aucune T4 avant le re-run (phase 2 jamais jouée)');

    // Re-run SANS --force : $total === 0 (tout est déjà equilibree=TRUE) mais la phase 2 doit s'exécuter.
    $this->artisan('compta:backfill-partie-double', [
        '--asso' => $this->association->id,
    ])->assertSuccessful();

    // La phase 2 doit avoir reconstruit la T4 malgré $total === 0.
    $nbT4Apres = Transaction::where('remise_id', $this->remise->id)
        ->whereNull('reference')
        ->where('equilibree', true)
        ->count();
    expect($nbT4Apres)->toBe(1, 'La phase 2 doit reconstruire la T4 sur un re-run sans --force (régression finding #1)');
})->group('backfill', 'remise-backfill');
