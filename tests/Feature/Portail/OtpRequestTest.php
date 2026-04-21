<?php

declare(strict_types=1);

use App\Mail\Portail\OtpMail;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\TiersPortailOtp;
use App\Services\Portail\OtpService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

it('génère un OTP pour un Tiers connu', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $now = Carbon::now();
    Carbon::setTestNow($now);

    app(OtpService::class)->request($asso, 'marie@example.org');

    expect(TiersPortailOtp::count())->toBe(1);

    $otp = TiersPortailOtp::first();

    expect((int) $otp->association_id)->toBe((int) $asso->id)
        ->and($otp->email)->toBe('marie@example.org')
        ->and($otp->code_hash)->not->toBeEmpty()
        ->and($otp->expires_at->timestamp)->toBe($now->copy()->addMinutes(10)->timestamp)
        ->and($otp->consumed_at)->toBeNull()
        ->and($otp->attempts)->toBe(0)
        ->and($otp->last_sent_at->timestamp)->toBe($now->timestamp);

    Mail::assertSent(OtpMail::class, fn (OtpMail $m) => $m->hasTo('marie@example.org'));

    Carbon::setTestNow(null);
});

it('le code hashé est vérifiable via la propriété publique du Mailable', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    app(OtpService::class)->request($asso, 'marie@example.org');

    $otp = TiersPortailOtp::first();

    Mail::assertSent(OtpMail::class, function (OtpMail $mail) use ($otp) {
        return Hash::check($mail->code, $otp->code_hash);
    });
});

it('le code est un string de 8 chiffres', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    app(OtpService::class)->request($asso, 'marie@example.org');

    Mail::assertSent(OtpMail::class, function (OtpMail $mail) {
        return preg_match('/^\d{8}$/', $mail->code) === 1;
    });
});

it('tiers inconnu — aucun OTP créé, aucun mail envoyé', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    // Intentionally do not create any Tiers with this email
    app(OtpService::class)->request($asso, 'inconnu@example.org');

    expect(TiersPortailOtp::count())->toBe(0);

    Mail::assertNotSent(OtpMail::class);
});
