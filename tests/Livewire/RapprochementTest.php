<?php

use App\Livewire\Rapprochement;
use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\Recette;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders with compte selector', function () {
    $compte = CompteBancaire::factory()->create(['nom' => 'Compte Courant']);

    Livewire::test(Rapprochement::class)
        ->assertOk()
        ->assertSee('Compte Courant')
        ->assertSee('Compte bancaire');
});

it('toggles pointe on a transaction', function () {
    $compte = CompteBancaire::factory()->create();
    $depense = Depense::factory()->create([
        'compte_id' => $compte->id,
        'pointe' => false,
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(Rapprochement::class)
        ->set('compte_id', $compte->id)
        ->call('toggle', 'depense', $depense->id);

    expect($depense->fresh()->pointe)->toBeTrue();
});

it('displays correct solde theorique', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial' => 5000.00,
    ]);

    Recette::factory()->create([
        'compte_id' => $compte->id,
        'montant_total' => 1000.00,
        'pointe' => true,
        'saisi_par' => $this->user->id,
    ]);

    Depense::factory()->create([
        'compte_id' => $compte->id,
        'montant_total' => 500.00,
        'pointe' => true,
        'saisi_par' => $this->user->id,
    ]);

    // 5000 + 1000 - 500 = 5500
    Livewire::test(Rapprochement::class)
        ->set('compte_id', $compte->id)
        ->assertSee('5 500,00');
});
