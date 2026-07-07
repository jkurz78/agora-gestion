# Questionnaires — Type « Satisfaction + texte long » + mise en page papier satisfaction — Spec

> Nouveau type de question compound (note de satisfaction + texte long) et refonte de la
> **mise en page papier** des questions satisfaction (smileys sans libellés, à droite du titre).
> Branche `feat/questionnaires`. Laravel 11 + Livewire 4 + DomPDF, Pest.

## 1. Objectif

- **Nouveau type `satisfaction_texte_long`** : une note de satisfaction (5 smileys) **et** une
  zone de texte long, dans une seule question / une seule réponse. Chaque partie a son propre
  caractère obligatoire.
- **Mise en page papier** des questions satisfaction (existante **et** nouvelle) : smileys
  **sans libellés**, regroupés et **alignés à droite sur la ligne du titre**. (Écran inchangé —
  décidé 2026-06-26.)

## 2. Décisions actées

| # | Sujet | Décision |
|---|-------|----------|
| ST1 | Type | `TypeQuestion::SatisfactionTexteLong = 'satisfaction_texte_long'`. `label()` = « Satisfaction + texte long ». `valueColumn()` = `value_integer` (la note, colonne primaire). `aDesOptions()` = false. `estReponse()` = true. |
| ST2 | Stockage | Une réponse : **note → `value_integer`**, **texte → `value_text`** (même ligne, comme satisfaction + commentaire). Le texte est **toujours** stocké (pas de gate `config['commentaire']`). |
| ST3 | Deux obligatoires | `obligatoire` (colonne) = **note** o/n ; nouveau `config['texte_obligatoire']` (bool, **défaut false**) = **texte** o/n. Indépendants : la note peut être optionnelle et le texte obligatoire, ou l'inverse. |
| ST4 | Écran | Smileys (avec libellés, centrés — disposition actuelle) + **zone de texte long toujours affichée** (`<textarea>`), pré-remplie depuis `value_text`. Champ texte nommé `q_{id}_commentaire` (capté par le contrôleur existant). `*` sur le texte si `texte_obligatoire`. |
| ST5 | Papier (satisfaction ET satisfaction+texte) | Titre (+ numéro de groupe + `*`) **à gauche**, **bloc smileys compact sans libellés à droite, même ligne**. Pour le type compound : **zone de texte 3 lignes** en dessous (+ mention obligatoire si `texte_obligatoire`). Pour la satisfaction simple : sa **ligne de commentaire court** optionnelle (si `config['commentaire']`) reste en dessous. |
| ST6 | Validation | Helper centralisé `champsManquants($q, ?string $note, ?string $texte): array<champ, message>` sur `QuestionnaireReponseService`. Cas standard : `obligatoire && note vide → ['q_{id}' => …]`. Cas compound : note (si `obligatoire`) **et** texte (si `texte_obligatoire`), erreurs sur `q_{id}` / `q_{id}_commentaire`. Utilisé par le parcours réel, l'aperçu **et** `verifierObligatoires` (qui itère désormais toutes les questions, plus seulement `obligatoire=true`). |
| ST7 | Éditeur | Type au sélecteur. Quand sélectionné : la case **Obligatoire** existante = note ; nouvelle case **« Texte long obligatoire »** (`config['texte_obligatoire']`, défaut décoché) ; masquer options/ressenti/commentaire-court ; mention « Une zone de texte long s'affiche toujours après les smileys ». |
| ST8 | Résultats/export | La **note** s'agrège comme `Satisfaction` (`value_integer`) ; le **texte** est exposé comme un verbatim `value_text`. `agreger()` et l'exporteur doivent gérer le nouveau type (sinon `LogicException`/crash). |

## 3. Stockage (`QuestionnaireReponseService::normaliser`)

- Ajouter `SatisfactionTexteLong` à la branche `value_integer` (avec Satisfaction/Ressenti) pour la note.
- Bloc texte : stocker `value_text = $commentaire` **toujours** pour `SatisfactionTexteLong`
  (et conserver le comportement existant pour `Satisfaction` + `config['commentaire']`).

## 4. Validation

`public function champsManquants(QuestionnaireCampaignQuestion $q, ?string $note, ?string $texte): array`
- Retourne un tableau `['q_{id}' => 'message', 'q_{id}_commentaire' => 'message']` (clés présentes
  seulement si la partie est manquante).
- Standard (tous types sauf compound) : si `$q->obligatoire && ($note === null || $note === '')`
  → `['q_'.$q->id => 'Cette question est obligatoire.']`.
- `SatisfactionTexteLong` :
  - si `$q->obligatoire && note vide` → `['q_'.$q->id => 'Veuillez indiquer votre satisfaction.']`
  - si `($q->config['texte_obligatoire'] ?? false) && texte vide` → `['q_'.$q->id.'_commentaire' => 'Ce texte est obligatoire.']`

**Parcours réel + aperçu** : remplacer le `if obligatoire` inline par
`$erreurs += $this->reponses->champsManquants($q, $valeur, $commentaire)` (fusion par écran),
blocage si `$erreurs` non vide.

**`verifierObligatoires`** : itérer **toutes** les questions de la campagne ; pour chacune,
reconstruire `$note` (depuis `value_integer`) et `$texte` (depuis `value_text`) de la réponse
stockée, et lever l'exception si `champsManquants(...)` est non vide.

## 5. Écran (`partials/champ.blade.php`)

- Extraire le bloc smileys satisfaction (SVG + radios + CSS) en sous-partiel réutilisable
  (`partials/champ-satisfaction-smileys.blade.php`) ; l'inclure depuis le case `satisfaction`
  **et** le nouveau case `satisfaction_texte_long`.
- `satisfaction_texte_long` : smileys + `<textarea name="q_{id}_commentaire" rows="4">` toujours
  affichée, pré-remplie `value_text`, label « Votre commentaire » + `*` si `texte_obligatoire`.

## 6. Papier (`pdf/questionnaire-papier.blade.php` + partiels)

- Per-question : si le type est satisfaction OU satisfaction+texte → rendre une **table 2 colonnes**
  (gauche : `{numéro}. {libellé}` + `*` si obligatoire + aide ; droite, `text-align:right` :
  bloc smileys compact). Sinon, rendu actuel (titre puis `champ-papier`).
- Nouveau partiel `pdf/partials/champ-papier-smileys.blade.php` : les 5 smileys SVG (mêmes
  visages/couleurs qu'à l'écran, via `<img src="data:image/svg+xml;base64,…">`), **sans libellés**,
  avec une **case à cocher** sous chaque, serrés (largeur fixe ~ 26 px/colonne).
- Type compound : sous la table, **zone texte 3 lignes** (bloc bordé) + petite mention si
  `texte_obligatoire`. Satisfaction simple : ligne de commentaire court (si `config['commentaire']`,
  comportement actuel) sous la table.
- Retirer l'ancien case `satisfaction` (avec libellés) de `champ-papier.blade.php` (devenu mort).

## 7. Éditeur (`Livewire/Questionnaire/ModeleEditor`)

- Nouvelle propriété `public bool $texteObligatoire = false;` chargée/sauvée dans `config`.
- Vue : pour `satisfaction_texte_long`, afficher la case **Obligatoire** (note) + **« Texte long
  obligatoire »** ; masquer les blocs options/ressenti/commentaire-court ; mention d'aide.
- `buildConfig()`/save : persister `config['texte_obligatoire']` pour ce type.

## 8. Résultats & export

- `QuestionnaireResultatService::agreger()` : `SatisfactionTexteLong` agrège la **note** comme
  `Satisfaction` ; expose le **texte** (verbatims `value_text`) comme un texte long.
- `QuestionnaireExcelExporter` : colonne note (comme satisfaction) ; le texte exporté (colonne
  dédiée ou en complément, selon le motif existant pour les commentaires).

## 9. Tests

- **Enum** : label, valueColumn=value_integer, estReponse=true, aDesOptions=false.
- **normaliser** : note→value_integer + texte→value_text pour le compound.
- **champsManquants** : compound, 4 combinaisons (note req/opt × texte req/opt) ; standard inchangé.
- **verifierObligatoires** : compound texte obligatoire non rempli → exception, même si note optionnelle.
- **Parcours réel + aperçu** : un compound avec note+texte obligatoires bloque si l'un manque ;
  enregistre note (value_integer) + texte (value_text) quand complet.
- **Éditeur** : créer un compound, `texte_obligatoire` persiste ; obligatoire (note) persiste.
- **Papier (blade render)** : titre + smileys sur la même ligne, smileys sans libellés, zone 3
  lignes pour le compound, ligne commentaire court pour la satisfaction simple.
- **Résultats/export** : pas de crash, la note agrège, le texte apparaît.

## 10. Hors périmètre

- Mise en page écran des smileys à droite du titre (décidé : écran inchangé).
- Logique conditionnelle / branchement.
