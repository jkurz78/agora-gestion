<?php

use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Famille;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\RapportService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->service = new RapportService;
    $this->user = User::factory()->create();
});

function rapportSvcLigne(Transaction $tx, Compte $compte, float $montant, ?int $operationId = null, ?int $seance = null): TransactionLigne
{
    $estDepense = $tx->type->value === 'depense';

    return TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
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
    $sc = Compte::factory()->depense()->numero('606')->create(['intitule' => 'Fournitures']);
    $depense = Transaction::factory()->asDepense()->create(['date' => '2025-11-15', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    rapportSvcLigne($depense, $sc, 150.00);
    rapportSvcLigne($depense, $sc, 50.00);

    $result = $this->service->compteDeResultat(2025);

    expect($result['charges'])->toHaveCount(1);
    $cat = $result['charges'][0];
    expect($cat['famille_nom'])->toBe('60 — 60');
    expect($cat['montant_n'])->toBe(200.0);
    expect($cat['montant_n1'])->toBeNull();
    expect($cat['budget'])->toBeNull();
    expect($cat['comptes'])->toHaveCount(1);
    expect($cat['comptes'][0]['compte_nom'])->toBe('Fournitures');
    expect($cat['comptes'][0]['montant_n'])->toBe(200.0);
});

it('compteDeResultat inclut montant_n1 depuis exercice précédent', function () {
    $sc = Compte::factory()->depense()->numero('613')->create(['intitule' => 'Location']);

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
    expect($result['charges'][0]['comptes'][0]['montant_n1'])->toBe(300.0);
});

it('compteDeResultat inclut le budget depuis budget_lines', function () {
    $sc = Compte::factory()->depense()->numero('614')->create(['intitule' => 'Salle']);
    BudgetLine::factory()->create(['compte_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 1000.00]);

    $depense = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    rapportSvcLigne($depense, $sc, 800.00);

    $result = $this->service->compteDeResultat(2025);

    expect($result['charges'][0]['budget'])->toBe(1000.0);
    expect($result['charges'][0]['comptes'][0]['budget'])->toBe(1000.0);
});

it('compteDeResultat inclut les dons dans les produits', function () {
    $sc = Compte::factory()->numero('754')->create(['intitule' => 'Dons manuels']);
    $recette = Transaction::factory()->asRecette()->create([
        'date' => '2025-11-01',
        'montant_total' => 500.00,
        'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    rapportSvcLigne($recette, $sc, 500.00);

    $result = $this->service->compteDeResultat(2025);

    expect($result['produits'])->toHaveCount(1);
    expect($result['produits'][0]['comptes'][0]['montant_n'])->toBe(500.0);
});

it('compteDeResultat inclut les cotisations dans les produits', function () {
    $sc = Compte::factory()->numero('756')->create(['intitule' => 'Adhésions']);
    $recette = Transaction::factory()->asRecette()->create([
        'date' => '2025-11-01',
        'montant_total' => 200.00,
        'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    rapportSvcLigne($recette, $sc, 200.00);

    $result = $this->service->compteDeResultat(2025);

    expect($result['produits'][0]['comptes'][0]['montant_n'])->toBe(200.0);
});

it('compteDeResultat trie familles et comptes par nom', function () {
    $tenantId = (int) TenantContext::currentId();
    Famille::create(['association_id' => $tenantId, 'code' => '61', 'nom' => 'Alpha']);
    Famille::create(['association_id' => $tenantId, 'code' => '62', 'nom' => 'Zèbre']);

    $sc2 = Compte::factory()->depense()->numero('611')->create(['intitule' => 'Zzz']);
    $sc1 = Compte::factory()->depense()->numero('612')->create(['intitule' => 'Aaa']);
    $sc3 = Compte::factory()->depense()->numero('621')->create(['intitule' => 'Mid']);

    $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    rapportSvcLigne($d, $sc1, 10.00);

    $d2 = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d2->lignes()->forceDelete();
    rapportSvcLigne($d2, $sc3, 10.00);

    $d3 = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d3->lignes()->forceDelete();
    rapportSvcLigne($d3, $sc2, 10.00);

    $result = $this->service->compteDeResultat(2025);

    $chargeLabels = collect($result['charges'])->pluck('famille_nom')->toArray();
    $alphaIdx = array_search('61 — Alpha', $chargeLabels);
    $zebreIdx = array_search('62 — Zèbre', $chargeLabels);
    expect($alphaIdx)->toBeLessThan($zebreIdx);

    $alphaEntry = collect($result['charges'])->firstWhere('famille_nom', '61 — Alpha');
    expect($alphaEntry['comptes'][0]['compte_nom'])->toBe('Aaa');
    expect($alphaEntry['comptes'][1]['compte_nom'])->toBe('Zzz');
});

// ── compteDeResultatOperations ────────────────────────────────────────────────

it('compteDeResultatOperations filtre par opérations et exclut les cotisations', function () {
    $op = Operation::factory()->create();
    $sc = Compte::factory()->depense()->numero('625')->create(['intitule' => 'Transport']);

    $depense = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();

    rapportSvcLigne($depense, $sc, 100.00, (int) $op->id);
    rapportSvcLigne($depense, $sc, 200.00, null);

    $scCot = Compte::factory()->pourCotisations()->numero('756')->create(['intitule' => 'Adhésions']);
    $recette = Transaction::factory()->asRecette()->create(['date' => '2025-10-01', 'montant_total' => 500.00, 'saisi_par' => $this->user->id]);
    $recette->lignes()->forceDelete();
    rapportSvcLigne($recette, $scCot, 500.00);

    $result = $this->service->compteDeResultatOperations(2025, [$op->id]);

    expect($result['charges'][0]['montant'])->toBe(100.0);
    expect($result['produits'])->toHaveCount(0);
});

it('compteDeResultatOperations retourne structure sans montant_n1 ni budget', function () {
    $op = Operation::factory()->create();
    $sc = Compte::factory()->depense()->numero('614')->create(['intitule' => 'Salle']);
    BudgetLine::factory()->create(['compte_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 999.00]);

    $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    rapportSvcLigne($d, $sc, 10.00, (int) $op->id);

    $result = $this->service->compteDeResultatOperations(2025, [$op->id]);

    $cat = $result['charges'][0];
    expect($cat)->not->toHaveKey('montant_n1');
    expect($cat)->not->toHaveKey('budget');
    expect($cat['comptes'][0])->not->toHaveKey('montant_n1');
    expect($cat['comptes'][0])->not->toHaveKey('budget');
});

it('compteDeResultatOperations avec parSeances regroupe par séance', function () {
    $op = Operation::factory()->create();
    $sc = Compte::factory()->depense()->numero('625')->create(['intitule' => 'Transport']);

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

    $sc0 = $cat['comptes'][0];
    expect($sc0['montant'])->toBe(150.0);
    expect($sc0['seances'][1])->toBe(100.0);
});

it('compteDeResultatOperations avec parTiers regroupe par tiers', function () {
    $op = Operation::factory()->create();
    $sc = Compte::factory()->depense()->numero('625')->create(['intitule' => 'Transport']);

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

    $sc0 = $cat['comptes'][0];
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
    $sc = Compte::factory()->depense()->numero('625')->create(['intitule' => 'Transport']);
    $tiers = Tiers::factory()->create(['type' => 'particulier', 'nom' => 'dupont', 'prenom' => 'Jean']);

    $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'tiers_id' => $tiers->id, 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    rapportSvcLigne($d, $sc, 75.00, (int) $op->id, 2);

    $result = $this->service->compteDeResultatOperations(2025, [$op->id], parSeances: true, parTiers: true);

    expect($result)->toHaveKey('seances');

    $sc0 = $result['charges'][0]['comptes'][0];
    expect($sc0)->toHaveKey('tiers');
    expect($sc0)->toHaveKey('seances');
    expect($sc0['montant'])->toBe(75.0);

    $t = $sc0['tiers'][0];
    expect($t)->toHaveKey('seances');
    expect($t)->toHaveKey('montant');
    expect($t['seances'][2])->toBe(75.0);
    expect($t['montant'])->toBe(75.0);
});

// ── operationsEligibles / normaliserOperations ────────────────────────────────

it('operationsEligibles délègue à OperationsEligiblesQuery pour l\'exercice demandé', function () {
    $op = Operation::factory()->create();
    $sc = Compte::factory()->depense()->numero('606')->create(['intitule' => 'Achats']);

    $tx = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $tx->lignes()->forceDelete();
    rapportSvcLigne($tx, $sc, 100.00, (int) $op->id);

    Operation::factory()->create(); // opération sans mouvement, ne doit pas apparaître

    expect($this->service->operationsEligibles(2025))->toBe([(int) $op->id])
        ->and($this->service->operationsEligibles(2024))->toBe([]);
});

it('normaliserOperations garde l\'id éligible et écarte un id inexistant', function () {
    $op = Operation::factory()->create();
    $sc = Compte::factory()->depense()->numero('606')->create(['intitule' => 'Achats']);

    $tx = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $tx->lignes()->forceDelete();
    rapportSvcLigne($tx, $sc, 100.00, (int) $op->id);

    expect($this->service->normaliserOperations([(string) $op->id, '999999', 'abc', '0'], 2025))
        ->toBe([(int) $op->id]);
});
