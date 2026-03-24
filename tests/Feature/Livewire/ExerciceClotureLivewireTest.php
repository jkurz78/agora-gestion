<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Livewire\Dashboard;
use App\Models\Exercice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('sets exerciceCloture to false when exercice is open', function () {
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);

    Livewire::test(Dashboard::class)
        ->assertSet('exerciceCloture', false);
});

it('sets exerciceCloture to true when exercice is closed', function () {
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Cloture]);
    session(['exercice_actif' => 2025]);

    Livewire::test(Dashboard::class)
        ->assertSet('exerciceCloture', true);
});

it('sets exerciceCloture to false when exercice does not exist', function () {
    session(['exercice_actif' => 2099]);

    Livewire::test(Dashboard::class)
        ->assertSet('exerciceCloture', false);
});
