<?php

declare(strict_types=1);

use App\Livewire\SousCategorieList;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->asso = Association::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->asso->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->asso);
    session(['current_association_id' => $this->asso->id]);
    $this->actingAs($this->admin);

    $this->cat = Categorie::factory()->create(['association_id' => $this->asso->id]);
});

afterEach(function () {
    TenantContext::clear();
});

it('crée une sous-catégorie avec flag pour_frais_kilometriques coché', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->set('categorie_id', (string) $this->cat->id)
        ->set('nom', 'Déplacements')
        ->set('pour_frais_kilometriques', true)
        ->call('save');

    $sc = SousCategorie::where('nom', 'Déplacements')->first();
    expect($sc)->not->toBeNull();
    expect($sc->pour_frais_kilometriques)->toBeTrue();
});

it('édite une sous-catégorie et précharge le flag pour_frais_kilometriques', function () {
    $sc = SousCategorie::create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->cat->id,
        'nom' => 'Déplacements',
        'pour_frais_kilometriques' => true,
    ]);

    Livewire::test(SousCategorieList::class)
        ->call('openEdit', $sc->id)
        ->assertSet('pour_frais_kilometriques', true);
});

it('affiche la colonne Frais kilométriques dans le tableau', function () {
    SousCategorie::create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->cat->id,
        'nom' => 'Déplacements',
        'pour_frais_kilometriques' => true,
    ]);

    Livewire::test(SousCategorieList::class)
        ->assertSee('Frais kilométriques');
});
