# Journal de banque — Slice 1 (design)

**Date** : 2026-06-01
**Branche** : `feat/compta-v5` (non mergée, non poussée)
**Statut** : design validé en brainstorming, prêt pour `/plan`.

## Contexte

Après le cutover partie double (voir `docs/specs/2026-05-31-cutover-pd-remises-rapprochement-encaissement.md`), la reprise génère des écritures de trésorerie :

- **T2** — encaissement d'un règlement (`5112/530/512x → 411`), créé quand un chèque/règlement isolé passe « en attente » → « reçu ».
- **T4** — remise bancaire (`512x → 5112`), créé à la comptabilisation/reconstruction d'une remise.

Ces écritures sont des mouvements **purement bilanciels** (classes 4/5, aucune ligne de produit/charge classe 6/7). Or l'UI opérationnelle (liste recettes/dépenses, formulaire d'édition) filtre les lignes via le scope `TransactionLigne::scopeVentilation()` = `whereNotNull('sous_categorie_id')`. Les T2/T4 n'ont aucune ligne de ce type, donc elles apparaissent comme des « fausses recettes » : montant non nul (en-tête `montant_total`), aucune ligne listable, formulaire d'édition vide avec total 0. Déroutant et potentiellement risqué (formulaire vide éditable).

## Intention (Why)

Introduire la notion comptable de **journal** comme abstraction structurante. Chaque transaction appartient à un journal :

- recette opérationnelle → **journal des ventes**
- dépense opérationnelle → **journal des achats**
- T2 / T4 (trésorerie) → **journal de banque**
- (futur) saisie libre → **journal OD**

Tant que l'UI est présentée en « partie simple » (recettes/dépenses — état actuel), **le journal de banque est masqué** des écrans opérationnels. Les T2/T4 disparaissent donc de la liste recettes/dépenses, tout en restant présentes dans le relevé du compte 512X et le rapprochement bancaire (c'est leur raison d'être).

## Périmètre — Slice 1 uniquement

Cette slice pose **la fondation journal + le masquage**, sans dette connue. Elle ne fait **rien** de ce qui suit (différé explicitement) :

- **Slice 2 — Numérotation des journaux** : numérotation séquentielle par journal × exercice, backfill unique en ordre de date. C'est là que seront posées la référence métier de la T4 (`RBC-xxxxx` = numéro de bordereau de remise) et le numéro de journal de la T2. → Toute la numérotation de banque reste **vierge** en Slice 1, pour garantir un backfill unique et propre en Slice 2.
- **Slice 3 — Journaux visibles** : UI Achats/Ventes/Banque/OD remplaçant la présentation recettes/dépenses. C'est **là** que se fait la bascule de vocabulaire visible `recette/dépense → achat/vente`.

### Décision de vocabulaire

L'enum `journal` utilise le **vocabulaire comptable interne** (`vente`/`achat`/`banque`/`od`), distinct du `type` (recette/dépense). Aucun libellé affiché ne change en Slice 1 : les listes vente/achat restent les listes recettes/dépenses actuelles, étiquetées comme aujourd'hui. La bascule de libellés visibles est différée à la Slice 3, via la couche d'affichage, sans toucher aux données.

## Architecture

### 1. Modèle de données

- Nouvelle colonne `transactions.journal` : `enum('vente','achat','banque','od')`, **NOT NULL**.
  - Migration : ajouter en nullable, backfill (cf. §3), puis passer NOT NULL (ou défaut applicatif garanti par le hook §2).
- Nouvel enum PHP `App\Enums\JournalComptable` : cas `Vente`, `Achat`, `Banque`, `Od` (valeurs `vente`/`achat`/`banque`/`od`).
- Cast `'journal' => JournalComptable::class` sur le modèle `Transaction`.
- *Pas* d'entité/table `Journal` : promotion possible plus tard sans rupture (Slice 3).

### 2. Assignation à la création — un hook + deux overrides

Le `journal` est dérivable du `type` pour tout l'opérationnel ; seules les T2/T4 (trésorerie) font exception, et elles viennent **exclusivement** d'`EcritureGenerator`.

- **Hook modèle `Transaction::creating`** : si `journal` non fourni → `recette ⇒ vente`, `depense ⇒ achat`. Couvre les 7 créateurs de transactions sans les modifier un par un (TransactionService, FactureService, AdhesionService, HelloAssoSyncService, ReglementOperationService, TransactionExtourneService, et `EcritureGenerator::createTransactionHeader`).
- **Overrides explicites** dans `EcritureGenerator` : `pourEncaissementCreance` (T2) et `pourRemiseBancaire` (T4) posent `journal = banque` (via un paramètre `journal` ajouté à `createTransactionHeader`, défaut `null` → laisse le hook décider).

Invariant : à la création, une transaction a toujours un `journal` non nul.

### 3. Backfill de l'existant — migration idempotente

Règle structurelle, par transaction, fondée sur la classe PCG des comptes des lignes (les données du clone sont en PD : `compte_id` backfillé) :

1. La transaction possède **au moins une ligne de compte classe 6 ou 7** → opérationnelle :
   `type = recette ⇒ vente`, `type = depense ⇒ achat`.
2. Sinon, la transaction possède **uniquement des lignes de trésorerie/tiers (classes 4/5)** → `banque`.
3. Fallback défensif (aucune ligne classée, transaction legacy non backfillée — ne devrait pas exister sur le clone PD) → par `type` (`recette ⇒ vente`, `depense ⇒ achat`).

Cette règle classe correctement : T2/T4 (aucune ligne 6/7 → banque), opérationnel (ligne 6/7 → vente/achat), extournes (suivent les lignes de leur miroir).

Idempotent : `WHERE journal IS NULL` (ou recalcul stable). Multi-tenant : la migration itère par transaction sans dépendre de `TenantContext` (backfill data-layer, comme Step 36).

### 4. Masquage du journal de banque

- Scope modèle `Transaction::scopeOperationnel(Builder $q)` → `whereIn('journal', ['vente','achat'])` (exclut `banque` **et** `od`).
- Appliqué aux **écrans opérationnels** listant les recettes/dépenses. Le plan énumérera les chokepoints exacts (au minimum `TransactionUniverselle` ; vérifier `TransactionCompteList`, `TiersTransactions`, et les timelines tiers / exports opérationnels).
- **Non touchés** (le journal de banque y reste visible/comptabilisé) :
  - relevé / solde du compte 512X,
  - rapprochement bancaire (`RapprochementBancaireService`, écran de pointage),
  - compte de résultat (déjà agrégé par compte classe 6/7 — les lignes banque en sont naturellement absentes).

### 5. Découplage de `RemiseBancaireService::modifier()` — fin de la dette « FK null »

Aujourd'hui, `modifier()` identifie la T4 vs ses sources via `reference IS NULL` (3 emplacements : sélection des sources à retirer, comptage d'index de numérotation, sélection des nouvelles sources). On remplace ce critère par le **critère structurel 512X** déjà utilisé par `queryT4()` (la T4 est la transaction de remise portant une ligne 512X au débit).

- Pur refactor : **aucun changement de comportement**. La T4 garde `reference = null` (on cesse simplement de s'en servir comme sentinelle). Les sources gardent leur référence `RBC-xxxxx-NNN` (inchangé).
- Bénéfice : supprime la dette identifiée, et dé-risque la future Slice 2 (numérotation), qui pourra poser une référence sur la T4 sans rien casser.

## Cas limites & risques

- **Transactions sans `compte_id` sur leurs lignes** (legacy non backfillé) : fallback par `type` (§3.3). Sur le clone PD actuel, `compte_id` est backfillé → cas non rencontré, mais couvert défensivement.
- **Extournes** : classées par leurs lignes (un miroir de vente reste vente ; un miroir de banque reste banque). Cohérent.
- **Cohérence hook (création) ↔ backfill** : un nouvel opérationnel a un type + des lignes 6/7 → vente/achat des deux côtés ; un nouveau T2/T4 est posé `banque` explicitement et n'a pas de ligne 6/7 → cohérent avec le backfill.
- **Masquage trop large** : risque d'exclure par erreur une transaction opérationnelle. Mitigation : tests d'assignation par chemin + test « une vente reste visible, une banque est masquée ».

## Critères d'acceptation (tests)

1. **Assignation à la création** :
   - recette opérationnelle (p. ex. `pourRecetteComptant`) → `journal = vente` ;
   - dépense opérationnelle → `journal = achat` ;
   - T2 (`pourEncaissementCreance`) → `journal = banque` ;
   - T4 (`pourRemiseBancaire`) → `journal = banque` ;
   - un créateur direct hors EcritureGenerator (p. ex. `TransactionService`) → `journal` posé par le hook selon `type`.
2. **Backfill** : sur un jeu mêlant T1 (vente/achat), T2, T4 et une extourne → chaque transaction reçoit le bon journal ; idempotent (rejouer = no-op).
3. **Masquage** : une transaction `journal = banque` n'apparaît pas dans la liste opérationnelle (`scopeOperationnel`), mais reste comptée dans le relevé 512X et le rapprochement.
4. **`modifier()` découplé** : la suite remise existante reste verte ; + un cas où les sources ont `reference = null` (scénario Finding 2) prouve que l'identification de la T4 ne dépend plus de `reference`.
5. **Suite complète** : 0 failed.

## Hors périmètre (rappel)

- Numérotation des journaux (Slice 2).
- Référence métier de la T4 `RBC-xxxxx` (Slice 2).
- UI journaux visibles + bascule vocabulaire visible (Slice 3).
- Entité/table `Journal` configurable (Slice 3).
