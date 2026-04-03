# Lot 2 — Enrichissement CR par opérations — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enrichir le CR par opérations avec un dropdown hiérarchique, séances en colonnes, et tiers en lignes.

**Architecture:** Nouvelles méthodes génériques dans `RapportService` (query builder + accumulation + hiérarchie) qui supportent les 4 combinaisons de flags (parSeances × parTiers). Composant Livewire refait avec `#[Url]` pour persister l'état. Blade avec Alpine.js pour le dropdown cascade et rendu conditionnel du tableau.

**Tech Stack:** Laravel 11, Livewire 4 (avec Alpine.js bundled), Bootstrap 5, Pest PHP

**Spec:** `docs/superpowers/specs/2026-04-03-rapports-lot2-design.md`

---

### Task 1: Service — Enrichir compteDeResultatOperations (TDD)

**Files:**
- Modify: `app/Services/RapportService.php`
- Modify: `tests/Unit/RapportServiceTest.php`

- [ ] **Step 1: Écrire les tests pour parSeances**

Ajouter dans `tests/Unit/RapportServiceTest.php` :

```php
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
    expect($cat['seances'][0])->toBe(50.0); // hors séance

    $sc0 = $cat['sous_categories'][0];
    expect($sc0['total'])->toBe(150.0);
    expect($sc0['seances'][1])->toBe(100.0);
});
```

- [ ] **Step 2: Écrire les tests pour parTiers**

```php
use App\Models\Tiers;

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

    // Transaction sans tiers
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

    // Tiers triés alphabétiquement
    $labels = collect($sc0['tiers'])->pluck('label')->all();
    expect($labels)->toContain('(sans tiers)');
    expect($labels)->toContain('Jean DUPONT');
    expect($labels)->toContain('Martin SAS');

    // Vérifier types
    $tiersMap = collect($sc0['tiers'])->keyBy('label');
    expect($tiersMap['Jean DUPONT']['type'])->toBe('particulier');
    expect($tiersMap['Martin SAS']['type'])->toBe('entreprise');
    expect($tiersMap['(sans tiers)']['type'])->toBeNull();
});
```

- [ ] **Step 3: Écrire le test pour les deux flags combinés**

```php
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
```

- [ ] **Step 4: Exécuter les tests — vérifier qu'ils échouent**

Run: `./vendor/bin/sail test tests/Unit/RapportServiceTest.php --filter="parSeances|parTiers|combinés"`
Expected: FAIL (méthodes pas encore modifiées)

- [ ] **Step 5: Implémenter `buildOperationQueries`**

Ajouter dans `app/Services/RapportService.php` après les méthodes existantes (avant `buildHierarchySimple`) :

```php
/**
 * Construit les deux requêtes (sans/avec affectations) pour un rapport par opérations.
 *
 * @param  array<int>  $operationIds
 * @return array{\Illuminate\Database\Query\Builder, \Illuminate\Database\Query\Builder}
 */
private function buildOperationQueries(
    string $type,
    string $start,
    string $end,
    array $operationIds,
    bool $withSeance,
    bool $withTiers,
): array {
    $baseCols = [
        'c.id as categorie_id', 'c.nom as categorie_nom',
        'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom',
    ];
    $baseGroup = ['c.id', 'c.nom', 'sc.id', 'sc.nom'];

    if ($withTiers) {
        $baseCols = array_merge($baseCols, [
            DB::raw('COALESCE(tx.tiers_id, 0) as tiers_id'),
            DB::raw("COALESCE(t.type, '') as tiers_type"),
            DB::raw("COALESCE(t.nom, '') as tiers_nom"),
            DB::raw("COALESCE(t.prenom, '') as tiers_prenom"),
            DB::raw("COALESCE(t.entreprise, '') as tiers_entreprise"),
        ]);
        $baseGroup = array_merge($baseGroup, ['tx.tiers_id', 't.type', 't.nom', 't.prenom', 't.entreprise']);
    }

    // Q1 : lignes sans affectations
    $q1Cols = $baseCols;
    $q1Group = $baseGroup;
    if ($withSeance) {
        $q1Cols[] = DB::raw('COALESCE(transaction_lignes.seance, 0) as seance');
        $q1Group[] = DB::raw('COALESCE(transaction_lignes.seance, 0)');
    }
    $q1Cols[] = DB::raw('SUM(transaction_lignes.montant) as montant');

    $q1 = DB::table('transaction_lignes')
        ->join('sous_categories as sc', 'transaction_lignes.sous_categorie_id', '=', 'sc.id')
        ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
        ->join('transactions as tx', 'tx.id', '=', 'transaction_lignes.transaction_id')
        ->where('tx.type', $type)
        ->leftJoin('transaction_ligne_affectations as tla', 'tla.transaction_ligne_id', '=', 'transaction_lignes.id')
        ->whereNull('transaction_lignes.deleted_at')
        ->whereNull('tx.deleted_at')
        ->whereNull('tla.id')
        ->whereIn('transaction_lignes.operation_id', $operationIds)
        ->whereBetween('tx.date', [$start, $end])
        ->select($q1Cols)
        ->groupBy($q1Group);

    if ($withTiers) {
        $q1->leftJoin('tiers as t', 't.id', '=', 'tx.tiers_id');
    }

    // Q2 : lignes avec affectations
    $q2Cols = $baseCols;
    $q2Group = $baseGroup;
    if ($withSeance) {
        $q2Cols[] = DB::raw('COALESCE(tla2.seance, 0) as seance');
        $q2Group[] = DB::raw('COALESCE(tla2.seance, 0)');
    }
    $q2Cols[] = DB::raw('SUM(tla2.montant) as montant');

    $q2 = DB::table('transaction_ligne_affectations as tla2')
        ->join('transaction_lignes', 'transaction_lignes.id', '=', 'tla2.transaction_ligne_id')
        ->join('sous_categories as sc', 'transaction_lignes.sous_categorie_id', '=', 'sc.id')
        ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
        ->join('transactions as tx', 'tx.id', '=', 'transaction_lignes.transaction_id')
        ->where('tx.type', $type)
        ->whereNull('transaction_lignes.deleted_at')
        ->whereNull('tx.deleted_at')
        ->whereIn('tla2.operation_id', $operationIds)
        ->whereBetween('tx.date', [$start, $end])
        ->select($q2Cols)
        ->groupBy($q2Group);

    if ($withTiers) {
        $q2->leftJoin('tiers as t', 't.id', '=', 'tx.tiers_id');
    }

    return [$q1, $q2];
}
```

- [ ] **Step 6: Implémenter `fetchOperationRows`**

```php
/**
 * Exécute les requêtes et accumule les résultats dans un map plat.
 *
 * @param  array<int>  $operationIds
 * @return array<string, array>
 */
private function fetchOperationRows(
    string $type,
    string $start,
    string $end,
    array $operationIds,
    bool $withSeance,
    bool $withTiers,
): array {
    [$q1, $q2] = $this->buildOperationQueries($type, $start, $end, $operationIds, $withSeance, $withTiers);

    $map = [];
    foreach ([$q1->get(), $q2->get()] as $rows) {
        foreach ($rows as $row) {
            $key = (string) $row->sous_categorie_id;
            if ($withTiers) {
                $key .= '_'.$row->tiers_id;
            }
            if ($withSeance) {
                $key .= '_'.$row->seance;
            }

            if (isset($map[$key])) {
                $map[$key]['montant'] += (float) $row->montant;
            } else {
                $entry = [
                    'categorie_id' => (int) $row->categorie_id,
                    'categorie_nom' => $row->categorie_nom,
                    'sous_categorie_id' => (int) $row->sous_categorie_id,
                    'sous_categorie_nom' => $row->sous_categorie_nom,
                    'montant' => (float) $row->montant,
                ];
                if ($withSeance) {
                    $entry['seance'] = (int) $row->seance;
                }
                if ($withTiers) {
                    $entry['tiers_id'] = (int) $row->tiers_id;
                    $entry['tiers_type'] = $row->tiers_type !== '' ? $row->tiers_type : null;
                    $entry['tiers_nom'] = $row->tiers_nom !== '' ? $row->tiers_nom : null;
                    $entry['tiers_prenom'] = $row->tiers_prenom !== '' ? $row->tiers_prenom : null;
                    $entry['tiers_entreprise'] = $row->tiers_entreprise !== '' ? $row->tiers_entreprise : null;
                }
                $map[$key] = $entry;
            }
        }
    }

    return $map;
}
```

- [ ] **Step 7: Implémenter `buildHierarchyOperations` et `formatTiersLabel`**

```php
/**
 * Construit la hiérarchie catégorie → sous-catégorie (→ tiers) avec montant simple ou par séance.
 *
 * @param  array<string, array>  $map
 * @param  list<int>  $allSeances
 * @return list<array>
 */
private function buildHierarchyOperations(array $map, bool $withSeance, bool $withTiers, array $allSeances = []): array
{
    $categories = [];

    foreach ($map as $entry) {
        $catId = $entry['categorie_id'];
        $scId = $entry['sous_categorie_id'];

        if (! isset($categories[$catId])) {
            $categories[$catId] = [
                'categorie_id' => $catId,
                'label' => $entry['categorie_nom'],
                'sous_categories_map' => [],
            ];
            if ($withSeance) {
                $categories[$catId]['seances'] = array_fill_keys($allSeances, 0.0);
                $categories[$catId]['total'] = 0.0;
            } else {
                $categories[$catId]['montant'] = 0.0;
            }
        }

        if (! isset($categories[$catId]['sous_categories_map'][$scId])) {
            $scEntry = ['sous_categorie_id' => $scId, 'label' => $entry['sous_categorie_nom']];
            if ($withSeance) {
                $scEntry['seances'] = array_fill_keys($allSeances, 0.0);
                $scEntry['total'] = 0.0;
            } else {
                $scEntry['montant'] = 0.0;
            }
            if ($withTiers) {
                $scEntry['tiers_map'] = [];
            }
            $categories[$catId]['sous_categories_map'][$scId] = $scEntry;
        }

        $montant = $entry['montant'];

        if ($withTiers) {
            $tiersId = $entry['tiers_id'];
            if (! isset($categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId])) {
                $tEntry = [
                    'tiers_id' => $tiersId,
                    'label' => $this->formatTiersLabel($entry),
                    'type' => $tiersId === 0 ? null : $entry['tiers_type'],
                ];
                if ($withSeance) {
                    $tEntry['seances'] = array_fill_keys($allSeances, 0.0);
                    $tEntry['total'] = 0.0;
                } else {
                    $tEntry['montant'] = 0.0;
                }
                $categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId] = $tEntry;
            }
            if ($withSeance) {
                $categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId]['seances'][$entry['seance']] += $montant;
                $categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId]['total'] += $montant;
            } else {
                $categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId]['montant'] += $montant;
            }
        }

        if ($withSeance) {
            $seance = $entry['seance'];
            $categories[$catId]['sous_categories_map'][$scId]['seances'][$seance] += $montant;
            $categories[$catId]['sous_categories_map'][$scId]['total'] += $montant;
            $categories[$catId]['seances'][$seance] += $montant;
            $categories[$catId]['total'] += $montant;
        } else {
            $categories[$catId]['sous_categories_map'][$scId]['montant'] += $montant;
            $categories[$catId]['montant'] += $montant;
        }
    }

    $result = [];
    foreach ($categories as $cat) {
        $scs = [];
        foreach ($cat['sous_categories_map'] as $sc) {
            if ($withTiers) {
                $tiers = array_values($sc['tiers_map']);
                usort($tiers, fn ($a, $b) => strcmp($a['label'], $b['label']));
                $sc['tiers'] = $tiers;
                unset($sc['tiers_map']);
            }
            $scs[] = $sc;
        }
        usort($scs, fn ($a, $b) => strcmp($a['label'], $b['label']));
        $cat['sous_categories'] = $scs;
        unset($cat['sous_categories_map']);
        $result[] = $cat;
    }
    usort($result, fn ($a, $b) => strcmp($a['label'], $b['label']));

    return $result;
}

private function formatTiersLabel(array $entry): string
{
    if ($entry['tiers_id'] === 0) {
        return '(sans tiers)';
    }
    if ($entry['tiers_type'] === 'entreprise') {
        return ($entry['tiers_entreprise'] !== null && $entry['tiers_entreprise'] !== '')
            ? $entry['tiers_entreprise']
            : mb_strtoupper($entry['tiers_nom'] ?? '');
    }
    $nom = mb_strtoupper($entry['tiers_nom'] ?? '');
    $prenom = $entry['tiers_prenom'] ?? '';

    return trim(($prenom !== '' ? $prenom.' ' : '').$nom);
}
```

- [ ] **Step 8: Modifier `compteDeResultatOperations` pour utiliser les nouvelles méthodes**

Remplacer la méthode existante (lignes 45-56) :

```php
/**
 * Compte de résultat filtré par opérations, avec dimensions optionnelles.
 *
 * @param  array<int>  $operationIds
 * @return array{charges: list<array>, produits: list<array>, seances?: list<int>}
 */
public function compteDeResultatOperations(
    int $exercice,
    array $operationIds,
    bool $parSeances = false,
    bool $parTiers = false,
): array {
    [$start, $end] = $this->exerciceDates($exercice);

    $chargesMap = $this->fetchOperationRows('depense', $start, $end, $operationIds, $parSeances, $parTiers);
    $produitsMap = $this->fetchOperationRows('recette', $start, $end, $operationIds, $parSeances, $parTiers);

    $allSeances = [];
    if ($parSeances) {
        $allSeances = collect(array_merge(array_values($chargesMap), array_values($produitsMap)))
            ->pluck('seance')
            ->unique()
            ->map(fn ($s) => (int) $s)
            ->sort()
            ->values()
            ->all();
    }

    $result = [
        'charges' => $this->buildHierarchyOperations($chargesMap, $parSeances, $parTiers, $allSeances),
        'produits' => $this->buildHierarchyOperations($produitsMap, $parSeances, $parTiers, $allSeances),
    ];

    if ($parSeances) {
        $result['seances'] = $allSeances;
    }

    return $result;
}
```

- [ ] **Step 9: Exécuter tous les tests du service**

Run: `./vendor/bin/sail test tests/Unit/RapportServiceTest.php -v`
Expected: PASS (tous les tests, anciens et nouveaux)

- [ ] **Step 10: Commit**

```bash
git add app/Services/RapportService.php tests/Unit/RapportServiceTest.php
git commit -m "feat(rapports): enrichir compteDeResultatOperations avec dimensions séances/tiers"
```

---

### Task 2: Composant Livewire — Refonte (TDD)

**Files:**
- Modify: `app/Livewire/RapportCompteResultatOperations.php`
- Modify: `tests/Livewire/RapportCompteResultatOperationsTest.php`

- [ ] **Step 1: Mettre à jour les tests existants et ajouter les nouveaux**

Réécrire `tests/Livewire/RapportCompteResultatOperationsTest.php` :

```php
<?php

use App\Livewire\RapportCompteResultatOperations;
use App\Models\Categorie;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
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

it('se rend avec l\'arbre hiérarchique d\'opérations', function () {
    $op = Operation::factory()->create(['nom' => 'Festival été']);

    Livewire::test(RapportCompteResultatOperations::class)
        ->assertOk()
        ->assertViewHas('operationTree');
});

it('affiche un message si aucune opération sélectionnée', function () {
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSeeHtml('lectionnez');
});

it('affiche les données filtrées par opération', function () {
    $op = Operation::factory()->create();
    $cat = Categorie::factory()->depense()->create(['nom' => 'Frais']);
    $sc = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Transport']);

    $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 100.00]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->assertSee('Transport')
        ->assertSee('100,00');
});

it('supporte parSeances via query string', function () {
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSet('parSeances', false)
        ->set('parSeances', true)
        ->assertSet('parSeances', true);
});

it('supporte parTiers via query string', function () {
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSet('parTiers', false)
        ->set('parTiers', true)
        ->assertSet('parTiers', true);
});

it('passe les données tiers quand parTiers est actif', function () {
    $op = Operation::factory()->create();
    $cat = Categorie::factory()->depense()->create(['nom' => 'Frais']);
    $sc = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Transport']);
    $tiers = Tiers::factory()->create(['type' => 'particulier', 'nom' => 'dupont', 'prenom' => 'Jean']);

    $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'tiers_id' => $tiers->id, 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 100.00]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->set('parTiers', true)
        ->assertSee('DUPONT');
});
```

- [ ] **Step 2: Exécuter les tests — vérifier qu'ils échouent**

Run: `./vendor/bin/sail test tests/Livewire/RapportCompteResultatOperationsTest.php -v`
Expected: FAIL (composant pas encore refait)

- [ ] **Step 3: Réécrire le composant Livewire**

Remplacer le contenu complet de `app/Livewire/RapportCompteResultatOperations.php` :

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Models\TypeOperation;
use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Attributes\Url;
use Livewire\Component;

final class RapportCompteResultatOperations extends Component
{
    /** @var array<int, int> */
    #[Url(as: 'ops')]
    public array $selectedOperationIds = [];

    #[Url(as: 'seances')]
    public bool $parSeances = false;

    #[Url(as: 'tiers')]
    public bool $parTiers = false;

    public function render(): mixed
    {
        $exercice = app(ExerciceService::class)->current();

        $operationTree = $this->buildOperationTree($exercice);

        $charges = [];
        $produits = [];
        $seances = [];
        $totalCharges = 0.0;
        $totalProduits = 0.0;
        $hasSelection = ! empty($this->selectedOperationIds);

        if ($hasSelection) {
            $data = app(RapportService::class)->compteDeResultatOperations(
                $exercice,
                $this->selectedOperationIds,
                $this->parSeances,
                $this->parTiers,
            );
            $charges = $data['charges'];
            $produits = $data['produits'];
            $seances = $data['seances'] ?? [];
            $totalCharges = $this->parSeances
                ? collect($charges)->sum('total')
                : collect($charges)->sum('montant');
            $totalProduits = $this->parSeances
                ? collect($produits)->sum('total')
                : collect($produits)->sum('montant');
        }

        return view('livewire.rapport-compte-resultat-operations', [
            'operationTree' => $operationTree,
            'charges' => $charges,
            'produits' => $produits,
            'seances' => $seances,
            'totalCharges' => $totalCharges,
            'totalProduits' => $totalProduits,
            'resultatNet' => $totalProduits - $totalCharges,
            'hasSelection' => $hasSelection,
        ]);
    }

    /** @return list<array{id: int, nom: string, types: list<array>}> */
    private function buildOperationTree(int $exercice): array
    {
        $typeOperations = TypeOperation::actif()
            ->with(['sousCategorie', 'operations' => fn ($q) => $q->forExercice($exercice)->orderBy('nom')])
            ->orderBy('nom')
            ->get();

        $tree = [];
        foreach ($typeOperations as $type) {
            if ($type->operations->isEmpty()) {
                continue;
            }
            $scId = $type->sous_categorie_id;
            if (! isset($tree[$scId])) {
                $tree[$scId] = [
                    'id' => $scId,
                    'nom' => $type->sousCategorie->nom,
                    'types' => [],
                ];
            }
            $tree[$scId]['types'][] = [
                'id' => $type->id,
                'nom' => $type->nom,
                'operations' => $type->operations
                    ->map(fn ($op) => ['id' => $op->id, 'nom' => $op->nom])
                    ->values()
                    ->all(),
            ];
        }

        return collect($tree)->sortBy('nom')->values()->all();
    }
}
```

- [ ] **Step 4: Exécuter les tests du composant**

Run: `./vendor/bin/sail test tests/Livewire/RapportCompteResultatOperationsTest.php -v`
Expected: PASS (le blade n'est pas encore refait mais les assertions portent sur les données et propriétés)

Note : si des tests échouent à cause du blade (variables manquantes dans le template), passer à la Task 3 d'abord puis revenir.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/RapportCompteResultatOperations.php tests/Livewire/RapportCompteResultatOperationsTest.php
git commit -m "feat(rapports): refonte composant CR opérations (arbre, parSeances, parTiers)"
```

---

### Task 3: Blade — Barre de filtres + dropdown hiérarchique

**Files:**
- Modify: `resources/views/livewire/rapport-compte-resultat-operations.blade.php`

- [ ] **Step 1: Écrire la barre de filtres avec le dropdown Alpine.js**

Remplacer tout le contenu du blade. Écrire d'abord la section filtres :

```blade
<div>
    {{-- Barre de filtres --}}
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                {{-- Dropdown hiérarchique --}}
                <div x-data="{
                    selectedIds: @entangle('selectedOperationIds').live,
                    open: false,
                    tree: @js($operationTree),

                    init() {
                        if (this.selectedIds.length === 0) {
                            this.$nextTick(() => { this.open = true; });
                        }
                    },

                    toggleOp(id) {
                        const idx = this.selectedIds.indexOf(id);
                        if (idx > -1) {
                            this.selectedIds = this.selectedIds.filter(i => i !== id);
                        } else {
                            this.selectedIds = [...this.selectedIds, id];
                        }
                    },

                    toggleGroup(opIds) {
                        const allIn = opIds.every(id => this.selectedIds.includes(id));
                        if (allIn) {
                            this.selectedIds = this.selectedIds.filter(id => !opIds.includes(id));
                        } else {
                            const newIds = [...this.selectedIds];
                            opIds.forEach(id => { if (!newIds.includes(id)) newIds.push(id); });
                            this.selectedIds = newIds;
                        }
                    },

                    groupState(opIds) {
                        const count = opIds.filter(id => this.selectedIds.includes(id)).length;
                        if (count === 0) return 'none';
                        if (count === opIds.length) return 'all';
                        return 'partial';
                    },

                    get label() {
                        const n = this.selectedIds.length;
                        if (n === 0) return 'S\u00e9lectionnez des op\u00e9rations...';
                        if (n === 1) {
                            for (const sc of this.tree) {
                                for (const t of sc.types) {
                                    for (const o of t.operations) {
                                        if (o.id === this.selectedIds[0]) return o.nom;
                                    }
                                }
                            }
                        }
                        return n + ' op\u00e9rations s\u00e9lectionn\u00e9es';
                    },
                }" class="dropdown" @click.outside="open = false">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                            @click="open = !open" x-text="label" style="min-width:220px;text-align:left;"></button>
                    <div class="dropdown-menu p-2" :class="{ show: open }"
                         style="min-width:320px;max-height:400px;overflow-y:auto;">
                        <template x-for="sc in tree" :key="sc.id">
                            <div class="mb-2">
                                {{-- Niveau sous-catégorie --}}
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input"
                                           :checked="groupState(sc.types.flatMap(t => t.operations.map(o => o.id))) === 'all'"
                                           :indeterminate="groupState(sc.types.flatMap(t => t.operations.map(o => o.id))) === 'partial'"
                                           @change="toggleGroup(sc.types.flatMap(t => t.operations.map(o => o.id)))">
                                    <label class="form-check-label fw-bold text-muted small" x-text="sc.nom"></label>
                                </div>
                                <template x-for="type in sc.types" :key="type.id">
                                    <div class="ms-3">
                                        {{-- Niveau type opération --}}
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input"
                                                   :checked="groupState(type.operations.map(o => o.id)) === 'all'"
                                                   :indeterminate="groupState(type.operations.map(o => o.id)) === 'partial'"
                                                   @change="toggleGroup(type.operations.map(o => o.id))">
                                            <label class="form-check-label fw-semibold small" x-text="type.nom"></label>
                                        </div>
                                        {{-- Niveau opérations --}}
                                        <template x-for="op in type.operations" :key="op.id">
                                            <div class="form-check ms-3">
                                                <input type="checkbox" class="form-check-input"
                                                       :checked="selectedIds.includes(op.id)"
                                                       @change="toggleOp(op.id)">
                                                <label class="form-check-label small" x-text="op.nom"></label>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Toggles --}}
                <div class="form-check form-switch mb-0">
                    <input type="checkbox" wire:model.live="parSeances" class="form-check-input" id="toggleSeances">
                    <label class="form-check-label small" for="toggleSeances">S&eacute;ances en colonnes</label>
                </div>
                <div class="form-check form-switch mb-0">
                    <input type="checkbox" wire:model.live="parTiers" class="form-check-input" id="toggleTiers">
                    <label class="form-check-label small" for="toggleTiers">Tiers en lignes</label>
                </div>
            </div>
        </div>
    </div>

    {{-- Contenu du rapport (Task 4) --}}
</div>
```

- [ ] **Step 2: Commit partiel (filtres)**

```bash
git add resources/views/livewire/rapport-compte-resultat-operations.blade.php
git commit -m "feat(rapports): dropdown hiérarchique + toggles séances/tiers"
```

---

### Task 4: Blade — Rendu conditionnel du tableau (4 modes)

**Files:**
- Modify: `resources/views/livewire/rapport-compte-resultat-operations.blade.php`

- [ ] **Step 1: Ajouter le rendu du tableau après la barre de filtres**

Après le commentaire `{{-- Contenu du rapport (Task 4) --}}`, ajouter :

```blade
    @if (! $hasSelection)
        <p class="text-muted text-center py-4">S&eacute;lectionnez au moins une op&eacute;ration pour afficher le rapport.</p>
    @else
        @foreach ([
            ['data' => $charges, 'label' => 'DÉPENSES', 'totalMontant' => $totalCharges],
            ['data' => $produits, 'label' => 'RECETTES', 'totalMontant' => $totalProduits],
        ] as $section)
        <div class="card mb-3 border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table mb-0" style="font-size:13px;border-collapse:collapse;width:100%;">
                    <tbody>
                        {{-- En-tête colonnes --}}
                        @if ($parSeances)
                            <tr style="background:#3d5473;color:#fff;">
                                <td style="width:20px;"></td>
                                <td></td>
                                @foreach ($seances as $s)
                                    <td class="text-end" style="width:90px;font-size:11px;opacity:.85;padding:4px 8px;">
                                        {{ $s === 0 ? 'Hors séances' : 'S'.$s }}
                                    </td>
                                @endforeach
                                <td class="text-end" style="width:90px;font-size:11px;opacity:.85;padding:4px 8px;">Total</td>
                            </tr>
                        @else
                            <tr style="background:#3d5473;color:#fff;">
                                <td style="width:20px;"></td>
                                <td></td>
                                <td class="text-end" style="width:130px;font-size:12px;opacity:.85;">Montant</td>
                            </tr>
                        @endif
                        {{-- Titre section --}}
                        <tr style="background:#3d5473;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="{{ $parSeances ? count($seances) + 3 : 3 }}" style="padding:4px 12px 10px;">
                                {{ $section['label'] }}
                            </td>
                        </tr>

                        @foreach ($section['data'] as $cat)
                            @php
                                $scVisibles = collect($cat['sous_categories'])->filter(fn($sc) =>
                                    ($parSeances ? ($sc['total'] ?? 0) : ($sc['montant'] ?? 0)) > 0
                                );
                            @endphp
                            @if (! $scVisibles->isEmpty())
                                {{-- Ligne catégorie --}}
                                <tr style="background:#dce6f0;">
                                    <td></td>
                                    <td style="font-weight:600;color:#1e3a5f;padding:7px 12px;">{{ $cat['label'] }}</td>
                                    @if ($parSeances)
                                        @foreach ($seances as $s)
                                            <td class="text-end fw-bold" style="padding:7px 8px;">
                                                @if (($cat['seances'][$s] ?? 0) > 0)
                                                    {{ number_format($cat['seances'][$s], 2, ',', ' ') }} &euro;
                                                @else
                                                    &mdash;
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="text-end fw-bold" style="padding:7px 8px;">{{ number_format($cat['total'], 2, ',', ' ') }} &euro;</td>
                                    @else
                                        <td class="text-end fw-bold" style="padding:7px 12px;">{{ number_format($cat['montant'], 2, ',', ' ') }} &euro;</td>
                                    @endif
                                </tr>

                                @foreach ($scVisibles as $sc)
                                    {{-- Ligne sous-catégorie --}}
                                    <tr style="background:#f7f9fc;">
                                        <td></td>
                                        <td style="padding:5px 12px 5px 32px;color:#444;">{{ $sc['label'] }}</td>
                                        @if ($parSeances)
                                            @foreach ($seances as $s)
                                                <td class="text-end" style="padding:5px 8px;color:#444;">
                                                    @if (($sc['seances'][$s] ?? 0) > 0)
                                                        {{ number_format($sc['seances'][$s], 2, ',', ' ') }} &euro;
                                                    @else
                                                        &mdash;
                                                    @endif
                                                </td>
                                            @endforeach
                                            <td class="text-end fw-bold" style="padding:5px 8px;">{{ number_format($sc['total'], 2, ',', ' ') }} &euro;</td>
                                        @else
                                            <td class="text-end" style="padding:5px 12px;color:#444;">{{ number_format($sc['montant'], 2, ',', ' ') }} &euro;</td>
                                        @endif
                                    </tr>

                                    {{-- Lignes tiers (si activé) --}}
                                    @if ($parTiers && ! empty($sc['tiers']))
                                        @foreach ($sc['tiers'] as $t)
                                            @if (($parSeances ? ($t['total'] ?? 0) : ($t['montant'] ?? 0)) > 0)
                                            <tr style="background:#fff;">
                                                <td></td>
                                                <td style="padding:4px 12px 4px 52px;color:#666;font-size:12px;">
                                                    @if ($t['type'] === 'entreprise')
                                                        <i class="bi bi-building text-muted" style="font-size:.65rem"></i>
                                                    @elseif ($t['type'] === 'particulier')
                                                        <i class="bi bi-person text-muted" style="font-size:.65rem"></i>
                                                    @endif
                                                    @if ($t['tiers_id'] === 0)
                                                        <em>{{ $t['label'] }}</em>
                                                    @else
                                                        {{ $t['label'] }}
                                                    @endif
                                                </td>
                                                @if ($parSeances)
                                                    @foreach ($seances as $s)
                                                        <td class="text-end" style="padding:4px 8px;color:#888;font-size:12px;">
                                                            @if (($t['seances'][$s] ?? 0) > 0)
                                                                {{ number_format($t['seances'][$s], 2, ',', ' ') }} &euro;
                                                            @else
                                                                &mdash;
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                    <td class="text-end" style="padding:4px 8px;color:#666;font-size:12px;">{{ number_format($t['total'], 2, ',', ' ') }} &euro;</td>
                                                @else
                                                    <td class="text-end" style="padding:4px 12px;color:#666;font-size:12px;">{{ number_format($t['montant'], 2, ',', ' ') }} &euro;</td>
                                                @endif
                                            </tr>
                                            @endif
                                        @endforeach
                                    @endif
                                @endforeach
                            @endif
                        @endforeach

                        {{-- Ligne total section --}}
                        @php
                            $totalSectionSeances = [];
                            if ($parSeances) {
                                $totalSectionSeances = array_fill_keys($seances, 0.0);
                                foreach ($section['data'] as $cat) {
                                    foreach ($seances as $s) {
                                        $totalSectionSeances[$s] += $cat['seances'][$s] ?? 0.0;
                                    }
                                }
                            }
                        @endphp
                        <tr style="background:#5a7fa8;color:#fff;font-weight:700;font-size:14px;">
                            <td colspan="2" style="padding:9px 12px;">TOTAL {{ $section['label'] }}</td>
                            @if ($parSeances)
                                @foreach ($seances as $s)
                                    <td class="text-end" style="padding:9px 8px;">{{ number_format($totalSectionSeances[$s], 2, ',', ' ') }} &euro;</td>
                                @endforeach
                                <td class="text-end" style="padding:9px 8px;">{{ number_format($section['totalMontant'], 2, ',', ' ') }} &euro;</td>
                            @else
                                <td class="text-end" style="padding:9px 12px;">{{ number_format($section['totalMontant'], 2, ',', ' ') }} &euro;</td>
                            @endif
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @endforeach

        {{-- Barre résultat net --}}
        <div class="rounded p-4 d-flex justify-content-between align-items-center mt-2"
             style="background:{{ $resultatNet >= 0 ? '#2E7D32' : '#B5453A' }};color:#fff;font-size:1.1rem;font-weight:700;">
            <span>{{ $resultatNet >= 0 ? 'EXCÉDENT' : 'DÉFICIT' }}</span>
            <span>{{ number_format(abs($resultatNet), 2, ',', ' ') }} &euro;</span>
        </div>
    @endif
```

- [ ] **Step 2: Exécuter les tests du composant**

Run: `./vendor/bin/sail test tests/Livewire/RapportCompteResultatOperationsTest.php -v`
Expected: PASS

- [ ] **Step 3: Exécuter tous les tests**

Run: `./vendor/bin/sail test -v`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add resources/views/livewire/rapport-compte-resultat-operations.blade.php
git commit -m "feat(rapports): rendu tableau CR avec séances en colonnes et tiers en lignes"
```

---

### Task 5: Vérification visuelle + ajustements

**Files:**
- Possiblement: `app/Livewire/RapportCompteResultatOperations.php`
- Possiblement: `resources/views/livewire/rapport-compte-resultat-operations.blade.php`

- [ ] **Step 1: Lancer l'application**

Run: `./vendor/bin/sail up -d`
Tester dans le navigateur : `http://localhost/compta/rapports/operations`

- [ ] **Step 2: Vérifier le dropdown hiérarchique**

Vérifier :
- [ ] Le dropdown s'ouvre automatiquement au premier chargement
- [ ] La hiérarchie Sous-catégorie → Type opération → Opération s'affiche
- [ ] Cocher un type coche toutes ses opérations
- [ ] Cocher une sous-catégorie coche tous ses descendants
- [ ] L'état indéterminé (tiret) apparaît quand certains enfants sont cochés
- [ ] Le dropdown reste ouvert au clic intérieur
- [ ] Le bouton affiche le bon label (nom si 1, "N opérations" si >1)
- [ ] Le dropdown se ferme au clic extérieur

- [ ] **Step 3: Vérifier le mode simple (aucun toggle)**

- [ ] Le tableau s'affiche comme avant (une colonne Montant)
- [ ] Catégories → sous-catégories → montants corrects

- [ ] **Step 4: Vérifier le toggle séances**

- [ ] Les colonnes Hors séances, S1, S2, ..., Total apparaissent
- [ ] Les montants sont correctement ventilés
- [ ] Les cellules à zéro affichent "—"
- [ ] Les totaux catégorie sont ventilés par séance

- [ ] **Step 5: Vérifier le toggle tiers**

- [ ] Le 3e niveau d'indentation apparaît sous chaque sous-catégorie
- [ ] Les icônes `bi-person` / `bi-building` sont affichées
- [ ] "(sans tiers)" en italique pour les transactions sans tiers
- [ ] Tri alphabétique des tiers

- [ ] **Step 6: Vérifier les deux toggles combinés**

- [ ] Matrice complète : Cat → Sous-cat → Tiers × colonnes séances
- [ ] Les totaux par séance sont corrects à chaque niveau

- [ ] **Step 7: Vérifier la persistance query string**

- [ ] Sélectionner des opérations → l'URL contient `?ops=...`
- [ ] Activer les toggles → l'URL contient `&seances=1&tiers=1`
- [ ] Copier l'URL, ouvrir dans nouvel onglet → même état restauré

- [ ] **Step 8: Appliquer Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 9: Commit final**

```bash
git add -A
git commit -m "style: pint + ajustements visuels lot 2 CR opérations"
```
