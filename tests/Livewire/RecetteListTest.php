<?php

use App\Livewire\RecetteList;
use App\Models\Recette;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
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
        ->set('exercice', 2025)
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
    Recette::factory()->create([
        'libelle' => 'Recette Gamma',
        'tiers' => 'Gamma SARL',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);
    Recette::factory()->create([
        'libelle' => 'Recette Delta',
        'tiers' => 'Delta Inc',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(RecetteList::class)
        ->set('tiers', 'Gamma')
        ->assertSee('Recette Gamma')
        ->assertDontSee('Recette Delta');
});
