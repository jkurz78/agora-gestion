<?php

declare(strict_types=1);

use App\Livewire\MembreList;
use App\Models\Cotisation;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('affiche bi-check-lg Bootstrap Icon pour un membre avec cotisation pointée', function () {
    $tiers = Tiers::factory()->create();
    // La derniereCotisation est chargée via une relation — on crée une cotisation pointée
    Cotisation::factory()->create([
        'tiers_id' => $tiers->id,
        'pointe' => true,
    ]);

    Livewire::test(MembreList::class)
        ->set('filtre', 'tous')
        ->assertSeeHtml('bi bi-check-lg text-success');
});

it('n\'affiche pas le caractère unicode ✓', function () {
    $tiers = Tiers::factory()->create();
    Cotisation::factory()->create([
        'tiers_id' => $tiers->id,
        'pointe' => true,
    ]);

    Livewire::test(MembreList::class)
        ->set('filtre', 'tous')
        ->assertDontSee('✓');
});

it('affiche un bouton bi-clock-history lié aux transactions du membre', function () {
    $tiers = Tiers::factory()->create();
    Cotisation::factory()->create(['tiers_id' => $tiers->id]);

    Livewire::test(MembreList::class)
        ->set('filtre', 'tous')
        ->assertSeeHtml('bi bi-clock-history')
        ->assertSeeHtml('href="'.route('tiers.transactions', $tiers->id).'"');
});

it('les boutons d\'action ont la classe btn-sm sans style inline de padding', function () {
    $tiers = Tiers::factory()->create();
    Cotisation::factory()->create(['tiers_id' => $tiers->id]);

    Livewire::test(MembreList::class)
        ->set('filtre', 'tous')
        ->assertSeeHtml('btn btn-sm')
        ->assertDontSeeHtml('padding:.15rem');
});
