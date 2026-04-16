<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Livewire\Exercices\ReouvrirExercice;
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

    $this->exercice = Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2025,
        'statut' => StatutExercice::Cloture,
        'date_cloture' => now(),
        'cloture_par_id' => $this->user->id,
    ]);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    TenantContext::clear();
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
        ->assertRedirect(route('exercices.changer'));
});
