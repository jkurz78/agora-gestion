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
- Le changement de plage est dans le **composant Livewire** (l'appelant), pas dans `HelloAssoApiClient`

### Complétude avant création

Le VirementInterne et le rapprochement ne sont créés que si le cashout est **complet** :
- La somme des `montant_total` des transactions en base (identifiées par `helloasso_payment_id`) correspond au montant du cashout (écart ≤ 0.01€)
- Si incomplet → pas de virement, pas de rapprochement, warning remonté
- La vérification utilise `whereNull('helloasso_cashout_id')` pour l'idempotence : un re-sync ne re-stampe pas les transactions déjà marquées

### Mise à jour du cashout_id sans restriction d'exercice

- `helloasso_cashout_id` est mis à jour sur toutes les transactions trouvées en base, **quel que soit leur exercice**
- Seule la **création** de nouvelles transactions est restreinte à l'exercice N
- Un re-sync ne modifie pas les transactions déjà marquées (`whereNull('helloasso_cashout_id')`)

### Transactions manquantes sur N-1

- Si des payments du cashout correspondent à des orders hors exercice N dont les transactions n'existent pas en base, un **info** est remonté (pas une erreur bloquante)
- L'utilisateur peut lancer une sync sur N-1 pour les importer s'il le souhaite
- Le process continue : les autres cashouts complets sont traités normalement

### Limitation multi-paiements

Actuellement, seul `$order['payments'][0]['id']` est stocké en `helloasso_payment_id` sur la Transaction. Les commandes avec paiements échelonnés (plusieurs payments) ne sont liées que via le premier payment. Si le cashout référence un payment ultérieur (2e échéance), la lookup par `helloasso_payment_id` le manquera. Cette limitation est acceptée pour la V1 — les paiements échelonnés sont rares chez SVS.

---

## Rapprochement auto-verrouillé

### Principe comptable

Sur le compte HelloAsso, un cashout a un effet net nul :
- Les transactions (recettes) sont des **crédits** : +X€
- Le VirementInterne (sortie vers compte courant) est un **débit** : -X€
- Écart = 0 → `solde_fin = solde_ouverture`

### Création via une méthode dédiée

La création du rapprochement auto ne passe **pas** par `RapprochementBancaireService::create()` (qui force `statut = EnCours` et bloque si un rapprochement en cours existe). Une nouvelle méthode `createVerrouilleAuto()` est ajoutée sur `RapprochementBancaireService` :

```php
public function createVerrouilleAuto(
    CompteBancaire $compte,
    string $dateFin,
    float $soldeFin,
    array $transactionIds,
    int $virementId,
): RapprochementBancaire
```

Cette méthode :
- Crée le rapprochement directement en `statut = Verrouille` avec `verrouille_at = now()`
- Pointe les transactions et le virement dans la même DB::transaction
- Réutilise `calculerSoldeOuverture()` pour le solde d'ouverture
- **Ne vérifie pas** s'il existe un rapprochement en cours (le rapprochement auto est indépendant du workflow manuel)

### Conflit avec un rapprochement manuel en cours

Si l'utilisateur a un rapprochement manuel `EnCours` sur le compte HelloAsso au moment de la sync :
- Les rapprochements auto sont créés normalement (pas de blocage)
- Le rapprochement manuel en cours peut pointer d'autres écritures indépendamment
- Les écritures déjà pointées dans un rapprochement auto verrouillé ne sont pas disponibles pour le pointage manuel (elles sont déjà `rapprochement_id != null`)

### Ordre de traitement

Les cashouts doivent être traités en **ordre chronologique** (`cashOutDate` croissant) pour garantir que la chaîne de soldes (`solde_ouverture` → `solde_fin`) est cohérente et que les `date_fin` sont monotoniquement croissants.

### Création du rapprochement (étapes)

Dans une même `DB::transaction()`, quand un cashout est complet :

1. **Créer le VirementInterne** avec `helloasso_cashout_id`
2. **Créer le RapprochementBancaire** via `createVerrouilleAuto()` :
   - `compte_id` = compte HelloAsso
   - `date_fin` = date du cashout
   - `solde_ouverture` = `solde_fin` du dernier rapprochement verrouillé sur ce compte, ou `0.00` si premier
   - `solde_fin` = `solde_ouverture` (net = 0)
   - `statut` = `Verrouille`
   - `verrouille_at` = `now()`
   - `saisi_par` = utilisateur connecté
3. **Pointer les écritures** :
   - Transactions avec ce `cashout_id` → `rapprochement_id` = rapprochement créé, `pointe` = true
   - VirementInterne → `rapprochement_source_id` = rapprochement créé (sortie du compte HA)

### Déverrouillage

Le `deverrouiller()` existant fonctionne tel quel. Contrainte existante : seul le **dernier** rapprochement verrouillé d'un compte peut être déverrouillé. Si plusieurs cashouts ont été auto-verrouillés, ils se déverrouillent en ordre inverse chronologique. Cette contrainte est documentée mais ne nécessite pas de changement — c'est le comportement attendu (on ne déverrouille pas un rapprochement ancien sans déverrouiller les plus récents d'abord).

### Idempotence

Un re-sync d'un cashout déjà verrouillé :
- Le VirementInterne existe déjà (`helloasso_cashout_id` unique) → mis à jour
- Les transactions ont déjà `helloasso_cashout_id` et `rapprochement_id` → pas de re-pointage
- Le rapprochement existe déjà → le cashout est ignoré (détecté via l'existence du VirementInterne avec `helloasso_cashout_id`)

---

## Flux de la sync modifié

```
Synchroniser (exercice N)
│
├─ 1. Fetch orders (exercice N) → import transactions
│
├─ 2. Fetch payments (exercice N-1 → fin N)
│     └─ extractCashOutsFromPayments() → liste des cashouts triés par date
│
├─ 3. Pour chaque cashout (ordre chronologique) :
│     ├─ Si VirementInterne avec ce cashout_id existe déjà → skip (idempotent)
│     ├─ Collecter les payment IDs
│     ├─ Chercher les transactions en base (par helloasso_payment_id, sans filtre exercice)
│     ├─ Mettre à jour helloasso_cashout_id sur les transactions trouvées (whereNull)
│     ├─ Vérifier complétude (somme transactions == montant cashout)
│     │
│     ├─ Si complet :
│     │   ├─ Créer VirementInterne
│     │   ├─ Créer RapprochementBancaire verrouillé via createVerrouilleAuto()
│     │   └─ Pointer transactions + virement
│     │
│     ├─ Si incomplet :
│     │   └─ Warning : "Cashout #X incomplet (écart de Y€)"
│     │
│     └─ Si transactions manquantes sur N-1 :
│         └─ Info : "X transactions disponibles sur l'exercice YYYY"
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

Le résultat retourné par `synchroniserCashouts()` est enrichi (même format array actuel) :

```php
return [
    'virements_created' => int,
    'virements_updated' => int,
    'rapprochements_created' => int,
    'cashouts_incomplets' => list<string>,  // warnings détaillés
    'info_exercice_precedent' => list<string>,  // infos N-1
    'errors' => list<string>,
];
```

---

## Impact sur le code existant

### Fichiers modifiés

| Fichier | Modification |
|---|---|
| `HelloassoSync.php` (Livewire) | Fetch payments avec plage élargie N-1→N ; afficher les nouveaux champs du rapport |
| `HelloAssoSyncService.php` | Refonte de `synchroniserCashouts()` : tri chronologique, complétude, création conditionnelle virement + rapprochement auto via `createVerrouilleAuto()` |
| `RapprochementBancaireService.php` | Ajout de `createVerrouilleAuto()` — crée un rapprochement directement verrouillé avec pointage |
| `helloasso-sync.blade.php` | Affichage des rapprochements créés, cashouts incomplets, info N-1 |

### Fichiers non modifiés

| Fichier | Raison |
|---|---|
| `RapprochementBancaire.php` | Le modèle existant suffit |
| `TransactionService.php` | La protection `isLockedByRapprochement()` est déjà en place |
| `HelloAssoApiClient.php` | `fetchPayments()` et `extractCashOutsFromPayments()` ne changent pas (changement de plage dans l'appelant) |

### Pas de migration

Aucune nouvelle colonne ou table. Tout s'appuie sur l'infrastructure existante (`rapprochement_id`, `rapprochement_source_id`, `statut Verrouille`).

---

## Hors périmètre

- **Barre de progression** pendant la sync — à ajouter si les volumes deviennent importants
- **UI dédiée de visualisation des versements** — les rapprochements auto sont visibles dans l'écran de rapprochement existant du compte HelloAsso
- **Gestion des remboursements HelloAsso** — limitation connue, traitement manuel
- **Paiements échelonnés** — seul le premier payment d'un order est lié via `helloasso_payment_id` (V1)
