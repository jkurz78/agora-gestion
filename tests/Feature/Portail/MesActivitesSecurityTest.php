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
// Test 6 : Pas de fuite intra-asso — Alice ne voit que ses activités
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

    $html = Livewire::test(MesActivites::class, ['association' => $asso])
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
// Test 7 : Pas de fuite cross-tenant — TenantScope filtre les Participant
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] Alice asso A ne voit pas les activités de l\'asso B', function () {
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

    // Alice se connecte sur asso A
    TenantContext::boot($assoA);
    $alice = Tiers::factory()->create(['association_id' => $assoA->id]);
    Auth::guard('tiers-portail')->login($alice);

    $html = Livewire::test(MesActivites::class, ['association' => $assoA])
        ->assertStatus(200)
        ->html();

    // TenantScope sur Participant empêche la fuite cross-tenant
    expect($html)->not->toContain('Op SecretAssoB');
});
