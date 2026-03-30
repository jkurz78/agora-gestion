# Suppression du champ `code` (Operation + TypeOperation) — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Supprimer le champ `code` redondant des modèles `Operation` et `TypeOperation`, et utiliser `nom` partout à la place.

**Architecture:** Mise à jour de tous les modèles, factories, seeders, vues Blade, composants Livewire, requests de validation et tests pour retirer toute référence à `code`. Les `orderBy('code')` deviennent `orderBy('nom')`. Migration en dernier pour supprimer les colonnes.

**Tech Stack:** Laravel 11, Livewire 4, Pest PHP, MySQL

**Note de déploiement:** Les changements de code (Tasks 1-8) doivent être déployés AVANT la migration (Task 9). En dev, exécuter `migrate:fresh --seed` après toutes les tâches.

---

### Task 1: Modèles et Factories — retirer `code`

**Files:**
- Modify: `app/Models/Operation.php:20` — retirer `'code'` du `$fillable`
- Modify: `app/Models/TypeOperation.php:18` — retirer `'code'` du `$fillable`
- Modify: `database/factories/OperationFactory.php:25` — retirer la ligne `'code'`
- Modify: `database/factories/TypeOperationFactory.php:21` — retirer la ligne `'code'`

- [ ] **Step 1: Retirer `code` de `Operation::$fillable`**

Dans `app/Models/Operation.php`, retirer `'code',` (ligne 20) du tableau `$fillable`.

- [ ] **Step 2: Retirer `code` de `TypeOperation::$fillable`**

Dans `app/Models/TypeOperation.php`, retirer `'code',` (ligne 18) du tableau `$fillable`.

- [ ] **Step 3: Retirer `code` de `OperationFactory`**

Dans `database/factories/OperationFactory.php`, retirer la ligne :
```php
'code' => fake()->unique()->lexify('OP-????'),
```

- [ ] **Step 4: Retirer `code` de `TypeOperationFactory`**

Dans `database/factories/TypeOperationFactory.php`, retirer la ligne :
```php
'code' => fake()->unique()->bothify('OP-####'),
```

- [ ] **Step 5: Commit**

```bash
git add app/Models/Operation.php app/Models/TypeOperation.php database/factories/OperationFactory.php database/factories/TypeOperationFactory.php
git commit -m "refactor: remove code field from Operation and TypeOperation models and factories"
```

---

### Task 2: Seeders et Validation Requests — retirer `code`

**Files:**
- Modify: `database/seeders/TypeOperationSeeder.php` — changer `firstOrCreate` key et retirer `code`
- Modify: `database/seeders/OperationsTiersSeeder.php` — changer lookup et retirer `code`
- Modify: `app/Http/Requests/StoreOperationRequest.php:22` — supprimer la règle `'code'`
- Modify: `app/Http/Requests/UpdateOperationRequest.php:24` — supprimer la règle `'code'`

- [ ] **Step 1: Modifier TypeOperationSeeder**

Remplacer les `firstOrCreate` qui utilisent `['code' => 'PSA']` par `['nom' => 'Parcours de soins A']`. Idem pour `FORM` → `['nom' => 'Formation']`.

- [ ] **Step 2: Modifier OperationsTiersSeeder**

Remplacer `TypeOperation::where('code', 'PSA')` par `TypeOperation::where('nom', 'Parcours de soins A')`. Idem pour `'FORM'` → `'Formation'`. Retirer `'code' => 'PARC1'` et `'code' => 'PARC2'` des données d'opérations.

- [ ] **Step 3: Retirer `code` de StoreOperationRequest**

Supprimer la ligne :
```php
'code' => ['required', 'string', 'max:50', 'unique:operations,code'],
```

- [ ] **Step 4: Retirer `code` de UpdateOperationRequest**

Supprimer la ligne :
```php
'code' => ['required', 'string', 'max:50', Rule::unique('operations', 'code')->ignore($this->route('operation'))],
```

Note: `Rule` est encore utilisé pour `statut` et `type_operation_id`, donc l'import reste nécessaire.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/TypeOperationSeeder.php database/seeders/OperationsTiersSeeder.php app/Http/Requests/StoreOperationRequest.php app/Http/Requests/UpdateOperationRequest.php
git commit -m "refactor: remove code from seeders and validation requests"
```

---

### Task 3: Livewire TypeOperationManager — retirer `code`

**Files:**
- Modify: `app/Livewire/TypeOperationManager.php`
- Modify: `resources/views/livewire/type-operation-manager.blade.php`

- [ ] **Step 1: Modifier le composant PHP**

Dans `app/Livewire/TypeOperationManager.php` :
1. Supprimer `public string $code = '';` (ligne 40)
2. Ligne 124 : remplacer `->orderBy('code')` par `->orderBy('nom')`
3. Ligne 152 : supprimer `$this->code = $type->code;`
4. Ligne 203 : supprimer la règle `'code' => 'required|string|max:20|unique:type_operations,code'...`
5. Ligne 230 : supprimer `'code' => $this->code,` du tableau `$data`
6. Ligne 323 : supprimer la validation `'code'` dans `nextTab()`
7. Ligne 555 : supprimer `$this->code = '';` dans `resetForm()`

- [ ] **Step 2: Modifier la vue Blade**

Dans `resources/views/livewire/type-operation-manager.blade.php` :
1. Supprimer la colonne "Code" du `<thead>` (ligne 31)
2. Supprimer le `<td>` code du `<tbody>` (ligne 55)
3. Lignes 132-133 : remplacer `{{ $code }}{{ $code && $nom ? ' — ' : '' }}{{ $nom }}` par `{{ $nom }}`
4. Supprimer le champ input `wire:model="code"` avec son label et message d'erreur (autour de la ligne 166)

- [ ] **Step 3: Commit**

```bash
git add app/Livewire/TypeOperationManager.php resources/views/livewire/type-operation-manager.blade.php
git commit -m "refactor: remove code field from TypeOperationManager"
```

---

### Task 4: Livewire — tous les autres composants PHP

**Files:**
- Modify: `app/Livewire/TransactionList.php:142` — `orderBy('code')` → `orderBy('nom')`
- Modify: `app/Livewire/TransactionForm.php:363` — `orderBy('code')` → `orderBy('nom')`
- Modify: `app/Livewire/RemiseBancaireSelection.php:84` — `sortBy('code')` → `sortBy('nom')`
- Modify: `app/Livewire/RapportSeances.php:65` — `orderBy('code')` → `orderBy('nom')`
- Modify: `app/Livewire/RapportCompteResultatOperations.php:56` — `orderBy('code')` → `orderBy('nom')`
- Modify: `app/Livewire/Banques/HelloassoSyncWizard.php` — retirer `$newOperationCode`, validation, usage, et `orderBy('code')` → `orderBy('nom')`

- [ ] **Step 1: TransactionList**

Ligne 142 : remplacer `orderBy('code')` par `orderBy('nom')`.

- [ ] **Step 2: TransactionForm**

Ligne 363 : remplacer `orderBy('code')` par `orderBy('nom')`.

- [ ] **Step 3: RemiseBancaireSelection**

Ligne 84 : remplacer `sortBy('code')` par `sortBy('nom')`.

- [ ] **Step 4: RapportSeances**

Ligne 65 : remplacer `TypeOperation::actif()->orderBy('code')` par `TypeOperation::actif()->orderBy('nom')`.

- [ ] **Step 5: RapportCompteResultatOperations**

Ligne 56 : remplacer `TypeOperation::actif()->orderBy('code')` par `TypeOperation::actif()->orderBy('nom')`.

- [ ] **Step 6: HelloassoSyncWizard**

Dans `app/Livewire/Banques/HelloassoSyncWizard.php` :
1. Supprimer `public string $newOperationCode = '';` (ligne 81)
2. Ligne 170 : retirer `'newOperationCode'` du `$this->reset(...)`
3. Ligne 176 : supprimer la validation `'newOperationCode' => 'required|string|max:50|unique:operations,code'`
4. Ligne 184 : supprimer `'code' => $this->newOperationCode,`
5. Ligne 504 : remplacer `orderBy('code')` par `orderBy('nom')`

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/TransactionList.php app/Livewire/TransactionForm.php app/Livewire/RemiseBancaireSelection.php app/Livewire/RapportSeances.php app/Livewire/RapportCompteResultatOperations.php app/Livewire/Banques/HelloassoSyncWizard.php
git commit -m "refactor: replace orderBy/sortBy code with nom in all Livewire components"
```

---

### Task 5: Vues Blade — opérations (index, create, edit)

**Files:**
- Modify: `resources/views/operations/index.blade.php`
- Modify: `resources/views/operations/create.blade.php`
- Modify: `resources/views/operations/edit.blade.php`

- [ ] **Step 1: operations/index.blade.php**

1. Supprimer la colonne "Code" du `<thead>` (ligne 47)
2. Supprimer le `<td>{{ $operation->code }}</td>` (ligne 60)
3. Dans le filtre type (ligne 30) : remplacer `{{ $type->code }} — {{ $type->nom }}` par `{{ $type->nom }}`
4. Ajuster `colspan="8"` → `colspan="7"` (ligne 88)
5. Décaler les `data-col` et `opsSortIcon` indices (Nom devient col 0, Type col 1, etc.)
6. Ajuster `var numCols = 7;` → `var numCols = 6;`

- [ ] **Step 2: operations/create.blade.php**

1. Supprimer `data-code="{{ $type->code }}"` des `<option>` (ligne 19)
2. Remplacer `{{ $type->code }} — {{ $type->nom }}` par `{{ $type->nom }}` (ligne 21)
3. Supprimer tout le bloc "Code type (read-only) + Code opération" (lignes 35-51)
4. Dans le JS, supprimer les lignes `data-code` / `type_code_display` (lignes 120-121, 125)

- [ ] **Step 3: operations/edit.blade.php**

1. Supprimer `data-code="{{ $type->code }}"` des `<option>` (ligne 28)
2. Remplacer `{{ $type->code }} — {{ $type->nom }}` par `{{ $type->nom }}` (ligne 30)
3. Supprimer tout le bloc "Code type (read-only) + Code opération" (lignes 49-65)
4. Dans le JS, supprimer les lignes `data-code` / `type_code_display` (lignes 149, 153)

- [ ] **Step 4: Commit**

```bash
git add resources/views/operations/index.blade.php resources/views/operations/create.blade.php resources/views/operations/edit.blade.php
git commit -m "refactor: remove code field from operation views"
```

---

### Task 6: Vues Blade — toutes les vues Livewire

**Files:**
- Modify: `resources/views/livewire/gestion-operations.blade.php:27` — `$op->code` → `$op->nom`
- Modify: `resources/views/livewire/gestion-dashboard.blade.php:38` — `$op->code` → `$op->nom`
- Modify: `resources/views/livewire/transaction-list.blade.php:57` — `$op->code` → `$op->nom`
- Modify: `resources/views/livewire/transaction-form.blade.php:161,262` — `$op->code` → `$op->nom`
- Modify: `resources/views/livewire/remise-bancaire-selection.blade.php:30` — `$operation->code` → `$operation->nom`
- Modify: `resources/views/livewire/rapport-compte-resultat-operations.blade.php:10,19` — remplacer code par nom
- Modify: `resources/views/livewire/rapport-seances.blade.php:10,19` — remplacer code par nom
- Modify: `resources/views/livewire/banques/helloasso-sync-wizard.blade.php:118,137-141` — remplacer code par nom et supprimer champ Code

- [ ] **Step 1: gestion-operations.blade.php**

Ligne 27 : remplacer `{{ $op->code }} ({{ $op->date_debut...` par `{{ $op->nom }} ({{ $op->date_debut...`

- [ ] **Step 2: gestion-dashboard.blade.php**

Ligne 38 : remplacer `{{ $op->code }}` par `{{ $op->nom }}`

- [ ] **Step 3: transaction-list.blade.php**

Ligne 57 : remplacer `{{ $op->code }}` par `{{ $op->nom }}`

- [ ] **Step 4: transaction-form.blade.php**

Lignes 161 et 262 : remplacer `{{ $op->code }}` par `{{ $op->nom }}`

- [ ] **Step 5: remise-bancaire-selection.blade.php**

Ligne 30 : remplacer `{{ $operation->code }}` par `{{ $operation->nom }}`

- [ ] **Step 6: rapport-compte-resultat-operations.blade.php**

1. Ligne 10 : remplacer `{{ $type->code }} — {{ $type->nom }}` par `{{ $type->nom }}`
2. Ligne 19 : remplacer `{{ $op->code }}` par `{{ $op->nom }}`

- [ ] **Step 7: rapport-seances.blade.php**

1. Ligne 10 : remplacer `{{ $type->code }} — {{ $type->nom }}` par `{{ $type->nom }}`
2. Ligne 19 : remplacer `{{ $op->code }}` par `{{ $op->nom }}`

- [ ] **Step 8: helloasso-sync-wizard.blade.php**

1. Ligne 118 : remplacer `{{ $op->code }}` par `{{ $op->nom }}`
2. Supprimer le bloc du champ "Code *" dans le formulaire inline (lignes 137-141) — le label + input `wire:model="newOperationCode"` + erreur

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/gestion-operations.blade.php resources/views/livewire/gestion-dashboard.blade.php resources/views/livewire/transaction-list.blade.php resources/views/livewire/transaction-form.blade.php resources/views/livewire/remise-bancaire-selection.blade.php resources/views/livewire/rapport-compte-resultat-operations.blade.php resources/views/livewire/rapport-seances.blade.php resources/views/livewire/banques/helloasso-sync-wizard.blade.php
git commit -m "refactor: replace code with nom in all Livewire views"
```

---

### Task 7: Tests — adapter au retrait de `code`

**Files:**
- Modify: `tests/Feature/OperationTest.php` — retirer `'code'` des payloads
- Modify: `tests/Feature/OperationValidationTest.php` — retirer `'code'` des payloads
- Modify: `tests/Feature/TypeOperationTest.php` — retirer les `set('code', ...)`, `where('code', ...)`, `assertHasErrors(['code'])`
- Modify: `tests/Feature/GestionOperationsTest.php` — retirer `'code'`, ajuster `assertSee`
- Modify: `tests/Livewire/RapportSeancesTest.php` — retirer `'code'`, ajuster assertions
- Modify: `tests/Livewire/RapportCompteResultatOperationsTest.php` — retirer `'code'`, ajuster assertions
- Modify: `tests/Livewire/RapportOperationsExerciceTest.php` — retirer `'code'`, ajuster assertions
- Modify: `tests/Livewire/TransactionFormOperationsFilterTest.php` — retirer `'code'`, ajuster assertions

- [ ] **Step 1: OperationTest.php**

1. Ligne 32 : retirer `'code' => 'FETE-2025',` du payload store
2. Ligne 97 : retirer `'code' => $operation->code,` du payload update

- [ ] **Step 2: OperationValidationTest.php**

Ligne 33 : retirer `'code' => 'TEST-2025',` du payload.

- [ ] **Step 3: TypeOperationTest.php**

1. Ligne 31 : retirer `->assertSee($type->code)`
2. Ligne 37 : retirer `->set('code', 'YOGA')`
3. Lignes 53-54 : remplacer `TypeOperation::where('code', 'YOGA')` par `TypeOperation::where('nom', 'Yoga thérapeutique')`
4. Ligne 66 : retirer `->set('code', '')`
5. Ligne 70 : changer `assertHasErrors(['code', 'nom', 'sous_categorie_id'])` en `assertHasErrors(['nom', 'sous_categorie_id'])`
6. Ligne 76 : retirer `'code' => 'OLD',`
7. Lignes 82-83 : retirer `->set('code', 'NEW')`
8. Ligne 87 : retirer `expect($type->code)->toBe('NEW');`
9. Ligne 149 : retirer `->set('code', 'LOGO')`
10. Ligne 155 : remplacer `TypeOperation::where('code', 'LOGO')` par `TypeOperation::where('nom', 'Test logo')`
11. Lignes 194-195 : retirer `'code' => 'DUPL',`
12. Ligne 200 : retirer `->set('code', 'DUPL')`
13. Ligne 204 : changer `assertHasErrors(['code', 'nom'])` en `assertHasErrors(['nom'])`

- [ ] **Step 4: GestionOperationsTest.php**

1. Ligne 18 : retirer `'code' => 'ART-TEST',`
2. Ligne 21 : remplacer `assertSee('ART-TEST')` par `assertSee('Art-thérapie test')`

- [ ] **Step 5: RapportSeancesTest.php**

1. Ligne 23 : retirer `'code' => 'FEST',`
2. Ligne 24 : retirer `'code' => 'INVIS',`
3. Ligne 27 : remplacer `assertSee('FEST')` par `assertSee('Festival')`
4. Ligne 28 : remplacer `assertDontSee('INVIS')` par `assertDontSee('Invisible')`

- [ ] **Step 6: RapportCompteResultatOperationsTest.php**

1. Ligne 23 : retirer `'code' => 'FEST-ETE',`
2. Ligne 26 : remplacer `assertSee('FEST-ETE')` par `assertSee('Festival été')`

- [ ] **Step 7: RapportOperationsExerciceTest.php**

1. Ligne 20 : retirer `'code' => 'OP-HORS',`
2. Ligne 28 : remplacer `assertDontSee('OP-HORS')` par `assertDontSee('Op hors exercice')`
3. Ligne 33 : retirer `'code' => 'OP-CLOT-VIS',`
4. Ligne 41 : remplacer `assertSee('OP-CLOT-VIS')` par `assertSee('Op clôturée visible')`

- [ ] **Step 8: TransactionFormOperationsFilterTest.php**

1. Ligne 34 : retirer `'code' => 'OP-COURANTE',`
2. Ligne 43 : remplacer `assertSee('OP-COURANTE')` par `assertSee('Op courante')`

- [ ] **Step 9: Lancer tous les tests**

Run: `./vendor/bin/sail test`
Expected: Tous les tests passent.

- [ ] **Step 10: Commit**

```bash
git add tests/Feature/OperationTest.php tests/Feature/OperationValidationTest.php tests/Feature/TypeOperationTest.php tests/Feature/GestionOperationsTest.php tests/Livewire/RapportSeancesTest.php tests/Livewire/RapportCompteResultatOperationsTest.php tests/Livewire/RapportOperationsExerciceTest.php tests/Livewire/TransactionFormOperationsFilterTest.php
git commit -m "test: update all tests to remove code field references"
```

---

### Task 8: Migration — supprimer les colonnes `code`

**Files:**
- Create: `database/migrations/2026_03_30_200000_drop_code_from_operations_and_type_operations.php`

- [ ] **Step 1: Créer la migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('type_operations', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });

        Schema::table('operations', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }

    public function down(): void
    {
        Schema::table('type_operations', function (Blueprint $table) {
            $table->string('code', 20)->unique()->after('id');
        });

        Schema::table('operations', function (Blueprint $table) {
            $table->string('code', 50)->unique()->after('id');
        });
    }
};
```

- [ ] **Step 2: Commit**

```bash
git add database/migrations/2026_03_30_200000_drop_code_from_operations_and_type_operations.php
git commit -m "feat: migration to drop code column from operations and type_operations"
```

---

### Task 9: Vérification finale

- [ ] **Step 1: migrate:fresh --seed**

Run: `./vendor/bin/sail artisan migrate:fresh --seed`
Expected: Pas d'erreur.

- [ ] **Step 2: Lancer la suite de tests complète**

Run: `./vendor/bin/sail test`
Expected: Tous les tests passent.

- [ ] **Step 3: Recherche exhaustive de `code` résiduel**

Run: `grep -rn "'code'" --include="*.php" --include="*.blade.php" app/ resources/ database/ tests/ | grep -v "code_postal\|zip_code\|postal_code\|error_code\|status_code\|verification_code\|unicode\|encode\|decode\|{code}\|<code"`

S'assurer qu'il ne reste aucune référence à l'ancien champ `code`.

- [ ] **Step 4: Vider le cache des vues**

Run: `./vendor/bin/sail artisan view:clear`

- [ ] **Step 5: Lancer Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 6: Commit final si corrections Pint**

```bash
git add -A
git commit -m "chore: cleanup after removing code field"
```
