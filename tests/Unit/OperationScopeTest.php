<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Models\Operation;

// Exercice 2025 : 2025-09-01 → 2026-08-31

it('exclut une opération terminée avant l\'exercice', function () {
    Operation::factory()->create([
        'date_debut' => '2024-09-01',
        'date_fin' => '2025-08-31',
        'statut' => StatutOperation::EnCours,
    ]);

    expect(Operation::forExercice(2025)->count())->toBe(0);
});

it('inclut une opération dont le début est antérieur à l\'exercice (chevauchement gauche)', function () {
    Operation::factory()->create([
        'date_debut' => '2025-06-01',
        'date_fin' => '2025-11-30',
        'statut' => StatutOperation::EnCours,
    ]);

    expect(Operation::forExercice(2025)->count())->toBe(1);
});

it('inclut une opération entièrement dans l\'exercice', function () {
    Operation::factory()->create([
        'date_debut' => '2025-10-01',
        'date_fin' => '2026-03-31',
        'statut' => StatutOperation::EnCours,
    ]);

    expect(Operation::forExercice(2025)->count())->toBe(1);
});

it('inclut une opération qui chevauche entièrement l\'exercice', function () {
    Operation::factory()->create([
        'date_debut' => '2025-01-01',
        'date_fin' => '2027-01-01',
        'statut' => StatutOperation::EnCours,
    ]);

    expect(Operation::forExercice(2025)->count())->toBe(1);
});

it('exclut une opération future qui commence après l\'exercice', function () {
    Operation::factory()->create([
        'date_debut' => '2026-09-01',
        'date_fin' => '2027-08-31',
        'statut' => StatutOperation::EnCours,
    ]);

    expect(Operation::forExercice(2025)->count())->toBe(0);
});

it('inclut une opération clôturée si elle chevauche l\'exercice (statut ignoré par le scope)', function () {
    Operation::factory()->create([
        'date_debut' => '2025-10-01',
        'date_fin' => '2026-03-31',
        'statut' => StatutOperation::Cloturee,
    ]);

    expect(Operation::forExercice(2025)->count())->toBe(1);
});
