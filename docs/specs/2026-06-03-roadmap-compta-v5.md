# Roadmap Compta V5 — chantiers ordonnés

**Date** : 2026-06-03 (restructurée le 2026-06-03 après l'audit des flux métier).
**Vision cible réconciliée le 2026-06-22** — voir la section ci-dessous (nord du programme).
**Branche** : `feat/compta-v5` (NON mergée — `main` reste en v4.3.x).
**Mémoire liée** : [[project-compta-v5-flux-bancaires-live-pd]] et les sous-slices 1a→1d / cutover.
**Audit des flux** : `docs/audits/2026-06-03-audit-flux-compta-v5.md` (5 flux : dons, cotisations, NDF, NDF par abandon de créance, HelloAsso + virement).

---

## Vision cible — réconciliée 2026-06-22

> **Statut** : nord du programme. Remplace l'orientation « cash basis enrichie » de la mémoire `project_compta_partie_double.md` (2026-05-02), désormais **obsolète sur l'orientation** mais conservée pour sa valeur de référence (frontière fiscale FEC, briques TVA/immo, connecteur paye « integrate not build », précédents marché). La **partie double uniforme** a tranché (ADR-003 + `docs/compta-partie-double.md`).

**Au cutover prod, compta-v5 est un vrai logiciel comptable** : des écritures sur des comptes dont les **soldes sont consultables via des balances**, **justifiés par des grands livres**. Les comptes de tiers (411 clients / 401 fournisseurs) sont **lettrables et lettrés le plus automatiquement possible**.

### Deux modes produit

| Mode | IHM | Écritures | Modules |
|---|---|---|---|
| **Recettes-dépenses** | Identique à l'actuelle (nouvelle dépense / recette, marquer reçu, remise bancaire, rapprochement) | Générées, journalisées, lettrées, équilibrées **en arrière-boutique** (invisibles à l'utilisateur) | — |
| **Partie double** | La **même** IHM continue de fonctionner, mais **chaque transaction montre ses écritures, et elles sont modifiables** | Visibles + éditables | **Activables** : TVA, immobilisations ; **saisie d'OD** ; **clôtures mensuelles** ; **clôture annuelle** (à-nouveaux + reprise des écritures non lettrées) |

Dans **les deux modes** : les écrans **Règlement** et **Encadrants** (montants prévisionnels portés sur les opérations) sont traduits en **écritures « prévisionnelles »**, consommées par les états pour calculer des prévisions **quand le toggle prévision correspondant est activé**.

### Vocabulaire fixé (piège à éviter)

- **`use_partie_double`** (`config/compta.php`) = **flag de cutover technique** : décide si les *rapports* lisent les colonnes PD ou legacy. **Voué à disparaître** post-cutover. **Ce n'est PAS** le mode produit.
- **Mode produit** (Recettes-dépenses vs Partie double) = réglage **par association** qui décide la **visibilité / édition des écritures** + l'accès OD / modules. **À modéliser** (probable réglage `Association`, distinct du flag de cutover).

### Décision 2026-06-22 — dissolution `sous_categories` → `comptes` en FONDATION

`sous_categories` (notion V4, classes 6/7 seulement) est **dissoute dans `comptes`** (source de vérité unique, toutes classes 1-7). Exécutée **avant P1** (états), par **choix de propreté assumé** — pour ne rien bâtir de neuf sur le modèle dual alors qu'« on a beaucoup à construire par-dessus ce socle ».

> **Caveat tracé (décision en connaissance de cause)** : P1 (balance, grand livre, affichage des écritures) lit **déjà** le ledger PD (`transaction_lignes.compte_id + comptes`), **pas** `sous_categorie_id` — il n'exige donc **pas techniquement** la dissolution. Le déclencheur *strict* reste le **mode OD** (chemin unique de création de compte, au lieu de « sous-cat IHM → observer matérialise » + « OD crée direct ») **+** le **retrait des rapports legacy**. On l'avance en fondation **par décision, pas par contrainte**. Effet de bord acquis : ferme **structurellement l'angle mort HelloAsso** (enrichissement PD best-effort, invisible à la garde). **Cette décision remplace le positionnement « fin de parcours » de l'item 10 (Phase 3) et du §8 de `docs/compta-partie-double.md`.**

### Cible élargie — à séquencer (prochaine étape : roadmap)

Au-delà des états (P1), le programme comprend : **saisie d'OD**, **clôtures mensuelles**, **clôture annuelle** (à-nouveaux + reprise des écritures non lettrées), module **TVA**, module **immobilisations**, **écritures prévisionnelles** (Règlement / Encadrants + toggle prévision). **L'ordonnancement détaillé de ces slices est l'objet de la prochaine session** (séquencement complet de la roadmap). La présente section fixe la *cible* ; elle n'ordonne pas encore ces chantiers.

---

## Principe d'exécution

- **Un chantier par session** : on lance les sujets les uns après les autres pour ménager le contexte de l'agent et la qualité du code produit.
- **Clones prod → localhost** (migration + backfill + smoke-test) **intercalés** entre certains chantiers, pour recetter sur de vraies données. (Script `scripts/clone-prod-to-localhost.sh` — fait clone + `migrate` + `compta:backfill-partie-double --all` + `COMPTA_USE_PARTIE_DOUBLE=true` + smoke-test.)
- **⚠️ Garde-fou** : ne JAMAIS laisser un subagent lancer `migrate:fresh` / `sail test` sans border l'accès DB (un config cache figé sur mysql peut détruire le clone — incident 2026-06-02).
- **Cutover / merge → main = horizon LOINTAIN.** Il faut une version vraiment stable et robuste avant de merger. En attendant, tout vit sur `feat/compta-v5`, `main` reste **v4.3**.

## Acquis (déjà livrés sur `feat/compta-v5`)

- **Fondations PD** : data layer, école 411 systématique, backfill, cutover (sous-slices 1a→1d).
- **Slice journal de banque** : colonne `journal`, masquage T2/T4 des écrans opérationnels.
- **Flux bancaires live** : Bug 1 (`comptabilisee_at`), Bug 2/3 (filtre `journal` sur candidats/agrégats), Bug 4a (pointage remise meut le solde), Bug 4b (dépôt au pointage — **reverté** par le chantier 1).
- **Volet A** : saisie créance (recette « attendue », mode null → `pourRecetteACredit`), « Marquer reçu » avec capture du mode, réversion « reçu → non reçu ».
- **Correctifs** : `equilibree` après enrichissement PD, backfill `comptabilisee_at`, exclusion HelloAsso de la modale, total crédit rappro (×N → opérationnel).
- ✅ **Chantier 1 livré (2026-06-03)** : revert auto-remise + rapprochement sur le **512X strict du compte** ; + fix « bouton Comptabiliser » sur la page remise (régression v3 `3d7f7b32`) + suppression de l'écran `validation` orphelin. Suite verte 12 488 / 0. Commits `7a639282`, `d829e3ed`, `46fb8fb5`.
- ✅ **Chantier 2a / 3a livrés (2026-06-04)** : recette comptant + charges 401 (dette/Marquer payé/réversion) en T2 séparée live.
- ✅ **Chantier 4 livré (2026-06-05)** : **statut de règlement dérivé du grand livre** (411/401), source de vérité unique. Enum `EnMain` ajoutée (zéro rename, migration additive), `EtatReglementResolver` (resolve multi-hop `411/401→5112/530→512X` + syncer miroir gardé par le flag PD), câblé aux transitions (create/update+réversion, marquerRecu/Payé, pointage/dépointage, toggleRemise, comptabiliser, remise modifier/supprimer/brouillon, facture encaissement), data-migration `recu→en_main`, commande `compta:reconcilier-statuts` (rempart anti-dérive), libellés direction-aware (Dû/À remettre/Remis-Réglé/Pointé) + badges `en_main`. **Dissout l'audit Thème B** + corrige le bug réversion (statut périmé). Suite verte (exit 0, 12 642 assertions). ~16 commits. Revue finale a colmaté 4 dérives miroir (remise modifier/supprimer/brouillon + facture) + 4 sites UI aveugles. **Recette localhost à faire.**

---

## Structure : un socle horizontal, puis les flux verticaux qui s'y branchent

Le moteur PD (`EcritureGenerator`, école 411, invariants d'équilibre/tiers) est **sain**. L'audit montre que les trous sont (1) dans le **cycle de vie autour** du moteur — saisie → écriture → règlement → statut — et (2) dans des **flux qui n'appellent pas le moteur**. D'où la stratégie : **durcir le socle (cycle de vie) AVANT de brancher les flux dessus** — on ne peut brancher un flux « sur du PD sain » que si le cycle de vie est sain.

> **Note de numérotation** : les numéros 2→11 sont les identifiants historiques des chantiers (référencés dans la mémoire) ; l'ordre d'exécution réel est désormais donné par les **phases** ci-dessous.

---

## Phase 0 — Quick fixes (immédiat, hors file)

### QF-B — Statut de règlement posé à la création (recette comptant) ✅ LIVRÉ 2026-06-03 (commit `97a061ee`)
**Bug** (audit Thème B, **BLOQUANT**) : `TransactionForm::save()` ne pose jamais `statut_reglement` → un don/recette **comptant** naît `en_attente` → reçu fiscal bloqué, statut faux, « Marquer reçu » fait un skip silencieux (411 déjà lettré).
**Fix étroit** : poser `statut_reglement = Recu` quand `paiementRecu = true`. **Stopgap** en attendant le chantier 4 (statut dérivé du ledger, qui le dissout structurellement). Réf : `TransactionForm.php:577-591`.

### ~~QF-D — Abandon de créance → OD~~ → **reclassé : déplacé dans FX-NDF (Phase 2)**
Après analyse (2026-06-03), ce n'est pas un quick fix : il faut une **nouvelle méthode OD** `4xx D / 75x C` + le re-câblage de `validerAvecAbandonCreance` + la mise à jour de 4 fichiers de tests. C'est la **même refonte que FX-NDF**, avec le compte final **467**. Décision : le faire **une seule fois** dans FX-NDF. (Non bloquant recette : la pollution 512X n'apparaît que si abandon de créance **+** rapprochement.)

---

## Phase 1 — Socle : le cycle de vie d'une opération en PD

### 2. Règlement recette — converger sur le modèle « T2 séparée »
**Intention** : le **bouton « Marquer reçu »** crée une **T2 séparée**, mais l'**édition** comptant (toggle `paiementRecu`) crée un encaissement **lumpé** sur la même transaction. Deux structures pour le même fait. **Cible** : recette comptant = T1 créance (`411 D / 7xx C`, Vente) + T2 encaissement (`portage D / 411 C`, Banque, 411 lettré inter-tx), identique à « créance puis Marquer reçu ».
- **2a — chemin live** ✅ **LIVRÉ 2026-06-04** (commits `a5535d29` + `6079ff9a`, suite 12 529 / 0). `TransactionService::enrichirPartieDouble` : recette comptant → `pourRecetteACredit` + `pourEncaissementCreance`. Réversion uniforme via `annulerEncaissementSiReversion`. `pourRecetteComptant` conservé (bloc de tests). Intègre QF-B. Collatéral `RapprochementBancaireService` (propagation T2 sans garde flag + exclusion T2 du SUM legacy). Fixtures Console adaptées (purge T2 orphelines avant remise-en-legacy). **Recette localhost à faire.**
- **2b — backfill** ✅ **LIVRÉ 2026-06-08** (`4d915272`). `TransactionConverter` comptant recette → `pourRecetteACredit` + `pourEncaissementCreance` (T2 séparée), `comptePortageOverride` sur `pourEncaissementCreance` pour chèque pointé direct, propagation `rapprochement_id` T1→T2, reset `--force` supprime T2 orphelines. Tests AC, E2E et [E8] adaptés. Suite 4102 assertions / 0 fail.

### 3. Volet 1A symétrique — charges (dette fournisseur 401)
**Intention** : miroir dépense du couple Volet A + chantier 2a. Compte **401** (fournisseur générique ; le **467** spécifique NDF reste FX-NDF, Phase 2). **Non-compensation** : 401 et 411 restent distincts en compta (la fiche tiers 360 ne les agrège qu'en vue *relationnelle*, pas comme solde comptable). Le moteur existe (`pourDepenseACredit`, `pourReglementFournisseur`) — manque l'UI + le service.
- **3a-i (live, comptant)** ✅ **LIVRÉ 2026-06-04** (`37de19c7`, suite 12 583/0) : `enrichirPartieDouble` dépense comptant → `pourDepenseACredit` + `pourReglementFournisseur` (T1 dette Achat + T2 règlement Banque, 401 lettré) — analog de 2a. + `ReglementOperationService::trouverReglementT2` + propagation `rapprochement_id` sur la T2 dépense au pointage (sinon **régression rappro dépense** : le 512X passe sur la T2). + statut dépense `Recu`/réglé à la création. La garde legacy-SUM de 2a (`journal=Banque AND remise_id NULL`) couvre déjà la T2 dépense.
- **3a-ii (live, paiement différé)** ✅ **LIVRÉ 2026-06-04** (`d59b55ac`, suite 12 583/0 ; recette localhost à faire) : toggle « paiement effectué ? » sur dépense (non payé → mode null → `pourDepenseACredit` dette ouverte) + `ReglementOperationService::marquerPaye` + `reglerSiNonRegle` + bouton « Marquer payé » + réversion payé→non-payé (`annulerReglementSiReversion`). Résout le gros du **Thème C**.
- **3b (backfill)** ✅ **LIVRÉ 2026-06-08** (`4d915272`, même commit que 2b). `TransactionConverter` comptant dépense → `pourDepenseACredit` + `pourReglementFournisseur` (T2 séparée), propagation `rapprochement_id` T1→T2. Convergence complète : backfill = live pour recettes ET dépenses.
**Dépendances** : aucune pour 3a. 2b/3b livrés post-chantier 4 (le resolver était déjà robuste aux deux structures).

### 4. Volet B — statuts dérivés du grand livre (symétrique 411 + 401) — ✅ LIVRÉ 2026-06-05
**Spec/plan** : `docs/specs/2026-06-04-statut-reglement-derive-grand-livre.md` + `plans/2026-06-04-chantier4-statut-derive-411-401.md`. **Décisions d'exécution** : 2b/3b NON prérequis (resolver robuste aux 2 structures), enum direction-neutre **sans rename** (ajout `EnMain` seul, migration additive), miroir recalculé par `syncer` (legacy fallback + override PD). Voir la mémoire `project_compta_v5_chantier4_statut_derive.md`.
**Intention** : le statut cesse d'être un **enum stocké** et devient **dérivé du ledger** (source de vérité unique). Symétrique :
- recette : **attendu / à remettre / remis / rapproché** (411 → 5112 → 512X → pointé) ;
- dépense : **dû / réglé / pointé** (401 → 512X → pointé).

**Cas concret à couvrir (trouvé en recette 2a, 2026-06-04)** : une recette « reçue » repassée en non-reçue (réversion) **garde aujourd'hui `statut_reglement = Recu` à tort** — l'enum stocké n'est jamais reposé par la réversion (`annulerEncaissementSiReversion` supprime la T2 mais ne touche pas le statut ; QF-B ne pose le statut qu'à la création). Le statut dérivé doit recalculer « en attente » (411 non lettré + pas de 512X). À couvrir symétriquement recette **et** dépense. (Décision 2026-06-04 : pas de stopgap, on laisse le chantier 4 le dissoudre.)
→ **Dissout structurellement l'audit Thème B** (le « comptant naît en_attente » disparaît : le statut se dérive du 411 lettré / 512X présent).
**Dépendances** : **APRÈS chantier 3** (cycle 401 à dériver) **+ chantier 2b** (convergence backfill → structure T2 uniforme à dériver, sinon il faut dériver le statut sur deux structures). Spec existante `2026-06-02-cycle-vie-creance-statut-derive.md` — **à élargir au 401**.

### G. Garantie de non-échappement PD *(nouveau — audit Thème A)*
**Intention** : aucune transaction ne doit exister sans écriture PD **équilibrée** en mode PD. Ferme les skips silencieux (wizard adhésion, ligne km sans usage configuré → skip de **toute** la transaction, don sans tiers).
**Découpage** : (1) d'abord un **rapport** — étendre `compta:smoke-test-v5` pour lister les transactions sans lignes PD / non équilibrées en mode PD ; (2) puis un **garde-fou bloquant**, activé **en capstone de Phase 2** (sinon il casserait immédiatement sur wizard/HelloAsso non encore corrigés).
- **G volet 1 (rapport)** ✅ **LIVRÉ 2026-06-08** : `compta:smoke-test-v5` étendu — nouvelle colonne « Tx sans PD » dans le tableau récapitulatif, résumé par source (HelloAsso / Adhésion wizard / NDF / Saisie manuelle) avec raison probable du skip (tiers_id null / ligne sans sous-catégorie / usage comptable non configuré / bypass TransactionService), option `--detail` pour le tableau ligne par ligne. 6 tests Pest ajoutés. Exit code 1 si des Tx sans PD détectées.

---

## Phase 2 — Brancher les flux audités sur le socle (1 flux = 1 sous-chantier)

**Dépend de la Phase 1** : on branche les flux sur un cycle de vie (statut + règlement symétrique) déjà sain.

### ✅ FX-Cotisation — Adhésions/cotisations via le moteur PD — LIVRÉ 2026-06-08
Audit Thèmes A/E/H : le **wizard d'adhésion** (`AdhesionService::creerTransactionPaiement`) routé via `TransactionService::create()` → enrichissement PD complet (411 D / 7xx C + T2 encaissement + syncer statut dérivé + garde exercice ouvert). Flag `AdhesionTransactionLigneObserver::$suppress` inhibe les 2 observers adhésion (TransactionLigne + Transaction) pour éviter la double création. 6 tests TDD [A]-[F], 195 tests adhésion verts (523 assertions). Reste à brancher `tiers_payeur_id` (spec PASS `…tiers-payeur-cotisation…`).

### FX-HelloAsso — HelloAsso en PD « live »
Audit Thèmes A/F : `HelloAssoSyncService` crée des transactions legacy, **PD différé à un backfill manuel sans auto-trigger**. Cibler l'enrichissement PD **à la création** (ou auto-backfill post-sync) ; cash-out `512→512` (rejoint chantier 8) ; **propagation `rapprochement_id` sur la T2 déjà encaissée** dans `createVerrouilleAuto` (Thème F) ; garde `compte_versement_id` null (fallback silencieux) ; cas montant 0 (promo 100 %).

### ✅ FX-NDF — Notes de frais sur le socle « charges » — LIVRÉ 2026-06-08
Validation NDF → T1 seul (6xx D / 401 C, `mode_paiement` null pour empêcher T2 prématuré), statut dérivé `EnAttente`. Abandon de créance refactoré en dual-path (PD: `EcritureGenerator::pourAbandonCreance` OD 401 D / 7xx C + auto-lettrage, pas de ligne 512X ; legacy: Dépense Recu + Don Recette inchangé). `EtatReglementResolver` enrichi : navigation T2→T1 en arrière (fallback stored), abandon de créance (Recu sans trésorerie). 6 tests TDD [A]-[F], 370 tests NDF verts.

### ✅ FX-Don — Dons & reçu fiscal — LIVRÉ 2026-06-08
Audit Thème G : le flux don passe déjà par `TransactionService::create()` (PD natif). Corrections reçu fiscal : garde explicite « don sans tiers » (`RecuFiscalException::donateurManquant()`), montant basculé sur `credit` PD (fallback `montant` legacy) via helper `montantRecu()` — anticipe le drop legacy. Le couplage PD du reçu est résolu par le chantier 4 (statut dérivé du grand livre). 3 tests TDD [A]-[C], 85 tests RecuFiscal verts.

### Capstone Phase 2
Activer le **garde-fou bloquant** de non-échappement PD (chantier **G**, volet 2) une fois tous les flux ci-dessus corrigés.

---

## Phase 3 — États, affichage & fin de parcours

### 5. Lettrage humainement lisible (AAAA → ZZZZ par compte)
**Intention** : remplacer les codes random 20 caractères (`Str::random(20)`) par une **séquence lisible par compte** (convention compta : `AA`, `AB`, … par compte lettrable). Indispensable pour la phase d'**affichage des écritures**.

### 6. États comptables : balance 4/5 + grand livre par compte
**Intention** : produire un **état balance** des comptes de **classes 4 et 5** + un **grand livre par compte** (toutes les écritures d'un compte, avec lettrage). Repose sur le ledger PD.

### 7. Numérotation des transactions par journal
**Intention** : chaque **journal × exercice** a sa séquence ; poser la **référence métier** (T4 remise = n° de bordereau `RBC-xxxxx`, T2 = n° du journal de banque). **Aujourd'hui les transactions banque n'ont pas de référence.** (= Slice 2 du journal de banque, différée.)

### 8. Virements internes en V5 — écriture `512 → 512` — ✅ LIVRÉ 2026-06-17
**Intention** : convertir `VirementInterne` (modèle **parallèle**, hors ledger PD) en vraies **écritures du journal de banque** (`512 → 512`). Complétude du ledger + **cohérence du rapprochement**. Déjà sollicité par **FX-HelloAsso** (cash-out HelloAsso = un `VirementInterne` sans lignes PD).
**Livré** : `EcritureGenerator::pourVirementInterne()` (512 source C / 512 dest D, journal Banque), câblé dans `VirementInterneService` create/update/delete. 17 tests. Voir `project_compta_v5_priorites_phase3`.

### 9. Ventilation sur pièce pointée — **brainstorm à venir**
**Intention** : **trou de conception V5**. En **V4**, la ventilation vit dans une **table d'affectations séparée** → non bloquée par le pointage. En **V5**, elle devient un **vrai jeu d'écritures** (découper la ligne `7x/6x` en N lignes, même total, même imputation). Le **verrou de rapprochement bloque à tort** (le `4x/5x` banque est inchangé). À **brainstormer → spec** (modèle : ventiler le côté produit/charge d'une pièce déjà pointée, total + 512X gelés).

### 11. Slice 3 — affichage des écritures et des journaux
**Intention** : l'UI qui montre le **grand livre par journal** (Ventes / Achats / Banque / OD), remplaçant la présentation recettes/dépenses, et bascule le **vocabulaire visible**.
**Dépendances** : **APRÈS 5** (lettrage lisible) **+ 7** (numérotation) **+** idéalement **6** (états).

### 10. ~~Drop du legacy (SousCategorie + colonnes legacy) — « fin de parcours »~~ → **REPOSITIONNÉ EN FONDATION (décision 2026-06-22)**
**Ancien positionnement (caduc)** : drop des structures parallèles en toute fin, une fois le PD source de vérité partout.
**Nouveau** : la **dissolution `sous_categories` → `comptes`** (source de vérité unique) est promue **slice fondation, avant P1** — voir la section **« Vision cible » → Décision 2026-06-22** en tête de ce document. Le drop des colonnes legacy résiduelles (`transactions.type`, `transaction_lignes.montant`) et le retrait des rapports legacy restent, eux, en fin de parcours (gardés par le flag `use_partie_double` jusqu'au cutover).

---

## Jalon terminal

**Cutover / merge `feat/compta-v5` → `main` (prod).** Horizon lointain. Prérequis : version **stable + robuste**, recettée sur clone prod. En attendant, `main` reste **v4.3.x**.
