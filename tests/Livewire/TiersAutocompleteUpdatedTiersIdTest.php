<?php

declare(strict_types=1);

use App\Livewire\TiersAutocomplete;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('met à jour selectedLabel et selectedType quand tiersId change programmatiquement', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'type' => 'particulier', 'prenom' => 'Jean']);

    Livewire::actingAs($this->user)
        ->test(TiersAutocomplete::class)
        ->set('tiersId', $tiers->id)
        ->assertSet('selectedLabel', 'Jean DUPONT')
        ->assertSet('selectedType', 'particulier');
});

it('vide selectedLabel et selectedType quand tiersId devient null', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont']);

    Livewire::actingAs($this->user)
        ->test(TiersAutocomplete::class, ['tiersId' => $tiers->id])
        ->set('tiersId', null)
        ->assertSet('selectedLabel', null)
        ->assertSet('selectedType', null);
});
