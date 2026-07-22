# À-nouveaux comptables — Conception

**Date :** 22 juillet 2026

**Branche :** `feat/compta-v5`

**Statut :** conception validée

**Périmètre :** génération, invalidation et reprise initiale des écritures d'à-nouveaux

## 1. Contexte et objectif

La comptabilité en partie double de `compta-v5` stocke les mouvements dans `transactions` et `transaction_lignes`, mais ne génère pas encore d'écritures d'à-nouveaux lors de la clôture annuelle. Les valeurs historiques `solde_initial` des comptes bancaires restent en dehors du grand livre.

La balance et le grand livre paramétrables demandés pour `compta-v5` doivent reposer sur des soldes d'ouverture comptablement équilibrés. Le mécanisme d'à-nouveaux est donc un prérequis distinct, à livrer avant ces deux états.

L'objectif est de créer au premier jour de N+1 une vraie pièce comptable AN, équilibrée, auditable et issue de la clôture de N. Les comptes de bilan sont repris ; les comptes de charges et produits repartent explicitement à zéro.

## 2. Constat sur les données existantes

L'audit en lecture seule de la base locale du 22 juillet 2026 montre :

- les ENL du 31 août 2025 forment des écritures équilibrées, avec 860,00 € au débit et au crédit ;
- elles ont mouvementé les comptes 401, 411 et 512 ;
- ces ENL sont enregistrées avec `type_ecriture = normale`, et non comme à-nouveaux ;
- le 401 présente actuellement un solde créditeur de 180,00 € ;
- le 411 est actuellement soldé ;
- les soldes initiaux bancaires de 2 388,82 € et 24 010,00 € restent stockés hors grand livre ;
- les 173 lignes du 401 et les 194 lignes du 411 ne portent ni `operation_id` ni séance ; l'axe analytique est porté par les lignes 6 et 7.

Ces constats imposent une reprise initiale contrôlée et interdisent l'addition naïve de tous les exercices dès lors que des AN sont présents.

## 3. Périmètre

### 3.1 Inclus

- journal comptable dédié `AN` ;
- génération d'une pièce AN unique et équilibrée par exercice ;
- aperçu des AN dans l'assistant de clôture ;
- reprise des comptes de bilan des classes 1 à 5 ;
- reprise détaillée des postes non lettrés des comptes 401 et 411 ;
- reprise agrégée des autres comptes de bilan ;
- constatation du résultat N sur 120 ou 129 dans l'ouverture de N+1 ;
- invalidation des AN lors de la réouverture de N ;
- régénération complète lors de la nouvelle clôture ;
- reprise initiale contrôlée des données historiques ;
- adaptation des soldes bancaires et du lettrage pour éviter le double comptage ;
- audit, isolation tenant, idempotence et rollback atomique.

### 3.2 Hors périmètre

- balance paramétrable et grand livre : ils feront l'objet d'une spécification et d'un plan séparés après livraison des AN ;
- bilan comptable ;
- FEC ;
- axe analytique opération/séance dans les AN ;
- reprise des classes 6 et 7 ;
- allocation du résultat après décision de l'assemblée générale ;
- refonte générale de la clôture en écritures de solde des comptes 6 et 7.

## 4. Architecture retenue

### 4.1 Une pièce AN unique

Chaque clôture de N crée une seule transaction comptable datée du premier jour de N+1. Elle porte :

- `journal = an` ;
- `type_ecriture = an` ;
- `equilibree = true` après contrôle strict ;
- une référence stable construite à partir des exercices, par exemple `AN-2025-2026` ;
- un libellé explicite indiquant l'exercice source.

La pièce contient toutes les lignes de reprise. Cette forme préserve l'équilibre global sans utiliser de compte technique entre plusieurs pièces.

Le type métier historique de `transactions.type` ne doit pas permettre à la pièce AN d'apparaître comme une recette, une dépense ou un virement opérationnel. L'implémentation introduira une valeur dédiée compatible avec le cast `TypeTransaction` et adaptera les `match` exhaustifs concernés.

### 4.2 Génération auditable

Une table tenant-scopée de générations AN relie :

- l'association ;
- l'exercice source N ;
- l'exercice cible N+1 ;
- la transaction AN créée ;
- l'origine `cloture` ou `reprise_initiale` ;
- le statut `active` ou `invalidee` ;
- l'utilisateur ayant créé ou invalidé la génération ;
- les dates de création et d'invalidation.

Une contrainte fonctionnelle garantit qu'une seule génération active existe pour un même exercice cible et une même association.

Les lignes auxiliaires 401/411 disposent d'une filiation explicite vers leur ligne source. Cette filiation ne passe pas par `transaction_ligne_affectations`, qui reste réservée à la ventilation analytique.

## 5. Calcul des lignes AN

### 5.1 Comptes repris

Le calcul s'effectue à la date de fin de N et ne retient que les comptes de classes 1 à 5. Pour les comptes agrégés, seuls les soldes non nuls produisent une ligne. Pour les 401/411, chaque poste ouvert est repris même si plusieurs postes de sens opposés donnent un solde global nul sur le compte.

- classes 1, 2, 3 et comptes de classe 4 hors 401/411 : une ligne nette par compte ;
- comptes 401 et 411 : une ligne par poste non lettré ;
- comptes de classe 5 : une ligne nette par compte, y compris chaque 512 ;
- classes 6 et 7 : aucune ligne AN.

Chaque montant net est placé au débit s'il est débiteur et au crédit s'il est créditeur. Les calculs se font en décimal au centime, sans conversion intermédiaire en nombres flottants.

### 5.2 Postes auxiliaires 401/411

Chaque poste non lettré repris conserve :

- le compte 401 ou 411 ;
- le tiers, obligatoire ;
- le libellé et la référence métier d'origine ;
- le montant ouvert au débit ou au crédit ;
- la filiation vers la ligne d'origine.

Une transaction peut être ventilée sur plusieurs opérations et séances. L'AN 401/411 ne copie donc ni `operation_id`, ni séance, ni affectation analytique. Si une analyse future des dettes ou créances par opération est nécessaire, elle sera dérivée des lignes 6/7 de la transaction source.

Lors d'un règlement en N+1, le lettrage vise le descendant AN actif du poste ouvert. Les services qui dérivent l'état de règlement de la transaction métier suivent la filiation afin de conserver le bon statut sur la transaction d'origine.

### 5.3 Résultat de l'exercice

Les classes 6 et 7 ne sont pas reprises. Leur solde net est représenté dans l'ouverture de N+1 par :

- `120 — Résultat de l'exercice (excédent)` au crédit en cas d'excédent ;
- `129 — Résultat de l'exercice (déficit)` au débit en cas de déficit.

Ces comptes système sont provisionnés automatiquement pour chaque tenant. Leur allocation ultérieure est hors périmètre.

### 5.4 Contrôle d'équilibre

Avant toute écriture, le service compare le total des débits et des crédits de l'aperçu. Un écart, même d'un centime, bloque la clôture. L'équilibre est contrôlé une seconde fois sur les lignes persistées avant la validation de la transaction SQL.

## 6. Cycle de vie

### 6.1 Clôture de N

L'assistant de clôture :

1. exécute les contrôles préalables ;
2. calcule et affiche l'aperçu AN ;
3. demande une confirmation explicite ;
4. dans une seule transaction SQL, crée la génération, la pièce AN et ses lignes, vérifie l'équilibre, puis clôture N et inscrit l'action d'audit.

Si une étape échoue, l'ensemble est annulé et N reste ouvert.

La présence de mouvements déjà saisis en N+1 produit un avertissement mais ne bloque pas la génération, puisque l'AN est daté du premier jour de l'exercice. En revanche, N+1 déjà clôturé bloque la clôture tardive de N.

### 6.2 Réouverture de N

La réouverture affiche explicitement qu'elle invalidera les AN de N+1. Après confirmation, une transaction SQL :

1. marque la génération active comme invalidée ;
2. soft-delete la transaction AN et ses lignes ;
3. rouvre N ;
4. conserve la trace de l'utilisateur, du motif et de l'horodatage.

N+1 signale alors que ses soldes d'ouverture sont temporairement indisponibles. Sa clôture est bloquée tant que N demeure ouvert.

Une nouvelle clôture de N crée une nouvelle génération complète. Les écritures invalidées ne sont ni restaurées ni modifiées silencieusement.

## 7. Soldes bancaires et rapprochement

### 7.1 Reprise du 512

Chaque compte 512 reçoit une ligne AN agrégée égale à son solde comptable de clôture.

Les opérations bancaires antérieures non pointées restent dans leur exercice d'origine et demeurent proposées au rapprochement de N+1. Elles ne sont pas dupliquées dans les AN. La ligne AN elle-même est exclue des candidats au rapprochement.

### 7.2 Calcul des soldes après introduction des AN

Les services de solde bancaire deviennent sensibles à l'exercice :

- AN actif au début de l'exercice ;
- plus mouvements du même exercice jusqu'à la date demandée.

Ils ne doivent plus additionner tous les exercices contenant chacun leurs AN. Après reprise initiale, `solde_initial` ne doit plus être ajouté aux calculs comptables du grand livre.

Le rapprochement continue à s'appuyer sur le dernier rapprochement verrouillé et les anciennes opérations non pointées. Il ignore les écritures du journal AN pour la sélection des opérations pointables.

## 8. Reprise initiale

### 8.1 Principe

La première pièce AN de l'exercice courant n'est pas créée silencieusement dans une migration de schéma. Une commande métier dédiée exécute :

1. un audit obligatoire en mode dry-run ;
2. un aperçu détaillé ;
3. une confirmation explicite ;
4. la création atomique de la génération d'origine `reprise_initiale` et de sa pièce AN.

La commande est idempotente et refuse une seconde génération active pour le même exercice cible.

### 8.2 Sources de la reprise

Le bootstrap reconstitue le solde au premier jour de l'exercice courant à partir :

- des écritures antérieures disponibles ;
- des postes 401/411 non lettrés à cette date ;
- des anciens `solde_initial` bancaires et de leur date de référence.

Lorsque des mouvements existent le même jour que `date_solde_initial`, l'outil affiche l'ambiguïté et refuse de comptabiliser tant qu'une règle explicite d'inclusion ou d'exclusion n'a pas été confirmée. Ce contrôle couvre les ENL du 31 août 2025 présentes dans la base actuelle.

La contrepartie patrimoniale nécessaire à la reprise des soldes historiques hors grand livre est portée sur `102 — Fonds associatifs sans droit de reprise`, provisionné comme compte système. Elle ne passe pas par 120/129, car elle ne représente pas le résultat de l'exercice courant.

## 9. Interface utilisateur

L'aperçu AN de l'assistant de clôture présente :

- les dates et exercices source/cible ;
- les totaux débit et crédit ;
- l'écart, nécessairement nul pour continuer ;
- les lignes agrégées des comptes de bilan ;
- les postes 401/411 regroupés par tiers, avec détail dépliable ;
- la ligne 120 ou 129 ;
- les avertissements non bloquants ;
- les erreurs bloquantes en français.

La reprise initiale présente les mêmes informations en ligne de commande, avec un tableau spécifique comparant les anciens soldes bancaires, les mouvements à la date de référence et le montant proposé pour l'AN.

## 10. Contrôles bloquants

La génération est refusée si :

- le grand livre de N n'est pas équilibré ;
- une ligne comptable nécessaire est orpheline ou supprimée ;
- un poste 401/411 non lettré n'a pas de tiers ;
- un ensemble de lettrage utilisé pour calculer les postes ouverts est incohérent ;
- l'aperçu AN n'est pas équilibré ;
- une génération active existe déjà pour la cible ;
- l'exercice cible est clôturé ;
- le tenant courant n'est pas booté ;
- le bootstrap rencontre une ambiguïté bancaire non validée.

Toutes les écritures et requêtes brutes incluent explicitement `association_id` via `TenantContext`.

## 11. Tests attendus

Le développement suit un cycle TDD et couvre au minimum :

- calcul des soldes des classes 1 à 5 ;
- absence totale de classes 6 et 7 dans les AN ;
- absence de `transaction_ligne_affectations` sur les AN ;
- résultat excédentaire sur 120 et déficitaire sur 129 ;
- équilibre exact au centime ;
- détail des 401/411 par tiers et poste ouvert ;
- refus d'un poste auxiliaire sans tiers ;
- filiation et lettrage d'un poste repris en N+1 ;
- statut correct de la transaction métier d'origine après règlement ;
- reprise agrégée du 512 ;
- maintien des anciennes opérations bancaires non pointées ;
- exclusion des AN des candidats au rapprochement ;
- absence de double comptage entre `solde_initial`, AN et historique ;
- clôture atomique et rollback complet ;
- idempotence ;
- réouverture, invalidation et nouvelle clôture ;
- blocage de la clôture N+1 lorsque N est rouvert ;
- isolation stricte entre deux tenants ;
- dry-run et confirmation de la reprise initiale ;
- ambiguïté des ENL présentes à la date du solde initial ;
- rendu de l'aperçu dans l'assistant Livewire.

## 12. Séquencement

1. Implémenter et valider les à-nouveaux décrits dans ce document.
2. Exécuter la reprise initiale contrôlée sur les données `compta-v5`.
3. Vérifier balance d'ouverture, lettrage et rapprochement bancaire.
4. Concevoir et implémenter la balance paramétrable.
5. Concevoir et implémenter le grand livre.
6. Traiter ultérieurement bilan et FEC.
