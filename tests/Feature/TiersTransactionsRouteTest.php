<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('affiche la page transactions d\'un tiers existant', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'Martin']);

    $this->actingAs($this->user)
        ->get(route('compta.tiers.transactions', $tiers))
        ->assertOk()
        ->assertSee('Martin');
});

it('retourne 404 pour un tiers inexistant', function (): void {
    $this->actingAs($this->user)
        ->get(route('compta.tiers.transactions', 9999))
        ->assertNotFound();
});

it('redirige les guests vers login', function (): void {
    $tiers = Tiers::factory()->create();

    $this->get(route('compta.tiers.transactions', $tiers))
        ->assertRedirect('/login');
});
