<?php

declare(strict_types=1);

use App\Livewire\SousCategorieList;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->categorie = Categorie::factory()->create();
});

it('renders the sous-categorie list component', function () {
    Livewire::test(SousCategorieList::class)
        ->assertStatus(200);
});

it('can create a sous-categorie via modal', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->assertSet('showModal', true)
        ->assertSet('editingId', null)
        ->set('categorie_id', (string) $this->categorie->id)
        ->set('nom', 'Électricité')
        ->set('code_cerfa', '1234')
        ->set('pour_dons', false)
        ->set('pour_cotisations', false)
        ->set('pour_inscriptions', false)
        ->call('save')
        ->assertSet('showModal', false);

    $this->assertDatabaseHas('sous_categories', [
        'categorie_id' => $this->categorie->id,
        'nom' => 'Électricité',
        'code_cerfa' => '1234',
    ]);
});

it('validates required fields when creating', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->set('categorie_id', '')
        ->set('nom', '')
        ->call('save')
        ->assertHasErrors(['categorie_id', 'nom']);
});

it('validates categorie_id exists', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->set('categorie_id', '99999')
        ->set('nom', 'Test')
        ->call('save')
        ->assertHasErrors(['categorie_id']);
});

it('validates nom max length', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->set('categorie_id', (string) $this->categorie->id)
        ->set('nom', str_repeat('a', 101))
        ->call('save')
        ->assertHasErrors(['nom']);
});

it('validates code_cerfa max length', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->set('categorie_id', (string) $this->categorie->id)
        ->set('nom', 'Test')
        ->set('code_cerfa', str_repeat('a', 11))
        ->call('save')
        ->assertHasErrors(['code_cerfa']);
});

it('can create without code_cerfa', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->set('categorie_id', (string) $this->categorie->id)
        ->set('nom', 'Sans CERFA')
        ->call('save')
        ->assertSet('showModal', false);

    $this->assertDatabaseHas('sous_categories', [
        'nom' => 'Sans CERFA',
        'code_cerfa' => null,
    ]);
});

it('can update a sous-categorie via modal', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);

    Livewire::test(SousCategorieList::class)
        ->call('openEdit', $sc->id)
        ->assertSet('showModal', true)
        ->assertSet('editingId', $sc->id)
        ->assertSet('nom', $sc->nom)
        ->set('nom', 'Nom modifié')
        ->set('code_cerfa', '9999')
        ->call('save')
        ->assertSet('showModal', false);

    $this->assertDatabaseHas('sous_categories', [
        'id' => $sc->id,
        'nom' => 'Nom modifié',
        'code_cerfa' => '9999',
    ]);
});

it('can toggle a flag', function () {
    $sc = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'pour_dons' => false,
    ]);

    Livewire::test(SousCategorieList::class)
        ->call('toggleFlag', $sc->id, 'pour_dons');

    expect($sc->fresh()->pour_dons)->toBeTrue();
});

it('rejects invalid flag names', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);

    Livewire::test(SousCategorieList::class)
        ->call('toggleFlag', $sc->id, 'invalid_flag');
});

it('can update a field inline', function () {
    $sc = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'nom' => 'Ancien nom',
    ]);

    Livewire::test(SousCategorieList::class)
        ->call('updateField', $sc->id, 'nom', 'Nouveau nom');

    expect($sc->fresh()->nom)->toBe('Nouveau nom');
});

it('validates inline field update', function () {
    $sc = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'nom' => 'Ancien nom',
    ]);

    Livewire::test(SousCategorieList::class)
        ->call('updateField', $sc->id, 'nom', '')
        ->assertSet('flashType', 'danger');

    expect($sc->fresh()->nom)->toBe('Ancien nom');
});

it('can delete a sous-categorie', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);

    Livewire::test(SousCategorieList::class)
        ->call('delete', $sc->id);

    $this->assertDatabaseMissing('sous_categories', ['id' => $sc->id]);
});

it('shows error when deleting a sous-categorie with linked lignes', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);

    $depense = Transaction::factory()->asDepense()->create([
        'saisi_par' => $this->user->id,
        'date' => '2025-10-15',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $depense->id,
        'sous_categorie_id' => $sc->id,
    ]);

    Livewire::test(SousCategorieList::class)
        ->call('delete', $sc->id)
        ->assertSet('flashType', 'danger');

    $this->assertDatabaseHas('sous_categories', ['id' => $sc->id]);
});
