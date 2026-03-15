<?php

declare(strict_types=1);

use App\Livewire\CotisationForm;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    session(['exercice_actif' => 2025]);
});

it('ouvre le formulaire avec le tiers pré-sélectionné via event', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::actingAs($this->user)
        ->test(CotisationForm::class)
        ->dispatch('open-cotisation-for-tiers', tiersId: $tiers->id)
        ->assertSet('showForm', true)
        ->assertSet('tiers_id', $tiers->id);
});

it('ouvre le formulaire sans tiers quand tiersId est null', function (): void {
    Livewire::actingAs($this->user)
        ->test(CotisationForm::class)
        ->dispatch('open-cotisation-for-tiers', tiersId: null)
        ->assertSet('showForm', true)
        ->assertSet('tiers_id', null);
});
