<?php

declare(strict_types=1);

use App\Models\Association;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->service = new ExerciceService;
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
    CarbonImmutable::setTestNow(null);
});

it('current returns year based on tenant exercice_mois_debut', function () {
    $assoSept = Association::factory()->create(['exercice_mois_debut' => 9]);
    $assoJan = Association::factory()->create(['exercice_mois_debut' => 1]);

    // Fix "now" to 2026-06-15 for predictable test
    CarbonImmutable::setTestNow('2026-06-15');

    TenantContext::boot($assoSept);
    expect($this->service->current())->toBe(2025); // exercice sept 2025 - aug 2026

    TenantContext::boot($assoJan);
    expect($this->service->current())->toBe(2026); // exercice jan 2026 - dec 2026

    CarbonImmutable::setTestNow();
});

it('dateRange returns start and end based on tenant config', function () {
    $asso = Association::factory()->create(['exercice_mois_debut' => 9]);
    TenantContext::boot($asso);

    $range = $this->service->dateRange(2025);
    expect($range['start']->toDateString())->toBe('2025-09-01')
        ->and($range['end']->toDateString())->toBe('2026-08-31');

    $assoJan = Association::factory()->create(['exercice_mois_debut' => 1]);
    TenantContext::boot($assoJan);

    $range = $this->service->dateRange(2026);
    expect($range['start']->toDateString())->toBe('2026-01-01')
        ->and($range['end']->toDateString())->toBe('2026-12-31');
});

it('label is year-only when exercice is jan-dec', function () {
    $asso = Association::factory()->create(['exercice_mois_debut' => 1]);
    TenantContext::boot($asso);

    expect($this->service->label(2026))->toBe('2026');
});

it('label is year-range when exercice starts mid-year', function () {
    $asso = Association::factory()->create(['exercice_mois_debut' => 9]);
    TenantContext::boot($asso);

    expect($this->service->label(2025))->toBe('2025-2026');
});

it('Exercice::dateDebut reads exercice_mois_debut from associated tenant', function () {
    $asso = Association::factory()->create(['exercice_mois_debut' => 1]);
    TenantContext::boot($asso);

    $ex = \App\Models\Exercice::create(['annee' => 2026, 'statut' => \App\Enums\StatutExercice::Ouvert]);
    expect($ex->dateDebut()->toDateString())->toBe('2026-01-01')
        ->and($ex->dateFin()->toDateString())->toBe('2026-12-31')
        ->and($ex->label())->toBe('2026');
});
