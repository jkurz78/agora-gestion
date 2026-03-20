<?php

declare(strict_types=1);

use App\Livewire\VirementInterneForm;
use App\Models\User;
use App\Models\VirementInterne;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('s\'ouvre pour un nouveau virement via open-virement-form', function () {
    Livewire::test(VirementInterneForm::class)
        ->dispatch('open-virement-form', id: null)
        ->assertSet('showForm', true)
        ->assertSet('virementId', null);
});

it('s\'ouvre en édition via open-virement-form avec un id', function () {
    $virement = VirementInterne::factory()->create(['date' => '2025-10-01']);

    Livewire::test(VirementInterneForm::class)
        ->dispatch('open-virement-form', id: $virement->id)
        ->assertSet('showForm', true)
        ->assertSet('virementId', $virement->id)
        ->assertSet('montant', (string) $virement->montant);
});
