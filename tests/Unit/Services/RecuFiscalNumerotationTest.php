<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;

function invokePrivateMethod(object $object, string $method, array $args): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($object, $args);
}

it('génère des numéros séquentiels par tenant et année', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $service = app(RecuFiscalService::class);
    $tiers = Tiers::factory()->create();

    $numero1 = invokePrivateMethod($service, 'allouerNumero', [2026]);
    RecuFiscalEmis::factory()->create(['numero' => $numero1, 'annee_civile' => 2026, 'tiers_id' => $tiers->id]);

    $numero2 = invokePrivateMethod($service, 'allouerNumero', [2026]);
    RecuFiscalEmis::factory()->create(['numero' => $numero2, 'annee_civile' => 2026, 'tiers_id' => $tiers->id]);

    $numero3 = invokePrivateMethod($service, 'allouerNumero', [2026]);

    expect($numero1)->toBe('2026-0001');
    expect($numero2)->toBe('2026-0002');
    expect($numero3)->toBe('2026-0003');
});

it('isole les séquences par tenant', function () {
    $asso1 = Association::factory()->create();
    $asso2 = Association::factory()->create();

    TenantContext::boot($asso1);
    $service = app(RecuFiscalService::class);
    $tiers1 = Tiers::factory()->create();
    $numeroAsso1 = invokePrivateMethod($service, 'allouerNumero', [2026]);
    RecuFiscalEmis::factory()->create(['numero' => $numeroAsso1, 'annee_civile' => 2026, 'tiers_id' => $tiers1->id]);

    TenantContext::boot($asso2);
    $tiers2 = Tiers::factory()->create();
    $numeroAsso2 = invokePrivateMethod($service, 'allouerNumero', [2026]);

    expect($numeroAsso1)->toBe('2026-0001');
    expect($numeroAsso2)->toBe('2026-0001');  // chaque asso démarre à 0001
});

it('isole les séquences par année civile', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $service = app(RecuFiscalService::class);
    $tiers = Tiers::factory()->create();

    $numero2025 = invokePrivateMethod($service, 'allouerNumero', [2025]);
    RecuFiscalEmis::factory()->create(['numero' => $numero2025, 'annee_civile' => 2025, 'tiers_id' => $tiers->id]);

    $numero2026 = invokePrivateMethod($service, 'allouerNumero', [2026]);

    expect($numero2025)->toBe('2025-0001');
    expect($numero2026)->toBe('2026-0001');
});
