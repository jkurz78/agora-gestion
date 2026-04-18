<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
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

it('requires authentication to access parametres', function () {
    TenantContext::clear();
    auth()->logout();
    $this->get(route('parametres.categories.index'))
        ->assertRedirect(route('login'));
});

it('displays the parametres page with categories', function () {
    $categorie = Categorie::factory()->create(['association_id' => $this->association->id]);

    $this->get(route('parametres.categories.index'))
        ->assertOk()
        ->assertSee($categorie->nom);
});

it('can store a categorie', function () {
    $this->post(route('parametres.categories.store'), [
        'nom' => 'Fournitures',
        'type' => 'depense',
    ])->assertRedirect(route('parametres.categories.index'));

    $this->assertDatabaseHas('categories', [
        'nom' => 'Fournitures',
        'type' => 'depense',
        'association_id' => $this->association->id,
    ]);
});

it('validates required fields when storing a categorie', function () {
    $this->post(route('parametres.categories.store'), [])
        ->assertSessionHasErrors(['nom', 'type']);
});

it('validates type must be depense or recette', function () {
    $this->post(route('parametres.categories.store'), [
        'nom' => 'Test',
        'type' => 'invalide',
    ])->assertSessionHasErrors(['type']);
});

it('validates nom max length', function () {
    $this->post(route('parametres.categories.store'), [
        'nom' => str_repeat('a', 101),
        'type' => 'depense',
    ])->assertSessionHasErrors(['nom']);
});

it('can update a categorie', function () {
    $categorie = Categorie::factory()->create(['nom' => 'Ancien nom', 'association_id' => $this->association->id]);

    $this->put(route('parametres.categories.update', $categorie), [
        'nom' => 'Nouveau nom',
        'type' => 'recette',
    ])->assertRedirect(route('parametres.categories.index'));

    $this->assertDatabaseHas('categories', [
        'id' => $categorie->id,
        'nom' => 'Nouveau nom',
        'type' => 'recette',
    ]);
});

it('can destroy a categorie', function () {
    $categorie = Categorie::factory()->create(['association_id' => $this->association->id]);

    $this->delete(route('parametres.categories.destroy', $categorie))
        ->assertRedirect(route('parametres.categories.index'));

    $this->assertDatabaseMissing('categories', ['id' => $categorie->id]);
});

it('returns flash error when destroying a categorie with sous-categories', function () {
    $categorie = Categorie::factory()->create(['association_id' => $this->association->id]);
    SousCategorie::factory()->create(['categorie_id' => $categorie->id, 'association_id' => $this->association->id]);

    $this->delete(route('parametres.categories.destroy', $categorie))
        ->assertRedirect(route('parametres.categories.index'))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('categories', ['id' => $categorie->id]);
});
