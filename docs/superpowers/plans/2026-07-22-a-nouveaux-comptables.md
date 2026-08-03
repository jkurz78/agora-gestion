# À-nouveaux comptables Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Générer, invalider et reprendre des écritures d'à-nouveaux équilibrées, auditables et compatibles avec le lettrage auxiliaire et le rapprochement bancaire.

**Architecture:** Une génération tenant-scopée produit une pièce AN unique au premier jour de N+1. Un builder pur calcule l'aperçu, un service transactionnel le persiste avec la clôture, et une table de filiation permet aux règlements N+1 de viser les postes 401/411 reportés sans perdre le lien métier d'origine.

**Tech Stack:** Laravel 11, PHP 8.3, Eloquent, Livewire 4, MySQL/SQLite, Pest PHP, Bootstrap 5 CDN.

## Global Constraints

- `declare(strict_types=1)`, classes finales et signatures typées.
- Tous les libellés, validations et erreurs sont en français.
- Toute donnée comptable est tenant-scopée et fail-closed via `TenantContext`.
- Les calculs monétaires utilisent des chaînes décimales et BCMath ; aucun calcul métier nouveau en `float`.
- Les écritures AN ne portent ni classe 6/7, ni `operation_id`, ni séance, ni `transaction_ligne_affectations`.
- Les AN sont de vraies écritures : `journal = an`, `type_ecriture = an`, `type = an`.
- Une clôture, sa génération AN et son audit sont atomiques.
- Une réouverture invalide immédiatement les AN par soft-delete sans supprimer l'historique de génération.
- La reprise initiale exige un dry-run et une confirmation explicite.
- Les modifications utilisateur déjà présentes dans la branche ne doivent pas être incluses dans les commits du chantier.

---

## Structure des fichiers

- `app/Enums/OrigineANouveau.php`, `StatutANouveau.php` : valeurs persistées de génération.
- `app/Models/ANouveauGeneration.php`, `ANouveauLigneOrigine.php` : audit et filiation des postes auxiliaires.
- `app/Services/Compta/ANouveau/ANouveauPreview.php`, `ANouveauPreviewBuilder.php` : calcul sans écriture.
- `app/Services/Compta/ANouveau/ANouveauService.php` : création/invalidation atomique.
- `app/Services/Compta/ANouveau/PosteReporteResolver.php` : résolution du descendant 401/411 actif.
- `app/Console/Commands/BootstrapANouveauCommand.php` : reprise initiale contrôlée.
- `app/Livewire/Exercices/ClotureWizard.php`, `ReouvrirExercice.php` : orchestration UI.

---

### Task 1: Schéma, enums et comptes système

**Files:**
- Create: `database/migrations/2026_07_22_100001_add_an_journal_and_type.php`
- Create: `database/migrations/2026_07_22_100002_create_a_nouveau_generations_tables.php`
- Create: `database/migrations/2026_07_22_100003_seed_a_nouveau_system_accounts.php`
- Create: `app/Enums/OrigineANouveau.php`
- Create: `app/Enums/StatutANouveau.php`
- Create: `app/Models/ANouveauGeneration.php`
- Create: `app/Models/ANouveauLigneOrigine.php`
- Modify: `app/Enums/JournalComptable.php`
- Modify: `app/Enums/TypeTransaction.php`
- Modify: `app/Models/Transaction.php`
- Test: `tests/Feature/Compta/ANouveau/ANouveauSchemaTest.php`

**Interfaces:**
- Produces: `ANouveauGeneration::activePourCible(int $annee): ?self`.
- Produces: relations `generation->transaction`, `generation->origines`, `origine->ligneAN`, `origine->ligneSource`, `origine->ligneRacine`.

- [ ] **Step 1: Écrire le test rouge du schéma et des casts**

```php
it('supporte une génération AN tenant-scopée avec filiation auxiliaire', function (): void {
    expect(JournalComptable::AN->value)->toBe('an')
        ->and(TypeTransaction::AN->value)->toBe('an');

    foreach (['102', '120', '129'] as $numero) {
        expect(Compte::ofNumero($numero))->not->toBeNull();
    }

    $generation = ANouveauGeneration::create([
        'exercice_source' => 2024,
        'exercice_cible' => 2025,
        'origine' => OrigineANouveau::Cloture,
        'statut' => StatutANouveau::Active,
    ]);

    expect($generation->association_id)->toBe((int) TenantContext::currentId());
});
```

- [ ] **Step 2: Vérifier l'échec**

Run: `./vendor/bin/sail artisan test tests/Feature/Compta/ANouveau/ANouveauSchemaTest.php`

Expected: FAIL car enums, tables et modèles n'existent pas.

- [ ] **Step 3: Implémenter le schéma minimal**

Créer `a_nouveau_generations` avec `association_id`, exercices source/cible, `transaction_id` nullable, origine, statut, auteurs et horodatages d'invalidation. Créer `a_nouveau_ligne_origines` avec `association_id`, `generation_id`, `ligne_an_id`, `ligne_source_id`, `ligne_racine_id`. Ajouter index et clés étrangères, sans cascade destructive sur les lignes comptables.

Étendre les enums ainsi :

```php
case AN = 'an';
```

Pour `Transaction::sensTresorerie()`, refuser explicitement un AN :

```php
TypeTransaction::AN => throw new LogicException('Une écriture AN n’a pas de sens de trésorerie opérationnel.'),
```

Étendre l'ENUM MySQL `journal` à `('vente','achat','banque','od','an')` et provisionner 102, 120, 129 avec `SystemeSeeder::unconditionalSql(..., lettrable: false)`.

- [ ] **Step 4: Vérifier le vert**

Run: `./vendor/bin/sail artisan test tests/Feature/Compta/ANouveau/ANouveauSchemaTest.php tests/Unit/Enums/JournalComptableTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Enums app/Models/ANouveauGeneration.php app/Models/ANouveauLigneOrigine.php app/Models/Transaction.php database/migrations tests/Feature/Compta/ANouveau/ANouveauSchemaTest.php
git commit -m "feat(compta-v5): pose les fondations des a-nouveaux"
```

### Task 2: Builder d'aperçu AN

**Files:**
- Create: `app/Services/Compta/ANouveau/ANouveauPreview.php`
- Create: `app/Services/Compta/ANouveau/ANouveauPreviewBuilder.php`
- Create: `app/Exceptions/Compta/ANouveauInvalideException.php`
- Test: `tests/Unit/Services/Compta/ANouveau/ANouveauPreviewBuilderTest.php`
- Test: `tests/Feature/Services/Compta/ANouveau/ANouveauPreviewTenantTest.php`

**Interfaces:**
- Produces: `ANouveauPreviewBuilder::build(int $exerciceSource): ANouveauPreview`.
- Produces: `ANouveauPreview` avec `exerciceSource`, `exerciceCible`, `dateCible`, `lignes`, `totalDebit`, `totalCredit`, `equilibree()`.
- Chaque ligne est un tableau `{compte_id, numero_pcg, debit, credit, tiers_id, libelle, source_ligne_id, racine_ligne_id}`.

- [ ] **Step 1: Écrire les tests rouges de calcul**

Créer un jeu équilibré avec 512 débiteur, 401 non lettré, 411 lettré, charges 6 et produits 7. Asserter :

```php
$preview = app(ANouveauPreviewBuilder::class)->build(2025);

expect($preview->equilibree())->toBeTrue()
    ->and(collect($preview->lignes)->pluck('numero_pcg'))->toContain('401', '5121', '120')
    ->not->toContain('606', '706')
    ->and(collect($preview->lignes)->firstWhere('numero_pcg', '401')['tiers_id'])->toBe($fournisseur->id)
    ->and(collect($preview->lignes)->firstWhere('numero_pcg', '401')['operation_id'] ?? null)->toBeNull();
```

Ajouter les cas déficit→129, deux postes 401 opposés malgré solde global nul, ligne 401 sans tiers refusée, tenant voisin exclu et calcul au centime exact.

- [ ] **Step 2: Vérifier les échecs**

Run: `./vendor/bin/sail artisan test tests/Unit/Services/Compta/ANouveau/ANouveauPreviewBuilderTest.php tests/Feature/Services/Compta/ANouveau/ANouveauPreviewTenantTest.php`

Expected: FAIL car builder absent.

- [ ] **Step 3: Implémenter le builder**

Lire uniquement les transactions de N, y compris l'AN d'ouverture de N, et agréger `SUM(debit-credit)` par compte pour les classes 1 à 5. Pour 401/411, sélectionner les lignes actives non lettrées de N, poste par poste. Calculer le résultat par `SUM(credit-debit)` des classes 6/7 et produire 120 ou 129. Utiliser `bcadd`, `bcsub` et `bccomp` à l'échelle 2.

- [ ] **Step 4: Vérifier le vert**

Run: `./vendor/bin/sail artisan test tests/Unit/Services/Compta/ANouveau/ANouveauPreviewBuilderTest.php tests/Feature/Services/Compta/ANouveau/ANouveauPreviewTenantTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Compta/ANouveau app/Exceptions/Compta/ANouveauInvalideException.php tests/Unit/Services/Compta/ANouveau tests/Feature/Services/Compta/ANouveau
git commit -m "feat(compta-v5): calcule l apercu des a-nouveaux"
```

### Task 3: Persistance atomique et invalidation

**Files:**
- Create: `app/Services/Compta/ANouveau/ANouveauService.php`
- Test: `tests/Feature/Services/Compta/ANouveau/ANouveauServiceTest.php`

**Interfaces:**
- Consumes: un `ANouveauPreview` déjà calculé et équilibré.
- Produces: `ANouveauService::persister(ANouveauPreview $preview, OrigineANouveau $origine, User $acteur): ANouveauGeneration`.
- Produces: `ANouveauService::invalider(Exercice $source, User $acteur, string $motif): void`.

- [ ] **Step 1: Écrire les tests rouges de génération**

```php
$preview = app(ANouveauPreviewBuilder::class)->build((int) $exercice->annee);
$generation = app(ANouveauService::class)->persister(
    $preview,
    OrigineANouveau::Cloture,
    $user,
);
$transaction = $generation->transaction()->with('lignes')->firstOrFail();

expect($transaction->date->toDateString())->toBe('2026-09-01')
    ->and($transaction->journal)->toBe(JournalComptable::AN)
    ->and($transaction->type_ecriture)->toBe('an')
    ->and($transaction->type)->toBe(TypeTransaction::AN)
    ->and($transaction->equilibree)->toBeTrue()
    ->and($transaction->lignes()->sum('debit'))->toEqual($transaction->lignes()->sum('credit'));
```

Tester aussi idempotence, filiation 401/411, rollback sur aperçu invalide, invalidation soft-delete et conservation de l'enregistrement de génération.

- [ ] **Step 2: Vérifier les échecs**

Run: `./vendor/bin/sail artisan test tests/Feature/Services/Compta/ANouveau/ANouveauServiceTest.php`

Expected: FAIL car service absent.

- [ ] **Step 3: Implémenter le service**

Dans `DB::transaction()`, verrouiller l'exercice et l'éventuelle génération active, créer l'en-tête AN, les lignes sans opération/séance, les origines auxiliaires, recharger les totaux et refuser tout écart. L'invalidation met `statut=invalidee`, renseigne auteur/date/motif, puis soft-delete les lignes et la transaction.

- [ ] **Step 4: Vérifier le vert**

Run: `./vendor/bin/sail artisan test tests/Feature/Services/Compta/ANouveau/ANouveauServiceTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Compta/ANouveau/ANouveauService.php tests/Feature/Services/Compta/ANouveau/ANouveauServiceTest.php
git commit -m "feat(compta-v5): genere et invalide les pieces AN"
```

### Task 4: Continuité des postes 401/411

**Files:**
- Create: `app/Services/Compta/ANouveau/PosteReporteResolver.php`
- Modify: `app/Services/Compta/EcritureGenerator.php`
- Modify: `app/Services/ReglementOperationService.php`
- Modify: `app/Services/Compta/EtatReglementResolver.php`
- Test: `tests/Feature/Services/Compta/ANouveau/PosteReporteReglementTest.php`

**Interfaces:**
- Produces: `PosteReporteResolver::pourTransaction(Transaction $transaction, CarbonInterface $date): ?TransactionLigne`.
- Produces: `PosteReporteResolver::depuisLigne(TransactionLigne $ligne, CarbonInterface $date): TransactionLigne`.

- [ ] **Step 1: Écrire le test rouge de règlement inter-exercices**

Créer une créance 411 ouverte en N, générer AN N+1, puis l'encaisser en N+1. Asserter que la ligne AN est lettrée avec la ligne de règlement, que la ligne source N n'est pas utilisée par le nouveau lettrage, et que `EtatReglementResolver` retourne `Recu` pour la transaction métier d'origine.

- [ ] **Step 2: Vérifier l'échec**

Run: `./vendor/bin/sail artisan test tests/Feature/Services/Compta/ANouveau/PosteReporteReglementTest.php`

Expected: FAIL car les générateurs ciblent encore directement la ligne du T1.

- [ ] **Step 3: Implémenter le resolver et brancher les consommateurs**

Résoudre la racine de la ligne, puis chercher la dernière origine appartenant à une génération active dont l'exercice cible contient la date de règlement. Modifier les trois chemins de règlement de `EcritureGenerator`, `ReglementOperationService::reglerOuEncaisser()`, `trouverT2()` et `EtatReglementResolver` pour utiliser cette ligne active.

- [ ] **Step 4: Vérifier le vert et les régressions lettrage**

Run: `./vendor/bin/sail artisan test tests/Feature/Services/Compta/ANouveau/PosteReporteReglementTest.php --filter="AN|report" && ./vendor/bin/sail artisan test --filter="Lettrage|EtatReglement|ReglementOperation"`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Compta/ANouveau/PosteReporteResolver.php app/Services/Compta/EcritureGenerator.php app/Services/ReglementOperationService.php app/Services/Compta/EtatReglementResolver.php tests/Feature/Services/Compta/ANouveau/PosteReporteReglementTest.php
git commit -m "feat(compta-v5): conserve le lettrage des postes reportes"
```

### Task 5: Soldes bancaires exercice-aware

**Files:**
- Modify: `app/Services/SoldeService.php`
- Modify: `app/Services/RapprochementBancaireService.php`
- Modify: `app/Livewire/RapprochementDetail.php`
- Test: `tests/Feature/Services/Compta/ANouveau/ANouveauSoldeBancaireTest.php`

**Interfaces:**
- Produces: `SoldeService::solde(CompteBancaire $compte, ?int $exercice = null, ?CarbonInterface $date = null): float`.

- [ ] **Step 1: Écrire les tests rouges bancaires**

Tester qu'avec une génération active, le solde vaut ligne AN 512 + mouvements de l'exercice, sans `solde_initial` ni mouvements N. Tester qu'une opération N non pointée reste candidate en N+1 et que la transaction AN n'est jamais pointable.

- [ ] **Step 2: Vérifier l'échec**

Run: `./vendor/bin/sail artisan test tests/Feature/Services/Compta/ANouveau/ANouveauSoldeBancaireTest.php`

Expected: FAIL par double comptage historique.

- [ ] **Step 3: Implémenter le chemin AN avec fallback legacy**

Si une génération active existe pour l'exercice demandé, retrouver le compte PCG via `compte_bancaire_id` et sommer `tl.debit-tl.credit` uniquement sur la plage début d'exercice→date. Sinon conserver exactement le calcul historique basé sur `solde_initial`. Exclure explicitement `journal=an` des requêtes de candidats au pointage.

- [ ] **Step 4: Vérifier le vert et les régressions rapprochement**

Run: `./vendor/bin/sail artisan test tests/Feature/Services/Compta/ANouveau/ANouveauSoldeBancaireTest.php --filter=AN && ./vendor/bin/sail artisan test --filter="Rapprochement|SoldeService"`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/SoldeService.php app/Services/RapprochementBancaireService.php app/Livewire/RapprochementDetail.php tests/Feature/Services/Compta/ANouveau/ANouveauSoldeBancaireTest.php
git commit -m "feat(compta-v5): calcule les soldes bancaires depuis les AN"
```

### Task 6: Clôture et réouverture atomiques

**Files:**
- Modify: `app/Services/ExerciceService.php`
- Modify: `app/Services/ClotureCheckService.php`
- Modify: `app/Livewire/Exercices/ClotureWizard.php`
- Modify: `resources/views/livewire/exercices/cloture-wizard.blade.php`
- Modify: `app/Livewire/Exercices/ReouvrirExercice.php`
- Modify: `resources/views/livewire/exercices/reouvrir-exercice.blade.php`
- Test: `tests/Feature/Livewire/Exercices/ANouveauClotureTest.php`
- Test: `tests/Feature/Livewire/Exercices/ANouveauReouvertureTest.php`

**Interfaces:**
- Modifie: `ExerciceService::cloturer()` génère AN avant de changer le statut.
- Modifie: `ExerciceService::reouvrir()` invalide AN avant de rouvrir.

- [ ] **Step 1: Écrire les tests Livewire rouges**

Tester aperçu visible, totaux équilibrés, détail tiers, confirmation, clôture+AN atomiques, avertissement si N+1 contient des mouvements, blocage si N+1 est clôturé, message de réouverture et invalidation effective.

- [ ] **Step 2: Vérifier les échecs**

Run: `./vendor/bin/sail artisan test tests/Feature/Livewire/Exercices/ANouveauClotureTest.php tests/Feature/Livewire/Exercices/ANouveauReouvertureTest.php`

Expected: FAIL car l'assistant ne connaît pas les AN.

- [ ] **Step 3: Implémenter l'intégration**

Injecter `ANouveauPreviewBuilder` et `ANouveauService` dans `ExerciceService`. Dans la transaction de clôture, construire l'aperçu puis appeler `ANouveauService::persister($preview, OrigineANouveau::Cloture, $user)` avant de modifier le statut. Ajouter les contrôles bloquants : grand livre équilibré, 401/411 ouverts avec tiers, génération active existante, exercice cible clôturé. Présenter dans l'étape récapitulative les lignes AN regroupées et les totaux. Ajouter à la réouverture la conséquence explicite d'invalidation.

- [ ] **Step 4: Vérifier le vert**

Run: `./vendor/bin/sail artisan test tests/Feature/Livewire/Exercices/ANouveauClotureTest.php tests/Feature/Livewire/Exercices/ANouveauReouvertureTest.php tests/Feature/Livewire/ExerciceClotureLivewireTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ExerciceService.php app/Services/ClotureCheckService.php app/Livewire/Exercices resources/views/livewire/exercices tests/Feature/Livewire/Exercices
git commit -m "feat(compta-v5): integre les AN a la cloture annuelle"
```

### Task 7: Reprise initiale contrôlée

**Files:**
- Create: `app/Services/Compta/ANouveau/BootstrapANouveauService.php`
- Create: `app/Console/Commands/BootstrapANouveauCommand.php`
- Test: `tests/Feature/Console/BootstrapANouveauCommandTest.php`

**Interfaces:**
- Produces: `BootstrapANouveauService::preview(int $exerciceCible, array $arbitrages = []): ANouveauPreview`.
- Produces: commande `compta:bootstrap-an {--association=} {--acteur=} {--exercice=} {--dry-run} {--confirmer} {--meme-jour=inclus|exclus}`.

- [ ] **Step 1: Écrire les tests rouges du bootstrap**

Tester que `--dry-run` n'écrit rien, que l'absence de `--association` est refusée, que `--acteur` est obligatoire en confirmation et doit appartenir au tenant, que l'absence de `--confirmer` refuse la persistance, que deux soldes initiaux 512 sont compensés sur 102, que les 401/411 ouverts antérieurs sont repris, que le même jour solde initial+ENL exige `--meme-jour`, et qu'un second run est refusé.

- [ ] **Step 2: Vérifier les échecs**

Run: `./vendor/bin/sail artisan test tests/Feature/Console/BootstrapANouveauCommandTest.php`

Expected: FAIL car commande absente.

- [ ] **Step 3: Implémenter le service et la commande**

Résoudre l'association demandée, booter `TenantContext`, puis résoudre l'acteur et vérifier son appartenance à cette association. Produire le même DTO d'aperçu que la clôture. Calculer le montant bancaire proposé à la date d'ouverture selon l'arbitrage `inclus|exclus`, ajouter 102 comme contrepartie patrimoniale, afficher un tableau par compte bancaire, puis appeler `ANouveauService::persister($preview, OrigineANouveau::RepriseInitiale, $user)`.

- [ ] **Step 4: Vérifier le vert**

Run: `./vendor/bin/sail artisan test tests/Feature/Console/BootstrapANouveauCommandTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Compta/ANouveau/BootstrapANouveauService.php app/Console/Commands/BootstrapANouveauCommand.php tests/Feature/Console/BootstrapANouveauCommandTest.php
git commit -m "feat(compta-v5): ajoute la reprise initiale des AN"
```

### Task 8: Signalement d'ouverture indisponible et blocage N+1

**Files:**
- Modify: `app/Services/ClotureCheckService.php`
- Modify: `resources/views/livewire/exercices/cloture-wizard.blade.php`
- Modify: `resources/views/layouts/app-sidebar.blade.php`
- Test: `tests/Feature/Livewire/Exercices/ANouveauIndisponibleTest.php`

**Interfaces:**
- Produces: contrôle `AN de l'exercice précédent` dans `ClotureCheckResult`.

- [ ] **Step 1: Écrire le test rouge**

Réouvrir N après génération, afficher N+1, asserter le bandeau « Soldes d'ouverture indisponibles » et l'impossibilité de clôturer N+1. Reclôturer N et vérifier la disparition du bandeau.

- [ ] **Step 2: Vérifier l'échec**

Run: `./vendor/bin/sail artisan test tests/Feature/Livewire/Exercices/ANouveauIndisponibleTest.php`

Expected: FAIL car aucun signalement n'existe.

- [ ] **Step 3: Implémenter le contrôle et le bandeau**

Détecter un exercice précédent existant mais ouvert ou une génération invalidée sans remplaçante active. Ajouter un `CheckItem` bloquant et un bandeau limité aux routes comptabilité/rapports/exercices.

- [ ] **Step 4: Vérifier le vert**

Run: `./vendor/bin/sail artisan test tests/Feature/Livewire/Exercices/ANouveauIndisponibleTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ClotureCheckService.php resources/views/livewire/exercices/cloture-wizard.blade.php resources/views/layouts/app-sidebar.blade.php tests/Feature/Livewire/Exercices/ANouveauIndisponibleTest.php
git commit -m "feat(compta-v5): signale les ouvertures comptables indisponibles"
```

### Task 9: Vérification globale et documentation opératoire

**Files:**
- Modify: `docs/compta-partie-double.md`
- Create: `docs/runbooks/2026-07-22-reprise-initiale-a-nouveaux.md`
- Modify: `docs/recette/2026-07-recette-fonctionnelle-v5.md`

**Interfaces:**
- Documente la commande de dry-run, l'arbitrage ENL du 31 août 2025, la confirmation et le rollback.

- [ ] **Step 1: Exécuter les tests ciblés complets**

Run: `./vendor/bin/sail artisan test --filter="ANouveau|A-nouveau|a-nouveau|Cloture|Reouverture|Lettrage|EtatReglement|SoldeService|Rapprochement"`

Expected: 0 failure.

- [ ] **Step 2: Exécuter la suite complète**

Run: `./vendor/bin/sail artisan test`

Expected: 0 failure.

- [ ] **Step 3: Formater et vérifier le style**

Run: `./vendor/bin/pint app/Enums app/Models app/Services/Compta/ANouveau app/Services/ExerciceService.php app/Services/SoldeService.php app/Console/Commands/BootstrapANouveauCommand.php tests/Feature/Compta/ANouveau tests/Feature/Services/Compta/ANouveau tests/Feature/Console/BootstrapANouveauCommandTest.php`

Puis : `./vendor/bin/pint --test`

Expected: aucun fichier à corriger au second passage.

- [ ] **Step 4: Exécuter le dry-run local**

Run: `./vendor/bin/sail artisan compta:bootstrap-an --association=1 --exercice=2025 --dry-run`

Expected: affiche les 5121/5122, détecte les ENL du 31 août 2025, n'écrit aucune génération et demande l'arbitrage même-jour.

- [ ] **Step 5: Documenter sans exécuter la reprise réelle**

Le runbook doit indiquer les commandes exactes :

```bash
./vendor/bin/sail artisan compta:bootstrap-an --association=1 --exercice=2025 --dry-run --meme-jour=inclus
./vendor/bin/sail artisan compta:bootstrap-an --association=1 --acteur=admin@monasso.fr --exercice=2025 --confirmer --meme-jour=inclus
```

La seconde commande reste une action de production explicitement déclenchée par l'utilisateur ; elle n'est pas exécutée automatiquement pendant le développement.

- [ ] **Step 6: Commit**

```bash
git add docs/compta-partie-double.md docs/runbooks/2026-07-22-reprise-initiale-a-nouveaux.md docs/recette/2026-07-recette-fonctionnelle-v5.md
git commit -m "docs(compta-v5): documente la reprise des a-nouveaux"
```
