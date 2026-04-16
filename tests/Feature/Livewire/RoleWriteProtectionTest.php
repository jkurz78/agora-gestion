<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Livewire\BudgetTable;
use App\Livewire\ParticipantTable;
use App\Livewire\RapprochementDetail;
use App\Livewire\ReglementTable;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\RapprochementBancaire;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
});

// Helper: create a user with the given pivot role
function makeUserWithRole(Association $association, string $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role, 'joined_at' => now()]);
    return $user;
}

// ─── TransactionForm (Compta) ────────────────────────────────────────────────

it('consultation gets canEdit false on TransactionForm', function () {
    $user = makeUserWithRole($this->association, 'consultation');

    Livewire::actingAs($user)
        ->test(TransactionForm::class)
        ->assertSet('canEdit', false);
});

it('comptable gets canEdit true on TransactionForm', function () {
    $user = makeUserWithRole($this->association, 'comptable');

    Livewire::actingAs($user)
        ->test(TransactionForm::class)
        ->assertSet('canEdit', true);
});

// ─── BudgetTable (Compta) ────────────────────────────────────────────────────

it('consultation gets canEdit false on BudgetTable', function () {
    $user = makeUserWithRole($this->association, 'consultation');

    Livewire::actingAs($user)
        ->test(BudgetTable::class)
        ->assertSet('canEdit', false);
});

it('comptable gets canEdit true on BudgetTable', function () {
    $user = makeUserWithRole($this->association, 'comptable');

    Livewire::actingAs($user)
        ->test(BudgetTable::class)
        ->assertSet('canEdit', true);
});

// ─── RapprochementDetail (Compta) ────────────────────────────────────────────

it('consultation gets canEdit false on RapprochementDetail', function () {
    $user = makeUserWithRole($this->association, 'consultation');
    $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
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
    $user = makeUserWithRole($this->association, 'comptable');
    $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
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
    $user = makeUserWithRole($this->association, 'consultation');
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    Livewire::actingAs($user)
        ->test(ParticipantTable::class, ['operation' => $operation])
        ->assertSet('canEdit', false);
});

it('gestionnaire gets canEdit true on ParticipantTable', function () {
    $user = makeUserWithRole($this->association, 'gestionnaire');
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    Livewire::actingAs($user)
        ->test(ParticipantTable::class, ['operation' => $operation])
        ->assertSet('canEdit', true);
});

it('comptable gets canEdit false on ParticipantTable', function () {
    $user = makeUserWithRole($this->association, 'comptable');
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    Livewire::actingAs($user)
        ->test(ParticipantTable::class, ['operation' => $operation])
        ->assertSet('canEdit', false);
});

it('admin gets canEdit true on ParticipantTable', function () {
    $user = makeUserWithRole($this->association, 'admin');
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    Livewire::actingAs($user)
        ->test(ParticipantTable::class, ['operation' => $operation])
        ->assertSet('canEdit', true);
});

// ─── ReglementTable (Gestion) ────────────────────────────────────────────────

it('consultation gets canEdit false on ReglementTable', function () {
    $user = makeUserWithRole($this->association, 'consultation');
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    Livewire::actingAs($user)
        ->test(ReglementTable::class, ['operation' => $operation])
        ->assertSet('canEdit', false);
});

it('gestionnaire gets canEdit true on ReglementTable', function () {
    $user = makeUserWithRole($this->association, 'gestionnaire');
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);

    Livewire::actingAs($user)
        ->test(ReglementTable::class, ['operation' => $operation])
        ->assertSet('canEdit', true);
});
