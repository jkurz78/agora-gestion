# Questionnaires — Lot 6 : Impression papier + QR — Plan

> Exécution subagent-driven. Spec : `docs/specs/2026-06-25-questionnaires-impression-papier-qr-spec.md`.
> Branche `feat/questionnaires`. Laravel 11 + Livewire 4 + DomPDF + `endroid/qr-code` v6, Pest,
> SQLite `:memory:`. PHP 8.5 `Deprecated: PDO::MYSQL_ATTR_SSL_CA` ≠ échec.

**Goal :** PDF papier d'une campagne, une invitation/participant (multi-pages), QR =
`lienReponse()` + `code_court`, groupes rendus en blocs visuels.

**Architecture :** QR helper → blade DomPDF (+ partiel champ-papier) → service (genererPour +
résolveur d'écrans + QR + rendu) → écran Livewire `ImpressionPapier` (jumeau d'`EnvoiCompose`).
Ordre des tâches : T1 → T2 → T3 → T4 (dépendances linéaires).

---

## Task 1 — Helper QR code

**Files :**
- `app/Support/QuestionnaireQrCode.php`
- Test : `tests/Unit/QuestionnaireQrCodeTest.php`

**Steps :**
- `final class QuestionnaireQrCode` avec `public static function dataUri(string $url): string`
  utilisant **`endroid/qr-code` v6** (vérifier l'API exacte de la version installée — Builder/Writer
  PNG → data URI ; au besoin via context7/docs). Retour : `data:image/png;base64,...`.
- Test : `dataUri('https://exemple.test/q/abc')` renvoie une chaîne non vide commençant par
  `data:image/png;base64,`.
- Run `vendor/bin/pest tests/Unit/QuestionnaireQrCodeTest.php` ; `./vendor/bin/pint`.
- Commit : `feat(questionnaires): helper QR code data-URI (endroid v6)`

## Task 2 — Gabarit PDF papier + rendu par type

**Files :**
- `resources/views/pdf/questionnaire-papier.blade.php`
- `resources/views/pdf/partials/champ-papier.blade.php`
- Test : `tests/Feature/Questionnaire/ImpressionPapierBladeTest.php`

**Contrat de données (passé par le service, T3) :**
- `$campagne` (titre_affiche, intro, anonymise) ; `$nomAsso`, `$logoDataUri` (string|null) ;
- `$groupes` : `array<int, Collection>` des questions de la campagne découpées (résolveur) —
  identiques pour toutes les invitations ;
- `$pages` : `array<int, array{invitation: QuestionnaireInvitation, qr: string}>` (le QR data-URI
  est par invitation).

**Steps (TDD via rendu de vue) :**
- `questionnaire-papier.blade.php` : boucle `$pages` ; chaque invitation = conteneur avec
  `page-break-before: always` sauf la 1ʳᵉ ; **en-tête** (logo+nom asso, titre affiché, intro ;
  à droite : `<img src="{{ $page['qr'] }}">` + `code_court` + « Scannez pour répondre en ligne ») ;
  **corps** = boucle `$groupes` → bloc `.groupe-papier` (fond `#f5f7fb`, padding, `page-break-inside:avoid`)
  → boucle questions (`page-break-inside:avoid`) : `Information` → intertitre (`libelle` + `aide`,
  pas de zone réponse) ; sinon libellé (+ `*` si obligatoire) + `@include('pdf.partials.champ-papier')` ;
  **consentement** (« ☐ J'accepte d'être recontacté(e) ») si `$campagne->anonymise` ; **remerciement** court.
  CSS **DomPDF-safe** (tables/inline-block, largeurs explicites, pas de flexbox).
- `champ-papier.blade.php` : match sur `$question->type` (jumeau de `champ.blade.php`) :
  texte_court → 1 ligne (filet) ; texte_long → bloc 4–5 lignes ; satisfaction → 5 niveaux
  libellés + case ☐ (pas de SVG) ; ressenti → échelle horizontale à marquer + labels gauche/droite
  (défauts si absents) ; case_a_cocher → 1 case ☐ + libellé ; choix_unique → chaque option avec ☐.
- Test : `view('pdf.questionnaire-papier', [...])->render()` avec données fabriquées →
  asserter : nom participant ; `src="data:image/png;base64` ; `code_court` ; chaque libellé ;
  `Information` rendu en intertitre **sans** zone de réponse ; 2 questions groupées dans le même
  bloc `.groupe-papier` (et 2 non groupées dans des blocs distincts) ; `page-break-before` présent
  pour une 2ᵉ invitation ; consentement présent si `anonymise=true`, absent sinon.
- Run `vendor/bin/pest tests/Feature/Questionnaire/ImpressionPapierBladeTest.php` ; `./vendor/bin/pint`.
- Commit : `feat(questionnaires): gabarit PDF papier + rendu manuscrit par type`

## Task 3 — Service d'impression

**Files :**
- `app/Services/Questionnaire/QuestionnaireImpressionService.php`
- Test : `tests/Feature/Questionnaire/QuestionnaireImpressionServiceTest.php`

**Steps :**
- Injecter `QuestionnaireInvitationService` + `QuestionnaireEcranResolver`.
- `construireDonnees(QuestionnaireCampaign $campagne, array $participantIds): array` (testable) :
  `genererPour($campagne, $participantIds)` ; charger les invitations sélectionnées
  (`with('participant.tiers')`, triées par nom) ; découper les questions de la campagne en
  `$groupes` ; construire `$pages` = `['invitation'=>..., 'qr'=>QuestionnaireQrCode::dataUri($inv->lienReponse())]` ;
  `$nomAsso`/`$logoDataUri` via `CurrentAssociation`/`brandingLogoDataUri`. Retourne le tableau
  attendu par le blade (T2).
- `telecharger(QuestionnaireCampaign $campagne, array $participantIds): \Illuminate\Http\Response` :
  `Pdf::loadView('pdf.questionnaire-papier', $this->construireDonnees(...))->setPaper('a4')->download("questionnaire-{$campagne->id}.pdf")`.
- Tests : participants sans invitation → en obtiennent une (token + `code_court`) après appel ;
  idempotent (2ᵉ appel ne duplique pas) ; `construireDonnees` renvoie un `qr` data-URI par
  invitation et les `$groupes` attendus ; `telecharger` renvoie une `Response` non vide
  (`assertInstanceOf(Response::class, ...)` + contenu non vide).
- Run `vendor/bin/pest tests/Feature/Questionnaire/QuestionnaireImpressionServiceTest.php` ; `./vendor/bin/pint`.
- Commit : `feat(questionnaires): QuestionnaireImpressionService (invitations + QR + PDF)`

## Task 4 — Écran Livewire + entrée + téléchargement

**Files :**
- `app/Livewire/Questionnaire/ImpressionPapier.php`
- `resources/views/livewire/questionnaire/impression-papier.blade.php`
- Câblage d'entrée (route/écran, miroir d'`EnvoiCompose`) + lien « Imprimer (papier) »
- Test : `tests/Livewire/Questionnaire/ImpressionPapierTest.php`

**Steps :**
- Lire `app/Livewire/Questionnaire/EnvoiCompose.php` + son point d'entrée (route ou intégration
  dans `OperationQuestionnaires`) et **reproduire le même câblage** pour `ImpressionPapier`.
- Composant : `mount(QuestionnaireCampaign $campagne)` présélectionne tous les participants de
  l'opération ; vue listant les participants (cases `wire:model="selectedParticipants"`) + bouton
  **« Générer le PDF »** ; action `imprimer(QuestionnaireImpressionService $service)` →
  `return $service->telecharger($this->campagne, $this->selectedParticipants)` (download depuis
  Livewire). **Repli** documenté : route GET `campagnes/{campagne}/impression` si le download
  Livewire pose problème.
- Ajouter l'accès « Imprimer (papier) » à côté de l'« Envoyer par email » existant.
- Tests : `mount` présélectionne les participants ; `imprimer` appelle `genererPour` (les
  invitations existent ensuite) et **retourne une réponse de téléchargement** (vérifier le type
  de retour de l'action).
- Run `vendor/bin/pest tests/Livewire/Questionnaire/ImpressionPapierTest.php` puis
  `vendor/bin/pest tests/Feature/Questionnaire tests/Livewire/Questionnaire` ; `./vendor/bin/pint`.
- Commit : `feat(questionnaires): écran impression papier + entrée campagne`

---

## Revue finale
Suite `tests/Feature/Questionnaire tests/Livewire/Questionnaire tests/Unit` verte + `./vendor/bin/pint`,
puis **recette navigateur** : générer un PDF, vérifier QR scannable (→ ouvre le questionnaire en
ligne), code court, groupes visuels, multi-pages, zones de réponse par type. Non poussé/mergé.
