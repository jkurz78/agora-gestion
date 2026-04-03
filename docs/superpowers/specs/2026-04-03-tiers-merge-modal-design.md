# Design — Composant TiersMergeModal

**Date** : 2026-04-03
**Contexte** : Enrichissement contrôlé des tiers lors du rapprochement (HelloAsso, formulaire participant, fusion future)

## Problème

Aujourd'hui, quand un tiers est rapproché d'un enregistrement existant (HelloAsso, médecin référent, thérapeute, prescripteur), le mapping se fait automatiquement sans que l'utilisateur puisse arbitrer l'impact sur les données du tiers en base. Les données sont soit écrasées silencieusement, soit ignorées.

## Solution

Un composant Livewire réutilisable `TiersMergeModal` qui présente une modale à 3 colonnes permettant à l'utilisateur de comparer, arbitrer et enrichir les données d'un tiers avant validation.

## Architecture

### Composant Livewire : `TiersMergeModal`

- **Fichier** : `app/Livewire/TiersMergeModal.php`
- **Vue** : `resources/views/livewire/tiers-merge-modal.blade.php`
- **Type** : composant réutilisable, inclus dans chaque vue appelante

### Communication avec les parents

**Ouverture** — Le parent dispatche un événement Livewire :

```php
$this->dispatch('open-tiers-merge', [
    'sourceData'   => [...],       // array clé/valeur des données entrantes
    'tiersId'      => 42,          // ID du tiers existant
    'sourceLabel'  => 'Données HelloAsso',
    'targetLabel'  => 'Tiers existant',
    'confirmLabel' => 'Associer ce tiers HelloAsso',
    'context'      => 'helloasso', // identifiant pour le callback retour
    'contextData'  => [...],       // données additionnelles pour le callback
]);
```

**Retour** — Le composant dispatche :

- `tiers-merge-confirmed` avec `tiersId`, `context`, `contextData` → le parent exécute sa logique métier (associer HelloAsso, lier comme médecin, etc.)
- `tiers-merge-cancelled` avec `context` → le parent revient à l'état de sélection

## Layout de la modale

### Structure 3 colonnes

| Colonne 1 — Source (read-only) | Colonne 2 — Tiers existant (read-only) | Colonne 3 — Résultat (éditable) |
|---|---|---|
| Données entrantes | Données du tiers en BDD | Valeurs finales qui seront enregistrées |

- **En-têtes** : paramétrables via `sourceLabel` et `targetLabel`. Colonne 3 : "Résultat"
- **Bouton de validation** : label paramétrable via `confirmLabel`
- **Bouton annuler** : "Annuler" (fixe)

### Champs affichés (coordonnées uniquement)

| Champ | Clé | Type input col. 3 |
|-------|-----|-------------------|
| Type | `type` | `<select>` (particulier / entreprise) |
| Nom | `nom` | `<input type="text">` |
| Prénom | `prenom` | `<input type="text">` |
| Entreprise | `entreprise` | `<input type="text">` |
| Email | `email` | `<input type="text">` |
| Téléphone | `telephone` | `<input type="text">` |
| Adresse | `adresse_ligne1` | `<input type="text">` |
| Code postal | `code_postal` | `<input type="text">` |
| Ville | `ville` | `<input type="text">` |
| Pays | `pays` | `<input type="text">` |

## Pré-remplissage de la colonne 3

Règles appliquées à l'ouverture de la modale, champ par champ :

1. **Base** : la valeur du tiers existant (colonne 2)
2. **Complétion** : si le champ est vide en colonne 2 et non vide en colonne 1, prendre la valeur de colonne 1
3. **Type** : toujours priorité au tiers existant (colonne 2), quel que soit le type en colonne 1

## Interaction utilisateur

### Copie par clic

- Clic sur un champ en colonne 1 → copie sa valeur dans le champ correspondant de la colonne 3
- Clic sur un champ en colonne 2 → copie sa valeur dans le champ correspondant de la colonne 3
- Le curseur passe au style `pointer` sur les cellules cliquables des colonnes 1 et 2

### Édition manuelle

- La colonne 3 est entièrement éditable (inputs et select)
- L'utilisateur peut saisir une valeur qui ne provient ni de la colonne 1 ni de la colonne 2

### Mise à jour en temps réel

La coloration se recalcule à chaque modification de la colonne 3.

## Coloration visuelle

La coloration s'applique **par ligne** et uniquement quand il y a un **conflit** (colonne 1 et colonne 2 ont toutes deux une valeur non vide et différente).

| Situation | Colonne 1 | Colonne 2 |
|-----------|-----------|-----------|
| Pas de conflit (valeurs identiques, ou une seule colonne remplie) | neutre | neutre |
| Conflit, col. 3 = valeur col. 1 | **vert** | **rouge** |
| Conflit, col. 3 = valeur col. 2 | **rouge** | **vert** |
| Conflit, col. 3 = saisie manuelle (≠ col. 1 et ≠ col. 2) | **rouge** | **rouge** |

Couleurs : rouge brique (`#B5453A` / `--bs-danger`) et vert anglais (`#2E7D32` / `--bs-success`) — couleurs personnalisées définies dans `resources/views/partials/colors.blade.php`.

## Flags booléens (traitement silencieux)

Les champs suivants ne sont **pas affichés** dans la modale. Ils sont traités automatiquement à la validation par un OR logique entre les valeurs du tiers existant et les données source :

- `pour_depenses` : `tiers.pour_depenses || source.pour_depenses`
- `pour_recettes` : `tiers.pour_recettes || source.pour_recettes`
- `est_helloasso` : `tiers.est_helloasso || source.est_helloasso`

## Garde — Conflit d'identité HelloAsso

Si les deux enregistrements ont `est_helloasso = true` avec des `helloasso_nom/helloasso_prenom` différents (identités HelloAsso distinctes) :

- Le bouton de validation est **désactivé**
- Un message d'alerte s'affiche : "Ces deux tiers ont des identités HelloAsso différentes. La fusion n'est pas possible."

Ce cas ne se produira pas dans les contextes actuels (enrichissement), mais prépare le cas fusion futur.

## Validation (bouton confirmer)

1. Mettre à jour le tiers existant avec les 10 champs coordonnées de la colonne 3
2. Appliquer le OR sur les 3 flags booléens
3. Dispatcher `tiers-merge-confirmed` avec `tiersId`, `context`, `contextData`
4. Fermer la modale

## Annulation (bouton annuler ou fermeture modale)

1. Dispatcher `tiers-merge-cancelled` avec `context`
2. Fermer la modale
3. Aucune modification en base

## Normalisation des données source

Le composant attend un `sourceData` dont les clés correspondent aux champs du modèle Tiers (`nom`, `prenom`, `email`, `telephone`, `adresse_ligne1`, `code_postal`, `ville`, `pays`, `type`, `entreprise`). C'est au parent de normaliser les données avant de dispatcher l'événement :

- **HelloAsso** : `lastName` → `nom`, `firstName` → `prenom`, `address` → `adresse_ligne1`, `zipCode` → `code_postal`, `city` → `ville`, `country` → `pays`
- **Médecin** : `medecin_nom` → `nom`, `medecin_prenom` → `prenom`, `medecin_email` → `email`, `medecin_telephone` → `telephone`, `medecin_adresse` → `adresse_ligne1`, `medecin_code_postal` → `code_postal`, `medecin_ville` → `ville`
- **Thérapeute** : `therapeute_nom` → `nom`, etc. (même pattern)
- **Prescripteur** : `adresse_par_nom` → `nom`, `adresse_par_prenom` → `prenom`, etc.

## Points d'intégration

### HelloassoSyncWizard

**Avant** : `associerTiers($index)` met à jour directement les flags HelloAsso du tiers sélectionné.

**Après** : `associerTiers($index)` dispatche `open-tiers-merge` avec :
- `sourceData` : données extraites de la personne HelloAsso (nom, prénom, email, adresse...)
- `tiersId` : `$this->selectedTiers[$index]`
- `sourceLabel` : "Données HelloAsso"
- `confirmLabel` : "Associer ce tiers HelloAsso"
- `context` : `'helloasso'`
- `contextData` : `['index' => $index, 'person' => $person]`

**Callback** `tiers-merge-confirmed` : applique les flags HelloAsso (`est_helloasso`, `helloasso_nom`, `helloasso_prenom`) et met à jour le state local.

### ParticipantShow — Médecin traitant

**Avant** : `mapMedecinTiers()` met à jour `medecin_tiers_id` directement.

**Après** : dispatche `open-tiers-merge` avec :
- `sourceData` : champs `medecin_*` depuis `ParticipantDonneesMedicales`
- `tiersId` : `$this->mapMedecinTiersId`
- `sourceLabel` : "Données médecin du formulaire"
- `confirmLabel` : "Associer comme médecin traitant"
- `context` : `'medecin'`

**Callback** : met à jour `medecin_tiers_id` sur le participant.

### ParticipantShow — Thérapeute référent

Même pattern avec `therapeute_*` et `context: 'therapeute'`.

### ParticipantShow — Adressé par (prescripteur)

Même pattern avec `adresse_par_*` et `context: 'adresse_par'`.

### Futur — Fusion de tiers (hors scope)

Un bouton "Fusionner" sur la fiche d'un tiers ouvrira la même modale avec deux tiers existants. Le traitement post-validation inclura en plus le remplacement en cascade de toutes les références au tiers source par le tiers cible dans les tables liées (transactions, participants, email_logs...).

### Futur — Import CSV de tiers (hors scope)

Un import CSV dédié aux tiers pourra s'appuyer sur la modale de merge. Pour chaque ligne du fichier, recherche automatique dans la base tiers sur : email, téléphone, ou couple prénom/nom. Si un ou plusieurs tiers matchent → proposition de fusion via `TiersMergeModal`. Si aucun match → création automatique du tiers et poursuite de l'import.

## Composants existants non modifiés

- `TiersAutocomplete` : reste inchangé, continue de sélectionner/créer des tiers
- `TiersForm` : reste inchangé, CRUD classique
- Le bouton "Créer depuis HelloAsso" reste disponible en alternative (création automatique sans modale)

## Stack technique

- Livewire 4 pour le composant et la communication événementielle
- Alpine.js pour la logique de copie par clic (réactivité côté client)
- Bootstrap 5 modal + classes utilitaires (`bg-success-subtle`, `bg-danger-subtle`)
- Pas de JS externe, pas de npm
