<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TypeOperation;
use App\Services\ReglementOperationService;
use Carbon\Carbon;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();

    // sc706 et compte706 exposés par setupPartieDoubleContext()
    $typeOp = TypeOperation::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte706->id,
    ]);
    $this->operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Formation Test',
    ]);
    $this->seance = Seance::create([
        'association_id' => $this->association->id,
        'operation_id' => $this->operation->id,
        'numero' => 1,
        'date' => '2025-11-15',
    ]);

    $this->service = app(ReglementOperationService::class);
    $this->date = Carbon::parse('2025-11-15');
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function creerReglementEquilibreeTest(object $ctx, float $montant = 100.00): Reglement
{
    $tiers = Tiers::factory()->create(['association_id' => $ctx->association->id]);
    $participant = Participant::create([
        'association_id' => $ctx->association->id,
        'tiers_id' => (int) $tiers->id,
        'operation_id' => (int) $ctx->operation->id,
        'date_inscription' => now(),
    ]);

    return Reglement::create([
        'participant_id' => (int) $participant->id,
        'seance_id' => (int) $ctx->seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => $montant,
    ]);
}

// ---------------------------------------------------------------------------
// Scénario : après comptabiliserSeance avec PD active + sc706 mappée,
// la Transaction créée doit avoir equilibree = true
// ---------------------------------------------------------------------------

it('comptabiliserSeance avec PD active et sc706 mappée → Transaction.equilibree = true', function () {
    creerReglementEquilibreeTest($this, 120.00);

    $this->service->comptabiliserSeance($this->seance, (int) $this->compteBancaire->id, $this->date);

    $tx = Transaction::where('statut_reglement', StatutReglement::EnAttente->value)->firstOrFail();
    expect((bool) $tx->equilibree)->toBeTrue();
});

it('comptabiliserSeance avec 2 règlements → les 2 Transactions ont equilibree = true', function () {
    creerReglementEquilibreeTest($this, 80.00);
    creerReglementEquilibreeTest($this, 60.00);

    $this->service->comptabiliserSeance($this->seance, (int) $this->compteBancaire->id, $this->date);

    $txs = Transaction::where('statut_reglement', StatutReglement::EnAttente->value)->get();
    expect($txs)->toHaveCount(2);

    foreach ($txs as $tx) {
        expect((bool) $tx->equilibree)->toBeTrue("{$tx->id} doit avoir equilibree = true");
    }
});
