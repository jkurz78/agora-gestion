<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Rapports\CompteResultatBuilder;
use App\Services\Rapports\ProjectionMatrix;
use App\Tenant\TenantContext;

/*
 * Projections ventilées par exercice.
 *
 * La règle de projection — « au grain le plus fin, le réalisé s'il existe,
 * sinon le prévu » — s'appliquait sur une matrice unique, tous exercices
 * confondus. Conséquence : dès qu'une année portait du réalisé sur une
 * cellule, la prévision d'une AUTRE année sur la même cellule disparaissait,
 * masquée par un réalisé qui ne la concernait pas. Une opération pluriannuelle
 * dont l'an 1 est réalisé et l'an 3 seulement planifié perdait son an 3.
 *
 * ⚠️ L'horloge des tests est gelée au 2026-01-15 (tests/Pest.php), donc
 * l'exercice affiché est 2025 : du 2025-09-01 au 2026-08-31. Les fixtures sont
 * datées franchement à l'intérieur des fenêtres visées, jamais sur une borne —
 * SQLite exclut le dernier jour d'un whereBetween date-only.
 */

beforeEach(function () {
    $assoc = Association::factory()->create();
    TenantContext::boot($assoc);
    $this->association = $assoc;

    $this->user = User::factory()->create();
    $this->user->associations()->attach($assoc->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();

    $tenantId = (int) TenantContext::currentId();

    $this->compteCharge = Compte::create([
        'association_id' => $tenantId, 'numero_pcg' => '622', 'intitule' => 'Encadrement',
        'classe' => 6, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
    ]);
    $this->compteProduit = Compte::create([
        'association_id' => $tenantId, 'numero_pcg' => '706', 'intitule' => 'Prestations',
        'classe' => 7, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
    ]);

    $type = TypeOperation::factory()->create([
        'association_id' => $tenantId,
        'compte_id' => $this->compteProduit->id,
    ]);
    $this->operation = Operation::factory()->create([
        'association_id' => $tenantId,
        'nom' => 'Op pluriannuelle',
        'type_operation_id' => $type->id,
    ]);

    $this->intervenant = Tiers::factory()->create([
        'association_id' => $tenantId,
        'nom' => 'FORMATEUR',
        'prenom' => 'Alex',
    ]);

    $this->builder = app(CompteResultatBuilder::class);
});

afterEach(function () {
    TenantContext::clear();
});

/**
 * Dépense réelle imputée sur l'opération du test, à une séance donnée.
 *
 * Le tiers est celui des prévisions : la règle de projection opère au grain
 * (compte, tiers, séance, opération), donc un réalisé porté par un autre tiers
 * ne recouvre PAS la prévision — il s'y ajoute. Aligner le tiers est ce qui
 * rend les cellules comparables.
 */
function projExDepense(int $compteId, int $operationId, float $montant, string $date, int $seance): void
{
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => (int) TenantContext::currentId(),
        'date' => $date,
        'saisi_par' => auth()->id(),
        'tiers_id' => test()->intervenant->id,
    ]);
    $tx->lignes()->forceDelete();

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => $montant,
        'compte_id' => $compteId,
        'operation_id' => $operationId,
        'seance' => $seance,
        'debit' => $montant,
        'credit' => 0.0,
    ]);
}

/**
 * Prévision d'encadrement rattachée à une séance, datée ou non.
 *
 * `encadrement_previsions.tiers_id` est NOT NULL : la prévision est toujours
 * portée par un intervenant, on en crée donc un.
 */
function projExPrevision(int $compteId, int $operationId, float $montant, ?string $dateSeance, int $numero): void
{
    $seance = Seance::create([
        'association_id' => (int) TenantContext::currentId(),
        'operation_id' => $operationId,
        'numero' => $numero,
        'date' => $dateSeance,
    ]);

    EncadrementPrevision::create([
        'association_id' => (int) TenantContext::currentId(),
        'operation_id' => $operationId,
        'tiers_id' => test()->intervenant->id,
        'seance_id' => $seance->id,
        'compte_id' => $compteId,
        'montant_prevu' => $montant,
    ]);
}

/**
 * @return array{charges: array<int, ProjectionMatrix>, produits: array<int, ProjectionMatrix>}
 */
function projExCompute(CompteResultatBuilder $builder, ?string $start, ?string $end, array $operationIds): array
{
    $methode = new ReflectionMethod(CompteResultatBuilder::class, 'computeProjections');

    return $methode->invoke($builder, $start, $end, $operationIds);
}

it('sépare les matrices par exercice au lieu de masquer la prévision d\'une autre année', function () {
    // Même cellule (compte, tiers, séance, opération) : réalisé en 2025,
    // prévision en 2027. Avec une matrice unique, le réalisé écrasait la
    // prévision et l'an 3 disparaissait.
    projExDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10', 1);
    projExPrevision((int) $this->compteCharge->id, (int) $this->operation->id, 300.0, '2027-10-10', 1);

    $matrices = projExCompute($this->builder, null, null, [(int) $this->operation->id])['charges'];

    expect(array_keys($matrices))->toBe([2027, 2025])
        ->and($matrices[2025]->total())->toBe(100.0)
        ->and($matrices[2027]->total())->toBe(300.0);
});

it('trie les exercices du plus récent au plus ancien', function () {
    projExDepense((int) $this->compteCharge->id, (int) $this->operation->id, 10.0, '2023-10-10', 1);
    projExDepense((int) $this->compteCharge->id, (int) $this->operation->id, 20.0, '2025-10-10', 1);
    projExDepense((int) $this->compteCharge->id, (int) $this->operation->id, 30.0, '2024-10-10', 1);

    $matrices = projExCompute($this->builder, null, null, [(int) $this->operation->id])['charges'];

    expect(array_keys($matrices))->toBe([2025, 2024, 2023]);
});

it('range une prévision de séance non datée dans la matrice de l\'exercice 0', function () {
    projExPrevision((int) $this->compteCharge->id, (int) $this->operation->id, 42.0, null, 1);

    $matrices = projExCompute($this->builder, null, null, [(int) $this->operation->id])['charges'];

    expect(array_keys($matrices))->toBe([0])
        ->and($matrices[0]->total())->toBe(42.0);
});

it('applique la règle réel-sinon-prévu à l\'intérieur d\'un même exercice', function () {
    // Même cellule, même exercice : le réalisé gagne, la prévision est ignorée.
    projExDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10', 1);
    projExPrevision((int) $this->compteCharge->id, (int) $this->operation->id, 999.0, '2025-10-12', 1);

    $matrices = projExCompute($this->builder, null, null, [(int) $this->operation->id])['charges'];

    expect(array_keys($matrices))->toBe([2025])
        ->and($matrices[2025]->total())->toBe(100.0);
});

it('en portée courante la matrice fusionnée reproduit le total d\'avant', function () {
    // Invariant : la portée courante n'expose qu'un exercice daté, donc la
    // fusion doit valoir exactement ce que produisait la matrice unique.
    // Cellules qui se recouvrent (le réalisé gagne) et cellules disjointes.
    projExDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10', 1);
    projExPrevision((int) $this->compteCharge->id, (int) $this->operation->id, 999.0, '2025-10-12', 1);
    projExPrevision((int) $this->compteCharge->id, (int) $this->operation->id, 50.0, '2025-11-10', 2);
    // Hors exercice affiché : ne doit pas peser sur la portée courante.
    projExDepense((int) $this->compteCharge->id, (int) $this->operation->id, 777.0, '2024-10-10', 1);

    $data = $this->builder->compteDeResultatOperations(
        2025,
        [(int) $this->operation->id],
        parSeances: true,
        parTiers: true,
        previsionnel: true,
        parOperations: true,
    );

    // 100 (réalisé, écrase la prévision de 999) + 50 (prévision seule).
    expect($data['proj_charges']->total())->toBe(150.0);
});

it('la matrice fusionnée cumule les exercices au lieu d\'en écraser un', function () {
    // Même cellule (compte, tiers, séance, opération) sur deux exercices : une
    // fusion écrite avec set() au lieu de add() en perdrait une, en silence.
    projExDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10', 1);
    projExDepense((int) $this->compteCharge->id, (int) $this->operation->id, 60.0, '2024-10-10', 1);

    $matrices = projExCompute($this->builder, null, null, [(int) $this->operation->id])['charges'];

    $fusion = new ReflectionMethod(CompteResultatBuilder::class, 'fusionnerMatrices');
    $fusionnee = $fusion->invoke($this->builder, $matrices);

    expect(array_keys($matrices))->toBe([2025, 2024])
        ->and($matrices[2025]->total())->toBe(100.0)
        ->and($matrices[2024]->total())->toBe(60.0)
        ->and($fusionnee->total())->toBe(160.0);
});

it('expose les matrices par exercice à côté des clés historiques', function () {
    projExDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10', 1);

    $data = $this->builder->compteDeResultatOperations(
        2025,
        [(int) $this->operation->id],
        parSeances: true,
        parTiers: true,
        previsionnel: true,
        parOperations: true,
    );

    expect($data['proj_charges'])->toBeInstanceOf(ProjectionMatrix::class)
        ->and($data['proj_produits'])->toBeInstanceOf(ProjectionMatrix::class)
        ->and($data['proj_charges_par_exercice'])->toBeArray()
        ->and($data['proj_produits_par_exercice'])->toBeArray();
});
