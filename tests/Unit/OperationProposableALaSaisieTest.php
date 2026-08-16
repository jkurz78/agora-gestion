<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Models\Association;
use App\Models\Operation;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

afterEach(function () {
    TenantContext::clear();
});

it('inclut une opération en cours datée sur un exercice antérieur', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op ancienne',
        'date_debut' => '2020-09-01',
        'date_fin' => '2021-08-31',
        'statut' => StatutOperation::EnCours,
    ]);

    expect(Operation::proposableALaSaisie()->pluck('nom')->all())->toBe(['Op ancienne']);
});

it('inclut une opération en cours sans dates', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op sans dates',
        'date_debut' => null,
        'date_fin' => null,
        'statut' => StatutOperation::EnCours,
    ]);

    expect(Operation::proposableALaSaisie()->count())->toBe(1);
});

it('inclut une opération en cours datée dans le futur', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op future',
        'date_debut' => '2030-09-01',
        'date_fin' => '2031-08-31',
        'statut' => StatutOperation::EnCours,
    ]);

    expect(Operation::proposableALaSaisie()->count())->toBe(1);
});

it('exclut une opération clôturée', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op clôturée',
        'date_debut' => '2025-09-01',
        'date_fin' => '2026-08-31',
        'statut' => StatutOperation::Cloturee,
    ]);

    expect(Operation::proposableALaSaisie()->count())->toBe(0);
});

it('exclut une opération d\'une autre association', function () {
    $autre = Association::factory()->create();
    Operation::factory()->create([
        'association_id' => $autre->id,
        'nom' => 'Op autre tenant',
        'statut' => StatutOperation::EnCours,
    ]);

    expect(Operation::proposableALaSaisie()->count())->toBe(0);
});
