<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Tiers avec 0 activité → 404 sur /portail/{slug}/mes-activites
// ─────────────────────────────────────────────────────────────────────────────
it('retourne 404 quand le tiers n\'a aucune activité', function (): void {
    $asso = Association::factory()->create(['slug' => 'asso-redir-test']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $this->get("/{$asso->slug}/portail/mes-activites")
        ->assertStatus(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Tiers avec 1 type → redirect vers mes-activites.show avec ce type
// ─────────────────────────────────────────────────────────────────────────────
it('redirige vers le type unique quand le tiers a 1 type d\'activité', function (): void {
    $asso = Association::factory()->create(['slug' => 'asso-redir-test2']);
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Formations',
    ]);

    $op = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
    ]);

    $response = $this->get("/{$asso->slug}/portail/mes-activites");
    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toContain('/mes-activites/'.(int) $typeOp->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Tiers avec 2 types → redirect vers le 1er alphabétique
// ─────────────────────────────────────────────────────────────────────────────
it('redirige vers le premier type alphabétique quand le tiers a 2 types', function (): void {
    $asso = Association::factory()->create(['slug' => 'asso-redir-test3']);
    TenantContext::boot($asso);

    $typeZ = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Zumba',
    ]);
    $typeA = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Ateliers',
    ]);

    $opZ = Operation::factory()->create(['association_id' => $asso->id, 'type_operation_id' => $typeZ->id]);
    $opA = Operation::factory()->create(['association_id' => $asso->id, 'type_operation_id' => $typeA->id]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    Participant::factory()->create(['association_id' => $asso->id, 'tiers_id' => $tiers->id, 'operation_id' => $opZ->id]);
    Participant::factory()->create(['association_id' => $asso->id, 'tiers_id' => $tiers->id, 'operation_id' => $opA->id]);

    $response = $this->get("/{$asso->slug}/portail/mes-activites");
    $response->assertRedirect();
    $location = $response->headers->get('Location');
    // "Ateliers" vient avant "Zumba" alphabétiquement
    expect($location)->toContain('/mes-activites/'.(int) $typeA->id);
});
