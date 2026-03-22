# Lot 1 — Évolutions de schéma HelloAsso Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter les colonnes et enums nécessaires à l'intégration HelloAsso et à l'unification du modèle, sans modifier le comportement existant.

**Architecture:** Migrations additives (ajout de colonnes nullable), extension d'enum, ajout d'un flag booléen sur `sous_categories`, et validation conditionnelle (inscription → opération obligatoire). Aucun écran ni service existant n'est modifié fonctionnellement.

**Tech Stack:** Laravel 11, Pest PHP, MySQL (Docker/Sail)

**Spec:** `docs/superpowers/specs/2026-03-22-helloasso-integration-design.md`

---

### Task 1: Migration — colonnes HelloAsso sur `transactions`

**Files:**
- Create: `database/migrations/2026_03_22_100001_add_helloasso_columns_to_transactions.php`
- Modify: `app/Models/Transaction.php`
- Test: `tests/Feature/Migrations/HelloAssoSchemaTest.php`

- [ ] **Step 1: Write the failing test**

```php
// tests/Feature/Migrations/HelloAssoSchemaTest.php
<?php

declare(strict_types=1);

use App\Models\Transaction;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('transactions table has helloasso_order_id column', function () {
    expect(Schema::hasColumn('transactions', 'helloasso_order_id'))->toBeTrue();
});

it('transactions table has helloasso_cashout_id column', function () {
    expect(Schema::hasColumn('transactions', 'helloasso_cashout_id'))->toBeTrue();
});

it('can store helloasso_order_id and helloasso_cashout_id on a transaction', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();
    $sc = SousCategorie::factory()->create();

    $transaction = Transaction::create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'libelle' => 'Test HA',
        'montant_total' => '50.00',
        'mode_paiement' => 'cb',
        'compte_id' => $compte->id,
        'saisi_par' => $user->id,
        'reference' => 'HA-001',
        'helloasso_order_id' => 12345,
        'helloasso_cashout_id' => 678,
    ]);

    $transaction->refresh();
    expect($transaction->helloasso_order_id)->toBe(12345)
        ->and($transaction->helloasso_cashout_id)->toBe(678);
});

it('helloasso columns are nullable on transactions', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();

    $transaction = Transaction::create([
        'type' => 'depense',
        'date' => '2025-10-15',
        'libelle' => 'Sans HA',
        'montant_total' => '30.00',
        'mode_paiement' => 'virement',
        'compte_id' => $compte->id,
        'saisi_par' => $user->id,
        'reference' => 'REF-001',
    ]);

    $transaction->refresh();
    expect($transaction->helloasso_order_id)->toBeNull()
        ->and($transaction->helloasso_cashout_id)->toBeNull();
});

it('unique composite index on helloasso_order_id + tiers_id', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();
    $tiers = \App\Models\Tiers::factory()->create();

    Transaction::create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => '50.00',
        'mode_paiement' => 'cb',
        'compte_id' => $compte->id,
        'saisi_par' => $user->id,
        'reference' => 'REF-001',
        'tiers_id' => $tiers->id,
        'helloasso_order_id' => 99999,
    ]);

    expect(fn () => Transaction::create([
        'type' => 'recette',
        'date' => '2025-10-16',
        'montant_total' => '60.00',
        'mode_paiement' => 'cb',
        'compte_id' => $compte->id,
        'saisi_par' => $user->id,
        'reference' => 'REF-002',
        'tiers_id' => $tiers->id,
        'helloasso_order_id' => 99999,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Migrations/HelloAssoSchemaTest.php`
Expected: FAIL — columns do not exist

- [ ] **Step 3: Create the migration**

```php
// database/migrations/2026_03_22_100001_add_helloasso_columns_to_transactions.php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('helloasso_order_id')->nullable()->after('numero_piece');
            $table->unsignedBigInteger('helloasso_cashout_id')->nullable()->index()->after('helloasso_order_id');

            $table->unique(['helloasso_order_id', 'tiers_id'], 'transactions_ha_order_tiers_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_ha_order_tiers_unique');
            $table->dropColumn(['helloasso_order_id', 'helloasso_cashout_id']);
        });
    }
};
```

- [ ] **Step 4: Update the Transaction model — add to fillable and casts**

In `app/Models/Transaction.php`, add `'helloasso_order_id'` and `'helloasso_cashout_id'` to `$fillable`, and add casts:
```php
'helloasso_order_id' => 'integer',
'helloasso_cashout_id' => 'integer',
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Migrations/HelloAssoSchemaTest.php`
Expected: PASS (all 5 tests)

- [ ] **Step 6: Run full test suite to confirm no regression**

Run: `./vendor/bin/sail test`
Expected: all existing tests pass

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_03_22_100001_add_helloasso_columns_to_transactions.php \
  app/Models/Transaction.php \
  tests/Feature/Migrations/HelloAssoSchemaTest.php
git commit -m "feat(schema): add helloasso_order_id and helloasso_cashout_id to transactions"
```

---

### Task 2: Migration — colonnes HelloAsso sur `transaction_lignes`

**Files:**
- Create: `database/migrations/2026_03_22_100002_add_helloasso_columns_to_transaction_lignes.php`
- Modify: `app/Models/TransactionLigne.php`
- Test: `tests/Feature/Migrations/HelloAssoSchemaTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Migrations/HelloAssoSchemaTest.php`:

```php
it('transaction_lignes table has helloasso_item_id column', function () {
    expect(Schema::hasColumn('transaction_lignes', 'helloasso_item_id'))->toBeTrue();
});

it('transaction_lignes table has exercice column', function () {
    expect(Schema::hasColumn('transaction_lignes', 'exercice'))->toBeTrue();
});

it('can store helloasso_item_id and exercice on a transaction ligne', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();
    $sc = SousCategorie::factory()->create();

    $transaction = Transaction::create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => '30.00',
        'mode_paiement' => 'cb',
        'compte_id' => $compte->id,
        'saisi_par' => $user->id,
        'reference' => 'REF-003',
    ]);

    $ligne = \App\Models\TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sc->id,
        'montant' => '30.00',
        'helloasso_item_id' => 54321,
        'exercice' => 2025,
    ]);

    $ligne->refresh();
    expect($ligne->helloasso_item_id)->toBe(54321)
        ->and($ligne->exercice)->toBe(2025);
});

it('helloasso_item_id is unique on transaction_lignes', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();
    $sc = SousCategorie::factory()->create();

    $transaction = Transaction::create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => '60.00',
        'mode_paiement' => 'cb',
        'compte_id' => $compte->id,
        'saisi_par' => $user->id,
        'reference' => 'REF-004',
    ]);

    \App\Models\TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sc->id,
        'montant' => '30.00',
        'helloasso_item_id' => 11111,
    ]);

    expect(fn () => \App\Models\TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sc->id,
        'montant' => '30.00',
        'helloasso_item_id' => 11111,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Migrations/HelloAssoSchemaTest.php`
Expected: new tests FAIL

- [ ] **Step 3: Create the migration**

```php
// database/migrations/2026_03_22_100002_add_helloasso_columns_to_transaction_lignes.php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table) {
            $table->unsignedBigInteger('helloasso_item_id')->nullable()->unique()->after('notes');
            $table->unsignedInteger('exercice')->nullable()->after('helloasso_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table) {
            $table->dropColumn(['helloasso_item_id', 'exercice']);
        });
    }
};
```

- [ ] **Step 4: Update the TransactionLigne model — add to fillable and casts**

In `app/Models/TransactionLigne.php`, add `'helloasso_item_id'` and `'exercice'` to `$fillable`, and add casts:
```php
'helloasso_item_id' => 'integer',
'exercice' => 'integer',
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Migrations/HelloAssoSchemaTest.php`
Expected: PASS (all 9 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_03_22_100002_add_helloasso_columns_to_transaction_lignes.php \
  app/Models/TransactionLigne.php \
  tests/Feature/Migrations/HelloAssoSchemaTest.php
git commit -m "feat(schema): add helloasso_item_id and exercice to transaction_lignes"
```

---

### Task 3: Migration — colonne HelloAsso sur `virements_internes`

**Files:**
- Create: `database/migrations/2026_03_22_100003_add_helloasso_cashout_id_to_virements_internes.php`
- Modify: `app/Models/VirementInterne.php`
- Test: `tests/Feature/Migrations/HelloAssoSchemaTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Migrations/HelloAssoSchemaTest.php`:

```php
it('virements_internes table has helloasso_cashout_id column', function () {
    expect(Schema::hasColumn('virements_internes', 'helloasso_cashout_id'))->toBeTrue();
});

it('can store helloasso_cashout_id on a virement interne', function () {
    $user = User::factory()->create();
    $source = CompteBancaire::factory()->create();
    $dest = CompteBancaire::factory()->create();

    $virement = \App\Models\VirementInterne::create([
        'date' => '2025-10-15',
        'montant' => '500.00',
        'compte_source_id' => $source->id,
        'compte_destination_id' => $dest->id,
        'saisi_par' => $user->id,
        'helloasso_cashout_id' => 456,
    ]);

    $virement->refresh();
    expect($virement->helloasso_cashout_id)->toBe(456);
});

it('helloasso_cashout_id is unique on virements_internes', function () {
    $user = User::factory()->create();
    $source = CompteBancaire::factory()->create();
    $dest = CompteBancaire::factory()->create();

    \App\Models\VirementInterne::create([
        'date' => '2025-10-15',
        'montant' => '500.00',
        'compte_source_id' => $source->id,
        'compte_destination_id' => $dest->id,
        'saisi_par' => $user->id,
        'helloasso_cashout_id' => 789,
    ]);

    expect(fn () => \App\Models\VirementInterne::create([
        'date' => '2025-10-16',
        'montant' => '300.00',
        'compte_source_id' => $source->id,
        'compte_destination_id' => $dest->id,
        'saisi_par' => $user->id,
        'helloasso_cashout_id' => 789,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Migrations/HelloAssoSchemaTest.php`
Expected: new tests FAIL

- [ ] **Step 3: Create the migration**

```php
// database/migrations/2026_03_22_100003_add_helloasso_cashout_id_to_virements_internes.php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('virements_internes', function (Blueprint $table) {
            $table->unsignedBigInteger('helloasso_cashout_id')->nullable()->unique()->after('numero_piece');
        });
    }

    public function down(): void
    {
        Schema::table('virements_internes', function (Blueprint $table) {
            $table->dropColumn('helloasso_cashout_id');
        });
    }
};
```

- [ ] **Step 4: Update the VirementInterne model — add to fillable and casts**

In `app/Models/VirementInterne.php`, add `'helloasso_cashout_id'` to `$fillable`, and add cast:
```php
'helloasso_cashout_id' => 'integer',
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Migrations/HelloAssoSchemaTest.php`
Expected: PASS (all 12 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_03_22_100003_add_helloasso_cashout_id_to_virements_internes.php \
  app/Models/VirementInterne.php \
  tests/Feature/Migrations/HelloAssoSchemaTest.php
git commit -m "feat(schema): add helloasso_cashout_id to virements_internes"
```

---

### Task 4: Migration — `pour_inscriptions` sur `sous_categories`

**Files:**
- Create: `database/migrations/2026_03_22_100004_add_pour_inscriptions_to_sous_categories.php`
- Modify: `app/Models/SousCategorie.php`
- Test: `tests/Feature/Migrations/HelloAssoSchemaTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Migrations/HelloAssoSchemaTest.php`:

```php
it('sous_categories table has pour_inscriptions column', function () {
    expect(Schema::hasColumn('sous_categories', 'pour_inscriptions'))->toBeTrue();
});

it('pour_inscriptions defaults to false', function () {
    $categorie = \App\Models\Categorie::factory()->create();

    $sc = SousCategorie::create([
        'categorie_id' => $categorie->id,
        'nom' => 'Test SC',
        'code_cerfa' => '999',
    ]);

    $sc->refresh();
    expect($sc->pour_inscriptions)->toBeFalse();
});

it('can set pour_inscriptions to true', function () {
    $categorie = \App\Models\Categorie::factory()->create();

    $sc = SousCategorie::create([
        'categorie_id' => $categorie->id,
        'nom' => 'Inscription stage',
        'code_cerfa' => '706',
        'pour_inscriptions' => true,
    ]);

    $sc->refresh();
    expect($sc->pour_inscriptions)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Migrations/HelloAssoSchemaTest.php`
Expected: new tests FAIL

- [ ] **Step 3: Create the migration**

```php
// database/migrations/2026_03_22_100004_add_pour_inscriptions_to_sous_categories.php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sous_categories', function (Blueprint $table) {
            $table->boolean('pour_inscriptions')->default(false)->after('pour_cotisations');
        });
    }

    public function down(): void
    {
        Schema::table('sous_categories', function (Blueprint $table) {
            $table->dropColumn('pour_inscriptions');
        });
    }
};
```

- [ ] **Step 4: Update the SousCategorie model — add to fillable and casts**

In `app/Models/SousCategorie.php`, add `'pour_inscriptions'` to `$fillable`, and add cast:
```php
'pour_inscriptions' => 'boolean',
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Migrations/HelloAssoSchemaTest.php`
Expected: PASS (all 15 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_03_22_100004_add_pour_inscriptions_to_sous_categories.php \
  app/Models/SousCategorie.php \
  tests/Feature/Migrations/HelloAssoSchemaTest.php
git commit -m "feat(schema): add pour_inscriptions flag to sous_categories"
```

---

### Task 5: Enum — ajouter `helloasso` à `ModePaiement`

**Files:**
- Modify: `app/Enums/ModePaiement.php`
- Modify: `app/Livewire/TransactionForm.php` (validation rule)
- Test: `tests/Feature/Migrations/HelloAssoSchemaTest.php` (append)

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Migrations/HelloAssoSchemaTest.php`:

```php
use App\Enums\ModePaiement;

it('ModePaiement enum has helloasso case', function () {
    $ha = ModePaiement::HelloAsso;
    expect($ha->value)->toBe('helloasso')
        ->and($ha->label())->toBe('HelloAsso');
});

it('can create a transaction with mode_paiement helloasso', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();

    $transaction = Transaction::create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => '25.00',
        'mode_paiement' => 'helloasso',
        'compte_id' => $compte->id,
        'saisi_par' => $user->id,
        'reference' => 'HA-REF',
    ]);

    $transaction->refresh();
    expect($transaction->mode_paiement)->toBe(ModePaiement::HelloAsso);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Migrations/HelloAssoSchemaTest.php`
Expected: FAIL — ModePaiement::HelloAsso does not exist

- [ ] **Step 3: Add the enum case**

In `app/Enums/ModePaiement.php`, add the case and its label:

```php
case HelloAsso = 'helloasso';
```

And in the `label()` method:
```php
self::HelloAsso => 'HelloAsso',
```

- [ ] **Step 4: Update TransactionForm validation**

In `app/Livewire/TransactionForm.php`, find the validation rule for `mode_paiement` and add `helloasso`:

```php
'mode_paiement' => ['required', 'in:virement,cheque,especes,cb,prelevement,helloasso'],
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Migrations/HelloAssoSchemaTest.php`
Expected: PASS (all 17 tests)

- [ ] **Step 6: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: all tests pass (existing validation tests must not break)

- [ ] **Step 7: Commit**

```bash
git add app/Enums/ModePaiement.php \
  app/Livewire/TransactionForm.php \
  tests/Feature/Migrations/HelloAssoSchemaTest.php
git commit -m "feat(enum): add HelloAsso to ModePaiement"
```

---

### Task 6: Validation conditionnelle — inscription → opération obligatoire

**Files:**
- Modify: `app/Services/TransactionService.php`
- Modify: `app/Livewire/TransactionForm.php`
- Test: `tests/Feature/TransactionInscriptionValidationTest.php`

- [ ] **Step 1: Write the failing tests**

```php
// tests/Feature/TransactionInscriptionValidationTest.php
<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\Categorie;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(TransactionService::class);
    $this->compte = CompteBancaire::factory()->create();

    $categorie = Categorie::factory()->create(['type' => 'recette']);
    $this->scInscription = SousCategorie::create([
        'categorie_id' => $categorie->id,
        'nom' => 'Inscription stage',
        'code_cerfa' => '706',
        'pour_inscriptions' => true,
    ]);
    $this->scDon = SousCategorie::create([
        'categorie_id' => $categorie->id,
        'nom' => 'Don manuel',
        'code_cerfa' => '754',
        'pour_dons' => true,
    ]);
});

it('refuses a transaction ligne with inscription sous-categorie without operation_id', function () {
    $data = [
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => '50.00',
        'mode_paiement' => 'cb',
        'compte_id' => $this->compte->id,
        'reference' => 'REF-INS',
    ];
    $lignes = [[
        'sous_categorie_id' => $this->scInscription->id,
        'montant' => '50.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    expect(fn () => $this->service->create($data, $lignes))
        ->toThrow(\InvalidArgumentException::class, 'operation_id');
});

it('accepts a transaction ligne with inscription sous-categorie with operation_id', function () {
    $operation = Operation::factory()->create();

    $data = [
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => '50.00',
        'mode_paiement' => 'cb',
        'compte_id' => $this->compte->id,
        'reference' => 'REF-INS-OK',
    ];
    $lignes = [[
        'sous_categorie_id' => $this->scInscription->id,
        'montant' => '50.00',
        'operation_id' => $operation->id,
        'seance' => null,
        'notes' => null,
    ]];

    $transaction = $this->service->create($data, $lignes);
    expect($transaction->lignes()->count())->toBe(1)
        ->and($transaction->lignes->first()->operation_id)->toBe($operation->id);
});

it('does not require operation_id for non-inscription sous-categorie', function () {
    $data = [
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => '50.00',
        'mode_paiement' => 'cb',
        'compte_id' => $this->compte->id,
        'reference' => 'REF-DON',
    ];
    $lignes = [[
        'sous_categorie_id' => $this->scDon->id,
        'montant' => '50.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    $transaction = $this->service->create($data, $lignes);
    expect($transaction->lignes()->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/TransactionInscriptionValidationTest.php`
Expected: FAIL — the first test does not throw

- [ ] **Step 3: Add validation in TransactionService::create()**

In `app/Services/TransactionService.php`, in the `create()` method, after the `DB::transaction()` opens and before creating lignes, add:

```php
$this->validateInscriptionRequiresOperation($lignes);
```

Add this private method to `TransactionService`:

```php
private function validateInscriptionRequiresOperation(array $lignes): void
{
    $inscriptionSousCategorieIds = SousCategorie::where('pour_inscriptions', true)
        ->pluck('id')
        ->toArray();

    foreach ($lignes as $index => $ligne) {
        if (in_array((int) $ligne['sous_categorie_id'], $inscriptionSousCategorieIds, true)
            && empty($ligne['operation_id'])) {
            throw new \InvalidArgumentException(
                "La ligne {$index} utilise une sous-catégorie d'inscription : operation_id est obligatoire."
            );
        }
    }
}
```

Also call `$this->validateInscriptionRequiresOperation($lignes)` in the `update()` method, before recreating lignes.

Add the import at the top of the file:
```php
use App\Models\SousCategorie;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/TransactionInscriptionValidationTest.php`
Expected: PASS (all 3 tests)

- [ ] **Step 5: Add Livewire validation in TransactionForm**

In `app/Livewire/TransactionForm.php`, in the `save()` method, after existing validation and before calling `$this->service->create()` or `$this->service->update()`, add:

```php
// Validate inscription sous-catégories require an operation
$inscriptionIds = SousCategorie::where('pour_inscriptions', true)->pluck('id')->toArray();
foreach ($this->lignes as $index => $ligne) {
    if (in_array((int) ($ligne['sous_categorie_id'] ?? 0), $inscriptionIds, true)
        && empty($ligne['operation_id'])) {
        $this->addError("lignes.{$index}.operation_id", "L'opération est obligatoire pour une inscription.");
        return;
    }
}
```

Add the import if not already present:
```php
use App\Models\SousCategorie;
```

- [ ] **Step 6: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: all tests pass

- [ ] **Step 7: Commit**

```bash
git add app/Services/TransactionService.php \
  app/Livewire/TransactionForm.php \
  tests/Feature/TransactionInscriptionValidationTest.php
git commit -m "feat(validation): inscription sous-categorie requires operation_id"
```

---

### Task 7: Vérification finale et tag

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: all tests pass, zero failures

- [ ] **Step 2: Run Pint (PSR-12 formatting)**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`
Expected: code formatted, no errors

- [ ] **Step 3: Commit formatting changes if any**

```bash
git add -A
git commit -m "style: pint formatting"
```

- [ ] **Step 4: Verify migration can run fresh**

Run: `./vendor/bin/sail artisan migrate:fresh --seed`
Expected: all migrations run, seeders execute without errors
