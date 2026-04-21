<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Models\TiersPortailOtp;
use App\Services\Portail\OtpService;
use App\Services\Portail\RequestResult;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

it('email inconnu — aucun enregistrement créé, aucun mail envoyé, aucune exception', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    // Aucun Tiers avec cet email
    $result = app(OtpService::class)->request($asso, 'unknown@example.org');

    expect(TiersPortailOtp::count())->toBe(0);
    Mail::assertNothingSent();
    expect($result)->toBe(RequestResult::Silent);
});

/**
 * Vérifie que Hash::make est appelé pour un email connu.
 * On intercepte la façade avec shouldReceive/andReturnUsing pour que le
 * vrai BcryptHasher soit utilisé (NOT NULL constraint satisfaite) tout en
 * permettant de vérifier l'appel.
 */
it('email connu — Hash::make est appelé', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $hasher = new BcryptHasher;
    Hash::shouldReceive('make')
        ->atLeast()->once()
        ->andReturnUsing(fn (string $value, array $options = []) => $hasher->make($value, $options));

    app(OtpService::class)->request($asso, 'marie@example.org');
});

/**
 * Vérifie que Hash::make est appelé même pour un email inconnu.
 * C'est ce test qui drive l'implémentation GREEN (temps constant anti-énumération).
 */
it('email inconnu — Hash::make est quand même appelé (temps constant)', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    // Aucun Tiers avec cet email
    $hasher = new BcryptHasher;
    Hash::shouldReceive('make')
        ->atLeast()->once()
        ->andReturnUsing(fn (string $value, array $options = []) => $hasher->make($value, $options));

    app(OtpService::class)->request($asso, 'unknown@example.org');
});

it('Tiers appartenant à une autre association — aucun OTP créé, aucun mail envoyé (cross-tenant)', function () {
    $asso = Association::factory()->create();
    $autreAsso = Association::factory()->create();

    // Crée le Tiers dans l'autre association
    TenantContext::boot($autreAsso);
    Tiers::factory()->create([
        'association_id' => $autreAsso->id,
        'email' => 'marie@example.org',
    ]);

    // Boote sur $asso : le Tiers de $autreAsso est invisible via TenantScope
    TenantContext::boot($asso);

    app(OtpService::class)->request($asso, 'marie@example.org');

    expect(TiersPortailOtp::withoutGlobalScopes()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('Tiers soft-deleted — traité comme inconnu', function () {
    $hasSoftDeletes = in_array(
        SoftDeletes::class,
        class_uses_recursive(Tiers::class)
    );

    if (! $hasSoftDeletes) {
        $this->markTestSkipped('Tiers lacks SoftDeletes trait');
    }

    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);
    $tiers->delete();

    app(OtpService::class)->request($asso, 'marie@example.org');

    expect(TiersPortailOtp::count())->toBe(0);
    Mail::assertNothingSent();
});

it('Tiers archivé — traité comme inconnu [dette Q1]', function () {
    // TODO: activer quand le champ `archived` sera ajouté sur Tiers (Q1 debt)
    $this->markTestSkipped('archived field not yet on Tiers — Q1 debt');
});
