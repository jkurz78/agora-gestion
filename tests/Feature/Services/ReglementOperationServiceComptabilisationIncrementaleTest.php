<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
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

/*
 * Une séance se comptabilise en plusieurs fois : un participant qui a annoncé
 * un virement pas encore arrivé n'a pas de mode de paiement, il attend le
 * passage suivant. Le service ne prend que les règlements prêts
 * (Reglement::aComptabiliser()) et ne recrée jamais une transaction existante.
 */

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();

    $typeOp = TypeOperation::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte706->id,
    ]);
    $this->operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Atelier incrémental',
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

function reglementIncrementalTest(object $ctx, ?ModePaiement $mode, float $montant = 50.0): Reglement
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
        'mode_paiement' => $mode?->value,
        'montant_prevu' => $montant,
    ]);
}

function comptabiliserIncrementalTest(object $ctx): void
{
    $ctx->service->comptabiliserSeance($ctx->seance, (int) $ctx->compteBancaire->id, $ctx->date);
}

it('ne comptabilise que les règlements dont le mode est renseigné', function () {
    $avecMode = reglementIncrementalTest($this, ModePaiement::Cheque);
    $sansMode = reglementIncrementalTest($this, null);

    comptabiliserIncrementalTest($this);

    expect($avecMode->fresh()->estComptabilise())->toBeTrue()
        ->and($sansMode->fresh()->estComptabilise())->toBeFalse()
        ->and(Transaction::whereNotNull('reglement_id')->count())->toBe(1);
});

it('ignore un règlement à 0 €', function () {
    reglementIncrementalTest($this, ModePaiement::Cheque, 0.0);

    comptabiliserIncrementalTest($this);

    expect(Transaction::whereNotNull('reglement_id')->count())->toBe(0);
});

it('comptabilise au second passage le règlement dont le mode a été renseigné entre-temps', function () {
    $premier = reglementIncrementalTest($this, ModePaiement::Cheque);
    $tardif = reglementIncrementalTest($this, null);

    comptabiliserIncrementalTest($this);
    $tardif->update(['mode_paiement' => ModePaiement::Virement->value]);
    comptabiliserIncrementalTest($this);

    expect(Transaction::where('reglement_id', (int) $premier->id)->count())->toBe(1)
        ->and(Transaction::where('reglement_id', (int) $tardif->id)->count())->toBe(1)
        ->and(Transaction::whereNotNull('reglement_id')->count())->toBe(2);
});

it('ne crée rien au second passage quand rien n\'a changé', function () {
    reglementIncrementalTest($this, ModePaiement::Cheque);
    reglementIncrementalTest($this, null);

    comptabiliserIncrementalTest($this);
    comptabiliserIncrementalTest($this);

    expect(Transaction::whereNotNull('reglement_id')->count())->toBe(1);
});

it('comptabilise de nouveau un règlement dont la transaction a été supprimée', function () {
    $reglement = reglementIncrementalTest($this, ModePaiement::Especes);

    comptabiliserIncrementalTest($this);
    Transaction::where('reglement_id', (int) $reglement->id)->sole()->delete();
    comptabiliserIncrementalTest($this);

    expect(Transaction::where('reglement_id', (int) $reglement->id)->count())->toBe(1)
        ->and(Transaction::withTrashed()->where('reglement_id', (int) $reglement->id)->count())->toBe(2);
});
