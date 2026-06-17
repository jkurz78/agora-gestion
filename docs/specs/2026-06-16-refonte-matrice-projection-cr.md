# Refonte projection CR par opérations — Matrice pré-calculée

**Date** : 2026-06-16
**Branche** : `main` (V4 production)
**Composant** : `RapportCompteResultatOperations` + `CompteResultatBuilder`
**Prérequis** : spec initiale `2026-06-15-projection-cr-operations.md` (livrée)

## 1. Problème

La logique de projection (`réel > 0 ? réel : prévu`) est actuellement dupliquée dans **4 endroits** :

| Lieu | Mécanisme | Granularité |
|------|-----------|-------------|
| `CompteResultatBuilder::computeProjections()` | Pré-calcul PHP au grain `(sc, tiers, séance)` | SC, catégorie, section, total |
| Blade `$projeter()` closure | Inline dans le template | Tiers (agrégé, viole non-distributivité) |
| Excel `RapportExportController` | Mix de `$projections[…]` et logique inline | SC, catégorie (OK), tiers (absent) |
| PDF `rapport-operations.blade.php` | Mix de `$projections[…]` et logique inline | SC (OK), catégorie/séance (partiel) |

Cette duplication a causé **8 bugs** corrigés manuellement et nécessité **3 audits parallèles** pour valider la cohérence. Chaque nouvelle combinaison d'axes (séances × opérations × tiers) multiplie les chemins de code.

### 1.1 Axes actuels et contrainte d'exclusivité

| Axe | Disposition | Combinable avec |
|-----|-----------|----------------|
| Séances en colonnes (`parSeances`) | Colonnes S1, S2… + Total | ~~parOperations~~ ← **contrainte à supprimer** |
| Opérations en colonnes (`parOperations`) | Colonnes Op1, Op2… + Total | ~~parSeances~~ ← **contrainte à supprimer** |
| Tiers en lignes (`parTiers`) | Sous-lignes par tiers | Tout |

**Objectif** : supprimer la contrainte d'exclusivité parSeances/parOperations. Le mode `parSeances + parOperations` affiche les opérations en colonnes avec des sous-lignes par séance dans chaque SC.

## 2. Architecture cible

### 2.1 Principe : « Calculer une fois, agréger partout »

```
┌─────────────────────────────────────────────────┐
│            CompteResultatBuilder                 │
│                                                  │
│  1. Fetch réel au grain (sc, tiers, séance, op)  │
│  2. Fetch prévu au grain (sc, tiers, séance, op) │
│  3. Appliquer projection cellule par cellule     │
│  4. Retourner ProjectionMatrix                   │
└──────────────────────┬──────────────────────────┘
                       │
                       ▼
              ProjectionMatrix
       [scId][tiersId][seance][opId] = float
                       │
        ┌──────────────┼──────────────┐
        ▼              ▼              ▼
     Blade           Excel           PDF
   (agrège)        (agrège)       (agrège)
```

### 2.2 La classe `ProjectionMatrix`

Nouveau value object dans `app/Services/Rapports/ProjectionMatrix.php`.

```php
final class ProjectionMatrix
{
    // Cellules projetées : cells[scId][tiersId][seance][opId] = float
    private array $cells = [];

    // Mapping sc → cat pour agrégation
    private array $scToCat = [];

    public function set(int $scId, int $tiersId, int $seance, int $opId, float $value): void;

    // ── Agrégations ───────────────────────────────────

    /** Total global */
    public function total(): float;

    /** Par sous-catégorie (somme sur tiers × séance × op) */
    public function bySc(): array;  // [scId => float]

    /** Par catégorie */
    public function byCat(): array;  // [catId => float]

    /** Par sous-catégorie × séance */
    public function byScSeance(): array;  // [scId][seance] => float

    /** Par sous-catégorie × opération */
    public function byScOp(): array;  // [scId][opId] => float

    /** Par catégorie × opération */
    public function byCatOp(): array;  // [catId][opId] => float

    /** Par opération (section total) */
    public function byOp(): array;  // [opId => float]

    /** Par sous-catégorie × séance × opération */
    public function byScSeanceOp(): array;  // [scId][seance][opId] => float

    /** Par tiers dans une SC */
    public function byScTiers(int $scId): array;  // [tiersId => float]

    /** Par tiers × séance dans une SC */
    public function byScTiersSeance(int $scId): array;  // [tiersId][seance] => float

    /** Par tiers × opération dans une SC */
    public function byScTiersOp(int $scId): array;  // [tiersId][opId] => float
}
```

Chaque méthode d'agrégation calcule et met en cache le résultat au premier appel.

### 2.3 Construction de la matrice dans `computeProjections`

Le Builder actuel fetch déjà le réel au grain `(sc, tiers, séance)` et le prévu au même grain. L'évolution :

1. **Ajouter l'axe opération** : `fetchOperationRows` reçoit un flag `$withOperation` qui ajoute `operation_id` au SELECT/GROUP BY. Les prévisions ont déjà `operation_id` quand `$parOperations=true`.

2. **Supprimer la récursion** : plus de boucle `foreach ($operationIds)` avec appel récursif à `computeProjections([$opId])`. Une seule passe au grain `(sc, tiers, séance, op)`.

3. **Appliquer la projection** : `$reel > 0 ? $reel : $prevu` sur chaque cellule de la matrice.

4. **Retourner** : `['charges' => ProjectionMatrix, 'produits' => ProjectionMatrix]`.

### 2.4 Données retournées par `compteDeResultatOperations`

```php
return [
    'charges'  => $chargesHierarchy,   // hiérarchie réalisée (inchangée)
    'produits' => $produitsHierarchy,  // hiérarchie réalisée (inchangée)
    'seances'  => $allSeances,
    'operation_names' => $operationNames,

    // Ancien format (supprimé) :
    // 'projections' => ['charges' => ['sc' => [...], 'cat' => [...], ...], ...]
    // 'previsions_charges' => [...],
    // 'previsions_produits' => [...],

    // Nouveau format :
    'proj_charges'  => ProjectionMatrix,   // null si mode=realise
    'proj_produits' => ProjectionMatrix,   // null si mode=realise
    'prev_charges'  => array,              // hiérarchie prévisions (mode=comparaison)
    'prev_produits' => array,              // hiérarchie prévisions (mode=comparaison)
];
```

### 2.5 Merge prévision-only dans la hiérarchie réalisée

Actuellement fait par `$mergeForDisplay` dans le Blade et dans l'export controller (C2 de l'audit). **Déplacé dans le Builder** : quand `$previsionnel=true`, le Builder merge les catégories/SC prévision-only dans la hiérarchie réalisée avant de la retourner. Les templates n'ont plus à s'en occuper.

## 3. Nouveau mode : parSeances + parOperations

### 3.1 Disposition du tableau

Quand les deux sont actifs, les opérations restent en colonnes et les séances deviennent des **sous-lignes** dans chaque sous-catégorie :

```
                    │  Op. Yoga  │  Op. Théâtre  │  Total  │
────────────────────┼────────────┼───────────────┼─────────┤
Encadrement         │            │               │         │
  Séance 1          │    120 €   │     80 €      │  200 €  │
  Séance 2          │    130 €   │     90 €      │  220 €  │
  Séance 3          │    125 €   │      — €      │  125 €  │
  Hors séance       │     50 €   │     30 €      │   80 €  │
  TOTAL             │    425 €   │    200 €      │  625 €  │
```

### 3.2 Agrégation depuis la matrice

- Cellule `(séance S, opération O)` dans SC `X` = `proj.byScSeanceOp()[X][S][O]`
- Total séance (colonne Total) = `sum(proj.byScSeanceOp()[X][S][*])`
- Total SC par opération = `proj.byScOp()[X][O]`
- Total SC global = `proj.bySc()[X]`

Pas de logique de projection dans le template — juste des lectures de la matrice.

### 3.3 Mode comparaison avec parSeances + parOperations

Chaque cellule devient un triplet (prévu / réel / écart). La colonne Total par opération résume. Les prévisions sont lues depuis la hiérarchie `prev_charges`/`prev_produits` indexée de la même façon.

### 3.4 Tiers en sous-lignes (parTiers + parSeances + parOperations)

Les tiers s'intercalent entre la dernière séance et le TOTAL de chaque SC. Chaque tiers montre ses montants par opération, sommés sur les séances :

```
  TOTAL             │    425 €   │    200 €      │  625 €  │
    ↳ DUPONT        │    300 €   │    150 €      │  450 €  │
    ↳ MARTIN        │    125 €   │     50 €      │  175 €  │
```

Les tiers ne sont **pas** ventilés par séance (trop de cellules, peu de valeur). Ils montrent le total par opération via `proj.byScTiersOp(scId)`.

## 4. Impact sur les vues

### 4.1 Blade `rapport-compte-resultat-operations.blade.php`

**Supprimé** :
- Closure `$projeter()`
- Closure `$isProjectionPrevu()`
- Closure `$mergeForDisplay()`
- Closure `$indexPrevisions()`
- Variables `$projCharges`, `$projProduits`, `$idxPrevCharges`, `$idxPrevProduits`
- Toute logique `if ($mode === 'projection')` qui recalcule des valeurs

**Ajouté** :
- Lecture directe de `$projCharges->bySc()`, `$projCharges->byScSeance()`, etc.
- Nouveau bloc `@if ($parSeances && $parOperations)` pour le mode combiné

### 4.2 Excel `RapportExportController::xlsxOperations()`

**Supprimé** :
- Variable `$projeter` (closure)
- Variable `$projections` (ancien format dict)
- Closure `$mergeForDisplay()` (déjà dans le Builder)
- Closure `$buildPrevIdx()` (remplacée par la matrice)

**Simplifié** :
- Les valeurs projetées sont lues depuis `$projCharges->byScOp()[$scId][$opId]` etc.
- Le bloc parOperations et le bloc standard partagent la même source de données

### 4.3 PDF `rapport-operations.blade.php`

Même simplification que le Blade. Suppression de toute logique de projection inline.

### 4.4 Livewire `RapportCompteResultatOperations.php`

**Supprimé** :
- `updatedParOperations()` (contrainte d'exclusivité)
- `updatedParSeances()` (contrainte d'exclusivité)

**Ajouté** :
- Les données `proj_charges` / `proj_produits` sont passées à la vue

Le calcul `$totalCharges` / `$totalProduits` en mode projection utilise `$projCharges->total()` au lieu de `collect($data)->sum('montant')`.

## 5. Impact sur le Builder

### 5.1 Méthodes modifiées

| Méthode | Changement |
|---------|-----------|
| `computeProjections()` | Retourne `[ProjectionMatrix, ProjectionMatrix]` au lieu d'un dict. Grain `(sc, tiers, séance, op)`. Plus de récursion. |
| `fetchOperationRows()` | Nouveau flag `$withOperation` (ajout `operation_id` au SELECT/GROUP BY) |
| `buildPrevisionsCharges()` | Appelé avec `parSeances=true, parTiers=true, parOperations=true` systématiquement pour la matrice |
| `buildPrevisionsProduits()` | Idem |
| `compteDeResultatOperations()` | Merge prévision-only dans la hiérarchie avant retour. Retourne `ProjectionMatrix` au lieu de dict `projections`. |

### 5.2 Méthodes supprimées

Aucune. Les `fetchDepenseParOperationRows` / `fetchRecetteParOperationRows` restent pour la hiérarchie réalisée en mode parOperations (données brutes).

### 5.3 Performance

**Avant** : `computeProjections` fait 7 queries × N opérations (récursion) = **7N queries**.

**Après** : 1 fetch réel au grain `(sc, tiers, séance, op)` + 1 fetch prévu = **2 queries** (+ les queries existantes pour la hiérarchie réalisée).

Le flag `$withOperation` ajoute `operation_id` au GROUP BY, ce qui augmente le nombre de lignes retournées mais reste une seule query. Gain net significatif : O(7N) → O(1).

## 6. Tests

### 6.1 Tests unitaires `ProjectionMatrix`

- `test_set_and_total` : set 3 cellules, vérifier total
- `test_bySc_aggregation` : 2 SC avec plusieurs tiers/séances/ops, vérifier sommes
- `test_byCat_uses_scToCat_mapping` : vérifier agrégation catégorie
- `test_byScSeanceOp` : vérifier la combinaison 3 axes
- `test_empty_matrix_returns_zeros`
- `test_caching_returns_same_result`

### 6.2 Tests d'intégration Builder

- `test_projection_matrix_vs_legacy` : comparer les totaux `ProjectionMatrix::bySc()` avec l'ancien `computeProjections()['sc']` sur un jeu de données réel
- `test_projection_parSeances_parOperations` : vérifier que le mode combiné fonctionne
- `test_prevision_only_categories_merged` : vérifier qu'une catégorie sans réalisé apparaît dans la hiérarchie

### 6.3 Tests existants

Les 12 tests `RapportExportTest` existants doivent continuer à passer. Ils vérifient que les exports retournent un 200 OK avec le bon Content-Type.

## 7. Découpage

| Step | Contenu | Vérification |
|------|---------|-------------|
| 1 | Créer `ProjectionMatrix` avec tests unitaires | `php artisan test --filter=ProjectionMatrix` |
| 2 | Modifier `fetchOperationRows` : flag `$withOperation` | Tests existants passent |
| 3 | Refondre `computeProjections` pour retourner `ProjectionMatrix` | Test de non-régression `projection_matrix_vs_legacy` |
| 4 | Merge prévision-only dans le Builder (`compteDeResultatOperations`) | Test `prevision_only_categories_merged` |
| 5 | Adapter Livewire (suppression exclusivité, passage `ProjectionMatrix`) | Tests existants passent |
| 6 | Refondre Blade — supprimer projection inline, lire la matrice | Test navigateur |
| 7 | Refondre Excel — supprimer projection inline, lire la matrice | `RapportExportTest` passe |
| 8 | Refondre PDF — supprimer projection inline, lire la matrice | `RapportExportTest` passe |
| 9 | Nouveau bloc Blade : parSeances + parOperations combiné | Test navigateur |
| 10 | Adapter Excel et PDF pour parSeances + parOperations | Test navigateur + export |

## 8. Risques et mitigations

| Risque | Mitigation |
|--------|-----------|
| Mismatch tiers charges (W4 audit) : `encadrement_previsions.tiers_id` ≠ `transactions.tiers_id` | Localisé dans `computeProjections` au lieu de 4 endroits. Le problème domaine reste, mais son impact est visible et corrigible à un seul endroit. |
| Régression sur les totaux | Step 3 inclut un test de non-régression qui compare l'ancien et le nouveau calcul. |
| Matrice très grande (beaucoup de tiers × séances × opérations) | En pratique : ~10 SC × ~50 tiers × ~15 séances × ~5 opérations = 37 500 cellules. Négligeable en mémoire. |
| PDF/Excel en mode parSeances + parOperations très large | Adapter le sizing adaptatif existant (lignes 16-35 du PDF). |

## 9. Hors périmètre

- Refactoring des 6 méthodes fetch dupliquées (I1/I2 de l'audit) — dette technique séparée
- Optimisation cache des résultats entre renders Livewire (I5 de l'audit)
- Tiers ventilés par séance en mode parTiers + parSeances + parOperations (jugé trop dense)
