# G.2 — Garde bloquante non-échappement PD — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Empêcher toute transaction d'être créée/modifiée sans écritures PD équilibrées quand `config('compta.use_partie_double') = true`.

**Architecture:** `PartieDoubleGuard::assertComplete($tx)` appelé dans chaque service après la génération PD. Les 2 services qui oubliaient `equilibree = true` sont corrigés. Commande artisan `compta:assert-pd-complete` en filet de sécurité.

**Tech Stack:** Laravel 11, Pest PHP, MySQL

**Spec:** `docs/specs/2026-06-17-garde-bloquante-pd-g2.md`

---

### Task 1: Exception PartieDoubleIncompleteException

**Files:**
- Create: `app/Exceptions/Compta/PartieDoubleIncompleteException.php`
- Test: `tests/Unit/Exceptions/Compta/PartieDoubleIncompleteExceptionTest.php`

- [ ] **Step 1: Write the exception class**

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

final class PartieDoubleIncompleteException extends \RuntimeException
{
    public static function nonEquilibree(int $transactionId): self
    {
        return new self("Transaction #{$transactionId} : mode PD actif mais equilibree=false.");
    }

    public static function sansLignes(int $transactionId): self
    {
        return new self("Transaction #{$transactionId} : mode PD actif mais aucune ligne comptable (compte_id).");
    }

    public static function desequilibree(int $transactionId, string $debit, string $credit): self
    {
        return new self("Transaction #{$transactionId} : PD déséquilibrée (debit={$debit}, credit={$credit}).");
    }
}
```

- [ ] **Step 2: Write unit tests**

```php
<?php

declare(strict_types=1);

use App\Exceptions\Compta\PartieDoubleIncompleteException;

it('creates nonEquilibree exception with transaction id', function () {
    $e = PartieDoubleIncompleteException::nonEquilibree(42);
    expect($e)->toBeInstanceOf(\RuntimeException::class)
        ->and($e->getMessage())->toContain('#42')
        ->and($e->getMessage())->toContain('equilibree=false');
});

it('creates sansLignes exception with transaction id', function () {
    $e = PartieDoubleIncompleteException::sansLignes(42);
    expect($e->getMessage())->toContain('#42')
        ->and($e->getMessage())->toContain('aucune ligne comptable');
});

it('creates desequilibree exception with amounts', function () {
    $e = PartieDoubleIncompleteException::desequilibree(42, '100.00', '80.00');
    expect($e->getMessage())->toContain('#42')
        ->and($e->getMessage())->toContain('100.00')
        ->and($e->getMessage())->toContain('80.00');
});
```

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --filter=PartieDoubleIncompleteExceptionTest`
Expected: 3 PASS

- [ ] **Step 4: Commit**

```bash
git add app/Exceptions/Compta/PartieDoubleIncompleteException.php tests/Unit/Exceptions/Compta/PartieDoubleIncompleteExceptionTest.php
git commit -m "feat(compta-v5): add PartieDoubleIncompleteException (G.2 step 1)"
```

---

### Task 2: PartieDoubleGuard class + unit tests

**Files:**
- Create: `app/Services/Compta/PartieDoubleGuard.php`
- Create: `tests/Unit/Services/Compta/PartieDoubleGuardTest.php`

**Context:** The guard is a static method `assertComplete(Transaction $tx)`. It checks 4 conditions sequentially (see spec §2.2). Transaction has `equilibree` (bool), `helloasso_order_id` (nullable int), and `lignes()` HasMany to `TransactionLigne` where PD lines have `compte_id IS NOT NULL`.

- [ ] **Step 1: Write the guard class**

```php
<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Exceptions\Compta\PartieDoubleIncompleteException;
use App\Models\Transaction;

final class PartieDoubleGuard
{
    public static function assertComplete(Transaction $tx): void
    {
        if (! config('compta.use_partie_double')) {
            return;
        }

        if ($tx->helloasso_order_id !== null) {
            return;
        }

        if ($tx->equilibree !== true) {
            throw PartieDoubleIncompleteException::nonEquilibree((int) $tx->id);
        }

        $lignesPD = $tx->lignes()->whereNotNull('compte_id')->get();

        if ($lignesPD->isEmpty()) {
            throw PartieDoubleIncompleteException::sansLignes((int) $tx->id);
        }

        $totalDebit = round((float) $lignesPD->sum('debit'), 2);
        $totalCredit = round((float) $lignesPD->sum('credit'), 2);

        if ($totalDebit !== $totalCredit) {
            throw PartieDoubleIncompleteException::desequilibree(
                (int) $tx->id,
                number_format($totalDebit, 2, '.', ''),
                number_format($totalCredit, 2, '.', ''),
            );
        }
    }
}
```

- [ ] **Step 2: Write unit tests**

6 tests covering each branch:

1. PD off → pass
2. HelloAsso → pass
3. `equilibree = false` → throw `nonEquilibree`
4. `equilibree = true` but no PD lines → throw `sansLignes`
5. `equilibree = true`, PD lines but unbalanced → throw `desequilibree`
6. `equilibree = true`, balanced PD lines → pass

Each test uses `Transaction::factory()->create()` with appropriate attributes, then calls `PartieDoubleGuard::assertComplete($tx)`. PD config set via `config()->set('compta.use_partie_double', true/false)`.

For test 1: set `config('compta.use_partie_double', false)`, create transaction with `equilibree = false` — guard passes silently.

For test 2: set PD on, create transaction with `helloasso_order_id = 12345`, `equilibree = false` — guard passes.

For test 3: set PD on, create transaction with `equilibree = false`, no `helloasso_order_id` — throws.

For test 4: set PD on, create transaction with `equilibree = true` but no `TransactionLigne` with `compte_id` — throws.

For test 5: set PD on, create transaction with `equilibree = true`, add 2 lignes with `compte_id` but `debit != credit` — throws.

For test 6: set PD on, create transaction with `equilibree = true`, add 2 balanced PD lines — passes silently.

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --filter=PartieDoubleGuardTest`
Expected: 6 PASS

- [ ] **Step 4: Commit**

```bash
git add app/Services/Compta/PartieDoubleGuard.php tests/Unit/Services/Compta/PartieDoubleGuardTest.php
git commit -m "feat(compta-v5): PartieDoubleGuard::assertComplete() (G.2 step 2)"
```

---

### Task 3: Fix FactureService — set equilibree = true after PD success

**Files:**
- Modify: `app/Services/FactureService.php` (line ~1103, after `pourRecetteACredit()`)
- Test: `tests/Feature/FactureServiceEquilibreeTest.php`

**Context:** `FactureService::genererTransactionDepuisLignesManuelles()` (private method called at line ~251 via `DB::transaction`) calls `EcritureGenerator::pourRecetteACredit()` at line 1096 when PD is active. But it never sets `equilibree = true` on the Transaction afterwards. The Transaction is created at line ~1024 without `equilibree` (defaults to `false`).

- [ ] **Step 1: Write a failing test**

Test that after `genererTransactionDepuisLignesManuelles()`, the Transaction has `equilibree = true`. Use the existing FactureService test infrastructure. Create a facture with manual lignes, call `valider()` (which triggers `genererTransactionDepuisLignesManuelles()`), assert `$tx->equilibree === true`.

Requires: PD config on, a Tiers, a Facture with lignes, a SousCategorie mapped to a Compte classe 7, a Compte 411.

- [ ] **Step 2: Run to verify it fails**

Expected: FAIL — `equilibree` is `false`

- [ ] **Step 3: Add `equilibree = true` after PD success**

In `app/Services/FactureService.php`, after line 1102 (the `pourRecetteACredit()` call succeeds), add:

```php
$transaction->forceFill(['equilibree' => true])->save();
```

This goes inside the `if (! $skipPartieDouble && ! empty($ventilations))` block, after the `pourRecetteACredit()` call at line 1102.

- [ ] **Step 4: Run test — verify it passes**

- [ ] **Step 5: Commit**

```bash
git add app/Services/FactureService.php tests/Feature/FactureServiceEquilibreeTest.php
git commit -m "fix(compta-v5): FactureService sets equilibree=true after PD (G.2 step 3)"
```

---

### Task 4: Fix ReglementOperationService — set equilibree = true after PD success

**Files:**
- Modify: `app/Services/ReglementOperationService.php` (line ~123, after `enrichirCreancePartieDouble()`)
- Test: `tests/Feature/ReglementOperationServiceEquilibreeTest.php`

**Context:** `ReglementOperationService::comptabiliserSeance()` at line 92 wraps creation in a `DB::transaction`. For each reglement, it creates a Transaction (line 98), a TransactionLigne (line 114), then calls `enrichirCreancePartieDouble()` (line 123). But never sets `equilibree = true`.

`enrichirCreancePartieDouble()` (line 450) resolves the compte, enriches the ligne, then delegates to `EcritureGenerator::pourRecetteACredit()` (line 492). If the resolver returns `null` (no compte mapping), it returns early (line 468) — in that case `equilibree` should NOT be set.

- [ ] **Step 1: Write a failing test**

Test that after `comptabiliserSeance()` with PD active and a properly mapped sous-catégorie, the Transaction has `equilibree = true`.

- [ ] **Step 2: Run to verify it fails**

Expected: FAIL — `equilibree` is `false`

- [ ] **Step 3: Set `equilibree = true` after successful PD enrichment**

In `enrichirCreancePartieDouble()`, after the successful `pourRecetteACredit()` call (line 500), add:

```php
$tx->forceFill(['equilibree' => true])->save();
```

This goes right before the closing `}` of `enrichirCreancePartieDouble()` (line 501), after line 500. It only runs if the method didn't return early at line 468 (null compte guard).

- [ ] **Step 4: Run test — verify it passes**

- [ ] **Step 5: Commit**

```bash
git add app/Services/ReglementOperationService.php tests/Feature/ReglementOperationServiceEquilibreeTest.php
git commit -m "fix(compta-v5): ReglementOperationService sets equilibree=true after PD (G.2 step 4)"
```

---

### Task 5: Wire guard into TransactionService

**Files:**
- Modify: `app/Services/TransactionService.php` (lines ~66 and ~412)
- Test: `tests/Feature/TransactionServiceGuardTest.php`

**Context:** `TransactionService::create()` at line 55-74 creates the Transaction, loops lignes, calls `enrichirPartieDouble()` (line 66), then `etatReglementResolver->syncer()` (line 71), then returns. The guard goes after `enrichirPartieDouble` and before `syncer`.

`TransactionService::update()` follows the same pattern — after the `enrichirPartieDouble()` call at line 412.

- [ ] **Step 1: Write a failing integration test**

Test: PD on, create a Transaction without providing the data needed for `enrichirPartieDouble()` to succeed (e.g., a sous-catégorie with no Compte mapping). Without the guard, this would create the Transaction with `equilibree = false` silently. With the guard, it should throw `PartieDoubleIncompleteException`.

Note: `enrichirPartieDouble()` already handles failure by logging and skipping — it doesn't throw. So the Transaction gets created with `equilibree = false`. The guard catches this.

- [ ] **Step 2: Run to verify it fails (guard not wired yet)**

Expected: test expects exception but none is thrown (Transaction created with `equilibree = false`)

- [ ] **Step 3: Wire guard into create() and update()**

In `app/Services/TransactionService.php`:

After line 66 (`$this->enrichirPartieDouble($transaction, $lignesCreees);`), add:
```php
PartieDoubleGuard::assertComplete($transaction);
```

After line 412 (`$this->enrichirPartieDouble($transaction, $lignesCreees);`), add:
```php
PartieDoubleGuard::assertComplete($transaction);
```

Add import:
```php
use App\Services\Compta\PartieDoubleGuard;
```

- [ ] **Step 4: Run test — verify it passes**

- [ ] **Step 5: Also test happy path still works** (existing tests should still pass)

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --filter=TransactionService`

- [ ] **Step 6: Commit**

```bash
git add app/Services/TransactionService.php tests/Feature/TransactionServiceGuardTest.php
git commit -m "feat(compta-v5): wire PartieDoubleGuard into TransactionService (G.2 step 5)"
```

---

### Task 6: Wire guard into FactureService, ReglementOperationService, VirementInterneService, TransactionExtourneService

**Files:**
- Modify: `app/Services/FactureService.php` (~line 1103)
- Modify: `app/Services/ReglementOperationService.php` (~line 500)
- Modify: `app/Services/VirementInterneService.php` (~lines 34, 58)
- Modify: `app/Services/TransactionExtourneService.php` (~line 59)
- Test: `tests/Feature/PartieDoubleGuardIntegrationTest.php`

**Context:**

**FactureService:** After the `pourRecetteACredit()` call + `equilibree = true` (added in Task 3), add `PartieDoubleGuard::assertComplete($transaction)`. This goes inside the `if (! $skipPartieDouble && ! empty($ventilations))` block.

**ReglementOperationService:** After the `equilibree = true` (added in Task 4), add `PartieDoubleGuard::assertComplete($tx)`.

**VirementInterneService:** In `create()` (line ~34) after `pourVirementInterne()`, and in `update()` (line ~58) after re-creation, add `PartieDoubleGuard::assertComplete($virement->transaction)`.

**TransactionExtourneService:** In `extourner()` (line ~59) after `assertEquilibreMiroir($miroir)`, add `PartieDoubleGuard::assertComplete($miroir)`.

- [ ] **Step 1: Wire guard into all 4 services**

Add the `PartieDoubleGuard::assertComplete()` call and the `use` import to each file.

- [ ] **Step 2: Write integration tests**

Verify that for each service, a successfully created transaction with PD passes the guard (no exception). Tests should exercise the normal code path end-to-end to confirm the guard doesn't reject valid PD.

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --filter=PartieDoubleGuardIntegration`
Expected: PASS

- [ ] **Step 4: Run full test suite**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test`
Expected: no new failures

- [ ] **Step 5: Commit**

```bash
git add app/Services/FactureService.php app/Services/ReglementOperationService.php app/Services/VirementInterneService.php app/Services/TransactionExtourneService.php tests/Feature/PartieDoubleGuardIntegrationTest.php
git commit -m "feat(compta-v5): wire PartieDoubleGuard into all PD services (G.2 step 6)"
```

---

### Task 7: Artisan command compta:assert-pd-complete

**Files:**
- Create: `app/Console/Commands/AssertPDCompleteCommand.php`
- Test: `tests/Feature/Commands/AssertPDCompleteCommandTest.php`

**Context:** Safety net command that checks all non-HelloAsso transactions of the current tenant. Pattern identical to `compta:reconcilier-statuts`. Must run in tenant context (boot `TenantContext`).

Signature: `compta:assert-pd-complete {--check} {--fix} {--association=}`

Modes:
- Default (no flag): report count OK / KO
- `--check`: exit 1 if any KO found (for CI)
- `--fix`: re-run TransactionConverter on KO transactions (delegate to existing backfill logic)

The `--association` option boots the tenant context. Without it, use current context.

- [ ] **Step 1: Write the command**

Query all transactions WHERE `helloasso_order_id IS NULL` and `association_id = TenantContext::currentId()`. For each, check the same conditions as the guard (equilibree, PD lines present, balanced). Report results.

- [ ] **Step 2: Write tests**

- With a tenant with all transactions balanced: `--check` exits 0
- With a tenant with an unbalanced transaction: `--check` exits 1
- Default mode: outputs counts

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --filter=AssertPDCompleteCommandTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/AssertPDCompleteCommand.php tests/Feature/Commands/AssertPDCompleteCommandTest.php
git commit -m "feat(compta-v5): compta:assert-pd-complete command (G.2 step 7)"
```

---

### Task 8: Full regression

**Files:** none (run only)

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test`
Expected: no new failures vs baseline (13 123 assertions, known pre-existing failures only)

- [ ] **Step 2: Run Pint**

Run: `./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint --test`
Expected: PASS

- [ ] **Step 3: Commit any Pint fixes if needed**
