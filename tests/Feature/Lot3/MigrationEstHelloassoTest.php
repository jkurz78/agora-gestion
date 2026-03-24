<?php

declare(strict_types=1);

use App\Models\Tiers;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has est_helloasso boolean column on tiers table', function () {
    $tiers = Tiers::factory()->create();
    expect($tiers->est_helloasso)->toBeFalse();
});

it('defaults est_helloasso to false', function () {
    $tiers = Tiers::factory()->create();
    expect($tiers->est_helloasso)->toBeFalse();
});

it('can set est_helloasso to true', function () {
    $tiers = Tiers::factory()->create(['est_helloasso' => true]);
    expect($tiers->est_helloasso)->toBeTrue();
});
