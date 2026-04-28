# ADR-002 : Facture manuelle — modèle invoice-first à 3 types de lignes

**Statut :** Accepté
**Date :** 2026-04-28
**Auteurs :** Jurgen Kurz, Claude
**Liens :** spec `docs/specs/2026-04-28-facture-libre-s2.md`, plan `plans/facture-libre-s2.md`

---

## Contexte

Le flux historique d'AgoraGestion suit un chemin strict : séances → règlements participants → `Transaction` (recette) → `Facture` (les lignes `Montant` ref pointent des `transaction_lignes` pré-existantes). Ce modèle est adapté aux cotisations et séances régulières, mais impossible à utiliser pour une prestation exceptionnelle (mission, vente hors catalogue) : il faut pré-créer une `Operation` fictive juste pour pouvoir saisir des transactions.

La Slice 1 (v4.1.8) a livré le **Devis manuel** : engagement commercial adressé à un `Tiers` quelconque, sans `Operation` ni `Participant`, avec 5 statuts tracés et un cycle de vie complet. Une fois le devis accepté, il manquait un chemin pour émettre la facture correspondante et enregistrer la créance comptable.

Deux chemins d'implémentation ont été comparés :

---

## Options envisagées

**Path A — Transaction-first** : devis accepté → `Transaction` recette directement, la facture restant optionnelle ou générée après coup.

- Simple en termes de modèle : une seule origine pour les transactions.
- Crée une dualité difficile à maintenir : certaines transactions viennent de séances, d'autres de devis. La facture devient un document secondaire détaché de la création de la créance.
- Contredit la convention comptable : la facture est le document qui fonde la créance, pas la transaction.

**Path B — Invoice-first** : devis accepté → `Facture` brouillon → lignes manuelles éditées → à la **validation** de la facture, génération automatique de la `Transaction` recette (statut "à recevoir").

- Respecte la sémantique métier : la facture est le document fondateur.
- Réutilise le pivot `facture_transaction` existant et le flow d'encaissement Créances (v2.4.3) sans modification.
- Requiert d'élargir `FactureLigne` avec un discriminator de type.

---

## Décision retenue

**Path B — Invoice-first**, avec un seul modèle `Facture` portant trois types de lignes :

| Type | Valeur enum | Comportement |
|---|---|---|
| `Montant` | `montant` | existant — référence une `transaction_ligne` pré-existante |
| `MontantManuel` | `montant_manuel` | nouveau — génère une `transaction_ligne` à la validation |
| `Texte` | `texte` | existant — information, sans impact comptable |

Le mix des trois types est autorisé sur la même facture. Le discriminant vit au niveau de la ligne, pas de la facture.

---

## Conséquences

**Schéma.** `facture_lignes` reçoit 5 colonnes nullables (`prix_unitaire`, `quantite`, `sous_categorie_id`, `operation_id`, `seance`) qui ne servent qu'aux lignes `MontantManuel`. Aucun backfill : les lignes existantes (`Montant` / `Texte`) gardent ces colonnes à NULL. `factures` reçoit deux colonnes : `devis_id` (FK nullable, ON DELETE RESTRICT) et `mode_paiement_prevu` (enum `ModePaiement`, nullable).

**Génération de transaction.** À la validation d'une facture portant ≥ 1 ligne `MontantManuel` : 1 `Transaction` recette est créée (statut `StatutReglement::EnAttente`, mode = `facture.mode_paiement_prevu`), accompagnée de N `TransactionLignes` (1 par ligne `MontantManuel`). `facture_lignes.transaction_ligne_id` est backfillé sur chaque ligne manuelle. La transaction est rattachée à la facture via le pivot `facture_transaction` existant.

**Encaissement.** La transaction générée (`statut = en_attente`) s'intègre nativement dans le flow Créances v2.4.3 (bouton "Encaisser" existant) sans modification.

**PDF — option α "asymétrie honnête".** Les colonnes PU/Qté sont rendues uniquement sur les lignes `MontantManuel`. Les lignes `Montant` ref n'affichent que libellé + montant total. Les lignes `Texte` n'affichent que le libellé (colonnes montant vides — c'est aussi le fix d'un bug préexistant S1 qui affichait `0,00 €`).

**Dette préexistante.** L'avoir actuel (`FactureService::annuler`) n'annule pas les transactions générées par les lignes manuelles : il part du principe que les transactions pré-existent à la facture, hypothèse désormais fausse pour les lignes `MontantManuel`. Cette dette est orthogonale à S2 ; elle est documentée et trackée séparément (voir `project_avoir_transactions_dette.md`).

**Évolutivité.** Si `transactions` reçoit un jour `prix_unitaire` / `quantite` (nullable), les lignes `Montant` ref pourront restituer ces valeurs sans changement du modèle `facture_lignes`.
