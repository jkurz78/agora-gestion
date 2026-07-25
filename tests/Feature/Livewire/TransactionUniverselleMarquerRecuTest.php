<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Livewire\TransactionUniverselle;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Services\ReglementOperationService;
use Carbon\Carbon;
use Livewire\Livewire;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();

    $typeOp = TypeOperation::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte706->id,
    ]);
    $this->operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Séance test',
    ]);
    $this->seance = Seance::create([
        'association_id' => $this->association->id,
        'operation_id' => $this->operation->id,
        'numero' => 1,
        'date' => '2025-12-01',
    ]);

    $this->service = app(ReglementOperationService::class);
    $this->date = Carbon::parse('2025-12-01');
});

// ---------------------------------------------------------------------------
// AC #9 — le règlement depuis Transactions ouvre la modale datée du poste tiers
// ---------------------------------------------------------------------------

it('[AC9] TransactionUniverselle::marquerRecu ouvre la modale datée sans générer T2', function () {
    // Arrange : T1 avec ligne 411 (cheque, montant 80)
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $participant = Participant::create([
        'association_id' => $this->association->id,
        'tiers_id' => (int) $tiers->id,
        'operation_id' => (int) $this->operation->id,
        'date_inscription' => now(),
    ]);
    Reglement::create([
        'participant_id' => (int) $participant->id,
        'seance_id' => (int) $this->seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 80.00,
    ]);

    $this->service->comptabiliserSeance($this->seance, (int) $this->compteBancaire->id, $this->date);

    $t1 = Transaction::where('statut_reglement', StatutReglement::EnAttente->value)->firstOrFail();

    $compte411 = compteSysteme('411');
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();
    expect($ligne411T1->lettrage_code)->toBeNull();

    // Act : le règlement doit être saisi dans la modale commune, à une date choisie.
    Livewire::test(TransactionUniverselle::class)
        ->call('marquerRecu', $t1->id)
        ->assertDispatched('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne411T1->id, exercice: 2025);

    expect(Transaction::count())->toBe(1)
        ->and($ligne411T1->fresh()->lettrage_code)->toBeNull();
});

// ---------------------------------------------------------------------------
// Guard : marquerRecu skip si statut déjà Recu
// ---------------------------------------------------------------------------

it('[AC9-guard] TransactionUniverselle::marquerRecu skip silencieux si statut != en_attente', function () {
    $txRecu = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'statut_reglement' => StatutReglement::Recu->value,
        'compte_id' => $this->compteBancaire->id,
    ]);

    Livewire::test(TransactionUniverselle::class)
        ->call('marquerRecu', $txRecu->id);

    // Statut inchangé
    $txRecu->refresh();
    expect($txRecu->statut_reglement)->toBe(StatutReglement::Recu);

    // Pas de T2 générée
    expect(Transaction::count())->toBe(1);
});
