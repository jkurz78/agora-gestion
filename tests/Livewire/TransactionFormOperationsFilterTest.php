<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\Operation;
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
    // Exercice affiché : 2025 (2025-09-01 → 2026-08-31)
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

it('affiche une opération en cours datée sur un exercice antérieur', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op passée',
        'date_debut' => '2024-09-01',
        'date_fin' => '2025-08-31',
        'statut' => StatutOperation::EnCours,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->assertSee('Op passée');
});

it('affiche une opération en cours datée dans un exercice futur', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op future',
        'date_debut' => '2030-09-01',
        'date_fin' => '2031-08-31',
        'statut' => StatutOperation::EnCours,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->assertSee('Op future');
});

it('affiche une opération dans l\'exercice courant', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op courante',
        'date_debut' => '2025-10-01',
        'date_fin' => '2026-03-31',
        'statut' => StatutOperation::EnCours,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->assertSee('Op courante');
});

it('n\'affiche pas une opération clôturée même dans l\'exercice', function () {
    Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op clôturée',
        'date_debut' => '2025-10-01',
        'date_fin' => '2026-03-31',
        'statut' => StatutOperation::Cloturee,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->assertDontSee('Op clôturée');
});

it('affiche à nouveau une opération rouverte', function () {
    $operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Op rouverte',
        'date_debut' => '2025-10-01',
        'date_fin' => '2026-03-31',
        'statut' => StatutOperation::Cloturee,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->assertDontSee('Op rouverte');

    $operation->update(['statut' => StatutOperation::EnCours]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->assertSee('Op rouverte');
});
