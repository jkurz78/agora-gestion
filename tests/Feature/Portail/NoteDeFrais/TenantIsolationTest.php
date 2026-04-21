<?php

declare(strict_types=1);

use App\Livewire\Portail\NoteDeFrais\Show;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Services\Portail\NoteDeFrais\NoteDeFraisService;
use App\Tenant\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Dossier d'intrusion multi-tenant — Portail Tiers Slice 2 (NDF).
 *
 * Six scénarios vérifient que l'isolation tenant des Notes de frais est étanche :
 *   1. NDF asso A invisible depuis portail asso B (liste).
 *   2. Accès direct NDF asso A depuis portail asso B → 404.
 *   3. Accès NDF d'un autre Tiers même asso → 403 (policy view + update).
 *   4. TenantScope fail-closed sur NoteDeFrais sans TenantContext → 0 lignes.
 *   5. saveDraft avec Tiers d'une autre asso → association_id = TenantContext courant.
 *   6. Suppression NDF d'un autre Tiers → AuthorizationException (policy delete).
 *
 * Si l'un de ces tests échoue c'est une faille critique à corriger avant tout merge.
 */
beforeEach(function () {
    TenantContext::clear();
    Storage::fake('local');
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 1 : NDF asso A invisible depuis portail asso B (liste)
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion-ndf] NDF de asso A invisible depuis la liste portail de asso B', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    // Marie existe dans les deux associations
    TenantContext::boot($assoA);
    $marieA = Tiers::factory()->create([
        'association_id' => $assoA->id,
        'email' => 'marie@example.org',
    ]);

    TenantContext::boot($assoB);
    $marieB = Tiers::factory()->create([
        'association_id' => $assoB->id,
        'email' => 'marie@example.org',
    ]);

    // Marie crée une NDF dans asso A
    TenantContext::boot($assoA);
    NoteDeFrais::factory()->create([
        'association_id' => $assoA->id,
        'tiers_id' => $marieA->id,
        'libelle' => 'NDF confidentielle asso A',
    ]);

    // Marie se connecte sur portail asso B
    $sessionKey = 'login_tiers-portail_'.sha1(SessionGuard::class);

    TenantContext::boot($assoB);
    Auth::guard('tiers-portail')->login($marieB);

    $response = $this->withSession([
        $sessionKey => $marieB->id,
        'portail.last_activity_at' => now()->timestamp,
    ])->get("/portail/{$assoB->slug}/notes-de-frais");

    $response->assertStatus(200)
        ->assertDontSeeText('NDF confidentielle asso A');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 2 : Accès direct à une NDF d'une autre asso → 404
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion-ndf] accès direct NDF asso A depuis portail asso B → 404', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    TenantContext::boot($assoA);
    $marieA = Tiers::factory()->create(['association_id' => $assoA->id]);
    $ndfA = NoteDeFrais::factory()->create([
        'association_id' => $assoA->id,
        'tiers_id' => $marieA->id,
    ]);

    TenantContext::boot($assoB);
    $marieB = Tiers::factory()->create(['association_id' => $assoB->id]);

    $sessionKey = 'login_tiers-portail_'.sha1(SessionGuard::class);

    Auth::guard('tiers-portail')->login($marieB);

    // Marie B tente d'accéder à la NDF de asso A via le portail de asso B
    $response = $this->withSession([
        $sessionKey => $marieB->id,
        'portail.last_activity_at' => now()->timestamp,
    ])->get("/portail/{$assoB->slug}/notes-de-frais/{$ndfA->id}");

    // TenantScope fail-closed : la NDF n'est pas visible dans le contexte assoB
    // → model binding échoue → 404
    $response->assertStatus(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 3 : Accès NDF d'un autre Tiers dans la même asso → 403
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion-ndf] NDF d\'un autre Tiers même asso → 403 sur show et edit', function () {
    $asso = Association::factory()->create();

    TenantContext::boot($asso);
    $marie = Tiers::factory()->create(['association_id' => $asso->id]);
    $paul = Tiers::factory()->create(['association_id' => $asso->id]);

    // Paul crée une NDF
    $ndfPaul = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $asso->id,
        'tiers_id' => $paul->id,
    ]);

    // Marie se connecte
    Auth::guard('tiers-portail')->login($marie);
    $sessionKey = 'login_tiers-portail_'.sha1(SessionGuard::class);

    $session = [
        $sessionKey => $marie->id,
        'portail.last_activity_at' => now()->timestamp,
    ];

    // Marie tente d'afficher la NDF de Paul → 403
    $this->withSession($session)
        ->get("/portail/{$asso->slug}/notes-de-frais/{$ndfPaul->id}")
        ->assertStatus(403);

    // Marie tente d'éditer la NDF de Paul → 403
    $this->withSession($session)
        ->get("/portail/{$asso->slug}/notes-de-frais/{$ndfPaul->id}/edit")
        ->assertStatus(403);
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 4 : TenantScope fail-closed sur NoteDeFrais sans TenantContext
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion-ndf] TenantScope fail-closed — NoteDeFrais::count() === 0 sans contexte', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    NoteDeFrais::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
    ]);

    // Contexte présent → la NDF est visible
    expect(NoteDeFrais::count())->toBe(1);

    // Sans contexte : fail-closed → 0 lignes
    TenantContext::clear();
    expect(NoteDeFrais::count())->toBe(0);

    // Autre asso → toujours 0 (la NDF appartient à assoA)
    $assoB = Association::factory()->create();
    TenantContext::boot($assoB);
    expect(NoteDeFrais::count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 5 : saveDraft via service avec Tiers d'une autre asso
// Le service utilise TenantContext::currentId() pour association_id,
// pas le Tiers passé en argument. La NDF créée appartient bien au tenant courant.
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion-ndf] saveDraft stocke association_id depuis TenantContext, pas depuis le Tiers', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    TenantContext::boot($assoA);
    $marieA = Tiers::factory()->create(['association_id' => $assoA->id]);

    TenantContext::boot($assoB);
    $paulB = Tiers::factory()->create(['association_id' => $assoB->id]);

    // TenantContext courant = assoB
    // On appelle saveDraft en passant le Tiers de assoA (marieA)
    // → association_id sur la NDF créée doit être assoB (le tenant courant)
    $service = app(NoteDeFraisService::class);

    $ndf = $service->saveDraft($marieA, [
        'date' => now()->format('Y-m-d'),
        'libelle' => 'Test cross-tenant save',
        'lignes' => [],
    ]);

    // L'association_id est imposée par TenantContext, pas par le Tiers
    expect((int) $ndf->association_id)->toBe((int) $assoB->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 6 : Suppression NDF d'un autre Tiers → AuthorizationException
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion-ndf] suppression NDF d\'un autre Tiers via Show::delete() → AuthorizationException', function () {
    $asso = Association::factory()->create();

    TenantContext::boot($asso);
    $marie = Tiers::factory()->create(['association_id' => $asso->id]);
    $paul = Tiers::factory()->create(['association_id' => $asso->id]);

    // Paul crée une NDF brouillon
    $ndfPaul = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $asso->id,
        'tiers_id' => $paul->id,
    ]);

    // Marie s'authentifie
    Auth::guard('tiers-portail')->login($marie);

    // Marie appelle Show::delete() sur la NDF de Paul → policy refuse
    $component = new Show;
    $component->association = $asso;
    $component->noteDeFrais = $ndfPaul;

    expect(fn () => $component->delete())
        ->toThrow(AuthorizationException::class);

    // La NDF de Paul n'est pas supprimée
    expect($ndfPaul->fresh()->trashed())->toBeFalse();
});
