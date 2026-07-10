<?php

use App\Models\Categorie;
use App\Models\Compte;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\BudgetService;

beforeEach(function () {
    $this->service = new BudgetService;
    $this->user = User::factory()->create();
});

/**
 * Compte de résultat matérialisé par SousCategorieCompteObserver depuis le
 * code_cerfa de la sous-catégorie (classe 6 ou 7).
 */
function budgetServiceTestCompte(string $codeCerfa, string $type): Compte
{
    $categorie = $type === 'depense'
        ? Categorie::factory()->depense()->create()
        : Categorie::factory()->recette()->create();

    $sc = SousCategorie::factory()->create([
        'categorie_id' => $categorie->id,
        'code_cerfa' => $codeCerfa,
    ]);

    return Compte::where('numero_pcg', $codeCerfa)
        ->where('association_id', $sc->association_id)
        ->firstOrFail();
}

/** Ligne de ventilation compte-first (dépense: débit, recette: crédit). */
function budgetServiceTestLigne(Transaction $tx, Compte $compte, float $montant): TransactionLigne
{
    $estDepense = $tx->type->value === 'depense';

    return TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => null,
        'montant' => $montant,
        'compte_id' => $compte->id,
        'debit' => $estDepense ? $montant : 0.0,
        'credit' => $estDepense ? 0.0 : $montant,
    ]);
}

it('computes realise for comptes de classe 6 (depense)', function () {
    $compte = budgetServiceTestCompte('606', 'depense');

    // Depense in exercice 2025 (Sept 2025 - Aug 2026)
    $depense = Transaction::factory()->asDepense()->create([
        'date' => '2025-11-15',
        'saisi_par' => $this->user->id,
    ]);
    $depense->lignes()->forceDelete();
    budgetServiceTestLigne($depense, $compte, 150.00);
    budgetServiceTestLigne($depense, $compte, 50.00);

    // Depense outside exercice 2025
    $depenseOut = Transaction::factory()->asDepense()->create([
        'date' => '2024-10-15',
        'saisi_par' => $this->user->id,
    ]);
    $depenseOut->lignes()->forceDelete();
    budgetServiceTestLigne($depenseOut, $compte, 300.00);

    $result = $this->service->realise((int) $compte->id, 2025);

    expect($result)->toBe(200.0);
});

it('computes realise for comptes de classe 7 (recette)', function () {
    $compte = budgetServiceTestCompte('706', 'recette');

    // Recette in exercice 2025
    $recette = Transaction::factory()->asRecette()->create([
        'date' => '2025-12-01',
        'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    budgetServiceTestLigne($recette, $compte, 500.00);

    $result = $this->service->realise((int) $compte->id, 2025);

    expect($result)->toBe(500.0);
});

it('returns 0 when no transactions', function () {
    $compte = budgetServiceTestCompte('616', 'depense');

    $result = $this->service->realise((int) $compte->id, 2025);

    expect($result)->toBe(0.0);
});
