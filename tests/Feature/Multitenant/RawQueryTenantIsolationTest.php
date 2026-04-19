<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Services\Rapports\CompteResultatBuilder;
use App\Services\TransactionUniverselleService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Regression tests for cross-tenant data exposure in raw DB::table() queries.
 * See: docs/semgrep-s6-report.md — findings #1 and #2.
 *
 * Both CompteResultatBuilder and TransactionUniverselleService used DB::table()
 * without filtering by association_id, meaning data from all tenants was mixed.
 */
beforeEach(function () {
    TenantContext::clear();

    $this->assoA = Association::factory()->create();
    $this->assoB = Association::factory()->create();
});

afterEach(function () {
    TenantContext::clear();
});

// ── TransactionUniverselleService ─────────────────────────────────────────────

it('TransactionUniverselleService does not leak cross-tenant data when compteId and tiersId are null', function () {
    // Tenant A : 1 dépense
    TenantContext::boot($this->assoA);
    $compteA = CompteBancaire::factory()->create(['solde_initial' => 0]);
    Transaction::factory()->asDepense()->create([
        'compte_id' => $compteA->id,
        'date' => '2025-01-15',
    ]);

    // Tenant B : 1 dépense
    TenantContext::boot($this->assoB);
    $compteB = CompteBancaire::factory()->create(['solde_initial' => 0]);
    Transaction::factory()->asDepense()->create([
        'compte_id' => $compteB->id,
        'date' => '2025-01-15',
    ]);

    // Requête depuis la perspective du tenant A — sans filtre compte ni tiers
    TenantContext::boot($this->assoA);
    $svc = app(TransactionUniverselleService::class);
    $result = $svc->paginate(null, null, ['depense'], null, null, null, null, null, null, null, null);

    // Doit retourner uniquement la dépense du tenant A
    expect($result['paginator']->total())->toBe(1);
});

it('TransactionUniverselleService does not expose other-tenant recettes', function () {
    TenantContext::boot($this->assoA);
    $compteA = CompteBancaire::factory()->create(['solde_initial' => 0]);
    Transaction::factory()->asRecette()->create(['compte_id' => $compteA->id, 'date' => '2025-02-10']);

    TenantContext::boot($this->assoB);
    $compteB = CompteBancaire::factory()->create(['solde_initial' => 0]);
    Transaction::factory()->asRecette()->create(['compte_id' => $compteB->id, 'date' => '2025-02-10']);

    TenantContext::boot($this->assoA);
    $svc = app(TransactionUniverselleService::class);
    $result = $svc->paginate(null, null, ['recette'], null, null, null, null, null, null, null, null);

    expect($result['paginator']->total())->toBe(1);
});

// ── CompteResultatBuilder ─────────────────────────────────────────────────────

it('CompteResultatBuilder::compteDeResultat does not aggregate other-tenant charges', function () {
    // Tenant A : 1 dépense on exercice 2024 (date sept 2024 - août 2025)
    TenantContext::boot($this->assoA);
    $compteA = CompteBancaire::factory()->create(['solde_initial' => 0]);
    $txA = Transaction::factory()->asDepense()->create([
        'compte_id' => $compteA->id,
        'date' => '2025-01-10',
        'montant_total' => 100.00,
    ]);

    // Tenant B : 1 dépense with same date range but a distinct large amount
    TenantContext::boot($this->assoB);
    $compteB = CompteBancaire::factory()->create(['solde_initial' => 0]);
    Transaction::factory()->asDepense()->create([
        'compte_id' => $compteB->id,
        'date' => '2025-01-11',
        'montant_total' => 9876.00,
    ]);

    // Requête depuis la perspective du tenant A
    TenantContext::boot($this->assoA);
    $builder = app(CompteResultatBuilder::class);
    $result = $builder->compteDeResultat(2024); // exercice 2024-2025

    // The total of all charges for tenant A must not include tenant B's 9876€
    $totalCharges = (float) collect($result['charges'])->sum('montant_n');
    expect($totalCharges)->not->toBeGreaterThanOrEqual(9876.0);
});

it('CompteResultatBuilder fetchBudgetMap does not leak cross-tenant budget lines', function () {
    // Both tenants have a transaction + budget for the SAME sous_categorie_id.
    // Without the fix, fetchBudgetMap SUMs both tenants' budgets for that sous-categorie.

    TenantContext::boot($this->assoA);
    $compteA = CompteBancaire::factory()->create(['solde_initial' => 0]);
    $souscatA = SousCategorie::factory()->create();
    $txA = Transaction::factory()->asDepense()->create([
        'compte_id' => $compteA->id,
        'date' => '2025-01-10',
        'montant_total' => 100.00,
    ]);
    $txA->lignes()->update(['sous_categorie_id' => $souscatA->id]);
    DB::table('budget_lines')->insert([
        'association_id' => $this->assoA->id,
        'exercice' => 2024,
        'sous_categorie_id' => $souscatA->id,
        'montant_prevu' => 200.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Tenant B inserts a budget for THE SAME sous_categorie_id (cross-tenant collision).
    // This is the realistic scenario: in a shared DB, IDs from table A can appear in table B.
    DB::table('budget_lines')->insert([
        'association_id' => $this->assoB->id,
        'exercice' => 2024,
        'sous_categorie_id' => $souscatA->id, // intentionally same sous_categorie_id
        'montant_prevu' => 9999.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // From tenant A's perspective, budget for souscatA must be 200€, not 200+9999=10199€
    TenantContext::boot($this->assoA);
    $builder = app(CompteResultatBuilder::class);
    $result = $builder->compteDeResultat(2024);

    // The result is a hierarchie: [['categorie_nom' => ..., 'budget' => ..., 'sous_categories' => [...]]]
    // We need to extract all 'budget' values from sous_categories within charges and produits
    $extractBudgets = function (array $rows): array {
        $budgets = [];
        foreach ($rows as $cat) {
            if (isset($cat['budget']) && $cat['budget'] !== null) {
                $budgets[] = (float) $cat['budget'];
            }
            foreach ($cat['sous_categories'] ?? [] as $sc) {
                if (isset($sc['budget']) && $sc['budget'] !== null) {
                    $budgets[] = (float) $sc['budget'];
                }
            }
        }

        return $budgets;
    };

    $allBudgets = array_merge(
        $extractBudgets($result['charges']),
        $extractBudgets($result['produits']),
    );

    // Without the fix, some budget value would be >= 9999 (10199 = 200 + 9999).
    // With the fix, max budget value should be 200.
    expect(collect($allBudgets)->contains(fn ($v) => $v >= 9999.0))->toBeFalse();
});
