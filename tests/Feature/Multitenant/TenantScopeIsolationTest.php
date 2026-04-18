<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Tenant\TenantContext;

beforeEach(fn () => TenantContext::clear());
afterEach(fn () => TenantContext::clear());

it('Tiers are scoped to current tenant', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    TenantContext::boot($assoA);
    Tiers::create(['nom' => 'TiersA', 'type' => 'particulier']);

    TenantContext::boot($assoB);
    Tiers::create(['nom' => 'TiersB', 'type' => 'particulier']);

    TenantContext::boot($assoA);
    expect(Tiers::count())->toBe(1)
        ->and(Tiers::first()->nom)->toBe('TIERSA');

    TenantContext::boot($assoB);
    expect(Tiers::count())->toBe(1)
        ->and(Tiers::first()->nom)->toBe('TIERSB');
});

it('create auto-fills association_id from TenantContext', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::create(['nom' => 'Auto', 'type' => 'particulier']);
    expect($tiers->association_id)->toBe($asso->id);
});
