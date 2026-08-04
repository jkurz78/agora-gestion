<?php

declare(strict_types=1);

/**
 * Question ouverte avant d'assouplir le verrou « transaction réglée » sur le
 * compte d'une ligne : reclasser une cotisation vers un compte de don remet-il
 * en cause la qualité de membre du tiers ?
 *
 * Le chemin est déjà atteignable aujourd'hui sur une transaction RAPPROCHÉE
 * (assertLockedInvariants n'interdit pas le changement de compte de ligne).
 * Ce test constate le comportement réel de ce chemin.
 */

use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Adhesion;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $this->service = app(TransactionService::class);

    $this->compteCotisation = Compte::factory()->numero('751')->create([
        'association_id' => $this->association->id,
    ]);
    $this->compteCotisation->usages()->create(['usage' => UsageComptable::Cotisation->value]);

    $this->compteDon = Compte::factory()->numero('754')->create([
        'association_id' => $this->association->id,
    ]);
    $this->compteDon->usages()->create(['usage' => UsageComptable::Don->value]);
});

it('reclasser une cotisation rapprochée vers un compte de don conserve l’adhésion', function (): void {
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Cotisation',
        'montant_total' => '25.00',
        'mode_paiement' => null,   // créance : pas de T2, donc pas de lettrage 411
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ];

    $transaction = $this->service->create($data, [[
        'compte_id' => $this->compteCotisation->id,
        'montant' => '25.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]);

    // L'observer a créé l'adhésion depuis la ligne portant l'usage « cotisation ».
    $adhesion = Adhesion::where('transaction_id', $transaction->id)->sole();
    expect($adhesion->exists)->toBeTrue();

    expect($transaction->fresh()->aUnReglementTiers())->toBeFalse();

    // Rapprochée (pointée) mais non réglée : c'est la branche isLockedByRapprochement.
    $transaction->update(['statut_reglement' => StatutReglement::Pointe->value]);
    expect($transaction->fresh()->isLockedByRapprochement())->toBeTrue();

    $ventilation = $transaction->lignes()->ventilation()->sole();

    $this->service->update($transaction->fresh(), $data, [[
        'id' => (int) $ventilation->id,
        'compte_id' => (int) $this->compteDon->id,   // 751 → 754
        'montant' => $ventilation->montant,
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]);

    $ligneApres = $transaction->fresh()->lignes()->ventilation()->sole();

    expect((int) $ligneApres->compte_id)->toBe((int) $this->compteDon->id)
        ->and((string) $ligneApres->montant)->toBe((string) $ventilation->montant)
        // Le sens est recalculé : recette → crédit, jamais de débit sur un produit.
        ->and(round((float) $ligneApres->credit, 2))->toBe(25.00)
        ->and(round((float) $ligneApres->debit, 2))->toBe(0.00);

    // Le point qui décide : l'adhésion survit-elle ?
    expect(Adhesion::withTrashed()->where('transaction_id', $transaction->id)->count())->toBe(1)
        ->and(Adhesion::where('transaction_id', $transaction->id)->first()?->deleted_at)->toBeNull();

    $fresh = $transaction->fresh();
    expect(round((float) $fresh->lignes()->sum('debit'), 2))
        ->toBe(round((float) $fresh->lignes()->sum('credit'), 2));
});
