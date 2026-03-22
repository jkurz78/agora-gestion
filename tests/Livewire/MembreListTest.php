<?php

declare(strict_types=1);

use App\Livewire\MembreList;
use App\Models\Cotisation;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    session(['exercice_actif' => 2025]);
});

it('renders without error', function (): void {
    Livewire::actingAs($this->user)
        ->test(MembreList::class)
        ->assertOk();
});

it('filtre a_jour retourne les tiers avec cotisation exercice courant', function (): void {
    $aJour = Tiers::factory()->create(['nom' => 'AJour']);
    $retard = Tiers::factory()->create(['nom' => 'EnRetard']);

    Cotisation::factory()->create(['tiers_id' => $aJour->id, 'exercice' => 2025]);
    Cotisation::factory()->create(['tiers_id' => $retard->id, 'exercice' => 2024]);

    Livewire::actingAs($this->user)
        ->test(MembreList::class)
        ->set('filtre', 'a_jour')
        ->assertSee('AJour')
        ->assertDontSee('EnRetard');
});

it('filtre en_retard retourne les tiers avec cotisation N-1 sans cotisation N', function (): void {
    $aJour = Tiers::factory()->create(['nom' => 'AJour']);
    $retard = Tiers::factory()->create(['nom' => 'EnRetard']);

    Cotisation::factory()->create(['tiers_id' => $aJour->id, 'exercice' => 2024]);
    Cotisation::factory()->create(['tiers_id' => $aJour->id, 'exercice' => 2025]);
    Cotisation::factory()->create(['tiers_id' => $retard->id, 'exercice' => 2024]);

    Livewire::actingAs($this->user)
        ->test(MembreList::class)
        ->set('filtre', 'en_retard')
        ->assertSee('EnRetard')
        ->assertDontSee('AJour');
});

it('filtre tous retourne tous les tiers avec au moins une cotisation', function (): void {
    $avecCot = Tiers::factory()->create(['nom' => 'AvecCot']);
    $sansCot = Tiers::factory()->create(['nom' => 'SansCot']);

    Cotisation::factory()->create(['tiers_id' => $avecCot->id, 'exercice' => 2024]);

    Livewire::actingAs($this->user)
        ->test(MembreList::class)
        ->set('filtre', 'tous')
        ->assertSee('AvecCot')
        ->assertDontSee('SansCot');
});

it('filtre par recherche texte sur le nom', function (): void {
    $martin = Tiers::factory()->create(['nom' => 'Martin']);
    $dupont = Tiers::factory()->create(['nom' => 'Dupont']);

    Cotisation::factory()->create(['tiers_id' => $martin->id, 'exercice' => 2025]);
    Cotisation::factory()->create(['tiers_id' => $dupont->id, 'exercice' => 2025]);

    Livewire::actingAs($this->user)
        ->test(MembreList::class)
        ->set('filtre', 'a_jour')
        ->set('search', 'Martin')
        ->assertSee('Martin')
        ->assertDontSee('Dupont');
});

it('has default perPage of 20', function (): void {
    Livewire::actingAs($this->user)
        ->test(MembreList::class)
        ->assertSet('perPage', 20);
});

it('resets to page 1 when perPage changes', function (): void {
    Livewire::actingAs($this->user)
        ->test(MembreList::class)
        ->set('perPage', 50)
        ->assertSet('paginators.page', 1);
});
