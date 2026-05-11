<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Livewire\Tiers\Onglets\Operations;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TypeOperation;
use App\Models\TypeOperationTarif;
use App\Models\User;
use App\Services\Tiers\DTO\ParticipationsTimelineDTO;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

// ── Phase 9 : composant monte ────────────────────────────────────────────────

it('monte avec un Tiers et expose participations', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);

    Livewire::test(Operations::class, ['tiers' => $tiers])
        ->assertStatus(200)
        ->assertViewHas('participations', fn ($p) => $p instanceof ParticipationsTimelineDTO && $p->totalCount === 1);
});

// ── Phase 10 : composant section-card + structure ────────────────────────────

it('rend une carte section "Participation" avec compteur', function (): void {
    $tiers = Tiers::factory()->create();
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();
    $op3 = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op1->id]);
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op2->id]);
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op3->id]);

    Livewire::test(Operations::class, ['tiers' => $tiers])
        ->assertSee('Participation')
        ->assertSee('3'); // badge compteur
});

it('n\'affiche aucune section si tiers sans participation', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(Operations::class, ['tiers' => $tiers])
        ->assertDontSee('Participation');
});

// ── Phase 11 : tableau colonnes ──────────────────────────────────────────────

it('rend les colonnes attendues du tableau participations', function (): void {
    $tiers = Tiers::factory()->create();
    $type = TypeOperation::factory()->create(['nom' => 'Yoga']);
    $op = Operation::factory()->create(['type_operation_id' => $type->id, 'nom' => 'Yoga saison 2025']);
    $tarif = TypeOperationTarif::factory()->create(['type_operation_id' => $type->id, 'libelle' => 'Plein', 'montant' => 150]);
    Participant::factory()->create([
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
        'type_operation_tarif_id' => $tarif->id,
    ]);

    Livewire::test(Operations::class, ['tiers' => $tiers])
        ->assertSee('Yoga saison 2025')
        ->assertSee('Yoga')
        ->assertSee('Plein')
        ->assertSee('150');
});

// ── Phase 11.2 : badges + pastilles ─────────────────────────────────────────

it('affiche le badge HelloAsso si est_helloasso=true', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create([
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
        'est_helloasso' => true,
    ]);

    Livewire::test(Operations::class, ['tiers' => $tiers])
        ->assertSee('HelloAsso');
});

it('affiche le badge Archivée si opération soft-deleted', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);
    $op->delete();

    Livewire::test(Operations::class, ['tiers' => $tiers])
        ->assertSee('Archivée');
});

it('affiche le badge Gratuit pour statut gratuit', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);
    // Aucun Reglement → W=0 → gratuit

    Livewire::test(Operations::class, ['tiers' => $tiers])
        ->assertSee('Gratuit');
});

it('affiche la pastille Soldé pour statut solde', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    $seance = Seance::factory()->create(['operation_id' => $op->id]);
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);
    $regl = Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seance->id, 'montant_prevu' => 50]);
    Transaction::factory()->create(['reglement_id' => $regl->id, 'statut_reglement' => StatutReglement::Recu]);

    Livewire::test(Operations::class, ['tiers' => $tiers])
        ->assertSee('Soldé');
});

it('affiche le lien vers le tiers référent si présent', function (): void {
    $tiers = Tiers::factory()->create();
    $referent = Tiers::factory()->create(['prenom' => 'Marie', 'nom' => 'Dupont']);
    $op = Operation::factory()->create();
    Participant::factory()->create([
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
        'refere_par_id' => $referent->id,
    ]);

    Livewire::test(Operations::class, ['tiers' => $tiers])
        ->assertSee('Marie DUPONT')
        ->assertSee(route('tiers.show', $referent->id));
});
