# Écran Gestion des Opérations et Participants

**Date :** 2026-03-25
**Statut :** Draft

## Contexte

Le modèle participants (tables `participants` et `participant_donnees_medicales`) est implémenté. Ce lot crée l'écran principal de gestion des opérations dans l'espace Gestion, avec un tableau de participants éditable inline.

## Périmètre

1. Page `/gestion/operations` : sélecteur d'opération + onglets (Détails, Participants)
2. Tableau participants éditable inline (click-to-edit)
3. Modales ajout/édition participant avec tous les champs Tiers
4. Notes médicales avec éditeur WYSIWYG (Quill CDN)
5. Export Excel via openspout
6. Navigation : lien Opérations dans la navbar Gestion, lien depuis le dashboard Gestion
7. Migration : ajout `taille`, `notes` sur données médicales, `refere_par_id` sur participants
8. Retrait du composant ParticipantList de la fiche opération compta

## Page `/gestion/operations`

### Structure

**Route :** `GET /gestion/operations` → vue `gestion.operations` avec composant Livewire `GestionOperations`

**Paramètre URL :** `?id=X` pour pré-sélectionner une opération (utilisé par le dashboard Gestion).

**Sélecteur d'opération (en haut) :**
- Dropdown des opérations de l'exercice courant (nom + dates)
- Bouton "+" à droite pour créer une nouvelle opération (modale, réutilise le formulaire existant)
- Si aucune opération sélectionnée : message invitant à en choisir ou en créer une

**Onglets Bootstrap (en dessous) :**
- **Détails** — informations de l'opération en lecture seule (nom, description, dates, séances, statut). Pas de bilan financier (celui-ci reste côté compta).
- **Participants** — tableau éditable inline + barre d'outils
- **Séances** — grisé, non cliquable (lot futur)
- **Finances** — grisé, non cliquable (lot futur)

## Onglet Participants

### Barre d'outils

Au-dessus du tableau :
- À gauche : compteur "X participants"
- À droite : bouton "Exporter Excel" (icône bi-file-earmark-spreadsheet + texte), bouton "Ajouter un participant" (bouton primaire)

### Tableau

**Colonnes :**

| Colonne | Source | Éditable inline | Triable | Chiffré | Condition |
|---|---|---|---|---|---|
| Nom | Tiers | oui (modifie le Tiers) | oui | non | toujours |
| Prénom | Tiers | oui (modifie le Tiers) | oui | non | toujours |
| Téléphone | Tiers | oui (modifie le Tiers) | non | non | toujours |
| Email | Tiers | oui (modifie le Tiers) | non | non | toujours |
| Date inscription | participants | oui | oui | non | toujours |
| Date naissance | données médicales | oui | non | oui | flag sensible |
| Sexe | données médicales | oui | non | oui | flag sensible |
| Taille | données médicales | oui | non | oui | flag sensible |
| Poids | données médicales | oui | non | oui | flag sensible |
| Référé par | participants | oui (autocomplete tiers) | oui | non | toujours |
| Notes méd. | données médicales | modale WYSIWYG | non | oui (HTML) | flag sensible |
| Actions | — | éditer (modale) / supprimer | — | — | toujours |

**Comportement édition inline (style click-to-edit) :**
- Clic sur une cellule → elle se transforme en input (text, date, select selon le type)
- Sauvegarde automatique au blur (sortie du champ) ou Entrée
- Escape annule la modification
- Le curseur change au survol des cellules éditables
- Sexe : select (F/M)
- Date naissance, date inscription : composant `<x-date-input>` avec Flatpickr
- Référé par : autocomplete tiers (composant léger inline)
- Notes médicales : icône dans la cellule, survol → aperçu en bulle (tooltip HTML), clic → modale avec éditeur Quill

**Tri :**
- JS côté client, convention existante (`data-sort` sur les `<td>`)
- Colonnes triables : Nom, Prénom, Date inscription, Référé par
- Tri par défaut : Nom + Prénom alphabétique

### Modale d'ajout

Ouverte via le bouton "Ajouter un participant".

**Contenu :**
- Sélecteur de tiers (`TiersAutocomplete`, filtre `tous`, typeFiltre `particulier`)
- Possibilité de créer un nouveau tiers via le bouton existant dans l'autocomplete
- Date d'inscription (défaut : aujourd'hui, composant `<x-date-input>`)
- Tous les champs Tiers affichés et éditables : nom, prénom, adresse ligne 1, code postal, ville, téléphone, email
- Boutons Annuler / Inscrire

**Comportement :** À la sélection d'un tiers existant, les champs Tiers se pré-remplissent et restent éditables. Les modifications sont sauvegardées sur le Tiers en base à la validation.

### Modale d'édition

Ouverte via le bouton éditer (icône crayon) par ligne. Alternative à l'édition inline.

**Contenu :** Tous les champs du participant et du Tiers :
- Champs Tiers : nom, prénom, adresse ligne 1, code postal, ville, téléphone, email
- Date inscription
- Référé par (autocomplete tiers)
- Si flag `peut_voir_donnees_sensibles` : date naissance, sexe, taille, poids, notes médicales (éditeur Quill)
- Boutons Annuler / Enregistrer

### Suppression

Bouton supprimer (icône poubelle) par ligne avec confirmation (`wire:confirm`). Supprime le participant et ses données médicales en cascade.

## Export Excel

**Route :** `GET /gestion/operations/{operation}/participants/export`

**Lib :** `openspout/openspout` (composer require)

**Contenu du fichier .xlsx :**
- En-têtes en gras
- Colonnes identiques au tableau (sauf Actions et Notes médicales)
- Les colonnes médicales ne sont incluses que si l'utilisateur a le flag `peut_voir_donnees_sensibles`
- Nom du fichier : `participants-{nom-operation}-{date}.xlsx`

## Notes médicales — Éditeur WYSIWYG

**Lib CDN :** Quill.js (Snow theme)

**Barre d'outils minimale :** Gras, Italique, Liste à puces, Liste numérotée

**Stockage :** HTML chiffré dans `participant_donnees_medicales.notes` (cast `encrypted`)

**Affichage dans le tableau :** Icône (bi-journal-text ou bi-chat-text). Au survol : tooltip avec aperçu du texte (rendu HTML, tronqué). Au clic : modale avec l'éditeur Quill complet.

## Migrations

### Ajout colonnes `participant_donnees_medicales`

- `taille` (text, nullable) — cast `encrypted`
- `notes` (text, nullable) — cast `encrypted`, contient du HTML

### Ajout colonne `participants`

- `refere_par_id` (FK → tiers, nullable)

## Modifications du modèle

### `Participant`

- Ajout `refere_par_id` au fillable
- Ajout relation `referePar()` → BelongsTo Tiers

### `ParticipantDonneesMedicales`

- Ajout `taille`, `notes` au fillable
- Ajout casts `encrypted` pour `taille` et `notes`

## Navigation

### Navbar Gestion (mise à jour)

- Adhérents
- **Opérations** (nouveau, lien direct vers `/gestion/operations`)
- Sync HelloAsso
- Paramètres (le lien Opérations est retiré du sous-menu Paramètres côté Gestion)
- Menu utilisateur

### Dashboard Gestion

Le lien sur le nom de chaque opération dans la carte Opérations pointe vers `/gestion/operations?id={id}` au lieu de `/compta/operations/{id}`.

### Fiche opération compta

Retrait de `<livewire:participant-list :operation="$operation" />` de `resources/views/operations/show.blade.php`. Le composant `ParticipantList` existant (provisoire) peut être supprimé.

## Changements techniques

### Nouveaux fichiers

| Fichier | Description |
|---|---|
| `app/Livewire/GestionOperations.php` | Page principale sélecteur + onglets |
| `resources/views/livewire/gestion-operations.blade.php` | Vue du composant |
| `resources/views/gestion/operations.blade.php` | Wrapper page |
| `app/Livewire/ParticipantTable.php` | Tableau éditable inline |
| `resources/views/livewire/participant-table.blade.php` | Vue click-to-edit + modales |
| `app/Http/Controllers/ParticipantExportController.php` | Export Excel openspout |
| `database/migrations/xxxx_add_taille_notes_to_participant_donnees_medicales.php` | Nouvelles colonnes médicales |
| `database/migrations/xxxx_add_refere_par_id_to_participants.php` | FK référé par |

### Fichiers modifiés

| Fichier | Changement |
|---|---|
| `routes/web.php` | Ajout routes `/gestion/operations` et export |
| `resources/views/layouts/app.blade.php` | Ajout "Opérations" dans navbar Gestion, retrait du lien Paramètres/Opérations côté Gestion |
| `resources/views/operations/show.blade.php` | Retrait composant ParticipantList |
| `resources/views/livewire/gestion-dashboard.blade.php` | Lien opérations → `/gestion/operations?id=X` |
| `app/Models/Participant.php` | Ajout `refere_par_id`, relation `referePar()` |
| `app/Models/ParticipantDonneesMedicales.php` | Ajout `taille`, `notes`, casts encrypted |

### Fichiers supprimés

| Fichier | Raison |
|---|---|
| `app/Livewire/ParticipantList.php` | Remplacé par ParticipantTable |
| `resources/views/livewire/participant-list.blade.php` | Remplacé par participant-table |

### Dépendances

- `composer require openspout/openspout` — export Excel
- Quill.js via CDN (Snow theme) — éditeur WYSIWYG notes médicales

## Tests

- Test page `/gestion/operations` : chargement, sélection opération, pré-sélection via `?id=X`
- Test onglet Détails : affichage infos opération
- Test onglet Participants : affichage tableau, colonnes conditionnelles médicales
- Test ajout participant : modale, autocomplete tiers, validation
- Test édition inline : modification cellule, sauvegarde auto, modification Tiers
- Test modale édition : tous les champs affichés et éditables
- Test suppression participant : confirmation, cascade données médicales
- Test export Excel : génération fichier, colonnes conditionnelles, contenu correct
- Test navigation : navbar Gestion, lien dashboard, retrait ParticipantList de compta
- Test contrôle accès : colonnes médicales masquées sans flag
