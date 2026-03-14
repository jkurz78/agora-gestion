<?php

use App\Models\Tiers;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('requires authentication to access membres index', function () {
    $this->get(route('membres.index'))
        ->assertRedirect(route('login'));
});

it('can list membres', function () {
    $tiers = Tiers::factory()->membre()->create();

    $this->actingAs($this->user)
        ->get(route('membres.index'))
        ->assertOk()
        ->assertSee($tiers->nom)
        ->assertSee($tiers->prenom);
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
            'notes_membre' => 'Test notes',
        ])
        ->assertRedirect(route('membres.index'));

    $this->assertDatabaseHas('tiers', [
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'email' => 'jean@example.com',
    ]);
});

it('validates required fields when creating a membre', function () {
    $this->actingAs($this->user)
        ->post(route('membres.store'), [])
        ->assertSessionHasErrors(['nom']);
});

it('can view membre show page', function () {
    $tiers = Tiers::factory()->membre()->create();

    $this->actingAs($this->user)
        ->get(route('membres.show', $tiers))
        ->assertOk()
        ->assertSee($tiers->nom)
        ->assertSee($tiers->prenom);
});

it('can update a membre', function () {
    $tiers = Tiers::factory()->membre()->create(['nom' => 'Ancien']);

    $this->actingAs($this->user)
        ->put(route('membres.update', $tiers), [
            'nom' => 'Nouveau',
            'prenom' => 'Prénom',
            'statut' => 'inactif',
        ])
        ->assertRedirect(route('membres.show', $tiers));

    $this->assertDatabaseHas('tiers', [
        'id' => $tiers->id,
        'nom' => 'Nouveau',
        'statut_membre' => 'inactif',
    ]);
});

it('can delete a membre', function () {
    $tiers = Tiers::factory()->membre()->create();

    $this->actingAs($this->user)
        ->delete(route('membres.destroy', $tiers))
        ->assertRedirect(route('membres.index'));

    $this->assertDatabaseMissing('tiers', ['id' => $tiers->id]);
});
