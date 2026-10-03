# Adhésions — complétude de l'exercice et cohérence des écrans

> **Date** : 2026-10-02
> **Statut** : spec validée, exécution directe (pas de plan en tâches)
> **Périmètre** : correctif. Aucune fonctionnalité nouvelle.

## 1. Le cas qui a déclenché

Une adhésion 2026‑2027 saisie à la main pour Julie Moniotte le 02/10/2026 s'affiche sur l'écran
Adhérents avec **les dates de l'an passé** (01/09/2025 → 31/08/2026). Sa fiche montre pourtant bien
deux adhésions, dont la nouvelle avec les bonnes dates — mais **sans exercice**.

Trois défauts distincts ont été trouvés en tirant ce fil. Aucun ne fausse un montant ; tous faussent
une **liste de personnes**, ce qui pour une convocation statutaire n'est pas anodin.

## 2. Les trois défauts, mesurés

### 2.1 L'exercice est nul dès que la formule n'est pas en mode « exercice »

[`AdhesionService::computeDatesEtExercice()`](../../app/Services/AdhesionService.php#L197) et
[`creerDepuisWizard()`](../../app/Services/AdhesionService.php#L319) posent tous deux
`exercice = null` pour les modes **durée**, **dates HelloAsso** et **illimité**. Seul le mode
« exercice » le renseigne.

Or la formule de la saison en cours est auto-créée par la synchro HelloAsso en mode `duree` avec
`helloasso_start_date`/`end_date` et **les deux unités de durée à NULL** — une quatrième forme que
le modèle appelle « Custom » et pour laquelle la validation XOR est volontairement contournée.

**Conséquence mesurée en production** : les **trois** adhésions de la saison 2026‑2027 ont un
exercice nul, qu'elles viennent de la synchro (Kohl, Salin Beneteau) ou d'une saisie manuelle
(Moniotte). Et ce n'est pas récent : **Mircea Notz** porte une adhésion **2025‑2026** également
sans exercice.

⚠️ Le défaut est **invisible à l'écran** : [adherent-list.blade.php:72](../../resources/views/livewire/adherent-list.blade.php#L72)
affiche l'année entre parenthèses en la recalculant par `anneeForDate($adh->transaction->date)` —
depuis la date du **règlement**, pas depuis la colonne. L'écran paraît donc juste pendant que la
donnée est incomplète.

### 2.2 Le tri de « dernière adhésion » place les NULL en dernier

[`AdherentList.php:102`](../../app/Livewire/AdherentList.php#L102) trie par `exercice` **en premier**.
En SQL, `ORDER BY ... DESC` place les NULL en dernier : l'adhésion 2026‑2027 de Julie Moniotte, à
exercice nul, perd contre celle de 2025‑2026. Le critère suivant, `date_fin`, aurait donné la bonne
réponse — il n'est jamais atteint.

C'est le symptôme visible, et le seul que l'utilisateur ait constaté.

### 2.3 Le filtre « à jour » mélange deux références de temps

[`AdherentList.php:44`](../../app/Livewire/AdherentList.php#L44) et le filtre `a_jour`
([#L52](../../app/Livewire/AdherentList.php#L52)) combinent :

```php
$exercice = ExerciceService::current();   // suit le sélecteur d'exercice
$today    = now()->toDateString();        // ne le suit pas
// a_jour : exercice = $exercice  OU  date_debut <= $today <= date_fin  OU  mode illimite
```

Basculer le sélecteur d'exercice déplace **une moitié** de la condition et pas l'autre.

**Conséquence mesurée** : avec le sélecteur sur 2025‑2026, le filtre rend **26** personnes — les 24
à `exercice = 2025`, plus Kohl et Salin Beneteau entrées par la branche « aujourd'hui » alors
qu'elles n'ont adhéré que pour 2026‑2027. Et il **exclut Mircea Notz**, membre de 2025‑2026, dont
l'exercice est nul et dont les dates ne couvrent plus aujourd'hui.

Deux erreurs en sens inverse sur la même liste. **Corriger 2.1 ne répare pas ce défaut** : Notz
rentrerait, mais Kohl et Salin Beneteau resteraient.

### 2.4 La date de début saisie est jetée en silence (défaut mineur)

Dans le wizard, [`dateFinCalculee()`](../../app/Livewire/NouvelleAdhesionModal.php#L88) ignore les
dates HelloAsso : pour une formule Custom, elle rend `null`, donc **aucune date de fin ne s'affiche**.
Et le DTO transmet la date de début choisie, que la branche HelloAsso du service **écrase** par
`helloasso_start_date`.

L'utilisateur saisit donc une date qui n'aura aucun effet, sans en être averti.

## 3. Ce qui n'est PAS cassé, et qu'il ne faut pas « réparer »

- **Le filtre « Adhérents / Exercice en cours » de la communication**
  ([`CommunicationTiers.php:203`](../../app/Livewire/CommunicationTiers.php#L203)) **ne touche pas la
  table `adhesions`**. Il interroge les transactions : une recette, dans l'exercice visé par sa date,
  dont une ligne pointe un compte d'usage `cotisation`. Il est insensible à 2.1, et c'est la bonne
  sémantique pour une convocation — qui a effectivement réglé. **C'est le chemin à recommander pour
  une AG.** Mesuré : 25 personnes pour 2025‑2026, soit exactement celles qui ont une adhésion
  couvrant cette période.
- **L'exercice comptable des transactions.** `transactions` n'a **aucune colonne `exercice`** : il est
  dérivé de la date, partout, par `forExercice()`. `adhesions.exercice` est autre chose — la *saison
  d'adhésion*. Les deux peuvent légitimement diverger : un règlement du 25/08/2026 pour la saison
  2026‑2027 produit une écriture sur l'exercice 2025‑2026 et une adhésion sur 2026‑2027.
- **Les dates des adhésions.** Les trois de la saison en cours portent `2026-09-01 → 2027-08-31`,
  correctement. Seul l'exercice manque.
- **Les montants.** Aucun chiffre comptable n'est affecté par ces trois défauts.

## 4. Décisions

| # | Décision | Justification |
|---|---|---|
| D1 | **L'exercice est dérivé de la date de début, pour tous les modes**, synchro comprise | `exerciceFromDate()` existe déjà ([#L476](../../app/Services/AdhesionService.php#L476)) et respecte `exercice_mois_debut`. Pour la formule HelloAsso 01/09/2026 → 31/08/2027, il rend 2026 — exactement la saison affichée. Exigence de complétude posée par le propriétaire : « une adhésion saisie manuellement doit respecter la complétude des données ». |
| D2 | **La déduplication reste fondée sur les dates pour les formules en durée** | [`findExistingAdhesion()`](../../app/Services/AdhesionService.php#L257) **branche sur la nullité de l'exercice** : renseigné → recherche sur `(tiers, exercice)`, nul → sur `(date_debut, date_fin)`. Remplir l'exercice sans découpler ferait **changer de branche** la recherche de doublon d'une resynchro HelloAsso. L'exercice devient une **information**, pas une clé. Sans quoi deux adhésions légitimes d'un même tiers sur une même saison — formule trimestrielle renouvelée — deviendraient indistinguables. |
| D3 | **Le filtre « à jour » adopte une référence de temps unique** : l'exercice sélectionné | Une adhésion est « à jour pour l'exercice E » si son exercice vaut E **ou** si sa période recouvre E — jamais « si elle couvre aujourd'hui ». Le mélange actuel produit une liste fausse dans les deux sens. |
| D4 | **Le tri de « dernière adhésion » porte d'abord sur la date de fin** | `COALESCE(date_fin, '9999-12-31') DESC`, puis `exercice`, puis `id`. Le `COALESCE` fait remonter une adhésion illimitée, qui n'a pas de fin. ⚠️ À éprouver sur **MariaDB**, moteur de production. |
| D5 | **Le wizard affiche la période imposée en lecture seule** quand la formule porte des dates fixes | Arbitré par le propriétaire. Le champ de saisie ne réapparaît que pour une formule en durée réelle. `dateFinCalculee()` doit connaître les dates HelloAsso pour que l'aperçu cesse d'être muet. |
| D6 | **On peut créer une adhésion manuelle sur n'importe quelle formule**, y compris HelloAsso | Arbitré par le propriétaire : « idéalement on peut créer une adhésion manuellement sur toutes les formules ». Évite de dupliquer une formule pour la saisie manuelle. Le **compte bancaire** HelloAsso reste exclu du wizard, comme aujourd'hui. |

## 4bis. 🔴 L'index unique, révisé le 2026-10-03

**Défaut de cette spec, trouvé en l'exécutant.** La table porte
`UNIQUE adhesions_unique_per_exercice (association_id, tiers_id, exercice)`. L'exercice nul servait
d'échappatoire : en SQL, deux NULL sont distincts. **Le remplir (D1) rend AC‑4 impossible** — deux
adhésions d'un même tiers sur une même saison deviennent interdites en base.

Pire : **l'index compte les lignes en suppression logique**. Mircea Notz porte deux adhésions sur la
même transaction 192 — la 25 vivante à exercice nul, et la 26 « Adhésion legacy » à exercice 2025,
soft-deletée le 21/06/2026 par la migration `2026_06_20_000001`. Donner 2025 à la 25 violerait la
clé de la 26, et **`artisan migrate` échouerait au déploiement en production**.

**D7 — l'index devient `(association_id, tiers_id, exercice, date_debut, adhesion_key)`**, où
`adhesion_key` est une colonne générée :

```php
$table->unsignedTinyInteger('adhesion_key')
    ->virtualAs('CASE WHEN deleted_at IS NULL THEN 1 END');
```

🔴 **Corrigé le 2026-10-03, après mesure sur les trois moteurs.** La première rédaction de cette
spec proposait `COALESCE(DATE_FORMAT(deleted_at, '%Y%m%d%H%i%s'), '0')`. Elle était **inutilisable
en production** :

```
MariaDB 11.4.13 → ERROR 1901 (HY000): Function or expression 'date_format()'
                  cannot be used in the GENERATED ALWAYS AS clause
```

MySQL 8.4 l'accepte, MariaDB la refuse, SQLite n'a pas `DATE_FORMAT` du tout. `artisan migrate`
aurait échoué **au déploiement**. Deux défauts supplémentaires de cette version : la valeur d'un
`DATE_FORMAT` sur un `TIMESTAMP` dépend du fuseau de session, et deux lignes supprimées **dans la
même seconde** auraient collisionné (`1062 Duplicate entry`, reproduit sur MySQL).

La forme `CASE WHEN deleted_at IS NULL THEN 1 END` est acceptée par **les trois moteurs**. Les
lignes vivantes valent `1` et restent contraintes entre elles ; les lignes supprimées valent `NULL`,
distinct de tout sur SQLite, MySQL **et** MariaDB, et ne bloquent personne — pas même une autre
ligne supprimée.

⚠️ **Limite de cette clé, à connaître** : une ligne dont `date_debut` ou `exercice` est `NULL` n'est
contrainte par rien, puisqu'un NULL dans un index unique le neutralise. C'est le cas des adhésions
offertes de `creerGratuite()`, dont le `mode` et la `date_debut` sont nuls. Leur protection reste
**applicative**. Ne pas écrire que « la protection une-par-exercice demeure » sans cette réserve.

C'est le motif déjà employé par [`budget_lines.operation_key`](../../database/migrations/2026_08_31_100000_add_operation_id_to_budget_lines.php#L29),
et pour la même raison. ⚠️ **`virtualAs` et jamais `storedAs`** : SQLite refuse d'ajouter une colonne
générée STORED par `ALTER TABLE`.

Effet : les lignes vivantes partagent la valeur `'0'` et restent contraintes entre elles ; une ligne
supprimée porte son horodatage et ne bloque plus personne. `date_debut` autorise par ailleurs deux
adhésions en durée de débuts différents.

⚠️ **Ordre de migration impératif** : créer le nouvel index **avant** de supprimer l'ancien.
L'ancien sert de support à la clé étrangère `association_id` ; l'ordre inverse produit l'erreur
MySQL 1553.

**D8 — la ligne 26 de Notz est laissée telle quelle.** On corrige l'index, on ne supprime pas des
données historiques pour contourner un problème d'index. Avec D7 elle ne bloque plus rien, et Notz
est repris normalement.

**Mesuré en production** : **un seul** tiers porte ce doublon, **une seule** ligne est en suppression
logique dans toute la table. Le cas est isolé, pas le premier d'une série.

## 5. Ce qui change

| Fichier | Changement |
|---|---|
| [`AdhesionService::computeDatesEtExercice()`](../../app/Services/AdhesionService.php#L197) | Chaque branche rend `exercice = exerciceFromDate($dateDebut)` au lieu de `null`. |
| [`AdhesionService::creerDepuisWizard()`](../../app/Services/AdhesionService.php#L319) | Idem sur les quatre branches de calcul. |
| [`AdhesionService::findExistingAdhesion()`](../../app/Services/AdhesionService.php#L257) | Ne branche plus sur la nullité de l'exercice mais sur le **mode de la formule** : dates pour les formules en durée et illimitées, exercice pour le mode exercice. |
| `guardAgainstOverlap()` | Même traitement : la garde de doublon doit suivre la même règle, sinon elle refusera une seconde adhésion légitime sur la même saison. |
| [`AdherentList.php:102`](../../app/Livewire/AdherentList.php#L102) | Tri par `COALESCE(date_fin, '9999-12-31') DESC`, puis exercice, puis id. |
| [`AdherentList.php:52`](../../app/Livewire/AdherentList.php#L52) | Filtres `a_jour` et `en_retard` : la branche « dates » se compare aux **bornes de l'exercice sélectionné**, plus à `today`. |
| [`NouvelleAdhesionModal::dateFinCalculee()`](../../app/Livewire/NouvelleAdhesionModal.php#L88) | Reconnaît les dates HelloAsso et les rend. |
| `NouvelleAdhesionModal` + sa vue | Période en lecture seule quand la formule porte des dates fixes. |
| Migration de reprise | Backfill des `adhesions.exercice` nuls. |

## 6. Reprise de données

Toutes les adhésions à `exercice` nul reçoivent `exerciceFromDate(date_debut)`. La règle est
**déterministe** et les dates sont déjà correctes : aucune décision au cas par cas.

Volume connu au 2026-10-02, en production : **4 lignes** — Kohl, Salin Beneteau, Moniotte
(2026‑2027) et Notz (2025‑2026). Le volume n'a pas d'incidence sur le coût.

⚠️ Une adhésion sans `date_debut` ne peut pas être reprise. Aucune n'existe aujourd'hui ; la
migration doit néanmoins les **laisser telles quelles et les compter**, jamais deviner.

## 7. Critères d'acceptation

1. **AC‑1** — Une adhésion créée par le wizard sur la formule HelloAsso porte `exercice = 2026`,
   `date_debut = 2026-09-01`, `date_fin = 2027-08-31`. C'est le cas Moniotte.
2. **AC‑2** — Une adhésion créée par la **synchro** sur la même formule porte le même exercice.
   La synchro et la saisie manuelle ne divergent plus.
3. **AC‑3** — Les quatre modes (exercice, durée en mois, durée en jours, dates HelloAsso) et le mode
   illimité produisent tous un exercice non nul. Prouvé par mutation : rendre `null` dans une branche
   doit faire tomber un test et un seul.
4. **AC‑4** — **Deux adhésions successives d'un même tiers sur une même saison** avec une formule en
   durée restent **deux adhésions distinctes** : ni fusionnées par `findExistingAdhesion()`, ni
   refusées par `guardAgainstOverlap()`. C'est le test qui protège D2.
5. **AC‑5** — Une resynchro HelloAsso d'une commande déjà importée ne crée **pas** de doublon
   d'adhésion après la reprise.
6. **AC‑6** — Sur l'écran Adhérents, la « dernière adhésion » d'un tiers portant 2025‑2026 et
   2026‑2027 est **celle de 2026‑2027**. Le cas Moniotte.
7. **AC‑7** — Avec le sélecteur sur 2025‑2026, le filtre « à jour » retient **Mircea Notz** et
   **exclut Kohl et Salin Beneteau**. C'est le test qui prouve D3, et il échoue aujourd'hui dans les
   deux sens.
8. **AC‑8** — Dans le wizard, choisir une formule à dates fixes affiche la période imposée **et**
   une date de fin non vide ; aucun champ de saisie de date n'est proposé.
9. **AC‑9** — La migration de reprise renseigne les 4 lignes connues **y compris celle de Notz**,
   laisse intactes les adhésions sans `date_debut`, et rend leur nombre.
9bis. **AC‑9bis** — Deux adhésions d'un même tiers sur le même exercice avec des dates de début
   **différentes** coexistent en base ; avec la **même** date de début, la contrainte les refuse.
   Et une ligne en suppression logique ne bloque jamais une ligne vivante. À éprouver sous **MySQL**,
   la contrainte étant inopérante autrement.
10. **AC‑10** — Le filtre de communication « Adhérents / Exercice en cours » rend **le même nombre
    qu'avant** le correctif : 25 pour 2025‑2026. Il ne doit pas bouger — c'est le témoin de
    non-régression du chemin qui marchait.
11. **AC‑11** — Suite **complète** verte, et **suite MySQL** sur le périmètre : le tri du D4 est du
    SQL brut, et la production tourne sur **MariaDB**.

## 8. Hors scope

- **L'export des adhérents.** Envisagé au début, puis écarté : la convocation d'AG passe par le
  filtre de communication, qui fonctionne déjà et donne la bonne liste (§ 3). Aucun besoin démontré.
- **Les adhésions offertes et la communication.** `creerGratuite()`
  ([#L283](../../app/Services/AdhesionService.php#L283)) produit une adhésion **sans transaction** :
  le filtre de communication, qui lit les transactions, ne la verrait pas. Aucune n'existe en
  production aujourd'hui. À traiter le jour où une adhésion sera offerte.
- **Les formules homonymes.** La synchro crée sa formule sans regarder les noms existants : rien
  n'empêche une formule manuelle au libellé identique. Le risque est la confusion dans la liste
  déroulante, pas la collision de données — la synchro retrouve la sienne par
  `helloasso_form_slug` + `helloasso_tier_id`, et l'unicité en base porte sur ce triplet. Vérifié :
  **aucun doublon en production**.
- **La refonte de l'écran Comptabilité / Cotisations.** Ce n'est pas un vestige : c'est une vue
  filtrée de la liste universelle (`usage-filter="pour_cotisations"`), et
  `AdhesionTransactionLigneObserver` y crée l'adhésion automatiquement. Deux portes, un seul état
  final, par conception.
