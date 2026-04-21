<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Models\TiersPortailOtp;
use App\Services\Portail\OtpService;
use App\Services\Portail\RequestResult;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

it('premier appel retourne Sent et crée 1 OTP', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $result = app(OtpService::class)->request($asso, 'marie@example.org');

    expect($result)->toBe(RequestResult::Sent);
    expect(TiersPortailOtp::count())->toBe(1);
    Mail::assertSentCount(1);
});

it('deuxième appel ≤ 60 s retourne TooSoon, aucun nouveau record, aucun nouveau mail', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    Carbon::setTestNow(now());

    app(OtpService::class)->request($asso, 'marie@example.org');

    Carbon::setTestNow(now()->addSeconds(30));

    $result = app(OtpService::class)->request($asso, 'marie@example.org');

    expect($result)->toBe(RequestResult::TooSoon);
    expect(TiersPortailOtp::count())->toBe(1);
    Mail::assertSentCount(1);

    expect(app(OtpService::class)->canResend($asso, 'marie@example.org'))->toBeFalse();

    Carbon::setTestNow(null);
});

it('canResend reflète le délai à 30s, 60s et 61s', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    Carbon::setTestNow(now());

    app(OtpService::class)->request($asso, 'marie@example.org');

    // À 30s : pas encore
    Carbon::setTestNow(now()->addSeconds(30));
    expect(app(OtpService::class)->canResend($asso, 'marie@example.org'))->toBeFalse();

    // À 60s pile : autorisé (inclusif)
    Carbon::setTestNow(now()->addSeconds(30)); // 30 + 30 = 60
    expect(app(OtpService::class)->canResend($asso, 'marie@example.org'))->toBeTrue();

    // À 61s : autorisé
    Carbon::setTestNow(now()->addSeconds(1)); // 60 + 1 = 61
    expect(app(OtpService::class)->canResend($asso, 'marie@example.org'))->toBeTrue();

    Carbon::setTestNow(null);
});

it("deuxième appel > 60 s retourne Sent, invalide l'ancien OTP et crée un nouveau", function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    Carbon::setTestNow(now());

    app(OtpService::class)->request($asso, 'marie@example.org');

    $premierOtp = TiersPortailOtp::first();
    $premierHash = $premierOtp->code_hash;

    Carbon::setTestNow(now()->addSeconds(61));

    $result = app(OtpService::class)->request($asso, 'marie@example.org');

    expect($result)->toBe(RequestResult::Sent);
    expect(TiersPortailOtp::withoutGlobalScopes()->count())->toBe(2);

    // Ancien OTP : consommé
    expect($premierOtp->fresh()->consumed_at)->not->toBeNull();

    // Nouveau OTP : attempts=0, consumed_at=null, code différent
    $nouvelOtp = TiersPortailOtp::orderByDesc('id')->first();
    expect($nouvelOtp->attempts)->toBe(0);
    expect($nouvelOtp->consumed_at)->toBeNull();
    expect($nouvelOtp->code_hash)->not->toBe($premierHash);

    Mail::assertSentCount(2);

    Carbon::setTestNow(null);
});

it('anti-énumération préservé — email inconnu retourne Silent, rien en base, pas de mail', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    // Aucun Tiers avec cet email
    $result = app(OtpService::class)->request($asso, 'inconnu@example.org');

    expect($result)->toBe(RequestResult::Silent);
    expect(TiersPortailOtp::count())->toBe(0);
    Mail::assertNothingSent();
});
