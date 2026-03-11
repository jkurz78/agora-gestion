<?php

use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\Membre;
use App\Models\Recette;
use App\Models\User;
use App\Services\RapprochementService;

beforeEach(function () {
    $this->service = new RapprochementService;
    $this->user = User::factory()->create();
    $this->compte = CompteBancaire::factory()->create([
        'solde_initial' => 1000.00,
    ]);
});

it('returns solde_initial when no transactions', function () {
    $solde = $this->service->soldeTheorique($this->compte);

    expect($solde)->toBe(1000.0);
});

it('computes solde theorique with pointed transactions', function () {
    // Pointed recette: +500
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 500.00,
        'pointe' => true,
        'saisi_par' => $this->user->id,
    ]);

    // Non-pointed recette: should not count
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 200.00,
        'pointe' => false,
        'saisi_par' => $this->user->id,
    ]);

    // Pointed depense: -300
    Depense::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 300.00,
        'pointe' => true,
        'saisi_par' => $this->user->id,
    ]);

    // Pointed don: +100
    Don::factory()->create([
        'compte_id' => $this->compte->id,
        'montant' => 100.00,
        'pointe' => true,
        'saisi_par' => $this->user->id,
    ]);

    // Pointed cotisation: +50
    Cotisation::factory()->create([
        'compte_id' => $this->compte->id,
        'montant' => 50.00,
        'pointe' => true,
        'membre_id' => Membre::factory()->create()->id,
    ]);

    // solde_initial(1000) + recette(500) - depense(300) + don(100) + cotisation(50) = 1350
    $solde = $this->service->soldeTheorique($this->compte);

    expect($solde)->toBe(1350.0);
});

it('toggles pointe on and off', function () {
    $depense = Depense::factory()->create([
        'compte_id' => $this->compte->id,
        'pointe' => false,
        'saisi_par' => $this->user->id,
    ]);

    // Toggle on
    $result = $this->service->togglePointe('depense', $depense->id);
    expect($result)->toBeTrue();
    expect($depense->fresh()->pointe)->toBeTrue();

    // Toggle off
    $result = $this->service->togglePointe('depense', $depense->id);
    expect($result)->toBeFalse();
    expect($depense->fresh()->pointe)->toBeFalse();
});

it('updates solde after toggling', function () {
    $depense = Depense::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 200.00,
        'pointe' => false,
        'saisi_par' => $this->user->id,
    ]);

    // Before toggle: solde = 1000 (no pointed transactions)
    expect($this->service->soldeTheorique($this->compte))->toBe(1000.0);

    // Toggle on: solde = 1000 - 200 = 800
    $this->service->togglePointe('depense', $depense->id);
    expect($this->service->soldeTheorique($this->compte))->toBe(800.0);
});
