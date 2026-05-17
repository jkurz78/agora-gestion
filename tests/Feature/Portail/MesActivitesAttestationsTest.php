<?php

declare(strict_types=1);

use App\Enums\StatutPresence;
use App\Livewire\Portail\MesActivites;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Support\PortailRoute;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function makeAssoAtt2(): Association
{
    return Association::factory()->create(['slug' => 'asso-att2']);
}

function makeEnCoursOpAtt(Association $asso): array
{
    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id]);
    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Formation Encours',
        'date_debut' => now()->subMonth(),
        'date_fin' => now()->addMonth(),
    ]);
    // Séance passée (→ déclenchera horizon EnCours)
    $seance = Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->subWeek()->toDateString(),
        'numero' => 1,
    ]);
    // Séance future (nécessaire pour qualifier "En cours" vs "Terminée")
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->addWeek()->toDateString(),
        'numero' => 2,
    ]);

    return [$typeOp, $operation, $seance];
}

function makeTermineeOpAtt(Association $asso): array
{
    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id]);
    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Formation Terminée',
        'date_debut' => now()->subMonths(3),
        'date_fin' => now()->subMonth(),
    ]);
    $seance = Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->subMonth()->toDateString(),
        'numero' => 1,
    ]);

    return [$typeOp, $operation, $seance];
}

function makeParticipantAtt(Association $asso, Operation $operation, Tiers $tiers): Participant
{
    return Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
    ]);
}

function addPresence(Seance $seance, Participant $participant, string $statut): void
{
    Presence::create([
        'seance_id' => $seance->id,
        'participant_id' => $participant->id,
        'statut' => $statut,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 9 : Section En cours avec Presence(Present) → bouton attestation séance
// ─────────────────────────────────────────────────────────────────────────────
it('Section En cours : bouton attestation séance affiché pour séance avec statut Present', function () {
    $asso = makeAssoAtt2();
    TenantContext::boot($asso);

    [$typeOp, $operation, $seancePas] = makeEnCoursOpAtt($asso);
    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = makeParticipantAtt($asso, $operation, $tiers);
    addPresence($seancePas, $participant, StatutPresence::Present->value);

    $html = Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertStatus(200)
        ->html();

    $expectedUrl = PortailRoute::to('attestations.seance', $asso, [
        'operation' => $operation->id,
        'seance' => $seancePas->id,
    ]);

    expect($html)->toContain($expectedUrl);
    expect($html)->toContain('attestation');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 10 : Section Terminée → bouton attestation globale
// ─────────────────────────────────────────────────────────────────────────────
it('Section Terminée : bouton attestation globale affiché', function () {
    $asso = makeAssoAtt2();
    TenantContext::boot($asso);

    [$typeOp, $operation, $seance] = makeTermineeOpAtt($asso);
    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = makeParticipantAtt($asso, $operation, $tiers);
    addPresence($seance, $participant, StatutPresence::Present->value);

    $html = Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertStatus(200)
        ->html();

    $expectedUrl = PortailRoute::to('attestations.recap', $asso, [
        'operation' => $operation->id,
        'participant' => $participant->id,
    ]);

    expect($html)->toContain($expectedUrl);
    expect($html)->toContain('Attestation globale');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 11 : Présence != Present → pas de bouton attestation sur cette séance
// ─────────────────────────────────────────────────────────────────────────────
it('Section En cours : pas de bouton attestation si statut != Present (Excuse)', function () {
    $asso = makeAssoAtt2();
    TenantContext::boot($asso);

    [$typeOp, $operation, $seancePas] = makeEnCoursOpAtt($asso);
    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = makeParticipantAtt($asso, $operation, $tiers);
    addPresence($seancePas, $participant, StatutPresence::Excuse->value);

    $html = Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertStatus(200)
        ->html();

    $absentUrl = PortailRoute::to('attestations.seance', $asso, [
        'operation' => $operation->id,
        'seance' => $seancePas->id,
    ]);

    expect($html)->not->toContain($absentUrl);
});
