# Plan — Type « Satisfaction + texte long » + mise en page papier satisfaction

> Subagent-driven. Spec : `docs/specs/2026-06-26-questionnaires-satisfaction-texte-long.md`.
> Branche `feat/questionnaires`. Pest, SQLite `:memory:`. PHP 8.5 `Deprecated` ≠ échec.

**Goal :** nouveau type compound `satisfaction_texte_long` (note + texte long, deux obligatoires
indépendants) + papier satisfaction en smileys-sans-libellés à droite du titre.

---

## Task 1 — Cœur : enum + stockage + validation à deux flags

**Files :** `app/Enums/TypeQuestion.php`, `app/Services/Questionnaire/QuestionnaireReponseService.php`,
`app/Http/Controllers/QuestionnaireRepondantController.php`, `app/Http/Controllers/QuestionnaireApercuController.php`,
factory si besoin ; tests `tests/Unit/Enums/TypeQuestionTest.php`, `tests/Feature/Questionnaire/QuestionnaireReponseServiceTest.php` (ou équivalent), `RepondantParcoursTest.php`, `QuestionnaireApercuTest.php`.

**Steps :**
- Enum `SatisfactionTexteLong = 'satisfaction_texte_long'` : `label()`='Satisfaction + texte long' ;
  `valueColumn()`='value_integer' ; `aDesOptions()`=false ; `estReponse()`=true.
- `normaliser()` : ajouter le type à la branche `value_integer` (note) ; stocker `value_text=$commentaire`
  **toujours** pour ce type (étendre le bloc commentaire existant).
- Nouvelle méthode `public function champsManquants(QuestionnaireCampaignQuestion $q, ?string $note, ?string $texte): array`
  (spec §4) — clés `q_{id}` / `q_{id}_commentaire`.
- `verifierObligatoires()` : itérer **toutes** les questions ; reconstruire note (`value_integer`) +
  texte (`value_text`) de la réponse stockée ; exception si `champsManquants` non vide.
- Parcours réel `store(next)` + aperçu `stocker(next)` : remplacer le `if obligatoire` inline par
  l'accumulation `champsManquants($q, $valeur, $commentaire)` par écran ; blocage si non vide.
- Tests : enum ; normaliser (note+texte) ; champsManquants 4 combinaisons ; verifierObligatoires
  (texte obligatoire seul) ; parcours réel + aperçu (blocage + enregistrement note+texte).
- Commit : `feat(questionnaires): type satisfaction_texte_long — stockage + validation à deux flags`

## Task 2 — Éditeur

**Files :** `app/Livewire/Questionnaire/ModeleEditor.php`, `resources/views/livewire/questionnaire/modele-editor.blade.php`,
test `tests/Livewire/Questionnaire/ModeleEditorTest.php`.

**Steps :**
- Propriété `public bool $texteObligatoire = false;` (chargée/sauvée dans `config['texte_obligatoire']`).
- Vue : pour `satisfaction_texte_long`, case **Obligatoire** (note) + nouvelle case **« Texte long
  obligatoire »** ; masquer options/ressenti/commentaire-court ; mention « Une zone de texte long
  s'affiche toujours après les smileys. ».
- Save : persister `config['texte_obligatoire']` pour ce type.
- Tests : créer un compound → `texte_obligatoire` persiste ; obligatoire (note) persiste.
- Commit : `feat(questionnaires): éditeur — type satisfaction_texte_long + texte obligatoire`

## Task 3 — Écran

**Files :** `resources/views/questionnaire/repondant/partials/champ.blade.php`,
nouveau `resources/views/questionnaire/repondant/partials/champ-satisfaction-smileys.blade.php`,
test `tests/Feature/Questionnaire/RepondantParcoursTest.php`.

**Steps :**
- Extraire le bloc smileys (SVG + radios + CSS) du case `satisfaction` vers
  `champ-satisfaction-smileys.blade.php` ; inclure depuis le case `satisfaction`.
- Nouveau case `satisfaction_texte_long` : inclure le sous-partiel smileys + `<textarea
  name="q_{id}_commentaire" rows="4">` toujours affichée, pré-remplie `value_text`, label
  « Votre commentaire » + `*` si `config['texte_obligatoire']`.
- Tests : la page d'un écran avec un compound affiche les smileys ET la zone de texte ; soumission
  enregistre note + texte.
- Commit : `feat(questionnaires): écran — type satisfaction_texte_long (smileys + texte long)`

## Task 4 — Papier (mise en page satisfaction)

**Files :** `resources/views/pdf/questionnaire-papier.blade.php`,
nouveau `resources/views/pdf/partials/champ-papier-smileys.blade.php`,
`resources/views/pdf/partials/champ-papier.blade.php` (retirer l'ancien case satisfaction),
test `tests/Feature/Questionnaire/ImpressionPapierBladeTest.php`.

**Steps :**
- `questionnaire-papier.blade.php` : pour les types satisfaction/satisfaction_texte_long, rendre une
  **table 2 colonnes** — gauche `{numéro}. {libellé}` (+ `*`/aide), droite `text-align:right` le bloc
  smileys compact. Sinon, rendu actuel.
- `champ-papier-smileys.blade.php` : 5 smileys SVG (mêmes visages/couleurs, `<img data:image/svg+xml>`),
  **sans libellés**, case à cocher sous chaque, serrés.
- Compound : sous la table, **zone texte 3 lignes** (bloc bordé ~3 lignes) + mention si
  `texte_obligatoire`. Satisfaction simple : ligne commentaire court si `config['commentaire']`.
- Retirer le case `satisfaction` de `champ-papier.blade.php`.
- Tests : titre + smileys sur la même ligne (table) ; pas de libellés de niveau ; zone 3 lignes pour
  le compound ; ligne commentaire pour la satisfaction simple avec `commentaire`.
- Commit : `feat(questionnaires): papier — smileys sans libellés à droite du titre + texte long`

## Task 5 — Résultats & export

**Files :** `app/Services/Questionnaire/QuestionnaireResultatService.php`,
`app/Services/Questionnaire/QuestionnaireExcelExporter.php`,
tests `tests/Livewire/Questionnaire/CampagneResultatsTest.php`, `tests/Feature/Questionnaire/QuestionnaireExcelExporterTest.php`.

**Steps :**
- `agreger()` : `SatisfactionTexteLong` → agrège la note comme `Satisfaction` + expose le texte
  (`value_text`) en verbatims (comme `TexteLong`).
- Exporteur : colonne note (comme satisfaction) ; texte exporté selon le motif existant.
- Tests : pas de crash ; la note agrège ; le texte apparaît dans résultats/export.
- Commit : `feat(questionnaires): résultats & export — type satisfaction_texte_long`

---

## Revue finale
Suite `tests/Feature/Questionnaire tests/Livewire/Questionnaire tests/Unit` verte + `./vendor/bin/pint`,
puis recette navigateur (écran + PDF). Non poussé/mergé.
