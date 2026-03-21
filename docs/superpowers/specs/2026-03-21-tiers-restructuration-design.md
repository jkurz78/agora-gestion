# Tiers — Restructuration modèle et formulaire unique

**Date :** 2026-03-21
**Contexte :** Préparation à l'import HelloAsso. Enrichissement du modèle `Tiers` (adresse structurée, entreprise, date de naissance, identifiant HelloAsso) et consolidation des deux formulaires existants en un seul formulaire modal avec section détails repliable.

---

## 1. Modèle de données

### Migration `Tiers`

| Champ actuel | Champ futur | Type DB | Notes |
|---|---|---|---|
| `adresse` (text) | `adresse_ligne1` | **text** (inchangé, renommé seulement) | `renameColumn` sans changement de type |
| — | `code_postal` | string(10), nullable | Nouveau |
| — | `ville` | string(100), nullable | Nouveau |
| — | `pays` | string(100), nullable, défaut "France" | Nouveau |
| — | `entreprise` | string(255), nullable | Nom de société (voir sémantique ci-dessous) |
| — | `date_naissance` | date, nullable | Nouveau |
| — | `helloasso_id` | string(255), nullable, unique | Identifiant externe HelloAsso |

**Stratégie :** `adresse` → `adresse_ligne1` via `->renameColumn()` (type `text` conservé). Les nouveaux champs démarrent à NULL. Aucun parsing des données existantes.

**Migration `down()` :** renommer `adresse_ligne1` → `adresse`, supprimer les 6 nouvelles colonnes.

**Contrainte UNIQUE sur `helloasso_id` :** en MySQL, un index UNIQUE autorise plusieurs valeurs NULL (les NULL ne sont pas considérés égaux). La contrainte ne porte que sur les valeurs non nulles. Il ne faut **pas** ajouter de règle de validation PHP `unique` qui rejetterait les lignes avec `helloasso_id = null`.

### Sémantique des champs `nom` / `entreprise` selon le type

| Type | `nom` | `prenom` | `entreprise` |
|---|---|---|---|
| `particulier` | Nom de famille (obligatoire) | Prénom (optionnel) | Désactivé dans le formulaire, peut contenir une valeur en base |
| `entreprise` | Nom du contact chez la société (optionnel) | Masqué | Raison sociale (obligatoire pour l'affichage) |

Cette sémantique correspond à la structure HelloAsso `payer` : `lastName` + `firstName` + `company`.

### Méthode `displayName()` (mise à jour)

- `type = "entreprise"` → retourne `$this->entreprise ?? $this->nom`
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
| Nom de famille | Oui (particulier) | Label = "Nom de famille" si particulier |
| Entreprise | Oui (entreprise) | Visible et requis si type = entreprise ; champ disabled si particulier |
| Prénom | Non | Visible uniquement si type = particulier |
| Dépenses / Recettes | Au moins une | Checkboxes usage |

### Section "Détails" (accordéon Bootstrap `collapse`)

Fermée par défaut à la création. **Ouverte automatiquement** en mode édition si au moins un champ détail est renseigné.

| Champ | Type |
|---|---|
| Nom du contact | string(150), nullable — visible si type = entreprise (contact chez la société) |
| Email | email, nullable |
| Téléphone | string(30), nullable |
| Adresse ligne 1 | text, nullable |
| Code postal | string(10), nullable |
| Ville | string(100), nullable |
| Pays | string(100), nullable, pré-rempli "France" |
| Date de naissance | date, nullable |

`helloasso_id` : non affiché dans le formulaire — alimenté uniquement par l'import HelloAsso.

### Comportement switch radio Particulier → Entreprise

- `entreprise` ← `trim(prenom . ' ' . nom)` (texte de la section principale transféré vers le champ entreprise)
- `nom` et `prenom` vidés
- Le switch inverse (Entreprise → Particulier) n'est pas géré (cas non pertinent dans le workflow)

### Dispatch après sauvegarde

```php
// Dans save(), AVANT resetForm()
$tiers = ...; // tiers créé ou mis à jour
$id = $tiers->id;
$this->dispatch('tiers-saved', id: $id);
$this->resetForm();
```

`$id` doit être capturé avant `resetForm()` car celui-ci vide `$this->tiersId`.

---

## 3. Intégration dans `TiersAutocomplete`

### Suppression du mini-modal

Les éléments suivants sont **entièrement supprimés** de `TiersAutocomplete` :
- Propriétés : `showCreateModal`, `newNom`, `newPrenom`, `newType`, `newPourDepenses`, `newPourRecettes`
- Méthodes : `openCreateModal()`, `confirmCreate()`
- Vue : bloc `@if($showCreateModal)`

### Conservation du modal "tiers existant inactif"

Le modal `showActivateModal` / `activateTiers()` (qui propose d'activer un tiers existant exclu par le filtre) est **conservé tel quel**.

### Nouveau flux "Créer"

1. L'utilisateur tape dans l'autocomplete → clique `+ Créer "Jean Dupont"`
2. `TiersAutocomplete` dispatch vers `TiersForm` :
   ```php
   $this->dispatch('open-tiers-form', prefill: [
       'nom' => $this->search,
       'pour_recettes' => in_array($this->filtre, ['recettes', 'dons']),
       'pour_depenses' => $this->filtre === 'depenses',
   ])->to(\App\Livewire\TiersForm::class);
   ```
   > **Livewire 4 :** `->to(ClassName::class)` est requis pour qu'un composant enfant/sibling reçoive l'événement. Sans cela, l'événement ne sort pas du composant émetteur.

3. `TiersForm` écoute cet événement :
   ```php
   #[On('open-tiers-form')]
   public function openWithPrefill(array $prefill): void
   ```
   Pré-rempli : `nom` = texte complet (sans split), `type = "particulier"`, usage selon payload, `showForm = true`.

   > **Note intentionnelle :** le texte entier est placé dans `nom` sans tentative de split prénom/nom. L'utilisateur ajuste manuellement dans le formulaire complet. C'est un choix délibéré : le split automatique par espace est fragile et produit des erreurs silencieuses.

4. Après sauvegarde, `TiersForm` dispatch `tiers-saved` avec `id`.
5. `TiersAutocomplete` écoute `tiers-saved` (`#[On('tiers-saved')]`) et appelle `selectTiers($id)`.

   > **Livewire 4 :** `tiers-saved` dispatché par `TiersForm` sans `->to()` remonte vers les parents et les écouteurs globaux. `TiersAutocomplete` doit avoir `#[On('tiers-saved')]` sur sa méthode.

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
- `helloasso_id` : deux tiers avec valeurs non-nulles identiques → erreur d'unicité ; deux tiers avec `helloasso_id = null` → acceptés tous les deux

### Modèle `Tiers`
- `displayName()` particulier → `"prenom nom"`
- `displayName()` entreprise avec `entreprise` renseigné → retourne `entreprise`
- `displayName()` entreprise avec `entreprise` null → fallback sur `nom`
- Création avec champs minimaux
- Création avec tous les champs

### `TiersForm`
- Rendu type particulier : prénom visible, champ entreprise désactivé
- Rendu type entreprise : prénom absent, champ entreprise actif et requis
- Switch radio particulier → entreprise : `trim(prenom + ' ' + nom)` → `entreprise`, `nom`/`prenom` vidés
- Section détails fermée à la création
- Section détails ouverte à l'édition si au moins un champ détail est renseigné
- Sauvegarde avec champs obligatoires seulement (création OK)
- Sauvegarde avec tous les champs (création OK)
- Validation : au moins un usage coché (erreur sinon)
- Édition : champs pré-remplis, mise à jour persistée
- Dispatch `tiers-saved` avec `id` après création (id disponible après `resetForm()`)
- Réception `open-tiers-form` : `showForm = true`, `nom` pré-rempli, usage pré-coché, `type = "particulier"`

### `TiersAutocomplete`
- Clic `+ Créer "X"` : dispatch `open-tiers-form` avec `nom = "X"` et usage selon filtre (vers `TiersForm::class`)
- `showCreateModal` et méthodes supprimées n'existent plus
- `showActivateModal` / `activateTiers()` fonctionnent toujours
- Réception `tiers-saved` avec `id` → `tiersId` positionné, tiers sélectionné

---

## 5. Périmètre explicitement hors scope

- Import/synchronisation des données HelloAsso (lot suivant)
- Déduplication des tiers à l'import (lot suivant)
- Interface de matching tiers HelloAsso ↔ tiers locaux (lot suivant)
- Changement des couleurs / thème (lot dédié)
