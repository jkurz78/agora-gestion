<?php

declare(strict_types=1);

use App\Livewire\DonForm;
use App\Models\Don;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('s\'ouvre pour un nouveau don via open-don-form', function () {
    Livewire::test(DonForm::class)
        ->dispatch('open-don-form', id: null)
        ->assertSet('showForm', true)
        ->assertSet('donId', null);
});

it('s\'ouvre en édition via open-don-form avec un id', function () {
    $don = Don::factory()->create(['date' => '2025-10-01']);

    Livewire::test(DonForm::class)
        ->dispatch('open-don-form', id: $don->id)
        ->assertSet('showForm', true)
        ->assertSet('donId', $don->id)
        ->assertSet('montant', $don->montant);
});

it('se ferme via resetForm', function () {
    Livewire::test(DonForm::class)
        ->dispatch('open-don-form', id: null)
        ->call('resetForm')
        ->assertSet('showForm', false);
});
