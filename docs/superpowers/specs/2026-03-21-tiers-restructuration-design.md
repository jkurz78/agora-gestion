# Tiers — Restructuration modèle et formulaire unique

**Date :** 2026-03-21
**Contexte :** Préparation à l'import HelloAsso. Enrichissement du modèle `Tiers` (adresse structurée, entreprise, date de naissance, identifiant HelloAsso) et consolidation des deux formulaires existants en un seul formulaire modal avec section détails repliable.

---

## 1. Modèle de données

### Migration `Tiers`

| Champ actuel | Champ futur | Type | Notes |
|---|---|---|---|
| `adresse` (text) | `adresse_ligne1` | string(255), nullable | Renommé — données existantes conservées |
| — | `code_postal` | string(10), nullable | Nouveau |
| — | `ville` | string(100), nullable | Nouveau |
| — | `pays` | string(100), nullable, défaut "France" | Nouveau |
| — | `entreprise` | string(255), nullable | Nom de société (si type = "entreprise") |
| — | `date_naissance` | date, nullable | Nouveau |
| — | `helloasso_id` | string(255), nullable, unique | Identifiant externe HelloAsso — usage interne uniquement |

**Stratégie de migration :** `adresse` est renommé en `adresse_ligne1` via `->renameColumn()`. Les nouveaux champs démarrent à NULL. Aucun parsing des données existantes.

### Méthode `displayName()` (mise à jour)

- `type = "entreprise"` → retourne `$this->entreprise` (fallback sur `$this->nom` si vide)
- `type = "particulier"` → retourne `trim($this->prenom . ' ' . $this->nom)`

---

## 2. Formulaire `TiersForm` — modal unique

### Principe

Un seul composant Livewire `TiersForm`, toujours rendu en **modal overlay**, utilisé dans deux contextes :
- Page `/tiers` (bouton "Nouveau tiers" + édition)
- Inline lors de la saisie d'une transaction (via `TiersAutocomplete`)

### Section principale (toujours visible)

| Champ | Obligatoire | Comportement |
|---|---|---|
| Type | Oui | Radio "Particulier / Entreprise" (`wire:model.live`) |
| Nom de famille | Oui | Label = "Raison sociale" si type = entreprise |
| Prénom | Non | Visible uniquement si type = particulier |
| Entreprise | Non | Visible uniquement si type = entreprise |
| Dépenses / Recettes | Au moins une | Checkboxes usage |

### Section "Détails" (accordéon Bootstrap `collapse`)

Fermée par défaut à la création. **Ouverte automatiquement** en mode édition si au moins un champ détail est renseigné.

| Champ | Type |
|---|---|
| Email | email, nullable |
| Téléphone | string(30), nullable |
| Adresse ligne 1 | string(255), nullable |
| Code postal | string(10), nullable |
| Ville | string(100), nullable |
| Pays | string(100), nullable, pré-rempli "France" |
| Date de naissance | date, nullable |

`helloasso_id` : non affiché dans le formulaire — alimenté uniquement par l'import HelloAsso.

### Comportement switch radio Particulier → Entreprise

- `entreprise` ← `trim(prenom . ' ' . nom)`
- `nom` et `prenom` vidés
- Le switch inverse (Entreprise → Particulier) n'est pas géré (cas non pertinent dans le workflow)

---

## 3. Intégration dans `TiersAutocomplete`

### Suppression du mini-modal

Le mini-modal de création rapide (`showCreateModal`, propriétés `newNom`/`newPrenom`/`newType`/`newPourDepenses`/`newPourRecettes`, méthodes `openCreateModal()`/`confirmCreate()`) est **entièrement supprimé**.

### Nouveau flux "Créer"

1. L'utilisateur tape dans l'autocomplete → clique `+ Créer "Jean Dupont"`
2. `TiersAutocomplete` dispatch l'événement Livewire `open-tiers-form` avec payload :
   ```php
   ['prefill' => ['nom' => $this->search, 'pour_recettes' => true]] // selon filtre
   ```
3. `TiersForm` écoute cet événement (`#[On('open-tiers-form')]`), s'ouvre pré-rempli :
   - `nom` = texte de recherche complet (sans split)
   - `pour_recettes` ou `pour_depenses` coché selon `filtre`
   - `type` = "particulier" par défaut
4. Après sauvegarde, `TiersForm` dispatch `tiers-saved` avec `['id' => $tiers->id]`
5. `TiersAutocomplete` écoute `tiers-saved` et appelle `selectTiers($id)` automatiquement

### Correspondance `filtre` → usage pré-coché

| Valeur `filtre` | Pré-coché |
|---|---|
| `"recettes"`, `"dons"` | `pour_recettes = true` |
| `"depenses"` | `pour_depenses = true` |
| Autre | rien de pré-coché |

---

## 4. Tests

### Migration
- Les colonnes `code_postal`, `ville`, `pays`, `entreprise`, `date_naissance`, `helloasso_id` existent après migration
- La colonne `adresse` n'existe plus (renommée en `adresse_ligne1`)
- Les données existantes dans `adresse_ligne1` sont conservées

### Modèle `Tiers`
- `displayName()` : particulier → `"prenom nom"`, entreprise → valeur du champ `entreprise`
- Création avec champs obligatoires uniquement
- Création avec tous les champs
- `helloasso_id` unique (contrainte DB)

### `TiersForm`
- Rendu type particulier : prénom visible, entreprise absent
- Rendu type entreprise : prénom absent, champ entreprise visible
- Switch radio particulier → entreprise : concat prénom+nom → entreprise, nom/prénom vidés
- Section détails fermée à la création, ouverte à l'édition si données présentes
- Sauvegarde avec champs obligatoires seulement (création OK)
- Sauvegarde avec tous les champs (création OK)
- Validation : au moins un usage coché (erreur sinon)
- Édition : champs pré-remplis, mise à jour persistée

### `TiersAutocomplete`
- `openCreateModal` n'existe plus
- Clic `+ Créer "X"` dispatch `open-tiers-form` avec `nom = "X"` et usage selon filtre
- Réception `tiers-saved` → tiers sélectionné automatiquement dans l'autocomplete

---

## 5. Périmètre explicitement hors scope

- Import/synchronisation des données HelloAsso (lot suivant)
- Déduplication des tiers à l'import (lot suivant)
- Interface de matching tiers HelloAsso ↔ tiers locaux (lot suivant)
- Changement des couleurs / thème (lot dédié)
