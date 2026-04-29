<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Portail OTP bypass en mode démo.
 *
 * Le bypass est activé UNIQUEMENT si APP_ENV=demo (Demo::isActive()).
 * Deux personas sont exposés sur la page de login portail :
 *   - "Marie GAUTHIER" (particulier, pour_depenses)
 *   - "Salle des Brotteaux" (entreprise, pour_depenses)
 *
 * Pattern calqué sur PortailMonoTest : purge des assos + création explicite
 * d'une seule asso pour que MonoAssociation::isActive() retourne true.
 */
beforeEach(function (): void {
    MonoAssociation::flush();
    TenantContext::clear();

    // Purge : garantit exactement 1 asso → MonoAssociation::isActive() === true
    DB::table('association')->delete();

    $asso = Association::factory()->create(['slug' => 'demo-test']);
    TenantContext::boot($asso);

    // Créer les 2 tiers de démo via la factory (IDs auto-assignés)
    $this->tiersMembre = Tiers::factory()->pourDepenses()->create([
        'type' => 'particulier',
        'nom' => 'GAUTHIER',
        'prenom' => 'Marie',
        'email' => 'marie.gauthier@gmail.com',
    ]);

    $this->tiersFournisseur = Tiers::factory()->pourDepenses()->create([
        'type' => 'entreprise',
        'nom' => 'Salle des Brotteaux',
        'prenom' => null,
        'email' => 'reservation@salle-brotteaux.fr',
    ]);
});

afterEach(function (): void {
    app()->detectEnvironment(fn (): string => 'testing');
    MonoAssociation::flush();
    TenantContext::clear();
});

// ─── T1 : env=demo → bandeau visible sur /portail/login ──────────────────────

it('T1: env=demo — bandeau démo visible avec les 2 personas sur /portail/login', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    $response = $this->get('/portail/login');

    $response->assertStatus(200);
    $response->assertSee('alert-info', false);
    $response->assertSeeText('Marie GAUTHIER');
    $response->assertSeeText('Salle des Brotteaux');
});

// ─── T2 : env=local → bandeau absent ─────────────────────────────────────────

it('T2: env=local — bandeau démo absent sur /portail/login', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $response = $this->get('/portail/login');

    $response->assertStatus(200);
    $response->assertDontSeeText('Marie GAUTHIER');
    $response->assertDontSeeText('Salle des Brotteaux');
});

// ─── T3 : env=demo + loginAsTier($id) → session ouverte, redirect home ───────

it('T3: env=demo — loginAsTier(id) ouvre la session portail et redirige vers home', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');
    Log::spy();

    $tierId = (int) $this->tiersMembre->id;
    $response = $this->get("/portail/demo/login-as/{$tierId}");

    // Doit rediriger vers la home portail
    $response->assertRedirect();

    // Guard tiers-portail doit être connecté avec le bon tiers
    expect(Auth::guard('tiers-portail')->check())->toBeTrue();
    expect((int) Auth::guard('tiers-portail')->id())->toBe($tierId);

    // Log audit émis
    Log::shouldHaveReceived('info')->once()->withArgs(function (string $message): bool {
        return $message === 'demo.portail.login_as_tier';
    });
});

// ─── T4 : env=local → bypass refusé (403) ────────────────────────────────────

it('T4: env=local — loginAsTier refusé hors démo (403)', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $tierId = (int) $this->tiersMembre->id;
    $response = $this->get("/portail/demo/login-as/{$tierId}");

    $response->assertStatus(403);
    expect(Auth::guard('tiers-portail')->check())->toBeFalse();
});

// ─── T5 : env=demo + ID inconnu → 404, pas de session ───────────────────────

it('T5: env=demo — loginAsTier(id inexistant) retourne 404 sans ouvrir de session', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    $response = $this->get('/portail/demo/login-as/999999');

    $response->assertStatus(404);
    expect(Auth::guard('tiers-portail')->check())->toBeFalse();
});
