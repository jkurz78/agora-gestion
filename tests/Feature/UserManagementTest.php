<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('can store a new user', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.utilisateurs.store'), [
            'nom' => 'Jean Dupont',
            'email' => 'jean@example.com',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ])
        ->assertRedirectContains(route('parametres.index'));

    $this->assertDatabaseHas('users', [
        'nom' => 'Jean Dupont',
        'email' => 'jean@example.com',
    ]);
});

it('validates required fields when storing a user', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.utilisateurs.store'), [])
        ->assertSessionHasErrors(['nom', 'email', 'password']);
});

it('validates email is valid', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.utilisateurs.store'), [
            'nom' => 'Test',
            'email' => 'pas-un-email',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ])
        ->assertSessionHasErrors(['email']);
});

it('validates email is unique', function () {
    $existing = User::factory()->create(['email' => 'existant@example.com']);

    $this->actingAs($this->user)
        ->post(route('parametres.utilisateurs.store'), [
            'nom' => 'Test',
            'email' => 'existant@example.com',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ])
        ->assertSessionHasErrors(['email']);
});

it('validates password minimum length', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.utilisateurs.store'), [
            'nom' => 'Test',
            'email' => 'test@example.com',
            'password' => 'court',
            'password_confirmation' => 'court',
        ])
        ->assertSessionHasErrors(['password']);
});

it('validates password confirmation', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.utilisateurs.store'), [
            'nom' => 'Test',
            'email' => 'test@example.com',
            'password' => 'motdepasse1',
            'password_confirmation' => 'different',
        ])
        ->assertSessionHasErrors(['password']);
});

it('can destroy a user', function () {
    $userToDelete = User::factory()->create();

    $this->actingAs($this->user)
        ->delete(route('parametres.utilisateurs.destroy', $userToDelete))
        ->assertRedirectContains(route('parametres.index'));

    $this->assertDatabaseMissing('users', ['id' => $userToDelete->id]);
});
