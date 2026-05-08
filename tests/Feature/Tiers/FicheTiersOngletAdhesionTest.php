<?php

declare(strict_types=1);

use App\Models\Adhesion;
use App\Models\Tiers;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('Onglet Adhésion absent si tiers sans adhésion', function (): void {
    $tiers = Tiers::factory()->create();

    $response = $this->actingAs($this->user)->get(route('tiers.show', $tiers));

    $response->assertOk();
    $response->assertSee('Coordonnées');
    expect($response->getContent())->not->toContain('?onglet=adhesion');
});

it('Onglet Adhésion présent avec compteur (N) si tiers a N adhésions', function (): void {
    $tiers = Tiers::factory()->create();
    Adhesion::factory()->create(['tiers_id' => $tiers->id, 'exercice' => 2024]);
    Adhesion::factory()->create(['tiers_id' => $tiers->id, 'exercice' => 2025]);

    $response = $this->actingAs($this->user)->get(route('tiers.show', $tiers));

    $response->assertOk()
        ->assertSee('Adhésion')
        ->assertSee('(2)');
    expect($response->getContent())->toContain('?onglet=adhesion');
});

it('Query string ?onglet=adhesion sélectionne l\'onglet', function (): void {
    $tiers = Tiers::factory()->create();
    Adhesion::factory()->create(['tiers_id' => $tiers->id]);

    $response = $this->actingAs($this->user)->get(route('tiers.show', $tiers).'?onglet=adhesion');

    $response->assertOk()
        ->assertSee('Adhésion');
});
