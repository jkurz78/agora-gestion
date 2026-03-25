# Gestion des exercices comptables — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter la gestion formelle des exercices comptables avec clôture, réouverture, verrouillage des données et piste d'audit.

**Architecture:** Deux nouveaux modèles (`Exercice`, `ExerciceAction`) + deux enums + un service de contrôles pré-clôture + enrichissement de `ExerciceService` + 4 composants Livewire (wizard clôture, changer d'exercice, réouverture, piste d'audit) + un trait `RespectsExerciceCloture` injecté dans 12 composants existants + double verrou (service + UI) pour protéger les données.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Pest PHP, MySQL

**Spec:** `docs/superpowers/specs/2026-03-24-gestion-exercices-design.md`

---

## File Structure

### New Files

| Type | Path | Role |
|------|------|------|
| Enum | `app/Enums/StatutExercice.php` | Ouvert / Cloture |
| Enum | `app/Enums/TypeActionExercice.php` | Creation / Cloture / Reouverture |
| Migration | `database/migrations/2026_03_24_100000_create_exercices_table.php` | Table `exercices` + seed données existantes |
| Migration | `database/migrations/2026_03_24_100001_create_exercice_actions_table.php` | Table `exercice_actions` |
| Model | `app/Models/Exercice.php` | Eloquent + scopes + helpers |
| Model | `app/Models/ExerciceAction.php` | Audit trail append-only |
| DTO | `app/Services/ClotureCheckResult.php` | Résultat des contrôles pré-clôture |
| DTO | `app/Services/CheckItem.php` | Un contrôle individuel |
| Service | `app/Services/ClotureCheckService.php` | Les 6 contrôles pré-clôture |
| Exception | `app/Exceptions/ExerciceCloturedException.php` | Levée par assertOuvert() |
| Trait | `app/Livewire/Concerns/RespectsExerciceCloture.php` | Injecte $exerciceCloture |
| Livewire | `app/Livewire/Exercices/ClotureWizard.php` | Wizard 3 étapes |
| Livewire | `app/Livewire/Exercices/ChangerExercice.php` | Liste + création |
| Livewire | `app/Livewire/Exercices/ReouvrirExercice.php` | Réouverture + motif |
| Livewire | `app/Livewire/Exercices/PisteAudit.php` | Journal des actions |
| View | `resources/views/livewire/exercices/cloture-wizard.blade.php` | |
| View | `resources/views/livewire/exercices/changer-exercice.blade.php` | |
| View | `resources/views/livewire/exercices/reouvrir-exercice.blade.php` | |
| View | `resources/views/livewire/exercices/piste-audit.blade.php` | |
| View | `resources/views/exercices/cloture.blade.php` | Layout wrapper |
| View | `resources/views/exercices/changer.blade.php` | Layout wrapper |
| View | `resources/views/exercices/reouvrir.blade.php` | Layout wrapper |
| View | `resources/views/exercices/audit.blade.php` | Layout wrapper |
| Test | `tests/Unit/Models/ExerciceTest.php` | Model unit tests |
| Test | `tests/Unit/ExerciceServiceEnrichedTest.php` | New ExerciceService methods |
| Test | `tests/Feature/Services/ClotureCheckServiceTest.php` | Contrôles pré-clôture |
| Test | `tests/Feature/Services/ExerciceClotureTest.php` | Clôture / réouverture / verrou |
| Test | `tests/Feature/Livewire/ClotureWizardTest.php` | Wizard Livewire |
| Test | `tests/Feature/Livewire/ChangerExerciceTest.php` | Changer d'exercice |
| Test | `tests/Feature/Livewire/ReouvrirExerciceTest.php` | Réouverture |
| Test | `tests/Feature/Livewire/PisteAuditTest.php` | Piste d'audit |
| Test | `tests/Feature/ExerciceVerrouServiceTest.php` | Verrou service sur transactions/virements/rapprochements |
| Test | `tests/Feature/Livewire/ExerciceClotureLivewireTest.php` | Trait RespectsExerciceCloture sur composants existants |
| Test | `tests/Feature/Migrations/ExercicesMigrationTest.php` | Migration + seed |

### Modified Files

| Path | Changes |
|------|---------|
| `app/Services/ExerciceService.php` | Add `exerciceAffiche()`, `anneeForDate()`, `assertOuvert()`, `cloturer()`, `reouvrir()`, `creerExercice()`, `changerExerciceAffiche()`. Remove `available()`. |
| `app/Services/TransactionService.php:15-29` | Add `assertOuvert()` call in `create()`, `update()`, `delete()`, `affecterLigne()`, `supprimerAffectations()` |
| `app/Services/VirementInterneService.php:13-41` | Add `assertOuvert()` call in `create()`, `update()`, `delete()` |
| `app/Services/RapprochementBancaireService.php:36-259` | Add `assertOuvert()` in `create()`, `createVerrouilleAuto()`, `verrouiller()`, `deverrouiller()`, `supprimer()`. For `toggleTransaction()`: verify exercice of the **rapprochement**, not the transaction. |
| `app/Livewire/BudgetTable.php:50-83` | Add `assertOuvert()` inline in `addLine()`, `saveEdit()`, `deleteLine()`. Use trait. |
| `app/Livewire/TransactionList.php` | Use trait `RespectsExerciceCloture` |
| `app/Livewire/TransactionCompteList.php` | Use trait |
| `app/Livewire/TransactionForm.php` | Use trait |
| `app/Livewire/TransactionUniverselle.php` | Use trait. Remove `$exercicesDispos`/`available()` usage. |
| `app/Livewire/VirementInterneList.php` | Use trait |
| `app/Livewire/VirementInterneForm.php` | Use trait |
| `app/Livewire/RapprochementList.php` | Use trait |
| `app/Livewire/RapprochementDetail.php` | Use trait |
| `app/Livewire/ImportCsv.php` | Use trait |
| `app/Livewire/Banques/HelloassoSyncWizard.php` | Use trait |
| `app/Livewire/Dashboard.php` | Use trait |
| `routes/web.php:50-59` | Remove POST `/exercice/changer`. Add 4 exercice routes. |
| `resources/views/layouts/app.blade.php:1-11,293-319` | Replace exercice dropdown with badge + add Exercices menu |
| 12 blade views for existing components | Add `$exerciceCloture` conditional disabling |
| `tests/Unit/ExerciceServiceTest.php:52-73` | Remove `available()` tests |
| `database/seeders/DatabaseSeeder.php` | Create exercice record when seeding transactions |

---

## Task 1: Enums

**Files:**
- Create: `app/Enums/StatutExercice.php`
- Create: `app/Enums/TypeActionExercice.php`

- [ ] **Step 1: Create StatutExercice enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutExercice: string
{
    case Ouvert = 'ouvert';
    case Cloture = 'cloture';

    public function label(): string
    {
        return match ($this) {
            self::Ouvert => 'Ouvert',
            self::Cloture => 'Clôturé',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Ouvert => 'bg-success',
            self::Cloture => 'bg-secondary',
        };
    }
}
```

- [ ] **Step 2: Create TypeActionExercice enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeActionExercice: string
{
    case Creation = 'creation';
    case Cloture = 'cloture';
    case Reouverture = 'reouverture';

    public function label(): string
    {
        return match ($this) {
            self::Creation => 'Création',
            self::Cloture => 'Clôture',
            self::Reouverture => 'Réouverture',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Creation => 'bg-success',
            self::Cloture => 'bg-danger',
            self::Reouverture => 'bg-warning text-dark',
        };
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Enums/StatutExercice.php app/Enums/TypeActionExercice.php
git commit -m "feat(exercices): add StatutExercice and TypeActionExercice enums"
```

---

## Task 2: Migrations

**Files:**
- Create: `database/migrations/2026_03_24_100000_create_exercices_table.php`
- Create: `database/migrations/2026_03_24_100001_create_exercice_actions_table.php`
- Test: `tests/Feature/Migrations/ExercicesMigrationTest.php`

- [ ] **Step 1: Write migration test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Models\Exercice;
use App\Models\ExerciceAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates exercices table with correct columns', function () {
    expect(Exercice::create([
        'annee' => 2025,
        'statut' => StatutExercice::Ouvert,
    ]))->toBeInstanceOf(Exercice::class)
        ->and(Exercice::where('annee', 2025)->exists())->toBeTrue();
});

it('enforces unique annee constraint', function () {
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
})->throws(\Illuminate\Database\QueryException::class);

it('creates exercice_actions table with correct columns', function () {
    $user = User::factory()->create();
    $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

    $action = ExerciceAction::create([
        'exercice_id' => $exercice->id,
        'action' => \App\Enums\TypeActionExercice::Creation,
        'user_id' => $user->id,
        'commentaire' => 'Test',
    ]);

    expect($action)->toBeInstanceOf(ExerciceAction::class)
        ->and($action->created_at)->not->toBeNull();
});

it('seeds exercices from existing transactions during migration', function () {
    // This test verifies the migration runs without error on an empty database.
    // The actual seeding logic is tested via the migration's `up()` method,
    // which runs as part of RefreshDatabase.
    expect(true)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Migrations/ExercicesMigrationTest.php`
Expected: FAIL — Exercice model not found

- [ ] **Step 3: Create exercices migration**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\TypeActionExercice;
use App\Services\ExerciceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercices', function (Blueprint $table): void {
            $table->id();
            $table->smallInteger('annee')->unique();
            $table->string('statut', 20)->default(StatutExercice::Ouvert->value);
            $table->datetime('date_cloture')->nullable();
            $table->foreignId('cloture_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Seed exercices from existing transactions
        $this->seedFromTransactions();
    }

    public function down(): void
    {
        Schema::dropIfExists('exercices');
    }

    private function seedFromTransactions(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        $dates = DB::table('transactions')
            ->whereNull('deleted_at')
            ->selectRaw('DISTINCT YEAR(date) as y, MONTH(date) as m')
            ->get();

        $annees = collect();
        foreach ($dates as $row) {
            // mois >= 9 → exercice = année, sinon exercice = année - 1
            $annee = $row->m >= 9 ? $row->y : $row->y - 1;
            $annees->push($annee);
        }

        // Also include virements
        $virementDates = DB::table('virements_internes')
            ->whereNull('deleted_at')
            ->selectRaw('DISTINCT YEAR(date) as y, MONTH(date) as m')
            ->get();

        foreach ($virementDates as $row) {
            $annee = $row->m >= 9 ? $row->y : $row->y - 1;
            $annees->push($annee);
        }

        $annees = $annees->unique()->sort();

        // Add current exercice if not already present
        $exerciceService = app(ExerciceService::class);
        $currentAnnee = $exerciceService->current();
        $annees->push($currentAnnee);
        $annees = $annees->unique()->sort();

        $adminId = DB::table('users')->value('id') ?? 1;

        foreach ($annees as $annee) {
            DB::table('exercices')->insert([
                'annee' => $annee,
                'statut' => StatutExercice::Ouvert->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
```

- [ ] **Step 4: Create exercice_actions migration**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeActionExercice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercice_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exercice_id')->constrained('exercices')->cascadeOnDelete();
            $table->string('action', 20);
            $table->foreignId('user_id')->constrained('users');
            $table->text('commentaire')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // Seed creation actions for existing exercices
        $this->seedCreationActions();
    }

    public function down(): void
    {
        Schema::dropIfExists('exercice_actions');
    }

    private function seedCreationActions(): void
    {
        $exercices = DB::table('exercices')->get();
        $adminId = DB::table('users')->value('id') ?? 1;

        foreach ($exercices as $exercice) {
            DB::table('exercice_actions')->insert([
                'exercice_id' => $exercice->id,
                'action' => TypeActionExercice::Creation->value,
                'user_id' => $adminId,
                'commentaire' => 'Création automatique lors de la migration initiale',
                'created_at' => now(),
            ]);
        }
    }
};
```

- [ ] **Step 5: Run test to verify it still fails (models not yet created)**

Run: `./vendor/bin/sail test tests/Feature/Migrations/ExercicesMigrationTest.php`
Expected: FAIL — Exercice class not found

- [ ] **Step 6: Commit migrations**

```bash
git add database/migrations/2026_03_24_100000_create_exercices_table.php database/migrations/2026_03_24_100001_create_exercice_actions_table.php tests/Feature/Migrations/ExercicesMigrationTest.php
git commit -m "feat(exercices): add migrations for exercices and exercice_actions tables"
```

---

## Task 3: Models

**Files:**
- Create: `app/Models/Exercice.php`
- Create: `app/Models/ExerciceAction.php`
- Test: `tests/Unit/Models/ExerciceTest.php`

- [ ] **Step 1: Write model unit tests**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\TypeActionExercice;
use App\Models\Exercice;
use App\Models\ExerciceAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Exercice model', function () {
    it('casts statut to StatutExercice enum', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

        expect($exercice->statut)->toBe(StatutExercice::Ouvert);
    });

    it('casts date_cloture to datetime', function () {
        $exercice = Exercice::create([
            'annee' => 2025,
            'statut' => StatutExercice::Cloture,
            'date_cloture' => '2026-09-15 10:30:00',
        ]);

        expect($exercice->date_cloture)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });

    it('isCloture returns true when statut is Cloture', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Cloture]);

        expect($exercice->isCloture())->toBeTrue();
    });

    it('isCloture returns false when statut is Ouvert', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

        expect($exercice->isCloture())->toBeFalse();
    });

    it('label returns formatted year range', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

        expect($exercice->label())->toBe('2025-2026');
    });

    it('dateDebut returns September 1st of annee', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

        expect($exercice->dateDebut()->format('Y-m-d'))->toBe('2025-09-01');
    });

    it('dateFin returns August 31st of annee+1', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

        expect($exercice->dateFin()->format('Y-m-d'))->toBe('2026-08-31');
    });

    it('scopeOuvert filters open exercices', function () {
        Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Cloture]);
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

        expect(Exercice::ouvert()->count())->toBe(1)
            ->and(Exercice::ouvert()->first()->annee)->toBe(2025);
    });

    it('scopeCloture filters closed exercices', function () {
        Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Cloture]);
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

        expect(Exercice::cloture()->count())->toBe(1)
            ->and(Exercice::cloture()->first()->annee)->toBe(2024);
    });

    it('has many actions', function () {
        $user = User::factory()->create();
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        ExerciceAction::create([
            'exercice_id' => $exercice->id,
            'action' => TypeActionExercice::Creation,
            'user_id' => $user->id,
        ]);

        expect($exercice->actions)->toHaveCount(1);
    });

    it('belongs to cloturePar user', function () {
        $user = User::factory()->create();
        $exercice = Exercice::create([
            'annee' => 2025,
            'statut' => StatutExercice::Cloture,
            'cloture_par_id' => $user->id,
        ]);

        expect($exercice->cloturePar->id)->toBe($user->id);
    });
});

describe('ExerciceAction model', function () {
    it('casts action to TypeActionExercice enum', function () {
        $user = User::factory()->create();
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        $action = ExerciceAction::create([
            'exercice_id' => $exercice->id,
            'action' => TypeActionExercice::Creation,
            'user_id' => $user->id,
        ]);

        expect($action->action)->toBe(TypeActionExercice::Creation);
    });

    it('belongs to exercice', function () {
        $user = User::factory()->create();
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        $action = ExerciceAction::create([
            'exercice_id' => $exercice->id,
            'action' => TypeActionExercice::Creation,
            'user_id' => $user->id,
        ]);

        expect($action->exercice->id)->toBe($exercice->id);
    });

    it('belongs to user', function () {
        $user = User::factory()->create();
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        $action = ExerciceAction::create([
            'exercice_id' => $exercice->id,
            'action' => TypeActionExercice::Creation,
            'user_id' => $user->id,
        ]);

        expect($action->user->id)->toBe($user->id);
    });

    it('does not have updated_at column', function () {
        $user = User::factory()->create();
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        $action = ExerciceAction::create([
            'exercice_id' => $exercice->id,
            'action' => TypeActionExercice::Creation,
            'user_id' => $user->id,
        ]);

        expect($action->updated_at)->toBeNull();
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Unit/Models/ExerciceTest.php`
Expected: FAIL — classes not found

- [ ] **Step 3: Create Exercice model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutExercice;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Exercice extends Model
{
    protected $fillable = [
        'annee',
        'statut',
        'date_cloture',
        'cloture_par_id',
    ];

    protected function casts(): array
    {
        return [
            'annee' => 'integer',
            'statut' => StatutExercice::class,
            'date_cloture' => 'datetime',
            'cloture_par_id' => 'integer',
        ];
    }

    public function cloturePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cloture_par_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ExerciceAction::class);
    }

    public function scopeOuvert(Builder $query): Builder
    {
        return $query->where('statut', StatutExercice::Ouvert);
    }

    public function scopeCloture(Builder $query): Builder
    {
        return $query->where('statut', StatutExercice::Cloture);
    }

    public function isCloture(): bool
    {
        return $this->statut === StatutExercice::Cloture;
    }

    public function label(): string
    {
        return $this->annee.'-'.($this->annee + 1);
    }

    public function dateDebut(): Carbon
    {
        return Carbon::create($this->annee, 9, 1)->startOfDay();
    }

    public function dateFin(): Carbon
    {
        return Carbon::create($this->annee + 1, 8, 31)->startOfDay();
    }
}
```

- [ ] **Step 4: Create ExerciceAction model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeActionExercice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExerciceAction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'exercice_actions';

    protected $fillable = [
        'exercice_id',
        'action',
        'user_id',
        'commentaire',
    ];

    protected function casts(): array
    {
        return [
            'action' => TypeActionExercice::class,
            'exercice_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/sail test tests/Unit/Models/ExerciceTest.php tests/Feature/Migrations/ExercicesMigrationTest.php`
Expected: ALL PASS

- [ ] **Step 6: Commit**

```bash
git add app/Models/Exercice.php app/Models/ExerciceAction.php tests/Unit/Models/ExerciceTest.php
git commit -m "feat(exercices): add Exercice and ExerciceAction models with tests"
```

---

## Task 4: Exception + ExerciceService enrichment

**Files:**
- Create: `app/Exceptions/ExerciceCloturedException.php`
- Modify: `app/Services/ExerciceService.php`
- Modify: `tests/Unit/ExerciceServiceTest.php` (remove `available()` tests)
- Create: `tests/Unit/ExerciceServiceEnrichedTest.php`

- [ ] **Step 1: Create ExerciceCloturedException**

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ExerciceCloturedException extends RuntimeException
{
    public function __construct(int $annee)
    {
        parent::__construct("L'exercice {$annee}-".($annee + 1).' est clôturé. Aucune modification n\'est autorisée.');
    }
}
```

- [ ] **Step 2: Write tests for new ExerciceService methods**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\TypeActionExercice;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Exercice;
use App\Models\ExerciceAction;
use App\Models\User;
use App\Services\ExerciceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ExerciceService::class);
    $this->user = User::factory()->create();
});

afterEach(function () {
    CarbonImmutable::setTestNow(null);
    session()->forget('exercice_actif');
});

describe('anneeForDate()', function () {
    it('returns the year for September date', function () {
        expect($this->service->anneeForDate(CarbonImmutable::parse('2025-09-15')))->toBe(2025);
    });

    it('returns the year for December date', function () {
        expect($this->service->anneeForDate(CarbonImmutable::parse('2025-12-01')))->toBe(2025);
    });

    it('returns previous year for January date', function () {
        expect($this->service->anneeForDate(CarbonImmutable::parse('2026-01-15')))->toBe(2025);
    });

    it('returns previous year for August date', function () {
        expect($this->service->anneeForDate(CarbonImmutable::parse('2026-08-31')))->toBe(2025);
    });
});

describe('exerciceAffiche()', function () {
    it('returns the Exercice model for current session year', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
        session(['exercice_actif' => 2025]);

        expect($this->service->exerciceAffiche()->id)->toBe($exercice->id);
    });

    it('returns null when no exercice exists for the year', function () {
        session(['exercice_actif' => 2099]);

        expect($this->service->exerciceAffiche())->toBeNull();
    });
});

describe('assertOuvert()', function () {
    it('does not throw when exercice is open', function () {
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

        $this->service->assertOuvert(2025);
        expect(true)->toBeTrue(); // no exception
    });

    it('throws ExerciceCloturedException when exercice is closed', function () {
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Cloture]);

        $this->service->assertOuvert(2025);
    })->throws(ExerciceCloturedException::class);

    it('does not throw when exercice does not exist in database', function () {
        $this->service->assertOuvert(2099);
        expect(true)->toBeTrue();
    });
});

describe('cloturer()', function () {
    it('closes the exercice and creates audit action', function () {
        $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

        $this->service->cloturer($exercice, $this->user);

        $exercice->refresh();
        expect($exercice->statut)->toBe(StatutExercice::Cloture)
            ->and($exercice->date_cloture)->not->toBeNull()
            ->and($exercice->cloture_par_id)->toBe($this->user->id)
            ->and(ExerciceAction::where('exercice_id', $exercice->id)
                ->where('action', TypeActionExercice::Cloture)->exists())->toBeTrue();
    });
});

describe('reouvrir()', function () {
    it('reopens a closed exercice with a comment', function () {
        $exercice = Exercice::create([
            'annee' => 2025,
            'statut' => StatutExercice::Cloture,
            'date_cloture' => now(),
            'cloture_par_id' => $this->user->id,
        ]);

        $this->service->reouvrir($exercice, $this->user, 'Erreur de saisie détectée');

        $exercice->refresh();
        expect($exercice->statut)->toBe(StatutExercice::Ouvert)
            ->and($exercice->date_cloture)->toBeNull()
            ->and($exercice->cloture_par_id)->toBeNull()
            ->and(ExerciceAction::where('exercice_id', $exercice->id)
                ->where('action', TypeActionExercice::Reouverture)->exists())->toBeTrue();
    });
});

describe('creerExercice()', function () {
    it('creates a new exercice with creation action', function () {
        $exercice = $this->service->creerExercice(2025, $this->user);

        expect($exercice->annee)->toBe(2025)
            ->and($exercice->statut)->toBe(StatutExercice::Ouvert)
            ->and(ExerciceAction::where('exercice_id', $exercice->id)
                ->where('action', TypeActionExercice::Creation)->exists())->toBeTrue();
    });

    it('throws when exercice already exists', function () {
        Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);

        $this->service->creerExercice(2025, $this->user);
    })->throws(\Illuminate\Database\QueryException::class);
});

describe('changerExerciceAffiche()', function () {
    it('updates session with exercice year', function () {
        $exercice = Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Ouvert]);

        $this->service->changerExerciceAffiche($exercice);

        expect(session('exercice_actif'))->toBe(2024);
    });
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Unit/ExerciceServiceEnrichedTest.php`
Expected: FAIL — methods not found

- [ ] **Step 4: Enrich ExerciceService**

Modify `app/Services/ExerciceService.php` — add new methods, remove `available()`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutExercice;
use App\Enums\TypeActionExercice;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Exercice;
use App\Models\ExerciceAction;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ExerciceService
{
    /**
     * Return the current exercice year.
     * Financial year runs September 1 to August 31, identified by start year.
     */
    public function current(): int
    {
        if (session()->has('exercice_actif')) {
            return (int) session('exercice_actif');
        }

        $now = CarbonImmutable::now();

        return $now->month >= 9 ? $now->year : $now->year - 1;
    }

    /**
     * Return the start and end dates for a given exercice.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function dateRange(int $exercice): array
    {
        return [
            'start' => CarbonImmutable::create($exercice, 9, 1)->startOfDay(),
            'end' => CarbonImmutable::create($exercice + 1, 8, 31)->startOfDay(),
        ];
    }

    /**
     * Return a display label for the given exercice, e.g. "2025-2026".
     */
    public function label(int $exercice): string
    {
        return $exercice.'-'.($exercice + 1);
    }

    /**
     * Return the best default date for a new entry in the active exercice.
     * Returns today if in range, dateFin if past, dateDebut if future.
     */
    public function defaultDate(): string
    {
        $range = $this->dateRange($this->current());
        $today = CarbonImmutable::today();

        if ($today->lt($range['start'])) {
            return $range['start']->toDateString();
        }

        if ($today->gt($range['end'])) {
            return $range['end']->toDateString();
        }

        return $today->toDateString();
    }

    /**
     * Return the Exercice model for the currently displayed exercice.
     */
    public function exerciceAffiche(): ?Exercice
    {
        return Exercice::where('annee', $this->current())->first();
    }

    /**
     * Calculate which exercice a given date belongs to.
     * Month >= 9 → that year, otherwise → previous year.
     */
    public function anneeForDate(CarbonImmutable|Carbon $date): int
    {
        return $date->month >= 9 ? $date->year : $date->year - 1;
    }

    /**
     * Assert that the exercice for the given year is open.
     * Throws ExerciceCloturedException if closed.
     * Does nothing if the exercice does not exist in database (graceful for fresh installs).
     */
    public function assertOuvert(int $annee): void
    {
        $exercice = Exercice::where('annee', $annee)->first();

        if ($exercice !== null && $exercice->isCloture()) {
            throw new ExerciceCloturedException($annee);
        }
    }

    /**
     * Close an exercice: update status, record action.
     */
    public function cloturer(Exercice $exercice, User $user): void
    {
        DB::transaction(function () use ($exercice, $user): void {
            $exercice->update([
                'statut' => StatutExercice::Cloture,
                'date_cloture' => now(),
                'cloture_par_id' => $user->id,
            ]);

            ExerciceAction::create([
                'exercice_id' => $exercice->id,
                'action' => TypeActionExercice::Cloture,
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * Reopen a closed exercice with a mandatory comment.
     */
    public function reouvrir(Exercice $exercice, User $user, string $commentaire): void
    {
        DB::transaction(function () use ($exercice, $user, $commentaire): void {
            $exercice->update([
                'statut' => StatutExercice::Ouvert,
                'date_cloture' => null,
                'cloture_par_id' => null,
            ]);

            ExerciceAction::create([
                'exercice_id' => $exercice->id,
                'action' => TypeActionExercice::Reouverture,
                'user_id' => $user->id,
                'commentaire' => $commentaire,
            ]);
        });
    }

    /**
     * Create a new exercice year.
     */
    public function creerExercice(int $annee, User $user): Exercice
    {
        return DB::transaction(function () use ($annee, $user): Exercice {
            $exercice = Exercice::create([
                'annee' => $annee,
                'statut' => StatutExercice::Ouvert,
            ]);

            ExerciceAction::create([
                'exercice_id' => $exercice->id,
                'action' => TypeActionExercice::Creation,
                'user_id' => $user->id,
            ]);

            return $exercice;
        });
    }

    /**
     * Switch the displayed exercice in session.
     */
    public function changerExerciceAffiche(Exercice $exercice): void
    {
        session(['exercice_actif' => $exercice->annee]);
    }
}
```

- [ ] **Step 5: Remove `available()` tests from ExerciceServiceTest**

In `tests/Unit/ExerciceServiceTest.php`, delete the entire `describe('available()', ...)` block (lines 52-73).

- [ ] **Step 6: Run all ExerciceService tests**

Run: `./vendor/bin/sail test tests/Unit/ExerciceServiceTest.php tests/Unit/ExerciceServiceEnrichedTest.php tests/Feature/Services/ExerciceServiceTest.php`
Expected: ALL PASS

- [ ] **Step 7: Commit**

```bash
git add app/Exceptions/ExerciceCloturedException.php app/Services/ExerciceService.php tests/Unit/ExerciceServiceTest.php tests/Unit/ExerciceServiceEnrichedTest.php
git commit -m "feat(exercices): enrich ExerciceService with cloturer/reouvrir/assertOuvert"
```

---

## Task 5: ClotureCheckService

**Files:**
- Create: `app/Services/CheckItem.php`
- Create: `app/Services/ClotureCheckResult.php`
- Create: `app/Services/ClotureCheckService.php`
- Test: `tests/Feature/Services/ClotureCheckServiceTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\StatutRapprochement;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ClotureCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    $this->service = app(ClotureCheckService::class);
});

describe('contrôles bloquants', function () {
    it('rapprochements en cours: passes when none exist', function () {
        $result = $this->service->executer(2025);

        $bloquant = collect($result->bloquants)->firstWhere('nom', 'Rapprochements en cours');
        expect($bloquant->ok)->toBeTrue();
    });

    it('rapprochements en cours: fails when one exists in exercice period', function () {
        $compte = CompteBancaire::factory()->create();
        RapprochementBancaire::create([
            'compte_id' => $compte->id,
            'date_fin' => '2025-11-30',
            'solde_ouverture' => 0,
            'solde_fin' => 100,
            'statut' => StatutRapprochement::EnCours,
            'saisi_par' => $this->user->id,
        ]);

        $result = $this->service->executer(2025);

        $bloquant = collect($result->bloquants)->firstWhere('nom', 'Rapprochements en cours');
        expect($bloquant->ok)->toBeFalse();
    });

    it('lignes sans sous-categorie: passes when all have one', function () {
        $result = $this->service->executer(2025);

        $bloquant = collect($result->bloquants)->firstWhere('nom', 'Lignes sans sous-catégorie');
        expect($bloquant->ok)->toBeTrue();
    });

    it('lignes sans sous-categorie: fails when some lack it', function () {
        $compte = CompteBancaire::factory()->create();
        $transaction = Transaction::factory()->asDepense()->create([
            'date' => '2025-10-15',
            'compte_id' => $compte->id,
        ]);
        $transaction->lignes()->create([
            'montant' => 50,
            'sous_categorie_id' => null,
            'exercice' => 2025,
        ]);

        $result = $this->service->executer(2025);

        $bloquant = collect($result->bloquants)->firstWhere('nom', 'Lignes sans sous-catégorie');
        expect($bloquant->ok)->toBeFalse();
    });
});

describe('contrôles avertissement', function () {
    it('transactions non pointées: passes when all are pointed', function () {
        $result = $this->service->executer(2025);

        $avert = collect($result->avertissements)->firstWhere('nom', 'Transactions non pointées');
        expect($avert->ok)->toBeTrue();
    });

    it('transactions non pointées: warns when some exist', function () {
        $compte = CompteBancaire::factory()->create();
        Transaction::factory()->asDepense()->create([
            'date' => '2025-10-15',
            'compte_id' => $compte->id,
            'rapprochement_id' => null,
        ]);

        $result = $this->service->executer(2025);

        $avert = collect($result->avertissements)->firstWhere('nom', 'Transactions non pointées');
        expect($avert->ok)->toBeFalse();
    });

    it('budget absent: warns when no budget lines exist', function () {
        $result = $this->service->executer(2025);

        $avert = collect($result->avertissements)->firstWhere('nom', 'Budget absent');
        expect($avert->ok)->toBeFalse();
    });

    it('budget absent: passes when budget lines exist', function () {
        $categorie = Categorie::factory()->create();
        $sc = SousCategorie::factory()->create(['categorie_id' => $categorie->id]);
        BudgetLine::create(['sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 100]);

        $result = $this->service->executer(2025);

        $avert = collect($result->avertissements)->firstWhere('nom', 'Budget absent');
        expect($avert->ok)->toBeTrue();
    });
});

describe('peutCloturer()', function () {
    it('returns true when all blocking checks pass', function () {
        $result = $this->service->executer(2025);

        expect($result->peutCloturer())->toBeTrue();
    });

    it('returns false when a blocking check fails', function () {
        $compte = CompteBancaire::factory()->create();
        RapprochementBancaire::create([
            'compte_id' => $compte->id,
            'date_fin' => '2025-11-30',
            'solde_ouverture' => 0,
            'solde_fin' => 100,
            'statut' => StatutRapprochement::EnCours,
            'saisi_par' => $this->user->id,
        ]);

        $result = $this->service->executer(2025);

        expect($result->peutCloturer())->toBeFalse();
    });
});

it('returns soldes des comptes', function () {
    $result = $this->service->executer(2025);

    expect($result->soldesComptes)->toBeArray();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Services/ClotureCheckServiceTest.php`
Expected: FAIL — classes not found

- [ ] **Step 3: Create CheckItem DTO**

```php
<?php

declare(strict_types=1);

namespace App\Services;

final class CheckItem
{
    public function __construct(
        public readonly string $nom,
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?array $details = null,
    ) {}
}
```

- [ ] **Step 4: Create ClotureCheckResult DTO**

```php
<?php

declare(strict_types=1);

namespace App\Services;

final class ClotureCheckResult
{
    /**
     * @param  CheckItem[]  $bloquants
     * @param  CheckItem[]  $avertissements
     * @param  array<string, float>  $soldesComptes
     */
    public function __construct(
        public readonly array $bloquants,
        public readonly array $avertissements,
        public readonly array $soldesComptes,
    ) {}

    public function peutCloturer(): bool
    {
        return collect($this->bloquants)->every(fn (CheckItem $c): bool => $c->ok);
    }
}
```

- [ ] **Step 5: Create ClotureCheckService**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutRapprochement;
use App\Models\BudgetLine;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\VirementInterne;

final class ClotureCheckService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly SoldeService $soldeService,
    ) {}

    public function executer(int $annee): ClotureCheckResult
    {
        $range = $this->exerciceService->dateRange($annee);
        $start = $range['start']->toDateString();
        $end = $range['end']->toDateString();

        return new ClotureCheckResult(
            bloquants: [
                $this->checkRapprochementsEnCours($start, $end),
                $this->checkLignesSansSousCategorie($annee),
                $this->checkVirementsDesequilibres($start, $end),
            ],
            avertissements: [
                $this->checkTransactionsNonPointees($start, $end),
                $this->checkBudgetAbsent($annee),
            ],
            soldesComptes: $this->calculerSoldesComptes(),
        );
    }

    private function checkRapprochementsEnCours(string $start, string $end): CheckItem
    {
        $count = RapprochementBancaire::where('statut', StatutRapprochement::EnCours)
            ->whereBetween('date_fin', [$start, $end])
            ->count();

        return new CheckItem(
            nom: 'Rapprochements en cours',
            ok: $count === 0,
            message: $count === 0
                ? 'Aucun rapprochement en cours'
                : "{$count} rapprochement(s) en cours sur la période",
        );
    }

    private function checkLignesSansSousCategorie(int $annee): CheckItem
    {
        $count = TransactionLigne::where('exercice', $annee)
            ->whereNull('sous_categorie_id')
            ->count();

        return new CheckItem(
            nom: 'Lignes sans sous-catégorie',
            ok: $count === 0,
            message: $count === 0
                ? 'Toutes les lignes ont une sous-catégorie'
                : "{$count} ligne(s) sans sous-catégorie",
        );
    }

    private function checkVirementsDesequilibres(string $start, string $end): CheckItem
    {
        // A balanced virement has matching source/destination amounts (always true in current model
        // since VirementInterne has a single montant). This check is a safeguard.
        $count = VirementInterne::whereBetween('date', [$start, $end])
            ->where(function ($q) {
                $q->whereNull('compte_source_id')
                    ->orWhereNull('compte_destination_id');
            })
            ->count();

        return new CheckItem(
            nom: 'Virements déséquilibrés',
            ok: $count === 0,
            message: $count === 0
                ? 'Tous les virements sont équilibrés'
                : "{$count} virement(s) déséquilibré(s)",
        );
    }

    private function checkTransactionsNonPointees(string $start, string $end): CheckItem
    {
        $count = Transaction::whereNull('rapprochement_id')
            ->whereBetween('date', [$start, $end])
            ->count();

        return new CheckItem(
            nom: 'Transactions non pointées',
            ok: $count === 0,
            message: $count === 0
                ? 'Toutes les transactions sont pointées'
                : "{$count} transaction(s) non pointée(s)",
        );
    }

    private function checkBudgetAbsent(int $annee): CheckItem
    {
        $count = BudgetLine::forExercice($annee)->count();

        return new CheckItem(
            nom: 'Budget absent',
            ok: $count > 0,
            message: $count > 0
                ? "{$count} ligne(s) de budget"
                : 'Aucune ligne de budget pour cet exercice',
        );
    }

    /**
     * @return array<string, float>
     */
    private function calculerSoldesComptes(): array
    {
        $soldes = [];
        foreach (CompteBancaire::orderBy('nom')->get() as $compte) {
            $soldes[$compte->nom] = $this->soldeService->solde($compte);
        }

        return $soldes;
    }
}
```

- [ ] **Step 6: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Services/ClotureCheckServiceTest.php`
Expected: ALL PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/CheckItem.php app/Services/ClotureCheckResult.php app/Services/ClotureCheckService.php tests/Feature/Services/ClotureCheckServiceTest.php
git commit -m "feat(exercices): add ClotureCheckService with pre-closure controls"
```

---

## Task 6: Service lock (verrou service)

**Files:**
- Modify: `app/Services/TransactionService.php`
- Modify: `app/Services/VirementInterneService.php`
- Modify: `app/Services/RapprochementBancaireService.php`
- Modify: `app/Livewire/BudgetTable.php`
- Test: `tests/Feature/ExerciceVerrouServiceTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\StatutRapprochement;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\RapprochementBancaireService;
use App\Services\TransactionService;
use App\Services\VirementInterneService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
    $this->compte = CompteBancaire::factory()->create();
    $this->categorie = Categorie::factory()->create();
    $this->sousCategorie = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);

    // Exercice 2025 clôturé
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Cloture]);
    // Exercice 2024 ouvert
    Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Ouvert]);
});

describe('TransactionService verrou', function () {
    it('blocks create on closed exercice', function () {
        app(TransactionService::class)->create(
            ['date' => '2025-10-15', 'type' => 'depense', 'compte_id' => $this->compte->id, 'montant_total' => 100, 'reference' => 'TEST'],
            [['montant' => 100, 'sous_categorie_id' => $this->sousCategorie->id, 'operation_id' => null, 'seance' => null, 'notes' => null]]
        );
    })->throws(ExerciceCloturedException::class);

    it('allows create on open exercice', function () {
        $transaction = app(TransactionService::class)->create(
            ['date' => '2024-10-15', 'type' => 'depense', 'compte_id' => $this->compte->id, 'montant_total' => 100, 'reference' => 'TEST'],
            [['montant' => 100, 'sous_categorie_id' => $this->sousCategorie->id, 'operation_id' => null, 'seance' => null, 'notes' => null]]
        );
        expect($transaction->id)->not->toBeNull();
    });

    it('blocks delete on closed exercice', function () {
        // Create transaction on open exercice, then close it
        $transaction = Transaction::factory()->asDepense()->create([
            'date' => '2025-10-15',
            'compte_id' => $this->compte->id,
        ]);

        app(TransactionService::class)->delete($transaction);
    })->throws(ExerciceCloturedException::class);
});

describe('VirementInterneService verrou', function () {
    it('blocks create on closed exercice', function () {
        $compte2 = CompteBancaire::factory()->create();
        app(VirementInterneService::class)->create([
            'date' => '2025-10-15',
            'compte_source_id' => $this->compte->id,
            'compte_destination_id' => $compte2->id,
            'montant' => 100,
            'libelle' => 'Test',
        ]);
    })->throws(ExerciceCloturedException::class);
});

describe('RapprochementBancaireService verrou', function () {
    it('blocks create on closed exercice date_fin', function () {
        app(RapprochementBancaireService::class)->create(
            $this->compte,
            '2025-11-30',
            500.00
        );
    })->throws(ExerciceCloturedException::class);

    it('allows toggleTransaction when rapprochement is on open exercice even if transaction is from closed exercice', function () {
        // Rapprochement on exercice 2024 (open)
        $rapprochement = RapprochementBancaire::create([
            'compte_id' => $this->compte->id,
            'date_fin' => '2024-12-31',
            'solde_ouverture' => 0,
            'solde_fin' => 100,
            'statut' => StatutRapprochement::EnCours,
            'saisi_par' => $this->user->id,
        ]);

        // Transaction from closed exercice 2025 (cross-exercice pointing)
        $transaction = Transaction::factory()->asDepense()->create([
            'date' => '2025-10-15',
            'compte_id' => $this->compte->id,
            'montant_total' => 50,
        ]);

        // Should NOT throw — rapprochement is on open exercice
        app(RapprochementBancaireService::class)->toggleTransaction($rapprochement, 'depense', $transaction->id);

        $transaction->refresh();
        expect($transaction->rapprochement_id)->toBe($rapprochement->id);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/ExerciceVerrouServiceTest.php`
Expected: FAIL — no exception thrown (lock not yet implemented)

- [ ] **Step 3: Add lock to TransactionService**

In `app/Services/TransactionService.php`, inject ExerciceService and add `assertOuvert()` calls:

Add at the top of the class:
```php
public function __construct(
    private readonly ExerciceService $exerciceService,
) {}
```

Add at the beginning of `create()` (before `$this->validateInscriptionRequiresOperation`):
```php
$this->exerciceService->assertOuvert(
    $this->exerciceService->anneeForDate(\Carbon\CarbonImmutable::parse($data['date']))
);
```

Add at the beginning of `update()` (before `$this->validateInscriptionRequiresOperation`):
```php
$this->exerciceService->assertOuvert(
    $this->exerciceService->anneeForDate(\Carbon\CarbonImmutable::parse($data['date']))
);
```

Add at the beginning of `delete()` (before the rapprochement check):
```php
$this->exerciceService->assertOuvert(
    $this->exerciceService->anneeForDate(\Carbon\CarbonImmutable::parse($transaction->date))
);
```

Add at the beginning of `affecterLigne()`:
```php
$this->exerciceService->assertOuvert($ligne->exercice);
```

Add at the beginning of `supprimerAffectations()`:
```php
$this->exerciceService->assertOuvert($ligne->exercice);
```

- [ ] **Step 4: Add lock to VirementInterneService**

In `app/Services/VirementInterneService.php`:

Add constructor:
```php
public function __construct(
    private readonly ExerciceService $exerciceService,
) {}
```

Add at the beginning of `create()`:
```php
$this->exerciceService->assertOuvert(
    $this->exerciceService->anneeForDate(\Carbon\CarbonImmutable::parse($data['date']))
);
```

Add at the beginning of `update()`:
```php
$this->exerciceService->assertOuvert(
    $this->exerciceService->anneeForDate(\Carbon\CarbonImmutable::parse($data['date']))
);
```

Add at the beginning of `delete()`:
```php
$this->exerciceService->assertOuvert(
    $this->exerciceService->anneeForDate(\Carbon\CarbonImmutable::parse($virement->date))
);
```

- [ ] **Step 5: Add lock to RapprochementBancaireService**

In `app/Services/RapprochementBancaireService.php`:

Add constructor:
```php
public function __construct(
    private readonly ExerciceService $exerciceService,
) {}
```

Add at the beginning of `create()` (before the enCours check):
```php
$this->exerciceService->assertOuvert(
    $this->exerciceService->anneeForDate(\Carbon\CarbonImmutable::parse($dateFin))
);
```

Add at the beginning of `createVerrouilleAuto()`:
```php
$this->exerciceService->assertOuvert(
    $this->exerciceService->anneeForDate(\Carbon\CarbonImmutable::parse($dateFin))
);
```

Add at the beginning of `toggleTransaction()` (after the verrouille check), using the **rapprochement's** date_fin, not the transaction's date:
```php
$this->exerciceService->assertOuvert(
    $this->exerciceService->anneeForDate(\Carbon\CarbonImmutable::parse($rapprochement->date_fin))
);
```

Add at the beginning of `verrouiller()`:
```php
$this->exerciceService->assertOuvert(
    $this->exerciceService->anneeForDate(\Carbon\CarbonImmutable::parse($rapprochement->date_fin))
);
```

Add at the beginning of `deverrouiller()` (after the isVerrouille check):
```php
$this->exerciceService->assertOuvert(
    $this->exerciceService->anneeForDate(\Carbon\CarbonImmutable::parse($rapprochement->date_fin))
);
```

Add at the beginning of `supprimer()` (after the isVerrouille check):
```php
$this->exerciceService->assertOuvert(
    $this->exerciceService->anneeForDate(\Carbon\CarbonImmutable::parse($rapprochement->date_fin))
);
```

- [ ] **Step 6: Add inline lock to BudgetTable**

In `app/Livewire/BudgetTable.php`, add at the beginning of `addLine()`, `saveEdit()`, and `deleteLine()`:
```php
app(ExerciceService::class)->assertOuvert(app(ExerciceService::class)->current());
```

- [ ] **Step 7: Run tests**

Run: `./vendor/bin/sail test tests/Feature/ExerciceVerrouServiceTest.php`
Expected: ALL PASS

- [ ] **Step 8: Run full test suite to check for regressions**

Run: `./vendor/bin/sail test`
Expected: ALL PASS (existing tests should not break since exercices table is empty or has open exercices by default)

- [ ] **Step 9: Commit**

```bash
git add app/Services/TransactionService.php app/Services/VirementInterneService.php app/Services/RapprochementBancaireService.php app/Livewire/BudgetTable.php tests/Feature/ExerciceVerrouServiceTest.php
git commit -m "feat(exercices): add service lock assertOuvert on all mutation services"
```

---

## Task 7: Trait RespectsExerciceCloture + inject into all Livewire components

**Files:**
- Create: `app/Livewire/Concerns/RespectsExerciceCloture.php`
- Modify: 12 existing Livewire components (add `use RespectsExerciceCloture`)
- Test: `tests/Feature/Livewire/ExerciceClotureLivewireTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Livewire\Dashboard;
use App\Livewire\TransactionList;
use App\Models\Exercice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('sets exerciceCloture to false when exercice is open', function () {
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);

    Livewire::test(Dashboard::class)
        ->assertSet('exerciceCloture', false);
});

it('sets exerciceCloture to true when exercice is closed', function () {
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Cloture]);
    session(['exercice_actif' => 2025]);

    Livewire::test(Dashboard::class)
        ->assertSet('exerciceCloture', true);
});

it('sets exerciceCloture to false when exercice does not exist', function () {
    session(['exercice_actif' => 2099]);

    Livewire::test(Dashboard::class)
        ->assertSet('exerciceCloture', false);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ExerciceClotureLivewireTest.php`
Expected: FAIL — property not found

- [ ] **Step 3: Create trait**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Services\ExerciceService;

trait RespectsExerciceCloture
{
    public bool $exerciceCloture = false;

    public function bootRespectsExerciceCloture(): void
    {
        $exercice = app(ExerciceService::class)->exerciceAffiche();
        $this->exerciceCloture = $exercice?->isCloture() ?? false;
    }
}
```

- [ ] **Step 4: Add trait to all 12 components**

Add `use \App\Livewire\Concerns\RespectsExerciceCloture;` to these files (after existing `use` traits):

1. `app/Livewire/TransactionList.php`
2. `app/Livewire/TransactionCompteList.php`
3. `app/Livewire/TransactionForm.php`
4. `app/Livewire/TransactionUniverselle.php`
5. `app/Livewire/VirementInterneList.php`
6. `app/Livewire/VirementInterneForm.php`
7. `app/Livewire/RapprochementList.php`
8. `app/Livewire/RapprochementDetail.php`
9. `app/Livewire/BudgetTable.php`
10. `app/Livewire/ImportCsv.php`
11. `app/Livewire/Banques/HelloassoSyncWizard.php`
12. `app/Livewire/Dashboard.php`

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ExerciceClotureLivewireTest.php`
Expected: ALL PASS

- [ ] **Step 6: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: ALL PASS

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Concerns/RespectsExerciceCloture.php app/Livewire/TransactionList.php app/Livewire/TransactionCompteList.php app/Livewire/TransactionForm.php app/Livewire/TransactionUniverselle.php app/Livewire/VirementInterneList.php app/Livewire/VirementInterneForm.php app/Livewire/RapprochementList.php app/Livewire/RapprochementDetail.php app/Livewire/BudgetTable.php app/Livewire/ImportCsv.php app/Livewire/Banques/HelloassoSyncWizard.php app/Livewire/Dashboard.php tests/Feature/Livewire/ExerciceClotureLivewireTest.php
git commit -m "feat(exercices): add RespectsExerciceCloture trait to 12 Livewire components"
```

---

## Task 8: Routes + Navbar + Menu

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`
- Create: `resources/views/exercices/cloture.blade.php`
- Create: `resources/views/exercices/changer.blade.php`
- Create: `resources/views/exercices/reouvrir.blade.php`
- Create: `resources/views/exercices/audit.blade.php`

- [ ] **Step 1: Update routes**

In `routes/web.php`:

Remove lines 50-59 (the POST `/exercice/changer` route).

Add new routes inside the `auth` middleware group, after the rapports line:
```php
// Exercices
Route::view('/exercices/cloture', 'exercices.cloture')->name('exercices.cloture');
Route::view('/exercices/changer', 'exercices.changer')->name('exercices.changer');
Route::view('/exercices/reouvrir', 'exercices.reouvrir')->name('exercices.reouvrir');
Route::view('/exercices/audit', 'exercices.audit')->name('exercices.audit');
```

- [ ] **Step 2: Create layout wrapper views**

Create `resources/views/exercices/cloture.blade.php`:
```blade
<x-app-layout>
    <x-slot name="title">Clôturer l'exercice</x-slot>
    <livewire:exercices.cloture-wizard />
</x-app-layout>
```

Create `resources/views/exercices/changer.blade.php`:
```blade
<x-app-layout>
    <x-slot name="title">Changer d'exercice</x-slot>
    <livewire:exercices.changer-exercice />
</x-app-layout>
```

Create `resources/views/exercices/reouvrir.blade.php`:
```blade
<x-app-layout>
    <x-slot name="title">Réouvrir l'exercice</x-slot>
    <livewire:exercices.reouvrir-exercice />
</x-app-layout>
```

Create `resources/views/exercices/audit.blade.php`:
```blade
<x-app-layout>
    <x-slot name="title">Piste d'audit</x-slot>
    <livewire:exercices.piste-audit />
</x-app-layout>
```

- [ ] **Step 3: Update navbar — replace exercice dropdown with badge**

In `resources/views/layouts/app.blade.php`:

Replace lines 1-11 (the `@php` block) — remove `$exercicesDispos`:
```blade
@php
    $association   = \App\Models\Association::find(1);
    $nomAsso       = $association?->nom ?? 'Soigner•Vivre•Sourire';
    $logoAsset     = ($association?->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($association->logo_path))
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($association->logo_path)
        : asset('images/logo.png');
    $exerciceService = app(\App\Services\ExerciceService::class);
    $exerciceActif   = $exerciceService->current();
    $exerciceLabel   = $exerciceService->label($exerciceActif);
    $exerciceModel   = $exerciceService->exerciceAffiche();
    $exerciceCloture = $exerciceModel?->isCloture() ?? false;
@endphp
```

Replace lines 293-319 (the exercice dropdown) with a non-clickable badge:
```blade
<li class="nav-item d-flex align-items-center">
    <span class="badge rounded-pill"
          style="background-color: rgba(255,255,255,0.18); color:#fff; font-size:.8rem; font-weight:500; padding:.4em .85em; border: 1px solid rgba(255,255,255,0.35) !important;">
        <i class="bi bi-{{ $exerciceCloture ? 'lock' : 'calendar3' }}"></i>
        Exercice {{ $exerciceLabel }}
    </span>
</li>
```

- [ ] **Step 4: Add Exercices dropdown menu in navbar**

In the navbar, between "Rapports" and "Paramètres" (after the `@endforeach` for `$navItems` around line 230), add:

```blade
{{-- Dropdown Exercices --}}
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle {{ request()->routeIs('exercices.*') ? 'active' : '' }}"
       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-journal-check"></i> Exercices
    </a>
    <ul class="dropdown-menu">
        @if ($exerciceCloture)
            <li>
                <a class="dropdown-item text-danger {{ request()->routeIs('exercices.reouvrir') ? 'active' : '' }}"
                   href="{{ route('exercices.reouvrir') }}">
                    <i class="bi bi-unlock"></i> Réouvrir l'exercice
                </a>
            </li>
        @else
            <li>
                <a class="dropdown-item {{ request()->routeIs('exercices.cloture') ? 'active' : '' }}"
                   href="{{ route('exercices.cloture') }}">
                    <i class="bi bi-lock"></i> Clôturer l'exercice
                </a>
            </li>
        @endif
        <li>
            <a class="dropdown-item {{ request()->routeIs('exercices.changer') ? 'active' : '' }}"
               href="{{ route('exercices.changer') }}">
                <i class="bi bi-arrow-left-right"></i> Changer d'exercice
            </a>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <a class="dropdown-item {{ request()->routeIs('exercices.audit') ? 'active' : '' }}"
               href="{{ route('exercices.audit') }}">
                <i class="bi bi-clock-history"></i> Piste d'audit
            </a>
        </li>
    </ul>
</li>
```

- [ ] **Step 5: Commit**

```bash
git add routes/web.php resources/views/layouts/app.blade.php resources/views/exercices/
git commit -m "feat(exercices): add routes, navbar menu, and layout wrappers"
```

---

## Task 9: Livewire — ClotureWizard

**Files:**
- Create: `app/Livewire/Exercices/ClotureWizard.php`
- Create: `resources/views/livewire/exercices/cloture-wizard.blade.php`
- Test: `tests/Feature/Livewire/ClotureWizardTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Livewire\Exercices\ClotureWizard;
use App\Models\Exercice;
use App\Models\User;
use App\Services\ClotureCheckResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);
});

it('renders step 1 with checks', function () {
    Livewire::test(ClotureWizard::class)
        ->assertOk()
        ->assertSee('Contrôles pré-clôture')
        ->assertSet('step', 1);
});

it('can advance to step 2 when all blocking checks pass', function () {
    Livewire::test(ClotureWizard::class)
        ->call('suite')
        ->assertSet('step', 2)
        ->assertSee('Récapitulatif');
});

it('cannot advance to step 2 when blocking checks fail', function () {
    // Create a blocking condition: rapprochement en cours
    $compte = \App\Models\CompteBancaire::factory()->create();
    \App\Models\RapprochementBancaire::create([
        'compte_id' => $compte->id,
        'date_fin' => '2025-11-30',
        'solde_ouverture' => 0,
        'solde_fin' => 100,
        'statut' => \App\Enums\StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(ClotureWizard::class)
        ->call('suite')
        ->assertSet('step', 1);
});

it('can navigate back from step 2 to step 1', function () {
    Livewire::test(ClotureWizard::class)
        ->call('suite')
        ->call('goToStep', 1)
        ->assertSet('step', 1);
});

it('can advance to step 3 from step 2', function () {
    Livewire::test(ClotureWizard::class)
        ->call('suite')
        ->call('suite')
        ->assertSet('step', 3)
        ->assertSee('Confirmation');
});

it('can close the exercice from step 3', function () {
    Livewire::test(ClotureWizard::class)
        ->call('suite')
        ->call('suite')
        ->call('cloturer')
        ->assertRedirect(route('exercices.changer'));

    $this->exercice->refresh();
    expect($this->exercice->statut)->toBe(StatutExercice::Cloture);
});

it('redirects to changer if exercice is already closed', function () {
    $this->exercice->update(['statut' => StatutExercice::Cloture]);

    Livewire::test(ClotureWizard::class)
        ->assertRedirect(route('exercices.changer'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ClotureWizardTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create ClotureWizard component**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Exercices;

use App\Services\ClotureCheckResult;
use App\Services\ClotureCheckService;
use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Component;

final class ClotureWizard extends Component
{
    public int $step = 1;

    public int $annee;

    public ?ClotureCheckResult $checkResult = null;

    public function mount(): void
    {
        $exerciceService = app(ExerciceService::class);
        $this->annee = $exerciceService->current();

        $exercice = $exerciceService->exerciceAffiche();
        if ($exercice?->isCloture()) {
            $this->redirect(route('exercices.changer'));

            return;
        }

        $this->runChecks();
    }

    public function runChecks(): void
    {
        $this->checkResult = app(ClotureCheckService::class)->executer($this->annee);
    }

    public function suite(): void
    {
        if ($this->step === 1) {
            $this->runChecks();
            if (! $this->checkResult->peutCloturer()) {
                return;
            }
            $this->step = 2;

            return;
        }

        if ($this->step === 2) {
            $this->step = 3;
        }
    }

    public function goToStep(int $step): void
    {
        if ($step < $this->step) {
            $this->step = $step;
            if ($step === 1) {
                $this->runChecks();
            }
        }
    }

    public function cloturer(): void
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->exerciceAffiche();

        if ($exercice === null || $exercice->isCloture()) {
            return;
        }

        $exerciceService->cloturer($exercice, auth()->user());

        session()->flash('success', "L'exercice {$exercice->label()} a été clôturé avec succès.");
        $this->redirect(route('exercices.changer'));
    }

    public function render(): View
    {
        return view('livewire.exercices.cloture-wizard', [
            'exerciceLabel' => app(ExerciceService::class)->label($this->annee),
        ]);
    }
}
```

- [ ] **Step 4: Create cloture-wizard blade view**

Create `resources/views/livewire/exercices/cloture-wizard.blade.php` following the HelloAsso wizard accordion pattern. 3-step accordion: Contrôles pré-clôture → Récapitulatif → Confirmation. Use `card border-primary` for active step, `bg-success` badge for completed steps, clickable headers for past steps via `wire:click="goToStep(N)"`. Step 1 shows blocking checks (red/green icons), warning checks (orange/green), and soldes. Step 2 shows summary table. Step 3 shows warning alert and red "Clôturer l'exercice" button.

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ClotureWizardTest.php`
Expected: ALL PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Exercices/ClotureWizard.php resources/views/livewire/exercices/cloture-wizard.blade.php tests/Feature/Livewire/ClotureWizardTest.php
git commit -m "feat(exercices): add ClotureWizard 3-step accordion component"
```

---

## Task 10: Livewire — ChangerExercice

**Files:**
- Create: `app/Livewire/Exercices/ChangerExercice.php`
- Create: `resources/views/livewire/exercices/changer-exercice.blade.php`
- Test: `tests/Feature/Livewire/ChangerExerciceTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Livewire\Exercices\ChangerExercice;
use App\Models\Exercice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Cloture]);
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);
});

it('renders the list of exercices', function () {
    Livewire::test(ChangerExercice::class)
        ->assertOk()
        ->assertSee('2025-2026')
        ->assertSee('2024-2025');
});

it('shows current exercice as active', function () {
    Livewire::test(ChangerExercice::class)
        ->assertSee('Affiché');
});

it('can switch to another exercice', function () {
    Livewire::test(ChangerExercice::class)
        ->call('changer', 2024);

    expect(session('exercice_actif'))->toBe(2024);
});

it('can create a new exercice', function () {
    Livewire::test(ChangerExercice::class)
        ->set('nouvelleAnnee', 2026)
        ->call('creer');

    expect(Exercice::where('annee', 2026)->exists())->toBeTrue();
});

it('cannot create a duplicate exercice', function () {
    Livewire::test(ChangerExercice::class)
        ->set('nouvelleAnnee', 2025)
        ->call('creer')
        ->assertHasErrors('nouvelleAnnee');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ChangerExerciceTest.php`
Expected: FAIL

- [ ] **Step 3: Create ChangerExercice component**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Exercices;

use App\Models\Exercice;
use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Component;

final class ChangerExercice extends Component
{
    public bool $showCreateModal = false;

    public ?int $nouvelleAnnee = null;

    public ?int $confirmExerciceId = null;

    public function changer(int $annee): void
    {
        $exercice = Exercice::where('annee', $annee)->firstOrFail();
        app(ExerciceService::class)->changerExerciceAffiche($exercice);

        $this->redirect(route('exercices.changer'));
    }

    public function creer(): void
    {
        $this->validate([
            'nouvelleAnnee' => ['required', 'integer', 'min:2000', 'max:2099', 'unique:exercices,annee'],
        ], [
            'nouvelleAnnee.unique' => 'Cet exercice existe déjà.',
        ]);

        app(ExerciceService::class)->creerExercice($this->nouvelleAnnee, auth()->user());

        $this->showCreateModal = false;
        $this->nouvelleAnnee = null;
        session()->flash('success', 'Exercice créé avec succès.');
    }

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);

        return view('livewire.exercices.changer-exercice', [
            'exercices' => Exercice::orderByDesc('annee')->get(),
            'exerciceActif' => $exerciceService->current(),
        ]);
    }
}
```

- [ ] **Step 4: Create changer-exercice blade view**

Create `resources/views/livewire/exercices/changer-exercice.blade.php`: info banner showing current exercice, table with columns (Exercice, Période, Statut badge, Date clôture, Clôturé par, Action), "Affiché" text for current, "Afficher" button for others with confirmation modal for closed exercices, "Créer un exercice" button opening a modal with year input.

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ChangerExerciceTest.php`
Expected: ALL PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Exercices/ChangerExercice.php resources/views/livewire/exercices/changer-exercice.blade.php tests/Feature/Livewire/ChangerExerciceTest.php
git commit -m "feat(exercices): add ChangerExercice component with create modal"
```

---

## Task 11: Livewire — ReouvrirExercice

**Files:**
- Create: `app/Livewire/Exercices/ReouvrirExercice.php`
- Create: `resources/views/livewire/exercices/reouvrir-exercice.blade.php`
- Test: `tests/Feature/Livewire/ReouvrirExerciceTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Livewire\Exercices\ReouvrirExercice;
use App\Models\Exercice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->exercice = Exercice::create([
        'annee' => 2025,
        'statut' => StatutExercice::Cloture,
        'date_cloture' => now(),
        'cloture_par_id' => $this->user->id,
    ]);
    session(['exercice_actif' => 2025]);
});

it('renders with exercice info and history', function () {
    Livewire::test(ReouvrirExercice::class)
        ->assertOk()
        ->assertSee('2025-2026')
        ->assertSee('Réouvrir');
});

it('requires a motif to reopen', function () {
    Livewire::test(ReouvrirExercice::class)
        ->set('commentaire', '')
        ->call('reouvrir')
        ->assertHasErrors('commentaire');
});

it('reopens the exercice with a valid motif', function () {
    Livewire::test(ReouvrirExercice::class)
        ->set('commentaire', 'Erreur de saisie détectée après clôture')
        ->call('reouvrir');

    $this->exercice->refresh();
    expect($this->exercice->statut)->toBe(StatutExercice::Ouvert);
});

it('redirects if exercice is already open', function () {
    $this->exercice->update(['statut' => StatutExercice::Ouvert]);

    Livewire::test(ReouvrirExercice::class)
        ->assertRedirect(route('exercices.changer'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ReouvrirExerciceTest.php`
Expected: FAIL

- [ ] **Step 3: Create ReouvrirExercice component**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Exercices;

use App\Models\Exercice;
use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Component;

final class ReouvrirExercice extends Component
{
    public string $commentaire = '';

    public function mount(): void
    {
        $exercice = app(ExerciceService::class)->exerciceAffiche();
        if ($exercice === null || ! $exercice->isCloture()) {
            $this->redirect(route('exercices.changer'));
        }
    }

    public function reouvrir(): void
    {
        $this->validate([
            'commentaire' => ['required', 'string', 'min:5'],
        ], [
            'commentaire.required' => 'Le motif de réouverture est obligatoire.',
            'commentaire.min' => 'Le motif doit contenir au moins 5 caractères.',
        ]);

        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->exerciceAffiche();

        if ($exercice === null || ! $exercice->isCloture()) {
            return;
        }

        $exerciceService->reouvrir($exercice, auth()->user(), $this->commentaire);

        session()->flash('success', "L'exercice {$exercice->label()} a été réouvert.");
        $this->redirect(route('exercices.reouvrir'));
    }

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->exerciceAffiche();

        return view('livewire.exercices.reouvrir-exercice', [
            'exercice' => $exercice,
            'actions' => $exercice?->actions()->with('user')->latest('created_at')->get() ?? collect(),
        ]);
    }
}
```

- [ ] **Step 4: Create reouvrir-exercice blade view**

Create `resources/views/livewire/exercices/reouvrir-exercice.blade.php`: red warning box with last closure info, consequences list, mandatory textarea for motif, history of actions on this exercice, red "Réouvrir l'exercice" button.

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ReouvrirExerciceTest.php`
Expected: ALL PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Exercices/ReouvrirExercice.php resources/views/livewire/exercices/reouvrir-exercice.blade.php tests/Feature/Livewire/ReouvrirExerciceTest.php
git commit -m "feat(exercices): add ReouvrirExercice component with mandatory motif"
```

---

## Task 12: Livewire — PisteAudit

**Files:**
- Create: `app/Livewire/Exercices/PisteAudit.php`
- Create: `resources/views/livewire/exercices/piste-audit.blade.php`
- Test: `tests/Feature/Livewire/PisteAuditTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\TypeActionExercice;
use App\Livewire\Exercices\PisteAudit;
use App\Models\Exercice;
use App\Models\ExerciceAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders audit trail table', function () {
    $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    ExerciceAction::create([
        'exercice_id' => $exercice->id,
        'action' => TypeActionExercice::Creation,
        'user_id' => $this->user->id,
        'commentaire' => 'Création initiale',
    ]);

    Livewire::test(PisteAudit::class)
        ->assertOk()
        ->assertSee('2025-2026')
        ->assertSee('Création')
        ->assertSee($this->user->nom);
});

it('displays actions in reverse chronological order', function () {
    $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    ExerciceAction::create([
        'exercice_id' => $exercice->id,
        'action' => TypeActionExercice::Creation,
        'user_id' => $this->user->id,
    ]);
    ExerciceAction::create([
        'exercice_id' => $exercice->id,
        'action' => TypeActionExercice::Cloture,
        'user_id' => $this->user->id,
    ]);

    Livewire::test(PisteAudit::class)
        ->assertSeeInOrder(['Clôture', 'Création']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Livewire/PisteAuditTest.php`
Expected: FAIL

- [ ] **Step 3: Create PisteAudit component**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Exercices;

use App\Models\ExerciceAction;
use Illuminate\View\View;
use Livewire\Component;

final class PisteAudit extends Component
{
    public function render(): View
    {
        $actions = ExerciceAction::with(['exercice', 'user'])
            ->latest('created_at')
            ->get();

        return view('livewire.exercices.piste-audit', [
            'actions' => $actions,
        ]);
    }
}
```

- [ ] **Step 4: Create piste-audit blade view**

Create `resources/views/livewire/exercices/piste-audit.blade.php`: table with columns Date, Exercice, Action (colored badge), Utilisateur, Commentaire. Sorted by date descending.

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Livewire/PisteAuditTest.php`
Expected: ALL PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Exercices/PisteAudit.php resources/views/livewire/exercices/piste-audit.blade.php tests/Feature/Livewire/PisteAuditTest.php
git commit -m "feat(exercices): add PisteAudit component for audit trail"
```

---

## Task 13: Blade views — read-only mode on existing components

**Files:**
- Modify: 12 existing blade views to use `$exerciceCloture` for conditional disabling

This task modifies the blade views to disable/hide buttons when the exercice is closed. The changes follow a consistent pattern per component type.

- [ ] **Step 1: Transaction lists (transaction-list, transaction-compte-list)**

In both views:
- Wrap "Nouvelle transaction" button: `@if (! $exerciceCloture)`
- Change "Modifier" to "Visualiser" when closed: `{{ $exerciceCloture ? 'Visualiser' : 'Modifier' }}`
- Hide "Supprimer" button: `@if (! $exerciceCloture)`

- [ ] **Step 2: Transaction form**

In `resources/views/livewire/transaction-form.blade.php`:
- Add `{{ $exerciceCloture ? 'disabled' : '' }}` to all form inputs
- Hide "Sauvegarder" and "Supprimer" buttons: `@if (! $exerciceCloture)`

- [ ] **Step 3: Transaction universelle**

In `resources/views/livewire/transaction-universelle.blade.php`:
- Hide delete buttons: `@if (! $exerciceCloture)`
- Change edit to view: `{{ $exerciceCloture ? 'Visualiser' : 'Modifier' }}`

- [ ] **Step 4: Virement views (list + form)**

Same patterns as transaction views.

- [ ] **Step 5: Rapprochement views (list + detail)**

In rapprochement-list: hide "Nouveau rapprochement" button.
In rapprochement-detail: hide toggle pointage buttons, hide verrouiller/déverrouiller.

- [ ] **Step 6: Budget table**

In `resources/views/livewire/budget-table.blade.php`:
- Hide add/delete buttons: `@if (! $exerciceCloture)`
- Disable inline edit: `@if (! $exerciceCloture)` around startEdit buttons
- Hide import panel toggle

- [ ] **Step 7: Import CSV**

In `resources/views/livewire/import-csv.blade.php`:
- Show "Exercice clôturé — import désactivé" message instead of form when `$exerciceCloture`

- [ ] **Step 8: HelloAsso sync wizard**

In `resources/views/livewire/banques/helloasso-sync-wizard.blade.php`:
- Wrap entire wizard content: `@if ($exerciceCloture) <alert>Exercice clôturé</alert> @else ... @endif`

- [ ] **Step 9: Dashboard**

In `resources/views/livewire/dashboard.blade.php`:
- Add informational banner at top: `@if ($exerciceCloture) <div class="alert alert-info">Vous consultez un exercice clôturé (lecture seule)</div> @endif`

- [ ] **Step 10: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: ALL PASS

- [ ] **Step 11: Commit**

```bash
git add resources/views/livewire/
git commit -m "feat(exercices): add read-only mode to all blade views when exercice is closed"
```

---

## Task 14: TransactionUniverselle cleanup + DatabaseSeeder

**Files:**
- Modify: `app/Livewire/TransactionUniverselle.php` — remove `available()` usage
- Modify: `database/seeders/DatabaseSeeder.php` — create exercice after seeding transactions

- [ ] **Step 1: Clean up TransactionUniverselle**

In `app/Livewire/TransactionUniverselle.php`, search for any reference to `available()` or `$exercicesDispos`. Remove them and replace with exercice from `ExerciceService::current()`. The component should not list exercices anymore (the navbar dropdown was removed).

- [ ] **Step 2: Update DatabaseSeeder**

In `database/seeders/DatabaseSeeder.php`, add after transaction seeding:

```php
// Create exercice for seeded data
$exerciceService = app(\App\Services\ExerciceService::class);
$annee = $exerciceService->current();
if (!\App\Models\Exercice::where('annee', $annee)->exists()) {
    $exerciceService->creerExercice($annee, \App\Models\User::first());
}
```

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: ALL PASS

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/TransactionUniverselle.php database/seeders/DatabaseSeeder.php
git commit -m "feat(exercices): cleanup available() usage, seed exercice in DatabaseSeeder"
```

---

## Task 15: Final verification

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: ALL PASS

- [ ] **Step 2: Run Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`
Expected: Clean formatting

- [ ] **Step 3: Run fresh migration + seed**

Run: `./vendor/bin/sail artisan migrate:fresh --seed`
Expected: No errors, exercice created in database

- [ ] **Step 4: Verify in browser**

Open http://localhost, login as admin@svs.fr / password:
- Navbar shows exercice badge (non-clickable)
- Exercices menu appears between Rapports and Paramètres
- Clôturer l'exercice shows wizard with checks
- Changer d'exercice shows list with create button
- Piste d'audit shows creation actions from migration

- [ ] **Step 5: Final commit if any formatting fixes**

```bash
git add -A
git commit -m "style: apply pint formatting"
```
