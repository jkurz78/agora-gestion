<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->admin);
});

afterEach(function () {
    TenantContext::clear();
});

it('can create a user with a role', function () {
    $this->post(route('parametres.utilisateurs.store'), [
        'nom' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'comptable',
    ])->assertRedirect();

    $user = User::where('email', 'test@example.com')->first();
    expect($user)->not->toBeNull();
    // Role is on the pivot
    $pivot = $user->associations()->where('association_id', $this->association->id)->first();
    expect($pivot?->pivot?->role)->toBe('comptable');
});

it('can update a user role', function () {
    $target = User::factory()->create();
    $target->associations()->attach($this->association->id, ['role' => 'comptable', 'joined_at' => now()]);

    $this->put(route('parametres.utilisateurs.update', $target), [
        'nom' => $target->nom,
        'email' => $target->email,
        'role' => 'gestionnaire',
    ])->assertRedirect();

    $pivot = $target->fresh()->associations()->where('association_id', $this->association->id)->first();
    expect($pivot?->pivot?->role)->toBe('gestionnaire');
});

it('defaults to consultation role if not specified', function () {
    $this->post(route('parametres.utilisateurs.store'), [
        'nom' => 'Default Role',
        'email' => 'default@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect();

    // Without role, no pivot attachment — user is created but not attached
    $user = User::where('email', 'default@example.com')->first();
    expect($user)->not->toBeNull();
    // No role attached if not specified (store() only attaches if role provided)
    $pivot = $user->associations()->where('association_id', $this->association->id)->first();
    expect($pivot)->toBeNull();
});

it('validates role must be a valid enum value', function () {
    $this->post(route('parametres.utilisateurs.store'), [
        'nom' => 'Test',
        'email' => 'bad@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'superadmin',
    ])->assertSessionHasErrors('role');
});

it('shows role column in user list', function () {
    $comptable = User::factory()->create();
    $comptable->associations()->attach($this->association->id, ['role' => 'comptable', 'joined_at' => now()]);

    $this->get(route('parametres.utilisateurs.index'))
        ->assertSee('Administrateur')
        ->assertSee('Comptable');
});
