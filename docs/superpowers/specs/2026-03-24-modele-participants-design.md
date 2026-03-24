# Modèle Participants — Inscription aux opérations

**Date :** 2026-03-24
**Statut :** Draft

## Contexte

Les opérations SVS sont des parcours de séances (ateliers, formations) auxquels des tiers s'inscrivent en tant que participants. Ce lot pose le modèle de données des participants et une interface minimale pour gérer les inscriptions depuis la fiche opération.

La synchro HelloAsso n'est pas modifiée dans ce lot — les colonnes de traçabilité HelloAsso sont prévues dans le modèle mais le peuplement automatique viendra dans un lot futur. Les inscriptions sont uniquement manuelles pour ce lot.

### Vision future (hors lot)

- Peuplement automatique des participants via la synchro HelloAsso
- Séances datées avec suivi de présence (Présent/Absent/Excusé + notes animateur chiffrées)
- Suivi financier par participant : montant prévu vs réalisé, échéanciers
- Bordereaux de remise avec détail par participant
- Portail déclaratif : email envoyé au participant pour qu'il remplisse ses informations personnelles et médicales, avec suivi des réponses et relances manuelles

## Périmètre

1. Tables `participants` et `participant_donnees_medicales`
2. Modèles Eloquent avec relations
3. Flag `peut_voir_donnees_sensibles` sur le User
4. Composant Livewire `ParticipantList` intégré dans la fiche opération
5. Interface de gestion du flag dans Paramètres > Utilisateurs

## Modèle de données

### Table `participants`

Pivot enrichi Tiers ↔ Opération. Un tiers ne peut être inscrit qu'une seule fois à une opération donnée.

| Colonne | Type | Contraintes |
|---|---|---|
| `id` | bigint PK | auto-increment |
| `tiers_id` | FK → tiers | NOT NULL |
| `operation_id` | FK → operations | NOT NULL |
| `date_inscription` | date | NOT NULL |
| `est_helloasso` | boolean | default false |
| `helloasso_item_id` | integer | nullable |
| `helloasso_order_id` | integer | nullable |
| `notes` | text | nullable |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Contrainte unique :** `(tiers_id, operation_id)`

**Index :** `operation_id` (requêtes fréquentes par opération)

### Table `participant_donnees_medicales`

Données sensibles chiffrées, isolées par opération pour faciliter la purge RGPD. Relation 1:1 avec `participants`.

| Colonne | Type en BDD | Cast Eloquent | Contraintes |
|---|---|---|---|
| `id` | bigint PK | — | auto-increment |
| `participant_id` | FK → participants | — | NOT NULL, unique |
| `date_naissance` | text | `encrypted` | nullable |
| `sexe` | text | `encrypted` | nullable |
| `poids` | text | `encrypted` | nullable |
| `created_at` | timestamp | — | |
| `updated_at` | timestamp | — | |

Les colonnes sont `text` en BDD car le chiffrement Laravel (AES-256-CBC via APP_KEY) stocke du texte encodé. Les valeurs sont nullable car les données peuvent ne pas encore être renseignées.

**Purge RGPD :** Supprimer la ligne `participant_donnees_medicales` suffit à purger les données sensibles d'un participant pour une opération. La suppression en cascade depuis `participants` est configurée via `onDelete('cascade')`.

### Migration `users`

Ajout colonne `peut_voir_donnees_sensibles` (boolean, default false) sur la table `users`.

## Modèles Eloquent

### `Participant` (`app/Models/Participant.php`)

- `declare(strict_types=1)`, `final class`
- Fillable : `tiers_id`, `operation_id`, `date_inscription`, `est_helloasso`, `helloasso_item_id`, `helloasso_order_id`, `notes`
- Casts : `date_inscription` → `date`, `est_helloasso` → `boolean`
- Relations :
  - `tiers()` → BelongsTo Tiers
  - `operation()` → BelongsTo Operation
  - `donneesMedicales()` → HasOne ParticipantDonneesMedicales

### `ParticipantDonneesMedicales` (`app/Models/ParticipantDonneesMedicales.php`)

- `declare(strict_types=1)`, `final class`
- Table : `participant_donnees_medicales`
- Fillable : `participant_id`, `date_naissance`, `sexe`, `poids`
- Casts : `date_naissance` → `encrypted`, `sexe` → `encrypted`, `poids` → `encrypted`
- Relation :
  - `participant()` → BelongsTo Participant

### Relations ajoutées aux modèles existants

- `Operation` : `participants()` → HasMany Participant
- `Tiers` : `participants()` → HasMany Participant
- `User` : ajout `peut_voir_donnees_sensibles` au fillable et cast boolean

## Contrôle d'accès aux données sensibles

Le flag `peut_voir_donnees_sensibles` sur le User contrôle l'accès :

- **Chargement conditionnel :** les composants Livewire ne chargent la relation `donneesMedicales` (`with('donneesMedicales')`) que si `auth()->user()->peut_voir_donnees_sensibles` est true
- **Affichage conditionnel :** les colonnes date de naissance, sexe, poids ne sont rendues dans le Blade que si le flag est activé
- **Saisie/modification :** le formulaire de données médicales n'est accessible que si le flag est activé
- **Par défaut :** le flag est false pour tous les utilisateurs existants — activation manuelle explicite requise

Pas de middleware dédié ni de système de permissions complexe — le contrôle se fait au niveau des requêtes et des vues.

## Interface

### Section Participants sur la fiche opération

**Emplacement :** Page `/compta/operations/{id}` (OperationController@show), nouvelle section sous les informations financières existantes.

**Composant Livewire `ParticipantList`** recevant l'opération en paramètre.

**Tableau des participants :**

| Colonne | Visible | Condition |
|---|---|---|
| Nom du tiers | toujours | — |
| Date d'inscription | toujours | — |
| Date de naissance | conditionnel | `peut_voir_donnees_sensibles` |
| Sexe | conditionnel | `peut_voir_donnees_sensibles` |
| Poids | conditionnel | `peut_voir_donnees_sensibles` |
| Actions | toujours | — |

**Actions :**
- Bouton "Ajouter un participant" → modale avec :
  - Sélecteur de tiers (autocomplete sur nom/prénom)
  - Possibilité de créer un nouveau tiers via la modale TiersForm existante
  - Date d'inscription (défaut : aujourd'hui)
- Par participant :
  - Saisir/modifier les données médicales (modale, si flag activé)
  - Supprimer le participant (avec confirmation)

### Gestion du flag utilisateur

**Emplacement :** Écran Paramètres > Utilisateurs existant.

Ajout d'une checkbox "Accès aux données sensibles" par utilisateur dans le formulaire existant.

## Changements techniques

### Nouveaux fichiers

| Fichier | Description |
|---|---|
| `app/Models/Participant.php` | Modèle pivot enrichi Tiers ↔ Opération |
| `app/Models/ParticipantDonneesMedicales.php` | Données sensibles chiffrées |
| `database/migrations/xxxx_create_participants_table.php` | Table participants avec contrainte unique |
| `database/migrations/xxxx_create_participant_donnees_medicales_table.php` | Table données médicales chiffrées |
| `database/migrations/xxxx_add_peut_voir_donnees_sensibles_to_users.php` | Flag sur users |
| `app/Livewire/ParticipantList.php` | Composant liste, ajout, suppression, données médicales |
| `resources/views/livewire/participant-list.blade.php` | Vue du composant |

### Fichiers modifiés

| Fichier | Changement |
|---|---|
| `app/Models/User.php` | Ajout `peut_voir_donnees_sensibles` au fillable et cast |
| `app/Models/Operation.php` | Ajout relation `participants()` |
| `app/Models/Tiers.php` | Ajout relation `participants()` |
| `resources/views/operations/show.blade.php` | Intégration `<livewire:participant-list :operation="$operation" />` |
| `resources/views/parametres/utilisateurs/index.blade.php` | Checkbox données sensibles |

### Ce qui ne change pas

- Synchro HelloAsso (pas de peuplement automatique des participants)
- Transactions / comptabilité
- Modèle Operation (sauf ajout de relation)
- Le double espace Compta/Gestion
- Les séances / présence (lot futur)

## Tests

- Test modèle Participant : création, contrainte unique (tiers_id, operation_id), relations
- Test modèle ParticipantDonneesMedicales : chiffrement/déchiffrement, cascade delete
- Test flag utilisateur : `peut_voir_donnees_sensibles` par défaut false, activation
- Test composant ParticipantList : ajout participant, suppression avec confirmation, affichage conditionnel des données médicales
- Test contrôle d'accès : données médicales non visibles si flag false, visibles si flag true
