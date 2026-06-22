# Questionnaires de satisfaction et sondages — Spec relue

> Spec finalisée, issue du brainstorming sur le design doc
> [2026-06-22-questionnaires-satisfaction-design.md](2026-06-22-questionnaires-satisfaction-design.md).
> Elle **tranche** toutes les questions ouvertes du §11 du design doc et fige les
> décisions de modèle de données. Elle est la référence pour le plan d'implémentation.

**Date :** 2026-06-22
**Branche cible :** `main` (le travail se fera sur une branche dédiée `feat/questionnaires`)
**Statut :** en attente de relecture utilisateur (avant écriture du plan)
**Périmètre planifié :** les 8 lots (plan phasé, un jalon par lot)

---

## 1. Décisions actées (journal)

Toutes les décisions ci-dessous ont été validées en brainstorming le 2026-06-22.

| # | Sujet | Décision |
|---|---|---|
| D1 | Périmètre du plan | Les **8 lots** sont planifiés. Le plan est un seul document phasé, un jalon livrable par lot. |
| D2 | Intégrité modèle ↔ campagne (§11) | **Snapshot** : à la création d'une campagne, on copie titre/intro/remerciement + questions + options dans des tables campagne-scopées. Éditer un modèle n'affecte jamais une campagne existante. FK `template_id` conservé pour traçabilité. Les réponses pointent les **questions figées de la campagne**. |
| D3 | Réponses partielles (§11) | **Persistance page par page** : la soumission a un statut `en_cours → soumise`. Reprise possible via le même lien. |
| D4 | Réouverture après soumission (§11) | Réponse **définitive** côté répondant. Réouverture admin d'une invitation : invitation `soumis` → `commence` ; soumission active `soumise` → `en_cours` ; `submitted_at` remis à `null` **des deux côtés** ; réponses conservées. (Les deux statuts vivent sur deux tables distinctes — `questionnaire_invitations` et `questionnaire_submissions`, voir §3.3.) |
| D5 | Ciblage des participants (§11) | **Sélection** des participants (défaut = tous), réutilise l'UX de `OperationCommunication`. |
| D6 | Rendu choix unique (§11) | Paramètre `radio` / `select` stocké sur la question, **défaut `auto`** (radio si peu d'options, select sinon). |
| D7 | Type « Ressenti » | **Conservé tel quel** : curseur aveugle 0-100, aucune valeur affichée au répondant, entier 0-100 stocké. |
| D8 | Stockage des valeurs de réponse (§5.3) | **Colonnes typées** : `value_text` / `value_integer` / `value_boolean` / `value_option` + `value_meta` (JSON) pour figer le libellé d'option choisi. Pas de `value_json` fourre-tout. |
| D9 | Consentement au contact | **Booléen seul**. L'identité vient de l'invitation → participant (déjà connue). Pas de ressaisie de contact en V1. |
| D10 | Anonymat petits groupes (§3.3 / §10) | **Toujours afficher** les résultats + **avertissement clair** « petit groupe → certains retours peuvent être reconnaissables ». Pas de seuil masquant. |
| D11 | Navigation | Modèles = écran dédié (catalogue réutilisable). Campagnes = créées/suivies depuis la **fiche Opération** (onglet/section, comme `OperationCommunication`). Slot sidebar exact tranché au plan. |
| D12 | PDF papier (§11) | **PDF groupé, une invitation par page** (saut de page). Sélectionner un seul participant → PDF d'une page. Un seul chemin de code. |
| D13 | Scan vs réponse déjà soumise (§11) | L'assistant **bloque** et propose **Ignorer / Remplacer**. Remplacer = nouvelle soumission active, ancienne marquée `remplacee` (conservée, masquée). Pas de versioning exposé, aucune donnée perdue. **Invariant** : au plus une soumission active (statut ∈ {`en_cours`,`soumise`}) par invitation — voir §3.3. |
| D14 | Réception scans par email (§11) | **Réutiliser l'intake per-association existante** (`IncomingMailParametres` / Boîte de réception). Détection QR route la pièce vers le pipeline questionnaire. Identification par QR / identifiant court, jamais par l'adresse. |
| D15 | Rétention des scans (§11) | **Conserver** les scans liés à la soumission (storage tenant), suppression manuelle possible, **pas de purge auto** en V1. |
| D16 | Fournisseur IA/OCR (§11) | **API Anthropic via la clé `anthropic_api_key` de l'association** (partagée). Sélecteur de modèle **dédié** : nouvelle colonne `questionnaire_ocr_model` sur `association` (réutilise le mécanisme `InvoiceOcrService::fetchAvailableModels`), **découplée** de `invoice_ocr_model` pour ne pas coupler questionnaires et factures. Garanties de confidentialité = celles déjà en place pour l'OCR factures. |
| D17 | Parcours répondant (technique) | **Controller + Blade, pagination serveur** (une page = une question, « Suivant » POST → persiste → page suivante). Pas de Livewire sur la route publique (évite la complexité de re-boot tenant à chaque hydratation). Livewire réservé aux écrans admin. |
| D18 | Résolution tenant + token public | Token **clair haute entropie** (`Str::random(48)`) présent **uniquement** dans l'URL/QR, **jamais stocké**. En base on stocke `token_hash = hash('sha256', $clair)` (colonne unique). Résolution : `hash('sha256', $token)` → `QuestionnaireInvitation::withoutGlobalScope(TenantScope::class)->where('token_hash', …)->firstOrFail()` → `TenantContext::boot($invitation->association)`. Mirroir **exact** de `SubscriptionService::findByToken` (sha256, `Str::random(48)`). Route throttlée. Une fuite DB n'expose donc aucun lien de réponse utilisable. |

---

## 2. Réutilisation de l'existant (carte d'infra)

Le moteur s'appuie sur des briques déjà en production. Le plan **réutilise**, ne réinvente pas.

| Besoin | Brique existante à réutiliser |
|---|---|
| Lien public tokenisé sans compte | Pattern `FormulaireToken` + routes publiques `/formulaire` (throttle), résolution tenant `withoutGlobalScope` + `TenantContext::boot` (cf. `Newsletter\SubscriptionService:107`) |
| QR code (génération) | `endroid/qr-code` |
| QR code (lecture sur scan) | `khanamiryan/qrcode-detector-decoder` |
| PDF | `barryvdh/laravel-dompdf` + `App\Support\PdfFooterRenderer` + `pdf.partials.footer-logos` |
| Export XLSX | `openspout/openspout` (déjà utilisé pour les exports) |
| OCR / IA | `InvoiceOcrService` (clé Anthropic per-asso, sélecteur modèle, stub démo) — à généraliser/dupliquer pour les questionnaires |
| Réception email + pièces jointes | `webklex/laravel-imap` + `IncomingDocuments` + `IncomingMailParametres` + Boîte de réception |
| Envoi email + sélection participants + logs | `OperationCommunication` + `EmailTemplate` (placeholders) + `EmailLog` + Mailable existant |
| Isolation tenant | `TenantModel` (scope global fail-closed), `TenantContext::currentId()`, `App\Support\TenantUrl` pour les URLs dans emails/PDF |
| Stockage fichiers tenant | `storage/app/associations/{id}/…` via trait `TenantStorage` |

---

## 3. Modèle de données

Tous les modèles étendent `TenantModel` (colonne `association_id`, scope global fail-closed).
Les modèles financiers ne sont pas concernés ; pas de `SoftDeletes` requis sauf mention.

### 3.1 Catalogue (modèles réutilisables)

**`questionnaire_templates`**
- `association_id`, `titre_interne`, `titre_affiche`, `intro` (text, nullable),
  `remerciement` (text, nullable), `actif` (bool, défaut true), timestamps.

**`questionnaire_template_questions`**
- `template_id`, `libelle`, `aide` (nullable), `type` (enum `TypeQuestion`),
  `ordre` (int), `obligatoire` (bool), `config` (json nullable), timestamps.
- `config` porte, selon le type : `rendu` (`auto`/`radio`/`select`) et `options`
  (`[{libelle, valeur, ordre}]`). Les options en JSON sont explicitement autorisées
  par le §3.2 du design (le cœur reste relationnel ; seules les options sont en JSON).

### 3.2 Campagne (instance figée — snapshot, D2)

**`questionnaire_campaigns`**
- `association_id`, `operation_id`, `template_id` (FK provenance, nullable on delete),
  `titre_affiche` (snapshot), `intro` (snapshot), `remerciement` (snapshot),
  `statut` (enum `StatutCampagne` : `brouillon`/`ouverte`/`cloturee`/`archivee`),
  `ouverte_at`, `cloturee_at`, timestamps.

**`questionnaire_campaign_questions`** (questions **gelées** — les réponses pointent ici)
- `campaign_id`, `libelle`, `aide`, `type`, `ordre`, `obligatoire`, `config` (json snapshot
  incl. options + rendu), timestamps.

### 3.3 Invitations & réponses

**`questionnaire_invitations`**
- `association_id`, `campaign_id`, `participant_id`,
  `token_hash` (string 64, **unique** — `hash('sha256', $token_clair)` ; le token clair
  `Str::random(48)` n'est **jamais stocké**, il ne vit que dans l'URL/QR — D18),
  `code_court` (court, lisible, **secours back-office uniquement** pour rattacher un scan
  quand le QR est abîmé — alphabet sans ambiguïté façon `FormulaireTokenService` ; n'ouvre
  **pas** d'accès public, donc stockage clair acceptable), `statut`
  (enum `non_ouvert`/`commence`/`soumis`), `sent_at`, `opened_at`, `submitted_at`, timestamps.
- Le `statut` est maintenu, les timestamps servent aux relances (lot 8).

**`questionnaire_submissions`**
- `association_id`, `campaign_id`, `invitation_id`,
  `statut` (enum `en_cours`/`soumise`/`remplacee`),
  `accepte_contact` (bool, défaut false),
  `source` (enum `en_ligne`/`papier`),
  `remplacee_par_id` (nullable, supersede pour le cas scan-remplace, D13),
  `submitted_at`, timestamps.
- **Invariant : au plus une soumission active (statut ∈ {`en_cours`,`soumise`}) par
  invitation.** Garanti dans le service (get-or-create de la soumission `en_cours` ;
  un remplacement marque l'ancienne `remplacee` dans la **même transaction**). Toutes les
  requêtes résultats/export filtrent `statut = 'soumise'` (exclut donc brouillons `en_cours`
  ET `remplacee`). **Défense DB** (introduite avec `remplacee` au lot 7) : colonne nullable
  `active_key` = `invitation_id` tant que la soumission est active, `NULL` dès qu'elle passe
  `remplacee`, avec `unique(active_key)` — MySQL traite les `NULL` comme distincts, ce qui
  matérialise « une seule active par invitation » sans index partiel (non supporté par MySQL).
- L'identité du participant est techniquement joignable via `invitation_id` mais
  **masquée par défaut** dans les vues/exports (politique d'affichage, pas absence de lien).

**`questionnaire_answers`**
- `association_id`, `submission_id`, `campaign_question_id`,
  `value_text` (nullable), `value_integer` (nullable), `value_boolean` (nullable),
  `value_option` (nullable, = `valeur` technique stable de l'option choisie),
  `value_meta` (json nullable, fige le libellé de l'option au moment de la réponse),
  timestamps.
- Une ligne par question répondue. Upsert au fil des pages (D3).

### 3.4 Papier / scan / OCR (lots 6-7)

**`questionnaire_paper_batches`** — lot d'impression ou de scan.
- `association_id`, `campaign_id`, `type` (`impression`/`scan`), `cree_par` (user id),
  timestamps.

**`questionnaire_paper_scans`** — une feuille scannée.
- `association_id`, `campaign_id` (nullable jusqu'au rattachement),
  `invitation_id` (nullable jusqu'au rattachement), `batch_id` (nullable),
  `incoming_document_id` (nullable, lien intake email), `source` (`upload`/`email`),
  `chemin_fichier` (storage tenant), `qr_statut` (`detecte`/`illisible`),
  `statut` (`en_attente`/`rattache`/`traite`/`ignore`), timestamps.

**`questionnaire_ocr_drafts`** — proposition IA à valider.
- `association_id`, `scan_id`, `invitation_id`,
  `payload` (json : valeur proposée + confiance par question),
  `statut` (`brouillon`/`valide`/`rejete`), timestamps.
- La validation crée une `submission` (`source=papier`) + ses `answers`, marque le scan `traite`.

### 3.5 Types de question (`TypeQuestion`)

| Enum | Saisie répondant | Colonne valeur | Config |
|---|---|---|---|
| `texte_court` | input mono-ligne | `value_text` | — |
| `texte_long` | textarea | `value_text` | — |
| `satisfaction` | 5 niveaux (très insatisfait → très satisfait) | `value_integer` (1-5) | — |
| `ressenti` | curseur aveugle 0-100 (sans chiffre) | `value_integer` (0-100) | — |
| `case_a_cocher` | oui/non | `value_boolean` | — |
| `choix_unique` | radio ou select | `value_option` (+ `value_meta`) | `rendu`, `options[]` |

---

## 4. Relations

```
Association
  └─ QuestionnaireTemplate
       └─ QuestionnaireTemplateQuestion

Operation
  └─ QuestionnaireCampaign  (template_id = provenance, snapshot des entêtes)
       ├─ QuestionnaireCampaignQuestion        (questions gelées)
       ├─ QuestionnaireInvitation → Participant
       │    └─ QuestionnairePaperScan → QuestionnaireOcrDraft
       └─ QuestionnaireSubmission
            └─ QuestionnaireAnswer → QuestionnaireCampaignQuestion
```

---

## 5. Cycle de vie de la campagne

`brouillon` → `ouverte` → `cloturee` → `archivee`.

- **brouillon** : campagne créée (snapshot pris), participants sélectionnables, pas d'envoi.
- **ouverte** : invitations générées, liens actifs, réponses acceptées. Transition = action
  « Ouvrir / Envoyer les invitations ».
- **cloturee** : réponses bloquées côté répondant, résultats conservés et consultables.
- **archivee** : masquée des vues courantes.

Édition après lancement (risque §10) : résolue par le **snapshot** (D2). Le modèle source
reste librement modifiable ; la campagne vit sur sa copie figée.

---

## 6. Parcours répondant (en ligne)

Route publique throttlée `GET/POST /q/{token}` (controller + Blade, D17).

1. Résolution tenant (D18) : `hash('sha256', $token)` → `where('token_hash', …)`
   `withoutGlobalScope(TenantScope::class)->firstOrFail()` → `TenantContext::boot`.
2. Garde d'état : campagne `ouverte` ? invitation non déjà `soumis` (sinon page
   « déjà répondu ») ? campagne non clôturée ?
3. Page d'introduction (`titre_affiche` + `intro`).
4. **Une question par page** ; « Suivant » POST → valide (obligatoire bloque, D3 persiste
   la réponse en `en_cours`) → page suivante. Facultatives sautables.
5. Bloc final de consentement au contact (case booléenne, D9).
6. Soumission finale → `statut = soumise`, `submitted_at`, invitation `soumis`.
7. Page de remerciement (`remerciement`).

Le curseur « ressenti » est affiché sans valeur ; l'entier 0-100 est stocké (D7).

---

## 7. Anonymat & consentement

- Réponses **non nominatives par défaut** dans les vues et exports (D10). L'app parle de
  questionnaire **confidentiel / non nominatif**, jamais d'anonymat absolu.
- Avertissement « petit groupe » affiché systématiquement sur l'écran de résultats (D10).
- `accepte_contact = true` (D9) est la **seule** condition qui expose l'identité du
  participant (via `invitation → participant`) sur cette réponse, dans l'écran de résultats
  et l'export.
- Le QR papier identifie techniquement l'invitation : l'anonymat reste une **politique
  d'affichage**, documentée comme telle.

---

## 8. Résultats (depuis la campagne)

- Compteurs : nb invitations, nb réponses soumises, taux de réponse.
- Stats par type : satisfaction (répartition 1-5 + moyenne), ressenti (moyenne/distribution
  0-100), case à cocher (% oui), choix unique (répartition par option). Verbatims listés pour
  textes court/long.
- Identité affichée uniquement si `accepte_contact` (D10/D9).
- Avertissement petit groupe (D10).

---

## 9. Export Excel (lot 5)

`.xlsx` via `openspout`, depuis l'écran résultats.

- Une ligne par soumission `statut = 'soumise'` (exclut `en_cours` et `remplacee`).
- **En-têtes 100 % stables, indépendants des réponses** : le jeu de colonnes ne varie
  jamais d'un export à l'autre.
- Colonnes contexte : association, type d'opération, opération, campagne, date de soumission.
- Colonnes anonymat : réponse confidentielle, a accepté le contact.
- Colonnes identité **toujours présentes dans l'en-tête** ; valeurs renseignées
  **uniquement** sur les lignes où `accepte_contact = true`, **vides** sinon (jamais de
  colonne qui apparaît/disparaît selon les consentements).
- Une colonne par **question gelée** (libellé figé de la campagne — D2 garantit la stabilité).
  - choix unique : libellé choisi (+ valeur technique en colonne secondaire optionnelle) ;
  - ressenti : entier 0-100 ; satisfaction : entier 1-5 (+ libellé si utile).

---

## 10. Impression papier + QR (lot 6)

PDF via dompdf, **groupé, une invitation par page** (D12). Un seul participant sélectionné →
PDF d'une page.

Chaque page : titre affiché, intro, questions dans l'ordre (mise en page papier libre, **pas**
la contrainte « une question par page » de l'écran), consignes papier, bloc consentement,
remerciement court, **QR code individuel**, **identifiant court lisible** (secours).

Le QR encode l'URL publique tokenisée (équivalente au lien email) — **aucune donnée
personnelle en clair**. Résolution participant côté serveur après vérification du token.

---

## 11. Scan, OCR & assistant de saisie (lot 7)

Deux canaux d'entrée :
- **Upload manuel** depuis le suivi de campagne (« Ajouter un scan »).
- **Réception email** via l'intake per-asso existante (D14) ; le QR route la pièce.

Flux : upload/réception → détection QR → rattachement campagne+invitation → OCR/IA (D16) →
brouillon de réponse (`questionnaire_ocr_drafts`) → assistant de saisie (image source +
valeur proposée + confiance + champ correction + alertes obligatoires) → validation humaine →
soumission définitive (`source=papier`).

- QR illisible : rattachement manuel via identifiant court ou recherche participant/campagne.
- Invitation déjà soumise : **bloque + Ignorer / Remplacer** (D13 — supersede non destructif).
- **Jamais** de sauvegarde auto sans validation humaine (hors périmètre §7 du design).
- Scans conservés (D15), storage tenant, mêmes règles d'accès que les documents sensibles.

---

## 12. Intégration communication (lot 8)

- Placeholders nouveaux dans les templates email : `{lien_questionnaire}` (URL via
  `TenantUrl`), `{operation}`, `{type_operation}`, `{prenom}`.
- Minimum acceptable : bouton « Envoyer les invitations » depuis la campagne → crée les
  invitations manquantes + envoie aux participants sélectionnés + crée les `EmailLog` habituels.
- Relances **manuelles** : renvoyer aux invitations non `soumis` (réutilise `sent_at`/`opened_at`/
  `submitted_at`). Relances automatiques planifiées = hors périmètre.

---

## 13. Sécurité & multi-tenant

- Tous les modèles étendent `TenantModel` (fail-closed). Toute query brute s'appuie sur
  `TenantContext::currentId()`.
- Route publique : token clair (`Str::random(48)`) **jamais stocké**, seul `token_hash`
  (sha256) est en base (unique) ; résolution `where('token_hash', …)` +
  `withoutGlobalScope(TenantScope::class)` puis `TenantContext::boot` (D18) ; throttle ;
  clôture de campagne = fin de validité. Une fuite DB n'expose aucun lien utilisable.
  `code_court` reste en clair mais n'est exploité qu'en back-office authentifié (rattachement
  de scan, lot 7) — il n'ouvre jamais l'accès public.
- URLs dans emails/PDF via `TenantUrl` (jamais `route()` direct).
- Stockage scans : `storage/app/associations/{id}/…` (trait `TenantStorage`).
- Cache keys (si stats mises en cache) : inclure `association_id`.
- Cast `(int)` des deux côtés sur les `===` PK/FK.

---

## 14. Hors périmètre (rappel design §7)

Questionnaires publics sans invitation ; anti-spam de formulaires ouverts ; logique
conditionnelle / branchements ; matrices / scoring avancé ; publication de résultats ;
déclenchements automatiques planifiés ; association auto modèle ↔ type d'opération ;
versioning collaboratif des modèles ; sauvegarde OCR sans validation ; OCR temps réel.

---

## 15. Lots de livraison (jalons du plan)

| Lot | Contenu | Livrable |
|---|---|---|
| 1 — Fondations | `templates` + `template_questions` (6 types), écran d'édition (modèles dédiés, D11) | un admin crée un modèle |
| 2 — Campagnes opération | snapshot campagne (D2), sélection participants (D5), génération invitations + token + code court, section sur fiche Opération | une opération lance un questionnaire |
| 3 — Parcours répondant | route publique tokenisée (D17/D18), intro, une question/page, persistance (D3), validation obligatoire, remerciement, définitif + réouverture admin (D4) | un participant répond |
| 4 — Résultats & anonymat | stats par type, verbatims, consentement (D9), masquage identité + avertissement (D10) | l'admin consulte sans identité par défaut |
| 5 — Export Excel | `.xlsx` structuré, en-têtes stables (D2) | analyse hors logiciel |
| 6 — Impression papier | PDF groupé 1/page + QR + identifiant court (D12) | distribution papier en atelier |
| 7 — Scan & saisie assistée | upload + email (D14), lecture QR, OCR/IA (D16), assistant + validation, supersede (D13), rétention (D15) | réponses papier saisies avec contrôle humain |
| 8 — Communication avancée | placeholders, envoi invitations + EmailLog, relances manuelles | intégration communication fine |

---

## 16. Tests & recette

**Unitaires** : création modèle + questions ordonnées ; snapshot campagne fige libellés/options ;
validation obligatoire par type ; stockage valeurs typées (D8) ; soumission non nominative par
défaut ; exposition identité ssi `accepte_contact` ; agrégation satisfaction/checkbox/choix
unique/ressenti ; export Excel à en-têtes stables ; supersede scan non destructif.
**Sécurité/intégrité ajoutés (P-review)** : le **token clair n'est jamais en base**, seul
`token_hash` (sha256) l'est, et la résolution se fait par hash (D18) ; **invariant ≤ 1 soumission
active par invitation** (un remplacement marque l'ancienne `remplacee` et les résultats ne voient
que `soumise`) ; **en-têtes d'export identiques** que le répondant ait consenti ou non (colonnes
identité présentes mais vides sans consentement) ; **réouverture admin** bascule bien
invitation `soumis→commence` ET soumission `soumise→en_cours` avec `submitted_at` remis à `null`
des deux côtés.

**Feature / parcours public** : édition modèle ; création campagne depuis opération ; génération
invitations ; parcours répondant complet (pagination + persistance) ; blocage question
obligatoire ; message de fin ; réouverture admin ; consultation résultats ; téléchargement
Excel ; PDF papier avec un QR par invitation ; upload scan + rattachement QR ; entrée email avec
scan en PJ ; validation d'un brouillon OCR ; résolution tenant correcte sur route publique
(isolation cross-tenant fail-closed).

**Recette manuelle** : modèle 6 types → campagne sur opération démo → invitations → réponse non
nominative → réponse avec consentement → vérifier que seule la 2e expose l'identité → export
Excel → PDF papier QR → scan feuille remplie → validation OCR avant sauvegarde.

---

## 17. Risques & points d'attention (rappel design §10)

Anonymat relatif (jamais promettre plus) ; verbatims identifiants ; ne pas reproduire
LimeSurvey ; figer les libellés (résolu par snapshot D2) ; tokens longs/non devinables/
clôturables ; QR = lien technique, anonymat = affichage ; OCR manuscrit faillible (brouillon
+ validation humaine) ; scans = données sensibles (stockage tenant) ; multi-tenant fail-closed
partout.
