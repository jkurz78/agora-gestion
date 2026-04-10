<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\StatutRapprochement;
use App\Livewire\BudgetTable;
use App\Livewire\ParticipantTable;
use App\Livewire\RapprochementDetail;
use App\Livewire\ReglementTable;
use App\Livewire\TransactionForm;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\RapprochementBancaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ─── TransactionForm (Compta) ────────────────────────────────────────────────

it('consultation gets canEdit false on TransactionForm', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);

    Livewire::actingAs($user)
        ->test(TransactionForm::class)
        ->assertSet('canEdit', false);
});

it('comptable gets canEdit true on TransactionForm', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);

    Livewire::actingAs($user)
        ->test(TransactionForm::class)
        ->assertSet('canEdit', true);
});

// ─── BudgetTable (Compta) ────────────────────────────────────────────────────

it('consultation gets canEdit false on BudgetTable', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);

    Livewire::actingAs($user)
        ->test(BudgetTable::class)
        ->assertSet('canEdit', false);
});

it('comptable gets canEdit true on BudgetTable', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);

    Livewire::actingAs($user)
        ->test(BudgetTable::class)
        ->assertSet('canEdit', true);
});

// ─── RapprochementDetail (Compta) ────────────────────────────────────────────

it('consultation gets canEdit false on RapprochementDetail', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    $compte = CompteBancaire::factory()->create();
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'statut' => StatutRapprochement::EnCours,
        'solde_ouverture' => 1000.00,
        'solde_fin' => 1200.00,
        'date_fin' => '2026-03-31',
        'saisi_par' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(RapprochementDetail::class, ['rapprochement' => $rapprochement])
        ->assertSet('canEdit', false);
});

it('comptable gets canEdit true on RapprochementDetail', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);
    $compte = CompteBancaire::factory()->create();
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'statut' => StatutRapprochement::EnCours,
        'solde_ouverture' => 1000.00,
        'solde_fin' => 1200.00,
        'date_fin' => '2026-03-31',
        'saisi_par' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(RapprochementDetail::class, ['rapprochement' => $rapprochement])
        ->assertSet('canEdit', true);
});

// ─── ParticipantTable (Gestion) ──────────────────────────────────────────────

it('consultation gets canEdit false on ParticipantTable', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    $operation = Operation::factory()->create();

    Livewire::actingAs($user)
        ->test(ParticipantTable::class, ['operation' => $operation])
        ->assertSet('canEdit', false);
});

it('gestionnaire gets canEdit true on ParticipantTable', function () {
    $user = User::factory()->create(['role' => Role::Gestionnaire]);
    $operation = Operation::factory()->create();

    Livewire::actingAs($user)
        ->test(ParticipantTable::class, ['operation' => $operation])
        ->assertSet('canEdit', true);
});

it('comptable gets canEdit false on ParticipantTable', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);
    $operation = Operation::factory()->create();

    Livewire::actingAs($user)
        ->test(ParticipantTable::class, ['operation' => $operation])
        ->assertSet('canEdit', false);
});

it('admin gets canEdit true on ParticipantTable', function () {
    $user = User::factory()->create(['role' => Role::Admin]);
    $operation = Operation::factory()->create();

    Livewire::actingAs($user)
        ->test(ParticipantTable::class, ['operation' => $operation])
        ->assertSet('canEdit', true);
});

// ─── ReglementTable (Gestion) ────────────────────────────────────────────────

it('consultation gets canEdit false on ReglementTable', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    $operation = Operation::factory()->create();

    Livewire::actingAs($user)
        ->test(ReglementTable::class, ['operation' => $operation])
        ->assertSet('canEdit', false);
});

it('gestionnaire gets canEdit true on ReglementTable', function () {
    $user = User::factory()->create(['role' => Role::Gestionnaire]);
    $operation = Operation::factory()->create();

    Livewire::actingAs($user)
        ->test(ReglementTable::class, ['operation' => $operation])
        ->assertSet('canEdit', true);
});

