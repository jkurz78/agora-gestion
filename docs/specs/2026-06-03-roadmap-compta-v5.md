# Roadmap Compta V5 — chantiers ordonnés

**Date** : 2026-06-03 (restructurée le 2026-06-03 après l'audit des flux métier).
**Branche** : `feat/compta-v5` (NON mergée — `main` reste en v4.3.x).
**Mémoire liée** : [[project-compta-v5-flux-bancaires-live-pd]] et les sous-slices 1a→1d / cutover.
**Audit des flux** : `docs/audits/2026-06-03-audit-flux-compta-v5.md` (5 flux : dons, cotisations, NDF, NDF par abandon de créance, HelloAsso + virement).

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
- **2b — backfill** ⏸️ **DIFFÉRÉ — NE PAS OUBLIER** (décision 2026-06-03) : `TransactionConverter` (ligne ~222 : recette comptant → même modèle T2 séparée). **Prérequis du chantier 4** (statut dérivé : structure uniforme live+historique requise). Touche les tests backfill/smoke/équivalence. À faire **juste avant le chantier 4**. Tant que 2b n'est pas fait : divergence assumée live (T2) ↔ historique backfillé (lumpé), non visible dans les écrans opérationnels.

### 3. Volet 1A symétrique — charges (dette fournisseur 401)
**Intention** : miroir dépense du couple Volet A + chantier 2a. Compte **401** (fournisseur générique ; le **467** spécifique NDF reste FX-NDF, Phase 2). **Non-compensation** : 401 et 411 restent distincts en compta (la fiche tiers 360 ne les agrège qu'en vue *relationnelle*, pas comme solde comptable). Le moteur existe (`pourDepenseACredit`, `pourReglementFournisseur`) — manque l'UI + le service.
- **3a-i (live, comptant)** ✅ **LIVRÉ 2026-06-04** (`37de19c7`, suite 12 583/0) : `enrichirPartieDouble` dépense comptant → `pourDepenseACredit` + `pourReglementFournisseur` (T1 dette Achat + T2 règlement Banque, 401 lettré) — analog de 2a. + `ReglementOperationService::trouverReglementT2` + propagation `rapprochement_id` sur la T2 dépense au pointage (sinon **régression rappro dépense** : le 512X passe sur la T2). + statut dépense `Recu`/réglé à la création. La garde legacy-SUM de 2a (`journal=Banque AND remise_id NULL`) couvre déjà la T2 dépense.
- **3a-ii (live, paiement différé)** ✅ **LIVRÉ 2026-06-04** (`d59b55ac`, suite 12 583/0 ; recette localhost à faire) : toggle « paiement effectué ? » sur dépense (non payé → mode null → `pourDepenseACredit` dette ouverte) + `ReglementOperationService::marquerPaye` + `reglerSiNonRegle` + bouton « Marquer payé » + réversion payé→non-payé (`annulerReglementSiReversion`). Résout le gros du **Thème C**.
- **3b (backfill)** ⏸️ **DIFFÉRÉ** (comme 2b) : `TransactionConverter` dépense comptant → T2 séparée. **Prérequis chantier 4**.
**Dépendances** : aucune pour 3a. 3b prérequis chantier 4.

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

---

## Phase 2 — Brancher les flux audités sur le socle (1 flux = 1 sous-chantier)

**Dépend de la Phase 1** : on branche les flux sur un cycle de vie (statut + règlement symétrique) déjà sain.

### FX-Cotisation — Adhésions/cotisations via le moteur PD
Audit Thèmes A/E/H : le **wizard d'adhésion** (`AdhesionService::creerTransactionPaiement`) crée Transaction+ligne en direct, **sans `TransactionService`** → aucune écriture PD. Le router par le moteur (`enrichirPartieDouble`), compte produit **751**, garde `ExerciceService::assertOuvert`. Brancher `tiers_payeur_id` (spec PASS `…tiers-payeur-cotisation…` : la ligne 411 doit porter le **payeur**).

### FX-HelloAsso — HelloAsso en PD « live »
Audit Thèmes A/F : `HelloAssoSyncService` crée des transactions legacy, **PD différé à un backfill manuel sans auto-trigger**. Cibler l'enrichissement PD **à la création** (ou auto-backfill post-sync) ; cash-out `512→512` (rejoint chantier 8) ; **propagation `rapprochement_id` sur la T2 déjà encaissée** dans `createVerrouilleAuto` (Thème F) ; garde `compte_versement_id` null (fallback silencieux) ; cas montant 0 (promo 100 %).

### FX-NDF — Notes de frais sur le socle « charges »
Audit Thèmes C/E/A/H : une fois le chantier 3 livré, faire produire à la NDF une **dette ouverte** (`6xx D / 467 C`) puis un **remboursement** réel (T2 `467 D / 512X C` via `pourReglementFournisseur`). Compte **467** (au lieu de 401). Garde sur la **ligne km sans usage** configuré (sinon skip de toute la transaction). Garde **exercice ouvert** à la soumission portail (sinon NDF bloquée en `soumise`).
- **Abandon de créance (ex-QF-D)** : router l'abandon en **OD** `467 D / 75x C` (lettrage du 467), **sans ligne 512X** → retire les 2 faux mouvements qui polluent le rappro du chantier 1 ; journal OD (pas Vente) ; `mode_paiement` null sur le don ; nouvelle méthode `EcritureGenerator::pourAbandonCreance`. MAJ tests `ValiderAvecAbandonCreanceTest` + E2E + `ConstaterAbandonTest` + `AbandonCreanceNonAffichageTest`.

### FX-Don — Dons & reçu fiscal
Audit Thème G : **coupler le reçu fiscal à l'écriture PD** (pas seulement à `statut_reglement.isEncaisse()`), garde « don sans tiers » (skip silencieux aujourd'hui), fiabiliser le montant du reçu sur `debit/credit` plutôt que la colonne legacy `montant` (anticipe le drop legacy).

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

### 8. Virements internes en V5 — écriture `512 → 512`
**Intention** : convertir `VirementInterne` (modèle **parallèle**, hors ledger PD) en vraies **écritures du journal de banque** (`512 → 512`). Complétude du ledger + **cohérence du rapprochement**. Déjà sollicité par **FX-HelloAsso** (cash-out HelloAsso = un `VirementInterne` sans lignes PD).

### 9. Ventilation sur pièce pointée — **brainstorm à venir**
**Intention** : **trou de conception V5**. En **V4**, la ventilation vit dans une **table d'affectations séparée** → non bloquée par le pointage. En **V5**, elle devient un **vrai jeu d'écritures** (découper la ligne `7x/6x` en N lignes, même total, même imputation). Le **verrou de rapprochement bloque à tort** (le `4x/5x` banque est inchangé). À **brainstormer → spec** (modèle : ventiler le côté produit/charge d'une pièce déjà pointée, total + 512X gelés).

### 11. Slice 3 — affichage des écritures et des journaux
**Intention** : l'UI qui montre le **grand livre par journal** (Ventes / Achats / Banque / OD), remplaçant la présentation recettes/dépenses, et bascule le **vocabulaire visible**.
**Dépendances** : **APRÈS 5** (lettrage lisible) **+ 7** (numérotation) **+** idéalement **6** (états).

### 10. Drop du legacy (SousCategorie + colonnes legacy)
**Intention** : une fois le PD **source de vérité partout** (et la garantie de non-échappement active), retirer les structures parallèles (Steps 39/40 parqués). **Fin de parcours.**

---

## Jalon terminal

**Cutover / merge `feat/compta-v5` → `main` (prod).** Horizon lointain. Prérequis : version **stable + robuste**, recettée sur clone prod. En attendant, `main` reste **v4.3.x**.
