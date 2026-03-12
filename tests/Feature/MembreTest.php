<?php

use App\Models\Membre;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('requires authentication to access membres index', function () {
    $this->get(route('membres.index'))
        ->assertRedirect(route('login'));
});

it('can list membres', function () {
    $membre = Membre::factory()->create();

    $this->actingAs($this->user)
        ->get(route('membres.index'))
        ->assertOk()
        ->assertSee($membre->nom)
        ->assertSee($membre->prenom);
});

it('can create a membre with valid data', function () {
    $this->actingAs($this->user)
        ->post(route('membres.store'), [
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'email' => 'jean@example.com',
            'telephone' => '0612345678',
            'adresse' => '1 rue de Paris',
            'date_adhesion' => '2025-01-15',
            'statut' => 'actif',
            'notes' => 'Test notes',
        ])
        ->assertRedirect(route('membres.index'));

    $this->assertDatabaseHas('membres', [
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean@example.com',
    ]);
});

it('validates required fields when creating a membre', function () {
    $this->actingAs($this->user)
        ->post(route('membres.store'), [])
        ->assertSessionHasErrors(['nom', 'prenom', 'statut']);
});

it('can view membre show page', function () {
    $membre = Membre::factory()->create();

    $this->actingAs($this->user)
        ->get(route('membres.show', $membre))
        ->assertOk()
        ->assertSee($membre->nom)
        ->assertSee($membre->prenom);
});

it('can update a membre', function () {
    $membre = Membre::factory()->create(['nom' => 'Ancien']);

    $this->actingAs($this->user)
        ->put(route('membres.update', $membre), [
            'nom' => 'Nouveau',
            'prenom' => 'Prénom',
            'statut' => 'inactif',
        ])
        ->assertRedirect(route('membres.show', $membre));

    $this->assertDatabaseHas('membres', [
        'id' => $membre->id,
        'nom' => 'Nouveau',
        'statut' => 'inactif',
    ]);
});

it('can delete a membre', function () {
    $membre = Membre::factory()->create();

    $this->actingAs($this->user)
        ->delete(route('membres.destroy', $membre))
        ->assertRedirect(route('membres.index'));

    $this->assertDatabaseMissing('membres', ['id' => $membre->id]);
});
