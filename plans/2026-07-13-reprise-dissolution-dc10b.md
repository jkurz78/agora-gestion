# Reprise dissolution sous-catégories DC-10b Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Achever la dissolution des sous-catégories en conservant `comptes` et `familles` comme seules sources de vérité, avec une migration finale sûre et une suite Pest verte.

**Architecture:** La migration finale renomme le pivot en `usages_comptes`, remplace ses contraintes legacy, supprime toutes les FK et colonnes historiques, puis les tables devenues inutiles. L'application et les tests sont ensuite rendus strictement compte-first ; les seuls noms historiques encore autorisés vivent dans les migrations qui doivent rejouer sur une installation neuve.

**Tech Stack:** Laravel 11, Livewire 4, MySQL 8.4, SQLite en mémoire pour Pest, PHP 8.5, Pest PHP.

## Global Constraints

- `declare(strict_types=1)`, classes finales et signatures typées.
- Multi-tenant fail-closed via `TenantModel` et `TenantContext`.
- Aucun `migrate:fresh` sur la base de développement, qui est un clone de production.
- Aucun push vers `origin`.
- La spécification validée prévaut : `usages_sous_categories` devient `usages_comptes`.
- En fin de chantier, aucune référence `SousCategorie` ou `sous_categorie` ne reste dans `app/` ni `resources/views/`.
- TDD : chaque correction comportementale commence par un test qui échoue pour la raison attendue.

---

### Task 1: Migration finale et modèle UsageCompte

**Files:**
- Create: `tests/Feature/Migrations/DropSousCategoriesFinalTest.php`
- Modify: `database/migrations/2026_07_12_200001_drop_sous_categories_and_categories.php`
- Modify: `app/Models/UsageCompte.php`
- Modify: `database/factories/UsageCompteFactory.php`
- Modify: `app/Models/Compte.php`

**Interfaces:**
- Consumes: schéma créé par les migrations DC-1, DC-2 et DC-10b-2.
- Produces: table `usages_comptes` avec `compte_id` obligatoire, FK cohérente et unicité `(association_id, compte_id, usage)` ; absence des tables et colonnes legacy.

- [ ] **Step 1: Écrire les tests de contrat de migration**

```php
it('drops the legacy accounting schema and renames usages', function (): void {
    expect(Schema::hasTable('usages_comptes'))->toBeTrue()
        ->and(Schema::hasTable('usages_sous_categories'))->toBeFalse()
        ->and(Schema::hasTable('sous_categories'))->toBeFalse()
        ->and(Schema::hasTable('categories'))->toBeFalse()
        ->and(Schema::hasColumn('transaction_lignes', 'sous_categorie_id'))->toBeFalse()
        ->and(Schema::hasColumn('comptes', 'categorie_id'))->toBeFalse();
});

it('enforces one usage per tenant account and usage', function (): void {
    $compte = Compte::factory()->create();
    UsageCompte::factory()->for($compte)->create(['usage' => UsageComptable::Don]);
    expect(fn () => UsageCompte::factory()->for($compte)->create([
        'usage' => UsageComptable::Don,
    ]))->toThrow(QueryException::class);
});
```

- [ ] **Step 2: Vérifier le rouge**

Run: `./vendor/bin/sail artisan test tests/Feature/Migrations/DropSousCategoriesFinalTest.php --stop-on-failure`

Expected: échec lors du drop de `transaction_lignes.sous_categorie_id` à cause de `tl_tx_sc_idx`, ou absence du nouvel index unique.

- [ ] **Step 3: Corriger la migration**

Supprimer `tl_tx_sc_idx` avant la colonne ; sur le pivot, supprimer les FK et index legacy, renommer la table, rendre `compte_id` non nullable, recréer sa FK avec suppression en cascade, puis créer les index `usages_comptes_unique` et `usages_comptes_asso_usage_idx`. Backfiller aussi les lignes de transaction soft-deleted quand le compte 1:1 existe et faire échouer la migration si un rattachement reste non résolu.

- [ ] **Step 4: Vérifier le vert et le style**

Run: `./vendor/bin/sail artisan test tests/Feature/Migrations/DropSousCategoriesFinalTest.php`

Expected: PASS.

Run: `./vendor/bin/pint --test database/migrations/2026_07_12_200001_drop_sous_categories_and_categories.php app/Models/UsageCompte.php database/factories/UsageCompteFactory.php tests/Feature/Migrations/DropSousCategoriesFinalTest.php`

Expected: exit 0.

---

### Task 2: Lecteurs applicatifs compte-first

**Files:**
- Modify: `app/Console/Commands/BackfillPartieDoubleCommand.php`
- Modify: `app/Console/Commands/DumpTransactionCommand.php`
- Modify: `app/Console/Commands/SmokeTestV5Command.php`
- Modify: `app/Console/Commands/ComptaCheckIntegrityCommand.php`
- Modify: `app/Console/Commands/TenantBenchmark.php`
- Delete or rewrite: `app/Console/Commands/FixProdJuin2026Command.php`
- Modify: `app/Livewire/OperationList.php`
- Modify: `app/Livewire/OperationDetail.php`
- Modify: `app/Livewire/Dashboard.php`
- Modify: `app/Livewire/TypeOperationList.php`
- Modify: `app/Livewire/ParticipantTable.php`
- Modify: `app/Livewire/Parametres/Adhesions/FormulesList.php`
- Modify: `app/Livewire/Portail/NoteDeFrais/Show.php`
- Modify: `app/Livewire/BackOffice/NoteDeFrais/Show.php`
- Modify: `app/Livewire/Banques/HelloAssoSyncWizard.php`
- Modify: `app/Livewire/Parametres/HelloassoSyncConfig.php`
- Modify: `app/Http/Controllers/HelloAssoCallbackController.php`
- Modify: `app/Services/ProvisionService.php`
- Modify: `app/Models/HelloAssoParametres.php`
- Modify: `database/factories/HelloAssoParametresFactory.php`
- Modify: `app/Services/Compta/Migrations/SystemeSeeder.php`
- Modify: `app/Services/Compta/Migrations/BancairesSeeder.php`
- Modify: `app/Services/Compta/BackfillAuditor.php`
- Modify: `resources/views/components/operation-breadcrumb.blade.php`
- Modify: `resources/views/livewire/dashboard.blade.php`
- Modify: `resources/views/livewire/type-operation-list.blade.php`
- Modify: `resources/views/livewire/parametres/adhesions/formules-list.blade.php`
- Modify: `resources/views/livewire/banques/helloasso-sync-wizard.blade.php`
- Modify: `resources/views/livewire/back-office/note-de-frais/show.blade.php`
- Modify: `resources/views/livewire/portail/note-de-frais/show.blade.php`
- Test: `tests/Feature/Console/DumpTransactionCommandTest.php`
- Test: `tests/Feature/Console/SmokeTestV5CommandTest.php`
- Test: `tests/Feature/Console/SmokeTestV5SansPdTest.php`
- Test: `tests/Feature/Console/BackfillPartieDoubleCommandTest.php`
- Test: `tests/Feature/Commands/TenantBenchmarkCommandTest.php`
- Create: `tests/Feature/Console/ComptaCheckIntegrityCommandTest.php`
- Test: `tests/Feature/GestionOperationNavigationTest.php`
- Test: `tests/Feature/GestionOperationsTest.php`
- Test: `tests/Feature/Livewire/ParticipantTableTest.php`
- Test: `tests/Feature/TypeOperationTest.php`
- Test: `tests/Feature/Services/ProvisionServiceTest.php`
- Test: `tests/Feature/BackOffice/NoteDeFrais/ShowTest.php`
- Test: `tests/Livewire/Banques/HelloassoSyncWizardActionTest.php`
- Test: `tests/Feature/Lot4/HelloAssoSyncConfigTest.php`
- Test: `tests/Feature/HelloAssoCallbackTest.php`
- Test: `tests/Feature/Webhooks/HelloAssoCallbackTenantContextTest.php`

**Interfaces:**
- Consumes: relations `compte()` des modèles de ventilation et `Compte::usages()`.
- Produces: aucun eager-load, `whereHas`, fallback Blade ou commande ne dépend d'une relation supprimée.

- [ ] **Step 1: Exécuter les tests ciblés pour capturer les régressions**

Run: `./vendor/bin/sail artisan test tests/Feature/Console/DumpTransactionCommandTest.php tests/Feature/Console/SmokeTestV5CommandTest.php tests/Feature/Console/SmokeTestV5SansPdTest.php tests/Feature/Commands/TenantBenchmarkCommandTest.php tests/Feature/GestionOperationNavigationTest.php tests/Feature/GestionOperationsTest.php tests/Feature/Livewire/ParticipantTableTest.php tests/Feature/TypeOperationTest.php tests/Feature/Services/ProvisionServiceTest.php --stop-on-failure`

Expected: au moins un échec sur une relation ou un attribut legacy supprimé.

- [ ] **Step 2: Basculer chaque lecteur sur compte/famille**

Utiliser `typeOperation.compte`, `ligne.compte`, `TransactionLigne::whereHas('compte.usages', ...)` et les propriétés `compte_id`, `numero_pcg`, `intitule`. Les commandes de contrôle identifient une ventilation par les classes 6/7, et non par la présence d'une colonne supprimée.

`HelloAssoParametres` devient tenant-scopé. Le callback public résout donc le token chiffré sans scope uniquement pendant la phase pré-authentification, puis boote immédiatement le `TenantContext` correspondant avant toute lecture ou écriture tenant. Les écrans authentifiés utilisent exclusivement le scope tenant courant, sans ID d'association codé en dur.

- [ ] **Step 3: Vérifier les tests ciblés**

Run: `./vendor/bin/sail artisan test tests/Feature/Console/DumpTransactionCommandTest.php tests/Feature/Console/SmokeTestV5CommandTest.php tests/Feature/Console/SmokeTestV5SansPdTest.php tests/Feature/Commands/TenantBenchmarkCommandTest.php tests/Feature/Console/ComptaCheckIntegrityCommandTest.php tests/Feature/GestionOperationNavigationTest.php tests/Feature/GestionOperationsTest.php tests/Feature/Livewire/ParticipantTableTest.php tests/Feature/TypeOperationTest.php tests/Feature/Services/ProvisionServiceTest.php`

Expected: PASS.

---

### Task 3: Contrats de rapports, imports et vocabulaire

**Files:**
- Modify: `app/Services/ProvisionService.php`
- Modify: `app/Services/Rapports/CompteResultatBuilder.php`
- Modify: `app/Services/CsvImportService.php`
- Modify: `app/Http/Controllers/CsvImportController.php`
- Modify: `app/Livewire/TypeOperationShow.php`
- Modify: `resources/views/pdf/rapport-compte-resultat.blade.php`
- Modify: `resources/views/pdf/rapport-operations.blade.php`
- Modify: `resources/views/livewire/rapport-compte-resultat.blade.php`
- Modify: `resources/views/livewire/rapport-compte-resultat-operations.blade.php`
- Modify: `resources/views/livewire/facture-edit.blade.php`
- Modify: `resources/views/livewire/facture-edit/partials/ligne-manuelle-montant-form.blade.php`
- Modify: `resources/views/livewire/devis-manuel/devis-edit.blade.php`
- Modify: `resources/views/livewire/type-operation-show.blade.php`
- Test: `tests/Feature/RapportServiceAffectationTest.php`
- Test: `tests/Livewire/RapportCompteResultatTest.php`
- Test: `tests/Feature/CsvImportServiceTest.php`
- Test: `tests/Feature/CsvImportControllerTest.php`
- Test: `tests/Feature/Audit/CsvImportRefuseNegatifsTest.php`
- Test: `tests/Feature/TypeOperationTest.php`

**Interfaces:**
- Consumes: payloads compte-first des services.
- Produces: clés internes `compte_id`, `compte_nom`, `famille_nom`, sans vocabulaire comptable historique. Dans le CSV, `compte` désigne le compte comptable de ventilation et `compte_bancaire` le compte de trésorerie, afin d'éviter deux colonnes homonymes.

- [ ] **Step 1: Adapter d'abord les assertions des tests de rapports et imports**

Run: `./vendor/bin/sail artisan test tests/Feature/RapportServiceAffectationTest.php tests/Livewire/RapportCompteResultatTest.php tests/Feature/CsvImportServiceTest.php --stop-on-failure`

Expected: FAIL sur les anciennes clés ou l'ancien en-tête.

- [ ] **Step 2: Renommer les payloads et leurs consommateurs en une chaîne atomique**

Remplacer les alias SQL, clés de tableaux, index de projection et variables Blade ensemble afin qu'aucune frontière producteur/consommateur ne soit désynchronisée.

Le contrat CSV cible est `date;reference;compte;montant_ligne;mode_paiement;compte_bancaire;libelle;tiers;operation;seance;notes`.

- [ ] **Step 3: Vérifier les rapports et imports**

Run: `./vendor/bin/sail artisan test tests/Feature/RapportServiceAffectationTest.php tests/Livewire/RapportCompteResultatTest.php tests/Feature/CsvImportServiceTest.php`

Expected: PASS.

---

### Task 4: Conversion finale des tests et suppression du code legacy

**Files:**
- Modify or delete: `tests/Feature/Annulation/AnnulerFactureConcurrenceTest.php`
- Modify or delete: `tests/Feature/Annulation/AnnulerFactureHistoriqueTransactionFirstTest.php`
- Modify or delete: `tests/Feature/Annulation/AnnulerFactureMontantRefTest.php`
- Modify or delete: `tests/Feature/Annulation/AnnulerFactureMultiTenantTest.php`
- Modify or delete: `tests/Feature/Annulation/CompteResultatAnnulationTest.php`
- Modify or delete: `tests/Feature/Annulation/TransactionRattachableAFactureScopeTest.php`
- Modify or delete: `tests/Feature/CR/PartieDoubleEquivalenceTest.php`
- Modify or delete: `tests/Feature/Commands/AuditComptaV5PreparationTest.php`
- Modify or delete: `tests/Feature/Compta/CompteUsageComptableTest.php`
- Modify or delete: `tests/Feature/Compta/FamilleTest.php`
- Modify or delete: `tests/Feature/Console/BackfillPartieDoubleCommandTest.php`
- Modify or delete: `tests/Feature/Console/BackfillPartieDoubleEndToEndTest.php`
- Modify or delete: `tests/Feature/Console/DumpTransactionCommandTest.php`
- Modify or delete: `tests/Feature/Console/SmokeTestV5SansPdTest.php`
- Modify or delete: `tests/Feature/CreanceSaisieTest.php`
- Modify or delete: `tests/Feature/Database/FactureLibreSeederTest.php`
- Modify or delete: `tests/Feature/Database/UsagesSousCategoriesTableTest.php`
- Modify or delete: `tests/Feature/Extourne/AnnulerTransactionModalTest.php`
- Modify or delete: `tests/Feature/Extourne/CreancesARecevoirExclutExtournesTest.php`
- Modify or delete: `tests/Feature/Extourne/IndicateursListeTest.php`
- Modify or delete: `tests/Feature/Extourne/RapportsAvecExtourneTest.php`
- Modify or delete: `tests/Feature/Extourne/RapprochementListFiltreTypeTest.php`
- Modify or delete: `tests/Feature/Extourne/TransactionExtourneAtomiciteTest.php`
- Modify or delete: `tests/Feature/Extourne/TransactionExtourneDepenseTest.php`
- Modify or delete: `tests/Feature/Extourne/TransactionExtourneServiceEnAttenteTest.php`
- Modify or delete: `tests/Feature/Extourne/TransactionExtourneServiceGuardsTest.php`
- Modify or delete: `tests/Feature/Extourne/TransactionExtourneServiceRecuTest.php`
- Modify or delete: `tests/Feature/FactureManuel/AjouterLigneManuelleTest.php`
- Modify or delete: `tests/Feature/FactureManuel/IntrusionMultiTenantTest.php`
- Modify or delete: `tests/Feature/FactureManuel/ValiderGuardsTest.php`
- Modify or delete: `tests/Feature/Journal/JournalBackfillTest.php`
- Modify or delete: `tests/Feature/Livewire/CompteAutocompleteTest.php`
- Modify or delete: `tests/Feature/Livewire/Parametres/Comptabilite/UsagesComptablesTest.php`
- Modify or delete: `tests/Feature/Livewire/RapprochementDetail512XTest.php`
- Modify or delete: `tests/Feature/Migration/FactureLibreSchemaTest.php`
- Modify or delete: `tests/Feature/Migrations/AddPartieDoubleColumnsToTransactionLignesTest.php`
- Modify or delete: `tests/Feature/Migrations/BackfillCompteIdMigrationTest.php`
- Modify or delete: `tests/Feature/Migrations/CreateComptesTableTest.php`
- Modify or delete: `tests/Feature/Migrations/HelloAssoSousCategoriesSchemaTest.php`
- Modify or delete: `tests/Feature/Migrations/HelloAssoTransactionLignesSchemaTest.php`
- Modify or delete: `tests/Feature/Migrations/PerformanceIndexesMigrationTest.php`
- Modify or delete: `tests/Feature/Migrations/TransactionLignesHelloAssoOptionIdTest.php`
- Modify or delete: `tests/Feature/Models/CompteTest.php`
- Modify or delete: `tests/Feature/Models/SousCategorieUsagesTest.php`
- Modify or delete: `tests/Feature/Models/TransactionLigneObserverTest.php`
- Modify or delete: `tests/Feature/Models/UsageSousCategorieTest.php`
- Modify or delete: `tests/Feature/Multitenant/RawQueryTenantIsolationTest.php`
- Modify or delete: `tests/Feature/Onboarding/WizardTest.php`
- Modify or delete: `tests/Feature/Portail/NoteDeFrais/FormCreateTest.php`
- Modify or delete: `tests/Feature/Portail/NoteDeFrais/FormKmWizardTest.php`
- Modify or delete: `tests/Feature/RapportServiceAffectationTest.php`
- Modify or delete: `tests/Feature/Rappro/PartieDoubleEquivalenceTest.php`
- Modify or delete: `tests/Feature/Services/Compta/EcritureGeneratorPourReglementTest.php`
- Modify or delete: `tests/Feature/Services/Compta/EtatReglementResolverExtourneTest.php`
- Modify or delete: `tests/Feature/Services/Compta/TransactionConverterT2Test.php`
- Modify or delete: `tests/Feature/Services/DevisServiceLignesAvanceesTest.php`
- Modify or delete: `tests/Feature/Services/FactureServicePartieDoubleTest.php`
- Modify or delete: `tests/Feature/Services/HelloAsso/FxHelloAssoPartieDoubleTest.php`
- Modify or delete: `tests/Feature/Services/NoteDeFrais/ValiderAvecAbandonCreanceTest.php`
- Modify or delete: `tests/Feature/Services/Portail/NoteDeFraisServiceKmTest.php`
- Modify or delete: `tests/Feature/Services/RapprochementBancaireServicePartieDoubleTest.php`
- Modify or delete: `tests/Feature/Services/ReglementOperationServicePartieDoubleTest.php`
- Modify or delete: `tests/Feature/Services/TransactionExtourneServicePartieDoubleTest.php`
- Modify or delete: `tests/Feature/Services/TransactionServiceDeleteT2Test.php`
- Modify or delete: `tests/Feature/Services/TransactionServicePartieDoubleTest.php`
- Modify or delete: `tests/Feature/Services/TransactionServiceUpdatePartieDoubleTest.php`
- Modify or delete: `tests/Feature/Services/UsagesComptablesServiceTest.php`
- Modify or delete: `tests/Feature/Storage/TypeOperationStorageTest.php`
- Modify or delete: `tests/Feature/TransactionServiceGuardTest.php`
- Modify or delete: `tests/Feature/TypeOperationTest.php`
- Modify or delete: `tests/Livewire/Banques/HelloassoSyncWizardActionTest.php`
- Modify or delete: `tests/Livewire/BudgetTableTest.php`
- Modify or delete: `tests/Livewire/PlanComptableTest.php`
- Modify or delete: `tests/Support/CreatesPartieDoubleContext.php`
- Modify or delete: `tests/Unit/BudgetServiceTest.php`
- Modify or delete: `tests/Unit/CompteFactoryTest.php`
- Modify or delete: `tests/Unit/Enums/UsageComptableTest.php`
- Modify or delete: `tests/Unit/Services/Adhesion/CompteFormuleResolverTest.php`
- Modify or delete: `tests/Unit/Services/Adhesion/CreerDepuisTransactionAvecOptionsTest.php`
- Modify or delete: `tests/Unit/Services/Adhesion/ResolveFormuleHelloAssoTest.php`
- Modify or delete: `tests/Unit/Services/Compta/EcritureGeneratorPourRemiseBancaireTest.php`
- Modify or delete: `tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php`
- Modify or delete: `tests/Unit/Services/Compta/EcritureGeneratorSkeletonTest.php`
- Modify or delete: `tests/Unit/Services/Compta/LettrageServiceAutoDelettrerLignesTest.php`
- Modify or delete: `tests/Unit/Services/Compta/LettrageServiceDelettrerTest.php`
- Modify or delete: `tests/Unit/Services/Compta/LettrageServiceLettrerTest.php`
- Modify or delete: `tests/Unit/Services/Compta/PartieDoubleGuardTest.php`
- Modify or delete: `tests/Unit/Services/Rapports/CompteResultatBuilderPartieDoubleTest.php`
- Modify or delete: `tests/Unit/Services/RecuFiscal/ResoudreLigneCotisationTest.php`
- Delete: `app/Models/Categorie.php`
- Delete: `app/Models/SousCategorie.php`
- Delete: `app/Models/UsageSousCategorie.php`
- Delete: `database/factories/CategorieFactory.php`
- Delete: `database/factories/SousCategorieFactory.php`
- Delete: `database/factories/UsageSousCategorieFactory.php`

**Interfaces:**
- Consumes: `CompteFactory`, `UsageCompteFactory` et schéma final.
- Produces: fixtures compte-first ; aucun test métier ne dépend des modèles ou colonnes supprimés.

- [ ] **Step 1: Produire l'inventaire exact et classer les tests**

Conserver les tests métier en les réécrivant avec `Compte::factory()` ; supprimer uniquement les tests dont l'unique objet était un pont ou un schéma désormais supprimé ; convertir les tests de migration historiques afin qu'ils testent le rejeu avant le drop sans appeler les modèles retirés.

- [ ] **Step 2: Exécuter la suite par domaine après chaque lot**

Run successivement :

```bash
./vendor/bin/sail artisan test tests/Feature/Models tests/Unit/Models
./vendor/bin/sail artisan test tests/Feature/Services tests/Unit/Services
./vendor/bin/sail artisan test tests/Feature/Livewire tests/Livewire
./vendor/bin/sail artisan test tests/Feature/Console tests/Feature/Migrations tests/Feature/Database
```

Expected: chaque lot termine avec 0 failure avant de passer au suivant.

- [ ] **Step 3: Vérifier AC1**

Run: `rg -n 'SousCategorie|sous_categorie|sous-catégorie|sous-categories|sous_categories' app resources/views`

Expected: aucune sortie.

---

### Task 5: Répétition MySQL et gates finaux

**Files:**
- Modify: `database/schema/mysql-schema.sql`
- Modify: `plans/2026-07-10-dissolution-dc10b-drop.md`
- Modify: `docs/specs/2026-07-07-dissolution-sous-categories-comptes.md` uniquement si une précision factuelle de cutover est requise.

**Interfaces:**
- Consumes: application et migrations compte-first validées sur SQLite.
- Produces: schéma MySQL final, recette reproductible et preuves AC1 à AC8.

- [ ] **Step 1: Répéter la migration sur une base MySQL jetable**

Créer une base temporaire distincte de `svs_accounting`, charger le schéma pré-drop, exécuter les migrations du 12 juillet et vérifier les FK/index via `information_schema`.

- [ ] **Step 2: Vérifier le clone avant migration**

Les contrôles doivent retourner zéro rattachement non résolu, zéro doublon d'usage et zéro doublon d'encadrement. Ne lancer la migration du clone qu'après la répétition MySQL et une sauvegarde/recréation explicite du clone.

- [ ] **Step 3: Exécuter les gates finaux**

```bash
./vendor/bin/pint --test
./vendor/bin/sail artisan test
./vendor/bin/sail artisan compta:smoke-test-v5
rg -n 'SousCategorie|sous_categorie|sous-catégorie|sous-categories|sous_categories' app resources/views
```

Expected: Pint exit 0, suite Pest 0 failure, smoke-test exit 0, `rg` sans sortie.
