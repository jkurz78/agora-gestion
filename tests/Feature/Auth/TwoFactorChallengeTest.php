<?php

declare(strict_types=1);

use App\Enums\TwoFactorMethod;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

it('redirects to challenge when 2FA email is active', function () {
    Mail::fake();
    $user = User::factory()->create(['two_factor_method' => TwoFactorMethod::Email, 'two_factor_confirmed_at' => now()]);

    $this->actingAs($user)
        ->get(route('compta.dashboard'))
        ->assertRedirect(route('two-factor.challenge'));
});

it('allows access when 2FA is not active', function () {
    $user = User::factory()->create(['two_factor_method' => null]);

    $this->actingAs($user)
        ->get(route('compta.dashboard'))
        ->assertOk();
});

it('allows access after successful 2FA verification', function () {
    Mail::fake();
    $user = User::factory()->create(['two_factor_method' => TwoFactorMethod::Email, 'two_factor_confirmed_at' => now()]);

    $this->actingAs($user)
        ->withSession(['two_factor_verified' => true])
        ->get(route('compta.dashboard'))
        ->assertOk();
});

it('shows challenge page for email method', function () {
    Mail::fake();
    $user = User::factory()->create(['two_factor_method' => TwoFactorMethod::Email, 'two_factor_confirmed_at' => now()]);

    $this->actingAs($user)
        ->get(route('two-factor.challenge'))
        ->assertOk()
        ->assertSee('Un code a été envoyé');
});

it('shows challenge page for TOTP method', function () {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::Totp,
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('two-factor.challenge'))
        ->assertOk()
        ->assertSee('application d\'authentification', false);
});

it('verifies valid TOTP code', function () {
    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();

    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::Totp,
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ]);

    $validCode = $google2fa->getCurrentOtp($secret);

    $this->actingAs($user)
        ->post(route('two-factor.challenge.verify'), ['code' => $validCode])
        ->assertRedirect(route('home'));
});

it('rejects invalid code', function () {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::Totp,
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('two-factor.challenge.verify'), ['code' => '000000'])
        ->assertSessionHasErrors('code');
});

it('verifies recovery code for TOTP', function () {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::Totp,
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
    ]);

    $service = app(TwoFactorService::class);
    $codes = $service->generateRecoveryCodes($user);

    $this->actingAs($user)
        ->post(route('two-factor.challenge.verify'), [
            'code' => $codes[0],
            'use_recovery' => '1',
        ])
        ->assertRedirect(route('home'));
});
