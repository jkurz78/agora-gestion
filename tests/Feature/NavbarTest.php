<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function () {
    // Re-use the default association created by migration; rename it so login-page assertions hold.
    $this->association = Association::firstOrFail();
    $this->association->update(['nom' => 'Mon Association']);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

it('navbar brand shows new app name and logo', function () {
    $response = $this->get(route('dashboard'));

    $response->assertSee('Mon Association');
    $response->assertSee('images/agora-gestion.svg', false);
});

it('navbar does not contain tableau de bord nav item link', function () {
    $response = $this->get(route('dashboard'));

    $response->assertSee('Dépenses');
    $response->assertSee('Recettes');
    // 'Tableau de bord' apparaît aussi dans le <h1> du dashboard, donc on
    // vérifie l'absence de l'icône speedometer2 qui était exclusive au nav-item supprimé
    $response->assertDontSee('bi-speedometer2', false);
});

it('page title is updated', function () {
    $response = $this->get(route('dashboard'));

    $response->assertSee('Mon Association', false);
});

it('login page shows product logo and app name', function () {
    TenantContext::clear();
    auth()->logout();
    $response = $this->get('/login');

    // /login is a public route with no tenant context: product branding only.
    $response->assertDontSee('Mon Association');
    $response->assertSee('images/agora-gestion.svg', false);
    $response->assertSee('AgoraGestion', false);
});

it('login page title shows product name', function () {
    TenantContext::clear();
    auth()->logout();
    $response = $this->get('/login');

    // Product branding: "AgoraGestion Gestion et comptabilité - Connexion"
    $response->assertSee('AgoraGestion Gestion et comptabilité - Connexion', false);
});
