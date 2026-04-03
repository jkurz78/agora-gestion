<?php

use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\RapportService;

beforeEach(function () {
    $this->service = new RapportService;
    $this->user = User::factory()->create();
    $this->depenseCat = Categorie::factory()->depense()->create(['nom' => 'Achats']);
    $this->recetteCat = Categorie::factory()->recette()->create(['nom' => 'Cotisations']);
});

// ── compteDeResultat ──────────────────────────────────────────────────────────

it('compteDeResultat retourne la hiérarchie catégorie/sous-catégorie pour N', function () {
    $sc = SousCategorie::factory()->create([
        'categorie_id' => $this->depenseCat->id,
        'nom' => 'Fournitures',
    ]);
    $depense = Transaction::factory()->asDepense()->create(['date' => '2025-11-15', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $depense->id, 'sous_categorie_id' => $sc->id, 'montant' => 150.00]);
    TransactionLigne::factory()->create(['transaction_id' => $depense->id, 'sous_categorie_id' => $sc->id, 'montant' => 50.00]);

    $result = $this->service->compteDeResultat(2025);

    expect($result['charges'])->toHaveCount(1);
    $cat = $result['charges'][0];
    expect($cat['label'])->toBe('Achats');
    expect($cat['montant_n'])->toBe(200.0);
    expect($cat['montant_n1'])->toBeNull();
    expect($cat['budget'])->toBeNull();
    expect($cat['sous_categories'])->toHaveCount(1);
    expect($cat['sous_categories'][0]['label'])->toBe('Fournitures');
    expect($cat['sous_categories'][0]['montant_n'])->toBe(200.0);
});

it('compteDeResultat inclut montant_n1 depuis exercice précédent', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Location']);

    // N-1 : exercice 2024 (sept 2024 - août 2025)
    $depenseN1 = Transaction::factory()->asDepense()->create(['date' => '2024-10-01', 'saisi_par' => $this->user->id]);
    $depenseN1->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $depenseN1->id, 'sous_categorie_id' => $sc->id, 'montant' => 300.00]);

    // N : exercice 2025 (sept 2025 - août 2026)
    $depenseN = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depenseN->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $depenseN->id, 'sous_categorie_id' => $sc->id, 'montant' => 350.00]);

    $result = $this->service->compteDeResultat(2025);

    expect($result['charges'][0]['montant_n'])->toBe(350.0);
    expect($result['charges'][0]['montant_n1'])->toBe(300.0);
    expect($result['charges'][0]['sous_categories'][0]['montant_n1'])->toBe(300.0);
});

it('compteDeResultat inclut le budget depuis budget_lines', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Salle']);
    BudgetLine::factory()->create(['sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 1000.00]);

    $depense = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $depense->id, 'sous_categorie_id' => $sc->id, 'montant' => 800.00]);

    $result = $this->service->compteDeResultat(2025);

    expect($result['charges'][0]['budget'])->toBe(1000.0);
    expect($result['charges'][0]['sous_categories'][0]['budget'])->toBe(1000.0);
});

it('compteDeResultat inclut les dons dans les produits', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->recetteCat->id, 'nom' => 'Dons manuels']);
    $recette = Transaction::factory()->asRecette()->create([
        'date' => '2025-11-01',
        'montant_total' => 500.00,
        'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $recette->id, 'sous_categorie_id' => $sc->id, 'montant' => 500.00]);

    $result = $this->service->compteDeResultat(2025);

    expect($result['produits'])->toHaveCount(1);
    expect($result['produits'][0]['sous_categories'][0]['montant_n'])->toBe(500.0);
});

it('compteDeResultat inclut les cotisations dans les produits', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->recetteCat->id, 'nom' => 'Adhésions']);
    $recette = Transaction::factory()->asRecette()->create([
        'date' => '2025-11-01',
        'montant_total' => 200.00,
        'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $recette->id, 'sous_categorie_id' => $sc->id, 'montant' => 200.00]);

    $result = $this->service->compteDeResultat(2025);

    expect($result['produits'][0]['sous_categories'][0]['montant_n'])->toBe(200.0);
});

it('compteDeResultat trie catégories et sous-catégories par nom', function () {
    $catB = Categorie::factory()->depense()->create(['nom' => 'Zèbre']);
    $catA = Categorie::factory()->depense()->create(['nom' => 'Alpha']);
    $sc2 = SousCategorie::factory()->create(['categorie_id' => $catA->id, 'nom' => 'Zzz']);
    $sc1 = SousCategorie::factory()->create(['categorie_id' => $catA->id, 'nom' => 'Aaa']);

    foreach ([$catA, $catB] as $cat) {
        $sc = $cat->id === $catA->id ? $sc1 : SousCategorie::factory()->create(['categorie_id' => $catB->id, 'nom' => 'Mid']);
        $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
        $d->lignes()->forceDelete();
        TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 10.00]);
    }
    // Also add sc2 data
    $d2 = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d2->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d2->id, 'sous_categorie_id' => $sc2->id, 'montant' => 10.00]);

    $result = $this->service->compteDeResultat(2025);

    $chargeLabels = collect($result['charges'])->pluck('label')->toArray();
    // Verify Alpha before Zèbre
    $alphaIdx = array_search('Alpha', $chargeLabels);
    $zebreIdx = array_search('Zèbre', $chargeLabels);
    expect($alphaIdx)->toBeLessThan($zebreIdx);

    // Sous-catégories de Alpha triées : Aaa avant Zzz
    $alphaEntry = collect($result['charges'])->firstWhere('label', 'Alpha');
    expect($alphaEntry['sous_categories'][0]['label'])->toBe('Aaa');
    expect($alphaEntry['sous_categories'][1]['label'])->toBe('Zzz');
});

// ── compteDeResultatOperations ────────────────────────────────────────────────

it('compteDeResultatOperations filtre par opérations et exclut les cotisations', function () {
    $op = Operation::factory()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Transport']);

    $depense = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();

    TransactionLigne::factory()->create(['transaction_id' => $depense->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 100.00]);
    TransactionLigne::factory()->create(['transaction_id' => $depense->id, 'sous_categorie_id' => $sc->id, 'operation_id' => null, 'montant' => 200.00]);

    // Cotisation via transaction (doit être exclue car sans operation_id)
    $scCot = SousCategorie::factory()->create(['categorie_id' => $this->recetteCat->id, 'nom' => 'Adhésions', 'pour_cotisations' => true]);
    $recette = Transaction::factory()->asRecette()->create(['date' => '2025-10-01', 'montant_total' => 500.00, 'saisi_par' => $this->user->id]);
    $recette->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $recette->id, 'sous_categorie_id' => $scCot->id, 'montant' => 500.00]);

    $result = $this->service->compteDeResultatOperations(2025, [$op->id]);

    expect($result['charges'][0]['montant'])->toBe(100.0);
    expect($result['produits'])->toHaveCount(0); // pas de recettes ni dons pour cette op
});

it('compteDeResultatOperations retourne structure sans montant_n1 ni budget', function () {
    $op = Operation::factory()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Salle']);
    BudgetLine::factory()->create(['sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 999.00]);

    $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 10.00]);

    $result = $this->service->compteDeResultatOperations(2025, [$op->id]);

    $cat = $result['charges'][0];
    expect($cat)->not->toHaveKey('montant_n1');
    expect($cat)->not->toHaveKey('budget');
    expect($cat['sous_categories'][0])->not->toHaveKey('montant_n1');
    expect($cat['sous_categories'][0])->not->toHaveKey('budget');
});

it('compteDeResultatOperations avec parSeances regroupe par séance', function () {
    $op = Operation::factory()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Transport']);

    $d1 = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d1->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d1->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => 1, 'montant' => 100.00]);

    $d2 = Transaction::factory()->asDepense()->create(['date' => '2025-10-02', 'saisi_par' => $this->user->id]);
    $d2->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d2->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => null, 'montant' => 50.00]);

    $result = $this->service->compteDeResultatOperations(2025, [$op->id], parSeances: true);

    expect($result)->toHaveKey('seances');
    expect($result['seances'])->toContain(0, 1);

    $cat = $result['charges'][0];
    expect($cat)->toHaveKey('total');
    expect($cat)->toHaveKey('seances');
    expect($cat['total'])->toBe(150.0);
    expect($cat['seances'][1])->toBe(100.0);
    expect($cat['seances'][0])->toBe(50.0);

    $sc0 = $cat['sous_categories'][0];
    expect($sc0['total'])->toBe(150.0);
    expect($sc0['seances'][1])->toBe(100.0);
});

it('compteDeResultatOperations avec parTiers regroupe par tiers', function () {
    $op = Operation::factory()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Transport']);

    $tiers1 = Tiers::factory()->create(['type' => 'particulier', 'nom' => 'dupont', 'prenom' => 'Jean']);
    $tiers2 = Tiers::factory()->entreprise()->create(['entreprise' => 'Martin SAS']);

    $d1 = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'tiers_id' => $tiers1->id, 'saisi_par' => $this->user->id]);
    $d1->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d1->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 100.00]);

    $d2 = Transaction::factory()->asDepense()->create(['date' => '2025-10-02', 'tiers_id' => $tiers2->id, 'saisi_par' => $this->user->id]);
    $d2->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d2->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 200.00]);

    $d3 = Transaction::factory()->asDepense()->create(['date' => '2025-10-03', 'tiers_id' => null, 'saisi_par' => $this->user->id]);
    $d3->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d3->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 50.00]);

    $result = $this->service->compteDeResultatOperations(2025, [$op->id], parTiers: true);

    expect($result)->not->toHaveKey('seances');

    $cat = $result['charges'][0];
    expect($cat['montant'])->toBe(350.0);

    $sc0 = $cat['sous_categories'][0];
    expect($sc0['montant'])->toBe(350.0);
    expect($sc0)->toHaveKey('tiers');
    expect($sc0['tiers'])->toHaveCount(3);

    $labels = collect($sc0['tiers'])->pluck('label')->all();
    expect($labels)->toContain('(sans tiers)');
    expect($labels)->toContain('Jean DUPONT');
    expect($labels)->toContain('Martin SAS');

    $tiersMap = collect($sc0['tiers'])->keyBy('label');
    expect($tiersMap['Jean DUPONT']['type'])->toBe('particulier');
    expect($tiersMap['Martin SAS']['type'])->toBe('entreprise');
    expect($tiersMap['(sans tiers)']['type'])->toBeNull();
});

it('compteDeResultatOperations avec parSeances et parTiers combinés', function () {
    $op = Operation::factory()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Transport']);
    $tiers = Tiers::factory()->create(['type' => 'particulier', 'nom' => 'dupont', 'prenom' => 'Jean']);

    $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'tiers_id' => $tiers->id, 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => 2, 'montant' => 75.00]);

    $result = $this->service->compteDeResultatOperations(2025, [$op->id], parSeances: true, parTiers: true);

    expect($result)->toHaveKey('seances');

    $sc0 = $result['charges'][0]['sous_categories'][0];
    expect($sc0)->toHaveKey('tiers');
    expect($sc0)->toHaveKey('seances');
    expect($sc0['total'])->toBe(75.0);

    $t = $sc0['tiers'][0];
    expect($t)->toHaveKey('seances');
    expect($t)->toHaveKey('total');
    expect($t['seances'][2])->toBe(75.0);
    expect($t['total'])->toBe(75.0);
});
