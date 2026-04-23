<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

// Ensure a clean slate before each test so mode detection is deterministic.
beforeEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
    DB::table('association')->delete();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Mode mono — $portailAssociation est résolu via View Composer
// Vérifie que le nom de l'asso est présent dans le HTML de /portail/login
// sans déclencher "Undefined variable $portailAssociation".
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: portail/login affiche le nom de l\'asso via View Composer', function () {
    Association::factory()->create(['nom' => 'Association Test Mono', 'slug' => 'test-mono']);

    $this->get('/portail/login')
        ->assertStatus(200)
        ->assertSeeText('Association Test Mono');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Mode slug-first — $portailAssociation est résolu via View Composer
// Vérifie que le nom de l'asso est présent dans le HTML de /{slug}/portail/login.
// ─────────────────────────────────────────────────────────────────────────────
it('mode slug-first: /{slug}/portail/login affiche le nom de l\'asso via View Composer', function () {
    Association::factory()->create(['nom' => 'Association Test Slug', 'slug' => 'test-slug']);

    $this->get('/test-slug/portail/login')
        ->assertStatus(200)
        ->assertSeeText('Association Test Slug');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Mode slug-first multi-tenant — l'asso affichée est la bonne (isolation)
// ─────────────────────────────────────────────────────────────────────────────
it('mode slug-first: affiche l\'asso correspondant au slug, pas une autre', function () {
    Association::factory()->create(['nom' => 'Asso Alpha', 'slug' => 'asso-alpha']);
    Association::factory()->create(['nom' => 'Asso Beta', 'slug' => 'asso-beta']);

    $this->get('/asso-alpha/portail/login')
        ->assertStatus(200)
        ->assertSeeText('Asso Alpha')
        ->assertDontSeeText('Asso Beta');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Regression — user web-authentifié visitant /portail en mode mono.
// ResolveTenant boote TenantContext avant MonoAssociationResolver : le
// resolver doit quand même injecter le paramètre route, sinon la layout
// plante sur route('portail.logo') avec un slug null (prod 2026-04-23).
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: user web-auth visitant /portail est redirigé vers /portail/login sans 500', function () {
    $asso = Association::factory()->create(['nom' => 'Asso Prod', 'slug' => 'asso-prod']);
    $admin = User::factory()->create(['derniere_association_id' => $asso->id]);
    $asso->users()->attach($admin->id, ['role' => 'admin']);

    $this->actingAs($admin)
        ->withSession(['current_association_id' => $asso->id])
        ->get('/portail')
        ->assertRedirect('/portail/login');
});

it('mode mono: /portail/login rend 200 même quand TenantContext était déjà booté par ResolveTenant', function () {
    $asso = Association::factory()->create(['nom' => 'Asso Prod', 'slug' => 'asso-prod']);
    $admin = User::factory()->create(['derniere_association_id' => $asso->id]);
    $asso->users()->attach($admin->id, ['role' => 'admin']);

    $this->actingAs($admin)
        ->withSession(['current_association_id' => $asso->id])
        ->get('/portail/login')
        ->assertStatus(200)
        ->assertSeeText('Asso Prod');
});
