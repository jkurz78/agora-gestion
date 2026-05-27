# Moteur partie double — Documentation interne

**Version documentée** : Slice 1 (`feat/compta-v5`)
**Date** : 2026-05-27
**Statut** : En production sur branche `feat/compta-v5`, cutover prod planifié post-backfill.

Mémoires de référence :
- `project_compta_partie_double.md` — cadrage initial (2026-05-02)
- `project_compta_v5_sous_slice_1a.md` — Data layer (schéma)
- `project_compta_v5_sous_slice_1b.md` — Services EcritureGenerator + LettrageService
- `project_compta_v5_sous_slice_1c.md` — Branchements UI, rapports, extournes
- `project_compta_v5_sous_slice_1d.md` — Backfill, renommage UI, cleanup

---

## 1. Vue d'ensemble

### Pourquoi la partie double ?

AgoraGestion stockait jusque-là les opérations financières dans un modèle à une seule ligne par transaction (`transactions.montant_total` + `transaction_lignes.montant` sans sens débit/crédit). Ce modèle, dit *cash basis simplifié*, convient aux associations non fiscalisées mais présente plusieurs limitations :

- Impossible de produire un FEC (Fichier des Écritures Comptables) conforme à BOI-CF-IOR-60-40-20 sans réécriture complète.
- Les tiers (clients 411, fournisseurs 401) ne sont pas modélisables nativement.
- La TVA, les immobilisations et les provisions nécessitent des lignes de contrepartie.

Le cadrage initial (`project_compta_partie_double.md`, 2026-05-02) envisageait une *cash basis enrichie* limitée aux associations fiscalisées. Le slice 1 a révisé cette orientation : on stocke en **partie double uniforme dès aujourd'hui pour toutes les associations**. Voir ADR-003 (`docs/adr/003-passage-partie-double.md`).

### Ce que le slice 1 livré

Le slice 1 (Steps 1 à 39 sur la branche `feat/compta-v5`) pose le moteur complet :

| Sous-slice | Périmètre |
|---|---|
| **1a** | Schéma cible : tables `comptes`, `lettrage_audit`, colonnes PD sur `transaction_lignes` |
| **1b** | Services : `LettrageService` + `EcritureGenerator` (6 méthodes `pour*`) |
| **1c** | Branchements : tous les services métier rebranchés sur `EcritureGenerator`, rapports, rappro, extournes |
| **1d** | Backfill, commande artisan, renommage UI, cleanup dette technique |

Aucun changement fonctionnel visible utilisateur dans le slice 1 : l'UI est inchangée, les données legacy sont conservées, le moteur partie double est transparent.

---

## 2. Modèle de données

### Table `comptes`

Plan de comptable général (PCG) simplifié, un enregistrement par compte, scopé par association.

```
comptes
  id                  bigint PK
  association_id      bigint FK → associations (scope multi-tenant)
  numero_pcg          string  -- ex. '411', '512001', '706'
  intitule            string  -- ex. 'Clients', 'BNP Compte courant', 'Prestations'
  classe              tinyint -- 1..7 (extrait de numero_pcg)
  est_systeme         boolean -- true = compte créé par le seeder (411, 401, 5112, 530, 512X)
  lettrable           boolean -- true = les lignes peuvent être lettrées (411, 401, 5112)
  iban                string  -- IBAN du compte bancaire physique (512X uniquement)
  compte_bancaire_id  bigint  -- FK → comptes_bancaires (512X uniquement, résolution IBAN)
  created_at / updated_at
```

**Classes attendues** :
- Classe 1 : Capitaux
- Classe 2 : Immobilisations
- Classe 3 : Stocks
- Classe 4 : Tiers (411 Clients, 401 Fournisseurs)
- Classe 5 : Trésorerie (512X banques, 5112 chèques à encaisser, 530 caisse)
- Classe 6 : Charges (dépenses)
- Classe 7 : Produits (recettes)

**Mapping SousCategorie → Compte** : `sous_categories.code_cerfa = comptes.numero_pcg`. La résolution est faite par `CompteVentilationResolver::resoudre` (voir §6).

### Table `transaction_lignes` (colonnes enrichies)

Les colonnes existantes (`sous_categorie_id`, `montant`, `seance_id`, etc.) sont conservées (cohabitation legacy). Les colonnes partie double sont ajoutées :

```
transaction_lignes (colonnes partie double)
  compte_id       bigint  FK → comptes, nullable
                          null = ligne legacy (pas encore backfillée)
                          non-null = ligne partie double
  debit           decimal(15,2) nullable -- montant en débit (DR)
  credit          decimal(15,2) nullable -- montant en crédit (CR)
  tiers_id        bigint  FK → tiers, nullable -- exclusivement sur lignes 411/401
  lettrage_code   string  nullable -- code lettrage (ex. 'L1', 'L2') posé par LettrageService
```

**Invariant XOR** : une ligne partie double valide a soit `debit > 0` soit `credit > 0`, jamais les deux, jamais les deux à zéro. Vérifié par `TransactionLigneObserver` (observer Eloquent déclenché sur `save()`). L'observer est court-circuité si `compte_id === null` (ligne legacy).

### Table `lettrage_audit`

Trace chaque opération lettrer/délettrer. Immuable (pas d'UPDATE ni DELETE métier).

```
lettrage_audit
  id                    bigint PK
  association_id        bigint FK → associations
  compte_id             bigint FK → comptes
  action                enum('lettrer', 'delettre')
  lettrage_code         string -- code lettré ou délettre
  motif                 string nullable -- ex. 'Auto-délettrage suite à extourne de TX#42'
  user_id               bigint FK → users nullable (null = action système)
  transaction_ligne_ids JSON   -- liste des IDs de lignes concernées
  created_at
```

### Colonne `transactions.equilibree`

Booléen posé à `true` par `EcritureGenerator::createTransactionHeader` après vérification que `∑ debit = ∑ credit` sur toutes les lignes de la transaction. L'invariant est l'équivalent du *bilan équilibré* en comptabilité classique.

**Observer XOR** : `TransactionLigneObserver` refus de sauvegarder une ligne PD qui violerait l'invariant débit/crédit exclusif. Le flag `equilibree` est posé manuellement par `EcritureGenerator` (pas par l'observer — ce dernier vérifie chaque ligne isolément, pas l'équilibre global).

---

## 3. Tableau des comptes système

Ces comptes sont créés automatiquement par `SystemeSeeder` (Step 5, sous-slice 1a) pour chaque association. Ils ne sont pas modifiables par l'utilisateur (`est_systeme = true`).

| Numéro PCG | Intitulé | Rôle | Lettrable | Classe |
|---|---|---|---|---|
| **411** | Clients | Créances clients — tout tiers en recette | Oui | 4 |
| **401** | Fournisseurs | Dettes fournisseurs — tout tiers en dépense | Oui | 4 |
| **5112** | Chèques à encaisser | Portage physique d'un chèque reçu non encore déposé en banque | Oui | 5 |
| **530** | Caisse | Espèces (créé uniquement si l'association a des transactions en espèces) | Non | 5 |
| **512X** | Compte bancaire physique | Un compte par IBAN (`5121`, `5122`, etc.) | Non | 5 |

**Note 530 conditionnel** : le compte 530 n'est créé que si `EXISTS (SELECT 1 FROM transactions WHERE association_id = :id AND mode_paiement = 'especes' AND deleted_at IS NULL)`.

**Note 512X** : chaque `CompteBancaire` (tableau de bord bancaire) donne lieu à un compte 512X dont le numéro est auto-incrémenté (`5121`, `5122`, …). La résolution se fait par IBAN : `Compte::where('iban', $compteBancaire->iban)->bancaires()->first()`.

---

## 4. Flux principaux

### Convention de lecture des diagrammes

```
DR = Débit   CR = Crédit
[411 DR 100 tiers=Dupont]  = ligne compte 411, montant 100 en débit, tiers Dupont
<lettre A>                  = lettrage_code 'A' (les deux lignes marquées A forment une paire)
```

### 4.1 Recette comptant chèque

Un adhérent Dupont règle 100 € par chèque. La transaction T1 est créée avec **N+3 lignes** (école 411 systématique) :

```
T1 — Recette comptant chèque (type=recette, equilibree=true)
  [411 DR 100  tiers=Dupont]  <A>   ← créance technique ouverte (tiers ici)
  [706 CR 100]                       ← produit (classe 7, pas de tiers)
  [5112 DR 100]                      ← chèque reçu en portefeuille (portage)
  [411 CR 100  tiers=Dupont]  <A>   ← solde immédiat de la créance technique

∑ DR = 200    ∑ CR = 200    equilibree = true
```

Les lignes 411D et 411C sont **auto-lettrées** avec un code unique (ex. `L001`) dès la création — elles forment une *paire interne* qui clôt immédiatement la créance technique. Ce lettrage interne est distinct du lettrage cross-tx de l'encaissement (§4.2).

### 4.2 Encaissement d'une créance (suite du §4.1 — chèque déposé)

Quand la remise bancaire est comptabilisée, une transaction T4 est créée (voir §4.3). Mais si la créance vient d'une facture validée (§4.5), l'encaissement direct crée une T2 :

```
T1 — Facture validée (recette à crédit)
  [411 DR 500  tiers=Dupont]         ← créance ouverte (lettrée plus tard)
  [706 CR 300]
  [708 CR 200]

T2 — Encaissement (type=encaissement_creance, equilibree=true)
  [512X DR 500]                       ← argent sur le compte bancaire
  [411 CR 500  tiers=Dupont]  <B>   ← apure la créance T1

T1.411D lettrée <B> avec T2.411C → créance soldée cross-transaction
```

### 4.3 Remise bancaire

La remise regroupe N chèques (T1, T3, T5 — chacun avec une ligne 5112D) en une seule transaction T4 qui crédite chaque 5112 et débite le compte courant :

```
T1 — Recette chèque Dupont 100€  → [5112 DR 100] <non-lettrée>
T3 — Recette chèque Martin 50€   → [5112 DR  50] <non-lettrée>
T5 — Recette chèque Lebrun 75€   → [5112 DR  75] <non-lettrée>

T4 — Remise bancaire (equilibree=true, remise_id=42, reference=NULL)
  [512X DR 225]                     ← total déposé sur compte courant
  [5112 CR 100]               <C>  ← solde T1.5112D
  [5112 CR  50]               <D>  ← solde T3.5112D
  [5112 CR  75]               <E>  ← solde T5.5112D

T1.5112D <C> lettrée avec T4.5112C[0]  (auto-lettrage 1↔1 sans regroupement par tiers)
T3.5112D <D> lettrée avec T4.5112C[1]
T5.5112D <E> lettrée avec T4.5112C[2]
```

**Asymétrie chèque dépense** : pour une *dépense* par chèque, le compte 5112 n'est **pas** utilisé. La ligne de trésorerie va directement sur 512X. Voir §5.

**T4 identifiée** par le triple critère : `remise_id = N AND reference IS NULL AND equilibree = true`.

### 4.4 Extourne

L'extourne crée un miroir T2' de T1. Avant de créer le miroir, toutes les lignes lettrées de T1 sont *auto-délettrées* pour éviter des lettres orphelines.

```
T1 — Recette chèque (avec paire 411 interne lettrée <A>)
  [411 DR 100  tiers=Dupont]  <A>
  [706 CR 100]
  [5112 DR 100]
  [411 CR 100  tiers=Dupont]  <A>

Extourne :
  1. autoDelettrerLignesDe(T1) → déléttre code <A> (audit action='delettre')
  2. creerTransactionMiroir(T1) → T2' = copie avec signes inversés, sans lettrage_code

T2' — Miroir (equilibree=true)
  [411 CR 100  tiers=Dupont]         ← sans lettrage_code
  [706 DR 100]
  [5112 CR 100]
  [411 DR 100  tiers=Dupont]
```

### 4.5 Facture validée puis encaissement

```
Facture F — statut=Validée
  → T1 créée par FactureService::valider via pourRecetteACredit(existingTransaction=T1)

T1 — Facture (recette à crédit, equilibree=true)
  [411 DR 500  tiers=Dupont]         ← créance ouverte
  [706 CR 300]                        ← ligne facture 1
  [708 CR 200]                        ← ligne facture 2
  statut_reglement = EnAttente

Encaissement — FactureService::marquerReglementRecu
  → T2 créée via pourEncaissementCreance

T2 — Encaissement (equilibree=true)
  [512X DR 500]                       ← argent reçu
  [411 CR 500  tiers=Dupont]  <B>   ← apure T1.411D

Auto-lettrage cross-transaction : T1.411D et T2.411C reçoivent code <B>
T1.statut_reglement = Recu
```

---

## 5. Conventions clés

### École 411 systématique

**Règle** : le tiers (client ou fournisseur) est **exclusivement** porté sur les lignes de classe 4 (411 Clients, 401 Fournisseurs). Les lignes de classe 5 (512X, 5112, 530) ne portent jamais de `tiers_id`.

Cette convention est conforme à la norme FEC (BOI-CF-IOR-60-40-20) qui réserve `CompAuxNum` (tiers comptable) à la classe 4. Elle est vérifiée par l'invariant `assertPasDeTiersSurClasse5` dans `EcritureGenerator`.

**Conséquence** : une recette comptant devient N+3 lignes (411D tiers + [7x C × N ventilations] + 5xx D + 411C tiers) avec auto-lettrage interne de la paire 411.

### Asymétrie chèque dépense

Pour une **recette** par chèque : ligne 5112D (chèque reçu en portefeuille → remise bancaire ultérieure).

Pour une **dépense** par chèque : ligne 512X D directement (chèque émis → débit immédiat). Le compte 5112 n'est **pas** utilisé pour les dépenses. Cette asymétrie est documentée dans la spec §4.3 et dans `CompteTresorerieResolver` (voir §6).

### Feature flag

```php
// config/compta.php
'use_partie_double' => env('COMPTA_USE_PARTIE_DOUBLE', false)
```

Utilisé par `CompteResultatBuilder` (Step 27) et `RapprochementBancaireService` (Step 29) pour basculer entre le path legacy et le path partie double. La double écriture (enrichissement des lignes) est **toujours active** depuis le branchement Step 21 — le flag contrôle uniquement la **lecture** dans les rapports et le rappro.

**Activation post-backfill** : une fois le backfill d'un exercice terminé avec succès, passer `COMPTA_USE_PARTIE_DOUBLE=true` active les rapports PD pour cet exercice.

### Mapping SousCategorie → Compte

```
sous_categories.code_cerfa  →  comptes.numero_pcg
```

Migration `2026_05_20_000001` pose ce mapping. Il n'y a pas de FK directe entre `sous_categories` et `comptes`. La résolution est faite à la volée par `CompteVentilationResolver::resoudre`.

---

## 6. Helpers et services

### `App\Services\Compta\EcritureGenerator`

Fichier central (~1 468 lignes). Génère les écritures comptables en double entrée pour chaque type de mouvement.

**Méthodes publiques** :

| Méthode | Flux | Transactions créées |
|---|---|---|
| `pourRecetteComptant(Transaction, Tiers, iterable $ventilations, Compte $compteTresorerie, ?Transaction $existing)` | Recette au comptant (chèque/virement/CB/espèces) | Enrichit T1 (N+3 lignes, auto-lettrage interne 411) |
| `pourRecetteACredit(Transaction, Tiers, iterable $ventilations, ?Transaction $existing)` | Recette à crédit (facture validée) | Enrichit T1 (411D + 7x C) |
| `pourEncaissementCreance(Transaction $t1Creance, Compte $compteTresorerie)` | Encaissement d'une créance | Crée T2 (512X D + 411C), auto-lettrage cross-tx |
| `pourDepenseComptant(Transaction, Tiers, iterable $ventilations, Compte $compteTresorerie, ?Transaction $existing)` | Dépense au comptant | Enrichit T1 (N+3 lignes, auto-lettrage interne 401) |
| `pourDepenseACredit(Transaction, Tiers, iterable $ventilations, ?Transaction $existing)` | Dépense à crédit | Enrichit T1 (401C + 6x D) |
| `pourReglementFournisseur(Transaction $t1Dette, Compte $compteTresorerie)` | Règlement fournisseur | Crée T2 (401D + 512X C), auto-lettrage cross-tx |
| `pourRemiseBancaire(RemiseBancaire, Collection $txSources, Compte $compte512X)` | Remise bancaire | Crée T4 (512X D + N × 5112C), auto-lettrage 5112 1↔1 |

**Paramètre `?Transaction $existingTransaction`** : si fourni, la méthode enrichit la transaction existante en place (skip `createTransactionHeader`). Utilisé par `TransactionService::create` (Step 21), `FactureService::valider` (Step 23) et `ReglementOperationService` (Step 26).

**Invariants publics** :
- `assertEquilibre(Transaction)` : vérifie `∑ debit = ∑ credit`
- `assertPasDeTiersSurClasse5(Collection $lignes)` : aucune ligne 5xx ne porte un `tiers_id`
- `assertTypeTransaction(Transaction, TypeTransaction $attendu)` : type correct
- `assertLignesPresentes(Collection, int $minimum)` : au moins N lignes

### `App\Services\Compta\CompteVentilationResolver`

Résout le `Compte` PCG depuis une `SousCategorie` (via `code_cerfa = numero_pcg`).

```php
CompteVentilationResolver::resoudre(
    ?int $sousCategorieId,
    int $classeAttendue,   // 6 pour dépense, 7 pour recette
    string $contextLog,
    array $contextLogData = []
): ?Compte
```

**4 gardes (retourne null + Log::warning)** :
1. `$sousCategorieId === null` (G1)
2. SousCategorie trouvée mais `code_cerfa` null (G2)
3. `Compte::ofNumero($code_cerfa)` introuvable pour ce tenant (G3)
4. Classe du compte ≠ `$classeAttendue` (G4)

### `App\Services\Compta\CompteTresorerieResolver`

Résout le compte de trésorerie (512X / 5112 / 530) depuis un `CompteBancaire` et un mode de paiement.

```php
CompteTresorerieResolver::resoudre(
    ?int $compteBancaireId,
    ModePaiement $mode,
    string $contextLog = 'CompteTresorerieResolver',
    Sens $sens = Sens::Recette,   // enum Sens { Recette, Depense } — Vague 3b
): ?Compte
```

**Logique** :
- `Sens::Recette` + chèque/espèces → retourne le placeholder 5112 (EcritureGenerator résout seul vers 5112 ou 530).
- `Sens::Recette` + virement/CB/prélèvement → cherche 512X par IBAN. `null` si introuvable.
- `Sens::Depense` + chèque/virement/CB/prélèvement → cherche 512X par IBAN. `null` si introuvable (asymétrie chèque dépense).

### `App\Services\Compta\LettrageService`

Gère le lettrage et le délettrage des lignes de transaction.

```php
// Lettrer un groupe de lignes (retourne le code lettrage posé)
$code = $lettrageService->lettrer(Collection $lignes, ?string $code = null, ?string $motif = null): string;

// Délettrer un groupe entier par code
$lettrageService->delettrer(string $code, ?string $motif = null): void;

// Délettrer le groupe portant le même code qu'une ligne donnée
$lettrageService->delettrerParLigne(TransactionLigne $ligne, ?string $motif = null): void;

// Auto-délettrer toutes les lignes lettrées d'une transaction (retourne nb de codes délettrés)
// Pattern rule-of-three — Vague 3b :
//   1. TransactionExtourneService::extourner (Step 31)
//   2. TransactionService::update (Vague 1)
//   3. TransactionConverter::convertir (Vague 1d)
$n = $lettrageService->autoDelettrerLignesDe(Transaction $tx, string $motif): int;
```

**Invariants vérifiés à chaque `lettrer()`** :
1. Minimum 2 lignes
2. Toutes les lignes appartiennent au même compte (même `compte_id`)
3. Toutes les lignes appartiennent au même tenant (même `association_id` via `TenantContext`)
4. Aucune ligne déjà lettrée (`lettrage_code IS NOT NULL`)
5. Au moins une ligne débit et une ligne crédit (équilibre du lettrage)

Chaque opération laisse une trace dans `lettrage_audit`.

### `App\Services\Compta\TransactionConverter`

Convertit une transaction legacy (sans `equilibree = true`) vers le modèle partie double. Idempotent : skip si déjà convertie (`equilibree = true`).

```php
$converter->convertir(Transaction $tx): ConversionResult;
// ConversionResult::CONVERGED   → transaction convertie avec succès
// ConversionResult::ALREADY_PD  → déjà equilibree=true (skip)
// ConversionResult::SKIPPED     → cas non supporté (montant négatif, mode sans 512X, etc.)
```

**Cas non backfillables** : transactions avec `montant_total < 0` (miroirs d'extourne) — `EcritureGenerator` lève `InvalidArgumentException`. Ces T2' sont exclues du backfill en v5.

### `App\Console\Commands\BackfillPartieDoubleCommand`

```
compta:backfill-partie-double
  --exercice=current|YYYY   Exercice à convertir (défaut : exercice courant, 1 sept → 31 août)
  --dry-run                 Audit seulement — aucune écriture en base
  --force                   Re-conversion totale même si equilibree=TRUE (interdit en prod)
  --asso=ID                 Limiter à une association (console interne seulement)
```

**Guard prod** : `--force` est refusé si `app()->environment('production')`.

**Rapport dry-run** : produit un tableau (converti / déjà PD / skippé / erreur) sans écriture.

**Idempotence** : sans `--force`, les transactions `equilibree = true` sont skippées.

---

## 7. Multi-tenant

Tous les modèles partie double étendent `App\Models\TenantModel` :

- `Compte::class` — scope global `WHERE association_id = TenantContext::currentId()`
- `TransactionLigne::class` — les colonnes PD enrichissent les lignes existantes (pas de scope propre, isolation via la transaction parente)
- `LettrageAudit::class` — scope global sur `association_id`

**Comportement fail-closed** : si `TenantContext` n'est pas booté, le scope global retourne `WHERE 1 = 0` (aucun enregistrement visible). Ce comportement protège contre les requêtes accidentelles hors contexte tenant.

**Helper de test** :

```php
// tests/Pest.php
function compteSysteme(string $numero): Compte
{
    return Compte::ofNumero($numero) ?? throw new \RuntimeException("Compte $numero introuvable");
}
```

**Trait Pest** `Tests\Support\CreatesPartieDoubleContext` : factorise le setup complet partie double (association, tenant context, seeder comptes système, tiers, compte bancaire 512X) utilisé par 5 fichiers de tests PD :
- `TransactionServicePartieDoubleTest`
- `FactureServicePartieDoubleTest`
- `FactureServicePartieDoubleEncaissementTest`
- `RemiseBancaireServicePartieDoubleTest`
- `ReglementOperationServicePartieDoubleTest`

**Convention messages log** : tous les services partie double préfixent leurs messages avec `[PartieDouble][ServiceName]`, par exemple :

```
[PartieDouble][TransactionService] Tiers introuvable pour tx #42 — skip PD
[PartieDouble][LettrageService] Auto-délettrage 3 codes suite à update de TX#17
```

---

## 8. Cutover et rollback

### Activer la partie double en production

1. Exécuter le backfill sur l'exercice cible (ou sur tous les exercices) :
   ```bash
   php artisan compta:backfill-partie-double --exercice=2025 --dry-run
   php artisan compta:backfill-partie-double --exercice=2025
   ```
2. Vérifier le rapport : `converti=N / déjà PD=0 / skippé=K / erreur=0`.
3. Activer le feature flag dans `.env` :
   ```
   COMPTA_USE_PARTIE_DOUBLE=true
   ```
4. Vider le cache de configuration :
   ```bash
   php artisan config:cache
   ```
5. Valider visuellement le Compte de résultat et le rapprochement bancaire.

### Rollback

Le feature flag `COMPTA_USE_PARTIE_DOUBLE=false` (default) remet les rapports et le rappro en mode legacy immédiatement. Les données PD restent en base mais ne sont pas lues.

Les colonnes legacy (`transactions.type`, `transaction_lignes.sous_categorie_id`, `transaction_lignes.montant`) sont **conservées** jusqu'à une PR dédiée post-stabilité prod (Step 40 différé).

### Colonnes et modèles legacy conservés

| Artéfact | Raison du maintien | Prochaine étape |
|---|---|---|
| `transaction_lignes.sous_categorie_id` | FK utilisée par les services legacy encore actifs | Drop Step 40, PR séparée |
| `transaction_lignes.montant` | Lu par les rapports en mode `use_partie_double=false` | Drop Step 40, PR séparée |
| `transactions.type` | Discriminant legacy (recette/dépense) | Drop Step 40, PR séparée |
| `transactions.compte_id` | FK vers `comptes_bancaires` (pas vers `comptes`) | Drop Step 40, PR séparée |
| `App\Models\SousCategorie` | FK `sous_categorie_id` dans 6 tables | Programme dédié post-cutover |

**Migration FK 6 tables** (`budget_lines`, `formules_adhesion`, `facture_lignes`, `note_de_frais_lignes`, `devis_lignes`, `usages_sous_categorie`) : toutes ont une FK `sous_categorie_id` vers `sous_categories`. La migration vers `compte_id` est un programme dédié post-cutover v5.0.

---

## 9. Tests d'équivalence garantis

### Compte de résultat — tolérance 0€

**Fichier** : `tests/Feature/CR/PartieDoubleEquivalenceTest.php` (Step 28)

Ce test crée une fixture exercice complet (3 recettes comptant, 1 créance + encaissement, 2 dépenses, 1 facture 2 lignes + encaissement, 1 remise bancaire 2 chèques, 1 séance via `ReglementOperationService`) et vérifie que les totaux du Compte de résultat en mode PD sont identiques aux totaux en mode legacy, à l'euro près.

8 scénarios (E1 à E7 + I2) couvrent : totaux par catégorie, totaux par sous-catégorie, filtrage opération, ventilation par séance, non-régression flag OFF, sanity montants > 0, absence lignes techniques (411/5112/512X) dans les produits/charges.

**Résultat** : 0 divergence détectée sur l'ensemble de la fixture exercice 2025.

### Rapprochement bancaire — tolérance 0€

**Fichier** : `tests/Feature/Rappro/PartieDoubleEquivalenceTest.php` (Step 30)

Vérifie que le solde calculé par `RapprochementBancaireService::calculerSoldePointage` en mode PD est identique au solde en mode legacy, sur la même fixture de transactions enrichies.

### Backfill end-to-end exercice complet

**Fichier** : `tests/Feature/Console/BackfillPartieDoubleEndToEndTest.php` (Step 35)

Crée un exercice complet en état legacy (toutes transactions `equilibree = false`), exécute `compta:backfill-partie-double`, vérifie :
- Toutes les transactions convertibles ont `equilibree = true`
- Les invariants PD sont respectés (∑DR = ∑CR par transaction)
- Les lettres cross-transaction (411, 5112) sont correctement posées
- Les cas non backfillables (miroirs d'extourne) sont skippés sans erreur

La fixture inclut un cas **HelloAsso CB** (portage direct 512X, pas 5112) et un cas **extourne** (T2' miroir exclu du backfill).

---

## Annexe — Structure des fichiers

```
app/
  Console/Commands/
    BackfillPartieDoubleCommand.php    -- commande artisan Step 32-34
  Models/
    Compte.php                         -- modèle PCG (TenantModel)
    TransactionLigne.php               -- enrichi avec compte_id/debit/credit/tiers_id/lettrage_code
  Observers/
    TransactionLigneObserver.php       -- invariant XOR débit/crédit
  Services/Compta/
    EcritureGenerator.php              -- 6 méthodes pour* (~1468 lignes)
    LettrageService.php                -- lettrer/delettrer/autoDelettrerLignesDe
    CompteVentilationResolver.php      -- SousCategorie → Compte (4 gardes)
    CompteTresorerieResolver.php       -- CompteBancaire → 512X/5112/530 (Sens enum)
    BackfillAuditor.php                -- audit dry-run
    TransactionConverter.php           -- conversion unitaire Tx legacy → PD
docs/
  compta-partie-double.md             -- ce fichier
  adr/
    003-passage-partie-double.md       -- ADR-003 révision stratégie comptable
tests/
  Feature/
    CR/PartieDoubleEquivalenceTest.php          -- Step 28
    Rappro/PartieDoubleEquivalenceTest.php      -- Step 30
    Console/
      BackfillPartieDoubleCommandTest.php       -- Steps 32-34
      BackfillPartieDoubleEndToEndTest.php      -- Step 35
    Services/
      TransactionServicePartieDoubleTest.php
      TransactionServiceUpdatePartieDoubleTest.php
      FactureServicePartieDoubleTest.php
      FactureServicePartieDoubleEncaissementTest.php
      RemiseBancaireServicePartieDoubleTest.php
      ReglementOperationServicePartieDoubleTest.php
      TransactionExtourneServicePartieDoubleTest.php
  Support/
    CreatesPartieDoubleContext.php      -- trait Pest setup PD (Vague 3b)
  Pest.php                             -- helper compteSysteme(string): Compte
```
