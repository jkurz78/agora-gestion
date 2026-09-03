<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
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
    TenantContext::boot($this->assoA);
    $compteBancaireA = CompteBancaire::factory()->create(['solde_initial' => 0]);
    $compteChargeA = Compte::factory()->depense()->numero('606A')->create(['intitule' => 'Charge tenant A']);
    $txA = Transaction::factory()->asDepense()->create([
        'compte_id' => $compteBancaireA->id,
        'date' => '2025-01-10',
        'montant_total' => 100.00,
    ]);
    $txA->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $txA->id,
        'compte_id' => $compteChargeA->id,
        'montant' => 100.00,
        'debit' => 100.00,
        'credit' => 0.00,
    ]);

    TenantContext::boot($this->assoB);
    $compteBancaireB = CompteBancaire::factory()->create(['solde_initial' => 0]);
    $compteChargeB = Compte::factory()->depense()->numero('606B')->create(['intitule' => 'Charge tenant B']);
    $txB = Transaction::factory()->asDepense()->create([
        'compte_id' => $compteBancaireB->id,
        'date' => '2025-01-11',
        'montant_total' => 9876.00,
    ]);
    $txB->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $txB->id,
        'compte_id' => $compteChargeB->id,
        'montant' => 9876.00,
        'debit' => 9876.00,
        'credit' => 0.00,
    ]);

    TenantContext::boot($this->assoA);
    $builder = app(CompteResultatBuilder::class);
    $result = $builder->compteDeResultat(2024);
    $charges = collect($result['charges'])->flatMap(fn (array $famille): array => $famille['comptes']);
    $chargeA = $charges->firstWhere('compte_id', (int) $compteChargeA->id);

    expect($charges)->toHaveCount(1)
        ->and($chargeA)->not->toBeNull()
        ->and((float) $chargeA['montant_n'])->toBe(100.0)
        ->and($charges->pluck('compte_id')->all())->not->toContain((int) $compteChargeB->id)
        ->and((float) collect($result['charges'])->sum('montant_n'))->toBe(100.0);
});

it('CompteResultatBuilder fetchBudgetRows does not leak cross-tenant budget lines', function () {
    // Both tenants have a budget for the SAME compte_id (compte belongs to A).
    // fetchBudgetRows() joins `comptes` to fabricate a line (libellé, famille)
    // for every budgeted compte, even one with no movement — so it must scope
    // BOTH `bl.association_id` (the budget row) AND `c.association_id` (the
    // compte it joins) to the current tenant. Without the second scope, a
    // budget row planted by B on A's compte_id makes A's compte (its id, its
    // intitulé, its famille) surface in B's rapport too.

    TenantContext::boot($this->assoA);
    $compteA = CompteBancaire::factory()->create(['solde_initial' => 0]);
    $compteVentilationA = Compte::factory()->depense()->create();
    $intituleCompteVentilationA = (string) $compteVentilationA->intitule;
    $txA = Transaction::factory()->asDepense()->create([
        'compte_id' => $compteA->id,
        'date' => '2025-01-10',
        'montant_total' => 100.00,
    ]);
    $txA->lignes()->update(['compte_id' => $compteVentilationA->id]);
    DB::table('budget_lines')->insert([
        'association_id' => $this->assoA->id,
        'exercice' => 2024,
        'compte_id' => $compteVentilationA->id,
        'montant_prevu' => 200.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Tenant B inserts a budget for the SAME compte_id (cross-tenant collision).
    DB::table('budget_lines')->insert([
        'association_id' => $this->assoB->id,
        'exercice' => 2024,
        'compte_id' => $compteVentilationA->id,
        'montant_prevu' => 9999.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Du point de vue du tenant A, le budget du compte reste 200 €, sans les 9 999 € du tenant B.
    TenantContext::boot($this->assoA);
    $builder = app(CompteResultatBuilder::class);
    $result = $builder->compteDeResultat(2024);

    // Extraire toutes les valeurs de budget dans la hiérarchie famille/comptes.
    $extractBudgets = function (array $rows): array {
        $budgets = [];
        foreach ($rows as $cat) {
            if (isset($cat['budget']) && $cat['budget'] !== null) {
                $budgets[] = (float) $cat['budget'];
            }
            foreach ($cat['comptes'] ?? [] as $sc) {
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

    // Direction manquante : côté tenant B, le compte de A ne doit apparaître
    // NULLE PART — ni son compte_id, ni son intitulé, ni les 9 999 € que B a
    // budgétés dessus. C'est le sens qui casse sans le scope sur
    // `c.association_id` dans fetchBudgetRows() : ce compte est de classe 6,
    // il apparaîtrait donc en entier dans les CHARGES de B, avec l'intitulé
    // et la famille de A.
    TenantContext::boot($this->assoB);
    $resultB = $builder->compteDeResultat(2024);
    $comptesB = collect(array_merge($resultB['charges'], $resultB['produits']))
        ->flatMap(fn (array $famille): array => $famille['comptes']);

    expect($comptesB->pluck('compte_id')->all())->not->toContain((int) $compteVentilationA->id)
        ->and($comptesB->pluck('compte_nom')->all())->not->toContain($intituleCompteVentilationA)
        ->and($comptesB->pluck('budget')->filter(fn ($b) => $b !== null)->all())->not->toContain(9999.0);
});
