<?php

use App\Models\Compte;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Models\User;
use App\Services\RapportService;

/** Somme récursive des montants d'une hiérarchie de rapport. */
function sommeHierarchie(array $noeuds): float
{
    $total = 0.0;
    foreach ($noeuds as $noeud) {
        $total += (float) ($noeud['montant'] ?? 0);
    }

    return round($total, 2);
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('laisse inchanges les totaux quand aucune contra-ligne n est affectee', function () {
    $compte = Compte::factory()->numero('741')->create();
    $opA = Operation::factory()->create();
    $opB = Operation::factory()->create();

    $tx = Transaction::factory()->asRecette()->create([
        'date' => '2025-11-10',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();

    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'montant' => 20000.00,
        'debit' => 0.00,
        'credit' => 20000.00,
        'operation_id' => $opA->id,
    ]);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $opA->id,
        'montant' => 15000.00,
    ]);
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $opB->id,
        'montant' => 5000.00,
    ]);

    $rapport = app(RapportService::class)->compteDeResultatOperations(
        2025,
        [(int) $opA->id, (int) $opB->id],
    );

    expect(sommeHierarchie($rapport['produits']))->toBe(20000.0);
});

it('rend un montant negatif pour un contra-produit eclate en affectations', function () {
    $compte = Compte::factory()->numero('709')->create(['intitule' => 'Gratuités accordées']);
    $opA = Operation::factory()->create();
    $opB = Operation::factory()->create();

    $tx = Transaction::factory()->asRecette()->create([
        'date' => '2025-11-12',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();

    // Contra-produit : classe 7 mouvementée au DÉBIT — elle réduit les produits.
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'montant' => 50.00,
        'debit' => 50.00,
        'credit' => 0.00,
        'operation_id' => $opA->id,
    ]);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $opA->id,
        'montant' => 30.00,
    ]);
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $opB->id,
        'montant' => 20.00,
    ]);

    $rapport = app(RapportService::class)->compteDeResultatOperations(
        2025,
        [(int) $opA->id, (int) $opB->id],
    );

    // −30 et −20 : la gratuité réduit les produits des deux opérations.
    expect(sommeHierarchie($rapport['produits']))->toBe(-50.0);
});

it('rend un montant negatif pour un rabais obtenu eclate en affectations', function () {
    $compte = Compte::factory()->numero('609')->create(['intitule' => 'Rabais obtenus']);
    $opA = Operation::factory()->create();

    $tx = Transaction::factory()->asDepense()->create([
        'date' => '2025-11-14',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();

    // Contra-charge : classe 6 mouvementée au CRÉDIT — elle réduit les charges.
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'montant' => 30.00,
        'debit' => 0.00,
        'credit' => 30.00,
        'operation_id' => $opA->id,
    ]);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $opA->id,
        'montant' => 30.00,
    ]);

    $rapport = app(RapportService::class)->compteDeResultatOperations(2025, [(int) $opA->id]);

    expect(sommeHierarchie($rapport['charges']))->toBe(-30.0);
});
