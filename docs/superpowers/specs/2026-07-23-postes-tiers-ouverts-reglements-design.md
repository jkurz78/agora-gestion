# Postes tiers ouverts et règlements datés — Conception

**Date :** 23 juillet 2026

**Branche :** `feat/compta-v5`

**Statut :** conception validée, en attente de relecture du document

**Périmètre :** consultation et règlement des dettes et créances 401/411, y compris celles reportées par les à-nouveaux

## 1. Contexte et objectif

Les à-nouveaux de `compta-v5` reprennent désormais en N+1 chaque poste 401 ou 411 non lettré de N. Le moteur comptable sait retrouver le descendant AN actif d'une transaction métier et le lettrer avec son règlement. Deux lacunes fonctionnelles subsistent :

- l'utilisateur ne dispose pas d'une vue centrale des dettes et créances encore ouvertes ;
- les chemins actuels de règlement ne demandent pas systématiquement la date réelle du paiement.

L'objectif est de rendre chaque poste tiers ouvert visible et réglable depuis l'exercice courant, sans exposer la pièce technique AN. Le même traitement doit être disponible depuis un écran dédié, depuis la liste universelle des transactions et depuis le formulaire d'une transaction.

## 2. Principes fonctionnels

Un **poste tiers ouvert** est une ligne comptable non lettrée d'un compte 401 ou 411, rattachée à un tiers et active dans l'exercice affiché.

- un 401 donne lieu à l'action **Régler** ;
- un 411 donne lieu à l'action **Encaisser** ;
- une ligne issue des AN représente le poste métier d'origine dans le nouvel exercice ;
- un règlement porte sur un seul poste métier ;
- le règlement peut être total ou partiel ;
- la date réelle du règlement est obligatoire ;
- le règlement est impossible dans un exercice clôturé.

Les règlements groupés de plusieurs factures ou dépenses sont exclus de cette version.

## 3. Source unique des postes ouverts

Un service de lecture dédié `PostesTiersOuvertsService` construit les postes visibles pour l'exercice affiché. Il est l'unique source des deux écrans afin d'éviter des règles différentes entre la liste dédiée et Transactions.

Pour chaque poste, le service restitue au minimum :

- l'identifiant technique du poste actif ;
- le sens 401 ou 411 ;
- le tiers ;
- le solde restant ;
- la date d'origine ;
- le numéro de pièce comptable d'origine (`numero_piece`) ;
- la référence métier libre d'origine (`reference`) ;
- le libellé d'origine ;
- l'exercice d'origine ;
- l'indication d'un éventuel report AN.

Pour un poste reporté, les informations métier sont suivies jusqu'à la transaction racine de N par la filiation AN existante. La ligne technique de N+1 reste la cible comptable du règlement, mais elle n'est pas présentée comme une transaction métier autonome.

Toutes les lectures sont tenant-scopées. Une ligne 401/411 sans tiers est considérée comme incohérente et n'est jamais rendue réglable silencieusement.

## 4. Écran « Postes tiers ouverts »

Un écran dédié est ajouté dans la navigation Comptabilité. Il donne une vision immédiate de tous les fournisseurs et clients non soldés de l'exercice affiché.

Le tableau suit les standards visuels existants de l'application et présente :

- le type Fournisseur/Client et le compte 401/411 ;
- le tiers ;
- le solde restant ;
- la date d'origine ;
- le numéro de pièce d'origine ;
- la référence d'origine ;
- le libellé ;
- l'exercice d'origine ;
- un badge `Report AN` lorsque le poste provient d'un exercice antérieur ;
- l'action `Régler` ou `Encaisser`.

Les filtres disponibles sont le type 401/411, le tiers, l'exercice d'origine et une recherche libre sur le tiers, le numéro de pièce, la référence et le libellé. Les montants et dates restent triables selon les conventions de l'application.

Après un règlement total, le poste disparaît. Après un règlement partiel, son reliquat est actualisé immédiatement.

## 5. Intégration dans Transactions

Les transactions ordinaires de l'exercice restent affichées comme aujourd'hui.

Un poste provenant des AN apparaît en plus comme une ligne virtuelle dans l'exercice cible :

- date d'affichage : premier jour de l'exercice cible ;
- badge : `Report AN` ;
- numéro de pièce et référence : ceux de la transaction métier d'origine ;
- action : `Régler` ou `Encaisser`.

Cette ligne virtuelle n'est pas une nouvelle transaction persistée. Elle permet d'agir sur le descendant AN actif par l'intermédiaire du service métier. Les postes nés dans l'exercice affiché ne sont pas dupliqués : leur transaction ordinaire porte directement l'action.

Les boutons actuels `Marquer payé` et `Marquer reçu` ouvrent le même dialogue de règlement que l'écran dédié.

## 6. Dialogue commun de règlement

Une modale Bootstrap commune collecte :

- le montant réglé ou encaissé ;
- la date réelle du règlement ;
- le mode de paiement ;
- le compte bancaire ou de trésorerie.

Le montant est prérempli avec le solde restant et demeure modifiable jusqu'à concurrence de ce solde.

La date proposée suit cette règle :

1. aujourd'hui si cette date appartient à l'exercice affiché ;
2. sinon la borne de l'exercice la plus proche d'aujourd'hui.

La date reste toujours modifiable avant validation. Elle doit appartenir à l'exercice affiché et celui-ci doit être ouvert.

La résolution du compte de trésorerie réutilise les règles comptables existantes. L'absence d'une correspondance valide bloque l'opération avec un message en français.

## 7. Traitement comptable

Un service métier unique `PosteTiersReglementService` exécute tous les règlements, quel que soit leur point d'entrée dans l'interface.

Dans une transaction SQL, il :

1. recharge et verrouille le poste ;
2. vérifie le tenant, le tiers, le compte 401/411, l'absence de lettrage et l'exercice ;
3. valide le montant et la date ;
4. prépare la ligne à lettrer en cas de règlement partiel ;
5. génère l'écriture T2 à la date réelle ;
6. lettre la part payée avec la ligne tiers de T2 ;
7. recalcule l'état de règlement de la transaction métier d'origine.

Le générateur d'écritures de règlement reçoit explicitement la ligne tiers à lettrer. Il ne doit pas choisir de nouveau le poste après son éventuel découpage.

Le règlement d'un report AN vise le descendant AN actif. La transaction et la ligne historiques de N ne sont ni modifiées ni lettrées rétroactivement.

## 8. Règlements partiels par découpage de ligne

Le règlement partiel est géré sans table d'allocation plusieurs-à-plusieurs.

Pour un poste de 100 € réglé à hauteur de 30 € :

- la ligne ouverte existante conserve son identifiant et devient le reliquat de 70 € ;
- une ligne sœur de 30 €, du même côté débit ou crédit, est créée dans la même transaction ;
- le total de la pièce source reste donc inchangé et équilibré ;
- la ligne sœur de 30 € est lettrée avec T2 ;
- seule la ligne de 70 € demeure ouverte.

Le maintien de l'identifiant sur le reliquat est essentiel pour les AN : la filiation active continue de désigner le poste restant sans être réécrite. La ligne sœur payée référence ce reliquat par une clé nullable `poste_tiers_parent_id` sur `transaction_lignes`. Cette filiation interne ne remplace ni la filiation AN ni le lettrage ; elle identifie sans ambiguïté le poste métier auquel restituer une part annulée.

En cas de règlements partiels successifs, chaque part payée est traçable séparément. Le service de lecture regroupe les éventuelles fractions encore ouvertes appartenant au même poste métier et présente un seul solde restant à l'utilisateur.

La transaction métier conserve l'état `En attente` tant qu'un reliquat existe. Elle devient `Reçu/Réglé` lorsque le poste est intégralement lettré, puis suit le fonctionnement existant du pointage bancaire.

## 9. Formulaire de création et d'édition d'une transaction

Les choix `Paiement effectué ?` et `Paiement déjà reçu ?` utilisent également le service commun.

### 9.1 Création

Lorsque l'utilisateur choisit `Oui`, le formulaire affiche la date de règlement, le mode et le compte bancaire. La date de règlement est indépendante de la date de la facture ou de la dépense et suit la règle de préremplissage de la modale commune.

La sauvegarde crée d'abord la transaction métier puis son règlement à la date saisie. Le choix `Non` crée uniquement la dette ou la créance ouverte.

### 9.2 Édition d'un poste ouvert

Passer de `Non` à `Oui` demande les mêmes informations et enregistre le règlement via le service commun. Le formulaire ne se contente plus de changer `mode_paiement` ou `statut_reglement`.

### 9.3 Poste partiellement ou totalement réglé

Un poste partiellement réglé affiche `Partiellement réglé — reste X €` et propose une action pour régler le reliquat. Le booléen Oui/Non n'est plus utilisé pour masquer cet état.

Un poste totalement réglé affiche un état dérivé `Payé` ou `Reçu`. Il ne peut pas être repassé silencieusement à `Non`.

## 10. Annulation d'un règlement

L'annulation est une action comptable explicite, distincte de l'édition de la transaction. Elle passe par une modale Bootstrap de confirmation.

Elle est refusée si l'écriture de règlement est déjà rapprochée, remise en banque ou autrement verrouillée par un flux comptable existant.

Dans une transaction SQL, l'annulation :

- identifie l'écriture T2 et la part tiers correspondante ;
- force-delete la T2 non rapprochée et ses lignes, conformément au mécanisme de réversion existant ;
- retire le lettrage concerné ;
- restitue le montant au solde ouvert du même poste métier ;
- recalcule l'état de règlement de la transaction d'origine.

Si le reliquat parent est encore non lettré, le montant annulé lui est ajouté et la ligne sœur est supprimée. Si le parent a été soldé entre-temps, la ligne sœur est simplement délettrée et redevient une fraction ouverte rattachée au même poste métier. Dans les deux cas, la restitution à l'écran regroupe les fractions ouvertes et demeure un poste unique portant le solde ouvert total.

## 11. Validations et erreurs

Le règlement est bloqué si :

- le poste n'existe plus ou a été réglé par un autre utilisateur ;
- la ligne n'appartient pas au tenant courant ;
- le compte n'est ni 401 ni 411 ;
- le tiers est absent ;
- le montant est nul, négatif ou supérieur au solde restant ;
- la date est hors de l'exercice affiché ;
- l'exercice est clôturé ;
- le compte de trésorerie ne peut pas être résolu ;
- la future écriture T2 ne serait pas équilibrée.

Le verrouillage pessimiste du poste et la transaction SQL empêchent deux validations simultanées de consommer le même solde. Une erreur ne laisse ni ligne découpée, ni T2 isolée, ni lettrage partiel.

## 12. Périmètre exclu

Cette version n'inclut pas :

- un règlement unique affecté à plusieurs transactions ;
- une interface d'allocation manuelle d'un paiement ;
- la compensation entre plusieurs dettes et créances ;
- une refonte générale des avoirs ;
- la modification des axes analytiques opération/séance ;
- l'affichage direct ou l'édition des pièces techniques AN.

## 13. Tests automatisés

Le développement suit un cycle TDD et couvre au minimum :

- lecture des postes 401 et 411 de l'exercice ;
- restitution du tiers, de la date, du numéro de pièce et de la référence d'origine ;
- résolution de la transaction racine pour un report AN ;
- absence de doublon entre transaction courante et ligne virtuelle ;
- rendu et filtres de l'écran dédié ;
- visibilité et action du report AN dans Transactions ;
- règlement total d'un 401 et d'un 411 ;
- règlement partiel et conservation du reliquat ;
- règlements partiels successifs ;
- T2 datée avec la date réellement saisie ;
- refus d'une date hors exercice ou d'un exercice clôturé ;
- refus d'un montant invalide ;
- verrouillage concurrent du même poste ;
- isolation stricte entre associations ;
- synchronisation du statut de la transaction métier d'origine ;
- disparition d'un poste totalement soldé ;
- reprise du seul reliquat lors de la clôture suivante ;
- absence de nouvelle reprise après règlement total ;
- utilisation du service commun par les boutons Transactions ;
- comportement des choix de paiement à la création et à l'édition ;
- affichage de l'état partiellement réglé ;
- annulation d'un règlement non rapproché ;
- refus d'annulation d'une écriture rapprochée ou verrouillée ;
- rollback complet en cas d'échec.

## 14. Recette fonctionnelle

La recette manuelle vérifie les scénarios suivants :

1. créer une créance 411 non encaissée et une dette 401 non réglée ;
2. constater leur présence sur l'écran dédié et dans Transactions ;
3. clôturer l'exercice et retrouver les deux postes en N+1 avec le badge `Report AN` ;
4. vérifier la date, le numéro de pièce et la référence d'origine ;
5. encaisser partiellement la créance à une date choisie ;
6. vérifier le reliquat et l'écriture comptable T2 ;
7. solder le reliquat et constater la disparition du poste ;
8. régler totalement la dette depuis Transactions ;
9. vérifier que les pièces historiques de N sont inchangées ;
10. annuler un règlement non rapproché et constater la réouverture du poste ;
11. rapprocher une écriture de règlement et vérifier que son annulation est refusée.
