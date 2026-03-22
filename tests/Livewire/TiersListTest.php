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

it('recherche dans le champ entreprise', function () {
    Tiers::factory()->entreprise()->create(['nom' => 'ACME Corp', 'entreprise' => 'ACME Corp', 'ville' => null]);
    Tiers::factory()->create(['nom' => 'Dupont', 'entreprise' => null]);

    Livewire::test(TiersList::class)
        ->set('search', 'ACME')
        ->assertSee('ACME Corp')
        ->assertDontSee('Dupont');
});

it('recherche dans le champ ville', function () {
    Tiers::factory()->create(['nom' => 'Martin', 'ville' => 'Lyon']);
    Tiers::factory()->create(['nom' => 'Dupont', 'ville' => 'Paris']);

    Livewire::test(TiersList::class)
        ->set('search', 'Lyon')
        ->assertSee('Martin')
        ->assertDontSee('Dupont');
});

it('recherche dans le champ code_postal', function () {
    Tiers::factory()->create(['nom' => 'Martin', 'code_postal' => '75001', 'ville' => 'Paris']);
    Tiers::factory()->create(['nom' => 'Dupont', 'code_postal' => '69001', 'ville' => 'Lyon']);

    Livewire::test(TiersList::class)
        ->set('search', '75')
        ->assertSee('Martin')
        ->assertDontSee('Dupont');
});

it('recherche dans le champ email', function () {
    Tiers::factory()->create(['nom' => 'Martin', 'email' => 'martin@acme.fr']);
    Tiers::factory()->create(['nom' => 'Dupont', 'email' => 'dupont@other.fr']);

    Livewire::test(TiersList::class)
        ->set('search', 'acme')
        ->assertSee('Martin')
        ->assertDontSee('Dupont');
});

it('filtre helloasso actif — affiche seulement les tiers avec helloasso_id', function () {
    Tiers::factory()->avecHelloasso()->create(['nom' => 'Martin']);
    Tiers::factory()->create(['nom' => 'Dupont', 'helloasso_id' => null]);

    Livewire::test(TiersList::class)
        ->set('filtreHelloasso', true)
        ->assertSee('Martin')
        ->assertDontSee('Dupont');
});

it('filtre helloasso inactif — affiche tous les tiers', function () {
    Tiers::factory()->avecHelloasso()->create(['nom' => 'Martin']);
    Tiers::factory()->create(['nom' => 'Dupont', 'helloasso_id' => null]);

    Livewire::test(TiersList::class)
        ->set('filtreHelloasso', false)
        ->assertSee('Martin')
        ->assertSee('Dupont');
});
