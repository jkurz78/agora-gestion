<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Operation;
use App\Models\User;
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
    $op = Operation::factory()->create([
        'nom' => 'Art-thérapie test',
        'association_id' => $this->association->id,
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
