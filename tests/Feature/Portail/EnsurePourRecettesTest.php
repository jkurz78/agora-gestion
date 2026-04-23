<?php

declare(strict_types=1);

use App\Http\Middleware\Portail\EnsurePourRecettes;
use App\Models\Association;
use App\Models\Tiers;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// NOTE (slice A → B): EnsurePourRecettes n'est branché sur aucune route
// en slice A. Les tests ci-dessous couvrent la logique isolée du middleware
// via une route synthétique nommée portail.mono.* (pour que PortailRoute::to
// génère la bonne URL de redirect). Quand la slice B ajoutera des routes
// réelles (/portail/cotisations, /portail/dons, etc.), basculer ces tests
// sur les vraies routes pour couvrir l'interaction avec EnforceSessionLifetime
// + Authenticate et ajouter la variante slug-first.

beforeEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
    DB::table('association')->delete();

    Route::get('/test/pour-recettes', fn () => 'OK')
        ->middleware(['web', EnsurePourRecettes::class])
        ->name('portail.mono.test-pour-recettes');
});

it('redirige si pour_recettes=false', function () {
    $asso = Association::factory()->create(['slug' => 'mono']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_depenses' => true,
        'pour_recettes' => false,
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    $this->get('/test/pour-recettes')
        ->assertRedirect()
        ->assertSessionHas('portail.info');
});

it('laisse passer si pour_recettes=true', function () {
    $asso = Association::factory()->create(['slug' => 'mono']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_recettes' => true,
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    $this->get('/test/pour-recettes')->assertOk()->assertSeeText('OK');
});
