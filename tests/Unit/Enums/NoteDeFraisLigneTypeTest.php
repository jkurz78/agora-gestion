<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Models\NoteDeFraisLigne;

it('expose les deux cas standard et kilometrique', function () {
    expect(NoteDeFraisLigneType::Standard->value)->toBe('standard');
    expect(NoteDeFraisLigneType::Kilometrique->value)->toBe('kilometrique');
    expect(NoteDeFraisLigneType::cases())->toHaveCount(2);
});

it('cast type enum et metadata array sur NoteDeFraisLigne', function () {
    $ligne = new NoteDeFraisLigne;
    $ligne->type = NoteDeFraisLigneType::Kilometrique;
    $ligne->metadata = ['cv_fiscaux' => 5, 'distance_km' => 420, 'bareme_eur_km' => 0.636];

    expect($ligne->type)->toBe(NoteDeFraisLigneType::Kilometrique);
    expect($ligne->metadata)->toBe(['cv_fiscaux' => 5, 'distance_km' => 420, 'bareme_eur_km' => 0.636]);
});
