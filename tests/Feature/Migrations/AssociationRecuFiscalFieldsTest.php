<?php

declare(strict_types=1);

use App\Models\Association;

it('persiste les champs fiscaux d\'éligibilité reçu fiscal sur Association', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'regime_fiscal_don' => 'Intérêt général',
        'objet_recu_fiscal' => 'Œuvre d\'intérêt général à caractère social',
        'rescrit_fiscal_numero' => '2024/RES/0042',
        'rescrit_fiscal_date' => '2024-06-15',
        'signataire_nom' => 'Jean Dupont',
        'signataire_qualite' => 'Président',
    ]);

    $asso->refresh();

    expect($asso->eligible_recu_fiscal)->toBeTrue();
    expect($asso->regime_fiscal_don)->toBe('Intérêt général');
    expect($asso->objet_recu_fiscal)->toBe('Œuvre d\'intérêt général à caractère social');
    expect($asso->rescrit_fiscal_numero)->toBe('2024/RES/0042');
    expect($asso->rescrit_fiscal_date->format('Y-m-d'))->toBe('2024-06-15');
    expect($asso->signataire_nom)->toBe('Jean Dupont');
    expect($asso->signataire_qualite)->toBe('Président');
});

it('par défaut, eligible_recu_fiscal est false', function () {
    $asso = Association::factory()->create();
    expect($asso->eligible_recu_fiscal)->toBeFalse();
});
