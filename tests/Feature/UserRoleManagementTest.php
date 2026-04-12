<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a user with a role', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);

    $this->actingAs($admin)->post(route('parametres.utilisateurs.store'), [
        'nom' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'comptable',
    ])->assertRedirect();

    expect(User::where('email', 'test@example.com')->first()->role)->toBe(Role::Comptable);
});

it('can update a user role', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $target = User::factory()->create(['role' => Role::Comptable]);

    $this->actingAs($admin)->put(route('parametres.utilisateurs.update', $target), [
        'nom' => $target->nom,
        'email' => $target->email,
        'role' => 'gestionnaire',
    ])->assertRedirect();

    expect($target->fresh()->role)->toBe(Role::Gestionnaire);
});

it('defaults to admin role if not specified', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);

    $this->actingAs($admin)->post(route('parametres.utilisateurs.store'), [
        'nom' => 'Default Role',
        'email' => 'default@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect();

    expect(User::where('email', 'default@example.com')->first()->role)->toBe(Role::Admin);
});

it('validates role must be a valid enum value', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);

    $this->actingAs($admin)->post(route('parametres.utilisateurs.store'), [
        'nom' => 'Test',
        'email' => 'bad@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'superadmin',
    ])->assertSessionHasErrors('role');
});

it('shows role column in user list', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $comptable = User::factory()->create(['role' => Role::Comptable]);

    $this->actingAs($admin)
        ->get(route('parametres.utilisateurs.index'))
        ->assertSee('Administrateur')
        ->assertSee('Comptable');
});
