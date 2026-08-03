# Audit des flux métier en Compta-V5 (partie double) — 2026-06-03

**Branche** : `feat/compta-v5` (non mergée). **Méthode** : 5 agents d'audit parallèles read-only (data-flow-tracer), un par flux.
**Périmètre** : Dons · Cotisations/Adhésions · Notes de frais · NDF par abandon de créance · HelloAsso + virement.

## Constat global

Le **moteur PD** (`EcritureGenerator` + école 411 systématique + `assertEquilibre`/`assertTiersObligatoire411`/`assertPasDeTiersSurClasse5`) est **sain et bien gardé quand il est invoqué**. Les problèmes sont **aux bords** :
1. certains chemins de création **n'invoquent pas** le moteur (PD absent ou différé) ;
2. des **statuts / journaux / comptes** mal posés ;
3. quelques **bugs discrets** (skips silencieux).

---

## Thème A — Des chemins de création ne produisent pas d'écritures PD (RISQUE PROGRAMME)

| Source | Constat | Sévérité | Réf |
|---|---|---|---|
| Cotisations | Wizard d'adhésion `AdhesionService::creerTransactionPaiement` crée Transaction+Ligne en direct, **sans `TransactionService`** → `enrichirPartieDouble` jamais appelé. | HAUTE | `AdhesionService.php:367-395` |
| HelloAsso / Cotisations / Dons | `HelloAssoSyncService::processOrder` bypass `enrichirPartieDouble` → PD **différé au backfill** `compta:backfill-partie-double`, **manuel, sans auto-trigger après sync**. | HAUTE | `HelloAssoSyncService.php:222-296` |
| NDF | Ligne kilométrique sans usage `FraisKilometriques` configuré → `sous_categorie_id` null → `enrichirPartieDouble` **skip TOUTE la transaction** (pas que la ligne km). | MOYENNE | `TransactionService.php:119-130` ; `KilometriqueLigneType.php:83` |
| Dons | Don sans `tiers_id` → skip silencieux PD (pas d'avertissement UI). | MINEUR | `TransactionForm.php:534` ; `TransactionService.php:97-104` |

**Impact** : en PD activé, un **volume réel** de transactions (adhésions wizard + tout HelloAsso + edge-cases) est **silencieusement absent du grand livre PD / FEC** jusqu'au prochain backfill manuel (et jamais, pour les skips wizard/sans-tiers). Le moteur de rapprochement et les états PD les ignorent.

---

## Thème B — Statut de règlement non posé à la création (BUG NET, impact utilisateur)

| Source | Constat | Sévérité | Réf |
|---|---|---|---|
| Dons | `TransactionForm::save()` ne pose **jamais** `statut_reglement` dans `$data` → défaut `en_attente`. Un don/recette **comptant** (paiementRecu=true) naît `en_attente` au lieu de `recu`. | **BLOQUANT** | `TransactionForm.php:577-591` |

**Conséquences** : reçu fiscal inaccessible (`RecuFiscalService` exige `isEncaisse()`), statut affiché faux. « Marquer reçu » bascule le statut mais l'`encaisserSiNonEncaisse` skip (411 déjà auto-lettré sur la T1 comptant) → pas de T2, pas d'erreur. Asymétrie avec HelloAsso / séances / facturation qui posent le statut explicitement. **Fix étroit** : poser `statut_reglement=Recu` quand `paiementRecu=true`.

---

## Thème C — Le règlement des CHARGES ne génère pas la bonne écriture (= confirme chantier 3)

| Source | Constat | Sévérité | Réf |
|---|---|---|---|
| NDF | Toute NDF validée passe par `pourDepenseComptant` (le comptable saisit le mode) → **dette membre (401) auto-soldée à la comptabilisation**, 512X crédité tout de suite, **pas de T2 de remboursement**. | HAUTE | `NoteDeFraisValidationService.php:259-303` ; `TransactionService.php:222-246` |
| NDF | `pourReglementFournisseur` (moteur du remboursement) **orphelin en prod**. « Marquer reçu » sur une dépense cherche un 411, skip. | MOYENNE | `EcritureGenerator.php:1093` ; `ReglementOperationService.php:241` |

→ C'est **exactement le chantier 3** (Volet 1A symétrique charges : T1 à crédit `60x/401` + « marquer payé » `401/512X`). Les audits **confirment** que le moteur existe et qu'il manque l'UI + le débranchement du chemin comptant.

---

## Thème D — Faux mouvements de trésorerie 512X (POLLUE LE RAPPROCHEMENT — interagit chantier 1)

| Source | Constat | Sévérité | Réf |
|---|---|---|---|
| NDF-abandon | L'abandon de créance fabrique **2 lignes 512X fictives** (`512X C` côté dépense + `512X D` côté don) qui se neutralisent mais existent en base, **non lettrées entre elles**. | HAUTE | `EcritureGenerator.php:762` (dépense) + `:398` (don) ; `NoteDeFraisValidationService.php:141-223` |

**Impact** : depuis chantier 1, le rapprochement liste toute écriture portant une ligne 512X du compte → ces **faux mouvements deviennent pointables** alors qu'aucune ligne du relevé n'y correspond. **Bon montage cible** : abandon = OD `401 D / 75x C` (lettrage du 401), **sans trésorerie**.

---

## Thème E — Choix de comptes / journaux discutables (conformité FEC)

| Source | Constat | Sévérité | Réf |
|---|---|---|---|
| NDF | Dette envers un **membre bénévole** portée sur **401 (Fournisseurs)** au lieu d'un **467** (autres débiteurs/créditeurs). | MOYENNE | `EcritureGenerator.php:681` ; seed `401` |
| NDF-abandon | Transaction de don en **journal Vente** au lieu d'**OD** + `mode_paiement=Virement` posé à tort sur le don (export FEC `ModePaie=VIR` faux). | MOYENNE | `NoteDeFraisValidationService.php:179,185` ; `Transaction.php:91` |

---

## Thème F — Propagation rapprochement incomplète (auto-rappro HelloAsso)

| Source | Constat | Sévérité | Réf |
|---|---|---|---|
| HelloAsso | `createVerrouilleAuto` (cash-out) ne propage pas `rapprochement_id` sur une **T2 déjà encaissée** → solde PD faux. | MOYENNE | `RapprochementBancaireService.php:109-115` |
| HelloAsso | `compte_versement_id` null → **fallback silencieux** vers `compte_helloasso_id` → mauvais compte de rapprochement. | MOYENNE | `HelloAssoSyncService.php:430-434` |
| HelloAsso | `VirementInterne` cash-out sans lignes PD (pas d'écriture `512X→512X`) → absent du grand livre PD. | MOYENNE | `HelloAssoSyncService.php:714-736` (cf. chantier 8) |

---

## Thème G — Reçu fiscal découplé du PD

- `RecuFiscalService` repose sur `statut_reglement.isEncaisse()` (true pour `Recu` ET `Pointe`), **pas** sur l'existence d'une écriture PD → émissible même sans écriture PD (cas HelloAsso). Montant lu sur la **colonne legacy `montant`** (fragile si dépréciée). Réf : `RecuFiscalService.php:25,49,107` ; `StatutReglement.php:24`.

## Thème H — Gardes manquantes

- Wizard adhésion bypass `ExerciceService::assertOuvert` (`AdhesionService.php:367`).
- NDF portail : submit ne vérifie pas l'exercice ouvert → échec silencieux à la compta, NDF bloquée en `soumise` (`Portail/.../NoteDeFraisService.php:146`).
- Pas de garde sur suppression/modif des transactions liées NDF/Don (`don_transaction_id`, `transaction_id`).

---

## Priorisation recommandée

| # | Action | Type | Pourquoi |
|---|---|---|---|
| **P0** | Thème B — `TransactionForm` pose `statut_reglement=Recu` pour les recettes comptant | Fix étroit, TDD ~30 min | Débloque le reçu fiscal + statut correct ; impacte la recette en cours |
| **P1** | Thème D — router l'abandon de créance en OD (`401/75x`, sans 512X) | Fix moyen | Nettoie la pollution du rapprochement (chantier 1) |
| **P1** | Thème A — enrichissement PD à la création (ou auto-backfill) pour wizard + HelloAsso, + fermer les skips silencieux | **Nouveau chantier** | Le grand livre PD/FEC est incomplet en live — robustesse programme |
| **P2** | Thème C — chantier 3 (1A symétrique charges) | Chantier roadmap | Confirmé par les audits ; moteur prêt |
| **P3** | Thèmes E/F/G/H | Conformité / robustesse | Acter comptes (467), journaux (OD), propagation rappro, gardes |

## Impact sur le chantier 2 (« marquer reçu » ↔ toggle édition)

Le chantier 2 reste pertinent mais s'**éclaire** : le chemin comptant (TransactionForm) auto-lettre le 411 sur la T1 (pas de T2) ; le chemin créance crée une T2 au « marquer reçu ». Le **Thème B** (statut non posé) est intriqué avec cette zone et devrait être traité **avant ou avec** le chantier 2. La décision de conception du chantier 2 = converger les deux chemins sur un modèle unique (à cadrer).
