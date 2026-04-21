<?php

declare(strict_types=1);

use App\Mail\Portail\OtpMail;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\TiersPortailOtp;
use App\Services\Portail\OtpService;
use App\Services\Portail\RequestResult;
use App\Services\Portail\VerifyStatus;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Helper : demande un OTP et récupère le code via Mail::assertSent.
 * Retourne le code en clair.
 */
function requestAndGetCode(OtpService $service, Association $asso, string $email): string
{
    Mail::fake();
    $service->request($asso, $email);

    $code = null;
    Mail::assertSent(OtpMail::class, function (OtpMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return (string) $code;
}

beforeEach(function () {
    Mail::fake();

    // Nettoyer le RateLimiter entre chaque test pour éviter la pollution
    RateLimiter::clear('portail-otp:*');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : code valide + 1 Tiers → Success([$tiersId]), consumed_at posé
// ─────────────────────────────────────────────────────────────────────────────
it('code valide + 1 Tiers → Success avec tiersId, consumed_at posé', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);
    $code = requestAndGetCode($service, $asso, 'marie@example.org');

    $result = $service->verify($asso, 'marie@example.org', $code);

    expect($result->status)->toBe(VerifyStatus::Success)
        ->and($result->tiersIds)->toBe([(int) $tiers->id]);

    $otp = TiersPortailOtp::withoutGlobalScopes()->first();
    expect($otp->consumed_at)->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : code valide + 2 Tiers même email → Success([$id1, $id2])
// ─────────────────────────────────────────────────────────────────────────────
it('code valide + 2 Tiers même email → Success avec les deux IDs', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $marie = Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'famille@example.org',
        'prenom' => 'Marie',
    ]);
    $paul = Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'famille@example.org',
        'prenom' => 'Paul',
    ]);

    $service = app(OtpService::class);
    $code = requestAndGetCode($service, $asso, 'famille@example.org');

    $result = $service->verify($asso, 'famille@example.org', $code);

    expect($result->status)->toBe(VerifyStatus::Success);
    expect($result->tiersIds)->toContain((int) $marie->id)
        ->and($result->tiersIds)->toContain((int) $paul->id)
        ->and($result->tiersIds)->toHaveCount(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : code incorrect → Invalid, attempts incrémenté
// ─────────────────────────────────────────────────────────────────────────────
it('code incorrect → Invalid, attempts incrémenté, consumed_at null', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);
    requestAndGetCode($service, $asso, 'marie@example.org');

    $result = $service->verify($asso, 'marie@example.org', '00000000');

    expect($result->status)->toBe(VerifyStatus::Invalid);

    $otp = TiersPortailOtp::withoutGlobalScopes()->first();
    expect($otp->attempts)->toBe(1)
        ->and($otp->consumed_at)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : OTP expiré → Invalid
// ─────────────────────────────────────────────────────────────────────────────
it('OTP expiré → Invalid', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    Carbon::setTestNow(now());
    $service = app(OtpService::class);
    $code = requestAndGetCode($service, $asso, 'marie@example.org');

    // Avance le temps de 11 minutes — OTP expiré (TTL = 10 min)
    Carbon::setTestNow(now()->addMinutes(11));

    $result = $service->verify($asso, 'marie@example.org', $code);

    expect($result->status)->toBe(VerifyStatus::Invalid);

    Carbon::setTestNow(null);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : OTP déjà consommé → Invalid
// ─────────────────────────────────────────────────────────────────────────────
it('OTP déjà consommé → Invalid au second verify', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);
    $code = requestAndGetCode($service, $asso, 'marie@example.org');

    // Premier verify : succès
    $first = $service->verify($asso, 'marie@example.org', $code);
    expect($first->status)->toBe(VerifyStatus::Success);

    // Deuxième verify : doit échouer
    $second = $service->verify($asso, 'marie@example.org', $code);
    expect($second->status)->toBe(VerifyStatus::Invalid);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : 3 tentatives échouées → cooldown actif, 4e tentative bloquée
// ─────────────────────────────────────────────────────────────────────────────
it('3 tentatives échouées → cooldown actif, 4e tentative retourne Cooldown', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    Carbon::setTestNow(now());

    $service = app(OtpService::class);
    $code = requestAndGetCode($service, $asso, 'marie@example.org');

    // Effacer le RateLimiter pour cet email précis
    $key = 'portail-otp:'.$asso->id.':marie@example.org';
    RateLimiter::clear($key);

    // 3 mauvais codes
    $service->verify($asso, 'marie@example.org', '00000001');
    $service->verify($asso, 'marie@example.org', '00000002');
    $service->verify($asso, 'marie@example.org', '00000003');

    // Cooldown doit être actif
    expect($service->cooldownActive($asso, 'marie@example.org'))->toBeTrue();

    // 4e tentative (bon code cette fois) → Cooldown
    $result = $service->verify($asso, 'marie@example.org', $code);
    expect($result->status)->toBe(VerifyStatus::Cooldown);

    // Après 16 minutes, cooldown expiré
    Carbon::setTestNow(now()->addMinutes(16));
    expect($service->cooldownActive($asso, 'marie@example.org'))->toBeFalse();

    Carbon::setTestNow(null);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7 : cooldown bloque aussi une nouvelle demande d'OTP (request())
// ─────────────────────────────────────────────────────────────────────────────
it('cooldown actif bloque request() : retourne Cooldown, aucun mail, aucun record', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);
    $key = 'portail-otp:'.$asso->id.':marie@example.org';
    RateLimiter::clear($key);

    // Demande initiale pour avoir un OTP
    requestAndGetCode($service, $asso, 'marie@example.org');
    $countBefore = TiersPortailOtp::withoutGlobalScopes()->count();

    // 3 mauvais codes pour déclencher le cooldown
    $service->verify($asso, 'marie@example.org', '00000001');
    $service->verify($asso, 'marie@example.org', '00000002');
    $service->verify($asso, 'marie@example.org', '00000003');

    expect($service->cooldownActive($asso, 'marie@example.org'))->toBeTrue();

    // Reset Mail::fake pour compter uniquement les nouveaux envois
    Mail::fake();

    // Tenter une nouvelle demande d'OTP pendant le cooldown
    $result = $service->request($asso, 'marie@example.org');

    expect($result)->toBe(RequestResult::Cooldown);
    Mail::assertNothingSent();

    // Aucun nouveau record créé
    expect(TiersPortailOtp::withoutGlobalScopes()->count())->toBe($countBefore);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 8 : clé cooldown scopée par (asso, email) — pas de cross-contamination
// ─────────────────────────────────────────────────────────────────────────────
it('cooldown scopé (asso, email) — pas de contamination cross-tenant ni cross-email', function () {
    $asso1 = Association::factory()->create();
    $asso2 = Association::factory()->create();

    // Tiers dans asso1
    TenantContext::boot($asso1);
    Tiers::factory()->create([
        'association_id' => $asso1->id,
        'email' => 'marie@example.org',
    ]);

    // Tiers dans asso2 avec même email
    TenantContext::boot($asso2);
    Tiers::factory()->create([
        'association_id' => $asso2->id,
        'email' => 'marie@example.org',
    ]);

    // Tiers dans asso1 avec email différent
    TenantContext::boot($asso1);
    Tiers::factory()->create([
        'association_id' => $asso1->id,
        'email' => 'autre@example.org',
    ]);

    $service = app(OtpService::class);

    // Nettoyer les clés
    RateLimiter::clear('portail-otp:'.$asso1->id.':marie@example.org');
    RateLimiter::clear('portail-otp:'.$asso2->id.':marie@example.org');
    RateLimiter::clear('portail-otp:'.$asso1->id.':autre@example.org');

    // Déclencher le cooldown pour (asso1, marie@example.org)
    TenantContext::boot($asso1);
    $code = requestAndGetCode($service, $asso1, 'marie@example.org');
    $service->verify($asso1, 'marie@example.org', '00000001');
    $service->verify($asso1, 'marie@example.org', '00000002');
    $service->verify($asso1, 'marie@example.org', '00000003');

    expect($service->cooldownActive($asso1, 'marie@example.org'))->toBeTrue();

    // asso2 + même email : PAS bloqué
    expect($service->cooldownActive($asso2, 'marie@example.org'))->toBeFalse();

    // asso1 + autre email : PAS bloqué
    expect($service->cooldownActive($asso1, 'autre@example.org'))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 9 : atomicité — 2 verify successifs sur le même code → 2e retourne Invalid
// ─────────────────────────────────────────────────────────────────────────────
it('race-condition : 2 verify sur le même code → 1 succès, 1 Invalid', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);
    $code = requestAndGetCode($service, $asso, 'marie@example.org');

    // Premier verify : succès attendu
    $first = $service->verify($asso, 'marie@example.org', $code);
    expect($first->status)->toBe(VerifyStatus::Success);

    // Deuxième verify immédiat : consumed_at est déjà posé → Invalid
    $second = $service->verify($asso, 'marie@example.org', $code);
    expect($second->status)->toBe(VerifyStatus::Invalid);
});
