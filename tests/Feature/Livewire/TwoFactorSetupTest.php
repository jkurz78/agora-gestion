<?php

declare(strict_types=1);

use App\Enums\TwoFactorMethod;
use App\Livewire\TwoFactorSetup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders disabled state when 2FA is off', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->assertSee('pas activée')
        ->assertSee('Activer via email')
        ->assertSee('Activer via application');
});

it('can enable email 2FA', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->call('enableEmail')
        ->assertSet('method', 'email')
        ->assertSee('OTP email activé');

    expect($user->fresh()->two_factor_method)->toBe(TwoFactorMethod::Email);
});

it('can start TOTP setup and shows QR code', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->call('startTotp');

    $component->assertSet('method', 'totp')
        ->assertSee('Scannez ce QR code');

    expect($component->get('totpSecret'))->toBeString()->not->toBeEmpty();
    expect($component->get('qrCodeSvg'))->toContain('<svg');
});

it('can confirm TOTP with valid code', function () {
    $user = User::factory()->create();
    $google2fa = new \PragmaRX\Google2FA\Google2FA();

    $component = Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->call('startTotp');

    $secret = $component->get('totpSecret');
    $validCode = $google2fa->getCurrentOtp($secret);

    $component->set('confirmCode', $validCode)
        ->call('confirmTotp')
        ->assertSee('TOTP activé');

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('shows recovery codes after TOTP confirmation', function () {
    $user = User::factory()->create();
    $google2fa = new \PragmaRX\Google2FA\Google2FA();

    $component = Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->call('startTotp');

    $secret = $component->get('totpSecret');
    $validCode = $google2fa->getCurrentOtp($secret);

    $component->set('confirmCode', $validCode)
        ->call('confirmTotp');

    $codes = $component->get('recoveryCodes');
    expect($codes)->toHaveCount(8);
});

it('can disable 2FA', function () {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::Email,
        'two_factor_confirmed_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->call('disable')
        ->assertSet('method', null)
        ->assertSee('pas activée');

    expect($user->fresh()->two_factor_method)->toBeNull();
});

it('can regenerate recovery codes', function () {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::Totp,
        'two_factor_secret' => 'test-secret',
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['old-code-hash'],
    ]);

    $component = Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->call('regenerateRecoveryCodes');

    expect($component->get('recoveryCodes'))->toHaveCount(8);
});

it('can switch from email to TOTP', function () {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::Email,
        'two_factor_confirmed_at' => now(),
    ]);

    $component = Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->call('startTotp');

    expect($component->get('totpSecret'))->not->toBeNull();
    expect($component->get('qrCodeSvg'))->toContain('<svg');
});
