<?php

declare(strict_types=1);

use App\Livewire\Portail\ChooseTiers;
use App\Livewire\Portail\OtpVerify;
use App\Mail\Portail\OtpMail;
use App\Models\Association;
use App\Models\Tiers;
use App\Services\Portail\AuthSessionService;
use App\Services\Portail\OtpService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * Régression : sélecteur OTP multi-Tiers
 *
 * Scénario Gherkin : "Multi-Tiers même email — parent voit enfant"
 * Étant donné 2 Tiers partageant le même email dans la même association,
 * Quand l'email est validé par OTP,
 * Alors la page /choisir liste les 2 Tiers,
 * Et après choix, la session est scopée au Tiers choisi.
 */
beforeEach(function () {
    Mail::fake();
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Test 1 : Après OTP valide pour email partagé, /choisir liste les 2 Tiers
// ---------------------------------------------------------------------------
it('multi-tiers : page /choisir affiche les 2 Tiers partageant le même email', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $parent = Tiers::factory()->create([
        'association_id' => $asso->id,
        'prenom' => 'Parent',
        'nom' => 'MARTIN',
        'email' => 'shared@example.com',
    ]);
    $enfant = Tiers::factory()->create([
        'association_id' => $asso->id,
        'prenom' => 'Enfant',
        'nom' => 'MARTIN',
        'email' => 'shared@example.com',
    ]);

    // Simuler l'OTP vérifié → pending_tiers_ids en session
    $service = app(OtpService::class);
    $service->request($asso, 'shared@example.com');

    $code = null;
    Mail::assertSent(OtpMail::class, function (OtpMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });
    session(['portail.pending_email' => 'shared@example.com']);
    Mail::fake(); // reset pour ne pas interférer avec la suite

    Livewire::test(OtpVerify::class, ['association' => $asso])
        ->set('code', (string) $code)
        ->call('submit')
        ->assertRedirect(route('portail.choisir', ['association' => $asso->slug]));

    // Pas encore connecté
    expect(auth('tiers-portail')->check())->toBeFalse();

    // Les 2 Tiers sont dans les pending
    $pendingIds = session('portail.pending_tiers_ids');
    expect($pendingIds)->toBeArray()->toHaveCount(2);
    expect($pendingIds)->toContain((int) $parent->id);
    expect($pendingIds)->toContain((int) $enfant->id);

    // La page /choisir liste les 2 noms
    Livewire::test(ChooseTiers::class, ['association' => $asso])
        ->assertSeeText('Parent')
        ->assertSeeText('Enfant')
        ->assertSeeText('MARTIN');
});

// ---------------------------------------------------------------------------
// Test 2 : Après choix de l'Enfant, la session est scopée sur l'Enfant
// ---------------------------------------------------------------------------
it('multi-tiers : choisir Enfant scopes la session sur le Tiers Enfant', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $parent = Tiers::factory()->create([
        'association_id' => $asso->id,
        'prenom' => 'Parent',
        'nom' => 'MARTIN',
        'email' => 'shared@example.com',
    ]);
    $enfant = Tiers::factory()->create([
        'association_id' => $asso->id,
        'prenom' => 'Enfant',
        'nom' => 'MARTIN',
        'email' => 'shared@example.com',
    ]);

    // Poser le pending directement (sans passer par OTP flow complet)
    $authService = new AuthSessionService;
    $authService->markPendingTiers([(int) $parent->id, (int) $enfant->id]);

    // Choisir l'Enfant
    Livewire::test(ChooseTiers::class, ['association' => $asso])
        ->call('choose', (int) $enfant->id)
        ->assertRedirect(route('portail.home', ['association' => $asso->slug]));

    // Session scopée sur l'Enfant
    expect(auth('tiers-portail')->check())->toBeTrue();
    expect((int) auth('tiers-portail')->id())->toBe((int) $enfant->id);

    // Pending purgé
    expect(session('portail.pending_tiers_ids'))->toBeNull();
});
