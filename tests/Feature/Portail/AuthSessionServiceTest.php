<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Services\Portail\AuthSessionService;
use App\Tenant\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : markPendingTiers stocke les IDs en session
// ─────────────────────────────────────────────────────────────────────────────
it('markPendingTiers stocke les IDs int en session sous portail.pending_tiers_ids', function () {
    $service = new AuthSessionService;

    $service->markPendingTiers([10, 25]);

    $stored = session('portail.pending_tiers_ids');
    expect($stored)->toBeArray()
        ->and($stored)->toContain(10)
        ->and($stored)->toContain(25)
        ->and($stored)->toHaveCount(2);
});

it('markPendingTiers convertit les valeurs en int', function () {
    $service = new AuthSessionService;

    $service->markPendingTiers(['7', '42']);

    $stored = session('portail.pending_tiers_ids');
    expect($stored[0])->toBe(7)
        ->and($stored[1])->toBe(42);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : hasPendingChoice retourne true si ≥ 2 valeurs
// ─────────────────────────────────────────────────────────────────────────────
it('hasPendingChoice retourne true si session contient 2 IDs ou plus', function () {
    $service = new AuthSessionService;

    $service->markPendingTiers([3, 7]);

    expect($service->hasPendingChoice())->toBeTrue();
});

it('hasPendingChoice retourne false si session contient 1 seul ID', function () {
    $service = new AuthSessionService;

    $service->markPendingTiers([3]);

    expect($service->hasPendingChoice())->toBeFalse();
});

it('hasPendingChoice retourne false si session est vide', function () {
    $service = new AuthSessionService;

    expect($service->hasPendingChoice())->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : pendingTiers retourne les modèles Tiers avec scope tenant
// ─────────────────────────────────────────────────────────────────────────────
it('pendingTiers retourne les modèles Tiers correspondant aux IDs en session', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers1 = Tiers::factory()->create(['association_id' => $asso->id]);
    $tiers2 = Tiers::factory()->create(['association_id' => $asso->id]);

    $service = new AuthSessionService;
    $service->markPendingTiers([(int) $tiers1->id, (int) $tiers2->id]);

    $result = $service->pendingTiers();

    expect($result)->toHaveCount(2);
    expect($result->pluck('id')->map('intval')->toArray())
        ->toContain((int) $tiers1->id)
        ->toContain((int) $tiers2->id);
});

it('pendingTiers retourne une collection vide si session est vide', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $service = new AuthSessionService;
    $result = $service->pendingTiers();

    expect($result)->toHaveCount(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : chooseTiers avec ID valide → login + purge session
// ─────────────────────────────────────────────────────────────────────────────
it('chooseTiers avec ID dans la liste pending connecte le Tiers et purge la session', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers1 = Tiers::factory()->create(['association_id' => $asso->id]);
    $tiers2 = Tiers::factory()->create(['association_id' => $asso->id]);

    $service = new AuthSessionService;
    $service->markPendingTiers([(int) $tiers1->id, (int) $tiers2->id]);

    $service->chooseTiers((int) $tiers1->id);

    // Guard login effectué
    expect(Auth::guard('tiers-portail')->check())->toBeTrue();
    expect((int) Auth::guard('tiers-portail')->id())->toBe((int) $tiers1->id);

    // Clé pending purgée
    expect(session('portail.pending_tiers_ids'))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : chooseTiers avec ID hors liste pending → AuthorizationException
// ─────────────────────────────────────────────────────────────────────────────
it('chooseTiers avec ID hors liste pending lève AuthorizationException', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers1 = Tiers::factory()->create(['association_id' => $asso->id]);
    $tiers2 = Tiers::factory()->create(['association_id' => $asso->id]);

    $service = new AuthSessionService;
    $service->markPendingTiers([(int) $tiers1->id]);

    // tiers2 n'est pas dans la liste → exception
    expect(fn () => $service->chooseTiers((int) $tiers2->id))
        ->toThrow(AuthorizationException::class);
});

it('chooseTiers sans aucune session pending lève AuthorizationException', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);

    $service = new AuthSessionService;

    expect(fn () => $service->chooseTiers((int) $tiers->id))
        ->toThrow(AuthorizationException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : loginSingleTiers — shortcut login direct
// ─────────────────────────────────────────────────────────────────────────────
it('loginSingleTiers connecte directement le Tiers sur la garde tiers-portail', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);

    $service = new AuthSessionService;
    $service->loginSingleTiers($tiers);

    expect(Auth::guard('tiers-portail')->check())->toBeTrue();
    expect((int) Auth::guard('tiers-portail')->id())->toBe((int) $tiers->id);
});

it('loginSingleTiers ne touche pas à la session portail.pending_tiers_ids', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers1 = Tiers::factory()->create(['association_id' => $asso->id]);
    $tiers2 = Tiers::factory()->create(['association_id' => $asso->id]);

    $service = new AuthSessionService;
    $service->markPendingTiers([(int) $tiers1->id, (int) $tiers2->id]);

    // loginSingleTiers ne doit pas purger pending
    $service->loginSingleTiers($tiers1);

    expect(session('portail.pending_tiers_ids'))->toBeArray()->toHaveCount(2);
});
