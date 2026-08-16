# Opérations — imputations et compte de résultat multi-exercices

**Date :** 2026-08-14
**Révision :** 2026-08-16
**Statut :** spécification fonctionnelle et technique à relire
**Périmètre :** saisie manuelle des dépenses/recettes et compte de résultat par opérations

## 1. Résumé de la décision

AgoraGestion doit permettre d'imputer manuellement une dépense ou une recette sur toute
opération au statut **En cours**, même si les dates de l'opération ne chevauchent pas
l'exercice comptable affiché. Une opération **Clôturée** n'est plus proposée dans les
sélecteurs de saisie, mais son statut n'interdit aucune imputation : c'est un filtre
d'ergonomie, pas une règle métier.

Le compte de résultat par opérations doit pouvoir afficher :

- soit les montants de l'**exercice affiché** dans AgoraGestion, comportement par défaut ;
- soit les montants de **tous les exercices** des opérations sélectionnées, ventilés en
  lignes par exercice puis, si l'option est active, par tiers.

Le sélecteur du rapport ne liste pas toutes les opérations du tenant. Il ne liste que les
opérations qui possèdent au moins un mouvement de charge ou de produit dans l'exercice
affiché. Le mode « Tous les exercices » élargit ensuite la période des opérations
sélectionnées sans élargir cette liste d'opérations.

Le rattachement des mouvements et des prévisions à un exercice, ainsi que les libellés
affichés, utilisent `ExerciceService`, source de vérité tenant-aware.

## 2. Contexte et problème actuel

### 2.1 Imputation

`TransactionForm::render()` charge aujourd'hui les opérations avec deux restrictions :

1. `Operation::forExercice(ExerciceService::current())` : les dates de l'opération doivent
   chevaucher l'exercice affiché ;
2. `statut = en_cours`.

La première restriction empêche de rattacher une transaction de l'exercice affiché à une
opération pluriannuelle, ancienne ou administrativement datée sur une autre période. La
transaction porte pourtant sa propre date : c'est cette date, et non la période de
l'opération, qui détermine son exercice comptable.

Le filtre de période existe aussi dans la liste d'opérations fournie au moteur OCR des
factures fournisseur.

### 2.2 Rapport par opérations

`RapportCompteResultatOperations` :

- construit son sélecteur avec les seules opérations dont les dates chevauchent l'exercice
  affiché ;
- calcule exclusivement les mouvements de cet exercice ;
- ne porte aucune dimension « exercice » dans les lignes du rapport ;
- propage seulement l'exercice affiché aux exports PDF et Excel.

Une même opération peut désormais recevoir des transactions sur plusieurs exercices. Le
rapport doit rendre ces montants visibles sans perdre les axes existants : comptes,
séances, tiers, opérations en colonnes, réalisé et projection.

## 3. Terminologie normative

Dans cette spécification :

- **Exercice affiché** : valeur retournée par `ExerciceService::current()`. Elle peut venir
  de la session `exercice_actif` et n'est donc pas nécessairement l'exercice de la date du
  jour.
- **Opération imputable manuellement** : opération du tenant courant dont le statut est
  `StatutOperation::EnCours`. Ses dates et l'état actif/inactif de son type n'interviennent
  pas.
- **Mouvement de résultat** : montant réel porté par un compte de classe 6 ou 7, lu selon
  les mêmes règles que le compte de résultat. Une ligne de classe 4 ou 5 n'est pas un
  mouvement de résultat.
- **Imputation directe** : `transaction_lignes.operation_id`.
- **Affectation ventilée** : `transaction_ligne_affectations.operation_id`. Lorsqu'une
  ligne possède des affectations, le rapport utilise les affectations et ne recompte pas
  le montant direct de la ligne parente.
- **Tous les exercices** : tous les exercices comportant un mouvement réel de résultat
  pour les opérations sélectionnées, sans dépendre de l'existence d'une ligne dans la
  table `exercices`.
- **Exercice non déterminé** : groupe réservé aux prévisions dont la séance n'a pas de
  date. La clé interne recommandée est `0`, qui ne peut pas entrer en collision avec une
  année d'exercice valide.

## 4. Règles fonctionnelles — imputation manuelle

### IMP-01 — Suppression de la contrainte de période

Dans tous les parcours où l'utilisateur choisit explicitement une opération pour une
dépense ou une recette, la liste contient toutes les opérations imputables manuellement,
sans `forExercice()` et sans comparaison de `date_debut` ou `date_fin`.

Une opération passée, présente ou future peut donc être choisie tant qu'elle est au statut
`En cours`.

### IMP-02 — Le statut clôturé filtre les propositions, il n'interdit rien

Le statut `Clôturée` retire une opération des **listes proposées**. Il ne constitue pas une
règle serveur et ne fait échouer aucune écriture.

La ligne de partage n'est pas « manuel contre automatique » — l'OCR est les deux — mais
**qui choisit l'opération** :

- **l'opération est choisie** (un humain, ou un modèle dont un humain valide la
  proposition) : les opérations clôturées ne sont pas proposées ;
- **l'opération est héritée d'un lien métier préexistant** : le rattachement s'applique
  sans condition, quel que soit le statut.

La réalité comptable est têtue : une écriture qui arrive déjà rattachée à une opération —
synchronisation HelloAsso, règlement tardif d'un participant, écriture issue d'un règlement
d'opération — ne peut pas être refusée. Personne n'a choisi cette opération, elle était
déjà là.

Pour imputer délibérément sur une opération clôturée, le comptable la rouvre, impute, puis
la reclôture. Cette bascule existe déjà (`OperationDetail`) et ce chantier n'y touche pas ;
elle est en revanche une **dépendance** de ce parcours et ne doit pas être retirée.

### IMP-04 — Parcours où l'opération est choisie

Le filtrage des opérations clôturées s'applique au minimum à :

- `TransactionForm`, pour les lignes directes ;
- la modale de ventilation de `TransactionForm` ;
- `InvoiceOcrService`, pour la liste fournie au prompt ;
- les lignes de facture où l'utilisateur choisit une opération ;
- les lignes de note de frais où l'utilisateur choisit une opération.

L'OCR figure dans cette liste : le modèle **choisit** une opération, un humain valide. Lui
fournir l'historique complet des opérations clôturées gonflerait son contexte et
permettrait d'imputer sur une opération clôturée sans que personne ait décidé de la
rouvrir.

Tout autre sélecteur découvert pendant l'implémentation utilise la même source et ne
recrée pas sa propre condition sur le statut ou les dates.

### IMP-06 — Isolation tenant

Une opération soumise doit appartenir au tenant courant. Les validations SQL de type
`exists` qui ne bénéficient pas du scope Eloquent doivent inclure explicitement
`association_id = TenantContext::currentId()`.

## 5. Règles fonctionnelles — sélecteur du rapport

### SEL-01 — Éligibilité par mouvement courant

Le sélecteur du compte de résultat par opérations contient uniquement les opérations qui
ont au moins un mouvement réel de résultat dans l'exercice affiché.

Le mouvement peut provenir :

- d'une ligne directe de classe 6 ou 7 ;
- d'une affectation ventilée dont le compte parent est de classe 6 ou 7.

Le calcul exclut :

- transactions et lignes supprimées ;
- lignes techniques de classes 4 et 5 ;
- mouvements hors de l'exercice affiché ;
- données d'un autre tenant.

### SEL-02 — Dates et statuts de l'opération sans effet sur le rapport

Une fois SEL-01 satisfaite :

- une opération clôturée reste consultable ;
- une opération dont le type est inactif reste consultable ;
- les dates de début et fin de l'opération ne sont pas consultées.

À l'inverse :

- une opération future sans mouvement dans l'exercice affiché est absente ;
- une opération clôturée lors d'un exercice précédent sans mouvement dans l'exercice
  affiché est absente ;
- une opération en cours sans mouvement dans l'exercice affiché est absente.

### SEL-03 — Arbre de sélection

Conserver la présentation hiérarchique actuelle par compte puis type d'opération, mais
construire l'arbre à partir des opérations éligibles plutôt qu'à partir de
`TypeOperation::actif()->operations()->forExercice()`.

Conséquences :

- ne pas filtrer les types inactifs ;
- charger `typeOperation.compte` avec les opérations éligibles pour éviter les N+1 ;
- prévoir les libellés de repli existants si un compte ou un type est absent ;
- conserver l'ordre alphabétique actuel des groupes, types et opérations.

### SEL-04 — Sélection transmise par URL

Les `selectedOperationIds` reçus par l'URL ne sont jamais considérés comme fiables. Avant
le calcul écran ou export, les normaliser en entiers uniques puis les intersecter avec les
identifiants éligibles de SEL-01.

Si tous les identifiants sont écartés :

- l'écran affiche l'état « Sélectionnez au moins une opération » et peut signaler que la
  sélection n'est plus disponible pour l'exercice affiché ;
- l'export répond avec une erreur de validation française (HTTP 422), sans produire un
  document vide ou exposer une autre opération.

Le passage en mode « Tous les exercices » ne modifie jamais l'éligibilité SEL-01.

## 6. Règles fonctionnelles — portée et ventilation par exercice

### EX-01 — Contrôle de portée

Ajouter un contrôle à deux valeurs dans la barre de filtres :

- **Exercice affiché** — valeur par défaut ;
- **Tous les exercices**.

L'état est porté dans l'URL, avec une valeur stable telle que `exercices=current|all`.
Une valeur inconnue retombe sur `current`.

Le contrôle est unique : choisir `all` élargit la période et active automatiquement les
lignes d'exercice. Il n'existe pas de second toggle « Exercices en lignes ».

### EX-02 — Portée « Exercice affiché »

Le réalisé est limité à `ExerciceService::dateRange(ExerciceService::current())`.

En mode projection :

- les prévisions datées sont limitées aux séances appartenant à ce même exercice ;
- les prévisions de séances non datées restent visibles sous « Exercice non déterminé » ;
- une prévision d'un autre exercice ne doit pas influencer le montant projeté courant.

L'affichage courant reste agrégé comme aujourd'hui et n'ajoute pas une ligne redondante
pour l'unique exercice daté. Si des prévisions non datées existent, elles apparaissent dans
une sous-ligne distincte « Exercice non déterminé » sous le compte ; elles ne sont jamais
fondues silencieusement dans le montant de l'exercice affiché. Le total du compte additionne
alors le montant courant et cette sous-ligne explicite.

### EX-03 — Portée « Tous les exercices »

Pour les opérations sélectionnées :

1. lire tous les mouvements réels de résultat, sans borne de date ;
2. calculer leur exercice depuis `transactions.date` avec
   `ExerciceService::anneeForDate()` ;
3. construire la liste des exercices à partir de ces mouvements réels ;
4. en mode projection, y ajouter les exercices portant une prévision, datée par
   `seances.date` ;
5. trier les exercices du plus récent au plus ancien.

Le point 4 est le cœur du mode projection : une opération pluriannuelle dont la troisième
année est planifiée mais non commencée doit montrer cette troisième année. Construire la
liste sur les seuls mouvements réels la rendrait invisible, c'est-à-dire viderait la
projection de son objet.

En mode réalisé, la liste reste strictement celle des mouvements réels.

Il ne faut pas :

- utiliser les dates de l'opération pour déduire les exercices ;
- limiter la liste aux enregistrements présents dans la table `exercices` ;
- créer des lignes d'exercice sans montant réel ni prévisionnel.

### EX-04 — Prévisions et projections multi-exercices

Le prévisionnel est affecté à l'exercice de `seances.date` via
`ExerciceService::anneeForDate()`.

- Une prévision datée est incluse, et son exercice entre dans la liste même s'il ne porte
  aucun mouvement réel (EX-03 point 4).
- Une prévision non datée est incluse dans « Exercice non déterminé ».
- « Exercice non déterminé » vient après tous les exercices datés et n'apparaît que si un
  montant prévisionnel/projeté non daté existe.

La règle de projection existante est conservée : au grain le plus fin, utiliser le réalisé
s'il existe, sinon le prévu. Elle doit toutefois être appliquée **séparément par exercice**.
Un réalisé de 2025-2026 ne doit pas masquer une prévision de 2024-2025, ni inversement.

### EX-05 — Hiérarchie des lignes

En portée `all`, la hiérarchie sous chaque compte est :

```text
Famille
└── Compte
    ├── Exercice le plus récent
    │   ├── Tiers A       (si « Tiers en lignes » est actif)
    │   ├── Tiers B
    │   └── Total exercice
    ├── Exercice précédent
    │   └── …
    ├── Exercice non déterminé (si nécessaire)
    └── Total compte
```

Sans ventilation par tiers, chaque exercice occupe une seule ligne sous le compte. Le total
du compte couvre exactement les exercices affichés.

Les lignes de famille, les totaux Dépenses/Recettes et le résultat net couvrent la même
portée. Aucun total global ne doit inclure silencieusement un exercice absent des lignes.

### EX-06 — Compatibilité avec les axes existants

La dimension exercice doit fonctionner avec :

- `parSeances` activé ou non ;
- `parTiers` activé ou non ;
- `parOperations` activé ou non ;
- le mode `realise` ;
- le mode `projection` ;
- le mode combiné séances × opérations.

L'exercice est une dimension de **ligne**. Il ne remplace ni ne réordonne les colonnes de
séances ou d'opérations.

## 7. Calcul de la dimension exercice

### CAL-01 — Rattachement et libellés

La nouvelle dimension multi-exercices réutilise l'`ExerciceService` déjà injecté dans le
builder :

- `dateRange(int $exercice)` pour limiter la portée `current` ;
- `anneeForDate(CarbonImmutable|Carbon $date)` pour rattacher une transaction ou une
  séance à son exercice ;
- `label(int $exercice)` pour les libellés de l'écran et des exports.

Seule exception : la clé `0` de l'« Exercice non déterminé » n'est pas une année et ne
passe jamais par `label()`, qui produirait un libellé absurde. Son intitulé est littéral.

La vue et le contrôleur d'export ne recalculent pas l'exercice d'une date.

### CAL-02 — Aucun changement de schéma

La table `exercices` stocke une année et un statut, tandis que le mois de début appartient
à l'association. Ce chantier n'ajoute pas `date_debut` ou `date_fin` à la base. Les dates
restent calculées par `ExerciceService` à partir du paramétrage du tenant.

## 8. Conception technique recommandée

### 8.1 Règle d'imputation réutilisable

Ajouter sur `Operation` un scope nommé explicitement, par exemple
`scopeProposableALaSaisie(Builder $query)`, qui applique uniquement le statut `En cours`.
Le scope global de `TenantModel` fournit le fail-closed tenant pour les requêtes Eloquent.

Tous les parcours où l'opération est choisie utilisent ce scope pour leurs options — y
compris la liste fournie au prompt OCR.

**Aucune garde métier n'est ajoutée sur le statut.** Le statut clôturé filtre des
propositions ; il n'autorise ni ne refuse une écriture. `TransactionService::create()`,
`update()` et `affecterLigne()` restent donc inchangés sur ce point, ce qui évite au
passage de casser les flux qui héritent leur opération d'un lien métier préexistant.

La seule défense serveur qui demeure est l'isolation tenant (IMP-06) : elle n'a jamais
relevé du métier, c'est de la sécurité.

### 8.2 Éligibilité du rapport

Ajouter au moteur de rapport une requête réutilisable qui retourne les identifiants
d'opérations éligibles pour un exercice. Elle agrège l'union de :

- Q1 : lignes directes sans affectations ;
- Q2 : affectations, jointes à leur ligne et à leur compte parent.

Les deux branches appliquent les filtres de SEL-01. Utiliser `UNION` ou une déduplication
explicite : une opération ne doit apparaître qu'une fois.

Exposer cette capacité via `RapportService` afin que le composant Livewire et le contrôleur
d'export utilisent le même contrat.

### 8.3 Signature du calcul

Étendre en fin de signature les méthodes de façade et du builder avec une portée stable,
par exemple :

```php
public function compteDeResultatOperations(
    int $exercice,
    array $operationIds,
    bool $parSeances = false,
    bool $parTiers = false,
    bool $previsionnel = false,
    bool $parOperations = false,
    string $porteeExercices = 'current',
): array
```

Une enum dédiée est acceptable si elle reste sérialisable vers `current|all`. Ajouter le
paramètre en dernier préserve les appels et tests existants.

### 8.4 Lecture et agrégation du réalisé

Faire évoluer `fetchOperationRowsPD()` sans perdre son invariant Q1/Q2 :

- en portée `current`, conserver un `whereBetween()` fondé sur `dateRange()` ;
- en portée `all`, retirer la borne ;
- sélectionner la date de transaction dans les deux branches ;
- inclure la date dans le groupement SQL ;
- calculer l'année d'exercice en PHP via `ExerciceService::anneeForDate()` ;
- inclure l'exercice dans la clé d'accumulation.

Le calcul PHP évite des expressions différentes entre SQLite (tests) et MySQL
(production). L'agrégation SQL reste faite par date, compte et dimensions demandées ; elle
ne charge pas les transactions une à une.

### 8.5 Contrat de données multi-exercices

En portée `all`, le résultat ajoute au minimum :

```php
[
    'exercices' => [
        ['annee' => 2025, 'label' => '2025-2026'],
        ['annee' => 2024, 'label' => '2024-2025'],
        ['annee' => 0, 'label' => 'Exercice non déterminé'], // si nécessaire
    ],
    'charges' => [/* hiérarchie */],
    'produits' => [/* hiérarchie */],
]
```

Chaque famille et compte porte un tableau `exercices`. Chaque entrée d'exercice contient :

- `montant` ;
- les maps `seances`, `operations` et `seance_operations` si demandées ;
- les `tiers` de cet exercice, chacun avec les mêmes dimensions utiles.

La structure historique `montant`, `seances`, `operations`, `tiers` reste disponible aux
niveaux globaux afin de préserver le rendu `current` et les totaux. Les valeurs globales
sont toujours la somme exacte des entrées d'exercice retenues.

Pour la projection, l'implémentation peut conserver `ProjectionMatrix` comme détail
interne, mais doit produire des matrices ou sous-ensembles distincts par exercice. La vue
ne doit pas exécuter de requête ni déterminer elle-même l'exercice d'une date.

### 8.6 Écran Livewire

Dans `RapportCompteResultatOperations` :

- ajouter la propriété URL de portée ;
- obtenir les opérations éligibles avant de construire l'arbre ;
- normaliser la sélection avant tout calcul ;
- transmettre la portée à `RapportService` ;
- inclure la portée dans `exportUrl()` ;
- calculer les totaux depuis la structure déjà limitée, sans requête Blade.

Dans `rapport-compte-resultat-operations.blade.php` :

- ajouter le contrôle `current|all` ;
- préserver le rendu actuel en `current` ;
- rendre la hiérarchie EX-05 en `all` ;
- recalculer les `colspan` avec les axes existants ;
- conserver les conventions de tableau du projet ;
- afficher des tirets pour les zéros comme aujourd'hui.

### 8.7 Exports

`RapportExportController` lit et valide le paramètre de portée, normalise les opérations
avec le même service que l'écran, puis appelle le même calcul.

Excel :

- ajouter une colonne de ligne `Exercice` en portée `all` ;
- produire l'ordre `Type, Famille, Compte, Exercice, Tiers éventuel`, puis les colonnes de
  séances/opérations et le total ;
- inclure les sous-totaux d'exercice et de compte ;
- conserver le format numérique et les en-têtes existants.

PDF :

- reproduire la hiérarchie écran ;
- conserver l'orientation paysage ;
- ajouter un sous-titre de portée ;
- éviter toute divergence de calcul avec Excel ou l'écran.

Sous-titres attendus :

```text
Exercice affiché : <label ExerciceService>
Tous les exercices
```

## 9. Fichiers directement concernés

### Domaine et saisie

- `app/Models/Operation.php`
- `app/Livewire/TransactionForm.php`
- `resources/views/livewire/transaction-form.blade.php`
- `app/Services/TransactionService.php`
- `app/Services/InvoiceOcrService.php`
- `app/Livewire/FactureEdit.php`
- `resources/views/livewire/facture-edit.blade.php`
- `app/Livewire/Portail/NoteDeFrais/Form.php`
- vue Livewire correspondante de la note de frais

### Rapport

- `app/Services/Rapports/CompteResultatBuilder.php`
- `app/Services/RapportService.php`
- `app/Livewire/RapportCompteResultatOperations.php`
- `resources/views/livewire/rapport-compte-resultat-operations.blade.php`
- `app/Http/Controllers/RapportExportController.php`
- `resources/views/pdf/rapport-operations.blade.php`

Cette liste est un minimum. Une recherche globale des sélecteurs manuels d'opération et
des appels à `compteDeResultatOperations()` est obligatoire avant modification.

## 10. Gestion des erreurs et états vides

- Aucune opération éligible : afficher un message expliquant qu'aucun mouvement de
  résultat n'existe sur l'exercice affiché.
- Opérations éligibles mais aucune sélection : conserver l'invite de sélection.
- Sélection URL devenue inéligible : ignorer les identifiants et afficher une information
  non bloquante.
- Identifiant cross-tenant soumis à une imputation : erreur de validation, sans révéler
  l'existence de l'opération. Le statut clôturé, lui, ne produit aucune erreur.
- Portée URL inconnue : repli sur `current`.
- Export sans opération valide : HTTP 422 avec message français.
- Prévision sans date : groupe explicite « Exercice non déterminé », jamais rattachement
  arbitraire à l'exercice affiché.

## 11. Exigences de performance et d'isolation

- Le nombre de requêtes principales du rapport ne doit pas croître avec le nombre
  d'exercices affichés. Il ne faut pas rappeler le rapport une fois par exercice.
- Les relations de l'arbre d'opérations sont chargées avec eager loading.
- Les lectures SQL brutes appliquent `TenantContext::currentId()` et restent fail-closed si
  le contexte n'est pas booté.
- Les branches Q1/Q2 continuent d'empêcher le double comptage des lignes ventilées.
- Le calcul ne doit pas charger toutes les transactions Eloquent en mémoire ; il agrège en
  SQL au grain date + dimensions, puis rattache les lignes agrégées aux exercices en PHP.
- Aucun cache inter-tenant n'est introduit. Si un cache est ajouté ultérieurement, sa clé
  inclura `association_id`, exercice affiché, portée, opérations et toggles.

## 12. Critères d'acceptation

### Saisie

1. Étant donné une opération `En cours` datée sur un exercice antérieur, quand un
   utilisateur saisit une dépense dans l'exercice affiché, alors l'opération est proposée
   et l'imputation est enregistrée.
2. Le même comportement vaut pour une recette et une affectation détaillée.
3. Une opération `Clôturée` n'est proposée dans aucun sélecteur de saisie, ni dans la
   liste fournie au moteur OCR.
4. Une écriture arrivant déjà rattachée à une opération clôturée — synchronisation, ou
   règlement issu d'un lien métier — est enregistrée sans être refusée.
5. Une opération rouverte redevient proposée, et le redevient à nouveau indisponible après
   reclôture.

### Sélecteur du rapport

6. Une opération avec une ligne directe de classe 6 ou 7 dans l'exercice affiché apparaît.
7. Une opération avec une affectation de classe 6 ou 7 dans l'exercice affiché apparaît.
8. Une opération ayant seulement une ligne de classe 4/5, un mouvement hors exercice ou
   une donnée supprimée n'apparaît pas.
9. Le statut clôturé, les dates de l'opération et l'état du type ne masquent pas une
   opération qui satisfait le critère de mouvement.
10. Une opération non éligible transmise dans l'URL ou à l'export ne produit aucun rapport.

### Rapport multi-exercices

11. Par défaut, seuls les montants de l'exercice affiché sont calculés.
12. En portée `all`, tous les exercices comportant un mouvement réel des opérations
    sélectionnées sont affichés, du plus récent au plus ancien.
13. Aucun exercice sans montant réel ni prévisionnel n'est ajouté. En mode projection,
    un exercice portant uniquement une prévision est présent ; en mode réalisé, non.
14. Les montants réels sont rattachés selon la date de transaction, pas selon les dates de
    l'opération.
15. Les prévisions sont rattachées selon la date de séance ; les séances non datées sont
    regroupées sous « Exercice non déterminé ».
16. Avec les tiers actifs, la hiérarchie est `Compte → Exercice → Tiers → Total compte`.
17. Les sommes des exercices égalent les totaux de compte, famille, section et résultat.
18. Aucun réalisé ou prévu n'est compté deux fois à cause des affectations.
19. Les combinaisons séances, tiers, opérations en colonnes, réalisé et projection restent
    fonctionnelles.
20. Écran, Excel et PDF retournent les mêmes montants et la même portée.

### Paramétrage des exercices

21. Le rattachement des transactions et séances dans la nouvelle dimension respecte le
    calendrier du tenant retourné par `ExerciceService`, pour un exercice civil comme pour
    un exercice décalé.

## 13. Stratégie de tests attendue

### Tests unitaires et service

- Scope de proposition : en cours inclus, clôturée exclue, dates sans effet, tenant isolé.
- Écriture héritant d'un lien métier vers une opération clôturée : enregistrée sans erreur.
- Isolation tenant : une opération d'une autre association reste refusée quel que soit le
  parcours.
- Éligibilité du sélecteur : Q1 direct, Q2 affectation, classes 6/7, classes 4/5,
  soft-deletes, tenant et bornes.
- `CompteResultatBuilder` : agrégation par exercice, tiers, séance, opération et absence de
  double comptage.
- Projection : séparation des exercices, inclusion d'un exercice uniquement prévisionnel,
  séance non datée.
- Dimension exercice : jeux de données multi-exercices avec un calendrier civil et un
  calendrier décalé, afin de vérifier `anneeForDate()` et les libellés.

### Tests Livewire

- Remplacer les attentes actuelles de `TransactionFormOperationsFilterTest` : une
  opération hors exercice mais en cours devient visible ; une clôturée reste absente.
- Arbre du rapport limité aux opérations ayant un mouvement courant, y compris opération
  clôturée et type inactif.
- Valeur URL `current|all`, repli d'une valeur invalide et propagation à l'export.
- Rendu `Exercice → Tiers` avec au moins deux exercices et deux tiers.
- États vides et sélection URL devenue inéligible.

### Matrice du rapport

Au minimum, couvrir par dataset les combinaisons suivantes en portée `all` :

| Mode | Séances | Tiers | Opérations en colonnes |
| --- | --- | --- | --- |
| Réalisé | non | non | non |
| Réalisé | oui | non | non |
| Réalisé | non | oui | non |
| Réalisé | non | non | oui |
| Réalisé | oui | oui | oui |
| Projection | non | non | non |
| Projection | oui | oui | non |
| Projection | oui | oui | oui |

Les tests existants des autres combinaisons restent des tests de non-régression.

### Exports

- XLSX `current` et `all`, avec lecture du classeur pour contrôler en-têtes, exercices et
  montants ;
- PDF `current` et `all` répondant 200 ; test du tableau de données fourni à la vue ou
  extraction texte du PDF pour vérifier les libellés ;
- export avec opération inéligible : 422 ;
- parité de totaux écran/XLSX/PDF sur une fixture commune.

### Recette manuelle

1. Choisir une opération en cours datée sur un ancien exercice et lui imputer une dépense
   puis une recette dans l'exercice affiché.
2. Vérifier qu'une opération clôturée est absente des sélecteurs manuels.
3. Ouvrir le rapport : seules les opérations avec mouvement courant sont proposées.
4. Sélectionner une opération ayant des mouvements sur plusieurs exercices.
5. Comparer `current` puis `all`, avec et sans tiers, en réalisé puis projection.
6. Exporter en Excel et PDF et rapprocher les totaux avec l'écran.
7. Refaire le contrôle sur une association dont l'exercice ne commence pas en septembre.

## 14. Hors périmètre

- Modification du statut ou des dates d'une opération. La bascule ouverte/clôturée
  existante (`OperationDetail`) reste toutefois une **dépendance** du parcours manuel
  décrit en IMP-02 : elle ne doit pas être retirée.
- Ajout d'une liste permettant de choisir individuellement plusieurs exercices : le choix
  reste `current|all`.
- Affichage d'opérations sans mouvement de résultat dans le sélecteur du rapport.
- Modification fonctionnelle des flux automatiques déjà rattachés à une opération.
- Migration ou backfill de données : les informations nécessaires existent déjà.
- Persistance du choix de portée dans le profil utilisateur : l'URL suffit.

## 15. Points de vigilance pour l'implémentation

- Ne pas confondre l'exercice **affiché** avec l'exercice de la date du jour.
- Ne pas confondre les dates de l'opération avec l'exercice d'une transaction.
- Ne pas réutiliser `Operation::forExercice()` pour l'imputation ou le sélecteur du rapport.
- Ne pas transformer le filtre de statut en garde serveur : une écriture héritant d'un
  lien métier doit passer même sur une opération clôturée.
- Ne pas filtrer le rapport sur `TypeOperation::actif()`.
- Ne pas autoriser un identifiant d'opération uniquement parce qu'il est présent dans le
  DOM ou l'URL.
- Ne pas compter simultanément la ligne parente et ses affectations.
- Ne pas laisser une matrice de projection globale masquer une valeur d'un autre exercice.
- Ne pas introduire de SQL spécifique à MySQL sans équivalent SQLite.
- Utiliser `ExerciceService` pour rattacher les dates à la nouvelle dimension exercice.

## 16. Définition de terminé

L'évolution est terminée lorsque :

- tous les critères d'acceptation sont couverts et verts ;
- la suite Pest complète passe sur SQLite et la suite MySQL concernée passe ;
- Pint ne signale aucune divergence ;
- les exports ont été vérifiés sur une fixture multi-exercices ;
- aucun nouveau filtre de dates d'opération ne subsiste dans les parcours d'imputation
  manuelle ciblés ;
- la documentation fonctionnelle éventuelle du rapport est mise à jour.
