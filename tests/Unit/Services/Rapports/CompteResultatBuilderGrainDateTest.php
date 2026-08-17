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
// Ce fichier vérifie l'invariant du lot « fondations multi-exercices » :
// fetchOperationRowsPD() descend au grain date (une ligne SQL par date), mais
// l'accumulation PHP doit fusionner à l'identique — aucun total ne bouge.

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
// Helpers locaux — noms préfixés grainDate* pour éviter toute collision avec
// les helpers globaux d'autres fichiers de test (creerDepensePD existe déjà
// dans CompteResultatBuilderPartieDoubleTest.php).
// ---------------------------------------------------------------------------

function grainDateCreerDepense(
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

// ---------------------------------------------------------------------------
// Non-régression — ce test doit passer AVANT et APRÈS le changement de grain.
// ---------------------------------------------------------------------------

it('[GD1] trois dépenses à des dates différentes du même exercice sur le même compte se somment, comme avant', function () {
    $tenantId = (int) TenantContext::currentId();
    $op = Operation::factory()->create(['association_id' => $tenantId]);

    // Exercice 2025 = 2025-09-01 → 2026-08-31 (horloge de test gelée au 2026-01-15).
    grainDateCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 100.0, '2025-10-05', (int) $op->id);
    grainDateCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 50.0, '2025-12-20', (int) $op->id);
    grainDateCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 25.0, '2026-03-10', (int) $op->id);

    $result = $this->builder->compteDeResultatOperations(2025, [(int) $op->id]);

    $charges = $result['charges'];
    expect($charges)->toHaveCount(1);

    $cat = $charges[0];
    expect((float) $cat['montant'])->toBe(175.0);
    expect($cat['comptes'])->toHaveCount(1);
    expect((float) $cat['comptes'][0]['montant'])->toBe(175.0);
});

it('[GD2] deux dépenses au même compte, même tiers, même séance mais dates différentes ne font qu\'une entrée cumulée dans la hiérarchie', function () {
    $tenantId = (int) TenantContext::currentId();
    $op = Operation::factory()->create(['association_id' => $tenantId]);
    $tiers = Tiers::factory()->create(['association_id' => $tenantId]);

    grainDateCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 60.0, '2025-10-05', (int) $op->id, (int) $tiers->id, 3);
    grainDateCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 40.0, '2026-01-10', (int) $op->id, (int) $tiers->id, 3);

    $result = $this->builder->compteDeResultatOperations(
        2025,
        [(int) $op->id],
        parSeances: true,
        parTiers: true,
    );

    $charges = $result['charges'];
    expect($charges)->toHaveCount(1);

    $sc = $charges[0]['comptes'][0];
    expect((float) $sc['montant'])->toBe(100.0);

    // Une seule entrée tiers — pas une par date.
    expect($sc['tiers'])->toHaveCount(1);
    expect((float) $sc['tiers'][0]['montant'])->toBe(100.0);
    expect((float) $sc['tiers'][0]['seances'][3])->toBe(100.0);
});

// ---------------------------------------------------------------------------
// Étape rouge — prouve que la nouvelle clé (préfixée par l'exercice) sépare
// bien deux exercices distincts quand fetchOperationRowsPD est appelée sans
// bornes de date (portée « tous les exercices »).
// ---------------------------------------------------------------------------

it('[GD3] fetchOperationRowsPD sans bornes de date sépare deux exercices distincts dans la map retournée', function () {
    $tenantId = (int) TenantContext::currentId();
    $op = Operation::factory()->create(['association_id' => $tenantId]);

    // Exercice 2024 (2024-09-01 → 2025-08-31)
    grainDateCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 80.0, '2024-10-15', (int) $op->id);
    // Exercice 2025 (2025-09-01 → 2026-08-31)
    grainDateCreerDepense($tenantId, $this->user->id, (int) $this->compte606->id, 120.0, '2025-10-15', (int) $op->id);

    $method = new ReflectionMethod(CompteResultatBuilder::class, 'fetchOperationRowsPD');
    $method->setAccessible(true);

    /** @var array<string, array> $map */
    $map = $method->invoke(
        $this->builder,
        'depense',
        null,
        null,
        [(int) $op->id],
        false,
        false,
        false,
    );

    expect($map)->toHaveCount(2);

    $annees = collect($map)->pluck('exercice')->sort()->values()->all();
    expect($annees)->toBe([2024, 2025]);

    $montantsParAnnee = collect($map)->keyBy('exercice')->map(fn ($e) => (float) $e['montant'])->all();
    expect($montantsParAnnee[2024])->toBe(80.0);
    expect($montantsParAnnee[2025])->toBe(120.0);
});
