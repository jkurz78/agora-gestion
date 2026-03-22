# Intégration HelloAsso — Synchronisation & Unification du modèle

## Contexte

L'association utilise HelloAsso pour collecter en ligne des cotisations, des dons et des inscriptions à des opérations/événements. L'écran de paramétrage de connexion HelloAsso est déjà implémenté (credentials OAuth2, test de connexion). Ce chantier couvre :

1. **L'abandon des tables dédiées `dons` et `cotisations`** au profit du modèle unifié Transaction/TransactionLigne — la sous-catégorie porte la sémantique métier
2. **La synchronisation des données HelloAsso** vers le modèle unifié
3. **La gestion des versements HelloAsso** (cash-outs) via un compte bancaire dédié et des virements internes

---

## Décisions structurantes

### Unification du modèle

- Les tables `dons` et `cotisations` sont **abandonnées**. Toutes les écritures (dons, cotisations, inscriptions, recettes, dépenses) passent par `transactions` + `transaction_lignes`.
- La distinction don/cotisation/inscription est portée par la **sous-catégorie** (code CERFA) de la TransactionLigne.
- Les flags `pour_dons` et `pour_cotisations` sur `sous_categories` restent pertinents pour guider la saisie et le filtrage.
- `exercice` (cotisations) est dérivé de la date via `scopeForExercice()` — pas de champ dédié.
- `recu_emis` et `objet` (dons) sont abandonnés dans l'immédiat. Les reçus fiscaux seront gérés dans un chantier séparé (table `documents_emis`), découplé de cette intégration.
- Pas de nouvelle table pour les inscriptions — c'est une TransactionLigne avec la sous-catégorie appropriée et une `operation_id`.

### Mapping HelloAsso → SVS

- **1 Transaction SVS = 1 tiers bénéficiaire unique** au sein d'une commande HelloAsso.
- Le tiers de la Transaction est le **bénéficiaire** (User HelloAsso), pas le payeur (Payer). L'information du payeur n'est pas conservée dans le modèle SVS.
- Si une commande HelloAsso contient des items pour des bénéficiaires différents (ex. un parent paie pour 2 enfants), elle génère **autant de Transactions que de bénéficiaires distincts**.
- Si une commande contient plusieurs items pour le même bénéficiaire (ex. cotisation + don), ils sont regroupés en **une seule Transaction multi-lignes**.
- `helloasso_order_id` sur Transaction lie les transactions issues d'une même commande.
- `helloasso_item_id` sur TransactionLigne identifie chaque ligne de façon unique pour l'idempotence.

### Compte HelloAsso dédié

- Un `CompteBancaire` dédié "HelloAsso" reçoit toutes les transactions issues de la synchronisation.
- Ce n'est pas le compte courant de l'association — c'est un compte intermédiaire modélisant le solde détenu par HelloAsso.
- Quand HelloAsso effectue un versement (cash-out), un `VirementInterne` est créé du compte HelloAsso vers le compte courant.

### Versements (cash-outs)

- Un versement HelloAsso regroupe N paiements en 1 virement bancaire vers l'association.
- Le `cashOutId` HelloAsso est stocké sur les Transactions (`helloasso_cashout_id`) et sur le VirementInterne correspondant.
- **Contrôle d'intégrité** : la somme des transactions portant un `cashOutId` donné + le montant du VirementInterne associé doit être = 0.
- **Verrouillage automatique** : quand un versement est rapproché, toutes les transactions liées par le même `cashOutId` sont verrouillées ensemble (rapprochement auto-généré).

---

## Évolutions du schéma de données

### Table `transactions` — colonnes ajoutées

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `helloasso_order_id` | bigint | nullable, index | ID de la commande HelloAsso source |
| `helloasso_cashout_id` | bigint | nullable, index | ID du versement HelloAsso (cash-out) |

### Table `transaction_lignes` — colonnes ajoutées

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `helloasso_item_id` | bigint | nullable, unique | ID de l'item HelloAsso — clé d'idempotence |

### Table `virements_internes` — colonnes ajoutées

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `helloasso_cashout_id` | bigint | nullable, unique | ID du versement HelloAsso |

### Table `sous_categories` — colonne ajoutée

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `pour_inscriptions` | boolean | default false | Flag pour les sous-catégories d'inscription à des opérations |

### Enum `ModePaiement` — valeur ajoutée

| Valeur | Label | Usage |
|---|---|---|
| `helloasso` | `HelloAsso` | Mode de paiement pour les transactions issues de HelloAsso |

### Migration des données existantes

Les données des tables `dons` et `cotisations` doivent être migrées vers `transactions` + `transaction_lignes` avant suppression des tables. La migration :

1. Pour chaque Don existant → crée une Transaction de type `recette` avec une TransactionLigne liée à la sous-catégorie du don. Champs mappés : `tiers_id`, `date`, `montant` → `montant_total`, `mode_paiement`, `compte_id`, `pointe`, `rapprochement_id`, `numero_piece`, `saisi_par`, `notes` (← `objet`), `deleted_at`. La TransactionLigne reprend `sous_categorie_id`, `operation_id`, `seance`, `montant`.
2. Pour chaque Cotisation existante → même logique. `date_paiement` → `date`, pas d'`operation_id` ni `seance`.
3. Les IDs de rapprochement (`rapprochement_id`) sont reportés sur la Transaction pour maintenir la cohérence du rapprochement bancaire.
4. Les tables `dons` et `cotisations` sont conservées temporairement (renommées `legacy_dons`, `legacy_cotisations`) le temps de valider la migration, puis supprimées dans une migration ultérieure.

---

## Architecture de la synchronisation

### Flux principal

```
Bouton "Synchroniser avec HelloAsso"
  │
  ├─ 1. Authentification OAuth2 (token 30 min)
  │
  ├─ 2. GET /v5/organizations/{slug}/orders?from={debut_exercice}&to={fin_exercice}
  │     → Pagination par continuationToken jusqu'à épuisement
  │
  ├─ 3. Pour chaque Order :
  │     ├─ Grouper les Items par bénéficiaire (User, ou Payer si User absent)
  │     ├─ Pour chaque groupe (bénéficiaire unique) :
  │     │   ├─ Upsert Tiers (match helloasso_id ou email)
  │     │   ├─ Upsert Transaction (match helloasso_order_id + tiers_id)
  │     │   └─ Pour chaque Item du groupe :
  │     │       ├─ Résoudre la sous-catégorie (mapping item.type → SousCategorie)
  │     │       ├─ Résoudre l'opération (mapping formSlug → Operation, si applicable)
  │     │       └─ Upsert TransactionLigne (match helloasso_item_id)
  │     └─ Reporter le cashOutId des Payments sur la Transaction
  │
  ├─ 4. GET /v5/organizations/{slug}/cash-outs?from={debut_exercice}&to={fin_exercice}
  │     → Pour chaque CashOut :
  │       ├─ Upsert VirementInterne (match helloasso_cashout_id)
  │       │   compte_source = Compte HelloAsso
  │       │   compte_destination = Compte courant par défaut (paramétrable)
  │       └─ Marquer les Transactions liées (helloasso_cashout_id)
  │
  └─ 5. Rapport de synchro : créés / mis à jour / erreurs
```

### Résolution des tiers

Ordre de recherche pour éviter les doublons :

1. `Tiers::where('helloasso_id', $userId)` — match exact si déjà synchronisé
2. `Tiers::where('email', $userEmail)` — match par email si nouveau dans HelloAsso mais déjà saisi manuellement
3. Création d'un nouveau Tiers avec les données HelloAsso (nom, prénom, email, adresse, date de naissance)

Lors du match par email, le `helloasso_id` est mis à jour sur le Tiers existant pour les prochaines synchros.

### Résolution des sous-catégories

Un mapping configurable (en base ou en config) associe les types d'items HelloAsso aux sous-catégories SVS :

| Item type HelloAsso | Sous-catégorie SVS (par défaut) |
|---|---|
| `Donation` | Sous-catégorie don (code CERFA 754 ou selon config) |
| `Membership` | Sous-catégorie cotisation (code CERFA 751) |
| `Registration` | Sous-catégorie inscription (à configurer par l'utilisateur) |

Ce mapping est paramétrable dans l'écran HelloAsso (extension de l'écran existant).

### Résolution des opérations

- Chaque formulaire HelloAsso (`formSlug` + `formType`) peut être associé à une Opération SVS.
- Ce mapping est paramétrable (table `helloasso_form_mappings` ou section dans l'écran de paramétrage).
- Quand un item provient d'un formulaire mappé, toutes les lignes héritent de l'opération.
- Pas de mapping automatique — le trésorier configure manuellement l'association formSlug → Opération.

### Gestion des montants

- HelloAsso exprime les montants en **centimes** (int).
- Conversion : `$montant = $helloassoAmount / 100` lors de l'import.
- SVS stocke en `decimal(10,2)`.

### Gestion du mode de paiement

- Toutes les transactions issues de HelloAsso utilisent le mode de paiement `helloasso`.
- On ne conserve pas le détail du moyen de paiement HelloAsso (CB, SEPA, etc.) car c'est HelloAsso qui encaisse, pas l'association.

---

## Service `HelloAssoSyncService`

### Responsabilités

- Orchestrer la synchronisation complète pour un exercice donné
- Gérer l'authentification OAuth2 (réutilisation du token pendant la synchro)
- Upsert des Tiers, Transactions, TransactionLignes, VirementInternes
- Retourner un rapport de synchronisation

### Méthode principale

```php
public function synchroniser(int $exercice): HelloAssoSyncResult
```

### Value object `HelloAssoSyncResult`

```php
final class HelloAssoSyncResult
{
    public function __construct(
        public readonly int $tiersCreated,
        public readonly int $tiersUpdated,
        public readonly int $transactionsCreated,
        public readonly int $transactionsUpdated,
        public readonly int $lignesCreated,
        public readonly int $lignesUpdated,
        public readonly int $virementsCreated,
        public readonly int $virementsUpdated,
        public readonly array $errors,
    ) {}
}
```

### Transactionnalité

Chaque commande HelloAsso est traitée dans sa propre `DB::transaction()`. Si une commande échoue, les autres continuent. Les erreurs sont collectées dans le rapport.

---

## Interface utilisateur

### Extension de l'écran Paramètres > HelloAsso

L'écran existant (`parametres.helloasso`) est enrichi avec :

#### Section "Synchronisation"

```
┌─ Synchronisation ──────────────────────────────────────────────┐
│                                                                 │
│ Compte HelloAsso    [ Select : compte bancaire dédié HA ]       │
│ Compte de versement [ Select : compte courant destination ]     │
│                                                                 │
│ Mapping des sous-catégories :                                   │
│   Dons          [ Select : sous-catégorie ]                     │
│   Cotisations   [ Select : sous-catégorie ]                     │
│   Inscriptions  [ Select : sous-catégorie ]                     │
│                                                                 │
│ Mapping des formulaires → opérations :                          │
│   (chargé dynamiquement depuis l'API HelloAsso)                 │
│   stage-ete-2026 (Event)      [ Select : Opération SVS ]       │
│   adhesion-2025 (Membership)  [ — Aucune — ]                   │
│   dons-libres (Donation)      [ — Aucune — ]                   │
│                                                                 │
│ [ Enregistrer la configuration ]                                │
└─────────────────────────────────────────────────────────────────┘
```

#### Section "Lancer la synchronisation"

```
┌─ Lancer la synchronisation ─────────────────────────────────────┐
│                                                                  │
│ Exercice  [ Select : 2025 (sept 2025 → août 2026) ▼ ]          │
│                                                                  │
│ [ Synchroniser avec HelloAsso ]                                  │
│                                                                  │
│ ┌─ Résultat (après exécution) ────────────────────────────────┐ │
│ │ ✓ Synchronisation terminée                                   │ │
│ │   Tiers : 12 créés, 3 mis à jour                             │ │
│ │   Transactions : 47 créées, 5 mises à jour                  │ │
│ │   Versements : 3 créés                                       │ │
│ │                                                               │ │
│ │ ⚠ 2 erreurs :                                                │ │
│ │   - Commande #12345 : sous-catégorie non mappée pour "Shop"  │ │
│ │   - Commande #12390 : email tiers vide                       │ │
│ └──────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────┘
```

---

## Impact sur les écrans existants

### Transaction Universelle

Le composant TransactionUniverselle (spec du 2026-03-19) est déjà conçu pour afficher toutes les transactions. L'unification don/cotisation simplifie le UNION car les types DON et COT disparaissent au profit de transactions recette classiques. La distinction visuelle (badge, filtrage) peut être maintenue via un flag ou la sous-catégorie.

Les impacts spécifiques :

- **Badges Type** : DON et COT disparaissent. Toutes les écritures sont DÉP, REC ou VIR. Un filtre par sous-catégorie peut offrir une vue "cotisations uniquement" ou "dons uniquement".
- **Bouton Nouveau** : plus de formulaires DonForm / CotisationForm séparés. Le TransactionForm unifié gère tous les cas via le choix de sous-catégorie.
- **UNION simplifié** : plus besoin d'inclure les tables `dons` et `cotisations` dans le UNION.

### Fiche membre (TiersTransactions)

La fiche d'un membre affiche ses transactions via `Transaction::where('tiers_id', $membreId)`. Les cotisations et dons du membre sont naturellement inclus car le tiers est le bénéficiaire. Pas de changement structurel.

Pour afficher spécifiquement "les cotisations de ce membre", on filtre les TransactionLignes dont la sous-catégorie a `pour_cotisations = true`.

### Rapprochement bancaire

Pas de changement structurel. Les transactions HelloAsso sont sur le compte HelloAsso dédié et se rapprochent comme les autres. Le verrouillage par `cashOutId` est un mécanisme additionnel spécifique aux versements.

### Rapports CERFA

Les rapports qui agrègent les dons (reçus fiscaux) doivent requêter `transaction_lignes` jointes aux `sous_categories` avec `pour_dons = true`, au lieu de la table `dons` directement.

### DonForm / CotisationForm

Ces formulaires sont **supprimés**. La saisie manuelle d'un don ou d'une cotisation se fait via TransactionForm, en choisissant la sous-catégorie appropriée.

---

## Séquencement des chantiers

Ce projet se décompose en phases indépendantes et déployables séparément :

### Phase 1 — Migration du modèle (pré-requis)

1. Ajouter les colonnes HelloAsso sur `transactions`, `transaction_lignes`, `virements_internes`
2. Ajouter `pour_inscriptions` sur `sous_categories`
3. Ajouter `helloasso` dans l'enum `ModePaiement`
4. Migrer les données `dons` → `transactions` + `transaction_lignes`
5. Migrer les données `cotisations` → `transactions` + `transaction_lignes`
6. Adapter les écrans (suppression DonForm/CotisationForm, adaptation des requêtes, rapports)
7. Renommer les tables legacy

### Phase 2 — Synchronisation HelloAsso

1. `HelloAssoSyncService` avec résolution tiers, sous-catégories, opérations
2. Extension de l'écran Paramètres > HelloAsso (configuration mapping + lancement synchro)
3. Gestion des versements (VirementInterne auto-générés)

### Phase 3 — Verrouillage versements (évolution)

1. Verrouillage automatique par `cashOutId`
2. Contrôle d'intégrité (somme = 0)

---

## Hors périmètre

- **Webhooks temps réel** — évolution future, la synchro ponctuelle suffit
- **Table `documents_emis`** (reçus fiscaux, factures) — chantier séparé
- **Payeur HelloAsso** — non conservé dans le modèle SVS (récupérable via l'API si besoin)
- **Détail du moyen de paiement HelloAsso** (CB vs SEPA) — non pertinent pour la compta de l'association
- **Remboursements HelloAsso** — à traiter dans une évolution future
