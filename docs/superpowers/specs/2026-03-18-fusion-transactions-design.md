# Spec — Fusion Dépenses/Recettes en Transactions

**Date :** 2026-03-18
**Statut :** Validé

---

## Contexte

Les entités `Depense` et `Recette` sont structurellement identiques (mêmes champs, mêmes relations, même logique métier). Leurs lignes de ventilation (`DepenseLigne`, `RecetteLigne`) et leurs affectations le sont aussi. Tout développement sur l'une doit être dupliqué sur l'autre. Cette spec décrit la fusion en une structure unifiée `Transaction`.

---

## Périmètre

**Dans le scope :**
- Tables `depenses`, `recettes`, `depense_lignes`, `recette_lignes`, `depense_ligne_affectations`, `recette_ligne_affectations`
- Modèles, services et composants Livewire associés

**Hors scope :**
- `dons`, `cotisations`, `virements_internes` — structures spécifiques, non touchées

---

## Décisions de design

### 1. Base de données — 6 tables → 3

| Avant | Après |
|---|---|
| `depenses` + `recettes` | `transactions` |
| `depense_lignes` + `recette_lignes` | `transaction_lignes` |
| `depense_ligne_affectations` + `recette_ligne_affectations` | `transaction_ligne_affectations` |

**Table `transactions` :**
```
id, type (enum: depense|recette), date, libelle, montant_total, mode_paiement,
tiers_id, reference, compte_id, pointe, notes, saisi_par, rapprochement_id,
numero_piece, deleted_at, created_at, updated_at
```

**Table `transaction_lignes` :**
```
id, transaction_id (FK → transactions), sous_categorie_id, operation_id,
seance, montant, notes, deleted_at
```

**Table `transaction_ligne_affectations` :**
```
id, transaction_ligne_id (FK → transaction_lignes), operation_id,
seance, montant, notes, created_at, updated_at
```
> Pas de `deleted_at` sur les affectations — elles sont supprimées en cascade (hard delete) quand la ligne parente est supprimée, conformément au comportement actuel.

La migration crée les nouvelles tables, migre les données, puis supprime les anciennes.

### 2. Convention des montants

Les montants sont **toujours stockés en valeur positive** dans la base. Le champ `type` porte le sens (débit ou crédit). Cette règle est encapsulée dans le modèle `Transaction` :

```php
public function estDebit(): bool
{
    return $this->type === TypeTransaction::Depense;
}

public function montantSigne(): float
{
    return $this->estDebit() ? -(float) $this->montant_total : (float) $this->montant_total;
}
```

Partout où un montant signé est nécessaire (calcul de solde, rapprochement), on utilise `montantSigne()` ou l'expression SQL équivalente :
```sql
CASE WHEN type = 'depense' THEN -montant_total ELSE montant_total END
```

### 3. Modèles — 6 → 3

- **`Transaction`** remplace `Depense` et `Recette`
  - Cast `type` → `TypeTransaction` (nouvel enum, distinct de `TypeCategorie`)
  - Méthodes `estDebit()`, `montantSigne()`, `isLockedByRapprochement()`
  - Scope `forExercice(int $annee)`
  - Relation `lignes()` → `TransactionLigne`

- **`TransactionLigne`** remplace `DepenseLigne` et `RecetteLigne`
  - Relation `transaction()` → `Transaction`
  - Relation `affectations()` → `TransactionLigneAffectation`

- **`TransactionLigneAffectation`** remplace `DepenseLigneAffectation` et `RecetteLigneAffectation`

**Enum `TypeTransaction` :**
```php
enum TypeTransaction: string {
    case Depense = 'depense';
    case Recette = 'recette';
}
```

### 4. Services

- **`TransactionService`** remplace `DepenseService` et `RecetteService`
  - Méthodes : `create`, `update`, `delete`, `affecterLigne`
  - Le `type` est passé à `create()` et stocké ; les validations (`assertLockedInvariants`, filtre sous-catégories) l'utilisent
  - `assertLockedInvariants` est déclenchée uniquement si `rapprochement_id != null` ET que le rapprochement est verrouillé (`isVerrouille() === true`). Elle protège : date, compte, montant total, nombre de lignes, montant par ligne. La sous-catégorie reste modifiable.
  - La suppression d'une `TransactionLigne` supprime d'abord ses affectations (hard delete) puis soft-delete la ligne. Ce n'est pas le comportement par défaut de Laravel — le service doit l'implémenter explicitement.

- **`TransactionCompteService`** simplifié :
  - La double sous-requête `depenses UNION recettes` devient une requête unique sur `transactions` avec `CASE WHEN type='depense' THEN -montant_total ELSE montant_total END as montant`

### 5. Interface Livewire

**Liste :**
- `DepenseList` + `RecetteList` → **`TransactionList`**
- Filtre en en-tête : `Dépenses | Recettes | Toutes` (défaut : Toutes)
- Les routes existantes `/depenses` et `/recettes` sont remplacées par `/transactions`

**Formulaire :**
- `DepenseForm` + `RecetteForm` → **`TransactionForm`**
- **Création :** le `type` est passé en paramètre à l'ouverture du composant, immuable, affiché en lecture seule
- **Édition :** le `type` est chargé depuis la base de données et affiché en lecture seule — non modifiable dans les deux cas
- Les sous-catégories sont filtrées par `categorie.type = type`. En édition, la valeur actuelle est toujours affichée même si elle appartient à la bonne catégorie (aucun cas de données incohérentes attendu après migration).
- Le libellé du bouton de soumission s'adapte au type
- **Deux boutons distincts** dans le menu/la liste : "Nouvelle dépense" et "Nouvelle recette"
  - Même composant, argument `type` différent
  - Le type n'est pas modifiable en cours de saisie (évite la perte de données et la complexité de rechargement)

### 6. Impact sur le reste de l'app

| Composant | Impact |
|---|---|
| `TransactionCompteService` | Simplifié : 1 requête `transactions` au lieu de 2 |
| `RapportService` | Requêtes sur `transactions JOIN transaction_lignes` filtrées par `type` |
| `RapprochementDetail` | `rapprochement_id` reste une FK directe sur `transactions` |
| `TiersTransactions` | Requête sur `transactions WHERE tiers_id = ?` |
| `Dashboard` | Agrégats sur `transactions` filtrés par `type` |

---

## Migration des données

Les IDs des dépenses et recettes se chevauchent (dépense id=1 ET recette id=1 existent) — ils ne peuvent pas être conservés tels quels dans une table unifiée.

**Ordre d'exécution :**

1. Créer `transactions`, `transaction_lignes`, `transaction_ligne_affectations`
2. `SET FOREIGN_KEY_CHECKS=0` pour éviter les erreurs de contrainte pendant la migration
3. Ajouter une colonne temporaire `old_id INT NULL` sur `transactions`. Insérer via INSERT…SELECT depuis `depenses` (type='depense') puis `recettes` (type='recette') en peuplant `old_id` avec l'ID source. Les nouveaux IDs auto-incrémentés sont générés par MySQL.
4. Insérer dans `transaction_lignes` via JOIN sur `transactions.old_id` pour résoudre le `transaction_id`. Ajouter une colonne temporaire `old_id INT NULL` sur `transaction_lignes` de la même façon.
5. Insérer dans `transaction_ligne_affectations` via JOIN sur `transaction_lignes.old_id`.
6. Supprimer les colonnes temporaires `old_id` sur `transactions` et `transaction_lignes`.
7. Supprimer dans l'ordre : `depense_ligne_affectations`, `recette_ligne_affectations`, `depense_lignes`, `recette_lignes`, `depenses`, `recettes`
7. `SET FOREIGN_KEY_CHECKS=1`

**Sur `rapprochement_id` :** cette colonne dans `transactions` est une FK vers `rapprochements_bancaires.id`. La table des rapprochements n'est pas modifiée. La valeur est copiée telle quelle depuis la source — aucun remapping nécessaire car elle pointe vers une table tierce non impactée.

**Sur `tiers_id`, `compte_id`, `saisi_par` :** même cas, copiés tels quels.

**Pas de redirections HTTP** sur `/depenses` et `/recettes` — usage interne uniquement, pas de SEO ni de liens externes à préserver.

---

## Tests

- Adapter les factories `DepenseFactory` → `TransactionFactory` avec état `asDepense()` / `asRecette()`
- Adapter les tests existants de `DepenseService` et `RecetteService` → `TransactionService`
- Ajouter un test vérifiant que `montantSigne()` retourne bien le signe attendu selon le type
- Vérifier que `assertLockedInvariants` fonctionne pour les deux types
