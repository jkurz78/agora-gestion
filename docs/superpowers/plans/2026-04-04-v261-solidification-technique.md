# v2.6.1 — Solidification technique

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix bugs (NPE, XSS), optimise N+1 queries, add performance indexes, introduce a role-based access system, refactor RapportService, and add comprehensive non-regression tests.

**Architecture:** Two lots — Lot 1 (corrections & sécurité) delivers immediate bug fixes and a temporary admin middleware; Lot 2 (rôles & fondations) replaces that middleware with a full enum-based role system, policies, and refactored report builders. All wrapped in one v2.6.1 release.

**Tech Stack:** Laravel 11, Livewire 4, Pest PHP, MySQL, Bootstrap 5

---

## LOT 1 — Corrections & Sécurité

### Task 1: Fix NPE in FormulaireToken::isExpire()

**Files:**
- Modify: `app/Models/FormulaireToken.php:36-39`
- Test: `tests/Unit/FormulaireTokenTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/FormulaireTokenTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\FormulaireToken;

it('returns true when expire_at is in the past', function () {
    $token = new FormulaireToken(['expire_at' => now()->subDay()]);
    expect($token->isExpire())->toBeTrue();
});

it('returns false when expire_at is in the future', function () {
    $token = new FormulaireToken(['expire_at' => now()->addDay()]);
    expect($token->isExpire())->toBeFalse();
});

it('returns false when expire_at is null', function () {
    $token = new FormulaireToken(['expire_at' => null]);
    expect($token->isExpire())->toBeFalse();
});

it('isValide returns false when expire_at is null', function () {
    $token = new FormulaireToken(['expire_at' => null, 'rempli_at' => null]);
    expect($token->isValide())->toBeFalse();
});

it('isValide returns false when already used', function () {
    $token = new FormulaireToken([
        'expire_at' => now()->addDay(),
        'rempli_at' => now(),
    ]);
    expect($token->isValide())->toBeFalse();
});

it('isValide returns true when not expired and not used', function () {
    $token = new FormulaireToken([
        'expire_at' => now()->addDay(),
        'rempli_at' => null,
    ]);
    expect($token->isValide())->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Unit/FormulaireTokenTest.php`
Expected: FAIL on "returns false when expire_at is null" — `Call to a member function lt() on null`

- [ ] **Step 3: Fix isExpire()**

In `app/Models/FormulaireToken.php`, replace lines 36-39:

```php
public function isExpire(): bool
{
    return $this->expire_at !== null && $this->expire_at->lt(today());
}
```

- [ ] **Step 4: Run test to verify all pass**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Unit/FormulaireTokenTest.php`
Expected: 6 tests PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/FormulaireTokenTest.php app/Models/FormulaireToken.php
git commit -m "fix: prevent NPE in FormulaireToken::isExpire() when expire_at is null"
```

---

### Task 2: Fix XSS in medical notes

**Files:**
- Modify: `app/Models/ParticipantDonneesMedicales.php` — add `sanitizeNotes()` static method
- Modify: `app/Livewire/ParticipantTable.php:249` — apply sanitisation at save
- Modify: `resources/views/livewire/participant-table.blade.php:315` — escape preview bubble
- Test: `tests/Unit/ParticipantDonneesMedicalesSanitizeTest.php` (create)

Note: The `{!! $medNotes !!}` at line 443 (contenteditable editor) and PDF views (`pdf/participant-fiche.blade.php:322`, `pdf/participants-annuaire.blade.php:236`) are acceptable because content is now sanitised at save time.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ParticipantDonneesMedicalesSanitizeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\ParticipantDonneesMedicales;

it('strips script tags from notes', function () {
    $dirty = '<p>Notes</p><script>alert("xss")</script>';
    expect(ParticipantDonneesMedicales::sanitizeNotes($dirty))
        ->toBe('<p>Notes</p>alert("xss")');
});

it('strips event handlers from tags', function () {
    $dirty = '<p onmouseover="alert(1)">Texte</p>';
    // strip_tags keeps allowed tags but removes attributes inherently? No, strip_tags keeps attributes.
    // We need to also strip dangerous attributes. Let's verify.
    $result = ParticipantDonneesMedicales::sanitizeNotes($dirty);
    expect($result)->not->toContain('onmouseover');
});

it('preserves allowed formatting tags', function () {
    $html = '<p>Texte <strong>gras</strong> et <em>italique</em></p><ul><li>Item</li></ul>';
    expect(ParticipantDonneesMedicales::sanitizeNotes($html))->toBe($html);
});

it('strips iframe tags', function () {
    $dirty = '<p>Text</p><iframe src="evil.com"></iframe>';
    expect(ParticipantDonneesMedicales::sanitizeNotes($dirty))
        ->toBe('<p>Text</p>');
});

it('returns empty string for empty input', function () {
    expect(ParticipantDonneesMedicales::sanitizeNotes(''))->toBe('');
});

it('strips img tags with onerror', function () {
    $dirty = '<img src=x onerror=alert(1)>';
    expect(ParticipantDonneesMedicales::sanitizeNotes($dirty))->toBe('');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Unit/ParticipantDonneesMedicalesSanitizeTest.php`
Expected: FAIL — `sanitizeNotes()` method not found

- [ ] **Step 3: Add sanitizeNotes() to model**

In `app/Models/ParticipantDonneesMedicales.php`, add before the `participant()` method (before line 62):

```php
    /**
     * Sanitise le HTML des notes médicales : ne garde que les balises de mise en forme.
     */
    public static function sanitizeNotes(string $html): string
    {
        // Strip all tags except basic formatting
        $clean = strip_tags($html, '<p><br><strong><em><b><i><u><ul><ol><li>');

        // Remove any event handler attributes (on*="...")
        return (string) preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $clean);
    }
```

- [ ] **Step 4: Run test to verify all pass**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Unit/ParticipantDonneesMedicalesSanitizeTest.php`
Expected: 6 tests PASS

- [ ] **Step 5: Apply sanitisation in ParticipantTable::saveNotes()**

In `app/Livewire/ParticipantTable.php`, replace line 249:

```php
        $med->update(['notes' => $this->medNotes !== '' ? $this->medNotes : null]);
```

With:

```php
        $notes = $this->medNotes !== '' ? ParticipantDonneesMedicales::sanitizeNotes($this->medNotes) : null;
        $med->update(['notes' => $notes]);
```

Add the import at the top of the file if not already present:
```php
use App\Models\ParticipantDonneesMedicales;
```

- [ ] **Step 6: Escape the preview bubble in blade**

In `resources/views/livewire/participant-table.blade.php`, replace line 315:

```blade
                                        <span class="notes-preview-bubble">{!! Str::limit($hasNotes, 300) !!}</span>
```

With:

```blade
                                        <span class="notes-preview-bubble">{!! nl2br(e(Str::limit(strip_tags($hasNotes), 300))) !!}</span>
```

- [ ] **Step 7: Run full test suite to verify no regression**

Run: `./vendor/bin/sail exec laravel.test php artisan test`
Expected: All tests PASS

- [ ] **Step 8: Commit**

```bash
git add app/Models/ParticipantDonneesMedicales.php app/Livewire/ParticipantTable.php resources/views/livewire/participant-table.blade.php tests/Unit/ParticipantDonneesMedicalesSanitizeTest.php
git commit -m "fix(security): sanitise medical notes HTML to prevent XSS"
```

---

### Task 3: Fix N+1 in RemiseBancaireService

**Files:**
- Modify: `app/Services/RemiseBancaireService.php:148-163,256-262`
- Test: `tests/Feature/Services/RemiseBancaireServiceBulkDeleteTest.php` (create)

- [ ] **Step 1: Write the non-regression test**

Create `tests/Feature/Services/RemiseBancaireServiceBulkDeleteTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Models\VirementInterne;
use App\Services\RemiseBancaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(RemiseBancaireService::class);
});

it('supprimer deletes all transactions, lignes, and affectations for a remise', function () {
    // Setup: create a remise with 2 transactions, each with 1 ligne and 1 affectation
    $compte = CompteBancaire::factory()->create(['est_systeme' => true, 'nom' => 'Remises en banque']);
    $compteBanque = CompteBancaire::factory()->create(['est_systeme' => false]);
    $virement = VirementInterne::factory()->create();
    $remise = RemiseBancaire::factory()->create([
        'mode_paiement' => ModePaiement::Cheque,
        'virement_id' => $virement->id,
    ]);

    $txIds = [];
    for ($i = 0; $i < 3; $i++) {
        $tx = Transaction::factory()->create([
            'remise_id' => $remise->id,
            'compte_id' => $compte->id,
            'type' => TypeTransaction::Recette,
        ]);
        $ligne = TransactionLigne::create([
            'transaction_id' => $tx->id,
            'sous_categorie_id' => 1,
            'montant' => 50.00,
        ]);
        TransactionLigneAffectation::create([
            'transaction_ligne_id' => $ligne->id,
            'operation_id' => 1,
            'montant' => 50.00,
        ]);
        $txIds[] = $tx->id;
    }

    // Act
    $this->service->supprimer($remise);

    // Assert: all soft-deleted
    expect(Transaction::whereIn('id', $txIds)->count())->toBe(0);
    expect(Transaction::withTrashed()->whereIn('id', $txIds)->count())->toBe(3);

    // Lignes and affectations hard-deleted
    $ligneIds = TransactionLigne::withTrashed()
        ->whereIn('transaction_id', $txIds)->pluck('id');
    expect(TransactionLigneAffectation::whereIn('transaction_ligne_id', $ligneIds)->count())->toBe(0);
});

it('updateContenu removes transactions for removed reglements', function () {
    // This test verifies the bulk delete in updateContenu works correctly
    $compte = CompteBancaire::factory()->create(['est_systeme' => true, 'nom' => 'Remises en banque']);
    $compteBanque = CompteBancaire::factory()->create(['est_systeme' => false]);
    $virement = VirementInterne::factory()->create();
    $remise = RemiseBancaire::factory()->create([
        'mode_paiement' => ModePaiement::Cheque,
        'virement_id' => $virement->id,
    ]);

    // Create a reglement linked to the remise with a transaction
    $reglement = Reglement::factory()->create([
        'remise_id' => $remise->id,
        'mode_paiement' => ModePaiement::Cheque,
    ]);
    $tx = Transaction::factory()->create([
        'remise_id' => $remise->id,
        'reglement_id' => $reglement->id,
        'compte_id' => $compte->id,
        'type' => TypeTransaction::Recette,
    ]);
    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => 1,
        'montant' => 50.00,
    ]);
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => 1,
        'montant' => 50.00,
    ]);

    // Act: update with empty list (removes the reglement)
    $this->service->updateContenu($remise, []);

    // Assert: reglement unlinked
    expect($reglement->fresh()->remise_id)->toBeNull();
    // Transaction force-deleted (not soft-deleted)
    expect(Transaction::withTrashed()->where('id', $tx->id)->count())->toBe(0);
});
```

Note: This test may need factory adjustments depending on existing factories. The agent implementing this should verify factory availability for `RemiseBancaire`, `VirementInterne`, `Reglement` and create simple ones if missing. The important thing is that the test verifies the same records are deleted as before.

- [ ] **Step 2: Run test to verify current behaviour passes**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/Services/RemiseBancaireServiceBulkDeleteTest.php`
Expected: Tests should PASS with the current N+1 code (behaviour is correct, just slow)

- [ ] **Step 3: Refactor supprimer() to use bulk deletes**

In `app/Services/RemiseBancaireService.php`, replace lines 255-262:

```php
            // Soft-delete all transactions
            Transaction::where('remise_id', $remise->id)->each(function (Transaction $tx) {
                $tx->lignes()->each(function ($ligne) {
                    $ligne->affectations()->delete();
                    $ligne->delete();
                });
                $tx->delete();
            });
```

With:

```php
            // Bulk-delete all transactions, lignes, and affectations
            $txIds = Transaction::where('remise_id', $remise->id)->pluck('id');
            if ($txIds->isNotEmpty()) {
                $ligneIds = TransactionLigne::whereIn('transaction_id', $txIds)->pluck('id');
                if ($ligneIds->isNotEmpty()) {
                    TransactionLigneAffectation::whereIn('transaction_ligne_id', $ligneIds)->delete();
                    TransactionLigne::whereIn('id', $ligneIds)->delete();
                }
                Transaction::whereIn('id', $txIds)->delete();
            }
```

Add imports at top if not present:
```php
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
```

- [ ] **Step 4: Refactor updateContenu() removal loop to use bulk deletes**

In `app/Services/RemiseBancaireService.php`, replace lines 148-164 (the `foreach ($toRemove ...)` block):

```php
            foreach ($toRemove as $reglementId) {
                $reglement = Reglement::findOrFail($reglementId);
                $reglement->update(['remise_id' => null]);

                $transaction = Transaction::where('remise_id', $remise->id)
                    ->where('reglement_id', $reglementId)
                    ->first();

                if ($transaction) {
                    $transaction->lignes()->each(function ($ligne) {
                        $ligne->affectations()->delete();
                        $ligne->delete();
                    });
                    $transaction->forceDelete();
                }
            }
```

With:

```php
            if (count($toRemove) > 0) {
                Reglement::whereIn('id', $toRemove)->update(['remise_id' => null]);

                $txToRemove = Transaction::where('remise_id', $remise->id)
                    ->whereIn('reglement_id', $toRemove)
                    ->pluck('id');

                if ($txToRemove->isNotEmpty()) {
                    $ligneIds = TransactionLigne::whereIn('transaction_id', $txToRemove)->pluck('id');
                    if ($ligneIds->isNotEmpty()) {
                        TransactionLigneAffectation::whereIn('transaction_ligne_id', $ligneIds)->delete();
                        TransactionLigne::whereIn('id', $ligneIds)->delete();
                    }
                    Transaction::whereIn('id', $txToRemove)->forceDelete();
                }
            }
```

- [ ] **Step 5: Run test to verify refactored code passes**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/Services/RemiseBancaireServiceBulkDeleteTest.php`
Expected: All tests PASS

- [ ] **Step 6: Run full RemiseBancaire test suite**

Run: `./vendor/bin/sail exec laravel.test php artisan test --filter=RemiseBancaire`
Expected: All existing tests PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/RemiseBancaireService.php tests/Feature/Services/RemiseBancaireServiceBulkDeleteTest.php
git commit -m "perf: replace N+1 loops with bulk deletes in RemiseBancaireService"
```

---

### Task 4: Add performance indexes

**Files:**
- Create: `database/migrations/2026_04_04_100001_add_performance_indexes.php`
- Test: `tests/Feature/Migrations/PerformanceIndexesMigrationTest.php` (create)

- [ ] **Step 1: Create the migration**

Run: `./vendor/bin/sail artisan make:migration add_performance_indexes`

Then replace the generated content with:

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
        Schema::table('transaction_lignes', function (Blueprint $table) {
            $table->index(['transaction_id', 'sous_categorie_id'], 'tl_tx_sc_idx');
            $table->index('operation_id', 'tl_operation_idx');
        });

        Schema::table('transaction_ligne_affectations', function (Blueprint $table) {
            $table->index('transaction_ligne_id', 'tla_tl_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table) {
            $table->dropIndex('tl_tx_sc_idx');
            $table->dropIndex('tl_operation_idx');
        });

        Schema::table('transaction_ligne_affectations', function (Blueprint $table) {
            $table->dropIndex('tla_tl_idx');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `./vendor/bin/sail artisan migrate`
Expected: Migration runs successfully

- [ ] **Step 3: Write migration test**

Create `tests/Feature/Migrations/PerformanceIndexesMigrationTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('has composite index on transaction_lignes(transaction_id, sous_categorie_id)', function () {
    $indexes = Schema::getIndexes('transaction_lignes');
    $indexColumns = collect($indexes)->pluck('columns')->map(fn ($cols) => implode(',', $cols))->toArray();
    expect($indexColumns)->toContain('transaction_id,sous_categorie_id');
});

it('has index on transaction_lignes(operation_id)', function () {
    $indexes = Schema::getIndexes('transaction_lignes');
    $indexColumns = collect($indexes)->pluck('columns')->map(fn ($cols) => implode(',', $cols))->toArray();
    expect($indexColumns)->toContain('operation_id');
});

it('has index on transaction_ligne_affectations(transaction_ligne_id)', function () {
    $indexes = Schema::getIndexes('transaction_ligne_affectations');
    $indexColumns = collect($indexes)->pluck('columns')->map(fn ($cols) => implode(',', $cols))->toArray();
    expect($indexColumns)->toContain('transaction_ligne_id');
});
```

- [ ] **Step 4: Run migration test**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/Migrations/PerformanceIndexesMigrationTest.php`
Expected: 3 tests PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/*add_performance_indexes* tests/Feature/Migrations/PerformanceIndexesMigrationTest.php
git commit -m "perf: add indexes on transaction_lignes and affectations for report queries"
```

---

### Task 5: Run Pint + full test suite for Lot 1

- [ ] **Step 1: Run Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 2: Run full test suite**

Run: `./vendor/bin/sail exec laravel.test php artisan test`
Expected: All tests PASS

- [ ] **Step 3: Commit any Pint fixes**

```bash
git add -A
git commit -m "style: apply Pint formatting after lot 1 fixes"
```

---

## LOT 2 — Rôles & Fondations

### Task 6: Create Role enum

**Files:**
- Create: `app/Enums/Role.php`
- Test: `tests/Unit/RoleEnumTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/RoleEnumTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Espace;
use App\Enums\Role;

it('has four cases', function () {
    expect(Role::cases())->toHaveCount(4);
});

it('admin can read and write both espaces', function () {
    expect(Role::Admin->canRead(Espace::Compta))->toBeTrue();
    expect(Role::Admin->canWrite(Espace::Compta))->toBeTrue();
    expect(Role::Admin->canRead(Espace::Gestion))->toBeTrue();
    expect(Role::Admin->canWrite(Espace::Gestion))->toBeTrue();
    expect(Role::Admin->canAccessParametres())->toBeTrue();
});

it('comptable can read+write compta, read-only gestion, no parametres', function () {
    expect(Role::Comptable->canRead(Espace::Compta))->toBeTrue();
    expect(Role::Comptable->canWrite(Espace::Compta))->toBeTrue();
    expect(Role::Comptable->canRead(Espace::Gestion))->toBeTrue();
    expect(Role::Comptable->canWrite(Espace::Gestion))->toBeFalse();
    expect(Role::Comptable->canAccessParametres())->toBeFalse();
});

it('gestionnaire can read+write gestion, read-only compta, no parametres', function () {
    expect(Role::Gestionnaire->canRead(Espace::Compta))->toBeTrue();
    expect(Role::Gestionnaire->canWrite(Espace::Compta))->toBeFalse();
    expect(Role::Gestionnaire->canRead(Espace::Gestion))->toBeTrue();
    expect(Role::Gestionnaire->canWrite(Espace::Gestion))->toBeTrue();
    expect(Role::Gestionnaire->canAccessParametres())->toBeFalse();
});

it('consultation can only read both espaces, no write, no parametres', function () {
    expect(Role::Consultation->canRead(Espace::Compta))->toBeTrue();
    expect(Role::Consultation->canWrite(Espace::Compta))->toBeFalse();
    expect(Role::Consultation->canRead(Espace::Gestion))->toBeTrue();
    expect(Role::Consultation->canWrite(Espace::Gestion))->toBeFalse();
    expect(Role::Consultation->canAccessParametres())->toBeFalse();
});

it('provides a French label for each role', function () {
    expect(Role::Admin->label())->toBe('Administrateur');
    expect(Role::Comptable->label())->toBe('Comptable');
    expect(Role::Gestionnaire->label())->toBe('Gestionnaire');
    expect(Role::Consultation->label())->toBe('Consultation');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Unit/RoleEnumTest.php`
Expected: FAIL — `Role` class not found

- [ ] **Step 3: Create the Role enum**

Create `app/Enums/Role.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Comptable = 'comptable';
    case Gestionnaire = 'gestionnaire';
    case Consultation = 'consultation';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::Comptable => 'Comptable',
            self::Gestionnaire => 'Gestionnaire',
            self::Consultation => 'Consultation',
        };
    }

    public function canRead(Espace $espace): bool
    {
        // All roles can read all espaces
        return true;
    }

    public function canWrite(Espace $espace): bool
    {
        return match ($this) {
            self::Admin => true,
            self::Comptable => $espace === Espace::Compta,
            self::Gestionnaire => $espace === Espace::Gestion,
            self::Consultation => false,
        };
    }

    public function canAccessParametres(): bool
    {
        return $this === self::Admin;
    }
}
```

- [ ] **Step 4: Run test to verify all pass**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Unit/RoleEnumTest.php`
Expected: 6 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Enums/Role.php tests/Unit/RoleEnumTest.php
git commit -m "feat: add Role enum with access matrix (admin, comptable, gestionnaire, consultation)"
```

---

### Task 7: Add role column to users + update model & factory & seeder

**Files:**
- Create: migration `add_role_to_users`
- Modify: `app/Models/User.php` — add role to fillable, casts
- Modify: `database/factories/UserFactory.php` — add role default
- Modify: `database/seeders/DatabaseSeeder.php` — set admin role on admin user
- Test: `tests/Unit/UserRoleTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/UserRoleTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults to admin role for new users', function () {
    $user = User::factory()->create();
    expect($user->role)->toBe(Role::Admin);
});

it('casts role to Role enum', function () {
    $user = User::factory()->create(['role' => 'comptable']);
    expect($user->role)->toBe(Role::Comptable);
    expect($user->role)->toBeInstanceOf(Role::class);
});

it('includes role in fillable', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    expect($user->role)->toBe(Role::Consultation);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Unit/UserRoleTest.php`
Expected: FAIL — column `role` does not exist

- [ ] **Step 3: Create the migration**

Run: `./vendor/bin/sail artisan make:migration add_role_to_users`

Replace content:

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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('admin')->after('peut_voir_donnees_sensibles');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
```

- [ ] **Step 4: Update User model**

In `app/Models/User.php`:

Add import:
```php
use App\Enums\Role;
```

Add `'role'` to `$fillable` array (after `peut_voir_donnees_sensibles`).

Add to `casts()` return array:
```php
'role' => Role::class,
```

- [ ] **Step 5: Update UserFactory**

In `database/factories/UserFactory.php`, add to the `definition()` return array:

```php
'role' => Role::Admin,
```

Add import:
```php
use App\Enums\Role;
```

- [ ] **Step 6: Update DatabaseSeeder**

In `database/seeders/DatabaseSeeder.php`, add `'role' => 'admin'` to the first user creation (admin@svs.fr) and `'role' => 'gestionnaire'` to the second user (jean@svs.fr) for testing purposes.

- [ ] **Step 7: Run migration and tests**

Run: `./vendor/bin/sail artisan migrate && ./vendor/bin/sail exec laravel.test php artisan test tests/Unit/UserRoleTest.php`
Expected: 3 tests PASS

- [ ] **Step 8: Commit**

```bash
git add database/migrations/*add_role_to_users* app/Models/User.php database/factories/UserFactory.php database/seeders/DatabaseSeeder.php tests/Unit/UserRoleTest.php
git commit -m "feat: add role column to users with enum cast and factory default"
```

---

### Task 8: Create CheckEspaceAccess middleware

**Files:**
- Create: `app/Http/Middleware/CheckEspaceAccess.php`
- Modify: `routes/web.php` — add middleware to route groups
- Test: `tests/Feature/Middleware/CheckEspaceAccessTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Middleware/CheckEspaceAccessTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows admin to access compta routes', function () {
    $user = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($user)->get(route('compta.dashboard'))->assertOk();
});

it('allows admin to access gestion routes', function () {
    $user = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($user)->get(route('gestion.dashboard'))->assertOk();
});

it('allows admin to access parametres routes', function () {
    $user = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($user)->get(route('compta.parametres.utilisateurs.index'))->assertOk();
});

it('allows comptable to access compta routes', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);
    $this->actingAs($user)->get(route('compta.dashboard'))->assertOk();
});

it('allows comptable to access gestion routes (read)', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);
    $this->actingAs($user)->get(route('gestion.dashboard'))->assertOk();
});

it('denies comptable access to parametres', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);
    $this->actingAs($user)->get(route('compta.parametres.utilisateurs.index'))->assertStatus(403);
});

it('allows gestionnaire to access gestion routes', function () {
    $user = User::factory()->create(['role' => Role::Gestionnaire]);
    $this->actingAs($user)->get(route('gestion.dashboard'))->assertOk();
});

it('allows gestionnaire to access compta routes (read)', function () {
    $user = User::factory()->create(['role' => Role::Gestionnaire]);
    $this->actingAs($user)->get(route('compta.dashboard'))->assertOk();
});

it('denies gestionnaire access to parametres', function () {
    $user = User::factory()->create(['role' => Role::Gestionnaire]);
    $this->actingAs($user)->get(route('gestion.parametres.utilisateurs.index'))->assertStatus(403);
});

it('allows consultation to read compta', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    $this->actingAs($user)->get(route('compta.dashboard'))->assertOk();
});

it('allows consultation to read gestion', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    $this->actingAs($user)->get(route('gestion.dashboard'))->assertOk();
});

it('denies consultation access to parametres', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    $this->actingAs($user)->get(route('compta.parametres.utilisateurs.index'))->assertStatus(403);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/Middleware/CheckEspaceAccessTest.php`
Expected: Tests for parametres denial should FAIL (currently no middleware blocks access)

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/CheckEspaceAccess.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CheckEspaceAccess
{
    public function handle(Request $request, Closure $next, string $level = 'read'): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        if ($level === 'parametres') {
            if (! $user->role->canAccessParametres()) {
                abort(403, 'Accès réservé aux administrateurs.');
            }

            return $next($request);
        }

        // For espace-level checks, the espace is resolved by DetecteEspace middleware
        // which runs before this one and sets the request attribute
        $espace = $request->attributes->get('espace');

        if ($espace && ! $user->role->canRead($espace)) {
            abort(403, 'Vous n\'avez pas accès à cet espace.');
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Apply middleware to routes**

In `routes/web.php`, modify the `$registerParametres` closure (line 44-66).

Wrap the `Route::prefix('parametres')` group at line 45 with the middleware:

Replace:
```php
    Route::prefix('parametres')->name('parametres.')->group(function (): void {
```

With:
```php
    Route::prefix('parametres')->name('parametres.')->middleware(CheckEspaceAccess::class.':parametres')->group(function (): void {
```

Add at the top of `routes/web.php`:
```php
use App\Http\Middleware\CheckEspaceAccess;
```

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/Middleware/CheckEspaceAccessTest.php`
Expected: All 12 tests PASS

- [ ] **Step 6: Run full test suite**

Run: `./vendor/bin/sail exec laravel.test php artisan test`
Expected: All tests PASS (existing tests use admin role by default)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/CheckEspaceAccess.php routes/web.php tests/Feature/Middleware/CheckEspaceAccessTest.php
git commit -m "feat: add CheckEspaceAccess middleware to restrict parametres to admins"
```

---

### Task 9: Create Policies

**Files:**
- Create: `app/Policies/OperationPolicy.php`
- Create: `app/Policies/TransactionPolicy.php`
- Create: `app/Policies/FacturePolicy.php`
- Create: `app/Policies/TiersPolicy.php`
- Create: `app/Policies/UserPolicy.php`
- Test: `tests/Feature/Policies/PolicyAccessTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Policies/PolicyAccessTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Espace;
use App\Enums\Role;
use App\Models\Facture;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Operation (Gestion espace) ──

it('admin can create operations', function () {
    $user = User::factory()->create(['role' => Role::Admin]);
    expect($user->can('create', Operation::class))->toBeTrue();
});

it('gestionnaire can create operations', function () {
    $user = User::factory()->create(['role' => Role::Gestionnaire]);
    expect($user->can('create', Operation::class))->toBeTrue();
});

it('comptable cannot create operations', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);
    expect($user->can('create', Operation::class))->toBeFalse();
});

it('consultation cannot create operations', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    expect($user->can('create', Operation::class))->toBeFalse();
});

it('all roles can view operations', function () {
    foreach (Role::cases() as $role) {
        $user = User::factory()->create(['role' => $role]);
        expect($user->can('viewAny', Operation::class))->toBeTrue(
            "Role {$role->value} should be able to view operations"
        );
    }
});

// ── Transaction (Compta espace) ──

it('admin can create transactions', function () {
    $user = User::factory()->create(['role' => Role::Admin]);
    expect($user->can('create', Transaction::class))->toBeTrue();
});

it('comptable can create transactions', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);
    expect($user->can('create', Transaction::class))->toBeTrue();
});

it('gestionnaire cannot create transactions', function () {
    $user = User::factory()->create(['role' => Role::Gestionnaire]);
    expect($user->can('create', Transaction::class))->toBeFalse();
});

it('consultation cannot create transactions', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    expect($user->can('create', Transaction::class))->toBeFalse();
});

// ── Facture (Compta espace) ──

it('admin can create factures', function () {
    $user = User::factory()->create(['role' => Role::Admin]);
    expect($user->can('create', Facture::class))->toBeTrue();
});

it('comptable can create factures', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);
    expect($user->can('create', Facture::class))->toBeTrue();
});

it('gestionnaire cannot create factures', function () {
    $user = User::factory()->create(['role' => Role::Gestionnaire]);
    expect($user->can('create', Facture::class))->toBeFalse();
});

// ── Tiers (both espaces) ──

it('admin can create tiers', function () {
    $user = User::factory()->create(['role' => Role::Admin]);
    expect($user->can('create', Tiers::class))->toBeTrue();
});

it('comptable can create tiers', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);
    expect($user->can('create', Tiers::class))->toBeTrue();
});

it('gestionnaire can create tiers', function () {
    $user = User::factory()->create(['role' => Role::Gestionnaire]);
    expect($user->can('create', Tiers::class))->toBeTrue();
});

it('consultation cannot create tiers', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    expect($user->can('create', Tiers::class))->toBeFalse();
});

// ── User (Parametres / Admin only) ──

it('admin can manage users', function () {
    $user = User::factory()->create(['role' => Role::Admin]);
    expect($user->can('create', User::class))->toBeTrue();
    expect($user->can('viewAny', User::class))->toBeTrue();
});

it('non-admin cannot manage users', function () {
    foreach ([Role::Comptable, Role::Gestionnaire, Role::Consultation] as $role) {
        $user = User::factory()->create(['role' => $role]);
        expect($user->can('create', User::class))->toBeFalse(
            "Role {$role->value} should not create users"
        );
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/Policies/PolicyAccessTest.php`
Expected: FAIL — no policies registered, all `can()` checks return false or true incorrectly

- [ ] **Step 3: Create OperationPolicy**

Create `app/Policies/OperationPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Espace;
use App\Models\Operation;
use App\Models\User;

final class OperationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canRead(Espace::Gestion);
    }

    public function view(User $user, Operation $operation): bool
    {
        return $user->role->canRead(Espace::Gestion);
    }

    public function create(User $user): bool
    {
        return $user->role->canWrite(Espace::Gestion);
    }

    public function update(User $user, Operation $operation): bool
    {
        return $user->role->canWrite(Espace::Gestion);
    }

    public function delete(User $user, Operation $operation): bool
    {
        return $user->role->canWrite(Espace::Gestion);
    }
}
```

- [ ] **Step 4: Create TransactionPolicy**

Create `app/Policies/TransactionPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Espace;
use App\Models\Transaction;
use App\Models\User;

final class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canRead(Espace::Compta);
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $user->role->canRead(Espace::Compta);
    }

    public function create(User $user): bool
    {
        return $user->role->canWrite(Espace::Compta);
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return $user->role->canWrite(Espace::Compta);
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $user->role->canWrite(Espace::Compta);
    }
}
```

- [ ] **Step 5: Create FacturePolicy**

Create `app/Policies/FacturePolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Espace;
use App\Models\Facture;
use App\Models\User;

final class FacturePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canRead(Espace::Compta);
    }

    public function view(User $user, Facture $facture): bool
    {
        return $user->role->canRead(Espace::Compta);
    }

    public function create(User $user): bool
    {
        return $user->role->canWrite(Espace::Compta);
    }

    public function update(User $user, Facture $facture): bool
    {
        return $user->role->canWrite(Espace::Compta);
    }

    public function delete(User $user, Facture $facture): bool
    {
        return $user->role->canWrite(Espace::Compta);
    }
}
```

- [ ] **Step 6: Create TiersPolicy**

Create `app/Policies/TiersPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Espace;
use App\Models\Tiers;
use App\Models\User;

final class TiersPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Tiers $tiers): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->canWrite(Espace::Compta) || $user->role->canWrite(Espace::Gestion);
    }

    public function update(User $user, Tiers $tiers): bool
    {
        return $user->role->canWrite(Espace::Compta) || $user->role->canWrite(Espace::Gestion);
    }

    public function delete(User $user, Tiers $tiers): bool
    {
        return $user->role->canWrite(Espace::Compta) || $user->role->canWrite(Espace::Gestion);
    }
}
```

- [ ] **Step 7: Create UserPolicy**

Create `app/Policies/UserPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canAccessParametres();
    }

    public function view(User $user, User $model): bool
    {
        return $user->role->canAccessParametres();
    }

    public function create(User $user): bool
    {
        return $user->role->canAccessParametres();
    }

    public function update(User $user, User $model): bool
    {
        return $user->role->canAccessParametres();
    }

    public function delete(User $user, User $model): bool
    {
        return $user->role->canAccessParametres() && $user->id !== $model->id;
    }
}
```

- [ ] **Step 8: Run tests**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/Policies/PolicyAccessTest.php`
Expected: All tests PASS (Laravel auto-discovers policies by convention)

- [ ] **Step 9: Commit**

```bash
git add app/Policies/ tests/Feature/Policies/PolicyAccessTest.php
git commit -m "feat: add authorization policies for Operation, Transaction, Facture, Tiers, User"
```

---

### Task 10: Integrate roles in Livewire components (canEdit computed property)

**Files:**
- Modify: Key Livewire components to expose `$canEdit` and gate write actions
- Test: `tests/Feature/Livewire/RoleWriteProtectionTest.php` (create)

The goal is to add a `getCanEditProperty()` method to the main Livewire components and protect their write actions. The agent implementing this task should:

- [ ] **Step 1: Identify all Livewire components with write actions**

Grep for public methods that call `->create(`, `->update(`, `->delete(`, `->save()` in `app/Livewire/`. The key components to protect are:
- `TransactionForm.php` — create/update transactions
- `TransactionList.php` — delete transactions
- `ParticipantTable.php` — add/remove participants, save notes
- `ReglementTable.php` — add/update/delete reglements
- `OperationDetail.php` — update operation details
- `FactureShow.php` — update facture
- `RemiseBancaireDetail.php` — update/delete remise

- [ ] **Step 2: Write the test**

Create `tests/Feature/Livewire/RoleWriteProtectionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('consultation user cannot access transaction create form', function () {
    $user = User::factory()->create(['role' => Role::Consultation]);
    $this->actingAs($user);

    // The component should expose canEdit = false
    $component = Livewire\Livewire::test(\App\Livewire\TransactionList::class);
    expect($component->get('canEdit'))->toBeFalse();
});

it('comptable user can access transaction create form', function () {
    $user = User::factory()->create(['role' => Role::Comptable]);
    $this->actingAs($user);

    $component = Livewire\Livewire::test(\App\Livewire\TransactionList::class);
    expect($component->get('canEdit'))->toBeTrue();
});

it('gestionnaire user cannot edit transactions', function () {
    $user = User::factory()->create(['role' => Role::Gestionnaire]);
    $this->actingAs($user);

    $component = Livewire\Livewire::test(\App\Livewire\TransactionList::class);
    expect($component->get('canEdit'))->toBeFalse();
});
```

- [ ] **Step 3: Add canEdit to Livewire components**

For each component identified in Step 1, add:

```php
use Illuminate\Support\Facades\Auth;
use App\Enums\Espace;

// Add as a computed property:
public function getCanEditProperty(): bool
{
    return Auth::user()->role->canWrite(Espace::Compta); // or Espace::Gestion depending on the component
}
```

Components in Compta espace: `TransactionForm`, `TransactionList`, `FactureShow`, `BudgetTable`, `RapprochementDetail`
Components in Gestion espace: `ParticipantTable`, `ReglementTable`, `OperationDetail`, `RemiseBancaireDetail`

- [ ] **Step 4: Guard write actions**

In each component's write methods, add an early return:

```php
public function someWriteAction(): void
{
    if (! $this->canEdit) {
        return;
    }
    // existing code...
}
```

- [ ] **Step 5: Update blade views to hide write buttons**

In the corresponding blade views, wrap action buttons with `@if($this->canEdit)`:

```blade
@if($this->canEdit)
    <button wire:click="delete({{ $item->id }})" ...>Supprimer</button>
@endif
```

- [ ] **Step 6: Run tests**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/Livewire/RoleWriteProtectionTest.php`
Expected: All tests PASS

- [ ] **Step 7: Run full test suite**

Run: `./vendor/bin/sail exec laravel.test php artisan test`
Expected: All tests PASS

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/ resources/views/livewire/ tests/Feature/Livewire/RoleWriteProtectionTest.php
git commit -m "feat: add canEdit role-based write protection to Livewire components"
```

---

### Task 11: Add role management to the Users UI

**Files:**
- Modify: `app/Http/Controllers/UserController.php` — add role to store/update
- Modify: `resources/views/parametres/utilisateurs/index.blade.php` — add role select
- Test: `tests/Feature/UserRoleManagementTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/UserRoleManagementTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a user with a role', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);

    $this->actingAs($admin)->post(route('compta.parametres.utilisateurs.store'), [
        'nom' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'comptable',
    ])->assertRedirect();

    expect(User::where('email', 'test@example.com')->first()->role)->toBe(Role::Comptable);
});

it('can update a user role', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $target = User::factory()->create(['role' => Role::Comptable]);

    $this->actingAs($admin)->put(route('compta.parametres.utilisateurs.update', $target), [
        'nom' => $target->nom,
        'email' => $target->email,
        'role' => 'gestionnaire',
    ])->assertRedirect();

    expect($target->fresh()->role)->toBe(Role::Gestionnaire);
});

it('defaults to admin role if not specified', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);

    $this->actingAs($admin)->post(route('compta.parametres.utilisateurs.store'), [
        'nom' => 'Default Role',
        'email' => 'default@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect();

    expect(User::where('email', 'default@example.com')->first()->role)->toBe(Role::Admin);
});

it('validates role must be a valid enum value', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);

    $this->actingAs($admin)->post(route('compta.parametres.utilisateurs.store'), [
        'nom' => 'Test',
        'email' => 'bad@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'superadmin',
    ])->assertSessionHasErrors('role');
});

it('shows role column in user list', function () {
    $admin = User::factory()->create(['role' => Role::Admin]);
    $comptable = User::factory()->create(['role' => Role::Comptable]);

    $this->actingAs($admin)
        ->get(route('compta.parametres.utilisateurs.index'))
        ->assertSee('Administrateur')
        ->assertSee('Comptable');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/UserRoleManagementTest.php`
Expected: FAIL — role not handled in controller

- [ ] **Step 3: Update UserController**

In `app/Http/Controllers/UserController.php`:

Add import:
```php
use App\Enums\Role;
use Illuminate\Validation\Rule;
```

In `store()`, add to validation rules:
```php
'role' => ['nullable', Rule::enum(Role::class)],
```

In `store()`, add `'role'` to the `User::create()` array:
```php
'role' => Role::tryFrom($validated['role'] ?? '') ?? Role::Admin,
```

In `update()`, add to validation rules:
```php
'role' => ['nullable', Rule::enum(Role::class)],
```

In `update()`, add before `$utilisateur->save()`:
```php
$utilisateur->role = Role::tryFrom($validated['role'] ?? '') ?? $utilisateur->role;
```

- [ ] **Step 4: Update the blade view**

In `resources/views/parametres/utilisateurs/index.blade.php`:

Update the table header (line 64) to add a "Rôle" column:
```blade
<tr><th>Nom</th><th>Email</th><th>Rôle</th><th style="width:100px;"></th></tr>
```

In the table body, add the role cell after email (after line 70):
```blade
<td><span class="badge bg-secondary">{{ $utilisateur->role->label() }}</span></td>
```

Update the colspan on the edit row (line 93) from 3 to 4.

In the add form, add a role select (insert before the sensible checkbox col):
```blade
<div class="col-md-2">
    <label class="form-label">Rôle</label>
    <select name="role" class="form-select">
        @foreach(\App\Enums\Role::cases() as $r)
            <option value="{{ $r->value }}" {{ old('role', 'admin') === $r->value ? 'selected' : '' }}>
                {{ $r->label() }}
            </option>
        @endforeach
    </select>
</div>
```

In the edit form, add the same select with current value:
```blade
<div class="col-md-2">
    <label class="form-label">Rôle</label>
    <select name="role" class="form-select">
        @foreach(\App\Enums\Role::cases() as $r)
            <option value="{{ $r->value }}" {{ $utilisateur->role === $r ? 'selected' : '' }}>
                {{ $r->label() }}
            </option>
        @endforeach
    </select>
</div>
```

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/UserRoleManagementTest.php`
Expected: All 5 tests PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/UserController.php resources/views/parametres/utilisateurs/index.blade.php tests/Feature/UserRoleManagementTest.php
git commit -m "feat: add role management to users admin screen"
```

---

### Task 12: Refactor RapportService into builders

**Files:**
- Create: `app/Services/Rapports/CompteResultatBuilder.php`
- Create: `app/Services/Rapports/FluxTresorerieBuilder.php`
- Modify: `app/Services/RapportService.php` — delegate to builders, keep toCsv()
- Test: existing tests must continue to pass (non-regression)

- [ ] **Step 1: Create the Rapports directory**

Run: `mkdir -p app/Services/Rapports`

- [ ] **Step 2: Extract CompteResultatBuilder**

Create `app/Services/Rapports/CompteResultatBuilder.php` containing:
- The 3 public methods: `compteDeResultat()`, `compteDeResultatOperations()`, `rapportSeances()`
- All 17 private methods they depend on: `exerciceDates()`, `fetchDepenseRows()`, `accumulerDepensesResolues()`, `fetchDepenseSeancesRows()`, `accumulerDepensesSeancesResolues()`, `fetchProduitsRows()`, `accumulerRecettesResolues()`, `accumulerRecettesSeancesResolues()`, `fetchProduitsSeancesRows()`, `buildOperationQueries()`, `fetchOperationRows()`, `buildHierarchyOperations()`, `buildHierarchyFull()`, `buildHierarchySimple()`, `buildHierarchySeances()`, `groupByCategorie()`, `fetchBudgetMap()`, `formatTiersLabel()`

The class is a plain service (no constructor dependencies):
```php
<?php

declare(strict_types=1);

namespace App\Services\Rapports;

// ... all the use statements from RapportService that these methods need

final class CompteResultatBuilder
{
    // Paste all 3 public methods + 17 private methods directly from RapportService
    // No logic changes whatsoever — pure copy-paste extraction
}
```

Copy every method exactly as-is from `RapportService.php` (lines 26-948). Do not change any logic, variable names, or method signatures.

- [ ] **Step 3: Extract FluxTresorerieBuilder**

Create `app/Services/Rapports/FluxTresorerieBuilder.php` containing:
- The `fluxTresorerie()` public method
- The `exerciceDates()` private method (duplicated — it's small and avoids coupling)

```php
<?php

declare(strict_types=1);

namespace App\Services\Rapports;

// ... use statements

final class FluxTresorerieBuilder
{
    public function fluxTresorerie(int $exercice): array
    {
        // Exact copy from RapportService lines 952-1124
    }

    private function exerciceDates(int $exercice): array
    {
        // Exact copy from RapportService lines 149-157
    }
}
```

- [ ] **Step 4: Rewrite RapportService as facade**

Replace `app/Services/RapportService.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Rapports\CompteResultatBuilder;
use App\Services\Rapports\FluxTresorerieBuilder;

final class RapportService
{
    public function __construct(
        private readonly CompteResultatBuilder $compteResultat,
        private readonly FluxTresorerieBuilder $fluxTresorerie,
    ) {}

    public function compteDeResultat(int $exercice): array
    {
        return $this->compteResultat->compteDeResultat($exercice);
    }

    public function compteDeResultatOperations(int $exercice, array $operationIds, bool $parSeances = false, bool $parTiers = false): array
    {
        return $this->compteResultat->compteDeResultatOperations($exercice, $operationIds, $parSeances, $parTiers);
    }

    public function rapportSeances(int $exercice, array $operationIds): array
    {
        return $this->compteResultat->rapportSeances($exercice, $operationIds);
    }

    public function fluxTresorerie(int $exercice): array
    {
        return $this->fluxTresorerie->fluxTresorerie($exercice);
    }

    public function toCsv(array $rows, array $headers): string
    {
        // Keep toCsv() directly here — it's a utility, not a builder
        // Exact copy from original lines 132-144
    }
}
```

- [ ] **Step 5: Run all existing RapportService tests**

Run: `./vendor/bin/sail exec laravel.test php artisan test --filter=Rapport`
Expected: All existing tests PASS without modification (the public API is unchanged)

- [ ] **Step 6: Run full test suite**

Run: `./vendor/bin/sail exec laravel.test php artisan test`
Expected: All tests PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/Rapports/ app/Services/RapportService.php
git commit -m "refactor: extract CompteResultatBuilder and FluxTresorerieBuilder from RapportService"
```

---

### Task 13: Add edge case tests for RapportService

**Files:**
- Create: `tests/Feature/Services/RapportServiceEdgeCasesTest.php`

- [ ] **Step 1: Write edge case tests**

Create `tests/Feature/Services/RapportServiceEdgeCasesTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Enums\TypeCategorie;
use App\Enums\TypeTransaction;
use App\Services\RapportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(RapportService::class);
});

it('compteDeResultat returns empty sections when no data exists for exercice', function () {
    $result = $this->service->compteDeResultat(2099);

    expect($result)->toHaveKeys(['charges', 'produits']);
    expect($result['charges'])->toBeEmpty();
    expect($result['produits'])->toBeEmpty();
});

it('fluxTresorerie returns structure with zero balances when no data', function () {
    // Need at least one real bank account for structure
    CompteBancaire::factory()->create(['est_systeme' => false]);

    $result = $this->service->fluxTresorerie(2099);

    expect($result)->toHaveKeys(['exercice', 'synthese', 'rapprochement', 'mensuel', 'ecritures_non_pointees']);
    expect($result['mensuel'])->toBeArray();
});

it('compteDeResultat handles negative transaction amounts correctly', function () {
    $compte = CompteBancaire::factory()->create(['est_systeme' => false]);
    $cat = Categorie::factory()->create(['type' => TypeCategorie::Charge]);
    $sc = SousCategorie::factory()->create(['categorie_id' => $cat->id]);

    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'date' => '2025-10-15', // exercice 2025
        'montant_total' => -50.00, // negative
        'compte_id' => $compte->id,
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'montant' => -50.00,
    ]);

    $result = $this->service->compteDeResultat(2025);
    // Should handle negative amounts without crashing
    expect($result)->toHaveKeys(['charges', 'produits']);
});

it('toCsv generates valid French CSV with semicolons', function () {
    $rows = [
        ['col1' => 'value1', 'col2' => 'value2'],
        ['col1' => 'val;ue3', 'col2' => 'value4'],
    ];
    $csv = $this->service->toCsv($rows, ['col1', 'col2']);

    // French CSV uses semicolons
    expect($csv)->toContain(';');
    // Values with semicolons should be quoted
    expect($csv)->toContain('"val;ue3"');
});

it('compteDeResultatOperations returns empty for non-existent operations', function () {
    $result = $this->service->compteDeResultatOperations(2025, [99999]);

    expect($result)->toHaveKeys(['charges', 'produits']);
    expect($result['charges'])->toBeEmpty();
    expect($result['produits'])->toBeEmpty();
});
```

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/Services/RapportServiceEdgeCasesTest.php`
Expected: All tests PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Services/RapportServiceEdgeCasesTest.php
git commit -m "test: add edge case tests for RapportService (empty data, negatives, CSV)"
```

---

### Task 14: Final Pint + full test suite for Lot 2

- [ ] **Step 1: Run Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 2: Run full test suite**

Run: `./vendor/bin/sail exec laravel.test php artisan test`
Expected: All tests PASS

- [ ] **Step 3: Commit any Pint fixes**

```bash
git add -A
git commit -m "style: apply Pint formatting after lot 2 implementation"
```

- [ ] **Step 4: Verify total test count**

Run: `./vendor/bin/sail exec laravel.test php artisan test --compact`
Report the total number of tests and assertions.
