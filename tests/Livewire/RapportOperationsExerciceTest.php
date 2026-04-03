<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Livewire\RapportCompteResultatOperations;
use App\Models\Operation;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
});

it('RapportCompteResultatOperations n\'affiche pas les opérations hors exercice', function () {
    Operation::factory()->create([
        'nom' => 'Op hors exercice',
        'date_debut' => '2024-09-01',
        'date_fin' => '2025-08-30',
        'statut' => StatutOperation::EnCours,
    ]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->assertDontSee('Op hors exercice');
});

it('RapportCompteResultatOperations affiche les opérations clôturées dans l\'exercice', function () {
    Operation::factory()->create([
        'nom' => 'Op clôturée visible',
        'date_debut' => '2025-10-01',
        'date_fin' => '2026-03-31',
        'statut' => StatutOperation::Cloturee,
    ]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSee('Op clôturée visible');
});

