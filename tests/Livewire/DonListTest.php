<?php

use App\Livewire\DonList;
use App\Models\Don;
use App\Models\Donateur;
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

it('renders with dons', function () {
    $donateur = Donateur::factory()->create([
        'nom' => 'Dupont',
        'prenom' => 'Marie',
    ]);

    Don::factory()->create([
        'donateur_id' => $donateur->id,
        'date' => '2025-10-15',
        'montant' => 100.00,
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(DonList::class)
        ->assertOk()
        ->assertSee('Dupont Marie');
});

it('filters by exercice', function () {
    $don1 = Don::factory()->create([
        'date' => '2025-11-01',
        'objet' => 'Dans exercice',
        'saisi_par' => $this->user->id,
    ]);

    $don2 = Don::factory()->create([
        'date' => '2024-10-15',
        'objet' => 'Hors exercice',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(DonList::class)
        ->assertSee('Dans exercice')
        ->assertDontSee('Hors exercice');
});

it('can delete a don with soft delete', function () {
    $don = Don::factory()->create([
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(DonList::class)
        ->call('delete', $don->id);

    $this->assertSoftDeleted('dons', ['id' => $don->id]);
});
