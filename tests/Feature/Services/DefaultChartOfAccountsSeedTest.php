<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\SousCategorie;
use App\Services\Onboarding\DefaultChartOfAccountsService;
use App\Tenant\TenantContext;

it('seeds 625A with FraisKilometriques and 771 with Don+AbandonCreance', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    (new DefaultChartOfAccountsService())->applyTo($asso);

    $km = SousCategorie::where('code_cerfa', '625A')->firstOrFail();
    expect($km->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();

    $abandon = SousCategorie::where('code_cerfa', '771')->firstOrFail();
    expect($abandon->hasUsage(UsageComptable::Don))->toBeTrue();
    expect($abandon->hasUsage(UsageComptable::AbandonCreance))->toBeTrue();

    $dons = SousCategorie::where('code_cerfa', '754')->firstOrFail();
    expect($dons->hasUsage(UsageComptable::Don))->toBeTrue();

    $coti = SousCategorie::where('code_cerfa', '751')->firstOrFail();
    expect($coti->hasUsage(UsageComptable::Cotisation))->toBeTrue();

    foreach (['706A', '706B'] as $cerfa) {
        $sc = SousCategorie::where('code_cerfa', $cerfa)->firstOrFail();
        expect($sc->hasUsage(UsageComptable::Inscription))->toBeTrue();
    }
});
