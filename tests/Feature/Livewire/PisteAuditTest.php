<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\TypeActionExercice;
use App\Livewire\Exercices\PisteAudit;
use App\Models\Exercice;
use App\Models\ExerciceAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders audit trail table', function () {
    $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    ExerciceAction::create([
        'exercice_id' => $exercice->id,
        'action' => TypeActionExercice::Creation,
        'user_id' => $this->user->id,
        'commentaire' => 'Création initiale',
    ]);

    Livewire::test(PisteAudit::class)
        ->assertOk()
        ->assertSee('2025-2026')
        ->assertSee('Création')
        ->assertSee($this->user->nom);
});

it('displays actions in reverse chronological order', function () {
    $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    $first = ExerciceAction::create([
        'exercice_id' => $exercice->id,
        'action' => TypeActionExercice::Creation,
        'user_id' => $this->user->id,
    ]);
    $first->forceFill(['created_at' => now()->subMinutes(5)])->save();

    ExerciceAction::create([
        'exercice_id' => $exercice->id,
        'action' => TypeActionExercice::Cloture,
        'user_id' => $this->user->id,
    ]);

    Livewire::test(PisteAudit::class)
        ->assertSeeInOrder(['Clôture', 'Création']);
});
