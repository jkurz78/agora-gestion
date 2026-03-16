# Refonte Rapports — 3 onglets Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refondre la page `/rapports` : onglet 1 (Compte de résultat avec N-1/Budget/Barre), onglet 2 nouveau (CR par opération), onglet 3 (Rapport par séances hiérarchique).

**Architecture:** `RapportService` expose 3 méthodes publiques s'appuyant sur 5 helpers privés de requêtes SQL. Chaque onglet a son propre composant Livewire et sa vue Blade. Le `code_cerfa` disparaît — tout est groupé par catégorie/sous-catégorie.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 CDN, Pest PHP, MySQL via Sail

---

## Fichiers

| Fichier | Action | Rôle |
|---------|--------|------|
| `app/Services/RapportService.php` | Modifier | 3 méthodes publiques + 5 helpers privés |
| `app/Livewire/RapportCompteResultat.php` | Modifier | Supprime `$selectedOperationIds` |
| `app/Livewire/RapportCompteResultatOperations.php` | **Créer** | Composant onglet 2 |
| `app/Livewire/RapportSeances.php` | Modifier | `operation_id: int` → `selectedOperationIds: array` |
| `resources/views/livewire/rapport-compte-resultat.blade.php` | Modifier | Refonte totale |
| `resources/views/livewire/rapport-compte-resultat-operations.blade.php` | **Créer** | Vue onglet 2 |
| `resources/views/livewire/rapport-seances.blade.php` | Modifier | Refonte totale |
| `resources/views/rapports/index.blade.php` | Modifier | 3 onglets |
| `tests/Unit/RapportServiceTest.php` | Modifier | Mise à jour + nouveaux tests |
| `tests/Livewire/RapportCompteResultatTest.php` | Modifier | Mise à jour tests |
| `tests/Livewire/RapportSeancesTest.php` | Modifier | Mise à jour tests |
| `tests/Livewire/RapportCompteResultatOperationsTest.php` | **Créer** | Tests onglet 2 |

---

## Chunk 1 : Service Layer

### Task 1 : Refactorer `RapportService::compteDeResultat()`

La méthode perd son paramètre `$operationIds`, adopte la nouvelle signature de retour hiérarchique, et intègre N-1 et budget.

**Files:**
- Modify: `app/Services/RapportService.php`
- Modify: `tests/Unit/RapportServiceTest.php`

- [ ] **Step 1 : Mettre à jour les tests existants pour la nouvelle API**

Remplacer le contenu de `tests/Unit/RapportServiceTest.php` :

```php
<?php

use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\Don;
use App\Models\Operation;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
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
    $depense = Depense::factory()->create(['date' => '2025-11-15', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $depense->id, 'sous_categorie_id' => $sc->id, 'montant' => 150.00]);
    DepenseLigne::factory()->create(['depense_id' => $depense->id, 'sous_categorie_id' => $sc->id, 'montant' => 50.00]);

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
    $depenseN1 = Depense::factory()->create(['date' => '2024-10-01', 'saisi_par' => $this->user->id]);
    $depenseN1->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $depenseN1->id, 'sous_categorie_id' => $sc->id, 'montant' => 300.00]);

    // N : exercice 2025 (sept 2025 - août 2026)
    $depenseN = Depense::factory()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depenseN->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $depenseN->id, 'sous_categorie_id' => $sc->id, 'montant' => 350.00]);

    $result = $this->service->compteDeResultat(2025);

    expect($result['charges'][0]['montant_n'])->toBe(350.0);
    expect($result['charges'][0]['montant_n1'])->toBe(300.0);
    expect($result['charges'][0]['sous_categories'][0]['montant_n1'])->toBe(300.0);
});

it('compteDeResultat inclut le budget depuis budget_lines', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Salle']);
    BudgetLine::factory()->create(['sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 1000.00]);

    $depense = Depense::factory()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $depense->id, 'sous_categorie_id' => $sc->id, 'montant' => 800.00]);

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
        $d = Depense::factory()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
        $d->lignes()->forceDelete();
        DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 10.00]);
    }
    // Also add sc2 data
    $d2 = Depense::factory()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d2->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $d2->id, 'sous_categorie_id' => $sc2->id, 'montant' => 10.00]);

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

    $depense = Depense::factory()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();

    DepenseLigne::factory()->create(['depense_id' => $depense->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 100.00]);
    DepenseLigne::factory()->create(['depense_id' => $depense->id, 'sous_categorie_id' => $sc->id, 'operation_id' => null, 'montant' => 200.00]);

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

    $depense = Depense::factory()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $depense->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 100.00]);

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

    $depense = Depense::factory()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $depense->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => 1, 'montant' => 100.00]);
    DepenseLigne::factory()->create(['depense_id' => $depense->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => 2, 'montant' => 150.00]);

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
        $d = Depense::factory()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
        $d->lignes()->forceDelete();
        DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => 1, 'montant' => 100.00]);
    }

    $result = $this->service->rapportSeances(2025, [$op1->id, $op2->id]);

    expect($result['charges'][0]['sous_categories'][0]['seances'][1])->toBe(200.0);
});

it('rapportSeances exclut lignes sans seance', function () {
    $op = Operation::factory()->withSeances(1)->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->depenseCat->id, 'nom' => 'Divers']);

    $depense = Depense::factory()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $depense->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $depense->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => null, 'montant' => 500.00]);

    $result = $this->service->rapportSeances(2025, [$op->id]);

    expect($result['charges'])->toHaveCount(0);
});

// ── toCsv ─────────────────────────────────────────────────────────────────────

it('génère un CSV valide avec séparateur point-virgule', function () {
    $csv = $this->service->toCsv([['Charge', 'Cat', 'Sous-cat', '100,00']], ['Type', 'Catégorie', 'Sous-catégorie', 'Montant']);
    expect($csv)->toContain('Type;Catégorie;Sous-catégorie;Montant');
    expect($csv)->toContain('Charge;Cat;Sous-cat;');
});
```

- [ ] **Step 2 : Vérifier que les anciens tests échouent (nouvelle API attendue)**

```bash
./vendor/bin/sail artisan test tests/Unit/RapportServiceTest.php
```
Attendu : plusieurs FAIL (ancienne structure `code_cerfa` / signature `rapportSeances($id)`)

- [ ] **Step 3 : Réécrire `RapportService`**

Remplacer le contenu de `app/Services/RapportService.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cotisation;
use App\Models\DepenseLigne;
use App\Models\Don;
use App\Models\RecetteLigne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RapportService
{
    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Compte de résultat complet : hiérarchie catégorie/sous-catégorie avec N-1 et budget.
     * Pas de filtre opération.
     *
     * @return array{charges: list<array>, produits: list<array>}
     */
    public function compteDeResultat(int $exercice): array
    {
        [$startN, $endN]   = $this->exerciceDates($exercice);
        [$startN1, $endN1] = $this->exerciceDates($exercice - 1);

        $chargesN  = $this->fetchDepenseRows($startN, $endN);
        $chargesN1 = $this->fetchDepenseRows($startN1, $endN1);

        $produitsN  = $this->fetchProduitsRows($startN, $endN, $exercice);
        $produitsN1 = $this->fetchProduitsRows($startN1, $endN1, $exercice - 1);

        $budgetMap = $this->fetchBudgetMap($exercice);

        return [
            'charges' => $this->buildHierarchyFull($chargesN, $chargesN1, $budgetMap),
            'produits' => $this->buildHierarchyFull($produitsN, $produitsN1, $budgetMap),
        ];
    }

    /**
     * Compte de résultat filtré par opérations. Pas de N-1 ni budget. Cotisations exclues.
     *
     * @param  array<int>  $operationIds
     * @return array{charges: list<array>, produits: list<array>}
     */
    public function compteDeResultatOperations(int $exercice, array $operationIds): array
    {
        [$start, $end] = $this->exerciceDates($exercice);

        $charges = $this->fetchDepenseRows($start, $end, $operationIds);
        $produits = $this->fetchProduitsRows($start, $end, $exercice, $operationIds);

        return [
            'charges' => $this->buildHierarchySimple($charges),
            'produits' => $this->buildHierarchySimple($produits),
        ];
    }

    /**
     * Rapport par séances : hiérarchie catégorie/sous-catégorie avec une colonne par séance.
     *
     * @param  array<int>  $operationIds
     * @return array{seances: list<int>, charges: list<array>, produits: list<array>}
     */
    public function rapportSeances(int $exercice, array $operationIds): array
    {
        [$start, $end] = $this->exerciceDates($exercice);

        $chargeRows  = $this->fetchDepenseSeancesRows($start, $end, $operationIds);
        $produitsRows = $this->fetchProduitsSeancesRows($start, $end, $operationIds);

        $allSeances = collect($chargeRows)
            ->merge($produitsRows)
            ->pluck('seance')
            ->unique()
            ->sort()
            ->values()
            ->map(fn ($s) => (int) $s)
            ->all();

        return [
            'seances'  => $allSeances,
            'charges'  => $this->buildHierarchySeances($chargeRows, $allSeances),
            'produits' => $this->buildHierarchySeances($produitsRows, $allSeances),
        ];
    }

    /**
     * Génère un CSV avec séparateur point-virgule (convention française).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $headers
     */
    public function toCsv(array $rows, array $headers): string
    {
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers, ';');
        foreach ($rows as $row) {
            fputcsv($output, $row, ';');
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    // ── Private helpers — requêtes SQL ────────────────────────────────────────

    /** @return array{string, string} */
    private function exerciceDates(int $exercice): array
    {
        return ["{$exercice}-09-01", ($exercice + 1) . '-08-31'];
    }

    /**
     * Agrégation des dépenses par (catégorie, sous-catégorie).
     *
     * @param  array<int>|null  $operationIds  null = pas de filtre
     * @return Collection<int, object>
     */
    private function fetchDepenseRows(string $start, string $end, ?array $operationIds = null): Collection
    {
        $q = DepenseLigne::query()
            ->join('sous_categories as sc', 'depense_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('depenses as d', 'd.id', '=', 'depense_lignes.depense_id')
            ->whereNull('depense_lignes.deleted_at')
            ->whereNull('d.deleted_at')
            ->whereBetween('d.date', [$start, $end])
            ->select([
                'c.id as categorie_id',
                'c.nom as categorie_nom',
                'sc.id as sous_categorie_id',
                'sc.nom as sous_categorie_nom',
                DB::raw('SUM(depense_lignes.montant) as montant'),
            ])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');

        if ($operationIds !== null) {
            $q->whereIn('depense_lignes.operation_id', $operationIds);
        }

        return $q->get();
    }

    /**
     * Agrégation des produits (recettes + dons + cotisations si pas de filtre opération).
     *
     * @param  array<int>|null  $operationIds  null = pas de filtre, cotisations incluses
     * @return Collection<int, object>
     */
    private function fetchProduitsRows(string $start, string $end, int $exercice, ?array $operationIds = null): Collection
    {
        // Map intermédiaire keyed by sous_categorie_id
        /** @var array<int, array{categorie_id: int, categorie_nom: string, sous_categorie_id: int, sous_categorie_nom: string, montant: float}> */
        $map = [];

        $accumuler = function (Collection $rows) use (&$map): void {
            foreach ($rows as $row) {
                $scId = (int) $row->sous_categorie_id;
                if (isset($map[$scId])) {
                    $map[$scId]['montant'] += (float) $row->montant;
                } else {
                    $map[$scId] = [
                        'categorie_id'       => (int) $row->categorie_id,
                        'categorie_nom'      => $row->categorie_nom,
                        'sous_categorie_id'  => $scId,
                        'sous_categorie_nom' => $row->sous_categorie_nom,
                        'montant'            => (float) $row->montant,
                    ];
                }
            }
        };

        // Recettes
        $rq = RecetteLigne::query()
            ->join('sous_categories as sc', 'recette_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('recettes as r', 'r.id', '=', 'recette_lignes.recette_id')
            ->whereNull('recette_lignes.deleted_at')
            ->whereNull('r.deleted_at')
            ->whereBetween('r.date', [$start, $end])
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('SUM(recette_lignes.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');
        if ($operationIds !== null) {
            $rq->whereIn('recette_lignes.operation_id', $operationIds);
        }
        $accumuler($rq->get());

        // Dons (sous_categorie_id nullable → INNER JOIN exclut ceux sans sous-catégorie)
        $dq = Don::query()
            ->join('sous_categories as sc', 'dons.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->whereNull('dons.deleted_at')
            ->whereBetween('dons.date', [$start, $end])
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('SUM(dons.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');
        if ($operationIds !== null) {
            $dq->whereIn('dons.operation_id', $operationIds);
        }
        $accumuler($dq->get());

        // Cotisations — uniquement sans filtre opération (elles n'ont pas d'operation_id)
        if ($operationIds === null) {
            $cq = Cotisation::query()
                ->join('sous_categories as sc', 'cotisations.sous_categorie_id', '=', 'sc.id')
                ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
                ->whereNull('cotisations.deleted_at')
                ->where('cotisations.exercice', $exercice)
                ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('SUM(cotisations.montant) as montant')])
                ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');
            $accumuler($cq->get());
        }

        return collect(array_values($map))->map(fn ($row) => (object) $row);
    }

    /**
     * Agrégation des dépenses par (catégorie, sous-catégorie, séance).
     *
     * @param  array<int>  $operationIds
     * @return Collection<int, object>
     */
    private function fetchDepenseSeancesRows(string $start, string $end, array $operationIds): Collection
    {
        return DepenseLigne::query()
            ->join('sous_categories as sc', 'depense_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('depenses as d', 'd.id', '=', 'depense_lignes.depense_id')
            ->whereNull('depense_lignes.deleted_at')
            ->whereNull('d.deleted_at')
            ->whereBetween('d.date', [$start, $end])
            ->whereNotNull('depense_lignes.seance')
            ->whereIn('depense_lignes.operation_id', $operationIds)
            ->select([
                'c.id as categorie_id', 'c.nom as categorie_nom',
                'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom',
                'depense_lignes.seance',
                DB::raw('SUM(depense_lignes.montant) as montant'),
            ])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', 'depense_lignes.seance')
            ->get();
    }

    /**
     * Agrégation des produits par séance (recettes + dons ; cotisations exclues).
     *
     * @param  array<int>  $operationIds
     * @return Collection<int, object>
     */
    private function fetchProduitsSeancesRows(string $start, string $end, array $operationIds): Collection
    {
        /** @var array<string, array{categorie_id: int, categorie_nom: string, sous_categorie_id: int, sous_categorie_nom: string, seance: int, montant: float}> */
        $map = [];

        $accumuler = function (Collection $rows) use (&$map): void {
            foreach ($rows as $row) {
                $key = $row->sous_categorie_id . '_' . $row->seance;
                if (isset($map[$key])) {
                    $map[$key]['montant'] += (float) $row->montant;
                } else {
                    $map[$key] = [
                        'categorie_id'       => (int) $row->categorie_id,
                        'categorie_nom'      => $row->categorie_nom,
                        'sous_categorie_id'  => (int) $row->sous_categorie_id,
                        'sous_categorie_nom' => $row->sous_categorie_nom,
                        'seance'             => (int) $row->seance,
                        'montant'            => (float) $row->montant,
                    ];
                }
            }
        };

        // Recettes par séance
        $accumuler(RecetteLigne::query()
            ->join('sous_categories as sc', 'recette_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('recettes as r', 'r.id', '=', 'recette_lignes.recette_id')
            ->whereNull('recette_lignes.deleted_at')
            ->whereNull('r.deleted_at')
            ->whereBetween('r.date', [$start, $end])
            ->whereNotNull('recette_lignes.seance')
            ->whereIn('recette_lignes.operation_id', $operationIds)
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', 'recette_lignes.seance', DB::raw('SUM(recette_lignes.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', 'recette_lignes.seance')
            ->get());

        // Dons par séance
        $accumuler(Don::query()
            ->join('sous_categories as sc', 'dons.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->whereNull('dons.deleted_at')
            ->whereBetween('dons.date', [$start, $end])
            ->whereNotNull('dons.seance')
            ->whereIn('dons.operation_id', $operationIds)
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', 'dons.seance', DB::raw('SUM(dons.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', 'dons.seance')
            ->get());

        return collect(array_values($map))->map(fn ($row) => (object) $row);
    }

    /**
     * Budget alloué par sous-catégorie pour un exercice.
     *
     * @return array<int, float>  [sous_categorie_id => montant_prevu]
     */
    private function fetchBudgetMap(int $exercice): array
    {
        return DB::table('budget_lines')
            ->where('exercice', $exercice)
            ->select('sous_categorie_id', DB::raw('SUM(montant_prevu) as budget'))
            ->groupBy('sous_categorie_id')
            ->get()
            ->keyBy('sous_categorie_id')
            ->map(fn ($row) => (float) $row->budget)
            ->all();
    }

    // ── Private helpers — construction de la hiérarchie ───────────────────────

    /**
     * Construit la hiérarchie complète avec montant_n, montant_n1, budget (onglet 1).
     *
     * @param  Collection<int, object>  $flatN   Rows exercice N
     * @param  Collection<int, object>  $flatN1  Rows exercice N-1
     * @param  array<int, float>        $budgetMap
     * @return list<array>
     */
    private function buildHierarchyFull(Collection $flatN, Collection $flatN1, array $budgetMap): array
    {
        // Map intermédiaire keyed by sous_categorie_id
        /** @var array<int, array> */
        $map = [];

        foreach ($flatN as $row) {
            $scId = (int) $row->sous_categorie_id;
            $map[$scId] = [
                'categorie_id'       => (int) $row->categorie_id,
                'categorie_nom'      => $row->categorie_nom,
                'sous_categorie_nom' => $row->sous_categorie_nom,
                'montant_n'          => (float) $row->montant,
                'montant_n1'         => null,
                'budget'             => $budgetMap[$scId] ?? null,
            ];
        }

        foreach ($flatN1 as $row) {
            $scId = (int) $row->sous_categorie_id;
            if (isset($map[$scId])) {
                $map[$scId]['montant_n1'] = (float) $row->montant;
            } else {
                // Sous-cat présente en N-1 mais pas en N
                $map[$scId] = [
                    'categorie_id'       => (int) $row->categorie_id,
                    'categorie_nom'      => $row->categorie_nom,
                    'sous_categorie_nom' => $row->sous_categorie_nom,
                    'montant_n'          => 0.0,
                    'montant_n1'         => (float) $row->montant,
                    'budget'             => $budgetMap[$scId] ?? null,
                ];
            }
        }

        return $this->groupByCategorie($map, true);
    }

    /**
     * Construit la hiérarchie simple avec montant uniquement (onglet 2).
     *
     * @param  Collection<int, object>  $flat
     * @return list<array>
     */
    private function buildHierarchySimple(Collection $flat): array
    {
        $map = [];
        foreach ($flat as $row) {
            $scId = (int) $row->sous_categorie_id;
            $map[$scId] = [
                'categorie_id'       => (int) $row->categorie_id,
                'categorie_nom'      => $row->categorie_nom,
                'sous_categorie_nom' => $row->sous_categorie_nom,
                'montant'            => (float) $row->montant,
            ];
        }

        return $this->groupByCategorie($map, false);
    }

    /**
     * Construit la hiérarchie avec colonnes séances (onglet 3).
     *
     * @param  Collection<int, object>  $flat
     * @param  list<int>                $allSeances
     * @return list<array>
     */
    private function buildHierarchySeances(Collection $flat, array $allSeances): array
    {
        // Map keyed by sous_categorie_id
        /** @var array<int, array> */
        $map = [];

        foreach ($flat as $row) {
            $scId   = (int) $row->sous_categorie_id;
            $seance = (int) $row->seance;

            if (! isset($map[$scId])) {
                $map[$scId] = [
                    'categorie_id'       => (int) $row->categorie_id,
                    'categorie_nom'      => $row->categorie_nom,
                    'sous_categorie_nom' => $row->sous_categorie_nom,
                    'seances'            => [],
                    'total'              => 0.0,
                ];
            }
            $map[$scId]['seances'][$seance] = ($map[$scId]['seances'][$seance] ?? 0.0) + (float) $row->montant;
            $map[$scId]['total'] += (float) $row->montant;
        }

        // Group by catégorie
        /** @var array<int, array> */
        $categories = [];
        foreach ($map as $scId => $sc) {
            $catId = $sc['categorie_id'];
            if (! isset($categories[$catId])) {
                $categories[$catId] = [
                    'categorie_id'    => $catId,
                    'label'           => $sc['categorie_nom'],
                    'seances'         => array_fill_keys($allSeances, 0.0),
                    'total'           => 0.0,
                    'sous_categories' => [],
                ];
            }

            // Pad sous-catégorie séances avec 0.0 pour séances manquantes
            $scSeances = [];
            foreach ($allSeances as $s) {
                $scSeances[$s] = $sc['seances'][$s] ?? 0.0;
            }

            foreach ($allSeances as $s) {
                $categories[$catId]['seances'][$s] += $scSeances[$s];
            }
            $categories[$catId]['total'] += $sc['total'];

            $categories[$catId]['sous_categories'][] = [
                'sous_categorie_id' => $scId,
                'label'             => $sc['sous_categorie_nom'],
                'seances'           => $scSeances,
                'total'             => $sc['total'],
            ];
        }

        usort($categories, fn ($a, $b) => strcmp($a['label'], $b['label']));
        foreach ($categories as &$cat) {
            usort($cat['sous_categories'], fn ($a, $b) => strcmp($a['label'], $b['label']));
        }

        return array_values($categories);
    }

    /**
     * Regroupe la map plate en hiérarchie catégorie → sous-catégories.
     *
     * @param  array<int, array>  $map     Keyed by sous_categorie_id
     * @param  bool               $withN1Budget  Inclure montant_n1 et budget dans le retour
     * @return list<array>
     */
    private function groupByCategorie(array $map, bool $withN1Budget): array
    {
        /** @var array<int, array> */
        $categories = [];

        foreach ($map as $scId => $sc) {
            $catId = $sc['categorie_id'];

            if (! isset($categories[$catId])) {
                $cat = [
                    'categorie_id'    => $catId,
                    'label'           => $sc['categorie_nom'],
                    'sous_categories' => [],
                ];
                if ($withN1Budget) {
                    $cat['montant_n']  = 0.0;
                    $cat['montant_n1'] = null;
                    $cat['budget']     = null;
                } else {
                    $cat['montant'] = 0.0;
                }
                $categories[$catId] = $cat;
            }

            if ($withN1Budget) {
                $categories[$catId]['montant_n'] += $sc['montant_n'];
                if ($sc['montant_n1'] !== null) {
                    $categories[$catId]['montant_n1'] = ($categories[$catId]['montant_n1'] ?? 0.0) + $sc['montant_n1'];
                }
                if ($sc['budget'] !== null) {
                    $categories[$catId]['budget'] = ($categories[$catId]['budget'] ?? 0.0) + $sc['budget'];
                }
                $categories[$catId]['sous_categories'][] = [
                    'sous_categorie_id' => $scId,
                    'label'             => $sc['sous_categorie_nom'],
                    'montant_n'         => $sc['montant_n'],
                    'montant_n1'        => $sc['montant_n1'],
                    'budget'            => $sc['budget'],
                ];
            } else {
                $categories[$catId]['montant'] += $sc['montant'];
                $categories[$catId]['sous_categories'][] = [
                    'sous_categorie_id' => $scId,
                    'label'             => $sc['sous_categorie_nom'],
                    'montant'           => $sc['montant'],
                ];
            }
        }

        usort($categories, fn ($a, $b) => strcmp($a['label'], $b['label']));
        foreach ($categories as &$cat) {
            usort($cat['sous_categories'], fn ($a, $b) => strcmp($a['label'], $b['label']));
        }

        return array_values($categories);
    }
}
```

- [ ] **Step 4 : Vérifier que les tests passent**

```bash
./vendor/bin/sail artisan test tests/Unit/RapportServiceTest.php
```
Attendu : tous PASS

- [ ] **Step 5 : Vérifier le style**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint app/Services/RapportService.php --test
```
Si erreurs : `./vendor/bin/sail exec laravel.test ./vendor/bin/pint app/Services/RapportService.php`

- [ ] **Step 6 : Commit**

```bash
git add app/Services/RapportService.php tests/Unit/RapportServiceTest.php
git commit -m "refactor: RapportService — hiérarchie catégorie/sous-catégorie, N-1, budget, séances multi-op"
```

---

## Chunk 2 : Composants Livewire et Vues

### Task 2 : Refactorer `RapportCompteResultat`

Supprime `$selectedOperationIds`, adapte `render()` et `exportCsv()` à la nouvelle structure.

**Files:**
- Modify: `app/Livewire/RapportCompteResultat.php`
- Modify: `resources/views/livewire/rapport-compte-resultat.blade.php`
- Modify: `tests/Livewire/RapportCompteResultatTest.php`

- [ ] **Step 1 : Mettre à jour les tests Livewire**

Remplacer `tests/Livewire/RapportCompteResultatTest.php` :

```php
<?php

use App\Livewire\RapportCompteResultat;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    session()->forget('exercice_actif');
});

it('se rend sans erreur', function () {
    Livewire::test(RapportCompteResultat::class)
        ->assertOk()
        ->assertSee('DÉPENSES')
        ->assertSee('RECETTES')
        ->assertSee('Exporter CSV');
});

it('affiche les catégories et sous-catégories', function () {
    $cat = Categorie::factory()->depense()->create(['nom' => 'Charges admin']);
    $sc  = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Fournitures']);
    $d   = Depense::factory()->create(['date' => '2025-11-15', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 250.00]);

    Livewire::test(RapportCompteResultat::class)
        ->assertSee('Charges admin')
        ->assertSee('Fournitures')
        ->assertSee('250,00');
});

it('affiche EXCÉDENT quand recettes > dépenses', function () {
    $catD = Categorie::factory()->depense()->create();
    $catR = Categorie::factory()->recette()->create();
    $scD  = SousCategorie::factory()->create(['categorie_id' => $catD->id, 'nom' => 'Frais']);
    $scR  = SousCategorie::factory()->create(['categorie_id' => $catR->id, 'nom' => 'Adhésions']);

    $d = Depense::factory()->create(['date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $scD->id, 'montant' => 100.00]);

    $r = Recette::factory()->create(['date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $r->lignes()->forceDelete();
    RecetteLigne::factory()->create(['recette_id' => $r->id, 'sous_categorie_id' => $scR->id, 'montant' => 500.00]);

    Livewire::test(RapportCompteResultat::class)->assertSee('EXCÉDENT');
});

it('affiche DÉFICIT quand dépenses > recettes', function () {
    $cat = Categorie::factory()->depense()->create();
    $sc  = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Lourdes charges']);
    $d   = Depense::factory()->create(['date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 5000.00]);

    Livewire::test(RapportCompteResultat::class)->assertSee('DÉFICIT');
});

it('affiche la barre de budget quand un budget existe', function () {
    $cat = Categorie::factory()->depense()->create();
    $sc  = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Salle']);
    BudgetLine::factory()->create(['sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 1000.00]);
    $d = Depense::factory()->create(['date' => '2025-11-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'montant' => 800.00]);

    Livewire::test(RapportCompteResultat::class)->assertSee('80 %');
});

it("n'a pas de filtre opération", function () {
    Livewire::test(RapportCompteResultat::class)
        ->assertDontSeeHtml('selectedOperationIds')
        ->assertOk();
});
```

- [ ] **Step 2 : Vérifier que les tests échouent (ancien composant)**

```bash
./vendor/bin/sail artisan test tests/Livewire/RapportCompteResultatTest.php
```
Attendu : plusieurs FAIL

- [ ] **Step 3 : Mettre à jour le composant Livewire**

Remplacer `app/Livewire/RapportCompteResultat.php` :

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Component;

final class RapportCompteResultat extends Component
{
    public function exportCsv(): mixed
    {
        $exercice = app(ExerciceService::class)->current();
        $data = app(RapportService::class)->compteDeResultat($exercice);

        $rows = [];
        foreach ($data['charges'] as $cat) {
            foreach ($cat['sous_categories'] as $sc) {
                $rows[] = [
                    'Charge',
                    $cat['label'],
                    $sc['label'],
                    $sc['montant_n1'] !== null ? number_format((float) $sc['montant_n1'], 2, ',', '') : '',
                    number_format((float) $sc['montant_n'], 2, ',', ''),
                    $sc['budget'] !== null ? number_format((float) $sc['budget'], 2, ',', '') : '',
                    $sc['budget'] !== null ? number_format((float) $sc['montant_n'] - (float) $sc['budget'], 2, ',', '') : '',
                ];
            }
        }
        foreach ($data['produits'] as $cat) {
            foreach ($cat['sous_categories'] as $sc) {
                $rows[] = [
                    'Produit',
                    $cat['label'],
                    $sc['label'],
                    $sc['montant_n1'] !== null ? number_format((float) $sc['montant_n1'], 2, ',', '') : '',
                    number_format((float) $sc['montant_n'], 2, ',', ''),
                    $sc['budget'] !== null ? number_format((float) $sc['budget'], 2, ',', '') : '',
                    $sc['budget'] !== null ? number_format((float) $sc['montant_n'] - (float) $sc['budget'], 2, ',', '') : '',
                ];
            }
        }

        $csv = app(RapportService::class)->toCsv($rows, ['Type', 'Catégorie', 'Sous-catégorie', 'N-1', 'N', 'Budget', 'Écart']);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'compte_resultat_' . $exercice . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function render(): mixed
    {
        $exercice = app(ExerciceService::class)->current();
        $data     = app(RapportService::class)->compteDeResultat($exercice);

        $labelN  = $exercice . '–' . ($exercice + 1);
        $labelN1 = ($exercice - 1) . '–' . $exercice;

        $totalChargesN  = collect($data['charges'])->sum('montant_n');
        $totalProduitsN = collect($data['produits'])->sum('montant_n');
        $resultatNet    = $totalProduitsN - $totalChargesN;

        return view('livewire.rapport-compte-resultat', [
            'charges'       => $data['charges'],
            'produits'      => $data['produits'],
            'labelN'        => $labelN,
            'labelN1'       => $labelN1,
            'totalChargesN' => $totalChargesN,
            'totalProduitsN'=> $totalProduitsN,
            'resultatNet'   => $resultatNet,
        ]);
    }
}
```

- [ ] **Step 4 : Réécrire la vue**

Remplacer `resources/views/livewire/rapport-compte-resultat.blade.php` :

```blade
<div>
    {{-- Style commun aux 3 rapports --}}
    <style>
        .cr-section-header { background: #3d5473; color: #fff; }
        .cr-section-header td { border-bottom: none; padding: 8px 12px; }
        .cr-section-label td { background: #3d5473; color: #fff; font-weight: 700; font-size: 14px; border-bottom: none; padding: 4px 12px 10px; }
        .cr-cat td { background: #dce6f0; color: #1e3a5f; font-weight: 600; border-bottom: 1px solid #b8ccdf; padding: 7px 12px; }
        .cr-sub td { background: #f7f9fc; color: #444; border-bottom: 1px solid #e2e8f0; padding: 5px 12px; }
        .cr-total td { background: #5a7fa8; color: #fff; font-weight: 700; font-size: 14px; border-bottom: none; padding: 9px 12px; }
        .cr-n1 { color: #9ab0c8; }
        .cr-cat .cr-n1 { color: #6b8aaa; }
        .cr-neg { color: #dc3545; }
        .cr-pos { color: #198754; }
        .cr-zero { color: #6c757d; }
        .budget-bar-track { background: #e2e8f0; border-radius: 4px; height: 10px; width: 110px; overflow: hidden; }
        .cr-total .budget-bar-track { background: rgba(255,255,255,.25); }
        .budget-bar-fill { height: 10px; border-radius: 4px; }
        .budget-label { font-size: 11px; text-align: right; color: #555; margin-top: 2px; }
        .cr-total .budget-label { color: rgba(255,255,255,.8); }
    </style>

    {{-- Export --}}
    <div class="d-flex justify-content-end mb-3">
        <button wire:click="exportCsv" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download"></i> Exporter CSV
        </button>
    </div>

    @php
        // Helper : barre + % pour une ligne
        $renderBar = function(?float $montantN, ?float $budget): string {
            if ($budget === null || $budget <= 0) return '<span class="text-muted">—</span>';
            $pct     = $montantN / $budget * 100;
            $pctCap  = min($pct, 100);
            $color   = $pct > 100 ? '#dc3545' : ($pct > 90 ? '#fd7e14' : '#198754');
            return '<div class="budget-bar-track"><div class="budget-bar-fill" style="width:' . $pctCap . '%;background:' . $color . ';"></div></div>'
                 . '<div class="budget-label">' . number_format($pct, 0) . ' %</div>';
        };
        $renderEcart = function(?float $montantN, ?float $budget, bool $isCharge): string {
            if ($budget === null) return '<span class="text-muted">—</span>';
            $ecart = $montantN - $budget;
            if ($ecart == 0) return '<span class="cr-zero">0,00 €</span>';
            $isNeg = ($isCharge && $ecart < 0) || (!$isCharge && $ecart > 0);
            $cls = $isNeg ? 'cr-pos' : 'cr-neg';
            $sign = $ecart > 0 ? '+' : '';
            return '<span class="' . $cls . '">' . $sign . number_format($ecart, 2, ',', ' ') . ' €</span>';
        };
        $fmt = fn(?float $v): string => $v !== null ? number_format($v, 2, ',', ' ') . ' €' : '—';
    @endphp

    @foreach ([['data' => $charges, 'label' => 'DÉPENSES', 'isCharge' => true, 'total' => $totalChargesN],
               ['data' => $produits, 'label' => 'RECETTES', 'isCharge' => false, 'total' => $totalProduitsN]] as $section)
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                <tbody>
                    {{-- En-tête colonnes --}}
                    <tr class="cr-section-header">
                        <td style="width:20px;"></td>
                        <td></td>
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">{{ $labelN1 }}</td>
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">{{ $labelN }}</td>
                        <td class="text-end" style="width:115px;font-weight:400;font-size:12px;opacity:.85;">Budget</td>
                        <td class="text-end" style="width:90px;font-weight:400;font-size:12px;opacity:.85;">Écart</td>
                        <td class="text-center" style="width:130px;font-weight:400;font-size:12px;opacity:.85;">Conso. budget</td>
                    </tr>
                    {{-- Titre section --}}
                    <tr class="cr-section-label">
                        <td colspan="7">{{ $section['label'] }}</td>
                    </tr>

                    @foreach ($section['data'] as $cat)
                        @php
                            // Règle d'affichage : sous-cat visible si montant_n > 0, ou N-1 > 0, ou budget défini
                            $scVisibles = collect($cat['sous_categories'])->filter(function($sc) {
                                return $sc['montant_n'] > 0
                                    || ($sc['montant_n1'] !== null && $sc['montant_n1'] > 0)
                                    || ($sc['budget'] !== null && $sc['budget'] > 0);
                            });
                        @endphp
                        @if (! $scVisibles->isEmpty())
                        {{-- Ligne catégorie --}}
                        <tr class="cr-cat">
                            <td></td>
                            <td>{{ $cat['label'] }}</td>
                            <td class="text-end cr-n1">{{ $fmt($cat['montant_n1']) }}</td>
                            <td class="text-end">{{ $fmt($cat['montant_n']) }}</td>
                            <td class="text-end">{{ $fmt($cat['budget']) }}</td>
                            <td class="text-end">{!! $renderEcart($cat['montant_n'], $cat['budget'], $section['isCharge']) !!}</td>
                            <td class="text-center">{!! $renderBar($cat['montant_n'], $cat['budget']) !!}</td>
                        </tr>
                        {{-- Lignes sous-catégories (déjà filtrées) --}}
                        @foreach ($scVisibles as $sc)
                            <tr class="cr-sub">
                                <td></td>
                                <td style="padding-left:32px;">{{ $sc['label'] }}</td>
                                <td class="text-end cr-n1">{{ $fmt($sc['montant_n1']) }}</td>
                                <td class="text-end">{{ $fmt($sc['montant_n']) }}</td>
                                <td class="text-end">{{ $fmt($sc['budget']) }}</td>
                                <td class="text-end">{!! $renderEcart($sc['montant_n'], $sc['budget'], $section['isCharge']) !!}</td>
                                <td class="text-center">{!! $renderBar($sc['montant_n'], $sc['budget']) !!}</td>
                            </tr>
                        @endforeach
                        @endif
                    @endforeach

                    {{-- Total --}}
                    <tr class="cr-total">
                        <td colspan="2">TOTAL {{ $section['label'] }}</td>
                        <td class="text-end" style="color:#d0e4f7;">—</td>
                        <td class="text-end">{{ number_format($section['total'], 2, ',', ' ') }} €</td>
                        <td class="text-end">—</td>
                        <td class="text-end">—</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    @endforeach

    {{-- Résultat net --}}
    <div class="rounded p-4 d-flex justify-content-between align-items-center mt-2"
         style="background:{{ $resultatNet >= 0 ? '#198754' : '#dc3545' }};color:#fff;font-size:1.1rem;font-weight:700;">
        <span>{{ $resultatNet >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }}</span>
        <span>{{ number_format(abs($resultatNet), 2, ',', ' ') }} €</span>
    </div>
</div>
```

- [ ] **Step 5 : Vérifier que les tests passent**

```bash
./vendor/bin/sail artisan test tests/Livewire/RapportCompteResultatTest.php
```
Attendu : tous PASS

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/RapportCompteResultat.php \
        resources/views/livewire/rapport-compte-resultat.blade.php \
        tests/Livewire/RapportCompteResultatTest.php
git commit -m "feat: onglet 1 — compte de résultat hiérarchique avec N-1, budget et barre de consommation"
```

---

### Task 3 : Créer `RapportCompteResultatOperations` (onglet 2)

**Files:**
- Create: `app/Livewire/RapportCompteResultatOperations.php`
- Create: `resources/views/livewire/rapport-compte-resultat-operations.blade.php`
- Create: `tests/Livewire/RapportCompteResultatOperationsTest.php`

- [ ] **Step 1 : Écrire les tests**

Créer `tests/Livewire/RapportCompteResultatOperationsTest.php` :

```php
<?php

use App\Livewire\RapportCompteResultatOperations;
use App\Models\Categorie;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\Operation;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    session()->forget('exercice_actif');
});

it('se rend avec la liste des opérations', function () {
    $op = Operation::factory()->create(['nom' => 'Festival été']);
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertOk()
        ->assertSee('Festival été');
});

it('affiche un message si aucune opération sélectionnée', function () {
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSee('Sélectionnez au moins une opération');
});

it('affiche les données filtrées par opération', function () {
    $op  = Operation::factory()->create();
    $op2 = Operation::factory()->create();
    $cat = Categorie::factory()->depense()->create(['nom' => 'Frais']);
    $sc  = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Transport']);

    $d = Depense::factory()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 100.00]);
    DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op2->id, 'montant' => 999.00]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->assertSee('Transport')
        ->assertSee('100,00')
        ->assertDontSee('999,00');
});

it('désactive le bouton CSV quand aucune opération sélectionnée', function () {
    // Blade rend {{ $hasSelection ? '' : 'disabled' }} → attribut booléen "disabled" sur le bouton
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSee('Exporter CSV')
        ->assertSeeHtml(' disabled>');
});
```

- [ ] **Step 2 : Vérifier que les tests échouent (composant absent)**

```bash
./vendor/bin/sail artisan test tests/Livewire/RapportCompteResultatOperationsTest.php
```
Attendu : FAIL (classe non trouvée)

- [ ] **Step 3 : Créer le composant Livewire**

Créer `app/Livewire/RapportCompteResultatOperations.php` :

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Component;

final class RapportCompteResultatOperations extends Component
{
    /** @var array<int, int> */
    public array $selectedOperationIds = [];

    public function exportCsv(): mixed
    {
        if (empty($this->selectedOperationIds)) {
            return null;
        }

        $exercice = app(ExerciceService::class)->current();
        $data     = app(RapportService::class)->compteDeResultatOperations($exercice, $this->selectedOperationIds);

        $rows = [];
        foreach ($data['charges'] as $cat) {
            foreach ($cat['sous_categories'] as $sc) {
                $rows[] = ['Charge', $cat['label'], $sc['label'], number_format((float) $sc['montant'], 2, ',', '')];
            }
        }
        foreach ($data['produits'] as $cat) {
            foreach ($cat['sous_categories'] as $sc) {
                $rows[] = ['Produit', $cat['label'], $sc['label'], number_format((float) $sc['montant'], 2, ',', '')];
            }
        }

        $csv = app(RapportService::class)->toCsv($rows, ['Type', 'Catégorie', 'Sous-catégorie', 'Montant']);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'cr_operations_' . $exercice . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function render(): mixed
    {
        $exercice   = app(ExerciceService::class)->current();
        $operations = Operation::orderBy('nom')->get();
        $charges    = [];
        $produits   = [];
        $totalChargesN  = 0.0;
        $totalProduitsN = 0.0;

        if (! empty($this->selectedOperationIds)) {
            $data           = app(RapportService::class)->compteDeResultatOperations($exercice, $this->selectedOperationIds);
            $charges        = $data['charges'];
            $produits       = $data['produits'];
            $totalChargesN  = collect($charges)->sum('montant');
            $totalProduitsN = collect($produits)->sum('montant');
        }

        return view('livewire.rapport-compte-resultat-operations', [
            'operations'     => $operations,
            'charges'        => $charges,
            'produits'       => $produits,
            'totalChargesN'  => $totalChargesN,
            'totalProduitsN' => $totalProduitsN,
            'resultatNet'    => $totalProduitsN - $totalChargesN,
            'hasSelection'   => ! empty($this->selectedOperationIds),
        ]);
    }
}
```

- [ ] **Step 4 : Créer la vue**

Créer `resources/views/livewire/rapport-compte-resultat-operations.blade.php` :

```blade
<div>
    {{-- Filtre opérations --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-3 align-items-center">
                @foreach ($operations as $op)
                    <div class="form-check">
                        <input type="checkbox" wire:model.live="selectedOperationIds"
                               value="{{ $op->id }}" id="op-{{ $op->id }}" class="form-check-input">
                        <label for="op-{{ $op->id }}" class="form-check-label">{{ $op->nom }}</label>
                    </div>
                @endforeach
                <button wire:click="exportCsv" class="btn btn-outline-secondary btn-sm ms-auto"
                        {{ $hasSelection ? '' : 'disabled' }}>
                    <i class="bi bi-download"></i> Exporter CSV
                </button>
            </div>
        </div>
    </div>

    @if (! $hasSelection)
        <p class="text-muted text-center py-4">Sélectionnez au moins une opération pour afficher le rapport.</p>
    @else
        @foreach ([['data' => $charges, 'label' => 'DÉPENSES', 'total' => $totalChargesN],
                   ['data' => $produits, 'label' => 'RECETTES', 'total' => $totalProduitsN]] as $section)
        <div class="card mb-3 border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                    <tbody>
                        <tr style="background:#3d5473;color:#fff;">
                            <td style="width:20px;"></td>
                            <td></td>
                            <td class="text-end fw-400" style="width:130px;font-size:12px;opacity:.85;">Montant</td>
                        </tr>
                        <tr style="background:#3d5473;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="3" style="padding:4px 12px 10px;">{{ $section['label'] }}</td>
                        </tr>

                        @foreach ($section['data'] as $cat)
                            @php
                                $scVisibles = collect($cat['sous_categories'])->filter(fn($sc) => $sc['montant'] > 0);
                            @endphp
                            @if (! $scVisibles->isEmpty())
                            <tr style="background:#dce6f0;">
                                <td></td>
                                <td style="font-weight:600;color:#1e3a5f;padding:7px 12px;">{{ $cat['label'] }}</td>
                                <td class="text-end fw-bold" style="padding:7px 12px;">{{ number_format($cat['montant'], 2, ',', ' ') }} €</td>
                            </tr>
                            @foreach ($scVisibles as $sc)
                            <tr style="background:#f7f9fc;">
                                <td></td>
                                <td style="padding:5px 12px 5px 32px;color:#444;">{{ $sc['label'] }}</td>
                                <td class="text-end" style="padding:5px 12px;color:#444;">{{ number_format($sc['montant'], 2, ',', ' ') }} €</td>
                            </tr>
                            @endforeach
                            @endif
                        @endforeach

                        <tr style="background:#5a7fa8;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="2" style="padding:9px 12px;">TOTAL {{ $section['label'] }}</td>
                            <td class="text-end" style="padding:9px 12px;">{{ number_format($section['total'], 2, ',', ' ') }} €</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @endforeach

        <div class="rounded p-4 d-flex justify-content-between align-items-center mt-2"
             style="background:{{ $resultatNet >= 0 ? '#198754' : '#dc3545' }};color:#fff;font-size:1.1rem;font-weight:700;">
            <span>{{ $resultatNet >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }}</span>
            <span>{{ number_format(abs($resultatNet), 2, ',', ' ') }} €</span>
        </div>
    @endif
</div>
```

- [ ] **Step 5 : Vérifier que les tests passent**

```bash
./vendor/bin/sail artisan test tests/Livewire/RapportCompteResultatOperationsTest.php
```
Attendu : tous PASS

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/RapportCompteResultatOperations.php \
        resources/views/livewire/rapport-compte-resultat-operations.blade.php \
        tests/Livewire/RapportCompteResultatOperationsTest.php
git commit -m "feat: onglet 2 — compte de résultat par opération(s)"
```

---

### Task 4 : Refactorer `RapportSeances` (onglet 3)

**Files:**
- Modify: `app/Livewire/RapportSeances.php`
- Modify: `resources/views/livewire/rapport-seances.blade.php`
- Modify: `tests/Livewire/RapportSeancesTest.php`

- [ ] **Step 1 : Mettre à jour les tests**

Remplacer `tests/Livewire/RapportSeancesTest.php` :

```php
<?php

use App\Livewire\RapportSeances;
use App\Models\Categorie;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\Operation;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    session()->forget('exercice_actif');
});

it('se rend avec la liste des opérations ayant des séances', function () {
    $opAvec    = Operation::factory()->withSeances(2)->create(['nom' => 'Festival']);
    $opSans    = Operation::factory()->create(['nombre_seances' => null, 'nom' => 'Invisible']);

    Livewire::test(RapportSeances::class)
        ->assertSee('Festival')
        ->assertDontSee('Invisible');
});

it('affiche un message si aucune opération sélectionnée', function () {
    Livewire::test(RapportSeances::class)
        ->assertSee('Sélectionnez au moins une opération');
});

it('affiche les colonnes séances et le total', function () {
    $op  = Operation::factory()->withSeances(2)->create();
    $cat = Categorie::factory()->depense()->create(['nom' => 'Charges']);
    $sc  = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Location salle']);

    $d = Depense::factory()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => 1, 'montant' => 100.00]);
    DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => 2, 'montant' => 150.00]);

    Livewire::test(RapportSeances::class)
        ->set('selectedOperationIds', [$op->id])
        ->assertSee('Location salle')
        ->assertSee('Séance 1')
        ->assertSee('Séance 2')
        ->assertSee('100,00')
        ->assertSee('150,00')
        ->assertSee('250,00'); // total
});

it('agrège les séances de même numéro sur plusieurs opérations', function () {
    $op1 = Operation::factory()->withSeances(1)->create();
    $op2 = Operation::factory()->withSeances(1)->create();
    $cat = Categorie::factory()->depense()->create();
    $sc  = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Salle']);

    foreach ([$op1, $op2] as $op) {
        $d = Depense::factory()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
        $d->lignes()->forceDelete();
        DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => 1, 'montant' => 100.00]);
    }

    Livewire::test(RapportSeances::class)
        ->set('selectedOperationIds', [$op1->id, $op2->id])
        ->assertSee('200,00'); // séance 1 agrégée
});
```

- [ ] **Step 2 : Vérifier que les tests échouent**

```bash
./vendor/bin/sail artisan test tests/Livewire/RapportSeancesTest.php
```
Attendu : plusieurs FAIL

- [ ] **Step 3 : Mettre à jour le composant**

Remplacer `app/Livewire/RapportSeances.php` :

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Component;

final class RapportSeances extends Component
{
    /** @var array<int, int> */
    public array $selectedOperationIds = [];

    public function exportCsv(): mixed
    {
        if (empty($this->selectedOperationIds)) {
            return null;
        }

        $exercice = app(ExerciceService::class)->current();
        $data     = app(RapportService::class)->rapportSeances($exercice, $this->selectedOperationIds);

        $seances = $data['seances'];
        $headers = ['Type', 'Catégorie', 'Sous-catégorie'];
        foreach ($seances as $s) {
            $headers[] = 'Séance ' . $s;
        }
        $headers[] = 'Total';

        $rows = [];
        foreach ([['data' => $data['charges'], 'type' => 'Charge'], ['data' => $data['produits'], 'type' => 'Produit']] as $section) {
            foreach ($section['data'] as $cat) {
                foreach ($cat['sous_categories'] as $sc) {
                    $row = [$section['type'], $cat['label'], $sc['label']];
                    foreach ($seances as $s) {
                        $row[] = number_format($sc['seances'][$s] ?? 0.0, 2, ',', '');
                    }
                    $row[] = number_format($sc['total'], 2, ',', '');
                    $rows[] = $row;
                }
            }
        }

        $csv = app(RapportService::class)->toCsv($rows, $headers);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'rapport_seances_' . $exercice . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function render(): mixed
    {
        $operations = Operation::whereNotNull('nombre_seances')
            ->where('nombre_seances', '>', 0)
            ->orderBy('nom')
            ->get();

        $seances        = [];
        $charges        = [];
        $produits       = [];
        $totalChargesN  = 0.0;
        $totalProduitsN = 0.0;

        if (! empty($this->selectedOperationIds)) {
            $exercice       = app(ExerciceService::class)->current();
            $data           = app(RapportService::class)->rapportSeances($exercice, $this->selectedOperationIds);
            $seances        = $data['seances'];
            $charges        = $data['charges'];
            $produits       = $data['produits'];
            $totalChargesN  = collect($charges)->sum('total');
            $totalProduitsN = collect($produits)->sum('total');
        }

        return view('livewire.rapport-seances', [
            'operations'     => $operations,
            'seances'        => $seances,
            'charges'        => $charges,
            'produits'       => $produits,
            'totalChargesN'  => $totalChargesN,
            'totalProduitsN' => $totalProduitsN,
            'resultatNet'    => $totalProduitsN - $totalChargesN,
            'hasSelection'   => ! empty($this->selectedOperationIds),
        ]);
    }
}
```

- [ ] **Step 4 : Réécrire la vue**

Remplacer `resources/views/livewire/rapport-seances.blade.php` :

```blade
<div>
    {{-- Filtre opérations --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-3 align-items-center">
                @foreach ($operations as $op)
                    <div class="form-check">
                        <input type="checkbox" wire:model.live="selectedOperationIds"
                               value="{{ $op->id }}" id="ops-{{ $op->id }}" class="form-check-input">
                        <label for="ops-{{ $op->id }}" class="form-check-label">{{ $op->nom }}</label>
                    </div>
                @endforeach
                <button wire:click="exportCsv" class="btn btn-outline-secondary btn-sm ms-auto"
                        {{ $hasSelection ? '' : 'disabled' }}>
                    <i class="bi bi-download"></i> Exporter CSV
                </button>
            </div>
        </div>
    </div>

    @if (! $hasSelection)
        <p class="text-muted text-center py-4">Sélectionnez au moins une opération pour afficher le rapport.</p>
    @else
        @php $nbCols = count($seances) + 3; @endphp

        @foreach ([['data' => $charges, 'label' => 'DÉPENSES', 'total' => $totalChargesN],
                   ['data' => $produits, 'label' => 'RECETTES', 'total' => $totalProduitsN]] as $section)
        <div class="card mb-3 border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                    <tbody>
                        {{-- En-tête colonnes --}}
                        <tr style="background:#3d5473;color:#fff;">
                            <td style="width:20px;"></td>
                            <td></td>
                            @foreach ($seances as $s)
                                <td class="text-end" style="width:90px;font-size:12px;font-weight:400;opacity:.85;">Séance {{ $s }}</td>
                            @endforeach
                            <td class="text-end" style="width:100px;font-size:12px;font-weight:400;opacity:.85;">Total</td>
                        </tr>
                        <tr style="background:#3d5473;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="{{ $nbCols }}" style="padding:4px 12px 10px;">{{ $section['label'] }}</td>
                        </tr>

                        @foreach ($section['data'] as $cat)
                            @php
                                $scVisibles = collect($cat['sous_categories'])->filter(fn($sc) => $sc['total'] > 0);
                            @endphp
                            @if (! $scVisibles->isEmpty())
                            <tr style="background:#dce6f0;">
                                <td></td>
                                <td style="font-weight:600;color:#1e3a5f;padding:7px 12px;">{{ $cat['label'] }}</td>
                                @foreach ($seances as $s)
                                    <td class="text-end fw-semibold" style="padding:7px 12px;">{{ number_format($cat['seances'][$s] ?? 0, 2, ',', ' ') }} €</td>
                                @endforeach
                                <td class="text-end fw-bold" style="padding:7px 12px;">{{ number_format($cat['total'], 2, ',', ' ') }} €</td>
                            </tr>
                            @foreach ($scVisibles as $sc)
                            <tr style="background:#f7f9fc;">
                                <td></td>
                                <td style="padding:5px 12px 5px 32px;color:#444;">{{ $sc['label'] }}</td>
                                @foreach ($seances as $s)
                                    <td class="text-end" style="padding:5px 12px;color:#444;">{{ number_format($sc['seances'][$s] ?? 0, 2, ',', ' ') }} €</td>
                                @endforeach
                                <td class="text-end" style="padding:5px 12px;color:#444;">{{ number_format($sc['total'], 2, ',', ' ') }} €</td>
                            </tr>
                            @endforeach
                            @endif
                        @endforeach

                        <tr style="background:#5a7fa8;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="2" style="padding:9px 12px;">TOTAL {{ $section['label'] }}</td>
                            @foreach ($seances as $s)
                                <td class="text-end" style="padding:9px 12px;color:#d0e4f7;">—</td>
                            @endforeach
                            <td class="text-end" style="padding:9px 12px;">{{ number_format($section['total'], 2, ',', ' ') }} €</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @endforeach

        <div class="rounded p-4 d-flex justify-content-between align-items-center mt-2"
             style="background:{{ $resultatNet >= 0 ? '#198754' : '#dc3545' }};color:#fff;font-size:1.1rem;font-weight:700;">
            <span>{{ $resultatNet >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }}</span>
            <span>{{ number_format(abs($resultatNet), 2, ',', ' ') }} €</span>
        </div>
    @endif
</div>
```

- [ ] **Step 5 : Vérifier que les tests passent**

```bash
./vendor/bin/sail artisan test tests/Livewire/RapportSeancesTest.php
```
Attendu : tous PASS

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/RapportSeances.php \
        resources/views/livewire/rapport-seances.blade.php \
        tests/Livewire/RapportSeancesTest.php
git commit -m "feat: onglet 3 — rapport par séances hiérarchique multi-opérations"
```

---

## Chunk 3 : Navigation

### Task 5 : Mettre à jour `rapports/index.blade.php`

Ajouter l'onglet 2 entre les deux existants.

**Files:**
- Modify: `resources/views/rapports/index.blade.php`

- [ ] **Step 1 : Remplacer le fichier**

```blade
<x-app-layout>
    <h1 class="mb-4">Rapports</h1>

    <ul class="nav nav-tabs mb-4" id="rapportsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="compte-resultat-tab" data-bs-toggle="tab"
                    data-bs-target="#compte-resultat" type="button" role="tab"
                    aria-controls="compte-resultat" aria-selected="true">
                Compte de résultat
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="cr-operations-tab" data-bs-toggle="tab"
                    data-bs-target="#cr-operations" type="button" role="tab"
                    aria-controls="cr-operations" aria-selected="false">
                Compte de résultat par opération(s)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="rapport-seances-tab" data-bs-toggle="tab"
                    data-bs-target="#rapport-seances" type="button" role="tab"
                    aria-controls="rapport-seances" aria-selected="false">
                Rapport par séances
            </button>
        </li>
    </ul>

    <div class="tab-content" id="rapportsTabContent">
        <div class="tab-pane fade show active" id="compte-resultat" role="tabpanel"
             aria-labelledby="compte-resultat-tab">
            <livewire:rapport-compte-resultat />
        </div>
        <div class="tab-pane fade" id="cr-operations" role="tabpanel"
             aria-labelledby="cr-operations-tab">
            <livewire:rapport-compte-resultat-operations />
        </div>
        <div class="tab-pane fade" id="rapport-seances" role="tabpanel"
             aria-labelledby="rapport-seances-tab">
            <livewire:rapport-seances />
        </div>
    </div>
</x-app-layout>
```

- [ ] **Step 2 : Lancer tous les tests**

```bash
./vendor/bin/sail artisan test tests/Unit/RapportServiceTest.php \
    tests/Livewire/RapportCompteResultatTest.php \
    tests/Livewire/RapportCompteResultatOperationsTest.php \
    tests/Livewire/RapportSeancesTest.php
```
Attendu : tous PASS

- [ ] **Step 3 : Vérifier manuellement dans le navigateur**

Ouvrir `http://localhost/rapports` et vérifier :
- Onglet 1 : hiérarchie catégorie/sous-catégorie visible, colonnes N-1/N/Budget/Écart/Barre, EXCÉDENT/DÉFICIT en bas
- Onglet 2 : checkboxes opérations, tableau simplifié, bouton CSV désactivé sans sélection
- Onglet 3 : checkboxes opérations, colonnes dynamiques par séance, EXCÉDENT/DÉFICIT en bas

- [ ] **Step 4 : Commit final**

```bash
git add resources/views/rapports/index.blade.php
git commit -m "feat: page rapports — 3 onglets (CR, CR par opération, séances)"
```
