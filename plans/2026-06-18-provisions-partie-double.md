# Provisions Partie Double — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make provisions generate real PD journal entries (dotation + extourne) so they appear in the balance, grand livre, and compte de résultat.

**Architecture:** Each `Provision` generates 2 `Transaction` records via `EcritureGenerator` (dotation on last day of exercice N, extourne on first day of N+1). A new `ProvisionPDService` orchestrates generation/deletion. 4 new system accounts (486, 487, 681, 781) are seeded via `SystemeSeeder`.

**Tech Stack:** Laravel 11, Pest PHP, MySQL/SQLite (Sail)

**Spec:** `docs/specs/2026-06-18-provisions-partie-double.md`

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `database/migrations/2026_06_18_000001_add_provision_id_to_transactions.php` | Create | FK `provision_id` on `transactions` |
| `app/Models/Transaction.php` | Modify | Add `provision_id` to `$fillable`, add `provision()` relation |
| `app/Models/Provision.php` | Modify | Add `transactions()` relation |
| `app/Services/Compta/Migrations/SystemeSeeder.php` | Modify | Seed 486, 487, 681, 781 |
| `app/Services/Compta/EcritureGenerator.php` | Modify | Add `pourProvisionDotation()` + `pourProvisionExtourne()` |
| `app/Services/Compta/ProvisionPDService.php` | Create | Orchestrate PD generation/deletion for a provision |
| `app/Livewire/Provisions/ProvisionIndex.php` | Modify | Wire `ProvisionPDService` into `save()` and `delete()` |
| `app/Services/Rapports/FluxTresorerieBuilder.php` | Modify | Skip `ProvisionService` totals when `use_partie_double` |
| `tests/Feature/Services/Compta/ProvisionPDServiceTest.php` | Create | Test suite for provision PD integration |
| `tests/Support/CreatesPartieDoubleContext.php` | Modify | Add provision account helpers |

---

### Task 1: Migration — add `provision_id` FK on `transactions`

**Files:**
- Create: `database/migrations/2026_06_18_000001_add_provision_id_to_transactions.php`
- Modify: `app/Models/Transaction.php:28-60` (add `provision_id` to `$fillable`)
- Modify: `app/Models/Provision.php` (add `transactions()` relation)

- [ ] **Step 1: Create the migration**

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
            $table->foreignId('provision_id')
                ->nullable()
                ->after('virement_interne_id')
                ->constrained('provisions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provision_id');
        });
    }
};
```

- [ ] **Step 2: Add `provision_id` to Transaction model `$fillable`**

In `app/Models/Transaction.php`, add `'provision_id'` to the `$fillable` array after `'virement_interne_id'`.

- [ ] **Step 3: Add `provision()` relation on Transaction model**

In `app/Models/Transaction.php`, add:

```php
public function provision(): BelongsTo
{
    return $this->belongsTo(Provision::class);
}
```

- [ ] **Step 4: Add `transactions()` relation on Provision model**

In `app/Models/Provision.php`, add the import `use Illuminate\Database\Eloquent\Relations\HasMany;` and then the method:

```php
public function transactions(): HasMany
{
    return $this->hasMany(Transaction::class);
}
```

- [ ] **Step 5: Run migration**

Run: `./vendor/bin/sail artisan migrate`
Expected: success, `provision_id` column added to `transactions`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_18_000001_add_provision_id_to_transactions.php app/Models/Transaction.php app/Models/Provision.php
git commit -m "feat(compta-v5): migration + model relations for provision_id on transactions"
```

---

### Task 2: Seed 4 new system accounts (486, 487, 681, 781)

**Files:**
- Modify: `app/Services/Compta/Migrations/SystemeSeeder.php:169-185`
- Test: `tests/Feature/Services/Compta/ProvisionPDServiceTest.php` (seed verification)

- [ ] **Step 1: Write the test**

Create `tests/Feature/Services/Compta/ProvisionPDServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;

test('SystemeSeeder seeds provision accounts 486, 487, 681, 781', function () {
    SystemeSeeder::seed();

    $assocId = TenantContext::currentId();

    foreach (['486', '487', '681', '781'] as $pcg) {
        $compte = Compte::where('association_id', $assocId)
            ->where('numero_pcg', $pcg)
            ->first();

        expect($compte)->not->toBeNull("Compte {$pcg} not found");
        expect((bool) $compte->est_systeme)->toBeTrue();
        expect((bool) $compte->actif)->toBeTrue();
        expect((bool) $compte->lettrable)->toBeFalse();
    }

    // Verify classes
    expect((int) Compte::where('association_id', $assocId)->where('numero_pcg', '486')->first()->classe)->toBe(4);
    expect((int) Compte::where('association_id', $assocId)->where('numero_pcg', '487')->first()->classe)->toBe(4);
    expect((int) Compte::where('association_id', $assocId)->where('numero_pcg', '681')->first()->classe)->toBe(6);
    expect((int) Compte::where('association_id', $assocId)->where('numero_pcg', '781')->first()->classe)->toBe(7);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Services/Compta/ProvisionPDServiceTest.php`
Expected: FAIL — accounts 486/487/681/781 not found.

- [ ] **Step 3: Add accounts to SystemeSeeder::seed()**

In `app/Services/Compta/Migrations/SystemeSeeder.php`, in the `seed()` method, add after the 530 line:

```php
// Unconditional: 486 Charges constatées d'avance (classe 4, provisions)
DB::statement(self::unconditionalSql('486', 'Charges constatées d\'avance', 4));

// Unconditional: 487 Produits constatés d'avance (classe 4, provisions)
DB::statement(self::unconditionalSql('487', 'Produits constatés d\'avance', 4));

// Unconditional: 681 Dotations aux amort., dépréciations et provisions (classe 6)
DB::statement(self::unconditionalSql('681', 'Dotations aux amort., dépréciations et provisions', 6));

// Unconditional: 781 Reprises sur amort., dépréciations et provisions (classe 7)
DB::statement(self::unconditionalSql('781', 'Reprises sur amort., dépréciations et provisions', 7));
```

**Important:** The `unconditionalSql()` method sets `lettrable = 1` by default (it's designed for 411/401). For provision accounts, lettrable should be FALSE. We need to either:
- Add a parameter to `unconditionalSql()` for `lettrable`, OR
- Create a new helper

The simplest approach: add a `$lettrable = true` optional parameter to `unconditionalSql()`. Change the method signature:

```php
public static function unconditionalSql(string $numeroPcg, string $intitule, int $classe, bool $lettrable = true): string
```

And replace the hardcoded `1` for lettrable with `($lettrable ? 1 : 0)`:

In the SQL template, change:
```
1,
```
(the lettrable line) to:
```
{$lettrable},
```

Where `$lettrable` is set as: `$lettrable = $lettrable ? 1 : 0;` at the top of the method.

Then seed the provision accounts with `lettrable: false`:

```php
DB::statement(self::unconditionalSql('486', 'Charges constatées d\'avance', 4, false));
DB::statement(self::unconditionalSql('487', 'Produits constatés d\'avance', 4, false));
DB::statement(self::unconditionalSql('681', 'Dotations aux amort., dépréciations et provisions', 6, false));
DB::statement(self::unconditionalSql('781', 'Reprises sur amort., dépréciations et provisions', 7, false));
```

Existing calls (411, 401, 467, 5112) are unchanged — they use the default `true`.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Services/Compta/ProvisionPDServiceTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Compta/Migrations/SystemeSeeder.php tests/Feature/Services/Compta/ProvisionPDServiceTest.php
git commit -m "feat(compta-v5): seed provision accounts 486, 487, 681, 781 in SystemeSeeder"
```

---

### Task 3: EcritureGenerator — `pourProvisionDotation()` + `pourProvisionExtourne()`

**Files:**
- Modify: `app/Services/Compta/EcritureGenerator.php` (add 2 methods before the private methods section at line ~1633)
- Modify: `tests/Feature/Services/Compta/ProvisionPDServiceTest.php` (add tests)

**Context:** Follow the exact pattern of `pourVirementInterne()` (line 1546-1631). Key differences: journal = OD, accounts = 486/487/681/781, `provision_id` FK instead of `virement_interne_id`.

- [ ] **Step 1: Write the dotation test (dépense type)**

Add to `tests/Feature/Services/Compta/ProvisionPDServiceTest.php`:

```php
use App\Enums\JournalComptable;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Provision;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();
});

afterEach(function () {
    TenantContext::clear();
});

test('pourProvisionDotation — dépense generates 681 D / 486 C in journal OD', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 1500.00,
        'libelle' => 'Loyer décembre non facturé',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $generator = app(EcritureGenerator::class);
    $tx = $generator->pourProvisionDotation($provision);

    expect($tx->provision_id)->toBe((int) $provision->id);
    expect($tx->journal)->toBe(JournalComptable::Od);
    expect($tx->type)->toBe(TypeTransaction::Depense);
    expect($tx->type_ecriture)->toBe('normale');
    expect((float) $tx->montant_total)->toBe(1500.00);
    expect((bool) $tx->equilibree)->toBeTrue();
    expect($tx->lignes)->toHaveCount(2);

    $ligne681 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '681');
    $ligne486 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '486');

    expect($ligne681)->not->toBeNull();
    expect((float) $ligne681->debit)->toBe(1500.00);
    expect((float) $ligne681->credit)->toBe(0.0);

    expect($ligne486)->not->toBeNull();
    expect((float) $ligne486->debit)->toBe(0.0);
    expect((float) $ligne486->credit)->toBe(1500.00);
});

test('pourProvisionDotation — recette generates 487 D / 781 C in journal OD', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'recette',
        'montant' => 800.00,
        'libelle' => 'Subvention avance N+1',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $generator = app(EcritureGenerator::class);
    $tx = $generator->pourProvisionDotation($provision);

    expect($tx->journal)->toBe(JournalComptable::Od);
    expect($tx->type)->toBe(TypeTransaction::Recette);

    $ligne487 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '487');
    $ligne781 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '781');

    expect($ligne487)->not->toBeNull();
    expect((float) $ligne487->debit)->toBe(800.00);
    expect((float) $ligne487->credit)->toBe(0.0);

    expect($ligne781)->not->toBeNull();
    expect((float) $ligne781->debit)->toBe(0.0);
    expect((float) $ligne781->credit)->toBe(800.00);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Services/Compta/ProvisionPDServiceTest.php --filter="pourProvisionDotation"`
Expected: FAIL — method `pourProvisionDotation` does not exist.

- [ ] **Step 3: Implement `pourProvisionDotation()` in EcritureGenerator**

Add to `app/Services/Compta/EcritureGenerator.php`, **before** the `// =========================================================================` private methods separator (around line 1633). Add `use App\Models\Provision;` at the top imports.

```php
/**
 * Génère la transaction de DOTATION pour une provision de fin d'exercice.
 *
 * Dépense (FNP/CCA) : 681 D / 486 C
 * Recette (PCA)      : 487 D / 781 C
 *
 * Date = dernier jour de l'exercice de la provision.
 * Journal = OD.
 */
public function pourProvisionDotation(Provision $provision): Transaction
{
    $montant = (float) $provision->montant;
    $libelle = 'Dotation : '.$provision->libelle;
    $isDepense = $provision->type === TypeTransaction::Depense;
    $tenantId = (int) TenantContext::currentId();

    // Résolution des comptes
    $compteCharge = Compte::where('association_id', $tenantId)
        ->where('numero_pcg', $isDepense ? '681' : '487')
        ->firstOrFail();

    $compteContrepartie = Compte::where('association_id', $tenantId)
        ->where('numero_pcg', $isDepense ? '486' : '781')
        ->firstOrFail();

    return DB::transaction(function () use ($provision, $montant, $libelle, $isDepense, $tenantId, $compteCharge, $compteContrepartie): Transaction {
        $numeroPiece = app(NumeroPieceService::class)->assign(Carbon::parse($provision->date));

        $transaction = Transaction::create([
            'association_id' => $tenantId,
            'type' => $isDepense ? TypeTransaction::Depense : TypeTransaction::Recette,
            'date' => $provision->date->format('Y-m-d'),
            'libelle' => $libelle,
            'montant_total' => $montant,
            'mode_paiement' => null,
            'saisi_par' => Auth::id(),
            'equilibree' => true,
            'type_ecriture' => 'normale',
            'journal' => JournalComptable::Od,
            'numero_piece' => $numeroPiece,
            'provision_id' => $provision->id,
        ]);

        // Ligne débit (681 pour dépense, 487 pour recette)
        $ligneDebit = TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'compte_id' => $compteCharge->id,
            'debit' => $montant,
            'credit' => 0,
            'tiers_id' => null,
            'libelle' => $libelle,
            'montant' => 0,
            'sous_categorie_id' => null,
        ]);
        $ligneDebit->setRelation('compte', $compteCharge);

        // Ligne crédit (486 pour dépense, 781 pour recette)
        $ligneCredit = TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'compte_id' => $compteContrepartie->id,
            'debit' => 0,
            'credit' => $montant,
            'tiers_id' => null,
            'libelle' => $libelle,
            'montant' => 0,
            'sous_categorie_id' => null,
        ]);
        $ligneCredit->setRelation('compte', $compteContrepartie);

        $lignes = collect([$ligneDebit, $ligneCredit]);
        $this->assertEquilibre($lignes);
        $this->assertTenantCoherence($lignes);

        return $transaction->load('lignes.compte');
    });
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Services/Compta/ProvisionPDServiceTest.php --filter="pourProvisionDotation"`
Expected: PASS

- [ ] **Step 5: Write the extourne tests**

Add to `tests/Feature/Services/Compta/ProvisionPDServiceTest.php`:

```php
test('pourProvisionExtourne — dépense generates 486 D / 781 C, dated 1er sept N+1', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 1500.00,
        'libelle' => 'Loyer décembre non facturé',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $generator = app(EcritureGenerator::class);
    $tx = $generator->pourProvisionExtourne($provision);

    expect($tx->provision_id)->toBe((int) $provision->id);
    expect($tx->journal)->toBe(JournalComptable::Od);
    expect($tx->type)->toBe(TypeTransaction::Recette);
    expect($tx->type_ecriture)->toBe('extourne');
    expect($tx->date->format('Y-m-d'))->toBe('2026-09-01');
    expect((float) $tx->montant_total)->toBe(1500.00);

    $ligne486 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '486');
    $ligne781 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '781');

    expect($ligne486)->not->toBeNull();
    expect((float) $ligne486->debit)->toBe(1500.00);
    expect((float) $ligne486->credit)->toBe(0.0);

    expect($ligne781)->not->toBeNull();
    expect((float) $ligne781->debit)->toBe(0.0);
    expect((float) $ligne781->credit)->toBe(1500.00);
});

test('pourProvisionExtourne — recette generates 681 D / 487 C, dated 1er sept N+1', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'recette',
        'montant' => 800.00,
        'libelle' => 'Subvention avance N+1',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $generator = app(EcritureGenerator::class);
    $tx = $generator->pourProvisionExtourne($provision);

    expect($tx->journal)->toBe(JournalComptable::Od);
    expect($tx->type)->toBe(TypeTransaction::Depense);
    expect($tx->type_ecriture)->toBe('extourne');
    expect($tx->date->format('Y-m-d'))->toBe('2026-09-01');

    $ligne681 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '681');
    $ligne487 = $tx->lignes->first(fn ($l) => $l->compte->numero_pcg === '487');

    expect($ligne681)->not->toBeNull();
    expect((float) $ligne681->debit)->toBe(800.00);

    expect($ligne487)->not->toBeNull();
    expect((float) $ligne487->credit)->toBe(800.00);
});
```

- [ ] **Step 6: Implement `pourProvisionExtourne()` in EcritureGenerator**

Add right after `pourProvisionDotation()`:

```php
/**
 * Génère la transaction d'EXTOURNE pour une provision de fin d'exercice.
 *
 * Dépense (FNP/CCA) : 486 D / 781 C  (inverse de la dotation)
 * Recette (PCA)      : 681 D / 487 C  (inverse de la dotation)
 *
 * Date = premier jour de l'exercice N+1 (1er sept N+1).
 * Journal = OD. type_ecriture = 'extourne'.
 */
public function pourProvisionExtourne(Provision $provision): Transaction
{
    $montant = (float) $provision->montant;
    $libelle = 'Extourne : '.$provision->libelle;
    $isDepense = $provision->type === TypeTransaction::Depense;
    $tenantId = (int) TenantContext::currentId();

    // Résolution des comptes — inverse de la dotation
    $compteDebit = Compte::where('association_id', $tenantId)
        ->where('numero_pcg', $isDepense ? '486' : '681')
        ->firstOrFail();

    $compteCredit = Compte::where('association_id', $tenantId)
        ->where('numero_pcg', $isDepense ? '781' : '487')
        ->firstOrFail();

    // Date extourne = 1er sept de l'exercice suivant
    $exerciceSuivant = $provision->exercice + 1;
    $dateExtourne = Carbon::create($exerciceSuivant, 9, 1);

    return DB::transaction(function () use ($provision, $montant, $libelle, $isDepense, $tenantId, $compteDebit, $compteCredit, $dateExtourne): Transaction {
        $numeroPiece = app(NumeroPieceService::class)->assign($dateExtourne);

        $transaction = Transaction::create([
            'association_id' => $tenantId,
            'type' => $isDepense ? TypeTransaction::Recette : TypeTransaction::Depense,
            'date' => $dateExtourne->format('Y-m-d'),
            'libelle' => $libelle,
            'montant_total' => $montant,
            'mode_paiement' => null,
            'saisi_par' => Auth::id(),
            'equilibree' => true,
            'type_ecriture' => 'extourne',
            'journal' => JournalComptable::Od,
            'numero_piece' => $numeroPiece,
            'provision_id' => $provision->id,
        ]);

        $ligneDebit = TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'compte_id' => $compteDebit->id,
            'debit' => $montant,
            'credit' => 0,
            'tiers_id' => null,
            'libelle' => $libelle,
            'montant' => 0,
            'sous_categorie_id' => null,
        ]);
        $ligneDebit->setRelation('compte', $compteDebit);

        $ligneCredit = TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'compte_id' => $compteCredit->id,
            'debit' => 0,
            'credit' => $montant,
            'tiers_id' => null,
            'libelle' => $libelle,
            'montant' => 0,
            'sous_categorie_id' => null,
        ]);
        $ligneCredit->setRelation('compte', $compteCredit);

        $lignes = collect([$ligneDebit, $ligneCredit]);
        $this->assertEquilibre($lignes);
        $this->assertTenantCoherence($lignes);

        return $transaction->load('lignes.compte');
    });
}
```

- [ ] **Step 7: Run all provision tests**

Run: `./vendor/bin/sail test tests/Feature/Services/Compta/ProvisionPDServiceTest.php`
Expected: all PASS

- [ ] **Step 8: Commit**

```bash
git add app/Services/Compta/EcritureGenerator.php tests/Feature/Services/Compta/ProvisionPDServiceTest.php
git commit -m "feat(compta-v5): EcritureGenerator pourProvisionDotation + pourProvisionExtourne"
```

---

### Task 4: ProvisionPDService — orchestrator

**Files:**
- Create: `app/Services/Compta/ProvisionPDService.php`
- Modify: `tests/Feature/Services/Compta/ProvisionPDServiceTest.php` (add orchestrator tests)

- [ ] **Step 1: Write the orchestrator tests**

Add to `tests/Feature/Services/Compta/ProvisionPDServiceTest.php`:

```php
use App\Services\Compta\ProvisionPDService;

test('ProvisionPDService::generer creates dotation + extourne for a dépense provision', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 2000.00,
        'libelle' => 'FNP assurance',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $service = app(ProvisionPDService::class);
    $service->generer($provision);

    $txs = Transaction::where('provision_id', $provision->id)->orderBy('date')->get();
    expect($txs)->toHaveCount(2);

    // Dotation (31 aug)
    $dotation = $txs->first(fn ($t) => $t->type_ecriture === 'normale');
    expect($dotation)->not->toBeNull();
    expect($dotation->date->format('Y-m-d'))->toBe('2026-08-31');
    expect($dotation->journal)->toBe(JournalComptable::Od);

    // Extourne (1 sept)
    $extourne = $txs->first(fn ($t) => $t->type_ecriture === 'extourne');
    expect($extourne)->not->toBeNull();
    expect($extourne->date->format('Y-m-d'))->toBe('2026-09-01');
});

test('ProvisionPDService::generer replaces existing TX on re-call', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 1000.00,
        'libelle' => 'FNP test',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $service = app(ProvisionPDService::class);
    $service->generer($provision);

    $oldIds = Transaction::where('provision_id', $provision->id)->pluck('id')->toArray();
    expect($oldIds)->toHaveCount(2);

    // Re-generate (simulates update)
    $service->generer($provision);

    // Old TX hard-deleted
    foreach ($oldIds as $id) {
        expect(Transaction::withTrashed()->find($id))->toBeNull();
    }

    // New TX created
    expect(Transaction::where('provision_id', $provision->id)->count())->toBe(2);
});

test('ProvisionPDService::supprimer removes all PD transactions', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'recette',
        'montant' => 500.00,
        'libelle' => 'PCA test',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $service = app(ProvisionPDService::class);
    $service->generer($provision);
    expect(Transaction::where('provision_id', $provision->id)->count())->toBe(2);

    $service->supprimer($provision);
    expect(Transaction::where('provision_id', $provision->id)->count())->toBe(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/Services/Compta/ProvisionPDServiceTest.php --filter="ProvisionPDService"`
Expected: FAIL — class `ProvisionPDService` not found.

- [ ] **Step 3: Create ProvisionPDService**

Create `app/Services/Compta/ProvisionPDService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use Illuminate\Support\Facades\DB;

final class ProvisionPDService
{
    public function __construct(
        private readonly EcritureGenerator $ecritureGenerator,
    ) {}

    public function generer(Provision $provision): void
    {
        DB::transaction(function () use ($provision): void {
            $this->supprimer($provision);

            $dotation = $this->ecritureGenerator->pourProvisionDotation($provision);
            PartieDoubleGuard::assertComplete($dotation);

            $extourne = $this->ecritureGenerator->pourProvisionExtourne($provision);
            PartieDoubleGuard::assertComplete($extourne);
        });
    }

    public function supprimer(Provision $provision): void
    {
        Transaction::where('provision_id', (int) $provision->id)->each(function (Transaction $tx): void {
            TransactionLigne::where('transaction_id', $tx->id)->delete();
            $tx->forceDelete();
        });
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Services/Compta/ProvisionPDServiceTest.php`
Expected: all PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Compta/ProvisionPDService.php tests/Feature/Services/Compta/ProvisionPDServiceTest.php
git commit -m "feat(compta-v5): ProvisionPDService — orchestrates dotation + extourne generation"
```

---

### Task 5: Wire ProvisionPDService into ProvisionIndex (save + delete)

**Files:**
- Modify: `app/Livewire/Provisions/ProvisionIndex.php:121-218`
- Modify: `tests/Feature/Livewire/ProvisionIndexTest.php` (adapt existing tests)

- [ ] **Step 1: Write integration tests**

Add to `tests/Feature/Services/Compta/ProvisionPDServiceTest.php`:

```php
use App\Livewire\Provisions\ProvisionIndex;
use App\Services\ExerciceService;
use Livewire\Livewire;

test('ProvisionIndex::save creates PD transactions on new provision', function () {
    $exerciceService = app(ExerciceService::class);
    $exercice = $exerciceService->current();

    $sc = \App\Models\SousCategorie::factory()->create([
        'association_id' => $this->association->id,
    ]);

    Livewire::test(ProvisionIndex::class)
        ->set('libelle', 'Test provision PD')
        ->set('sous_categorie_id', (string) $sc->id)
        ->set('type', 'depense')
        ->set('montant', '1200.50')
        ->call('save');

    $provision = Provision::where('libelle', 'Test provision PD')->first();
    expect($provision)->not->toBeNull();
    expect(Transaction::where('provision_id', $provision->id)->count())->toBe(2);
});

test('ProvisionIndex::delete removes PD transactions', function () {
    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => app(ExerciceService::class)->current(),
        'type' => 'depense',
        'montant' => 500.00,
        'libelle' => 'To delete',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    app(ProvisionPDService::class)->generer($provision);
    expect(Transaction::where('provision_id', $provision->id)->count())->toBe(2);

    Livewire::test(ProvisionIndex::class)
        ->call('delete', $provision->id);

    expect(Transaction::where('provision_id', $provision->id)->count())->toBe(0);
    expect(Provision::find($provision->id))->toBeNull();
});
```

- [ ] **Step 2: Wire save() in ProvisionIndex**

In `app/Livewire/Provisions/ProvisionIndex.php`, add the import at the top:

```php
use App\Services\Compta\ProvisionPDService;
```

In `save()`, after line 168 (the `Provision::create($data)` / `$provision->update($data)` block, BEFORE the piece_jointe block), add:

```php
app(ProvisionPDService::class)->generer($provision);
```

This must be placed after `$provision` is created/updated so the `provision_id` FK is valid, but before the flash message.

- [ ] **Step 3: Wire delete() in ProvisionIndex**

In `delete()`, change:

```php
Provision::findOrFail($id)->delete();
```

to:

```php
$provision = Provision::findOrFail($id);
app(ProvisionPDService::class)->supprimer($provision);
$provision->delete();
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Services/Compta/ProvisionPDServiceTest.php tests/Feature/Livewire/ProvisionIndexTest.php`
Expected: all PASS (existing ProvisionIndex tests may need `SystemeSeeder::seed()` in their setup — if they fail, add it)

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Provisions/ProvisionIndex.php tests/Feature/Services/Compta/ProvisionPDServiceTest.php
git commit -m "feat(compta-v5): wire ProvisionPDService into ProvisionIndex save + delete"
```

---

### Task 6: Adapt FluxTresorerieBuilder — prevent double-counting

**Files:**
- Modify: `app/Services/Rapports/FluxTresorerieBuilder.php:68-71`
- Modify: `tests/Feature/Services/Compta/ProvisionPDServiceTest.php` (add test)

- [ ] **Step 1: Write the test**

Add to `tests/Feature/Services/Compta/ProvisionPDServiceTest.php`:

```php
use App\Services\Rapports\FluxTresorerieBuilder;
use Illuminate\Support\Facades\Config;

test('FluxTresorerieBuilder does not double-count provisions in PD mode', function () {
    Config::set('compta.use_partie_double', true);

    $provision = Provision::factory()->create([
        'association_id' => $this->association->id,
        'exercice' => 2025,
        'type' => 'depense',
        'montant' => 1000.00,
        'libelle' => 'FNP test flux',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);

    app(ProvisionPDService::class)->generer($provision);

    $builder = app(FluxTresorerieBuilder::class);
    $result = $builder->build(2025);

    // In PD mode, total_provisions and total_extournes should be 0
    // (the PD transactions handle it via 681/486/781 accounts)
    expect((float) $result['synthese']['total_provisions'])->toBe(0.0);
    expect((float) $result['synthese']['total_extournes'])->toBe(0.0);
});
```

- [ ] **Step 2: Modify FluxTresorerieBuilder**

In `app/Services/Rapports/FluxTresorerieBuilder.php`, replace lines 68-71:

```php
// --- Provisions de fin d'exercice ---
$provisionService = app(ProvisionService::class);
$totalProvisions = $provisionService->totalProvisions($exercice);
$totalExtournes = $provisionService->totalExtournes($exercice);
```

with:

```php
// --- Provisions de fin d'exercice ---
if (Config::get('compta.use_partie_double', false)) {
    // PD mode: provisions are already in transactions as 681/486/781/487 entries.
    // The operationnel() scope excludes journal OD, so they don't affect
    // totalRecettes/totalDepenses. No separate totals needed.
    $totalProvisions = 0.0;
    $totalExtournes = 0.0;
} else {
    $provisionService = app(ProvisionService::class);
    $totalProvisions = $provisionService->totalProvisions($exercice);
    $totalExtournes = $provisionService->totalExtournes($exercice);
}
```

Add `use Illuminate\Support\Facades\Config;` at the top if not already imported.

- [ ] **Step 3: Run test**

Run: `./vendor/bin/sail test tests/Feature/Services/Compta/ProvisionPDServiceTest.php --filter="FluxTresorerieBuilder"`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Services/Rapports/FluxTresorerieBuilder.php tests/Feature/Services/Compta/ProvisionPDServiceTest.php
git commit -m "feat(compta-v5): FluxTresorerieBuilder skips ProvisionService totals in PD mode"
```

---

### Task 7: Adapt existing ProvisionIndex tests + CreatesPartieDoubleContext

**Files:**
- Modify: `tests/Feature/Livewire/ProvisionIndexTest.php` (add SystemeSeeder to setup)
- Modify: `tests/Support/CreatesPartieDoubleContext.php` (add provision account resolution helpers)

- [ ] **Step 1: Check and adapt ProvisionIndexTest**

The existing `ProvisionIndexTest` creates provisions via Livewire. Now that `save()` calls `ProvisionPDService::generer()`, it needs the system accounts (486/487/681/781) to exist. Add `SystemeSeeder::seed()` to the test's `beforeEach` if not already present.

Read `tests/Feature/Livewire/ProvisionIndexTest.php` and add to its `beforeEach`:

```php
use App\Services\Compta\Migrations\SystemeSeeder;

// In beforeEach:
SystemeSeeder::seed();
```

- [ ] **Step 2: Run existing provision tests**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ProvisionIndexTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Livewire/ProvisionIndexTest.php
git commit -m "fix(compta-v5): add SystemeSeeder to ProvisionIndexTest for PD accounts"
```

---

### Task 8: Full regression

**Files:** None (test-only)

- [ ] **Step 1: Run full suite**

Run: `./vendor/bin/sail test`
Expected: All tests pass (only pre-existing failures: `AddEquilibreeAndTypeEcritureToTransactionsTest`).

- [ ] **Step 2: Run Pint**

Run: `./vendor/bin/pint`
Fix any formatting issues.

- [ ] **Step 3: Run compta:assert-pd-complete (smoke test)**

Run: `./vendor/bin/sail artisan compta:assert-pd-complete --check`
Expected: exit 0 (all transactions including provision ones are valid).

- [ ] **Step 4: Commit if Pint made changes**

```bash
git add -A
git commit -m "style: fix Pint issues in provisions PD files"
```

---

### Task 9: Check Provision factory exists

**Files:**
- Possibly create: `database/factories/ProvisionFactory.php`

- [ ] **Step 1: Verify the factory exists**

Run: `ls database/factories/ProvisionFactory.php 2>/dev/null && echo EXISTS || echo MISSING`

If MISSING, create it:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Provision;
use App\Models\SousCategorie;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

final class ProvisionFactory extends Factory
{
    protected $model = Provision::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId(),
            'exercice' => 2025,
            'type' => $this->faker->randomElement(['depense', 'recette']),
            'sous_categorie_id' => SousCategorie::factory(),
            'libelle' => $this->faker->sentence(3),
            'montant' => $this->faker->randomFloat(2, 100, 5000),
            'date' => '2026-08-31',
            'saisi_par' => 1,
        ];
    }
}
```

If EXISTS, read it and ensure it has the required fields for our tests.

- [ ] **Step 2: Commit if created**

```bash
git add database/factories/ProvisionFactory.php
git commit -m "feat(compta-v5): add ProvisionFactory for provision PD tests"
```

**Execution order:** This task MUST be executed BEFORE Task 2, since Task 3 tests use `Provision::factory()`. Run this task first, then Tasks 1-8 in order.
