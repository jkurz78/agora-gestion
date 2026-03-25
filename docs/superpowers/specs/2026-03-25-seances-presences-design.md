# Onglet Séances — Suivi des présences

**Date :** 2026-03-25
**Statut :** Draft

## Contexte

L'écran Gestion des Opérations a un onglet "Séances" grisé. Ce lot l'active avec une matrice de suivi des présences (séances en colonnes, participants en lignes).

## Périmètre

1. Table `seances` et modèle Eloquent
2. Table `presences` (données chiffrées) et modèle Eloquent
3. Enum `StatutPresence`
4. Composant Livewire `SeanceTable` avec matrice éditable
5. Activation de l'onglet Séances (visible si flag `peut_voir_donnees_sensibles`)
6. L'onglet Séances se positionne juste après Participants

## Modèle de données

### Table `seances`

| Colonne | Type | Contraintes |
|---|---|---|
| `id` | bigint PK | auto-increment |
| `operation_id` | FK → operations | NOT NULL |
| `numero` | integer | NOT NULL |
| `date` | date | nullable |
| `titre` | string(255) | nullable |
| `created_at`, `updated_at` | timestamps | |

Contrainte unique : `(operation_id, numero)`

### Table `presences`

Données chiffrées — suivi opérationnel sensible.

| Colonne | Type BDD | Cast | Contraintes |
|---|---|---|---|
| `id` | bigint PK | — | auto-increment |
| `seance_id` | FK → seances | — | NOT NULL |
| `participant_id` | FK → participants | — | NOT NULL |
| `statut` | text | `encrypted` | nullable |
| `kine` | text | `encrypted` | nullable |
| `commentaire` | text | `encrypted` | nullable |
| `created_at`, `updated_at` | timestamps | | |

Contrainte unique : `(seance_id, participant_id)`
Cascade delete depuis `seances` et `participants`.

### Enum `StatutPresence`

| Valeur | Label |
|---|---|
| `present` | Présent |
| `excuse` | Excusé |
| `absence_non_justifiee` | Absence non justifiée |
| `arret` | Arrêt |

### Relations

- `Seance` belongsTo `Operation`, hasMany `Presence`
- `Presence` belongsTo `Seance`, belongsTo `Participant`
- `Operation` hasMany `Seance` (orderBy numero)

## Interface — Onglet Séances

### Positionnement

L'onglet "Séances" se place juste après "Participants", avant "Compte résultat". Visible uniquement si `peut_voir_donnees_sensibles`.

### Barre d'outils

- Toggle "Séances proches" : filtre les séances dont la date est à ±2 semaines d'aujourd'hui (ou sans date). Désactivé par défaut = toutes affichées.
- Bouton "Ajouter une séance" : crée une séance avec le prochain numéro disponible.

### Matrice

**En-têtes (2 lignes par séance, éditables) :**
- Ligne 1 : Titre (input text, placeholder "Titre...", sauvegarde au blur)
- Ligne 2 : Date (input text, placeholder "jj/mm/aaaa", sauvegarde au blur)

**Colonne fixe à gauche** (sticky) : nom du participant.

**Chaque cellule participant × séance :**
- Select compact de statut : vide / Présent / Excusé / Absence non justifiée / Arrêt (petit select, font réduite)
- Checkbox "K" (Kiné)
- Commentaire : click-to-edit (affiche le texte ou "—", clic pour saisir, sauvegarde au blur, 200 caractères max)

**Scroll horizontal** si beaucoup de séances.

**Édition :** Les selects et checkboxes sont toujours visibles/éditables (pas de click-to-edit pour ces champs). Seul le commentaire est en click-to-edit.

**Sauvegarde :** Chaque modification déclenche un appel Livewire `updatePresence(seanceId, participantId, field, value)` au blur/change. Le modèle Presence est créé via updateOrCreate si nécessaire.

### Totaux

En bas de chaque colonne séance : nombre de présents / total participants.

## Changements techniques

### Nouveaux fichiers

| Fichier | Description |
|---|---|
| `app/Enums/StatutPresence.php` | Enum avec labels fr |
| `app/Models/Seance.php` | Modèle séance |
| `app/Models/Presence.php` | Modèle présence (chiffrée) |
| `database/migrations/xxxx_create_seances_table.php` | Table séances |
| `database/migrations/xxxx_create_presences_table.php` | Table présences |
| `app/Livewire/SeanceTable.php` | Composant matrice |
| `resources/views/livewire/seance-table.blade.php` | Vue matrice |

### Fichiers modifiés

| Fichier | Changement |
|---|---|
| `app/Models/Operation.php` | Ajout relation `seances()` |
| `resources/views/livewire/gestion-operations.blade.php` | Activation onglet Séances après Participants, conditionnel flag |

## Tests

- Test modèle Seance : création, contrainte unique (operation_id, numero), relation operation
- Test modèle Presence : chiffrement/déchiffrement, contrainte unique (seance_id, participant_id), cascade delete
- Test composant SeanceTable : affichage matrice, ajout séance, mise à jour présence, toggle séances proches
- Test accès : onglet masqué si pas le flag
