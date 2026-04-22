<?php

declare(strict_types=1);

use App\Livewire\Portail\ChooseTiers;
use App\Models\Association;
use App\Models\Tiers;
use App\Services\Portail\AuthSessionService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : GET /{slug}/portail/choisir sans session pending → redirect login
// ─────────────────────────────────────────────────────────────────────────────
it('GET /{slug}/portail/choisir sans session pending redirige vers portail.login', function () {
    $asso = Association::factory()->create();

    $this->get("/{$asso->slug}/portail/choisir")
        ->assertRedirect("/{$asso->slug}/portail/login");
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Avec 2 Tiers pending en session → page 200 + liste les 2 noms
// ─────────────────────────────────────────────────────────────────────────────
it('page /choisir avec 2 Tiers pending affiche les 2 noms complets', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers1 = Tiers::factory()->create([
        'association_id' => $asso->id,
        'prenom' => 'Marie',
        'nom' => 'DUPONT',
    ]);
    $tiers2 = Tiers::factory()->create([
        'association_id' => $asso->id,
        'prenom' => 'Paul',
        'nom' => 'MARTIN',
    ]);

    $service = new AuthSessionService;
    $service->markPendingTiers([(int) $tiers1->id, (int) $tiers2->id]);

    Livewire::test(ChooseTiers::class, ['association' => $asso])
        ->assertSeeText('Marie')
        ->assertSeeText('DUPONT')
        ->assertSeeText('Paul')
        ->assertSeeText('MARTIN');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Clic sur un Tiers → chooseTiers, login effectif, redirect home
// ─────────────────────────────────────────────────────────────────────────────
it('action choose($id) connecte le Tiers et redirige vers portail.home', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers1 = Tiers::factory()->create(['association_id' => $asso->id]);
    $tiers2 = Tiers::factory()->create(['association_id' => $asso->id]);

    $service = new AuthSessionService;
    $service->markPendingTiers([(int) $tiers1->id, (int) $tiers2->id]);

    Livewire::test(ChooseTiers::class, ['association' => $asso])
        ->call('choose', (int) $tiers1->id)
        ->assertRedirect(route('portail.home', ['association' => $asso->slug]));

    expect(auth('tiers-portail')->check())->toBeTrue();
    expect((int) auth('tiers-portail')->id())->toBe((int) $tiers1->id);

    // Session pending purgée
    expect(session('portail.pending_tiers_ids'))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Tentative de choisir un ID hors liste pending → 403
// ─────────────────────────────────────────────────────────────────────────────
it('action choose($id) avec id hors liste pending retourne 403', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers1 = Tiers::factory()->create(['association_id' => $asso->id]);
    $tiers2 = Tiers::factory()->create(['association_id' => $asso->id]);
    $tiersEtranger = Tiers::factory()->create(['association_id' => $asso->id]);

    $service = new AuthSessionService;
    $service->markPendingTiers([(int) $tiers1->id, (int) $tiers2->id]);

    // L'action doit abort(403) — Livewire la convertit en exception ou réponse 403
    Livewire::test(ChooseTiers::class, ['association' => $asso])
        ->call('choose', (int) $tiersEtranger->id)
        ->assertForbidden();

    // Session inchangée : les pending sont toujours là
    expect(session('portail.pending_tiers_ids'))->toBeArray()->toHaveCount(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Middleware EnsureTiersChosen sur / — avec pending → redirect choisir
// ─────────────────────────────────────────────────────────────────────────────
it('GET /{slug}/portail/ avec pending choice et non-authentifié redirige vers portail.choisir', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers1 = Tiers::factory()->create(['association_id' => $asso->id]);
    $tiers2 = Tiers::factory()->create(['association_id' => $asso->id]);

    $service = new AuthSessionService;
    $service->markPendingTiers([(int) $tiers1->id, (int) $tiers2->id]);

    $this->get("/{$asso->slug}/portail/")
        ->assertRedirect("/{$asso->slug}/portail/choisir");
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5b : Middleware EnsureTiersChosen — sans pending → pass-through
// ─────────────────────────────────────────────────────────────────────────────
it('GET /{slug}/portail/ sans pending ni authentification passe le middleware EnsureTiersChosen', function () {
    $asso = Association::factory()->create();

    // Pas de session pending, pas d'authentification
    // EnsureTiersChosen doit laisser passer (le middleware Authenticate du Step 13 s'en chargera)
    $response = $this->get("/{$asso->slug}/portail/");

    // Le middleware EnsureTiersChosen ne doit pas rediriger vers /choisir
    $this->assertNotEquals(
        "/{$asso->slug}/portail/choisir",
        $response->headers->get('Location')
    );
});
