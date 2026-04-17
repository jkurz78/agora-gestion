<?php

declare(strict_types=1);

use App\Models\Association;
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

it('can store a new user', function () {
    $this->post(route('parametres.utilisateurs.store'), [
        'nom' => 'Jean Dupont',
        'email' => 'jean@example.com',
        'password' => 'motdepasse1',
        'password_confirmation' => 'motdepasse1',
    ])->assertRedirectContains(route('parametres.utilisateurs.index'));

    $this->assertDatabaseHas('users', [
        'nom' => 'Jean Dupont',
        'email' => 'jean@example.com',
    ]);
});

it('validates required fields when storing a user', function () {
    $this->post(route('parametres.utilisateurs.store'), [])
        ->assertSessionHasErrors(['nom', 'email', 'password']);
});

it('validates email is valid', function () {
    $this->post(route('parametres.utilisateurs.store'), [
        'nom' => 'Test',
        'email' => 'pas-un-email',
        'password' => 'motdepasse1',
        'password_confirmation' => 'motdepasse1',
    ])->assertSessionHasErrors(['email']);
});

it('validates email is unique', function () {
    User::factory()->create(['email' => 'existant@example.com']);

    $this->post(route('parametres.utilisateurs.store'), [
        'nom' => 'Test',
        'email' => 'existant@example.com',
        'password' => 'motdepasse1',
        'password_confirmation' => 'motdepasse1',
    ])->assertSessionHasErrors(['email']);
});

it('validates password minimum length', function () {
    $this->post(route('parametres.utilisateurs.store'), [
        'nom' => 'Test',
        'email' => 'test@example.com',
        'password' => 'court',
        'password_confirmation' => 'court',
    ])->assertSessionHasErrors(['password']);
});

it('validates password confirmation', function () {
    $this->post(route('parametres.utilisateurs.store'), [
        'nom' => 'Test',
        'email' => 'test@example.com',
        'password' => 'motdepasse1',
        'password_confirmation' => 'different',
    ])->assertSessionHasErrors(['password']);
});

it('can destroy a user', function () {
    $userToDelete = User::factory()->create();

    $this->delete(route('parametres.utilisateurs.destroy', $userToDelete))
        ->assertRedirectContains(route('parametres.utilisateurs.index'));

    $this->assertDatabaseMissing('users', ['id' => $userToDelete->id]);
});
