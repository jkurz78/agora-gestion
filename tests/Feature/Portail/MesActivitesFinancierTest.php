<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Livewire\Portail\MesActivites;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

function makeFinancierSetup(Association $asso): array
{
    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id, 'nom' => 'Formation']);
    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Formation financière',
        'date_debut' => null,
        'date_fin' => null,
    ]);
    // 1 séance passée + 1 future → En cours
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->subWeek(),
    ]);
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->addWeek(),
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
    ]);

    return [$typeOp, $participant];
}

function makeTransactionForReglement(Association $asso, Reglement $reglement, float $montant, StatutReglement $statut): Transaction
{
    $compte = CompteBancaire::factory()->create(['association_id' => $asso->id]);
    $user = User::factory()->create();

    return Transaction::factory()->create([
        'association_id' => $asso->id,
        'reglement_id' => $reglement->id,
        'montant_total' => $montant,
        'statut_reglement' => $statut,
        'compte_id' => $compte->id,
        'saisi_par' => $user->id,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Bloc financier — opération avec règlements partiels → "À régler"
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le bloc financier avec montants et badge À régler quand reste > 0', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    [$typeOp, $participant] = makeFinancierSetup($asso);

    $r1 = Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);
    $r2 = Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);

    makeTransactionForReglement($asso, $r1, 50.00, StatutReglement::Recu);

    $html = Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertStatus(200)
        ->html();

    expect($html)
        ->toContain('Total')
        ->toContain('100,00')
        ->toContain('Réglé')
        ->toContain('50,00')
        ->toContain('Reste')
        ->toContain('À régler');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Bloc financier — opération à jour (Recu + Pointe) → badge "À jour"
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le badge À jour quand tout est réglé', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    [$typeOp, $participant] = makeFinancierSetup($asso);

    $r1 = Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);
    $r2 = Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);

    makeTransactionForReglement($asso, $r1, 50.00, StatutReglement::Recu);
    makeTransactionForReglement($asso, $r2, 50.00, StatutReglement::Pointe);

    $html = Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertStatus(200)
        ->html();

    expect($html)
        ->toContain('À jour')
        ->not->toContain('Reste')
        ->not->toContain('À régler');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Bloc financier — règlements sans transactions → "En attente de règlement"
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le badge En attente de règlement quand aucune transaction encaissée', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    [$typeOp, $participant] = makeFinancierSetup($asso);

    Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);
    Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);

    $html = Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertStatus(200)
        ->html();

    expect($html)
        ->toContain('En attente de r')  // "En attente de règlement" (é encoded)
        ->not->toContain('Reste');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Bloc financier — opération gratuite → bloc absent
// ─────────────────────────────────────────────────────────────────────────────
it('n\'affiche pas le bloc financier quand aucun règlement programmé', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    [$typeOp, $participant] = makeFinancierSetup($asso);
    // Aucun Reglement créé

    $html = Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertStatus(200)
        ->html();

    expect($html)->not->toContain('Total dû :');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Timeline horizontale — classe CSS flex-md-row présente
// ─────────────────────────────────────────────────────────────────────────────
it('la timeline des séances utilise flex-md-row pour le layout horizontal desktop', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id, 'nom' => 'Formation']);
    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Stage horizontal',
        'date_debut' => null,
        'date_fin' => null,
    ]);
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->subWeek(),
    ]);
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->addWeek(),
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
    ]);

    $html = Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertStatus(200)
        ->html();

    expect($html)
        ->toContain('<ul class="seance-timeline')
        ->toContain('flex-md-row');
});
