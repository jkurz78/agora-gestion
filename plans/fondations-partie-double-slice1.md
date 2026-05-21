# Plan: Fondations partie double — Slice 1

**Created**: 2026-05-20
**Spec**: `docs/specs/2026-05-19-fondations-partie-double-slice1.md` (3 commits, 938 lignes)
**Branch**: `feat/compta-v5` (à créer en Step 1)
**Status**: in-progress (sous-slice 1a, 10/11 steps done — 2026-05-21)
**Découpage build** : 4 sous-slices avec `/clear` intermédiaires (voir « Découpage en sous-slices »)

## Goal

Refonder le moteur de comptabilisation d'AgoraGestion sur un modèle uniforme partie double (écritures débit/crédit équilibrées) sans aucun changement fonctionnel visible pour l'utilisateur final. Le slice 1 livre les **fondations** : table `comptes` unifiée, lignes débit/crédit sur `transaction_lignes`, lettrage automatique, compte de résultat et rapprochement bancaire rebranchés sur le nouveau modèle, backfill idempotent de l'exercice courant. Le bilan, la TVA, les immobilisations et les OD libres sont **hors scope** (slices ultérieurs).

Cette refonte structurelle conditionne tout le programme « comptabilité élargie » : une fois ces fondations posées, les slices 2+ ajoutent uniquement des **vues** sur des données déjà partie double, sans réécriture du modèle.

## Acceptance Criteria

Issus de la spec §10. Référence vers la spec pour le détail.

### Fonctionnel (non-régression utilisateur)

- [ ] **CR identique** : compte de résultat affiché pour l'exercice courant produit les mêmes lignes et totaux que pré-slice 1 (tolérance 0,00€)
- [ ] **Rappro identique** : lignes pointables, libellés, montants, statuts pointés inchangés
- [ ] **Saisie recette / dépense** : écrans actuels fonctionnent sans changement perceptible
- [ ] **Saisie facture Factur-X** : `FactureService::valider()` génère désormais via `EcritureGenerator::pourRecetteACredit()`
- [ ] **Encaissement créance** : auto-lettrage de la paire 411
- [ ] **Remise bancaire** : T4 splittée par tiers (Variante 2a) + lettrage auto
- [ ] **Extourne d'une transaction lettrée** : auto-délettrage des lignes d'origine
- [ ] **Provisions v2.10.0** : continue de fonctionner inchangé
- [ ] **Fiche tiers 360** : solde ouvert visible, timeline inchangée

### Technique

- [ ] **Suite Pest verte** : 0 failures, 0 errors (hors skipped pré-existants)
- [ ] **Pint vert** : sans correction
- [ ] **Tenant isolation** : 12 tests d'intrusion S6 restent verts
- [ ] **Performance** : CR < 500ms, rappro mensuel < 200ms sur exercice complet
- [ ] **Backfill** : commande termine avec succès, idempotente, `--force` refusé en prod

### Sémantique partie double

- [ ] **Invariant équilibre** : `∑ debit = ∑ credit` sur toutes les transactions de l'exercice — *vérifiable à partir de fin sous-slice 1b (EcritureGenerator), validé définitivement en sous-slice 1d (post-backfill).*
- [ ] **Invariant lettrage** : `∑ (debit - credit) = 0` pour chaque `lettrage_code` — *idem, vérifiable dès 1b.*
- [ ] **Invariant tenant** : aucune ligne ne pointe vers un compte d'un autre tenant — *posé structurellement en 1a, exerçable dès 1b.*
- [ ] **Invariant tiers obligatoire 411/401** : toute ligne sur 411 ou 401 porte un `tiers_id` — *exerçable dès Step 14 (EcritureGenerator invariants).*
- [ ] **Invariant pas de tiers sur 512X** : aucune ligne 512 ne porte de tiers — *idem.*

### Audit

- [ ] **Lettrage audit complet** : chaque lettrer/délettrer génère une ligne `lettrage_audit`

### Gouvernance

- [ ] **Branche `feat/compta-v5`** vit en parallèle de `main`, mergée uniquement au cutover final — *vérifié manuellement par le développeur avant chaque push, pas par CI.*
- [ ] **Scripts ops** : squelettes posés en Step 1 (sous-slice 1a), scripts fonctionnels testables en dry-run en Step 43 (sous-slice 1d).
- [ ] **Recette préprod manuelle** : 12 vérifications PO §16.6 passées vertes — *déféré sous-slice 1d uniquement.*

---

## Découpage en sous-slices (pour `/build`)

44 steps regroupés en **4 sous-slices** auto-testables, avec point de `/clear` orchestrateur recommandé entre chacun. **Aucun cutover prod intermédiaire** : chaque sous-slice se termine par un merge dans `feat/compta-v5` (pas dans `main`). Cutover prod unique en fin de Sous-slice 4.

| Sous-slice | Steps | Phases | Critère de complétude | Durée estimée |
|---|---|---|---|---|
| **1a — Data layer** | 1-11 | A + B + C | Toutes migrations passées, modèles `Compte` / `TransactionLigne` enrichi en place, suite Pest verte | ~3-4 jours |
| **1b — Services partie double** | 12-20 | D | `LettrageService` + `EcritureGenerator` complets, matrice École C testée unitairement, suite Pest verte | ~5-7 jours |
| **1c — Branchements + rapports** | 21-31 | E + F + G | Tous écrans de saisie + rapports rebranchés sur le nouveau moteur, tests non-régression CR + rappro verts, suite Pest verte | ~5-7 jours |
| **1d — Backfill + renommage + ops** | 32-44 | H + I + (J) + K | Backfill idempotent fonctionnel, codebase renommé, scripts ops finalisés, recette préprod jouée — **prêt cutover prod** | ~5-7 jours |

**Total estimé build** : 18-25 jours soit ~4 semaines, conformément à la spec §16.9.

### Workflow `/clear` recommandé

À la fin de chaque sous-slice :
1. Suite Pest verte sur la branche
2. `/agentic-dev-team:code-review --changed` passé
3. Mise à jour `MEMORY.md` (ajout d'une entrée `project_compta_v5_sous_slice_Xx.md` avec récap + commits)
4. Push branche `feat/compta-v5` (pas de merge main !)
5. `/clear` pour nettoyer le contexte orchestrateur
6. `/agentic-dev-team:continue` au début de la session suivante (lit l'entrée mémoire et le plan)

### Points de validation PO entre sous-slices

- **Fin 1a** : valider que le schéma cible est conforme à ses attentes (table `comptes`, colonnes lignes, plan de comptes seedé). Pas de fonctionnalité visible — validation technique uniquement.
- **Fin 1b** : valider en console (tinker) que `EcritureGenerator::pourRecetteComptant(...)` produit les écritures attendues. Pas encore branché à l'UI.
- **Fin 1c** : démo locale (sans backfill) sur des données fraîches : saisie d'une recette, d'une remise, vérification CR / rappro. Pas encore les données prod.
- **Fin 1d** : **recette préprod complète** sur clone prod non-anonymisé, check-list §16.6 de la spec. **Décision cutover prod** prise ici.

---

## Steps

### Phase A — Préparation (steps 1-2) — Sous-slice 1a

#### Step 1 : Création de la branche `feat/compta-v5` et scripts ops squelette ✅

**Complexity**: trivial
**Status**: ✅ done — commit `037bc22f` (2026-05-20)
**RED**: N/A — pas de test pour la création de branche
**GREEN**:
- `git checkout main && git pull && git checkout -b feat/compta-v5`
- Créer squelettes commentés : `scripts/clone-prod-to-preprod.sh`, `scripts/deploy-preprod-v5.sh`, `scripts/v5-sync-from-main.sh` (gitignored si patterns d'ops existants — sinon dans `scripts/`)
- Créer fichier `.env.preprod.example` documentant les variables de bridage (MAIL_MAILER=log, HELLOASSO_WEBHOOK_DISABLED=true, INCOMING_MAIL_DISABLED=true, APP_ENV=preprod)
- Commit initial avec branche tracking
**REFACTOR**: None needed
**Files**: `scripts/*.sh`, `.env.preprod.example`
**Commit**: `chore(v5): bootstrap feat/compta-v5 branch + ops scripts skeleton`

#### Step 2 : Audit pré-backfill — commande artisan `audit:compta-v5-preparation` ✅

**Complexity**: standard
**Status**: ✅ done — commit `3281d432` (2026-05-20). 6 tests Pest verts (33 assertions), Pint vert. 5 sections d'audit, JSON output dans `storage/audits/`.
**RED**: Tests Pest :
- Sous-catégorie sans `code_cerfa` → apparaît dans le rapport
- Sous-catégorie avec `code_cerfa` valide → ne pose pas problème
- Transaction sans tiers → comptabilisée dans le rapport
- Mode de paiement non couvert par la matrice (rare) → flag dans rapport
**GREEN**:
- `App\Console\Commands\AuditComptaV5PreparationCommand`
- Sortie : table console + fichier JSON `storage/audits/compta-v5-YYYY-MM-DD.json`
- Sections : (1) sous-catégories sans code_cerfa, (2) modes de paiement non standard, (3) transactions sans tiers, (4) cas extournes anciennes, (5) HelloAsso payloads inhabituels
- Pas de modification de la base
**REFACTOR**: Extraire chaque section en méthode privée pour lisibilité
**Files**: `app/Console/Commands/AuditComptaV5PreparationCommand.php`, `tests/Feature/Commands/AuditComptaV5PreparationTest.php`
**Commit**: `feat(v5): audit:compta-v5-preparation command for backfill pre-flight checks`

---

### Phase B — Schéma (steps 3-8) — Sous-slice 1a

#### Step 3 : Migration `comptes` — création table + seed depuis `sous_categories` ✅

**Complexity**: complex
**Status**: ✅ done — commit `b9c1d28b` (2026-05-20). 8 tests Pest verts (42 assertions), Pint vert, suite complète 3453 tests verts. Décision notable : garde-fou extrait en service `App\Services\Compta\Migrations\AuditGuard` (testable hors cycle migrate). `pour_inscriptions` dérivé du pivot `usages_sous_categories` (colonne booléenne droppée v4.1.2).
**RED**: Tests Pest migration :
- Table `comptes` existe avec toutes les colonnes attendues
- Chaque `sous_categorie` existante a un `compte` correspondant avec `numero_pcg = code_cerfa`, `intitule = nom`, `classe` dérivée, `categorie_id` copié
- `pour_inscriptions` flag conservé
- Unicité `(association_id, numero_pcg)` respectée
- Tenant scope : aucun compte ne fuit entre asso
- **Garde-fou** : si une sous-catégorie sans `code_cerfa` existe au moment de la migration → throw `RuntimeException` explicite (« Run `php artisan audit:compta-v5-preparation` first and fix sous-catégories without code_cerfa before migrating »)
- Test Pest dédié : seed une sous-catégorie sans code_cerfa, attend `RuntimeException` au `migrate`
**GREEN**:
- Migration `2026_05_20_000001_create_comptes_table.php` (schéma spec §2.1)
- Pré-check dans `up()` : `if (SousCategorie::whereNull('code_cerfa')->exists()) throw ...`
- Backfill SQL inline : `INSERT INTO comptes SELECT FROM sous_categories` pour chaque tenant
**REFACTOR**: None needed
**Files**: `database/migrations/2026_05_20_000001_create_comptes_table.php`, `tests/Feature/Migrations/CreateComptesTableTest.php`
**Commit**: `feat(v5): create comptes table + seed depuis sous_categories`

#### Step 4 : Seed `comptes` depuis `comptes_bancaires` (sous-comptes 5121, 5122…) ✅

**Complexity**: standard
**Status**: ✅ done — commits `a3627a3a` + `eb581e1a` + `3352c710` (2026-05-21). 11 tests Pest verts (38 assertions), Pint vert, suite complète 10746 assertions / 0 failed. Décisions notables : seed extrait en service `App\Services\Compta\Migrations\BancairesSeeder` (mirror du pattern `AuditGuard`), numérotation par `ROW_NUMBER() OVER (PARTITION BY association_id ORDER BY id)` avec branching MySQL/SQLite (`CONCAT` vs `||`), `down()` filtre `LIKE '512_%'` (un char min après 512) pour exclure le futur 5112 système et supporter les assos avec 10+ banques (`51210`+), idempotence via `INSERT IGNORE` / `INSERT OR IGNORE` sur l'unique `(association_id, numero_pcg)`. `comptes_bancaires` n'a pas de `deleted_at` (model sans SoftDeletes) — comportement documenté inline.
**RED**: Tests Pest :
- Chaque `compte_bancaire` actif crée un compte `5121`, `5122`… par incrément
- Attributs bancaires copiés (IBAN, BIC, domiciliation, solde_initial, date_solde_initial)
- `est_systeme = TRUE`, `lettrable = FALSE`
- Numérotation cohérente entre tenants (chaque tenant repart à 5121)
**GREEN**:
- Migration `2026_05_20_000002_seed_comptes_bancaires_into_comptes.php`
- Logique d'incrément par tenant
**REFACTOR**: None needed
**Files**: `database/migrations/2026_05_20_000002_seed_comptes_bancaires_into_comptes.php`, tests Feature dédiés
**Commit**: `feat(v5): seed comptes bancaires comme sous-comptes 5121, 5122…`

#### Step 5 : Seed comptes système (411, 401, 5112, 530 conditionnel) ✅

**Complexity**: standard
**Status**: ✅ done — commits `409c61f4` + `2f0e177c` (2026-05-21). 19 tests Pest verts (82 assertions ; 8 migration + 11 policy), Pint vert, suite complète **10 829 assertions / 0 failed**. Décisions notables : seed extrait en service `App\Services\Compta\Migrations\SystemeSeeder` (mirror du pattern `AuditGuard` / `BancairesSeeder`), split `unconditionalSql()` (411/401/5112) + `conditionalCaisseSql()` (530) avec SQL EXISTS verbatim plan. Modèle minimal `App\Models\Compte` (TenantModel + SoftDeletes + fillable + casts, enrichi en Step 9 — `// TODO step 9` marker). `ComptePolicy` (update + delete refusent `est_systeme=true`, sinon délègue à `RoleAssociation::canWrite(Espace::Compta)`), enregistrée dans `AppServiceProvider`. **Bug pré-existant Step 3 surfacé et corrigé** : FK `comptes.association_id` sans cascade (default RESTRICT) cassait 141 tests dès que chaque association recevait un seed système — fix `->cascadeOnDelete()` aligné sur la convention projet (10+ tables tenant-scopées). Tests Step 4 refinés (`classe = 5` → `numero_pcg LIKE '512_%'`) pour ne pas compter le nouveau 5112 système.
**RED**: Tests Pest :
- Compte 411, 401, 5112 créés pour chaque tenant (toujours)
- **Critère 530 — décision actée** : `EXISTS (SELECT 1 FROM transactions WHERE association_id = :id AND mode_paiement = 'especes' AND deleted_at IS NULL)`. Tenant avec transactions espèces non-supprimées → compte 530 créé. Tenant sans → compte 530 absent. Tenant avec uniquement des transactions espèces soft-deleted → compte 530 absent.
- `est_systeme = TRUE`, `lettrable = TRUE`, `categorie_id = NULL` pour tous les comptes système
- Tentative de delete/edit refusée par policy
**GREEN**:
- Migration `2026_05_20_000003_seed_comptes_systeme.php`
- Détection conditionnelle 530 avec SQL exact ci-dessus
- Policy `ComptePolicy::update / delete` refuse `est_systeme = TRUE`
**REFACTOR**: None needed
**Files**: Migration + `app/Policies/ComptePolicy.php` + tests
**Commit**: `feat(v5): seed comptes système 411/401/5112 + 530 conditionnel + policy garde-fou`

#### Step 6 : Migration `transaction_lignes` — colonnes débit/crédit + lettrage + tiers ✅

**Complexity**: complex
**Status**: ✅ done — commit `789241bf` (2026-05-21). 12 tests Pest verts (49 assertions), Pint vert, suite complète **10 876 assertions / 0 failed**. 6 colonnes ajoutées avec types/defaults exact spec §2.2 (`compte_id` / `tiers_id` BIGINT nullable FK `nullOnDelete()`, `debit` / `credit` DECIMAL(12,2) DEFAULT 0, `lettrage_code` VARCHAR(20), `libelle` VARCHAR(255)), **3 indexes** posés `(compte_id, tiers_id, lettrage_code)` + `(lettrage_code)` + `(compte_id, tiers_id)`, `sous_categorie_id` et `montant` conservés intacts, `down()` testé (drop indexes → FKs → columns).
**RED**: Tests Pest :
- Colonnes `compte_id`, `debit`, `credit`, `tiers_id`, `lettrage_code`, `libelle` existent avec types attendus
- Index `(compte_id, tiers_id, lettrage_code)`, `(lettrage_code)` et `(compte_id, tiers_id)` posés (3 indexes per spec §2.2)
- `sous_categorie_id` et `montant` conservés (nullables)
- Aucune ligne existante n'est cassée par la migration
**GREEN**:
- Migration `2026_05_20_000004_add_partie_double_columns_to_transaction_lignes.php` (schéma spec §2.2)
- Aucun backfill ici — c'est Step 32 qui le fera
**REFACTOR**: None needed
**Files**: Migration + tests Feature
**Commit**: `feat(v5): add debit/credit/tiers_id/lettrage_code columns to transaction_lignes`

#### Step 7 : Migration `transactions` — flags `equilibree` + `type_ecriture` ✅

**Complexity**: standard
**Status**: ✅ done — commit `36f3a10f` (2026-05-21). 13 tests Pest verts (25 assertions), Pint vert, suite complète **10 900 assertions / 0 failed**. `equilibree` BOOLEAN default FALSE + `type_ecriture` ENUM('normale','an','od','extourne') default 'normale' ajoutés en `AFTER montant_total` / `AFTER equilibree`. Legacy `type`, `compte_id`, `tiers_id`, `remise_id` conservés intacts. `down()` testé.
**RED**: Tests Pest :
- Colonnes `equilibree` (default FALSE) + `type_ecriture` (enum) existent
- Anciennes colonnes `type`, `compte_id` conservées
**GREEN**:
- Migration `2026_05_20_000005_add_equilibree_and_type_ecriture_to_transactions.php` (schéma spec §2.3)
**REFACTOR**: None needed
**Files**: Migration + tests
**Commit**: `feat(v5): add equilibree flag + type_ecriture enum to transactions`

#### Step 8 : Migration `lettrage_audit` ✅

**Complexity**: standard
**Status**: ✅ done — commit `ece08013` (2026-05-21). 14 tests Pest verts (24 assertions), Pint vert, suite complète **10 925 assertions / 0 failed**. Table append-only conforme spec §2.5 : 9 colonnes + 2 indexes composites, pas d'`updated_at` ni `deleted_at`, `action` ENUM('lettre','delettre'), `transaction_ligne_ids` JSON, FK cascade asso + compte, FK nullOnDelete user (RGPD-ready), tenant scope vérifié au SQL.
**RED**: Tests Pest :
- Table existe avec colonnes attendues
- Indexes posés
- Tenant scope respecté
**GREEN**:
- Migration `2026_05_20_000006_create_lettrage_audit_table.php` (schéma spec §2.5)
**REFACTOR**: None needed
**Files**: Migration + tests
**Commit**: `feat(v5): create lettrage_audit table (append-only audit log)`

---

### Phase C — Modèle Eloquent (steps 9-11) — Sous-slice 1a *(fin → `/clear`)*

#### Step 9 : Modèle `App\Models\Compte` + relations + scopes ✅

**Complexity**: standard
**Status**: ✅ done — commits `ee77bd1c` + `7b3dd671` (2026-05-21). 15 tests Pest verts (32 assertions), Pint vert, suite complète **10 959 assertions / 0 failed**. Modèle Step 5 enrichi : 2 statics (`ofNumero` retourne `?self`, `ofNumeroSysteme` throws `ModelNotFoundException`), 3 scopes (`lettrables`, `classe(int)`, `bancaires`), 1 relation `lignes(): HasMany`. **Décision actée** : `bancaires()` utilise `LIKE '512_%'` (harmonisé avec Step 4 `down()`) — supporte 10+ banques, exclut toujours 5112 et 530. 4 fixes post-review : commentaire helper, test négatif `ofNumeroSysteme`, `count == 3` exact, cast `(int)` FK.
**RED**: Tests Pest :
- `Compte::ofNumero('706')` retourne le compte 706 du tenant courant
- `Compte::ofNumeroSysteme('411')` retourne le 411 système
- Scope `lettrables()` filtre `lettrable = TRUE`
- Scope `classe(int $classe)` filtre par classe
- **Scope `bancaires()`** : retourne les comptes 5121, 5122… (banques physiques uniquement). Assertions positives ET négatives : 5121 inclus, **5112 et 530 EXCLUS**. Filtre exact : `classe = 5 AND numero_pcg LIKE '512_%'` (un char + reste optionnel après 512, supporte 10+ banques tout en excluant 5112).
- Relation `Compte::lignes()` retourne les `TransactionLigne` du compte
- Étend `TenantModel`, scope global respecté
**GREEN**:
- `App\Models\Compte` extends `TenantModel`
- Méthodes statiques + scopes + relation
- Scope `bancaires()` utilise `LIKE '512_%'` (consistant avec Step 4 down() + 10+ banques)
- Casts (`classe`, `actif`, `est_systeme`, `lettrable`)
**REFACTOR**: None needed
**Files**: `app/Models/Compte.php`, `tests/Feature/Models/CompteTest.php`
**Commit**: `feat(v5): App\Models\Compte avec scopes ofNumero / lettrables / classe / bancaires`

#### Step 10 : Modèle `TransactionLigne` enrichi (debit, credit, lettrage) ✅

**Complexity**: standard
**Status**: ✅ done — commit `cca10ca6` (2026-05-21). 14 tests Pest verts (22 assertions), Pint vert, suite complète **10 980 assertions / 0 failed** (vérifiée directement par l'orchestrateur). Modèle enrichi : 6 fillable + 4 casts (compte_id/tiers_id int, debit/credit decimal:2), `isLettree()`, accessor `montantSigne` (Attribute style), relations `compte()` + `tiers()` BelongsTo. Observer `TransactionLigneObserver::saving` avec **discriminator `compte_id === null` skip** (clé du design — laisse passer les lignes legacy slice-0 inchangées pendant que Steps 21-26 rebranchent les services). Cas couverts : XOR violation (deux > 0), ni-ni (deux = 0), legacy row (compte_id null) succès, raw `DB::table` bypass de l'observer. Spec compliance et code quality APPROVED 0 issues.
**RED**: Tests Pest :
- `TransactionLigne::isLettree()` retourne true ssi `lettrage_code IS NOT NULL`
- Accesseur `montantSigne` retourne `debit - credit`
- **Validation via `saving` observer** : refuse à la sauvegarde si `debit > 0 AND credit > 0` (XOR violation) → throw `InvalidArgumentException`
- **Cas (debit=0, credit=0)** : également refusé à `saving` (ligne vide non-significative). Toute ligne persistée doit avoir soit `debit > 0` soit `credit > 0`, jamais les deux ni aucun
- Relation `compte()` retourne le Compte
- Relation `transaction()` inchangée
- **Exception** : pendant la migration Step 6 et avant le backfill, les lignes héritées ont `debit = 0` ET `credit = 0`. L'observer ne se déclenche **que sur `creating` et `updating` explicites**, pas sur les writes batch SQL des migrations. Documenté dans l'observer.
**GREEN**:
- Enrichir le modèle existant
- Observer `TransactionLigneObserver::saving` avec validation XOR + ni-ni
- Accesseur `montantSigne`
- Observer enregistré dans `AppServiceProvider`
**REFACTOR**: None needed
**Files**: `app/Models/TransactionLigne.php`, `app/Observers/TransactionLigneObserver.php`, tests
**Commit**: `feat(v5): TransactionLigne enrichi (debit/credit + observer XOR + isLettree)`

#### Step 11 : Cohabitation `SousCategorie` ↔ `Compte` (pas d'alias en 1a, déféré 1d)

**Complexity**: trivial
**Décision révisée post-AC review** : transformer `SousCategorie` en alias deprecated dans 1a casserait silencieusement les ~10 relations existantes (`Adhesion::sousCategorie`, `FormuleAdhesion::sousCategorie`, `BudgetLine::sousCategorie`, `UsageSousCategorie::sousCategorie`, etc.). On garde `SousCategorie` **inchangé** en 1a (pointe toujours sur `sous_categories`). Le renommage transverse `SousCategorie → Compte` est fait en bloc en 1d (Steps 36-39) avec une migration de relations propre.

**RED**: Tests Pest :
- `Compte::find(X)` et `SousCategorie::find(X)` retournent des objets **distincts** (deux tables, deux IDs potentiellement différents même si valeurs identiques)
- Les deux modèles cohabitent sans collision Eloquent
- Aucun test existant ne casse à cause du nouveau modèle `Compte` ajouté
**GREEN**:
- Aucune modification de `App\Models\SousCategorie` en 1a
- `App\Models\Compte` (créé Step 9) coexiste sur la table `comptes`
- Pas d'alias, pas de log deprecated
**REFACTOR**: None needed
**Files**: aucun nouveau fichier (juste un test de cohabitation)
**Commit**: `test(v5): valider la cohabitation Compte / SousCategorie en 1a`

---

### Phase D — Services (steps 12-20) — Sous-slice 1b *(fin → `/clear`)*

#### Step 12 : `LettrageService::lettrer` — invariants + audit

**Complexity**: complex
**RED**: Tests Pest exhaustifs sur invariants :
- Lettrage de 2 lignes équilibrées sur compte lettrable → OK + lettrage_code généré + audit créé
- Compte non lettrable → throw `CompteNonLettrableException`
- Lignes sur comptes différents → throw `LettrageMultiComptesException`
- Somme ≠ 0 → throw `LettrageNonEquilibreException`
- Une ligne déjà lettrée → throw `LettrageDejaPresentException`
- Tenant boundary → throw `TenantBoundaryException`
- Code fourni en argument respecté
- Audit ligne contient transaction_ligne_ids, user_id, motif
**GREEN**:
- `App\Services\Compta\LettrageService::lettrer(Collection $lignes, ?string $code, ?string $motif): string`
- Génération UUID-short 20 chars si code null
- Validation invariants
- Write `lettrage_audit` puis update `transaction_lignes.lettrage_code` en `DB::transaction()`
**REFACTOR**: Extraire les validators en méthodes privées (`assertSameCompte`, `assertEquilibre`, etc.)
**Files**: `app/Services/Compta/LettrageService.php`, `app/Exceptions/Compta/*.php`, tests
**Commit**: `feat(v5): LettrageService::lettrer + 5 invariants + audit`

#### Step 13 : `LettrageService::delettrer` + `delettrerParLigne`

**Complexity**: standard
**RED**: Tests Pest :
- `delettrer($code)` passe `lettrage_code = NULL` sur toutes les lignes du code
- Audit ligne action='delettre' créée
- Code inexistant → throw `LettrageInexistantException`
- `delettrerParLigne($ligne)` résout le code de la ligne et délettre tout le groupe
- Ligne sans lettrage_code → throw `LigneNonLettreeException`
**GREEN**:
- Méthodes `delettrer` et `delettrerParLigne`
- Audit write
**REFACTOR**: None needed
**Files**: `app/Services/Compta/LettrageService.php` (étendu), tests
**Commit**: `feat(v5): LettrageService::delettrer + delettrerParLigne + audit`

#### Step 14 : `EcritureGenerator` squelette + invariants

**Complexity**: complex
**RED**: Tests Pest sur le squelette :
- `EcritureGenerator` est résoluble via container
- Méthode `assertEquilibre` valide une collection de lignes
- Méthode `assertTiersObligatoire411` pour les lignes 411/401
- Méthode `assertPasDeTiersSur512` pour les lignes 512X
- Méthode `assertTenantCoherence` pour comptes/tiers vs `TenantContext::currentId()`
- Génération `lettrage_code` cohérente (format UUID-short)
**GREEN**:
- Classe `App\Services\Compta\EcritureGenerator` + injection `LettrageService`
- Méthodes privées d'invariants
- Exceptions dédiées dans `App\Exceptions\Compta\`
**REFACTOR**: None needed
**Files**: `app/Services/Compta/EcritureGenerator.php`, exceptions, tests
**Commit**: `feat(v5): EcritureGenerator skeleton + invariants partagés`

#### Step 15 : `EcritureGenerator::pourRecetteComptant` (tous modes)

**Complexity**: complex
**RED**: Tests Pest matrice §4.3 lignes 1-4 :
- Recette comptant chèque → T1 `5112 D X (tiers) / 706 C X`
- Recette comptant espèces → T1 `530 D X (tiers) / 706 C X`
- Recette virement → T1 `512 D X (tiers) / 706 C X`
- Recette CB HelloAsso → T1 `512 D X (tiers) / 706 C X`
- T1 équilibrée
- `tiers_id` porté sur la ligne du compte de portage/trésorerie, pas sur 706
- `transaction.type_ecriture = 'normale'`, `equilibree = TRUE`
- Tenant boundary respecté
**GREEN**:
- Méthode `pourRecetteComptant(Tiers, Compte $compteProduit, float, ModePaiement, ...): Transaction`
- Switch sur `ModePaiement` pour résoudre le compte de portage
**REFACTOR**: Extraire `resoudreComptePortage(ModePaiement, ?Compte $compteTresorerieExplicite): Compte`
**Files**: `app/Services/Compta/EcritureGenerator.php` (étendu), tests
**Commit**: `feat(v5): EcritureGenerator::pourRecetteComptant (chèque/espèces/virement/CB)`

#### Step 16 : `EcritureGenerator::pourRecetteACredit`

**Complexity**: standard
**RED**: Tests Pest :
- Recette à crédit → T1 `411 D X (tiers) / 706 C X`
- Pas de portage (pas de transaction T2 ici)
- Solde ouvert 411 du tiers = X après création
- Tiers obligatoire (throw si null)
**GREEN**:
- Méthode `pourRecetteACredit(Tiers, Compte, float, DateTime, ...): Transaction`
**REFACTOR**: None needed
**Files**: `app/Services/Compta/EcritureGenerator.php`, tests
**Commit**: `feat(v5): EcritureGenerator::pourRecetteACredit (411/706)`

#### Step 17 : `EcritureGenerator::pourEncaissementCreance` + auto-lettrage 411

**Complexity**: complex
**RED**: Tests Pest :
- Encaissement d'une créance 411 existante → T2 `5112 ou 530 ou 512 D X (tiers) / 411 C X (tiers)`
- Auto-lettrage : la ligne 411 de T1 et de T2 partagent un nouveau `lettrage_code`
- Solde ouvert 411 du tiers = 0 après encaissement
- Audit lettrage créé
- Refus si la ligne 411 source est déjà lettrée → `LigneDejaLettreException`
- Refus si la créance source n'appartient pas au tiers → throw
**GREEN**:
- Méthode `pourEncaissementCreance(Transaction $tCreance, ModePaiement, ...): Transaction`
- Résolution de la ligne 411 source via `$tCreance->lignes()->where('compte_id', $compte411->id)->first()`
- Appel `LettrageService::lettrer` sur les 2 lignes 411
**REFACTOR**: None needed
**Files**: `app/Services/Compta/EcritureGenerator.php`, tests
**Commit**: `feat(v5): EcritureGenerator::pourEncaissementCreance + auto-lettrage 411`

#### Step 18 : `EcritureGenerator::pourDepense*` (3 cas)

**Complexity**: standard
**RED**: Tests Pest matrice §4.3 :
- Dépense comptant chèque émis → `607 D X / 512 C X (tiers)` (pas de 5112 miroir, décision actée)
- Dépense comptant CB → `607 D X / 512 C X (tiers)`
- Dépense comptant espèces → `607 D X / 530 C X (tiers)`
- Tiers porté côté trésorerie
**GREEN**:
- Méthode `pourDepenseComptant(Tiers, Compte $compteCharge, float, ModePaiement, ...)`
**REFACTOR**: None needed
**Files**: tests
**Commit**: `feat(v5): EcritureGenerator::pourDepenseComptant (3 modes)`

#### Step 19 : `EcritureGenerator::pourDepenseACredit` + `pourReglementFournisseur` + auto-lettrage 401

**Complexity**: standard
**RED**: Tests Pest :
- Dépense à crédit → `607 D X / 401 C X (tiers)`
- Règlement fournisseur → `401 D X (tiers) / 512 C X` + auto-lettrage paire 401
- Solde ouvert 401 du tiers = 0 après règlement
**GREEN**:
- Méthodes `pourDepenseACredit` et `pourReglementFournisseur`
**REFACTOR**: Factoriser les 2 méthodes encaissement créance / règlement fournisseur si pattern identique
**Files**: tests
**Commit**: `feat(v5): EcritureGenerator::pourDepenseACredit + pourReglementFournisseur + auto-lettrage 401`

#### Step 20 : `EcritureGenerator::pourRemiseBancaire` (Variante 2a splittée par tiers)

**Complexity**: complex
**RED**: Tests Pest cas remise §11 scénario 2 :
- Remise de 3 chèques (Pierre 50, Paul 30, Jeanne 20) sur 512BNP
- Transaction T4 créée avec **4 lignes** :
  - `512BNP D 100` (sans tiers, sans lettrage)
  - `5112 C 50 (Pierre)` avec `lettrage_code = AAA`
  - `5112 C 30 (Paul)` avec `lettrage_code = AAB`
  - `5112 C 20 (Jeanne)` avec `lettrage_code = AAC`
- Les 3 lignes 5112 entrantes (T1, T2, T3) reçoivent leurs codes AAA, AAB, AAC respectivement
- Solde ouvert 5112 par tiers = 0
- Plusieurs chèques du même tiers groupés sous un même code
- Audit : 3 lignes `action='lettre'` créées
**GREEN**:
- Méthode `pourRemiseBancaire(RemiseBancaire, Collection $lignes5112Sources)`
- Group by tiers, calcul total, génération T4, lettrage par paire (ou par groupe-tiers)
**REFACTOR**: None needed
**Files**: tests Feature + Unit
**Commit**: `feat(v5): EcritureGenerator::pourRemiseBancaire (Variante 2a, splittée par tiers + auto-lettrage)`

---

### Phase E — Branchements UI (steps 21-26) — Sous-slice 1c

#### Step 21 : Livewire Recette branché sur `EcritureGenerator`

**Complexity**: complex
**RED**: Tests Pest Livewire :
- Saisie recette chèque via composant → écritures partie double créées
- Saisie recette à crédit → ligne 411 créée + créance visible fiche tiers
- Suite tests existants `RecetteForm*` reste verte
**GREEN**:
- `App\Livewire\Recettes\RecetteForm` (ou équivalent) appelle `EcritureGenerator::pourRecetteComptant` ou `pourRecetteACredit` selon le mode
- Conservation totale de l'UI existante
**REFACTOR**: Extraire la logique de submit en `RecetteFormHandler` testable unitairement
**Files**: composants Livewire concernés, tests
**Commit**: `feat(v5): branche saisie recette Livewire sur EcritureGenerator`

#### Step 22 : Livewire Dépense branché

**Complexity**: standard
**RED**: Symétrique au step 21 pour les dépenses
**GREEN**: Appel `pourDepenseComptant` ou `pourDepenseACredit`
**REFACTOR**: Cohérence avec Step 21
**Files**: composants Livewire Dépenses
**Commit**: `feat(v5): branche saisie dépense Livewire sur EcritureGenerator`

#### Step 23 : `FactureService::valider` branché sur `pourRecetteACredit`

**Complexity**: standard
**RED**: Tests Pest :
- `FactureService::valider($facture)` génère une transaction `411/706` (ou multiple lignes 706 si plusieurs produits)
- Solde ouvert 411 = montant TTC facture
- Test existant `FactureServiceTest` reste vert
**GREEN**:
- `FactureService::valider` délègue à `EcritureGenerator::pourRecetteACredit` (potentiellement boucle sur lignes facture pour multi-produits)
**REFACTOR**: None needed
**Files**: `app/Services/FactureService.php`, tests
**Commit**: `feat(v5): FactureService::valider délègue à EcritureGenerator::pourRecetteACredit`

#### Step 24 : `FactureService::encaisser` branché sur `pourEncaissementCreance`

**Complexity**: standard
**RED**: Tests Pest :
- Encaissement facture → auto-lettrage 411
- Solde ouvert 411 = 0
**GREEN**:
- `FactureService::encaisser` délègue à `EcritureGenerator::pourEncaissementCreance`
**REFACTOR**: None needed
**Files**: tests
**Commit**: `feat(v5): FactureService::encaisser délègue à EcritureGenerator + auto-lettrage`

#### Step 25 : `RemiseBancaireService::comptabiliser` branché sur `pourRemiseBancaire`

**Complexity**: complex
**RED**: Tests Pest :
- Création remise avec 3 chèques → T4 splittée par tiers conforme scénario §11
- Test existant `RemiseBancaireServiceTest` reste vert
**GREEN**:
- `RemiseBancaireService::comptabiliser` délègue à `EcritureGenerator::pourRemiseBancaire`
- Conservation totale UI préparation remise
**REFACTOR**: Vérifier que `RemiseBancaireService::supprimer` délette correctement (Step 31 traitera l'extourne mais la suppression de remise est un cas particulier — à confirmer en build)
**Files**: `app/Services/RemiseBancaireService.php`, tests
**Commit**: `feat(v5): RemiseBancaireService::comptabiliser délègue à EcritureGenerator + variante 2a`

#### Step 26 : `ReglementService` (comptabilisation onglet règlements) branché

**Complexity**: standard
**RED**: Tests Pest :
- Marquer un règlement comme reçu depuis l'onglet règlements d'une opération → écritures partie double générées
- Test existant `ReglementServiceTest` reste vert
**GREEN**:
- `ReglementService` (ou équivalent) délègue à `EcritureGenerator`
**REFACTOR**: None needed
**Files**: services règlements, tests
**Commit**: `feat(v5): ReglementService délègue à EcritureGenerator pour comptabilisation`

---

### Phase F — Rapports rebranchés (steps 27-30) — Sous-slice 1c

#### Step 27 : `CompteResultatBuilder` rebranché sur `compte_id` + classe (feature flag)

**Complexity**: complex
**RED**: Tests Pest :
- Nouveau builder lit `transaction_lignes.compte_id` + `comptes.classe IN (6,7)`
- Pour produits (classe 7) : `∑ credit - debit`
- Pour charges (classe 6) : `∑ debit - credit`
- Feature flag `config('compta.use_partie_double')` contrôle le branchement
- Tests existants `CompteResultatBuilderTest` continuent de passer dans les 2 modes
**GREEN**:
- Refonte de `CompteResultatBuilder` avec branchement conditionnel
- `ProvisionService` continue d'enrichir les totaux
**REFACTOR**: Extraire `SoldeCompteResolver` (calcul du solde signé selon la classe) en service réutilisable
**Files**: `app/Services/Rapports/CompteResultatBuilder.php`, tests
**Commit**: `feat(v5): CompteResultatBuilder rebranché sur compte_id + classe (feature flag)`

#### Step 28 : Test de non-régression CR (compare old vs new, tolérance 0,00€)

**Complexity**: complex
**RED**: Test Pest dédié `tests/Feature/CR/PartieDoubleEquivalenceTest.php` :
- Fixture exercice complet réaliste (HelloAsso, factures, remises, extournes, provisions)
- Lance ancien builder + nouveau builder
- Compare ligne à ligne (sous-catégorie/compte, montant)
- Tolérance 0,00€
- Échec = blocage CI
**GREEN**:
- Test écrit
- Couvre toutes les sources de données : recettes, dépenses, HelloAsso, factures, remises
**REFACTOR**: None needed
**Files**: tests
**Commit**: `test(v5): non-régression CR ancien vs nouveau builder (tolérance 0€)`

#### Step 29 : `RapprochementBancaireService` rebranché sur lignes classe 5

**Complexity**: complex
**RED**: Tests Pest :
- Liste rappro lit `transaction_lignes WHERE compte_id IN (512X) AND rapprochement_id IS NULL`
- Une remise = 1 ligne T4 (sans GROUP BY)
- Pointage / dépointage fonctionne
- Tests existants `RapprochementBancaireServiceTest` continuent de passer
**GREEN**:
- Refonte du service (suppression du `GROUP BY remise_id`)
- Conservation `rapprochement_id` à l'entête `transactions` (décision spec §7.2)
**REFACTOR**: None needed
**Files**: `app/Services/RapprochementBancaireService.php`, tests
**Commit**: `feat(v5): RapprochementBancaireService rebranché sur lignes classe 5`

#### Step 30 : Test de non-régression rappro

**Complexity**: complex
**RED**: Test Pest dédié `tests/Feature/Rappro/PartieDoubleEquivalenceTest.php` :
- Fixture exercice complet
- Compare lignes affichées (libellé, montant, statut pointé)
- Tolérance 0 ligne d'écart
**GREEN**: Test écrit
**REFACTOR**: None needed
**Files**: tests
**Commit**: `test(v5): non-régression rappro ancien vs nouveau service`

---

### Phase G — Extournes (step 31) — Sous-slice 1c *(fin → `/clear`)*

#### Step 31 : `TransactionExtourneService` enrichi (auto-délettrage)

**Complexity**: complex
**RED**: Tests Pest scénario spec §11 :
- Extourne d'une transaction dont les lignes sont lettrées → auto-délettrage
- Audit lettrage_audit reçoit `action='delettre'`, `motif='Auto-délettrage suite à extourne de TX#X'`
- Solde ouvert tiers remonte
- Transaction miroir T2' créée sans lettrage_code (volontaire)
- Tests existants `TransactionExtourneServiceTest` restent verts
**GREEN**:
- Enrichir `TransactionExtourneService::extourner` : avant de créer la transaction miroir, parcourir les lignes de l'origine et appeler `LettrageService::delettrerParLigne` pour chaque ligne lettrée
- Conservation totale du comportement extourne existant pour le reste
**REFACTOR**: None needed
**Files**: `app/Services/TransactionExtourneService.php`, tests
**Commit**: `feat(v5): TransactionExtourneService auto-délettre les lignes lettrées`

---

### Phase H — Backfill (steps 32-35) — Sous-slice 1d

#### Step 32 : `BackfillPartieDoubleCommand` squelette + dry-run + rapport

**Complexity**: complex
**RED**: Tests Pest :
- `php artisan compta:backfill-partie-double --exercice=current --dry-run` produit un rapport sans modifier la BDD
- Rapport contient : nb transactions à convertir, sous-catégories sans code_cerfa, modes non couverts
- Aucune ligne modifiée après dry-run
**GREEN**:
- `App\Console\Commands\BackfillPartieDoubleCommand`
- Mode dry-run = audit + simulation, pas de write
**REFACTOR**: Réutiliser logique de l'audit Step 2 si pertinent
**Files**: command, tests
**Commit**: `feat(v5): backfill command + dry-run report`

#### Step 33 : Conversion idempotente (skip si `equilibree=TRUE`)

**Complexity**: complex
**RED**: Tests Pest :
- Run sur fixture exercice complet → toutes transactions ont `equilibree=TRUE` après run
- Re-run immédiat → 0 transaction convertie (toutes déjà à jour, skip)
- Logs affichent le décompte « converted / already up to date / skipped »
- Invariants partie double respectés (équilibre, tiers obligatoire 411/401, pas tiers sur 512X)
**GREEN**:
- Logique de conversion appliquant la matrice §4.3 selon `type` + `mode_paiement`
- Skip si `transactions.equilibree = TRUE`
- DB::transaction englobante, validation post-conversion (Step 28 + Step 30 lancés en post-validation), rollback automatique sur échec
**REFACTOR**: Extraire `TransactionConverter` (1 transaction → écritures partie double) pour testabilité unitaire
**Files**: command (enrichi), `app/Services/Compta/TransactionConverter.php`, tests
**Commit**: `feat(v5): backfill conversion idempotente + invariants + rollback`

#### Step 34 : Option `--force` + reset + guard prod

**Complexity**: standard
**RED**: Tests Pest :
- `--force` en environnement local / preprod → re-conversion totale
- `--force` en prod → throw + exit code non-zéro
- Reset : `transaction_lignes` reset des nouvelles colonnes, T4 de remise supprimées, entrées `lettrage_audit motif=backfill` supprimées
**GREEN**:
- Option `--force` + guard `app()->environment() === 'production' ? abort : continue`
- Logique de reset propre
**REFACTOR**: None needed
**Files**: command (enrichi), tests
**Commit**: `feat(v5): backfill --force pour préprod (refusé en prod)`

#### Step 35 : Tests d'intégration backfill sur fixtures complètes

**Complexity**: complex
**RED**: Test Pest end-to-end :
- Fixture représentative (recettes/dépenses/HelloAsso/factures/remises/extournes/provisions)
- Run backfill → tous invariants OK
- CR identique pré/post backfill
- Rappro identique
- Performance < 5 min sur fixture exercice complet
**GREEN**: Test écrit + fixture créée
**REFACTOR**: None needed
**Files**: tests + fixtures
**Commit**: `test(v5): backfill end-to-end sur exercice complet`

---

### Phase I — Renommage `sous_categorie` → `compte` (steps 36-39) — Sous-slice 1d

#### Step 36 : Migration relations `transaction_lignes.sous_categorie_id` → `compte_id` (peupler)

**Complexity**: complex
**RED**: Tests Pest :
- Après migration, chaque `transaction_lignes.compte_id` est rempli (via mapping `sous_categorie_id → comptes.numero_pcg = sous_categories.code_cerfa`)
- Lignes orphelines (cas Step 2 audit) ne cassent pas la migration (rapport + valeur null tolérée temporairement)
**GREEN**:
- Migration de backfill `compte_id` depuis `sous_categorie_id`
- Conservation `sous_categorie_id` pour rollback de sécurité (drop en Step 40 différé)
**REFACTOR**: None needed
**Files**: migration + tests
**Commit**: `feat(v5): backfill transaction_lignes.compte_id depuis sous_categorie_id`

#### Step 37 : Renommage code base (search/replace assisté)

**Complexity**: complex
**RED**: Suite tests entière reste verte après le renommage
**GREEN**:
- Search/replace : `sous_categorie` → `compte` dans tout `app/`, `resources/views/`, `tests/`, `database/seeders/`, `routes/`
- Attention au cas où `sous_categorie` apparaît dans des données métier (chaînes utilisateur) → ne PAS renommer
- Cas particulier : `$ligne->sous_categorie_id` → `$ligne->compte_id` (avec backfill auto via Step 36)
**REFACTOR**: None needed (refacto en bloc)
**Files**: ~50 fichiers attendus
**Commit**: `refactor(v5): rename sous_categorie → compte across codebase`

#### Step 38 : Renommage UI + routes + redirects 301

**Complexity**: standard
**RED**: Tests Pest UI :
- Écran « Paramètres > Sous-catégories » devient « Paramètres > Comptes »
- Route `/parametres/comptes` répond 200, `/parametres/sous-categories` redirige 301
- Tests Livewire passent
**GREEN**:
- Refonte des vues Blade (labels, titres)
- Mise à jour routes + redirects
- Sidebar mise à jour
**REFACTOR**: None needed
**Files**: vues + routes
**Commit**: `feat(v5): UI rename sous-catégorie → compte + redirects 301`

#### Step 39 : Suppression alias deprecated `SousCategorie`

**Complexity**: trivial
**RED**: Suite tests verte sans `SousCategorie`
**GREEN**:
- Drop `app/Models/SousCategorie.php`
- Grep final pour s'assurer aucun usage résiduel
**REFACTOR**: None needed
**Files**: model deleted + tests
**Commit**: `chore(v5): remove deprecated SousCategorie alias`

---

### Phase J — Drop legacy (step 40, différé hors merge slice 1)

#### Step 40 : Migration de drop des colonnes legacy (PR séparée, 1 semaine après merge prod)

**Complexity**: standard
**RED**: Tests Pest :
- Avant drop : suite verte avec colonnes présentes
- Après drop : suite verte (aucune référence subsistante)
**GREEN**:
- Migration `2026_XX_XX_drop_legacy_columns_from_transactions_and_lignes.php` :
  - Drop `transactions.type`, `transactions.compte_id`
  - Drop `transaction_lignes.sous_categorie_id`, `transaction_lignes.montant`
- **Cette migration n'est pas dans le commit final v5.0.0** — c'est une PR séparée à exécuter ~1 semaine après stabilité prod v5.0.0 confirmée
**REFACTOR**: None needed
**Files**: migration séparée
**Commit**: `feat(v5): drop legacy columns (PR ultérieure, ne pas merger avant stabilité prod)`

---

### Phase K — Documentation + Ops finalisés (steps 41-44) — Sous-slice 1d *(fin → recette préprod + cutover prod)*

#### Step 41 : Documentation interne `docs/compta-partie-double.md`

**Complexity**: standard
**RED**: N/A (doc)
**GREEN**:
- Doc explicative pour l'équipe : modèle partie double, comptes, lettrage, génération auto, conventions affichage
- Diagrammes des flux principaux (recette comptant, remise, encaissement créance, extourne)
- Tableau des comptes système + leur rôle
**REFACTOR**: None needed
**Files**: `docs/compta-partie-double.md`
**Commit**: `docs(v5): documentation interne du moteur partie double`

#### Step 42 : ADR-002 — révision de la stratégie comptable

**Complexity**: trivial
**RED**: N/A (doc)
**GREEN**:
- `docs/adr/002-passage-partie-double.md` (note : `docs/adr/` est gitignored localement chez le PO, à confirmer)
- Acte la révision de `project_compta_partie_double.md` (cash basis enrichi → partie double uniforme)
- Liens vers spec slice 1
**REFACTOR**: None needed
**Files**: ADR
**Commit**: `docs(v5): ADR-002 révision cash basis → partie double uniforme`

#### Step 43 : Scripts ops finalisés

**Complexity**: standard
**RED**: Tests scripts :
- `scripts/clone-prod-to-preprod.sh` testable en dry-run (audit des étapes sans exécution réelle)
- `scripts/deploy-preprod-v5.sh` testable similairement
- Commande artisan `compta:smoke-test-v5` qui exécute CR + rappro + assertion équilibre sur exercice courant
**GREEN**:
- Implémentation complète des 3 scripts (vu §16.4 et §16.5 de la spec)
- Commande smoke
**REFACTOR**: None needed
**Files**: scripts, command
**Commit**: `feat(v5): ops scripts finalisés (clone-prod, deploy-preprod, smoke-test)`

#### Step 44 : Commande `v5:sync-from-main` helper

**Complexity**: standard
**RED**: Test Pest :
- Lance fetch + dry-run merge + dry-run backfill
- Échec dry-run backfill = exit code non-zéro + message clair
**GREEN**:
- `App\Console\Commands\V5SyncFromMainCommand`
- Wraps `git fetch + git merge main + php artisan test --filter=Backfill + php artisan compta:backfill-partie-double --dry-run`
- Rapport synthétique
**REFACTOR**: None needed
**Files**: command, test
**Commit**: `feat(v5): v5:sync-from-main helper pour sync hebdo + validation`

---

## Complexity Classification

| Step | Complexité | Justification |
|---|---|---|
| 1 | trivial | Création branche + scripts skeleton |
| 2 | standard | Commande de reporting, pas de changement métier |
| 3 | complex | Création table + seed transverse multi-tenant |
| 4 | standard | Seed simple avec incrément |
| 5 | standard | Seed + policy garde-fou |
| 6 | complex | Migration sur table critique avec indexes |
| 7 | standard | Ajout colonnes simples |
| 8 | standard | Nouvelle table de log |
| 9 | standard | Modèle Eloquent classique |
| 10 | standard | Enrichissement modèle existant |
| 11 | trivial | Alias deprecated |
| 12 | complex | Service avec 5 invariants + audit |
| 13 | standard | Méthodes complémentaires sur service existant |
| 14 | complex | Squelette service avec architecture invariants |
| 15-20 | complex | Méthodes de matrice avec invariants partie double |
| 21-26 | standard à complex | Branchements UI/Service sur EcritureGenerator |
| 27 | complex | Refonte builder critique + feature flag |
| 28 | complex | Test de non-régression structurant |
| 29 | complex | Refonte service rappro critique |
| 30 | complex | Test de non-régression structurant |
| 31 | complex | Enrichissement service extourne avec coordination |
| 32-35 | complex | Backfill = opération de migration de données |
| 36 | complex | Migration de relations sur table critique |
| 37 | complex | Refacto transverse codebase |
| 38 | standard | Refonte UI + routes |
| 39 | trivial | Suppression d'alias |
| 40 | standard | Migration de drop (différé) |
| 41-42 | standard à trivial | Documentation |
| 43-44 | standard | Scripts ops |

---

## Pre-PR Quality Gate

- [ ] Tous les tests Pest passent (0 failures, 0 errors)
- [ ] Pint passe sans correction
- [ ] Tests d'intrusion multi-tenant S6 (12 tests) restent verts
- [ ] Test de non-régression CR (Step 28) vert
- [ ] Test de non-régression rappro (Step 30) vert
- [ ] Backfill idempotent end-to-end (Step 35) vert
- [ ] Performance CR < 500ms et rappro < 200ms sur exercice complet
- [ ] `/agentic-dev-team:code-review --changed` passe sur l'ensemble de la branche
- [ ] Documentation `docs/compta-partie-double.md` revue
- [ ] ADR-002 rédigé
- [ ] Scripts ops testés en environnement local (dry-run)
- [ ] Recette manuelle préprod (12 vérifications spec §16.6) validée par le PO
- [ ] Backup prod pré-cutover dumpé et stocké

---

## Risks & Open Questions

### Risques (issus spec §13 + complétés)

| Risque | Mitigation | Step |
|---|---|---|
| Backfill produit un CR différent | Test automatique tolérance 0€, rollback DB sur écart | 28, 33 |
| Sous-catégories sans `code_cerfa` empêchent backfill | Dry-run préalable obligatoire (audit step 2), rapport, étape correction manuelle | 2, 32 |
| Performance dégradée sur listes longues | Indexes posés dès Step 6, benchmark préprod en Step 35 | 6, 35 |
| Renommage casse code non testé | Suite Pest complète + grep exhaustif pré-merge | 37 |
| Auto-délettrage perd l'historique | `lettrage_audit` append-only conserve tout | 12, 13 |
| Divergence branche v5 / main | Sync immédiat post-hotfix prod via `v5:sync-from-main` | 44 |
| Backfill non idempotent | Skip sur `equilibree=TRUE`, option `--force` préprod | 33, 34 |
| Cutover prod casse production | Dump pré-cutover obligatoire, plan rollback détaillé spec §16.8 | (cutover) |

### Questions ouvertes (à traiter en step 1 ou par discussion PO)

1. **Numérotation sous-comptes bancaires** : démarrage à `5121` confirmé spec §3.2. Ordre des banques existantes : ordre de création (FK `comptes_bancaires.id`) ou alphabétique ? **Recommandation** : ordre `id` croissant, plus stable et reproductible.

2. **Caisse 530** : la spec §3.4 dit « créé seulement si l'asso utilise les espèces ». Quel critère exact ? Recommandation : `EXISTS (SELECT 1 FROM transactions WHERE association_id = X AND mode_paiement = 'especes' AND deleted_at IS NULL)`.

3. **Renommage `transaction_lignes.sous_categorie_id` → `compte_id`** : on garde les deux colonnes pendant la transition (sous_categorie_id nullable, compte_id renseigné) ou on renomme directement la colonne ? Recommandation : **garder les deux** pendant Steps 36 à 39, drop sous_categorie_id en Step 40.

4. **Provisions** : la spec dit « inchangées ». Mais le `ProvisionService::totalProvisions` retourne potentiellement des montants signés qui doivent s'agréger avec le nouveau CR. À valider en Step 27 (test de non-régression CR avec provisions actives doit passer).

5. **Routes legacy `/compta/parametres/sous-categories`** : déjà droppées en v2.11.4 ? À vérifier en Step 38 (grep exhaustif des routes restantes).

6. **HelloAsso webhook** : pendant la durée de la branche v5, le webhook prod continue d'écrire en mode ancien sur prod. Le sync main → v5 doit régulièrement migrer les nouvelles transactions HelloAsso. Risque mitigué par l'idempotence du backfill (Step 33).

### Décisions de plan à valider par le PO

- **Ordre des steps** : Phase E (Branchements UI) **avant** Phase F (Rapports rebranchés) — défendable parce que les rapports lisent les données générées par les branchements. Alternative : Phase F en premier (feature flag) puis Phase E. Recommandation : ordre actuel (E avant F) car le feature flag de F permet déjà de tester sans dépendre de E.
- **Step 40 (drop legacy)** : confirmé hors slice 1 (PR séparée). Pas dans le merge v5.0.0.
- **Durée du plan** : ~3-4 semaines build + ~1-2 semaines recette préprod (spec §16.9). 44 steps = ~6 par semaine soit ~9 working days par phase A→K en moyenne.

---

## Next Actions

1. PO valide ce plan (ou suggère ajustements)
2. Création branche `feat/compta-v5` (Step 1)
3. Lancement `/agentic-dev-team:build` qui exécutera les steps en mode subagent-driven (préférence PO acquise — `feedback_execution_mode.md`)
4. Sync `main → feat/compta-v5` après chaque hotfix prod via `v5:sync-from-main` (livré Step 44)
5. Recette préprod itérative entre Phases F-G et Phase H (avant Phase I renommage pour limiter conflits sync)
