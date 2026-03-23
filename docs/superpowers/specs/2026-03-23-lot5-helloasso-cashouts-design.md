# Lot 5 — Gestion des versements HelloAsso (cashouts)

## Objectif

Importer les versements HelloAsso (cashouts) en virements internes SVS et relier les transactions existantes à leur cashout, pour obtenir une traçabilité complète du flux financier HelloAsso → compte courant.

## Contexte

Les Lots 3-4 synchronisent les commandes HelloAsso en transactions sur un compte bancaire dédié "HelloAsso". Ce compte intermédiaire modélise le solde détenu par HelloAsso avant versement.

Quand HelloAsso effectue un versement (cashout), il regroupe N paiements en un seul virement bancaire vers le compte courant de l'association. Le Lot 5 crée le `VirementInterne` correspondant et marque les transactions couvertes.

## Hypothèses

- **Un seul payment par order.** Le paiement échelonné n'est pas activé sur HelloAsso. Si un jour il l'est, une table de liaison `helloasso_transaction_payments` remplacera la colonne `helloasso_payment_id`.
- **Montants nets.** Les transactions SVS stockent le montant net reversé à l'association (shareAmount HelloAsso), pas le montant brut incluant la contribution volontaire à HelloAsso. Le cashout `amount` correspond à la somme des montants nets. Le contrôle d'intégrité compare donc des montants homogènes.

## Infrastructure existante

Les migrations sont déjà en place :
- `transactions.helloasso_cashout_id` — bigint nullable, indexé (migration Lot 1)
- `virements_internes.helloasso_cashout_id` — bigint nullable, unique (migration Lot 1)
- `HelloAssoParametres.compte_helloasso_id` — compte source
- `HelloAssoParametres.compte_versement_id` — compte destination

**Manquant :** `transactions.helloasso_payment_id` — le chaînon entre payments et transactions.

## Schéma

### Nouvelle migration

Ajouter sur `transactions` :
- `helloasso_payment_id` — bigint unsigned, nullable, index (pas unique : un payment couvre potentiellement N transactions si l'order a N bénéficiaires)

### Modèle Transaction

Ajouter `helloasso_payment_id` dans `$fillable` et `$casts` (integer).

### Chaîne de liaison

```
Cashout (versement bancaire HA)
  └─ contient N Payments (paiements individuels)
       └─ chaque Payment appartient à un Order
            └─ chaque Order → 1..N Transactions SVS (groupées par bénéficiaire)
```

Le lien se fait via :
- `transaction.helloasso_payment_id` → payment.id (renseigné lors du sync orders)
- `transaction.helloasso_cashout_id` → cashout.id (renseigné lors du sync cashouts)
- `virement_interne.helloasso_cashout_id` → cashout.id (renseigné lors du sync cashouts)

## API Client

Ajout de `fetchCashOuts(string $from, string $to): array` dans `HelloAssoApiClient`.

Endpoint : `GET /v5/organizations/{slug}/cash-outs?from={from}&to={to}`

Même pattern paginé que `fetchOrders`. Structure attendue d'un cashout :

```json
{
  "id": 12345,
  "date": "2026-01-15T10:00:00+01:00",
  "amount": 35000,
  "payments": [
    { "id": 52036, "amount": 25000 },
    { "id": 52037, "amount": 10000 }
  ]
}
```

## Sync Service — Modifications

### 1. Sync orders : renseigner `helloasso_payment_id`

Dans `processOrder`, lors du create/update de la Transaction, ajouter :
- `helloasso_payment_id` = `$order['payments'][0]['id']` (le premier payment de l'order)

Lors de l'update, renseigner si vide (même pattern que `reference`).

### 2. Sync cashouts : nouveau flux

Après le sync des orders, dans la même action "Synchroniser". Chaque cashout est traité dans son propre `DB::transaction()`.

```
Pour chaque cashout de l'API :
  1. Upsert VirementInterne (match helloasso_cashout_id, withTrashed + restore)
     - compte_source_id = parametres->compte_helloasso_id
     - compte_destination_id = parametres->compte_versement_id
     - montant = cashout.amount / 100
     - date = cashout.date
     - libelle = "Versement HelloAsso du {date formatée dd/mm/yyyy}"
     - reference = "HA-CO-{cashout.id}"
     - numero_piece = NumeroPieceService::assign() (uniquement à la création)
     - saisi_par = auth()->id() (contexte Livewire authentifié)

  2. Marquer les transactions liées
     - Pour chaque payment.id du cashout :
       UPDATE transactions SET helloasso_cashout_id = cashout.id
       WHERE helloasso_payment_id = payment.id
       AND helloasso_cashout_id IS NULL

  3. Contrôle d'intégrité (informatif, non bloquant)
     - Calculer SUM(montant_total) des transactions WHERE helloasso_cashout_id = cashout.id
     - Comparer avec le montant du virement
     - Si écart → ajouter un warning
```

### Plage de dates cashouts

La même plage d'exercice que pour les orders (`ExerciceService::dateRange`). Un cashout de début septembre peut couvrir des paiements de fin août — ce n'est pas un problème car le lien se fait par payment_id, pas par date.

## HelloAssoSyncResult — Extension

Ajout de champs au VO :
- `virementsCreated` (int)
- `virementsUpdated` (int)
- `integrityWarnings` (array de strings)

## UI — Extension du rapport

Pas de nouvelle page ni de nouveau composant. Le rapport de synchronisation (`helloasso-sync.blade.php`) affiche en plus :
- Compteurs virements créés / mis à jour
- Warnings d'intégrité en orange si présents (ex: "Cashout #12345 : écart de 5,00 € entre le versement (350,00 €) et les transactions liées (345,00 €)")

## Hors périmètre (Lot 6)

- Rapprochement bancaire automatique quand l'intégrité est vérifiée
- Verrouillage des transactions par cashout
- UI de visualisation du verrouillage par versement

## Cas limites

- **Cashout sans payments reconnus** : les transactions n'ont pas encore de `helloasso_payment_id` (créées avant ce lot). Le sync orders les renseigne lors du re-sync. Si le payment_id reste absent → warning d'intégrité.
- **Ordre du sync** : orders d'abord (pour renseigner payment_id), cashouts ensuite (pour le marquage). Cet ordre est garanti dans le service.
- **Soft-delete** : lookup `withTrashed()` + `restore()` sur VirementInterne, comme pour les transactions.
- **Idempotence** : le virement est identifié par `helloasso_cashout_id` (unique). Le marquage des transactions est idempotent (WHERE cashout_id IS NULL).
- **`compte_versement_id` non configuré** : si absent dans les paramètres, le sync cashouts est sauté avec un message d'erreur dans le rapport (pas bloquant pour le sync orders).
