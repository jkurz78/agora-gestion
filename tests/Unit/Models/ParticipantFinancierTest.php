<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Transaction;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

function makeParticipantWithReglements(Association $asso): Participant
{
    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id]);
    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
    ]);
    $seance = Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->subWeek(),
    ]);

    return Participant::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
    ]);
}

function makeTransaction(Association $asso, Reglement $reglement, float $montant, StatutReglement $statut): Transaction
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
// Test 6 : totalPrevu() retourne la somme des Reglement.montant_prevu
// ─────────────────────────────────────────────────────────────────────────────
it('totalPrevu retourne la somme des montants prévus des règlements', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $participant = makeParticipantWithReglements($asso);

    Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);
    Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);

    expect($participant->totalPrevu())->toBe(100.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7 : totalRegle() retourne la somme des Transactions encaissées (Recu + Pointe)
// ─────────────────────────────────────────────────────────────────────────────
it('totalRegle retourne la somme des transactions avec statut Recu ou Pointe', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $participant = makeParticipantWithReglements($asso);

    $r1 = Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);
    $r2 = Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);

    makeTransaction($asso, $r1, 50.00, StatutReglement::Recu);
    makeTransaction($asso, $r2, 50.00, StatutReglement::Pointe);

    expect($participant->totalRegle())->toBe(100.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 8 : resteARegler() retourne max(0, totalPrevu - totalRegle)
// ─────────────────────────────────────────────────────────────────────────────
it('resteARegler retourne max(0, totalPrevu - totalRegle)', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $participant = makeParticipantWithReglements($asso);

    $r1 = Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);
    $r2 = Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);

    makeTransaction($asso, $r1, 50.00, StatutReglement::Recu);

    expect($participant->resteARegler())->toBe(50.0);
});

it('resteARegler retourne 0 quand tout est regle', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $participant = makeParticipantWithReglements($asso);

    $r1 = Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);
    $r2 = Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);

    makeTransaction($asso, $r1, 50.00, StatutReglement::Recu);
    makeTransaction($asso, $r2, 50.00, StatutReglement::Pointe);

    expect($participant->resteARegler())->toBe(0.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 9 : totalRegle() ignore les transactions EnAttente
// ─────────────────────────────────────────────────────────────────────────────
it('totalRegle ignore les transactions EnAttente', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $participant = makeParticipantWithReglements($asso);

    $r1 = Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);
    $r2 = Reglement::factory()->create(['participant_id' => $participant->id, 'montant_prevu' => 50.00]);

    makeTransaction($asso, $r1, 50.00, StatutReglement::Recu);
    makeTransaction($asso, $r2, 50.00, StatutReglement::EnAttente);

    expect($participant->totalRegle())->toBe(50.0);
});
