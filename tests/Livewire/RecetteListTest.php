<?php

use App\Livewire\RecetteList;
use App\Models\Recette;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    session()->forget('exercice_actif');
});

it('renders with recettes', function () {
    $recette = Recette::factory()->create([
        'libelle' => 'Recette de test',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(RecetteList::class)
        ->assertOk()
        ->assertSee('Recette de test');
});

it('filters by exercice', function () {
    // Exercice 2025 = Sept 2025 - Aug 2026
    $recetteInExercice = Recette::factory()->create([
        'libelle' => 'Dans exercice',
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);

    // Exercice 2024 = Sept 2024 - Aug 2025
    $recetteOutExercice = Recette::factory()->create([
        'libelle' => 'Hors exercice',
        'date' => '2024-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(RecetteList::class)
        ->assertSee('Dans exercice')
        ->assertDontSee('Hors exercice');
});

it('can delete a recette with soft delete', function () {
    $recette = Recette::factory()->create([
        'libelle' => 'A supprimer',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(RecetteList::class)
        ->call('delete', $recette->id);

    $this->assertSoftDeleted('recettes', ['id' => $recette->id]);
});

it('displays reference column in recette list', function () {
    Recette::factory()->create([
        'libelle' => 'Cotisation membre',
        'reference' => 'REF-REC-007',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(RecetteList::class)
        ->assertSee('Réf.')
        ->assertSee('REF-REC-007');
});

it('filters recettes by tiers', function () {
    $tiersGamma = \App\Models\Tiers::factory()->create(['nom' => 'Gamma SARL', 'pour_recettes' => true]);
    $tiersDelta = \App\Models\Tiers::factory()->create(['nom' => 'Delta Inc', 'pour_recettes' => true]);

    Recette::factory()->create([
        'libelle' => 'Recette Gamma',
        'tiers_id' => $tiersGamma->id,
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);
    Recette::factory()->create([
        'libelle' => 'Recette Delta',
        'tiers_id' => $tiersDelta->id,
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(RecetteList::class)
        ->set('tiers', 'Gamma')
        ->assertSee('Recette Gamma')
        ->assertDontSee('Recette Delta');
});

it('affiche la colonne N° dans la liste des recettes', function () {
    $compte = \App\Models\CompteBancaire::factory()->create();
    Recette::factory()->create([
        'numero_piece' => '2025-2026:00001',
        'compte_id'    => $compte->id,
        'saisi_par'    => $this->user->id,
        'date'         => '2025-10-01',
    ]);

    Livewire::test(RecetteList::class)
        ->assertSee('N°')
        ->assertSee('2025-2026:00001');
});
