<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\User;

test('gestion operations page loads with operation list', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/gestion/operations')
        ->assertOk()
        ->assertSee('Gestion des opérations');
});

test('operations are listed in table', function (): void {
    $user = User::factory()->create();
    $op = Operation::factory()->create(['nom' => 'Art-thérapie test']);
    $this->actingAs($user)
        ->get('/gestion/operations')
        ->assertSee('Art-thérapie test');
});

test('operation detail page loads', function (): void {
    $user = User::factory()->create();
    $op = Operation::factory()->create(['nom' => 'Sophrologie test']);
    $this->actingAs($user)
        ->get("/gestion/operations/{$op->id}")
        ->assertOk()
        ->assertSee('Sophrologie test');
});
