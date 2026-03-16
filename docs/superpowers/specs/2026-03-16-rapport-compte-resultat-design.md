# Spec : Refonte de la page Rapports (3 onglets)

**Date :** 2026-03-16
**Statut :** En révision

---

## Périmètre

Refonte complète de la page `/rapports` qui passe de 2 à 3 onglets :

| Onglet | Composant Livewire | Statut |
|--------|-------------------|--------|
| Compte de résultat | `RapportCompteResultat` | Refonte |
| Compte de résultat par opération(s) | `RapportCompteResultatOperations` | Nouveau |
| Rapport par séances | `RapportSeances` | Refonte |

---

## Ruptures avec l'existant

- **`code_cerfa`** : le rapport actuel groupe par `code_cerfa`. La refonte groupe par `categorie` / `sous_categorie`. Le `code_cerfa` disparaît de toutes les vues et de tous les CSV.
- **Rapport par séances** : l'existant détermine les colonnes depuis `$operation->nombre_seances`. La refonte utilise les numéros de séances effectivement présents dans les données (`DISTINCT seance`). Une séance sans transaction n'apparaît plus comme colonne.
- **Rapport par séances** : la ligne "Solde" (produits − dépenses par séance) de l'existant est supprimée. Le rapport se termine par la case EXCÉDENT/DÉFICIT commune.

---

## Structure commune aux trois rapports

Les trois rapports partagent **exactement la même ossature de lignes** :

### Sections

1. Section **DÉPENSES** : en-tête bleu `#3d5473` + lignes catégories/sous-catégories + ligne TOTAL DÉPENSES
2. Section **RECETTES** : même structure
3. **Résultat final** : `<div>` séparé, vert (EXCÉDENT) ou rouge (DÉFICIT), montant exercice en cours uniquement

### Types de lignes

- **En-tête de section** (fond `#3d5473`, texte blanc) : deux lignes TR — ligne 1 : labels colonnes, ligne 2 : titre DÉPENSES ou RECETTES
- **Catégorie** (fond `#dce6f0`, texte `#1e3a5f`, gras) : somme de ses sous-catégories
- **Sous-catégorie** (fond `#f7f9fc`, indentation 32 px)
- **Total** (fond `#5a7fa8`, texte blanc, gras) : une ligne par section

### Règles d'affichage des lignes (partagées)

**Onglet 1** (avec N-1 et budget) — une sous-catégorie est affichée si :
- `montant_n > 0`, ou
- `montant_n1 > 0` (activité l'an passé), ou
- `budget > 0` (budget prévu mais rien dépensé)
→ masquée uniquement si `montant_n = 0` ET `montant_n1 = null` ET `budget = null`

**Onglets 2 et 3** (sans N-1 ni budget) — une sous-catégorie est affichée si :
- `montant > 0` (onglet 2) ou `total > 0` (onglet 3)
→ toutes les lignes à zéro sont masquées

Si toutes les sous-catégories d'une catégorie sont masquées → catégorie masquée aussi.

---

## Onglet 1 — Compte de résultat

### Filtre
Aucun filtre opération. Exercice courant via `ExerciceService::current()`.

### Colonnes (5 colonnes de données)

| # | Colonne | Largeur | Description |
|---|---------|---------|-------------|
| 1 | N-1 | 115 px | Montant exercice précédent, atténué (`#9ab0c8`/`#6b8aaa`). `—` si première année. |
| 2 | N | 115 px | Montant exercice en cours |
| 3 | Budget | 115 px | Budget alloué. `—` si aucune `budget_line`. |
| 4 | Écart | 90 px | `N − Budget`. Rouge si dépassement charges / manque recettes. Vert si économie / surplus. `—` si pas de budget. |
| 5 | Conso. budget | 120 px | Barre horizontale (voir ci-dessous). `—` si pas de budget. |

### Barre de consommation budget

Barre HTML pure (pas de JS), hauteur 10 px :

```php
$pct = ($budget !== null && $budget > 0) ? ($montantN / $budget * 100) : null;
```

- `null` → `—`, pas de barre
- `0–90 %` → vert `#198754`
- `90–100 %` → orange `#fd7e14`
- `> 100 %` → rouge `#dc3545`, débordement visuel
- Pourcentage affiché sous la barre

### Service : `RapportService::compteDeResultat(int $exercice): array`

**Pas d'`$operationIds`** — ce rapport ne filtre jamais par opération.

#### Sources de données

**Dépenses :**
```sql
SELECT c.id, c.nom, sc.id, sc.nom, SUM(dl.montant)
FROM depense_lignes dl
JOIN sous_categories sc ON sc.id = dl.sous_categorie_id
JOIN categories c       ON c.id  = sc.categorie_id
JOIN depenses d         ON d.id  = dl.depense_id
WHERE dl.deleted_at IS NULL AND d.deleted_at IS NULL
  AND d.date BETWEEN :start AND :end
GROUP BY c.id, c.nom, sc.id, sc.nom ORDER BY c.nom, sc.nom
```
Exécutée deux fois : exercice N et exercice N-1.

**Recettes :** même structure sur `recette_lignes` / `recettes`.

**Dons :**
```sql
SELECT c.id, c.nom, sc.id, sc.nom, SUM(dons.montant)
FROM dons
JOIN sous_categories sc ON sc.id = dons.sous_categorie_id
JOIN categories c       ON c.id  = sc.categorie_id
WHERE dons.deleted_at IS NULL AND dons.date BETWEEN :start AND :end
GROUP BY c.id, c.nom, sc.id, sc.nom
```
Exécutée pour N et N-1. Dons sans `sous_categorie_id` ignorés.

**Cotisations :**
```sql
SELECT c.id, c.nom, sc.id, sc.nom, SUM(cotisations.montant)
FROM cotisations
JOIN sous_categories sc ON sc.id = cotisations.sous_categorie_id
JOIN categories c       ON c.id  = sc.categorie_id
WHERE cotisations.deleted_at IS NULL AND cotisations.exercice = :exercice
GROUP BY c.id, c.nom, sc.id, sc.nom
```
Exécutée pour N et N-1 (avec `exercice = :exercice - 1` pour N-1). Jamais filtrées par opération.

**Budget :**
```sql
SELECT sc.id, SUM(bl.montant_prevu)
FROM budget_lines bl
JOIN sous_categories sc ON sc.id = bl.sous_categorie_id
WHERE bl.exercice = :exercice
GROUP BY sc.id
```
Tableau indexé par `sous_categorie_id → float|null` (`null` si aucune ligne).

#### Algorithme

1. Calculer `$startN/$endN` et `$startN1/$endN1`
2. Exécuter les 4 requêtes pour N, les 4 pour N-1, charger le budget
3. Construire un tableau plat intermédiaire, keyed par `sous_categorie_id` :
   ```php
   $map[$sc_id] = [
     'categorie_id' => int, 'categorie_nom' => string,
     'sous_categorie_nom' => string,
     'montant_n'  => float,       // accumulé depuis dépenses/recettes/dons/cotisations N
     'montant_n1' => float|null,  // idem N-1, null si aucune donnée
     'budget'     => float|null,
   ];
   ```
   La fusion recettes + dons + cotisations se fait ici, en sommant dans `$map[$sc_id]['montant_n']`.
4. Grouper `$map` par `categorie_id` pour construire la hiérarchie finale
5. Trier catégories par nom, sous-catégories par nom
6. Totaux catégorie = somme des sous-catégories ; `budget` catégorie = somme budgets (`null` si toutes nulles)

#### Structure de retour

```php
[
  'charges' => [
    [
      'categorie_id' => int,
      'label'        => string,
      'montant_n'    => float,
      'montant_n1'   => float|null,
      'budget'       => float|null,
      'sous_categories' => [
        [
          'sous_categorie_id' => int,
          'label'      => string,
          'montant_n'  => float,
          'montant_n1' => float|null,
          'budget'     => float|null,
        ],
      ],
    ],
  ],
  'produits' => [ /* même structure */ ],
]
```

### Vue Blade

Labels d'années dynamiques : `$labelN = "$exercice–" . ($exercice+1)`.

Calculs dans la vue :
```php
$ecart = ($budget !== null) ? ($montantN - $budget) : null;
$pct   = ($budget !== null && $budget > 0) ? ($montantN / $budget * 100) : null;
```

Convention signe écart : charges → positif = rouge ; produits → positif = vert (intentionnellement inversé).

### Export CSV

`response()->streamDownload()` conservé. Une ligne par sous-catégorie (pas de lignes catégorie ni totaux).

Colonnes : `Type` | `Catégorie` | `Sous-catégorie` | `N-1` | `N` | `Budget` | `Écart`

- Montants `null` → cellule vide
- Format des montants : `number_format($x, 2, ',', '')` (virgule décimale, pas de séparateur milliers)
- Le filtre d'affichage (fantômes) **ne s'applique pas** au CSV : toutes les sous-catégories avec au moins un montant ou un budget sont exportées

---

## Onglet 2 — Compte de résultat par opération(s)

### Fichiers

- `app/Livewire/RapportCompteResultatOperations.php`
- `resources/views/livewire/rapport-compte-resultat-operations.blade.php`

### Filtre

Checkboxes multi-sélection sur les opérations (même pattern que l'onglet 1 actuel). Si aucune opération sélectionnée → rapport vide (message "Sélectionnez au moins une opération") et bouton CSV désactivé (`disabled`). Même comportement pour l'onglet 3.

### Colonnes (1 colonne de données)

| # | Colonne | Largeur | Description |
|---|---------|---------|-------------|
| 1 | Montant | 130 px | Montant exercice en cours pour les opérations sélectionnées |

Pas de N-1, pas de Budget, pas d'Écart, pas de barre.

### Service : `RapportService::compteDeResultatOperations(int $exercice, array $operationIds): array`

Nouvelle méthode. Même structure de retour que `compteDeResultat()` mais sans `montant_n1` et sans `budget` dans les items.

#### Sources de données

**Dépenses :** même requête que l'onglet 1 pour N, avec `AND dl.operation_id IN (:ids)` obligatoire. Les lignes avec `operation_id IS NULL` sont implicitement exclues par la clause `IN`.

**Recettes :** même requête que l'onglet 1 pour N, avec `AND rl.operation_id IN (:ids)`.

**Dons :** même requête que l'onglet 1 pour N, avec `AND dons.operation_id IN (:ids)`.

**Cotisations :** **exclues** — elles n'ont pas de `operation_id`, leur inclusion fausserait le rapport par opération.

#### Structure de retour

```php
[
  'charges' => [
    [
      'categorie_id' => int,
      'label'        => string,
      'montant'      => float,
      'sous_categories' => [
        ['sous_categorie_id' => int, 'label' => string, 'montant' => float],
      ],
    ],
  ],
  'produits' => [ /* même structure */ ],
]
```

### Export CSV

Colonnes : `Type` | `Catégorie` | `Sous-catégorie` | `Montant`

---

## Onglet 3 — Rapport par séances

### Filtre

Checkboxes multi-sélection sur les opérations ayant `nombre_seances > 0`. Si aucune opération sélectionnée → rapport vide.

### Colonnes (dynamiques)

Une colonne par numéro de séance trouvé dans les données + colonne Total :

```
Séance 1 | Séance 2 | Séance 3 | ... | Total
```

Les numéros de séances sont déterminés par `DISTINCT seance` sur les données filtrées, triés par ordre croissant. Avec plusieurs opérations sélectionnées, les séances de même numéro sont agrégées (l'utilisateur est responsable de sélectionner des opérations cohérentes).

### Service : `RapportService::rapportSeances(int $exercice, array $operationIds): array`

Signature modifiée : `operation_id: int` → `operationIds: array`.

#### Sources de données

**Dépenses par séance :**
```sql
SELECT c.id, c.nom, sc.id, sc.nom, dl.seance, SUM(dl.montant)
FROM depense_lignes dl
JOIN sous_categories sc ON sc.id = dl.sous_categorie_id
JOIN categories c       ON c.id  = sc.categorie_id
JOIN depenses d         ON d.id  = dl.depense_id
WHERE dl.deleted_at IS NULL AND d.deleted_at IS NULL
  AND dl.seance IS NOT NULL
  AND dl.operation_id IN (:ids)
  AND d.date BETWEEN :start AND :end
GROUP BY c.id, c.nom, sc.id, sc.nom, dl.seance
```

**Recettes par séance :** même structure sur `recette_lignes` / `recettes`.

**Dons par séance :**
```sql
SELECT c.id, c.nom, sc.id, sc.nom, dons.seance, SUM(dons.montant)
FROM dons
JOIN sous_categories sc ON sc.id = dons.sous_categorie_id
JOIN categories c       ON c.id  = sc.categorie_id
WHERE dons.deleted_at IS NULL AND dons.seance IS NOT NULL
  AND dons.operation_id IN (:ids)
  AND dons.date BETWEEN :start AND :end
GROUP BY c.id, c.nom, sc.id, sc.nom, dons.seance
```

Dons fusionnés dans les produits. Cotisations exclues (pas de séance).

#### Algorithme

1. Exécuter les 3 requêtes
2. Accumuler dans une map `[categorie_id][sous_categorie_id][seance] => montant`
3. Collecter `$allSeances = DISTINCT séances trouvées, triées ASC`
4. Construire la hiérarchie : pour chaque catégorie → sous-catégories, avec `seances[n] => float` (0.0 si absente) et `total => sum`
5. Calculer `total` catégorie = somme des totaux sous-catégories

#### Structure de retour

```php
[
  'seances'  => [1, 2, 3, ...],   // liste ordonnée des numéros de séances
  'charges'  => [
    [
      'categorie_id' => int,
      'label'        => string,
      'seances'      => [1 => float, 2 => float, ...],
      'total'        => float,
      'sous_categories' => [
        [
          'sous_categorie_id' => int,
          'label'   => string,
          'seances' => [1 => float, 2 => float, ...],
          'total'   => float,
        ],
      ],
    ],
  ],
  'produits' => [ /* même structure */ ],
]
```

### Export CSV

Colonnes : `Type` | `Catégorie` | `Sous-catégorie` | `Séance 1` | `Séance 2` | ... | `Total`

---

## Navigation — `resources/views/rapports/index.blade.php`

Trois onglets Bootstrap dans l'ordre :

```html
<ul class="nav nav-tabs mb-4">
  <li>Compte de résultat</li>           <!-- livewire:rapport-compte-resultat -->
  <li>Compte de résultat par opération(s)</li>  <!-- livewire:rapport-compte-resultat-operations -->
  <li>Rapport par séances</li>          <!-- livewire:rapport-seances -->
</ul>
```

---

## Ce qui ne change pas

- Route `/rapports` existante, pas de nouvelle route
- `ExerciceService::current()` pour l'exercice (pas de sélecteur utilisateur)
- Style Bootstrap 5, `#3d5473` / `#5a7fa8`
- `declare(strict_types=1)` + `final class` + PSR-12
- Séparateur CSV `;`
- `response()->streamDownload()` pour tous les exports

---

## Notes d'implémentation

- **Convention signe écart (onglet 1) :** charges → positif = rouge ; produits → positif = vert.
- **En-tête de section :** labels colonnes en ligne 1, titre DÉPENSES/RECETTES en ligne 2.
- **Cotisations exclues des onglets 2 et 3** : pas de `operation_id` ni de `seance`.
- **Rapport par séances multi-opérations :** les séances de même numéro sont sommées ; c'est à l'utilisateur de choisir des opérations cohérentes. Les lignes avec `operation_id IS NULL` sont implicitement exclues par la clause `IN`.
- **Barre de consommation budget (onglet 1) :** la barre est cappée à 100 % de largeur en CSS (`max-width: 100%`) ; la couleur rouge indique le dépassement. Un léger débordement visuel (pseudo-élément) peut être ajouté pour signaler visuellement `> 100 %`.
- **Première année (onglet 1) :** `montant_n1 = null` quand aucune donnée n'existe pour N-1 dans la base, quelle qu'en soit la raison (première année ou sous-cat inexistante en N-1). Pas de notion d'année de fondation codée en dur.
