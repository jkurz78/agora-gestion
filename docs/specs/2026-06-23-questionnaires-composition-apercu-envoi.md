# Questionnaires — Composition, aperçu & envoi (+ commentaire satisfaction)

> Slice suivant du V1 numérique, **après lots 1–5** (livrés sur `feat/questionnaires`) et
> **avant** le papier (lots 6–7). Spec issue du brainstorming du 2026-06-23 ; décisions
> actées ci-dessous. Référence socle : [2026-06-22-questionnaires-satisfaction-spec.md](2026-06-22-questionnaires-satisfaction-spec.md).

**Date :** 2026-06-23
**Branche cible :** `feat/questionnaires`
**Statut :** en attente de relecture utilisateur (avant plan)

---

## 1. Décisions actées

| # | Sujet | Décision |
|---|---|---|
| E1 | Envoi des invitations | **Action dédiée « Envoyer les invitations » sur la campagne** ouvrant un compositeur (objet + corps TinyMCE + variables dont `{lien_questionnaire}` + sélection participants), réutilisant le `Mailable` + `EmailLog` existants. Pas via l'écran Communication de l'opération. |
| E2 | Composition riche | **Intro + remerciement = TinyMCE + variables** ; **titre affiché = texte simple mais variables autorisées** (ex. « Votre avis sur {operation} »). |
| E3 | Aperçu (mode test) | Bouton « Prévisualiser » **sur le modèle ET sur la campagne** ; rejoue le vrai parcours page par page, **aucune réponse enregistrée**, route admin authentifiée (pas de token). |
| E4 | Variables en aperçu | **Valeurs d'exemple** ({prenom}→« Jean », {operation}→vrai nom si campagne, sinon « Mon opération »). |
| D1 | Portée du commentaire optionnel | **Satisfaction uniquement** (toggle par question). |
| D2 | Libellé du commentaire | **Configurable par question**, défaut « Un commentaire ? (optionnel) ». |
| D3 | Export d'une satisfaction-avec-commentaire | **Deux colonnes** : « <libellé> » (note 1-5) + « <libellé> — commentaire » (texte). |
| D4 | Stockage du commentaire | **Même ligne de réponse** : `value_integer` = note, `value_text` = commentaire. Pas de nouvelle table. |
| D5 | Validation obligatoire (révisée) | `verifierObligatoires` vérifie la **colonne primaire du type** (`TypeQuestion::valueColumn()`) non nulle — pas « une colonne quelconque ». Le commentaire est **toujours facultatif**. |

---

## 2. Partie 0 — `QuestionnaireVariableResolver` (socle commun)

Service `app/Services/Questionnaire/QuestionnaireVariableResolver.php` qui produit la map
`{variable} => valeur` à partir du contexte questionnaire. Utilisé par A (rendu répondant),
B (aperçu, valeurs d'exemple) et C (envoi, + `{lien_questionnaire}`).

- `pour(QuestionnaireInvitation $invitation, bool $avecLien = false): array` — résout depuis
  `invitation → participant → tiers` + `campaign → operation → typeOperation` + association.
- `exemple(?QuestionnaireCampaign $campagne = null): array` — valeurs d'exemple pour l'aperçu
  (si `$campagne` fourni : vrai `operation`/dates, participant fictif « Jean Dupont »).
- `remplacer(string $html, array $vars): string` — `str_replace` des clés ; **les valeurs sont
  échappées** (`e()`) avant insertion dans le HTML déjà assaini, pour empêcher toute injection.

Variables exposées (réutilise le jeu d'opération existant de `CategorieEmail::variables()`) :
`{prenom} {nom} {civilite} {politesse} {civilite_nom} {politesse_nom} {salutation}`
`{operation} {type_operation} {association} {date_debut} {date_fin} {nb_seances}`.
Variable supplémentaire **réservée à l'email** (C) : `{lien_questionnaire}` = `invitation->lienReponse()`.
Les variables liées au lien ne sont **pas** proposées dans intro/remerciement (le répondant est
déjà sur la page).

---

## 3. Partie A — Composition riche des messages (intro / remerciement / titre)

### 3.1 Édition (modèle)
- `ModeleList` (modale d'édition du modèle) : **intro** et **remerciement** passent de `textarea`
  à **TinyMCE enrichi** (même config que les emails — table, image, listes, styles) + un bouton
  « Insérer une variable ». **titre_affiche** reste un `input` texte, mais accepte les `{variables}`.
- Pas de nouveau champ DB : `intro` et `remerciement` (text) stockent désormais du **HTML**.

### 3.2 Assainissement
- À l'enregistrement, intro/remerciement sont **assainis** (HTMLPurifier) en réutilisant le pattern
  d'`EmailTemplate` (protection des `{var}` dans les attributs `href`/`src` avant purify). Extraire
  un helper partagé `App\Support\HtmlTemplateSanitizer` si le code d'`EmailTemplate` n'est pas déjà
  factorisable ; sinon mirrorer.

### 3.3 Snapshot & rendu
- Le snapshot de campagne copie `titre_affiche`/`intro`/`remerciement` (HTML + `{vars}`) — inchangé
  structurellement.
- **Rendu répondant** (parcours réel ET aperçu) : `QuestionnaireVariableResolver::remplacer(...)`
  résout les variables, puis le HTML (déjà assaini) est affiché en `{!! !!}`. Le titre est résolu
  puis affiché échappé (texte simple).

---

## 4. Partie B — Mode aperçu (sans enregistrement)

### 4.1 Accès
- Bouton **« Prévisualiser »** sur l'éditeur de modèle et sur chaque ligne de campagne.
- Routes admin (groupe `questionnaires.*`, authentifié — pas de token) :
  - `GET /questionnaires/modeles/{template}/apercu` → `questionnaires.modeles.apercu`
  - `GET /questionnaires/campagnes/{campagne}/apercu` → `questionnaires.campagnes.apercu`
- Controller `QuestionnaireApercuController` (mince).

### 4.2 Comportement
- Rejoue le **vrai parcours page par page** via les **mêmes vues** que le répondant : intro →
  1 question/page → consentement → remerciement. Navigation en **GET** (`?page=N`).
- **Aucune** soumission ni réponse écrite en base ; aucune validation obligatoire bloquante.
- Variables résolues en **valeurs d'exemple** (E4) via `QuestionnaireVariableResolver::exemple()`.
- **Bandeau permanent** : « Mode aperçu — aucune réponse n'est enregistrée. »

### 4.3 Refactor DRY (rendu d'un champ)
- Extraire le rendu d'un champ par type dans un **partial partagé**
  `resources/views/questionnaire/repondant/partials/champ.blade.php`, utilisé par :
  - la vue `question.blade.php` du parcours réel (form POST),
  - la vue d'aperçu (navigation GET).
- La vue `question.blade.php` reçoit un drapeau `$apercu` (défaut false) : en aperçu, « Suivant »
  est un **lien GET** vers `?page=N+1` (pas de form POST). Le partial `champ` est identique dans les
  deux modes (et porte le rendu du commentaire satisfaction — partie D).

---

## 5. Partie C — Envoi par la messagerie (lot 8 remonté)

### 5.1 Compositeur
- Bouton **« Envoyer les invitations »** sur la campagne (statut `ouverte`) → composant Livewire
  `app/Livewire/Questionnaire/EnvoiCompose.php` (section/modale) :
  - **objet** (input) + **corps** (TinyMCE enrichi) + bouton « Insérer une variable » (le jeu inclut
    `{lien_questionnaire}`),
  - **sélection des participants** (défaut = tous ; pour une relance = non soumis),
  - un **corps par défaut** pré-rempli (gabarit minimal seedé). **DETTE TECH actée** : la
    sauvegarde/réutilisation de gabarits d'email questionnaire est **hors périmètre** de ce slice
    (à reprendre via `EmailTemplate`/`MessageTemplate` quand le besoin se confirme).

### 5.2 Envoi
- `app/Services/Questionnaire/QuestionnaireEnvoiService.php` :
  - `idsNonSoumis(campagne): array<int>`,
  - `envoyer(campagne, array $invitationIds, string $objet, string $corps): void` — génère les
    invitations manquantes pour les participants ciblés, puis **par invitation** : résout les
    variables (`QuestionnaireVariableResolver::pour($invitation, avecLien: true)`), envoie un
    `Mailable`, crée l'`EmailLog` (mêmes champs que `OperationCommunication`), pose `invitation.sent_at`.
  - Un participant **sans email** est ignoré (feuille papier uniquement — lots 6–7).
- `Mailable` `app/Mail/QuestionnaireInvitationMail.php` — mirroir de `MessageLibreMail` (logo CID
  `cid:logo-asso`), corps = HTML assaini + variables résolues.

### 5.3 Relances
- Bouton **« Relancer les non-répondants »** : même compositeur, cible = `idsNonSoumis`.
- (Relances automatiques planifiées = toujours hors périmètre.)

---

## 6. Partie D — Commentaire optionnel sur satisfaction

### 6.1 Configuration (question)
- Sur une question de **type satisfaction**, l'éditeur de questions (`app/Livewire/Questionnaire/ModeleEditor.php`) affiche :
  - une case **« Ajouter un commentaire optionnel »** → `config.commentaire = true`,
  - un input **libellé du commentaire** → `config.commentaire_libelle` (défaut
    « Un commentaire ? (optionnel) »).
- `config` (JSON) est snapshoté dans la campagne comme le reste.

### 6.2 Répondant
- La page satisfaction affiche l'échelle 5 niveaux, puis — si `config.commentaire` — un `textarea`
  étiqueté `config.commentaire_libelle`. Champ nommé `q_{id}_commentaire`. **Toujours facultatif.**
- Persistance : `QuestionnaireReponseService::enregistrerReponse(submission, question, valeurBrute,
  ?string $commentaire = null)` — pour une satisfaction avec commentaire : `value_integer` = note,
  `value_text` = commentaire (ou null). Le controller (`store` action `next`) lit `q_{id}` ET
  `q_{id}_commentaire` et passe les deux.

### 6.3 Validation obligatoire (révisée — D5)
- `verifierObligatoires` change de critère : une question obligatoire est satisfaite si la
  **colonne primaire de son type** (`$question->type->valueColumn()`) est **non nulle** dans sa
  ligne de réponse. Conséquence : un commentaire seul (value_text) sur une satisfaction sans note
  (value_integer null) **ne** valide **pas** la question. Le commentaire ne bloque jamais.
- Régression à couvrir : les autres types gardent le même comportement (texte → value_text, etc.).

### 6.4 Résultats
- `QuestionnaireResultatService` : pour une satisfaction avec `config.commentaire`, ajoute
  `verbatims` = liste des `value_text` non nuls, à côté de `moyenne`/`distribution`. L'écran
  résultats affiche les commentaires sous la stat de la question.

### 6.5 Export Excel
- `QuestionnaireExcelExporter` : une satisfaction avec `config.commentaire` émet **deux colonnes**
  consécutives : `<libellé>` (value_integer) puis `<libellé> — commentaire` (value_text). En-têtes
  **stables** (le `config` est figé au snapshot, donc le nombre de colonnes ne varie pas d'un export
  à l'autre d'une même campagne).

---

## 7. Ordre de construction & jalons

| Phase | Contenu | Jalon |
|---|---|---|
| 0 | `QuestionnaireVariableResolver` (+ tests) | socle variables réutilisable |
| A | intro/remerciement TinyMCE + variables + assainissement + rendu résolu | messages personnalisés |
| D | commentaire optionnel satisfaction (config, rendu, persistance, oblig. révisée, résultats, export) | satisfaction commentée |
| B | mode aperçu (modèle + campagne), partial `champ` partagé | tester sans enregistrer |
| C | envoi + relances par la messagerie (compositeur, Mailable, EmailLog) | invitations envoyées |

A et D peuvent être faits dans n'importe quel ordre après 0 ; B dépend du partial `champ`
(donc après A et D pour que le partial porte déjà le commentaire) ; C est indépendant (dépend de 0).

---

## 8. Tests

**Unitaires / feature :**
- Resolver : `pour()` résout les variables d'un vrai invitation ; `exemple()` produit des valeurs
  fictives ; `remplacer()` échappe les valeurs (pas d'injection HTML).
- A : intro/remerciement HTML assaini (script retiré, `{var}` en href préservé) ; rendu répondant
  résout les variables.
- D : config commentaire snapshoté ; persistance note+commentaire sur une ligne ; **obligatoire
  satisfait par la note seule, pas par le commentaire seul** ; résultats listent les verbatims ;
  export à 2 colonnes stables.
- B : route aperçu modèle/campagne `assertOk` ; **aucune** `questionnaire_submissions` créée après
  un parcours d'aperçu ; bandeau présent ; variables en valeurs d'exemple.
- C : `Mail::fake()` — `envoyer` envoie aux ciblés + crée `EmailLog` + pose `sent_at` ; `{lien_questionnaire}`
  présent dans le corps rendu ; relance ne vise que les non soumis ; participant sans email ignoré.

**Recette manuelle :** éditer intro/remerciement riches avec variables → prévisualiser depuis le
modèle puis la campagne (vérifier qu'aucune réponse n'apparaît dans les résultats) → activer un
commentaire sur une satisfaction, y répondre, vérifier verbatim + export 2 colonnes → envoyer les
invitations (email reçu avec le bon lien) → relancer.

## 9. Risques

- **XSS** : intro/remerciement/corps sont du HTML utilisateur → assainir + échapper les valeurs de
  variables. Ne jamais `{!! !!}` du contenu non assaini.
- **En-têtes d'export** : le commentaire ajoute une colonne ; rester stable grâce au snapshot du
  `config` (jamais recalculé d'après les réponses).
- **Aperçu** : garantir l'absence totale d'écriture en base (pas de `submission`/`answer`), même si
  le partial `champ` est partagé avec le parcours réel.
- **Multi-tenant** : routes aperçu/envoi authentifiées → tenant déjà booté ; `EmailLog` reste
  tenant-scopé.
- **TinyMCE + Livewire/modale** : l'init/teardown de TinyMCE dans une modale Livewire est piégeux
  (ré-init après re-render, sync `wire:model`). **Mirrorer le pattern TinyMCE+Livewire déjà
  fonctionnel** dans le module communication (`OperationCommunication`/`CommunicationTiers`) plutôt
  que ré-inventer ; vérifier le `wire:ignore` autour du textarea TinyMCE et le hook de
  synchronisation au submit.
