# Questionnaires — Anonymisation des réponses — Spec

> Nouveau comportement piloté par le drapeau `anonymise` (aujourd'hui décoratif) : lorsqu'il est
> activé, les réponses ne sont **plus rattachées au participant par défaut**. L'identité d'une
> réponse ne subsiste que sur **consentement écrit** du répondant. Objectif : ne pas prêter le
> flanc à une critique éthique sur la confidentialité, sans renoncer au suivi « qui a répondu »
> sur le canal en ligne. Branche `feat/questionnaires`. Laravel 11 + Livewire 4 + DomPDF, Pest.
>
> **Règle cardinale : `anonymise = false` ne change RIEN au comportement actuel.** Tout ce qui
> suit ne s'applique que lorsque le drapeau est coché.

## 1. Objectif & motivation

État actuel (v4.4.1) : toute réponse — en ligne, papier (OCR) ou saisie admin — est stockée
**explicitement FK-liée** au participant (`submission.invitation_id` → `invitation.participant_id`).
Le drapeau `anonymise` n'agit que sur l'écran de consentement final ; la base, le PDF (en-tête
**et** pied de page nominatifs) et la liaison scan→participant restent identiques qu'il soit
coché ou non. L'anonymat promis au répondant est donc illusoire.

Cette spec définit un modèle où **le rattachement réponse↔participant est l'exception**, consentie,
et non la règle — tout en préservant le suivi opérationnel (relances, taux) sur les canaux où il
reste légitime.

## 2. Principe directeur

> **La traçabilité « qui a répondu » vient exclusivement du lien personnel (l'invitation email).
> Pas du support, pas du canal.**

Conséquences qui découlent de ce principe :

- **Email / réponse en ligne** → lien personnel pendant la session → on garde « qui a répondu »
  (`invitation.statut = Soumis`), on **oublie** « quoi » (FK submission détaché) si pas de
  consentement.
- **Papier / QR public du PDF** → aucun lien personnel → **aucune** trace « qui » par défaut,
  sauf consentement écrit + nom renseigné.

## 3. Décisions actées

| # | Sujet | Décision |
|---|-------|----------|
| A1 | Bifurcation | Drapeau `anonymise` : **décoché** = statu quo (traçabilité nominative actuelle, **inchangée, aucune modification du flux existant**). **Coché** = comportement décrit ci-après. Un seul drapeau pilote tout. |
| A2 | Bearer token par formulaire | Chaque formulaire imprimé porte un **QR bearer** unique, opaque, **non relié à un participant** en base. Sert de clé de déduplication / supersede / comptage des scans. Remplace le rôle d'`invitation` pour le canal papier anonyme. |
| A3 | Oubli du lien (online) | À la finalisation, **séquence** : (1) marquer `invitation.statut = Soumis` + `submitted_at` (traçabilité « qui a répondu ») ; (2) marquer `submission.statut = Soumise` + `submitted_at` ; (3) si `anonymise` **et** consentement non coché → nuler `submission.invitation_id` / `active_key`. Si coché → comportement actuel (lien conservé). La connaissance de l'identité est temporaire (en session), pas au repos. **Résultat** : l'invitation sait que Marie a répondu, mais aucune soumission ne pointe vers elle. |
| A4 | Figeage | Une soumission anonymisée (`invitation_id` IS NULL **et** `bearer_token_id` IS NULL ou bearer-only) est **figée, non rouvrable**. Pas de réouverture admin. L'admin corrige en supprimant et refaisant. |
| A5 | Rapprochement par nom (papier) | Champ « Prénom Nom (optionnel) » imprimé **après** la case consentement. L'OCR ne transcrit ce champ **que si la case est cochée**. Le rapprochement vers un participant se fait avec **validation humaine** (brique existante) sur homonymes / erreurs OCR. Lien persisté **seulement** si case cochée **et** nom matché. Sinon le nom transcrit est **jeté**. |
| A6 | Taux de réponse | Formule inchangée : `taux = soumissions / participants de l'opération`. Le dénominateur est le nombre total de participants de l'opération. En papier pur (0 invitation) ce dénominateur reste valide — le taux représente « combien de participants inscrits se sont exprimés ». C'est un **plancher** (les présents en salle sont un sous-ensemble des inscrits) ; c'est un choix assumé. |
| A7 | Non-hybride | Règle d'usage : une même campagne peut mixer invitations email + formulaires papier **tant qu'aucun participant n'est sur les deux canaux** (ex. un absent par email, les présents en salle par papier). Ce cas est géré **sans code spécial** : la relance opère sur les invitations uniquement → vide en papier pur, contient l'absent sinon. Le mutex email/papier est **rejeté** : il tuerait la flexibilité légitime sans fermer le vrai trou (une même personne sur deux canaux, indétectable par construction). |
| A8 | Variables de texte | Pas de double rédaction. Le resolver expose un **mode anonyme** : les variables nominatives basculent vers une forme neutre, les variables neutres sont inchangées. L'auteur rédige une fois. |
| A9 | Limite assumée | Sur micro-cohorte (< 10), la ré-identification ciblée reste possible par élimination, timing ou écriture. Cette spec élimine le **lien systématique** au repos, pas la ré-identification absolue. Le libellé IHM reflète ce niveau (« Réponses non rattachées par défaut »), sans promettre un anonymat cryptographique. |
| A10 | Consentement unique | Une seule case couvre contact **et** rattachement nominal. Libellé : « J'accepte que mes réponses soient rattachées à mon nom pour que l'association puisse me recontacter. » Le booléen `accepte_contact` porte les deux intentions. |
| A11 | Verrouillage du drapeau | `anonymise` est défini sur le **modèle** (template) et copié sur la campagne à la création (`QuestionnaireCampaignService::creer()`). Le toggle reste modifiable sur le template. Sur la campagne, la valeur est **figée dès la première soumission** (garde dans `QuestionnaireCampaignService` ou au niveau IHM). |

## 4. Schéma de données

- **Nouvelle table `questionnaire_bearer_tokens`** : `id`, `campaign_id` (FK),
  `token` (opaque `Str::random(48)`), `token_hash` (sha256, index unique), `timestamps`.
  Jamais de FK vers participant.
- **`questionnaire_submissions`** : `invitation_id` / `active_key` → **nullable** (aujourd'hui
  requis). Nouvelle FK `bearer_token_id` (nullable) pour les réponses issues du papier / QR public.
  **Invariant** : exactement un des deux est non-null (`invitation_id` XOR `bearer_token_id`),
  sauf soumission anonymisée online (les deux null après oubli — `bearer_token_id` reste null,
  `invitation_id` nullé).
- **`questionnaire_ocr_drafts`** : `invitation_id` → **nullable**. Nouveau `bearer_token_id`
  (nullable). Un draft est rattaché à l'un ou l'autre selon le canal.
- **`questionnaire_campaigns`** : le booléen `anonymise` existe déjà ; on le fait **agir**.
  Pas de renommage (le contexte `QuestionnaireCampaign` suffit à lever l'ambiguïté).
- **Invariant de déduplication** : « ≤ 1 soumission active *par invitation* **ou** par bearer
  token ». Couvre le cas « même formulaire scanné puis rempli en ligne via son QR » (même bearer).

## 5. Impact sur les services existants — signatures à adapter

Le code actuel est **invitation-centrique** : 5 méthodes de service prennent
`QuestionnaireInvitation` en paramètre obligatoire. Le flux bearer anonyme n'a pas d'invitation.
Chaque méthode nécessite une variante ou un paramètre alternatif.

| Méthode | Fichier | Problème | Solution |
|---------|---------|----------|----------|
| `demarrerOuReprendre($invitation)` | `QuestionnaireReponseService:20` | Pas de point d'entrée bearer pour un répondant qui scanne le QR papier et répond en ligne. | Nouvelle méthode `demarrerOuReprendreBearer(QuestionnaireBearerToken $bearer): QuestionnaireSubmission`. Même logique (invariant ≤1 active), clé = `bearer_token_id` au lieu d'`invitation_id`. |
| `finaliser($submission, $accepteContact)` | `QuestionnaireReponseService:94` | `marquerSoumise()` appelle `$submission->invitation->update(...)` inconditionnellement (ligne 118) → NPE si `invitation_id` est null. | Conditionner : si `$submission->invitation_id !== null`, mettre à jour l'invitation. Sinon (bearer), ne rien faire sur l'invitation. L'oubli du FK (§A3 étape 3) s'exécute **après** ce bloc. |
| `creerDepuisOcr($invitation, ...)` | `QuestionnaireReponseService:190` | Pas de point d'entrée pour un scan bearer (papier anonyme). | Nouvelle méthode `creerDepuisOcrAnonyme(QuestionnaireBearerToken $bearer, array $valeurs, ..., ?string $nomTranscrit): QuestionnaireSubmission`. Si case cochée + nom matché → rattacher au participant via validation humaine. Sinon → soumission orpheline (bearer-only). |
| `pour($invitation)` | `QuestionnaireVariableResolver:28` | Le resolver exige une invitation pour extraire le participant/tiers. | Nouvelle méthode `pourAnonyme(QuestionnaireCampaign $campagne): array` qui retourne les variables neutres (§6) sans dépendance à un participant. |
| `construireDonnees($campagne, $participantIds)` | `QuestionnaireImpressionService:31` | Génère une page par invitation. En mode anonyme, pas d'invitation → pas de page. | Nouvelle méthode `construireDonneesAnonymes(QuestionnaireCampaign $campagne, int $nombreFormulaires): array`. Crée N bearer tokens, génère N pages avec chacune un QR bearer unique + variables neutres. Pas de nom, pas de `code_court`, pas de pied de page nominatif. |

## 6. Génération PDF (mode anonyme)

- **Nouveau QR bearer** dans `QuestionnaireQrCode` : encode l'URL de réponse bearer
  (`/q-anon/{token}` — route dédiée, voir §10).
  Un par formulaire imprimé.
- **`QuestionnaireImpressionService::construireDonneesAnonymes()`** : si `anonymise`, ne plus
  imprimer le nom, l'en-tête participant, le pied de page nominatif, ni le `code_court`.
  Remplacer par le QR bearer + une mention neutre.
- **Champ « Prénom Nom (optionnel) »** en blade `questionnaire-papier.blade.php`, **après** la
  case « J'accepte que mes réponses soient rattachées à mon nom pour que l'association puisse
  me recontacter. »
- **Resolver** : brancher `pourAnonyme()` pour l'intro / remerciement du PDF.

## 7. Variables de texte (`QuestionnaireVariableResolver`)

Une seule méthode de substitution (`remplacer()`), qui fait un `strtr($html, $vars)`. Le texte
TinyMCE n'est pas attaché à un contexte : c'est le tableau `$vars` passé qui décide.

**Classification** (depuis `pour()`, lignes 42-68) :

- **Nominatives** (dépendent de l'identité) : `{prenom}`, `{nom}`, `{email_participant}`,
  `{civilite}`, `{politesse}`, `{civilite_nom}`, `{politesse_nom}`, `{civilite_prenom_nom}`,
  `{politesse_prenom_nom}`, `{salutation}`, `{lien_questionnaire}`, `{bloc_liens}`.
- **Neutres** (opération / association) : `{operation}`, `{type_operation}`, `{association}`,
  `{date_debut}`, `{date_fin}`, `{nb_seances}`, `{table_seances}`, `{table_seances_a_venir}`,
  `{logo}`.

**Mode anonyme** (`pourAnonyme()`) : mêmes clés neutres ; les clés nominatives sont **mappées**
vers une forme neutre (pas omises — un littéral `{prenom}` oublié serait imprimé tel quel) :

- `{salutation}`, `{politesse_nom}`, `{civilite_prenom_nom}`… → `"Madame, Monsieur"`.
- `{prenom}`, `{nom}`, `{email_participant}`, `{civilite}` → `''`.
- `{lien_questionnaire}` / `{bloc_liens}` → non pertinents sur PDF anonyme (QR bearer remplace).

Optionnel : passe de contrôle avertissant l'auteur si un nom semble écrit en dur dans le texte
TinyMCE (non bloquant, au moment de cocher `anonymise`).

## 8. OCR & scan

- **`QuestionnaireQrDecoder`** : reconnaître le token bearer (à côté du token invitation
  existant) et renvoyer le type de clé (`'invitation'` | `'bearer'`).
- **`QuestionnaireScanService`** : router selon le type — bearer → résolution par
  `bearer_token_hash`, sans participant. Créer le `QuestionnairePaperScan` avec
  `bearer_token_id` au lieu d'`invitation_id`. Créer le `QuestionnaireOcrDraft` avec
  `bearer_token_id` (nullable `invitation_id`).
- **`QuestionnaireOcrService`** : étendre le prompt pour transcrire le champ « Prénom Nom »
  **uniquement si la case consentement est cochée**. Sinon, ne jamais relire cette zone.
- **`QuestionnaireReponseService::creerDepuisOcrAnonyme()`** : rapprochement par nom →
  participant, avec **validation humaine** sur homonymes / erreurs OCR. Lien persisté
  **seulement** si case cochée + nom matché.

## 9. Soumission en ligne & oubli du lien

- **Flux invitation (existant)** : `finaliser()` → si `anonymise` **et** consentement non
  coché → après `marquerSoumise()`, nuler `submission.invitation_id` / `active_key` (oubli).
  Si coché → comportement actuel. **L'invitation garde** `statut = Soumis` dans tous les cas
  (traçabilité « qui a répondu », pas « quoi »).
- **Flux bearer (nouveau)** : répondant scanne le QR papier → `demarrerOuReprendreBearer()`.
  À la finalisation, pas d'invitation à mettre à jour. La soumission reste liée au bearer.
  Si consentement coché + nom matché → FK vers participant ajouté (même logique que §8).
- **Réouverture admin** : une soumission anonymisée (`invitation_id` IS NULL) est **figée,
  non rouvrable**. L'IHM masque / désactive l'action `rouvrir()`. Garde dans
  `QuestionnaireReponseService::rouvrir()` : `abort_if($submission->invitation_id === null)`.
- **Dédoublonnage online/papier** : unifier l'invariant « ≤ 1 active » sur la clé pertinente
  (`invitation_id` ou `bearer_token_id`). Couvre le scan puis la saisie web du même formulaire.

## 10. IHM & résultats

- **Écran consentement** : rewording de la case → « J'accepte que mes réponses soient rattachées
  à mon nom pour que l'association puisse me recontacter. »
- Remplacer le bouton **« Saisir »** (par participant) par **« Ajouter une réponse »** (niveau
  campagne) quand `anonymise`.
- Liste de relance : basée sur les invitations uniquement → vide par construction en papier pur.
  Note explicative (« retours papier non traçables individuellement »).
- **`QuestionnaireResultatService`** : redéfinir `taux = soumissions / participants de
  l'opération`. Va chercher le dénominateur sur l'opération, pas sur les invitations. Taux
  plancher assumé (voir A6).
- **Export Excel** (`QuestionnaireExcelExporter`) : les soumissions anonymisées (`invitation_id`
  NULL) apparaissent avec une colonne identité vide. Le code existant (null-safe chain) le gère
  déjà — le rendre **explicite** avec un commentaire de test dédié.

## 11. Route de réponse bearer

Route dédiée : **`/q-anon/{token}`** (recommandée).

Justification : séparation claire des deux espaces de tokens. Un token bearer ne peut jamais
accidentellement résoudre une invitation (ni l'inverse). Simplifie le middleware (pas de
résolution polymorphe). Le contrôleur `QuestionnaireRepondantController` reçoit une nouvelle
action `showBearer($token)` / `storeBearer($token)` qui résout le bearer et délègue au
service via `demarrerOuReprendreBearer()`.

## 12. Tests

- **Statu quo (anonymise = false)** : les tests existants passent **sans modification**. Ajouter
  un test de non-régression explicite : finalisation sans anonymise → FK conservé, invitation
  mise à jour, rouvrir() fonctionne.
- **Oubli du FK (online)** : finalisation sans consentement → `invitation_id` NULL, `invitation`
  garde `statut = Soumis` ; avec consentement → FK conservé.
- **Bearer token** : génération, déduplication (deux scans du même bearer → 1 active), supersede
  papier anonyme.
- **Figeage** : soumission anonymisée → `rouvrir()` refusé / désactivé.
- **Rapprochement par nom** : case cochée + nom matché → lien persisté ; case non cochée → nom
  jeté, réponse anonyme ; homonyme → validation humaine.
- **Variables** : `pourAnonyme()` retourne des valeurs neutres pour les clés nominatives ;
  résolution neutre dans le rendu ; littéraux non résolus absents du rendu.
- **Taux** : calcul sur participants de l'opération ; papier pur (0 invitation) → taux = N
  soumissions / N participants (pas de division par zéro, pas de fallback à 0).
- **Non-hybride** : une invitation email + des bearers sur la même campagne → relance limitée à
  l'invitation, pas de double-réponse entre les deux canaux (clés distinctes).
- **Verrouillage** : tenter de modifier `anonymise` sur une campagne avec soumissions → refusé.
- **Export Excel** : soumission anonymisée → colonne identité vide, réponses présentes.
- **NPE marquerSoumise()** : finaliser une soumission bearer (pas d'invitation) → pas de crash.
- **OCR draft bearer** : scan bearer → `QuestionnaireOcrDraft` créé avec `bearer_token_id`,
  `invitation_id` null.

## 13. Hors périmètre

- Anonymat cryptographique / résistance à la ré-identification sur micro-cohorte (impossible par
  construction — voir A9).
- Détection bloquante d'un nom écrit en dur dans le texte TinyMCE (avertissement optionnel
  uniquement).
- Mutex email/papier au niveau campagne (rejeté — voir A7).
- Réimpression du même lot de bearers (un bearer n'existe que dans le QR imprimé ; réimprimer
  génère de nouveaux bearers).
