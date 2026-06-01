<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Rapports\CompteResultatBuilder;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    // GlobalBeforeEach de Pest.php a déjà booté TenantContext.
    $this->association = TenantContext::currentId()
        ? Association::find(TenantContext::currentId())
        : Association::factory()->create();

    // Reboot avec l'association créée (le global beforeEach en crée une, on la réutilise)
    $assoc = Association::factory()->create();
    TenantContext::boot($assoc);
    $this->association = $assoc;

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    // Comptes système
    SystemeSeeder::seed();

    $tenantId = (int) TenantContext::currentId();

    // Catégorie recette (pour comptes classe 7)
    $this->catRecette = Categorie::factory()->recette()->create([
        'association_id' => $tenantId,
        'nom' => 'Prestations',
    ]);

    // Catégorie dépense (pour comptes classe 6)
    $this->catDepense = Categorie::factory()->depense()->create([
        'association_id' => $tenantId,
        'nom' => 'Charges générales',
    ]);

    // Compte 706 (classe 7) lié à la catégorie recette
    $this->compte706 = Compte::create([
        'association_id' => $tenantId,
        'numero_pcg' => '706',
        'intitule' => 'Prestations de services',
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'categorie_id' => $this->catRecette->id,
    ]);

    // Compte 606 (classe 6) lié à la catégorie dépense
    $this->compte606 = Compte::create([
        'association_id' => $tenantId,
        'numero_pcg' => '606',
        'intitule' => 'Achats fournitures',
        'classe' => 6,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'categorie_id' => $this->catDepense->id,
    ]);

    // Sous-catégorie pour la création de TransactionLigne legacy
    $this->scRecette = SousCategorie::create([
        'association_id' => $tenantId,
        'categorie_id' => $this->catRecette->id,
        'nom' => 'Cotisations',
        'code_cerfa' => '706',
    ]);

    $this->scDepense = SousCategorie::create([
        'association_id' => $tenantId,
        'categorie_id' => $this->catDepense->id,
        'nom' => 'Fournitures bureau',
        'code_cerfa' => '606',
    ]);

    $this->builder = app(CompteResultatBuilder::class);

    // Feature flag PD activé pour tous ces tests
    Config::set('compta.use_partie_double', true);
});

afterEach(function () {
    Config::set('compta.use_partie_double', false);
});

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

/**
 * Crée une recette avec une TransactionLigne enrichie (compte_id + credit).
 */
function creerRecettePD(
    int $tenantId,
    int $userId,
    int $compteId,
    int $sousCategorieId,
    float $montant,
    string $date,
): TransactionLigne {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $tenantId,
        'date' => $date,
        'saisi_par' => $userId,
    ]);
    $tx->lignes()->forceDelete();

    return TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCategorieId,
        'montant' => $montant,
        'compte_id' => $compteId,
        'debit' => 0.0,
        'credit' => $montant,
    ]);
}

/**
 * Crée une dépense avec une TransactionLigne enrichie (compte_id + debit).
 */
function creerDepensePD(
    int $tenantId,
    int $userId,
    int $compteId,
    int $sousCategorieId,
    float $montant,
    string $date,
): TransactionLigne {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $tenantId,
        'date' => $date,
        'saisi_par' => $userId,
    ]);
    $tx->lignes()->forceDelete();

    return TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCategorieId,
        'montant' => $montant,
        'compte_id' => $compteId,
        'debit' => $montant,
        'credit' => 0.0,
    ]);
}

// ---------------------------------------------------------------------------
// [PD1] compteDeResultat — recette classe 7 agrégée correctement
// ---------------------------------------------------------------------------

it('[PD1] compteDeResultat — produit classe 7 agrégé par compte en mode PD', function () {
    $tenantId = (int) TenantContext::currentId();

    creerRecettePD($tenantId, $this->user->id, (int) $this->compte706->id, (int) $this->scRecette->id, 300.0, '2025-11-01');
    creerRecettePD($tenantId, $this->user->id, (int) $this->compte706->id, (int) $this->scRecette->id, 200.0, '2025-12-01');

    $result = $this->builder->compteDeResultat(2025);

    expect($result)->toHaveKeys(['charges', 'produits']);

    $produits = $result['produits'];
    expect($produits)->toHaveCount(1);

    $cat = $produits[0];
    expect($cat['label'])->toBe('Prestations');
    // En mode PD, le montant agrégé = SUM(credit) - SUM(debit) = 500
    expect((float) $cat['montant_n'])->toBe(500.0);
    expect($cat['sous_categories'])->toHaveCount(1);

    $sc = $cat['sous_categories'][0];
    // La clé 'sous_categorie_id' peut contenir compte_id en mode PD (mapping transparent)
    expect((float) $sc['montant_n'])->toBe(500.0);
});

// ---------------------------------------------------------------------------
// [PD2] compteDeResultat — charge classe 6 agrégée correctement
// ---------------------------------------------------------------------------

it('[PD2] compteDeResultat — charge classe 6 agrégée par compte en mode PD', function () {
    $tenantId = (int) TenantContext::currentId();

    creerDepensePD($tenantId, $this->user->id, (int) $this->compte606->id, (int) $this->scDepense->id, 150.0, '2025-10-01');

    $result = $this->builder->compteDeResultat(2025);

    $charges = $result['charges'];
    expect($charges)->toHaveCount(1);

    $cat = $charges[0];
    expect($cat['label'])->toBe('Charges générales');
    expect((float) $cat['montant_n'])->toBe(150.0);
});

// ---------------------------------------------------------------------------
// [PD3] compteDeResultat — lignes sans compte_id ignorées (best-effort)
// ---------------------------------------------------------------------------

it('[PD3] compteDeResultat — ligne sans compte_id (legacy pur) ignorée en mode PD', function () {
    $tenantId = (int) TenantContext::currentId();

    // Ligne sans compte_id (old-style legacy) — debit/credit à 0 (NOT NULL DEFAULT 0)
    $txLegacy = Transaction::factory()->asRecette()->create([
        'association_id' => $tenantId,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);
    $txLegacy->lignes()->forceDelete();
    TransactionLigne::create([
        'transaction_id' => $txLegacy->id,
        'sous_categorie_id' => (int) $this->scRecette->id,
        'montant' => 999.0,
        'compte_id' => null,
        'debit' => 0.0,
        'credit' => 0.0,
    ]);

    $result = $this->builder->compteDeResultat(2025);

    // La ligne sans compte_id ne doit pas apparaître en mode PD
    expect($result['produits'])->toBe([]);
});

// ---------------------------------------------------------------------------
// [PD4] compteDeResultatOperations — produit filtré par operationId
// ---------------------------------------------------------------------------

it('[PD4] compteDeResultatOperations — produit filtré par operation_id en mode PD', function () {
    $tenantId = (int) TenantContext::currentId();

    $op1 = Operation::factory()->create(['association_id' => $tenantId]);
    $op2 = Operation::factory()->create(['association_id' => $tenantId]);

    // Ligne pour op1
    $tx1 = Transaction::factory()->asRecette()->create([
        'association_id' => $tenantId,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);
    $tx1->lignes()->forceDelete();
    TransactionLigne::create([
        'transaction_id' => $tx1->id,
        'sous_categorie_id' => (int) $this->scRecette->id,
        'montant' => 100.0,
        'compte_id' => (int) $this->compte706->id,
        'debit' => 0.0,
        'credit' => 100.0,
        'operation_id' => (int) $op1->id,
    ]);

    // Ligne pour op2 (ne doit pas apparaître)
    $tx2 = Transaction::factory()->asRecette()->create([
        'association_id' => $tenantId,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);
    $tx2->lignes()->forceDelete();
    TransactionLigne::create([
        'transaction_id' => $tx2->id,
        'sous_categorie_id' => (int) $this->scRecette->id,
        'montant' => 500.0,
        'compte_id' => (int) $this->compte706->id,
        'debit' => 0.0,
        'credit' => 500.0,
        'operation_id' => (int) $op2->id,
    ]);

    $result = $this->builder->compteDeResultatOperations(2025, [(int) $op1->id]);

    $produits = $result['produits'];
    expect($produits)->toHaveCount(1);
    expect((float) $produits[0]['montant'])->toBe(100.0);
});

// ---------------------------------------------------------------------------
// [PD5] compteDeResultatOperations — charges filtrées classe 6
// ---------------------------------------------------------------------------

it('[PD5] compteDeResultatOperations — charges filtrées par operationId en mode PD', function () {
    $tenantId = (int) TenantContext::currentId();

    $op = Operation::factory()->create(['association_id' => $tenantId]);

    $txDep = Transaction::factory()->asDepense()->create([
        'association_id' => $tenantId,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);
    $txDep->lignes()->forceDelete();
    TransactionLigne::create([
        'transaction_id' => $txDep->id,
        'sous_categorie_id' => (int) $this->scDepense->id,
        'montant' => 75.0,
        'compte_id' => (int) $this->compte606->id,
        'debit' => 75.0,
        'credit' => 0.0,
        'operation_id' => (int) $op->id,
    ]);

    $result = $this->builder->compteDeResultatOperations(2025, [(int) $op->id]);

    $charges = $result['charges'];
    expect($charges)->toHaveCount(1);
    expect((float) $charges[0]['montant'])->toBe(75.0);
});

// ---------------------------------------------------------------------------
// [PD6] rapportSeances — agrégation par seance en mode PD
// ---------------------------------------------------------------------------

it('[PD6] rapportSeances — agrégation par seance en mode PD', function () {
    $tenantId = (int) TenantContext::currentId();

    $op = Operation::factory()->create(['association_id' => $tenantId]);

    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $tenantId,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => (int) $this->scRecette->id,
        'montant' => 250.0,
        'compte_id' => (int) $this->compte706->id,
        'debit' => 0.0,
        'credit' => 250.0,
        'operation_id' => (int) $op->id,
        'seance' => 3,
    ]);

    $result = $this->builder->rapportSeances(2025, [(int) $op->id]);

    expect($result)->toHaveKeys(['seances', 'charges', 'produits']);
    expect($result['seances'])->toContain(3);

    $produits = $result['produits'];
    expect($produits)->toHaveCount(1);
    $sc = $produits[0]['sous_categories'][0];
    expect((float) ($sc['seances'][3] ?? 0))->toBe(250.0);
});

// ---------------------------------------------------------------------------
// [PD7] Isolation multi-tenant — lignes d'un autre tenant ignorées
// ---------------------------------------------------------------------------

it('[PD7] lignes du tenant voisin ignorées en mode PD', function () {
    $tenantId = (int) TenantContext::currentId();

    // Tenant voisin
    $autreAssoc = Association::factory()->create();
    TenantContext::boot($autreAssoc);
    SystemeSeeder::seed();

    $autreCompte = Compte::create([
        'association_id' => (int) $autreAssoc->id,
        'numero_pcg' => '706',
        'intitule' => 'Recettes voisin',
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'categorie_id' => null,
    ]);

    $txAutre = Transaction::factory()->asRecette()->create([
        'association_id' => (int) $autreAssoc->id,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);
    $txAutre->lignes()->forceDelete();
    // TransactionLigne n'a pas de timestamps — insérer sans created_at/updated_at
    DB::table('transaction_lignes')->insert([
        'transaction_id' => $txAutre->id,
        'sous_categorie_id' => null,
        'montant' => 9999.0,
        'compte_id' => $autreCompte->id,
        'debit' => 0.0,
        'credit' => 9999.0,
    ]);

    // Revenir sur le tenant courant
    TenantContext::boot($this->association);

    $result = $this->builder->compteDeResultat(2025);
    expect($result['produits'])->toBe([]);
});

// ---------------------------------------------------------------------------
// [PD9] compteDeResultat — budget rattaché au compte en mode PD
// ---------------------------------------------------------------------------

it('[PD9] compteDeResultat — la colonne budget est rattachée au compte en mode PD', function () {
    $tenantId = (int) TenantContext::currentId();

    // Budget posé sur la sous-catégorie (clé legacy = sous_categorie_id).
    // En mode PD, le rapport agrège par compte_id → le budget doit suivre la
    // correspondance sous_categories.code_cerfa → comptes.numero_pcg.
    BudgetLine::create([
        'association_id' => $tenantId,
        'sous_categorie_id' => (int) $this->scDepense->id,
        'exercice' => 2025,
        'montant_prevu' => 250.00,
    ]);

    // Une charge réelle pour que la ligne (compte 606) apparaisse dans le rapport.
    creerDepensePD($tenantId, $this->user->id, (int) $this->compte606->id, (int) $this->scDepense->id, 150.0, '2025-10-01');

    $result = $this->builder->compteDeResultat(2025);

    $charges = $result['charges'];
    expect($charges)->toHaveCount(1);

    $cat = $charges[0];
    expect((float) $cat['budget'])->toBe(250.00);

    $sc = $cat['sous_categories'][0];
    expect((float) $sc['budget'])->toBe(250.00);
});

// ---------------------------------------------------------------------------
// [PD8] Feature flag OFF — mode legacy actif (pas de régression)
// ---------------------------------------------------------------------------

it('[PD8] feature flag OFF → mode legacy actif (pas de régression)', function () {
    Config::set('compta.use_partie_double', false);

    $tenantId = (int) TenantContext::currentId();

    // Créer une transaction legacy (sans compte_id, debit/credit = 0 — NOT NULL DEFAULT 0)
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $tenantId,
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => (int) $this->scRecette->id,
        'montant' => 400.0,
        'compte_id' => null,
        'debit' => 0.0,
        'credit' => 0.0,
    ]);

    $result = $this->builder->compteDeResultat(2025);

    // Le mode legacy doit voir la ligne (via sous_categorie_id)
    expect($result['produits'])->toHaveCount(1);
    expect((float) $result['produits'][0]['montant_n'])->toBe(400.0);
});
