<?php

declare(strict_types=1);

use App\Livewire\Portail\MesActivites;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Alice ne voit pas les activités de Bob dans la même asso
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] Alice ne voit pas les activités de Bob dans la même asso', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id, 'nom' => 'Parcours']);

    $alice = Tiers::factory()->create(['association_id' => $asso->id, 'email' => 'alice@ex.org']);
    $bob = Tiers::factory()->create(['association_id' => $asso->id, 'email' => 'bob@ex.org']);

    $opAlice1 = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Cycle Alice 1',
    ]);
    $opAlice2 = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Cycle Alice 2',
    ]);
    $opBob1 = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Cycle Bob 1',
    ]);
    $opBob2 = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Cycle Bob 2',
    ]);
    $opBob3 = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Cycle Bob 3',
    ]);

    Participant::factory()->create(['association_id' => $asso->id, 'tiers_id' => $alice->id, 'operation_id' => $opAlice1->id]);
    Participant::factory()->create(['association_id' => $asso->id, 'tiers_id' => $alice->id, 'operation_id' => $opAlice2->id]);
    Participant::factory()->create(['association_id' => $asso->id, 'tiers_id' => $bob->id, 'operation_id' => $opBob1->id]);
    Participant::factory()->create(['association_id' => $asso->id, 'tiers_id' => $bob->id, 'operation_id' => $opBob2->id]);
    Participant::factory()->create(['association_id' => $asso->id, 'tiers_id' => $bob->id, 'operation_id' => $opBob3->id]);

    Auth::guard('tiers-portail')->login($alice);

    $html = Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertStatus(200)
        ->html();

    // Alice voit ses activités
    expect($html)->toContain('Cycle Alice 1');
    expect($html)->toContain('Cycle Alice 2');

    // Alice ne voit pas les activités de Bob
    expect($html)->not->toContain('Cycle Bob 1');
    expect($html)->not->toContain('Cycle Bob 2');
    expect($html)->not->toContain('Cycle Bob 3');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Tiers ne peut pas accéder à un type pour lequel il n'est pas inscrit
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] Tiers reçoit 403 pour un TypeOperation sans participation', function () {
    $assoA = Association::factory()->create();
    TenantContext::boot($assoA);

    $typeOpA = TypeOperation::factory()->create(['association_id' => $assoA->id, 'nom' => 'Type A']);
    $typeOpB = TypeOperation::factory()->create(['association_id' => $assoA->id, 'nom' => 'Type B']);

    $opA = Operation::factory()->create([
        'association_id' => $assoA->id,
        'type_operation_id' => $typeOpA->id,
    ]);

    $alice = Tiers::factory()->create(['association_id' => $assoA->id]);
    Auth::guard('tiers-portail')->login($alice);

    // Alice a des participations sur type A mais pas sur type B
    Participant::factory()->create([
        'association_id' => $assoA->id,
        'tiers_id' => $alice->id,
        'operation_id' => $opA->id,
    ]);

    // Tente d'accéder au type B → 403
    Livewire::test(MesActivites::class, ['association' => $assoA, 'typeOperation' => $typeOpB])
        ->assertStatus(403);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Pas de fuite cross-tenant — TenantScope filtre les Participant
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] Alice asso A reçoit 403 en tentant d\'accéder à un TypeOperation de l\'asso B', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    // Créer données dans asso B
    TenantContext::boot($assoB);
    $typeOpB = TypeOperation::factory()->create(['association_id' => $assoB->id, 'nom' => 'Type B']);
    $opSecretB = Operation::factory()->create([
        'association_id' => $assoB->id,
        'type_operation_id' => $typeOpB->id,
        'nom' => 'Op SecretAssoB',
    ]);
    $tiersB = Tiers::factory()->create(['association_id' => $assoB->id]);
    Participant::factory()->create([
        'association_id' => $assoB->id,
        'tiers_id' => $tiersB->id,
        'operation_id' => $opSecretB->id,
    ]);

    // Alice se connecte sur asso A et tente d'accéder au typeOpB de assoB
    TenantContext::boot($assoA);
    $alice = Tiers::factory()->create(['association_id' => $assoA->id]);
    Auth::guard('tiers-portail')->login($alice);

    // TenantScope sur TypeOperation empêche de trouver typeOpB (assoB) depuis assoA
    // → abort_unless($hasParticipation, 403) est déclenché
    Livewire::test(MesActivites::class, ['association' => $assoA, 'typeOperation' => $typeOpB])
        ->assertStatus(403);
});
