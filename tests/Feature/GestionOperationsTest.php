<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\User;

test('gestion operations page loads', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/gestion/operations')
        ->assertOk()
        ->assertSee('Opération');
});

test('operations are listed in selector', function (): void {
    $user = User::factory()->create();
    $op = Operation::factory()->create(['nom' => 'Art-thérapie test']);
    $this->actingAs($user)
        ->get('/gestion/operations')
        ->assertSee('Art-thérapie test');
});

test('operation detail page shows tabs', function (): void {
    $user = User::factory()->create();
    $op = Operation::factory()->create(['nom' => 'Sophrologie test']);
    $this->actingAs($user)
        ->get('/gestion/operations/'.$op->id)
        ->assertOk()
        ->assertSee('Sophrologie test')
        ->assertSee('Détails');
});
