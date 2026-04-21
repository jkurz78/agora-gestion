<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;

// Each test manages TenantContext itself to be explicit about isolation.
beforeEach(fn () => TenantContext::clear());

it('loginUsingId connecte un Tiers sur la garde tiers-portail', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);

    Auth::guard('tiers-portail')->loginUsingId($tiers->id);

    expect(Auth::guard('tiers-portail')->check())->toBeTrue()
        ->and((int) Auth::guard('tiers-portail')->id())->toBe((int) $tiers->id)
        ->and(Auth::guard('tiers-portail')->user())->toBeInstanceOf(Tiers::class);
});

it('la garde web reste déconnectée après un login Tiers', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);

    Auth::guard('tiers-portail')->loginUsingId($tiers->id);

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('login User sur web — la garde tiers-portail reste déconnectée', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $user = User::factory()->create();

    Auth::guard('web')->login($user);

    expect(Auth::guard('tiers-portail')->check())->toBeFalse();
});

it('co-existence : User sur web ET Tiers sur tiers-portail simultanément', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $user = User::factory()->create();
    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);

    Auth::guard('web')->login($user);
    Auth::guard('tiers-portail')->loginUsingId($tiers->id);

    expect(Auth::guard('web')->check())->toBeTrue()
        ->and(Auth::guard('tiers-portail')->check())->toBeTrue()
        ->and(Auth::guard('web')->user())->toBeInstanceOf(User::class)
        ->and((int) Auth::guard('web')->id())->toBe((int) $user->id)
        ->and(Auth::guard('tiers-portail')->user())->toBeInstanceOf(Tiers::class)
        ->and((int) Auth::guard('tiers-portail')->id())->toBe((int) $tiers->id);
});

it('pas de password requis — loginUsingId ne lève pas d\'exception', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);

    $result = Auth::guard('tiers-portail')->loginUsingId($tiers->id);

    expect($result)->toBeInstanceOf(Tiers::class)
        ->and($tiers->getAuthPassword())->toBeString();
});

it('TenantScope respecté — loginUsingId d\'un Tiers hors tenant courant échoue', function () {
    // Create two associations with their respective Tiers
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    TenantContext::boot($assoA);
    $tiersA = Tiers::factory()->create(['association_id' => $assoA->id]);

    // Now boot asso B — tiersA is not visible in this tenant
    TenantContext::boot($assoB);

    // loginUsingId uses Tiers::find internally — TenantScope returns null for tiersA
    $result = Auth::guard('tiers-portail')->loginUsingId($tiersA->id);

    expect($result)->toBeFalse()
        ->and(Auth::guard('tiers-portail')->check())->toBeFalse();
});
