<?php

declare(strict_types=1);

use App\Livewire\CotisationForm;
use App\Models\Cotisation;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('s\'ouvre pour une nouvelle cotisation via open-cotisation-form', function () {
    Livewire::test(CotisationForm::class)
        ->dispatch('open-cotisation-form', id: null)
        ->assertSet('showForm', true)
        ->assertSet('cotisationId', null);
});

it('s\'ouvre en édition avec tiers verrouillé via open-cotisation-form', function () {
    $cotisation = Cotisation::factory()->create(['date_paiement' => '2025-10-01']);

    Livewire::test(CotisationForm::class)
        ->dispatch('open-cotisation-form', id: $cotisation->id)
        ->assertSet('showForm', true)
        ->assertSet('cotisationId', $cotisation->id)
        ->assertSet('tiers_id', $cotisation->tiers_id)
        ->assertSet('tiersLocked', true);
});
