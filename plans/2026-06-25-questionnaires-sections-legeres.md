# Questionnaires — Sections légères — Plan d'implémentation

> Exécution : subagent-driven. Spec : `docs/specs/2026-06-25-questionnaires-sections-legeres-spec.md`.
> Branche `feat/questionnaires`. Laravel 11 + Livewire 4 + Bootstrap 5, Pest, SQLite `:memory:`.
> PHP 8.5 `Deprecated: PDO::MYSQL_ATTR_SSL_CA` ≠ échec.

**Goal :** afficher plusieurs questions par écran (booléen `grouper_avec_precedente`) + type
`Information` display-only, sans table de sections.

**Architecture :** un résolveur pur découpe la liste ordonnée de questions en « écrans » ;
parcours, aperçu, résultats et export deviennent écran-aware / filtrent les Information.

---

## Task 1 — Socle données (booléen + enum Information + snapshot)

**Files :**
- Migration additive `database/migrations/2026_06_25_1000XX_add_grouper_to_questionnaire_questions.php`
- `app/Enums/TypeQuestion.php`
- `app/Models/QuestionnaireTemplateQuestion.php`, `app/Models/QuestionnaireCampaignQuestion.php`
- `app/Services/Questionnaire/QuestionnaireCampaignService.php` (snapshot `creerDepuisModele`)
- Tests : `tests/Feature/Questionnaire/QuestionnaireCampaignServiceTest.php`, `tests/Unit/TypeQuestionTest.php` (créer si absent)

**Steps :**
- Migration : `grouper_avec_precedente boolean default(false)` sur les **deux** tables de
  questions (vérifier les noms réels : `questionnaire_template_questions` /
  `questionnaire_campaign_questions`). `down()` propre. Lancer `./vendor/bin/sail artisan migrate`.
- Enum `TypeQuestion` : ajouter `case Information = 'information';`
  - `label()` → « Information / intertitre »
  - `aDesOptions()` → `false` pour Information
  - `valueColumn()` → `throw new \LogicException(...)` pour Information
  - nouveau `public function estReponse(): bool` → `$this !== self::Information`
- Modèles : `grouper_avec_precedente` dans `$fillable` + cast `'boolean'` (les deux modèles).
- Snapshot : `creerDepuisModele()` copie `grouper_avec_precedente` (et `type`, déjà copié).
- Tests : `estReponse()` (Information=false, un autre=true) ; `valueColumn()` sur Information
  lève `LogicException` ; snapshot copie le booléen modèle→campagne ; défaut `false`.
- Commit : `feat(questionnaires): socle sections légères (booléen grouper + type Information)`

## Task 2 — Résolveur d'écrans

**Files :**
- `app/Services/Questionnaire/QuestionnaireEcranResolver.php`
- Test : `tests/Unit/QuestionnaireEcranResolverTest.php`

**Steps :**
- `decouper(\Illuminate\Support\Collection $questionsOrdonnees): array` → liste de `Collection`
  (algorithme spec §4 : nouvel écran si `courant===null` ou `grouper_avec_precedente===false`).
- Tests (objets simples ou factories sans DB) : 3 questions toutes `false` → 3 écrans ;
  Q2+Q3 `true` → écran [Q1] + écran [Q2,Q3] ; 1ʳᵉ question `true` → démarre quand même un
  écran ; collection vide → `[]`.
- Commit : `feat(questionnaires): QuestionnaireEcranResolver (découpage en écrans)`

## Task 3 — Parcours répondant écran-aware

**Files :**
- `app/Http/Controllers/QuestionnaireRepondantController.php` (show/store)
- `resources/views/questionnaire/repondant/question.blade.php`
- `resources/views/questionnaire/repondant/partials/champ-information.blade.php` (nouveau)
- Test : `tests/Feature/Questionnaire/RepondantParcoursTest.php`

**Steps :**
- `show(page)` : `page` indexe les **écrans** (via `QuestionnaireEcranResolver`). Rendre l'écran :
  boucle sur ses questions ; Information → `champ-information` (titre `libelle` + texte `aide`,
  pas d'input) ; sinon → `champ.blade.php`. Pré-remplir chaque réponse réelle (reprise).
  `total` = nombre d'écrans.
- `store()` `next` : pour chaque question **réelle** (`type->estReponse()`) de l'écran courant :
  bloquer si obligatoire vide (erreur `reponse`/par champ), sinon `enregistrerReponse`. Si
  blocage → retour écran avec erreurs. Sinon écran suivant ; au dernier → consentement si
  `anonymise`, sinon `finaliser(accepteContact:false)` → merci (inchangé).
- `store()` `prev` : enregistre les réponses réelles de l'écran sans bloquer → écran précédent.
- Vue : barre « Page {x} sur {N} » (si `afficher_progression`), boutons Précédent (si
  `autoriser_retour`) / Suivant — réutiliser l'agencement existant.
- Tests : écran à 2 questions affiche les 2 ; Suivant bloque si une obligatoire vide,
  enregistre les 2 si OK ; `total`/progression = nb écrans ; Précédent conserve les saisies ;
  Information rendu sans input et **non** enregistré ; **compat** : questionnaire sans aucun
  flag = 1 question/écran (les tests existants restent verts).
- Commit : `feat(questionnaires): parcours répondant écran-aware (multi-questions/écran + Information)`

## Task 4 — Aperçu (parité)

**Files :**
- `app/Http/Controllers/QuestionnaireApercuController.php` (rendre/stocker)
- `resources/views/questionnaire/apercu/question.blade.php`
- Test : `tests/Feature/Questionnaire/QuestionnaireApercuTest.php`

**Steps :**
- Même `QuestionnaireEcranResolver`, même rendu (Information inclus), même validation
  obligatoire, **0 écriture en base** (réponses en session par écran). Progression « Page x / N ».
  Saut consentement si non-anonyme (inchangé).
- Tests : aperçu d'un écran à 2 questions ; obligatoire bloque ; navigation Suivant/Précédent
  conserve les réponses en session ; toujours 0 submission/answer.
- Commit : `feat(questionnaires): aperçu écran-aware (parité parcours)`

## Task 5 — Éditeur (type Information + case grouper inline)

**Files :**
- `app/Livewire/Questionnaire/ModeleEditor.php`
- `resources/views/livewire/questionnaire/modele-editor.blade.php`
- Test : `tests/Livewire/Questionnaire/ModeleEditorTest.php`

**Steps :**
- Type `Information` dans le sélecteur ; quand sélectionné, le formulaire n'affiche que
  **Titre** (`libelle`) + **Texte** (`aide`) ; masque options, satisfaction/ressenti, et
  **force `obligatoire=false`** à l'enregistrement.
- Liste des questions : nouvelle cellule **case à cocher inline** `wire:click="toggleGroupe({{ $q->id }})"`
  → bascule `grouper_avec_precedente` + persiste immédiatement (miroir `toggleActif`).
  **Masquée sur la 1ʳᵉ ligne.** Lignes groupées indentées + préfixe « ↳ ».
- Tests : créer une question Information (titre+texte, non obligatoire même si coché) ;
  `toggleGroupe` bascule et persiste le booléen ; la 1ʳᵉ question n'a pas de bascule active.
- Commit : `feat(questionnaires): éditeur — type Information + case grouper inline`

## Task 6 — Résultats & export (filtrer Information)

**Files :**
- `app/Livewire/Questionnaire/CampagneResultats.php`
- `app/Services/Questionnaire/QuestionnaireExcelExporter.php`
- Tests : `tests/Livewire/Questionnaire/CampagneResultatsTest.php`, `tests/Feature/Questionnaire/QuestionnaireExcelExporterTest.php`

**Steps :**
- Exclure les questions `Information` (filtre `type->estReponse()`) partout où l'on agrège /
  liste / exporte des colonnes de réponses.
- Tests : une campagne avec une Information + une vraie question → l'Information n'apparaît ni
  dans les résultats ni dans l'export (pas de colonne/ligne) ; la vraie question, oui.
- Commit : `feat(questionnaires): résultats & export ignorent les blocs Information`

---

## Revue finale
Après les 6 tâches : suite `tests/Feature/Questionnaire tests/Livewire/Questionnaire tests/Unit`
verte, `./vendor/bin/pint`, puis recette navigateur (non poussé/mergé).
