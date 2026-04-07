<?php

use App\Models\User;

it('navbar brand shows new app name and logo', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('compta.dashboard'));

    $response->assertSee('Mon Association');
    $response->assertSee('images/agora-gestion.svg', false);
});

it('navbar does not contain tableau de bord nav item link', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('compta.dashboard'));

    $response->assertSee('Dépenses');
    $response->assertSee('Recettes');
    // 'Tableau de bord' apparaît aussi dans le <h1> du dashboard, donc on
    // vérifie l'absence de l'icône speedometer2 qui était exclusive au nav-item supprimé
    $response->assertDontSee('bi-speedometer2', false);
});

it('page title is updated', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('compta.dashboard'));

    $response->assertSee('Mon Association Comptabilité', false);
});

it('login page shows logo and new app name', function () {
    $response = $this->get('/login');

    $response->assertSee('Mon Association');
    $response->assertSee('images/agora-gestion.svg', false);
    $response->assertSee('AgoraGestion', false);
});

it('login page title is updated', function () {
    $response = $this->get('/login');

    $response->assertSee('Mon Association Gestion et comptabilité - Connexion', false);
});
