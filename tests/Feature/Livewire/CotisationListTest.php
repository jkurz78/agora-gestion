<?php

declare(strict_types=1);

use App\Livewire\CotisationList;
use App\Models\Cotisation;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    session(['exercice_actif' => (int) date('Y')]);
});

it('n\'affiche pas de colonne Exercice', function () {
    Livewire::test(CotisationList::class)
        ->assertDontSee('Exercice');
});

it('affiche bi-check-lg pour une cotisation pointée', function () {
    $tiers = Tiers::factory()->create();
    Cotisation::factory()->create(['tiers_id' => $tiers->id, 'pointe' => true]);

    Livewire::test(CotisationList::class)
        ->assertSeeHtml('bi bi-check-lg text-success');
});

it('affiche un tiret pour une cotisation non pointée', function () {
    $tiers = Tiers::factory()->create();
    Cotisation::factory()->create(['tiers_id' => $tiers->id, 'pointe' => false]);

    Livewire::test(CotisationList::class)
        ->assertDontSeeHtml('badge bg-success">Oui')
        ->assertDontSeeHtml('badge bg-secondary">Non');
});

it('affiche le nom du membre comme lien vers ses transactions', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'type' => 'particulier']);
    Cotisation::factory()->create(['tiers_id' => $tiers->id]);

    Livewire::test(CotisationList::class)
        ->assertSeeHtml('href="' . route('tiers.transactions', $tiers->id) . '"');
});

it('affiche un tiret sans erreur pour une cotisation sans tiers', function () {
    // tiers_id est nullable dans cotisations (migration add_tiers_id_fk_to_transactions, ->nullable())
    Cotisation::factory()->create(['tiers_id' => null]);

    Livewire::test(CotisationList::class)
        ->assertSee('—');
});

it('les boutons d\'action ont la classe btn-sm sans style inline de padding', function () {
    $tiers = Tiers::factory()->create();
    Cotisation::factory()->create(['tiers_id' => $tiers->id]);

    Livewire::test(CotisationList::class)
        ->assertSeeHtml('btn btn-sm')
        ->assertDontSeeHtml('padding:.15rem');
});
