<?php

use App\Livewire\DepenseList;
use App\Models\Depense;
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
        ->assertSee('Réf.')
        ->assertSee('REF-2025-042');
});

it('filters depenses by tiers', function () {
    $tiersAlpha = \App\Models\Tiers::factory()->create(['nom' => 'Alpha Corp', 'pour_depenses' => true]);
    $tiersBeta = \App\Models\Tiers::factory()->create(['nom' => 'Beta SA', 'pour_depenses' => true]);

    Depense::factory()->create([
        'libelle' => 'Dépense Alpha',
        'tiers_id' => $tiersAlpha->id,
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);
    Depense::factory()->create([
        'libelle' => 'Dépense Beta',
        'tiers_id' => $tiersBeta->id,
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(DepenseList::class)
        ->set('tiers', 'Alpha')
        ->assertSee('Dépense Alpha')
        ->assertDontSee('Dépense Beta');
});

it('affiche la colonne N° dans la liste des dépenses', function () {
    $compte = \App\Models\CompteBancaire::factory()->create();
    Depense::factory()->create([
        'numero_piece' => '2025-2026:00001',
        'compte_id'    => $compte->id,
        'saisi_par'    => $this->user->id,
        'date'         => '2025-10-01',
    ]);

    Livewire::test(DepenseList::class)
        ->assertSee('N°')
        ->assertSee('2025-2026:00001');
});

it('has default perPage of 20', function () {
    Livewire::test(DepenseList::class)
        ->assertSet('perPage', 20);
});

it('resets to page 1 when perPage changes', function () {
    Livewire::test(DepenseList::class)
        ->set('perPage', 50)
        ->assertSet('paginators.page', 1);
});
