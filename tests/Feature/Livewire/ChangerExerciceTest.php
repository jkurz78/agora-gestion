<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Livewire\Exercices\ChangerExercice;
use App\Models\Exercice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Cloture]);
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);
});

it('renders the list of exercices', function () {
    Livewire::test(ChangerExercice::class)
        ->assertOk()
        ->assertSee('2025-2026')
        ->assertSee('2024-2025');
});

it('shows current exercice as active', function () {
    Livewire::test(ChangerExercice::class)
        ->assertSee('Affiché');
});

it('can switch to another exercice', function () {
    Livewire::test(ChangerExercice::class)
        ->call('changer', 2024);

    expect(session('exercice_actif'))->toBe(2024);
});

it('can create a new exercice', function () {
    Livewire::test(ChangerExercice::class)
        ->set('nouvelleAnnee', 2026)
        ->call('creer');

    expect(Exercice::where('annee', 2026)->exists())->toBeTrue();
});

it('cannot create a duplicate exercice', function () {
    Livewire::test(ChangerExercice::class)
        ->set('nouvelleAnnee', 2025)
        ->call('creer')
        ->assertHasErrors('nouvelleAnnee');
});
