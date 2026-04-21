# Slice Usages comptables — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Passer d'un modèle à flags booléens sur `sous_categories` à une table pivot `usages_sous_categories` pilotée par un enum `UsageComptable`, exposée via un écran Paramètres → Comptabilité → Usages. Ajouter un nouvel usage devient une valeur d'enum, plus jamais une migration de schéma.

**Architecture:** Table pivot `usages_sous_categories (association_id, sous_categorie_id, usage)` + enum `App\Enums\UsageComptable` (5 cases : Don, Cotisation, Inscription, FraisKilometriques, AbandonCreance). Helpers `SousCategorie::hasUsage()` / scope `forUsage()` / `Association::sousCategoriesFor()`. Service métier `UsagesComptablesService`. Écran Livewire `UsagesComptables` (cards Bootstrap, widgets mono/multi/sub-mono). Les 4 colonnes bool existantes sont migrées vers le pivot puis supprimées. Les 18 consumers de flags sont refactorés en deux vagues.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 (CDN), Pest PHP, MySQL via Laravel Sail.

**Référence spec :** [docs/specs/2026-04-21-usages-comptables-design.md](../docs/specs/2026-04-21-usages-comptables-design.md)

---

## Ordre de dispatch subagent

Tâches **séquentielles** (chacune dépend de la précédente) : 1 → 2 → 3 → 4 → 5.
Tâches **parallélisables** après 5 : 6a / 6b / 6c / 6d (refactor consumers).
Reprise séquentielle : 7 → 8 → 9 → 10 → 11 → 12 → 13.

---

## Task 1 : Migration — création de la table pivot

**Files:**
- Create: `database/migrations/2026_04_21_120000_create_usages_sous_categories_table.php`
- Test: `tests/Feature/Database/UsagesSousCategoriesTableTest.php`

- [ ] **Step 1 — Écrire le test de migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates the usages_sous_categories table with correct columns and unique index', function () {
    expect(Schema::hasTable('usages_sous_categories'))->toBeTrue();
    expect(Schema::hasColumns('usages_sous_categories', [
        'id', 'association_id', 'sous_categorie_id', 'usage', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('enforces unique (association_id, sous_categorie_id, usage)', function () {
    $asso = \App\Models\Association::factory()->create();
    \App\Support\TenantContext::boot($asso);
    $cat = \App\Models\Categorie::factory()->for($asso, 'association')->create(['type' => \App\Enums\TypeCategorie::Recette]);
    $sc = \App\Models\SousCategorie::factory()->for($asso, 'association')->for($cat)->create();

    \Illuminate\Support\Facades\DB::table('usages_sous_categories')->insert([
        'association_id' => $asso->id, 'sous_categorie_id' => $sc->id, 'usage' => 'don',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => \Illuminate\Support\Facades\DB::table('usages_sous_categories')->insert([
        'association_id' => $asso->id, 'sous_categorie_id' => $sc->id, 'usage' => 'don',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2 — Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail test tests/Feature/Database/UsagesSousCategoriesTableTest.php`
Expected: FAIL — table introuvable.

- [ ] **Step 3 — Écrire la migration**

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
        Schema::create('usages_sous_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('sous_categorie_id')->constrained('sous_categories')->cascadeOnDelete();
            $table->string('usage', 50);
            $table->timestamps();

            $table->unique(['association_id', 'sous_categorie_id', 'usage'], 'usages_sc_unique');
            $table->index(['association_id', 'usage'], 'usages_sc_asso_usage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usages_sous_categories');
    }
};
```

- [ ] **Step 4 — Lancer la migration et le test**

Run: `./vendor/bin/sail artisan migrate && ./vendor/bin/sail test tests/Feature/Database/UsagesSousCategoriesTableTest.php`
Expected: PASS.

- [ ] **Step 5 — Commit**

```bash
git add database/migrations/2026_04_21_120000_create_usages_sous_categories_table.php tests/Feature/Database/UsagesSousCategoriesTableTest.php
git commit -m "feat(usages): création table pivot usages_sous_categories"
```

---

## Task 2 : Enum `UsageComptable`

**Files:**
- Create: `app/Enums/UsageComptable.php`
- Test: `tests/Unit/Enums/UsageComptableTest.php`

- [ ] **Step 1 — Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;

it('has five cases', function () {
    expect(UsageComptable::cases())->toHaveCount(5);
});

it('returns label fr for each case', function () {
    expect(UsageComptable::Don->label())->toBe('Dons');
    expect(UsageComptable::Cotisation->label())->toBe('Cotisations');
    expect(UsageComptable::Inscription->label())->toBe('Inscriptions');
    expect(UsageComptable::FraisKilometriques->label())->toBe('Indemnités kilométriques');
    expect(UsageComptable::AbandonCreance->label())->toBe('Abandon de créance');
});

it('returns polarity (categorie type)', function () {
    expect(UsageComptable::FraisKilometriques->polarite())->toBe(TypeCategorie::Depense);
    expect(UsageComptable::Don->polarite())->toBe(TypeCategorie::Recette);
    expect(UsageComptable::Cotisation->polarite())->toBe(TypeCategorie::Recette);
    expect(UsageComptable::Inscription->polarite())->toBe(TypeCategorie::Recette);
    expect(UsageComptable::AbandonCreance->polarite())->toBe(TypeCategorie::Recette);
});

it('returns cardinality', function () {
    expect(UsageComptable::FraisKilometriques->cardinalite())->toBe('mono');
    expect(UsageComptable::AbandonCreance->cardinalite())->toBe('mono');
    expect(UsageComptable::Don->cardinalite())->toBe('multi');
    expect(UsageComptable::Cotisation->cardinalite())->toBe('multi');
    expect(UsageComptable::Inscription->cardinalite())->toBe('multi');
});
```

- [ ] **Step 2 — Test fail**

Run: `./vendor/bin/sail test tests/Unit/Enums/UsageComptableTest.php`
Expected: FAIL — enum introuvable.

- [ ] **Step 3 — Écrire l'enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum UsageComptable: string
{
    case Don = 'don';
    case Cotisation = 'cotisation';
    case Inscription = 'inscription';
    case FraisKilometriques = 'frais_kilometriques';
    case AbandonCreance = 'abandon_creance';

    public function label(): string
    {
        return match ($this) {
            self::Don => 'Dons',
            self::Cotisation => 'Cotisations',
            self::Inscription => 'Inscriptions',
            self::FraisKilometriques => 'Indemnités kilométriques',
            self::AbandonCreance => 'Abandon de créance',
        };
    }

    public function polarite(): TypeCategorie
    {
        return match ($this) {
            self::FraisKilometriques => TypeCategorie::Depense,
            self::Don, self::Cotisation, self::Inscription, self::AbandonCreance => TypeCategorie::Recette,
        };
    }

    public function cardinalite(): string
    {
        return match ($this) {
            self::FraisKilometriques, self::AbandonCreance => 'mono',
            self::Don, self::Cotisation, self::Inscription => 'multi',
        };
    }
}
```

- [ ] **Step 4 — Test pass**

Run: `./vendor/bin/sail test tests/Unit/Enums/UsageComptableTest.php`
Expected: PASS.

- [ ] **Step 5 — Commit**

```bash
git add app/Enums/UsageComptable.php tests/Unit/Enums/UsageComptableTest.php
git commit -m "feat(usages): enum UsageComptable avec label/polarité/cardinalité"
```

---

## Task 3 : Modèle pivot `UsageSousCategorie`

**Files:**
- Create: `app/Models/UsageSousCategorie.php`
- Create: `database/factories/UsageSousCategorieFactory.php`
- Test: `tests/Feature/Models/UsageSousCategorieTest.php`

- [ ] **Step 1 — Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use App\Support\TenantContext;

it('casts usage to UsageComptable enum', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $cat = Categorie::factory()->for($asso, 'association')->create(['type' => TypeCategorie::Recette]);
    $sc = SousCategorie::factory()->for($asso, 'association')->for($cat)->create();

    $link = UsageSousCategorie::create([
        'association_id' => $asso->id,
        'sous_categorie_id' => $sc->id,
        'usage' => UsageComptable::Don,
    ]);

    expect($link->usage)->toBe(UsageComptable::Don);
});

it('is tenant-scoped fail-closed', function () {
    $asso1 = Association::factory()->create();
    $asso2 = Association::factory()->create();
    TenantContext::boot($asso1);
    $cat1 = Categorie::factory()->for($asso1, 'association')->create(['type' => TypeCategorie::Recette]);
    $sc1 = SousCategorie::factory()->for($asso1, 'association')->for($cat1)->create();
    UsageSousCategorie::create([
        'association_id' => $asso1->id, 'sous_categorie_id' => $sc1->id, 'usage' => UsageComptable::Don,
    ]);
    TenantContext::boot($asso2);

    expect(UsageSousCategorie::count())->toBe(0);
});
```

- [ ] **Step 2 — Test fail**

Run: `./vendor/bin/sail test tests/Feature/Models/UsageSousCategorieTest.php`
Expected: FAIL.

- [ ] **Step 3 — Écrire le modèle et la factory**

`app/Models/UsageSousCategorie.php` :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UsageComptable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UsageSousCategorie extends TenantModel
{
    use HasFactory;

    protected $table = 'usages_sous_categories';

    protected $fillable = [
        'association_id',
        'sous_categorie_id',
        'usage',
    ];

    protected function casts(): array
    {
        return [
            'association_id' => 'integer',
            'sous_categorie_id' => 'integer',
            'usage' => UsageComptable::class,
        ];
    }

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class);
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }
}
```

`database/factories/UsageSousCategorieFactory.php` :

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UsageComptable;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use Illuminate\Database\Eloquent\Factories\Factory;

final class UsageSousCategorieFactory extends Factory
{
    protected $model = UsageSousCategorie::class;

    public function definition(): array
    {
        $sc = SousCategorie::factory()->create();

        return [
            'association_id' => $sc->association_id,
            'sous_categorie_id' => $sc->id,
            'usage' => UsageComptable::Don,
        ];
    }
}
```

- [ ] **Step 4 — Test pass**

Run: `./vendor/bin/sail test tests/Feature/Models/UsageSousCategorieTest.php`
Expected: PASS.

- [ ] **Step 5 — Commit**

```bash
git add app/Models/UsageSousCategorie.php database/factories/UsageSousCategorieFactory.php tests/Feature/Models/UsageSousCategorieTest.php
git commit -m "feat(usages): modèle UsageSousCategorie (TenantModel + factory)"
```

---

## Task 4 : Helpers sur `SousCategorie` et `Association`

**Files:**
- Modify: `app/Models/SousCategorie.php`
- Modify: `app/Models/Association.php`
- Test: `tests/Feature/Models/SousCategorieUsagesTest.php`

- [ ] **Step 1 — Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use App\Support\TenantContext;

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->catRecette = Categorie::factory()->for($this->asso, 'association')->create(['type' => TypeCategorie::Recette]);
    $this->scDon = SousCategorie::factory()->for($this->asso, 'association')->for($this->catRecette)->create(['nom' => 'Dons manuels']);
    $this->scCoti = SousCategorie::factory()->for($this->asso, 'association')->for($this->catRecette)->create(['nom' => 'Cotisations']);
    UsageSousCategorie::create([
        'association_id' => $this->asso->id, 'sous_categorie_id' => $this->scDon->id, 'usage' => UsageComptable::Don,
    ]);
    UsageSousCategorie::create([
        'association_id' => $this->asso->id, 'sous_categorie_id' => $this->scCoti->id, 'usage' => UsageComptable::Cotisation,
    ]);
});

it('SousCategorie::hasUsage returns true/false correctly', function () {
    expect($this->scDon->hasUsage(UsageComptable::Don))->toBeTrue();
    expect($this->scDon->hasUsage(UsageComptable::Cotisation))->toBeFalse();
    expect($this->scCoti->hasUsage(UsageComptable::Cotisation))->toBeTrue();
});

it('SousCategorie::forUsage scope filters correctly', function () {
    $dons = SousCategorie::forUsage(UsageComptable::Don)->get();
    expect($dons)->toHaveCount(1);
    expect($dons->first()->id)->toBe($this->scDon->id);
});

it('Association::sousCategoriesFor returns the right sous-cat', function () {
    $cotis = $this->asso->sousCategoriesFor(UsageComptable::Cotisation);
    expect($cotis)->toHaveCount(1);
    expect($cotis->first()->id)->toBe($this->scCoti->id);
});
```

- [ ] **Step 2 — Test fail**

Run: `./vendor/bin/sail test tests/Feature/Models/SousCategorieUsagesTest.php`
Expected: FAIL.

- [ ] **Step 3 — Modifier `app/Models/SousCategorie.php`** — ajouter relation `usages()`, méthode `hasUsage()`, scope `forUsage()`. Garder pour l'instant les 4 flags dans `$fillable` et `$casts` (retirés en Task 7).

Remplacer le contenu entier par :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UsageComptable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SousCategorie extends TenantModel
{
    use HasFactory;

    protected $table = 'sous_categories';

    protected $fillable = [
        'association_id',
        'categorie_id',
        'nom',
        'code_cerfa',
        'pour_dons',
        'pour_cotisations',
        'pour_inscriptions',
        'pour_frais_kilometriques',
    ];

    protected function casts(): array
    {
        return [
            'categorie_id' => 'integer',
            'pour_dons' => 'boolean',
            'pour_cotisations' => 'boolean',
            'pour_inscriptions' => 'boolean',
            'pour_frais_kilometriques' => 'boolean',
        ];
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class);
    }

    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'sous_categorie_id');
    }

    public function transactionLignes(): HasMany
    {
        return $this->hasMany(TransactionLigne::class, 'sous_categorie_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(UsageSousCategorie::class);
    }

    public function hasUsage(UsageComptable $usage): bool
    {
        return $this->usages()->where('usage', $usage->value)->exists();
    }

    public function scopeForUsage(Builder $query, UsageComptable $usage): Builder
    {
        return $query->whereHas('usages', fn (Builder $q) => $q->where('usage', $usage->value));
    }
}
```

- [ ] **Step 4 — Ajouter à `app/Models/Association.php`** la méthode `sousCategoriesFor` avant la fin de la classe :

```php
    public function sousCategoriesFor(\App\Enums\UsageComptable $usage): \Illuminate\Database\Eloquent\Collection
    {
        return SousCategorie::forUsage($usage)->where('association_id', $this->id)->orderBy('nom')->get();
    }
```

- [ ] **Step 5 — Test pass**

Run: `./vendor/bin/sail test tests/Feature/Models/SousCategorieUsagesTest.php`
Expected: PASS.

- [ ] **Step 6 — Commit**

```bash
git add app/Models/SousCategorie.php app/Models/Association.php tests/Feature/Models/SousCategorieUsagesTest.php
git commit -m "feat(usages): helpers hasUsage / forUsage / sousCategoriesFor"
```

---

## Task 5 : Migration de données — flags → pivot

**Files:**
- Create: `database/migrations/2026_04_21_120100_migrate_sous_categorie_flags_to_usages.php`
- Test: `tests/Feature/Database/MigrateFlagsToUsagesTest.php`

- [ ] **Step 1 — Écrire le test d'idempotence**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('migrates existing flags to pivot rows exactly once', function () {
    // On s'appuie sur DefaultChartOfAccountsService pour poser des flags existants
    $asso = \App\Models\Association::factory()->create();
    \App\Support\TenantContext::boot($asso);
    (new \App\Services\Onboarding\DefaultChartOfAccountsService())->applyTo($asso);

    $flagsCount = DB::table('sous_categories')
        ->where('association_id', $asso->id)
        ->where(function ($q) {
            $q->where('pour_dons', true)
              ->orWhere('pour_cotisations', true)
              ->orWhere('pour_inscriptions', true)
              ->orWhere('pour_frais_kilometriques', true);
        })->count();
    expect($flagsCount)->toBeGreaterThan(0);

    // Purger + re-jouer la data migration (simuler un rejeu)
    DB::table('usages_sous_categories')->where('association_id', $asso->id)->delete();

    // Invoquer la classe de migration directement
    $migration = include database_path('migrations/2026_04_21_120100_migrate_sous_categorie_flags_to_usages.php');
    $migration->up();
    $initialCount = DB::table('usages_sous_categories')->where('association_id', $asso->id)->count();

    // Rejouer : aucun doublon
    $migration->up();
    $afterReplayCount = DB::table('usages_sous_categories')->where('association_id', $asso->id)->count();

    expect($afterReplayCount)->toBe($initialCount);
});
```

- [ ] **Step 2 — Test fail**

Run: `./vendor/bin/sail test tests/Feature/Database/MigrateFlagsToUsagesTest.php`
Expected: FAIL — migration absente.

- [ ] **Step 3 — Écrire la migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $map = [
            'pour_dons' => 'don',
            'pour_cotisations' => 'cotisation',
            'pour_inscriptions' => 'inscription',
            'pour_frais_kilometriques' => 'frais_kilometriques',
        ];

        foreach ($map as $column => $usage) {
            DB::table('sous_categories')
                ->where($column, true)
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($usage) {
                    $now = now();
                    $inserts = [];
                    foreach ($rows as $r) {
                        $inserts[] = [
                            'association_id' => $r->association_id,
                            'sous_categorie_id' => $r->id,
                            'usage' => $usage,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($inserts !== []) {
                        DB::table('usages_sous_categories')->upsert(
                            $inserts,
                            ['association_id', 'sous_categorie_id', 'usage'],
                            ['updated_at']
                        );
                    }
                });
        }
    }

    public function down(): void
    {
        DB::table('usages_sous_categories')->whereIn('usage', [
            'don', 'cotisation', 'inscription', 'frais_kilometriques',
        ])->delete();
    }
};
```

- [ ] **Step 4 — Lancer la migration et le test**

Run: `./vendor/bin/sail artisan migrate && ./vendor/bin/sail test tests/Feature/Database/MigrateFlagsToUsagesTest.php`
Expected: PASS.

- [ ] **Step 5 — Commit**

```bash
git add database/migrations/2026_04_21_120100_migrate_sous_categorie_flags_to_usages.php tests/Feature/Database/MigrateFlagsToUsagesTest.php
git commit -m "feat(usages): migration de données flags → table pivot (idempotente)"
```

---

## Tasks 6a-6d : Refactor consumers (parallélisables)

Ces 4 tâches peuvent être dispatchées en parallèle après Task 5. Chacune remplace des accès directs aux colonnes booléennes par les helpers `hasUsage` / `forUsage` / `sousCategoriesFor`. **Les colonnes bool restent en base pendant cette phase** — les consumers consultent le pivot uniquement via les helpers. Après chaque tâche, lancer la suite complète pour non-régression.

### Task 6a : Consumers "lectures simples" (6 fichiers)

**Fichiers concernés (lire avant de modifier) :**
- `app/Livewire/Dashboard.php:63, 73`
- `app/Livewire/GestionDashboard.php:41, 51`
- `app/Livewire/AdherentList.php:41`
- `app/Livewire/CommunicationTiers.php:178, 195`
- `app/Livewire/ParticipantTable.php:471`
- `app/Livewire/OperationDetail.php:37, 41`

**Pattern de refactor :**

Avant :
```php
$donSousCategorieIds = SousCategorie::where('pour_dons', true)->pluck('id');
```
Après :
```php
$donSousCategorieIds = SousCategorie::forUsage(\App\Enums\UsageComptable::Don)->pluck('id');
```

Avant (OperationDetail) :
```php
->whereHas('sousCategorie', fn ($q) => $q->where('pour_dons', true))
```
Après :
```php
->whereHas('sousCategorie', fn ($q) => $q->whereHas('usages', fn ($u) => $u->where('usage', \App\Enums\UsageComptable::Don->value)))
```

- [ ] **Step 1 — Refactorer les 6 fichiers ci-dessus**

Pour chaque fichier, ouvrir et remplacer chaque occurrence de `where('pour_XXX', true)` par `forUsage(UsageComptable::YYY)` (avec le mapping ci-dessous). Ajouter `use App\Enums\UsageComptable;` en tête.

Mapping :
- `pour_dons` → `UsageComptable::Don`
- `pour_cotisations` → `UsageComptable::Cotisation`
- `pour_inscriptions` → `UsageComptable::Inscription`
- `pour_frais_kilometriques` → `UsageComptable::FraisKilometriques`

- [ ] **Step 2 — Lancer la suite complète**

Run: `./vendor/bin/sail test`
Expected: PASS (suite complète verte).

- [ ] **Step 3 — Commit**

```bash
git add app/Livewire/Dashboard.php app/Livewire/GestionDashboard.php app/Livewire/AdherentList.php app/Livewire/CommunicationTiers.php app/Livewire/ParticipantTable.php app/Livewire/OperationDetail.php
git commit -m "refactor(usages): consumers Dashboard/GestionDashboard/AdherentList/CommunicationTiers/ParticipantTable/OperationDetail → forUsage"
```

---

### Task 6b : Consumers Transaction & TypeOperation (5 fichiers)

**Fichiers concernés :**
- `app/Livewire/TransactionForm.php:480, 792`
- `app/Livewire/TransactionUniverselle.php:49` (commentaire)
- `app/Services/TransactionUniverselleService.php:101`
- `app/Services/TransactionService.php:300`
- `app/Livewire/TypeOperationShow.php:620`

**Cas particuliers** :
- `TransactionUniverselleService.php:101` et `TransactionForm.php:792` contiennent une liste blanche `['pour_dons', 'pour_cotisations', 'pour_inscriptions']` utilisée comme filtre. **Conserver ces chaînes string** (c'est une API de filtre externe), mais mapper vers `UsageComptable` à la lecture. Remplacer le bloc de filtrage par :

```php
$flagToUsage = [
    'pour_dons' => \App\Enums\UsageComptable::Don,
    'pour_cotisations' => \App\Enums\UsageComptable::Cotisation,
    'pour_inscriptions' => \App\Enums\UsageComptable::Inscription,
];
if (isset($flagToUsage[$this->sousCategorieFilter])) {
    $query->whereHas('sousCategorie', fn ($q) => $q->forUsage($flagToUsage[$this->sousCategorieFilter]));
}
```

(Adapter la variable `$this->sousCategorieFilter` au nom utilisé dans chaque fichier.)

- [ ] **Step 1 — Refactorer les 5 fichiers**

Lire chaque fichier aux lignes indiquées, appliquer le pattern. Pour `TypeOperationShow.php:620` :
```php
$sousCategories = SousCategorie::forUsage(UsageComptable::Inscription)->orderBy('nom')->get();
```

Pour `TransactionService.php:300` :
```php
$inscriptionSousCategorieIds = SousCategorie::forUsage(UsageComptable::Inscription)->pluck('id')->toArray();
```

- [ ] **Step 2 — Lancer la suite complète**

Run: `./vendor/bin/sail test`
Expected: PASS.

- [ ] **Step 3 — Commit**

```bash
git add app/Livewire/TransactionForm.php app/Livewire/TransactionUniverselle.php app/Services/TransactionUniverselleService.php app/Services/TransactionService.php app/Livewire/TypeOperationShow.php
git commit -m "refactor(usages): consumers Transaction/TypeOperation → forUsage"
```

---

### Task 6c : KilometriqueLigneType + HelloAssoSyncConfig + SousCategorieAutocomplete

**Fichiers concernés :**
- `app/Services/NoteDeFrais/LigneTypes/KilometriqueLigneType.php:84`
- `app/Livewire/Parametres/HelloassoSyncConfig.php:66-68`
- `app/Livewire/SousCategorieAutocomplete.php:21, 71`

**Cas particulier `SousCategorieAutocomplete`** :
La propriété `sousCategorieFlag` est une chaîne externe passée en paramètre (`pour_dons`, etc.). Garder la chaîne string en **input**, mapper vers `UsageComptable` à l'intérieur du composant. Remplacer la validation :

```php
// Avant
$allowedFlags = ['pour_dons', 'pour_cotisations', 'pour_inscriptions'];
// ... puis $query->where($this->sousCategorieFlag, true)

// Après
$flagToUsage = [
    'pour_dons' => \App\Enums\UsageComptable::Don,
    'pour_cotisations' => \App\Enums\UsageComptable::Cotisation,
    'pour_inscriptions' => \App\Enums\UsageComptable::Inscription,
];
if (isset($flagToUsage[$this->sousCategorieFlag])) {
    $query->forUsage($flagToUsage[$this->sousCategorieFlag]);
}
```

**Cas particulier `KilometriqueLigneType::resolveSousCategorie`** :
```php
// Avant
$flagged = SousCategorie::where('pour_frais_kilometriques', true)->pluck('id');
// Après
$flagged = SousCategorie::forUsage(\App\Enums\UsageComptable::FraisKilometriques)->pluck('id');
```

**Cas `HelloassoSyncConfig.php:66-68`** :
```php
return [
    'sousCategoriesDon' => SousCategorie::forUsage(UsageComptable::Don)->orderBy('nom')->get(),
    'sousCategoriesCotisation' => SousCategorie::forUsage(UsageComptable::Cotisation)->orderBy('nom')->get(),
    'sousCategoriesInscription' => SousCategorie::forUsage(UsageComptable::Inscription)->orderBy('nom')->get(),
];
```

- [ ] **Step 1 — Refactorer les 3 fichiers**

- [ ] **Step 2 — Lancer la suite complète**

Run: `./vendor/bin/sail test`
Expected: PASS.

- [ ] **Step 3 — Commit**

```bash
git add app/Services/NoteDeFrais/LigneTypes/KilometriqueLigneType.php app/Livewire/Parametres/HelloassoSyncConfig.php app/Livewire/SousCategorieAutocomplete.php
git commit -m "refactor(usages): KilometriqueLigneType + HelloAssoSyncConfig + SousCategorieAutocomplete → forUsage"
```

---

### Task 6d : TiersQuickViewService (raw SQL)

**Fichier :** `app/Services/TiersQuickViewService.php` (lignes 152, 153, 183, 213)

**Cas particulier** : ce service utilise des joins SQL bruts avec alias `sc.`. Remplacer les `where('sc.pour_XXX', true/false)` par un `exists` sur la table pivot.

**Lire le service intégralement avant modification** pour comprendre la structure des queries.

**Pattern de remplacement** (à adapter au contexte exact du service) :

```php
// Avant
->where('sc.pour_dons', true)

// Après
->whereExists(function ($q) {
    $q->from('usages_sous_categories as usc')
      ->whereColumn('usc.sous_categorie_id', 'sc.id')
      ->where('usc.usage', \App\Enums\UsageComptable::Don->value);
})

// Avant
->where('sc.pour_dons', false)
->where('sc.pour_cotisations', false)

// Après
->whereNotExists(function ($q) {
    $q->from('usages_sous_categories as usc')
      ->whereColumn('usc.sous_categorie_id', 'sc.id')
      ->whereIn('usc.usage', [
          \App\Enums\UsageComptable::Don->value,
          \App\Enums\UsageComptable::Cotisation->value,
      ]);
})
```

- [ ] **Step 1 — Lire le service intégralement**

Run: `cat app/Services/TiersQuickViewService.php | head -250`

- [ ] **Step 2 — Refactorer les 4 occurrences**

- [ ] **Step 3 — Lancer la suite complète**

Run: `./vendor/bin/sail test`
Expected: PASS.

- [ ] **Step 4 — Commit**

```bash
git add app/Services/TiersQuickViewService.php
git commit -m "refactor(usages): TiersQuickViewService raw SQL → whereExists pivot"
```

---

## Task 7 : Migration — drop des colonnes bool

**Files:**
- Create: `database/migrations/2026_04_21_120200_drop_flag_columns_from_sous_categories_table.php`
- Test: `tests/Feature/Database/DropFlagColumnsTest.php`

**Pré-requis** : Tasks 6a-6d terminées et suite verte.

- [ ] **Step 1 — Écrire le test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('drops flag columns from sous_categories', function () {
    expect(Schema::hasColumn('sous_categories', 'pour_dons'))->toBeFalse();
    expect(Schema::hasColumn('sous_categories', 'pour_cotisations'))->toBeFalse();
    expect(Schema::hasColumn('sous_categories', 'pour_inscriptions'))->toBeFalse();
    expect(Schema::hasColumn('sous_categories', 'pour_frais_kilometriques'))->toBeFalse();
});
```

- [ ] **Step 2 — Test fail**

Run: `./vendor/bin/sail test tests/Feature/Database/DropFlagColumnsTest.php`
Expected: FAIL — colonnes encore présentes.

- [ ] **Step 3 — Écrire la migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sous_categories', function (Blueprint $table) {
            $table->dropColumn([
                'pour_dons',
                'pour_cotisations',
                'pour_inscriptions',
                'pour_frais_kilometriques',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('sous_categories', function (Blueprint $table) {
            $table->boolean('pour_dons')->default(false);
            $table->boolean('pour_cotisations')->default(false);
            $table->boolean('pour_inscriptions')->default(false);
            $table->boolean('pour_frais_kilometriques')->default(false);
        });

        // Rejouer les flags depuis la pivot
        $map = [
            'don' => 'pour_dons',
            'cotisation' => 'pour_cotisations',
            'inscription' => 'pour_inscriptions',
            'frais_kilometriques' => 'pour_frais_kilometriques',
        ];
        foreach ($map as $usage => $column) {
            $ids = DB::table('usages_sous_categories')->where('usage', $usage)->pluck('sous_categorie_id');
            DB::table('sous_categories')->whereIn('id', $ids)->update([$column => true]);
        }
    }
};
```

- [ ] **Step 4 — Retirer les 4 flags de `$fillable` et `$casts` dans `app/Models/SousCategorie.php`**

Après modification, `$fillable` :
```php
protected $fillable = [
    'association_id',
    'categorie_id',
    'nom',
    'code_cerfa',
];
```

`casts()` :
```php
protected function casts(): array
{
    return [
        'categorie_id' => 'integer',
    ];
}
```

- [ ] **Step 5 — Lancer migration + suite**

Run: `./vendor/bin/sail artisan migrate && ./vendor/bin/sail test`
Expected: PASS suite complète.

- [ ] **Step 6 — Commit**

```bash
git add database/migrations/2026_04_21_120200_drop_flag_columns_from_sous_categories_table.php app/Models/SousCategorie.php tests/Feature/Database/DropFlagColumnsTest.php
git commit -m "feat(usages): drop des colonnes bool sous_categories + nettoyage model"
```

---

## Task 8 : Service `UsagesComptablesService`

**Files:**
- Create: `app/Services/UsagesComptablesService.php`
- Test: `tests/Feature/Services/UsagesComptablesServiceTest.php`

- [ ] **Step 1 — Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Services\UsagesComptablesService;
use App\Support\TenantContext;

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->catR = Categorie::factory()->for($this->asso, 'association')->create(['type' => TypeCategorie::Recette]);
    $this->catD = Categorie::factory()->for($this->asso, 'association')->create(['type' => TypeCategorie::Depense]);
    $this->service = app(UsagesComptablesService::class);
});

it('setFraisKilometriques poses the link and removes previous', function () {
    $sc1 = SousCategorie::factory()->for($this->asso, 'association')->for($this->catD)->create();
    $sc2 = SousCategorie::factory()->for($this->asso, 'association')->for($this->catD)->create();

    $this->service->setFraisKilometriques($sc1->id);
    expect($sc1->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();

    $this->service->setFraisKilometriques($sc2->id);
    expect($sc1->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeFalse();
    expect($sc2->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();
});

it('setFraisKilometriques(null) clears', function () {
    $sc = SousCategorie::factory()->for($this->asso, 'association')->for($this->catD)->create();
    $this->service->setFraisKilometriques($sc->id);
    $this->service->setFraisKilometriques(null);
    expect($sc->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeFalse();
});

it('toggleDon / toggleCotisation / toggleInscription are idempotent', function () {
    $sc = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create();
    $this->service->toggleDon($sc->id, true);
    $this->service->toggleDon($sc->id, true);
    expect($sc->fresh()->usages()->where('usage', UsageComptable::Don->value)->count())->toBe(1);

    $this->service->toggleDon($sc->id, false);
    expect($sc->fresh()->hasUsage(UsageComptable::Don))->toBeFalse();
});

it('setAbandonCreance on non-Don sous-cat throws', function () {
    $sc = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create();
    expect(fn () => $this->service->setAbandonCreance($sc->id))->toThrow(DomainException::class);
});

it('setAbandonCreance on Don sous-cat succeeds', function () {
    $sc = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create();
    $this->service->toggleDon($sc->id, true);
    $this->service->setAbandonCreance($sc->id);
    expect($sc->fresh()->hasUsage(UsageComptable::AbandonCreance))->toBeTrue();
});

it('toggleDon(false) cascades and removes AbandonCreance', function () {
    $sc = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create();
    $this->service->toggleDon($sc->id, true);
    $this->service->setAbandonCreance($sc->id);
    $this->service->toggleDon($sc->id, false);
    expect($sc->fresh()->hasUsage(UsageComptable::Don))->toBeFalse();
    expect($sc->fresh()->hasUsage(UsageComptable::AbandonCreance))->toBeFalse();
});

it('createAndFlag creates sous-cat and posts the pivot link', function () {
    $sc = $this->service->createAndFlag([
        'categorie_id' => $this->catR->id,
        'nom' => 'Nouvelle sous-cat',
        'code_cerfa' => null,
    ], UsageComptable::Cotisation);

    expect($sc)->toBeInstanceOf(SousCategorie::class);
    expect($sc->hasUsage(UsageComptable::Cotisation))->toBeTrue();
});

it('createAndFlag(AbandonCreance) also posts Don', function () {
    $sc = $this->service->createAndFlag([
        'categorie_id' => $this->catR->id,
        'nom' => 'Abandon de créance',
        'code_cerfa' => '771',
    ], UsageComptable::AbandonCreance);

    expect($sc->hasUsage(UsageComptable::Don))->toBeTrue();
    expect($sc->hasUsage(UsageComptable::AbandonCreance))->toBeTrue();
});

it('is tenant-scoped', function () {
    $asso2 = Association::factory()->create();
    TenantContext::boot($asso2);
    $catR2 = Categorie::factory()->for($asso2, 'association')->create(['type' => TypeCategorie::Recette]);
    $sc2 = SousCategorie::factory()->for($asso2, 'association')->for($catR2)->create();

    // Repasser en asso1, tenter d'agir sur sc2
    TenantContext::boot($this->asso);
    expect(fn () => $this->service->toggleDon($sc2->id, true))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
```

- [ ] **Step 2 — Test fail**

Run: `./vendor/bin/sail test tests/Feature/Services/UsagesComptablesServiceTest.php`
Expected: FAIL.

- [ ] **Step 3 — Écrire le service**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UsageComptable;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use App\Support\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

final class UsagesComptablesService
{
    public function setFraisKilometriques(?int $sousCategorieId): void
    {
        $this->setMono(UsageComptable::FraisKilometriques, $sousCategorieId);
    }

    public function setAbandonCreance(?int $sousCategorieId): void
    {
        if ($sousCategorieId !== null) {
            $sc = SousCategorie::findOrFail($sousCategorieId);
            if (! $sc->hasUsage(UsageComptable::Don)) {
                throw new DomainException('La sous-catégorie doit être un Don pour être désignée comme abandon de créance.');
            }
        }
        $this->setMono(UsageComptable::AbandonCreance, $sousCategorieId);
    }

    public function toggleDon(int $sousCategorieId, bool $active): void
    {
        $this->toggle(UsageComptable::Don, $sousCategorieId, $active);
        if (! $active) {
            // cascade : retirer AbandonCreance
            $this->toggle(UsageComptable::AbandonCreance, $sousCategorieId, false);
        }
    }

    public function toggleCotisation(int $sousCategorieId, bool $active): void
    {
        $this->toggle(UsageComptable::Cotisation, $sousCategorieId, $active);
    }

    public function toggleInscription(int $sousCategorieId, bool $active): void
    {
        $this->toggle(UsageComptable::Inscription, $sousCategorieId, $active);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    public function createAndFlag(array $attrs, UsageComptable $usage): SousCategorie
    {
        return DB::transaction(function () use ($attrs, $usage): SousCategorie {
            $sc = SousCategorie::create(array_merge(
                ['association_id' => TenantContext::currentId()],
                $attrs,
            ));
            $this->ensureLink($usage, $sc->id);
            if ($usage === UsageComptable::AbandonCreance) {
                $this->ensureLink(UsageComptable::Don, $sc->id);
            }

            return $sc;
        });
    }

    private function setMono(UsageComptable $usage, ?int $sousCategorieId): void
    {
        DB::transaction(function () use ($usage, $sousCategorieId): void {
            UsageSousCategorie::where('usage', $usage->value)->delete();
            if ($sousCategorieId !== null) {
                SousCategorie::findOrFail($sousCategorieId); // tenant scope vérifié
                $this->ensureLink($usage, $sousCategorieId);
            }
        });
    }

    private function toggle(UsageComptable $usage, int $sousCategorieId, bool $active): void
    {
        DB::transaction(function () use ($usage, $sousCategorieId, $active): void {
            $sc = SousCategorie::findOrFail($sousCategorieId); // tenant scope
            if ($active) {
                $this->ensureLink($usage, $sc->id);
            } else {
                UsageSousCategorie::where('sous_categorie_id', $sc->id)->where('usage', $usage->value)->delete();
            }
        });
    }

    private function ensureLink(UsageComptable $usage, int $sousCategorieId): void
    {
        UsageSousCategorie::firstOrCreate([
            'association_id' => TenantContext::currentId(),
            'sous_categorie_id' => $sousCategorieId,
            'usage' => $usage->value,
        ]);
    }
}
```

- [ ] **Step 4 — Test pass**

Run: `./vendor/bin/sail test tests/Feature/Services/UsagesComptablesServiceTest.php`
Expected: PASS.

- [ ] **Step 5 — Commit**

```bash
git add app/Services/UsagesComptablesService.php tests/Feature/Services/UsagesComptablesServiceTest.php
git commit -m "feat(usages): UsagesComptablesService (mono/multi + cascade + createAndFlag)"
```

---

## Task 9 : Composant Livewire `UsagesComptables`

**Files:**
- Create: `app/Livewire/Parametres/Comptabilite/UsagesComptables.php`
- Create: `resources/views/livewire/parametres/comptabilite/usages-comptables.blade.php`
- Test: `tests/Feature/Livewire/Parametres/Comptabilite/UsagesComptablesTest.php`

- [ ] **Step 1 — Écrire les tests feature**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Livewire\Parametres\Comptabilite\UsagesComptables;
use App\Models\Association;
use App\Models\AssociationUser;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\User;
use App\Support\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->admin = User::factory()->create();
    AssociationUser::create(['user_id' => $this->admin->id, 'association_id' => $this->asso->id, 'role' => 'admin', 'joined_at' => now()]);
    $this->catR = Categorie::factory()->for($this->asso, 'association')->create(['type' => TypeCategorie::Recette]);
    $this->catD = Categorie::factory()->for($this->asso, 'association')->create(['type' => TypeCategorie::Depense]);
    $this->actingAs($this->admin);
});

it('renders 4 usage cards', function () {
    Livewire::test(UsagesComptables::class)
        ->assertSee('Indemnités kilométriques')
        ->assertSee('Cotisations')
        ->assertSee('Inscriptions')
        ->assertSee('Dons');
});

it('toggleDon persists through service', function () {
    $sc = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create();
    Livewire::test(UsagesComptables::class)
        ->call('toggleDon', $sc->id, true);
    expect($sc->fresh()->hasUsage(UsageComptable::Don))->toBeTrue();
});

it('setFraisKilometriques switches mono link', function () {
    $sc1 = SousCategorie::factory()->for($this->asso, 'association')->for($this->catD)->create();
    $sc2 = SousCategorie::factory()->for($this->asso, 'association')->for($this->catD)->create();
    Livewire::test(UsagesComptables::class)
        ->set('fraisKmSelectedId', $sc1->id)
        ->call('saveFraisKilometriques');
    expect($sc1->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();
    Livewire::test(UsagesComptables::class)
        ->set('fraisKmSelectedId', $sc2->id)
        ->call('saveFraisKilometriques');
    expect($sc1->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeFalse();
    expect($sc2->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();
});

it('setAbandonCreance lists only Dons', function () {
    $scDon = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create(['nom' => 'Don A']);
    $scAutre = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create(['nom' => 'Autre']);
    app(\App\Services\UsagesComptablesService::class)->toggleDon($scDon->id, true);

    $comp = Livewire::test(UsagesComptables::class);
    $candidates = $comp->get('abandonCreanceCandidates');
    expect(collect($candidates)->pluck('id'))->toContain($scDon->id);
    expect(collect($candidates)->pluck('id'))->not->toContain($scAutre->id);
});

it('toggleDon false cascades AbandonCreance', function () {
    $sc = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create();
    $svc = app(\App\Services\UsagesComptablesService::class);
    $svc->toggleDon($sc->id, true);
    $svc->setAbandonCreance($sc->id);

    Livewire::test(UsagesComptables::class)->call('toggleDon', $sc->id, false);
    expect($sc->fresh()->hasUsage(UsageComptable::AbandonCreance))->toBeFalse();
});

it('createInlineSousCategorie creates + flags + filters categorie by polarity', function () {
    Livewire::test(UsagesComptables::class)
        ->set('inlineUsage', UsageComptable::Cotisation->value)
        ->set('inlineCategorieId', $this->catR->id)
        ->set('inlineNom', 'Nouvelle cotisation')
        ->set('inlineCodeCerfa', '751B')
        ->call('submitInline');
    $sc = SousCategorie::where('nom', 'Nouvelle cotisation')->first();
    expect($sc)->not->toBeNull();
    expect($sc->hasUsage(UsageComptable::Cotisation))->toBeTrue();
});

it('inline categorie list filtered to Depense for FraisKilometriques', function () {
    $comp = Livewire::test(UsagesComptables::class)
        ->set('inlineUsage', UsageComptable::FraisKilometriques->value);
    $cats = $comp->get('inlineCategoriesEligibles');
    $types = collect($cats)->pluck('type');
    expect($types->unique()->values()->all())->toBe([TypeCategorie::Depense]);
});

it('denies non-admin users', function () {
    $otherUser = User::factory()->create();
    $this->actingAs($otherUser);
    Livewire::test(UsagesComptables::class)->assertForbidden();
});
```

- [ ] **Step 2 — Test fail**

Run: `./vendor/bin/sail test tests/Feature/Livewire/Parametres/Comptabilite/UsagesComptablesTest.php`
Expected: FAIL.

- [ ] **Step 3 — Écrire le composant**

`app/Livewire/Parametres/Comptabilite/UsagesComptables.php` :

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Parametres\Comptabilite;

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Services\UsagesComptablesService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class UsagesComptables extends Component
{
    public ?int $fraisKmSelectedId = null;
    public ?int $abandonCreanceSelectedId = null;

    // Inline create modal
    public bool $inlineOpen = false;
    public ?string $inlineUsage = null; // UsageComptable value
    public ?int $inlineCategorieId = null;
    public string $inlineNom = '';
    public ?string $inlineCodeCerfa = null;

    public function mount(UsagesComptablesService $service): void
    {
        $this->authorize('admin');
        $fraisKm = SousCategorie::forUsage(UsageComptable::FraisKilometriques)->first();
        $this->fraisKmSelectedId = $fraisKm?->id;
        $abandon = SousCategorie::forUsage(UsageComptable::AbandonCreance)->first();
        $this->abandonCreanceSelectedId = $abandon?->id;
    }

    public function authorize(string $role): void
    {
        abort_unless(Auth::check() && Auth::user()->isAdmin(), 403);
    }

    public function toggleDon(int $id, bool $active): void
    {
        $this->authorize('admin');
        app(UsagesComptablesService::class)->toggleDon($id, $active);
    }

    public function toggleCotisation(int $id, bool $active): void
    {
        $this->authorize('admin');
        app(UsagesComptablesService::class)->toggleCotisation($id, $active);
    }

    public function toggleInscription(int $id, bool $active): void
    {
        $this->authorize('admin');
        app(UsagesComptablesService::class)->toggleInscription($id, $active);
    }

    public function saveFraisKilometriques(): void
    {
        $this->authorize('admin');
        app(UsagesComptablesService::class)->setFraisKilometriques($this->fraisKmSelectedId);
    }

    public function saveAbandonCreance(): void
    {
        $this->authorize('admin');
        try {
            app(UsagesComptablesService::class)->setAbandonCreance($this->abandonCreanceSelectedId);
        } catch (DomainException $e) {
            $this->addError('abandonCreance', $e->getMessage());
        }
    }

    public function openInline(string $usage): void
    {
        $this->authorize('admin');
        $this->reset(['inlineCategorieId', 'inlineNom', 'inlineCodeCerfa']);
        $this->inlineUsage = $usage;
        $this->inlineOpen = true;
    }

    public function submitInline(): void
    {
        $this->authorize('admin');
        $this->validate([
            'inlineUsage' => 'required|string',
            'inlineCategorieId' => 'required|integer|exists:categories,id',
            'inlineNom' => 'required|string|max:255',
            'inlineCodeCerfa' => 'nullable|string|max:20',
        ]);
        $usage = UsageComptable::from($this->inlineUsage);
        app(UsagesComptablesService::class)->createAndFlag([
            'categorie_id' => $this->inlineCategorieId,
            'nom' => $this->inlineNom,
            'code_cerfa' => $this->inlineCodeCerfa,
        ], $usage);
        $this->inlineOpen = false;
        $this->dispatch('usage-created');
    }

    public function getAbandonCreanceCandidatesProperty(): array
    {
        return SousCategorie::forUsage(UsageComptable::Don)->orderBy('nom')->get()->all();
    }

    public function getInlineCategoriesEligiblesProperty(): array
    {
        if ($this->inlineUsage === null) {
            return [];
        }
        $polarite = UsageComptable::from($this->inlineUsage)->polarite();

        return Categorie::where('type', $polarite)->orderBy('nom')->get()->all();
    }

    public function render(): View
    {
        return view('livewire.parametres.comptabilite.usages-comptables', [
            'sousCatsDepense' => SousCategorie::whereHas('categorie', fn ($q) => $q->where('type', TypeCategorie::Depense))->orderBy('nom')->get(),
            'sousCatsRecette' => SousCategorie::whereHas('categorie', fn ($q) => $q->where('type', TypeCategorie::Recette))->orderBy('nom')->get(),
            'sousCatsDon' => SousCategorie::forUsage(UsageComptable::Don)->pluck('id'),
            'sousCatsCotisation' => SousCategorie::forUsage(UsageComptable::Cotisation)->pluck('id'),
            'sousCatsInscription' => SousCategorie::forUsage(UsageComptable::Inscription)->pluck('id'),
        ]);
    }
}
```

- [ ] **Step 4 — Écrire la vue**

`resources/views/livewire/parametres/comptabilite/usages-comptables.blade.php` :

```blade
<div class="container py-4">
    <h1 class="h3 mb-4">Usages comptables</h1>
    <p class="text-muted mb-4">Configure les sous-catégories utilisées pour chaque cas d'usage comptable.</p>

    {{-- Card : Frais kilométriques --}}
    <div class="card mb-3">
        <div class="card-header text-white" style="background:#3d5473;">
            <strong>Indemnités kilométriques</strong>
            <small class="ms-2">La sous-catégorie utilisée quand un tiers déclare des kilomètres en note de frais.</small>
        </div>
        <div class="card-body">
            <div class="d-flex gap-2 align-items-start">
                <select class="form-select" style="max-width:480px" wire:model="fraisKmSelectedId" wire:change="saveFraisKilometriques">
                    <option value="">— Aucune —</option>
                    @foreach($sousCatsDepense as $sc)
                        <option value="{{ $sc->id }}">{{ $sc->nom }} ({{ $sc->code_cerfa ?? '—' }})</option>
                    @endforeach
                </select>
                <button type="button" class="btn btn-outline-secondary" wire:click="openInline('{{ \App\Enums\UsageComptable::FraisKilometriques->value }}')">
                    + Créer une sous-catégorie
                </button>
            </div>
        </div>
    </div>

    {{-- Card : Cotisations --}}
    <div class="card mb-3">
        <div class="card-header text-white" style="background:#3d5473;">
            <strong>Cotisations</strong>
            <small class="ms-2">Sous-catégories utilisées pour les cotisations des membres.</small>
        </div>
        <div class="card-body">
            @foreach($sousCatsRecette as $sc)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="coti_{{ $sc->id }}"
                        @checked($sousCatsCotisation->contains($sc->id))
                        wire:click="toggleCotisation({{ $sc->id }}, {{ $sousCatsCotisation->contains($sc->id) ? 'false' : 'true' }})">
                    <label class="form-check-label" for="coti_{{ $sc->id }}">{{ $sc->nom }} <small class="text-muted">({{ $sc->code_cerfa ?? '—' }})</small></label>
                </div>
            @endforeach
            <button type="button" class="btn btn-outline-secondary mt-2" wire:click="openInline('{{ \App\Enums\UsageComptable::Cotisation->value }}')">
                + Créer une sous-catégorie
            </button>
        </div>
    </div>

    {{-- Card : Inscriptions --}}
    <div class="card mb-3">
        <div class="card-header text-white" style="background:#3d5473;">
            <strong>Inscriptions</strong>
            <small class="ms-2">Sous-catégories utilisées pour les inscriptions/formations.</small>
        </div>
        <div class="card-body">
            @foreach($sousCatsRecette as $sc)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="inscr_{{ $sc->id }}"
                        @checked($sousCatsInscription->contains($sc->id))
                        wire:click="toggleInscription({{ $sc->id }}, {{ $sousCatsInscription->contains($sc->id) ? 'false' : 'true' }})">
                    <label class="form-check-label" for="inscr_{{ $sc->id }}">{{ $sc->nom }} <small class="text-muted">({{ $sc->code_cerfa ?? '—' }})</small></label>
                </div>
            @endforeach
            <button type="button" class="btn btn-outline-secondary mt-2" wire:click="openInline('{{ \App\Enums\UsageComptable::Inscription->value }}')">
                + Créer une sous-catégorie
            </button>
        </div>
    </div>

    {{-- Card : Dons + sub-mono AbandonCreance --}}
    <div class="card mb-3">
        <div class="card-header text-white" style="background:#3d5473;">
            <strong>Dons</strong>
            <small class="ms-2">Sous-catégories utilisées pour les dons manuels.</small>
        </div>
        <div class="card-body">
            @foreach($sousCatsRecette as $sc)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="don_{{ $sc->id }}"
                        @checked($sousCatsDon->contains($sc->id))
                        wire:click="toggleDon({{ $sc->id }}, {{ $sousCatsDon->contains($sc->id) ? 'false' : 'true' }})">
                    <label class="form-check-label" for="don_{{ $sc->id }}">{{ $sc->nom }} <small class="text-muted">({{ $sc->code_cerfa ?? '—' }})</small></label>
                </div>
            @endforeach
            <button type="button" class="btn btn-outline-secondary mt-2" wire:click="openInline('{{ \App\Enums\UsageComptable::Don->value }}')">
                + Créer une sous-catégorie
            </button>

            @if(count($this->abandonCreanceCandidates) > 0)
                <hr class="my-3">
                <label class="form-label"><strong>Abandon de créance</strong> <small class="text-muted">(sous-cat désignée pour les reçus fiscaux CERFA)</small></label>
                <div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="abandonCreance" value="" id="abandon_none" wire:model.live="abandonCreanceSelectedId" wire:change="saveAbandonCreance">
                        <label class="form-check-label" for="abandon_none">— Aucune —</label>
                    </div>
                    @foreach($this->abandonCreanceCandidates as $cand)
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="abandonCreance" value="{{ $cand->id }}" id="abandon_{{ $cand->id }}" wire:model.live="abandonCreanceSelectedId" wire:change="saveAbandonCreance">
                            <label class="form-check-label" for="abandon_{{ $cand->id }}">{{ $cand->nom }}</label>
                        </div>
                    @endforeach
                </div>
                @error('abandonCreance') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
            @endif
        </div>
    </div>

    {{-- Modale création inline --}}
    @if($inlineOpen)
        <div class="modal d-block" style="background:rgba(0,0,0,.5)" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Créer une sous-catégorie</h5>
                        <button type="button" class="btn-close" wire:click="$set('inlineOpen', false)"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Catégorie</label>
                            <select class="form-select" wire:model="inlineCategorieId">
                                <option value="">Sélectionner…</option>
                                @foreach($this->inlineCategoriesEligibles as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->nom }}</option>
                                @endforeach
                            </select>
                            @error('inlineCategorieId') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nom</label>
                            <input type="text" class="form-control" wire:model="inlineNom">
                            @error('inlineNom') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Code CERFA (optionnel)</label>
                            <input type="text" class="form-control" wire:model="inlineCodeCerfa">
                            @error('inlineCodeCerfa') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="$set('inlineOpen', false)">Annuler</button>
                        <button type="button" class="btn btn-primary" wire:click="submitInline">Créer</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 5 — Test pass**

Run: `./vendor/bin/sail test tests/Feature/Livewire/Parametres/Comptabilite/UsagesComptablesTest.php`
Expected: PASS.

- [ ] **Step 6 — Commit**

```bash
git add app/Livewire/Parametres/Comptabilite/UsagesComptables.php resources/views/livewire/parametres/comptabilite/usages-comptables.blade.php tests/Feature/Livewire/Parametres/Comptabilite/UsagesComptablesTest.php
git commit -m "feat(usages): composant Livewire UsagesComptables + vue cards + modale inline"
```

---

## Task 10 : Nettoyage UI `SousCategorieList`

**Files:**
- Modify: `app/Livewire/SousCategorieList.php`
- Modify: `resources/views/livewire/sous-categorie-list.blade.php` (chemin exact à vérifier)

- [ ] **Step 1 — Modifier `app/Livewire/SousCategorieList.php`** : retirer les 4 propriétés `$pour_*`, les règles de validation correspondantes, leur reset, leur save. Retirer aussi la méthode `toggleFlag` (ou équivalent, lignes 110-130). Retirer les lignes `69-72`, `83-86`, `93-96`, `113`, `172-175` du fichier tel qu'observé.

Vérifier que le formulaire de save ne transmet plus que les champs `nom`, `code_cerfa`, `categorie_id`, `actif` (ou équivalent selon le contenu actuel).

- [ ] **Step 2 — Modifier la vue Blade** : retirer les 4 colonnes flag de la table + les 4 checkboxes de la modale. Ajouter une note en bas de modale :

```blade
<p class="small text-muted mt-3 mb-0">
    Les usages comptables (Dons, Cotisations, Inscriptions, Frais km, Abandon de créance) se configurent dans
    <a href="{{ route('parametres.comptabilite.usages') }}">Paramètres → Comptabilité → Usages</a>.
</p>
```

- [ ] **Step 3 — Lancer la suite**

Run: `./vendor/bin/sail test`
Expected: PASS (les tests de `SousCategorieList` doivent être mis à jour si besoin pour refléter la suppression des checkboxes).

- [ ] **Step 4 — Commit**

```bash
git add app/Livewire/SousCategorieList.php resources/views/livewire/sous-categorie-list.blade.php
git commit -m "refactor(usages): SousCategorieList — retrait checkboxes flag + note de renvoi Usages"
```

---

## Task 11 : Refactor `DefaultChartOfAccountsService` + presets seed

**Files:**
- Modify: `app/Services/Onboarding/DefaultChartOfAccountsService.php`
- Test: `tests/Feature/Services/DefaultChartOfAccountsSeedTest.php`

- [ ] **Step 1 — Écrire le test des presets**

```php
<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\SousCategorie;
use App\Services\Onboarding\DefaultChartOfAccountsService;
use App\Support\TenantContext;

it('seeds 625A with FraisKilometriques and 771 with Don+AbandonCreance', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    (new DefaultChartOfAccountsService())->applyTo($asso);

    $km = SousCategorie::where('code_cerfa', '625A')->firstOrFail();
    expect($km->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();

    $abandon = SousCategorie::where('code_cerfa', '771')->firstOrFail();
    expect($abandon->hasUsage(UsageComptable::Don))->toBeTrue();
    expect($abandon->hasUsage(UsageComptable::AbandonCreance))->toBeTrue();

    $dons = SousCategorie::where('code_cerfa', '754')->firstOrFail();
    expect($dons->hasUsage(UsageComptable::Don))->toBeTrue();

    $coti = SousCategorie::where('code_cerfa', '751')->firstOrFail();
    expect($coti->hasUsage(UsageComptable::Cotisation))->toBeTrue();

    foreach (['706A', '706B'] as $cerfa) {
        $sc = SousCategorie::where('code_cerfa', $cerfa)->firstOrFail();
        expect($sc->hasUsage(UsageComptable::Inscription))->toBeTrue();
    }
});
```

- [ ] **Step 2 — Test fail**

Run: `./vendor/bin/sail test tests/Feature/Services/DefaultChartOfAccountsSeedTest.php`
Expected: FAIL — 625A et 771 non flaggés, seed toujours en mode flags bool.

- [ ] **Step 3 — Refactorer le service**

Remplacer le contenu de `app/Services/Onboarding/DefaultChartOfAccountsService.php` par :

```php
<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use Illuminate\Support\Facades\DB;

final class DefaultChartOfAccountsService
{
    /**
     * @return array<string, int>
     */
    public function applyTo(Association $association): array
    {
        $data = $this->defaultStructure();
        $categoriesCreated = 0;
        $sousCategoriesCreated = 0;

        DB::transaction(function () use ($association, $data, &$categoriesCreated, &$sousCategoriesCreated): void {
            foreach ($data as $cat) {
                $categorie = Categorie::create([
                    'association_id' => $association->id,
                    'nom' => $cat['nom'],
                    'type' => $cat['type'],
                ]);
                $categoriesCreated++;

                foreach ($cat['sous'] as $sc) {
                    $usages = $sc['usages'] ?? [];
                    unset($sc['usages']);
                    $sousCat = SousCategorie::create(array_merge([
                        'association_id' => $association->id,
                        'categorie_id' => $categorie->id,
                    ], $sc));
                    foreach ($usages as $usage) {
                        UsageSousCategorie::create([
                            'association_id' => $association->id,
                            'sous_categorie_id' => $sousCat->id,
                            'usage' => $usage->value,
                        ]);
                    }
                    $sousCategoriesCreated++;
                }
            }
        });

        return ['categories' => $categoriesCreated, 'sous_categories' => $sousCategoriesCreated];
    }

    /**
     * @return list<array{nom: string, type: TypeCategorie, sous: list<array<string, mixed>>}>
     */
    private function defaultStructure(): array
    {
        return [
            [
                'nom' => '60 - Achats',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Fournitures',        'code_cerfa' => '606'],
                    ['nom' => 'Petits équipements', 'code_cerfa' => '606B'],
                    ['nom' => 'Achats divers',      'code_cerfa' => '609'],
                ],
            ],
            [
                'nom' => '61 - Charges de fonctionnement',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Location salle',                     'code_cerfa' => '613A'],
                    ['nom' => 'Location lieu (centre équestre)',    'code_cerfa' => '613B'],
                    ['nom' => "Location lieu (salle d'armes)",      'code_cerfa' => '613C'],
                ],
            ],
            [
                'nom' => '62 - Autres services extérieurs',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Bilan pré-thérapeutique',  'code_cerfa' => '611A'],
                    ['nom' => 'Animation / Encadrement',  'code_cerfa' => '611B'],
                    ['nom' => 'Supervision',              'code_cerfa' => '611C'],
                    ['nom' => 'Sessions inter-ateliers',  'code_cerfa' => '611D'],
                    ['nom' => 'Honoraires juridiques',    'code_cerfa' => '622'],
                    ['nom' => 'Frais de déplacements',    'code_cerfa' => '625A', 'usages' => [UsageComptable::FraisKilometriques]],
                    ['nom' => 'Repas / Restauration',     'code_cerfa' => '625B'],
                    ['nom' => 'Locations de logiciels',   'code_cerfa' => '628A'],
                    ['nom' => 'Hébergement internet',     'code_cerfa' => '628B'],
                    ['nom' => 'Développement logiciel',   'code_cerfa' => '628C'],
                ],
            ],
            [
                'nom' => '66 - Charges financières',
                'type' => TypeCategorie::Depense,
                'sous' => [
                    ['nom' => 'Frais bancaires', 'code_cerfa' => '627'],
                ],
            ],
            [
                'nom' => '70 - Ventes et prestations',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Formations',              'code_cerfa' => '706A', 'usages' => [UsageComptable::Inscription]],
                    ['nom' => 'Parcours thérapeutiques', 'code_cerfa' => '706B', 'usages' => [UsageComptable::Inscription]],
                    ['nom' => 'Ventes de produits',      'code_cerfa' => '707'],
                ],
            ],
            [
                'nom' => '74 - Subventions',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Subvention État Ministère des Sports', 'code_cerfa' => '741'],
                ],
            ],
            [
                'nom' => '75 - Cotisations et dons',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Cotisations', 'code_cerfa' => '751', 'usages' => [UsageComptable::Cotisation]],
                    ['nom' => 'Dons manuels', 'code_cerfa' => '754', 'usages' => [UsageComptable::Don]],
                    ['nom' => 'Mécénat',     'code_cerfa' => '756'],
                ],
            ],
            [
                'nom' => '76 - Produits financiers',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Intérêts', 'code_cerfa' => '761'],
                ],
            ],
            [
                'nom' => '77 - Produits exceptionnels',
                'type' => TypeCategorie::Recette,
                'sous' => [
                    ['nom' => 'Abandon de créance', 'code_cerfa' => '771', 'usages' => [UsageComptable::Don, UsageComptable::AbandonCreance]],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 4 — Test pass + suite complète**

Run: `./vendor/bin/sail test tests/Feature/Services/DefaultChartOfAccountsSeedTest.php && ./vendor/bin/sail test`
Expected: PASS.

- [ ] **Step 5 — Commit**

```bash
git add app/Services/Onboarding/DefaultChartOfAccountsService.php tests/Feature/Services/DefaultChartOfAccountsSeedTest.php
git commit -m "feat(usages): seed charts of accounts avec presets usages (625A, 706A/B, 751, 754, 771)"
```

---

## Task 12 : Route + sidebar + permissions

**Files:**
- Modify: `routes/web.php` (ou fichier de routes Paramètres existant)
- Modify: vue de sidebar (probablement `resources/views/components/sidebar.blade.php` ou similaire)

- [ ] **Step 1 — Identifier les fichiers exacts**

Run:
```bash
grep -r "parametres" routes/ --include="*.php" -l
find resources/views -name "*sidebar*" -o -name "*menu*" | head
```

- [ ] **Step 2 — Ajouter la route**

Dans le groupe de routes Paramètres (avec middleware admin) :

```php
Route::get('/parametres/comptabilite/usages', \App\Livewire\Parametres\Comptabilite\UsagesComptables::class)
    ->name('parametres.comptabilite.usages');
```

- [ ] **Step 3 — Ajouter l'entrée sidebar**

Sous le groupe **Paramètres**, créer la rubrique **Comptabilité** avec l'entrée **Usages** :

```blade
<a href="{{ route('parametres.comptabilite.usages') }}" class="nav-link @if(request()->routeIs('parametres.comptabilite.usages')) active @endif">
    <i class="bi bi-sliders me-2"></i> Usages
</a>
```

- [ ] **Step 4 — Test accès**

Ajouter au test `UsagesComptablesTest` un test de routing :

```php
it('route is reachable for admin', function () {
    $this->get(route('parametres.comptabilite.usages'))->assertOk();
});

it('route denies non-admin', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $this->get(route('parametres.comptabilite.usages'))->assertForbidden();
});
```

Run: `./vendor/bin/sail test tests/Feature/Livewire/Parametres/Comptabilite/UsagesComptablesTest.php`
Expected: PASS.

- [ ] **Step 5 — Commit**

```bash
git add routes/web.php resources/views/components/ tests/Feature/Livewire/Parametres/Comptabilite/UsagesComptablesTest.php
git commit -m "feat(usages): route + sidebar Paramètres → Comptabilité → Usages"
```

---

## Task 13 : Validation finale — suite complète + pint

- [ ] **Step 1 — Lancer la suite complète**

Run: `./vendor/bin/sail test`
Expected: PASS (0 failed).

- [ ] **Step 2 — Formater**

Run: `./vendor/bin/sail composer exec pint`
Expected: format appliqué.

- [ ] **Step 3 — Commit si modifs**

```bash
git add -A
git commit -m "style: pint" || echo "Rien à commiter"
```

- [ ] **Step 4 — Smoke test manuel**

1. Démarrer Sail, aller sur http://localhost/parametres/comptabilite/usages avec admin
2. Vérifier affichage des 4 cards
3. Toggle une sous-cat Cotisation → recharger → état persisté
4. Créer une sous-cat inline Dons → apparaît cochée
5. Désigner une sous-cat Abandon → décocher son flag Don → l'abandon disparaît aussi
6. Aller sur /sous-categories → vérifier que la modale n'a plus de checkboxes, note de renvoi visible
7. Aller sur Dashboard / GestionDashboard / Transactions → vérifier aucun régression visuelle

- [ ] **Step 5 — Mise à jour memory**

Après validation utilisateur, mettre à jour `memory/project_usages_comptables.md` avec statut `LIVRÉ` + lien vers commit de fin de slice.

---

## Self-Review checklist (à exécuter par l'orchestrateur avant dispatch)

- [x] Spec coverage : chaque décision spec (modèle de données, layout, modale sous-cat épurée, création inline filtrée, abandon de créance, presets 625A/771) a au moins une tâche.
- [x] Placeholder scan : aucun TBD, chaque step a son code concret.
- [x] Type consistency : `UsageComptable` utilisé uniformément, méthodes service signatures cohérentes avec l'usage Livewire, noms de colonnes pivot identiques partout.
- [x] Ordre : refactor consumers (6a-6d) **avant** drop colonnes (7). Drop colonnes **avant** nettoyage UI SousCategorieList (10). Seed refactoré **après** drop (11).
