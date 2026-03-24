<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Livewire\Exercices\ReouvrirExercice;
use App\Models\Exercice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->exercice = Exercice::create([
        'annee' => 2025,
        'statut' => StatutExercice::Cloture,
        'date_cloture' => now(),
        'cloture_par_id' => $this->user->id,
    ]);
    session(['exercice_actif' => 2025]);
});

it('renders with exercice info', function () {
    Livewire::test(ReouvrirExercice::class)
        ->assertOk()
        ->assertSee('2025-2026');
});

it('requires a motif to reopen', function () {
    Livewire::test(ReouvrirExercice::class)
        ->set('commentaire', '')
        ->call('reouvrir')
        ->assertHasErrors('commentaire');
});

it('reopens the exercice with a valid motif', function () {
    Livewire::test(ReouvrirExercice::class)
        ->set('commentaire', 'Erreur de saisie détectée après clôture')
        ->call('reouvrir');

    $this->exercice->refresh();
    expect($this->exercice->statut)->toBe(StatutExercice::Ouvert);
});

it('redirects if exercice is already open', function () {
    $this->exercice->update(['statut' => StatutExercice::Ouvert, 'date_cloture' => null, 'cloture_par_id' => null]);

    Livewire::test(ReouvrirExercice::class)
        ->assertRedirect(route('compta.exercices.changer'));
});
