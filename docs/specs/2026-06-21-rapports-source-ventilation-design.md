# Source de ventilation financière + refonte des rapports « Analyse » — Design

> Spec de conception (brainstorming). La cible complète est décrite ici ;
> l'implémentation se fait **lot par lot** (voir §8). Le plan détaillé (code, tests)
> sera produit par `writing-plans`, un plan par lot.

**Date :** 2026-06-21
**Branche cible :** `main` (V4 — corrections/évolutions rapports avant entretiens partenaires)
**Statut :** en attente de validation utilisateur

---

## 1. Contexte & problème

Plusieurs écrans « Rapports & Analyses » recomposent chacun de leur côté les mêmes
données de ventilation (lignes de transaction × dimensions). Quatre besoins convergent
vers **une seule source de données plate réutilisable** :

1. **Problème 1** — Sur l'écran **Analyse financière** (PivotTable.js), la colonne
   `Montant` est non signée : dépenses et recettes s'additionnent, une somme de colonne
   ne veut rien dire.
2. **Problème 2** — Le popup « filtre des valeurs » de PivotTable.js (`.pvtFilterBox`)
   s'affiche à moitié hors écran à gauche et n'est pas déplaçable.
3. **Piste 4** — Besoin d'un **export Excel ventilé** : toutes les lignes de
   transaction détaillées (date, n° pièce, référence, mode de paiement, tiers,
   sous-catégorie, opération, séance, montant signé).
4. **Piste 5** — Besoin d'un nouveau rapport **Analyse par tiers**, sur le modèle du
   *Compte de résultat par opérations*, avec le tiers en dimension de ligne primaire.

Aujourd'hui :
- La logique « lignes plates financières » vit dans
  [`AnalysePivot::getFinancierDataProperty()`](../../app/Livewire/AnalysePivot.php)
  et alimente l'écran Analyse **et** l'export Excel (ce dernier via un hack
  `new AnalysePivot` dans
  [`RapportExportController`](../../app/Http/Controllers/RapportExportController.php)).
- Cette source **n'éclate pas** les ventilations (`transaction_ligne_affectations`) :
  elle lit `transaction_lignes.operation_id` / `transaction_lignes.seance` au grain ligne
  ([`AnalysePivot.php:92`](../../app/Livewire/AnalysePivot.php) et `:99`).
- À l'inverse, le *Compte de résultat par opérations*
  ([`CompteResultatBuilder`](../../app/Services/Rapports/CompteResultatBuilder.php))
  éclate déjà les affectations via un motif Q1 (lignes sans affectation) / Q2 (lignes
  avec affectation).

## 2. Objectif

Extraire **une fois** une source plate réutilisable (« la spine ») exposant les lignes
de ventilation **signées** et **éclatées par affectation**, et brancher dessus les trois
consommateurs de la « famille Analyse » : écran Analyse, export ventilé, Analyse par tiers.

## 3. Décisions actées (et pourquoi)

| Décision | Choix | Justification |
|---|---|---|
| Périmètre de la source | Famille « Analyse » uniquement | L'écran Analyse, l'export ventilé et Analyse par tiers sont la **même** donnée plate, à présentation près. |
| Réalisé vs prévisionnel | **Réalisé seulement** | La famille Analyse est réalisé. Le prévisionnel reste dans `CompteResultatBuilder`, qu'on **ne touche pas**. Évite de dupliquer la recomposition prévi/réa. |
| `CompteResultatBuilder` | **Inchangé** | Décision explicite : ne pas le modifier. On ré-implémente l'éclatement au grain ligne dans la nouvelle source (un peu de duplication des JOINs, assumée). |
| Modèle de données | **Inchangé** | Pas de refonte prévi/réa en lignes d'écritures (jugé trop risqué en pleine compta-v5). |
| Signe du montant | recette `+`, dépense `−` | Une somme de colonne = solde net. |
| Éclatement des affectations | **Oui**, dans la source | Aligne l'écran Analyse sur le CR par opérations (le bon traitement). « Ventilé » = grain affectation. |
| Forme de l'Analyse par tiers | **Tableau type CR-op** | Écran Livewire tabulaire (pas le pivot), tiers en ligne primaire, toggle « sous-catégorie en ligne ». |
| Livraison | **Lot par lot** | Pas tous les exports/états en un seul lot. |

## 4. Architecture — la spine

### 4.1 Le service

`App\Services\Rapports\VentilationFinanciereService`

```
pourExercice(int $exercice): array   // list<array<string,mixed>> — lignes plates
```

Read-only. Aucune écriture. Retourne des lignes plates prêtes à consommer
(pivot, export, regroupement).

### 4.2 Colonnes de sortie (par ligne)

| Clé | Source | Note |
|---|---|---|
| `Date` | `transactions.date` | formatée `d/m/Y` côté PHP |
| `N° pièce` | `transactions.numero_piece` | *(nouveau vs source actuelle)* |
| `Référence` | `transactions.reference` | *(nouveau)* |
| `Mode paiement` | `transactions.mode_paiement` | *(nouveau)* |
| `Libellé` | `transactions.libelle` | *(nouveau)* |
| `Tiers` | `CASE` entreprise/particulier | expression existante reprise telle quelle |
| `Type tiers` | `tiers.type` | |
| `Sous-catégorie` | `sous_categories.nom` | toujours au grain **ligne** |
| `Catégorie` | `categories.nom` | |
| `Type` | `transactions.type` | `depense`/`recette` (sert au signe) |
| `Compte` | `comptes_bancaires.nom` | |
| `Opération` | `operations.nom` | via ligne (Q1) **ou** affectation (Q2) |
| `Type opération` | `type_operations.nom` | |
| `Séance n°` | `transaction_lignes.seance` (Q1) / `tla.seance` (Q2) | |
| `Montant` | `transaction_lignes.montant` (Q1) / `tla.montant` (Q2) | **signé** : `−` si `Type = depense` |
| `Mois` / `Trimestre` / `Semestre` | dérivés de `Date` | helpers `trimestreFor`/`semestreFor` déplacés ici |

### 4.3 Éclatement par affectation (motif Q1/Q2 au grain ligne)

Identique en esprit à `CompteResultatBuilder::buildOperationQueries()`, mais **non agrégé** :

- **Q1 — lignes sans affectation** : 1 ligne de sortie, `operation_id`/`seance`/`montant`
  de la ligne. Les lignes **possédant** au moins une affectation sont exclues de Q1
  (même mécanisme que le CR par opérations) pour éviter le double comptage.
- **Q2 — affectations** : depuis `transaction_ligne_affectations`, 1 ligne de sortie
  **par affectation**, avec `tla.operation_id` / `tla.seance` / `tla.montant`.
  La sous-catégorie, la catégorie, le tiers et le compte restent ceux de la **ligne**
  (l'affectation ne splitte que opération/séance/montant).

Les deux requêtes partagent le même jeu d'alias (§4.2). Union puis enrichissement PHP
commun (Date, Montant signé via la colonne `Type`, Mois/Trimestre/Semestre).

**Invariant repris du CR par opérations :** Q1 = lignes sans **aucune** affectation,
Q2 = toutes les affectations. La spine ne réinvente pas de répartition ; elle hérite de
l'invariant du modèle (les affectations partitionnent le montant de la ligne).

### 4.4 Signe

Le signe est porté par `transactions.type` (commun à Q1 et Q2). Appliqué une seule fois
à l'enrichissement PHP : `Montant = (Type === 'depense' ? -1 : 1) × |montant|`.

## 5. Consommateurs

### 5.1 Écran Analyse — `AnalysePivot` (Problème 1)

`getFinancierDataProperty()` délègue à `VentilationFinanciereService::pourExercice()`.
Conséquences :
- colonne `Montant` désormais **signée** ;
- lignes **éclatées par affectation** → pivot par Opération/Séance plus juste ;
- **totaux globaux et par tiers/sous-catégorie inchangés** ; seules les lignes
  ventilées changent d'aspect (alignement sur le CR par opérations, pas une régression) ;
- lignes non ventilées : **aucun changement**.

### 5.2 Export Excel existant (auto-corrigé)

`RapportExportController::xlsxAnalyse('financier', …)` consomme la même source →
le `Montant` signé arrive **automatiquement** dans l'export `analyse-financier`.
Le hack `new AnalysePivot` est remplacé par un appel au service (nettoyage).

### 5.3 Export ventilé — Piste 4

Nouvelle clé de rapport dans `RapportExportController::RAPPORTS` :
`'ventilation' => ['xlsx']`, libellé « Export ventilé », dispatch vers
`xlsxVentilation(int $exercice)` qui dumpe les lignes du service avec les colonnes :
`Date / N° pièce / Référence / Mode paiement / Tiers / Sous-catégorie / Opération /
Séance / Montant`. Réutilise intégralement la spine.

### 5.4 Analyse par tiers — Piste 5

Nouvel écran Livewire tabulaire, sur le modèle de
[`RapportCompteResultatOperations`](../../app/Livewire/RapportCompteResultatOperations.php),
mais **nourri par la spine** (pas par `CompteResultatBuilder`) :
- **tiers** en dimension de ligne primaire ;
- **sous-catégorie** en sous-niveau ;
- toggle **« sous-catégorie en ligne »** (remplace le toggle « tiers en lignes » du CR-op) ;
- montants signés agrégés depuis les lignes plates.

Une petite logique de regroupement (tiers → sous-catégorie) côté composant/service léger ;
pas d'agrégation SQL dédiée — on agrège les lignes plates en mémoire.

## 6. Problème 2 — popup `.pvtFilterBox` (indépendant)

Patch **CSS pur**, sans dépendance à la spine : centrer `.pvtFilterBox` à l'écran
(`position: fixed` centré au lieu de débordant à gauche), sans draggable.
Injecté là où PivotTable.js est initialisé (blade(s) Analyse :
`livewire/analyse-pivot.blade.php`, et le cas échéant `rapports/analyse.blade.php` /
`gestion/analyse/index.blade.php` — point d'injection exact tranché au plan).

## 7. Hors périmètre (ce qu'on ne touche pas)

- `CompteResultatBuilder` et la famille « Compte de résultat » (hiérarchique).
- Le prévisionnel (`encadrement_previsions`, `reglements.montant_prevu`).
- Le modèle de données (pas de lignes d'écritures prévi/réa).
- L'onglet « participants » de `AnalysePivot` (inchangé).

## 8. Lots de livraison (pas à pas)

Chaque lot est **indépendamment testable et mergeable**. Un plan d'implémentation
distinct par lot ; on ne planifie le suivant qu'après recette + merge du précédent.

| Lot | Contenu | Livrable |
|---|---|---|
| **Lot 1 — Écran Analyse remis d'aplomb** | Extraction de `VentilationFinanciereService` + éclatement par affectation + Montant signé (Pb 1) + centrage popup CSS (Pb 2). Branche `AnalysePivot` et l'export `analyse-financier` existant sur la spine. | Écran Analyse + export Excel corrigés. |
| **Lot 2 — Export ventilé** (Piste 4) | Clé `ventilation`, `xlsxVentilation`, colonnes détaillées. | Nouvel export, seul. |
| **Lot 3 — Analyse par tiers** (Piste 5) | Nouvel écran Livewire type CR-op nourri par la spine, toggle « sous-catégorie en ligne ». | Nouveau rapport, seul. |

## 9. Tests & recette

- **Spine** : tests unitaires sur `VentilationFinanciereService` — signe (recette +,
  dépense −), éclatement (ligne 60/40 → 2 lignes, totaux conservés), lignes sans
  affectation inchangées, dimensions temporelles, périmètre exercice (`whereBetween`),
  exclusion `deleted_at`, multi-tenant (scope).
- **Lot 1** : test export `analyse-financier` (montant signé) ; recette manuelle écran
  Analyse — vérifier qu'une ligne ventilée apparaît bien éclatée et que les totaux
  tiers/sous-catégorie sont inchangés.
- **Lot 2** : test génération xlsx `ventilation` (colonnes + montant signé).
- **Lot 3** : tests regroupement tiers→sous-catégorie + toggle ; recette écran.
- Régression complète Pest + `./vendor/bin/pint` à chaque lot.

## 10. Risques

- **Pièges connus du projet** :
  cast `(int)` des deux côtés des `===` PK/FK (MySQL renvoie des strings) ;
  bornes de date SQLite vs MySQL sur `whereBetween` (dater à l'intérieur de la fenêtre
  dans les tests).
- **Duplication maîtrisée** : les JOINs de la spine recoupent ceux de
  `CompteResultatBuilder`. Assumé (décision : ne pas toucher ce dernier). Documenter le
  lien dans le code.
