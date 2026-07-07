# Questionnaires — Sections légères (regroupement par écran) — Spec

> Slice de **fondation** préalable à l'impression papier (Lot 6). Permet d'afficher
> **plusieurs questions sur un même écran** au lieu d'une par page, et d'introduire des
> **titres/intertitres** — sans table de sections ni refonte lourde de l'éditeur.
> Branche : `feat/questionnaires`. Stack : Laravel 11 + Livewire 4 + Bootstrap 5, Pest.

## 1. Contexte & objectif

Aujourd'hui le parcours répondant est **une question par écran** (`page = index de question`).
On veut pouvoir **grouper** des questions sur un même écran et leur donner un **titre de
section** optionnel. Décidé après arbitrage YAGNI : pas d'entité « section » en base, pas de
conteneurs dans l'éditeur — un mécanisme **positionnel** léger.

## 2. Décisions actées (brainstorming 2026-06-25)

| # | Sujet | Décision |
|---|-------|----------|
| S1 | Mécanisme de regroupement | **Booléen `grouper_avec_precedente`** sur la question (pas de table de sections). Un écran = une question « début » + les questions suivantes marquées « groupée ». |
| S2 | Titre / intro de section | **Pas de champ dédié.** Nouveau **type de question `Information`** (display-only) : `libelle` = titre, `aide` = texte. Placé en tête d'un groupe, il fait le titre de section ; ailleurs, un simple intertitre/consigne. |
| S3 | Nature du type Information | Ne **stocke aucune réponse**, exclu de l'obligatoire, des résultats et de l'export, `aDesOptions()=false`, jamais d'`valueColumn`. |
| S4 | Progression | **« Page x / N »**, N = nombre d'écrans calculés (y compris un écran purement Information). Masquée si `afficher_progression=false`. |
| S5 | Validation multi-questions | « Suivant » valide **toutes** les questions réelles de l'écran d'un coup ; chaque question obligatoire vide affiche son erreur ; blocage si au moins une manque. |
| S6 | Migration | `grouper_avec_precedente` **défaut `false`** → une question par écran = comportement actuel inchangé. Aucun backfill. |
| S7 | Périmètre | Ce slice ne fait **pas** l'impression papier (lot suivant, construit dessus), ni la logique conditionnelle/branchement. |

**Compromis assumé (S1)** : le regroupement est *positionnel*. Réordonner une question peut
changer son écran ; déplacer un « bloc » = déplacer ses questions une à une. Acceptable en V1.

## 3. Modèle de données

Aucune nouvelle table. Sur **`questionnaire_template_questions`** ET
**`questionnaire_campaign_questions`** :

- Ajouter `grouper_avec_precedente` `boolean` `default(false)` (migration additive).

Snapshot : la création de campagne copie déjà les questions du modèle → **inclure
`grouper_avec_precedente`** dans la copie (et le `type`, déjà copié).

Enum `App\Enums\TypeQuestion` : ajouter le case **`Information = 'information'`**.
- `label()` → « Information / intertitre ».
- `valueColumn()` → ne doit jamais être appelé (l'Information n'a pas de réponse) ; lever
  une `LogicException` explicite si appelé, pour fail-fast.
- `aDesOptions()` → `false`.
- Helper **`estReponse(): bool`** (nouveau) → `false` pour `Information`, `true` pour tous les
  autres. Sert de filtre unique partout où l'on itère les « vraies » questions.

## 4. Calcul des écrans

Un résolveur pur, réutilisé par le parcours réel **et** l'aperçu :
`App\Services\Questionnaire\QuestionnaireEcranResolver::decouper(Collection $questionsOrdonnees): array`
→ renvoie une liste d'écrans, chaque écran = `Collection` de questions, dans l'ordre.

Algorithme :
```
ecrans = []
courant = null
pour chaque q (triées par ordre) :
    si courant === null OU q->grouper_avec_precedente === false :
        courant = nouvelle collection ; ecrans[] = courant
    courant[] = q
```
La 1ʳᵉ question démarre toujours un écran (le flag est ignoré si rien ne précède).
`N = count(ecrans)`. L'« index de page » du parcours indexe désormais **les écrans**, plus
les questions.

## 5. Parcours répondant

Fichiers : `QuestionnaireRepondantController` (show/store), `questionnaire/repondant/question.blade.php`,
partiel `questionnaire/repondant/partials/champ.blade.php` (réutilisé par question), + nouveau
partiel `partials/champ-information.blade.php` (rendu display-only).

- **`show(page)`** : `page=0` intro ; `page=1..N` → écran N (rend titre/intro Information +
  chaque question via le partiel champ) ; puis consentement (si `anonymise`) ; merci.
- **Rendu d'un écran** : pour chaque question de l'écran, si `Information` → bloc titre+texte
  (pas d'input) ; sinon → widget habituel (`champ.blade.php`). Les valeurs déjà saisies sont
  pré-remplies par question (reprise).
- **`store()` action `next`** : pour chaque question **réelle** (`estReponse()`) de l'écran :
  valider l'obligatoire (vide + obligatoire → erreur attachée à `q_{id}`), puis
  `enregistrerReponse`. Si au moins une obligatoire manque → retour à l'écran avec erreurs,
  pas d'avance. Sinon → écran suivant, ou consentement/merci au dernier (comportement
  `anonymise` inchangé : non-anonyme = finalise direct → merci).
- **`store()` action `prev`** : enregistre les réponses réelles de l'écran **sans bloquer**,
  va à l'écran précédent (`autoriser_retour`).
- **Progression** : « Page {x} sur {N} » + barre (si `afficher_progression`). N = nb d'écrans.
- Un écran **uniquement** composé d'Information : « Suivant » avance sans rien valider/enregistrer.

## 6. Aperçu (parité)

`QuestionnaireApercuController` (rendre/stocker) : même `QuestionnaireEcranResolver`, même
rendu, même validation obligatoire, **toujours zéro écriture en base** (réponses en session
par écran). Progression « Page x / N ». Saut du consentement si non-anonyme (inchangé).

## 7. Éditeur (écran Questions)

`app/Livewire/Questionnaire/ModeleEditor.php` + vue `modele-editor.blade.php` :

- **Type Information** dans le sélecteur de type. Quand `type=information`, le formulaire
  d'édition n'affiche que **Titre** (`libelle`) + **Texte** (`aide`) ; masque options,
  obligatoire (forcé `false`), commentaire satisfaction, libellés ressenti.
- **Case « Sur le même écran que la précédente »** (`grouper_avec_precedente`) **inline dans la
  liste** des questions (une case dans la ligne du tableau, **pas** dans le formulaire
  d'édition) : `wire:click="toggleGroupe({id})"` qui bascule **et persiste immédiatement**,
  miroir exact de `toggleActif` sur la liste des modèles. **Masquée sur la 1ʳᵉ ligne** (rien
  ne précède). C'est volontaire : on arrange les groupes en voyant toute la liste, et le
  regroupement est une décision d'adjacence — donc une opération de liste, pas de fiche.
- **Indice visuel** (léger) : les lignes groupées sont légèrement indentées / préfixées d'un
  « ↳ » pour visualiser les écrans sur la liste à plat, mis à jour dès le clic. Cosmétique,
  pas de logique.
- Réordonnancement inchangé (le regroupement étant positionnel, il suit le nouvel ordre).

## 8. Résultats & export

Filtrer les questions `Information` (via `estReponse()`) partout où l'on agrège ou exporte :
`CampagneResultats`, `QuestionnaireExcelExporter`. Elles n'ont ni colonne ni ligne de réponse.

## 9. Impression / OCR (compat. aval — non construit ici)

Les écrans calculés cadreront la mise en page papier (questions d'un même écran = un bloc ;
Information = intertitre). L'OCR reste **par question réelle** : aucune structure nouvelle à
poser pour le papier, le booléen + le type Information suffisent.

## 10. Cas limites

- 1ʳᵉ question avec `grouper_avec_precedente=true` (donnée incohérente) → traitée comme début
  d'écran (le résolveur l'absorbe).
- Écran 100 % Information → page navigable sans réponse.
- `Information` ne peut pas être obligatoire (forcé `false` à la saisie).
- `valueColumn()` sur `Information` → `LogicException` (ne doit jamais arriver : filtré en amont).

## 11. Plan de test (Pest)

- **Résolveur** : `decouper()` regroupe les `grouper_avec_precedente=true` consécutives ;
  1ʳᵉ question toujours début ; un seul groupe ; tous séparés.
- **Parcours** : un écran à 2 questions affiche les 2 ; « Suivant » bloque si l'une (obligatoire)
  est vide et enregistre les deux quand valides ; progression « Page x / N » (N = écrans) ;
  « Précédent » conserve les saisies de l'écran ; type Information rendu sans input et non
  enregistré.
- **Aperçu** : parité (mêmes écrans, 0 écriture en base).
- **Éditeur** : créer une question Information (titre+texte, pas d'obligatoire) ; cocher
  « même écran » sur une question persiste le booléen ; case absente sur la 1ʳᵉ.
- **Snapshot** : `grouper_avec_precedente` copié modèle → campagne.
- **Migration** : questionnaire existant inchangé (1 question/écran) tant qu'aucun flag posé.
- **Résultats/export** : les Information n'apparaissent pas.

## 12. Hors périmètre

- Impression papier + QR (lot suivant, construit sur ces écrans).
- Logique conditionnelle / branchement.
- Déplacement d'un « bloc » en un geste, réordonnancement par glisser-déposer riche.
- Nouveaux types-feuilles (date, email/tél, choix multiple, nombre) — parqués.
