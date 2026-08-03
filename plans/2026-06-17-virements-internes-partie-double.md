# Virements internes partie double (512→512) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire `VirementInterne` into the partie double ledger — each virement generates a balanced 2-line entry (512X source C / 512X destination D) in the Banque journal.

**Architecture:** New method `EcritureGenerator::pourVirementInterne()` creates a `Transaction` header + 2 `TransactionLigne`. The `VirementInterneService` calls the generator on create/update/delete, guarded by `config('compta.use_partie_double')`. A new `TypeTransaction::Virement` case isolates these transactions from legacy views.

**Tech Stack:** Laravel 11, Pest PHP, MySQL (Sail)

**Spec:** `docs/specs/2026-06-17-virements-internes-partie-double.md`

---

## Task 1: Migration — add `virement_interne_id` FK on `transactions`

**Files:**
- Create: `database/migrations/2026_06_17_100000_add_virement_interne_id_to_transactions.php`

- [ ] **Step 1: Write the migration**

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
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('virement_interne_id')->nullable()->after('helloasso_cashout_id');
            $table->foreign('virement_interne_id')
                ->references('id')
                ->on('virements_internes')
                ->nullOnDelete();
            $table->index('virement_interne_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['virement_interne_id']);
            $table->dropIndex(['virement_interne_id']);
            $table->dropColumn('virement_interne_id');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `./vendor/bin/sail artisan migrate`
Expected: Migration runs successfully, no errors.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_17_100000_add_virement_interne_id_to_transactions.php
git commit -m "feat(compta-v5): migration — add virement_interne_id FK on transactions"
```

---

## Task 2: TypeTransaction::Virement + model relations

**Files:**
- Modify: `app/Enums/TypeTransaction.php`
- Modify: `app/Models/Transaction.php`
- Modify: `app/Models/VirementInterne.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php` with only the enum + relation tests for now:

```php
<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Tenant\TenantContext;

it('TypeTransaction has a Virement case with correct value and label', function () {
    $case = TypeTransaction::Virement;
    expect($case->value)->toBe('virement');
    expect($case->label())->toBe('Virement');
});

it('Transaction belongsTo VirementInterne via virement_interne_id', function () {
    $compteBancaire1 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);
    $compteBancaire2 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);

    $virement = VirementInterne::create([
        'association_id' => TenantContext::currentId(),
        'date' => '2026-01-15',
        'montant' => 500.00,
        'compte_source_id' => $compteBancaire1->id,
        'compte_destination_id' => $compteBancaire2->id,
        'numero_piece' => '2025-2026:99999',
        'saisi_par' => 1,
    ]);

    $transaction = Transaction::create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Virement,
        'date' => '2026-01-15',
        'libelle' => 'Virement interne',
        'montant_total' => 500.00,
        'saisi_par' => 1,
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => \App\Enums\JournalComptable::Banque,
        'numero_piece' => '2025-2026:99999',
        'virement_interne_id' => $virement->id,
    ]);

    expect($transaction->virementInterne)->toBeInstanceOf(VirementInterne::class);
    expect((int) $transaction->virementInterne->id)->toBe((int) $virement->id);

    expect($virement->fresh()->transaction)->toBeInstanceOf(Transaction::class);
    expect((int) $virement->fresh()->transaction->id)->toBe((int) $transaction->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php`
Expected: FAIL — `TypeTransaction::Virement` undefined, `virementInterne` relation missing.

- [ ] **Step 3: Add TypeTransaction::Virement**

In `app/Enums/TypeTransaction.php`, add the case and label:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeTransaction: string
{
    case Depense = 'depense';
    case Recette = 'recette';
    case Virement = 'virement';

    public function label(): string
    {
        return match ($this) {
            self::Depense => 'Dépense',
            self::Recette => 'Recette',
            self::Virement => 'Virement',
        };
    }
}
```

- [ ] **Step 4: Add Transaction::virementInterne() relation + fillable**

In `app/Models/Transaction.php`:

Add `'virement_interne_id'` to the `$fillable` array (after `'helloasso_cashout_id'`).

Add the `'virement_interne_id' => 'integer'` cast in `casts()`.

Add the relation method:

```php
public function virementInterne(): BelongsTo
{
    return $this->belongsTo(VirementInterne::class);
}
```

- [ ] **Step 5: Update Transaction::booted() for Virement journal default**

In `app/Models/Transaction.php`, the `booted()` `creating` hook defaults journal based on type. Update it to handle Virement (which should NOT get a Vente/Achat default — it always gets Banque from the generator, but the guard prevents a confusing fallback):

```php
protected static function booted(): void
{
    parent::booted();

    self::creating(function (Transaction $transaction): void {
        if ($transaction->journal !== null) {
            return;
        }
        $transaction->journal = match ($transaction->type) {
            TypeTransaction::Recette => JournalComptable::Vente,
            TypeTransaction::Depense => JournalComptable::Achat,
            TypeTransaction::Virement => JournalComptable::Banque,
        };
    });
}
```

- [ ] **Step 6: Add VirementInterne::transaction() relation**

In `app/Models/VirementInterne.php`, add:

```php
use Illuminate\Database\Eloquent\Relations\HasOne;
```

And the relation:

```php
public function transaction(): HasOne
{
    return $this->hasOne(Transaction::class, 'virement_interne_id');
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php`
Expected: 2 tests PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Enums/TypeTransaction.php app/Models/Transaction.php app/Models/VirementInterne.php tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php
git commit -m "feat(compta-v5): TypeTransaction::Virement + relations Transaction↔VirementInterne"
```

---

## Task 3: EcritureGenerator::pourVirementInterne()

**Files:**
- Modify: `app/Services/Compta/EcritureGenerator.php`
- Test: `tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php`

- [ ] **Step 1: Write the failing test — happy path**

Append to `tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php`:

```php
use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Models\Compte;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

function creerDeuxComptesBancairesAvec512(): array
{
    $cb1 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);
    $cb2 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);

    BancairesSeeder::seed();

    $compte512Source = Compte::where('compte_bancaire_id', $cb1->id)
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();
    $compte512Dest = Compte::where('compte_bancaire_id', $cb2->id)
        ->where('association_id', TenantContext::currentId())
        ->firstOrFail();

    return [$cb1, $cb2, $compte512Source, $compte512Dest];
}

function creerVirement(CompteBancaire $source, CompteBancaire $dest, float $montant = 1000.00): VirementInterne
{
    return VirementInterne::create([
        'association_id' => TenantContext::currentId(),
        'date' => '2026-01-15',
        'montant' => $montant,
        'compte_source_id' => $source->id,
        'compte_destination_id' => $dest->id,
        'reference' => 'VIR-TEST-001',
        'numero_piece' => '2025-2026:00042',
        'saisi_par' => User::factory()->create()->id,
    ]);
}

// ---------------------------------------------------------------------------
// beforeEach
// ---------------------------------------------------------------------------

beforeEach(function () {
    SystemeSeeder::seed();
});

// ---------------------------------------------------------------------------
// Tests EcritureGenerator::pourVirementInterne
// ---------------------------------------------------------------------------

it('generates a balanced 2-line entry for a virement interne', function () {
    [$cb1, $cb2, $compte512Source, $compte512Dest] = creerDeuxComptesBancairesAvec512();
    $virement = creerVirement($cb1, $cb2);

    $generator = app(EcritureGenerator::class);
    $transaction = $generator->pourVirementInterne($virement);

    // Transaction header
    expect($transaction)->toBeInstanceOf(Transaction::class);
    expect($transaction->type)->toBe(TypeTransaction::Virement);
    expect((float) $transaction->montant_total)->toBe(1000.00);
    expect($transaction->mode_paiement)->toBe(ModePaiement::Virement);
    expect($transaction->type_ecriture)->toBe('normale');
    expect($transaction->journal)->toBe(JournalComptable::Banque);
    expect($transaction->numero_piece)->toBe('2025-2026:00042');
    expect((int) $transaction->virement_interne_id)->toBe((int) $virement->id);
    expect($transaction->equilibree)->toBeTrue();

    // 2 lignes exactement
    $lignes = $transaction->lignes;
    expect($lignes)->toHaveCount(2);

    // Ligne débit = destination (argent arrive)
    $ligneDebit = $lignes->firstWhere('debit', '>', 0);
    expect($ligneDebit)->not->toBeNull();
    expect((int) $ligneDebit->compte_id)->toBe((int) $compte512Dest->id);
    expect((float) $ligneDebit->debit)->toBe(1000.00);
    expect((float) $ligneDebit->credit)->toBe(0.00);
    expect($ligneDebit->tiers_id)->toBeNull();
    expect($ligneDebit->sous_categorie_id)->toBeNull();
    expect($ligneDebit->lettrage_code)->toBeNull();

    // Ligne crédit = source (argent part)
    $ligneCredit = $lignes->firstWhere('credit', '>', 0);
    expect($ligneCredit)->not->toBeNull();
    expect((int) $ligneCredit->compte_id)->toBe((int) $compte512Source->id);
    expect((float) $ligneCredit->debit)->toBe(0.00);
    expect((float) $ligneCredit->credit)->toBe(1000.00);
    expect($ligneCredit->tiers_id)->toBeNull();
    expect($ligneCredit->sous_categorie_id)->toBeNull();
    expect($ligneCredit->lettrage_code)->toBeNull();
});

it('uses the virement reference as libelle when present', function () {
    [$cb1, $cb2] = creerDeuxComptesBancairesAvec512();
    $virement = creerVirement($cb1, $cb2);

    $transaction = app(EcritureGenerator::class)->pourVirementInterne($virement);

    expect($transaction->libelle)->toBe('VIR-TEST-001');
});

it('defaults libelle to "Virement interne" when reference is null', function () {
    [$cb1, $cb2] = creerDeuxComptesBancairesAvec512();
    $virement = creerVirement($cb1, $cb2);
    $virement->update(['reference' => null]);

    $transaction = app(EcritureGenerator::class)->pourVirementInterne($virement->fresh());

    expect($transaction->libelle)->toBe('Virement interne');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php --filter="generates a balanced"`
Expected: FAIL — method `pourVirementInterne` does not exist.

- [ ] **Step 3: Implement EcritureGenerator::pourVirementInterne()**

Add this method to `app/Services/Compta/EcritureGenerator.php`, inside the class, after the last `pour*()` method (before the private helpers section):

```php
/**
 * Génère l'écriture pour un virement interne : 512X source C / 512X destination D.
 *
 * 2 lignes, journal Banque, pas de tiers, pas de lettrage, pas de portage.
 * Le numero_piece est repris du VirementInterne (un seul fait comptable).
 *
 * Résolution des comptes 512X : Compte::where('compte_bancaire_id', $cbId)->bancaires().
 * Exception si un des deux comptes est introuvable (erreur de configuration).
 */
public function pourVirementInterne(\App\Models\VirementInterne $virement): Transaction
{
    $montant = (float) $virement->montant;
    $libelle = $virement->reference ?: 'Virement interne';

    // --- Résolution des comptes 512X ---
    $compte512Source = Compte::where('compte_bancaire_id', (int) $virement->compte_source_id)
        ->bancaires()
        ->first();

    if ($compte512Source === null) {
        throw new \RuntimeException(
            "Compte 512X introuvable pour CompteBancaire #{$virement->compte_source_id} (source). Configurez le plan comptable."
        );
    }

    $compte512Dest = Compte::where('compte_bancaire_id', (int) $virement->compte_destination_id)
        ->bancaires()
        ->first();

    if ($compte512Dest === null) {
        throw new \RuntimeException(
            "Compte 512X introuvable pour CompteBancaire #{$virement->compte_destination_id} (destination). Configurez le plan comptable."
        );
    }

    // --- Invariant : comptes distincts ---
    if ((int) $compte512Source->id === (int) $compte512Dest->id) {
        throw new \InvalidArgumentException(
            "Virement interne : les comptes source et destination sont identiques (Compte #{$compte512Source->id})."
        );
    }

    // --- Création dans une transaction DB ---
    return DB::transaction(function () use ($virement, $montant, $libelle, $compte512Source, $compte512Dest): Transaction {
        $transaction = Transaction::create([
            'association_id' => (int) TenantContext::currentId(),
            'type' => TypeTransaction::Virement,
            'date' => $virement->date->format('Y-m-d'),
            'libelle' => $libelle,
            'montant_total' => $montant,
            'mode_paiement' => ModePaiement::Virement,
            'saisi_par' => Auth::id(),
            'equilibree' => true,
            'type_ecriture' => 'normale',
            'journal' => JournalComptable::Banque,
            'numero_piece' => $virement->numero_piece,
            'virement_interne_id' => $virement->id,
        ]);

        // Ligne débit — destination (argent arrive)
        $ligneDebit = TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'compte_id' => $compte512Dest->id,
            'debit' => $montant,
            'credit' => 0,
            'tiers_id' => null,
            'libelle' => $libelle,
            'montant' => 0,
            'sous_categorie_id' => null,
        ]);
        $ligneDebit->setRelation('compte', $compte512Dest);

        // Ligne crédit — source (argent part)
        $ligneCredit = TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'compte_id' => $compte512Source->id,
            'debit' => 0,
            'credit' => $montant,
            'tiers_id' => null,
            'libelle' => $libelle,
            'montant' => 0,
            'sous_categorie_id' => null,
        ]);
        $ligneCredit->setRelation('compte', $compte512Source);

        // --- Assertions paranoïaques ---
        $lignes = collect([$ligneDebit, $ligneCredit]);
        $this->assertEquilibre($lignes);
        $this->assertTenantCoherence($lignes);
        $this->assertPasDeTiersSurClasse5($lignes);

        // Reload avec lignes pour que le caller puisse les lire
        return $transaction->load('lignes.compte');
    });
}
```

Add the missing import at the top of `EcritureGenerator.php` if not already present:

```php
use App\Models\VirementInterne;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php`
Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Compta/EcritureGenerator.php tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php
git commit -m "feat(compta-v5): EcritureGenerator::pourVirementInterne — 512 source C / 512 dest D"
```

---

## Task 4: Error cases — missing 512X, same account

**Files:**
- Test: `tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php`

- [ ] **Step 1: Write the failing tests for error cases**

Append to the test file:

```php
it('throws RuntimeException when source 512X is missing', function () {
    // cb1 without 512X, cb2 with 512X
    $cb1 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);
    $cb2 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);

    // Only seed 512X for cb2 — manually create just one
    BancairesSeeder::seed();
    // Delete the 512X for cb1
    Compte::where('compte_bancaire_id', $cb1->id)->delete();

    $virement = creerVirement($cb1, $cb2);

    app(EcritureGenerator::class)->pourVirementInterne($virement);
})->throws(\RuntimeException::class, 'source');

it('throws RuntimeException when destination 512X is missing', function () {
    $cb1 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);
    $cb2 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);

    BancairesSeeder::seed();
    // Delete the 512X for cb2
    Compte::where('compte_bancaire_id', $cb2->id)->delete();

    $virement = creerVirement($cb1, $cb2);

    app(EcritureGenerator::class)->pourVirementInterne($virement);
})->throws(\RuntimeException::class, 'destination');

it('throws InvalidArgumentException when source and destination resolve to the same 512X', function () {
    $cb1 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);

    BancairesSeeder::seed();

    // Same CompteBancaire on both sides
    $virement = creerVirement($cb1, $cb1);

    app(EcritureGenerator::class)->pourVirementInterne($virement);
})->throws(\InvalidArgumentException::class, 'identiques');
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php`
Expected: all tests PASS (implementation already handles these cases).

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php
git commit -m "test(compta-v5): error cases — missing 512X, same account virement"
```

---

## Task 5: Wire VirementInterneService — create with PD

**Files:**
- Modify: `app/Services/VirementInterneService.php`
- Test: `tests/Feature/VirementInterneTest.php` (or new test file)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/VirementInternePDTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\VirementInterneService;
use App\Tenant\TenantContext;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function creerCompteBancairesPD(): array
{
    $cb1 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);
    $cb2 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);

    BancairesSeeder::seed();

    return [$cb1, $cb2];
}

// ---------------------------------------------------------------------------
// beforeEach
// ---------------------------------------------------------------------------

beforeEach(function () {
    SystemeSeeder::seed();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('create() generates a PD transaction when use_partie_double is true', function () {
    config(['compta.use_partie_double' => true]);
    [$cb1, $cb2] = creerCompteBancairesPD();

    $service = app(VirementInterneService::class);
    $virement = $service->create([
        'date' => '2026-01-15',
        'montant' => 750.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
        'reference' => 'VIR-001',
    ]);

    // Transaction PD exists
    $transaction = Transaction::where('virement_interne_id', $virement->id)->first();
    expect($transaction)->not->toBeNull();
    expect($transaction->type)->toBe(TypeTransaction::Virement);
    expect($transaction->journal)->toBe(JournalComptable::Banque);
    expect((float) $transaction->montant_total)->toBe(750.00);
    expect($transaction->numero_piece)->toBe($virement->numero_piece);

    // 2 lignes
    expect($transaction->lignes)->toHaveCount(2);
});

it('create() does NOT generate a PD transaction when use_partie_double is false', function () {
    config(['compta.use_partie_double' => false]);
    [$cb1, $cb2] = creerCompteBancairesPD();

    $service = app(VirementInterneService::class);
    $virement = $service->create([
        'date' => '2026-01-15',
        'montant' => 750.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
    ]);

    expect(Transaction::where('virement_interne_id', $virement->id)->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/VirementInternePDTest.php --filter="generates a PD"`
Expected: FAIL — no Transaction created.

- [ ] **Step 3: Wire VirementInterneService::create()**

In `app/Services/VirementInterneService.php`, add the PD call:

Add at top:
```php
use App\Services\Compta\EcritureGenerator;
```

Modify the `create()` method:

```php
public function create(array $data): VirementInterne
{
    $this->exerciceService->assertOuvert(
        $this->exerciceService->anneeForDate(CarbonImmutable::parse($data['date']))
    );

    return DB::transaction(function () use ($data) {
        $data['saisi_par'] = auth()->id();
        $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));

        $virement = VirementInterne::create($data);

        if (config('compta.use_partie_double')) {
            app(EcritureGenerator::class)->pourVirementInterne($virement);
        }

        return $virement;
    });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/VirementInternePDTest.php`
Expected: both tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/VirementInterneService.php tests/Feature/VirementInternePDTest.php
git commit -m "feat(compta-v5): wire VirementInterneService::create with PD generation"
```

---

## Task 6: Wire VirementInterneService — update with PD

**Files:**
- Modify: `app/Services/VirementInterneService.php`
- Test: `tests/Feature/VirementInternePDTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/VirementInternePDTest.php`:

```php
it('update() recreates the PD transaction with new values', function () {
    config(['compta.use_partie_double' => true]);
    [$cb1, $cb2] = creerCompteBancairesPD();

    $service = app(VirementInterneService::class);
    $virement = $service->create([
        'date' => '2026-01-15',
        'montant' => 750.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
    ]);

    $oldTransactionId = Transaction::where('virement_interne_id', $virement->id)->first()->id;

    $virement = $service->update($virement, [
        'date' => '2026-01-16',
        'montant' => 1200.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
    ]);

    // Old transaction deleted
    expect(Transaction::withTrashed()->find($oldTransactionId))->toBeNull();

    // New transaction exists
    $newTx = Transaction::where('virement_interne_id', $virement->id)->first();
    expect($newTx)->not->toBeNull();
    expect((float) $newTx->montant_total)->toBe(1200.00);
    expect($newTx->date->format('Y-m-d'))->toBe('2026-01-16');
    expect($newTx->lignes)->toHaveCount(2);
});

it('update() works when PD is off (no transaction to delete)', function () {
    config(['compta.use_partie_double' => false]);
    [$cb1, $cb2] = creerCompteBancairesPD();

    $service = app(VirementInterneService::class);
    $virement = $service->create([
        'date' => '2026-01-15',
        'montant' => 750.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
    ]);

    $virement = $service->update($virement, [
        'date' => '2026-01-16',
        'montant' => 1200.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
    ]);

    expect((float) $virement->montant)->toBe(1200.00);
    expect(Transaction::where('virement_interne_id', $virement->id)->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/VirementInternePDTest.php --filter="update"`
Expected: FAIL — old transaction still exists.

- [ ] **Step 3: Wire VirementInterneService::update()**

Modify the `update()` method in `app/Services/VirementInterneService.php`:

```php
public function update(VirementInterne $virement, array $data): VirementInterne
{
    $this->exerciceService->assertOuvert(
        $this->exerciceService->anneeForDate(CarbonImmutable::parse($data['date']))
    );

    return DB::transaction(function () use ($virement, $data) {
        // Supprimer la Transaction PD liée si elle existe (delete-then-recreate)
        $existingTx = Transaction::where('virement_interne_id', $virement->id)->first();
        if ($existingTx !== null) {
            $existingTx->lignes()->forceDelete();
            $existingTx->forceDelete();
        }

        $virement->update($data);
        $virement = $virement->fresh();

        if (config('compta.use_partie_double')) {
            app(EcritureGenerator::class)->pourVirementInterne($virement);
        }

        return $virement;
    });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/VirementInternePDTest.php`
Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/VirementInterneService.php tests/Feature/VirementInternePDTest.php
git commit -m "feat(compta-v5): wire VirementInterneService::update — delete+recreate PD"
```

---

## Task 7: Wire VirementInterneService — delete with PD

**Files:**
- Modify: `app/Services/VirementInterneService.php`
- Test: `tests/Feature/VirementInternePDTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/VirementInternePDTest.php`:

```php
it('delete() removes the PD transaction along with the virement', function () {
    config(['compta.use_partie_double' => true]);
    [$cb1, $cb2] = creerCompteBancairesPD();

    $service = app(VirementInterneService::class);
    $virement = $service->create([
        'date' => '2026-01-15',
        'montant' => 750.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
    ]);

    $transactionId = Transaction::where('virement_interne_id', $virement->id)->first()->id;

    $service->delete($virement);

    // Virement soft-deleted
    expect(VirementInterne::find($virement->id))->toBeNull();

    // Transaction hard-deleted (PD transactions use forceDelete)
    expect(Transaction::withTrashed()->find($transactionId))->toBeNull();

    // Lignes cascade-deleted (FK constraint)
    expect(\App\Models\TransactionLigne::where('transaction_id', $transactionId)->exists())->toBeFalse();
});

it('delete() works when PD is off (no transaction to delete)', function () {
    config(['compta.use_partie_double' => false]);
    [$cb1, $cb2] = creerCompteBancairesPD();

    $service = app(VirementInterneService::class);
    $virement = $service->create([
        'date' => '2026-01-15',
        'montant' => 750.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
    ]);

    $service->delete($virement);

    expect(VirementInterne::find($virement->id))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/VirementInternePDTest.php --filter="delete"`
Expected: FAIL — Transaction still exists after delete.

- [ ] **Step 3: Wire VirementInterneService::delete()**

Modify the `delete()` method in `app/Services/VirementInterneService.php`:

```php
public function delete(VirementInterne $virement): void
{
    $this->exerciceService->assertOuvert(
        $this->exerciceService->anneeForDate(CarbonImmutable::parse($virement->date))
    );

    if ($virement->rapprochement_source_id !== null || $virement->rapprochement_destination_id !== null) {
        throw new \RuntimeException('Ce virement est pointé dans un rapprochement et ne peut pas être supprimé.');
    }

    if (RemiseBancaire::where('virement_id', $virement->id)->exists()) {
        throw new \RuntimeException('Ce virement est lié à une remise bancaire et ne peut pas être supprimé.');
    }

    DB::transaction(function () use ($virement) {
        // Supprimer la Transaction PD liée si elle existe
        $existingTx = Transaction::where('virement_interne_id', $virement->id)->first();
        if ($existingTx !== null) {
            $existingTx->lignes()->forceDelete();
            $existingTx->forceDelete();
        }

        $virement->delete();
    });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/VirementInternePDTest.php`
Expected: all tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/VirementInterneService.php tests/Feature/VirementInternePDTest.php
git commit -m "feat(compta-v5): wire VirementInterneService::delete — cleanup PD transaction"
```

---

## Task 8: Guard legacy views against TypeTransaction::Virement

**Files:**
- Modify: `app/Models/Transaction.php` (montantSigne, sensTresorerie)
- Test: `tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php`

- [ ] **Step 1: Write the test for montantSigne and sensTresorerie**

Append to `tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php`:

```php
it('Transaction::montantSigne returns positive for Virement type', function () {
    $tx = new Transaction();
    $tx->type = TypeTransaction::Virement;
    $tx->montant_total = 500.00;

    // Virements are transfers, not expenses — positive amount
    expect($tx->montantSigne())->toBe(500.00);
});

it('Transaction::sensTresorerie returns Recette for normal Virement', function () {
    $tx = new Transaction();
    $tx->type = TypeTransaction::Virement;
    $tx->type_ecriture = 'normale';

    // Virements don't have a natural sens, but Recette keeps montantSigne positive
    expect($tx->sensTresorerie())->toBe(\App\Enums\Sens::Recette);
});

it('scopeOperationnel excludes Virement transactions (journal=Banque)', function () {
    [$cb1, $cb2, $compte512Source, $compte512Dest] = creerDeuxComptesBancairesAvec512();
    $virement = creerVirement($cb1, $cb2);

    $transaction = app(EcritureGenerator::class)->pourVirementInterne($virement);

    // scopeOperationnel filters by journal IN (Vente, Achat) — Banque is excluded
    $found = Transaction::operationnel()->where('id', $transaction->id)->exists();
    expect($found)->toBeFalse();
});
```

- [ ] **Step 2: Run tests — montantSigne and sensTresorerie may need updating**

Run: `./vendor/bin/sail test tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php --filter="montantSigne|sensTresorerie|scopeOperationnel"`

If `montantSigne()` or `sensTresorerie()` fail, update them.

- [ ] **Step 3: Update montantSigne() if needed**

In `app/Models/Transaction.php`, `montantSigne()` currently returns negative for Depense, positive otherwise. Since `Virement` is not `Depense`, it already returns positive. Verify and adjust if the test passes as-is.

- [ ] **Step 4: Update sensTresorerie() if needed**

In `app/Models/Transaction.php`, `sensTresorerie()` currently treats non-Recette as Depense. For Virement, this returns `Sens::Depense` which conflicts with our test. Update:

```php
public function sensTresorerie(): Sens
{
    $sensNaturel = match ($this->type) {
        TypeTransaction::Recette => Sens::Recette,
        TypeTransaction::Depense => Sens::Depense,
        TypeTransaction::Virement => Sens::Recette,
    };

    return $this->type_ecriture === 'extourne'
        ? ($sensNaturel === Sens::Recette ? Sens::Depense : Sens::Recette)
        : $sensNaturel;
}
```

- [ ] **Step 5: Run all tests to verify nothing breaks**

Run: `./vendor/bin/sail test tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php`
Expected: all tests PASS.

Run: `./vendor/bin/sail test --filter="sensTresorerie|montantSigne"` to check no regressions.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Transaction.php tests/Unit/Services/Compta/EcritureGeneratorPourVirementInterneTest.php
git commit -m "fix(compta-v5): guard montantSigne/sensTresorerie for TypeTransaction::Virement"
```

---

## Task 9: Full regression — run entire test suite

**Files:** None (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `./vendor/bin/sail test`
Expected: suite passes (same known failures as before — `CompteTest::lettrables` and `EmargementRoundTripTest` pre-existing).

- [ ] **Step 2: Check for any TypeTransaction match regressions**

Scan for any `match ($this->type)` or `match ($transaction->type)` that might not handle the new `Virement` case:

```bash
grep -rn "match.*->type\b" app/ --include='*.php' | grep -v vendor | grep -v test
```

Fix any exhaustive match that doesn't handle `Virement`.

- [ ] **Step 3: Commit any fixes if needed**

```bash
git add -A
git commit -m "fix(compta-v5): handle TypeTransaction::Virement in exhaustive matches"
```

---

## Summary

| Task | Description | Commit message |
|------|-------------|----------------|
| 1 | Migration `virement_interne_id` FK | `feat: migration — add virement_interne_id FK` |
| 2 | `TypeTransaction::Virement` + relations | `feat: TypeTransaction::Virement + relations` |
| 3 | `EcritureGenerator::pourVirementInterne()` | `feat: EcritureGenerator::pourVirementInterne` |
| 4 | Error case tests (missing 512X, same account) | `test: error cases virement` |
| 5 | Wire `create()` with PD | `feat: wire create with PD` |
| 6 | Wire `update()` with PD | `feat: wire update — delete+recreate PD` |
| 7 | Wire `delete()` with PD | `feat: wire delete — cleanup PD` |
| 8 | Guard legacy views (montantSigne, sensTresorerie) | `fix: guard for TypeTransaction::Virement` |
| 9 | Full regression suite | verification only |
