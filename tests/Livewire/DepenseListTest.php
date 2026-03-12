<?php

use App\Livewire\DepenseList;
use App\Models\Depense;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders with depenses', function () {
    $depense = Depense::factory()->create([
        'libelle' => 'Dépense de test',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(DepenseList::class)
        ->assertOk()
        ->assertSee('Dépense de test');
});

it('filters by exercice', function () {
    // Exercice 2025 = Sept 2025 - Aug 2026
    $depenseInExercice = Depense::factory()->create([
        'libelle' => 'Dans exercice',
        'date' => '2025-11-01',
        'saisi_par' => $this->user->id,
    ]);

    // Exercice 2024 = Sept 2024 - Aug 2025
    $depenseOutExercice = Depense::factory()->create([
        'libelle' => 'Hors exercice',
        'date' => '2024-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(DepenseList::class)
        ->set('exercice', 2025)
        ->assertSee('Dans exercice')
        ->assertDontSee('Hors exercice');
});

it('can delete a depense with soft delete', function () {
    $depense = Depense::factory()->create([
        'libelle' => 'A supprimer',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(DepenseList::class)
        ->call('delete', $depense->id);

    $this->assertSoftDeleted('depenses', ['id' => $depense->id]);
});

it('displays reference column in depense list', function () {
    Depense::factory()->create([
        'libelle' => 'Achat fournitures',
        'reference' => 'REF-2025-042',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(DepenseList::class)
        ->assertSee('Référence')
        ->assertSee('REF-2025-042');
});

it('filters depenses by beneficiaire', function () {
    Depense::factory()->create([
        'libelle' => 'Dépense Alpha',
        'beneficiaire' => 'Alpha Corp',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);
    Depense::factory()->create([
        'libelle' => 'Dépense Beta',
        'beneficiaire' => 'Beta SA',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(DepenseList::class)
        ->set('beneficiaire', 'Alpha')
        ->assertSee('Dépense Alpha')
        ->assertDontSee('Dépense Beta');
});
