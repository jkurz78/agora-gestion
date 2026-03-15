<?php

// tests/Livewire/TiersListTest.php
declare(strict_types=1);

use App\Livewire\TiersList;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders the tiers list', function () {
    Tiers::factory()->create(['nom' => 'Mairie de Lyon']);

    Livewire::test(TiersList::class)
        ->assertOk()
        ->assertSee('Mairie de Lyon');
});

it('filters by search', function () {
    Tiers::factory()->create(['nom' => 'Mairie de Lyon']);
    Tiers::factory()->create(['nom' => 'Leclerc SA']);

    Livewire::test(TiersList::class)
        ->set('search', 'Mairie')
        ->assertSee('Mairie de Lyon')
        ->assertDontSee('Leclerc SA');
});

it('filters pour_depenses', function () {
    Tiers::factory()->pourDepenses()->create(['nom' => 'Fournisseur A']);
    Tiers::factory()->create(['nom' => 'Recette Only', 'pour_depenses' => false, 'pour_recettes' => true]);

    Livewire::test(TiersList::class)
        ->set('filtre', 'depenses')
        ->assertSee('Fournisseur A')
        ->assertDontSee('Recette Only');
});

it('can delete a tiers', function () {
    $tiers = Tiers::factory()->create();

    Livewire::test(TiersList::class)
        ->call('delete', $tiers->id);

    $this->assertDatabaseMissing('tiers', ['id' => $tiers->id]);
});
