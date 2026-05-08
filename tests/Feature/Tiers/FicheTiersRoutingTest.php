<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('rend la page fiche tiers en 200 avec identité visible', function (): void {
    $tiers = Tiers::factory()->create([
        'nom' => 'Martin',
        'prenom' => 'Jeanne',
        'email' => 'jeanne.martin@example.org',
    ]);

    $this->actingAs($this->user)
        ->get(route('tiers.show', $tiers))
        ->assertOk()
        ->assertSee('MARTIN')
        ->assertSee('Jeanne')
        ->assertSee('jeanne.martin@example.org');
});

it('alimente le breadcrumb topbar avec le nom du tiers', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont']);

    $response = $this->actingAs($this->user)->get(route('tiers.show', $tiers));

    $response->assertOk();
    // Le breadcrumb topbar utilise <x-slot:title> qui devient $breadcrumbPage.
    // Vérification souple : la nav breadcrumb contient le nom.
    $response->assertSeeInOrder(['Tiers', 'DUPONT']);
});

it('ne rend pas de balise <h1> sur la page', function (): void {
    $tiers = Tiers::factory()->create();

    $html = $this->actingAs($this->user)
        ->get(route('tiers.show', $tiers))
        ->getContent();

    expect($html)->not->toContain('<h1');
});

it('redirige les guests vers login', function (): void {
    $tiers = Tiers::factory()->create();
    $this->get(route('tiers.show', $tiers))->assertRedirect('/login');
});

it('sélectionne l\'onglet via la query string ?onglet=coordonnees', function (): void {
    $tiers = Tiers::factory()->create();

    $response = $this->actingAs($this->user)->get(route('tiers.show', $tiers).'?onglet=coordonnees');

    $response->assertOk();
    // Vérification souple : le composant Coordonnees est monté (sa vue contient "À venir.")
    $response->assertSee('À venir.');
});
