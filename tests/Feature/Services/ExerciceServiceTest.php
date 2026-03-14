<?php

use App\Services\ExerciceService;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow(null);
    session()->forget('exercice_actif');
});

it('defaultDate retourne aujourd\'hui si dans l\'exercice', function () {
    CarbonImmutable::setTestNow('2025-10-15');
    session(['exercice_actif' => 2025]); // 2025-09-01 → 2026-08-31

    $result = app(ExerciceService::class)->defaultDate();

    expect($result)->toBe('2025-10-15');
});

it('defaultDate retourne dateFin si aujourd\'hui est après l\'exercice', function () {
    CarbonImmutable::setTestNow('2026-03-14');
    session(['exercice_actif' => 2023]); // 2023-09-01 → 2024-08-31

    $result = app(ExerciceService::class)->defaultDate();

    expect($result)->toBe('2024-08-31');
});

it('defaultDate retourne dateDebut si aujourd\'hui est avant l\'exercice', function () {
    CarbonImmutable::setTestNow('2026-03-14');
    session(['exercice_actif' => 2027]); // 2027-09-01 → 2028-08-31

    $result = app(ExerciceService::class)->defaultDate();

    expect($result)->toBe('2027-09-01');
});
