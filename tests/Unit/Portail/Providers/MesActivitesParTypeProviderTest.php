<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Services\Portail\Providers\MesActivitesParTypeProvider;
use App\Tenant\TenantContext;

beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 1 : Tiers avec 0 participation → resolveAll retourne 0 sections
// ─────────────────────────────────────────────────────────────────────────────
it('retourne 0 sections quand le tiers n\'a aucune participation', function (): void {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);

    $provider = new MesActivitesParTypeProvider;
    $sections = collect($provider->resolveAll($tiers));

    expect($sections)->toBeEmpty();
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 2 : Tiers avec 2 types actifs → resolveAll retourne 2 sections triées alpha
// ─────────────────────────────────────────────────────────────────────────────
it('retourne 2 sections triées alphabétiquement quand le tiers a 2 types actifs', function (): void {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeFormations = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Formations',
    ]);
    $typeParcoursA = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Parcours de soins A',
    ]);

    $opF = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeFormations->id,
    ]);
    $opP = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeParcoursA->id,
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);

    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $opF->id,
    ]);
    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $opP->id,
    ]);

    $provider = new MesActivitesParTypeProvider;
    $sections = collect($provider->resolveAll($tiers));

    expect($sections)->toHaveCount(2);

    // Tri alpha : Formations avant Parcours de soins A
    expect($sections->get(0)->label)->toBe('Mes formations');
    expect($sections->get(1)->label)->toBe('Mes parcours de soins a');
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 3 : Format DTO (id, routeName, routeParams, ordre)
// ─────────────────────────────────────────────────────────────────────────────
it('produit des DTOs avec id, routeName, routeParams et ordre corrects', function (): void {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom' => 'Ateliers',
    ]);

    $op = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);

    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
    ]);

    $provider = new MesActivitesParTypeProvider;
    $sections = collect($provider->resolveAll($tiers));

    expect($sections)->toHaveCount(1);

    $dto = $sections->first();
    expect($dto->id)->toBe('mes-activites-'.(int) $typeOp->id);
    expect($dto->routeName)->toBe('portail.mes-activites.show');
    expect($dto->routeParams)->toBe(['typeOperation' => (int) $typeOp->id]);
    expect($dto->ordre)->toBeGreaterThanOrEqual(80);
    expect($dto->groupe)->toBe('Mes activités');
    expect($dto->icon)->toBe('bi-calendar-event');
    expect($dto->visible)->toBeTrue();
});
