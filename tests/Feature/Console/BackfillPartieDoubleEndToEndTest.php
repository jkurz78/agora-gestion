<?php

declare(strict_types=1);

/**
 * Step 35 — Tests end-to-end BackfillPartieDoubleCommand sur exercice complet.
 *
 * Test [L] : fixture représentative exercice complet (au moins 1 cas par ligne du tableau §8.3) :
 *   - 3 recettes comptant chèque (dont 2 remisées)
 *   - 2 recettes virement
 *   - 1 recette à crédit + encaissement (lettrage 411)
 *   - 2 dépenses comptant virement
 *   - 1 dépense chèque
 *   - 1 dépense à crédit (dette 401)
 *   - 1 facture validée + encaissement
 *
 * Workflow :
 *   1. Créer les transactions via les vrais services (avec PD activé) → état "après backfill"
 *   2. Capturer les résultats CR en mode PD (état de référence)
 *   3. Simuler l'état legacy : supprimer lignes PD-only, reset equilibree=FALSE
 *   4. Exécuter backfill → vérifier equilibree=TRUE sur toutes les Tx
 *   5. Comparer CR pré/post backfill : tolérance 0,00€
 *   6. Vérifier les invariants (équilibre, tiers 411, pas tiers classe 5)
 *   7. Vérifier performance : logguer la durée, asserter < 30s
 *
 * Décision PostBackfillValidator :
 *   Stratégie retenue = appel direct des builders CR et rappro dans ce test
 *   (pas de Artisan::call('test') qui serait fragile et interdirait le RefreshDatabase).
 *   Un PostBackfillValidator autonome serait sur-ingénierie pour 1 seul caller.
 */

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\FactureService;
use App\Services\Rapports\CompteResultatBuilder;
use App\Services\RemiseBancaireService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::clear();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    // Comptes système
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
    $this->iban = 'FR7612345000012345678901234';
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => $this->iban,
        'solde_initial' => 1000.00,
        'date_solde_initial' => '2025-09-01',
        'actif_recettes_depenses' => true,
    ]);
    BancairesSeeder::seed();
    $this->compte512X = Compte::where('iban', $this->iban)
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    // Catégories + sous-catégories + comptes PCG
    $this->catRecette = Categorie::factory()->recette()->create([
        'association_id' => $this->association->id,
        'nom' => 'Prestations',
    ]);
    $this->sc706 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->catRecette->id,
        'nom' => 'Cotisations membres',
        'code_cerfa' => '706',
    ]);
    $this->compte706 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '706'],
        [
            'intitule' => 'Cotisations membres',
            'classe' => 7,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'categorie_id' => $this->catRecette->id,
        ]
    );

    $this->catDepense = Categorie::factory()->depense()->create([
        'association_id' => $this->association->id,
        'nom' => 'Charges de fonctionnement',
    ]);
    $this->sc606 = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->catDepense->id,
        'nom' => 'Fournitures et petits matériels',
        'code_cerfa' => '606',
    ]);
    $this->compte606 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '606'],
        [
            'intitule' => 'Fournitures et petits matériels',
            'classe' => 6,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'categorie_id' => $this->catDepense->id,
        ]
    );

    $this->tiersA = Tiers::factory()->create(['association_id' => $this->association->id]);
    $this->tiersB = Tiers::factory()->create(['association_id' => $this->association->id]);

    $this->txService = app(TransactionService::class);
    $this->factureService = app(FactureService::class);
    $this->remiseService = app(RemiseBancaireService::class);
    $this->crBuilder = app(CompteResultatBuilder::class);

    $this->exercice = 2025;
});

afterEach(function () {
    Config::set('compta.use_partie_double', false);
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Construit la fixture d'exercice complet via les vrais services (état PD natif).
 * Retourne les IDs des transactions créées.
 *
 * @return list<int>
 */
function creerFixtureE2E(object $ctx): array
{
    $txIds = [];

    // R1 : Recette comptant chèque — 706 (100€)
    $txR1 = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-05',
        'libelle' => 'Adhésion chèque A',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);
    $txIds[] = $txR1->id;

    // R2 : Recette comptant chèque — 706 (150€)
    $txR2 = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-08',
        'libelle' => 'Adhésion chèque B',
        'montant_total' => '150.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $ctx->tiersB->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '150.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);
    $txIds[] = $txR2->id;

    // R3 : Recette comptant chèque — 706 (80€)
    $txR3 = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-12',
        'libelle' => 'Adhésion chèque C',
        'montant_total' => '80.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '80.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);
    $txIds[] = $txR3->id;

    // R4 : Recette virement — 706 (250€)
    $txR4 = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-10',
        'libelle' => 'Subvention mairie virement',
        'montant_total' => '250.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $ctx->tiersB->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '250.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);
    $txIds[] = $txR4->id;

    // R5 : Recette virement — 706 (300€)
    $txR5 = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'libelle' => 'Subvention région virement',
        'montant_total' => '300.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '300.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);
    $txIds[] = $txR5->id;

    // R6 : Recette à crédit — 706 (180€, tiers A)
    $txR6 = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-11-01',
        'libelle' => 'Formation stage créance',
        'montant_total' => '180.00',
        'mode_paiement' => null,
        'tiers_id' => $ctx->tiersB->id,
        'compte_id' => null,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '180.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);
    $txIds[] = $txR6->id;

    // Encaissement R6 (chèque)
    $txEnc = $ctx->txService->create([
        'type' => 'recette',
        'date' => '2025-11-20',
        'libelle' => 'Encaissement formation stage',
        'montant_total' => '180.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $ctx->tiersB->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc706->id, 'montant' => '180.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);
    $txIds[] = $txEnc->id;

    // D1 : Dépense comptant virement — 606 (120€)
    $txD1 = $ctx->txService->create([
        'type' => 'depense',
        'date' => '2025-10-15',
        'libelle' => 'Fournitures bureau virement',
        'montant_total' => '120.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc606->id, 'montant' => '120.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);
    $txIds[] = $txD1->id;

    // D2 : Dépense comptant virement — 606 (80€)
    $txD2 = $ctx->txService->create([
        'type' => 'depense',
        'date' => '2025-11-05',
        'libelle' => 'Petites fournitures virement',
        'montant_total' => '80.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $ctx->tiersB->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc606->id, 'montant' => '80.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);
    $txIds[] = $txD2->id;

    // D3 : Dépense comptant chèque — 606 (75€)
    $txD3 = $ctx->txService->create([
        'type' => 'depense',
        'date' => '2025-10-20',
        'libelle' => 'Fournitures chèque',
        'montant_total' => '75.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => $ctx->compteBancaire->id,
    ], [
        ['sous_categorie_id' => $ctx->sc606->id, 'montant' => '75.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);
    $txIds[] = $txD3->id;

    // D4 : Dépense à crédit — 606 (150€, tiers A)
    $txD4 = $ctx->txService->create([
        'type' => 'depense',
        'date' => '2025-11-10',
        'libelle' => 'Assurance dette',
        'montant_total' => '150.00',
        'mode_paiement' => null,
        'tiers_id' => $ctx->tiersA->id,
        'compte_id' => null,
    ], [
        ['sous_categorie_id' => $ctx->sc606->id, 'montant' => '150.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);
    $txIds[] = $txD4->id;

    // Facture validée (T1 créance + T2 encaissement)
    // Note : compte_bancaire_id = id du CompteBancaire (pas du Compte 512X)
    // pour que la T1 ait compte_id non null et que le backfill puisse la convertir.
    $facture = Facture::create([
        'association_id' => $ctx->association->id,
        'date' => '2025-12-01',
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $ctx->tiersB->id,
        'saisi_par' => $ctx->user->id,
        'exercice' => 2025,
        'montant_total' => 0,
        'mode_paiement_prevu' => ModePaiement::Virement->value,
        'compte_bancaire_id' => $ctx->compteBancaire->id,
    ]);
    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel->value,
        'sous_categorie_id' => $ctx->sc706->id,
        'libelle' => 'Formation hiver',
        'montant' => 400.00,
        'ordre' => 1,
    ]);
    $ctx->factureService->valider($facture);
    $facture->refresh();

    $t1Facture = $facture->transactions()->first();
    if ($t1Facture !== null) {
        $txIds[] = $t1Facture->id;
        $ctx->factureService->marquerReglementRecu($facture, [$t1Facture->id]);
        $facture->refresh();
        $t2Facture = $facture->transactions()->where('id', '!=', $t1Facture->id)->first();
        if ($t2Facture !== null) {
            $txIds[] = $t2Facture->id;
        }
    }

    // Remise : 2 chèques (R1 + R2) remisés
    $compte5112 = Compte::where('numero_pcg', '5112')
        ->where('association_id', $ctx->association->id)
        ->first();
    if ($compte5112 !== null) {
        $ligne5112R1 = TransactionLigne::where('transaction_id', $txR1->id)
            ->where('compte_id', $compte5112->id)
            ->first();
        $ligne5112R2 = TransactionLigne::where('transaction_id', $txR2->id)
            ->where('compte_id', $compte5112->id)
            ->first();

        if ($ligne5112R1 !== null && $ligne5112R2 !== null) {
            $remise = $ctx->remiseService->creer([
                'date' => '2025-10-25',
                'mode_paiement' => ModePaiement::Cheque->value,
                'compte_cible_id' => $ctx->compteBancaire->id,
            ]);
            $ctx->remiseService->comptabiliser($remise, [$txR1->id, $txR2->id]);
        }
    }

    return array_values(array_unique($txIds));
}

/**
 * Simule l'état legacy : supprime les lignes PD-only et reset equilibree=FALSE.
 * Reproduit setupBackfillFixtureStep33Legacy mais en bulk sur un array de txIds.
 *
 * Important : les T4 de remise bancaire (remise_id IS NOT NULL, reference IS NULL) sont
 * exclues de la simulation legacy car elles sont des transactions PD-pure (pas de lignes
 * de ventilation legacy). En production, elles ne se trouvent jamais dans un état legacy.
 *
 * @param  list<int>  $txIds
 */
function simulerEtatLegacyE2E(array $txIds): void
{
    // Identifier les transactions PD-pures (aucune ligne avec sous_categorie_id non null).
    // Ces transactions n'ont jamais eu d'état legacy :
    //   - T4 de remise bancaire (remise_id non null, reference null)
    //   - T2 d'encaissement facture / créance (créées par pourEncaissementCreance / marquerReglementRecu)
    //   - T2 de règlement fournisseur (créées par pourReglementFournisseur)
    // En production, ces Tx sont toujours equilibree=TRUE dès leur création.
    // Le backfill ne doit pas les traiter.
    $txPDPures = [];
    foreach ($txIds as $txId) {
        $hasLegacyLines = DB::table('transaction_lignes')
            ->where('transaction_id', $txId)
            ->whereNotNull('sous_categorie_id')
            ->whereNull('deleted_at')
            ->exists();
        if (! $hasLegacyLines) {
            $txPDPures[] = $txId;
        }
    }

    $txIdsLegacy = array_values(array_diff($txIds, $txPDPures));

    if (empty($txIdsLegacy)) {
        return;
    }

    // Supprimer les lignes PD-only (sous_categorie_id null + compte_id non null)
    TransactionLigne::whereIn('transaction_id', $txIdsLegacy)
        ->whereNull('sous_categorie_id')
        ->whereNotNull('compte_id')
        ->forceDelete();

    // Reset colonnes PD sur les lignes de ventilation
    TransactionLigne::whereIn('transaction_id', $txIdsLegacy)
        ->update([
            'compte_id' => null,
            'debit' => 0,
            'credit' => 0,
            'tiers_id' => null,
            'lettrage_code' => null,
        ]);

    // Marquer toutes les Tx legacy equilibree=FALSE
    Transaction::whereIn('id', $txIdsLegacy)->update(['equilibree' => false]);
}

/**
 * Calcule le CR total (produits - charges) depuis CompteResultatBuilder.
 *
 * @return array{charges_total: float, produits_total: float, solde: float, index: array<string, float>}
 */
function crSnapshot(object $crBuilder, int $exercice): array
{
    $cr = $crBuilder->compteDeResultat($exercice);

    $chargesTotal = (float) array_sum(array_map(fn ($c) => (float) ($c['montant_n'] ?? $c['montant'] ?? 0.0), $cr['charges']));
    $produitsTotal = (float) array_sum(array_map(fn ($c) => (float) ($c['montant_n'] ?? $c['montant'] ?? 0.0), $cr['produits']));

    // Index label => montant pour comparaison ligne à ligne
    $index = [];
    foreach (array_merge($cr['charges'], $cr['produits']) as $row) {
        $label = $row['label'];
        $montant = (float) ($row['montant_n'] ?? $row['montant'] ?? 0.0);
        $index[$label] = ($index[$label] ?? 0.0) + $montant;
    }

    return [
        'charges_total' => $chargesTotal,
        'produits_total' => $produitsTotal,
        'solde' => $produitsTotal - $chargesTotal,
        'index' => $index,
    ];
}

// ---------------------------------------------------------------------------
// Test [L] — End-to-end exercice complet
// ---------------------------------------------------------------------------

test('[L] backfill end-to-end exercice complet — toutes Tx equilibree=TRUE, CR identique pré/post', function () {
    $startTime = microtime(true);

    // Étape 1 : Créer la fixture via les vrais services (état PD natif)
    $txIds = creerFixtureE2E($this);

    // Étape 2 : Capturer CR en mode PD (référence "après backfill")
    Config::set('compta.use_partie_double', true);
    $crAvantLegacy = crSnapshot($this->crBuilder, $this->exercice);
    Config::set('compta.use_partie_double', false);

    // Étape 3 : Simuler l'état legacy
    simulerEtatLegacyE2E($txIds);

    // Vérifier que les Tx sont bien marquées legacy
    $txLegacy = Transaction::whereIn('id', $txIds)
        ->where(function ($q) {
            $q->where('equilibree', false)->orWhereNull('equilibree');
        })
        ->count();
    expect($txLegacy)->toBeGreaterThan(0, 'La simulation legacy doit marquer au moins 1 Tx comme non-équilibrée');

    // Étape 4 : Exécuter le backfill
    $this->artisan('compta:backfill-partie-double', [
        '--exercice' => '2025',
        '--asso' => $this->association->id,
    ])->assertSuccessful();

    $elapsed = microtime(true) - $startTime;

    // Étape 5 : Vérifier toutes les Tx equilibree=TRUE
    // Note : les Tx sans tiers (OD-like) et celles avec SC sans code_cerfa peuvent rester FALSE
    // La fixture E2E n'en a pas — toutes doivent être TRUE
    $txNonEquilibrees = Transaction::whereIn('id', $txIds)
        ->where(function ($q) {
            $q->where('equilibree', false)->orWhereNull('equilibree');
        })
        ->count();

    // Étape 6 : Vérifier les invariants sur toutes les Tx
    $compte411 = Compte::where('numero_pcg', '411')
        ->where('association_id', $this->association->id)
        ->first();
    $compte401 = Compte::where('numero_pcg', '401')
        ->where('association_id', $this->association->id)
        ->first();

    $compteIds411et401 = collect([$compte411?->id, $compte401?->id])->filter()->all();
    $compteClasse5Ids = Compte::where('classe', 5)
        ->where('association_id', $this->association->id)
        ->pluck('id')
        ->all();

    // Invariant équilibre sur chaque Tx convertie (equilibree=TRUE)
    $txEquilibrees = Transaction::whereIn('id', $txIds)
        ->where('equilibree', true)
        ->pluck('id');

    foreach ($txEquilibrees as $txId) {
        $sums = DB::table('transaction_lignes')
            ->where('transaction_id', $txId)
            ->whereNull('deleted_at')
            ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->first();

        $debit = round((float) ($sums->total_debit ?? 0), 2);
        $credit = round((float) ($sums->total_credit ?? 0), 2);

        expect($debit)->toBe($credit, "Tx #{$txId} non équilibrée : débit={$debit}, crédit={$credit}");
    }

    // Invariant tiers 411/401 NOT NULL
    if (! empty($compteIds411et401)) {
        $lignesSansTiers = DB::table('transaction_lignes')
            ->whereIn('transaction_id', $txIds)
            ->whereIn('compte_id', $compteIds411et401)
            ->whereNull('tiers_id')
            ->whereNull('deleted_at')
            ->count();
        expect($lignesSansTiers)->toBe(0, 'Des lignes 411/401 sans tiers ont été trouvées.');
    }

    // Invariant pas tiers sur classe 5
    if (! empty($compteClasse5Ids)) {
        $lignesAvecTiers = DB::table('transaction_lignes')
            ->whereIn('transaction_id', $txIds)
            ->whereIn('compte_id', $compteClasse5Ids)
            ->whereNotNull('tiers_id')
            ->whereNull('deleted_at')
            ->count();
        expect($lignesAvecTiers)->toBe(0, 'Des lignes classe 5 avec tiers_id ont été trouvées.');
    }

    // Étape 7 : Comparer CR pré/post backfill (tolérance 0,00€)
    Config::set('compta.use_partie_double', true);
    $crApres = crSnapshot($this->crBuilder, $this->exercice);
    Config::set('compta.use_partie_double', false);

    // Comparaison solde global
    expect($crApres['solde'])->toEqual(
        $crAvantLegacy['solde'],
        "Solde CR pré/post backfill diverge : avant={$crAvantLegacy['solde']}€, après={$crApres['solde']}€"
    );

    // Comparaison produits total
    expect($crApres['produits_total'])->toEqual(
        $crAvantLegacy['produits_total'],
        "Total produits CR pré/post backfill diverge"
    );

    // Comparaison charges total
    expect($crApres['charges_total'])->toEqual(
        $crAvantLegacy['charges_total'],
        "Total charges CR pré/post backfill diverge"
    );

    // Performance : log warning si > 10s, pas d'assertion stricte (< 30s)
    if ($elapsed > 10.0) {
        \Illuminate\Support\Facades\Log::warning('[Backfill E2E] Performance dégradée', [
            'elapsed_seconds' => round($elapsed, 2),
            'nb_transactions' => count($txIds),
        ]);
    }

    expect($elapsed)->toBeLessThan(30.0, "Le backfill E2E a pris {$elapsed}s (> 30s — optimisation requise)");

    // Rapport final
    expect($txNonEquilibrees)->toBe(
        0,
        "Toutes les Tx de la fixture doivent être equilibree=TRUE après backfill. Non-équilibrées : {$txNonEquilibrees}"
    );
})->group('backfill-e2e');

// ---------------------------------------------------------------------------
// Test [L2] — Invariants par cas type §8.3
// ---------------------------------------------------------------------------

test('[L2] chaque cas type §8.3 produit un équilibre correct après backfill', function () {
    // Ce test vérifie les cas individuellement via les tests [F][G][H] déjà présents
    // dans BackfillPartieDoubleCommandTest.php. Ici on ajoute la vérification
    // qu'aucun cas ne génère de lignes orphelines (compte_id null sur des lignes
    // qui devraient être enrichies).

    $txIds = creerFixtureE2E($this);
    simulerEtatLegacyE2E($txIds);

    $this->artisan('compta:backfill-partie-double', [
        '--exercice' => '2025',
        '--asso' => $this->association->id,
    ])->assertSuccessful();

    // Vérifier qu'aucune ligne de ventilation (sous_categorie_id non null)
    // n'est restée sans compte_id après backfill (sauf si le skip était légitime)
    // Les Tx equilibree=TRUE doivent avoir toutes leurs lignes enrichies
    $txEquilibrees = Transaction::whereIn('id', $txIds)
        ->where('equilibree', true)
        ->pluck('id')
        ->all();

    foreach ($txEquilibrees as $txId) {
        $lignesSansCompte = DB::table('transaction_lignes')
            ->where('transaction_id', $txId)
            ->whereNotNull('sous_categorie_id')
            ->whereNull('compte_id')
            ->whereNull('deleted_at')
            ->count();

        expect($lignesSansCompte)->toBe(0,
            "Tx equilibree=TRUE #{$txId} a des lignes de ventilation sans compte_id (sous_categorie_id non null mais compte_id null)"
        );
    }

    // Sanity : au moins 1 Tx equilibree=TRUE (fixture non vide)
    expect(count($txEquilibrees))->toBeGreaterThan(0, 'La fixture doit produire au moins 1 Tx équilibrée');
})->group('backfill-e2e');
