<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Livewire\Dashboard;
use App\Models\Association;
use App\Models\Exercice;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

it('sets exerciceCloture to false when exercice is open', function () {
    Exercice::create(['association_id' => $this->association->id, 'annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);

    Livewire::test(Dashboard::class)
        ->assertSet('exerciceCloture', false);
});

it('sets exerciceCloture to true when exercice is closed', function () {
    Exercice::create(['association_id' => $this->association->id, 'annee' => 2025, 'statut' => StatutExercice::Cloture]);
    session(['exercice_actif' => 2025]);

    Livewire::test(Dashboard::class)
        ->assertSet('exerciceCloture', true);
});

it('sets exerciceCloture to false when exercice does not exist', function () {
    session(['exercice_actif' => 2099]);

    Livewire::test(Dashboard::class)
        ->assertSet('exerciceCloture', false);
});
