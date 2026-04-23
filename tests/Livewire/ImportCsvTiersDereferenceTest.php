<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Support\TiersImportDereferenceGuard;

it('ignore le décochage de pour_depenses si transactions Depense existent', function () {
    $tiers = Tiers::factory()->create([
        'nom' => 'Dupont',
        'pour_depenses' => true,
        'pour_recettes' => true,
    ]);
    Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'type' => TypeTransaction::Depense,
    ]);

    $data = ['pour_depenses' => false, 'pour_recettes' => true];
    [$guarded, $warnings] = TiersImportDereferenceGuard::apply($tiers, $data);

    expect($guarded['pour_depenses'])->toBeTrue();
    expect($guarded['pour_recettes'])->toBeTrue();
    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('DUPONT')
        ->and($warnings[0])->toContain('ignoré');
});

it('applique le décochage de pour_depenses si pas de transactions Depense', function () {
    $tiers = Tiers::factory()->create([
        'pour_depenses' => true,
        'pour_recettes' => true,
    ]);

    $data = ['pour_depenses' => false, 'pour_recettes' => true];
    [$guarded, $warnings] = TiersImportDereferenceGuard::apply($tiers, $data);

    expect($guarded['pour_depenses'])->toBeFalse();
    expect($warnings)->toBeEmpty();
});

it('ignore le décochage de pour_recettes si transactions Recette existent', function () {
    $tiers = Tiers::factory()->create([
        'pour_depenses' => true,
        'pour_recettes' => true,
    ]);
    Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'type' => TypeTransaction::Recette,
    ]);

    $data = ['pour_depenses' => true, 'pour_recettes' => false];
    [$guarded, $warnings] = TiersImportDereferenceGuard::apply($tiers, $data);

    expect($guarded['pour_recettes'])->toBeTrue();
    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('ignoré');
});

it('applique le décochage de pour_recettes si pas de transactions Recette', function () {
    $tiers = Tiers::factory()->create([
        'pour_depenses' => true,
        'pour_recettes' => true,
    ]);

    $data = ['pour_depenses' => true, 'pour_recettes' => false];
    [$guarded, $warnings] = TiersImportDereferenceGuard::apply($tiers, $data);

    expect($guarded['pour_recettes'])->toBeFalse();
    expect($warnings)->toBeEmpty();
});

it('produit deux warnings si les deux flags sont décochés avec transactions liées', function () {
    $tiers = Tiers::factory()->create([
        'pour_depenses' => true,
        'pour_recettes' => true,
    ]);
    Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'type' => TypeTransaction::Depense,
    ]);
    Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'type' => TypeTransaction::Recette,
    ]);

    $data = ['pour_depenses' => false, 'pour_recettes' => false];
    [$guarded, $warnings] = TiersImportDereferenceGuard::apply($tiers, $data);

    expect($guarded['pour_depenses'])->toBeTrue();
    expect($guarded['pour_recettes'])->toBeTrue();
    expect($warnings)->toHaveCount(2);
});

it('ne touche pas les champs absents du tableau data', function () {
    $tiers = Tiers::factory()->create([
        'pour_depenses' => true,
        'pour_recettes' => true,
    ]);

    // Neither flag in data — guard should leave data unchanged and emit no warnings
    $data = ['nom' => 'Tartempion'];
    [$guarded, $warnings] = TiersImportDereferenceGuard::apply($tiers, $data);

    expect($guarded)->toBe(['nom' => 'Tartempion']);
    expect($warnings)->toBeEmpty();
});

it('ne garde pas le flag si le tiers était déjà à false', function () {
    $tiers = Tiers::factory()->create([
        'pour_depenses' => false,
        'pour_recettes' => false,
    ]);
    Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'type' => TypeTransaction::Depense,
    ]);

    // Trying to set false when already false — not a dereferencing, no guard needed
    $data = ['pour_depenses' => false, 'pour_recettes' => false];
    [$guarded, $warnings] = TiersImportDereferenceGuard::apply($tiers, $data);

    expect($guarded['pour_depenses'])->toBeFalse();
    expect($warnings)->toBeEmpty();
});
