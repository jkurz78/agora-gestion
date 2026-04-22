<?php

declare(strict_types=1);

use App\Enums\TwoFactorMethod;
use App\Mail\TwoFactorCodeMail;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Mail;

/**
 * Tests for the 2FA interaction on POST /{slug}/login.
 *
 * Scenario: rejected branded login must NOT trigger a 2FA email.
 *   - marie has 2FA enabled (email method)
 *   - marie does NOT belong to SVS
 *   - Attacker POSTs to /svs/login with marie's valid credentials
 *   - Expected: no TwoFactorCodeMail sent, marie is not authenticated, error shown
 */
beforeEach(function (): void {
    Association::query()->forceDelete();
    TenantContext::clear();

    $this->svs = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    $this->exemple = Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);

    // marie has 2FA enabled and belongs ONLY to Exemple, not SVS
    $this->marie = User::factory()->create([
        'email' => 'marie@exemple.fr',
        'two_factor_method' => TwoFactorMethod::Email,
        'two_factor_confirmed_at' => now(),
    ]);
    $this->marie->associations()->attach($this->exemple->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->marie->update(['derniere_association_id' => $this->exemple->id]);
});

afterEach(function (): void {
    TenantContext::clear();
});

test('rejected branded login does NOT trigger 2FA email', function () {
    Mail::fake();

    $response = $this->from('/svs/login')->post('/svs/login', [
        'email' => 'marie@exemple.fr',
        'password' => 'password',
    ]);

    // No TwoFactorCodeMail must have been sent
    Mail::assertNotSent(TwoFactorCodeMail::class);

    // Session must not be authenticated
    $this->assertGuest();

    // Must redirect back with the association-specific error
    $response->assertRedirect('/svs/login');
    $response->assertSessionHasErrors(['email' => "Cet email n'est pas rattaché à l'association SVS."]);
});

test('accepted branded login with 2FA triggers TwoFactorCodeMail for the legitimate user', function () {
    Mail::fake();

    // jean has 2FA and belongs to SVS
    $jean = User::factory()->create([
        'email' => 'jean@svs.fr',
        'two_factor_method' => TwoFactorMethod::Email,
        'two_factor_confirmed_at' => now(),
    ]);
    $jean->associations()->attach($this->svs->id, ['role' => 'admin', 'joined_at' => now()]);
    $jean->update(['derniere_association_id' => $this->svs->id]);

    $this->from('/svs/login')->post('/svs/login', [
        'email' => 'jean@svs.fr',
        'password' => 'password',
    ]);

    // 2FA email must be sent after membership is confirmed
    Mail::assertSent(TwoFactorCodeMail::class);
});
