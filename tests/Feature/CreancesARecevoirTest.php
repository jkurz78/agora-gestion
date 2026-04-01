<?php
// tests/Feature/CreancesARecevoirTest.php
declare(strict_types=1);

use App\Models\CompteBancaire;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has a system account named Créances à recevoir', function () {
    $compte = CompteBancaire::where('nom', 'Créances à recevoir')->first();

    expect($compte)->not->toBeNull()
        ->and($compte->est_systeme)->toBeTrue()
        ->and($compte->actif_recettes_depenses)->toBeTrue();
});

it('includes Créances à recevoir in comptes list for recettes', function () {
    $creances = CompteBancaire::where('nom', 'Créances à recevoir')->first();
    $compteNormal = CompteBancaire::factory()->create(['actif_recettes_depenses' => true]);

    $comptes = CompteBancaire::where('actif_recettes_depenses', true)->orderBy('nom')->get();
    expect($comptes->pluck('id')->toArray())->toContain($creances->id)
        ->and($comptes->pluck('id')->toArray())->toContain($compteNormal->id);
});

it('excludes Créances à recevoir from comptes list for non-recette contexts', function () {
    $creances = CompteBancaire::where('nom', 'Créances à recevoir')->first();

    $comptes = CompteBancaire::where('est_systeme', false)->orderBy('nom')->get();
    expect($comptes->pluck('id')->toArray())->not->toContain($creances->id);
});
