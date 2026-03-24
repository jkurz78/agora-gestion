# Lot 5 — Versements HelloAsso (cashouts) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Import HelloAsso cashouts as VirementInterne records and link existing transactions to their cashouts via payment IDs.

**Architecture:** Add `helloasso_payment_id` column on transactions (populated during order sync). Add `fetchCashOuts()` to the API client. Extend `HelloAssoSyncService` with a `synchroniserCashouts()` method that upserts VirementInterne records and marks linked transactions. The Livewire component calls both syncs sequentially and displays an extended report.

**Tech Stack:** Laravel 11, Livewire 4, Pest PHP, MySQL (Docker/Sail)

**Spec:** `docs/superpowers/specs/2026-03-23-lot5-helloasso-cashouts-design.md`

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `database/migrations/2026_03_23_200001_add_helloasso_payment_id_to_transactions.php` | Create | Migration: add `helloasso_payment_id` column |
| `app/Models/Transaction.php` | Modify | Add `helloasso_payment_id` to `$fillable` and `$casts` |
| `app/Services/HelloAssoApiClient.php` | Modify | Add `fetchCashOuts()` method |
| `app/Services/HelloAssoSyncResult.php` | Modify | Remove Lot 5 TODO comment (now implemented) |
| `app/Services/HelloAssoSyncService.php` | Modify | Add `helloasso_payment_id` in order sync + new `synchroniserCashouts()` method |
| `app/Livewire/Parametres/HelloassoSync.php` | Modify | Call cashout sync after orders, pass extended result to view |
| `resources/views/livewire/parametres/helloasso-sync.blade.php` | Modify | Display virement counts + integrity warnings |
| `tests/Feature/Lot5/MigrationPaymentIdTest.php` | Create | Test migration |
| `tests/Feature/Lot5/HelloAssoSyncCashoutTest.php` | Create | Test cashout sync service |
| `tests/Feature/Lot5/HelloAssoSyncComponentCashoutTest.php` | Create | Test Livewire component with cashouts |

**Note:** `synchroniserCashouts()` returns a plain array (not `HelloAssoSyncResult`). The Livewire component merges both results into a single `$this->result` array for the view. The `HelloAssoSyncResult` VO is only updated to remove the Lot 5 TODO comment.

---

### Task 1: Migration — `helloasso_payment_id` on transactions

**Files:**
- Create: `database/migrations/2026_03_23_200001_add_helloasso_payment_id_to_transactions.php`
- Modify: `app/Models/Transaction.php:20-36` (fillable), `app/Models/Transaction.php:38-52` (casts)
- Test: `tests/Feature/Lot5/MigrationPaymentIdTest.php`

- [ ] **Step 1: Write the migration test**

```php
<?php

declare(strict_types=1);

use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has helloasso_payment_id column on transactions', function () {
    expect(\Schema::hasColumn('transactions', 'helloasso_payment_id'))->toBeTrue();
});

it('can store helloasso_payment_id on a transaction', function () {
    $tx = Transaction::factory()->create(['helloasso_payment_id' => 99999]);
    expect($tx->fresh()->helloasso_payment_id)->toBe(99999);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Lot5/MigrationPaymentIdTest.php`
Expected: FAIL — column does not exist

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('helloasso_payment_id')->nullable()->index()->after('helloasso_cashout_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('helloasso_payment_id');
        });
    }
};
```

- [ ] **Step 4: Update Transaction model**

In `app/Models/Transaction.php`, add `'helloasso_payment_id'` to `$fillable` (after `helloasso_cashout_id`) and add `'helloasso_payment_id' => 'integer'` to `casts()`.

- [ ] **Step 5: Run migration and tests**

Run: `./vendor/bin/sail artisan migrate && ./vendor/bin/sail test tests/Feature/Lot5/MigrationPaymentIdTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_03_23_200001_add_helloasso_payment_id_to_transactions.php \
  app/Models/Transaction.php tests/Feature/Lot5/MigrationPaymentIdTest.php
git commit -m "feat(lot5): migration helloasso_payment_id sur transactions"
```

---

### Task 2: API Client — `fetchCashOuts()`

**Files:**
- Modify: `app/Services/HelloAssoApiClient.php:46-59` (add method after fetchForms)
- Test: `tests/Feature/Lot3/HelloAssoApiClientTest.php` (add test)

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Lot3/HelloAssoApiClientTest.php`:

```php
it('fetches cash-outs with pagination', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/cash-outs*' => Http::sequence()
            ->push([
                'data' => [
                    ['id' => 1, 'date' => '2025-10-15T10:00:00+02:00', 'amount' => 50000, 'payments' => [['id' => 101, 'amount' => 50000]]],
                ],
                'pagination' => ['continuationToken' => 'next'],
            ])
            ->push(['data' => [], 'pagination' => []]),
    ]);

    $parametres = \App\Models\HelloAssoParametres::where('association_id', 1)->first();
    $client = new \App\Services\HelloAssoApiClient($parametres);
    $cashOuts = $client->fetchCashOuts('2025-09-01', '2026-08-31');

    expect($cashOuts)->toHaveCount(1);
    expect($cashOuts[0]['id'])->toBe(1);
    expect($cashOuts[0]['payments'])->toHaveCount(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter="fetches cash-outs"`
Expected: FAIL — method fetchCashOuts does not exist

- [ ] **Step 3: Implement `fetchCashOuts`**

Add to `app/Services/HelloAssoApiClient.php` after `fetchForms()`:

```php
/**
 * Fetch all cash-outs for a date range, handling pagination.
 *
 * @return list<array<string, mixed>>
 */
public function fetchCashOuts(string $from, string $to): array
{
    $this->authenticate();

    return $this->fetchPaginated(
        "/v5/organizations/{$this->organisationSlug}/cash-outs",
        ['from' => $from, 'to' => $to],
    );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter="fetches cash-outs"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/HelloAssoApiClient.php tests/Feature/Lot3/HelloAssoApiClientTest.php
git commit -m "feat(lot5): fetchCashOuts dans HelloAssoApiClient"
```

---

### Task 3: Sync orders — populate `helloasso_payment_id`

**Files:**
- Modify: `app/Services/HelloAssoSyncService.php:101-139` (processOrder, create+update paths)
- Test: `tests/Feature/Lot4/HelloAssoSyncServiceTest.php` (add test)

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Lot4/HelloAssoSyncServiceTest.php`:

```php
it('stores helloasso_payment_id on transaction', function () {
    $orders = [
        [
            'id' => 120,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 5000,
            'formSlug' => 'dons-libres',
            'formType' => 'Donation',
            'items' => [
                ['id' => 1020, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [['id' => 555, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser($orders, 2025);

    $tx = Transaction::where('helloasso_order_id', 120)->first();
    expect($tx->helloasso_payment_id)->toBe(555);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter="stores helloasso_payment_id"`
Expected: FAIL — helloasso_payment_id is null

- [ ] **Step 3: Add `helloasso_payment_id` to processOrder**

In `app/Services/HelloAssoSyncService.php`, in the `DB::transaction` closure:

**Create path** (line ~125, Transaction::create array): add:
```php
'helloasso_payment_id' => $order['payments'][0]['id'] ?? null,
```

**Update path** (line ~112, inside `if ($existing)`): add after the reference backfill:
```php
if ($existing->helloasso_payment_id === null && isset($order['payments'][0]['id'])) {
    $data['helloasso_payment_id'] = $order['payments'][0]['id'];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter="Lot4"`
Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/HelloAssoSyncService.php tests/Feature/Lot4/HelloAssoSyncServiceTest.php
git commit -m "feat(lot5): renseigner helloasso_payment_id lors du sync orders"
```

---

### Task 4: Cashout sync — `synchroniserCashouts()`

**Files:**
- Modify: `app/Services/HelloAssoSyncService.php` (add method)
- Test: `tests/Feature/Lot5/HelloAssoSyncCashoutTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\HelloAssoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

    $this->compteHA = CompteBancaire::factory()->create(['nom' => 'HelloAsso']);
    $this->compteCourant = CompteBancaire::factory()->create(['nom' => 'Compte courant']);
    $this->scDon = SousCategorie::where('pour_dons', true)->first()
        ?? SousCategorie::factory()->create(['pour_dons' => true, 'nom' => 'Don']);

    $this->parametres = HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'test',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => $this->compteHA->id,
        'compte_versement_id' => $this->compteCourant->id,
        'sous_categorie_don_id' => $this->scDon->id,
    ]);

    $this->tiers = Tiers::factory()->avecHelloasso()->create([
        'nom' => 'Dupont', 'prenom' => 'Jean',
    ]);
});

it('creates a virement interne from a cashout', function () {
    // Pre-create a transaction with a payment_id
    Transaction::factory()->create([
        'type' => 'recette',
        'montant_total' => 50.00,
        'compte_id' => $this->compteHA->id,
        'tiers_id' => $this->tiers->id,
        'helloasso_order_id' => 100,
        'helloasso_payment_id' => 201,
    ]);

    $cashOuts = [
        [
            'id' => 5001,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 5000,
            'payments' => [['id' => 201, 'amount' => 5000]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    expect($result['virements_created'])->toBe(1);

    $virement = VirementInterne::where('helloasso_cashout_id', 5001)->first();
    expect($virement)->not->toBeNull();
    expect((float) $virement->montant)->toBe(50.00);
    expect($virement->compte_source_id)->toBe($this->compteHA->id);
    expect($virement->compte_destination_id)->toBe($this->compteCourant->id);
    expect($virement->reference)->toBe('HA-CO-5001');
});

it('marks transactions with cashout_id via payment link', function () {
    $tx = Transaction::factory()->create([
        'type' => 'recette',
        'montant_total' => 50.00,
        'compte_id' => $this->compteHA->id,
        'tiers_id' => $this->tiers->id,
        'helloasso_order_id' => 100,
        'helloasso_payment_id' => 201,
    ]);

    $cashOuts = [
        [
            'id' => 5001,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 5000,
            'payments' => [['id' => 201, 'amount' => 5000]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniserCashouts($cashOuts);

    expect($tx->fresh()->helloasso_cashout_id)->toBe(5001);
});

it('is idempotent — re-importing same cashout updates virement', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'montant_total' => 50.00,
        'compte_id' => $this->compteHA->id,
        'tiers_id' => $this->tiers->id,
        'helloasso_order_id' => 100,
        'helloasso_payment_id' => 201,
    ]);

    $cashOuts = [
        [
            'id' => 5001,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 5000,
            'payments' => [['id' => 201, 'amount' => 5000]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result1 = $service->synchroniserCashouts($cashOuts);
    expect($result1['virements_created'])->toBe(1);

    $result2 = $service->synchroniserCashouts($cashOuts);
    expect($result2['virements_created'])->toBe(0);
    expect($result2['virements_updated'])->toBe(1);

    expect(VirementInterne::where('helloasso_cashout_id', 5001)->count())->toBe(1);
});

it('reports integrity warning when amounts differ', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'montant_total' => 45.00,
        'compte_id' => $this->compteHA->id,
        'tiers_id' => $this->tiers->id,
        'helloasso_order_id' => 100,
        'helloasso_payment_id' => 201,
    ]);

    $cashOuts = [
        [
            'id' => 5001,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 5000,
            'payments' => [['id' => 201, 'amount' => 5000]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    expect($result['integrity_warnings'])->toHaveCount(1);
    expect($result['integrity_warnings'][0])->toContain('5001');
});

it('reports integrity warning when no transactions found for cashout', function () {
    $cashOuts = [
        [
            'id' => 5002,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 3000,
            'payments' => [['id' => 999, 'amount' => 3000]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    expect($result['virements_created'])->toBe(1);
    expect($result['integrity_warnings'])->toHaveCount(1);
});

it('restores soft-deleted virement on re-import', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'montant_total' => 50.00,
        'compte_id' => $this->compteHA->id,
        'tiers_id' => $this->tiers->id,
        'helloasso_order_id' => 100,
        'helloasso_payment_id' => 201,
    ]);

    $cashOuts = [
        [
            'id' => 5001,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 5000,
            'payments' => [['id' => 201, 'amount' => 5000]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniserCashouts($cashOuts);

    // Soft-delete the virement
    VirementInterne::where('helloasso_cashout_id', 5001)->first()->delete();
    expect(VirementInterne::where('helloasso_cashout_id', 5001)->count())->toBe(0);

    // Re-import should restore
    $result = $service->synchroniserCashouts($cashOuts);
    expect($result['virements_updated'])->toBe(1);
    expect(VirementInterne::where('helloasso_cashout_id', 5001)->count())->toBe(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/Lot5/HelloAssoSyncCashoutTest.php`
Expected: FAIL — method synchroniserCashouts does not exist

- [ ] **Step 3: Implement `synchroniserCashouts()`**

Add to `app/Services/HelloAssoSyncService.php`:

```php
/**
 * Import HelloAsso cash-outs into SVS virements internes.
 *
 * @param  list<array<string, mixed>>  $cashOuts
 * @return array{virements_created: int, virements_updated: int, integrity_warnings: list<string>, errors: list<string>}
 */
public function synchroniserCashouts(array $cashOuts): array
{
    $virementsCreated = 0;
    $virementsUpdated = 0;
    $integrityWarnings = [];
    $errors = [];

    foreach ($cashOuts as $cashOut) {
        try {
            $result = $this->processCashout($cashOut);
            $virementsCreated += $result['created'];
            $virementsUpdated += $result['updated'];
            if ($result['warning'] !== null) {
                $integrityWarnings[] = $result['warning'];
            }
        } catch (\Throwable $e) {
            $errors[] = "Cashout #{$cashOut['id']} : {$e->getMessage()}";
        }
    }

    return [
        'virements_created' => $virementsCreated,
        'virements_updated' => $virementsUpdated,
        'integrity_warnings' => $integrityWarnings,
        'errors' => $errors,
    ];
}

/**
 * @return array{created: int, updated: int, warning: ?string}
 */
private function processCashout(array $cashOut): array
{
    $result = ['created' => 0, 'updated' => 0, 'warning' => null];

    $cashOutDate = Carbon::parse($cashOut['date']);
    $montantEuros = round($cashOut['amount'] / 100, 2);

    DB::transaction(function () use ($cashOut, $cashOutDate, $montantEuros, &$result) {
        // 1. Upsert VirementInterne
        $existing = VirementInterne::withTrashed()
            ->where('helloasso_cashout_id', $cashOut['id'])
            ->first();

        if ($existing?->trashed()) {
            $existing->restore();
        }

        if ($existing) {
            $existing->update([
                'date' => $cashOutDate->toDateString(),
                'montant' => $montantEuros,
                'notes' => 'Versement HelloAsso du ' . $cashOutDate->format('d/m/Y'),
            ]);
            $result['updated']++;
        } else {
            VirementInterne::create([
                'date' => $cashOutDate->toDateString(),
                'montant' => $montantEuros,
                'compte_source_id' => $this->parametres->compte_helloasso_id,
                'compte_destination_id' => $this->parametres->compte_versement_id,
                'notes' => 'Versement HelloAsso du ' . $cashOutDate->format('d/m/Y'),
                'reference' => "HA-CO-{$cashOut['id']}",
                'helloasso_cashout_id' => $cashOut['id'],
                'saisi_par' => auth()->id() ?? 1,
                'numero_piece' => app(NumeroPieceService::class)->assign($cashOutDate),
            ]);
            $result['created']++;
        }

        // 2. Mark linked transactions
        $paymentIds = collect($cashOut['payments'] ?? [])->pluck('id')->filter()->all();
        if (! empty($paymentIds)) {
            Transaction::whereIn('helloasso_payment_id', $paymentIds)
                ->whereNull('helloasso_cashout_id')
                ->update(['helloasso_cashout_id' => $cashOut['id']]);
        }

        // 3. Integrity check
        $sumTransactions = (float) Transaction::where('helloasso_cashout_id', $cashOut['id'])->sum('montant_total');
        $sumTransactions = round($sumTransactions, 2);

        if (abs($sumTransactions - $montantEuros) > 0.01) {
            $result['warning'] = sprintf(
                'Cashout #%d : écart de %.2f € entre le versement (%.2f €) et les transactions liées (%.2f €)',
                $cashOut['id'],
                abs($montantEuros - $sumTransactions),
                $montantEuros,
                $sumTransactions,
            );
        }
    });

    return $result;
}
```

Also add `use App\Models\VirementInterne;` to the imports.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/Lot5/HelloAssoSyncCashoutTest.php`
Expected: ALL PASS

- [ ] **Step 5: Run full Lot4+Lot5 tests for regression**

Run: `./vendor/bin/sail test --filter="Lot4|Lot5"`
Expected: ALL PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/HelloAssoSyncService.php tests/Feature/Lot5/HelloAssoSyncCashoutTest.php
git commit -m "feat(lot5): synchroniserCashouts — import cashouts en virements internes"
```

---

### Task 5: Livewire component — integrate cashout sync

**Files:**
- Modify: `app/Livewire/Parametres/HelloassoSync.php`
- Modify: `resources/views/livewire/parametres/helloasso-sync.blade.php`
- Test: `tests/Feature/Lot5/HelloAssoSyncComponentCashoutTest.php`

- [ ] **Step 1: Write the component test**

```php
<?php

declare(strict_types=1);

use App\Livewire\Parametres\HelloassoSync;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\VirementInterne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

    $compteHA = CompteBancaire::factory()->create(['nom' => 'HelloAsso']);
    $compteCourant = CompteBancaire::factory()->create(['nom' => 'Compte courant']);
    $scDon = SousCategorie::where('pour_dons', true)->first()
        ?? SousCategorie::factory()->create(['pour_dons' => true, 'nom' => 'Don']);

    HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'mon-asso',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => $compteHA->id,
        'compte_versement_id' => $compteCourant->id,
        'sous_categorie_don_id' => $scDon->id,
    ]);

    Tiers::factory()->avecHelloasso()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
});

it('syncs orders and cashouts together and displays full report', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'id' => 100, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00',
                        'formSlug' => 'dons-libres', 'formType' => 'Donation',
                        'items' => [['id' => 1001, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don']],
                        'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont'],
                        'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
                        'payments' => [['id' => 201, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
                    ],
                ],
                'pagination' => [],
            ])
            ->push(['data' => [], 'pagination' => []]),
        '*/v5/organizations/mon-asso/cash-outs*' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'id' => 5001, 'date' => '2025-10-20T10:00:00+02:00', 'amount' => 5000,
                        'payments' => [['id' => 201, 'amount' => 5000]],
                    ],
                ],
                'pagination' => [],
            ])
            ->push(['data' => [], 'pagination' => []]),
    ]);

    Livewire::test(HelloassoSync::class)
        ->call('synchroniser')
        ->assertSee('1 créée')
        ->assertSee('Virements')
        ->assertSee('Synchronisation terminée');

    expect(VirementInterne::where('helloasso_cashout_id', 5001)->count())->toBe(1);
});

it('skips cashout sync when compte_versement_id is not configured', function () {
    HelloAssoParametres::where('association_id', 1)->update(['compte_versement_id' => null]);

    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push(['data' => [], 'pagination' => []])
            ->push(['data' => [], 'pagination' => []]),
    ]);

    Livewire::test(HelloassoSync::class)
        ->call('synchroniser')
        ->assertSee('Synchronisation terminée')
        ->assertSee('compte de versement');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/Lot5/HelloAssoSyncComponentCashoutTest.php`
Expected: FAIL

- [ ] **Step 3: Update the Livewire component**

In `app/Livewire/Parametres/HelloassoSync.php`, update the `synchroniser()` method:

```php
public function synchroniser(): void
{
    $this->erreur = null;
    $this->result = null;

    $parametres = HelloAssoParametres::where('association_id', 1)->first();
    if ($parametres === null || $parametres->client_id === null) {
        $this->erreur = 'Paramètres HelloAsso non configurés.';
        return;
    }

    if ($parametres->compte_helloasso_id === null) {
        $this->erreur = 'Compte HelloAsso non configuré. Configurez-le dans la section ci-dessus.';
        return;
    }

    try {
        $client = new HelloAssoApiClient($parametres);

        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($this->exercice);
        $from = $range['start']->toDateString();
        $to = $range['end']->toDateString();

        $orders = $client->fetchOrders($from, $to);
    } catch (\RuntimeException $e) {
        $this->erreur = $e->getMessage();
        return;
    }

    $syncService = new HelloAssoSyncService($parametres);
    $syncResult = $syncService->synchroniser($orders, $this->exercice);

    $this->result = [
        'transactionsCreated' => $syncResult->transactionsCreated,
        'transactionsUpdated' => $syncResult->transactionsUpdated,
        'lignesCreated' => $syncResult->lignesCreated,
        'lignesUpdated' => $syncResult->lignesUpdated,
        'ordersSkipped' => $syncResult->ordersSkipped,
        'errors' => $syncResult->errors,
        'virementsCreated' => 0,
        'virementsUpdated' => 0,
        'integrityWarnings' => [],
    ];

    // Cashout sync — only if compte_versement_id is configured
    if ($parametres->compte_versement_id === null) {
        $this->result['cashoutSkipped'] = true;
    } else {
        try {
            $cashOuts = $client->fetchCashOuts($from, $to);
            $cashoutResult = $syncService->synchroniserCashouts($cashOuts);

            $this->result['virementsCreated'] = $cashoutResult['virements_created'];
            $this->result['virementsUpdated'] = $cashoutResult['virements_updated'];
            $this->result['integrityWarnings'] = $cashoutResult['integrity_warnings'];

            if (! empty($cashoutResult['errors'])) {
                $this->result['errors'] = array_merge($this->result['errors'], $cashoutResult['errors']);
            }
        } catch (\RuntimeException $e) {
            $this->result['errors'][] = "Cashouts : {$e->getMessage()}";
        }
    }
}
```

- [ ] **Step 4: Update the blade view**

In `resources/views/livewire/parametres/helloasso-sync.blade.php`, after the existing `<li>` items for transactions/lignes, add:

```blade
@if(($result['virementsCreated'] ?? 0) > 0 || ($result['virementsUpdated'] ?? 0) > 0)
    <li>Virements : <strong>{{ $result['virementsCreated'] }} créé(s)</strong>, <strong>{{ $result['virementsUpdated'] }} mis à jour</strong></li>
@endif
```

And after the errors block, add:

```blade
@if(!empty($result['cashoutSkipped']))
    <div class="alert alert-info small">
        <i class="bi bi-info-circle me-1"></i> Versements non synchronisés : le compte de versement n'est pas configuré dans les paramètres HelloAsso.
    </div>
@endif

@if(!empty($result['integrityWarnings']))
    <div class="alert alert-warning">
        <strong><i class="bi bi-exclamation-triangle me-1"></i> Avertissements d'intégrité :</strong>
        <ul class="mb-0 mt-1">
            @foreach($result['integrityWarnings'] as $warning)
                <li class="small">{{ $warning }}</li>
            @endforeach
        </ul>
    </div>
@endif
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/Lot5/HelloAssoSyncComponentCashoutTest.php`
Expected: ALL PASS

- [ ] **Step 6: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: ALL PASS

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Parametres/HelloassoSync.php \
  resources/views/livewire/parametres/helloasso-sync.blade.php \
  tests/Feature/Lot5/HelloAssoSyncComponentCashoutTest.php
git commit -m "feat(lot5): intégration cashouts dans le composant Livewire + rapport étendu"
```

---

### Task 6: Verification — full test suite + manual check

- [ ] **Step 1: Run the complete test suite**

Run: `./vendor/bin/sail test`
Expected: ALL PASS (no regressions)

- [ ] **Step 2: Run Pint for code style**

Run: `./vendor/bin/pint --test`
Expected: PASS (or fix any issues with `./vendor/bin/pint`)

- [ ] **Step 3: Verify migration runs clean**

Run: `./vendor/bin/sail artisan migrate:fresh --seed && ./vendor/bin/sail test`
Expected: ALL PASS
