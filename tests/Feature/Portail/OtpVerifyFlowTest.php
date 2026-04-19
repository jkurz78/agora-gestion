<?php

declare(strict_types=1);

use App\Livewire\Portail\OtpVerify;
use App\Mail\Portail\OtpMail;
use App\Models\Association;
use App\Models\Tiers;
use App\Services\Portail\OtpService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Code valide + 1 Tiers → connexion sur tiers-portail + redirect home
// ─────────────────────────────────────────────────────────────────────────────
it('code valide + 1 Tiers → connexion tiers-portail et redirect portail.home', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);
    $code = captureOtpCode($service, $asso, 'marie@example.org');

    Livewire::test(OtpVerify::class, ['association' => $asso])
        ->set('code', $code)
        ->call('submit')
        ->assertRedirect(route('portail.home', ['association' => $asso->slug]));

    expect(auth('tiers-portail')->check())->toBeTrue();
    expect((int) auth('tiers-portail')->id())->toBe((int) $tiers->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Code valide + 2+ Tiers → stocke pending_tiers_ids + redirect choisir
// ─────────────────────────────────────────────────────────────────────────────
it('code valide + 2 Tiers → stocke pending_tiers_ids en session et redirect portail.choisir sans login', function () {
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
    $code = captureOtpCode($service, $asso, 'famille@example.org');

    Livewire::test(OtpVerify::class, ['association' => $asso])
        ->set('code', $code)
        ->call('submit')
        ->assertRedirect(route('portail.choisir', ['association' => $asso->slug]));

    expect(auth('tiers-portail')->check())->toBeFalse();

    $pendingIds = session('portail.pending_tiers_ids');
    expect($pendingIds)->toBeArray()
        ->and($pendingIds)->toContain((int) $marie->id)
        ->and($pendingIds)->toContain((int) $paul->id)
        ->and($pendingIds)->toHaveCount(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Code invalide → erreur affichée, reste sur la page, pas de login
// ─────────────────────────────────────────────────────────────────────────────
it('code invalide → message erreur affiché, reste sur la page, pas de connexion', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);
    captureOtpCode($service, $asso, 'marie@example.org');

    Livewire::test(OtpVerify::class, ['association' => $asso])
        ->set('code', '00000000')
        ->call('submit')
        ->assertNoRedirect()
        ->assertSet('errorMessage', 'Code invalide ou expiré.');

    expect(Auth::guard('tiers-portail')->check())->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Cooldown actif → message approprié
// ─────────────────────────────────────────────────────────────────────────────
it('cooldown actif → affiche message trop de tentatives avec délai', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);
    $key = 'portail-otp:'.$asso->id.':marie@example.org';
    RateLimiter::clear($key);

    captureOtpCode($service, $asso, 'marie@example.org');

    // Déclencher le cooldown : 3 mauvaises tentatives
    $service->verify($asso, 'marie@example.org', '00000001');
    $service->verify($asso, 'marie@example.org', '00000002');
    $service->verify($asso, 'marie@example.org', '00000003');

    expect($service->cooldownActive($asso, 'marie@example.org'))->toBeTrue();

    Livewire::test(OtpVerify::class, ['association' => $asso])
        ->set('code', '12345678')
        ->call('submit')
        ->assertNoRedirect()
        ->assertSet('errorMessage', 'Trop de tentatives. Réessayez dans 15 minutes.');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5a : Renvoi OK si canResend() → OtpService::request() appelé
// ─────────────────────────────────────────────────────────────────────────────
it('bouton renvoyer code appelle OtpService::request si canResend OK', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);
    // Premier envoi (initialise le pending_email en session)
    captureOtpCode($service, $asso, 'marie@example.org');

    // Avancer le temps au-delà du délai de renvoi (60s)
    Carbon::setTestNow(now()->addSeconds(61));
    Mail::fake();

    Livewire::test(OtpVerify::class, ['association' => $asso])
        ->call('resend')
        ->assertSet('infoMessage', 'Un nouveau code vous a été envoyé.')
        ->assertSet('errorMessage', null);

    Mail::assertSent(OtpMail::class);

    Carbon::setTestNow(null);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5b : Renvoi trop tôt → message d'attente, aucun email
// ─────────────────────────────────────────────────────────────────────────────
it('bouton renvoyer affiche message patienter si renvoi trop tôt', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);
    // Premier envoi (moins de 60s) — positionne aussi pending_email en session
    captureOtpCode($service, $asso, 'marie@example.org');

    Mail::fake();

    $component = Livewire::test(OtpVerify::class, ['association' => $asso])
        ->call('resend')
        ->assertNoRedirect();

    $component->assertSet('errorMessage', fn ($msg) => str_contains((string) $msg, 'patienter'));

    Mail::assertNothingSent();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : Normalisation du code — "1234 5678" et "12345678" acceptés
// ─────────────────────────────────────────────────────────────────────────────
it('champ code accepte format avec espaces et normalise avant vérification', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);
    $code = captureOtpCode($service, $asso, 'marie@example.org');

    // Format avec espace (4+4)
    $codeWithSpace = substr($code, 0, 4).' '.substr($code, 4);

    Livewire::test(OtpVerify::class, ['association' => $asso])
        ->set('code', $codeWithSpace)
        ->call('submit')
        ->assertRedirect(route('portail.home', ['association' => $asso->slug]));
});

it('champ code accepte format sans espaces', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'marie@example.org',
    ]);

    $service = app(OtpService::class);
    $code = captureOtpCode($service, $asso, 'marie@example.org');

    Livewire::test(OtpVerify::class, ['association' => $asso])
        ->set('code', $code)
        ->call('submit')
        ->assertRedirect(route('portail.home', ['association' => $asso->slug]));
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7 : Champ vide → erreur de validation
// ─────────────────────────────────────────────────────────────────────────────
it('champ code vide → erreur validation Veuillez saisir votre code', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    // Poser la session pour que mount() ne redirige pas
    session(['portail.pending_email' => 'marie@example.org']);

    Livewire::test(OtpVerify::class, ['association' => $asso])
        ->set('code', '')
        ->call('submit')
        ->assertHasErrors(['code']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 8 : Email absent de session → redirect portail.login
// ─────────────────────────────────────────────────────────────────────────────
it('accès direct sans portail.pending_email en session → redirect portail.login', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    // Pas de pending_email en session — accès direct à la page OTP
    Livewire::test(OtpVerify::class, ['association' => $asso])
        ->assertRedirect(route('portail.login', ['association' => $asso->slug]));
});

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Demande un OTP et capture le code en clair via Mail::fake.
 * Positionne aussi portail.pending_email en session.
 */
function captureOtpCode(OtpService $service, Association $asso, string $email): string
{
    Mail::fake();
    $service->request($asso, $email);

    $code = null;
    Mail::assertSent(OtpMail::class, function (OtpMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    session(['portail.pending_email' => mb_strtolower($email)]);

    return (string) $code;
}
