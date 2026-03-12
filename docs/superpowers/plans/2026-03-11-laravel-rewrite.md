# SVS Accounting Laravel Rewrite — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the SVS Accounting app from procedural PHP to Laravel 11 with Blade + Livewire 3.

**Architecture:** Traditional controllers for simple CRUD (membres, operations, parametres), Livewire components for dynamic forms and reactive lists (depenses, recettes, dons, budget, rapprochement, rapports, dashboard). Service layer for business logic. Pest PHP for testing.

**Tech Stack:** Laravel 11, PHP 8.2+, Livewire 3, Laravel Breeze, Bootstrap 5.3 (CDN), MySQL, Pest PHP, Laravel Pint

**Spec:** `docs/superpowers/specs/2026-03-11-laravel-rewrite-design.md`
**Original design:** `docs/plans/2026-03-11-svs-accounting-design.md`
**Original SQL schema:** `sql/schema.sql` (reference for column definitions)

---

## File Structure

```
app/
├── Enums/
│   ├── ModePaiement.php
│   ├── StatutMembre.php
│   ├── StatutOperation.php
│   └── TypeCategorie.php
├── Http/
│   ├── Controllers/
│   │   ├── CategorieController.php
│   │   ├── CompteBancaireController.php
│   │   ├── DashboardController.php
│   │   ├── MembreController.php
│   │   ├── OperationController.php
│   │   ├── ParametreController.php
│   │   ├── SousCategorieController.php
│   │   └── UserController.php
│   └── Requests/
│       ├── StoreCategorieRequest.php
│       ├── StoreCompteBancaireRequest.php
│       ├── StoreMembreRequest.php
│       ├── StoreOperationRequest.php
│       ├── StoreSousCategorieRequest.php
│       ├── StoreUserRequest.php
│       ├── UpdateCategorieRequest.php
│       ├── UpdateCompteBancaireRequest.php
│       ├── UpdateMembreRequest.php
│       └── UpdateOperationRequest.php
├── Livewire/
│   ├── BudgetTable.php
│   ├── CotisationForm.php
│   ├── Dashboard.php
│   ├── DepenseForm.php
│   ├── DepenseList.php
│   ├── DonForm.php
│   ├── DonList.php
│   ├── Rapprochement.php
│   ├── RapportCompteResultat.php
│   ├── RapportSeances.php
│   ├── RecetteForm.php
│   └── RecetteList.php
├── Models/
│   ├── BudgetLine.php
│   ├── Categorie.php
│   ├── CompteBancaire.php
│   ├── Cotisation.php
│   ├── Depense.php
│   ├── DepenseLigne.php
│   ├── Don.php
│   ├── Donateur.php
│   ├── Membre.php
│   ├── Operation.php
│   ├── Recette.php
│   ├── RecetteLigne.php
│   ├── SousCategorie.php
│   └── User.php  (modify Breeze default)
└── Services/
    ├── BudgetService.php
    ├── CotisationService.php
    ├── DepenseService.php
    ├── DonService.php
    ├── ExerciceService.php
    ├── RapportService.php
    ├── RapprochementService.php
    └── RecetteService.php

database/
├── factories/
│   ├── BudgetLineFactory.php
│   ├── CategorieFactory.php
│   ├── CompteBancaireFactory.php
│   ├── CotisationFactory.php
│   ├── DepenseFactory.php
│   ├── DepenseLigneFactory.php
│   ├── DonFactory.php
│   ├── DonateurFactory.php
│   ├── MembreFactory.php
│   ├── OperationFactory.php
│   ├── RecetteFactory.php
│   ├── RecetteLigneFactory.php
│   └── SousCategorieFactory.php
├── migrations/
│   ├── (Laravel defaults: users, password_reset_tokens, sessions, cache, jobs)
│   ├── xxxx_create_comptes_bancaires_table.php
│   ├── xxxx_create_categories_table.php
│   ├── xxxx_create_sous_categories_table.php
│   ├── xxxx_create_operations_table.php
│   ├── xxxx_create_depenses_table.php
│   ├── xxxx_create_depense_lignes_table.php
│   ├── xxxx_create_recettes_table.php
│   ├── xxxx_create_recette_lignes_table.php
│   ├── xxxx_create_budget_lines_table.php
│   ├── xxxx_create_membres_table.php
│   ├── xxxx_create_cotisations_table.php
│   ├── xxxx_create_donateurs_table.php
│   └── xxxx_create_dons_table.php
└── seeders/
    └── DatabaseSeeder.php

resources/views/
├── layouts/
│   └── app.blade.php
├── components/
│   └── flash-message.blade.php
├── auth/  (Breeze defaults, reskinned to Bootstrap)
│   ├── login.blade.php
│   ├── forgot-password.blade.php
│   └── reset-password.blade.php
├── membres/
│   ├── index.blade.php
│   ├── create.blade.php
│   ├── edit.blade.php
│   └── show.blade.php
├── operations/
│   ├── index.blade.php
│   ├── create.blade.php
│   ├── edit.blade.php
│   └── show.blade.php
├── parametres/
│   └── index.blade.php
├── depenses/
│   └── index.blade.php
├── recettes/
│   └── index.blade.php
├── dons/
│   └── index.blade.php
├── budget/
│   └── index.blade.php
├── rapprochement/
│   └── index.blade.php
├── rapports/
│   └── index.blade.php
├── dashboard.blade.php
└── livewire/
    ├── budget-table.blade.php
    ├── cotisation-form.blade.php
    ├── dashboard.blade.php
    ├── depense-form.blade.php
    ├── depense-list.blade.php
    ├── don-form.blade.php
    ├── don-list.blade.php
    ├── rapprochement.blade.php
    ├── rapport-compte-resultat.blade.php
    ├── rapport-seances.blade.php
    ├── recette-form.blade.php
    └── recette-list.blade.php

tests/
├── Feature/
│   ├── CategorieTest.php
│   ├── CompteBancaireTest.php
│   ├── MembreTest.php
│   ├── OperationTest.php
│   ├── SousCategorieTest.php
│   └── UserManagementTest.php
├── Livewire/
│   ├── BudgetTableTest.php
│   ├── CotisationFormTest.php
│   ├── DashboardTest.php
│   ├── DepenseFormTest.php
│   ├── DepenseListTest.php
│   ├── DonFormTest.php
│   ├── DonListTest.php
│   ├── RapprochementTest.php
│   ├── RapportCompteResultatTest.php
│   ├── RapportSeancesTest.php
│   ├── RecetteFormTest.php
│   └── RecetteListTest.php
└── Unit/
    ├── BudgetServiceTest.php
    ├── ExerciceServiceTest.php
    ├── RapportServiceTest.php
    └── RapprochementServiceTest.php

routes/
└── web.php
```

---

## Chunk 1: Project Foundation

### Task 1: Laravel Scaffold

**Files:**
- Remove: `config/`, `includes/`, `pages/`, `index.php` (old procedural code)
- Keep: `sql/schema.sql` (reference), `docs/` (specs & plans)
- Create: Fresh Laravel 11 project in project root

- [ ] **Step 1: Create fresh Laravel project in a temp directory**

```bash
cd /tmp && composer create-project laravel/laravel svs-accounting-laravel "11.*"
```

- [ ] **Step 2: Move Laravel files into project root**

Move all Laravel files into the project root, replacing old procedural code. Keep `docs/`, `sql/`, `.claude/`, `.git/`.

```bash
# From project root
rm -rf config includes pages index.php
cp -r /tmp/svs-accounting-laravel/* .
cp /tmp/svs-accounting-laravel/.* . 2>/dev/null || true
rm -rf /tmp/svs-accounting-laravel
```

- [ ] **Step 3: Configure .env**

Set in `.env`:
```
APP_NAME="SVS Comptabilité"
APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
APP_FAKER_LOCALE=fr_FR
DB_CONNECTION=mysql
DB_DATABASE=svs_accounting
DB_USERNAME=root
DB_PASSWORD=
```

- [ ] **Step 4: Update config/app.php**

Set `'timezone' => 'Europe/Paris'` in `config/app.php`.

- [ ] **Step 5: Verify Laravel boots**

```bash
php artisan --version
php artisan config:show app | grep timezone
```
Expected: Laravel 11.x, timezone = Europe/Paris

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "chore: scaffold Laravel 11 project, remove old procedural code"
```

### Task 2: Install Breeze + Livewire + French locale

**Files:**
- Modify: `composer.json`
- Create: `lang/fr/` directory with translation files

- [ ] **Step 1: Install Breeze with Blade stack**

```bash
composer require laravel/breeze --dev
php artisan breeze:install blade
```

- [ ] **Step 2: Install Livewire**

```bash
composer require livewire/livewire
```

- [ ] **Step 3: Install French translations**

```bash
composer require laravel-lang/common --dev
php artisan lang:add fr
```

- [ ] **Step 4: Remove Breeze registration routes**

In `routes/auth.php`, delete the two routes for `RegisteredUserController` (GET and POST `/register`). Delete `app/Http/Controllers/Auth/RegisteredUserController.php` and `resources/views/auth/register.blade.php`.

- [ ] **Step 5: Verify auth routes**

```bash
php artisan route:list --path=login
php artisan route:list --path=register
```
Expected: login routes exist, register routes do NOT exist.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat: install Breeze (blade), Livewire 3, French locale, remove registration"
```

### Task 3: Bootstrap 5 Layout & Breeze Reskin

**Files:**
- Create: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/auth/login.blade.php`
- Modify: `resources/views/auth/forgot-password.blade.php`
- Modify: `resources/views/auth/reset-password.blade.php`
- Create: `resources/views/components/flash-message.blade.php`
- Remove: Tailwind config files (`tailwind.config.js`, `postcss.config.js`, `vite.config.js`, `resources/css/app.css`, `resources/js/app.js`)

- [ ] **Step 1: Remove Tailwind/Vite frontend build system**

Delete `tailwind.config.js`, `postcss.config.js`, `vite.config.js`, `resources/css/app.css`, `resources/js/app.js`, `package.json`, `package-lock.json`. We use Bootstrap via CDN — no build step.

- [ ] **Step 2: Create Bootstrap layout**

Create `resources/views/layouts/app.blade.php`:

```blade
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'SVS Comptabilité' }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    @livewireStyles
</head>
<body>
    @auth
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="{{ route('dashboard') }}">SVS Comptabilité</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="{{ route('dashboard') }}">Tableau de bord</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('depenses.index') }}">Dépenses</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('recettes.index') }}">Recettes</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('budget.index') }}">Budget</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('membres.index') }}">Membres</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('dons.index') }}">Dons</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('operations.index') }}">Opérations</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('rapprochement.index') }}">Rapprochement</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('rapports.index') }}">Rapports</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('parametres.index') }}">Paramètres</a></li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <span class="nav-link text-light">{{ auth()->user()->nom }}</span>
                    </li>
                    <li class="nav-item">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="nav-link btn btn-link text-light">Déconnexion</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    @endauth

    <div class="container-fluid">
        <x-flash-message />
        {{ $slot }}
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @livewireScripts
</body>
</html>
```

- [ ] **Step 3: Create flash message component**

Create `resources/views/components/flash-message.blade.php`:

```blade
@if (session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if (session('error'))
    <div class="alert alert-danger alert-dismissible fade show">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
```

- [ ] **Step 4: Reskin Breeze login view to Bootstrap**

Replace `resources/views/auth/login.blade.php` with a Bootstrap 5 card-based login form (email, password, remember me, forgot password link). Use `<x-layouts.app>` wrapper without navbar (guest layout). Use standard Bootstrap form classes (`form-control`, `form-label`, `btn btn-primary`).

- [ ] **Step 5: Reskin forgot-password and reset-password views**

Same approach — Bootstrap cards with form controls. Follow Breeze's existing form field names and POST targets.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat: Bootstrap 5 layout, reskin Breeze auth views, flash messages"
```

### Task 4: ExerciceService

**Files:**
- Create: `app/Services/ExerciceService.php`
- Create: `tests/Unit/ExerciceServiceTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/ExerciceServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\ExerciceService;
use Carbon\Carbon;

it('returns current year when month is september or later', function (): void {
    Carbon::setTestNow(Carbon::create(2025, 9, 15));
    $service = new ExerciceService();
    expect($service->current())->toBe(2025);
});

it('returns previous year when month is before september', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 3, 11));
    $service = new ExerciceService();
    expect($service->current())->toBe(2025);
});

it('returns correct date range for exercice', function (): void {
    $service = new ExerciceService();
    $range = $service->dateRange(2025);
    expect($range['start']->toDateString())->toBe('2025-09-01')
        ->and($range['end']->toDateString())->toBe('2026-08-31');
});

it('returns august as last month of previous exercice', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 8, 31));
    $service = new ExerciceService();
    expect($service->current())->toBe(2025);
});

it('lists available exercices', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 3, 11));
    $service = new ExerciceService();
    $list = $service->available();
    expect($list)->toBeArray()
        ->and($list)->toContain(2025)
        ->and($list[0])->toBeGreaterThanOrEqual($list[1]);
});

it('formats exercice label', function (): void {
    $service = new ExerciceService();
    expect($service->label(2025))->toBe('2025-2026');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/ExerciceServiceTest.php
```
Expected: FAIL (class not found)

- [ ] **Step 3: Implement ExerciceService**

Create `app/Services/ExerciceService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

final class ExerciceService
{
    public function current(): int
    {
        $now = Carbon::now();
        return $now->month >= 9 ? $now->year : $now->year - 1;
    }

    /** @return array{start: CarbonImmutable, end: CarbonImmutable} */
    public function dateRange(int $exercice): array
    {
        return [
            'start' => CarbonImmutable::create($exercice, 9, 1),
            'end' => CarbonImmutable::create($exercice + 1, 8, 31),
        ];
    }

    /** @return list<int> */
    public function available(int $count = 5): array
    {
        $current = $this->current();
        return array_map(fn (int $i) => $current - $i, range(0, $count - 1));
    }

    public function label(int $exercice): string
    {
        return $exercice . '-' . ($exercice + 1);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test tests/Unit/ExerciceServiceTest.php
```
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: ExerciceService with current(), dateRange(), available(), label()"
```

---

## Chunk 2: Database Layer

### Task 5: PHP Enums

**Files:**
- Create: `app/Enums/TypeCategorie.php`
- Create: `app/Enums/ModePaiement.php`
- Create: `app/Enums/StatutOperation.php`
- Create: `app/Enums/StatutMembre.php`

- [ ] **Step 1: Create all four enums**

`app/Enums/TypeCategorie.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeCategorie: string
{
    case Depense = 'depense';
    case Recette = 'recette';

    public function label(): string
    {
        return match ($this) {
            self::Depense => 'Dépense',
            self::Recette => 'Recette',
        };
    }
}
```

`app/Enums/ModePaiement.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum ModePaiement: string
{
    case Virement = 'virement';
    case Cheque = 'cheque';
    case Especes = 'especes';
    case Cb = 'cb';
    case Prelevement = 'prelevement';

    public function label(): string
    {
        return match ($this) {
            self::Virement => 'Virement',
            self::Cheque => 'Chèque',
            self::Especes => 'Espèces',
            self::Cb => 'Carte bancaire',
            self::Prelevement => 'Prélèvement',
        };
    }
}
```

`app/Enums/StatutOperation.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutOperation: string
{
    case EnCours = 'en_cours';
    case Cloturee = 'cloturee';

    public function label(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::Cloturee => 'Clôturée',
        };
    }
}
```

`app/Enums/StatutMembre.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutMembre: string
{
    case Actif = 'actif';
    case Inactif = 'inactif';

    public function label(): string
    {
        return match ($this) {
            self::Actif => 'Actif',
            self::Inactif => 'Inactif',
        };
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add -A && git commit -m "feat: PHP backed enums (TypeCategorie, ModePaiement, StatutOperation, StatutMembre)"
```

### Task 6: Migrations

**Files:**
- Modify: `database/migrations/xxxx_create_users_table.php` (add `nom` column)
- Create: 13 new migration files (one per application table)

Reference `sql/schema.sql` for exact column names, types, and FK constraints.

- [ ] **Step 1: Modify users migration**

Add `nom VARCHAR(100)` after `id` in the existing users migration. Breeze already provides `name` — rename it to `nom` for French consistency. Verify `password` column exists (Breeze default, replaces `password_hash`).

- [ ] **Step 2: Create all 13 migrations in FK-dependency order**

```bash
php artisan make:migration create_comptes_bancaires_table
php artisan make:migration create_categories_table
php artisan make:migration create_sous_categories_table
php artisan make:migration create_operations_table
php artisan make:migration create_depenses_table
php artisan make:migration create_depense_lignes_table
php artisan make:migration create_recettes_table
php artisan make:migration create_recette_lignes_table
php artisan make:migration create_budget_lines_table
php artisan make:migration create_membres_table
php artisan make:migration create_cotisations_table
php artisan make:migration create_donateurs_table
php artisan make:migration create_dons_table
```

Implement each migration matching the column definitions from `sql/schema.sql`. Key details:

**comptes_bancaires:**
- `nom` VARCHAR(150), `iban` VARCHAR(34) nullable, `solde_initial` DECIMAL(10,2) default 0, `date_solde_initial` DATE, timestamps

**categories:**
- `nom` VARCHAR(100), `type` string (cast to TypeCategorie enum), timestamps

**sous_categories:**
- `categorie_id` FK constrained cascadeOnDelete, `nom` VARCHAR(100), `code_cerfa` VARCHAR(10) nullable, timestamps

**operations:**
- `nom` VARCHAR(150), `description` text nullable, `date_debut`/`date_fin` DATE nullable, `nombre_seances` INT nullable, `statut` string default 'en_cours', timestamps

**depenses:**
- `date` DATE, `libelle` VARCHAR(255), `montant_total` DECIMAL(10,2), `mode_paiement` string, `beneficiaire` VARCHAR(150) nullable, `reference` VARCHAR(100) nullable, `compte_id` FK nullable (nullOnDelete), `pointe` boolean default false, `notes` text nullable, `saisi_par` FK constrained to users, timestamps, softDeletes

**depense_lignes:**
- `depense_id` FK constrained cascadeOnDelete, `sous_categorie_id` FK constrained, `operation_id` FK nullable (nullOnDelete), `seance` INT nullable, `montant` DECIMAL(10,2), `notes` text nullable, softDeletes

**recettes:** Mirror of depenses with `payeur` instead of `beneficiaire`.

**recette_lignes:** Mirror of depense_lignes with `recette_id` FK.

**budget_lines:**
- `sous_categorie_id` FK constrained, `exercice` INT, `montant_prevu` DECIMAL(10,2), `notes` text nullable, timestamps

**membres:**
- `nom` VARCHAR(100), `prenom` VARCHAR(100), `email` VARCHAR(150) nullable, `telephone` VARCHAR(20) nullable, `adresse` text nullable, `date_adhesion` DATE nullable, `statut` string default 'actif', `notes` text nullable, timestamps

**cotisations:**
- `membre_id` FK constrained cascadeOnDelete, `exercice` INT, `montant` DECIMAL(10,2), `date_paiement` DATE, `mode_paiement` string, `compte_id` FK nullable (nullOnDelete), `pointe` boolean default false, timestamps, softDeletes

**donateurs:**
- `nom` VARCHAR(100), `prenom` VARCHAR(100), `email` VARCHAR(150) nullable, `adresse` text nullable, timestamps

**dons:**
- `donateur_id` FK nullable (nullOnDelete), `date` DATE, `montant` DECIMAL(10,2), `mode_paiement` string, `objet` VARCHAR(255) nullable, `operation_id` FK nullable (nullOnDelete), `seance` INT nullable, `compte_id` FK nullable (nullOnDelete), `pointe` boolean default false, `recu_emis` boolean default false, `saisi_par` FK constrained to users, timestamps, softDeletes

- [ ] **Step 3: Run migrations**

```bash
php artisan migrate:fresh
php artisan migrate:status
```
Expected: All migrations show `Ran`.

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat: all 13 application migrations matching original schema"
```

### Task 7: Eloquent Models

**Files:**
- Modify: `app/Models/User.php`
- Create: 13 model files in `app/Models/`

- [ ] **Step 1: Modify User model**

Update `app/Models/User.php`: rename `name` to `nom` in `$fillable`. Add relationships:
```php
public function depenses(): HasMany { return $this->hasMany(Depense::class, 'saisi_par'); }
public function recettes(): HasMany { return $this->hasMany(Recette::class, 'saisi_par'); }
public function dons(): HasMany { return $this->hasMany(Don::class, 'saisi_par'); }
```

- [ ] **Step 2: Create all 13 models**

Each model follows this pattern:
- `declare(strict_types=1)`, `final class`, `HasFactory` trait
- `$fillable` with all non-auto columns
- `$casts` for enums and dates
- `SoftDeletes` trait on financial models
- All relationships defined with return types

Key model details:

**CompteBancaire:** `$casts = ['solde_initial' => 'decimal:2', 'date_solde_initial' => 'date']`. Has `hasMany` for depenses, recettes, dons, cotisations.

**Categorie:** `$casts = ['type' => TypeCategorie::class]`. Has `hasMany SousCategorie`.

**SousCategorie:** `belongsTo Categorie`, `hasMany BudgetLine`, `hasMany DepenseLigne`, `hasMany RecetteLigne`. Scope `scopeDepenses` and `scopeRecettes` filtering by parent categorie type.

**Operation:** `$casts = ['statut' => StatutOperation::class, 'date_debut' => 'date', 'date_fin' => 'date']`. Has `hasMany DepenseLigne`, `hasMany RecetteLigne`, `hasMany Don`.

**Depense:** SoftDeletes. `$casts = ['date' => 'date', 'montant_total' => 'decimal:2', 'mode_paiement' => ModePaiement::class, 'pointe' => 'boolean']`. `belongsTo User (saisi_par)`, `belongsTo CompteBancaire (compte_id)`, `hasMany DepenseLigne`. Scope `scopeForExercice(Builder $query, int $exercice)`.

**DepenseLigne:** SoftDeletes. `$casts = ['montant' => 'decimal:2']`. `belongsTo Depense`, `belongsTo SousCategorie`, `belongsTo Operation` (nullable).

**Recette / RecetteLigne:** Mirror of Depense / DepenseLigne with `payeur` instead of `beneficiaire`, `recette_id` instead of `depense_id`.

**BudgetLine:** `$casts = ['montant_prevu' => 'decimal:2']`. `belongsTo SousCategorie`. Scope `scopeForExercice(Builder $query, int $exercice)` using `WHERE exercice = ?`.

**Membre:** `$casts = ['statut' => StatutMembre::class, 'date_adhesion' => 'date']`. `hasMany Cotisation`.

**Cotisation:** SoftDeletes. `$casts = ['montant' => 'decimal:2', 'date_paiement' => 'date', 'mode_paiement' => ModePaiement::class, 'pointe' => 'boolean']`. `belongsTo Membre`, `belongsTo CompteBancaire`. Scope `scopeForExercice`.

**Donateur:** `hasMany Don`.

**Don:** SoftDeletes. `$casts = ['date' => 'date', 'montant' => 'decimal:2', 'mode_paiement' => ModePaiement::class, 'pointe' => 'boolean', 'recu_emis' => 'boolean']`. `belongsTo Donateur` (nullable), `belongsTo User (saisi_par)`, `belongsTo CompteBancaire`, `belongsTo Operation` (nullable). Scope `scopeForExercice`.

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "feat: all 14 Eloquent models with relationships, casts, and scopes"
```

### Task 8: Factories & Seeder

**Files:**
- Modify: `database/factories/UserFactory.php`
- Create: 12 new factory files
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Update UserFactory**

Change `name` to `nom` in the definition.

- [ ] **Step 2: Create all 12 factories**

Each factory uses `fake('fr_FR')` for French-locale fake data.

Key factories:

**CompteBancaireFactory:** `nom` = `fake()->company()`, `iban` = `fake()->iban('FR')`, `solde_initial` = `fake()->randomFloat(2, 0, 50000)`, `date_solde_initial` = `fake()->date()`.

**CategorieFactory:** `nom` = `fake()->word()`, `type` = `fake()->randomElement(TypeCategorie::cases())`.

**SousCategorieFactory:** `categorie_id` = `Categorie::factory()`, `nom` = `fake()->word()`, `code_cerfa` = `fake()->optional()->numerify('###')`.

**OperationFactory:** `nom` = `fake()->sentence(3)`, `statut` = StatutOperation::EnCours. State `withSeances(int $n)` sets `nombre_seances` = `$n`.

**DepenseFactory:** All required fields with sensible defaults. `saisi_par` = `User::factory()`, `compte_id` = `CompteBancaire::factory()`. `afterCreating` hook creates 1-3 `DepenseLigne` records whose `montant` sums to `montant_total`.

**DepenseLigneFactory:** `sous_categorie_id` = `SousCategorie::factory()`, `montant` = `fake()->randomFloat(2, 10, 500)`.

**RecetteFactory / RecetteLigneFactory:** Mirror depense factories.

**MembreFactory:** French names and addresses. State `withCotisation(int $exercice)` creates a cotisation record.

**CotisationFactory:** `membre_id` = `Membre::factory()`, `exercice` = current exercice, `montant` = random, `date_paiement` = `fake()->date()`, `mode_paiement` = random.

**DonateurFactory:** French names.

**DonFactory:** `saisi_par` = `User::factory()`. Optional `donateur_id`, `operation_id`.

**BudgetLineFactory:** `sous_categorie_id` = `SousCategorie::factory()`, `exercice` = current exercice, `montant_prevu` = random.

- [ ] **Step 3: Create DatabaseSeeder**

Seed basic development data: 2 users, 3 comptes bancaires, categories with sous-categories for both depense and recette types, 2 operations, a few membres, donateurs.

- [ ] **Step 4: Run seeder and verify**

```bash
php artisan migrate:fresh --seed
```
Expected: No errors. Tables populated.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: all 13 factories and development seeder"
```

---

## Chunk 3: Parametres & Routes

### Task 9: Route Definitions & Parametres

**Files:**
- Modify: `routes/web.php`
- Create: `app/Http/Controllers/DashboardController.php`
- Create: `app/Http/Controllers/ParametreController.php`
- Create: `app/Http/Controllers/CategorieController.php`
- Create: `app/Http/Controllers/SousCategorieController.php`
- Create: `app/Http/Controllers/CompteBancaireController.php`
- Create: `app/Http/Controllers/UserController.php`
- Create: `app/Http/Requests/StoreCategorieRequest.php`
- Create: `app/Http/Requests/UpdateCategorieRequest.php`
- Create: `app/Http/Requests/StoreSousCategorieRequest.php`
- Create: `app/Http/Requests/UpdateSousCategorieRequest.php`
- Create: `app/Http/Requests/StoreCompteBancaireRequest.php`
- Create: `app/Http/Requests/UpdateCompteBancaireRequest.php`
- Create: `app/Http/Requests/StoreUserRequest.php`
- Create: `resources/views/parametres/index.blade.php`
- Create: `resources/views/dashboard.blade.php` (placeholder)
- Create: `tests/Feature/CategorieTest.php`
- Create: `tests/Feature/CompteBancaireTest.php`
- Create: `tests/Feature/SousCategorieTest.php`
- Create: `tests/Feature/UserManagementTest.php`

- [ ] **Step 1: Define all routes in web.php**

```php
<?php

use App\Http\Controllers\CategorieController;
use App\Http\Controllers\CompteBancaireController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MembreController;
use App\Http\Controllers\OperationController;
use App\Http\Controllers\ParametreController;
use App\Http\Controllers\SousCategorieController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Livewire full-page components (just need a route + minimal controller/view)
    Route::view('/depenses', 'depenses.index')->name('depenses.index');
    Route::view('/recettes', 'recettes.index')->name('recettes.index');
    Route::view('/dons', 'dons.index')->name('dons.index');
    Route::view('/budget', 'budget.index')->name('budget.index');
    Route::view('/rapprochement', 'rapprochement.index')->name('rapprochement.index');
    Route::view('/rapports', 'rapports.index')->name('rapports.index');

    // Resource controllers
    Route::resource('membres', MembreController::class);
    Route::resource('operations', OperationController::class)->except(['destroy']);

    // Parametres
    Route::get('/parametres', [ParametreController::class, 'index'])->name('parametres.index');
    Route::prefix('parametres')->name('parametres.')->group(function () {
        Route::resource('categories', CategorieController::class)->except(['show']);
        Route::resource('sous-categories', SousCategorieController::class)->except(['show']);
        Route::resource('comptes-bancaires', CompteBancaireController::class)->except(['show']);
        Route::resource('utilisateurs', UserController::class)->only(['store', 'destroy']);
    });
});

require __DIR__.'/auth.php';
```

- [ ] **Step 2: Create DashboardController with placeholder**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function index(): View
    {
        return view('dashboard');
    }
}
```

Create placeholder `resources/views/dashboard.blade.php`:
```blade
<x-layouts.app title="Tableau de bord">
    <h1>Tableau de bord</h1>
    <p>À venir</p>
</x-layouts.app>
```

- [ ] **Step 3: Create placeholder views for Livewire pages**

Create minimal `index.blade.php` for each Livewire module (depenses, recettes, dons, budget, rapprochement, rapports) — just the layout wrapper with a placeholder heading. These will be populated when we build the Livewire components.

- [ ] **Step 4: Write CategorieController tests**

Create `tests/Feature/CategorieTest.php`:

```php
<?php

use App\Models\Categorie;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('requires authentication', function () {
    $this->get(route('parametres.categories.index'))->assertRedirect(route('login'));
});

it('can list categories', function () {
    Categorie::factory()->count(3)->create();
    $this->actingAs($this->user)
        ->get(route('parametres.categories.index'))
        ->assertOk();
});

it('can store a category', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.categories.store'), [
            'nom' => 'Transport',
            'type' => 'depense',
        ])
        ->assertRedirect(route('parametres.index'));

    $this->assertDatabaseHas('categories', ['nom' => 'Transport', 'type' => 'depense']);
});

it('validates required fields on store', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.categories.store'), [])
        ->assertSessionHasErrors(['nom', 'type']);
});

it('can update a category', function () {
    $cat = Categorie::factory()->create();
    $this->actingAs($this->user)
        ->put(route('parametres.categories.update', $cat), ['nom' => 'Nouveau', 'type' => 'recette'])
        ->assertRedirect(route('parametres.index'));

    expect($cat->fresh()->nom)->toBe('Nouveau');
});

it('can delete a category', function () {
    $cat = Categorie::factory()->create();
    $this->actingAs($this->user)
        ->delete(route('parametres.categories.destroy', $cat))
        ->assertRedirect(route('parametres.index'));

    $this->assertDatabaseMissing('categories', ['id' => $cat->id]);
});
```

- [ ] **Step 5: Run categorie tests to verify they fail**

```bash
php artisan test tests/Feature/CategorieTest.php
```
Expected: FAIL (controllers not yet implemented)

- [ ] **Step 6: Implement CategorieController + FormRequests**

`app/Http/Controllers/CategorieController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategorieRequest;
use App\Http\Requests\UpdateCategorieRequest;
use App\Models\Categorie;
use Illuminate\Http\RedirectResponse;

final class CategorieController extends Controller
{
    public function store(StoreCategorieRequest $request): RedirectResponse
    {
        Categorie::create($request->validated());
        return redirect()->route('parametres.index')->with('success', 'Catégorie créée.');
    }

    public function update(UpdateCategorieRequest $request, Categorie $category): RedirectResponse
    {
        $category->update($request->validated());
        return redirect()->route('parametres.index')->with('success', 'Catégorie modifiée.');
    }

    public function destroy(Categorie $category): RedirectResponse
    {
        $category->delete();
        return redirect()->route('parametres.index')->with('success', 'Catégorie supprimée.');
    }
}
```

`StoreCategorieRequest` and `UpdateCategorieRequest`: validate `nom` (required, string, max:100) and `type` (required, in:depense,recette).

- [ ] **Step 7: Write and implement SousCategorieController, CompteBancaireController, UserController**

Follow the same pattern as CategorieController:
- **SousCategorieController:** CRUD with `categorie_id`, `nom`, `code_cerfa` validation. Tests in `tests/Feature/SousCategorieTest.php`.
- **CompteBancaireController:** CRUD with `nom`, `iban`, `solde_initial`, `date_solde_initial` validation. Tests in `tests/Feature/CompteBancaireTest.php`.
- **UserController:** Store (create new user with `nom`, `email`, `password`) and destroy only. Tests in `tests/Feature/UserManagementTest.php`.

- [ ] **Step 8: Create ParametreController and tabbed view**

`ParametreController::index()` loads all categories (with sousCategories), comptes bancaires, and users, passes them to the parametres/index view.

`resources/views/parametres/index.blade.php`: Bootstrap tab navigation (`nav-tabs`) with 4 tabs:
1. **Catégories** — table of categories with inline add/edit/delete forms
2. **Sous-catégories** — table with categorie filter, CERFA code column
3. **Comptes bancaires** — table with add/edit/delete
4. **Utilisateurs** — table with add/delete (no edit — no roles)

Each tab uses Bootstrap forms posting to the respective controller routes.

- [ ] **Step 9: Run all parametres tests**

```bash
php artisan test tests/Feature/
```
Expected: All PASS

- [ ] **Step 10: Verify routes**

```bash
php artisan route:list --path=parametres
```
Expected: All parametres sub-routes listed.

- [ ] **Step 11: Commit**

```bash
git add -A && git commit -m "feat: route definitions, parametres module (categories, sous-categories, comptes bancaires, users)"
```

---

## Chunk 4: Membres & Operations

### Task 10: MembreController & CotisationForm

**Files:**
- Create: `app/Http/Controllers/MembreController.php`
- Create: `app/Http/Requests/StoreMembreRequest.php`
- Create: `app/Http/Requests/UpdateMembreRequest.php`
- Create: `app/Services/CotisationService.php`
- Create: `app/Livewire/CotisationForm.php`
- Create: `resources/views/membres/index.blade.php`
- Create: `resources/views/membres/create.blade.php`
- Create: `resources/views/membres/edit.blade.php`
- Create: `resources/views/membres/show.blade.php`
- Create: `resources/views/livewire/cotisation-form.blade.php`
- Create: `tests/Feature/MembreTest.php`
- Create: `tests/Livewire/CotisationFormTest.php`

- [ ] **Step 1: Write MembreController feature tests**

`tests/Feature/MembreTest.php`: Test index, create, store (with validation), show, edit, update, destroy. Assert auth required. Assert database state after store/update/destroy.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Feature/MembreTest.php
```

- [ ] **Step 3: Implement MembreController**

Standard resource controller. `index()` lists membres with their cotisation status for the current exercice (eager-load `cotisations`). `show()` loads a single membre with all cotisations. Views use Bootstrap tables and forms.

`StoreMembreRequest` validates: `nom` (required), `prenom` (required), `email` (nullable, email), `telephone` (nullable), `adresse` (nullable), `date_adhesion` (nullable, date), `statut` (required, in:actif,inactif).

- [ ] **Step 4: Create membre Blade views**

- `index.blade.php`: Table with nom, prénom, statut, cotisation status (checkmark/cross for current exercice). Links to show/edit/create.
- `create.blade.php` / `edit.blade.php`: Bootstrap form with all membre fields.
- `show.blade.php`: Membre details + `<livewire:cotisation-form :membre="$membre" />` component for managing cotisations.

- [ ] **Step 5: Run membre tests**

```bash
php artisan test tests/Feature/MembreTest.php
```
Expected: All PASS

- [ ] **Step 6: Write CotisationForm Livewire tests**

`tests/Livewire/CotisationFormTest.php`:
```php
<?php

use App\Livewire\CotisationForm;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Membre;
use App\Models\User;
use Livewire\Livewire;

it('renders cotisation form for a membre', function () {
    $membre = Membre::factory()->create();
    Livewire::actingAs(User::factory()->create())
        ->test(CotisationForm::class, ['membre' => $membre])
        ->assertOk();
});

it('can add a cotisation', function () {
    $membre = Membre::factory()->create();
    $compte = CompteBancaire::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(CotisationForm::class, ['membre' => $membre])
        ->set('exercice', 2025)
        ->set('montant', 30.00)
        ->set('date_paiement', '2025-10-01')
        ->set('mode_paiement', 'especes')
        ->set('compte_id', $compte->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Cotisation::where('membre_id', $membre->id)->count())->toBe(1);
});

it('can delete a cotisation', function () {
    $membre = Membre::factory()->create();
    $cotisation = Cotisation::factory()->for($membre)->create();

    Livewire::actingAs(User::factory()->create())
        ->test(CotisationForm::class, ['membre' => $membre])
        ->call('delete', $cotisation->id)
        ->assertHasNoErrors();

    expect(Cotisation::withTrashed()->find($cotisation->id)->trashed())->toBeTrue();
});

it('validates required fields', function () {
    $membre = Membre::factory()->create();
    Livewire::actingAs(User::factory()->create())
        ->test(CotisationForm::class, ['membre' => $membre])
        ->call('save')
        ->assertHasErrors(['exercice', 'montant', 'date_paiement', 'mode_paiement']);
});
```

- [ ] **Step 7: Run Livewire tests to verify they fail**

```bash
php artisan test tests/Livewire/CotisationFormTest.php
```

- [ ] **Step 8: Implement CotisationService**

`app/Services/CotisationService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cotisation;
use App\Models\Membre;

final class CotisationService
{
    public function create(Membre $membre, array $data): Cotisation
    {
        return $membre->cotisations()->create($data);
    }

    public function delete(Cotisation $cotisation): void
    {
        $cotisation->delete(); // soft delete
    }
}
```

- [ ] **Step 9: Implement CotisationForm Livewire component**

`app/Livewire/CotisationForm.php`: Public properties for form fields (`exercice`, `montant`, `date_paiement`, `mode_paiement`, `compte_id`). `mount(Membre $membre)`. `save()` validates and calls CotisationService. `delete(int $id)` soft-deletes. View shows existing cotisations table + add form.

`resources/views/livewire/cotisation-form.blade.php`: Table of existing cotisations (exercice, montant, date, mode, pointé) with delete button. Below: form to add new cotisation.

- [ ] **Step 10: Run all Livewire tests**

```bash
php artisan test tests/Livewire/CotisationFormTest.php
```
Expected: All PASS

- [ ] **Step 11: Commit**

```bash
git add -A && git commit -m "feat: membres module with CRUD + cotisation Livewire form"
```

### Task 11: OperationController

**Files:**
- Create: `app/Http/Controllers/OperationController.php`
- Create: `app/Http/Requests/StoreOperationRequest.php`
- Create: `app/Http/Requests/UpdateOperationRequest.php`
- Create: `resources/views/operations/index.blade.php`
- Create: `resources/views/operations/create.blade.php`
- Create: `resources/views/operations/edit.blade.php`
- Create: `resources/views/operations/show.blade.php`
- Create: `tests/Feature/OperationTest.php`

- [ ] **Step 1: Write feature tests**

`tests/Feature/OperationTest.php`: Test index, create, store, show, edit, update. Show page must display linked depense_lignes, recette_lignes, and dons with totals and solde (total recettes + dons - total depenses).

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Feature/OperationTest.php
```

- [ ] **Step 3: Implement OperationController**

`index()`: List operations with statut badge. `show()`: Load operation with sum of linked depense_lignes, recette_lignes, and dons. Calculate solde. `store()`/`update()`: Standard CRUD.

`StoreOperationRequest`: `nom` required, `description` nullable, `date_debut`/`date_fin` nullable dates, `nombre_seances` nullable integer min:1, `statut` required in:en_cours,cloturee.

- [ ] **Step 4: Create Blade views**

- `index.blade.php`: Table with nom, dates, nombre_seances, statut badge. Links to show/edit/create.
- `show.blade.php`: Operation details + summary table of linked transactions (total depenses, total recettes, total dons, solde).
- `create.blade.php` / `edit.blade.php`: Form with all operation fields.

- [ ] **Step 5: Run tests**

```bash
php artisan test tests/Feature/OperationTest.php
```
Expected: All PASS

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat: operations module with CRUD and transaction summary"
```

---

## Chunk 5: Depenses & Recettes

### Task 12: DepenseService + DepenseForm + DepenseList

**Files:**
- Create: `app/Services/DepenseService.php`
- Create: `app/Livewire/DepenseForm.php`
- Create: `app/Livewire/DepenseList.php`
- Create: `resources/views/livewire/depense-form.blade.php`
- Create: `resources/views/livewire/depense-list.blade.php`
- Modify: `resources/views/depenses/index.blade.php`
- Create: `tests/Unit/DepenseServiceTest.php` (if needed beyond Livewire tests)
- Create: `tests/Livewire/DepenseFormTest.php`
- Create: `tests/Livewire/DepenseListTest.php`

- [ ] **Step 1: Write DepenseForm Livewire tests**

`tests/Livewire/DepenseFormTest.php`:
- Test rendering the form
- Test adding a ventilation line (adds row to `$lignes` array)
- Test removing a ventilation line
- Test validation: required fields, lignes.*.montant sum must equal montant_total
- Test successful save creates depense + lignes in DB
- Test seance selector appears when operation with nombre_seances is selected on a line
- Test editing an existing depense loads data correctly
- Test update modifies depense and replaces lignes

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Livewire/DepenseFormTest.php
```

- [ ] **Step 3: Implement DepenseService**

`app/Services/DepenseService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Depense;
use Illuminate\Support\Facades\DB;

final class DepenseService
{
    public function create(array $data, array $lignes): Depense
    {
        return DB::transaction(function () use ($data, $lignes) {
            $data['saisi_par'] = auth()->id();
            $depense = Depense::create($data);
            foreach ($lignes as $ligne) {
                $depense->lignes()->create($ligne);
            }
            return $depense;
        });
    }

    public function update(Depense $depense, array $data, array $lignes): Depense
    {
        return DB::transaction(function () use ($depense, $data, $lignes) {
            $depense->update($data);
            $depense->lignes()->delete(); // soft delete old
            foreach ($lignes as $ligne) {
                $depense->lignes()->create($ligne);
            }
            return $depense->fresh();
        });
    }

    public function delete(Depense $depense): void
    {
        DB::transaction(function () use ($depense) {
            $depense->lignes()->delete();
            $depense->delete();
        });
    }
}
```

- [ ] **Step 4: Implement DepenseForm Livewire component**

`app/Livewire/DepenseForm.php`:

Public properties:
- `?Depense $depense = null` (for edit mode)
- `string $date`, `string $libelle`, `string $montant_total`, `string $mode_paiement`
- `?string $beneficiaire`, `?string $reference`, `?int $compte_id`, `?string $notes`
- `array $lignes = []` — each item: `['sous_categorie_id' => '', 'operation_id' => '', 'seance' => '', 'montant' => '', 'notes' => '']`
- `bool $showForm = false`

Methods:
- `mount(?Depense $depense = null)` — if editing, populate properties from depense + lignes
- `addLigne()` — push new empty row to `$lignes`
- `removeLigne(int $index)` — splice from `$lignes`
- `save()` — validate, call DepenseService create or update, emit event to refresh list, reset form
- `edit(Depense $depense)` — populate form for editing

Validation rules:
- `date` required date, `libelle` required string, `montant_total` required numeric min:0.01
- `mode_paiement` required in enum values, `compte_id` nullable exists:comptes_bancaires,id
- `lignes` required array min:1
- `lignes.*.sous_categorie_id` required exists:sous_categories,id
- `lignes.*.montant` required numeric min:0.01
- Custom rule: sum of `lignes.*.montant` must equal `montant_total`

- [ ] **Step 5: Implement DepenseList Livewire component**

`app/Livewire/DepenseList.php`:

Public properties for filters:
- `?int $exercice` (defaults to current), `?int $categorie_id`, `?int $sous_categorie_id`
- `?int $operation_id`, `?int $compte_id`, `?string $pointe` (null/'oui'/'non')

Computed property `depenses()` builds query with eager-loaded `lignes.sousCategorie.categorie`, `compte`, `saisiPar`. Applies filters. Paginated.

Listens for `depense-saved` event to refresh.

`delete(int $id)` calls DepenseService::delete.

- [ ] **Step 6: Create Blade views for Livewire components**

`resources/views/livewire/depense-list.blade.php`:
- Filter bar: exercice selector, categorie, operation, compte, pointe dropdowns
- Table: date, libellé, montant_total, mode_paiement, bénéficiaire, pointé, actions (edit/delete)
- Pagination links

`resources/views/livewire/depense-form.blade.php`:
- Modal or inline form toggled by `$showForm`
- Header fields: date, libellé, montant_total, mode_paiement, bénéficiaire, référence, compte, notes
- Dynamic lignes section: table with sous-catégorie select, opération select, séance select (shown only when operation has nombre_seances), montant, notes. Add/remove buttons.
- Running total of lignes vs montant_total with visual indicator

`resources/views/depenses/index.blade.php`:
```blade
<x-layouts.app title="Dépenses">
    <h1>Dépenses</h1>
    <livewire:depense-list />
    <livewire:depense-form />
</x-layouts.app>
```

- [ ] **Step 7: Run all depense tests**

```bash
php artisan test tests/Livewire/DepenseFormTest.php tests/Livewire/DepenseListTest.php
```
Expected: All PASS

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "feat: depenses module (DepenseService, DepenseForm, DepenseList Livewire)"
```

### Task 13: RecetteService + RecetteForm + RecetteList

**Files:**
- Create: `app/Services/RecetteService.php`
- Create: `app/Livewire/RecetteForm.php`
- Create: `app/Livewire/RecetteList.php`
- Create: `resources/views/livewire/recette-form.blade.php`
- Create: `resources/views/livewire/recette-list.blade.php`
- Modify: `resources/views/recettes/index.blade.php`
- Create: `tests/Livewire/RecetteFormTest.php`
- Create: `tests/Livewire/RecetteListTest.php`

- [ ] **Step 1: Mirror depenses pattern for recettes**

RecetteService, RecetteForm, RecetteList are structurally identical to their depense counterparts with these differences:
- Model: `Recette` / `RecetteLigne` instead of `Depense` / `DepenseLigne`
- Field: `payeur` instead of `beneficiaire`
- Sous-categories filtered to `type = 'recette'` (depenses use `type = 'depense'`)
- Route/event names adjusted

Follow the exact same test patterns from Task 12, adapted for recettes.

- [ ] **Step 2: Write tests**

`tests/Livewire/RecetteFormTest.php` and `tests/Livewire/RecetteListTest.php` — mirror depense tests.

- [ ] **Step 3: Run tests to verify they fail, then implement, then verify they pass**

```bash
php artisan test tests/Livewire/RecetteFormTest.php tests/Livewire/RecetteListTest.php
```

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat: recettes module (RecetteService, RecetteForm, RecetteList Livewire)"
```

---

## Chunk 6: Dons & Budget

### Task 14: DonService + DonForm + DonList

**Files:**
- Create: `app/Services/DonService.php`
- Create: `app/Livewire/DonForm.php`
- Create: `app/Livewire/DonList.php`
- Create: `resources/views/livewire/don-form.blade.php`
- Create: `resources/views/livewire/don-list.blade.php`
- Modify: `resources/views/dons/index.blade.php`
- Create: `tests/Livewire/DonFormTest.php`
- Create: `tests/Livewire/DonListTest.php`

- [ ] **Step 1: Write DonForm Livewire tests**

Key test cases:
- Create don with existing donateur
- Create don with new donateur (inline creation)
- Create anonymous don (no donateur)
- Seance selector appears when operation with nombre_seances selected
- Validation: required fields (date, montant, mode_paiement, saisi_par auto-set)
- Edit existing don

- [ ] **Step 2: Write DonList Livewire tests**

Key test cases:
- List dons with filters (exercice, donateur, operation)
- Click donateur name shows donation history (modal or inline section)
- Delete don (soft delete)

- [ ] **Step 3: Run tests to verify they fail**

```bash
php artisan test tests/Livewire/DonFormTest.php tests/Livewire/DonListTest.php
```

- [ ] **Step 4: Implement DonService**

`app/Services/DonService.php`:
- `create(array $data, ?array $newDonateur = null)`: If `$newDonateur` provided, create Donateur first, then create Don with `donateur_id`. Sets `saisi_par = auth()->id()`. All in DB::transaction.
- `update(Don $don, array $data)`: Update don fields.
- `delete(Don $don)`: Soft delete.

- [ ] **Step 5: Implement DonForm Livewire component**

Similar to DepenseForm but simpler (no lignes). Properties: `date`, `montant`, `mode_paiement`, `objet`, `donateur_id`, `operation_id`, `seance`, `compte_id`. Additional properties for inline donateur creation: `new_donateur_nom`, `new_donateur_prenom`, `new_donateur_email`, `new_donateur_adresse`, `creating_donateur` (toggle).

- [ ] **Step 6: Implement DonList Livewire component**

Filters: exercice, donateur search. Table: date, donateur (or "Anonyme"), montant, mode, objet, opération, pointé. Click donateur name to toggle donation history section below.

- [ ] **Step 7: Create Blade views and wire up index page**

- [ ] **Step 8: Run tests**

```bash
php artisan test tests/Livewire/DonFormTest.php tests/Livewire/DonListTest.php
```
Expected: All PASS

- [ ] **Step 9: Commit**

```bash
git add -A && git commit -m "feat: dons module (DonService, DonForm, DonList with donateur inline creation)"
```

### Task 15: BudgetService + BudgetTable

**Files:**
- Create: `app/Services/BudgetService.php`
- Create: `app/Livewire/BudgetTable.php`
- Create: `resources/views/livewire/budget-table.blade.php`
- Modify: `resources/views/budget/index.blade.php`
- Create: `tests/Unit/BudgetServiceTest.php`
- Create: `tests/Livewire/BudgetTableTest.php`

- [ ] **Step 1: Write BudgetService unit tests**

`tests/Unit/BudgetServiceTest.php`:
```php
<?php

use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;
use App\Services\BudgetService;

it('computes realise for depense sous-categories', function () {
    $cat = Categorie::factory()->create(['type' => 'depense']);
    $sc = SousCategorie::factory()->for($cat, 'categorie')->create();

    $depense = Depense::factory()->create(['date' => '2025-10-15', 'montant_total' => 100]);
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'sous_categorie_id' => $sc->id,
        'montant' => 100,
    ]);

    $service = new BudgetService();
    $realise = $service->realise($sc->id, 2025);
    expect((float) $realise)->toBe(100.0);
});

it('computes realise for recette sous-categories', function () {
    $cat = Categorie::factory()->create(['type' => 'recette']);
    $sc = SousCategorie::factory()->for($cat, 'categorie')->create();

    $recette = Recette::factory()->create(['date' => '2025-11-01', 'montant_total' => 250]);
    RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
        'sous_categorie_id' => $sc->id,
        'montant' => 250,
    ]);

    $service = new BudgetService();
    $realise = $service->realise($sc->id, 2025);
    expect((float) $realise)->toBe(250.0);
});

it('returns zero when no transactions exist', function () {
    $sc = SousCategorie::factory()->create();
    $service = new BudgetService();
    expect((float) $service->realise($sc->id, 2025))->toBe(0.0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/BudgetServiceTest.php
```

- [ ] **Step 3: Implement BudgetService**

`app/Services/BudgetService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DepenseLigne;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;

final class BudgetService
{
    public function realise(int $sousCategorieId, int $exercice): float
    {
        $sc = SousCategorie::with('categorie')->findOrFail($sousCategorieId);
        $start = "{$exercice}-09-01";
        $end = ($exercice + 1) . '-08-31';

        if ($sc->categorie->type->value === 'depense') {
            return (float) DepenseLigne::where('sous_categorie_id', $sousCategorieId)
                ->whereHas('depense', fn ($q) => $q->whereBetween('date', [$start, $end]))
                ->sum('montant');
        }

        return (float) RecetteLigne::where('sous_categorie_id', $sousCategorieId)
            ->whereHas('recette', fn ($q) => $q->whereBetween('date', [$start, $end]))
            ->sum('montant');
    }
}
```

- [ ] **Step 4: Run unit tests**

```bash
php artisan test tests/Unit/BudgetServiceTest.php
```
Expected: All PASS

- [ ] **Step 5: Write BudgetTable Livewire tests**

`tests/Livewire/BudgetTableTest.php`: Test rendering, exercice switching, adding budget line, editing montant_prevu inline, deleting budget line, prevu vs realise display.

- [ ] **Step 6: Run tests to verify they fail, then implement**

Implement `app/Livewire/BudgetTable.php`:
- `$exercice` property (defaults to current)
- Loads all sous-categories grouped by categorie, with budget_lines for selected exercice and realise amounts
- `addLine(int $sousCategorieId)`: Creates BudgetLine with zero montant_prevu
- `updatePrevu(int $budgetLineId, float $montant)`: Updates montant_prevu
- `deleteLine(int $budgetLineId)`: Deletes budget line

View: Exercice selector. Two sections (Charges / Produits). Table: sous-catégorie, prévu (editable input), réalisé, écart. Totals row.

- [ ] **Step 7: Run tests**

```bash
php artisan test tests/Livewire/BudgetTableTest.php
```
Expected: All PASS

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "feat: budget module (BudgetService, BudgetTable Livewire with prevu vs realise)"
```

---

## Chunk 7: Rapprochement, Rapports & Dashboard

### Task 16: RapprochementService + Rapprochement Livewire

**Files:**
- Create: `app/Services/RapprochementService.php`
- Create: `app/Livewire/Rapprochement.php`
- Create: `resources/views/livewire/rapprochement.blade.php`
- Modify: `resources/views/rapprochement/index.blade.php`
- Create: `tests/Unit/RapprochementServiceTest.php`
- Create: `tests/Livewire/RapprochementTest.php`

- [ ] **Step 1: Write RapprochementService unit tests**

`tests/Unit/RapprochementServiceTest.php`:
- Test solde theorique calculation: `solde_initial + pointed_recettes + pointed_dons + pointed_cotisations - pointed_depenses`
- Test with no pointed transactions returns solde_initial
- Test toggle pointe on depense, recette, don, cotisation
- Test solde updates after toggling

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/RapprochementServiceTest.php
```

- [ ] **Step 3: Implement RapprochementService**

`app/Services/RapprochementService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\Recette;

final class RapprochementService
{
    public function soldeTheorique(CompteBancaire $compte, ?string $dateDebut = null, ?string $dateFin = null): float
    {
        $solde = (float) $compte->solde_initial;

        $solde += (float) Recette::where('compte_id', $compte->id)->where('pointe', true)
            ->when($dateFin, fn ($q) => $q->where('date', '<=', $dateFin))
            ->sum('montant_total');

        $solde += (float) Don::where('compte_id', $compte->id)->where('pointe', true)
            ->when($dateFin, fn ($q) => $q->where('date', '<=', $dateFin))
            ->sum('montant');

        $solde += (float) Cotisation::where('compte_id', $compte->id)->where('pointe', true)
            ->when($dateFin, fn ($q) => $q->where('date_paiement', '<=', $dateFin))
            ->sum('montant');

        $solde -= (float) Depense::where('compte_id', $compte->id)->where('pointe', true)
            ->when($dateFin, fn ($q) => $q->where('date', '<=', $dateFin))
            ->sum('montant_total');

        return $solde;
    }

    public function togglePointe(string $type, int $id): bool
    {
        $model = match ($type) {
            'depense' => Depense::findOrFail($id),
            'recette' => Recette::findOrFail($id),
            'don' => Don::findOrFail($id),
            'cotisation' => Cotisation::findOrFail($id),
        };

        $model->pointe = !$model->pointe;
        $model->save();

        return $model->pointe;
    }
}
```

- [ ] **Step 4: Run unit tests**

```bash
php artisan test tests/Unit/RapprochementServiceTest.php
```
Expected: All PASS

- [ ] **Step 5: Write and implement Rapprochement Livewire component**

Tests then implementation. Properties: `$compte_id`, `$date_debut`, `$date_fin`. Lists all unpointed transactions (4 types) for the selected compte and period. `toggle(string $type, int $id)` calls service. Displays live solde theorique.

View: Compte selector + date range. Table: date, type (Dépense/Recette/Don/Cotisation), libellé, montant, pointé checkbox. Solde théorique displayed prominently.

- [ ] **Step 6: Run all rapprochement tests**

```bash
php artisan test tests/Unit/RapprochementServiceTest.php tests/Livewire/RapprochementTest.php
```
Expected: All PASS

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat: rapprochement bancaire (RapprochementService, Livewire component)"
```

### Task 17: RapportService + Report Livewire Components

**Files:**
- Create: `app/Services/RapportService.php`
- Create: `app/Livewire/RapportCompteResultat.php`
- Create: `app/Livewire/RapportSeances.php`
- Create: `resources/views/livewire/rapport-compte-resultat.blade.php`
- Create: `resources/views/livewire/rapport-seances.blade.php`
- Modify: `resources/views/rapports/index.blade.php`
- Create: `tests/Unit/RapportServiceTest.php`
- Create: `tests/Livewire/RapportCompteResultatTest.php`
- Create: `tests/Livewire/RapportSeancesTest.php`

- [ ] **Step 1: Write RapportService unit tests**

`tests/Unit/RapportServiceTest.php`:
- Test compte de resultat aggregation by code_cerfa for a given exercice
- Test filtering by operations
- Test seance pivot table: rows = sous-categories, columns = seances 1..N, values = sum of montants
- Test CSV generation produces valid CSV string

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/RapportServiceTest.php
```

- [ ] **Step 3: Implement RapportService**

`app/Services/RapportService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DepenseLigne;
use App\Models\RecetteLigne;

final class RapportService
{
    /**
     * Compte de résultat grouped by code_cerfa.
     * Returns ['charges' => [...], 'produits' => [...]] where each entry
     * has code_cerfa, label (sous-categorie name), and montant.
     */
    public function compteDeResultat(int $exercice, ?array $operationIds = null): array
    {
        $start = "{$exercice}-09-01";
        $end = ($exercice + 1) . '-08-31';

        $charges = DepenseLigne::with('sousCategorie')
            ->whereHas('depense', fn ($q) => $q->whereBetween('date', [$start, $end]))
            ->when($operationIds, fn ($q) => $q->whereIn('operation_id', $operationIds))
            ->get()
            ->groupBy(fn ($l) => $l->sousCategorie->code_cerfa ?? 'N/A')
            ->map(fn ($group) => [
                'code_cerfa' => $group->first()->sousCategorie->code_cerfa,
                'label' => $group->first()->sousCategorie->nom,
                'montant' => $group->sum('montant'),
            ])
            ->sortKeys()
            ->values()
            ->toArray();

        $produits = RecetteLigne::with('sousCategorie')
            ->whereHas('recette', fn ($q) => $q->whereBetween('date', [$start, $end]))
            ->when($operationIds, fn ($q) => $q->whereIn('operation_id', $operationIds))
            ->get()
            ->groupBy(fn ($l) => $l->sousCategorie->code_cerfa ?? 'N/A')
            ->map(fn ($group) => [
                'code_cerfa' => $group->first()->sousCategorie->code_cerfa,
                'label' => $group->first()->sousCategorie->nom,
                'montant' => $group->sum('montant'),
            ])
            ->sortKeys()
            ->values()
            ->toArray();

        return ['charges' => $charges, 'produits' => $produits];
    }

    /**
     * Seance pivot table for an operation.
     * Returns rows of sous-categories with montant per seance.
     */
    public function rapportSeances(int $operationId): array
    {
        $depenseRows = DepenseLigne::with('sousCategorie.categorie')
            ->where('operation_id', $operationId)
            ->whereNotNull('seance')
            ->get();

        $recetteRows = RecetteLigne::with('sousCategorie.categorie')
            ->where('operation_id', $operationId)
            ->whereNotNull('seance')
            ->get();

        // Build pivot: keyed by sous_categorie_id, columns by seance number
        $pivot = [];
        foreach ([...$depenseRows, ...$recetteRows] as $row) {
            $scId = $row->sous_categorie_id;
            $pivot[$scId] ??= [
                'sous_categorie' => $row->sousCategorie->nom,
                'type' => $row->sousCategorie->categorie->type->value,
                'seances' => [],
                'total' => 0,
            ];
            $pivot[$scId]['seances'][$row->seance] = ($pivot[$scId]['seances'][$row->seance] ?? 0) + (float) $row->montant;
            $pivot[$scId]['total'] += (float) $row->montant;
        }

        return array_values($pivot);
    }

    public function toCsv(array $rows, array $headers): string
    {
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers, ';');
        foreach ($rows as $row) {
            fputcsv($output, $row, ';');
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }
}
```

- [ ] **Step 4: Run unit tests**

```bash
php artisan test tests/Unit/RapportServiceTest.php
```
Expected: All PASS

- [ ] **Step 5: Write and implement RapportCompteResultat Livewire component**

Properties: `$exercice` (required), `$operationIds` (array, default empty = all). Calls RapportService::compteDeResultat. View: exercice selector, operations multi-select, CERFA-formatted table (charges left, produits right), totals, resultat net. CSV export button triggers download.

- [ ] **Step 6: Write and implement RapportSeances Livewire component**

Properties: `$operation_id` (required, filtered to operations with nombre_seances). Calls RapportService::rapportSeances. View: operation selector, pivot table (rows = sous-categories, columns = Séance 1..N + Total), charges and produits separated, solde per seance. CSV export button.

- [ ] **Step 7: Wire up rapports/index.blade.php**

```blade
<x-layouts.app title="Rapports">
    <h1>Rapports</h1>
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#compte-resultat">Compte de résultat</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#seances">Rapport par séances</a>
        </li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane active" id="compte-resultat">
            <livewire:rapport-compte-resultat />
        </div>
        <div class="tab-pane" id="seances">
            <livewire:rapport-seances />
        </div>
    </div>
</x-layouts.app>
```

- [ ] **Step 8: Run all rapport tests**

```bash
php artisan test tests/Unit/RapportServiceTest.php tests/Livewire/RapportCompteResultatTest.php tests/Livewire/RapportSeancesTest.php
```
Expected: All PASS

- [ ] **Step 9: Commit**

```bash
git add -A && git commit -m "feat: rapports module (compte de resultat CERFA, rapport seances, CSV export)"
```

### Task 18: Dashboard Livewire Component

**Files:**
- Create: `app/Livewire/Dashboard.php`
- Create: `resources/views/livewire/dashboard.blade.php`
- Modify: `resources/views/dashboard.blade.php`
- Modify: `app/Http/Controllers/DashboardController.php`
- Create: `tests/Livewire/DashboardTest.php`

- [ ] **Step 1: Write Dashboard Livewire tests**

`tests/Livewire/DashboardTest.php`:
- Renders for authenticated user
- Shows correct exercice
- Displays solde general (total recettes - total depenses for exercice)
- Shows recent depenses and recettes
- Shows recent dons
- Shows membres with pending cotisation for current exercice
- Budget prevu vs realise summary

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Livewire/DashboardTest.php
```

- [ ] **Step 3: Implement Dashboard Livewire component**

`app/Livewire/Dashboard.php`:

Properties: `$exercice` (defaults to current via ExerciceService).

Computed properties / methods using ExerciceService, BudgetService:
- `soldeGeneral()`: total recettes - total depenses for exercice
- `dernieresDepenses()`: last 5 depenses
- `dernieresRecettes()`: last 5 recettes
- `derniersDons()`: last 5 dons
- `membresSansCotisation()`: membres without cotisation for current exercice
- `budgetResume()`: summary of prevu vs realise by categorie

View: Bootstrap cards grid:
- Row 1: Solde général card, exercice selector
- Row 2: Budget résumé (prevu vs realise bar or table)
- Row 3: Two columns — dernières dépenses table, dernières recettes table
- Row 4: Two columns — derniers dons table, membres sans cotisation table

- [ ] **Step 4: Update dashboard.blade.php**

```blade
<x-layouts.app title="Tableau de bord">
    <livewire:dashboard />
</x-layouts.app>
```

- [ ] **Step 5: Run tests**

```bash
php artisan test tests/Livewire/DashboardTest.php
```
Expected: All PASS

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat: dashboard with KPIs, recent transactions, budget summary"
```

---

## Chunk 8: Final Polish

### Task 19: Full Test Suite & Pint

- [ ] **Step 1: Run the complete test suite**

```bash
php artisan test
```
Expected: All tests pass.

- [ ] **Step 2: Run Pint for code style**

```bash
./vendor/bin/pint
```

- [ ] **Step 3: Fix any Pint issues and re-run tests**

```bash
./vendor/bin/pint
php artisan test
```
Expected: 0 Pint issues, all tests pass.

- [ ] **Step 4: Verify all routes**

```bash
php artisan route:list
```
Expected: All application routes present with correct verbs.

- [ ] **Step 5: Run migration fresh + seed to verify clean state**

```bash
php artisan migrate:fresh --seed
```
Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "chore: code style fixes (Pint)"
```

### Task 20: Final Verification

- [ ] **Step 1: Manual smoke test checklist**

Start the dev server and verify each module loads:
```bash
php artisan serve
```

Verify in browser:
1. Login page loads (Bootstrap styled)
2. Can log in with seeded user
3. Dashboard shows KPIs
4. Each nav link loads its page without errors
5. Can create/edit/delete a membre
6. Can create a depense with ventilation lines
7. Can create a recette
8. Can create a don with inline donateur creation
9. Budget shows prevu vs realise
10. Rapprochement toggles pointe
11. Rapports generate compte de resultat
12. Parametres tabs all functional
13. Logout works

- [ ] **Step 2: Commit any final fixes**

```bash
git add -A && git commit -m "fix: final adjustments from smoke testing"
```
