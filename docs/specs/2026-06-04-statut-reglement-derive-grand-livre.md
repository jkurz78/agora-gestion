# Statut de règlement dérivé du grand livre — symétrique 411 / 401 (design)

**Date** : 2026-06-04
**Branche** : `feat/compta-v5` (NON mergée — `main` reste v4.3.x).
**Chantier roadmap** : **Chantier 4 — Volet B** ([docs/specs/2026-06-03-roadmap-compta-v5.md](2026-06-03-roadmap-compta-v5.md), Phase 1).
**Étend / finalise** : [2026-06-02-cycle-vie-creance-statut-derive.md](2026-06-02-cycle-vie-creance-statut-derive.md) — dont le **Volet A (saisie créance + Marquer reçu/payé + réversion)** est **déjà livré** (chantiers 2a/3a). Ce document spécifie le **Volet B (statut dérivé)** et l'**élargit au 401** (dépense), explicitement hors périmètre de la spec d'origine (ligne 79).
**Mémoire** : [[project-compta-v5-flux-bancaires-live-pd]].
**Statut** : design validé en brainstorming (5 sections approuvées). Prêt pour `/plan`.

## Intention (Why)

Le `statut_reglement` cesse d'être un **enum stocké posé à la main** et devient le **reflet dérivé du grand livre partie double** — source de vérité unique. Le statut dit *où en est l'argent dans la chaîne de trésorerie*, recette **et** dépense, en miroir.

Bénéfices :
- **Dissout structurellement l'audit Thème B** : un comptant ne « naît » plus `en_attente` à tort — le statut se dérive du 411/401 lettré.
- **Corrige le bug réversion** (trouvé en recette 2a) : une recette reçue repassée non-reçue garde aujourd'hui `statut_reglement = recu` à tort (l'enum stocké n'est jamais reposé). Le statut dérivé lit le ledger (411 non lettré → `ouvert`) et affiche le bon état.
- **Symétrie 411 (recette) / 401 (dépense)** : même traversée en miroir débit/crédit.

## Décisions de cadrage (brainstorming 2026-06-04)

1. **2b/3b NON prérequis** (vérifié : `TransactionConverter:222` produit encore le cycle lumpé). Le resolver doit **de toute façon** tolérer des structures non-uniformes (HelloAsso/legacy jamais en T2 séparée). Donc on construit le resolver **robuste aux deux structures (lumpé + T2 séparée)** maintenant ; 2b/3b restent un **nettoyage optionnel** plus tard, pas un bloquant.
2. **Périmètre symétrique 411 + 401** en un seul chantier : un seul resolver, une seule migration d'enum, une seule passe de réconciliation, le cas réversion couvert des deux côtés.
3. **Miroir recalculé** (vs pur-dérivé) : la colonne `transactions.statut_reglement` reste un **miroir dénormalisé**, le **resolver est l'autorité**, recalculé à chaque transition. Préserve les ~18 sites de filtrage existants ; un test/commande de réconciliation détecte toute dérive.
4. **Enum direction-neutre** (position ledger), libellés rendus par direction.

## Section 1 — Modèle d'état

L'enum nomme la **position dans le grand livre** (neutre au sens) ; les **libellés** sont rendus par direction.

| Valeur enum (interne) | Règle de dérivation (ledger) | Libellé recette | Libellé dépense |
|---|---|---|---|
| `ouvert` | ligne **411/401** de la T1 **non lettrée** | **Dû** | **Dû** |
| `en_main` | terme du chaînage = portage **5112/530**, **aucun 512X** atteint | À remettre | *(n/a)* |
| `denoue` | terme du chaînage = **512X présent**, sa transaction porteuse non rapprochée | Remis | Réglé |
| `pointe` | la **transaction** porteuse du **512X** porte `rapprochement_id` | **Pointé** | **Pointé** |

> Précision : `rapprochement_id` est une colonne de `transactions` (pas de `transaction_lignes`) — on lit `$tx->rapprochement_id` sur la transaction qui porte la ligne 512X.

Logique : les **extrémités** (`ouvert`, `pointe`) sont le même concept des deux côtés → même mot. Le **milieu** (l'acte de bouger l'argent) reste direction-spécifique (« Remis » vs « Réglé »). Côté dépense, `en_main` **n'arrive jamais** (règlement direct depuis la banque, sans étape « en main »).

> Note réversible : « Dû » sur une recette se lit comptablement « quelqu'un nous doit » (créance). Si la recette localhost révèle une ambiguïté en liste mixte, on rebascule la recette sur « Attendu » (changement de libellé pur, l'architecture le permet).

**Mapping des consommateurs existants** (rétro-compat) :
- `isEncaisse()` → `!== ouvert` (argent reçu/payé). Reçu fiscal : un don est encaissé dès que le 411 est lettré.
- `estPointee()` → `=== pointe`.

## Section 2 — Le resolver `EtatReglementResolver`

Service **lecture seule, déterministe** : `resolve(Transaction): StatutReglement`. Aucune écriture, aucun effet de bord.

**Algorithme (miroir 411/recette ↔ 401/dépense)** :

1. **Détection du sens** (recette/dépense) → compte de tiers visé : `411` (recette) ou `401` (dépense). Réutilise l'enum `Sens` / `CompteTresorerieResolver`.
2. **Pas de ligne de tiers 411/401** sur la T1 (HelloAsso/legacy non enrichi PD) → **fallback : renvoie la colonne stockée** (le miroir est un no-op pour ces tx).
3. **Ligne 411/401 non lettrée** → `ouvert`.
4. **Ligne 411/401 lettrée** → **marcher le chaînage de lettrage** depuis la ligne de tiers jusqu'à son **compte de trésorerie terminal**, en composant les helpers existants single-hop (`lignePortageEncaissement`, `trouverEncaissementT2`, `trouverReglementT2`). Le chaînage est **multi-hop** selon le flux :
   - chèque recette : `411 → 5112 (encaissement) → 512X (remise)` — **2 sauts** ;
   - espèces recette : `411 → 530 → 512X` — 2 sauts ;
   - virement/CB recette : `411 → 512X` — direct (pas de portage) ;
   - dépense : `401 → 512X` — direct (règlement).
   Puis statuer sur le **terme** du chaînage :
   - terme = **5112/530**, aucun 512X atteint → `en_main` *(recette uniquement)* ;
   - terme = **512X** → lire `rapprochement_id` sur **la transaction porteuse de cette ligne 512X** : non null → `pointe`, sinon → `denoue`.

**Robustesse aux 2 structures** : les helpers réutilisés sont déjà double-cas (encaissement lumpé sur la T1 **OU** T2 séparée) → le resolver tombe juste sur backfill historique (lumpé) **et** sur le live (T2 séparée), **et** retombe proprement sur legacy/HelloAsso (fallback étape 2). Le caractère **multi-hop** (chèque/espèces) est le point dur à couvrir par des tests dédiés.

## Section 3 — Miroir recalculé (rempart anti-dérive)

La colonne reste un **miroir dénormalisé**, le **resolver est l'autorité**, recalculé à chaque transition.

**Centralisation** : `EtatReglementResolver::syncer(Transaction $t1): void` — `resolve()` puis persiste si différent. Idempotent, pas de cascade d'événements.

**Correction critique** : le statut vit sur la **T1** (créance/dette). Les transitions arrivent souvent sur une **T2** (encaissement, remise) ou sur le rapprochement → **chaque déclencheur recalcule la T1 liée**, pas la tx mutée. Résolution de la T1 depuis la T2 via le chaînage de lettrage.

| Transition | Service | Cible du `syncer` |
|---|---|---|
| Création créance/dette/comptant | `TransactionService::enrichirPartieDouble` | la T1 |
| Marquer reçu / payé | `encaisserSiNonEncaisse` / `reglerSiNonRegle` | la T1 |
| **Réversion** reçu→non / payé→non | `annulerEncaissementSiReversion` / `annulerReglementSiReversion` | la T1 (corrige le bug) |
| Remise en banque (pose le 512X) | `RemiseBancaireService::comptabiliser` | chaque T1 source |
| Pointage / dé-pointage | `RapprochementBancaireService::toggleTransaction` | la T1 liée |

**Garde mode legacy** : en `COMPTA_USE_PARTIE_DOUBLE=false`, `syncer`/`resolve` **no-op** → la colonne reste gérée à l'ancienne (ne casse pas le comportement legacy / `main`).

**Rempart anti-dérive** : commande `compta:reconcilier-statuts` (ou extension de `compta:smoke-test-v5`) assertant `colonne === resolve()` pour **toute** tx en mode PD, + un test Pest équivalent en CI. Toute divergence devient visible immédiatement.

## Section 4 — Migration & rétro-compat

**Schéma** : `statut_reglement` est un **`enum()` MySQL natif** (`['en_attente','recu','pointe']`, migration `2026_04_13_100001`). Migration en **3 temps** :
- (a) `MODIFY` → union temporaire `{en_attente, recu, pointe, ouvert, en_main, denoue}` ;
- (b) data-migration qui recalcule ;
- (c) `MODIFY` → jeu cible `{ouvert, en_main, denoue, pointe}`.

Compatible sqlite (tests `:memory:`) qui ignore les contraintes enum.

**Data-migration one-shot** (idempotente, rejouable) :
- tx **avec** lignes PD → resolver (précis) ;
- tx **sans** lignes PD (legacy/HelloAsso) → traduction minimale `en_attente→ouvert`, `recu→denoue`, `pointe→pointe`.

**Sites de filtrage** : faire passer un maximum par les **helpers d'enum** plutôt que par des comparaisons brutes. Helpers à étendre : `isEncaisse()` (`!== ouvert`), `estPointee()` (`=== pointe`), nouveaux `estOuvert()` / `estEnMain()` / `estDenoue()`, + `label(Sens)` direction-aware.

**Points durs (recensement exhaustif requis dans le plan)** — SQL brut / valeurs en dur qui cassent en silence :
- `app/Services/TransactionCompteService.php:90` — `(tx.statut_reglement = 'pointe') as pointe`
- `app/Services/TransactionUniverselleService.php:185,250` — `(tx.statut_reglement = 'pointe') as pointe`
- `app/Services/TransactionUniverselleService.php:62` — `=== 'en_attente'`
- `app/Livewire/TransactionUniverselle.php:70` — filtre UI `'' | 'en_attente' | 'recu' | 'pointe'`
- Recensement complet des ~18 sites listant `statut_reglement` à dresser dans le plan (modèles, Livewire, services, resources portail).

## Section 5 — Cas limites & stratégie de test

| Cas | Attendu |
|---|---|
| **Réversion recette** (reçu→non-reçu) | 411 non lettré → `resolve()=ouvert` **et** colonne re-synchronisée à `ouvert` |
| **Réversion dépense** (payé→non-payé) | 401 non lettré → `ouvert`, colonne re-synchronisée |
| **HelloAsso / legacy sans lignes PD** | `resolve()` retombe sur colonne stockée, pas de crash |
| **Espèces** | chaîne `530 → 512X` traitée comme chèque `5112` ; `en_main` couvre 5112 **et** 530 |
| **Équivalence lumpé ↔ séparé** | recette comptant backfillée (411 lettré same-tx) **et** live (T2 séparée), même état éco → **même statut dérivé** |
| **Multi-tenant** | resolver lit via Eloquent → `TenantScope` fail-closed, aucune query brute cross-tenant |
| **Mode legacy** (`COMPTA_USE_PARTIE_DOUBLE=false`) | resolver/syncer **no-op** → colonne gérée à l'ancienne |
| **Idempotence** | `syncer` rejoué N fois = même résultat, `resolve()` sans effet de bord |

**Exécution** : TDD subagent-driven (Sonnet implémente, Opus review spec+qualité). Tests sqlite `:memory:`.
**⚠️ Garde-fou DB** (incident clone-wipe 2026-06-02) : JAMAIS `migrate:fresh`, JAMAIS `sail test` non borné, JAMAIS de commande DB destructrice. Vérifier l'absence de `bootstrap/cache/config.php` figé sur mysql avant tout test.
**Recette manuelle localhost** (clone prod) en fin de chantier : saisir créance → marquer reçu → remettre → rapprocher → vérifier le statut à chaque étape, **+ réversion** (recette et dépense).

## Hors périmètre

- Suppression totale de la colonne `statut_reglement` (on garde le miroir).
- Convergence backfill 2b/3b (`TransactionConverter`) — nettoyage optionnel ultérieur, non bloquant.
- Couplage reçu fiscal ↔ écriture PD → reste **FX-Don** (Phase 2). Ici on garde seulement `isEncaisse()` correct via dérivation.
- Refonte de l'écran de rapprochement (livrée, slice flux bancaires).

## Jalon

Chantier 4 de la Phase 1. Ne **pas** merger `feat/compta-v5` → `main` avant validation d'ensemble (horizon lointain ; `main` reste v4.3.x).
