# Cycle de vie créance + statut dérivé du grand livre (design)

**Date** : 2026-06-02
**Branche** : `feat/compta-v5` (non mergée)
**Statut** : design en cours — décisions de fond prises, points ouverts à valider avant `/plan`.
**Origine** : tour IHM live PD post-slice « flux bancaires live » ([project-compta-v5-flux-bancaires-live-pd]]). Distinct de cette slice (qui est finie/verte).

## Contexte & constat

Le tour live a révélé deux trous structurels dans le modèle créance de la Compta V5 :

1. **On ne peut pas saisir de créance.** `TransactionForm` rend `mode_paiement` obligatoire ([TransactionForm.php:524](../../app/Livewire/TransactionForm.php)). Or `TransactionService::enrichirPartieDouble` route `mode renseigné ⇒ comptant` (`pourRecetteComptant`) / `mode absent ⇒ créance` (`pourRecetteACredit`). Conséquence : **toute recette saisie au formulaire est comptant (reçue)** — la créance n'existe que via séances/factures. C'est un comble pour un outil dont la raison d'être inclut la gestion des créances.

2. **Un seul `statut_reglement = en_attente` porte deux sens incompatibles** : (a) créance **en attente de paiement** (411 ouvert) ; (b) chèque **reçu, en attente de remise** en banque (5112 en main, 411 soldé). Impossible de les distinguer à l'écran ou en requête.

Note : le faux positif `equilibree=false` repéré au même moment a été **corrigé séparément** (commit `0f72063b`).

## Intention (Why)

- Permettre la **saisie d'une vraie créance** (recette attendue, pas encore reçue), puis son encaissement ultérieur.
- Donner un **statut non ambigu** reflétant où en est l'argent — **dérivé du grand livre partie double**, **source de vérité unique** (décision actée). Le statut cesse d'être une donnée saisie/posée à la main pour devenir le reflet de la réalité comptable.

## Périmètre — deux volets couplés

### Volet A — Saisie de créance

`TransactionForm` (recette) propose un choix explicite **« Paiement déjà reçu ? »** :
- **Non** → `mode_paiement` non requis/laissé vide → `pourRecetteACredit` → créance ouverte (`411 D / 7xx C`, 411 non lettré).
- **Oui** → mode requis → comptant (comportement actuel inchangé).

Un geste **« Marquer reçu »** sur une créance déclenche l'encaissement (T2 séparée `5112/530/512X → 411`, via `ReglementOperationService::encaisserSiNonEncaisse` — déjà existant et idempotent). Le flux créance → encaissement produit une **T2 séparée**, cas que la slice flux bancaires gère désormais (helper `lignePortageEncaissement`, commit `4c3455d4`).

UX : pas de jargon comptable à l'écran (préférence projet). Libellés utilisateur : « Recette attendue » / « Paiement reçu ».

### Volet B — Statut dérivé du grand livre

Le statut reflète **la position de l'argent dans la chaîne de trésorerie**, chaque étape = un compte PCG :

```
411 (créance) → 5112/530 (en main) → 512X (en banque) → pointé (relevé)
```

| Stage (interne) | Libellé utilisateur | Règle de dérivation (depuis le ledger) | Modes |
|---|---|---|---|
| `attendu` | Recette attendue | ligne **411** de la tx **non lettrée** | tous |
| `a_remettre` | À remettre en banque | 411 lettré **et** ligne portage **5112/530 non lettrée** (pas de 512X) | chèque/espèces |
| `remis` | Remis (à rapprocher) | portage 5112/530 **lettré** avec une écriture de dépôt (512X), **non** rapprochée | chèque/espèces + direct virement/CB |
| `pointe` | Rapproché | l'écriture porteuse du **512X** porte `rapprochement_id` | tous |

Virement/CB (direct 512X, pas d'étape 5112) : `attendu → remis → pointe`.

**Dérivation** = traversée du chaînage de lettrage `411 → 5112/530 → 512X` — exactement les patterns déjà construits pour la slice flux bancaires (`trouverEncaissementT2`, `lignePortageEncaissement`, `resoudreCompte512X`). À factoriser dans un service dédié **`EtatReglementResolver`** (lecture seule, déterministe).

**Pragmatisme « autant que possible »** : on conserve la colonne `transactions.statut_reglement` comme **miroir dénormalisé**, mais **recalculé par le resolver** à chaque transition (saisie, encaissement, remise, pointage, dé-pointage). Bénéfice : les ~18 sites qui filtrent sur `statut_reglement` continuent de fonctionner ; la **vérité reste le ledger** ; un test/commande de **réconciliation** (`colonne == dérivé` pour toutes les tx) détecte toute dérive. L'enum passe de 3 (`en_attente/recu/pointe`) à 4 valeurs (`attendu/a_remettre/remis/pointe`).

## Décisions ouvertes (à valider avant `/plan`)

1. **Miroir vs pur-dérivé** : je recommande le **miroir recalculé** (compatibilité requêtes + perf), avec le resolver comme autorité + test de réconciliation. Alternative pur-dérivé (calcul à la volée partout) = plus pur mais gros refactor des requêtes/filtres. → **Miroir recommandé.**
2. **Nommage enum** : `attendu / a_remettre / remis / pointe` (interne) ; libellés UI sans jargon. OK ?
3. **Sort de `recu`** : la valeur actuelle `recu` disparaît (scindée en `a_remettre`/`remis`). Migration de données : recalcul du statut de **toutes** les transactions via le resolver (one-shot, idempotent). OK ?
4. **Volet A en premier ou les deux ensemble ?** A (saisie créance) est petit et livrable seul ; B (statut dérivé) est plus lourd. Slicing possible : A → B.

## Preuve par l'exemple (cas réel tour de test 2026-06-03)

Une recette reçue puis **éditée en « non reçu »** : la T2 d'encaissement est bien supprimée (fix `e50a5cee`), le 411 redevient non lettré → **c'est une créance**. Mais la colonne `statut_reglement` reste **`recu`** (périmée) — décision actée de **ne PAS la patcher à la main** (sinon on éparpille des resets partout). Le **statut dérivé** lira le ledger (411 non lettré → `attendu`) et affichera le bon état, **sans dépendre de la colonne**. C'est précisément ce que ce programme corrige. → **Test Volet B** : après réversion, `statut_reglement='recu'` mais l'état dérivé = `attendu`.

## Cas limites & risques

- **Espèces** : chaîne `530 → 512X` (versement d'espèces). Même logique que chèque via 5112.
- **Lumpé vs T2 séparée** : la dérivation doit gérer les deux (encaissement sur la tx elle-même OU sur une T2). Le resolver réutilise `lignePortageEncaissement` (déjà à double cas).
- **Transactions HelloAsso / legacy non backfillées** : sans lignes PD, le resolver doit retomber proprement (statut legacy conservé ou `attendu` par défaut). À spécifier.
- **Migration enum** : `statut_reglement` est NOT NULL enum — changer les valeurs touche la colonne + les requêtes. Recensement des ~18 sites requis dans le plan.
- **Risque de drift** miroir/ledger : couvert par le test de réconciliation + recalcul systématique aux transitions.

## Hors périmètre

- Suppression totale de la colonne `statut_reglement` (on garde le miroir).
- Refonte de l'écran de rapprochement (slice flux bancaires, livrée).
- Dépenses fournisseurs à crédit (401) : la symétrie 401 est analogue mais traitée à part si besoin.

## Exécution

Programme distinct de la slice flux bancaires. Subagent-driven TDD. Tests manuels localhost (clone) — notamment : saisir une créance, la marquer reçue, la remettre, la rapprocher, et vérifier le statut dérivé à chaque étape. Ne pas merger `feat/compta-v5` avant validation d'ensemble.
