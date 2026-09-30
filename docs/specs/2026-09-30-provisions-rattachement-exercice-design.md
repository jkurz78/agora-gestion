# Provisions de fin d'exercice — refonte du rattachement à l'exercice

> **Date** : 2026-09-30
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
prestation sera rendue sur 2026‑2027. C'est le seul cas de décalage d'exercice de la base :
aucune charge de 2025‑2026 n'est ventilée sur une opération démarrant après le 31/08/2026.

En tentant de passer la PCA correspondante, trois défauts ont été mis au jour.

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
`Débit 487 / Crédit 781` — les deux jambes sont inversées, et le compte de contrepartie
au résultat est un compte de dotation aux provisions pour risques (681/781, classes 15/29/49),
pas un compte de rattachement à l'exercice.

Le code ([`EcritureGenerator::pourProvisionDotation()`](../../app/Services/Compta/EcritureGenerator.php#L1861))
applique la spec fidèlement. Les tests
([`ProvisionPDServiceTest`](../../tests/Feature/Services/Compta/ProvisionPDServiceTest.php))
valident les comptes, le journal, les dates et le cycle CRUD — **aucun n'assert le sens de
l'impact sur le résultat**, parce qu'aucun critère d'acceptation ne le demandait.

Conséquence pratique : le sens correct ne s'obtient aujourd'hui qu'en saisissant un **montant
négatif**, règle écrite dans [`Provision::montantSigne()`](../../app/Models/Provision.php#L82)
(« negative for PCA = reduces revenue ») et invisible à l'écran, qui ne propose que
« Dépense (charge) / Recette (produit) ». Le montant négatif produit en outre des colonnes
débit/crédit négatives au grand livre.

### 2.2 Le compte saisi n'atteint jamais le grand livre

Les comptes de l'écriture sont en dur (`681`/`486` ou `487`/`781`). Le `compte_id` choisi au
formulaire reste sur la table `provisions`. La provision ne touche donc jamais le compte
qu'elle est censée corriger.

### 2.3 Les lignes générées ne portent aucune dimension analytique

[`pourProvisionDotation()`](../../app/Services/Compta/EcritureGenerator.php#L1894) crée ses
`TransactionLigne` sans `operation_id` ni `seance`, alors que le formulaire demande une
opération. Or le compte de résultat par opération filtre sur `tl.operation_id`
([`fetchOperationRowsPD()`](../../app/Services/Rapports/CompteResultatBuilder.php#L1391)).

**Une provision est donc invisible dans le compte de résultat de l'opération.** Elle corrige le
résultat de l'association et laisse celui de l'opération inchangé — une divergence entre deux
lectures du même fait.

Le § 6.1 de la spec de juin (« CompteResultatBuilder — Aucun changement ») a tranché cette
question sans la poser : c'était une spec de plomberie, destinée à rendre les provisions
visibles au grand livre, pas une spec de comptabilité. Son § 8 excluait explicitement toute
modification de l'IHM.

## 3. Décisions actées

| # | Décision | Justification |
|---|----------|---------------|
| D1 | Schéma d'écriture aligné sur le PCG ; 681/781 abandonnés | Ce sont les comptes des provisions pour risques et charges, pas ceux du rattachement à l'exercice. Le sens devient porté par le cas, plus par le signe du montant. |
| D2 | Le cas (CCA/PCA/FNP/PAR) est **déduit**, jamais choisi ni stocké | `classe 6 + adossé = CCA`, `classe 7 + adossé = PCA`, `classe 6 + libre = FNP`, `classe 7 + libre = PAR`. Une charge payée d'avance a forcément une ligne d'origine ; une facture non parvenue n'en a par définition aucune. |
| D3 | Une CCA/PCA est **adossée à la ligne d'origine** ; elle en hérite compte, opération, séance et tiers | Les dimensions analytiques ne peuvent plus diverger : le CR de l'opération se réconcilie par construction. |
| D4 | La table `provisions` ne garde que la **décision** ; les champs de saisie libre deviennent nullables et réservés au lot 2 | Invariant `transaction_ligne_id XOR compte_id` : la nullité porte le sens. Une seule table couvre les deux lots sans champ mort. |
| D5 | Point d'entrée unique : l'écran Provisions, avec **détection des candidats** | Le geste est un balayage de clôture, pas une correction ligne à ligne. La détection propose, la recherche libre complète. |
| D6 | Une ligne provisionnée est **verrouillée** : ni reclassement, ni suppression | Même grammaire que le verrou des cases de comptabilisation (v5.3.6). L'incohérence ne peut pas naître ; on retire la provision, on corrige, on la repasse. |
| D7 | `operation_id` et `seance` propagés sur **les deux lignes** de chaque écriture | Seule la ligne 6/7 change les rapports ; la porter aussi sur la ligne de classe 4 rend le 487 lisible au grand livre et ne coûte rien. |
| D8 | Livraison en deux lots : CCA/PCA d'abord, FNP/PAR ensuite | Le premier lot couvre le besoin mesuré et n'exige aucun nouveau compte. Le second demande de seeder 408 et 418. |
| D9 | Vocabulaire comptable conservé à l'écran, doublé d'une phrase en clair | Le destinataire est un trésorier ou un expert-comptable : il cherche « PCA », pas « report ». |

## 4. Modèle de données

La table `provisions` est vide sur toutes les associations : la migration est une réécriture,
sans reprise de données.

| Colonne | Statut | Rôle |
|---|---|---|
| `transaction_ligne_id` | **ajoutée** | FK nullable vers `transaction_lignes`, `ON DELETE RESTRICT`. Renseignée ⇒ CCA/PCA. |
| `compte_id`, `operation_id`, `seance`, `tiers_id` | **rendues nullables** | Réservées au lot 2. Renseignées ⇒ FNP/PAR. |
| `montant` | conservée | Toujours **positif**. |
| `exercice` | conservée | Exercice de rattachement. |
| `libelle` | conservée, nullable | Hérité de la ligne en mode adossé, saisi en mode libre. |
| `notes`, `piece_jointe_*`, `saisi_par` | conservées | Inchangées. |
| `type` | **supprimée** | Remplacée par le cas déduit. |
| `date` | **supprimée** | Dérivable de `exercice` ; la date qui fait foi est celle de l'écriture, immuable. |

**Invariant** : `transaction_ligne_id XOR compte_id` — exactement l'un des deux est renseigné.

Il reste une **garde applicative**, pas une contrainte SQL : la production tourne sur
MariaDB 11.4 et aucun environnement de test ne la parle, un `CHECK` exposerait à une divergence
de moteurs. Un test verrouille l'invariant.

**Plafond du montant** : `montant ≤ montant de la ligne d'origine`, somme des provisions
non supprimées de la même ligne comprise. Le décalage partiel est permis.

**Enum `App\Enums\CasProvision`** : `ChargeConstateeDavance`, `ProduitConstateDavance`,
`FactureNonParvenue`, `ProduitARecevoir`. Calculé par `Provision::cas()`, jamais persisté.

## 5. Schéma d'écriture

`6xx` / `7xx` désigne le compte de la ligne d'origine (lot 1) ou le compte saisi (lot 2).

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
- régénération intégrale (suppression puis recréation) à chaque modification de la provision.

**Comptes au plan comptable** : 486, 487, 401 et 411 existent. **408** (fournisseurs — factures
non parvenues) et **418** (clients — produits non encore facturés) sont absents et devront être
seedés au lot 2. 681 et 781 restent seedés — ils servent aux dotations aux amortissements — mais
ne sont plus employés par les provisions.

**Dimensions analytiques** : `operation_id` et `seance` sont recopiés sur les deux lignes de
chaque écriture. `tiers_id` est recopié sur la ligne de classe 4 pour FNP/PAR (lot 2), où il
désigne le fournisseur ou le client.

## 6. L'écran

Menu inchangé : Exercices > Écritures de provisions. Sélecteur d'exercice en tête ; l'exercice
visé doit être ouvert (`ExerciceService::assertOuvert()`).

### 6.1 Zone « À rattacher » — candidats détectés

Règle de détection, lot 1 : toute ligne de classe 6 ou 7 de l'exercice, ventilée sur une
opération dont `date_debut` est postérieure à la fin de l'exercice. Ventilation lue à la fois sur
`transaction_lignes.operation_id` et sur `transaction_ligne_affectations.operation_id` — les deux
portes du modèle.

Une ligne **intégralement** provisionnée sort de la liste. Une ligne **partiellement**
provisionnée y reste, en affichant le montant déjà décalé et le reste à décaler — c'est la
conséquence directe du montant partiel permis au § 4.

L'exercice de la provision est celui de la date de sa transaction d'origine. Il coïncide par
construction avec l'exercice sélectionné à l'écran, puisque les candidats en sont tirés.

Colonnes : date, pièce, compte, libellé, tiers, opération avec ses dates, montant, action
**« Constater d'avance »**.

La détection **propose**. Une recherche libre, à côté, permet de provisionner n'importe quelle
ligne 6/7 de l'exercice : le décalage porté par une opération n'est pas le seul légitime.

### 6.2 Zone « Provisions de l'exercice »

Ce qui a été passé : cas, ligne d'origine cliquable vers sa transaction, compte, opération,
montant, pièce jointe, auteur, date de saisie, action **« Retirer »**.

### 6.3 La modale

Héritées de la ligne, en lecture seule : compte, opération, séance, tiers, montant, date de pièce.

Cas déduit, écrit en clair, exemple :

> **Produit constaté d'avance** — les 175,00 € encaissés le 08/04/2026 seront retirés du résultat
> 2025‑2026 et reconnus sur 2026‑2027.

Saisis : montant à décaler (pré-rempli au total de la ligne, modifiable, plafonné), notes,
pièce jointe.

**Aperçu des deux écritures avant validation** — quatre lignes, avec dates, débits et crédits.
Ce n'est pas cosmétique : l'inversion de la v2.10 a survécu parce que l'écriture produite n'a
jamais été regardée. C'est la seule barrière qui ne dépende ni d'un test ni d'une relecture de spec.

### 6.4 Retrait

Supprime les deux écritures et libère le verrou. **Refusé si l'exercice N+1 est clôturé** :
l'extourne y vit.

### 6.5 Wizard de clôture

Le récap de l'étape 2 est conservé. [`ProvisionService::mapper()`](../../app/Services/ProvisionService.php)
lit aujourd'hui `$provision->compte`, nul en mode adossé — il afficherait « Compte supprimé ».
Il doit lire le compte via la ligne d'origine. `ClotureWizard` est le **seul** consommateur
restant de `ProvisionService`.

## 7. Le verrou

Les lignes sont soft-deletées : la FK `ON DELETE RESTRICT` ne protège que des suppressions
physiques. La garde réelle est applicative, sur trois sites :

1. [`ReclassementLigneService::reclasser()`](../../app/Services/Compta/ReclassementLigneService.php#L46)
   — une garde de plus dans la chaîne existante, même grammaire que celle du reçu fiscal :
   *« Cette ligne fait l'objet d'une provision — retirez-la avant de la reclasser. »*
2. [`TransactionForm::removeLigne()`](../../app/Livewire/TransactionForm.php#L407) — même refus.
3. Extourne ou annulation de la transaction porteuse — refus symétrique de celui que
   `ReclassementLigneService` oppose déjà à une transaction extournée.

Le **reçu fiscal ne bloque pas** : une PCA ne change ni le compte, ni le tiers, ni la date du
don, seulement l'exercice de reconnaissance du produit.

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
- **Statut de règlement et lettrage** : inchangés, la provision ne touche pas le 411.
- **`compta:assert-pd-complete`** : les transactions de provision portent `provision_id` et
  ne sont pas HelloAsso ; le guard les valide normalement.
- **D7** : vérifier qu'aucun consommateur ne lit les classes 4 par opération. La décision de
  propager `operation_id` sur la ligne de contrepartie tient ; si un consommateur s'avérait
  sensible, le repli est de ne la porter que sur la ligne 6/7 — seule celle-là change les
  rapports, et le reste de la spec est inchangé.

## 9. Cas de validation

Le cas réel, à reproduire en test et en recette.

**Donnée de départ** : `transaction_lignes#145`, pièce `transactions#122` du 08/04/2026, journal
vente, compte **706B**, crédit **175,00 €**, `operation_id = 8`. Opération 8 « EquiThe FE sept
2026 », du 18/09/2026 au 18/06/2027. Exercices 2025 et 2026 ouverts.

**Écritures attendues** :

```
Dotation  31/08/2026  OD   706B D 175,00 (op. 8)  /  487  C 175,00 (op. 8)
Extourne  01/09/2026  OD   487  D 175,00 (op. 8)  /  706B C 175,00 (op. 8)
```

**Résultats attendus** :

| | CR association | CR opération 8 | Bilan |
|---|---|---|---|
| 2025‑2026 | 706B : 175 − 175 = **0,00** | **0,00** | 487 créditeur de 175 au **passif**, rubrique « Produits constatés d'avance » |
| 2026‑2027 | 706B : **+175,00** | **+175,00** | 487 soldé |

Les deux lectures bougent sur le même compte, dans le même sens, au même moment.

## 10. Critères d'acceptation

1. **AC‑1** — Le test du cas § 9 assert les **quatre montants** du tableau : CR association et
   CR opération, sur 2025‑2026 et 2026‑2027. Il porte sur le *sens du résultat*, pas sur la forme
   de l'écriture.
2. **AC‑2** — Mutation : inverser débit et crédit dans le générateur fait tomber AC‑1. À prouver,
   pas à supposer.
3. **AC‑3** — Le bilan sort 487 au passif en « Produits constatés d'avance » au 31/08/2026.
4. **AC‑4** — Une provision partielle (par exemple 100 € sur une ligne de 175 €) produit les
   mêmes effets au prorata ; la somme des provisions d'une ligne ne peut dépasser son montant.
5. **AC‑5** — Le cas est correctement déduit pour les quatre combinaisons `classe × adossement`.
6. **AC‑6** — L'invariant `transaction_ligne_id XOR compte_id` est refusé dans les deux sens
   (les deux nuls, les deux renseignés).
7. **AC‑7** — Un test **par garde** du verrou : reclassement, suppression de ligne, extourne de
   la transaction, retrait sur exercice N+1 clôturé. Chacun doit tuer un filtre précis — retirer
   n'importe quelle garde doit faire tomber exactement un test.
8. **AC‑8** — Un test **par filtre tenant** sur la requête de détection, qui joint `operations`,
   `comptes`, `transaction_lignes` et `transaction_ligne_affectations`.
9. **AC‑9** — La détection trouve la ligne 145 ; une ligne intégralement provisionnée sort de la
   liste, une ligne partiellement provisionnée y reste avec son reste à décaler.
10. **AC‑10** — Le récap du wizard de clôture affiche le compte de la ligne d'origine, jamais
    « Compte supprimé ».
11. **AC‑11** — Aucune écriture de provision ne porte de débit ou de crédit négatif.
12. **AC‑12** — Suite **complète** verte — pas un sous-ensemble : le garde du lot budget 2a vivait
    à la racine de `tests/Feature/` et deux revues ciblées l'avaient manqué.
13. **AC‑13** — `pest -c phpunit.mysql.xml` vert dans le conteneur sur le périmètre : la détection
    fait des jointures et un `GROUP BY`, la production tourne sur MariaDB.

## 11. Lots

**Lot 1 — CCA et PCA.** Migration de la table, enum `CasProvision`, schéma d'écriture PCG,
propagation analytique, écran avec détection et aperçu, verrou sur les trois sites, adaptation du
wizard de clôture. Aucun nouveau compte à seeder.

**Lot 2 — FNP et PAR.** Seed de 408 et 418, mode de saisie libre dans la modale (compte,
opération, séance, tiers, libellé), `tiers_id` sur la ligne de contrepartie. Le modèle de données
du lot 1 l'accueille sans migration supplémentaire.

## 12. Hors scope

- Les **fonds dédiés** (195 / 6895 / 7895), mécanique associative des dons et subventions affectés
  non encore employés. Voisine mais distincte : la PCA est la contrepartie d'une prestation non
  rendue, le fonds dédié l'emploi différé d'une ressource affectée. Les comptes ne sont pas seedés.
- Le **prorata temporel assisté** : le montant partiel est saisi à la main, aucun calcul d'étalement.
- La granularité 6815 / 6868 des dotations : sans objet, les provisions n'emploient plus 681/781.
- Toute modification du **plancher de date** ou du verrou de période, traités par un autre chantier.
