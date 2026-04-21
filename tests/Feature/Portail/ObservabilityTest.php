<?php

declare(strict_types=1);

use App\Mail\Portail\OtpMail;
use App\Models\Association;
use App\Models\Tiers;
use App\Services\Portail\AuthSessionService;
use App\Services\Portail\OtpService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Helper : demande un OTP et récupère le code via le Mailable.
 */
function observabilityRequestAndGetCode(OtpService $service, Association $asso, string $email): string
{
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
    RateLimiter::clear('portail-otp:*');
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. OtpService::request() réussi → portail.otp.requested
// ─────────────────────────────────────────────────────────────────────────────
it('request réussi émet portail.otp.requested avec email', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $spy = Log::spy();

    app(OtpService::class)->request($asso, 'marie@example.org');

    $spy->shouldHaveReceived('info')
        ->with('portail.otp.requested', Mockery::on(fn ($ctx) => ($ctx['email'] ?? null) === 'marie@example.org'))
        ->once();
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. OtpService::verify() valide → portail.otp.verified
// ─────────────────────────────────────────────────────────────────────────────
it('verify valide émet portail.otp.verified avec email et tiers_count', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);
    $code = observabilityRequestAndGetCode($service, $asso, 'marie@example.org');

    $spy = Log::spy();

    $service->verify($asso, 'marie@example.org', $code);

    $spy->shouldHaveReceived('info')
        ->with('portail.otp.verified', Mockery::on(fn ($ctx) => ($ctx['email'] ?? null) === 'marie@example.org'
            && ($ctx['tiers_count'] ?? null) === 1))
        ->once();
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. OtpService::verify() incorrect → portail.otp.failed avec attempts
// ─────────────────────────────────────────────────────────────────────────────
it('verify incorrect émet portail.otp.failed avec attempts incrémenté', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $key = 'portail-otp:'.$asso->id.':marie@example.org';
    RateLimiter::clear($key);

    $service = app(OtpService::class);
    observabilityRequestAndGetCode($service, $asso, 'marie@example.org');

    $spy = Log::spy();

    $service->verify($asso, 'marie@example.org', '00000000');

    $spy->shouldHaveReceived('info')
        ->with('portail.otp.failed', Mockery::on(fn ($ctx) => ($ctx['email'] ?? null) === 'marie@example.org'
            && array_key_exists('attempts', $ctx)))
        ->once();
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. Après 3 verify faux → portail.cooldown.triggered au 3ème
// ─────────────────────────────────────────────────────────────────────────────
it('3ème verify incorrect déclenche portail.cooldown.triggered', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $key = 'portail-otp:'.$asso->id.':marie@example.org';
    RateLimiter::clear($key);

    $service = app(OtpService::class);
    observabilityRequestAndGetCode($service, $asso, 'marie@example.org');

    // 2 premières tentatives — cooldown pas encore actif
    $service->verify($asso, 'marie@example.org', '00000001');
    $service->verify($asso, 'marie@example.org', '00000002');

    $spy = Log::spy();

    // 3ème tentative → cooldown déclenché
    $service->verify($asso, 'marie@example.org', '00000003');

    $spy->shouldHaveReceived('info')
        ->with('portail.cooldown.triggered', Mockery::on(fn ($ctx) => ($ctx['email'] ?? null) === 'marie@example.org'))
        ->once();
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. loginSingleTiers → portail.login.success
// ─────────────────────────────────────────────────────────────────────────────
it('loginSingleTiers émet portail.login.success avec tiers_id et email', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $spy = Log::spy();

    app(AuthSessionService::class)->loginSingleTiers($tiers);

    $spy->shouldHaveReceived('info')
        ->with('portail.login.success', Mockery::on(fn ($ctx) => ((int) ($ctx['tiers_id'] ?? 0)) === (int) $tiers->id
            && ($ctx['email'] ?? null) === 'marie@example.org'))
        ->once();
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. chooseTiers → portail.tiers.chosen
// ─────────────────────────────────────────────────────────────────────────────
it('chooseTiers émet portail.tiers.chosen avec tiers_id', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers1 = Tiers::factory()->create(['association_id' => $asso->id]);
    $tiers2 = Tiers::factory()->create(['association_id' => $asso->id]);

    $service = app(AuthSessionService::class);
    $service->markPendingTiers([(int) $tiers1->id, (int) $tiers2->id]);

    $spy = Log::spy();

    $service->chooseTiers((int) $tiers1->id);

    $spy->shouldHaveReceived('info')
        ->with('portail.tiers.chosen', Mockery::on(fn ($ctx) => ((int) ($ctx['tiers_id'] ?? 0)) === (int) $tiers1->id))
        ->once();
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. Sécurité : aucun log ne contient le code OTP (8 chiffres) ni "code_hash"
// ─────────────────────────────────────────────────────────────────────────────
it('aucun log ne contient le code OTP (8 chiffres) ni code_hash', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $key = 'portail-otp:'.$asso->id.':marie@example.org';
    RateLimiter::clear($key);

    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> $loggedEntries */
    $loggedEntries = [];

    $spy = Log::spy();
    $spy->shouldReceive('info')
        ->andReturnUsing(function (string $message, array $context = []) use (&$loggedEntries): void {
            $loggedEntries[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        });

    $service = app(OtpService::class);

    // request + mauvais code + bon code
    $code = observabilityRequestAndGetCode($service, $asso, 'marie@example.org');
    $service->verify($asso, 'marie@example.org', '00000000');
    $service->verify($asso, 'marie@example.org', $code);

    // Vérifier chaque entrée loggée
    foreach ($loggedEntries as $entry) {
        $serialized = json_encode($entry, JSON_THROW_ON_ERROR);

        expect($serialized)->not->toMatch('/\b\d{8}\b/')
            ->and($serialized)->not->toContain('code_hash');
    }

    // Vérification supplémentaire : le code OTP réel n'apparaît pas dans les logs
    foreach ($loggedEntries as $entry) {
        $serialized = json_encode($entry, JSON_THROW_ON_ERROR);
        expect($serialized)->not->toContain($code);
    }
});
