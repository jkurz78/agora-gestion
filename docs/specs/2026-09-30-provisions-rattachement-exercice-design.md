# Écritures de rattachement à l'exercice — CCA / PCA, puis FNP / PAR

> **Date** : 2026-09-30, révisée le 2026-10-01 après revue externe
> **Statut** : spec validée, plan à écrire
> **Remplace** : [2026-06-18-provisions-partie-double.md](2026-06-18-provisions-partie-double.md)

## 1. Contexte

L'écran « Exercices > Écritures de provisions » existe depuis la v2.10.0 (2026-04-10) et a été
intégré à la partie double le 2026-06-18. Il n'a **jamais servi** : la table `provisions` compte
0 ligne, sur toutes les associations.

Le besoin est apparu le 2026-09-29. Un encaissement HelloAsso de 175 € du 08/04/2026
(`transaction_lignes#145`, pièce `transactions#122`) a été reclassé de « don » vers
**706B — Parcours thérapeutiques**, ventilé sur l'**opération 8 « EquiThe FE sept 2026 »**, qui
court du 18/09/2026 au 18/06/2027. Le produit est encaissé sur l'exercice 2025‑2026, la
prestation sera rendue sur 2026‑2027.

En tentant de passer la PCA correspondante, trois défauts ont été mis au jour. Une revue externe
du 2026-10-01 en a ajouté trois autres, confirmés dans le code et dans les données.

## 2. Ce que cette spec corrige

### 2.1 Le schéma d'écriture de la spec de juin est inversé

Le § 4.3 de [2026-06-18-provisions-partie-double.md](2026-06-18-provisions-partie-double.md) écrit :

> Résultat net sur N : […] la provision de recette diminue les produits nets (487 D annule une
> partie des 7xx, compensé par 781 C).

**487 est un compte de classe 4.** Le compte de résultat ne lit que les classes 6 et 7
([`CompteResultatBuilder::fetchClasseRowsPD()`](../../app/Services/Rapports/CompteResultatBuilder.php#L1195)).
Le « 487 D annule une partie des 7xx » n'a jamais eu lieu. Il ne reste que le 781 au crédit :
une PCA à montant positif **augmente** le résultat de l'exercice au lieu de le diminuer.

Le schéma PCG d'une PCA est `Débit 7xx / Crédit 487`. La spec de juin a écrit
`Débit 487 / Crédit 781` — les deux jambes sont inversées, et la contrepartie au résultat est un
compte de dotation aux provisions (681 dotations, 781 reprises, dont les comptes de bilan relèvent
des classes 15, 29, 39, 49 et 59), non un compte de régularisation.

Le code ([`EcritureGenerator::pourProvisionDotation()`](../../app/Services/Compta/EcritureGenerator.php#L1861))
applique la spec fidèlement. Les tests
([`ProvisionPDServiceTest`](../../tests/Feature/Services/Compta/ProvisionPDServiceTest.php))
valident les comptes, le journal, les dates et le cycle CRUD — **aucun n'assert le sens de
l'impact sur le résultat**, parce qu'aucun critère d'acceptation ne le demandait.

Conséquence pratique : le sens correct ne s'obtient aujourd'hui qu'en saisissant un **montant
négatif**, règle écrite dans [`Provision::montantSigne()`](../../app/Models/Provision.php#L82)
(« negative for PCA = reduces revenue ») et invisible à l'écran.

### 2.2 Le compte saisi n'atteint jamais le grand livre

Les comptes de l'écriture sont en dur. Le `compte_id` choisi au formulaire reste sur la table
`provisions`. La provision ne touche donc jamais le compte qu'elle est censée corriger.

### 2.3 Les lignes générées ne portent aucune dimension analytique

[`pourProvisionDotation()`](../../app/Services/Compta/EcritureGenerator.php#L1894) crée ses
`TransactionLigne` sans `operation_id` ni `seance`, alors que le formulaire demande une opération.
Or le compte de résultat par opération filtre sur `tl.operation_id`
([`fetchOperationRowsPD()`](../../app/Services/Rapports/CompteResultatBuilder.php#L1391)).

**Une provision est donc invisible dans le compte de résultat de l'opération.** Elle corrige le
résultat de l'association et laisse celui de l'opération inchangé.

Le § 6.1 de la spec de juin (« CompteResultatBuilder — Aucun changement ») a tranché cette
question sans la poser : c'était une spec de plomberie, et son § 8 excluait explicitement toute
modification de l'IHM.

### 2.4 Une ligne ventilée par affectations n'a pas d'opération à hériter

Une ligne porte sa ventilation soit directement (`tl.operation_id`), soit par
`transaction_ligne_affectations` — et dans ce second cas `tl.operation_id` est généralement nul.
Le compte de résultat traite chaque affectation séparément
([Q2](../../app/Services/Rapports/CompteResultatBuilder.php#L1422)).

La **seule** ligne à affectations de la base est `transaction_lignes#84`,
**741 — Subvention État Ministère des Sports**, 20 000 € au crédit, `tl.operation_id = NULL`,
ventilée en 10 000 € / 5 000 € / 5 000 € sur trois opérations.

Rattacher une telle ligne au grain de la ligne lui ferait hériter d'une **opération nulle** —
c'est-à-dire reproduire en silence le défaut même que cette spec répare. Elle doit donc être
**refusée avec un message**, pas servie approximativement. Le grain affectation est hors scope
(§ 12) : aucune ligne de la base n'a d'affectation unique, et celle qui en a trois est une
subvention, dont le traitement relève des fonds dédiés.

### 2.5 La classe du compte ne suffit pas à déduire le sens

L'application gère des comptes de sens inverse, documentés dans
[`SensMontantPd`](../../app/Services/Compta/SensMontantPd.php#L7) : `709` « Gratuités accordées »
au débit, `609` « Rabais obtenus » au crédit. La base en contient un — `709A`, 50 € **au débit**.

Un schéma fixe `7xx D / 487 C` appliqué à une ligne 709A débitrice **ajouterait** un débit, donc
amplifierait la réduction de produits au lieu de la neutraliser.

### 2.6 Le verrou doit être au service, et couvre plus que la suppression

[`TransactionService::update()`](../../app/Services/TransactionService.php#L356) fait
`$transaction->lignes()->forceDelete()` ([#L535](../../app/Services/TransactionService.php#L535))
puis recrée toutes les lignes : une FK `RESTRICT` lèverait une erreur SQL brute à la première
édition de transaction. [`affecterLigne()`](../../app/Services/TransactionService.php#L935) et
[`supprimerAffectations()`](../../app/Services/TransactionService.php#L983) modifient les
dimensions héritées sans passer par là.

Deux conséquences supplémentaires :

- **L'identité d'une ligne n'est pas stable par conception.** `update()` détruit et recrée toutes
  les lignes à chaque édition. Le verrou ne protège donc pas une ligne, il **gèle la transaction
  entière**, correction de libellé comprise — `update()` est le seul chemin d'édition.
- **La synchronisation HelloAsso touche la ligne du cas de validation.** Depuis la v5.3.8
  (`484a9fad`) le sync ne réécrit plus compte ni opération, mais « le montant et les libellés
  continuent d'être synchronisés ».
  [`TransactionConverter`](../../app/Services/Compta/TransactionConverter.php#L91) enrichit en
  place et idempotemment, sans `forceDelete` : l'id de la ligne survit, **son montant non**. Un
  remboursement HelloAsso ferait passer la ligne sous le montant déjà provisionné.

## 3. Décisions actées

| # | Décision | Justification |
|---|----------|---------------|
| D1 | Schéma d'écriture aligné sur le PCG ; 681/781 abandonnés | Ce sont les comptes des dotations et reprises aux amortissements et provisions, pas ceux de la régularisation (classe 48). Le sens devient porté par le cas, plus par le signe du montant. |
| D2 | Le cas (CCA/PCA/FNP/PAR) est **déduit**, jamais choisi ni stocké | `classe 6 + adossé = CCA`, `classe 7 + adossé = PCA`, `classe 6 + libre = FNP`, `classe 7 + libre = PAR`. Une charge payée d'avance a forcément une écriture d'origine ; une facture non parvenue n'en a par définition aucune. |
| D3 | Une CCA/PCA s'adosse à son origine et en hérite compte, opération, séance, et le tiers de la **transaction** | Les dimensions analytiques ne peuvent plus diverger : le CR de l'opération se réconcilie par construction. Le tiers d'une écriture 6/7 est porté par la transaction, et c'est lui que lit le rapport ([#L1354](../../app/Services/Rapports/CompteResultatBuilder.php#L1354)) ; `transaction_lignes.tiers_id` reste la place du tiers *porté par* la ligne 408/418 du lot 2. |
| D4 | Le grain est **la ligne** ; une ligne porteuse d'affectations est **refusée avec un message** | § 2.4. Aucune ligne de la base n'a d'affectation unique ; la seule qui en a trois est une subvention relevant des fonds dédiés. Servir ces lignes au grain ligne reproduirait le défaut réparé. |
| D5 | La table `provisions` ne garde que la **décision** ; les champs de saisie libre sont nullables et réservés au lot 2 | Invariant à deux branches, exactement l'une renseignée : la nullité porte le sens. |
| D6 | Point d'entrée unique : l'écran, avec **détection des candidats** | Le geste est un balayage de clôture. La détection propose, la recherche libre complète. |
| D7 | Une ligne adossée est **verrouillée**, et le verrou gèle sa transaction entière | § 2.6. L'incohérence ne peut pas naître ; on retire le rattachement, on corrige, on le repasse. |
| D8 | `operation_id` et `seance` propagés sur **la seule ligne de classe 6/7** de chaque écriture | Seule celle-là change les rapports. Les porter aussi sur la contrepartie de classe 4 créerait de la surface sans bénéfice — décision révisée après revue. |
| D9 | Lot 1 n'accepte qu'une ligne de **classe 6 au débit ou de classe 7 au crédit** | § 2.5. Formulé par le sens observé et non par une liste de numéros : résiste à tout contra-compte futur. |
| D10 | Livraison en deux lots : CCA/PCA d'abord, FNP/PAR ensuite | Le premier couvre le besoin mesuré et n'exige aucun nouveau compte. Le second demande de seeder 408 et 418. |
| D11 | Vocabulaire comptable à l'écran : « écritures de rattachement », « charge / produit constaté d'avance » | CCA/PCA/FNP/PAR sont des comptes de régularisation (classe 48), pas des provisions (15/29/39/49/59). Les noms **physiques** `provisions` / `Provision` sont conservés — même arbitrage que `code_cerfa`, relibellé « Compte comptable » sans renommer la colonne. |
| D12 | Le rattachement est **tout ou rien** : le montant est celui de l'origine, jamais saisi | Aucun cas mesuré n'appelle un décalage partiel — l'opération 8 démarre entièrement dans l'exercice suivant. Supprime d'un coup le plafond, le cumul par origine, le verrou de concurrence et la notion de reste à décaler. |

## 4. Modèle de données

La table `provisions` est vide sur toutes les associations : la migration est une réécriture,
sans reprise de données.

| Colonne | Statut | Rôle |
|---|---|---|
| `transaction_ligne_id` | **ajoutée** | FK nullable, `ON DELETE RESTRICT`. Renseignée ⇒ CCA/PCA. |
| `compte_id`, `operation_id`, `seance`, `tiers_id` | **rendues nullables** | Réservées au lot 2. `compte_id` renseigné ⇒ FNP/PAR. |
| `montant` | conservée | Toujours **positif**, égal au montant de la ligne d'origine. Recopié à la création, jamais saisi (D12). |
| `exercice` | conservée | Exercice de rattachement. |
| `libelle` | conservée, nullable | Hérité en mode adossé, saisi en mode libre. |
| `notes`, `piece_jointe_*`, `saisi_par` | conservées | Inchangées. |
| `type` | **supprimée** | Remplacée par le cas déduit. |
| `date` | **supprimée** | Dérivable de `exercice` ; la date qui fait foi est celle de l'écriture, immuable. |

**Invariant** : exactement **une** de ces deux colonnes est renseignée —
`transaction_ligne_id` (lot 1) ou `compte_id` (lot 2).

Garde **applicative**, pas contrainte SQL : la production tourne sur MariaDB 11.4 et aucun
environnement de test ne la parle. Un test verrouille l'invariant dans les deux sens (aucune
renseignée, les deux renseignées).

**Ce qui est hérité de la ligne d'origine** : le compte (`tl.compte_id`), l'opération et la séance
(`tl.operation_id`, `tl.seance`), le sens (lu sur la ligne), le montant
(`max(tl.debit, tl.credit)` — non ambigu puisque les lignes de sens inverse sont refusées par D9),
et le tiers d'affichage (`transactions.tiers_id`).

Une ligne ne peut porter **qu'un seul** rattachement, puisqu'il couvre son montant entier — et
le retrait supprimant physiquement la ligne (§ 6.4), « un seul rattachement » et « un seul
rattachement non supprimé » sont la même phrase. Garde applicative, comme l'invariant et pour la même raison de moteur — un index unique
filtré n'existe ni sur MariaDB ni sur MySQL, et un rattachement retiré doit pouvoir être repassé.
Le `lockForUpdate` posé sur la ligne d'origine dans la transaction DB de l'écriture suffit à
écarter la création simultanée de deux rattachements : un appel, pas un mécanisme — c'est le cumul
par origine et son calcul en centimes que D12 supprime, pas le verrou.

**Enum `App\Enums\CasProvision`** : `ChargeConstateeDavance`, `ProduitConstateDavance`,
`FactureNonParvenue`, `ProduitARecevoir`. Calculé, jamais persisté.

## 5. Schéma d'écriture

`6xx` / `7xx` désigne le compte de l'origine (lot 1) ou le compte saisi (lot 2).

| Cas | Dotation — dernier jour de N | Extourne — 1er jour de N+1 |
|---|---|---|
| **PCA** — produit constaté d'avance | `7xx` D / `487` C | `487` D / `7xx` C |
| **CCA** — charge constatée d'avance | `486` D / `6xx` C | `6xx` D / `486` C |
| **PAR** — produit à recevoir *(lot 2)* | `418` D / `7xx` C | `7xx` D / `418` C |
| **FNP** — facture non parvenue *(lot 2)* | `6xx` D / `408` C | `408` D / `6xx` C |

Inchangé par rapport à juin, et toujours valable :

- journal **OD** pour toutes les écritures (opérations d'inventaire) ;
- dotation datée de `ExerciceService::dateRange($exercice)['end']`, extourne de
  `dateRange($exercice + 1)['start']` — lues dans le paramétrage, jamais figées au 1er septembre ;
- extourne générée **immédiatement** au CRUD, sans attendre la clôture ;
- extourne générée même si l'exercice N+1 n'existe pas encore dans `exercices` ;
- `provision_id` sur `transactions`, `PartieDoubleGuard::assertComplete()` sur chaque écriture ;
- régénération intégrale (suppression puis recréation) à chaque modification.

**Comptes au plan comptable** : 486, 487, 401 et 411 existent. **408** et **418** sont absents et
seront seedés au lot 2. 681 et 781 restent seedés — ils servent aux dotations aux amortissements —
mais ne sont plus employés ici.

**Dimensions analytiques** (D8) : `operation_id` et `seance` sont portés sur **la seule ligne de
classe 6/7**. La contrepartie de classe 4 n'en porte pas. Pour le lot 2, `tiers_id` est porté sur
la ligne 408/418, où il désigne le fournisseur ou le client.

**Exercices verrouillés** : toute création, modification ou retrait appelle
[`assertOuvertVerrouille()`](../../app/Services/ExerciceService.php#L240) sur **N puis N+1**, dans
l'ordre canonique documenté (association, exercice source, exercice cible, puis écritures), à
l'intérieur de la même transaction DB. La dotation vit dans N, l'extourne dans N+1 : les deux
doivent être ouverts, à l'écriture comme au retrait.

## 6. L'écran

Menu : Exercices > **Écritures de rattachement**. Sélecteur d'exercice en tête.

### 6.1 Zone « À rattacher » — candidats détectés

Est candidate toute ligne de classe 6 ou 7 de l'exercice dont l'opération a un `date_debut`
postérieur à la fin de l'exercice. **Une seule requête** — la ventilation est lue sur
`tl.operation_id`.

**Exclusions** :

- ligne de **sens inverse** — classe 6 au crédit, classe 7 au débit (D9) ;
- ligne **porteuse d'affectations** (D4). Elle n'a pas d'opération propre à hériter ; elle est
  listée comme **écartée, avec son motif**, jamais omise en silence ;
- ligne appartenant à une transaction de rattachement : `transactions.provision_id IS NOT NULL`.
  Sans cette exclusion, l'extourne de notre PCA — un crédit sur 706B en N+1 — se présenterait
  elle-même comme candidate à la recherche libre. La règle de sens écarte déjà la dotation, qui
  est un débit sur un compte de classe 7 ;
- ligne déjà rattachée.

Colonnes : date, pièce, compte, libellé, tiers, opération avec ses dates, montant, action
**« Constater d'avance »**.

La détection **propose**. Une recherche libre, à côté, permet de rattacher toute ligne de
l'exercice respectant les mêmes exclusions : le décalage porté par une opération n'est pas le seul
légitime.

L'exercice du rattachement est celui de la date de la transaction d'origine. Il coïncide par
construction avec l'exercice sélectionné, puisque les candidats en sont tirés.

### 6.2 Zone « Rattachements de l'exercice »

Cas, ligne d'origine cliquable vers sa transaction, compte, opération, montant, pièce jointe, auteur,
date de saisie, action **« Retirer »**.

### 6.3 La modale

Héritées et en lecture seule : compte, opération, séance, tiers, **montant**, date de pièce. Cas
déduit, écrit en clair :

> **Produit constaté d'avance** — les 175,00 € encaissés le 08/04/2026 seront retirés du résultat
> 2025‑2026 et reconnus sur 2026‑2027.

Saisis : notes, pièce jointe. Le montant n'est pas saisissable (D12) — le rattachement porte la
ligne entière.

**Aperçu des deux écritures avant validation** — quatre lignes, avec dates, débits et crédits.
Ce n'est pas cosmétique : l'inversion de la v2.10 a survécu parce que l'écriture produite n'a
jamais été regardée. C'est la seule barrière qui ne dépende ni d'un test ni d'une relecture de spec.

### 6.4 Retrait

Supprime les deux écritures et libère le verrou. Refusé si N ou N+1 est clôturé.

**Le retrait supprime physiquement la ligne de `provisions`** (`forceDelete`), il ne la
soft-delete pas. Un seul invariant en découle, et il est vrai des deux côtés :

> une ligne de `provisions` existe ⟺ la ligne d'origine est verrouillée.

Révisé le 2026-10-01, pendant l'exécution. Le soft-delete créait une **contradiction entre
l'application et la base** : `TransactionLigne::estRattachee()` lit `provisions()->exists()`, qui
exclut les lignes soft-deletées, tandis que la FK `restrictOnDelete()` les compte. Après un
retrait, l'application considérait la ligne libre et la base continuait de bloquer le
`forceDelete()` des lignes que fait `TransactionService::update()` — soit précisément l'erreur SQL
brute que l'AC‑8 interdit, sur le chemin même que le § 7 recommande (« retirez-le, corrigez,
repassez-le »).

Trois options existaient : passer la FK en `nullOnDelete` (l'invariant du § 4 devient alors
violable au repos sur les lignes retirées), supprimer la FK (l'application reste seule gardienne),
ou supprimer physiquement. La troisième est retenue parce qu'elle laisse **une seule règle sans
exception** et garde la FK exactement alignée sur ce que l'application veut dire.

**Conséquence assumée : il n'y a pas de trace en base d'un rattachement retiré.** Ce n'est pas une
perte nette — les deux écritures sont déjà supprimées physiquement par `ProvisionPDService`, donc
le retrait n'a de toute façon aucune trace comptable, et une ligne soft-deletée n'en était qu'une
demi-trace. La trace vit dans le journal applicatif : `Log::info('[Rattachement] … retiré')` porte
la provision, la ligne d'origine, l'exercice, et — automatiquement, via `App\Support\LogContext` —
l'`association_id` et l'`user_id`. Si un historique consultable à l'écran devient nécessaire, il
appellera une table d'audit dédiée, pas un `deleted_at` détourné.

### 6.5 Wizard de clôture

Le récap de l'étape 2 est conservé. [`ProvisionService::mapper()`](../../app/Services/ProvisionService.php)
lit aujourd'hui `$provision->compte`, nul en mode adossé — il afficherait « Compte supprimé ».
Il doit lire le compte via l'origine. `ClotureWizard` est le **seul** consommateur restant de
`ProvisionService`.

## 7. Le verrou

Les lignes sont soft-deletées à certains endroits et `forceDelete`-ées à d'autres : la FK
`RESTRICT` n'est qu'un filet de dernier recours, et sur le chemin d'édition elle produirait une
erreur SQL brute. **La garde est applicative et vit au service.**

Une ligne rattachée gèle **sa transaction entière** : il n'existe aucun chemin d'édition léger à
exempter, `update()` reconstruisant toutes les lignes. Le refus nomme le rattachement et la
sortie : le retirer, corriger, le repasser.

Sites à garder :

| Site | Geste refusé |
|---|---|
| [`TransactionService::update()`](../../app/Services/TransactionService.php#L356) | Toute édition de la transaction porteuse. |
| [`TransactionService::affecterLigne()`](../../app/Services/TransactionService.php#L935) | Modifier les affectations d'une ligne rattachée. |
| [`TransactionService::supprimerAffectations()`](../../app/Services/TransactionService.php#L983) | Les supprimer. |
| `TransactionService::delete()` / `annuler()` | Supprimer ou annuler la transaction porteuse. |
| Extourne de la transaction porteuse | Symétrique du refus que `ReclassementLigneService` oppose déjà à une transaction extournée. |
| [`ReclassementLigneService::reclasser()`](../../app/Services/Compta/ReclassementLigneService.php#L46) | Reclasser la ligne — même grammaire que la garde du reçu fiscal. |
| [`TransactionForm::removeLigne()`](../../app/Livewire/TransactionForm.php#L407) | Retirer la ligne. |
| Synchronisation HelloAsso | **Modifier le montant** d'une ligne rattachée. Le refus est journalisé et ne fait pas échouer la synchronisation des autres commandes. Les libellés restent synchronisés. |

Le **reçu fiscal ne bloque pas** : un rattachement ne change ni le compte, ni le tiers, ni la date
du don, seulement l'exercice de reconnaissance du produit.

**Résolution de la ligne** : l'id reçu de l'IHM est résolu dans le scope tenant courant, et son
compte, son exercice et son association sont vérifiés avant toute écriture. Un appel forgé portant
l'id d'une autre association doit être refusé, pas servi.

## 8. Effets de bord

**Le bilan est déjà correct, et c'est lui qui prouve l'erreur.**
[`BilanComptableBuilder`](../../app/Services/Rapports/BilanComptableBuilder.php#L283) place 487
créditeur en « Produits constatés d'avance » au passif et 486 débiteur en « Charges constatées
d'avance » à l'actif : les rubriques existent depuis toujours. Le schéma actuel rend 487
*débiteur*, et la PCA atterrit en « autres créances », **à l'actif**. Rien à écrire, tout à
vérifier.

À confirmer plutôt qu'à modifier :

- **Trésorerie** : neutre par construction — 486/487 sont en classe 4, aucun compte 512 n'est
  touché, et `FluxTresorerieBuilder` n'appelle plus `ProvisionService`.
- **Budget** : le réalisé de 706B tombe à 0 sur 2025‑2026. C'est l'effet recherché.
- **Statut de règlement et lettrage** : inchangés, le rattachement ne touche pas le 411.
- **`compta:assert-pd-complete`** : les transactions portent `provision_id` et ne sont pas
  HelloAsso ; le guard les valide normalement.

## 9. Cas de validation

**Donnée de départ** : `transaction_lignes#145`, pièce `transactions#122` du 08/04/2026, journal
vente, compte **706B**, **crédit** 175,00 €, `operation_id = 8`, sans affectation. Opération 8
« EquiThe FE sept 2026 », du 18/09/2026 au 18/06/2027. Exercices 2025 et 2026 ouverts.

**Écritures attendues** :

```
Dotation  31/08/2026  OD   706B D 175,00 (op. 8)  /  487  C 175,00
Extourne  01/09/2026  OD   487  D 175,00          /  706B C 175,00 (op. 8)
```

**Résultats attendus** :

| | CR association | CR opération 8 | Bilan |
|---|---|---|---|
| 2025‑2026 | 706B : 175 − 175 = **0,00** | **0,00** | 487 créditeur de 175 au **passif**, rubrique « Produits constatés d'avance » |
| 2026‑2027 | 706B : **+175,00** | **+175,00** | 487 soldé |

**Second cas, le refus** : `transaction_lignes#84` (741, 20 000 € au crédit, trois affectations)
doit être **refusée** — à la détection, à la recherche libre et au service sur appel direct — avec
un motif lisible. C'est le test qui prouve que D4 ne laisse pas passer une ligne sans opération
propre.

**Troisième cas, le refus de sens** : la ligne `709A` débitrice doit être refusée aux trois mêmes
endroits (D9).

## 10. Critères d'acceptation

1. **AC‑1** — Le test du cas § 9 assert les **quatre montants** du tableau : CR association et CR
   opération, sur 2025‑2026 et 2026‑2027. Il porte sur le *sens du résultat*, pas sur la forme de
   l'écriture.
2. **AC‑2** — Mutation : inverser débit et crédit dans le générateur fait tomber AC‑1. À prouver,
   pas à supposer.
3. **AC‑3** — Le bilan sort 487 au passif en « Produits constatés d'avance » au 31/08/2026.
4. **AC‑4** — Le cas est correctement déduit pour les quatre combinaisons, et l'invariant des deux
   colonnes est refusé dans les deux sens : aucune renseignée, les deux renseignées.
5. **AC‑5** — Une ligne **de sens inverse** est refusée aux trois endroits — détection, recherche
   libre, appel direct au service : la ligne `709A` débitrice du § 9.
6. **AC‑6** — Une ligne **porteuse d'affectations** est refusée aux trois mêmes endroits, avec un
   motif, et n'est **jamais** traitée au grain ligne : la ligne 84 du § 9.
7. **AC‑7** — Un test **par site** du verrou du § 7 : `update()`, `affecterLigne()`,
   `supprimerAffectations()`, `delete()`, `annuler()`, extourne, `reclasser()`, `removeLigne()`,
   et modification du montant par la synchronisation HelloAsso. Chacun doit tuer une garde précise
   — retirer n'importe laquelle fait tomber exactement un test.
8. **AC‑8** — `update()` sur une transaction porteuse rend un **refus métier lisible**, jamais une
   erreur SQL de contrainte de clé étrangère.
9. **AC‑9** — Création, modification et retrait sont refusés si N **ou** N+1 est clôturé.
10. **AC‑10** — Une ligne ne peut porter qu'un rattachement ; deux créations simultanées sur la
    même ligne n'en produisent qu'un. Un rattachement retiré peut être repassé sur la même ligne.
    **Et après un retrait, `TransactionService::update()` sur la pièce d'origine réussit** — c'est
    ce test qui aurait attrapé la contradiction entre le soft-delete et la FK (§ 6.4).
11. **AC‑11** — Une transaction de rattachement n'est jamais candidate : ni sa dotation (écartée
    par le sens), ni son extourne (écartée par `provision_id`).
12. **AC‑12** — Tenant : un appel forgé portant l'id d'une ligne d'une autre association est
    refusé. Un test **par filtre** sur la requête de détection, qui joint `operations`, `comptes`
    et `transaction_lignes`.
13. **AC‑13** — La détection trouve la ligne 145 ; une ligne déjà rattachée sort de la liste.
14. **AC‑14** — Le récap du wizard de clôture affiche le compte de l'origine, jamais « Compte
    supprimé ».
15. **AC‑15** — Aucune écriture de rattachement ne porte de débit ou de crédit négatif.
16. **AC‑16** — Suite **complète** verte — pas un sous-ensemble : le garde du lot budget 2a vivait
    à la racine de `tests/Feature/` et deux revues ciblées l'avaient manqué. Plus
    `pest -c phpunit.mysql.xml` dans le conteneur sur le périmètre : la détection fait des
    jointures et un `GROUP BY`, la production tourne sur MariaDB.

## 11. Lots

**Lot 1 — CCA et PCA.** Migration de la table, enum `CasProvision`, schéma d'écriture PCG, règle de
sens, refus des lignes à affectations, propagation analytique, écran avec détection et aperçu,
verrou sur les neuf sites, verrouillage de N et N+1, adaptation du wizard de clôture, relibellé de
l'IHM. Aucun nouveau compte à seeder, aucun montant à saisir.

**Lot 2 — FNP et PAR.** Seed de 408 et 418, mode de saisie libre dans la modale, `tiers_id` sur la
ligne de contrepartie. Le modèle de données du lot 1 l'accueille, **à une exception près** :

⚠️ `provisions.compte_id` porte une FK `ON DELETE SET NULL`, héritée de la migration
`2026_07_07_100002` qui l'a posée uniformément sur dix tables de ventilation. Un hard-delete de
compte mettrait `compte_id` à NULL et laisserait un rattachement dont **aucune** des deux colonnes
de l'invariant n'est renseignée — la garde applicative ne revalide pas les lignes existantes. Le
risque est nul au lot 1, qui ne peuple jamais `compte_id`, et `Compte` utilise `SoftDeletes`, donc
il reste faible au lot 2. **Le lot 2 doit néanmoins passer cette FK en `restrictOnDelete()`**, par
symétrie avec `transaction_ligne_id`.

**Associations non assujetties à la TVA.** Le PCG prévoit que 408 et 418 portent des montants
toutes taxes comprises, avec des comptes de TVA en contrepartie. Aucun compte `445*` n'existe au
plan comptable de l'application : les écritures FNP/PAR à deux lignes du lot 2 valent **pour une
association non assujettie**. Un besoin d'assujettissement rouvrirait ce point.

## 12. Hors scope

**Les fonds dédiés** — et c'est le point le plus important de cette section.

Une subvention **acquise mais affectée** à une action qui se poursuit ne relève ni de la CCA ni de
la PCA. Le PCG associatif (règlement ANC 2018‑06) prévoit les fonds dédiés : une charge
« Engagements à réaliser sur subventions attribuées » (689x) au débit, un compte de fonds dédiés
(19x) au crédit ; l'exercice suivant, un produit « Report des ressources non utilisées » (789x).

La différence n'est pas qu'un vocabulaire. Avec une PCA, le produit **disparaît** du résultat de
N. Avec un fonds dédié, il **reste** à 741 et une charge le neutralise : le compte de résultat
montre à la fois la ressource reçue et l'engagement pris. Les fonds dédiés portent en outre une
obligation d'annexe (tableau de variation).

Aucun compte 19x, 689x ni 789x n'existe au plan comptable, et le code n'en porte aucune trace. La
numérotation fine des sous-comptes reste à confronter au plan ANC. Le besoin est réel et récurrent
— la subvention DS3C du § 2.4 en est l'exemple — mais c'est une **doctrine différente**, avec un
calcul de consommation à construire (part affectée moins charges engagées sur l'opération) et une
annexe à produire. **Chantier propre, spec propre.**

Également hors scope, et chacun pour une raison mesurée :

- **Le rattachement partiel** (D12). Aucun cas de la base ne le demande. Il coûterait le plafond,
  le cumul par ligne, le calcul en centimes et la notion de reste à décaler. Une prestation à
  cheval sur deux exercices le rouvrirait — et c'est alors le prorata qui serait la vraie question.
- **Le grain affectation** (D4). Aucune ligne n'a d'affectation unique, et la seule qui en a trois
  est une subvention relevant des fonds dédiés. Le jour où une ligne à affectations doit être
  rattachée, il faudra une colonne `transaction_ligne_affectation_id`, l'invariant à trois branches
  et une seconde branche de détection. D'ici là, elle est refusée explicitement.
- La granularité 6815 / 6868 : sans objet, les rattachements n'emploient plus 681/781.
- Toute modification du **plancher de date** ou du verrou de période.
- Une **voie d'édition légère** des transactions porteuses : écartée au profit du gel complet, qui
  n'ajoute aucun second écrivain sur `transactions`.
