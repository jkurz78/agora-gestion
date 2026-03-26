# Remises en banque — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow users to group cheque/cash payments from the Règlements grid into bank deposit slips, generating individual accounting transactions and a single internal transfer for bank reconciliation.

**Architecture:** A new `remises_bancaires` table links to `reglements` (via `remise_id`) and `transactions` (via `remise_id`). A system bank account "Remises en banque" acts as intermediary — individual recette transactions are created on it, then a single `VirementInterne` transfers the total to the target bank. The pattern mirrors the existing HelloAsso cashout flow.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Pest PHP, barryvdh/laravel-dompdf

**Spec:** `docs/superpowers/specs/2026-03-26-remises-en-banque-design.md`

---

### Task 1: Migrations — est_systeme, remises_bancaires, remise_id on transactions

**Files:**
- Create: `database/migrations/2026_03_26_100001_add_est_systeme_to_comptes_bancaires.php`
- Create: `database/migrations/2026_03_26_100002_create_remises_bancaires_table.php`
- Create: `database/migrations/2026_03_26_100003_add_remise_id_and_reglement_id_to_transactions.php`
- Create: `database/migrations/2026_03_26_100004_add_fk_remise_id_on_reglements.php`
- Test: `tests/Feature/Migrations/RemisesBancairesSchemaTest.php`

- [ ] **Step 1: Write migration test**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('remises_bancaires migrations', function () {
    it('adds est_systeme column to comptes_bancaires', function () {
        expect(Schema::hasColumn('comptes_bancaires', 'est_systeme'))->toBeTrue();
    });

    it('creates the system account Remises en banque', function () {
        $compte = \App\Models\CompteBancaire::where('est_systeme', true)
            ->where('nom', 'Remises en banque')
            ->first();
        expect($compte)->not->toBeNull()
            ->and((float) $compte->solde_initial)->toBe(0.0)
            ->and($compte->actif_recettes_depenses)->toBeFalse()
            ->and($compte->actif_dons_cotisations)->toBeFalse();
    });

    it('creates remises_bancaires table with all columns', function () {
        expect(Schema::hasTable('remises_bancaires'))->toBeTrue();
        expect(Schema::hasColumns('remises_bancaires', [
            'id', 'numero', 'date', 'mode_paiement', 'compte_cible_id',
            'virement_id', 'libelle', 'saisi_par', 'created_at', 'updated_at', 'deleted_at',
        ]))->toBeTrue();
    });

    it('adds remise_id and reglement_id columns to transactions', function () {
        expect(Schema::hasColumn('transactions', 'remise_id'))->toBeTrue();
        expect(Schema::hasColumn('transactions', 'reglement_id'))->toBeTrue();
    });

    it('adds FK constraint on reglements.remise_id', function () {
        // Insert a remise then a reglement referencing it, then delete remise → reglement.remise_id should become null
        $user = \App\Models\User::factory()->create();
        $compte = \App\Models\CompteBancaire::factory()->create();
        $remise = \App\Models\RemiseBancaire::create([
            'numero' => 1,
            'date' => '2025-10-01',
            'mode_paiement' => 'cheque',
            'compte_cible_id' => $compte->id,
            'libelle' => 'Test',
            'saisi_par' => $user->id,
        ]);

        $operation = \App\Models\Operation::factory()->create();
        $tiers = \App\Models\Tiers::factory()->create();
        $participant = \App\Models\Participant::factory()->create([
            'operation_id' => $operation->id,
            'tiers_id' => $tiers->id,
        ]);
        $seance = \App\Models\Seance::factory()->create(['operation_id' => $operation->id]);
        $reglement = \App\Models\Reglement::create([
            'participant_id' => $participant->id,
            'seance_id' => $seance->id,
            'mode_paiement' => 'cheque',
            'montant_prevu' => 30,
            'remise_id' => $remise->id,
        ]);

        // Force-delete remise to trigger nullOnDelete
        $remise->forceDelete();

        $reglement->refresh();
        expect($reglement->remise_id)->toBeNull();
    });
});
```

- [ ] **Step 2: Run test — verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Migrations/RemisesBancairesSchemaTest.php`
Expected: FAIL (table/columns don't exist yet)

- [ ] **Step 3: Write migration — add est_systeme + create system account**

File: `database/migrations/2026_03_26_100001_add_est_systeme_to_comptes_bancaires.php`

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
        Schema::table('comptes_bancaires', function (Blueprint $table): void {
            $table->boolean('est_systeme')->default(false)->after('actif_dons_cotisations');
        });

        // Create the system intermediary account
        \App\Models\CompteBancaire::create([
            'nom' => 'Remises en banque',
            'iban' => '',
            'solde_initial' => 0,
            'date_solde_initial' => now()->toDateString(),
            'actif_recettes_depenses' => false,
            'actif_dons_cotisations' => false,
            'est_systeme' => true,
        ]);
    }

    public function down(): void
    {
        \App\Models\CompteBancaire::where('nom', 'Remises en banque')
            ->where('est_systeme', true)
            ->delete();

        Schema::table('comptes_bancaires', function (Blueprint $table): void {
            $table->dropColumn('est_systeme');
        });
    }
};
```

- [ ] **Step 4: Write migration — create remises_bancaires table**

File: `database/migrations/2026_03_26_100002_create_remises_bancaires_table.php`

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
        Schema::create('remises_bancaires', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('numero')->unique();
            $table->date('date');
            $table->string('mode_paiement');
            $table->foreignId('compte_cible_id')->constrained('comptes_bancaires');
            $table->foreignId('virement_id')->nullable()->constrained('virements_internes')->nullOnDelete();
            $table->string('libelle');
            $table->foreignId('saisi_par')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remises_bancaires');
    }
};
```

- [ ] **Step 5: Write migration — add remise_id and reglement_id to transactions**

File: `database/migrations/2026_03_26_100003_add_remise_id_and_reglement_id_to_transactions.php`

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
        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('remise_id')->nullable()->after('rapprochement_id')
                ->constrained('remises_bancaires')->nullOnDelete();
            $table->foreignId('reglement_id')->nullable()->after('remise_id')
                ->constrained('reglements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reglement_id');
            $table->dropConstrainedForeignId('remise_id');
        });
    }
};
```

- [ ] **Step 6: Write migration — add FK on reglements.remise_id**

File: `database/migrations/2026_03_26_100004_add_fk_remise_id_on_reglements.php`

The `remise_id` column already exists as `unsignedBigInteger` without FK. Add the FK constraint.

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
        Schema::table('reglements', function (Blueprint $table): void {
            $table->foreign('remise_id')
                ->references('id')
                ->on('remises_bancaires')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reglements', function (Blueprint $table): void {
            $table->dropForeign(['remise_id']);
        });
    }
};
```

- [ ] **Step 7: Run test — verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Migrations/RemisesBancairesSchemaTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_03_26_10000* tests/Feature/Migrations/RemisesBancairesSchemaTest.php
git commit -m "feat(remises): add migrations for remises_bancaires, est_systeme, FK constraints"
```

---

### Task 2: Models — RemiseBancaire + update existing models

**Files:**
- Create: `app/Models/RemiseBancaire.php`
- Modify: `app/Models/CompteBancaire.php` — add `est_systeme` to fillable/casts
- Modify: `app/Models/Transaction.php` — add `remise_id` to fillable/casts, add `remise()` relation
- Modify: `app/Models/Reglement.php` — add `remise()` relation
- Test: `tests/Feature/RemiseBancaireModelTest.php`

- [ ] **Step 1: Write model test**

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compteCible = CompteBancaire::factory()->create();
});

it('creates a RemiseBancaire with correct casts', function () {
    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteCible->id,
        'libelle' => 'Remise chèques n°1',
        'saisi_par' => $this->user->id,
    ]);

    expect($remise->mode_paiement)->toBe(ModePaiement::Cheque)
        ->and($remise->date->format('Y-m-d'))->toBe('2025-10-15')
        ->and($remise->numero)->toBe(1);
});

it('has compteCible, virement, saisiPar relations', function () {
    $virement = VirementInterne::factory()->create();
    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteCible->id,
        'virement_id' => $virement->id,
        'libelle' => 'Test',
        'saisi_par' => $this->user->id,
    ]);

    expect($remise->compteCible->id)->toBe($this->compteCible->id)
        ->and($remise->virement->id)->toBe($virement->id)
        ->and($remise->saisiPar->id)->toBe($this->user->id);
});

it('isVerrouillee returns false when no virement', function () {
    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteCible->id,
        'libelle' => 'Test',
        'saisi_par' => $this->user->id,
    ]);

    expect($remise->isVerrouillee())->toBeFalse();
});

it('referencePrefix returns RBC for cheque', function () {
    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteCible->id,
        'libelle' => 'Test',
        'saisi_par' => $this->user->id,
    ]);

    expect($remise->referencePrefix())->toBe('RBC');
});

it('referencePrefix returns RBE for especes', function () {
    $remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Especes->value,
        'compte_cible_id' => $this->compteCible->id,
        'libelle' => 'Test',
        'saisi_par' => $this->user->id,
    ]);

    expect($remise->referencePrefix())->toBe('RBE');
});

describe('CompteBancaire est_systeme', function () {
    it('defaults to false', function () {
        $compte = CompteBancaire::factory()->create();
        expect($compte->est_systeme)->toBeFalse();
    });

    it('can be set to true', function () {
        $compte = CompteBancaire::factory()->create(['est_systeme' => true]);
        expect($compte->est_systeme)->toBeTrue();
    });
});
```

- [ ] **Step 2: Run test — verify it fails**

Run: `./vendor/bin/sail test tests/Feature/RemiseBancaireModelTest.php`
Expected: FAIL (RemiseBancaire class not found)

- [ ] **Step 3: Create RemiseBancaire model**

File: `app/Models/RemiseBancaire.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModePaiement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class RemiseBancaire extends Model
{
    use SoftDeletes;

    protected $table = 'remises_bancaires';

    protected $fillable = [
        'numero',
        'date',
        'mode_paiement',
        'compte_cible_id',
        'virement_id',
        'libelle',
        'saisi_par',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'mode_paiement' => ModePaiement::class,
            'numero' => 'integer',
            'compte_cible_id' => 'integer',
            'virement_id' => 'integer',
            'saisi_par' => 'integer',
        ];
    }

    public function compteCible(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_cible_id');
    }

    public function virement(): BelongsTo
    {
        return $this->belongsTo(VirementInterne::class, 'virement_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function reglements(): HasMany
    {
        return $this->hasMany(Reglement::class, 'remise_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'remise_id');
    }

    public function isVerrouillee(): bool
    {
        if ($this->virement_id === null) {
            return false;
        }

        return $this->virement?->isLockedByRapprochement() === true;
    }

    public function referencePrefix(): string
    {
        return $this->mode_paiement === ModePaiement::Cheque ? 'RBC' : 'RBE';
    }

    public function montantTotal(): float
    {
        return (float) $this->reglements()->sum('montant_prevu');
    }
}
```

- [ ] **Step 4: Update CompteBancaire model — add est_systeme**

Add `'est_systeme'` to `$fillable` and `'est_systeme' => 'boolean'` to `casts()` in `app/Models/CompteBancaire.php`.

- [ ] **Step 5: Update Transaction model — add remise_id**

Add `'remise_id'` and `'reglement_id'` to `$fillable`, `'remise_id' => 'integer'` and `'reglement_id' => 'integer'` to `casts()`, and add relations:

```php
public function remise(): BelongsTo
{
    return $this->belongsTo(RemiseBancaire::class, 'remise_id');
}

public function reglement(): BelongsTo
{
    return $this->belongsTo(Reglement::class, 'reglement_id');
}
```

- [ ] **Step 6: Update Reglement model — add remise relation**

Add to `app/Models/Reglement.php`:

```php
public function remise(): BelongsTo
{
    return $this->belongsTo(\App\Models\RemiseBancaire::class, 'remise_id');
}
```

- [ ] **Step 7: Update CompteBancaire factory — add est_systeme default**

In `database/factories/CompteBancaireFactory.php`, add `'est_systeme' => false` to the definition.

- [ ] **Step 8: Run test — verify it passes**

Run: `./vendor/bin/sail test tests/Feature/RemiseBancaireModelTest.php`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Models/RemiseBancaire.php app/Models/CompteBancaire.php app/Models/Transaction.php app/Models/Reglement.php database/factories/CompteBancaireFactory.php tests/Feature/RemiseBancaireModelTest.php
git commit -m "feat(remises): add RemiseBancaire model, update existing models"
```

---

### Task 3: RemiseBancaireService — creer, comptabiliser

**Files:**
- Create: `app/Services/RemiseBancaireService.php`
- Test: `tests/Feature/Services/RemiseBancaireServiceTest.php`

- [ ] **Step 1: Write test for creer()**

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\RemiseBancaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compteCible = CompteBancaire::factory()->create();
    $this->service = app(RemiseBancaireService::class);
});

describe('creer()', function () {
    it('creates a remise with auto-incremented numero', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        expect($remise)->toBeInstanceOf(RemiseBancaire::class)
            ->and($remise->numero)->toBe(1)
            ->and($remise->libelle)->toBe('Remise chèques n°1')
            ->and($remise->virement_id)->toBeNull()
            ->and($remise->saisi_par)->toBe($this->user->id);
    });

    it('increments numero globally', function () {
        $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $remise2 = $this->service->creer([
            'date' => '2025-11-01',
            'mode_paiement' => ModePaiement::Especes->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        expect($remise2->numero)->toBe(2)
            ->and($remise2->libelle)->toBe('Remise espèces n°2');
    });
});
```

- [ ] **Step 2: Run test — verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Services/RemiseBancaireServiceTest.php --filter="creer"`
Expected: FAIL

- [ ] **Step 3: Write RemiseBancaireService::creer()**

File: `app/Services/RemiseBancaireService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

final class RemiseBancaireService
{
    public function __construct(
        private readonly TransactionService $transactionService,
        private readonly VirementInterneService $virementInterneService,
    ) {}

    public function creer(array $data): RemiseBancaire
    {
        return DB::transaction(function () use ($data) {
            $numero = (int) RemiseBancaire::withTrashed()->max('numero') + 1;

            $modePaiement = ModePaiement::from($data['mode_paiement']);
            $prefix = $modePaiement === ModePaiement::Cheque ? 'chèques' : 'espèces';
            $libelle = "Remise {$prefix} n°{$numero}";

            return RemiseBancaire::create([
                'numero' => $numero,
                'date' => $data['date'],
                'mode_paiement' => $data['mode_paiement'],
                'compte_cible_id' => $data['compte_cible_id'],
                'libelle' => $libelle,
                'saisi_par' => auth()->id(),
            ]);
        });
    }
}
```

- [ ] **Step 4: Run test — verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Services/RemiseBancaireServiceTest.php --filter="creer"`
Expected: PASS

- [ ] **Step 5: Write test for comptabiliser()**

Add to the same test file:

```php
describe('comptabiliser()', function () {
    beforeEach(function () {
        $this->sousCategorie = SousCategorie::factory()->create();
        $this->operation = Operation::factory()->create(['sous_categorie_id' => $this->sousCategorie->id]);
        $this->tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
        $this->participant = Participant::factory()->create([
            'operation_id' => $this->operation->id,
            'tiers_id' => $this->tiers->id,
        ]);
        $this->seance = Seance::factory()->create([
            'operation_id' => $this->operation->id,
            'numero' => 1,
        ]);
        $this->reglement = Reglement::create([
            'participant_id' => $this->participant->id,
            'seance_id' => $this->seance->id,
            'mode_paiement' => ModePaiement::Cheque->value,
            'montant_prevu' => 30.00,
        ]);
    });

    it('creates transactions and virement for selected reglements', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $this->service->comptabiliser($remise, [$this->reglement->id]);

        $remise->refresh();

        // Virement created
        expect($remise->virement_id)->not->toBeNull();
        $virement = $remise->virement;
        expect((float) $virement->montant)->toBe(30.0)
            ->and($virement->reference)->toBe('RBC-001');

        // Transaction created on intermediary account
        $transactions = Transaction::where('remise_id', $remise->id)->get();
        expect($transactions)->toHaveCount(1);
        $tx = $transactions->first();
        expect($tx->type)->toBe(TypeTransaction::Recette)
            ->and((float) $tx->montant_total)->toBe(30.0)
            ->and($tx->tiers_id)->toBe($this->tiers->id)
            ->and($tx->reference)->toBe('RBC-001-01')
            ->and($tx->numero_piece)->not->toBeNull();

        // Transaction has one ligne with correct operation/seance/sous_categorie
        $ligne = $tx->lignes->first();
        expect($ligne->sous_categorie_id)->toBe($this->sousCategorie->id)
            ->and($ligne->operation_id)->toBe($this->operation->id)
            ->and($ligne->seance)->toBe(1);

        // Reglement is linked
        $this->reglement->refresh();
        expect($this->reglement->remise_id)->toBe($remise->id);
    });

    it('uses intermediary system account for transactions', function () {
        $compteIntermediaire = CompteBancaire::where('est_systeme', true)
            ->where('nom', 'Remises en banque')
            ->first();

        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);
        $this->service->comptabiliser($remise, [$this->reglement->id]);

        $tx = Transaction::where('remise_id', $remise->id)->first();
        expect($tx->compte_id)->toBe($compteIntermediaire->id);

        // Virement goes from intermediary to target
        $virement = $remise->fresh()->virement;
        expect($virement->compte_source_id)->toBe($compteIntermediaire->id)
            ->and($virement->compte_destination_id)->toBe($this->compteCible->id);
    });

    it('refuses to comptabiliser an already-comptabilised remise', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);
        $this->service->comptabiliser($remise, [$this->reglement->id]);

        $this->service->comptabiliser($remise->fresh(), [$this->reglement->id]);
    })->throws(\RuntimeException::class);

    it('refuses reglements with wrong mode_paiement', function () {
        $this->reglement->update(['mode_paiement' => ModePaiement::Especes->value]);

        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $this->service->comptabiliser($remise, [$this->reglement->id]);
    })->throws(\RuntimeException::class);

    it('refuses reglements already linked to another remise', function () {
        // Create another remise to use as a valid FK target
        $autreRemise = $this->service->creer([
            'date' => '2025-10-01',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);
        $this->reglement->update(['remise_id' => $autreRemise->id]);

        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $this->service->comptabiliser($remise, [$this->reglement->id]);
    })->throws(\RuntimeException::class);

    it('throws when operation has no sous_categorie', function () {
        $this->operation->update(['sous_categorie_id' => null]);

        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $this->service->comptabiliser($remise, [$this->reglement->id]);
    })->throws(\RuntimeException::class);
});
```

- [ ] **Step 6: Run test — verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Services/RemiseBancaireServiceTest.php --filter="comptabiliser"`
Expected: FAIL

- [ ] **Step 7: Implement comptabiliser()**

Add to `app/Services/RemiseBancaireService.php`:

```php
public function comptabiliser(RemiseBancaire $remise, array $reglementIds): void
{
    if ($remise->virement_id !== null) {
        throw new \RuntimeException('Cette remise est déjà comptabilisée.');
    }

    DB::transaction(function () use ($remise, $reglementIds) {
        $reglements = Reglement::with(['participant.tiers', 'seance.operation'])
            ->whereIn('id', $reglementIds)
            ->get();

        // Validate all reglements
        foreach ($reglements as $reglement) {
            if ($reglement->remise_id !== null) {
                throw new \RuntimeException("Le règlement #{$reglement->id} est déjà inclus dans une autre remise.");
            }
            if ($reglement->mode_paiement !== $remise->mode_paiement) {
                throw new \RuntimeException("Le règlement #{$reglement->id} n'a pas le bon mode de paiement.");
            }
        }

        $compteIntermediaire = CompteBancaire::where('est_systeme', true)
            ->where('nom', 'Remises en banque')
            ->firstOrFail();

        $prefix = $remise->referencePrefix();
        $numeroPadded = str_pad((string) $remise->numero, 3, '0', STR_PAD_LEFT);
        $totalMontant = 0;
        $index = 0;

        foreach ($reglements as $reglement) {
            $index++;
            $participant = $reglement->participant;
            $tiers = $participant->tiers;
            $seance = $reglement->seance;
            $operation = $seance->operation;

            if ($operation->sous_categorie_id === null) {
                throw new \RuntimeException(
                    "L'opération \"{$operation->nom}\" n'a pas de sous-catégorie définie."
                );
            }

            $indexPadded = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $reference = "{$prefix}-{$numeroPadded}-{$indexPadded}";
            $libelle = "Règlement {$tiers->displayName()} - {$operation->nom} S{$seance->numero}";

            $this->transactionService->create([
                'type' => TypeTransaction::Recette->value,
                'date' => $remise->date->format('Y-m-d'),
                'libelle' => $libelle,
                'montant_total' => $reglement->montant_prevu,
                'mode_paiement' => $remise->mode_paiement->value,
                'tiers_id' => $tiers->id,
                'reference' => $reference,
                'compte_id' => $compteIntermediaire->id,
                'remise_id' => $remise->id,
                'reglement_id' => $reglement->id,
            ], [
                [
                    'sous_categorie_id' => $operation->sous_categorie_id,
                    'operation_id' => $operation->id,
                    'seance' => $seance->numero,
                    'montant' => $reglement->montant_prevu,
                    'notes' => null,
                ],
            ]);

            $reglement->update(['remise_id' => $remise->id]);
            $totalMontant += (float) $reglement->montant_prevu;
        }

        // Create the virement
        $virementReference = "{$prefix}-{$numeroPadded}";
        $virement = $this->virementInterneService->create([
            'date' => $remise->date->format('Y-m-d'),
            'montant' => $totalMontant,
            'compte_source_id' => $compteIntermediaire->id,
            'compte_destination_id' => $remise->compte_cible_id,
            'reference' => $virementReference,
            'notes' => $remise->libelle,
        ]);

        $remise->update(['virement_id' => $virement->id]);
    });
}
```

- [ ] **Step 8: Run test — verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Services/RemiseBancaireServiceTest.php`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Services/RemiseBancaireService.php tests/Feature/Services/RemiseBancaireServiceTest.php
git commit -m "feat(remises): add RemiseBancaireService with creer() and comptabiliser()"
```

---

### Task 4: RemiseBancaireService — modifier, supprimer

**Files:**
- Modify: `app/Services/RemiseBancaireService.php`
- Modify: `tests/Feature/Services/RemiseBancaireServiceTest.php`

- [ ] **Step 1: Write test for modifier()**

Add to the test file:

```php
describe('modifier()', function () {
    beforeEach(function () {
        $this->sousCategorie = SousCategorie::factory()->create();
        $this->operation = Operation::factory()->create(['sous_categorie_id' => $this->sousCategorie->id]);
        $this->tiers1 = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
        $this->tiers2 = Tiers::factory()->create(['nom' => 'Martin', 'prenom' => 'Sophie']);
        $this->participant1 = Participant::factory()->create([
            'operation_id' => $this->operation->id,
            'tiers_id' => $this->tiers1->id,
        ]);
        $this->participant2 = Participant::factory()->create([
            'operation_id' => $this->operation->id,
            'tiers_id' => $this->tiers2->id,
        ]);
        $this->seance = Seance::factory()->create([
            'operation_id' => $this->operation->id,
            'numero' => 1,
        ]);
        $this->reg1 = Reglement::create([
            'participant_id' => $this->participant1->id,
            'seance_id' => $this->seance->id,
            'mode_paiement' => ModePaiement::Cheque->value,
            'montant_prevu' => 30.00,
        ]);
        $this->reg2 = Reglement::create([
            'participant_id' => $this->participant2->id,
            'seance_id' => $this->seance->id,
            'mode_paiement' => ModePaiement::Cheque->value,
            'montant_prevu' => 25.00,
        ]);

        // Create and comptabiliser with reg1 only
        $this->remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);
        $this->service->comptabiliser($this->remise, [$this->reg1->id]);
        $this->remise->refresh();
    });

    it('adds a reglement and updates virement montant', function () {
        $this->service->modifier($this->remise, [$this->reg1->id, $this->reg2->id]);

        $this->remise->refresh();
        expect(Transaction::where('remise_id', $this->remise->id)->count())->toBe(2);
        expect((float) $this->remise->virement->montant)->toBe(55.0);

        $this->reg2->refresh();
        expect($this->reg2->remise_id)->toBe($this->remise->id);
    });

    it('removes a reglement and updates virement montant', function () {
        // First add reg2
        $this->service->modifier($this->remise, [$this->reg1->id, $this->reg2->id]);
        $this->remise->refresh();

        // Now remove reg1
        $this->service->modifier($this->remise, [$this->reg2->id]);

        $this->remise->refresh();
        expect(Transaction::where('remise_id', $this->remise->id)->count())->toBe(1);
        expect((float) $this->remise->virement->montant)->toBe(25.0);

        $this->reg1->refresh();
        expect($this->reg1->remise_id)->toBeNull();
    });

    it('deletes remise when empty list', function () {
        $this->service->modifier($this->remise, []);

        expect(RemiseBancaire::find($this->remise->id))->toBeNull();
    });
});
```

- [ ] **Step 2: Run test — verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Services/RemiseBancaireServiceTest.php --filter="modifier"`
Expected: FAIL

- [ ] **Step 3: Implement modifier()**

Add to `app/Services/RemiseBancaireService.php`:

```php
public function modifier(RemiseBancaire $remise, array $reglementIds): void
{
    if ($remise->isVerrouillee()) {
        throw new \RuntimeException('Cette remise est verrouillée par un rapprochement bancaire.');
    }

    if (count($reglementIds) === 0) {
        $this->supprimer($remise);
        return;
    }

    DB::transaction(function () use ($remise, $reglementIds) {
        $currentReglementIds = Reglement::where('remise_id', $remise->id)->pluck('id')->toArray();
        $toRemove = array_diff($currentReglementIds, $reglementIds);
        $toAdd = array_diff($reglementIds, $currentReglementIds);

        // Remove reglements — use direct reglement_id link on transaction
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

        // Add new reglements
        if (count($toAdd) > 0) {
            $newReglements = Reglement::with(['participant.tiers', 'seance.operation'])
                ->whereIn('id', $toAdd)
                ->get();

            foreach ($newReglements as $reglement) {
                if ($reglement->remise_id !== null) {
                    throw new \RuntimeException("Le règlement #{$reglement->id} est déjà inclus dans une autre remise.");
                }
                if ($reglement->mode_paiement !== $remise->mode_paiement) {
                    throw new \RuntimeException("Le règlement #{$reglement->id} n'a pas le bon mode de paiement.");
                }
            }

            $compteIntermediaire = CompteBancaire::where('est_systeme', true)
                ->where('nom', 'Remises en banque')
                ->firstOrFail();

            $prefix = $remise->referencePrefix();
            $numeroPadded = str_pad((string) $remise->numero, 3, '0', STR_PAD_LEFT);

            // Current max index
            $currentCount = Transaction::where('remise_id', $remise->id)->count();
            $index = $currentCount;

            foreach ($newReglements as $reglement) {
                $index++;
                $participant = $reglement->participant;
                $tiers = $participant->tiers;
                $seance = $reglement->seance;
                $operation = $seance->operation;

                if ($operation->sous_categorie_id === null) {
                    throw new \RuntimeException(
                        "L'opération \"{$operation->nom}\" n'a pas de sous-catégorie définie."
                    );
                }

                $indexPadded = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
                $reference = "{$prefix}-{$numeroPadded}-{$indexPadded}";
                $libelle = "Règlement {$tiers->displayName()} - {$operation->nom} S{$seance->numero}";

                $this->transactionService->create([
                    'type' => TypeTransaction::Recette->value,
                    'date' => $remise->date->format('Y-m-d'),
                    'libelle' => $libelle,
                    'montant_total' => $reglement->montant_prevu,
                    'mode_paiement' => $remise->mode_paiement->value,
                    'tiers_id' => $tiers->id,
                    'reference' => $reference,
                    'compte_id' => $compteIntermediaire->id,
                    'remise_id' => $remise->id,
                    'reglement_id' => $reglement->id,
                ], [
                    [
                        'sous_categorie_id' => $operation->sous_categorie_id,
                        'operation_id' => $operation->id,
                        'seance' => $seance->numero,
                        'montant' => $reglement->montant_prevu,
                        'notes' => null,
                    ],
                ]);

                $reglement->update(['remise_id' => $remise->id]);
            }
        }

        // Update virement montant
        $newTotal = (float) Reglement::where('remise_id', $remise->id)->sum('montant_prevu');
        $this->virementInterneService->update($remise->virement, [
            'date' => $remise->date->format('Y-m-d'),
            'montant' => $newTotal,
        ]);
    });
}
```

- [ ] **Step 4: Run test — verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Services/RemiseBancaireServiceTest.php --filter="modifier"`
Expected: PASS

- [ ] **Step 5: Write test for supprimer()**

```php
describe('supprimer()', function () {
    beforeEach(function () {
        $this->sousCategorie = SousCategorie::factory()->create();
        $this->operation = Operation::factory()->create(['sous_categorie_id' => $this->sousCategorie->id]);
        $this->tiers = Tiers::factory()->create();
        $this->participant = Participant::factory()->create([
            'operation_id' => $this->operation->id,
            'tiers_id' => $this->tiers->id,
        ]);
        $this->seance = Seance::factory()->create([
            'operation_id' => $this->operation->id,
            'numero' => 1,
        ]);
        $this->reglement = Reglement::create([
            'participant_id' => $this->participant->id,
            'seance_id' => $this->seance->id,
            'mode_paiement' => ModePaiement::Cheque->value,
            'montant_prevu' => 30.00,
        ]);
    });

    it('soft-deletes remise, transactions, virement and frees reglements', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);
        $this->service->comptabiliser($remise, [$this->reglement->id]);
        $remise->refresh();

        $virementId = $remise->virement_id;
        $transactionIds = Transaction::where('remise_id', $remise->id)->pluck('id')->toArray();

        $this->service->supprimer($remise);

        // Remise soft-deleted
        expect(RemiseBancaire::find($remise->id))->toBeNull();
        expect(RemiseBancaire::withTrashed()->find($remise->id))->not->toBeNull();

        // Transactions soft-deleted
        foreach ($transactionIds as $txId) {
            expect(Transaction::find($txId))->toBeNull();
            expect(Transaction::withTrashed()->find($txId))->not->toBeNull();
        }

        // Virement soft-deleted
        expect(VirementInterne::find($virementId))->toBeNull();
        expect(VirementInterne::withTrashed()->find($virementId))->not->toBeNull();

        // Reglement freed
        $this->reglement->refresh();
        expect($this->reglement->remise_id)->toBeNull();
    });

    it('can delete a brouillon remise (no transactions)', function () {
        $remise = $this->service->creer([
            'date' => '2025-10-15',
            'mode_paiement' => ModePaiement::Cheque->value,
            'compte_cible_id' => $this->compteCible->id,
        ]);

        $this->service->supprimer($remise);

        expect(RemiseBancaire::find($remise->id))->toBeNull();
    });
});
```

- [ ] **Step 6: Run test — verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Services/RemiseBancaireServiceTest.php --filter="supprimer"`
Expected: FAIL

- [ ] **Step 7: Implement supprimer()**

Add to `app/Services/RemiseBancaireService.php`:

```php
public function supprimer(RemiseBancaire $remise): void
{
    if ($remise->isVerrouillee()) {
        throw new \RuntimeException('Cette remise est verrouillée par un rapprochement bancaire.');
    }

    DB::transaction(function () use ($remise) {
        // Free all reglements
        Reglement::where('remise_id', $remise->id)->update(['remise_id' => null]);

        // Soft-delete all transactions
        Transaction::where('remise_id', $remise->id)->each(function (Transaction $tx) {
            $tx->lignes()->each(function ($ligne) {
                $ligne->affectations()->delete();
                $ligne->delete();
            });
            $tx->delete();
        });

        // Soft-delete virement
        if ($remise->virement_id !== null) {
            $remise->virement->delete();
        }

        // Soft-delete remise
        $remise->delete();
    });
}
```

- [ ] **Step 8: Run all service tests**

Run: `./vendor/bin/sail test tests/Feature/Services/RemiseBancaireServiceTest.php`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Services/RemiseBancaireService.php tests/Feature/Services/RemiseBancaireServiceTest.php
git commit -m "feat(remises): add modifier() and supprimer() to RemiseBancaireService"
```

---

### Task 5: Routes + Navigation

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`
- Create: `resources/views/gestion/remises-bancaires/index.blade.php`
- Create: `resources/views/gestion/remises-bancaires/selection.blade.php`
- Create: `resources/views/gestion/remises-bancaires/validation.blade.php`
- Create: `resources/views/gestion/remises-bancaires/show.blade.php`

- [ ] **Step 1: Add routes to web.php**

In `routes/web.php`, inside the Gestion group (after the seances export route, before `$registerParametres()`), add:

```php
// Remises en banque
Route::view('/remises-bancaires', 'gestion.remises-bancaires.index')->name('remises-bancaires');
Route::get('/remises-bancaires/{remise}', function (\App\Models\RemiseBancaire $remise) {
    return view('gestion.remises-bancaires.show', compact('remise'));
})->name('remises-bancaires.show');
Route::get('/remises-bancaires/{remise}/selection', function (\App\Models\RemiseBancaire $remise) {
    return view('gestion.remises-bancaires.selection', compact('remise'));
})->name('remises-bancaires.selection');
Route::get('/remises-bancaires/{remise}/validation', function (\App\Models\RemiseBancaire $remise) {
    return view('gestion.remises-bancaires.validation', compact('remise'));
})->name('remises-bancaires.validation');
Route::get('/remises-bancaires/{remise}/pdf', \App\Http\Controllers\RemiseBancairePdfController::class)
    ->name('remises-bancaires.pdf');
```

- [ ] **Step 2: Add navigation item**

In `resources/views/layouts/app.blade.php`, after the Opérations nav item (line 295) and before the Sync HelloAsso item (line 297), add:

```blade
{{-- Remises en banque --}}
<li class="nav-item">
    <a class="nav-link {{ request()->routeIs('gestion.remises-bancaires*') ? 'active' : '' }}"
       href="{{ route('gestion.remises-bancaires') }}">
        <i class="bi bi-bank"></i> Remises en banque
    </a>
</li>
```

- [ ] **Step 3: Create Blade wrapper views**

File: `resources/views/gestion/remises-bancaires/index.blade.php`

```blade
<x-app-layout>
    <h1 class="mb-4">Remises en banque</h1>
    <livewire:remise-bancaire-list />
</x-app-layout>
```

File: `resources/views/gestion/remises-bancaires/show.blade.php`

```blade
<x-app-layout>
    <livewire:remise-bancaire-show :remise="$remise" />
</x-app-layout>
```

File: `resources/views/gestion/remises-bancaires/selection.blade.php`

```blade
<x-app-layout>
    <livewire:remise-bancaire-selection :remise="$remise" />
</x-app-layout>
```

File: `resources/views/gestion/remises-bancaires/validation.blade.php`

```blade
<x-app-layout>
    <livewire:remise-bancaire-validation :remise="$remise" />
</x-app-layout>
```

- [ ] **Step 4: Commit**

```bash
git add routes/web.php resources/views/layouts/app.blade.php resources/views/gestion/remises-bancaires/
git commit -m "feat(remises): add routes, navigation, and blade wrappers"
```

---

### Task 6: Livewire — RemiseBancaireList

**Files:**
- Create: `app/Livewire/RemiseBancaireList.php`
- Create: `resources/views/livewire/remise-bancaire-list.blade.php`
- Test: `tests/Feature/Livewire/RemiseBancaireListTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\RemiseBancaire;
use App\Models\User;
use App\Livewire\RemiseBancaireList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders the list page', function () {
    $this->get(route('gestion.remises-bancaires'))
        ->assertStatus(200)
        ->assertSeeLivewire(RemiseBancaireList::class);
});

it('displays existing remises', function () {
    $compte = CompteBancaire::factory()->create(['nom' => 'Banque Populaire']);
    RemiseBancaire::create([
        'numero' => 1,
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $compte->id,
        'libelle' => 'Remise chèques n°1',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(RemiseBancaireList::class)
        ->assertSee('Remise chèques n°1')
        ->assertSee('Banque Populaire')
        ->assertSee('Brouillon');
});

it('creates a new remise and redirects to selection', function () {
    $compte = CompteBancaire::factory()->create();

    Livewire::test(RemiseBancaireList::class)
        ->set('date', '2025-10-15')
        ->set('compte_cible_id', $compte->id)
        ->set('mode_paiement', 'cheque')
        ->call('create')
        ->assertRedirect();

    expect(RemiseBancaire::count())->toBe(1);
});
```

- [ ] **Step 2: Run test — verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Livewire/RemiseBancaireListTest.php`
Expected: FAIL

- [ ] **Step 3: Create Livewire component**

File: `app/Livewire/RemiseBancaireList.php`

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\RemiseBancaire;
use App\Services\RemiseBancaireService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class RemiseBancaireList extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public bool $showCreateForm = false;
    public string $date = '';
    public string $compte_cible_id = '';
    public string $mode_paiement = 'cheque';

    public function create(): void
    {
        $this->validate([
            'date' => ['required', 'date'],
            'compte_cible_id' => ['required', 'exists:comptes_bancaires,id'],
            'mode_paiement' => ['required', 'in:cheque,especes'],
        ]);

        $remise = app(RemiseBancaireService::class)->creer([
            'date' => $this->date,
            'mode_paiement' => $this->mode_paiement,
            'compte_cible_id' => (int) $this->compte_cible_id,
        ]);

        $this->redirect(route('gestion.remises-bancaires.selection', $remise));
    }

    public function supprimer(int $id): void
    {
        try {
            $remise = RemiseBancaire::findOrFail($id);
            app(RemiseBancaireService::class)->supprimer($remise);
            session()->flash('success', 'Remise supprimée.');
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $remises = RemiseBancaire::with(['compteCible', 'virement', 'reglements'])
            ->orderByDesc('date')
            ->orderByDesc('numero')
            ->paginate(20);

        $comptes = CompteBancaire::where('est_systeme', false)
            ->orderBy('nom')
            ->get();

        return view('livewire.remise-bancaire-list', [
            'remises' => $remises,
            'comptes' => $comptes,
        ]);
    }
}
```

- [ ] **Step 4: Create Blade view**

File: `resources/views/livewire/remise-bancaire-list.blade.php`

The view should contain:
- Flash message alerts (success/error)
- A "+" button that toggles `$showCreateForm`
- A create form with: date (x-date-input), bank account select (excluding system accounts), type select (Chèques/Espèces)
- A table with columns: N°, Date, Type, Banque, Nb règlements, Montant, Statut, Actions
- Statut logic: no `virement_id` → "Brouillon", `isVerrouillee()` → "Verrouillée", else → "Comptabilisée"
- Actions: Voir (link to show), Modifier (link to selection, if not verrouillée), Supprimer (wire:click, if not verrouillée, with confirm), PDF (link, if comptabilisée)
- Table header style: `table-dark` with `style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880"`

- [ ] **Step 5: Run test — verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Livewire/RemiseBancaireListTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/RemiseBancaireList.php resources/views/livewire/remise-bancaire-list.blade.php tests/Feature/Livewire/RemiseBancaireListTest.php
git commit -m "feat(remises): add RemiseBancaireList Livewire component"
```

---

### Task 7: Livewire — RemiseBancaireSelection

**Files:**
- Create: `app/Livewire/RemiseBancaireSelection.php`
- Create: `resources/views/livewire/remise-bancaire-selection.blade.php`
- Test: `tests/Feature/Livewire/RemiseBancaireSelectionTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Livewire\RemiseBancaireSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compteCible = CompteBancaire::factory()->create();
    $this->remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteCible->id,
        'libelle' => 'Remise chèques n°1',
        'saisi_par' => $this->user->id,
    ]);

    $this->sousCategorie = SousCategorie::factory()->create();
    $this->operation = Operation::factory()->create([
        'nom' => 'Gym Seniors',
        'sous_categorie_id' => $this->sousCategorie->id,
    ]);
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
    $participant = Participant::factory()->create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $tiers->id,
    ]);
    $seance = Seance::factory()->create([
        'operation_id' => $this->operation->id,
        'numero' => 1,
    ]);
    $this->reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
    ]);
});

it('renders the selection page', function () {
    $this->get(route('gestion.remises-bancaires.selection', $this->remise))
        ->assertStatus(200)
        ->assertSeeLivewire(RemiseBancaireSelection::class);
});

it('shows available reglements with matching mode_paiement', function () {
    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->assertSee('Dupont Jean')
        ->assertSee('Gym Seniors')
        ->assertSee('30,00');
});

it('does not show reglements with different mode_paiement', function () {
    $this->reglement->update(['mode_paiement' => ModePaiement::Especes->value]);

    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->assertDontSee('Dupont Jean');
});

it('toggles reglement selection', function () {
    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->call('toggleReglement', $this->reglement->id)
        ->assertSet('selectedIds', [$this->reglement->id]);
});

it('redirects to validation with selected ids', function () {
    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->call('toggleReglement', $this->reglement->id)
        ->call('valider')
        ->assertRedirect();
});
```

- [ ] **Step 2: Run test — verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Livewire/RemiseBancaireSelectionTest.php`
Expected: FAIL

- [ ] **Step 3: Create Livewire component**

File: `app/Livewire/RemiseBancaireSelection.php`

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Reglement;
use App\Models\RemiseBancaire;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class RemiseBancaireSelection extends Component
{
    public RemiseBancaire $remise;

    /** @var list<int> */
    public array $selectedIds = [];

    public string $filterOperation = '';
    public string $filterTiers = '';

    public function mount(RemiseBancaire $remise): void
    {
        $this->remise = $remise;

        // Pre-select already-linked reglements (for modification flow)
        $this->selectedIds = Reglement::where('remise_id', $remise->id)
            ->pluck('id')
            ->toArray();
    }

    public function toggleReglement(int $id): void
    {
        if (in_array($id, $this->selectedIds, true)) {
            $this->selectedIds = array_values(array_diff($this->selectedIds, [$id]));
        } else {
            $this->selectedIds[] = $id;
        }
    }

    public function valider(): void
    {
        if (count($this->selectedIds) === 0) {
            $this->addError('selection', 'Sélectionnez au moins un règlement.');
            return;
        }

        session(['remise_selected_ids' => $this->selectedIds]);
        $this->redirect(route('gestion.remises-bancaires.validation', $this->remise));
    }

    public function render(): View
    {
        $query = Reglement::with(['participant.tiers', 'seance.operation'])
            ->where('mode_paiement', $this->remise->mode_paiement->value)
            ->where('montant_prevu', '>', 0)
            ->where(function ($q) {
                $q->whereNull('remise_id')
                    ->orWhere('remise_id', $this->remise->id);
            });

        if ($this->filterOperation !== '') {
            $query->whereHas('seance.operation', fn ($q) => $q->where('id', $this->filterOperation));
        }

        if ($this->filterTiers !== '') {
            $query->whereHas('participant.tiers', fn ($q) => $q
                ->where('nom', 'like', "%{$this->filterTiers}%")
                ->orWhere('prenom', 'like', "%{$this->filterTiers}%")
            );
        }

        $reglements = $query->get()->sortBy(fn ($r) => [
            $r->seance->operation->nom ?? '',
            $r->seance->numero ?? 0,
            $r->participant->tiers->nom ?? '',
        ])->values();

        $totalSelected = $reglements->whereIn('id', $this->selectedIds)->sum('montant_prevu');
        $countSelected = count($this->selectedIds);

        $operations = $reglements->map(fn ($r) => $r->seance->operation)->unique('id')->sortBy('nom')->values();

        return view('livewire.remise-bancaire-selection', [
            'reglements' => $reglements,
            'totalSelected' => $totalSelected,
            'countSelected' => $countSelected,
            'operations' => $operations,
        ]);
    }
}
```

- [ ] **Step 4: Create Blade view**

File: `resources/views/livewire/remise-bancaire-selection.blade.php`

The view should contain:
- Header: remise info (n°, date, type, banque cible) with link back to list
- Filter row: operation select, tiers text search
- Table with columns: checkbox, Participant (tiers name), Opération, Séance (numero + titre), Montant
- Checkbox calls `wire:click="toggleReglement({{ $reglement->id }})"`
- Selected rows highlighted with `table-success` class
- Sticky footer bar: "N règlements sélectionnés — Total: X,XX €" + "Valider la sélection" button
- Table header style: `table-dark` with `style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880"`

- [ ] **Step 5: Run test — verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Livewire/RemiseBancaireSelectionTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/RemiseBancaireSelection.php resources/views/livewire/remise-bancaire-selection.blade.php tests/Feature/Livewire/RemiseBancaireSelectionTest.php
git commit -m "feat(remises): add RemiseBancaireSelection Livewire component"
```

---

### Task 8: Livewire — RemiseBancaireValidation

**Files:**
- Create: `app/Livewire/RemiseBancaireValidation.php`
- Create: `resources/views/livewire/remise-bancaire-validation.blade.php`
- Test: `tests/Feature/Livewire/RemiseBancaireValidationTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Livewire\RemiseBancaireValidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compteCible = CompteBancaire::factory()->create();
    $this->remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteCible->id,
        'libelle' => 'Remise chèques n°1',
        'saisi_par' => $this->user->id,
    ]);

    $sc = SousCategorie::factory()->create();
    $operation = Operation::factory()->create(['nom' => 'Gym', 'sous_categorie_id' => $sc->id]);
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
    $participant = Participant::factory()->create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
    ]);
    $seance = Seance::factory()->create(['operation_id' => $operation->id, 'numero' => 1]);
    $this->reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
    ]);
});

it('renders the validation page with session data', function () {
    session(['remise_selected_ids' => [$this->reglement->id]]);

    Livewire::test(RemiseBancaireValidation::class, ['remise' => $this->remise])
        ->assertSee('Dupont Jean')
        ->assertSee('30,00');
});

it('comptabiliser creates transactions and redirects', function () {
    session(['remise_selected_ids' => [$this->reglement->id]]);

    Livewire::test(RemiseBancaireValidation::class, ['remise' => $this->remise])
        ->call('comptabiliser')
        ->assertRedirect(route('gestion.remises-bancaires'));

    expect(Transaction::where('remise_id', $this->remise->id)->count())->toBe(1);
    $this->remise->refresh();
    expect($this->remise->virement_id)->not->toBeNull();
});
```

- [ ] **Step 2: Run test — verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Livewire/RemiseBancaireValidationTest.php`
Expected: FAIL

- [ ] **Step 3: Create Livewire component**

File: `app/Livewire/RemiseBancaireValidation.php`

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Services\RemiseBancaireService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class RemiseBancaireValidation extends Component
{
    public RemiseBancaire $remise;

    /** @var list<int> */
    public array $selectedIds = [];

    public function mount(RemiseBancaire $remise): void
    {
        $this->remise = $remise;
        $this->selectedIds = session('remise_selected_ids', []);
    }

    public function comptabiliser(): void
    {
        try {
            $service = app(RemiseBancaireService::class);

            if ($this->remise->virement_id !== null) {
                $service->modifier($this->remise, $this->selectedIds);
            } else {
                $service->comptabiliser($this->remise, $this->selectedIds);
            }

            session()->forget('remise_selected_ids');
            session()->flash('success', 'Remise comptabilisée avec succès.');
            $this->redirect(route('gestion.remises-bancaires'));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $reglements = Reglement::with(['participant.tiers', 'seance.operation'])
            ->whereIn('id', $this->selectedIds)
            ->get()
            ->sortBy(fn ($r) => [
                $r->seance->operation->nom ?? '',
                $r->seance->numero ?? 0,
                $r->participant->tiers->nom ?? '',
            ])->values();

        $totalMontant = $reglements->sum('montant_prevu');

        return view('livewire.remise-bancaire-validation', [
            'reglements' => $reglements,
            'totalMontant' => $totalMontant,
        ]);
    }
}
```

- [ ] **Step 4: Create Blade view**

File: `resources/views/livewire/remise-bancaire-validation.blade.php`

The view should contain:
- Summary card: date, banque cible, type, nombre de règlements, montant total
- Table: N°, Participant, Opération, Séance, Montant (no checkbox)
- Footer: total
- Buttons: "Comptabiliser" (wire:click, btn-success), "Retour" (link back to selection, btn-secondary)
- Table header style: `table-dark` with `style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880"`

- [ ] **Step 5: Run test — verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Livewire/RemiseBancaireValidationTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/RemiseBancaireValidation.php resources/views/livewire/remise-bancaire-validation.blade.php tests/Feature/Livewire/RemiseBancaireValidationTest.php
git commit -m "feat(remises): add RemiseBancaireValidation Livewire component"
```

---

### Task 9: Livewire — RemiseBancaireShow

**Files:**
- Create: `app/Livewire/RemiseBancaireShow.php`
- Create: `resources/views/livewire/remise-bancaire-show.blade.php`
- Test: `tests/Feature/Livewire/RemiseBancaireShowTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Livewire\RemiseBancaireShow;
use App\Services\RemiseBancaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compteCible = CompteBancaire::factory()->create(['nom' => 'Banque Pop']);

    $sc = SousCategorie::factory()->create();
    $operation = Operation::factory()->create(['nom' => 'Gym', 'sous_categorie_id' => $sc->id]);
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
    $participant = Participant::factory()->create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
    ]);
    $seance = Seance::factory()->create(['operation_id' => $operation->id, 'numero' => 1]);
    $reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
    ]);

    $service = app(RemiseBancaireService::class);
    $this->remise = $service->creer([
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteCible->id,
    ]);
    $service->comptabiliser($this->remise, [$reglement->id]);
    $this->remise->refresh();
});

it('renders the show page', function () {
    $this->get(route('gestion.remises-bancaires.show', $this->remise))
        ->assertStatus(200)
        ->assertSeeLivewire(RemiseBancaireShow::class);
});

it('displays remise details', function () {
    Livewire::test(RemiseBancaireShow::class, ['remise' => $this->remise])
        ->assertSee('Remise chèques n°1')
        ->assertSee('Banque Pop')
        ->assertSee('Dupont Jean')
        ->assertSee('RBC-001-01');
});
```

- [ ] **Step 2: Run test — verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Livewire/RemiseBancaireShowTest.php`
Expected: FAIL

- [ ] **Step 3: Create Livewire component**

File: `app/Livewire/RemiseBancaireShow.php`

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\RemiseBancaire;
use App\Models\Transaction;
use App\Services\RemiseBancaireService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class RemiseBancaireShow extends Component
{
    public RemiseBancaire $remise;

    public function mount(RemiseBancaire $remise): void
    {
        $this->remise = $remise;
    }

    public function supprimer(): void
    {
        try {
            app(RemiseBancaireService::class)->supprimer($this->remise);
            session()->flash('success', 'Remise supprimée.');
            $this->redirect(route('gestion.remises-bancaires'));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $transactions = Transaction::where('remise_id', $this->remise->id)
            ->with(['tiers', 'lignes.sousCategorie'])
            ->orderBy('reference')
            ->get();

        $totalMontant = $transactions->sum('montant_total');
        $verrouille = $this->remise->isVerrouillee();

        return view('livewire.remise-bancaire-show', [
            'transactions' => $transactions,
            'totalMontant' => $totalMontant,
            'verrouille' => $verrouille,
        ]);
    }
}
```

- [ ] **Step 4: Create Blade view**

File: `resources/views/livewire/remise-bancaire-show.blade.php`

The view should contain:
- Flash alerts
- Header card: n°, date, type, banque cible, montant total, statut badge
- Table of transactions: N°, Référence, N° pièce, Participant (tiers), Opération, Séance, Montant
- Footer row: total
- Virement reference display
- Action buttons: PDF (link), Modifier (link to selection, if not verrouillée), Supprimer (wire:click with confirm, if not verrouillée), Retour à la liste
- Table header style: `table-dark` with `style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880"`

- [ ] **Step 5: Run test — verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Livewire/RemiseBancaireShowTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/RemiseBancaireShow.php resources/views/livewire/remise-bancaire-show.blade.php tests/Feature/Livewire/RemiseBancaireShowTest.php
git commit -m "feat(remises): add RemiseBancaireShow Livewire component"
```

---

### Task 10: PDF Bordereau

**Files:**
- Create: `app/Http/Controllers/RemiseBancairePdfController.php`
- Create: `resources/views/pdf/remise-bancaire.blade.php`
- Test: `tests/Feature/RemiseBancairePdfTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Services\RemiseBancaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates a PDF for a comptabilised remise', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $compteCible = CompteBancaire::factory()->create();
    $sc = SousCategorie::factory()->create();
    $operation = Operation::factory()->create(['sous_categorie_id' => $sc->id]);
    $tiers = Tiers::factory()->create();
    $participant = Participant::factory()->create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
    ]);
    $seance = Seance::factory()->create(['operation_id' => $operation->id, 'numero' => 1]);
    $reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
    ]);

    $service = app(RemiseBancaireService::class);
    $remise = $service->creer([
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $compteCible->id,
    ]);
    $service->comptabiliser($remise, [$reglement->id]);

    $response = $this->get(route('gestion.remises-bancaires.pdf', $remise));

    $response->assertStatus(200);
    $response->assertHeader('content-type', 'application/pdf');
});
```

- [ ] **Step 2: Run test — verify it fails**

Run: `./vendor/bin/sail test tests/Feature/RemiseBancairePdfTest.php`
Expected: FAIL

- [ ] **Step 3: Create PDF controller**

File: `app/Http/Controllers/RemiseBancairePdfController.php`

Follow the exact pattern from `RapprochementPdfController` — `__invoke()` method, load view, use `Pdf::loadView()`, handle logo, generate filename.

The controller should:
1. Load remise with relations (compteCible, virement, transactions.tiers)
2. Get association info from Parametres
3. Build data array
4. Generate filename: `{Association} - Bordereau remise {type} n°{numero} du {date}.pdf`
5. Return `$pdf->stream()` or `$pdf->download()` based on `?mode=inline`

- [ ] **Step 4: Create PDF Blade view**

File: `resources/views/pdf/remise-bancaire.blade.php`

Follow `pdf/rapprochement.blade.php` pattern (same CSS, header with logo, association info). Content:
- Title: "Bordereau de remise en banque"
- Subtitle: Chèques / Espèces — n°{numero} — {date}
- Banque: {compteCible.nom}
- Table: N°, Tireur, Opération, Séance, Montant
- Footer: Nombre de pièces, Montant total
- Signature zone (empty box)

- [ ] **Step 5: Run test — verify it passes**

Run: `./vendor/bin/sail test tests/Feature/RemiseBancairePdfTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/RemiseBancairePdfController.php resources/views/pdf/remise-bancaire.blade.php tests/Feature/RemiseBancairePdfTest.php
git commit -m "feat(remises): add PDF bordereau generation"
```

---

### Task 11: Filter system accounts from selectors

**Files:**
- Modify: `app/Livewire/TransactionUniverselle.php` — exclude `est_systeme` accounts from compte selector
- Modify: `app/Livewire/VirementInterneForm.php` — exclude `est_systeme` accounts
- Modify: any other component that has a compte bancaire selector for create/edit
- Test: `tests/Feature/SystemAccountFilterTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('system accounts are excluded from selectors but visible in lists', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Normal accounts
    $compteNormal = CompteBancaire::factory()->create(['nom' => 'Banque Pop', 'est_systeme' => false]);
    // System account (created by migration, or manually here)
    $compteSysteme = CompteBancaire::factory()->create(['nom' => 'Remises en banque', 'est_systeme' => true]);

    // Selector query (used in forms)
    $selectableComptes = CompteBancaire::where('est_systeme', false)->get();
    expect($selectableComptes->pluck('nom')->toArray())->toContain('Banque Pop')
        ->and($selectableComptes->pluck('nom')->toArray())->not->toContain('Remises en banque');

    // List query (used in consultation)
    $allComptes = CompteBancaire::all();
    expect($allComptes->pluck('nom')->toArray())->toContain('Remises en banque');
});
```

- [ ] **Step 2: Run test — verify it passes** (this is a pure query test)

Run: `./vendor/bin/sail test tests/Feature/SystemAccountFilterTest.php`
Expected: PASS

- [ ] **Step 3: Add filter to TransactionUniverselle**

In `app/Livewire/TransactionUniverselle.php`, find where `CompteBancaire` is queried for the account selector and add `->where('est_systeme', false)`.

- [ ] **Step 4: Add filter to VirementInterneForm**

In `app/Livewire/VirementInterneForm.php`, find where `CompteBancaire` is queried for the source/destination selectors and add `->where('est_systeme', false)`.

- [ ] **Step 5: Add filter to any other selectors**

Search for `CompteBancaire::` queries in Livewire components that populate dropdowns, and add the `est_systeme` filter where appropriate (create/edit forms, NOT list/consultation views).

- [ ] **Step 6: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: PASS (no regressions)

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/ tests/Feature/SystemAccountFilterTest.php
git commit -m "feat(remises): filter system accounts from create/edit selectors"
```

---

### Task 12: Run full test suite + final verification

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: All tests PASS

- [ ] **Step 2: Run Pint for code style**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 3: Commit style fixes if any**

```bash
git add -A
git commit -m "style: apply Pint formatting"
```

- [ ] **Step 4: Run fresh migration + seed to verify migrations work cleanly**

Run: `./vendor/bin/sail artisan migrate:fresh --seed`
Expected: No errors, system account "Remises en banque" created

- [ ] **Step 5: Manual smoke test**

Verify in browser:
- Navigate to Gestion > Remises en banque — list page loads
- Create a new remise (date, banque, type CHQ)
- Selection screen shows available règlements
- Select some, validate, comptabiliser
- Remise appears in list as "Comptabilisée"
- Show page displays all transactions
- PDF downloads correctly
- Transactions visible in Compta > Transactions
- Virement visible in Compta > Virements
- System account NOT in create/edit selectors
- System account visible in transaction list filters
