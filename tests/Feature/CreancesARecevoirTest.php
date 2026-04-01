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
