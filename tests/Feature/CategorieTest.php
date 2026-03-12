<?php

use App\Models\Categorie;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('requires authentication to access parametres', function () {
    $this->get(route('parametres.index'))
        ->assertRedirect(route('login'));
});

it('displays the parametres page with categories', function () {
    $categorie = Categorie::factory()->create();

    $this->actingAs($this->user)
        ->get(route('parametres.index'))
        ->assertOk()
        ->assertSee($categorie->nom);
});

it('can store a categorie', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.categories.store'), [
            'nom' => 'Fournitures',
            'type' => 'depense',
        ])
        ->assertRedirect(route('parametres.index'));

    $this->assertDatabaseHas('categories', [
        'nom' => 'Fournitures',
        'type' => 'depense',
    ]);
});

it('validates required fields when storing a categorie', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.categories.store'), [])
        ->assertSessionHasErrors(['nom', 'type']);
});

it('validates type must be depense or recette', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.categories.store'), [
            'nom' => 'Test',
            'type' => 'invalide',
        ])
        ->assertSessionHasErrors(['type']);
});

it('validates nom max length', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.categories.store'), [
            'nom' => str_repeat('a', 101),
            'type' => 'depense',
        ])
        ->assertSessionHasErrors(['nom']);
});

it('can update a categorie', function () {
    $categorie = Categorie::factory()->create(['nom' => 'Ancien nom']);

    $this->actingAs($this->user)
        ->put(route('parametres.categories.update', $categorie), [
            'nom' => 'Nouveau nom',
            'type' => 'recette',
        ])
        ->assertRedirect(route('parametres.index'));

    $this->assertDatabaseHas('categories', [
        'id' => $categorie->id,
        'nom' => 'Nouveau nom',
        'type' => 'recette',
    ]);
});

it('can destroy a categorie', function () {
    $categorie = Categorie::factory()->create();

    $this->actingAs($this->user)
        ->delete(route('parametres.categories.destroy', $categorie))
        ->assertRedirect(route('parametres.index'));

    $this->assertDatabaseMissing('categories', ['id' => $categorie->id]);
});

it('returns flash error when destroying a categorie with sous-categories', function () {
    $categorie = Categorie::factory()->create();
    \App\Models\SousCategorie::factory()->create(['categorie_id' => $categorie->id]);

    $this->actingAs($this->user)
        ->delete(route('parametres.categories.destroy', $categorie))
        ->assertRedirect(route('parametres.index'))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('categories', ['id' => $categorie->id]);
});
