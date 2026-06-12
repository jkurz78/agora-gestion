<?php

declare(strict_types=1);

use App\Enums\Sens;
use App\Enums\TypeTransaction;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Vérifie que Transaction::sensTresorerie() retourne le bon sens
 * selon le type et le type_ecriture (normale vs extourne).
 *
 * TenantContext est amorcé par le beforeEach global de tests/Pest.php.
 */

test('sensTresorerie recette normale = Recette', function () {
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'type_ecriture' => 'normale',
    ]);
    expect($tx->sensTresorerie())->toBe(Sens::Recette);
});

test('sensTresorerie depense normale = Depense', function () {
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'type_ecriture' => 'normale',
    ]);
    expect($tx->sensTresorerie())->toBe(Sens::Depense);
});

test('sensTresorerie recette extourne = Depense (flip)', function () {
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'type_ecriture' => 'extourne',
    ]);
    expect($tx->sensTresorerie())->toBe(Sens::Depense);
});

test('sensTresorerie depense extourne = Recette (flip)', function () {
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'type_ecriture' => 'extourne',
    ]);
    expect($tx->sensTresorerie())->toBe(Sens::Recette);
});
