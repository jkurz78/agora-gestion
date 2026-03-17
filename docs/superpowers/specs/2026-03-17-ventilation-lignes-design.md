# Spec — Ventilation de lignes & solidification du verrouillage post-rapprochement

**Date :** 2026-03-17
**Statut :** En révision
**Branche :** à créer (pas dans `main`)

---

## Contexte

Quand une subvention (ou toute recette globale) est encaissée et rapprochée avec le compte bancaire, elle est saisie en une ligne sans opération. La répartition analytique entre les opérations intervient plus tard — mais à ce stade la pièce est verrouillée et ne peut plus être modifiée.

Par ailleurs, le verrouillage actuel est implémenté dans le composant Livewire (`RecetteForm`), ce qui le rend contournable via d'autres chemins de code et ne couvre pas tous les invariants.

Ce chantier adresse les deux problèmes de façon symétrique sur `Recette` et `Depense`.

---

## Périmètre

1. **Solidification du verrouillage** — déplacer l'enforcement dans la couche service
2. **Ventilation de lignes** — permettre d'affecter analytiquement une ligne verrouillée à plusieurs opérations via une table dédiée

---

## 1. Solidification du verrouillage

### Invariants d'une pièce verrouillée (via `update()`)

| Champ | Statut via `update()` |
|-------|--------|
| `date` | **Immutable** |
| `compte_id` | **Immutable** |
| `montant_total` | **Immutable** (calculé) |
| Nombre de lignes | **Immutable** |
| `montant` par ligne | **Immutable** |
| `sous_categorie_id` par ligne | **Immutable** |
| `libelle` | Mutable |
| `reference` | Mutable |
| `notes` | Mutable |
| `tiers_id` | Mutable |
| `operation_id` par ligne | Mutable |
| `seance` par ligne | Mutable |
| `notes` par ligne | Mutable |

### Comparaison des lignes par ID (pas par position)

`RecetteService::update()` reçoit désormais un tableau de lignes **avec leur `id`** pour les lignes existantes. La validation des invariants compare chaque ligne soumise à la ligne DB correspondante par `id`. Les lignes sans `id` dans le payload sont rejetées si la pièce est verrouillée.

Le composant Livewire stocke l'`id` de chaque ligne au chargement et le transmet au service.

**Sur une pièce non verrouillée**, le comportement de `update()` est inchangé : `forceDelete` + recréation de toutes les lignes. L'`id` dans le payload est ignoré dans ce cas.

### `update()` vs `affecterLigne()` : deux chemins distincts

`update()` vérifie tous les invariants listés ci-dessus.

`affecterLigne()` est une opération distincte qui ne passe **pas** par `update()`. Elle opère directement sur la table des affectations sans modifier la ligne source.

### Implémentation

Le critère de verrouillage utilisé dans tout ce chantier est `$recette->isLockedByRapprochement()` — soit `rapprochement_id !== null` **ET** le rapprochement a un `verrouille_at`. Une pièce pointée dans un rapprochement non encore verrouillé n'est pas considérée verrouillée et passe par le chemin `forceDelete + recréation` habituel.

`RecetteService::update()` et `DepenseService::update()`, si la pièce est verrouillée, vérifient :
- `date` inchangée
- `compte_id` inchangé
- même nombre de lignes que dans la DB
- pour chaque ligne (par `id`) : `montant` et `sous_categorie_id` inchangés

Si un invariant est violé : `\RuntimeException` levée.

Le composant Livewire supprime sa propre logique de re-congélation et délègue entièrement au service.

---

## 2. Ventilation de lignes — Option 1 (table d'affectations séparée)

### Concept

La ligne source dans `recette_lignes` / `depense_lignes` reste **intacte et immuable**. Les affectations analytiques sont stockées dans une table dédiée. La ligne originale n'est jamais modifiée ni supprimée.

Avantages par rapport à une approche "replace in place" :
- **Historique** : la ligne originale est toujours visible
- **Réversibilité** : supprimer toutes les affectations = retour à l'état initial
- **Re-ventilation** : réécriture complète des affectations en une seule opération
- **Flag naturel** : une ligne "a-t-elle des affectations ?" suffit à savoir si elle est ventilée

### Nouvelles tables

```sql
recette_ligne_affectations
  id                    unsignedBigInteger PK
  recette_ligne_id      FK → recette_lignes.id
  operation_id          FK → operations.id (nullable)
  seance                unsignedInteger (nullable)
  montant               decimal(10,2)
  notes                 string(255) (nullable)
  timestamps

depense_ligne_affectations
  id                    unsignedBigInteger PK
  depense_ligne_id      FK → depense_lignes.id
  operation_id          FK → operations.id (nullable)
  seance                unsignedInteger (nullable)
  montant               decimal(10,2)
  notes                 string(255) (nullable)
  timestamps
```

### Règles métier

- La somme des affectations doit être **strictement égale** au montant de la ligne source.
- La comparaison est effectuée en centimes entiers (`(int) round($montant * 100)`) pour éviter les erreurs de virgule flottante.
- Une affectation peut avoir `operation_id = null` (reste non affecté).
- Le nombre minimal d'affectations est 1.
- Chaque `montant` d'affectation doit être > 0.
- La `sous_categorie_id` est portée par la ligne source — elle n'est pas dupliquée dans les affectations.
- La ventilation est disponible sur les pièces verrouillées **et non verrouillées** (une pièce non rapprochée peut aussi être ventilée analytiquement).

### Nouvelle méthode de service

```php
// RecetteService (identique sur DepenseService)
public function affecterLigne(RecetteLigne $ligne, array $affectations): void
```

**Paramètres `$affectations`** : tableau de `[operation_id, seance, montant, notes]`.

**Validations** :
- `$ligne` doit appartenir à une recette accessible.
- La somme des `montant` (en centimes) doit égaler exactement `$ligne->montant` (en centimes).
- Chaque `montant` doit être > 0.

**Comportement** : dans une `DB::transaction()`, supprime toutes les affectations existantes de la ligne (`deleteAll`) et insère les nouvelles. Permet ainsi la re-ventilation en une seule opération.

**Suppression des affectations** :

```php
public function supprimerAffectations(RecetteLigne $ligne): void
```

Supprime toutes les affectations d'une ligne — retour à l'état initial (ligne sans affectation).

### UI — Formulaire de détail

**Bouton "Ventiler" / "Modifier la ventilation"**
- Affiché sur **toutes les lignes** d'une pièce (verrouillée ou non).
- Libellé **"Ventiler"** si aucune affectation existante.
- Libellé **"Modifier la ventilation"** si des affectations existent.
- Le `<select>` `operation_id` de la ligne reste présent et indépendant de la ventilation.

**Bandeau d'information**
Affiché en haut du formulaire quand la pièce est verrouillée, rappelant les champs modifiables vs verrouillés.

**Panneau de ventilation (inline, sous la ligne)**
- S'ouvre au clic sur "Ventiler" / "Modifier la ventilation".
- Affiche la ligne source en lecture seule (sous-catégorie + montant d'origine).
- Pré-rempli avec les affectations existantes (ou une seule ligne vide si aucune).
- Permet d'ajouter/supprimer des affectations : `operation_id`, `seance`, `montant`, `notes`.
- Compteur "Reste à ventiler : X €" mis à jour en temps réel (calcul en centimes).
- Bouton "Enregistrer" désactivé si reste ≠ 0,00 €.
- Bouton "Annuler la ventilation" pour supprimer toutes les affectations (retour à l'état initial).
- Bouton "Fermer" pour fermer le panneau sans sauvegarder.

**Affichage d'une ligne ventilée dans le tableau**
La ligne source reste affichée normalement. En dessous, les affectations apparaissent en sous-lignes indentées (lecture seule, non éditables directement).

---

## 3. Impact sur les rapports

**Seul `RapportService` est impacté.** Les listes (`recette-list`, `depense-list`) et le `BudgetService` travaillent au niveau de la transaction (header) et non des lignes — aucune modification requise.

Dans `RapportService`, les 6 requêtes qui filtrent/agrègent par `operation_id` sur `recette_lignes` ou `depense_lignes` doivent adopter la logique suivante :

> Si une ligne a des affectations → utiliser les affectations (jointure sur `recette_ligne_affectations`).
> Si une ligne n'a pas d'affectations → utiliser directement `recette_lignes.operation_id`.

Cette logique peut être centralisée dans une méthode ou un scope réutilisable pour éviter la duplication.

---

## Symétrie Recette / Dépense

Toutes les évolutions s'appliquent identiquement à `RecetteService` / `RecetteForm` et `DepenseService` / `DepenseForm`. Confirmé : `DepenseForm` supporte déjà les lignes multiples — la symétrie est complète sans travail préalable.

---

## Hors périmètre

- Ventilation des dons (structure différente, pas de lignes multiples).
- Import CSV de ventilations.
- Rapport dédié "état de ventilation des subventions".
- Renforcement de `delete()` sur pièce verrouillée : `RecetteService::delete()` vérifie actuellement `rapprochement_id !== null` mais pas que le rapprochement est verrouillé. Ce comportement est conservé tel quel dans ce chantier.

---

## Tests

**Verrouillage — `RecetteService::update()`**
- Rejette une modification de `date` sur pièce verrouillée.
- Rejette une modification de `compte_id` sur pièce verrouillée.
- Rejette une modification de `montant` de ligne sur pièce verrouillée.
- Rejette une modification de `sous_categorie_id` de ligne sur pièce verrouillée.
- Rejette l'ajout d'une ligne sur pièce verrouillée.
- Rejette la suppression d'une ligne sur pièce verrouillée.
- Accepte la modification de `libelle`, `notes`, `tiers_id` sur pièce verrouillée.
- Accepte la modification de `operation_id`, `seance`, `notes` de ligne sur pièce verrouillée.
- Idem pour `DepenseService::update()`.

**Ventilation — `RecetteService::affecterLigne()`**
- Cas nominal : affectation en N sous-lignes, somme exacte.
- Cas nominal : affectation en 1 seule sous-ligne.
- Rejette si somme des affectations ≠ montant source (en centimes).
- Rejette si une affectation a `montant` ≤ 0.
- Re-ventilation : remplace correctement les affectations existantes.
- `montant_total` et la ligne source sont inchangés après affectation.
- Idem pour `DepenseService::affecterLigne()`.

**Suppression — `RecetteService::supprimerAffectations()`**
- Supprime toutes les affectations d'une ligne → ligne revenue à l'état initial.
- Idem pour `DepenseService::supprimerAffectations()`.
