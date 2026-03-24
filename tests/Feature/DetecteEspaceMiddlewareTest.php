<?php

declare(strict_types=1);

use App\Enums\Espace;
use App\Models\User;

test('middleware sets espace compta for /compta/ routes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/compta/dashboard')
        ->assertOk();

    $user->refresh();
    expect($user->dernier_espace)->toBe(Espace::Compta);
});

test('middleware sets espace gestion for /gestion/ routes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/gestion/dashboard')
        ->assertOk();

    $user->refresh();
    expect($user->dernier_espace)->toBe(Espace::Gestion);
});

test('root redirects to dernier_espace dashboard', function (): void {
    $user = User::factory()->create(['dernier_espace' => Espace::Gestion]);
    $this->actingAs($user)
        ->get('/')
        ->assertRedirect('/gestion/dashboard');
});

test('root redirects to compta dashboard by default', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/')
        ->assertRedirect('/compta/dashboard');
});
