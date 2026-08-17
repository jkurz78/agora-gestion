<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Rapports\CompteResultatBuilder;
use App\Tenant\TenantContext;

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------
//
// Ce fichier vérifie le lot « ventilation par exercice dans la hiérarchie » :
// buildUnifiedHierarchy() ajoute, à chaque nœud famille et compte, une clé
// 'exercices' (liste triée du plus récent au plus ancien, 0 toujours en
// dernier) et 'montant_exercices' (somme des entrées), sans changer aucune
// valeur existante.
//
// Horloge gelée au 2026-01-15 (tests/Pest.php) → exercice courant 2025
// (2025-09-01 → 2026-08-31). Toutes les fixtures sont datées franchement à
// l'intérieur d'une fenêtre d'exercice, jamais sur une borne.

beforeEach(function () {
    $assoc = Association::factory()->create();
    TenantContext::boot($assoc);
    $this->association = $assoc;

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();

    $tenantId = (int) TenantContext::currentId();

    $this->compte606 = Compte::create([
        'association_id' => $tenantId,
        'numero_pcg' => '606',
        'intitule' => 'Achats fournitures',
        'classe' => 6,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $this->builder = app(CompteResultatBuilder::class);
});

// ---------------------------------------------------------------------------
// Helpers locaux — préfixés hieExercices* pour éviter toute collision avec
// les helpers globaux d'autres fichiers de test (creerDepensePD existe déjà
// dans CompteResultatBuilderPartieDoubleTest.php, grainDateCreerDepense dans
// CompteResultatBuilderGrainDateTest.php).
// ---------------------------------------------------------------------------

function hieExercicesCreerDepense(
    int $tenantId,
    int $userId,
    int $compteId,
    float $montant,
    string $date,
    ?int $operationId = null,
    ?int $tiersId = null,
    ?int $seance = null,
): TransactionLigne {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $tenantId,
        'date' => $date,
        'saisi_par' => $userId,
        'tiers_id' => $tiersId,
    ]);
    $tx->lignes()->forceDelete();

    return TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => $montant,
        'compte_id' => $compteId,
        'debit' => $montant,
        'credit' => 0.0,
        'operation_id' => $operationId,
        'seance' => $seance,
    ]);
}

/**
 * Appelle fetchOperationRowsPD sans bornes de date (portée « tous les
 * exercices ») via réflexion — méthode privée.
 *
 * @param  array<int>  $operationIds
 * @return array<string, array>
 */
function hieExercicesFetchMap(
    CompteResultatBuilder $builder,
    string $type,
    array $operationIds,
    bool $withSeance,
    bool $withTiers,
    bool $withOperation,
): array {
    $method = new ReflectionMethod(CompteResultatBuilder::class, 'fetchOperationRowsPD');
    $method->setAccessible(true);

    return $method->invoke($builder, $type, null, null, $operationIds, $withSeance, $withTiers, $withOperation);
}

/**
 * Appelle buildUnifiedHierarchy() via réflexion — méthode privée.
 *
 * @param  array<string, array>  $map
 * @param  list<int>  $allSeances
 * @param  array<int>  $operationIds
 * @return list<array<string, mixed>>
 */
function hieExercicesBuildHierarchy(
    CompteResultatBuilder $builder,
    array $map,
    bool $withSeance,
    bool $withTiers,
    bool $withOperation,
    array $allSeances = [],
    array $operationIds = [],
): array {
    $method = new ReflectionMethod(CompteResultatBuilder::class, 'buildUnifiedHierarchy');
    $method->setAccessible(true);

    return $method->invoke($builder, $map, $withSeance, $withTiers, $withOperation, $allSeances, $operationIds);
}

// ---------------------------------------------------------------------------
// [HE1] deux dépenses au même compte sur deux exercices → 2 entrées triées
// ---------------------------------------------------------------------------

it('[HE1] le compte porte une entrée exercices par exercice distinct, triée du plus récent au plus ancien, avec les bons libellés', function () {
    $tenantId = (int) TenantContext::currentId();
    $op = Operation::factory()->create(['association_id' => $tenantId]);

    // Exercice 2025 (2025-09-01 → 2026-08-31)
    hieExercicesCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 100.0, '2025-10-15', (int) $op->id);
    // Exercice 2024 (2024-09-01 → 2025-08-31)
    hieExercicesCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 60.0, '2024-11-20', (int) $op->id);

    $map = hieExercicesFetchMap($this->builder, 'depense', [(int) $op->id], false, false, false);
    $charges = hieExercicesBuildHierarchy($this->builder, $map, false, false, false);

    expect($charges)->toHaveCount(1);
    $sc = $charges[0]['comptes'][0];

    expect($sc['exercices'])->toHaveCount(2);

    expect($sc['exercices'][0]['annee'])->toBe(2025);
    expect((float) $sc['exercices'][0]['montant'])->toBe(100.0);
    expect($sc['exercices'][0]['label'])->toBe('2025-2026');

    expect($sc['exercices'][1]['annee'])->toBe(2024);
    expect((float) $sc['exercices'][1]['montant'])->toBe(60.0);
    expect($sc['exercices'][1]['label'])->toBe('2024-2025');
});

// ---------------------------------------------------------------------------
// [HE2] montant_exercices = somme des entrées, au niveau compte ET famille
// ---------------------------------------------------------------------------

it('[HE2] montant_exercices égale la somme des entrées exercices, au niveau compte et au niveau famille', function () {
    $tenantId = (int) TenantContext::currentId();
    $op = Operation::factory()->create(['association_id' => $tenantId]);

    hieExercicesCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 100.0, '2025-10-15', (int) $op->id);
    hieExercicesCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 60.0, '2024-11-20', (int) $op->id);

    $map = hieExercicesFetchMap($this->builder, 'depense', [(int) $op->id], false, false, false);
    $charges = hieExercicesBuildHierarchy($this->builder, $map, false, false, false);

    $cat = $charges[0];
    $sc = $cat['comptes'][0];

    expect((float) $sc['montant_exercices'])->toBe(160.0);
    expect((float) $cat['montant_exercices'])->toBe(160.0);
});

// ---------------------------------------------------------------------------
// [HE3] invariant — montant/seances/operations/tiers du compte inchangés
// ---------------------------------------------------------------------------
//
// Construit deux lignes réparties sur deux exercices, deux séances et deux
// tiers distincts, avec toutes les dimensions actives. Si accumulerExercice()
// écrivait par erreur dans le nœud parent (au lieu de sa seule sous-entrée
// d'exercice), chaque montant global serait doublé — ce test le détecterait
// immédiatement (100.0 attendu, 200.0 si pollution).

it('[HE3] montant, seances, operations et tiers du compte gardent exactement leurs valeurs — non pollués par l\'accumulation par exercice', function () {
    $tenantId = (int) TenantContext::currentId();
    $op = Operation::factory()->create(['association_id' => $tenantId]);
    $tiersA = Tiers::factory()->create(['association_id' => $tenantId]);
    $tiersB = Tiers::factory()->create(['association_id' => $tenantId]);

    // Exercice 2025, séance 1, tiersA
    hieExercicesCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 70.0, '2025-10-15', (int) $op->id, (int) $tiersA->id, 1);
    // Exercice 2024, séance 2, tiersB
    hieExercicesCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 30.0, '2024-11-20', (int) $op->id, (int) $tiersB->id, 2);

    $map = hieExercicesFetchMap($this->builder, 'depense', [(int) $op->id], true, true, true);
    $allSeances = [1, 2];
    $charges = hieExercicesBuildHierarchy($this->builder, $map, true, true, true, $allSeances, [(int) $op->id]);

    $sc = $charges[0]['comptes'][0];

    // Totaux globaux inchangés — sémantique identique à avant ce lot.
    expect((float) $sc['montant'])->toBe(100.0);
    expect((float) $sc['seances'][1])->toBe(70.0);
    expect((float) $sc['seances'][2])->toBe(30.0);
    expect((float) $sc['operations'][(int) $op->id])->toBe(100.0);

    expect($sc['tiers'])->toHaveCount(2);
    $tiersParId = collect($sc['tiers'])->keyBy('tiers_id');
    expect((float) $tiersParId[(int) $tiersA->id]['montant'])->toBe(70.0);
    expect((float) $tiersParId[(int) $tiersB->id]['montant'])->toBe(30.0);
});

// ---------------------------------------------------------------------------
// [HE4] parTiers actif : chaque entrée d'exercice porte ses propres tiers
// ---------------------------------------------------------------------------

it('[HE4] avec parTiers actif, chaque entrée exercices porte ses propres tiers — un tiers présent sur un seul exercice n\'apparaît que là', function () {
    $tenantId = (int) TenantContext::currentId();
    $op = Operation::factory()->create(['association_id' => $tenantId]);
    $tiersA = Tiers::factory()->create(['association_id' => $tenantId]);
    $tiersB = Tiers::factory()->create(['association_id' => $tenantId]);

    // tiersA uniquement en 2025
    hieExercicesCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 50.0, '2025-10-15', (int) $op->id, (int) $tiersA->id);
    // tiersB uniquement en 2024
    hieExercicesCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 40.0, '2024-11-20', (int) $op->id, (int) $tiersB->id);

    $map = hieExercicesFetchMap($this->builder, 'depense', [(int) $op->id], false, true, false);
    $charges = hieExercicesBuildHierarchy($this->builder, $map, false, true, false);

    $sc = $charges[0]['comptes'][0];
    $exercices = collect($sc['exercices'])->keyBy('annee');

    $ex2025 = $exercices[2025];
    $ex2024 = $exercices[2024];

    expect($ex2025['tiers'])->toHaveCount(1);
    expect((int) $ex2025['tiers'][0]['tiers_id'])->toBe((int) $tiersA->id);
    expect((float) $ex2025['tiers'][0]['montant'])->toBe(50.0);

    expect($ex2024['tiers'])->toHaveCount(1);
    expect((int) $ex2024['tiers'][0]['tiers_id'])->toBe((int) $tiersB->id);
    expect((float) $ex2024['tiers'][0]['montant'])->toBe(40.0);
});

// ---------------------------------------------------------------------------
// [HE5] parSeances + parOperations actifs : les maps existent par exercice
// ---------------------------------------------------------------------------

it('[HE5] avec parSeances et parOperations actifs, seances/operations/seance_operations existent dans chaque entrée exercices et somment correctement', function () {
    $tenantId = (int) TenantContext::currentId();
    $op = Operation::factory()->create(['association_id' => $tenantId]);

    hieExercicesCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 45.0, '2025-10-15', (int) $op->id, null, 1);
    hieExercicesCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 55.0, '2024-11-20', (int) $op->id, null, 2);

    $map = hieExercicesFetchMap($this->builder, 'depense', [(int) $op->id], true, false, true);
    $allSeances = [1, 2];
    $opId = (int) $op->id;
    $charges = hieExercicesBuildHierarchy($this->builder, $map, true, false, true, $allSeances, [$opId]);

    $sc = $charges[0]['comptes'][0];
    $exercices = collect($sc['exercices'])->keyBy('annee');

    $ex2025 = $exercices[2025];
    expect((float) $ex2025['seances'][1])->toBe(45.0);
    expect((float) $ex2025['seances'][2])->toBe(0.0);
    expect((float) $ex2025['operations'][$opId])->toBe(45.0);
    expect((float) $ex2025['seance_operations'][1][$opId])->toBe(45.0);

    $ex2024 = $exercices[2024];
    expect((float) $ex2024['seances'][2])->toBe(55.0);
    expect((float) $ex2024['seances'][1])->toBe(0.0);
    expect((float) $ex2024['operations'][$opId])->toBe(55.0);
    expect((float) $ex2024['seance_operations'][2][$opId])->toBe(55.0);
});

// ---------------------------------------------------------------------------
// [HE6] structures de travail absentes de la sortie
// ---------------------------------------------------------------------------

it('[HE6] ni exercices_map ni tiers_map ne subsistent dans la sortie', function () {
    $tenantId = (int) TenantContext::currentId();
    $op = Operation::factory()->create(['association_id' => $tenantId]);
    $tiers = Tiers::factory()->create(['association_id' => $tenantId]);

    hieExercicesCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 45.0, '2025-10-15', (int) $op->id, (int) $tiers->id, 1);
    hieExercicesCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 55.0, '2024-11-20', (int) $op->id, (int) $tiers->id, 2);

    $map = hieExercicesFetchMap($this->builder, 'depense', [(int) $op->id], true, true, true);
    $allSeances = [1, 2];
    $opId = (int) $op->id;
    $charges = hieExercicesBuildHierarchy($this->builder, $map, true, true, true, $allSeances, [$opId]);

    $cat = $charges[0];
    $sc = $cat['comptes'][0];

    expect(array_key_exists('exercices_map', $cat))->toBeFalse();
    expect(array_key_exists('exercices_map', $sc))->toBeFalse();
    expect(array_key_exists('tiers_map', $sc))->toBeFalse();

    foreach ($sc['exercices'] as $ex) {
        expect(array_key_exists('tiers_map', $ex))->toBeFalse();
    }
});

// ---------------------------------------------------------------------------
// [HE7] tri — l'exercice 0 (« Exercice non déterminé ») se place en dernier
// ---------------------------------------------------------------------------
//
// Map forgée à la main (pas de fixture DB pour l'exercice 0 tant que les
// prévisions sans date ne sont pas branchées) : seule façon de tester la clé
// 0 dans ce lot.

it('[HE7] un exercice 0 injecté artificiellement se place après tous les exercices datés, avec le libellé « Exercice non déterminé »', function () {
    $map = [
        'k1' => [
            'exercice' => 2025,
            'famille_id' => 1,
            'famille_nom' => '70 — Test',
            'compte_id' => 10,
            'compte_nom' => 'Compte test',
            'montant' => 10.0,
        ],
        'k2' => [
            'exercice' => 2024,
            'famille_id' => 1,
            'famille_nom' => '70 — Test',
            'compte_id' => 10,
            'compte_nom' => 'Compte test',
            'montant' => 20.0,
        ],
        'k3' => [
            'exercice' => 0,
            'famille_id' => 1,
            'famille_nom' => '70 — Test',
            'compte_id' => 10,
            'compte_nom' => 'Compte test',
            'montant' => 5.0,
        ],
    ];

    $charges = hieExercicesBuildHierarchy($this->builder, $map, false, false, false);

    $sc = $charges[0]['comptes'][0];
    $annees = array_column($sc['exercices'], 'annee');

    expect($annees)->toBe([2025, 2024, 0]);

    $exZero = collect($sc['exercices'])->firstWhere('annee', 0);
    expect($exZero['label'])->toBe('Exercice non déterminé');
    expect((float) $exZero['montant'])->toBe(5.0);
    expect((float) $sc['montant_exercices'])->toBe(35.0);
});
