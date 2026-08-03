<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Famille;
use App\Services\Onboarding\DefaultChartOfAccountsService;
use App\Tenant\TenantContext;

it('seeds 625A with FraisKilometriques and 771 with Don+AbandonCreance', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $result = (new DefaultChartOfAccountsService)->applyTo($asso);

    expect($result)->toHaveKeys(['familles', 'comptes'])
        ->and(array_keys($result))->toHaveCount(2);

    $km = Compte::where('numero_pcg', '625A')->firstOrFail();
    expect($km->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();

    $abandon = Compte::where('numero_pcg', '771')->firstOrFail();
    expect($abandon->hasUsage(UsageComptable::Don))->toBeTrue();
    expect($abandon->hasUsage(UsageComptable::AbandonCreance))->toBeTrue();

    $dons = Compte::where('numero_pcg', '754')->firstOrFail();
    expect($dons->hasUsage(UsageComptable::Don))->toBeTrue();

    $mecenat = Compte::where('numero_pcg', '756')->firstOrFail();
    expect($mecenat->hasUsage(UsageComptable::Don))->toBeTrue();

    $coti = Compte::where('numero_pcg', '751')->firstOrFail();
    expect($coti->hasUsage(UsageComptable::Cotisation))->toBeTrue();

    foreach (['706A', '706B'] as $numero) {
        $compte = Compte::where('numero_pcg', $numero)->firstOrFail();
        expect($compte->hasUsage(UsageComptable::Inscription))->toBeTrue();
    }
});

it('creates the account families derived from PCG prefixes', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    (new DefaultChartOfAccountsService)->applyTo($asso);

    $compte = Compte::where('numero_pcg', '625A')->firstOrFail();
    expect($compte->classe)->toBe(6);
    expect($compte->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();

    // Toutes les familles créées en primaire existent bien (60/61/62/70/74/75/76/77 —
    // 627 "Frais bancaires" est nichée sous 62, pas une famille 66 à part : le
    // rattachement famille est dérivé par préfixe numero_pcg, voir
    // DefaultChartOfAccountsService::defaultStructure()).
    expect(Famille::where('association_id', $asso->id)->count())->toBe(8);

    // 627 doit être rattaché à sa famille dérivée par préfixe (62), pas 66.
    $fraisBancaires = Compte::where('numero_pcg', '627')->firstOrFail();
    expect($fraisBancaires->code_famille)->toBe('62');
});
