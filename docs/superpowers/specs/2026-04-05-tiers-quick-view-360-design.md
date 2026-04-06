# Tiers Quick View 360° — Spec de design

**Date :** 2026-04-05
**Version :** Lot 1 — Vision synthétique

## Objectif

Créer une popup de consultation rapide ("Quick View 360°") affichant une vision synthétique de tout ce qu'on sait sur un tiers donné : ses transactions, participations, rôles de référent, factures. La popup est invocable depuis n'importe où dans l'application via une icône info.

### Use cases

1. **Consultation rapide (use case principal)** : L'utilisateur est dans un workflow (saisie, consultation), voit un tiers, et veut rapidement savoir qui il est et quel est son historique, sans quitter son contexte.
2. **Exploration** : Les liens dans la popup renvoient vers les écrans existants (transactions, opérations, participants) pour un drill-down approfondi. Un lot 2 éventuel pourrait proposer une fiche d'analyse dédiée.

## Architecture

### Composants

| Composant | Type | Rôle |
|---|---|---|
| `TiersQuickView` | Livewire component | Popover riche — reçoit un `tiersId`, charge les données agrégées, rend le HTML |
| `TiersInfoIcon` | Blade component (pas Livewire) | Icône cliquable `<x-tiers-info-icon :tiersId="$id" />` qui dispatch un événement pour ouvrir le popover |
| `TiersQuickViewService` | Service PHP | Centralise toutes les requêtes d'agrégation |

### Flux

```
Clic icône info
  → dispatch browser event 'open-quick-view' { tiersId, anchorEl }
  → TiersQuickView (unique dans app.blade.php) reçoit l'event
  → Appelle TiersQuickViewService::getSummary(tiers, exercice)
  → Popover Bootstrap s'affiche ancré à l'icône, contenu HTML riche
  → Liens dans la popup → navigation vers écrans existants (routes standard)
  → Icône info sur un tiers lié dans la popup → recharge le popover avec le nouveau tiersId
```

### Placement dans le layout

Un seul `<livewire:tiers-quick-view />` dans `app.blade.php`, partagé par toute l'application. Le composant `TiersInfoIcon` est un simple Blade component sans état serveur.

## Contenu de la popup

### En-tête (toujours affiché)

- Type du tiers (badge : Particulier / Entreprise)
- Email (lien `mailto:`) — si renseigné
- Téléphone (lien `tel:`) — si renseigné
- Sélecteur d'exercice (petit dropdown, exercice en cours par défaut)

### Sections conditionnelles

Chaque section n'apparaît **que si des données existent** pour ce tiers. Un tiers sans activité n'affiche que l'en-tête contact.

#### Dépenses

- **Source** : `transactions` WHERE `tiers_id = X` AND `type = depense`, scopé `forExercice()`
- **Contenu** : Total dépenses sur l'exercice + ventilation par opération
  - Via `transaction_lignes.operation_id` : regroupement par opération avec sous-catégorie
  - Exemple : "Atelier Yoga — Animation : 450 EUR (3 dépenses)"
- **Liens** : Chaque opération → page opération ; total → transactions du tiers filtrées dépenses

#### Recettes

- **Source** : `transactions` WHERE `tiers_id = X` AND `type = recette`, scopé `forExercice()`
- **Contenu** : "X recettes / X EUR sur l'exercice"
- **Lien** : → transactions du tiers filtrées recettes

#### Dons

- **Source** : `transactions` WHERE `tiers_id = X` AND `type = don`, scopé `forExercice()`
- **Contenu** : "X dons / X EUR sur l'exercice"
- **Lien** : → transactions du tiers filtrées dons

#### Adhésions (cotisations)

- **Source** : `transactions` WHERE `tiers_id = X` AND `type = cotisation`, scopé `forExercice()`
- **Contenu** : "Cotisation exercice : X EUR" ou section absente
- **Lien** : → transactions du tiers filtrées cotisations

#### Participations

- **Source** : `participants` WHERE `tiers_id = X`, avec eager load de l'opération
- **Contenu** : Liste des opérations auxquelles le tiers a participé (nom, date)
- **Lien** : Chaque opération → page opération

#### Référent (gate : `peut_voir_donnees_sensibles`)

- **Condition d'affichage** : `auth()->user()->peut_voir_donnees_sensibles` ET données existantes
- **Source** : `participants` WHERE `refere_par_id = X` OU `medecin_tiers_id = X` OU `therapeute_tiers_id = X`
- **Contenu** : Regroupé par catégorie :
  - "Référent de : [liste participants]"
  - "Médecin de : [liste participants]"
  - "Thérapeute de : [liste participants]"
- **Liens** : Chaque participant → page participant ; icône info sur les tiers liés

#### Factures

- **Source** : `factures` WHERE `tiers_id = X`, scopé par exercice (sur `date_emission`)
- **Contenu** : "X factures dont X impayées / X EUR"
- **Lien** : → liste factures (filtrée si possible)

### Pied de popup

Lien "Toutes les transactions →" vers `/compta/tiers/{id}/transactions`

## Service d'agrégation : `TiersQuickViewService`

### Méthode principale

```php
public function getSummary(Tiers $tiers, int $exercice): array
```

### Structure de retour

Les clés ne sont présentes **que si des données existent** :

```php
[
    'contact' => [
        'email' => 'john@example.com',    // nullable
        'telephone' => '06 12 34 56 78',  // nullable
    ],
    'depenses' => [
        'count' => 4,
        'total' => 1650.00,
        'par_operation' => [
            [
                'operation_id' => 12,
                'operation_nom' => 'Atelier Yoga',
                'sous_categorie' => 'Animation',
                'count' => 3,
                'total' => 450.00,
            ],
            // ...
        ],
    ],
    'recettes' => [
        'count' => 5,
        'total' => 3400.00,
    ],
    'dons' => [
        'count' => 2,
        'total' => 500.00,
    ],
    'cotisations' => [
        'count' => 1,
        'total' => 50.00,
    ],
    'participations' => [
        ['operation_id' => 12, 'operation_nom' => 'Atelier Yoga', 'date_debut' => '2025-10-01'],
        // ...
    ],
    'referent' => [   // absent si !peut_voir_donnees_sensibles
        'refere_par' => [
            ['participant_id' => 5, 'nom' => 'Dupont Marie'],
        ],
        'medecin' => [...],
        'therapeute' => [...],
    ],
    'factures' => [
        'count' => 4,
        'impayees' => 1,
        'total' => 2000.00,
    ],
]
```

## Points d'intégration de l'icône info

L'icône `<x-tiers-info-icon :tiersId="$id" />` est ajoutée aux endroits suivants :

1. **TiersAutocomplete** — dans le pill de sélection (quand un tiers est sélectionné)
2. **TiersList** — colonne Nom dans le tableau
3. **Tableaux de transactions** — colonne Tiers (dépenses, recettes, dons, cotisations)
4. **ParticipantTable / ParticipantShow** — champs tiers, médecin, thérapeute, référent
5. **Liste factures** — colonne Tiers

Chaque ajout est une seule ligne dans un template Blade ; facilement réversible (rechercher/supprimer `<x-tiers-info-icon`).

## Comportement du popover

### Interaction

- **Ouverture** : clic sur l'icône info
- **Fermeture** : clic ailleurs, touche Escape, ou clic sur une autre icône info
- **Un seul popover** ouvert à la fois
- **Navigation entre tiers** : clic sur une icône info dans la popup → recharge le contenu avec le nouveau `tiersId` (popover reste ouvert)
- **Changement d'exercice** : recharge les données sans fermer le popover

### Positionnement

- Placement préféré : `right` (à droite de l'icône)
- Fallback automatique Bootstrap/Popper.js : `left`, `top`, `bottom`
- Largeur fixe : ~450px

### Loading

- Au clic, le popover s'ouvre immédiatement avec un spinner
- Le composant Livewire charge les données côté serveur
- Le contenu remplace le spinner une fois les données reçues

## Stack technique

- **Composant Livewire 4** (cohérent avec l'architecture existante)
- **Bootstrap 5 Popover** avec contenu HTML (option `html: true`)
- **Alpine.js** pour le positionnement (passage des coordonnées DOM via `$el`)
- **Blade component** pour l'icône (pas de surcharge serveur)
- **Service PHP** pour les requêtes agrégées (pattern existant `app/Services/`)

## Hors périmètre (lot 1)

- Fiche d'analyse complète (page dédiée avec onglets/drill-down)
- Actions depuis la popup (édition du tiers, envoi email, etc.)
- Export/impression de la fiche
- Historique multi-exercices (on a le sélecteur, pas de comparaison)
