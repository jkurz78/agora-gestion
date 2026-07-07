# Spec — 5 nouveaux types de questions questionnaires

Statut : **validée** (design approuvé en session le 2026-07-07).
Branche cible : `feat/questionnaires-fiche-campagne` (empilée sur `feat/questionnaires-ressenti-pixel`).

## Contexte

Le moteur de questionnaires supporte 8 types de questions. Cinq besoins reviennent régulièrement
et ont été parqués : saisie de date, sélection multiple, valeur numérique libre, adresse email
avec double saisie web, et sélecteur numérique paramétrable (dropdown min/max pour l'âge, etc.).

## Types existants (rappel)

TexteCourt, TexteLong, Satisfaction, SatisfactionTexteLong, Ressenti, CaseACocher, ChoixUnique,
Information.

## Nouveaux types

| # | Type | Slug enum | Label affiché | Colonne DB | Config JSON |
|---|------|-----------|---------------|-----------|-------------|
| 1 | Date | `date` | Date | `value_text` (ISO `Y-m-d`) | — |
| 2 | Choix multiple | `choix_multiple` | Choix multiple | `value_option` (JSON array `["opt_a","opt_b"]`) | `options` (même structure que ChoixUnique) |
| 3 | Nombre | `nombre` | Nombre | `value_text` (string numérique, décimaux supportés) | `min`, `max` (optionnels, numériques) |
| 4 | Email | `email` | Adresse email | `value_text` | — |
| 5 | Sélection numérique | `selection_numerique` | Sélection numérique | `value_integer` | `min`, `max` (requis, entiers) |

**Pas de migration sur `questionnaire_answers`** — tous les types réutilisent les colonnes
existantes (`value_text`, `value_option`, `value_integer`).

`aDesOptions()` retourne `true` pour ChoixMultiple (réutilise le système d'options de ChoixUnique).
`estReponse()` retourne `true` pour les 5 types.

---

## D1 — Enum `TypeQuestion`

Ajouter 5 cases à l'enum. Mettre à jour les 5 méthodes :
- `label()` — libellés ci-dessus.
- `valueColumn()` — colonnes ci-dessus.
- `aDesOptions()` — `true` pour `ChoixMultiple`, `false` pour les 4 autres.
- `estReponse()` — `true` pour les 5.
- `pourSelect()` — automatique (itère `self::cases()`).

## D2 — Éditeur (`ModeleEditor`)

**Champs de config dans `buildConfig()` :**
- **Date, Email** : aucun champ de config spécifique.
- **Nombre** : champs optionnels `min` / `max` (numériques, `min < max` si les deux renseignés).
- **Choix multiple** : réutilise le champ `optionsBrut` (une option par ligne) — même UX et même
  structure `config['options']` que ChoixUnique.
- **Sélection numérique** : champs requis `min` / `max` (entiers, `min < max`, validation côté
  éditeur).

Le sélecteur de type dans l'éditeur gagne 5 entrées via `pourSelect()`.

## D3 — Formulaire web (`champ.blade.php`)

- **Date** : `<input type="date">` (natif, format ISO).
- **Choix multiple** : `<input type="checkbox">` par option, instruction « Cochez une ou plusieurs
  réponses ». Toujours des checkboxes quelle que soit la quantité d'options.
- **Nombre** : `<input type="number" step="any">` avec attributs HTML `min`/`max` si configurés.
- **Email** : deux `<input type="email">` (saisie + confirmation). Validation JS inline « Les
  adresses ne correspondent pas ». Seule la première valeur est envoyée au serveur.
- **Sélection numérique** : `<select>` avec `<option>` de `config.min` à `config.max`.

## D4 — PDF papier (`champ-papier.blade.php`)

- **Date** : 8 cases individuelles par chiffre avec séparateurs `/` :
  ```
   ┌──┐ ┌──┐     ┌──┐ ┌──┐     ┌──┐ ┌──┐ ┌──┐ ┌──┐
   │  │ │  │  /  │  │ │  │  /  │  │ │  │ │  │ │  │
   └──┘ └──┘     └──┘ └──┘     └──┘ └──┘ └──┘ └──┘
    J    J        M    M        A    A    A    A
  ```
  Instruction : « Saisissez la date : JJ / MM / AAAA ».
- **Choix multiple** : cases ☐ par option + instruction « Cochez une ou plusieurs réponses ».
- **Nombre** : ligne à remplir + instruction « Entrez un nombre » (+ « entre X et Y » si min/max
  configurés).
- **Email** : ligne à remplir + label « Adresse email » (pas de double saisie papier).
- **Sélection numérique** : ligne à remplir + instruction « Entrez un nombre entre X et Y ».

## D5 — Validation et normalisation (`QuestionnaireReponseService`)

**`normaliser()` — nouveaux match arms :**
- `Date` → `['value_text' => $val]` — validation format date (`Y-m-d` ou `d/m/Y` converti).
- `ChoixMultiple` → `['value_option' => json_encode($val)]` — `$val` est un `array` de valeurs
  techniques. Stocke aussi `value_meta['libelles']` (snapshot des libellés cochés).
- `Nombre` → `['value_text' => (string) $val]` — validation `is_numeric` + bornes si config.
- `Email` → `['value_text' => $val]` — validation `filter_var($val, FILTER_VALIDATE_EMAIL)`.
- `SelectionNumerique` → `['value_integer' => (int) $val]` — validation `$val` entier dans
  `[config.min, config.max]`.

**`champsManquants()`** — les 5 types suivent le pattern standard : vérifie la colonne primaire
via `$type->valueColumn()`.

## D6 — Résultats

### Agrégation (`QuestionnaireResultatService::agreger()`)

- **Date** → `verbatims` (liste des dates formatées), `n`.
- **Choix multiple** → `repartition` par option (comme ChoixUnique, mais chaque réponse incrémente
  plusieurs compteurs ; le total des compteurs peut dépasser `n`), `n`.
- **Nombre** → `moyenne` (float), `min`, `max`, `n` — nouveau pattern.
- **Email** → `verbatims` (liste des emails), `n`.
- **Sélection numérique** → `moyenne` (float), `distribution` (comptage par valeur), `n`
  (pattern Satisfaction).

### Affichage consolidé (`_resultats.blade.php`)

- **Date, Email** → blockquotes verbatims (pattern TexteCourt).
- **Choix multiple** → badges par option avec compteur (pattern ChoixUnique).
- **Nombre** → badge moyenne + min/max affichés.
- **Sélection numérique** → badge moyenne + distribution badges.

### Affichage individuel (`_reponses-individuelles.blade.php`)

- **Date** → date formatée `d/m/Y`.
- **Choix multiple** → badges listant les options cochées.
- **Nombre** → valeur brute.
- **Email** → valeur texte.
- **Sélection numérique** → valeur entière.

## D7 — Export Excel (`QuestionnaireExcelExporter`)

**`valeurAffichee()` — nouveaux match arms :**
- `Date` → valeur brute ISO (Excel la formate).
- `ChoixMultiple` → libellés joints par « , ».
- `Nombre` → valeur numérique.
- `Email` → valeur texte.
- `SelectionNumerique` → valeur entière.

## D8 — OCR (`QuestionnaireOcrService`)

**`buildPrompt()` — règles d'extraction :**
- `date` : « value = date au format AAAA-MM-JJ ».
- `choix_multiple` : « value = tableau JSON des VALEURS TECHNIQUES cochées » (avec liste des
  options envoyée dans le prompt).
- `nombre` : « value = nombre (entier ou décimal) ».
- `email` : « value = adresse email ».
- `selection_numerique` : « value = entier entre X et Y ».

**`demoStub()` — valeurs démo :**
- `date` → `'2026-01-15'`.
- `choix_multiple` → premier élément du tableau d'options `['opt_1']`.
- `nombre` → `42`.
- `email` → `'exemple@email.fr'`.
- `selection_numerique` → `intdiv(min + max, 2)` (valeur médiane).

## Hors périmètre (YAGNI)

- Pas de type « Heure » ou « Date+Heure ».
- Pas de label « unité » pour Nombre (km, €, etc.).
- Pas de rendu conditionnel papier (cases numérotées pour petites plages) pour Sélection numérique.
- Pas de validation MX/DNS pour Email (format uniquement).
- `RessentiScanMeasurer` inchangé (ne concerne que Ressenti).

## Surfaces d'impact (checklist)

Pour chaque nouveau type, les fichiers suivants doivent être modifiés :

| # | Fichier | Action |
|---|---------|--------|
| 1 | `app/Enums/TypeQuestion.php` | Ajouter case + 4 match arms |
| 2 | `app/Livewire/Questionnaire/ModeleEditor.php` | `buildConfig()` + `editerQuestion()` |
| 3 | `resources/views/livewire/questionnaire/modele-editor.blade.php` | Champs config conditionnels |
| 4 | `resources/views/questionnaire/repondant/partials/champ.blade.php` | `@case` formulaire web |
| 5 | `resources/views/pdf/partials/champ-papier.blade.php` | `@case` rendu papier |
| 6 | `app/Services/Questionnaire/QuestionnaireReponseService.php` | `normaliser()` + validation |
| 7 | `app/Services/Questionnaire/QuestionnaireResultatService.php` | `agreger()` |
| 8 | `app/Services/Questionnaire/QuestionnaireExcelExporter.php` | `valeurAffichee()` + headers |
| 9 | `resources/views/questionnaire/resultats/_resultats.blade.php` | Affichage consolidé |
| 10 | `resources/views/questionnaire/resultats/_reponses-individuelles.blade.php` | Affichage individuel |
| 11 | `app/Services/Questionnaire/QuestionnaireOcrService.php` | `buildPrompt()` + `demoStub()` |
| 12 | `tests/Unit/Enums/TypeQuestionTest.php` | Tests enum |
| 13 | Nouveaux tests feature par type | Validation, résultats, export |

## Critères d'acceptation

- **AC1** : les 5 types apparaissent dans le sélecteur de l'éditeur de modèle.
- **AC2** : chaque type se rend correctement dans le formulaire web (aperçu + répondant réel).
- **AC3** : chaque type se rend correctement sur le PDF papier imprimé.
- **AC4** : Email : la confirmation bloque l'envoi si les 2 champs diffèrent (web uniquement).
- **AC5** : Sélection numérique : le dropdown affiche les entiers de min à max.
- **AC6** : Choix multiple : les réponses sont stockées en JSON dans `value_option` et restituées
  en badges dans les résultats.
- **AC7** : Nombre : les décimaux sont acceptés et stockés dans `value_text`.
- **AC8** : les résultats consolidés agrègent correctement chaque type (verbatims, répartition,
  moyenne selon le type).
- **AC9** : l'export Excel rend chaque type dans la colonne correspondante.
- **AC10** : l'OCR (Claude vision) extrait correctement les valeurs pour chaque type sur un scan.
- **AC11** : les validations min/max sont appliquées (Nombre, Sélection numérique).
- **AC12** : suite questionnaires complète verte + pint.
