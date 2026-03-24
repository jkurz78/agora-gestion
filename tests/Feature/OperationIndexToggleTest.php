<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Models\Operation;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
});

it('masque par défaut les opérations hors exercice', function () {
    Operation::factory()->create([
        'nom' => 'Op passée',
        'date_debut' => '2024-09-01',
        'date_fin' => '2025-08-30',
        'statut' => StatutOperation::EnCours,
    ]);

    $this->get(route('compta.operations.index'))
        ->assertDontSee('Op passée');
});

it('affiche toutes les opérations avec ?all=1', function () {
    Operation::factory()->create([
        'nom' => 'Op passée',
        'date_debut' => '2024-09-01',
        'date_fin' => '2025-08-30',
        'statut' => StatutOperation::EnCours,
    ]);

    $this->get(route('compta.operations.index', ['all' => 1]))
        ->assertSee('Op passée');
});
