# Gestion des immobilisations — design

**Date** : 2026-08-04
**Statut** : design validé, prêt pour plan d'implémentation
**Périmètre** : lot 1 (acquisition + registre + dotations)

---

## 1. Contexte et problème

AgoraGestion ne connaît aujourd'hui aucune immobilisation. Le plan comptable par
défaut ne livre que des classes 6 et 7 (17 comptes de charges, 9 de produits,
cf. `App\Services\Onboarding\DefaultChartOfAccountsService`) ; les seules classes
1-5 en base sont les comptes système (401, 411, 512X, 5112, 530). Tout le
matériel acheté jusqu'ici est donc parti directement en charge.

Le déclencheur est concret : l'achat de tenues d'escrime pour plusieurs milliers
d'euros, destinées à servir sur plusieurs exercices. Passer ce montant en charge
sur un seul exercice fausse le résultat de l'année d'achat et de toutes les
suivantes.

Le traitement correct est celui du PCG (règlement ANC 2018-06 pour les
associations) : l'achat est porté à l'actif en classe 2, et seule la **dotation
aux amortissements** annuelle constitue une charge.

### Ce que le socle sait déjà faire

Trois vérifications faites en amont, qui expliquent pourquoi ce module est peu
invasif :

- **Compte de résultat** : lecture *compte-first* des classes 6 et 7
  (`CompteResultatBuilder::fetchClasseRowsPD`). Une écriture de classe 2 en est
  exclue mécaniquement — aucune modification nécessaire.
- **À-nouveau** : générique sur les classes 1 à 7, avec `classe >= 6` basculé au
  résultat (`ANouveauPreviewBuilder`). Les soldes 21X et 281X se reportent d'eux-mêmes
  d'un exercice à l'autre.
- **Balance, grand livre, journaux** : génériques sur tous les comptes.

### Ce qui bloque

- `EcritureGenerator::pourDepenseACredit` rejette tout compte de ventilation qui
  n'est pas de classe 6.
- `PlanComptableSelecteur` et l'écran `PlanComptable` ne gèrent que les classes 6 et 7.
- `TransactionLigne::scopeVentilation` retient les classes 6 et 7 — une ligne de
  classe 2 serait traitée comme une ligne technique PD dans plus de dix appelants.

Depuis le chantier 3a-i, le chemin dépense réel ne passe **plus** par
`pourDepenseComptant` : toute dépense, comptant ou à crédit, part de
`pourDepenseACredit` (T1 : 60X D / 401 C, journal Achat), et le comptant ajoute
`pourReglementFournisseur` en T2 (`TransactionService`). **Il n'y a donc qu'un
seul garde-fou de classe à considérer.**

---

## 2. Décisions de cadrage

| # | Question | Décision |
|---|---|---|
| 1 | Ambition | Registre **+ dotations générées**. Le bilan fait l'objet d'un chantier séparé. |
| 2 | Granularité | Une fiche = **un lot homogène** (« 20 tenues d'escrime »), pas un bien unitaire. |
| 3 | Calcul | **Linéaire, prorata mensuel**, une seule date (mise en service). Dégressif écarté. |
| 4 | Analytique | Les dotations sont générées **non ventilées** ; la fonction « Ventiler » existante reste le geste du comptable. |
| 5 | Déclenchement | **Écran dédié avec aperçu**, plus un contrôle non bloquant à la clôture. |
| 6 | Existant | **Démarrage à blanc** — aucune reprise de fiches historiques. |
| 7 | Subventions | **Hors périmètre** — les subventions restent en 74X. |

### Justification des décisions non évidentes

**Décision 4 — dotations non ventilées.** Une ventilation analytique par défaut
portée par la fiche serait fausse dès la deuxième année : une opération est une
instance datée (`date_debut` / `date_fin`, scope `forExercice`) alors qu'une
immobilisation vit plusieurs exercices. Imputer la dotation 2028 sur
« Escrime 2026 » n'aurait pas de sens.

Or la ligne de dotation porte le compte 6811, de classe 6 : elle est donc une
ligne de ventilation *par construction* au sens de `TransactionLigne::scopeVentilation`.
Le bouton « Ventiler » apparaît sans une ligne de code supplémentaire, et il sait
déjà répartir sur plusieurs opérations et séances via `transaction_ligne_affectations` —
ce qu'un sélecteur mono-opération n'aurait pas permis.

> ⚠️ **Prémisse invalidée par la recette du 2026-08-06. La ventilation des
> dotations n'est pas réalisable en l'état.**
>
> Le raisonnement ci-dessus vérifiait que la *ligne* était ventilable, sans
> vérifier que la *transaction* était atteignable. Elle ne l'est pas : la dotation
> est en `journal = od`, et le journal OD est exclu des deux écrans de travail —
> `TransactionUniverselleService::brancheDepense()` filtre en dur
> `whereIn('tx.journal', ['vente', 'achat'])`, et `Transaction::scopeOperationnel()`
> fait de même pour le dashboard. Une écriture OD n'est donc joignable qu'en
> lecture, dans `/rapports/journaux` et le grand livre.
>
> Les provisions sont dans le même cas depuis plus longtemps, sans que ça se soit vu.
>
> Le déblocage relève du chantier **« consultation des journaux + saisie manuelle »**,
> qui doit fournir un écran d'édition des écritures par journal et période. Voir la
> note de projet correspondante. Jusque-là, les dotations sont générées correctement
> mais restent non ventilées, et le compte de résultat par opération ne porte donc
> pas le coût d'usage des immobilisations.
>
> Le bug de la ligne à `montant = 0`, qui aurait de toute façon empêché toute
> ventilation, a été corrigé au commit `2f9bf64f`.

**Séquencement à documenter** : le panneau de ventilation se ferme sur un
exercice clôturé. Les dotations étant datées du 31/08, l'ordre est
générer → ventiler → clôturer.

**Décision 6 — démarrage à blanc.** Une reprise extra-comptable (fiches
historiques sans écriture) fabriquerait une divergence silencieuse entre le
registre et la balance. Une reprise comptable complète (fiches + écriture
d'ouverture 21X / 281X) est correcte mais relève d'un lot ultérieur. Comme rien
n'existe aujourd'hui en classe 2, le démarrage à blanc ne dégrade rien.

---

## 3. Hors périmètre du lot 1

- Bilan (actif / passif) — chantier séparé, il servirait aussi aux provisions 486/487.
- Sorties d'immobilisations (perte, cession, mise au rebut) — lot 2, voir § 11.
- Subventions d'investissement (131X / 139 / 777).
- Constitution d'une immobilisation à partir de plusieurs factures.
- Production immobilisée.
- Amortissement dégressif.
- Reprise de fiches historiques.
- Rattachement d'une dépense existante à une fiche.

---

## 4. Modèle de données

### 4.1 `immobilisations`

Étend `App\Models\TenantModel`. `final class`, `declare(strict_types=1)`,
`SoftDeletes` (modèle financier, cf. CLAUDE.md).

| Colonne | Type | Rôle |
|---|---|---|
| `association_id` | FK | tenant |
| `numero` | string(10) | `IM00001`, séquence par tenant — voir § 4.3 |
| `libelle` | string(255) | « 20 tenues d'escrime » |
| `quantite` | unsigned int, défaut 1 | déclarative en lot 1 ; support de la sortie partielle en lot 2 |
| `compte_id` | FK `comptes` | le compte 21X |
| `compte_amortissement_id` | FK `comptes` | le compte 281X |
| `montant_acquisition` | decimal(10,2) | base amortissable |
| `date_mise_en_service` | date | départ du prorata |
| `duree_mois` | unsigned smallint | 60 pour 5 ans |
| `transaction_id` | FK `transactions`, non nullable | l'écriture d'acquisition |
| `notes` | text nullable | |

Contraintes : `unique(association_id, numero)`.

`transaction_id` est non nullable : la fiche et son écriture naissent ensemble
dans la même `DB::transaction()`, il ne peut donc exister ni fiche orpheline ni
acquisition sans fiche.

**`montant_acquisition` est le coût d'entrée à l'actif**, c'est-à-dire la base
amortissable — et non « le montant de la transaction ». La distinction paraît
verbale ; elle ne l'est pas, pour deux évolutions probables :

- une immobilisation constituée de **plusieurs achats** : la base devient la
  somme, sans que le sens de la colonne change ;
- l'arrivée de la **TVA** : la base est TTC quand la taxe n'est pas récupérable
  (association non assujettie, cas actuel), HT quand elle l'est. Là encore, seule
  change l'alimentation en amont.

**Accès aux transactions d'acquisition.** Le modèle expose
`transactionsAcquisition(): Collection` — au pluriel, bien qu'adossée à un unique
FK en lot 1. Tous les consommateurs (fiche, PDF, badge, verrou) sont donc écrits
contre une collection dès le départ. Le jour où une immobilisation devra porter
plusieurs achats, le passage 1:1 → 1:N ne touchera que le modèle et sa migration,
aucun site de lecture. Le coût est nul aujourd'hui ; l'économie est réelle demain.

**Pourquoi `duree_mois` et non `duree_annees`** : le calcul est mensuel. Stocker
des années reviendrait à stocker l'unité d'affichage plutôt que l'unité du
modèle, et à multiplier par 12 à chaque usage. Les durées non entières en années
existent réellement pour une association : agencements de locaux loués amortis
sur la durée du bail restant à courir (42 mois), matériel dédié à un projet
subventionné calé sur la convention de financement (30 mois).

Saisie : liste des durées usuelles en années (3, 5, 7, 10, 15) plus une option
« autre durée, en mois ». Affichage : « 5 ans » quand `duree_mois % 12 === 0`,
« 30 mois » sinon.

### 4.1.1 Les deux dates, et leur contrôle

Le modèle porte bien **deux dates distinctes** : `transactions.date` sur
l'écriture d'acquisition (date de constatation, c'est-à-dire la date de la
facture) et `date_mise_en_service` sur la fiche. La fiche ne duplique pas la date
d'achat — elle la lit sur sa transaction.

Dans le formulaire, la mise en service est **pré-remplie à la date d'achat** ;
elles seront donc égales dans l'immense majorité des cas.

**Contrôle retenu** : `date_mise_en_service >= premier jour de l'exercice de la
transaction d'acquisition`.

Pourquoi pas `MES >= date d'achat` en strict : le cas « livré et utilisé en
septembre, facturé en octobre » est banal, et la date d'une dépense à crédit est
celle de la facture. Un contrôle strict forcerait à fausser l'une des deux dates.

Pourquoi ce contrôle-là : l'amortissement ne peut pas commencer dans un exercice
antérieur à celui où le bien entre à l'actif — sinon on doterait un exercice où
le bien n'existe pas encore au bilan. À l'intérieur d'un même exercice, un léger
décalage est sans conséquence : seul le cumul au 31/08 compte.

**Pas de borne supérieure.** Une mise en service postérieure à l'acquisition est
légitime (matériel acheté en août, installé en octobre). La règle du § 6 la gère
déjà : les mois écoulés ont un plancher à 0, donc la dotation de l'exercice
d'acquisition vaut zéro.

En contrepartie de cette absence de borne, une faute de frappe sur l'année
(2036 pour 2026) produirait des dotations nulles indéfiniment, en silence. Le
livre des immobilisations affiche donc un état **« pas encore en service »** pour
toute fiche dont la mise en service est à venir : l'anomalie devient visible au
lieu de rester muette.

**Résolution des comptes.** Le compte 281X est dérivé du 21X à la création par la
règle PCG (2154 → 28154), pré-rempli dans le formulaire, modifiable, puis **figé
sur la fiche** — une modification ultérieure du plan comptable ne peut donc pas
déplacer silencieusement les amortissements d'une fiche existante. Le compte 6811
est résolu à la génération par convention, comme le sont déjà 401 et 411 ; son
absence lève une exception explicite plutôt que de produire une écriture
incomplète.

### 4.2 `immobilisation_dotations`

| Colonne | Type | Rôle |
|---|---|---|
| `association_id` | FK | tenant |
| `immobilisation_id` | FK, cascade | |
| `exercice` | unsigned smallint | année de début — `2026` pour l'exercice 2026-2027 |
| `montant` | decimal(10,2) | dotation réellement comptabilisée |
| `transaction_id` | FK `transactions` | l'écriture de dotation |

Contraintes : **`unique(immobilisation_id, exercice)`**.

Cette contrainte unique *est* la garantie d'idempotence — la base refuse un
doublon, sans machine à états comme celle de l'à-nouveau.

**Le plan d'amortissement n'est pas stocké.** Cette table ne contient que ce qui a
été réellement comptabilisé ; les exercices futurs sont calculés à la volée.
Aucun échéancier ne peut devenir périmé après un changement de durée ou de montant.

### 4.3 Séquence de numérotation

Séquence **par tenant, pas par exercice** — une immobilisation traverse les
exercices, contrairement au numéro de pièce. Table dédiée
`immobilisation_sequences (association_id unique, dernier_numero)`, alimentée
selon l'idiome établi par `NumeroPieceService` : `insertOrIgnore` pour garantir
l'existence de la ligne, puis `lockForUpdate` avant incrément.

Format : `IM` + numéro sur 5 chiffres, zéro-paddé.

Le numéro apparaît dans la liste, sur la fiche, dans le PDF, et dans le libellé
des dotations générées : « Dotation IM00001 — 20 tenues d'escrime ». Le journal
des OD devient lisible sans ouvrir le registre.

---

## 5. Comptabilisation

### 5.1 Acquisition

`ImmobilisationService::acquerir()` ouvre une `DB::transaction()`, crée la fiche,
puis appelle **directement `EcritureGenerator`** avec la ventilation sur le
compte 21X — comme le font déjà `FactureService`, `ProvisionPDService` et
`NoteDeFraisValidationService`, et non via `TransactionService` qui est piloté
par le formulaire de saisie.

Le routage reproduit celui de `TransactionService` : `pourDepenseACredit` seule
si l'achat est à crédit, `pourDepenseACredit` puis `pourReglementFournisseur` si
le règlement est comptant.

Un seul garde à assouplir, dans `EcritureGenerator::pourDepenseACredit` :

```php
if ($compteVent->classe !== 6
    && ! ($autoriseImmobilisation && $compteVent->classe === 2)) {
    throw CompteIncorrectException::classeAttendue(...);
}
```

`$autoriseImmobilisation` est un **paramètre nommé, défaut `false`**. Les autres
appelants (`TransactionService`, `TransactionConverter`, HelloAsso, notes de
frais, factures fournisseurs) ne le passent pas et restent verrouillés sur la
classe 6.

Résultat : `type = Depense`, `journal = Achat`, dette 401, puis règlement,
lettrage, remise et rapprochement — tout le circuit fournisseur hérité sans
code supplémentaire.

### 5.2 Dotation

Une transaction par fiche et par exercice, `type = Depense` + `journal = Od` —
la forme exacte de `EcritureGenerator::pourProvisionDotation` :

| Compte | Débit | Crédit |
|---|---|---|
| 6811 — Dotations aux amortissements sur immobilisations corporelles | montant | |
| 281X — Amortissements … | | montant |

Datée du **31/08** (dernier jour de l'exercice cible).

Nouvelle méthode `EcritureGenerator::pourDotationAmortissement(Immobilisation, int $exercice, string $montant)`.

### 5.2.1 Invariants de date

**La date du jour n'intervient jamais**, ni dans le calcul (§ 6, ancré sur le mois
de clôture) ni dans l'écriture. Générer en octobre N+1 les dotations de l'exercice
N est le cas normal, pas l'exception : la clôture des comptes est postérieure à la
fin de la période.

Conséquence vérifiée : `NumeroPieceService::exerciceFromDate(31/08/2027)` retourne
bien `"2026-2027"` (mois 8, donc branche `else`). En passant la date de clôture,
la pièce est numérotée sur le bon exercice — à condition de ne jamais passer `now()`.

Trois gardes, **au niveau du service** et non du seul écran. `TransactionForm`
contraint la date à l'exercice en cours, mais `EcritureGenerator` et
`TransactionService` ne vérifient rien : le service de dotation doit donc porter
lui-même ces contrôles, sans quoi une commande artisan ou un futur appelant
pourrait écrire n'importe où.

1. **Exercice cible commencé.** La génération est refusée tant que la date de
   *début* de l'exercice n'est pas passée.

   > **Révisé le 2026-08-05, après recette.** La règle initiale exigeait que
   > l'exercice soit **terminé**, pour éviter une écriture datée dans le futur.
   > Elle empêchait d'anticiper les travaux de clôture, ce qui est un besoin
   > réel : les opérations d'inventaire se préparent couramment avant la fin de
   > la période. Une dotation passée en juin et datée du 31/08 est une écriture
   > d'inventaire provisoire, et « Recalculer » (§ 7.4) est précisément l'outil
   > qui la rafraîchit si la fiche bouge d'ici là. La borne haute est donc levée ;
   > seul un exercice **non commencé** reste refusé.
2. **Exercice cible non clôturé.** Une génération, un recalcul ou une annulation
   sur un exercice clôturé est refusé.
3. **Date imposée.** La transaction est datée du dernier jour de l'exercice cible,
   valeur dérivée de `ExerciceService::dateRange($exercice)`, jamais d'un paramètre
   appelant ni de `now()`.

### 5.3 Comment la dotation atteint le compte de résultat

Le compte 6811 est un compte de charges ordinaire, de classe 6. Il entre donc
dans `fetchClasseRowsPD($start, $end, 6)` comme n'importe quel 606 ou 615.
**Aucune modification de rapport n'est nécessaire.**

C'est toute l'asymétrie recherchée : l'acquisition en classe 2 est invisible au
compte de résultat, la dotation en classe 6 y figure, étalée sur la durée d'usage.

**Conséquence sur les familles.** Le regroupement par famille du compte de
résultat est un `leftJoin` avec `COALESCE(..., '(sans famille)')`
(`CompteResultatBuilder`). Rien ne se perd si la famille manque, mais la dotation
s'afficherait sous « (sans famille) ». La famille **« 68 — Dotations aux
amortissements »** doit donc être créée dans le lot 1.

---

## 6. Règle de calcul

- **Mois écoulés** à la fin de l'exercice E = nombre de mois du mois de mise en
  service (inclus) jusqu'au mois de clôture de E (inclus), plafonné à
  `duree_mois`, plancher à 0. Le mois de mise en service compte pour un mois
  entier : une mise en service le 12/02 comme le 26/02 fait compter février.
- **Cumul théorique** à la fin de E = `montant_acquisition × mois_écoulés / duree_mois`,
  arrondi **au centime le plus proche, demi vers le haut**.
- **Dotation de l'exercice E = cumul théorique(E) − cumul déjà comptabilisé.**

Cette dernière formule est le cœur du calcul. Elle absorbe les arrondis au lieu
de les accumuler, la dernière dotation solde le bien à l'euro près par
construction, et une durée corrigée en cours de vie se rattrape d'elle-même sur
l'exercice suivant. Aucun mécanisme de rattrapage manuel à écrire.

### Exemple 1 — année pleine

3 000 €, mise en service le 12/09/2026, 60 mois. Exercice 2026 = 01/09/2026 → 31/08/2027.

| Exercice | Mois écoulés | Cumul théorique | Dotation |
|---|---|---|---|
| 2026 | 12 | 600,00 | 600,00 |
| 2027 | 24 | 1 200,00 | 600,00 |
| 2028 | 36 | 1 800,00 | 600,00 |
| 2029 | 48 | 2 400,00 | 600,00 |
| 2030 | 60 | 3 000,00 | 600,00 |

### Exemple 2 — prorata et arrondi ingrat

1 000 €, mise en service le 15/02/2027, 36 mois.

| Exercice | Mois écoulés | Cumul théorique | Dotation |
|---|---|---|---|
| 2026 | 7 (fév.→août 2027) | 194,44 | 194,44 |
| 2027 | 19 | 527,78 | 333,34 |
| 2028 | 31 | 861,11 | 333,33 |
| 2029 | 36 (plafonné) | 1 000,00 | 138,89 |

Total : 1 000,00 €. Le décalage d'un centime entre les exercices 2027 et 2028 est
absorbé par la formule, sans traitement particulier.

Précision : `bcmath` à 2 décimales, cohérent avec `EcritureGenerator::assertEquilibre`.

---

## 7. Écrans et navigation

Entrée **« Immobilisations » dans le groupe Comptabilité** de la sidebar, après
Budget. Un neuvième groupe de premier niveau serait disproportionné pour un
module de trois écrans, et pour un comptable les immobilisations relèvent de la
comptabilité.

### 7.1 Livre des immobilisations

Colonnes : numéro, libellé, quantité, compte, mise en service, durée, valeur
brute, cumul amortissements, VNC. Totaux en pied (brut, cumul, VNC).

État **« pas encore en service »** sur les fiches dont la mise en service est
postérieure à aujourd'hui (§ 4.1.1) — c'est ce qui rend visible une faute de
frappe sur l'année, qui produirait sinon des dotations nulles en silence.

Conventions : en-tête `table-dark` avec
`style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880"`, tri JS côté
client avec `data-sort` sur les `<td>` (dates en ISO `Y-m-d`, nombres bruts).

### 7.2 Fiche

Identité, plan d'amortissement complet (exercice, mois écoulés, dotation, cumul,
VNC) avec les exercices comptabilisés visuellement distincts des projections,
lien vers la transaction d'acquisition, lien vers chaque transaction de dotation.
Boutons **Modifier** et **Supprimer** (§ 7.3.1).

**Export PDF imprimable** : `ImmobilisationPdfController`, via
`barryvdh/laravel-dompdf` ^3.1 déjà présent. Le patron de référence est
`RapprochementPdfController` et sa vue : association résolue par
`CurrentAssociation::get()`, logo chargé en base64 depuis `brandingLogoFullPath()`
avec détection du type MIME, en-tête portant ce logo et l'identité de
l'association, `@include('pdf.partials.footer-logos')` dans la vue, et
`App\Support\PdfFooterRenderer::render($pdf)` appelé après le `loadView` pour le
pied de page et la pagination. Contenu métier : identité de la fiche, référence
de l'acquisition, plan d'amortissement complet.

### 7.3 Nouvelle immobilisation

Formulaire calqué sur celui d'une dépense (fournisseur, date d'achat, montant,
mode de règlement, pièce jointe), plus les champs propres : libellé, quantité,
compte 21X, date de mise en service, durée. Le geste reste « je saisis un
achat » ; la fiche est le résultat, pas le formulaire.

La date de mise en service est pré-remplie à la date d'achat et validée selon
le § 4.1.1.

Modale Bootstrap, sans fermeture au clic extérieur (cf. commit `57af945a`).
Confirmations via modale, jamais `confirm()` natif.

**Le montant saisi est le total de l'acquisition**, pas un prix unitaire — c'est
la base amortissable, et elle doit pouvoir absorber le port ou une remise, qui
feraient qu'un prix unitaire ne tomberait pas juste. Le champ est donc libellé
« Montant total de l'acquisition », avec un rappel calculé « soit X € l'unité »
dès que la quantité dépasse 1, et une aide indiquant que la quantité sert au
suivi d'inventaire sans entrer dans le calcul de l'amortissement.

Le sélecteur de durée propose les cinq durées usuelles en années **plus une
option « Autre durée… »** qui révèle une saisie libre en mois — sans quoi aucun
parcours ne pourrait produire les durées non entières que le § 4.1 justifie.

### 7.3.1 Modification et suppression d'une fiche

Ajoutés le 2026-08-05, après recette. Leur absence était une incohérence de
conception : tout le mécanisme « Recalculer » du § 7.4 existe pour absorber une
durée ou un montant corrigés en cours de vie, alors qu'aucun écran ne permettait
cette correction.

**Modifiables** : `libelle`, `quantite`, `duree_mois`, `date_mise_en_service`,
`notes`. Ils n'engagent pas l'écriture comptable, et les dotations déjà passées
se rattrapent d'elles-mêmes. La mise en service reste soumise au contrôle du
§ 4.1.1.

**Non modifiables** : `montant_acquisition` et `compte_id`. Ils engagent une
transaction potentiellement déjà réglée, lettrée ou rapprochée ; ils s'affichent
en lecture seule. Pour les corriger, on supprime la fiche et on resaisit.

**Suppression** : soft-delete de la fiche *et* de sa transaction d'acquisition,
dans une même `DB::transaction()`. Refusée si des dotations existent (il faut les
annuler d'abord) ou si l'exercice de l'acquisition est clôturé. La confirmation
dit explicitement que l'écriture comptable part avec la fiche.

### 7.4 Dotations de l'exercice N

Trois colonnes par fiche : **comptabilisé**, **recalculé**, **écart**. Une action
par ligne : *Générer* si absente, *Recalculer* s'il y a un écart, rien si aligné.

**Pas de flag *dirty*.** La détection est dérivée de la comparaison entre le
montant comptabilisé et le montant recalculé — elle ne peut donc jamais se
désynchroniser, et ne coûte aucune colonne.

*Recalculer* = soft-delete de la transaction, suppression de la ligne de
dotation, régénération. **Uniquement sur un exercice ouvert** : sur un exercice
clôturé on ne touche à rien, et la règle du § 6 rattrape l'écart d'elle-même sur
l'exercice suivant.

**Avertissement obligatoire** : la transaction remplacée emporte avec elle ses
affectations analytiques. Si la dotation avait déjà été ventilée sur des
opérations, ce travail est perdu et doit être refait. La modale de confirmation
doit le dire explicitement, et ne se déclencher que sur les lignes réellement en
écart.

---

## 8. Plan comptable et comptes

L'écran `PlanComptable` est verrouillé sur les classes 6 et 7
(`Compte::whereIn('classe', [6, 7])`). Il faut l'ouvrir à la classe 2, de même
que `PlanComptableSelecteur` pour le sélecteur de compte d'immobilisation.

Kit de comptes créé à la demande et idempotent, sur le patron de
`ComptesProvisioningService` :

| Compte | Intitulé | Contrepartie |
|---|---|---|
| 2154 | Matériel | 28154 |
| 2183 | Matériel de bureau et informatique | 28183 |
| 2184 | Mobilier | 28184 |
| 2188 | Autres immobilisations corporelles | 28188 |
| 6811 | Dotations aux amortissements sur immobilisations corporelles | — |

Les tenues d'escrime relèvent du 2188.

Familles créées au passage : **21**, **28**, **68**. Les deux premières ne servent
qu'au regroupement d'affichage du plan comptable ; la troisième conditionne
l'affichage correct de la dotation au compte de résultat (§ 5.3).

---

## 9. Contacts avec l'existant

**`TransactionLigne::scopeVentilation` reste inchangé.** L'élargir à la classe 2
rendrait la ligne d'acquisition modifiable depuis le formulaire générique — donc
capable de diverger de sa fiche — et ferait entrer les actifs dans la matrice
analytique (`EncadrementMatrixBuilder`) et dans le contrôle d'intégrité.

À la place, la transaction d'acquisition est **verrouillée** dans
`TransactionForm`, sur le modèle de `isLockedByFacture` et `isLockedByHelloAsso`
déjà en place : un `isLockedByImmobilisation`, un message « Cette transaction
provient de l'immobilisation "…" — modifiez la fiche », et le lien vers celle-ci.
Le registre est le maître ; le formulaire générique ne peut pas le contredire.

Autres points de contact :

- **`TransactionUniverselle`** : la transaction d'acquisition n'ayant aucune ligne
  de ventilation, `compte_ventilation_nom` serait vide. On affiche le compte 21X
  de la fiche et un badge « Immobilisation ».
- **`ClotureCheckService`** : nouveau contrôle **non bloquant** « dotations non
  générées pour cet exercice ».
- **`ComptaCheckIntegrityCommand`** : vérifier qu'une transaction d'acquisition
  n'est pas signalée à tort comme incohérente.
- **Rapports** : rien à changer (§ 1, § 5.3).

Multi-tenant : les trois nouvelles tables sont tenant-scopées ; `Immobilisation`
et `ImmobilisationDotation` étendent `TenantModel`. Toute URL en PDF ou e-mail
passe par `App\Support\TenantUrl`.

---

## 10. Tests

**Unitaires — calcul** (le cœur) : prorata de première année, année pleine,
dernière année qui solde à l'euro près, durée non multiple de 12, durée corrigée
en cours de vie, arrondi ingrat (1 000 € sur 36 mois, cf. exemple 2), mise en
service postérieure à la fin de l'exercice (dotation nulle).

**Unitaires — garde** : `pourDepenseACredit` refuse toujours la classe 2 sans le
drapeau, l'accepte avec ; refuse toujours les classes autres que 2 et 6.

**Unitaires — cohérence des dates** (§ 4.1.1) : mise en service antérieure à
l'exercice de l'acquisition refusée ; mise en service antérieure à la date
d'achat mais dans le même exercice acceptée (cas « livré puis facturé ») ; mise
en service postérieure à l'acquisition acceptée, avec dotation nulle sur
l'exercice d'acquisition.

**Unitaires — séquence** : numéros consécutifs, cloisonnement par tenant.

**Unitaires — invariants de date** (§ 5.2.1) : générer en octobre N+1 les
dotations de l'exercice N produit une écriture au 31/08 et une pièce numérotée
sur l'exercice N, quelle que soit la date du jour ; la génération est refusée sur
un exercice non terminé ; elle est refusée sur un exercice clôturé, de même que
le recalcul et l'annulation. Ces tests figent l'horloge (`travelTo`) pour prouver
que `now()` n'influence pas le résultat.

**Feature — parcours complet** : acquisition → fiche + transaction équilibrée +
dette 401 ; règlement ; génération de dotation ; idempotence du rejeu ;
recalcul après modification de la fiche ; verrouillage du formulaire de
transaction ; le compte de résultat ignore l'acquisition et intègre la dotation ;
l'à-nouveau reporte 21X et 281X sur l'exercice suivant.

**Feature — cloisonnement tenant** sur les trois tables.

Conventions de test : `Tests\Support\TenantTestCase` (ou bootstrap global
`tests/Pest.php`), locale `fr`. Attention à la frontière de date SQLite —
`whereBetween` sur des dates exclut le dernier jour, donc dater à l'intérieur des
fenêtres dans les tests. Cast `(int)` des deux côtés dans les comparaisons de
clés.

---

## 11. Extensions prévues (non implémentées)

Le lot 1 est conçu pour que ces ajouts soient additifs :

- **Sorties d'immobilisations** (lot 2, déclenché par l'inventaire annuel). Les
  mouvements de sortie seront une **table d'événements rattachés à la fiche**,
  jamais une mutation de la fiche : une mise au rebut totale et une sortie
  partielle deviennent alors la même chose à un montant près, et le plan se
  recalcule sur la valeur résiduelle sans casser l'historique. La colonne
  `quantite` est présente dès le lot 1 pour cela. À terme, un écran de
  rapprochement d'inventaire plutôt qu'un bouton « supprimer » sur la fiche.
- **Bilan** (chantier séparé) — servirait aussi aux provisions 486/487.
- **Subventions d'investissement** — 131X au crédit à l'encaissement, quote-part
  annuelle 139 D / 777 C calée sur le rythme de la dotation.
- **Rattachement d'une dépense existante** à une fiche, pour l'achat arrivé par
  une facture fournisseur ou par l'inbox — sans jamais ouvrir le garde-fou de
  classe globalement. Deux cas de nature différente :
  - *facture fournisseur non encore comptabilisée* — purement **additif**. Le
    circuit `FacturePartenaireDeposee` → `TransactionForm` → `TransactionService`
    → `pourDepenseACredit` gagne un appelant qui passe `$autoriseImmobilisation:
    true` et crée la fiche. Le paramètre nommé à défaut `false` est précisément ce
    qui permet d'ajouter un appelant sans toucher aux six autres.
  - *dépense déjà comptabilisée* — **hors de portée de ce modèle**. Cela revient à
    reclasser une ligne de classe 6 vers un 21X sur une transaction existante,
    éventuellement réglée : c'est la question de doctrine ouverte « reclassement
    en place *vs* saisie d'écritures OD », qui doit être tranchée avant. Cette
    spec n'a pas à l'anticiper.
- **Immobilisation composée de plusieurs achats.** Le passage de `transaction_id`
  à une table pivot est une migration mécanique, et les sites de lecture sont
  déjà neutralisés par `transactionsAcquisition()` (§ 4.1). Surtout, le calcul est
  immunisé par construction : la règle du § 6 absorbe une base qui change en cours
  de vie exactement comme elle absorbe une durée corrigée — le rattrapage tombe
  sur la dotation suivante, sans recalcul rétroactif ni écriture de correction.
  Reste une question sémantique, à trancher le moment venu et sans contrainte du
  schéma : un second achat re-base-t-il la fiche, ou crée-t-il un « composant »
  avec sa propre mise en service ? Le PCG admet les deux.
- **TVA.** Rien à préparer ici : le modèle est déjà neutre (§ 4.1). Le seul point
  à retenir pour le futur chantier est que les immobilisations ont leur **propre
  compte de TVA déductible, le 44562**, distinct du 44566 des autres biens et
  services. L'écriture d'acquisition gagnera une troisième ligne dans
  `EcritureGenerator` ; la fiche et la dotation ne changent pas.

  Aucun champ « réservé pour usage futur » n'est ajouté. Non validés et non
  alimentés, ces champs finissent vides indéfiniment ou remplis avec la mauvaise
  sémantique, et aucun test ne peut porter sur une règle qui n'est pas écrite.
- **Reprise de l'existant** — fiches historiques plus écriture d'ouverture
  21X D / 281X C, si les comptes de l'expert-comptable portent un jour des
  immobilisations que le registre ignore.
