<?php

use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\Famille;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\RapportService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->service = new RapportService;
    $this->user = User::factory()->create();
    $this->depenseCat = Categorie::factory()->depense()->create(['nom' => 'Achats']);
    $this->recetteCat = Categorie::factory()->recette()->create(['nom' => 'Cotisations']);
});

/**
 * Ligne de ventilation compte-first : compte résolu depuis le code_cerfa de la
 * sous-catégorie (matérialisé par SousCategorieCompteObserver), debit/credit
 * posés selon le type de la transaction (dépense: débit, recette: crédit).
 */
function rapportSvcLigne(Transaction $tx, SousCategorie $sc, float $montant, ?int $operationId = null, ?int $seance = null): TransactionLigne
{
    $compte = Compte::where('numero_pcg', $sc->code_cerfa)
        ->where('association_id', $sc->association_id)
        ->firstOrFail();

    $estDepense = $tx->type->value === 'depense';

    return TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'operation_id' => $operationId,
        'seance' => $seance,
        'montant' => $montant,
        'compte_id' => $compte->id,
        'debit' => $estDepense ? $montant : 0.0,
        'credit' => $estDepense ? 0.0 : $montant,
    ]);
}

// ── compteDeResultat ──────────────────────────────────────────────────────────

it('compteDeResultat retourne la hiérarchie famille/compte pour N', function () {
    // DC-4 : le regroupement de 1er niveau est la famille (préfixe 2 chiffres du
    // numero_pcg), pas la catégorie. code_cerfa déclenche la matérialisation
    // Compte (intitulé = nom de la sous-catégorie) + Famille de secours (nom = code).
    $sc = SousCategorie::factory()->create([
        'categorie_id' => $this->depenseCat->id,
        'nom' => 'Fournitures',
        'code_cerfa' => '606',
    ]);
    $depense = Transaction::factory()->asDepense()->create(['date' => '2025-11-15', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    rapportSvcLigne($depense, $sc, 150.00);
    rapportSvcLigne($depense, $sc, 50.00);

    $result = $this->service->compteDeResultat(2025);

    expect($result['charges'])->toHaveCount(1);
    $cat = $result['charges'][0];
    expect($cat['label'])->toBe('60 — 60');
    expect($cat['montant_n'])->toBe(200.0);
    expect($cat['montant_n1'])->toBeNull();
    expect($cat['budget'])->toBeNull();
    expect($cat['sous_categories'])->toHaveCount(1);
    expect($cat['sous_categories'][0]['label'])->toBe('Fournitures');
    expect($cat['sous_categories'][0]['montant_n'])->toBe(200.0);
});

it('compteDeResultat inclut montant_n1 depuis exercice précédent', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Location', 'code_cerfa' => '613']);

    // N-1 : exercice 2024 (sept 2024 - août 2025)
    $depenseN1 = Transaction::factory()->asDepense()->create(['date' => '2024-10-01', 'saisi_par' => $this->user->id]);
    $depenseN1->lignes()->forceDelete();
    rapportSvcLigne($depenseN1, $sc, 300.00);

    // N : exercice 2025 (sept 2025 - août 2026)
    $depenseN = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depenseN->lignes()->forceDelete();
    rapportSvcLigne($depenseN, $sc, 350.00);

    $result = $this->service->compteDeResultat(2025);

    expect($result['charges'][0]['montant_n'])->toBe(350.0);
    expect($result['charges'][0]['montant_n1'])->toBe(300.0);
    expect($result['charges'][0]['sous_categories'][0]['montant_n1'])->toBe(300.0);
});

it('compteDeResultat inclut le budget depuis budget_lines', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Salle', 'code_cerfa' => '614']);
    BudgetLine::factory()->create(['sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 1000.00]);

    $depense = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    rapportSvcLigne($depense, $sc, 800.00);

    $result = $this->service->compteDeResultat(2025);

    expect($result['charges'][0]['budget'])->toBe(1000.0);
    expect($result['charges'][0]['sous_categories'][0]['budget'])->toBe(1000.0);
});

it('compteDeResultat inclut les dons dans les produits', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->recetteCat->id, 'nom' => 'Dons manuels', 'code_cerfa' => '754']);
    $recette = Transaction::factory()->asRecette()->create([
        'date' => '2025-11-01',
        'montant_total' => 500.00,
        'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    rapportSvcLigne($recette, $sc, 500.00);

    $result = $this->service->compteDeResultat(2025);

    expect($result['produits'])->toHaveCount(1);
    expect($result['produits'][0]['sous_categories'][0]['montant_n'])->toBe(500.0);
});

it('compteDeResultat inclut les cotisations dans les produits', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->recetteCat->id, 'nom' => 'Adhésions', 'code_cerfa' => '756']);
    $recette = Transaction::factory()->asRecette()->create([
        'date' => '2025-11-01',
        'montant_total' => 200.00,
        'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    rapportSvcLigne($recette, $sc, 200.00);

    $result = $this->service->compteDeResultat(2025);

    expect($result['produits'][0]['sous_categories'][0]['montant_n'])->toBe(200.0);
});

it('compteDeResultat trie familles et comptes par nom', function () {
    // DC-4 : le regroupement de 1er niveau est la famille (préfixe 2 chiffres du numero_pcg).
    // On nomme explicitement 2 familles ('Alpha' code 61, 'Zèbre' code 62) pour contrôler
    // le tri, et on rattache chaque sous-catégorie via code_cerfa à l'une ou l'autre.
    $tenantId = (int) TenantContext::currentId();
    Famille::create(['association_id' => $tenantId, 'code' => '61', 'nom' => 'Alpha']);
    Famille::create(['association_id' => $tenantId, 'code' => '62', 'nom' => 'Zèbre']);

    $catB = Categorie::factory()->depense()->create(['nom' => 'Zèbre']);
    $catA = Categorie::factory()->depense()->create(['nom' => 'Alpha']);
    $sc2 = SousCategorie::factory()->create(['categorie_id' => $catA->id, 'nom' => 'Zzz', 'code_cerfa' => '611']);
    $sc1 = SousCategorie::factory()->create(['categorie_id' => $catA->id, 'nom' => 'Aaa', 'code_cerfa' => '612']);

    foreach ([$catA, $catB] as $cat) {
        $sc = $cat->id === $catA->id ? $sc1 : SousCategorie::factory()->create(['categorie_id' => $catB->id, 'nom' => 'Mid', 'code_cerfa' => '621']);
        $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
        $d->lignes()->forceDelete();
        rapportSvcLigne($d, $sc, 10.00);
    }
    // Also add sc2 data
    $d2 = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d2->lignes()->forceDelete();
    rapportSvcLigne($d2, $sc2, 10.00);

    $result = $this->service->compteDeResultat(2025);

    $chargeLabels = collect($result['charges'])->pluck('label')->toArray();
    // Verify Alpha before Zèbre
    $alphaIdx = array_search('61 — Alpha', $chargeLabels);
    $zebreIdx = array_search('62 — Zèbre', $chargeLabels);
    expect($alphaIdx)->toBeLessThan($zebreIdx);

    // Sous-catégories (comptes) de la famille Alpha triées : Aaa avant Zzz
    $alphaEntry = collect($result['charges'])->firstWhere('label', '61 — Alpha');
    expect($alphaEntry['sous_categories'][0]['label'])->toBe('Aaa');
    expect($alphaEntry['sous_categories'][1]['label'])->toBe('Zzz');
});

// ── compteDeResultatOperations ────────────────────────────────────────────────

it('compteDeResultatOperations filtre par opérations et exclut les cotisations', function () {
    $op = Operation::factory()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Transport', 'code_cerfa' => '625']);

    $depense = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();

    rapportSvcLigne($depense, $sc, 100.00, (int) $op->id);
    rapportSvcLigne($depense, $sc, 200.00, null);

    // Cotisation via transaction (doit être exclue car sans operation_id)
    $scCot = SousCategorie::factory()->pourCotisations()->create(['categorie_id' => $this->recetteCat->id, 'nom' => 'Adhésions', 'code_cerfa' => '756']);
    $recette = Transaction::factory()->asRecette()->create(['date' => '2025-10-01', 'montant_total' => 500.00, 'saisi_par' => $this->user->id]);
    $recette->lignes()->forceDelete();
    rapportSvcLigne($recette, $scCot, 500.00);

    $result = $this->service->compteDeResultatOperations(2025, [$op->id]);

    expect($result['charges'][0]['montant'])->toBe(100.0);
    expect($result['produits'])->toHaveCount(0); // pas de recettes ni dons pour cette op
});

it('compteDeResultatOperations retourne structure sans montant_n1 ni budget', function () {
    $op = Operation::factory()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Salle', 'code_cerfa' => '614']);
    BudgetLine::factory()->create(['sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 999.00]);

    $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    rapportSvcLigne($d, $sc, 10.00, (int) $op->id);

    $result = $this->service->compteDeResultatOperations(2025, [$op->id]);

    $cat = $result['charges'][0];
    expect($cat)->not->toHaveKey('montant_n1');
    expect($cat)->not->toHaveKey('budget');
    expect($cat['sous_categories'][0])->not->toHaveKey('montant_n1');
    expect($cat['sous_categories'][0])->not->toHaveKey('budget');
});

it('compteDeResultatOperations avec parSeances regroupe par séance', function () {
    $op = Operation::factory()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Transport', 'code_cerfa' => '625']);

    $d1 = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d1->lignes()->forceDelete();
    rapportSvcLigne($d1, $sc, 100.00, (int) $op->id, 1);

    $d2 = Transaction::factory()->asDepense()->create(['date' => '2025-10-02', 'saisi_par' => $this->user->id]);
    $d2->lignes()->forceDelete();
    rapportSvcLigne($d2, $sc, 50.00, (int) $op->id, null);

    $result = $this->service->compteDeResultatOperations(2025, [$op->id], parSeances: true);

    expect($result)->toHaveKey('seances');
    expect($result['seances'])->toContain(0, 1);

    $cat = $result['charges'][0];
    expect($cat)->toHaveKey('montant');
    expect($cat)->toHaveKey('seances');
    expect($cat['montant'])->toBe(150.0);
    expect($cat['seances'][1])->toBe(100.0);
    expect($cat['seances'][0])->toBe(50.0);

    $sc0 = $cat['sous_categories'][0];
    expect($sc0['montant'])->toBe(150.0);
    expect($sc0['seances'][1])->toBe(100.0);
});

it('compteDeResultatOperations avec parTiers regroupe par tiers', function () {
    $op = Operation::factory()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Transport', 'code_cerfa' => '625']);

    $tiers1 = Tiers::factory()->create(['type' => 'particulier', 'nom' => 'dupont', 'prenom' => 'Jean']);
    $tiers2 = Tiers::factory()->entreprise()->create(['entreprise' => 'Martin SAS']);

    $d1 = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'tiers_id' => $tiers1->id, 'saisi_par' => $this->user->id]);
    $d1->lignes()->forceDelete();
    rapportSvcLigne($d1, $sc, 100.00, (int) $op->id);

    $d2 = Transaction::factory()->asDepense()->create(['date' => '2025-10-02', 'tiers_id' => $tiers2->id, 'saisi_par' => $this->user->id]);
    $d2->lignes()->forceDelete();
    rapportSvcLigne($d2, $sc, 200.00, (int) $op->id);

    $d3 = Transaction::factory()->asDepense()->create(['date' => '2025-10-03', 'tiers_id' => null, 'saisi_par' => $this->user->id]);
    $d3->lignes()->forceDelete();
    rapportSvcLigne($d3, $sc, 50.00, (int) $op->id);

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
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Transport', 'code_cerfa' => '625']);
    $tiers = Tiers::factory()->create(['type' => 'particulier', 'nom' => 'dupont', 'prenom' => 'Jean']);

    $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'tiers_id' => $tiers->id, 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    rapportSvcLigne($d, $sc, 75.00, (int) $op->id, 2);

    $result = $this->service->compteDeResultatOperations(2025, [$op->id], parSeances: true, parTiers: true);

    expect($result)->toHaveKey('seances');

    $sc0 = $result['charges'][0]['sous_categories'][0];
    expect($sc0)->toHaveKey('tiers');
    expect($sc0)->toHaveKey('seances');
    expect($sc0['montant'])->toBe(75.0);

    $t = $sc0['tiers'][0];
    expect($t)->toHaveKey('seances');
    expect($t)->toHaveKey('montant');
    expect($t['seances'][2])->toBe(75.0);
    expect($t['montant'])->toBe(75.0);
});
