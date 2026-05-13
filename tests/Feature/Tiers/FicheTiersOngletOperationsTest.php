<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Livewire\Tiers\FicheTiers;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('n\'affiche pas l\'onglet Opérations si le tiers n\'a aucune participation', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::actingAs($this->user)
        ->test(FicheTiers::class, ['tiers' => $tiers])
        ->assertDontSeeHtml('?onglet=operations');
});

it('affiche l\'onglet Opérations avec compteur si tiers a des participations', function (): void {
    $tiers = Tiers::factory()->create();
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op1->id]);
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op2->id]);

    Livewire::actingAs($this->user)
        ->test(FicheTiers::class, ['tiers' => $tiers])
        ->assertSee('Opérations')
        ->assertSee('(2)');
});

it('charge le composant Operations via ?onglet=operations', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);

    $response = $this->actingAs($this->user)->get(route('tiers.show', $tiers->id).'?onglet=operations');
    $response->assertOk();
    $response->assertSee('Participation'); // depuis x-tiers.operations.section-card
});

// ── Phase 8 : compteur somme des 3 sections ──────────────────────────────────

it('onglet absent si tiers sans aucun lien (ni participation, ni référé, ni suit)', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::actingAs($this->user)
        ->test(FicheTiers::class, ['tiers' => $tiers])
        ->assertDontSeeHtml('?onglet=operations');
});

it('compteur reflète la somme des 3 sections (participation + a-referre + suit)', function (): void {
    $tiers = Tiers::factory()->create();
    $tiersB = Tiers::factory()->create();
    $tiersC = Tiers::factory()->create();
    $tiersD = Tiers::factory()->create();
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();
    $op3 = Operation::factory()->create();

    // 1 participation propre
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op1->id]);

    // 1 tiers distinct référé
    Participant::factory()->create(['tiers_id' => $tiersB->id, 'operation_id' => $op2->id, 'refere_par_id' => $tiers->id]);

    // 1 tiers distinct suivi en médecin
    Participant::factory()->create(['tiers_id' => $tiersC->id, 'operation_id' => $op3->id, 'medecin_tiers_id' => $tiers->id]);

    // compteur attendu = 1 + 1 + 1 = 3
    Livewire::actingAs($this->user)
        ->test(FicheTiers::class, ['tiers' => $tiers])
        ->assertSee('Opérations')
        ->assertSee('(3)');
});

it('tiers qui a uniquement référé d\'autres tiers → onglet visible avec compteur = nb distinct', function (): void {
    $referent = Tiers::factory()->create();
    $marie = Tiers::factory()->create(['prenom' => 'Marie', 'nom' => 'Blanc']);
    $paul = Tiers::factory()->create(['prenom' => 'Paul', 'nom' => 'Noir']);
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();
    $op3 = Operation::factory()->create();

    // Marie référée sur 3 opérations, Paul référé sur 1 opération → 2 tiers distincts
    Participant::factory()->create(['tiers_id' => $marie->id, 'operation_id' => $op1->id, 'refere_par_id' => $referent->id]);
    Participant::factory()->create(['tiers_id' => $marie->id, 'operation_id' => $op2->id, 'refere_par_id' => $referent->id]);
    Participant::factory()->create(['tiers_id' => $paul->id, 'operation_id' => $op3->id, 'refere_par_id' => $referent->id]);

    // Le référent n'a aucune participation propre
    Livewire::actingAs($this->user)
        ->test(FicheTiers::class, ['tiers' => $referent])
        ->assertSee('Opérations')
        ->assertSee('(2)'); // 2 tiers distincts référés
});

// ── Phase 7c : compteur inclut Encadrement ───────────────────────────────────

it('compteur d\'onglet inclut les opérations encadrées', function (): void {
    $tiers = Tiers::factory()->create();
    $tiersB = Tiers::factory()->create();
    $type = TypeOperation::factory()->create(['nom' => 'Formation']);
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();
    $op3 = Operation::factory()->create(['type_operation_id' => $type->id]);
    $op4 = Operation::factory()->create(['type_operation_id' => $type->id]);

    // 1 participation propre
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op1->id]);

    // 1 tiers distinct référé
    Participant::factory()->create(['tiers_id' => $tiersB->id, 'operation_id' => $op2->id, 'refere_par_id' => $tiers->id]);

    // 2 opérations encadrées (dépenses)
    $tr1 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense]);
    $tr2 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense]);
    TransactionLigne::factory()->create(['transaction_id' => $tr1->id, 'operation_id' => $op3->id, 'montant' => 100.00]);
    TransactionLigne::factory()->create(['transaction_id' => $tr2->id, 'operation_id' => $op4->id, 'montant' => 200.00]);

    // compteur attendu = 1 (part) + 1 (ref) + 0 (suit) + 2 (enc) = 4
    Livewire::actingAs($this->user)
        ->test(FicheTiers::class, ['tiers' => $tiers])
        ->assertSee('Opérations')
        ->assertSee('(4)');
});

it('tiers strictement intervenant (encadrement seul) → onglet visible', function (): void {
    $intervenant = Tiers::factory()->create();
    $type = TypeOperation::factory()->create(['nom' => 'Pilates']);
    $op = Operation::factory()->create(['type_operation_id' => $type->id]);

    // Aucune participation propre, aucun référé, aucun suivi
    // Seulement payé pour intervenir
    $tr = Transaction::factory()->create(['tiers_id' => $intervenant->id, 'type' => TypeTransaction::Depense]);
    TransactionLigne::factory()->create(['transaction_id' => $tr->id, 'operation_id' => $op->id, 'montant' => 300.00]);

    Livewire::actingAs($this->user)
        ->test(FicheTiers::class, ['tiers' => $intervenant])
        ->assertSeeHtml('?onglet=operations')
        ->assertSee('(1)');
});
