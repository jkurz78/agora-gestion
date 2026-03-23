# Lot 6 — Verrouillage automatique des versements HelloAsso

## Contexte

Les lots 1–5 ont mis en place la synchronisation HelloAsso : import des orders en transactions, rapprochement des tiers, import des cashouts en virements internes. Le Lot 6 complète le cycle en **verrouillant automatiquement** les transactions liées à un versement HelloAsso via un rapprochement bancaire auto-généré sur le compte HelloAsso.

---

## Objectif

Quand un cashout HelloAsso est complet (toutes les transactions existent en base), la sync crée automatiquement :
- Le VirementInterne (compte HA → compte courant)
- Un RapprochementBancaire verrouillé sur le compte HelloAsso, pointant les transactions et le virement

Les transactions verrouillées sont protégées : pas de suppression, pas de modification de date/montant/compte. Seules les affectations (sous-catégorie, opération, notes) restent modifiables.

Le déverrouillage reste possible manuellement via l'écran de rapprochement existant.

---

## Décisions structurantes

### Fetch payments élargi

- `fetchPayments()` est appelé avec la plage **exercice N-1 → fin exercice N** (2 exercices)
- `fetchOrders()` reste sur **exercice N uniquement**
- Objectif : capter les cashouts cross-exercice — un cashout de septembre N peut contenir des paiements de juin N-1
- `extractCashOutsFromPayments()` ne change pas — regroupe par `idCashOut` les payments en état `CashedOut`

### Complétude avant création

Le VirementInterne et le rapprochement ne sont créés que si le cashout est **complet** :
- La somme des `montant_total` des transactions en base (identifiées par `helloasso_payment_id`) correspond au montant du cashout (écart ≤ 0.01€)
- Si incomplet → pas de virement, pas de rapprochement, warning remonté

### Mise à jour du cashout_id sans restriction d'exercice

- `helloasso_cashout_id` est mis à jour sur toutes les transactions trouvées en base, **quel que soit leur exercice**
- Seule la **création** de nouvelles transactions est restreinte à l'exercice N

### Transactions manquantes sur N-1

- Si des payments du cashout correspondent à des orders hors exercice N dont les transactions n'existent pas en base, un **info** est remonté (pas une erreur bloquante)
- L'utilisateur peut lancer une sync sur N-1 pour les importer s'il le souhaite
- Le process continue : les autres cashouts complets sont traités normalement

---

## Rapprochement auto-verrouillé

### Principe comptable

Sur le compte HelloAsso, un cashout a un effet net nul :
- Les transactions (recettes) sont des **crédits** : +X€
- Le VirementInterne (sortie vers compte courant) est un **débit** : -X€
- Écart = 0 → `solde_fin = solde_ouverture`

### Création du rapprochement

Dans une même `DB::transaction()`, quand un cashout est complet :

1. **Créer le VirementInterne** avec `helloasso_cashout_id`
2. **Créer le RapprochementBancaire** :
   - `compte_id` = compte HelloAsso
   - `date_fin` = date du cashout
   - `solde_ouverture` = `solde_fin` du dernier rapprochement verrouillé sur ce compte, ou `0.00` si premier
   - `solde_fin` = `solde_ouverture` (net = 0)
   - `statut` = `Verrouille`
   - `verrouille_at` = `now()`
   - `saisi_par` = utilisateur connecté
3. **Pointer les écritures** :
   - Transactions avec ce `cashout_id` → `rapprochement_id` = rapprochement créé
   - VirementInterne → `rapprochement_source_id` = rapprochement créé (sortie du compte HA)

### Déverrouillage

Aucun mécanisme spécifique. Le `deverrouiller()` existant dans `RapprochementBancaireService` fonctionne tel quel — le rapprochement repasse en `EnCours`, les transactions redeviennent modifiables.

---

## Flux de la sync modifié

```
Synchroniser (exercice N)
│
├─ 1. Fetch orders (exercice N) → import transactions
│
├─ 2. Fetch payments (exercice N-1 → fin N)
│     └─ extractCashOutsFromPayments() → liste des cashouts
│
├─ 3. Pour chaque cashout :
│     ├─ Collecter les payment IDs
│     ├─ Chercher les transactions en base (par helloasso_payment_id, sans filtre exercice)
│     ├─ Mettre à jour helloasso_cashout_id sur les transactions trouvées
│     ├─ Vérifier complétude (somme transactions == montant cashout)
│     │
│     ├─ Si complet :
│     │   ├─ Créer VirementInterne
│     │   ├─ Créer RapprochementBancaire verrouillé
│     │   └─ Pointer transactions + virement
│     │
│     ├─ Si incomplet :
│     │   └─ Warning : "Cashout #X incomplet (écart de Y€)"
│     │
│     └─ Si transactions manquantes sur N-1 :
│         └─ Info : "X transactions disponibles sur l'exercice N-1"
│
└─ 4. Rapport enrichi
```

---

## Rapport de sync enrichi

Le rapport existant s'enrichit avec :

- **Rapprochements créés : X** — nombre de cashouts complets auto-verrouillés
- **Cashouts incomplets : X** — avec détail (montant, écart)
- **Info exercice N-1** — "X transactions disponibles sur l'exercice YYYY" (si applicable)

Pas de nouvelle carte UI — tout s'intègre dans le rapport après "Synchronisation terminée".

---

## Impact sur le code existant

### Fichiers modifiés

| Fichier | Modification |
|---|---|
| `HelloassoSync.php` (Livewire) | Fetch payments avec plage élargie N-1→N, passer au service |
| `HelloAssoSyncService.php` | Refonte de `synchroniserCashouts()` : complétude, création conditionnelle virement + rapprochement auto |
| `helloasso-sync.blade.php` | Affichage des rapprochements créés, cashouts incomplets, info N-1 |

### Fichiers non modifiés

| Fichier | Raison |
|---|---|
| `RapprochementBancaire.php` | Le modèle existant suffit |
| `RapprochementBancaireService.php` | `deverrouiller()` fonctionne tel quel |
| `TransactionService.php` | La protection `isLockedByRapprochement()` est déjà en place |
| `HelloAssoApiClient.php` | `fetchPayments()` et `extractCashOutsFromPayments()` ne changent pas |

### Pas de migration

Aucune nouvelle colonne ou table. Tout s'appuie sur l'infrastructure existante (`rapprochement_id`, `rapprochement_source_id`, `statut Verrouille`).

---

## Hors périmètre

- **Barre de progression** pendant la sync — à ajouter si les volumes deviennent importants
- **UI dédiée de visualisation des versements** — les rapprochements auto sont visibles dans l'écran de rapprochement existant du compte HelloAsso
- **Gestion des remboursements HelloAsso** — limitation connue, traitement manuel
