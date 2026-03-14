<?php

use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\Recette;
use App\Models\Tiers;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\SoldeService;

beforeEach(function () {
    $this->service = new SoldeService;
    $this->user = User::factory()->create();
});

it('returns solde_initial when no movements', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial'      => 1000.00,
        'date_solde_initial' => '2024-01-01',
    ]);

    expect($this->service->solde($compte))->toBe(1000.0);
});

it('adds recettes since date_solde_initial', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial'      => 500.00,
        'date_solde_initial' => '2024-06-01',
    ]);
    Recette::factory()->create([
        'compte_id'     => $compte->id,
        'montant_total' => 200.00,
        'date'          => '2024-07-01',
        'saisi_par'     => $this->user->id,
    ]);
    // Before date_solde_initial — must be ignored
    Recette::factory()->create([
        'compte_id'     => $compte->id,
        'montant_total' => 999.00,
        'date'          => '2024-05-01',
        'saisi_par'     => $this->user->id,
    ]);

    expect($this->service->solde($compte))->toBe(700.0);
});

it('subtracts depenses since date_solde_initial', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial'      => 1000.00,
        'date_solde_initial' => '2024-01-01',
    ]);
    Depense::factory()->create([
        'compte_id'     => $compte->id,
        'montant_total' => 300.00,
        'date'          => '2024-03-01',
        'saisi_par'     => $this->user->id,
    ]);

    expect($this->service->solde($compte))->toBe(700.0);
});

it('adds cotisations (date_paiement) since date_solde_initial', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial'      => 0.00,
        'date_solde_initial' => '2024-01-01',
    ]);
    $tiers = Tiers::factory()->membre()->create();
    Cotisation::factory()->create([
        'compte_id'     => $compte->id,
        'montant'       => 50.00,
        'date_paiement' => '2024-02-01',
        'tiers_id'      => $tiers->id,
    ]);

    expect($this->service->solde($compte))->toBe(50.0);
});

it('subtracts dons since date_solde_initial', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial'      => 1000.00,
        'date_solde_initial' => '2024-01-01',
    ]);
    Don::factory()->create([
        'compte_id' => $compte->id,
        'montant'   => 100.00,
        'date'      => '2024-02-01',
        'saisi_par' => $this->user->id,
    ]);

    expect($this->service->solde($compte))->toBe(900.0);
});

it('adds virements received and subtracts virements sent', function () {
    $source      = CompteBancaire::factory()->create([
        'solde_initial'      => 2000.00,
        'date_solde_initial' => '2024-01-01',
    ]);
    $destination = CompteBancaire::factory()->create([
        'solde_initial'      => 500.00,
        'date_solde_initial' => '2024-01-01',
    ]);
    VirementInterne::factory()->create([
        'compte_source_id'      => $source->id,
        'compte_destination_id' => $destination->id,
        'montant'               => 400.00,
        'date'                  => '2024-03-01',
        'saisi_par'             => $this->user->id,
    ]);

    expect($this->service->solde($source))->toBe(1600.0);
    expect($this->service->solde($destination))->toBe(900.0);
});

it('ignores soft-deleted depenses', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial'      => 1000.00,
        'date_solde_initial' => '2024-01-01',
    ]);
    $depense = Depense::factory()->create([
        'compte_id'     => $compte->id,
        'montant_total' => 300.00,
        'date'          => '2024-03-01',
        'saisi_par'     => $this->user->id,
    ]);
    $depense->delete();

    expect($this->service->solde($compte))->toBe(1000.0);
});

it('ignores soft-deleted virements', function () {
    $source = CompteBancaire::factory()->create([
        'solde_initial'      => 1000.00,
        'date_solde_initial' => '2024-01-01',
    ]);
    $destination = CompteBancaire::factory()->create([
        'solde_initial'      => 0.00,
        'date_solde_initial' => '2024-01-01',
    ]);
    $virement = VirementInterne::factory()->create([
        'compte_source_id'      => $source->id,
        'compte_destination_id' => $destination->id,
        'montant'               => 400.00,
        'date'                  => '2024-03-01',
        'saisi_par'             => $this->user->id,
    ]);
    $virement->delete();

    expect($this->service->solde($source))->toBe(1000.0);
    expect($this->service->solde($destination))->toBe(0.0);
});

it('handles null date_solde_initial by including all history', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial'      => 100.00,
        'date_solde_initial' => null,
    ]);
    Recette::factory()->create([
        'compte_id'     => $compte->id,
        'montant_total' => 50.00,
        'date'          => '2000-01-01',
        'saisi_par'     => $this->user->id,
    ]);

    expect($this->service->solde($compte))->toBe(150.0);
});
