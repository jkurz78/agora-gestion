<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;

/*
 * « Prêt à comptabiliser » a UNE définition : Reglement::aComptabiliser().
 * Le service s'en sert pour choisir quoi comptabiliser, la grille pour
 * afficher l'état des cases et compter le bouton. Ces tests épinglent
 * chacune des trois conditions.
 */

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);

    $this->operation = Operation::factory()->create();
    $this->seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
});

afterEach(function () {
    TenantContext::clear();
});

function reglementEtatTest(object $ctx, ?ModePaiement $mode, float $montant): Reglement
{
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $ctx->operation->id,
        'date_inscription' => now(),
    ]);

    return Reglement::create([
        'participant_id' => (int) $participant->id,
        'seance_id' => (int) $ctx->seance->id,
        'mode_paiement' => $mode?->value,
        'montant_prevu' => $montant,
    ]);
}

function transactionEtatTest(Reglement $reglement): Transaction
{
    return Transaction::create([
        'type' => 'recette',
        'date' => '2025-11-15',
        'libelle' => 'Règlement comptabilisé',
        'montant_total' => (float) $reglement->montant_prevu,
        'mode_paiement' => $reglement->mode_paiement?->value,
        'tiers_id' => (int) $reglement->participant->tiers_id,
        'compte_id' => (int) CompteBancaire::factory()->create()->id,
        'reglement_id' => (int) $reglement->id,
    ]);
}

it('retient un règlement avec montant, mode et sans transaction', function () {
    $pret = reglementEtatTest($this, ModePaiement::Cheque, 30.0);

    expect(Reglement::aComptabiliser()->pluck('id')->map(fn ($id) => (int) $id)->all())
        ->toBe([(int) $pret->id]);
});

it('écarte un règlement sans mode de paiement', function () {
    reglementEtatTest($this, null, 30.0);

    expect(Reglement::aComptabiliser()->count())->toBe(0);
});

it('écarte un règlement à 0 € ou à montant négatif', function () {
    $temoin = reglementEtatTest($this, ModePaiement::Cheque, 30.0);
    reglementEtatTest($this, ModePaiement::Cheque, 0.0);
    reglementEtatTest($this, ModePaiement::Cheque, -10.0);

    expect(Reglement::aComptabiliser()->pluck('id')->map(fn ($id) => (int) $id)->all())
        ->toBe([(int) $temoin->id]);
});

it('écarte un règlement déjà comptabilisé', function () {
    $reglement = reglementEtatTest($this, ModePaiement::Cheque, 30.0);
    transactionEtatTest($reglement);

    expect(Reglement::aComptabiliser()->count())->toBe(0);
});

it('retient de nouveau un règlement dont la transaction a été supprimée', function () {
    $reglement = reglementEtatTest($this, ModePaiement::Cheque, 30.0);
    transactionEtatTest($reglement)->delete();

    expect(Reglement::aComptabiliser()->count())->toBe(1);
});

it('estComptabilise suit la présence d\'une transaction non supprimée', function () {
    $reglement = reglementEtatTest($this, ModePaiement::Cheque, 30.0);
    expect($reglement->estComptabilise())->toBeFalse();

    $tx = transactionEtatTest($reglement);
    expect($reglement->fresh()->estComptabilise())->toBeTrue();

    $tx->delete();
    expect($reglement->fresh()->estComptabilise())->toBeFalse();
});

it('le verrou d\'un règlement comptabilisé ne dépend pas d\'une association active', function () {
    $reglement = reglementEtatTest($this, ModePaiement::Cheque, 30.0);
    transactionEtatTest($reglement);
    $reglement = $reglement->fresh();

    TenantContext::clear();

    expect($reglement->estComptabilise())->toBeTrue()
        ->and(Reglement::aComptabiliser()->whereKey($reglement->id)->exists())->toBeFalse();
});
