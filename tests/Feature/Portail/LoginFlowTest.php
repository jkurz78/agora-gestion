<?php

declare(strict_types=1);

use App\Livewire\Portail\Login;
use App\Mail\Portail\OtpMail;
use App\Models\Association;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
    TenantContext::clear();
});

it('soumission avec email vide déclenche une erreur de validation', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Livewire::test(Login::class, ['association' => $asso])
        ->set('email', '')
        ->call('submit')
        ->assertHasErrors(['email' => 'required']);
});

it('soumission avec email invalide déclenche une erreur de validation', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Livewire::test(Login::class, ['association' => $asso])
        ->set('email', 'pas-un-email')
        ->call('submit')
        ->assertHasErrors(['email']);
});

it('soumission email valide Tiers connu envoie OTP et redirige vers /otp', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    Livewire::test(Login::class, ['association' => $asso])
        ->set('email', 'marie@example.org')
        ->call('submit')
        ->assertRedirect(route('portail.otp', ['association' => $asso->slug]));

    Mail::assertSent(OtpMail::class, fn (OtpMail $m) => $m->hasTo('marie@example.org'));
});

it('soumission email inconnu redirige vers /otp avec le même flash anti-énumération, sans mail', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Livewire::test(Login::class, ['association' => $asso])
        ->set('email', 'inconnu@example.org')
        ->call('submit')
        ->assertRedirect(route('portail.otp', ['association' => $asso->slug]));

    Mail::assertNotSent(OtpMail::class);
});

it('email valide soumis est stocké en session portail.pending_email', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    Livewire::test(Login::class, ['association' => $asso])
        ->set('email', 'marie@example.org')
        ->call('submit');

    expect(session('portail.pending_email'))->toBe('marie@example.org');
});
