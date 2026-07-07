<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Operation;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

test('gestion operations page loads with operation list', function (): void {
    $this->get('/operations')
        ->assertOk()
        ->assertSee('Liste des opérations');
});

test('operations are listed in table', function (): void {
    // La liste filtre par exercice courant (OperationList::render). Le défaut de
    // OperationFactory (date_debut jusqu'à +3 mois) peut tomber hors exercice et
    // exclure l'opération → test flaky. On ancre les dates dans l'exercice courant.
    // Cf. feedback_sqlite_date_boundary (dater à l'intérieur de la fenêtre).
    $range = app(ExerciceService::class)->dateRange(app(ExerciceService::class)->current());

    $op = Operation::factory()->create([
        'nom' => 'Art-thérapie test',
        'association_id' => $this->association->id,
        'date_debut' => $range['start'],
        'date_fin' => $range['end'],
    ]);
    $this->get('/operations')
        ->assertSee('Art-thérapie test');
});

test('operation detail page loads', function (): void {
    $op = Operation::factory()->create([
        'nom' => 'Sophrologie test',
        'association_id' => $this->association->id,
    ]);
    $this->get("/operations/{$op->id}")
        ->assertOk()
        ->assertSee('Sophrologie test');
});
