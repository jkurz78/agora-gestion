# Plan: Messagerie libre depuis l'opération

**Created**: 2026-04-11
**Branch**: main
**Status**: approved

## Goal

Permettre à la secrétaire d'envoyer des emails personnalisés aux participants d'une opération depuis un nouvel onglet "Communication". Les messages utilisent des variables substituées par participant, peuvent inclure des pièces jointes, et alimentent l'historique participant + opération. Une bibliothèque de modèles se construit par l'usage (enregistrer/mettre à jour depuis le formulaire de composition).

## Acceptance Criteria

- [ ] AC1: Onglet "Communication" visible sur la page opération (rôles Compta/Gestion)
- [ ] AC2: Liste participants avec checkboxes, tous cochés par défaut, sans-email grisés non cochables
- [ ] AC3: Sélecteur de modèles groupés par type d'opération puis généraux
- [ ] AC4: Charger un modèle pré-remplit objet + corps avec variables stylisées
- [ ] AC5: Bouton Variables insère les variables existantes + 4 nouvelles séance
- [ ] AC6: "Enregistrer comme modèle" sauvegarde en BDD, réutilisable immédiatement
- [ ] AC7: "Mettre à jour le modèle" met à jour le modèle source
- [ ] AC8: Envoi test → 1 email à l'adresse saisie, variables substituées pour le 1er participant coché
- [ ] AC9: Warning affiché si variable du corps non résoluble
- [ ] AC10: Envoi réel → 1 email personnalisé par participant coché
- [ ] AC11: Progression affichée pendant l'envoi (compteur mis à jour)
- [ ] AC12: Tempo entre chaque envoi (≥500ms)
- [ ] AC13: Chaque envoi logué dans email_logs (catégorie message) avec campagne_id
- [ ] AC14: Campagne créée dans campagnes_email avec totaux corrects
- [ ] AC15: Historique campagnes dans l'onglet Communication
- [ ] AC16: Détail campagne dépliable : statut par participant
- [ ] AC17: Timeline participant affiche les emails message
- [ ] AC18: Upload pièces jointes : max 5, 10 Mo total, validation client + serveur
- [ ] AC19: Fichiers joints nettoyés du stockage temporaire après envoi
- [ ] AC20: PSR-12 (pint), declare(strict_types=1), final class, type hints

## Steps

### Step 1: Migration — table `message_templates`

**Complexity**: standard
**Why separate table**: `email_templates` a une contrainte `unique(['categorie', 'type_operation_id'])` — un seul template par catégorie/type. La bibliothèque de messages nécessite N modèles par type. Table dédiée = zéro risque sur l'existant.
**RED**: Test Pest : la table `message_templates` existe, colonnes attendues, FK vers type_operations
**GREEN**: Migration `create_message_templates_table` : `id`, `nom` (string 100), `objet` (string 255), `corps` (text), `type_operation_id` (nullable FK), `timestamps`
**REFACTOR**: None needed
**Files**: `database/migrations/2026_04_11_000001_create_message_templates_table.php`, `tests/Feature/MessageTemplateTest.php`
**Commit**: `feat(email): create message_templates migration`

### Step 2: Migration — table `campagnes_email` + colonne `campagne_id` sur `email_logs`

**Complexity**: standard
**RED**: Test Pest : table `campagnes_email` existe, colonnes attendues ; `email_logs.campagne_id` nullable FK existe
**GREEN**: Migration : `campagnes_email` (`id`, `operation_id` FK, `objet`, `corps` text, `nb_destinataires` int default 0, `nb_erreurs` int default 0, `envoye_par` FK users nullable, `timestamps`) + `alter email_logs add campagne_id nullable FK`
**REFACTOR**: None needed
**Files**: `database/migrations/2026_04_11_000002_create_campagnes_email_table.php`, `tests/Feature/CampagneEmailTest.php`
**Commit**: `feat(email): create campagnes_email table and email_logs.campagne_id`

### Step 3: Models — `MessageTemplate`, `CampagneEmail` + relations

**Complexity**: standard
**RED**: Test : création MessageTemplate avec relations typeOperation ; création CampagneEmail avec relations operation, envoyePar, emailLogs ; EmailLog->campagne relation
**GREEN**: Modèles Eloquent `final class` avec fillable, casts, relations. Ajouter relation `campagne()` sur EmailLog, relation `emailLogs()` sur CampagneEmail
**REFACTOR**: None needed
**Files**: `app/Models/MessageTemplate.php`, `app/Models/CampagneEmail.php`, `app/Models/EmailLog.php`
**Commit**: `feat(email): add MessageTemplate and CampagneEmail models`

### Step 4: Étendre `CategorieEmail` — case `Message` + variables enrichies

**Complexity**: standard
**RED**: Test : `CategorieEmail::Message->value === 'message'` ; `variables()` contient les 4 nouvelles variables séance ; `label()` retourne 'Message libre'
**GREEN**: Ajouter `case Message = 'message'` à l'enum. Variables = common + `{date_prochaine_seance}`, `{date_precedente_seance}`, `{numero_prochaine_seance}`, `{numero_precedente_seance}`. Mettre à jour `label()`.
**REFACTOR**: None needed
**Files**: `app/Enums/CategorieEmail.php`, `tests/Unit/CategorieEmailTest.php`
**Commit**: `feat(email): add Message category with seance variables`

### Step 5: `MessageLibreMail` — Mailable avec variables + pièces jointes

**Complexity**: standard
**RED**: Test : le Mailable rend le sujet et le corps avec variables substituées ; les pièces jointes sont attachées ; les variables séance non résolubles retournent chaîne vide
**GREEN**: Classe `MessageLibreMail extends Mailable` suivant le pattern de `DocumentMail` : constructeur reçoit participant data + operation data + template objet/corps + attachments array. Méthode privée `variables()` retourne les paires clé/valeur incluant les 4 nouvelles. Méthode statique `unresolvedVariables(string $corps, array $variables): array` pour détecter les warnings.
**REFACTOR**: Extraire la logique de résolution des variables séance (prochaine/précédente) dans une méthode réutilisable
**Files**: `app/Mail/MessageLibreMail.php`, `tests/Unit/MessageLibreMailTest.php`
**Commit**: `feat(email): create MessageLibreMail with seance variables`

### Step 6: Composant Livewire `OperationCommunication` — squelette + onglet

**Complexity**: standard
**RED**: Test Livewire : le composant se monte avec une opération ; l'onglet "Communication" apparaît dans OperationDetail ; cliquer dessus affiche le composant
**GREEN**: Composant `OperationCommunication` avec propriété `$operation`. Vue blade minimale (placeholder). Ajouter le tab `communication` dans `OperationDetail` (setTab, blade nav-item, @if bloc avec `<livewire:operation-communication>`).
**REFACTOR**: None needed
**Files**: `app/Livewire/OperationCommunication.php`, `resources/views/livewire/operation-communication.blade.php`, `app/Livewire/OperationDetail.php`, `resources/views/livewire/operation-detail.blade.php`
**Commit**: `feat(email): add Communication tab to operation page`

### Step 7: Liste participants avec checkboxes

**Complexity**: standard
**RED**: Test : la liste affiche tous les participants de l'opération ; propriété `$selectedParticipants` contient tous les IDs par défaut ; participants sans email sont exclus de la sélection ; décocher/cocher met à jour le compteur
**GREEN**: Propriétés `$participants` (computed), `$selectedParticipants` (array d'IDs). Blade : table avec checkboxes `wire:model="selectedParticipants"`, checkbox "Sélectionner tout", compteur "N destinataires sélectionnés", participants sans email grisés disabled.
**REFACTOR**: None needed
**Files**: `app/Livewire/OperationCommunication.php`, `resources/views/livewire/operation-communication.blade.php`
**Commit**: `feat(email): participant selection with checkboxes`

### Step 8: Sélecteur de modèles + chargement

**Complexity**: standard
**RED**: Test : le sélecteur affiche les modèles groupés (type_operation puis généraux) ; sélectionner un modèle remplit `$objet` et `$corps` ; propriété `$selectedTemplateId` est mise à jour
**GREEN**: Propriété `$selectedTemplateId`, méthode `loadTemplate()`. Computed `$availableTemplates` groupés par type_operation. Blade : `<select wire:model="selectedTemplateId" wire:change="loadTemplate">` avec `<optgroup>`.
**REFACTOR**: None needed
**Files**: `app/Livewire/OperationCommunication.php`, `resources/views/livewire/operation-communication.blade.php`
**Commit**: `feat(email): template selector with optgroup loading`

### Step 9: Éditeur TinyMCE + insertion de variables + warning non résolubles

**Complexity**: complex
**RED**: Test : le bouton Variables liste les variables de la catégorie Message ; warning affiché quand une variable du corps n'a pas de valeur (ex: aucune séance future) ; le corps est synchronisé avec la propriété Livewire
**GREEN**: Intégrer TinyMCE via Alpine `tinymceEditor()` (même pattern que TypeOperationManager). Bouton Variables custom avec les variables de `CategorieEmail::Message->variables()`. Méthode PHP `getUnresolvedVariables()` appelée à chaque mise à jour du corps pour afficher les warnings sous l'éditeur. Synchronisation via `@entangle` ou dispatch JS→Livewire.
**REFACTOR**: None needed
**Files**: `app/Livewire/OperationCommunication.php`, `resources/views/livewire/operation-communication.blade.php`
**Commit**: `feat(email): TinyMCE editor with variables and unresolved warnings`

### Step 10: Enregistrer / mettre à jour un modèle

**Complexity**: standard
**RED**: Test : "Enregistrer comme modèle" crée un MessageTemplate en BDD ; "Mettre à jour" modifie le modèle source ; le modèle est immédiatement disponible dans le sélecteur
**GREEN**: Propriétés `$templateNom`, `$templateTypeOperationId`. Méthode `saveAsTemplate()` : crée un MessageTemplate. Méthode `updateTemplate()` : met à jour le modèle chargé. Blade : bouton "Enregistrer comme modèle" ouvre un formulaire inline (nom + type_operation optionnel). Si modèle chargé, afficher aussi "Mettre à jour {nom}".
**REFACTOR**: None needed
**Files**: `app/Livewire/OperationCommunication.php`, `resources/views/livewire/operation-communication.blade.php`
**Commit**: `feat(email): save and update message templates inline`

### Step 11: Upload pièces jointes (max 5, 10 Mo)

**Complexity**: standard
**RED**: Test : upload d'un fichier l'ajoute à la liste ; validation max 5 fichiers, 10 Mo total ; suppression individuelle fonctionne ; types autorisés (pdf, jpg, png, doc, docx)
**GREEN**: Trait `WithFileUploads`. Propriété `$emailAttachments` (array, PAS `$file`). Règles de validation Livewire. Blade : input file + liste des fichiers avec bouton supprimer + messages d'erreur.
**REFACTOR**: None needed
**Files**: `app/Livewire/OperationCommunication.php`, `resources/views/livewire/operation-communication.blade.php`
**Commit**: `feat(email): file attachments with validation`

### Step 12: Envoi test

**Complexity**: standard
**RED**: Test : l'envoi test envoie 1 email à l'adresse saisie ; variables substituées pour le 1er participant coché ; toast de confirmation ; pas de log dans email_logs (c'est un test)
**GREEN**: Propriété `$testEmail` (initialisée à `Auth::user()->email`). Méthode `envoyerTest()` : construit `MessageLibreMail` pour le 1er participant sélectionné, envoie à `$testEmail`. Toast Livewire.
**REFACTOR**: Extraire la construction du Mailable dans une méthode privée `buildMail(Participant $participant): MessageLibreMail`
**Files**: `app/Livewire/OperationCommunication.php`, `resources/views/livewire/operation-communication.blade.php`
**Commit**: `feat(email): test email sending`

### Step 13: Envoi réel avec progression et tempo

**Complexity**: complex
**RED**: Test : l'envoi crée une CampagneEmail ; envoie N emails (1 par participant) ; chaque envoi logué dans email_logs avec campagne_id ; compteurs nb_destinataires/nb_erreurs corrects ; progression mise à jour ; tempo ≥500ms entre envois
**GREEN**: Méthode `envoyerMessages()` : confirmation modale Bootstrap → crée CampagneEmail → boucle sur participants sélectionnés avec `$this->dispatch('progression', ...)` ou propriétés réactives `$envoiEnCours`, `$envoiProgression`, `$envoiTotal`. Sleep(500ms) entre envois. Try/catch par participant. Mise à jour campagne en fin. Nettoyage fichiers temporaires.
**REFACTOR**: None needed
**Files**: `app/Livewire/OperationCommunication.php`, `resources/views/livewire/operation-communication.blade.php`
**Commit**: `feat(email): bulk send with progress and throttling`

### Step 14: Historique campagnes dans l'onglet Communication

**Complexity**: standard
**RED**: Test : les campagnes de l'opération s'affichent en liste (date, objet, nb dest, nb erreurs) ; cliquer déplie le détail par participant (email_logs de la campagne)
**GREEN**: Computed `$campagnes` (CampagneEmail de l'opération, desc). Blade : liste sous le formulaire de composition. Chaque campagne dépliable (Alpine `x-show`) montrant les email_logs associés avec statut (badge vert/rouge).
**REFACTOR**: None needed
**Files**: `app/Livewire/OperationCommunication.php`, `resources/views/livewire/operation-communication.blade.php`
**Commit**: `feat(email): campaign history with expandable details`

### Step 15: Timeline participant — intégrer les emails message

**Complexity**: trivial
**RED**: Test : un email_log catégorie 'message' apparaît dans la timeline de ParticipantShow
**GREEN**: Dans `ParticipantShow::render()`, les email_logs sont déjà chargés et affichés dans la timeline. Vérifier que la catégorie 'message' est bien prise en charge (pas de filtre excluant). Si filtre existe, l'étendre.
**REFACTOR**: None needed
**Files**: `app/Livewire/ParticipantShow.php`, `tests/Feature/ParticipantShowTest.php`
**Commit**: `feat(email): show message emails in participant timeline`

### Step 16: Seeds par défaut — modèles de messages standards

**Complexity**: trivial
**RED**: Test : après seed, au moins 3 MessageTemplate existent avec corps contenant des variables
**GREEN**: Seeder `MessageTemplateSeeder` avec 3-5 modèles : "Rappel séance J-2", "Remerciements et questionnaire", "Information logistique". Corps avec variables `{prenom}`, `{date_prochaine_seance}`, etc.
**REFACTOR**: None needed
**Files**: `database/seeders/MessageTemplateSeeder.php`, `database/seeders/DatabaseSeeder.php`
**Commit**: `feat(email): seed default message templates`

## Complexity Classification

| Rating | Criteria | Review depth |
|--------|----------|--------------|
| `trivial` | Single-file rename, config change, typo fix, documentation-only | Skip inline review |
| `standard` | New function, test, module, or behavioral change within existing patterns | Spec-compliance + relevant quality agents |
| `complex` | Architectural change, security-sensitive, cross-cutting concern | Full agent suite |

## Pre-PR Quality Gate

- [ ] All tests pass (`./vendor/bin/pest`)
- [ ] Linter passes (`./vendor/bin/pint --test`)
- [ ] `/code-review --changed` passes
- [ ] Migration testée avec `migrate:fresh --seed`
- [ ] Test manuel : composer un message, envoyer un test, envoyer en réel, vérifier historique

## Risks & Open Questions

| Risk | Mitigation |
|------|------------|
| Envoi synchrone lent sur 200+ participants | Tempo 500ms = ~100s pour 200 participants. Acceptable MVP, queue Laravel en v2 si besoin |
| Progression Livewire pendant envoi synchrone | Livewire ne peut pas mettre à jour le DOM pendant une requête longue. Options : (a) `stream()` Livewire 3+, (b) découper en batches via JS polling, (c) wire:poll court. À explorer au step 13 |
| TinyMCE body sync avec Livewire | Pattern validé dans TypeOperationManager (Alpine entangle). Réutiliser tel quel |
| Nommage propriété upload Livewire | Utiliser `$emailAttachments`, jamais `$file` ni `upload()` (feedback mémoire) |
| `email_from` non configuré sur le type d'opération | Vérifier avant envoi, afficher erreur claire (pattern existant dans ParticipantShow) |
