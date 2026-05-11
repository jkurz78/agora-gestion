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
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\TypeOperationTarif;
use App\Models\User;
use App\Services\Tiers\DTO\AReferreTimelineDTO;
use App\Services\Tiers\DTO\ParticipationsTimelineDTO;
use App\Services\Tiers\DTO\SuitTimelineDTO;
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
    $tr = Transaction::factory()->create(['tiers_id' => $tiers->id, 'reglement_id' => $regl->id, 'statut_reglement' => StatutReglement::Recu]);
    TransactionLigne::factory()->create(['transaction_id' => $tr->id, 'operation_id' => $op->id, 'montant' => 50.00]);

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

it('affiche la pastille Partiel pour statut partiel', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    $seances = Seance::factory()->count(2)->create(['operation_id' => $op->id]);
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);

    $reglEncaisse = Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seances[0]->id, 'montant_prevu' => 30]);
    $trEncaisse = Transaction::factory()->create(['tiers_id' => $tiers->id, 'reglement_id' => $reglEncaisse->id, 'statut_reglement' => StatutReglement::Recu]);
    TransactionLigne::factory()->create(['transaction_id' => $trEncaisse->id, 'operation_id' => $op->id, 'montant' => 30.00]);

    $reglEnAttente = Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seances[1]->id, 'montant_prevu' => 30]);
    Transaction::factory()->create(['tiers_id' => $tiers->id, 'reglement_id' => $reglEnAttente->id, 'statut_reglement' => StatutReglement::EnAttente]);

    Livewire::test(Operations::class, ['tiers' => $tiers])
        ->assertSee('Partiel');
});

it('affiche la pastille Non payé pour statut non_paye', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    $seance = Seance::factory()->create(['operation_id' => $op->id]);
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);

    $regl = Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seance->id, 'montant_prevu' => 50]);
    Transaction::factory()->create(['reglement_id' => $regl->id, 'statut_reglement' => StatutReglement::EnAttente]);

    Livewire::test(Operations::class, ['tiers' => $tiers])
        ->assertSee('Non payé');
});

// ── Phase 7b : section "A référé" ────────────────────────────────────────────

it('monte avec les 3 DTOs aReferre et suit', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(Operations::class, ['tiers' => $tiers])
        ->assertStatus(200)
        ->assertViewHas('aReferre', fn ($d) => $d instanceof AReferreTimelineDTO)
        ->assertViewHas('suit', fn ($d) => $d instanceof SuitTimelineDTO);
});

it('affiche la section "A référé" si tiers a référé', function (): void {
    $referent = Tiers::factory()->create();
    $tiersReferre = Tiers::factory()->create(['prenom' => 'Alice', 'nom' => 'Martin']);
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiersReferre->id, 'operation_id' => $op->id, 'refere_par_id' => $referent->id]);

    Livewire::test(Operations::class, ['tiers' => $referent])
        ->assertSee('A référé')
        ->assertSee('Alice MARTIN');
});

it('cache la section "A référé" si vide', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(Operations::class, ['tiers' => $tiers])
        ->assertDontSee('A référé');
});

it('affiche le lien vers le tiers référé', function (): void {
    $referent = Tiers::factory()->create();
    $tiersReferre = Tiers::factory()->create(['prenom' => 'Paul', 'nom' => 'Durand']);
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiersReferre->id, 'operation_id' => $op->id, 'refere_par_id' => $referent->id]);

    Livewire::test(Operations::class, ['tiers' => $referent])
        ->assertSee(route('tiers.show', $tiersReferre->id));
});

it('affiche 3 lignes si même tiers référé sur 3 opérations', function (): void {
    $referent = Tiers::factory()->create();
    $marie = Tiers::factory()->create(['prenom' => 'Marie', 'nom' => 'Curie']);
    $op1 = Operation::factory()->create(['nom' => 'Op Alpha']);
    $op2 = Operation::factory()->create(['nom' => 'Op Beta']);
    $op3 = Operation::factory()->create(['nom' => 'Op Gamma']);

    Participant::factory()->create(['tiers_id' => $marie->id, 'operation_id' => $op1->id, 'refere_par_id' => $referent->id]);
    Participant::factory()->create(['tiers_id' => $marie->id, 'operation_id' => $op2->id, 'refere_par_id' => $referent->id]);
    Participant::factory()->create(['tiers_id' => $marie->id, 'operation_id' => $op3->id, 'refere_par_id' => $referent->id]);

    Livewire::test(Operations::class, ['tiers' => $referent])
        ->assertSee('Op Alpha')
        ->assertSee('Op Beta')
        ->assertSee('Op Gamma');
});

// ── Phase 7b : section "Suit" ────────────────────────────────────────────────

it('affiche la section "Suit" si tiers suit des personnes', function (): void {
    $medecin = Tiers::factory()->create();
    $patient = Tiers::factory()->create(['prenom' => 'Jean', 'nom' => 'Valjean']);
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $patient->id, 'operation_id' => $op->id, 'medecin_tiers_id' => $medecin->id]);

    Livewire::test(Operations::class, ['tiers' => $medecin])
        ->assertSee('Suit')
        ->assertSee('Jean VALJEAN');
});

it('cache la section "Suit" si vide', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(Operations::class, ['tiers' => $tiers])
        ->assertDontSee('Suit');
});

it('affiche le badge Qualité Médecin', function (): void {
    $medecin = Tiers::factory()->create();
    $patient = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $patient->id, 'operation_id' => $op->id, 'medecin_tiers_id' => $medecin->id]);

    Livewire::test(Operations::class, ['tiers' => $medecin])
        ->assertSee('Médecin');
});

it('affiche le badge Qualité Thérapeute', function (): void {
    $therapeute = Tiers::factory()->create();
    $patient = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $patient->id, 'operation_id' => $op->id, 'therapeute_tiers_id' => $therapeute->id]);

    Livewire::test(Operations::class, ['tiers' => $therapeute])
        ->assertSee('Thérapeute');
});

it('affiche le badge HelloAsso dans la section A référé', function (): void {
    $referent = Tiers::factory()->create();
    $patient = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create([
        'tiers_id' => $patient->id,
        'operation_id' => $op->id,
        'refere_par_id' => $referent->id,
        'est_helloasso' => true,
    ]);

    Livewire::test(Operations::class, ['tiers' => $referent])
        ->assertSee('HelloAsso');
});

it('affiche le badge Archivée dans la section A référé si opération soft-deleted', function (): void {
    $referent = Tiers::factory()->create();
    $patient = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create([
        'tiers_id' => $patient->id,
        'operation_id' => $op->id,
        'refere_par_id' => $referent->id,
    ]);
    $op->delete();

    Livewire::test(Operations::class, ['tiers' => $referent])
        ->assertSee('Archivée');
});

it('affiche le badge HelloAsso dans la section Suit', function (): void {
    $medecin = Tiers::factory()->create();
    $patient = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create([
        'tiers_id' => $patient->id,
        'operation_id' => $op->id,
        'medecin_tiers_id' => $medecin->id,
        'est_helloasso' => true,
    ]);

    Livewire::test(Operations::class, ['tiers' => $medecin])
        ->assertSee('HelloAsso');
});

it('affiche le badge Archivée dans la section Suit si opération soft-deleted', function (): void {
    $medecin = Tiers::factory()->create();
    $patient = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create([
        'tiers_id' => $patient->id,
        'operation_id' => $op->id,
        'medecin_tiers_id' => $medecin->id,
    ]);
    $op->delete();

    Livewire::test(Operations::class, ['tiers' => $medecin])
        ->assertSee('Archivée');
});

it('affiche 2 lignes pour double rôle médecin+thérapeute sur même opération', function (): void {
    $suivi = Tiers::factory()->create();
    $patient = Tiers::factory()->create(['prenom' => 'Anne', 'nom' => 'Brun']);
    $op = Operation::factory()->create(['nom' => 'Op Double Role']);

    Participant::factory()->create([
        'tiers_id' => $patient->id,
        'operation_id' => $op->id,
        'medecin_tiers_id' => $suivi->id,
        'therapeute_tiers_id' => $suivi->id,
    ]);

    Livewire::test(Operations::class, ['tiers' => $suivi])
        ->assertSee('Médecin')
        ->assertSee('Thérapeute');
});
