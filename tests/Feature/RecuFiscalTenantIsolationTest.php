<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Tenant\TenantContext;

it('le scope global empêche un autre tenant de voir les reçus', function () {
    $asso1 = Association::factory()->create();
    $asso2 = Association::factory()->create();

    TenantContext::boot($asso1);
    $tiers = Tiers::factory()->create();
    $recu = RecuFiscalEmis::factory()->create(['tiers_id' => $tiers->id]);

    expect(RecuFiscalEmis::find($recu->id))->not->toBeNull();

    TenantContext::boot($asso2);
    expect(RecuFiscalEmis::find($recu->id))->toBeNull();
});

it('sans TenantContext booté, RecuFiscalEmis::all() ne retourne rien', function () {
    // Override the global beforeEach boot — this test verifies fail-closed behavior.
    TenantContext::clear();

    // Create a reçu under a real tenant first.
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $tiers = Tiers::factory()->create();
    RecuFiscalEmis::factory()->create(['tiers_id' => $tiers->id]);

    // Now clear the context and confirm fail-closed: count must be 0.
    TenantContext::clear();

    expect(RecuFiscalEmis::count())->toBe(0);  // fail-closed
});
