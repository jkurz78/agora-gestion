# Plan: Fondations partie double — Slice 1

**Created**: 2026-05-20
**Spec**: `docs/specs/2026-05-19-fondations-partie-double-slice1.md` (3 commits, 938 lignes)
**Branch**: `feat/compta-v5` (à créer en Step 1)
**Status**: sous-slice 1a TERMINÉE (11/11 — 2026-05-21) + 1b TERMINÉE 2026-05-22 + **1c en cours 2026-05-24 : Steps 21+23+24+25+26+27+28+29 livrés** (suite 11 901 / 0 failed). Prochain : Step 30 (test non-régression rappro) ou Step 31 (TransactionExtourneService).
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
- [ ] **Remise bancaire** : T4 = 1 ligne 512 D total + N lignes 5112 C par chèque source (sans tiers, amendée 2026-05-22) + lettrage par paire
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
- [ ] **Invariant pas de tiers sur classe 5** (amendé 2026-05-22) : aucune ligne 512X / 5112 / 530 ne porte de tiers (conformément FEC) — *exerçable dès Phase D révisée.*

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
| **1a — Data layer** ✅ | 1-11 | A + B + C | Toutes migrations passées, modèles `Compte` / `TransactionLigne` enrichi en place, suite Pest verte | ~3-4 jours |
| **1b — Services partie double** ✅ (révisée 2026-05-22) | 12-20 | D | `LettrageService` + `EcritureGenerator` complets école 411 systématique (FEC-conforme). Matrice École 411 testée. Suite Pest 11 446 / 0 failed. | ~6-9 jours réels (1ʳᵉ version + révision même jour) |
| **1c — Branchements + rapports** | 21-31 | E + F + G | Tous écrans de saisie + rapports rebranchés sur le nouveau moteur, tests non-régression CR + rappro verts, suite Pest verte | ~5-7 jours |
| **1d — Backfill + renommage + ops** | 32-44 | H + I + (J) + K | Backfill idempotent fonctionnel, codebase renommé, scripts ops finalisés, recette préprod jouée — **prêt cutover prod** | ~5-7 jours |

**Total estimé build** : 18-25 jours soit ~4 semaines, conformément à la spec §16.9. **Révision Phase D ajoute ~1-2 jours** suite amendement spec 2026-05-22.

---

## Amendement 2026-05-22 — Révision Phase D (école 411 systématique)

**Contexte** : trou de cadrage identifié au démarrage 1c. La spec §4.3 d'origine court-circuitait le 411 (recette comptant chèque = `5112 D X tiers / 706 C X`), incompatible FEC. Bascule sur l'école « 411 systématique » : toute écriture de produit/charge passe par 411/401, les classes 5 ne portent jamais de tiers. Voir spec §amendement et §§4.1-4.4, §11 amendés.

**Conséquence** : les 6 méthodes `EcritureGenerator::pour*` livrées en première version 1b (commits `27613be5` à `9359a5c5`) sont à **réviser**. `LettrageService` (Steps 12-13) est inchangé. Step 14 (squelette + invariants) est inchangé sauf l'invariant 5 reformulé.

### Périmètre de révision méthode par méthode

| Step | Méthode | Commit d'origine | Révision requise |
|---|---|---|---|
| 12 | `LettrageService::lettrer` | `70b46ae8` | **Inchangé** |
| 13 | `LettrageService::delettrer*` | `87bd2786` | **Inchangé** |
| 14 | `EcritureGenerator` squelette + invariants | `f5ef4452` | Invariant 5 reformulé : « tiers obligatoire 411/401, **interdit sur classe 5** (512X, 5112, 530), optionnel sur 6x/7x ». Les méthodes d'invariants existent déjà — il faut juste **étendre `assertPasDeTiersSur512` → `assertPasDeTiersSurClasse5`** (s'applique à 5112 et 530 aussi). |
| 15 | `pourRecetteComptant` | `27613be5` | **Refonte majeure** : signature multi-ventilation (`iterable $ventilations`). Schéma passe de 2 lignes à (N+3) lignes : `411 D total tiers / [7x C × N] / 5xx D total / 411 C total tiers` + auto-lettrage interne 411. |
| 16 | `pourRecetteACredit` | `4da5e084` | **Extension multi-vent** : signature `iterable $ventilations`. Schéma `411 D total tiers / [7x C × N]`. |
| 17 | `pourEncaissementCreance` | `a1f35238` | **Mineur** : la ligne 411 source agrégée reste unique (multi-vent ne touche que les lignes 7x). Vérifier que la résolution `$tCreance->lignes()->where('compte_id', $compte411->id)->first()` reste valide (oui, une seule ligne 411 D par créance dans la nouvelle école). |
| 18 | `pourDepenseComptant` | `9116b731` | **Refonte majeure symétrique S15** : signature multi-vent. Schéma `[6x D × N] / 401 C total tiers / 401 D total tiers / 5xx C total` + auto-lettrage interne 401. |
| 19 | `pourDepenseACredit` + `pourReglementFournisseur` | `1604623b` | **`pourDepenseACredit`** : extension multi-vent symétrique S16. **`pourReglementFournisseur`** : mineur (symétrique S17). |
| 20 | `pourRemiseBancaire` | `9359a5c5` | **Modéré** : retirer le groupement par tiers (les lignes 5112 sources n'ont plus de tiers). Schéma : `512 D total / [5112 C × N — une par chèque source, sans tiers]` + lettrage par paire ligne source ↔ ligne remise. Helper privé `regrouperParTiers` à supprimer. |

### Stratégie d'exécution

1. **Pas de rollback git** des commits 1b — historique préservé comme « version école directe ».
2. **Commits de révision par-dessus**, datés 2026-05-22, méthode par méthode en TDD.
3. **Ordre** : Step 14 (invariants) → Step 15 → Step 18 (refontes majeures symétriques) → Step 16 → Step 19a `pourDepenseACredit` (multi-vent) → Step 17 + Step 19b `pourReglementFournisseur` (vérifs mineures) → Step 20 (allégement).
4. **Tests Pest** : squelettes/factories/helpers conservés. Réécriture des assertions sur les lignes attendues (4 lignes au lieu de 2 sur comptant, sans tiers sur 5xx, etc.). Ajout : 1 test multi-ventilation par méthode + 1 test FEC-conformité globale (aucune ligne classe 5 ne porte tiers).
5. **Cible** : suite Pest verte avec révision finie avant Step 21.

### Steps Phase D — statut révisé

Les blocs RED/GREEN/REFACTOR ci-dessous reflètent la **première version (école directe)** livrée le 2026-05-22. La révision 2026-05-22 (école 411 systématique) suivra la table ci-dessus, sans réécriture du plan step par step — la spec amendée tient lieu de référence pour la version cible.

---

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

#### Step 11 : Cohabitation `SousCategorie` ↔ `Compte` (pas d'alias en 1a, déféré 1d) ✅

**Complexity**: trivial
**Status**: ✅ done — commit `315658d9` (2026-05-21). 4 tests Pest verts (15 assertions), Pint vert, suite complète **10 995 assertions / 0 failed**. Aucune modif code prod — uniquement le test file `tests/Feature/Models/SousCategorieCompteCohabitationTest.php`. Verifie : tables distinctes (`comptes` vs `sous_categories`), coexistence Eloquent sans collision, `FormuleAdhesion::sousCategorie` retourne toujours `SousCategorie` (smoke test régression).
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

### Phase D — Services (steps 12-20) — Sous-slice 1b ✅ TERMINÉE 2026-05-22 *(fin → `/clear`)*

Sous-slice 1b livrée sur `feat/compta-v5` — 9 commits (Steps 12-20), 76 nouveaux tests Pest unit, +374 assertions. Suite globale 11 371 assertions / 0 failed. `LettrageService` et `EcritureGenerator` complets, matrice École C entièrement testée unitairement. **Pas encore branché sur l'UI** (c'est 1c).


#### Step 12 : `LettrageService::lettrer` — invariants + audit ✅

**Complexity**: complex
**Status**: ✅ done — commit `70b46ae8` (2026-05-22). 9/9 tests, 29 assertions. Décision : tenant-check exécuté en premier (security-first vs ordre plan), bcmath pour comparaison équilibre (évite drift float), `Illuminate\Support\Collection` en signature (supertype).
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

#### Step 13 : `LettrageService::delettrer` + `delettrerParLigne` ✅

**Complexity**: standard
**Status**: ✅ done — commit `87bd2786` (2026-05-22). 7/7 tests, 32 assertions. Refactor `writeAudit()` mutualisé entre lettrer/delettrer. Filtrage tenant via `whereHas('compte')` (TenantScope global s'applique automatiquement).
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

#### Step 14 : `EcritureGenerator` squelette + invariants ✅

**Complexity**: complex
**Status**: ✅ done — commit `f5ef4452` (2026-05-22). 14/14 tests, 18 assertions. Invariants exposés en **public** pour testabilité directe (visibilité à réévaluer après Step 20 — peut être réduit à private si les `pour*` les exercent déjà). 4 nouvelles exceptions (EcritureNonEquilibree, CompteIncorrect, TiersRequis, TiersInterdit) + enrichissement `TenantBoundaryException::crossTenantTiers`. ⚠ **RÉVISION 2026-05-22** : invariant 5 reformulé, `assertPasDeTiersSur512` à étendre en `assertPasDeTiersSurClasse5` (cf. spec §4.2 amendé).
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

#### Step 15 : `EcritureGenerator::pourRecetteComptant` (tous modes) ⚠ EN RÉVISION

**Complexity**: complex
**Status**: 1ʳᵉ version (école directe) — commit `27613be5` (2026-05-22). 13/13 tests, 49 assertions. ⚠ **EN RÉVISION 2026-05-22** : refonte majeure pour école 411 systématique (signature multi-ventilation, schéma N+3 lignes, auto-lettrage interne 411). Voir « Amendement 2026-05-22 — Révision Phase D » plus haut. Décisions historiques (école directe) : Prelevement = Virement (portage 512X) ; `assertPasDeTiersSur512` NON appelé sur T1 directes ; legacy `montant=0`, `sous_categorie_id=null` ; `Transaction.fillable` enrichi avec `equilibree` + `type_ecriture`.
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

#### Step 16 : `EcritureGenerator::pourRecetteACredit` ⚠ EN RÉVISION

**Complexity**: standard
**Status**: 1ʳᵉ version — commit `4da5e084` (2026-05-22). 9/9 tests, 27 assertions. ⚠ **EN RÉVISION 2026-05-22** : extension signature multi-ventilation (`iterable $ventilations`). Schéma 411 D total tiers / [7x C × N]. Helper `createTransactionHeader(...)` conservé. `mode_paiement = null` pour créance constatée conservé.
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

#### Step 17 : `EcritureGenerator::pourEncaissementCreance` + auto-lettrage 411 ⚠ RÉVISION MINEURE

**Complexity**: complex
**Status**: 1ʳᵉ version — commit `a1f35238` (2026-05-22). 10/10 tests, 47 assertions. ⚠ **RÉVISION MINEURE 2026-05-22** : la ligne 411 source agrégée reste unique (multi-vent ne touche que les lignes 7x amont), schéma T2 inchangé `5xx D / 411 C tiers`. Vérifier que la ligne 5xx D (sans tiers) respecte le nouvel invariant 5 amendé. Décisions historiques : `LettrageDejaPresentException` réutilisée ; `LigneDejaLettreeException` créée mais non utilisée (à nettoyer en revue) ; résolution ligne 411 source via query DB fraîche (stale relation).
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

#### Step 18 : `EcritureGenerator::pourDepense*` (3 cas) ⚠ EN RÉVISION

**Complexity**: standard
**Status**: 1ʳᵉ version (école directe) — commit `9116b731` (2026-05-22). 11/11 tests, 44 assertions. ⚠ **EN RÉVISION 2026-05-22** : refonte majeure symétrique S15 pour école 411 systématique (signature multi-ventilation, schéma N+3 lignes `[6x D × N] / 401 C total tiers / 401 D total tiers / 5xx C total`, auto-lettrage interne 401). Helper `resoudreComptePortageDepense` conservé (asymétrie 5112 conservée : chèque émis débite 512 directement).
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

#### Step 19 : `EcritureGenerator::pourDepenseACredit` + `pourReglementFournisseur` + auto-lettrage 401 ⚠ EN RÉVISION

**Complexity**: standard
**Status**: 1ʳᵉ version — commit `1604623b` (2026-05-22). 14/14 tests (6 dépense crédit + 8 règlement), 64 assertions. ⚠ **EN RÉVISION 2026-05-22** : `pourDepenseACredit` extension multi-ventilation symétrique S16 (`[6x D × N] / 401 C total tiers`) ; `pourReglementFournisseur` révision mineure (T2 inchangé `401 D tiers / 5xx C`, vérifier nouvel invariant 5 sur 5xx sans tiers). Décision conservée : pas de factorisation S17/S19 (5 axes diffèrent, lisibilité métier).
**RED**: Tests Pest :
- Dépense à crédit → `607 D X / 401 C X (tiers)`
- Règlement fournisseur → `401 D X (tiers) / 512 C X` + auto-lettrage paire 401
- Solde ouvert 401 du tiers = 0 après règlement
**GREEN**:
- Méthodes `pourDepenseACredit` et `pourReglementFournisseur`
**REFACTOR**: Factoriser les 2 méthodes encaissement créance / règlement fournisseur si pattern identique
**Files**: tests
**Commit**: `feat(v5): EcritureGenerator::pourDepenseACredit + pourReglementFournisseur + auto-lettrage 401`

#### Step 20 : `EcritureGenerator::pourRemiseBancaire` ⚠ RÉVISION MODÉRÉE

**Complexity**: complex
**Status**: 1ʳᵉ version (splittée par tiers) — commit `9359a5c5` (2026-05-22). 13/13 tests, 67 assertions. ⚠ **RÉVISION MODÉRÉE 2026-05-22** : retrait du groupement par tiers (les lignes 5112 sources n'ont plus de tiers, cf. invariant 5 amendé). Nouveau schéma : `512 D total / [5112 C × N — une par chèque source, sans tiers]` + lettrage par paire ligne source ↔ ligne remise. Helper privé `regrouperParTiers` à supprimer. Résolution `CompteBancaire → Compte 512X` par IBAN conservée. Pattern de rechargements DB explicites conservé.
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

#### Step 21 : `TransactionService` branché sur `EcritureGenerator` (recette + dépense unifié) ✅

**Status**: ✅ TERMINÉ 2026-05-23 — commits `5ad4645d` (feat) + `b725c6e2` (fix gardes mode comptant) + `0a7dad79` (refactor qualité). Suite Pest 11 537 / 0 failed. **Steps 21 + 22 fusionnés** : `app/Livewire/TransactionForm.php` gère unifié recettes + dépenses, donc branchement dans `TransactionService::create()` (1 commit feat + 2 commits suite reviews spec + qualité).

**Décisions actées** (cf. brainstorming avec PO le 2026-05-23) :
- **Point d'injection** : `TransactionService::create()` (PAS `TransactionForm`). UI inchangée. EcritureGenerator étendu d'un paramètre `?Transaction $existingTransaction = null` sur ses 4 méthodes `pourXXX` (option A — préserve l'API publique pour les 113 tests unit).
- **Modèle DB** : 1 seule Transaction. Les N lignes ventilation legacy sont enrichies en place via `$ligne->fill([compte_id, debit, credit])->save()` (chemin Eloquent → observer XOR actif). Les 3 lignes techniques PD-only (411/401 D tiers, 5xx D/C portage, 411/401 C tiers) sont ajoutées sur la MÊME Transaction par EcritureGenerator (qui skippe la création de ses propres lignes de ventilation si `$existingTransaction` fourni).
- **Double écriture systématique** : pas de feature flag. Les lignes legacy (sous_categorie_id + montant) sont conservées intactes pour les rapports.
- **Asymétrie chèque** : pour dépense `Cheque`, `EcritureGenerator::resoudreComptePortageDepense` retourne `$compteTresorerieExplicite` (512X direct, pas 5112 miroir). TransactionService doit donc skip si IBAN→512X échoue pour dépense chèque (sinon écriture incorrecte sur 5112).
- **Gardes skip avec log** : tiers_id null, sous_categorie sans code_cerfa, compte introuvable, classe inattendue, compte_id null + mode nécessitant 512X → skip + Log::warning (préserve la création legacy).

**Tests ajoutés** : `tests/Feature/Services/TransactionServicePartieDoubleTest.php` — 12 scenarios (89 assertions) : 4 cas canoniques, multi-ventilation, conservation legacy, 4 cas de skip, dépense chèque (révèle bug placeholder 5112), compte_id null sur mode comptant, garde-fou XOR observer, propagation notes ventilation.

**Refactor inclus** : helper privé `EcritureGenerator::reattacherComptesAuxLignes(Collection, Compte, ?Compte, Collection)` extrait — élimine ~80 lignes dupliquées sur les 4 méthodes `pourXXX`.

**Dette technique notée pour Steps 22+** :
- **N+1 latent** dans `TransactionService::enrichirPartieDouble` : N × `SousCategorie::find` + N × `Compte::ofNumero` dans la boucle. Préfetch à faire si HelloAsso (N≤10) pose problème en pratique. Non bloquant N≤3.
- **SRP enrichirPartieDouble (~215 lignes)** : 4 responsabilités identifiables (résolution tiers, ventilations+enrichissement legacy, trésorerie 512X, dispatch 4 chemins). Extraction en 3 méthodes privées recommandée au Step 22 ou Step 23 quand la méthode regrossit (sinon re-divergence assurée).
- **`TransactionService::update`** : NON couvert au Step 21. La logique `forceDelete` + recréation des lignes détruirait les lignes PD-only sans les recréer. Step dédié à prévoir (ex. Step 26b ou au cutover 1d).
- **Helpers test globaux `compte411()` / `compte401()` / `compte5112()`** : à factoriser dans un trait `WithCompteResolvers` ou `tests/Pest.php` quand Step 23 en aura besoin.
- **Setup test dupliqué (~65 lignes beforeEach)** : à factoriser en trait `CreatesPartieDoublContext` au Step 23.

**Files**: `app/Services/TransactionService.php`, `app/Services/Compta/EcritureGenerator.php`, `tests/Feature/Services/TransactionServicePartieDoubleTest.php`
**Commits**: `5ad4645d` + `b725c6e2` + `0a7dad79`

#### Step 22 : Livewire Dépense branché — **FUSIONNÉ dans Step 21** ✅

#### Step 23 : `FactureService::valider` branché sur `pourRecetteACredit` ✅

**Status**: ✅ TERMINÉ 2026-05-23 — commits `84615294` (feat) + `67674dd8` (quality fixes I2 + note dette). Suite Pest 11 592 / 0 failed (+14 vs Step 21).
**Option choisie**: A (inline) — résolution SC→Compte inline dans `resoudreCompteVentilationRecette` (helper privé) + appel direct `EcritureGenerator::pourRecetteACredit(existingTransaction:)`. Pas d'extraction de helper partagé avec Step 21 (rule of three — attendre Step 25).
**Décisions implémentation** :
- Toujours `pourRecetteACredit` (facture validée = créance, même si `mode_paiement_prevu` renseigné). Pas de branche comptant.
- Lignes legacy enrichies via `$ligne->fill([...])->save()` (observer XOR actif, pattern Step 21).
- Boucle skip **sans break** (asymétrie vs Step 21 documentée inline) : la boucle sert AUSSI à créer les TransactionLignes legacy pour TOUTES les FactureLignes (chaque FactureLigne doit avoir sa `transaction_ligne_id`). Le `break` Step 21 ne s'applique pas ici. Sémantique à réconcilier au Step 25+ lors de l'extraction du helper partagé.
- 4 gardes skip avec `Log::warning` : sous_cat null (mort en pratique grâce à `assertGuardsLignesManuelles`), code_cerfa null, Compte introuvable, classe ≠ 7.
- `Tiers::findOrFail` (FK NOT NULL en base).
- Lignes Montant (ref TX existante), Texte, et factures sans MontantManuel inchangées.

**Tests** : 8 scénarios dans `tests/Feature/Services/FactureServicePartieDoubleTest.php` (~55 assertions) : happy path 1+2 ventilations, solde ouvert 411 = montant_total, conservation legacy, facture sans MontantManuel, FEC-conformité, pas de lettrage auto, SC sans code_cerfa (skip gracieux + Log::warning + facture toujours Validee).

**Dette technique notée pour Steps 24+** :
- **DRY M1** : `resoudreCompteVentilationRecette` (~55 lignes) quasi-identique au bloc résolution Step 21. Extraction `App\Services\Compta\PartieDoubleEnricher` ou similaire à faire **au Step 25** (3ᵉ caller fera émerger le pattern net).
- **SRP** : `genererTransactionDepuisLignesManuelles` est passée de ~30 à ~92 lignes (4 responsabilités initiales + 3 nouvelles). À surveiller — extraction à faire en même temps que M1.
- **M2** : `Tiers::findOrFail((int) $facture->tiers_id)` génère 1 requête DB en plus. Pourrait être `$facture->tiers` (relation chargée). À fixer post-cutover.
- **M5 helpers test** : `compte411PD()` dupliqué de `compte411()` (Step 21) à cause de collision globale Pest. Factoriser dans `tests/Pest.php` au Step 24.
- **M6 messages log** : `'[PartieDouble] Step 23 — …'` — remplacer par `'[PartieDouble][FactureService] …'` post-cutover.

**Files**: `app/Services/FactureService.php` (+116 lignes), `tests/Feature/Services/FactureServicePartieDoubleTest.php` (créé, 436 lignes)
**Commits**: `84615294` + `67674dd8`

#### Step 24 : `FactureService::marquerReglementRecu` branché sur `pourEncaissementCreance` ✅

**Status**: ✅ TERMINÉ 2026-05-23 — commits `4153b9b9` (feat) + `2ceb599a` (quality fixes I-1 à I-4 + M-4). Suite Pest 11 649 / 0 failed (+57 vs Step 23).
**Méthode cible** : `marquerReglementRecu(Facture, array $transactionIds)` (et non `encaisser` comme nommé dans le plan d'origine).
**Différence vs Steps 21+23** : T2 = **nouvelle Transaction physique** créée par `pourEncaissementCreance` (pas d'enrichissement de la T1). La paire 411 (T1-ligne + T2-ligne) est auto-lettrée. T2 attachée au pivot `facture_transaction`.
**Décisions implémentation** :
- Mode + compteTresorerie résolus depuis T1 (`$t1->mode_paiement` copié de `mode_paiement_prevu` au Step 23, `$t1->compte_id` CompteBancaire).
- 5 gardes skip avec `Log::warning` : transaction sans tiers/411 introuvable (legacy), compte 411 absent (tenant sans schéma PD), mode_paiement null, IBAN no-match pour modes nécessitant 512X, ligne 411 déjà lettrée → throw `LettrageDejaPresentException` rollback complet.
- Pas de paramètre `$existingTransaction` car `pourEncaissementCreance` crée toujours une T2 nouvelle (matrice spec §4.3 à 2 transactions distinctes).
- Préservation flag legacy : si skip PD, `statut_reglement = Recu` quand même (le PD est best-effort).

**Refactor inclus — Option B (extraction helper `CompteTresorerieResolver`)** :
- Nouveau `App\Services\Compta\CompteTresorerieResolver::resoudre(?int $compteBancaireId, ModePaiement $mode, string $contextLog, bool $isDepense): ?Compte`
- Factorise la résolution `CompteBancaire IBAN → Compte 512X` + fallback placeholder utilisée par TransactionService (Step 21) ET FactureService (Step 24).
- 3ᵉ caller à venir : RemiseBancaireService Step 25.
- 8 cas couverts en test unit dédié (`tests/Unit/Services/Compta/CompteTresorerieResolverTest.php`, 201 assertions).

**Refactor inclus — helper test global** :
- `compteSysteme(string $numero): Compte` extrait dans `tests/Pest.php` — remplace les 6 helpers locaux dupliqués (`compte411()` / `compte5112()` / `compte411PD()` / `compte411Enc()` / etc.) dans 4 fichiers test. -125 lignes au total.

**Tests** : 6 scénarios dans `tests/Feature/Services/FactureServicePartieDoubleEncaissementTest.php` (~42 assertions) + 8 cas dans test unit resolver. Couvre : Cheque (5112), Virement (512X IBAN), solde ouvert 411 = 0 après encaissement, double encaissement (LettrageDejaPresentException + rollback complet via 2-T1 cohérentes), mode + compte_id null (skip + Log::warning), T1 legacy sans 411 (skip gracieux).

**Dette technique notée pour Steps 25+** :
- **N+1 latent** dans `marquerReglementRecu` : pour chaque tx ~5-6 SELECT/insert. `Compte::ofNumero('411')` hoistable hors boucle. Acceptable car N ≤ 3-5 en pratique.
- **Param `$isDepense: bool`** dans CompteTresorerieResolver : enum `Sens { Recette, Depense }` plus parlant. Post-cutover.
- **`TransactionService::update`** : toujours pas couvert (idem note Step 21).
- **Convention messages log `[PartieDouble] Step XX`** : préfixe historique fragile (cf. M-6 Step 23). À refactorer en `[PartieDouble][ServiceName]` post-cutover.

**Files**: `app/Services/Compta/CompteTresorerieResolver.php` (créé, 97 lignes), `app/Services/FactureService.php` (+89 lignes), `app/Services/TransactionService.php` (-40 lignes inline → appel resolver), `tests/Feature/Services/FactureServicePartieDoubleEncaissementTest.php` (créé, 6 scénarios), `tests/Unit/Services/Compta/CompteTresorerieResolverTest.php` (créé, 8 cas), `tests/Pest.php` (+13 lignes helper), `tests/Feature/Services/TransactionServicePartieDoubleTest.php` + `FactureServicePartieDoubleTest.php` + `EcritureGeneratorPourRecetteComptantTest.php` (refacto helpers)
**Commits**: `4153b9b9` + `2ceb599a`

#### Step 25 : `RemiseBancaireService::comptabiliser` branché sur `pourRemiseBancaire` ✅

**Status**: ✅ TERMINÉ 2026-05-23 — commits `3291033e` (feat) + `53f1becf` (quality fixes Important-1 à Minor-3). Suite Pest 11 741 / 0 failed (+92 vs Step 24).
**Méthode cible** : `comptabiliser(RemiseBancaire, array $transactionIds)`. Couvre aussi `modifier()` et `supprimer()` (cycle complet T4).
**Décisions implémentation** :
- T4 = nouvelle Transaction créée par `pourRemiseBancaire` (pas d'enrichissement). Lien remise→T4 via `transactions.remise_id = $remise->id` posé après création (option a, cohérent §2.4 legacy).
- Modes supportés : Cheque (portage 5112) + Especes (portage 530). Autres → InvalidArgumentException (déjà couvert par EcritureGenerator).
- **Auto-lettrage 5112↔5112 par paire 1↔1** (spec §4.4 amendée 2026-05-22, helper `regrouperParTiers` SUPPRIMÉ). Vérifié dans test A : 3 paires distinctes, codes lettrage uniques par paire.
- Critère discriminant T4 : `(remise_id, reference IS NULL, equilibree = true)` formalisé en méthode privée `queryT4(int $remiseId): Builder<Transaction>` avec docblock documentant l'invariant (T1 sources ont TOUJOURS reference != null après comptabiliser/modifier).
- `comptabiliser()` idempotente : garde explicite `if (queryT4()->exists()) throw "déjà comptabilisée"`. L'UI Livewire (`RemiseBancaireValidation`) route déjà vers `modifier()` si reference existe → aucune régression UI.
- `modifier()` : delete+recreate T4 via `supprimerT4SiExiste() + recreerT4()` dans la même DB::transaction. Bug index numérotation corrigé (T4 ne doit pas être comptée).
- `supprimer()` : `supprimerT4SiExiste()` (délettre paires 5112↔5112, forceDelete lignes + T4) puis `$remise->delete()`.
- Gardes skip avec Log::warning : tx legacy sans ligne portage 5112/530 (skip cette tx mais continue traitement legacy), mode non supporté, compte portage introuvable. Si aucune source résolue → pas de T4, log warning.
- **CompteTresorerieResolver NON utilisé ici** : `pourRemiseBancaire` résout 512X par IBAN inline (force-resolve sans fallback). Asymétrie acceptée — pas 3ᵉ caller resolver.

**Tests** : 11 scénarios dans `tests/Feature/Services/RemiseBancaireServicePartieDoubleTest.php` (~91 assertions). Couvre : A (chèque 3 sources), B (espèces 2 sources), C (solde 5112=0), D (supprimer + délettrer + delete T4), E (modifier ajout + index correct), F (modifier retrait + délettrage source), G (legacy skip), G2 (mix PD+legacy), H (verrouillée throw), I (invariant queryT4 unique), J (idempotence comptabiliser throw).

**Dette technique notée pour Steps 26+** :
- **N+1 latent dans `recreerT4()`** : boucle sur transactionsRemise → 1 SELECT ligne portage par tx. Hoistable via `whereIn`. Acceptable car N petit.
- **Helpers privés `supprimerT4SiExiste()` + `recreerT4()` + `queryT4()`** : RemiseBancaireService grossit à ~360 lignes. Extraction `App\Services\Compta\RemiseT4Manager` envisageable si T4 acquiert plus d'autonomie (extournes Step 31 par ex).
- **Step 26 ReglementService** : appliquera le même pattern (critère discriminant T3 = `equilibree = true AND reference IS NULL` probablement). Le pattern `queryT4()` formalisé en Step 25 sera réplicable.
- **Step 29 RapprochementBancaireService** : la T4 a `remise_id` et une ligne 512X. Le rappro lira `transaction_lignes` classe 5 → ligne 512X T4 sera pointable comme unique ligne de la remise (spec §7).
- **Step 31 TransactionExtourneService** : pattern `supprimerT4SiExiste()` peut servir de référence pour extourner une T4 (délettrer paires 5112↔5112 avant extourne).

**Files**: `app/Services/RemiseBancaireService.php` (+218 lignes), `tests/Feature/Services/RemiseBancaireServicePartieDoubleTest.php` (créé, 11 scénarios)
**Commits**: `3291033e` + `53f1becf`

#### Step 26 : `ReglementOperationService` (onglet règlements opération) branché ✅

**Status**: ✅ TERMINÉ 2026-05-23 — commits `946156c0` (feat) + `848ad31b` (fix tenant scope Reglement). Suite Pest 11 806 / 0 failed (+65 vs Step 25).
**Service nouveau** : `App\Services\ReglementOperationService` (créé, ~280 lignes) — option B retenue (nouveau service dédié, cohérent pattern FactureService).
**2 méthodes publiques** :
- `comptabiliserSeance(Seance, array $data)` : crée N Transactions de règlement créance (statut_reglement=EnAttente) + enrichit lignes legacy + appelle `pourRecetteACredit(existingTransaction)` pour chaque. **TOUJOURS pourRecetteACredit** même si mode_paiement renseigné (statut EnAttente = créance, mode est prospective).
- `marquerRecu(Transaction)` : toggle statut_reglement=Recu + appelle `pourEncaissementCreance` pour T2 + auto-lettrage 411 (pattern Step 24). **Pas d'attache pivot** (pas de facture).
**Refactor Livewire** : `ReglementTable` devient thin wrapper, -32 lignes inline.
**Guard multi-tenant ajouté** (fix `848ad31b`) : `Reglement::where('seance_id')` enrichi de `whereHas('participant', fn → where('association_id', currentId))`. Reglement n'a pas de colonne association_id directe — la chaîne tenant passe par participant.association_id.
**Tests** : 8 scénarios (~60 assertions) dans `tests/Feature/Services/ReglementOperationServicePartieDoubleTest.php`. Couvre : créance créée, marquerRecu (T2 + lettrage), solde 411 = 0, multi-seances, Virement+IBAN, garde locks, mode_paiement prospective, tenant cross-isolation [H].
**Dette technique** : **3ème occurrence `resoudreCompteVentilationRecette`** (TransactionService + FactureService + ReglementOperationService). À extraire en `App\Services\Compta\CompteVentilationResolver` **au début du Step 27** (avant le code CompteResultatBuilder) pour éviter divergence sur correction future.
**Files**: `app/Services/ReglementOperationService.php` (créé), `app/Livewire/ReglementTable.php` (-32 lignes), `tests/Feature/Services/ReglementOperationServicePartieDoubleTest.php` (créé, 8 scénarios)
**Commits**: `946156c0` + `848ad31b`

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
