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
- `exercice` (cotisations) : un champ `exercice` nullable (integer) est ajouté sur `TransactionLigne` pour les lignes de type cotisation. Ce champ préserve l'attribution explicite d'une cotisation à un exercice, indépendamment de la date de paiement (un membre peut payer sa cotisation 2025 en septembre 2026). Pour les transactions issues de HelloAsso, l'exercice est dérivé de la date du formulaire d'adhésion. Le scope `forExercice()` sur les requêtes de cotisations filtre sur ce champ quand il est renseigné, et sur la date sinon.
- `recu_emis` (dons) est abandonné dans l'immédiat. Les reçus fiscaux seront gérés dans un chantier séparé (table `documents_emis`), découplé de cette intégration.
- `objet` (dons) : mappé vers le champ `libelle` de la Transaction lors de la migration (pas vers `notes`). C'est ce champ qui est affiché dans les listes.
- Pas de nouvelle table pour les inscriptions — c'est une TransactionLigne avec la sous-catégorie appropriée et une `operation_id`.
- **Règle métier** : quand une TransactionLigne utilise une sous-catégorie avec `pour_inscriptions = true`, le champ `operation_id` devient **obligatoire** (validation côté formulaire et côté synchro HelloAsso — un item Registration sans mapping formSlug → Opération est bloquant à l'étape 3 du workflow).

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
- **Contrôle d'intégrité** : la somme des `montant_total` des transactions portant un `cashOutId` donné doit être égale au `montant` du VirementInterne associé (toutes les valeurs sont positives — c'est une égalité, pas une somme à zéro).
- **Verrouillage automatique** : quand un versement est rapproché, toutes les transactions liées par le même `cashOutId` sont verrouillées ensemble (rapprochement auto-généré).

---

## Évolutions du schéma de données

### Table `transactions` — colonnes ajoutées

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `helloasso_order_id` | bigint | nullable, index composite unique avec `tiers_id` | ID de la commande HelloAsso source |
| `helloasso_cashout_id` | bigint | nullable, index | ID du versement HelloAsso (cash-out) |

### Table `transaction_lignes` — colonnes ajoutées

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `helloasso_item_id` | bigint | nullable, unique | ID de l'item HelloAsso — clé d'idempotence |
| `exercice` | integer | nullable | Exercice d'attribution (cotisations). Permet de dissocier l'exercice de la date de paiement. |

### Table `virements_internes` — colonnes ajoutées

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `helloasso_cashout_id` | bigint | nullable, unique | ID du versement HelloAsso |

### Table `sous_categories` — colonne ajoutée

| Colonne | Type | Contraintes | Description |
|---|---|---|---|
| `pour_inscriptions` | boolean | default false | Flag pour les sous-catégories d'inscription à des opérations. Les trois flags `pour_dons`, `pour_cotisations`, `pour_inscriptions` sont mutuellement indépendants — une sous-catégorie peut en activer plusieurs si pertinent métier, mais l'UI de mapping HelloAsso filtre par flag correspondant au type d'item. |

### Enum `ModePaiement` — valeur ajoutée

| Valeur | Label | Usage |
|---|---|---|
| `helloasso` | `HelloAsso` | Mode de paiement pour les transactions issues de HelloAsso |

### Migration des données existantes

Les données des tables `dons` et `cotisations` doivent être migrées vers `transactions` + `transaction_lignes` avant suppression des tables. La migration :

1. Pour chaque Don existant → crée une Transaction de type `recette` avec une TransactionLigne liée à la sous-catégorie du don. Champs mappés : `tiers_id`, `date`, `montant` → `montant_total`, `mode_paiement`, `compte_id`, `pointe`, `rapprochement_id`, `numero_piece`, `saisi_par`, `libelle` (← `objet`), `deleted_at`. La TransactionLigne reprend `sous_categorie_id`, `operation_id`, `seance`, `montant`.
2. Pour chaque Cotisation existante → même logique. `date_paiement` → `date`, `libelle` ← `'Cotisation ' || exercice`, `saisi_par` ← null (la table cotisations ne porte pas ce champ), pas d'`operation_id` ni `seance`. Le champ `exercice` de la cotisation est reporté sur `transaction_lignes.exercice`.
3. Les IDs de rapprochement (`rapprochement_id`) sont reportés sur la Transaction pour maintenir la cohérence du rapprochement bancaire.
4. Les tables `dons` et `cotisations` sont conservées temporairement (renommées `legacy_dons`, `legacy_cotisations`) le temps de valider la migration, puis supprimées dans une migration ultérieure.

---

## Architecture de la synchronisation

### Workflow guidé en étapes

La synchronisation n'est pas un import automatique aveugle. C'est un **workflow interactif** qui permet au trésorier de valider les rapprochements avant import.

```
Bouton "Synchroniser avec HelloAsso"
  │
  ├─ Étape 1 — Récupération et analyse
  │   ├─ Authentification OAuth2 (token 30 min)
  │   ├─ GET /v5/organizations/{slug}/orders?from={debut_exercice}&to={fin_exercice}
  │   │   → Pagination par continuationToken jusqu'à épuisement
  │   ├─ GET /v5/organizations/{slug}/cash-outs?from={debut_exercice}&to={fin_exercice}
  │   └─ Stockage temporaire des données récupérées (table de staging ou session)
  │
  ├─ Étape 2 — Rapprochement des tiers
  │   Pour chaque personne HelloAsso non encore liée à un Tiers SVS :
  │   ├─ Proposition automatique de correspondance (email, nom+prénom)
  │   ├─ Le trésorier choisit :
  │   │   ├─ Associer à un tiers existant → passe est_helloasso à true
  │   │   ├─ Créer un nouveau tiers par import (est_helloasso = true)
  │   │   └─ Ignorer (l'item ne sera pas importé)
  │   └─ Un tiers déjà lié (helloasso_id connu) passe automatiquement
  │
  ├─ Étape 3 — Rapprochement des formulaires → opérations
  │   Pour chaque formSlug non encore mappé :
  │   ├─ Associer à une opération existante
  │   ├─ Créer une nouvelle opération
  │   └─ Pas d'opération (ne concerne pas les inscriptions)
  │
  ├─ Étape 4 — Import
  │   ├─ Pour chaque Order :
  │   │   ├─ Grouper les Items par bénéficiaire (User, ou Payer si User absent)
  │   │   ├─ Pour chaque groupe (bénéficiaire unique) :
  │   │   │   ├─ Upsert Transaction (match helloasso_order_id + tiers_id)
  │   │   │   └─ Pour chaque Item du groupe :
  │   │   │       ├─ Résoudre la sous-catégorie (mapping item.type → SousCategorie)
  │   │   │       ├─ Résoudre l'opération (mapping formSlug → Operation)
  │   │   │       └─ Upsert TransactionLigne (match helloasso_item_id)
  │   │   └─ Reporter le cashOutId des Payments sur la Transaction
  │   ├─ Pour chaque CashOut :
  │   │   ├─ Upsert VirementInterne (match helloasso_cashout_id)
  │   │   └─ Marquer les Transactions liées (helloasso_cashout_id)
  │   └─ Rapport de synchro : créés / mis à jour / ignorés / erreurs
  │
  └─ Les étapes 2 et 3 ne sont bloquantes que lors de la première synchro
      ou quand de nouveaux tiers/formulaires apparaissent. Les synchros
      suivantes passent directement à l'étape 4 si tout est déjà rapproché.
```

### Rapprochement des tiers

L'objet User HelloAsso (bénéficiaire) n'a **pas d'ID persistant** dans l'API — c'est une structure embarquée (nom, email, adresse) sans identifiant stable. Le rapprochement avec les Tiers SVS se fait par **email** (match principal) et **nom+prénom** (suggestion secondaire).

Le champ `helloasso_id` (string) existant sur Tiers est **reconverti en booléen `est_helloasso`** :
- `est_helloasso = true` → le tiers est piloté par HelloAsso. Ses données (nom, adresse, etc.) sont mises à jour à chaque synchro. Les modifications manuelles restent possibles mais seront écrasées à la prochaine synchronisation.
- `est_helloasso = false` (défaut) → tiers géré manuellement.

Le lien entre un tiers SVS et une personne HelloAsso est établi lors de l'**étape 2 du workflow** (rapprochement tiers) par le trésorier. Les cas limites (changement d'email, homonymes) sont gérés manuellement à cette étape.

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

### Gestion de `saisi_par`

- Les transactions créées par la synchronisation ont `saisi_par` = l'utilisateur connecté qui a déclenché la synchro.
- Cela permet de tracer qui a importé les données dans l'audit trail.

### Items à montant zéro

- Les items HelloAsso avec `amount = 0` (inscriptions gratuites) sont importés normalement. Ils créent des TransactionLignes à 0,00 €, ce qui est comptablement correct (constatation d'une inscription sans flux financier).

### Remboursements HelloAsso

- Hors périmètre de cette version. Si un paiement est remboursé côté HelloAsso après synchronisation, la Transaction SVS conserve son montant original. Le trésorier doit gérer manuellement le remboursement dans SVS. Cette limitation est documentée dans l'écran de synchro.

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

- **Badges Type** : DON et COT disparaissent comme types de transaction. Toutes les écritures sont DÉP, REC ou VIR.
- **Bouton Nouveau** : plus de formulaires DonForm / CotisationForm séparés. Le TransactionForm unifié gère tous les cas via le choix de sous-catégorie.
- **UNION simplifié** : plus besoin d'inclure les tables `dons` et `cotisations` dans le UNION.

### Navigation menu — Dons et Cotisations

Les entrées de menu "Transactions > Dons" et "Transactions > Cotisations" sont **conservées** mais pointent désormais vers des vues filtrées de TransactionUniverselle :

- **Menu "Dons"** → `TransactionUniverselle` avec une prop `$sousCategorieFilter = 'pour_dons'`. Le composant filtre les transactions qui ont **au moins une TransactionLigne** dont la sous-catégorie a `pour_dons = true`.
- **Menu "Cotisations"** → `TransactionUniverselle` avec une prop `$sousCategorieFilter = 'pour_cotisations'`. Même logique sur `pour_cotisations = true`.

Ce filtre n'est plus basé sur le type de la transaction (qui est toujours `recette`) mais sur les **flags de la sous-catégorie** des lignes. C'est une nouvelle prop verrouillée du composant TransactionUniverselle qui vient s'ajouter à celles définies dans la spec du 2026-03-19 (`$compteId`, `$tiersId`, `$types`, `$exercice`).

Le filtre est un `whereHas('lignes.sousCategorie', fn($q) => $q->where($flag, true))` côté Eloquent. Les boutons toggle Type (DÉP/REC/VIR) ne s'affichent pas quand ce filtre est actif (car toutes les transactions sont de type recette).

À terme, le même mécanisme pourra servir pour une entrée "Inscriptions" via `pour_inscriptions = true`.

### Fiche membre (TiersTransactions)

La fiche d'un membre affiche ses transactions via `Transaction::where('tiers_id', $membreId)`. Les cotisations et dons du membre sont naturellement inclus car le tiers est le bénéficiaire. Pas de changement structurel.

Pour afficher spécifiquement "les cotisations de ce membre", on filtre les TransactionLignes dont la sous-catégorie a `pour_cotisations = true`.

### Rapprochement bancaire

Pas de changement structurel. Les transactions HelloAsso sont sur le compte HelloAsso dédié et se rapprochent comme les autres. Le verrouillage par `cashOutId` est un mécanisme additionnel spécifique aux versements.

### Rapports CERFA

Les rapports qui agrègent les dons (reçus fiscaux) doivent requêter `transaction_lignes` jointes aux `sous_categories` avec `pour_dons = true`, au lieu de la table `dons` directement.

### DonForm / CotisationForm

Ces formulaires sont **supprimés**. La saisie manuelle d'un don ou d'une cotisation se fait via TransactionForm, en choisissant la sous-catégorie appropriée.

### Blast radius — fichiers impactés par l'unification (Phase 1)

| Fichier | Action |
|---|---|
| `app/Models/Don.php` | Supprimer |
| `app/Models/Cotisation.php` | Supprimer |
| `app/Services/DonService.php` | Supprimer |
| `app/Services/CotisationService.php` | Supprimer |
| `app/Livewire/DonForm.php` | Supprimer |
| `app/Livewire/CotisationForm.php` | Supprimer |
| `app/Livewire/DonList.php` | Supprimer |
| `app/Livewire/CotisationList.php` | Supprimer |
| `app/Services/SoldeService.php` | Adapter — retirer les branches `dons()` et `cotisations()`, tout passe par `recettes()` |
| `app/Services/RapprochementBancaireService.php` | Adapter — retirer les requêtes Don/Cotisation directes |
| `app/Services/RapportService.php` | Adapter — requêter `transaction_lignes` jointes aux sous-catégories au lieu des tables `dons`/`cotisations` |
| `app/Services/TransactionUniverselleService.php` | Simplifier — retirer les branches UNION dons/cotisations |
| `app/Livewire/Dashboard.php` | Adapter — `Don::forExercice()` → requête TransactionLigne avec sous-cat `pour_dons`, cotisations idem |
| `app/Livewire/RapprochementDetail.php` | Adapter — retirer les imports/références Don/Cotisation |
| `app/Livewire/TransactionCompteList.php` | Adapter — retirer les références Don/Cotisation |
| `app/Models/CompteBancaire.php` | Adapter — retirer les relations `dons()` et `cotisations()` |
| `app/Models/Tiers.php` | Adapter — retirer les relations `dons()` et `cotisations()` |
| `app/Models/RapprochementBancaire.php` | Adapter — retirer les relations `dons()` et `cotisations()` |
| `routes/web.php` | Adapter — retirer les routes `/dons` et `/cotisations` dédiées |
| `app/Services/BudgetService.php` | Vérifier — fonctionne déjà sur TransactionLigne, mais valider que les anciennes données don/cotisation non comptées en budget ne créent pas de doublon |

---

## Séquencement des chantiers

Ce projet se décompose en **lots fins**, chacun déployable et testable indépendamment. Chaque lot produit une application fonctionnelle.

### Lot 1 — Évolutions de schéma (migrations sans impact fonctionnel)

Ajout de colonnes, sans modifier le comportement existant :

1. Ajouter `helloasso_order_id`, `helloasso_cashout_id` sur `transactions`
2. Ajouter `helloasso_item_id`, `exercice` sur `transaction_lignes`
3. Ajouter `helloasso_cashout_id` sur `virements_internes`
4. Ajouter `pour_inscriptions` sur `sous_categories`
5. Ajouter `helloasso` dans l'enum `ModePaiement`
6. Ajouter la validation conditionnelle : sous-catégorie `pour_inscriptions` → `operation_id` obligatoire sur TransactionLigne

### Lot 2 — Migration des données dons → transactions

1. Migration SQL : chaque Don → Transaction (type recette) + TransactionLigne
2. Adaptation de `SoldeService` — retirer la branche `dons()`
3. Adaptation de `RapprochementBancaireService` — retirer les requêtes Don
4. Adaptation de `RapprochementDetail` — retirer les références Don
5. Adaptation de `Dashboard` — `Don::forExercice()` → requête TransactionLigne avec sous-cat `pour_dons`
6. Adaptation de `RapportService` (CERFA) — requêter `transaction_lignes` + sous-catégories `pour_dons`
7. Adaptation de `TransactionUniverselleService` — retirer la branche UNION dons
8. Suppression de `DonForm`, `DonList`, `DonService`, `Don` (modèle)
9. Adaptation des relations sur `CompteBancaire`, `Tiers`, `RapprochementBancaire` — retirer `dons()`
10. Adaptation de `routes/web.php` — route `/dons` pointe vers TransactionUniverselle filtrée
11. Renommer table `dons` → `legacy_dons`

### Lot 3 — Migration des données cotisations → transactions

Même logique que lot 2, appliquée aux cotisations :

1. Migration SQL : chaque Cotisation → Transaction + TransactionLigne (avec `exercice` reporté)
2. Adaptation de `SoldeService`, `RapprochementBancaireService`, `RapprochementDetail`
3. Adaptation de `Dashboard` — requête membres sans cotisation via TransactionLigne
4. Adaptation de `TransactionUniverselleService` — retirer la branche UNION cotisations
5. Suppression de `CotisationForm`, `CotisationList`, `CotisationService`, `Cotisation` (modèle)
6. Adaptation des relations sur `CompteBancaire`, `Tiers`, `RapprochementBancaire` — retirer `cotisations()`
7. Adaptation de `routes/web.php` — route `/cotisations` pointe vers TransactionUniverselle filtrée
8. Renommer table `cotisations` → `legacy_cotisations`
9. Vérifier `BudgetService` — pas de doublon avec les données migrées

### Lot 4 — Synchronisation HelloAsso : récupération et rapprochement tiers

1. Service `HelloAssoApiClient` — encapsule l'authentification OAuth2 et les appels API (orders, cash-outs, forms)
2. Écran de rapprochement des tiers — liste les personnes HelloAsso non liées, propose correspondances, permet associer/créer/ignorer
3. Marquage `est_helloasso = true` sur les Tiers associés
4. Migration : convertir la colonne `helloasso_id` (string nullable) en `est_helloasso` (boolean, default false)

### Lot 5 — Synchronisation HelloAsso : rapprochement formulaires et import

1. Écran de rapprochement des formulaires → opérations (associer/créer/ignorer)
2. Configuration du mapping sous-catégories (Donation→sous-cat, Membership→sous-cat, Registration→sous-cat)
3. Configuration compte HelloAsso + compte de versement
4. `HelloAssoSyncService` — import des orders en transactions (étape 4 du workflow)
5. Rapport de synchronisation (créés/mis à jour/ignorés/erreurs)

### Lot 6 — Gestion des versements HelloAsso

1. Import des cash-outs → VirementInterne (compte HelloAsso → compte courant)
2. Marquage `helloasso_cashout_id` sur les transactions liées
3. Contrôle d'intégrité : somme transactions = montant du virement

### Lot 7 — Verrouillage versements (évolution)

1. Verrouillage automatique des transactions par `cashOutId` (rapprochement auto-généré)
2. UI de visualisation du verrouillage par versement

---

## Hors périmètre

- **Webhooks temps réel** — évolution future, la synchro ponctuelle suffit
- **Table `documents_emis`** (reçus fiscaux, factures) — chantier séparé
- **Payeur HelloAsso** — non conservé dans le modèle SVS (récupérable via l'API si besoin)
- **Détail du moyen de paiement HelloAsso** (CB vs SEPA) — non pertinent pour la compta de l'association
- **Remboursements HelloAsso** — limitation connue : une transaction synchronisée puis remboursée côté HelloAsso conserve son montant original dans SVS (traitement manuel par le trésorier)
- **Sync en job asynchrone** — pour la V1 la synchro est synchrone (requête Livewire). Si les volumes deviennent importants, une évolution vers un job queued avec barre de progression est envisageable
