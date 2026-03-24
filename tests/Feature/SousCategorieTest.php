<?php

use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->categorie = Categorie::factory()->create();
});

it('can store a sous-categorie', function () {
    $this->actingAs($this->user)
        ->post(route('compta.parametres.sous-categories.store'), [
            'categorie_id' => $this->categorie->id,
            'nom' => 'Électricité',
            'code_cerfa' => '1234',
        ])
        ->assertRedirect(route('compta.parametres.sous-categories.index'));

    $this->assertDatabaseHas('sous_categories', [
        'categorie_id' => $this->categorie->id,
        'nom' => 'Électricité',
        'code_cerfa' => '1234',
    ]);
});

it('validates required fields when storing a sous-categorie', function () {
    $this->actingAs($this->user)
        ->post(route('compta.parametres.sous-categories.store'), [])
        ->assertSessionHasErrors(['categorie_id', 'nom']);
});

it('validates categorie_id exists', function () {
    $this->actingAs($this->user)
        ->post(route('compta.parametres.sous-categories.store'), [
            'categorie_id' => 99999,
            'nom' => 'Test',
        ])
        ->assertSessionHasErrors(['categorie_id']);
});

it('validates nom max length for sous-categorie', function () {
    $this->actingAs($this->user)
        ->post(route('compta.parametres.sous-categories.store'), [
            'categorie_id' => $this->categorie->id,
            'nom' => str_repeat('a', 101),
        ])
        ->assertSessionHasErrors(['nom']);
});

it('validates code_cerfa max length', function () {
    $this->actingAs($this->user)
        ->post(route('compta.parametres.sous-categories.store'), [
            'categorie_id' => $this->categorie->id,
            'nom' => 'Test',
            'code_cerfa' => str_repeat('a', 11),
        ])
        ->assertSessionHasErrors(['code_cerfa']);
});

it('can store a sous-categorie without code_cerfa', function () {
    $this->actingAs($this->user)
        ->post(route('compta.parametres.sous-categories.store'), [
            'categorie_id' => $this->categorie->id,
            'nom' => 'Sans CERFA',
        ])
        ->assertRedirect(route('compta.parametres.sous-categories.index'));

    $this->assertDatabaseHas('sous_categories', [
        'nom' => 'Sans CERFA',
        'code_cerfa' => null,
    ]);
});

it('can update a sous-categorie', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);

    $this->actingAs($this->user)
        ->put(route('compta.parametres.sous-categories.update', $sc), [
            'categorie_id' => $this->categorie->id,
            'nom' => 'Nom modifié',
            'code_cerfa' => '9999',
        ])
        ->assertRedirect(route('compta.parametres.sous-categories.index'));

    $this->assertDatabaseHas('sous_categories', [
        'id' => $sc->id,
        'nom' => 'Nom modifié',
        'code_cerfa' => '9999',
    ]);
});

it('can destroy a sous-categorie', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);

    $this->actingAs($this->user)
        ->delete(route('compta.parametres.sous-categories.destroy', $sc))
        ->assertRedirect(route('compta.parametres.sous-categories.index'));

    $this->assertDatabaseMissing('sous_categories', ['id' => $sc->id]);
});

it('returns flash error when destroying a sous-categorie with linked lignes', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);

    $depense = Transaction::factory()->asDepense()->create([
        'saisi_par' => $this->user->id,
        'date' => '2025-10-15',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $depense->id,
        'sous_categorie_id' => $sc->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('compta.parametres.sous-categories.destroy', $sc))
        ->assertRedirect(route('compta.parametres.sous-categories.index'))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('sous_categories', ['id' => $sc->id]);
});
