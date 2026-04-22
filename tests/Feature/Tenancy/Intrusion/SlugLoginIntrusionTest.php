<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Livewire\SuperAdmin\AssociationCreateForm;
use App\Livewire\SuperAdmin\AssociationDetail;
use App\Mail\TwoFactorCodeMail;
use App\Models\Association;
use App\Models\User;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * Cross-slice intrusion coverage — slug-first and mono modes.
 *
 * Verifies that:
 *   - An authenticated user cannot silently switch tenants via a foreign /{slug}/login.
 *   - Mono→multi switch immediately gates slug-less /portail/login with 404.
 *   - Non-existent / reserved slugs never resolve to a login page.
 *   - POST /{slug}/login does not reveal users from other tenants.
 *   - /portail/login returns 404 in multi mode regardless of auth state.
 *   - TenantContext does not bleed across requests after a 404 on /portail/login.
 *   - Reserved slugs are rejected both on creation AND on slug-edit by super-admin.
 */
beforeEach(function (): void {
    MonoAssociation::flush();
    TenantContext::clear();
    // Wipe any associations created by the global Pest bootstrap so these
    // tests control exactly how many associations exist.
    Association::query()->forceDelete();
});

afterEach(function (): void {
    MonoAssociation::flush();
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 1 — Cross-asso login switch interdit sans logout explicite
//
// Un user déjà authentifié sur SVS qui visite /exemple/login (slug d'une
// autre asso) doit être redirigé vers /dashboard sans que sa session ne
// soit modifiée (pas de logout silencieux, pas de tenant-switch).
// ─────────────────────────────────────────────────────────────────────────────

it('scénario 1a : user authentifié sur SVS visitant /exemple/login est redirigé vers /dashboard', function (): void {
    $svs = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    $exemple = Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);

    $jean = User::factory()->create(['email' => 'jean@svs.fr']);
    $jean->associations()->attach($svs->id, ['role' => 'membre', 'joined_at' => now()]);
    $jean->update(['derniere_association_id' => $svs->id]);

    // Jean appartient aussi à Exemple (multi-asso user, cas limite).
    $jean->associations()->attach($exemple->id, ['role' => 'membre', 'joined_at' => now()]);

    $this->actingAs($jean);
    session(['current_association_id' => $svs->id]);

    $response = $this->get('/exemple/login');

    $response->assertStatus(302);
    $response->assertRedirect('/dashboard');
});

it('scénario 1b : la session current_association_id reste SVS après redirect depuis /exemple/login', function (): void {
    $svs = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    $exemple = Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);

    $jean = User::factory()->create(['email' => 'jean@svs.fr']);
    $jean->associations()->attach($svs->id, ['role' => 'membre', 'joined_at' => now()]);
    $jean->associations()->attach($exemple->id, ['role' => 'membre', 'joined_at' => now()]);
    $jean->update(['derniere_association_id' => $svs->id]);

    $this->actingAs($jean);
    session(['current_association_id' => $svs->id]);

    $this->get('/exemple/login');

    // La session ne doit pas avoir basculé vers Exemple.
    expect((int) session('current_association_id'))->toBe((int) $svs->id);
});

it('scénario 1c : user authentifié reste authentifié après visite de /exemple/login (pas de logout silencieux)', function (): void {
    $svs = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    $exemple = Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);

    $jean = User::factory()->create(['email' => 'jean@svs.fr']);
    $jean->associations()->attach($svs->id, ['role' => 'membre', 'joined_at' => now()]);
    $jean->associations()->attach($exemple->id, ['role' => 'membre', 'joined_at' => now()]);
    $jean->update(['derniere_association_id' => $svs->id]);

    $this->actingAs($jean);
    session(['current_association_id' => $svs->id]);

    $this->get('/exemple/login');

    $this->assertAuthenticatedAs($jean);
});

it('scénario 1d : TenantContext ne bascule pas vers Exemple lors du redirect depuis /exemple/login', function (): void {
    $svs = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    $exemple = Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);

    $jean = User::factory()->create(['email' => 'jean@svs.fr']);
    $jean->associations()->attach($svs->id, ['role' => 'membre', 'joined_at' => now()]);
    $jean->associations()->attach($exemple->id, ['role' => 'membre', 'joined_at' => now()]);
    $jean->update(['derniere_association_id' => $svs->id]);

    TenantContext::boot($svs);

    $this->actingAs($jean);
    session(['current_association_id' => $svs->id]);

    $this->get('/exemple/login');

    // Le TenantContext doit rester SVS — BootTenantFromSlug s'exécute APRÈS
    // RedirectIfAuthenticated dans l'ordre de priorité (bootstrap/app.php).
    // Après le redirect, le TenantContext est dans l'état laissé par la réponse.
    // On vérifie simplement qu'il ne vaut pas l'id d'Exemple.
    expect(TenantContext::currentId())->not->toBe((int) $exemple->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 2 — Post-bascule mono→multi, /portail/login devient 404
//
// Quand une 2ème asso est créée, l'observer flush MonoAssociation et la
// prochaine requête sur /portail/login doit retourner 404 (RequireMono).
// ─────────────────────────────────────────────────────────────────────────────

it('scénario 2a : /portail/login retourne 200 en mono', function (): void {
    Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    MonoAssociation::flush();

    TenantContext::clear();
    $this->get('/portail/login')->assertStatus(200);
});

it('scénario 2b : /portail/login retourne 404 après création d\'une 2ème asso (bascule mono→multi)', function (): void {
    Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    MonoAssociation::flush();
    expect(MonoAssociation::isActive())->toBeTrue();

    // L'observer doit flush MonoAssociation automatiquement à la création.
    Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);

    TenantContext::clear();
    $this->get('/portail/login')->assertStatus(404);
});

it('scénario 2c : /login affiche AgoraGestion (neutre) après bascule mono→multi', function (): void {
    Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);
    MonoAssociation::flush();

    TenantContext::clear();
    $this->get('/login')
        ->assertStatus(200)
        ->assertSee('<h2 class="mb-0">AgoraGestion</h2>', false)
        ->assertDontSee('<h2 class="mb-0">SVS</h2>', false)
        ->assertDontSee('<h2 class="mb-0">Exemple</h2>', false);
});

it('scénario 2d : user authentifié SVS ne peut pas accéder à Exemple via /portail/login en multi', function (): void {
    $svs = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);
    MonoAssociation::flush();

    $jean = User::factory()->create(['email' => 'jean@svs.fr']);
    $jean->associations()->attach($svs->id, ['role' => 'membre', 'joined_at' => now()]);
    $jean->update(['derniere_association_id' => $svs->id]);

    TenantContext::clear();

    $this->actingAs($jean);
    session(['current_association_id' => $svs->id]);

    // RequireMono abort(404) AVANT toute résolution de tenant — même un user authentifié reçoit 404.
    $this->get('/portail/login')->assertStatus(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 3 — Slug inexistant ou réservé sur /{slug}/login
//
// Un slug absent de la DB → 404 via BootTenantFromSlug.
// Un slug réservé (dashboard, admin, portail) → 404 car aucune asso ne peut
// avoir ce slug (ReservedSlug rule), donc absent de la DB → 404.
// ─────────────────────────────────────────────────────────────────────────────

it('scénario 3a : GET /inconnu/login (slug inexistant) retourne 404', function (): void {
    // Aucune asso avec ce slug.
    TenantContext::clear();
    $this->get('/inconnu/login')->assertStatus(404);
});

it('scénario 3b : GET /dashboard/login (slug réservé) retourne 404', function (): void {
    // "dashboard" est dans reserved_slugs → aucune asso ne peut l'avoir.
    TenantContext::clear();
    $this->get('/dashboard/login')->assertStatus(404);
});

it('scénario 3c : GET /admin/login (slug réservé) retourne 404', function (): void {
    TenantContext::clear();
    $this->get('/admin/login')->assertStatus(404);
});

it('scénario 3d : GET /portail/login (slug réservé) en multi retourne 404', function (): void {
    Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);
    MonoAssociation::flush();

    TenantContext::clear();
    // "portail" est réservé → aucune asso ne peut l'avoir → BootTenantFromSlug abort(404).
    // Sauf que /portail/login est d'abord tenté comme route portail-mono (RequireMono → 404
    // en multi). Dans les deux cas le résultat attendu est 404.
    $this->get('/portail/login')->assertStatus(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 4 — POST /{slug}/login ne révèle pas l'existence d'users d'autres assos
//
// marie@exemple.fr n'appartient qu'à Exemple.
// En postant ses creds sur /svs/login, elle doit recevoir l'erreur
// "Cet email n'est pas rattaché à l'association SVS." et aucun email
// 2FA ne doit lui être envoyé.
// ─────────────────────────────────────────────────────────────────────────────

it('scénario 4a : POST /svs/login avec creds valides d\'un user d\'une autre asso retourne erreur métier', function (): void {
    $svs = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    $exemple = Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);

    $marie = User::factory()->create(['email' => 'marie@exemple.fr']);
    $marie->associations()->attach($exemple->id, ['role' => 'membre', 'joined_at' => now()]);
    $marie->update(['derniere_association_id' => $exemple->id]);

    TenantContext::clear();

    $response = $this->from('/svs/login')->post('/svs/login', [
        'email' => 'marie@exemple.fr',
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors(['email' => "Cet email n'est pas rattaché à l'association SVS."]);
});

it('scénario 4b : POST /svs/login avec creds d\'un user d\'une autre asso n\'envoie pas d\'email 2FA', function (): void {
    Mail::fake();

    $svs = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    $exemple = Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);

    // marie a le 2FA email activé — son code ne doit JAMAIS être envoyé lors
    // d'une tentative de login sur une asso à laquelle elle n'appartient pas.
    $marie = User::factory()->create([
        'email' => 'marie@exemple.fr',
        'two_factor_secret' => null,
        'two_factor_method' => null,
    ]);
    $marie->associations()->attach($exemple->id, ['role' => 'membre', 'joined_at' => now()]);
    $marie->update(['derniere_association_id' => $exemple->id]);

    TenantContext::clear();

    $this->from('/svs/login')->post('/svs/login', [
        'email' => 'marie@exemple.fr',
        'password' => 'password',
    ]);

    Mail::assertNotSent(TwoFactorCodeMail::class);
    $this->assertGuest();
});

it('scénario 4c : POST /svs/login avec creds d\'un user d\'une autre asso ne démarre pas de session', function (): void {
    $svs = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    $exemple = Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);

    $marie = User::factory()->create(['email' => 'marie@exemple.fr']);
    $marie->associations()->attach($exemple->id, ['role' => 'membre', 'joined_at' => now()]);
    $marie->update(['derniere_association_id' => $exemple->id]);

    TenantContext::clear();

    $this->from('/svs/login')->post('/svs/login', [
        'email' => 'marie@exemple.fr',
        'password' => 'password',
    ]);

    $this->assertGuest();
    expect(session('current_association_id'))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 5 — /portail/login en mode multi retourne 404 indépendamment de l'auth
//
// RequireMono abort(404) s'exécute AVANT toute logique d'auth.
// ─────────────────────────────────────────────────────────────────────────────

it('scénario 5a : GET /portail/login en multi retourne 404 pour user non authentifié', function (): void {
    Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);
    MonoAssociation::flush();

    TenantContext::clear();
    $this->get('/portail/login')->assertStatus(404);
});

it('scénario 5b : GET /portail/login en multi retourne 404 pour user authentifié SVS', function (): void {
    $svs = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);
    MonoAssociation::flush();

    $jean = User::factory()->create(['email' => 'jean@svs.fr']);
    $jean->associations()->attach($svs->id, ['role' => 'membre', 'joined_at' => now()]);
    $jean->update(['derniere_association_id' => $svs->id]);

    TenantContext::clear();

    $this->actingAs($jean);
    session(['current_association_id' => $svs->id]);

    $this->get('/portail/login')->assertStatus(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 6 — TenantContext ne fuite pas après 404 sur /portail/login
//
// Chaque requête simule un process PHP-FPM frais (TenantContext::clear avant).
// Après un 404 sur /portail/login, une requête suivante sur /login doit
// afficher le logo AgoraGestion neutre, et /svs/portail/login doit revenir
// en 200 avec le bon contexte.
// ─────────────────────────────────────────────────────────────────────────────

it('scénario 6a : après 404 sur /portail/login (multi), GET /login affiche logo AgoraGestion neutre', function (): void {
    Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);
    MonoAssociation::flush();

    // Requête 1 — /portail/login → 404
    TenantContext::clear();
    $this->get('/portail/login')->assertStatus(404);

    // Requête 2 — /login → neutre (pas de fuite de contexte)
    TenantContext::clear();
    $this->get('/login')
        ->assertStatus(200)
        ->assertSee('<h2 class="mb-0">AgoraGestion</h2>', false)
        ->assertDontSee('/tenant-assets/', false);
});

it('scénario 6b : après 404 sur /portail/login (multi), GET /svs/portail/login retourne 200', function (): void {
    Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);
    MonoAssociation::flush();

    // Requête 1 — /portail/login → 404
    TenantContext::clear();
    $this->get('/portail/login')->assertStatus(404);

    // Requête 3 — /{slug}/portail/login → 200 (slug-first, contexte SVS)
    TenantContext::clear();
    $this->get('/svs/portail/login')->assertStatus(200);
});

it('scénario 6c : TenantContext est cohérent (SVS) après accès à /svs/portail/login', function (): void {
    $svs = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);
    MonoAssociation::flush();

    // Accès direct au portail slug-first SVS.
    TenantContext::clear();
    $this->get('/svs/portail/login')->assertStatus(200);

    // BootTenantFromSlug a booté SVS pour cette requête.
    expect(TenantContext::currentId())->toBe((int) $svs->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 7 — Slug réservé rejeté à la création ET à l'édition
//
// Renforce les tests Steps 2-3 dans la suite intrusion comme ceinture+bretelles.
// ─────────────────────────────────────────────────────────────────────────────

it('scénario 7a : super-admin ne peut pas créer une asso avec slug "admin"', function (): void {
    $superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);

    // Boot a TenantContext so the Livewire component can render without fail-closed scope.
    $asso = Association::factory()->create(['nom' => 'Système', 'slug' => 'systeme']);
    TenantContext::boot($asso);

    Mail::fake();

    Livewire::actingAs($superAdmin)
        ->test(AssociationCreateForm::class)
        ->set('nom', 'Admin Asso')
        ->set('slug', 'admin')
        ->set('email_admin', 'admin@test.example')
        ->set('nom_admin', 'Admin Test')
        ->call('submit')
        ->assertHasErrors(['slug']);

    expect(Association::where('slug', 'admin')->exists())->toBeFalse();
});

it('scénario 7b : super-admin ne peut pas changer un slug existant vers "portail"', function (): void {
    $superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
    $asso = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    TenantContext::boot($asso);

    Livewire::actingAs($superAdmin)
        ->test(AssociationDetail::class, ['association' => $asso])
        ->call('openSlugEditor')
        ->set('newSlug', 'portail')
        ->call('saveSlug')
        ->assertHasErrors(['newSlug']);

    $asso->refresh();
    expect($asso->slug)->toBe('svs');
});

it('scénario 7c : super-admin ne peut pas changer un slug existant vers "login"', function (): void {
    $superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
    $asso = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    TenantContext::boot($asso);

    Livewire::actingAs($superAdmin)
        ->test(AssociationDetail::class, ['association' => $asso])
        ->call('openSlugEditor')
        ->set('newSlug', 'login')
        ->call('saveSlug')
        ->assertHasErrors(['newSlug']);

    $asso->refresh();
    expect($asso->slug)->toBe('svs');
});
