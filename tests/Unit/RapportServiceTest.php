<?php

use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\Cotisation;
use App\Models\Don;
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
    TransactionLigne::factory()->create(['transaction_id' =>$depense->id, 'sous_categorie_id' => $sc->id, 'montant' => 150.00]);
    TransactionLigne::factory()->create(['transaction_id' =>$depense->id, 'sous_categorie_id' => $sc->id, 'montant' => 50.00]);

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
    TransactionLigne::factory()->create(['transaction_id' =>$depenseN1->id, 'sous_categorie_id' => $sc->id, 'montant' => 300.00]);

    // N : exercice 2025 (sept 2025 - août 2026)
    $depenseN = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depenseN->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' =>$depenseN->id, 'sous_categorie_id' => $sc->id, 'montant' => 350.00]);

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
    TransactionLigne::factory()->create(['transaction_id' =>$depense->id, 'sous_categorie_id' => $sc->id, 'montant' => 800.00]);

    $result = $this->service->compteDeResultat(2025);

    expect($result['charges'][0]['budget'])->toBe(1000.0);
    expect($result['charges'][0]['sous_categories'][0]['budget'])->toBe(1000.0);
});

it('compteDeResultat inclut les dons dans les produits', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->recetteCat->id, 'nom' => 'Dons manuels']);
    $tiers = Tiers::factory()->create();
    Don::factory()->create([
        'sous_categorie_id' => $sc->id,
        'date' => '2025-11-01',
        'montant' => 500.00,
        'tiers_id' => $tiers->id,
        'saisi_par' => $this->user->id,
    ]);

    $result = $this->service->compteDeResultat(2025);

    expect($result['produits'])->toHaveCount(1);
    expect($result['produits'][0]['sous_categories'][0]['montant_n'])->toBe(500.0);
});

it('compteDeResultat inclut les cotisations dans les produits', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->recetteCat->id, 'nom' => 'Adhésions']);
    $tiers = Tiers::factory()->create();
    Cotisation::factory()->create([
        'sous_categorie_id' => $sc->id,
        'exercice' => 2025,
        'montant' => 200.00,
        'tiers_id' => $tiers->id,
    ]);

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
        TransactionLigne::factory()->create(['transaction_id' =>$d->id, 'sous_categorie_id' => $sc->id, 'montant' => 10.00]);
    }
    // Also add sc2 data
    $d2 = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d2->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' =>$d2->id, 'sous_categorie_id' => $sc2->id, 'montant' => 10.00]);

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

    TransactionLigne::factory()->create(['transaction_id' =>$depense->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 100.00]);
    TransactionLigne::factory()->create(['transaction_id' =>$depense->id, 'sous_categorie_id' => $sc->id, 'operation_id' => null, 'montant' => 200.00]);

    // Cotisation (doit être exclue)
    $scCot = SousCategorie::factory()->create(['categorie_id' => $this->recetteCat->id, 'nom' => 'Adhésions']);
    $tiers = Tiers::factory()->create();
    Cotisation::factory()->create(['sous_categorie_id' => $scCot->id, 'exercice' => 2025, 'montant' => 500.00, 'tiers_id' => $tiers->id]);

    $result = $this->service->compteDeResultatOperations(2025, [$op->id]);

    expect($result['charges'][0]['montant'])->toBe(100.0);
    expect($result['produits'])->toHaveCount(0); // pas de recettes ni dons pour cette op
});

it('compteDeResultatOperations retourne structure sans montant_n1 ni budget', function () {
    $op = Operation::factory()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Salle']);
    BudgetLine::factory()->create(['sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 999.00]);

    $depense = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' =>$depense->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 100.00]);

    $result = $this->service->compteDeResultatOperations(2025, [$op->id]);

    $sc0 = $result['charges'][0]['sous_categories'][0];
    expect($sc0)->not->toHaveKey('montant_n1');
    expect($sc0)->not->toHaveKey('budget');
    expect($sc0['montant'])->toBe(100.0);
});

// ── rapportSeances ────────────────────────────────────────────────────────────

it('rapportSeances retourne hiérarchie catégorie/sous-catégorie avec colonnes séances', function () {
    $op = Operation::factory()->withSeances(2)->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Location']);

    $depense = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' =>$depense->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => 1, 'montant' => 100.00]);
    TransactionLigne::factory()->create(['transaction_id' =>$depense->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => 2, 'montant' => 150.00]);

    $result = $this->service->rapportSeances(2025, [$op->id]);

    expect($result['seances'])->toBe([1, 2]);
    expect($result['charges'])->toHaveCount(1);
    $cat = $result['charges'][0];
    expect($cat['label'])->toBe('Achats');
    expect($cat['total'])->toBe(250.0);
    $sc0 = $cat['sous_categories'][0];
    expect($sc0['seances'][1])->toBe(100.0);
    expect($sc0['seances'][2])->toBe(150.0);
    expect($sc0['total'])->toBe(250.0);
});

it('rapportSeances agrège plusieurs opérations par numéro de séance', function () {
    $op1 = Operation::factory()->withSeances(2)->create();
    $op2 = Operation::factory()->withSeances(2)->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Salle']);

    foreach ([$op1, $op2] as $op) {
        $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
        $d->lignes()->forceDelete();
        TransactionLigne::factory()->create(['transaction_id' =>$d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => 1, 'montant' => 100.00]);
    }

    $result = $this->service->rapportSeances(2025, [$op1->id, $op2->id]);

    expect($result['charges'][0]['sous_categories'][0]['seances'][1])->toBe(200.0);
});

it('rapportSeances exclut lignes sans seance', function () {
    $op = Operation::factory()->withSeances(1)->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Divers']);

    $depense = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' =>$depense->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => null, 'montant' => 500.00]);

    $result = $this->service->rapportSeances(2025, [$op->id]);

    expect($result['charges'])->toHaveCount(0);
});

// ── toCsv ─────────────────────────────────────────────────────────────────────

it('génère un CSV valide avec séparateur point-virgule', function () {
    $csv = $this->service->toCsv([['Charge', 'Cat', 'Sous-cat', '100,00']], ['Type', 'Catégorie', 'Sous-catégorie', 'Montant']);
    expect($csv)->toContain('Type;Catégorie;Sous-catégorie;Montant');
    expect($csv)->toContain('Charge;Cat;Sous-cat;');
});
