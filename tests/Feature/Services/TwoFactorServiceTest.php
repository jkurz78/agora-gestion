<?php

declare(strict_types=1);

use App\Enums\TwoFactorMethod;
use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(TwoFactorService::class);
});

// ── Enable / Disable ──

it('enables email 2FA', function () {
    $user = User::factory()->create();
    $this->service->enableEmail($user);

    expect($user->fresh()->two_factor_method)->toBe(TwoFactorMethod::Email);
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('enables TOTP and returns secret', function () {
    $user = User::factory()->create();
    $secret = $this->service->enableTotp($user);

    expect($secret)->toBeString()->toHaveLength(16);
    expect($user->fresh()->two_factor_method)->toBe(TwoFactorMethod::Totp);
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse(); // not confirmed yet
});

it('confirms TOTP with valid code', function () {
    $user = User::factory()->create();
    $secret = $this->service->enableTotp($user);

    $google2fa = new \PragmaRX\Google2FA\Google2FA();
    $validCode = $google2fa->getCurrentOtp($secret);

    expect($this->service->confirmTotp($user, $validCode))->toBeTrue();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('rejects invalid TOTP confirmation code', function () {
    $user = User::factory()->create();
    $this->service->enableTotp($user);

    expect($this->service->confirmTotp($user, '000000'))->toBeFalse();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('disables 2FA', function () {
    $user = User::factory()->create();
    $this->service->enableEmail($user);
    $this->service->disable($user);

    expect($user->fresh()->two_factor_method)->toBeNull();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

// ── Email OTP ──

it('generates and verifies email code', function () {
    Mail::fake();
    $user = User::factory()->create();
    $this->service->enableEmail($user);

    $this->service->generateEmailCode($user);

    Mail::assertSent(TwoFactorCodeMail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});

it('rejects expired email code', function () {
    $user = User::factory()->create();
    $this->service->enableEmail($user);

    // Insert expired code
    \DB::table('two_factor_codes')->insert([
        'user_id' => $user->id,
        'code' => \Hash::make('123456'),
        'expires_at' => now()->subMinute(),
    ]);

    expect($this->service->verifyEmailCode($user, '123456'))->toBeFalse();
});

// ── Recovery codes ──

it('generates 8 recovery codes', function () {
    $user = User::factory()->create();
    $codes = $this->service->generateRecoveryCodes($user);

    expect($codes)->toHaveCount(8);
    expect($codes[0])->toMatch('/^[a-z0-9]{4}-[a-z0-9]{4}$/');
});

it('verifies and consumes a recovery code', function () {
    $user = User::factory()->create();
    $codes = $this->service->generateRecoveryCodes($user);

    expect($this->service->verifyRecoveryCode($user, $codes[0]))->toBeTrue();
    expect($this->service->remainingRecoveryCodes($user->fresh()))->toBe(7);
});

it('rejects invalid recovery code', function () {
    $user = User::factory()->create();
    $this->service->generateRecoveryCodes($user);

    expect($this->service->verifyRecoveryCode($user, 'xxxx-yyyy'))->toBeFalse();
});

// ── Trusted browser ──

it('revokes all trusted browsers', function () {
    $user = User::factory()->create(['two_factor_trusted_token' => 'old-token']);
    $this->service->revokeTrustedBrowsers($user);

    expect($user->fresh()->two_factor_trusted_token)->toBeNull();
});
