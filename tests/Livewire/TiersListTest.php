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

it('tri par nom ASC — ordre COALESCE(entreprise, nom)', function () {
    Tiers::factory()->entreprise()->create(['entreprise' => 'Zéphyr SA', 'nom' => 'dummy1']);
    Tiers::factory()->create(['nom' => 'Arnaud', 'entreprise' => null]);
    Tiers::factory()->entreprise()->create(['entreprise' => 'Martin SARL', 'nom' => 'dummy2']);

    $component = Livewire::test(TiersList::class);
    // Default is already sortBy='nom', sortDir='asc', so don't call sort

    $html = $component->html();
    $posArnaud  = strpos($html, 'Arnaud');
    $posMartin  = strpos($html, 'Martin SARL');
    $posZephyr  = strpos($html, 'Zéphyr SA');

    expect($posArnaud)->toBeLessThan($posMartin);
    expect($posMartin)->toBeLessThan($posZephyr);
});

it('tri par nom DESC', function () {
    Tiers::factory()->entreprise()->create(['entreprise' => 'Zéphyr SA', 'nom' => 'dummy1']);
    Tiers::factory()->create(['nom' => 'Arnaud', 'entreprise' => null]);

    $component = Livewire::test(TiersList::class)
        ->call('sort', 'nom');  // toggle from default asc to desc

    $html = $component->html();
    expect(strpos($html, 'Zéphyr SA'))->toBeLessThan(strpos($html, 'Arnaud'));
});

it('tri par ville', function () {
    Tiers::factory()->create(['nom' => 'Martin', 'email' => 'martin@paris.fr', 'ville' => 'Paris']);
    Tiers::factory()->create(['nom' => 'Dupont', 'email' => 'dupont@bordeaux.fr', 'ville' => 'Bordeaux']);

    $component = Livewire::test(TiersList::class)
        ->call('sort', 'ville');

    $html = $component->html();
    // Bordeaux sorts before Paris
    expect(strpos($html, 'dupont@bordeaux.fr'))->toBeLessThan(strpos($html, 'martin@paris.fr'));
});

it('entreprise sans raison sociale — displayName affiche nom, tri COALESCE rabat sur nom', function () {
    Tiers::factory()->create(['type' => 'entreprise', 'entreprise' => null, 'nom' => 'Ancien', 'prenom' => null]);
    Tiers::factory()->entreprise()->create(['entreprise' => 'Zéphyr SA', 'nom' => 'dummy']);

    $component = Livewire::test(TiersList::class);
    // Default is already sortBy='nom', sortDir='asc'

    $html = $component->html();
    expect(strpos($html, 'Ancien'))->toBeLessThan(strpos($html, 'Zéphyr SA'));
});
