<?php

declare(strict_types=1);

use App\Http\Middleware\Portail\EnsurePeutVoirNotesDeFrais;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// NOTE : EnsurePeutVoirNotesDeFrais n'est pas encore branché sur une vraie route
// (ce sera fait en Step 6). Les tests couvrent la logique du middleware via une
// route synthétique. La règle : accès autorisé si pour_depenses=true OU si le Tiers
// a déjà au moins une NoteDeFrais déposée dans le tenant courant.

beforeEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
    DB::table('association')->delete();

    Route::get('/test/peut-voir-ndf', fn () => 'OK')
        ->middleware(['web', EnsurePeutVoirNotesDeFrais::class])
        ->name('portail.mono.test-peut-voir-ndf');
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 1 : pour_depenses=true, 0 NDF → 200 OK
// ─────────────────────────────────────────────────────────────────────────────
it('laisse passer si pour_depenses=true même sans NDF', function () {
    $asso = Association::factory()->create(['slug' => 'mono']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_depenses' => true,
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    $this->get('/test/peut-voir-ndf')
        ->assertOk()
        ->assertSeeText('OK');
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 2 : pour_depenses=false, ≥1 NDF → 200 OK
// ─────────────────────────────────────────────────────────────────────────────
it('laisse passer si pour_depenses=false mais le Tiers a au moins une NDF', function () {
    $asso = Association::factory()->create(['slug' => 'mono']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_depenses' => false,
    ]);

    NoteDeFrais::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    $this->get('/test/peut-voir-ndf')
        ->assertOk()
        ->assertSeeText('OK');
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 3 : pour_depenses=false, 0 NDF → 302 avec flash
// ─────────────────────────────────────────────────────────────────────────────
it('redirige avec flash si pour_depenses=false et aucune NDF', function () {
    $asso = Association::factory()->create(['slug' => 'mono']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_depenses' => false,
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    $this->get('/test/peut-voir-ndf')
        ->assertRedirect()
        ->assertSessionHas('portail.info');
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 4 : non authentifié → 302
// ─────────────────────────────────────────────────────────────────────────────
it('redirige si aucun Tiers authentifié', function () {
    $asso = Association::factory()->create(['slug' => 'mono']);
    TenantContext::boot($asso);

    // Pas de login
    $this->get('/test/peut-voir-ndf')
        ->assertRedirect();
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 5 : isolation multi-tenant — NDF de asso B ne comptent pas pour asso A
// ─────────────────────────────────────────────────────────────────────────────
it('[isolation] NDF asso B invisibles pour Tiers asso A (pour_depenses=false) → redirige', function () {
    $assoA = Association::factory()->create(['slug' => 'asso-a']);
    $assoB = Association::factory()->create(['slug' => 'asso-b']);

    // Alice existe dans asso A uniquement, pour_depenses=false
    TenantContext::boot($assoA);
    $alice = Tiers::factory()->create([
        'association_id' => $assoA->id,
        'pour_depenses' => false,
    ]);

    // Créer un Tiers dans asso B et lui attacher une NDF
    TenantContext::boot($assoB);
    $tiersB = Tiers::factory()->create([
        'association_id' => $assoB->id,
    ]);
    NoteDeFrais::factory()->create([
        'association_id' => $assoB->id,
        'tiers_id' => $tiersB->id,
    ]);

    // Revenir sur asso A et authentifier Alice
    TenantContext::boot($assoA);
    Auth::guard('tiers-portail')->login($alice);

    // Le TenantScope est actif sur asso A → NoteDeFrais::where('tiers_id', alice->id)
    // ne voit que les NDF de asso A. Alice en a 0 dans asso A et pour_depenses=false
    // → le middleware doit rediriger.
    $this->get('/test/peut-voir-ndf')
        ->assertRedirect()
        ->assertSessionHas('portail.info');
});
