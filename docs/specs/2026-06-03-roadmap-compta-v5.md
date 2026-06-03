# Roadmap Compta V5 — chantiers ordonnés

**Date** : 2026-06-03
**Branche** : `feat/compta-v5` (NON mergée — `main` reste en v4.3.x).
**Mémoire liée** : [[project-compta-v5-flux-bancaires-live-pd]] et les sous-slices 1a→1d / cutover.

## Principe d'exécution

- **Un chantier par session** : on lance les sujets les uns après les autres pour ménager le contexte de l'agent et la qualité du code produit.
- **Clones prod → localhost** (migration + backfill + smoke-test) **intercalés** entre certains chantiers, pour recetter sur de vraies données. (Script `scripts/clone-prod-to-localhost.sh` — fait clone + `migrate` + `compta:backfill-partie-double --all` + `COMPTA_USE_PARTIE_DOUBLE=true` + smoke-test.)
- **⚠️ Garde-fou** : ne JAMAIS laisser un subagent lancer `migrate:fresh` / `sail test` sans border l'accès DB (un config cache figé sur mysql peut détruire le clone — incident 2026-06-02).
- **Cutover / merge → main = horizon LOINTAIN.** Il faut une version vraiment stable et robuste avant de merger. En attendant, tout vit sur `feat/compta-v5`, `main` reste **v4.3**.

## Acquis (déjà livrés sur `feat/compta-v5`)

- **Fondations PD** : data layer, école 411 systématique, backfill, cutover (sous-slices 1a→1d).
- **Slice journal de banque** : colonne `journal`, masquage T2/T4 des écrans opérationnels.
- **Flux bancaires live** : Bug 1 (`comptabilisee_at`), Bug 2/3 (filtre `journal` sur candidats/agrégats), Bug 4a (pointage remise meut le solde), **Bug 4b (dépôt au pointage — À REVERTER, cf. chantier 1)**.
- **Volet A** : saisie créance (recette « attendue », mode null → `pourRecetteACredit`), « Marquer reçu » avec capture du mode, réversion « reçu → non reçu ».
- **Correctifs** : `equilibree` après enrichissement PD, backfill `comptabilisee_at`, exclusion HelloAsso de la modale, total crédit rappro (×N → opérationnel).

---

## Chantiers (ordre d'exécution)

### 1. Revert auto-remise + rapprochement sur critère 512X
**Intention** : abandonner la génération **auto** d'une remise au pointage (marginal, générateur d'édge cases). Le rapprochement ne liste que les écritures **présentes sur le 512X (5121)** — un chèque encaissé **non remis** (5112) n'est **pas** pointable ; on le **remet d'abord** (cas général). Aligne la liste du rappro sur `calculerSoldePointage` (qui somme déjà les lignes 512X).
**Périmètre** : revert `remettreAutoPourRapprochement` / `genererDepotChequeLoose` / helpers / colonne `auto_generee` / tests / liste remises ; filtre du rappro « porte une ligne 512X ». Vérifier le cas dépense chèque émis (512X direct, reste pointable).
**Dépendances** : aucune. **Premier** (repart sur une base saine).

### 2. Cohérence « marquer reçu » ↔ toggle « reçu » de l'édition
**Intention** : aujourd'hui le **bouton « Marquer reçu »** crée une **T2 séparée**, mais l'**édition** (toggle `paiementRecu`) crée un encaissement **lumpé** sur la même transaction. Deux chemins → deux structures. Harmoniser sur **un seul modèle** (sinon divergence en B et à l'affichage des écritures).

### 3. Volet 1A symétrique — charges (dette fournisseur 401)
**Intention** : miroir du Volet A côté **dépenses**. Saisir une **dette fournisseur** (`60x D / 401 C` via `pourDepenseACredit`, déjà existant), **« Marquer payé »** (`401 D / 512X C` via `pourReglementFournisseur`, déjà existant), réversion. **Le moteur existe — manque l'UI** (le formulaire force le mode pour les dépenses → toujours comptant).

### 4. Volet B — statuts dérivés du grand livre (symétrique 411 + 401)
**Intention** : le statut cesse d'être un **enum stocké** et devient **dérivé du ledger** (source de vérité unique). Symétrique :
- recette : **attendu / à remettre / remis / rapproché** (411 → 5112 → 512X → pointé) ;
- dépense : **dû / réglé / pointé** (401 → 512X → pointé).
**Dépendances** : **APRÈS 1A** (sinon pas de cycle 401 à dériver). Spec existante `2026-06-02-cycle-vie-creance-statut-derive.md` — **à élargir au 401**.

### 5. Lettrage humainement lisible (AAAA → ZZZZ par compte)
**Intention** : remplacer les codes random 20 caractères (`Str::random(20)`) par une **séquence lisible par compte** (convention compta : `AA`, `AB`, … par compte lettrable). Indispensable pour la phase d'**affichage des écritures**.

### 6. États comptables : balance 4/5 + grand livre par compte
**Intention** : produire un **état balance** des comptes de **classes 4 et 5** + un **grand livre par compte** (toutes les écritures d'un compte, avec lettrage). Repose sur le ledger PD.

### 7. Numérotation des transactions par journal
**Intention** : chaque **journal × exercice** a sa séquence ; poser la **référence métier** (T4 remise = n° de bordereau `RBC-xxxxx`, T2 = n° du journal de banque). **Aujourd'hui les transactions banque n'ont pas de référence.** (= Slice 2 du journal de banque, différée.)

### 8. Virements internes en V5 — écriture `512 → 512`
**Intention** : convertir `VirementInterne` (modèle **parallèle**, hors ledger PD) en vraies **écritures du journal de banque** (`512 → 512`). Complétude du ledger + **cohérence du rapprochement** (le critère « porte une ligne 512X » du chantier 1 ne s'applique pas aux virements tant qu'ils sont hors ledger).

### 9. Ventilation sur pièce pointée — **brainstorm à venir**
**Intention** : **trou de conception V5**. En **V4**, la ventilation vit dans une **table d'affectations séparée** → non bloquée par le pointage. En **V5**, elle devient un **vrai jeu d'écritures** (découper la ligne `7x/6x` en N lignes, même total, même imputation). Le **verrou de rapprochement bloque à tort** (le `4x/5x` banque est inchangé). À **brainstormer → spec** (modèle : ventiler le côté produit/charge d'une pièce déjà pointée, total + 512X gelés).

### 10. Drop du legacy (SousCategorie + colonnes legacy)
**Intention** : une fois le PD **source de vérité partout**, retirer les structures parallèles (Steps 39/40 parqués). **Fin de parcours.**

### 11. Slice 3 — affichage des écritures et des journaux
**Intention** : l'UI qui montre le **grand livre par journal** (Ventes / Achats / Banque / OD), remplaçant la présentation recettes/dépenses, et bascule le **vocabulaire visible**.
**Dépendances** : **APRÈS 5** (lettrage lisible) **+ 7** (numérotation) **+** idéalement **6** (états).

---

## Jalon terminal

**Cutover / merge `feat/compta-v5` → `main` (prod).** Horizon lointain. Prérequis : version **stable + robuste**, recettée sur clone prod. En attendant, `main` reste **v4.3.x**.
