# Spec : Refonte du rapport Compte de résultat

**Date :** 2026-03-16
**Statut :** Approuvé

---

## Contexte

Le rapport Compte de résultat existant agrège les charges et produits par sous-catégorie dans deux tableaux plats. Il manque la hiérarchie catégorie/sous-catégorie, la comparaison avec l'exercice précédent, le suivi budgétaire et un indicateur visuel de consommation.

---

## Structure visuelle

Le rapport est organisé en deux sections verticales séparées : **Dépenses** puis **Recettes**, suivies d'une ligne de résultat.

### En-tête de section

Chaque section (DÉPENSES / RECETTES) occupe **deux lignes** dans un bloc bleu `#3d5473` :

```
┌──────────────────────┬──────────┬──────────┬──────────┬────────┬──────────────┐
│                      │ 2023-24  │ 2024-25  │  Budget  │ Écart  │ Conso. budget│  ← ligne 1 : labels colonnes
│  DÉPENSES            │          │          │          │        │              │  ← ligne 2 : titre section
└──────────────────────┴──────────┴──────────┴──────────┴────────┴──────────────┘
```

### Lignes de données

- **Catégorie** (fond `#dce6f0`, texte `#1e3a5f`, gras) : nom catégorie + 5 colonnes chiffrées
- **Sous-catégorie** (fond `#f7f9fc`, indentation 32 px) : nom sous-catégorie + 5 colonnes chiffrées
- **Total** (fond `#5a7fa8`, texte blanc, gras) : une ligne par section

### Résultat final

Élément `<div>` séparé (hors table), pleine largeur :

- Fond vert `#198754` + libellé **EXCÉDENT** si recettes ≥ dépenses
- Fond rouge `#dc3545` + libellé **DÉFICIT** si recettes < dépenses
- Affiche uniquement le montant de l'exercice en cours (pas de N-1, budget, ou écart)

---

## Colonnes (5 colonnes de données)

| # | Colonne | Largeur | Description |
|---|---------|---------|-------------|
| 1 | N-1 | 115 px | Montant exercice précédent, couleur atténuée (`#9ab0c8` sous-cat, `#6b8aaa` cat). `—` si première année (aucun enregistrement trouvé pour N-1). |
| 2 | N | 115 px | Montant exercice en cours |
| 3 | Budget | 115 px | Budget alloué. `—` si aucun `budget_lines` trouvé. |
| 4 | Écart | 90 px | `N − Budget`. Positif = rouge, négatif = vert, zéro = gris. `—` si pas de budget. |
| 5 | Conso. budget | 120 px | Barre horizontale (voir ci-dessous). `—` si pas de budget. |

---

## Barre de consommation budget

Barre HTML pure (aucun JS), hauteur 10 px, calculée en PHP dans la vue :

```php
$pct = $budget > 0 ? ($montantN / $budget * 100) : null;
```

- `null` (budget absent) → afficher `—`, pas de barre
- `0 – 90 %` → fond vert `#198754`
- `90 – 100 %` → fond orange `#fd7e14`
- `> 100 %` → fond rouge `#dc3545`, barre pleine + débordement visuel
- Pourcentage affiché en petit sous la barre (ex. `108 %`)

---

## Sources de données et requêtes

### Principe général

Le service construit séparément deux tableaux (`charges`, `produits`) en agrégeant par `(categorie_id, sous_categorie_id)`, pour les exercices N et N-1.

### Dépenses (charges)

```sql
SELECT
    c.id        AS categorie_id,
    c.nom       AS categorie_nom,
    sc.id       AS sous_categorie_id,
    sc.nom      AS sous_categorie_nom,
    SUM(dl.montant) AS montant
FROM depense_lignes dl
JOIN sous_categories sc ON sc.id = dl.sous_categorie_id
JOIN categories c       ON c.id  = sc.categorie_id
JOIN depenses d         ON d.id  = dl.depense_id
WHERE dl.deleted_at IS NULL
  AND d.deleted_at  IS NULL
  AND d.date BETWEEN :start AND :end
  [AND dl.operation_id IN (:ids)]   -- filtre optionnel
GROUP BY c.id, c.nom, sc.id, sc.nom
ORDER BY c.nom, sc.nom
```

Exécutée deux fois : une pour N (`start=YYYY-09-01`, `end=YYYY+1-08-31`) et une pour N-1.

### Recettes (produits)

Même structure que Dépenses, sur `recette_lignes` / `recettes`, filtre `operation_id` optionnel.

### Dons (ajoutés aux produits)

```sql
SELECT
    c.id        AS categorie_id,
    c.nom       AS categorie_nom,
    sc.id       AS sous_categorie_id,
    sc.nom      AS sous_categorie_nom,
    SUM(dons.montant) AS montant
FROM dons
JOIN sous_categories sc ON sc.id = dons.sous_categorie_id
JOIN categories c       ON c.id  = sc.categorie_id
WHERE dons.deleted_at IS NULL
  AND dons.date BETWEEN :start AND :end
  [AND dons.operation_id IN (:ids)]   -- filtre optionnel
GROUP BY c.id, c.nom, sc.id, sc.nom
```

Les dons sans `sous_categorie_id` (nullable) sont ignorés du rapport.

### Cotisations (ajoutées aux produits)

```sql
SELECT
    c.id        AS categorie_id,
    c.nom       AS categorie_nom,
    sc.id       AS sous_categorie_id,
    sc.nom      AS sous_categorie_nom,
    SUM(cotisations.montant) AS montant
FROM cotisations
JOIN sous_categories sc ON sc.id = cotisations.sous_categorie_id
JOIN categories c       ON c.id  = sc.categorie_id
WHERE cotisations.deleted_at IS NULL
  AND cotisations.exercice = :exercice
GROUP BY c.id, c.nom, sc.id, sc.nom
```

**Les cotisations ne sont jamais filtrées par `operation_id`** (elles n'ont pas ce champ).

### Budget

```sql
SELECT
    sc.id                  AS sous_categorie_id,
    SUM(bl.montant_prevu)  AS budget
FROM budget_lines bl
JOIN sous_categories sc ON sc.id = bl.sous_categorie_id
WHERE bl.exercice = :exercice
GROUP BY sc.id
```

Retourne un tableau indexé par `sous_categorie_id → float`. Si une sous-catégorie n'a pas de `budget_line`, sa valeur dans ce tableau est absente → le service stocke `null` (pas `0.0`) pour distinguer "pas de budget" de "budget à zéro".

---

## Service `RapportService::compteDeResultat()`

### Signature

```php
public function compteDeResultat(int $exercice, ?array $operationIds = null): array
```

### Algorithme

1. Calculer `$startN`, `$endN`, `$startN1`, `$endN1` (dates exercice N et N-1)
2. Exécuter les 4 requêtes d'agrégation pour **N** : dépenses, recettes, dons, cotisations
3. Exécuter les 4 mêmes requêtes pour **N-1** : dépenses, recettes, dons, cotisations (même structure, mêmes joins — le filtre `operation_id` s'applique aussi à N-1 si fourni ; cotisations non filtrées par operation)
4. Charger le tableau budget par `sous_categorie_id` (exercice N uniquement, pas de budget N-1)
5. Fusionner recettes + dons + cotisations dans les produits pour N, idem pour N-1 (accumulation par `sous_categorie_id`)
6. Construire la structure hiérarchique :
   - Grouper par `categorie_id`
   - Pour chaque catégorie, lister ses sous-catégories avec `montant_n`, `montant_n1`, `budget`
   - `montant_n1 = null` si aucune ligne trouvée pour cet exercice N-1 (première année ou sous-cat absente en N-1)
   - `budget = null` si aucune `budget_line` trouvée pour cette sous-catégorie
   - `montant_n` de la catégorie = somme de ses sous-catégories pour N
   - `montant_n1` de la catégorie = somme de ses sous-catégories pour N-1 (`null` si toutes les sous-cats ont `null`)
   - `budget` de la catégorie = somme des budgets de ses sous-catégories (`null` si aucune n'a de budget)
7. Trier les catégories par `nom`, les sous-catégories par `nom`

### Structure de retour

```php
[
  'charges' => [
    [
      'categorie_id'    => int,
      'label'           => string,           // nom catégorie
      'montant_n'       => float,            // somme des sous-catégories pour N
      'montant_n1'      => float|null,       // somme N-1, null si aucune donnée N-1
      'budget'          => float|null,       // somme budgets sous-cat, null si aucun budget
      'sous_categories' => [
        [
          'sous_categorie_id' => int,
          'label'             => string,
          'montant_n'         => float,
          'montant_n1'        => float|null, // null = aucune donnée N-1 pour cette sous-cat
          'budget'            => float|null, // null = aucune budget_line pour cette sous-cat
        ],
        // ...
      ],
    ],
    // ...
  ],
  'produits' => [ /* même structure */ ],
]
```

---

## Vue Blade

La vue reçoit `$charges`, `$produits`, `$operations` (pour le filtre).

### Calculs dans la vue

Pour chaque ligne (catégorie ou sous-catégorie) :

```php
$ecart = ($budget !== null) ? ($montantN - $budget) : null;
$pct   = ($budget !== null && $budget > 0) ? ($montantN / $budget * 100) : null;
```

Couleur de l'écart :
- `$ecart > 0` dans les charges → rouge (dépassement)
- `$ecart < 0` dans les charges → vert (économie)
- `$ecart > 0` dans les produits → vert (mieux que prévu)
- `$ecart < 0` dans les produits → rouge (manque)
- `$ecart === 0` ou `null` → gris

Totaux calculés dans le bloc `@php` de la vue :

```php
$totalChargesN  = collect($charges)->sum('montant_n');
$totalProduitsN = collect($produits)->sum('montant_n');
$resultatNet    = $totalProduitsN - $totalChargesN;
```

Règles d'affichage des lignes de sous-catégorie :
- `montant_n > 0` → toujours affichée
- `montant_n = 0` et `montant_n1 > 0` → affichée (activité l'an passé, utile pour la comparaison)
- `montant_n = 0` et `montant_n1 = null` et `budget > 0` → affichée (budget prévu mais rien dépensé)
- `montant_n = 0` et `montant_n1 = null` et `budget = null` → masquée (ligne fantôme)

Si toutes les sous-catégories d'une catégorie sont masquées, la ligne catégorie est masquée elle aussi.

### En-têtes de colonnes

Les libellés d'années dans les en-têtes de section sont générés dynamiquement :

```php
$labelN  = $exercice . '–' . ($exercice + 1);
$labelN1 = ($exercice - 1) . '–' . $exercice;
```

---

## Export CSV

La méthode `exportCsv()` du composant Livewire est conservée avec la même signature et le même mécanisme `response()->streamDownload()`. Seul le contenu change.

Une ligne par sous-catégorie (les lignes de catégorie et les totaux ne sont pas exportés).

Colonnes : `Type` | `Catégorie` | `Sous-catégorie` | `N-1` | `N` | `Budget` | `Écart`

- `Type` : `Charge` ou `Produit`
- Montants `null` → cellule vide dans le CSV
- Séparateur `;` (convention française, existant conservé)
- Le filtre d'affichage (lignes fantômes) **ne s'applique pas** au CSV : toutes les sous-catégories ayant au moins un montant ou un budget sont exportées

Génération des lignes dans le composant :

```php
foreach ($data['charges'] as $cat) {
    foreach ($cat['sous_categories'] as $sc) {
        $rows[] = [
            'Charge',
            $cat['label'],
            $sc['label'],
            $sc['montant_n1'] !== null ? number_format($sc['montant_n1'], 2, ',', '') : '',
            number_format($sc['montant_n'], 2, ',', ''),
            $sc['budget'] !== null ? number_format($sc['budget'], 2, ',', '') : '',
            $sc['budget'] !== null ? number_format($sc['montant_n'] - $sc['budget'], 2, ',', '') : '',
        ];
    }
}
// idem pour $data['produits'] avec type 'Produit'
```

---

## Ce qui ne change pas

- Filtre par opération (checkboxes en haut de page)
- Sélection de l'exercice : `ExerciceService::current()`, pas de sélecteur côté utilisateur
- Style général : Bootstrap 5, `table-dark` avec `#3d5473`
- `declare(strict_types=1)` + `final class`
- Pas de JS côté client pour les barres (HTML/CSS pur)

## Notes d'implémentation

- **Convention de signe des écarts :** Pour les charges, un écart positif (N > Budget) signifie un dépassement → rouge. Pour les produits, un écart positif signifie une recette supérieure au budget → vert. La logique est intentionnellement inversée entre les deux sections.
- **En-tête de section :** Les labels de colonnes sont en ligne 1, le titre DÉPENSES/RECETTES en ligne 2 — ordre intentionnel pour l'alignement visuel avec les données.
