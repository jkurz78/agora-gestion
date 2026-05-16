<?php

declare(strict_types=1);

use App\Enums\StatutPresence;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function makeAssoAttTest(): Association
{
    return Association::factory()->create(['slug' => 'asso-att-test']);
}

function makeOperationAttTest(Association $asso): array
{
    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id]);
    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Formation Test',
    ]);
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'association_id' => $asso->id,
        'date' => now()->subWeek()->toDateString(),
        'numero' => 1,
    ]);

    return [$operation, $seance];
}

function loginTiersAttTest(Association $asso, ?string $email = null): Tiers
{
    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => $email ?? 'alice-att@ex.org',
    ]);
    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    return $tiers;
}

function makeParticipantWithPresence(Association $asso, Operation $operation, Tiers $tiers, Seance $seance, string $statut = StatutPresence::Present->value): Participant
{
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'association_id' => $asso->id,
        'date_inscription' => now()->subMonth()->toDateString(),
    ]);
    Presence::create([
        'seance_id' => $seance->id,
        'participant_id' => $participant->id,
        'statut' => $statut,
    ]);

    return $participant;
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : attestation séance — succès (200 + PDF inline)
// ─────────────────────────────────────────────────────────────────────────────
it('GET attestations.seance retourne PDF inline avec Content-Type application/pdf', function () {
    $asso = makeAssoAttTest();
    TenantContext::boot($asso);

    [$operation, $seance] = makeOperationAttTest($asso);
    $tiers = loginTiersAttTest($asso);
    makeParticipantWithPresence($asso, $operation, $tiers, $seance);

    $url = route('portail.attestations.seance', [
        'association' => $asso->slug,
        'operation' => $operation->id,
        'seance' => $seance->id,
    ]);
    $response = $this->get($url);

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');

    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('inline');
    expect($disposition)->not->toContain('attachment');
    expect($disposition)->toContain('attestation');

    $body = $response->getContent();
    expect(substr((string) $body, 0, 4))->toBe('%PDF');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : attestation séance — sans présence Present → 403
// ─────────────────────────────────────────────────────────────────────────────
it('GET attestations.seance retourne 403 si pas de présence Present sur la séance', function () {
    $asso = makeAssoAttTest();
    TenantContext::boot($asso);

    [$operation, $seance] = makeOperationAttTest($asso);
    $tiers = loginTiersAttTest($asso);
    // Participant without presence record
    Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'association_id' => $asso->id,
        'date_inscription' => now()->subMonth()->toDateString(),
    ]);

    $url = route('portail.attestations.seance', [
        'association' => $asso->slug,
        'operation' => $operation->id,
        'seance' => $seance->id,
    ]);

    $this->get($url)->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : attestation séance — intrusion intra-asso (Alice → séance de Bob) → 403
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] Alice 403 GET attestations.seance avec la séance de Bob', function () {
    $asso = makeAssoAttTest();
    TenantContext::boot($asso);

    [$operation, $seance] = makeOperationAttTest($asso);
    $bob = Tiers::factory()->create(['association_id' => $asso->id, 'email' => 'bob-att@ex.org']);
    makeParticipantWithPresence($asso, $operation, $bob, $seance);

    $alice = loginTiersAttTest($asso);  // Alice n'a pas de participant sur cette opération

    $url = route('portail.attestations.seance', [
        'association' => $asso->slug,
        'operation' => $operation->id,
        'seance' => $seance->id,
    ]);

    $this->get($url)->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : attestation séance — cross-tenant → 404
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] cross-tenant attestations.seance — TenantScope retourne 404 pour séance asso B', function () {
    $assoA = makeAssoAttTest();
    $assoB = Association::factory()->create(['slug' => 'asso-b-att']);

    TenantContext::boot($assoB);
    $tiersB = Tiers::factory()->create(['association_id' => $assoB->id]);
    [$operationB, $seanceB] = makeOperationAttTest($assoB);
    makeParticipantWithPresence($assoB, $operationB, $tiersB, $seanceB);

    TenantContext::boot($assoA);
    $alice = loginTiersAttTest($assoA);

    // Alice tente d'accéder à une séance de asso B via le slug de asso A
    $url = route('portail.attestations.seance', [
        'association' => $assoA->slug,
        'operation' => $operationB->id,
        'seance' => $seanceB->id,
    ]);

    $this->get($url)->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : attestation recap — succès (200 + PDF inline)
// ─────────────────────────────────────────────────────────────────────────────
it('GET attestations.recap retourne PDF inline avec Content-Type application/pdf', function () {
    $asso = makeAssoAttTest();
    TenantContext::boot($asso);

    [$operation, $seance] = makeOperationAttTest($asso);
    $tiers = loginTiersAttTest($asso);
    $participant = makeParticipantWithPresence($asso, $operation, $tiers, $seance);

    $url = route('portail.attestations.recap', [
        'association' => $asso->slug,
        'operation' => $operation->id,
        'participant' => $participant->id,
    ]);
    $response = $this->get($url);

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');

    $body = $response->getContent();
    expect(substr((string) $body, 0, 4))->toBe('%PDF');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : attestation recap — intrusion intra-asso → 403
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] Alice 403 GET attestations.recap avec le participant de Bob', function () {
    $asso = makeAssoAttTest();
    TenantContext::boot($asso);

    [$operation, $seance] = makeOperationAttTest($asso);
    $bob = Tiers::factory()->create(['association_id' => $asso->id, 'email' => 'bob-att2@ex.org']);
    $bobParticipant = makeParticipantWithPresence($asso, $operation, $bob, $seance);

    loginTiersAttTest($asso);  // Alice connectée

    $url = route('portail.attestations.recap', [
        'association' => $asso->slug,
        'operation' => $operation->id,
        'participant' => $bobParticipant->id,
    ]);

    $this->get($url)->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7 : attestation recap — cross-tenant → 404
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] cross-tenant attestations.recap — participant asso B retourne 404 depuis asso A', function () {
    $assoA = makeAssoAttTest();
    $assoB = Association::factory()->create(['slug' => 'asso-b-recap']);

    TenantContext::boot($assoB);
    $tiersB = Tiers::factory()->create(['association_id' => $assoB->id]);
    [$operationB, $seanceB] = makeOperationAttTest($assoB);
    $participantB = makeParticipantWithPresence($assoB, $operationB, $tiersB, $seanceB);

    TenantContext::boot($assoA);
    loginTiersAttTest($assoA);

    $url = route('portail.attestations.recap', [
        'association' => $assoA->slug,
        'operation' => $operationB->id,
        'participant' => $participantB->id,
    ]);

    $this->get($url)->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 8 : Logger émis avec participant_id + tiers_id
// ─────────────────────────────────────────────────────────────────────────────
it('log portail.attestation.seance.telecharge est émis avec participant_id + tiers_id', function () {
    Log::spy();

    $asso = makeAssoAttTest();
    TenantContext::boot($asso);

    [$operation, $seance] = makeOperationAttTest($asso);
    $tiers = loginTiersAttTest($asso);
    $participant = makeParticipantWithPresence($asso, $operation, $tiers, $seance);

    $url = route('portail.attestations.seance', [
        'association' => $asso->slug,
        'operation' => $operation->id,
        'seance' => $seance->id,
    ]);
    $this->get($url)->assertStatus(200);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($participant, $tiers, $seance): bool {
            return $message === 'portail.attestation.seance.telecharge'
                && (int) $context['participant_id'] === (int) $participant->id
                && (int) $context['tiers_id'] === (int) $tiers->id
                && (int) $context['seance_id'] === (int) $seance->id;
        });
});

it('log portail.attestation.recap.telecharge est émis avec participant_id + tiers_id', function () {
    Log::spy();

    $asso = makeAssoAttTest();
    TenantContext::boot($asso);

    [$operation, $seance] = makeOperationAttTest($asso);
    $tiers = loginTiersAttTest($asso);
    $participant = makeParticipantWithPresence($asso, $operation, $tiers, $seance);

    $url = route('portail.attestations.recap', [
        'association' => $asso->slug,
        'operation' => $operation->id,
        'participant' => $participant->id,
    ]);
    $this->get($url)->assertStatus(200);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $message, array $context) use ($participant, $tiers): bool {
            return $message === 'portail.attestation.recap.telecharge'
                && (int) $context['participant_id'] === (int) $participant->id
                && (int) $context['tiers_id'] === (int) $tiers->id;
        });
});
