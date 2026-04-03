# Design — Écran Analyse (Pivot Table)

**Date** : 2026-04-03
**Contexte** : Prototype d'écran de reporting dynamique type TCD, basé sur PivotTable.js

## Problème

Pour analyser les données (participants, règlements, transactions), l'utilisateur doit exporter en CSV puis construire un tableau croisé dans Excel. Un écran d'analyse interactif intégré à l'application éviterait cette étape.

## Solution

Un composant Livewire `AnalysePivot` servant les données en JSON à PivotTable.js (lib CDN). Deux vues de données sélectionnables via un toggle. Prototype isolé, facile à retirer si non concluant.

## Architecture

- **Route** : `/gestion/analyse`
- **Menu** : entrée "Analyse" dans l'espace Gestion
- **Composant** : `app/Livewire/AnalysePivot.php`
- **Vue** : `resources/views/livewire/analyse-pivot.blade.php`

### Dépendances CDN (chargées uniquement sur cette page)

- jQuery 3.7.1 slim
- jQueryUI 1.13.2
- PivotTable.js 2.23.0 + locale FR (`pivot.min.js`, `pivot.fr.min.js`, `pivot.min.css`)

## Vue 1 — Participants / Règlements

**Grain** : un règlement (= un participant × une séance)

| Champ | Source | Type pivot |
|-------|--------|------------|
| Opération | `operations.nom` | dimension |
| Type opération | `type_operations.nom` | dimension |
| Séance (n°) | `seances.numero` | dimension |
| Séance (date) | `seances.date` | dimension |
| Séance (titre) | `seances.titre` | dimension |
| Participant (nom) | `tiers.nom` | dimension |
| Participant (prénom) | `tiers.prenom` | dimension |
| Ville | `tiers.ville` | dimension |
| Date inscription | `participants.date_inscription` | dimension |
| Mode paiement | `reglements.mode_paiement` | dimension |
| Montant prévu | `reglements.montant_prevu` | mesure |
| Statut présence | `presences.statut` | dimension |

**Requête** : `Reglement` → join `Participant` → join `Tiers` → join `Seance` → join `Operation` → join `TypeOperation`, left join `Presence` sur `(participant_id, seance_id)`.

## Vue 2 — Financière

**Grain** : une ligne de transaction

| Champ | Source | Type pivot |
|-------|--------|------------|
| Opération | `operations.nom` | dimension |
| Type opération | `type_operations.nom` via operation | dimension |
| Séance (n°) | `transaction_lignes.seance` | dimension |
| Tiers | `tiers.displayName()` | dimension |
| Type tiers | `tiers.type` | dimension |
| Date | `transactions.date` | dimension |
| Montant | `transaction_lignes.montant` | mesure |
| Sous-catégorie | `sous_categories.nom` | dimension |
| Catégorie | via sous-catégorie parent | dimension |
| Type (dépense/recette) | `transactions.type` | dimension |
| Compte | `comptes.nom` | dimension |

**Requête** : `TransactionLigne` → join `Transaction` → join `Tiers` → left join `Operation` → left join `TypeOperation` → join `SousCategorie` → join `Compte`.

## Filtre global

Sélecteur d'exercice en haut de page (scope `forExercice()`) pour limiter les données à l'exercice sélectionné.

## UI

- Toggle boutons "Participants / Règlements" | "Financière" en haut
- Zone PivotTable.js en dessous (pleine largeur)
- PivotTable.js gère le drag & drop, filtres, agrégats
- Config par défaut : lignes = Opération, colonnes = vide, valeur = Montant, agrégat = Somme
- Locale française (noms d'agrégats traduits)

## Isolation (prototype)

- Route dédiée, composant dédié, vue dédiée
- Aucune modification des modèles/services existants
- Si dead end → supprimer la route, le composant, la vue et l'entrée menu

## Stack technique

- Laravel 11 + Livewire 4 (composant serveur pour les requêtes)
- PivotTable.js 2.23.0 via CDN (rendu client)
- jQuery 3.7.1 + jQueryUI 1.13.2 via CDN
- Bootstrap 5 pour le layout (toggle, sélecteur exercice)
