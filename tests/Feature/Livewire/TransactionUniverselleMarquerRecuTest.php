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
        'sous_categorie_id' => $this->sc706->id,
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
// AC #9 — Bug B : marquerRecu via TransactionUniverselle génère T2 + auto-lettrage 411
// ---------------------------------------------------------------------------

it('[AC9] TransactionUniverselle::marquerRecu génère T2 (portage D + 411 C) et auto-lettre la paire 411', function () {
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
    expect($ligne411T1->lettrage_code)->toBeNull(); // non lettrée avant l'action

    // Act : appel via Livewire (miroir de la correction Bug B)
    Livewire::test(TransactionUniverselle::class)
        ->call('marquerRecu', $t1->id);

    // Assert : statut_reglement dérivé — chèque reçu non remis = EnMain (chantier 4)
    $t1->refresh();
    expect($t1->statut_reglement)->toBe(StatutReglement::EnMain);

    // Assert : T2 générée (on a maintenant 2 transactions)
    expect(Transaction::count())->toBe(2);

    $t2 = Transaction::where('id', '!=', $t1->id)->firstOrFail();
    $lignesT2 = TransactionLigne::where('transaction_id', $t2->id)->get();
    expect($lignesT2)->toHaveCount(2);

    $compte5112 = compteSysteme('5112');

    // Portage 5112 D (chèque → placeholder)
    $lignePortage = $lignesT2->firstWhere('compte_id', $compte5112->id);
    expect($lignePortage)->not->toBeNull();
    expect((float) $lignePortage->debit)->toBe(80.0);
    expect((float) $lignePortage->credit)->toBe(0.0);
    expect($lignePortage->tiers_id)->toBeNull(); // FEC : pas de tiers sur 5x

    // 411 C tiers sur T2
    $ligne411T2 = $lignesT2->firstWhere('compte_id', $compte411->id);
    expect($ligne411T2)->not->toBeNull();
    expect((float) $ligne411T2->credit)->toBe(80.0);
    expect((float) $ligne411T2->debit)->toBe(0.0);
    expect($ligne411T2->tiers_id)->not->toBeNull();

    // Auto-lettrage : T1.ligne411 et T2.ligne411 partagent le même code
    $ligne411T1->refresh();
    $ligne411T2->refresh();
    expect($ligne411T1->lettrage_code)->not->toBeNull();
    expect($ligne411T1->lettrage_code)->toBe($ligne411T2->lettrage_code);
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
