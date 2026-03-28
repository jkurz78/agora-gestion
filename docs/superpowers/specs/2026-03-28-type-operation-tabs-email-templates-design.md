# Refonte modale TypeOperation — Onglets + Gabarits email TinyMCE

**Date :** 2026-03-28
**Statut :** Validé

## Contexte

La modale TypeOperation est devenue trop chargée (code, nom, description, sous-catégorie, séances, 3 switches, logo, tarifs, email expéditeur, gabarit email, test email). Il faut la découper en onglets et enrichir la partie email avec :
- 3 types de gabarits (formulaire, attestation de présence, facture)
- Un éditeur texte riche (TinyMCE) avec insertion de variables
- Un système de modèle par défaut / personnalisé par type d'opération

## Design

### Modale — 3 onglets

| Onglet | Contenu |
|--------|---------|
| **Général** | Code, Nom, Description, Sous-catégorie, Nb séances, Switches (confidentiel, adhérents, actif), Logo |
| **Tarifs** | Tableau trié par montant décroissant (Libellé, Montant, Actions), ajout en dernière ligne du tableau, en-tête bleu foncé `#3d5473` |
| **Emails** | Adresse expéditeur + sous-onglets par catégorie de gabarit |

### Mode création vs édition

| Élément | Création | Édition |
|---------|----------|---------|
| Navigation | Boutons Suivant / Précédent | Onglets librement cliquables |
| Onglets visités | Cliquables une fois visités | Tous cliquables |
| Bouton Enregistrer | Uniquement sur le dernier onglet (Emails) | Visible en permanence |

### Onglet Tarifs — Tableau propre

- Tableau avec colonnes : Libellé / Montant / Actions (supprimer)
- En-tête `table-dark` avec `--bs-table-bg:#3d5473` (cohérent app)
- Montant affiché formaté (123,50 €)
- Champs d'ajout (libellé + montant + bouton) intégrés en dernière ligne du tableau
- Tri par montant décroissant

### Table `email_templates`

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | bigint PK | |
| `categorie` | enum(`formulaire`, `attestation`, `facture`) | Type de gabarit |
| `type_operation_id` | FK nullable → `type_operations` | NULL = modèle par défaut |
| `objet` | varchar(255) | Objet de l'email (avec variables) |
| `corps` | text | Corps HTML riche (TinyMCE) |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Contrainte unique** : `(categorie, type_operation_id)` — un seul gabarit par catégorie par type d'opération, et un seul défaut par catégorie.

**FK** : `type_operation_id` avec `onDelete('cascade')` — suppression d'un type supprime ses gabarits personnalisés.

**Seeder** : crée 3 enregistrements par défaut (`type_operation_id = NULL`) avec un contenu HTML riche. Le contenu exact sera défini lors de l'implémentation.

### Onglet Emails — UX

**En haut** : Adresse d'expédition (nom + email + bouton Tester) — reste stocké sur `type_operations` (c'est l'expéditeur, pas le gabarit).

**Sous-onglets** : Formulaire / Attestation / Facture

Pour chaque sous-onglet :
- **Select** avec 2 options :
  - "Modèle par défaut" → édite le gabarit avec `type_operation_id = NULL`
  - "Personnalisé — {nom du type}" → édite le gabarit lié à ce type (n'apparaît que s'il existe)
- **Bouton "Personnaliser"** : copie le défaut dans un nouveau gabarit lié au type, l'éditeur affiche la copie
- **Bouton "Revenir au défaut"** : supprime le gabarit personnalisé, retour au défaut
- **Éditeur TinyMCE** pour le corps (objet reste un input text simple)

### Comportement du modèle par défaut

- Quand "Modèle par défaut" est sélectionné, l'éditeur affiche le contenu du gabarit par défaut **en lecture seule** dans le contexte de la modale TypeOperation
- Pour modifier un gabarit par défaut, passer par Paramètres > (futur écran dédié) ou directement en base via le seeder — on n'expose pas l'édition du défaut depuis la modale d'un type spécifique pour éviter qu'un utilisateur ne modifie involontairement le défaut pour tous les types
- Pour personnaliser : cliquer "Personnaliser" → copie créée via `updateOrCreate` sur `(categorie, type_operation_id)` pour éviter les doublons en cas de race condition

### TinyMCE — Intégration technique

- **Version** : TinyMCE 5 (MIT, pas de licence commerciale requise)
- **Self-hosted** : fichiers JS dans `public/vendor/tinymce/`
- **Toolbar** : `bold italic underline | bullist numlist | link | variablesButton`
- **Bouton custom "Variables ▾"** : menu déroulant créé via `editor.ui.registry.addMenuButton`, liste adaptée selon la catégorie du sous-onglet actif
- **Insertion** : texte brut `{prenom}` à la position du curseur
- **Synchronisation Livewire** : `<textarea>` caché synchronisé avec l'éditeur via event `change` de TinyMCE

### Variables par catégorie

| Variable | Formulaire | Attestation | Facture | Description |
|----------|:---:|:---:|:---:|-------------|
| `{prenom}` | x | x | x | Prénom du participant |
| `{nom}` | x | x | x | Nom du participant |
| `{operation}` | x | x | x | Nom de l'opération |
| `{type_operation}` | x | x | x | Nom du type d'opération |
| `{date_debut}` | x | x | x | Date début opération |
| `{date_fin}` | x | x | x | Date fin opération |
| `{nb_seances}` | x | x | x | Nombre de séances (depuis l'opération) |
| `{numero_seance}` | | x | x | Numéro de la séance |
| `{date_seance}` | | x | x | Date de la séance |
| `{date_facture}` | | | x | Date de la facture |
| `{numero_facture}` | | | x | Numéro de la facture |

### Migration des données existantes

1. Créer la table `email_templates`
2. Pour chaque `type_operations` ayant `email_formulaire_corps` non null :
   - Créer un enregistrement `email_templates` avec `categorie = 'formulaire'`, `type_operation_id = type.id`, objet et corps migrés
3. Supprimer les colonnes `email_formulaire_objet` et `email_formulaire_corps` de `type_operations`
4. Les colonnes `email_from` et `email_from_name` **restent** sur `type_operations`

**Note rollback** : la migration `down()` recrée les colonnes sur `type_operations` et recopie les données depuis `email_templates` avant de supprimer la table. Migration réversible.

### Mail classes

- `FormulaireInvitation` : adapter pour lire depuis `email_templates` au lieu des champs `type_operations`. Le corps est maintenant du HTML riche — ne plus appliquer `nl2br(e(...))`. Sanitiser le HTML côté serveur avec `strip_tags()` en ne gardant que les balises autorisées (`<p><br><strong><em><u><ul><ol><li><a>`) avant rendu. Ajouter un paramètre `nomTypeOperation` pour la variable `{type_operation}`.
- `AttestationPresence` : **nouvelle** mail class (à créer quand la fonctionnalité d'envoi sera implémentée)
- `Facture` : **nouvelle** mail class (à créer quand la fonctionnalité d'envoi sera implémentée)

Note : seul le gabarit Formulaire a un envoi fonctionnel aujourd'hui. Les gabarits Attestation et Facture sont créés et éditables mais l'envoi n'est pas encore câblé.

**Éléments automatiques** (hors contenu éditable TinyMCE) : le lien formulaire, le code token et la date d'expiration restent ajoutés automatiquement sous le corps du message dans la vue Blade `formulaire-invitation.blade.php`. Ils ne sont pas des variables éditables.

## Fichiers impactés

| Fichier | Action |
|---------|--------|
| `database/migrations/2026_03_28_..._create_email_templates_table.php` | **Créer** — table + migration données |
| `database/seeders/EmailTemplateSeeder.php` | **Créer** — 3 gabarits par défaut |
| `app/Models/EmailTemplate.php` | **Créer** — modèle Eloquent |
| `app/Enums/CategorieEmail.php` | **Créer** — enum (formulaire, attestation, facture) |
| `app/Models/TypeOperation.php` | **Modifier** — ajouter relation `emailTemplates()`, retirer `email_formulaire_*` de `$fillable` |
| `database/seeders/DatabaseSeeder.php` | **Modifier** — appeler `EmailTemplateSeeder` |
| `app/Livewire/TypeOperationManager.php` | **Modifier** — onglets, gestion gabarits, intégration TinyMCE |
| `resources/views/livewire/type-operation-manager.blade.php` | **Modifier** — restructurer en onglets, TinyMCE, sous-onglets email |
| `app/Mail/FormulaireInvitation.php` | **Modifier** — lire depuis `email_templates`, HTML riche |
| `resources/views/emails/formulaire-invitation.blade.php` | **Modifier** — adapter pour HTML riche (plus de `nl2br(e())`) |
| `app/Livewire/ParticipantTable.php` | **Modifier** — charger gabarit depuis `email_templates` |
| `public/vendor/tinymce/` | **Créer** — fichiers TinyMCE self-hosted |
| `tests/Feature/TypeOperationTest.php` | **Modifier** — adapter pour onglets et gabarits |

## Validation

- [ ] Modale s'ouvre avec 3 onglets (Général / Tarifs / Emails)
- [ ] Mode création : Suivant/Précédent, Enregistrer uniquement sur dernier onglet
- [ ] Mode édition : onglets librement cliquables, Enregistrer visible partout
- [ ] Onglet Tarifs : tableau trié par montant décroissant, ajout en dernière ligne
- [ ] Onglet Emails : sous-onglets Formulaire/Attestation/Facture
- [ ] Select "Modèle par défaut" / "Personnalisé" fonctionne
- [ ] Bouton Personnaliser copie le défaut
- [ ] Bouton Revenir au défaut supprime le personnalisé
- [ ] TinyMCE s'affiche et fonctionne (B/I/U, listes, liens)
- [ ] Bouton Variables insère la variable à la position du curseur
- [ ] Variables différentes selon le sous-onglet actif
- [ ] Envoi email formulaire fonctionne toujours (corps HTML riche)
- [ ] Migration : gabarits existants migrés correctement
- [ ] Seeder : 3 gabarits par défaut créés
- [ ] Modèle par défaut en lecture seule dans la modale TypeOperation
- [ ] Modifier le défaut depuis type A ne corrompt pas ce que voit type B
- [ ] Annuler la modale en création ne sauvegarde pas de gabarit
- [ ] TinyMCE survit aux re-renders Livewire (wire:ignore)
- [ ] État des onglets réinitialisé à la fermeture/réouverture de la modale
- [ ] Bouton Tester fonctionne depuis l'onglet Emails
- [ ] Suppression d'un TypeOperation cascade sur ses email_templates
- [ ] Variable `{type_operation}` fonctionne dans l'envoi formulaire
