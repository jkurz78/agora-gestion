# Plan — Dissolution `sous_categories` → `comptes` + familles dérivées

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development.
> Spec de référence : `docs/specs/2026-07-07-dissolution-sous-categories-comptes.md` (D1-D4).

**Goal :** supprimer les notions de sous-catégorie/catégorie au profit de compte/famille,
`comptes` devenant l'unique source de vérité de la ventilation.

**Architecture :** pattern colonnes duales éprouvé sur v5 — (1) familles, (2) compte_id +
backfill + double écriture par trait, (3) bascule lecteurs, (4) bascule écrans + vocabulaire,
(5) drop. Chaque étape laisse la suite verte et le smoke test iso (AC5).

**Stack :** Laravel 11, Livewire 4, Pest. Exécution : Opus orchestre, Sonnet implémente.

---

## Slice 1 — Familles

### DC-1 : table + modèle + migration de données + dérivation
- Migration `create_familles_table` : `id, association_id FK cascade, code (string 2),
  nom, timestamps`, unique `(association_id, code)`.
- Migration de données (même fichier) : pour chaque `categories` existante, parse
  `^(\d{2})\s*-\s*(.+)$` sur `nom` → upsert famille `(code, nom)`. Puis pour chaque
  préfixe distinct `LEFT(numero_pcg,2)` des comptes 6/7 sans famille → créer
  `(code, nom=code)`.
- Modèle `Famille extends TenantModel` (fillable association_id/code/nom, pattern
  `SousCategorie`), factory.
- `Compte` : accesseur `code_famille` (= substr(numero_pcg,0,2)), méthode `famille():
  ?Famille`, scope/helper `Famille::pourComptes(Collection): Collection` (chargement
  groupé keyBy code — pas de N+1).
- Auto-création orpheline : `CompteObserver::created` (nouveau, léger) → si aucune
  famille pour le préfixe d'un compte 6/7, la créer `(nom = code)`. Jamais bloquant.
- Tests : migration data (parse + fallback + orphelins 681/781), modèle, observer.

## Slice 2 — Schéma + backfill + double écriture

### DC-2 : compte_id sur 10 tables + backfill
Tables (toutes sauf `transaction_lignes` qui l'a déjà) : `budget_lines, facture_lignes,
devis_lignes, formules_adhesion, type_operations, helloasso_form_mappings, provisions,
usages_sous_categories, notes_de_frais_lignes, encadrement_previsions`.
- Une migration : `compte_id` nullable + FK `comptes` nullOnDelete sur chacune.
- Backfill in-migration : `UPDATE t JOIN sous_categories sc ON t.sous_categorie_id = sc.id
  JOIN comptes c ON c.numero_pcg = sc.code_cerfa AND c.association_id = sc.association_id
  SET t.compte_id = c.id` (adapter par table ; SQLite-compatible pour les tests →
  boucle Eloquent si nécessaire).
- Test : backfill sur données de fixture (chaque table).

### DC-3 : trait `SyncCompteDepuisSousCategorie`
- `app/Models/Concerns/SyncCompteDepuisSousCategorie.php` : hook `saving` — si
  `sous_categorie_id` non null et `compte_id` null → résoudre via mapping
  code_cerfa=numero_pcg (cache par requête). Posé sur les 10 modèles.
- ⚠ PAS sur `TransactionLigne` : son `compte_id` est posé par le pipeline PD
  (EcritureGenerator/TransactionService) ; un remplissage au saving déclencherait
  l'invariant XOR (ni-ni) sur les lignes legacy à debit/credit 0.
- Tests : création via chaque modèle → compte_id rempli ; TransactionLigne inchangé.

## Slice 3 — Bascule des lecteurs

### DC-4 : rapports
`CompteResultatBuilder` (chemin legacy : jointure compte_id + regroupement famille
dérivée au lieu de categorie/sous_categorie), `RapportService`/CERFA (`numero_pcg`
remplace `code_cerfa`), exports XLSX, VentilationFinanciereService. Invariant :
smoke-test `compta:smoke-test-v5` iso avant/après.

### DC-5 : services métier
FactureService, AdhesionService, HelloAssoSyncService (mapping), NDF
(NoteDeFraisValidationService), ProvisionPDService + ProvisionService,
UsagesComptablesService (usages portés par compte), TransactionService +
`CompteVentilationResolver` (résolution directe compte_id, sous_categorie_id en repli).

### DC-6 : écrans lecteurs
Dashboard, GestionDashboard, ReglementTable, CommunicationTiers,
RapportCompteResultatOperations, TransactionUniverselle (lecture seule d'abord).

## Slice 4 — Écrans + vocabulaire

### DC-7 : écran Plan comptable
Rework `SousCategorieList` → `PlanComptable` (route `parametres/plan-comptable`,
redirect 301 de l'ancienne) : liste classes 6/7 groupée par famille, création
**numéro d'abord** (validation `^[67][0-9A-Z]{2,5}$`, unicité par asso, message fr),
numéro immuable dès qu'il existe une `transaction_lignes.compte_id`, libellé éditable
inline, badge « système » (681/781 : non supprimable, verrouillé), édition du nom des
familles.

### DC-8 : sélecteurs de ventilation
TransactionForm, TransactionUniverselle, FactureEdit, DevisEdit, BudgetTable,
NDF portail + back-office, FormulesList, HelloassoSyncConfig, ProvisionIndex,
UsagesComptables : optgroup par famille (`code — nom`), options « numéro — libellé »
(D1), `wire:model` vers `compte_id` (écriture directe ; le trait DC-3 devient sans
objet pour ces chemins).

### DC-9 : onboarding + seeds + factories + démo
Wizard onboarding (étape sous-catégories → plan comptable),
`DefaultChartOfAccountsService` + `CategoriesSeeder` → créent comptes + familles,
factories, snapshot démo, vocabulaire résiduel dans les blades. D1 : vérifier que les
PDF tiers n'affichent que le libellé.

## Slice 5 — Drop

### DC-10 : purge + revue finale
Drop colonnes `sous_categorie_id` (11 tables — transaction_lignes incluse), tables
`sous_categories` + `categories`, modèles `SousCategorie`/`Categorie` + factories,
`SousCategorieCompteObserver`, `CompteVentilationResolver`, `AuditGuard` (+ son appel
dans la migration create_comptes), trait DC-3, `SousCategorieAutocomplete`, écrans
morts. AC1 : grep zéro référence app/ + views. Pint + suite complète + smoke test.

---

Chaque tâche : TDD (tests d'abord), pint sur fichiers touchés, commit dédié
`feat(compta-v5): …` ou `refactor(compta-v5): …`. Revue orchestrateur entre chaque tâche.
