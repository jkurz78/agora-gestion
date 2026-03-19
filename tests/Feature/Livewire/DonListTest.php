<?php

declare(strict_types=1);

use App\Livewire\DonList;
use App\Models\Don;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('affiche bi-check-lg pour un don pointé', function () {
    Don::factory()->create(['pointe' => true]);

    Livewire::test(DonList::class)
        ->assertSeeHtml('bi bi-check-lg text-success');
});

it('affiche un tiret pour un don non pointé', function () {
    Don::factory()->create(['pointe' => false]);

    Livewire::test(DonList::class)
        ->assertSeeHtml('class="text-muted">—</span>')
        ->assertDontSee('Non');
});

it('n\'affiche plus les badges Oui/Non pour Pointé', function () {
    Don::factory()->create(['pointe' => true]);
    Don::factory()->create(['pointe' => false]);

    Livewire::test(DonList::class)
        ->assertDontSeeHtml('badge bg-success">Oui')
        ->assertDontSeeHtml('badge bg-secondary">Non');
});

it('les boutons d\'action ont la classe btn-sm sans style inline de padding', function () {
    Don::factory()->create();

    Livewire::test(DonList::class)
        ->assertSeeHtml('btn btn-sm')
        ->assertDontSeeHtml('padding:.15rem');
});
