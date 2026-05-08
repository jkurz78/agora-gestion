<?php

declare(strict_types=1);

use App\Enums\RegimeFiscalDon;
use App\Models\Association;

it('persiste les champs fiscaux d\'éligibilité reçu fiscal sur Association', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'regime_fiscal_don' => RegimeFiscalDon::InteretGeneral,
        'objet_recu_fiscal' => 'Œuvre d\'intérêt général à caractère social',
        'rescrit_fiscal_numero' => '2024/RES/0042',
        'rescrit_fiscal_date' => '2024-06-15',
        'signataire_nom' => 'Jean Dupont',
        'signataire_qualite' => 'Président',
        'loi_coluche_eligible' => true,
        'ifi_eligible' => false,
    ]);

    $asso->refresh();

    expect($asso->eligible_recu_fiscal)->toBeTrue();
    expect($asso->regime_fiscal_don)->toBe(RegimeFiscalDon::InteretGeneral);
    expect($asso->objet_recu_fiscal)->toBe('Œuvre d\'intérêt général à caractère social');
    expect($asso->rescrit_fiscal_numero)->toBe('2024/RES/0042');
    expect($asso->rescrit_fiscal_date->format('Y-m-d'))->toBe('2024-06-15');
    expect($asso->signataire_nom)->toBe('Jean Dupont');
    expect($asso->signataire_qualite)->toBe('Président');
    expect($asso->loi_coluche_eligible)->toBeTrue();
    expect($asso->ifi_eligible)->toBeFalse();
});

it('par défaut, eligible_recu_fiscal est false', function () {
    $asso = Association::factory()->create();
    expect($asso->eligible_recu_fiscal)->toBeFalse();
});

it('par défaut, loi_coluche_eligible et ifi_eligible sont false', function () {
    $asso = Association::factory()->create();
    expect($asso->loi_coluche_eligible)->toBeFalse();
    expect($asso->ifi_eligible)->toBeFalse();
});

it('accepte toutes les valeurs de l\'enum RegimeFiscalDon', function () {
    foreach (RegimeFiscalDon::cases() as $case) {
        $asso = Association::factory()->create(['regime_fiscal_don' => $case]);
        $asso->refresh();
        expect($asso->regime_fiscal_don)->toBe($case);
    }
});

it('accepte regime_fiscal_don null', function () {
    $asso = Association::factory()->create(['regime_fiscal_don' => null]);
    $asso->refresh();
    expect($asso->regime_fiscal_don)->toBeNull();
});
