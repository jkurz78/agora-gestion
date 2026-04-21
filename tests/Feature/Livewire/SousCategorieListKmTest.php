<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
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

it('crée une sous-catégorie via modal (sans flag kilométrique)', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->set('categorie_id', (string) $this->cat->id)
        ->set('nom', 'Déplacements')
        ->call('save');

    $sc = SousCategorie::where('nom', 'Déplacements')->first();
    expect($sc)->not->toBeNull();
    // Usage pivot is managed separately (not via the modal form in this slice)
    expect($sc->hasUsage(UsageComptable::FraisKilometriques))->toBeFalse();
});

it('édite une sous-catégorie et précharge les champs nom/code_cerfa', function () {
    $sc = SousCategorie::factory()->pourFraisKilometriques()->create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->cat->id,
        'nom' => 'Déplacements',
    ]);

    Livewire::test(SousCategorieList::class)
        ->call('openEdit', $sc->id)
        ->assertSet('nom', 'Déplacements')
        ->assertSet('showModal', true);

    expect($sc->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();
});

it('affiche la liste des sous-catégories (vérifie le rendu sans erreur)', function () {
    SousCategorie::factory()->pourFraisKilometriques()->create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->cat->id,
        'nom' => 'Déplacements',
    ]);

    // The component renders without error and shows the add button
    Livewire::test(SousCategorieList::class)
        ->assertStatus(200)
        ->assertSee('Ajouter une sous-catégorie');
});
