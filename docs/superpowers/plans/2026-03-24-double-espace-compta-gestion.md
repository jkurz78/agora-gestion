# Double espace Comptabilité / Gestion — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the app into two URL-prefixed spaces (Comptabilité `/compta/` and Gestion `/gestion/`) with distinct navbars, a switcher, and a minimal Gestion dashboard.

**Architecture:** Two route groups with URL prefixes, a `DetecteEspace` middleware injecting space variables into views, a single parameterized layout. `MembreList` migrates to `AdherentList` under `/gestion/adherents`.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 CDN, Pest PHP

**Spec:** `docs/superpowers/specs/2026-03-24-double-espace-compta-gestion-design.md`

---

## Task 1: Enum Espace + Migration `dernier_espace` sur users

**Files:**
- Create: `app/Enums/Espace.php`
- Create: `database/migrations/2026_03_24_200000_add_dernier_espace_to_users_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/EspaceEnumTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/EspaceEnumTest.php
declare(strict_types=1);

use App\Enums\Espace;
use App\Models\User;

test('Espace enum has compta and gestion cases', function (): void {
    expect(Espace::Compta->value)->toBe('compta');
    expect(Espace::Gestion->value)->toBe('gestion');
    expect(Espace::Compta->label())->toBe('Comptabilité');
    expect(Espace::Gestion->label())->toBe('Gestion');
    expect(Espace::Compta->color())->toBe('#722281');
    expect(Espace::Gestion->color())->toBe('#63B2EA');
});

test('user dernier_espace defaults to compta', function (): void {
    $user = User::factory()->create();
    expect($user->dernier_espace)->toBeInstanceOf(Espace::class);
    expect($user->dernier_espace)->toBe(Espace::Compta);
});

test('user dernier_espace can be set to gestion', function (): void {
    $user = User::factory()->create();
    $user->update(['dernier_espace' => Espace::Gestion]);
    $user->refresh();
    expect($user->dernier_espace)->toBe(Espace::Gestion);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/EspaceEnumTest.php`
Expected: FAIL — Espace enum class not found

- [ ] **Step 3: Create the Espace enum**

```php
<?php
// app/Enums/Espace.php
declare(strict_types=1);

namespace App\Enums;

enum Espace: string
{
    case Compta = 'compta';
    case Gestion = 'gestion';

    public function label(): string
    {
        return match ($this) {
            self::Compta => 'Comptabilité',
            self::Gestion => 'Gestion',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Compta => '#722281',
            self::Gestion => '#63B2EA',
        };
    }
}
```

- [ ] **Step 4: Create the migration**

```php
<?php
// database/migrations/2026_03_24_200000_add_dernier_espace_to_users_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('dernier_espace', 10)->default('compta')->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('dernier_espace');
        });
    }
};
```

- [ ] **Step 5: Update User model**

Add `'dernier_espace'` to `$fillable` and add cast in `casts()`:

```php
// app/Models/User.php — add to $fillable:
'dernier_espace',

// app/Models/User.php — add to casts():
'dernier_espace' => Espace::class,
```

Import: `use App\Enums\Espace;`

- [ ] **Step 6: Run migration and test**

Run: `./vendor/bin/sail artisan migrate && ./vendor/bin/sail test tests/Feature/EspaceEnumTest.php`
Expected: All 3 tests PASS

- [ ] **Step 7: Commit**

```bash
git add app/Enums/Espace.php database/migrations/2026_03_24_200000_add_dernier_espace_to_users_table.php app/Models/User.php tests/Feature/EspaceEnumTest.php
git commit -m "feat(espace): add Espace enum and dernier_espace column on users"
```

---

## Task 2: Middleware DetecteEspace

**Files:**
- Create: `app/Http/Middleware/DetecteEspace.php`
- Test: `tests/Feature/DetecteEspaceMiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/DetecteEspaceMiddlewareTest.php
declare(strict_types=1);

use App\Enums\Espace;
use App\Models\User;

test('middleware sets espace compta for /compta/ routes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/compta/dashboard')
        ->assertOk();

    $user->refresh();
    expect($user->dernier_espace)->toBe(Espace::Compta);
});

test('middleware sets espace gestion for /gestion/ routes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/gestion/dashboard')
        ->assertOk();

    $user->refresh();
    expect($user->dernier_espace)->toBe(Espace::Gestion);
});

test('root redirects to dernier_espace dashboard', function (): void {
    $user = User::factory()->create(['dernier_espace' => Espace::Gestion]);
    $this->actingAs($user)
        ->get('/')
        ->assertRedirect('/gestion/dashboard');
});

test('root redirects to compta dashboard by default', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/')
        ->assertRedirect('/compta/dashboard');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/DetecteEspaceMiddlewareTest.php`
Expected: FAIL — routes don't exist yet (404)

Note: These tests will fully pass only after Task 3 (routes). For now, just verify the test file is syntactically correct. We proceed with the middleware implementation.

- [ ] **Step 3: Create the middleware**

```php
<?php
// app/Http/Middleware/DetecteEspace.php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Espace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DetecteEspace
{
    public function handle(Request $request, Closure $next, string $espace): Response
    {
        $espaceEnum = Espace::from($espace);

        // Share with views
        $request->attributes->set('espace', $espaceEnum);
        view()->share('espace', $espaceEnum);
        view()->share('espaceColor', $espaceEnum->color());
        view()->share('espaceLabel', $espaceEnum->label());

        // Persist last espace choice
        $user = $request->user();
        if ($user !== null && $user->dernier_espace !== $espaceEnum) {
            $user->update(['dernier_espace' => $espaceEnum]);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Http/Middleware/DetecteEspace.php tests/Feature/DetecteEspaceMiddlewareTest.php
git commit -m "feat(espace): add DetecteEspace middleware"
```

---

## Task 3: Restructure routes with /compta/ and /gestion/ prefixes

**Files:**
- Modify: `routes/web.php`
- Test: `tests/Feature/DetecteEspaceMiddlewareTest.php` (re-run existing tests)

- [ ] **Step 1: Write additional routing tests**

Add to `tests/Feature/DetecteEspaceMiddlewareTest.php`:

```php
test('legacy /dashboard redirects 301 to /compta/dashboard', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect('/compta/dashboard')
        ->assertStatus(301);
});

test('legacy /membres redirects 301 to /gestion/adherents', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/membres')
        ->assertRedirect('/gestion/adherents')
        ->assertStatus(301);
});

test('legacy /transactions redirects 301 to /compta/transactions', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/transactions')
        ->assertRedirect('/compta/transactions')
        ->assertStatus(301);
});

test('parametres accessible from both spaces', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/compta/parametres/association')->assertOk();
    $this->actingAs($user)->get('/gestion/parametres/association')->assertOk();
});
```

- [ ] **Step 2: Rewrite routes/web.php**

Replace the entire routes file. Key structure:

```php
<?php
// routes/web.php
use App\Enums\Espace;
use App\Http\Controllers\BudgetExportController;
use App\Http\Controllers\CategorieController;
use App\Http\Controllers\CompteBancaireController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OperationController;
use App\Http\Controllers\RapprochementPdfController;
use App\Http\Controllers\SousCategorieController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\DetecteEspace;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Tiers;
use Illuminate\Support\Facades\Route;

// Root: redirect to user's last espace
Route::middleware('auth')->get('/', function () {
    $espace = auth()->user()->dernier_espace ?? Espace::Compta;
    return redirect("/{$espace->value}/dashboard");
})->name('home');

// ── Shared route registrar (parametres, helloasso-sync) ──
$registerParametres = function (): void {
    Route::prefix('parametres')->name('parametres.')->group(function (): void {
        Route::view('/association', 'parametres.association')->name('association');
        Route::view('/helloasso', 'parametres.helloasso')->name('helloasso');
        Route::resource('categories', CategorieController::class)->except(['show']);
        Route::resource('sous-categories', SousCategorieController::class)->except(['show']);
        Route::post('sous-categories/{sousCategory}/toggle-flag', [SousCategorieController::class, 'toggleFlag'])->name('sous-categories.toggle-flag');
        Route::resource('comptes-bancaires', CompteBancaireController::class)->except(['show']);
        Route::resource('utilisateurs', UserController::class)->only(['index', 'store', 'update', 'destroy']);
    });
    Route::view('/helloasso-sync', 'banques.helloasso-sync')->name('helloasso-sync');
};

// ── Espace Comptabilité ──
Route::middleware(['auth', DetecteEspace::class.':compta'])
    ->prefix('compta')
    ->name('compta.')
    ->group(function () use ($registerParametres): void {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::view('/transactions', 'transactions.index')->name('transactions.index');
        Route::view('/transactions/all', 'transactions.all')->name('transactions.all');
        Route::get('/transactions/import/template/{type}', [CsvImportController::class, 'template'])
            ->whereIn('type', ['depense', 'recette'])
            ->name('transactions.import.template');
        Route::view('/dons', 'dons.index')->name('dons.index');
        Route::view('/cotisations', 'cotisations.index')->name('cotisations.index');
        Route::view('/tiers', 'tiers.index')->name('tiers.index');
        Route::get('/tiers/{tiers}/transactions', function (Tiers $tiers) {
            return view('tiers.transactions', compact('tiers'));
        })->name('tiers.transactions');
        Route::view('/budget', 'budget.index')->name('budget.index');
        Route::get('/budget/export', BudgetExportController::class)->name('budget.export');
        Route::view('/rapprochement', 'rapprochement.index')->name('rapprochement.index');
        Route::get('/rapprochement/{rapprochement}', function (RapprochementBancaire $rapprochement) {
            return view('rapprochement.detail', compact('rapprochement'));
        })->name('rapprochement.detail');
        Route::get('/rapprochement/{rapprochement}/pdf', RapprochementPdfController::class)
            ->name('rapprochement.pdf');
        Route::view('/virements', 'virements.index')->name('virements.index');
        Route::get('comptes-bancaires/{compte}/transactions', function (CompteBancaire $compte) {
            return view('comptes-bancaires.transactions', compact('compte'));
        })->name('comptes-bancaires.transactions');
        Route::view('/rapports', 'rapports.index')->name('rapports.index');

        // Exercices
        Route::view('/exercices/cloture', 'exercices.cloture')->name('exercices.cloture');
        Route::view('/exercices/changer', 'exercices.changer')->name('exercices.changer');
        Route::view('/exercices/reouvrir', 'exercices.reouvrir')->name('exercices.reouvrir');
        Route::view('/exercices/audit', 'exercices.audit')->name('exercices.audit');

        // Operations
        Route::resource('operations', OperationController::class)->except(['destroy']);

        // Shared registrations
        $registerParametres();
    });

// ── Espace Gestion ──
Route::middleware(['auth', DetecteEspace::class.':gestion'])
    ->prefix('gestion')
    ->name('gestion.')
    ->group(function () use ($registerParametres): void {
        Route::view('/dashboard', 'gestion.dashboard')->name('dashboard');
        Route::view('/adherents', 'gestion.adherents')->name('adherents');

        // Shared registrations
        $registerParametres();
    });

// ── Profile (espace-agnostic) ──
Route::middleware('auth')->group(function (): void {
    Route::view('/profil', 'profil.index')->name('profil.index');
});

// ── Legacy redirects (301) ──
Route::middleware('auth')->group(function (): void {
    Route::permanentRedirect('/dashboard', '/compta/dashboard');
    Route::permanentRedirect('/transactions', '/compta/transactions');
    Route::permanentRedirect('/transactions/all', '/compta/transactions/all');
    Route::permanentRedirect('/dons', '/compta/dons');
    Route::permanentRedirect('/cotisations', '/compta/cotisations');
    Route::permanentRedirect('/tiers', '/compta/tiers');
    Route::permanentRedirect('/budget', '/compta/budget');
    Route::permanentRedirect('/rapprochement', '/compta/rapprochement');
    Route::permanentRedirect('/virements', '/compta/virements');
    Route::permanentRedirect('/rapports', '/compta/rapports');
    Route::permanentRedirect('/membres', '/gestion/adherents');
    Route::permanentRedirect('/banques/helloasso-sync', '/compta/helloasso-sync');
    Route::permanentRedirect('/exercices/cloture', '/compta/exercices/cloture');
    Route::permanentRedirect('/exercices/changer', '/compta/exercices/changer');
    Route::permanentRedirect('/exercices/reouvrir', '/compta/exercices/reouvrir');
    Route::permanentRedirect('/exercices/audit', '/compta/exercices/audit');
});

require __DIR__.'/auth.php';
```

- [ ] **Step 3: Run route tests**

Run: `./vendor/bin/sail test tests/Feature/DetecteEspaceMiddlewareTest.php`
Expected: All tests PASS (dashboard views may need gestion views — create empty placeholders if needed)

- [ ] **Step 4: Commit**

```bash
git add routes/web.php tests/Feature/DetecteEspaceMiddlewareTest.php
git commit -m "feat(espace): restructure routes with /compta/ and /gestion/ prefixes"
```

---

## Task 4: Update all route() references in Blade views

**Files:**
- Modify: All Blade files containing `route()` calls (see list below)
- Modify: All PHP files in `app/` containing `route()` or `redirect()->route()` calls

**Important context:** Every old route name (e.g., `'dashboard'`, `'transactions.index'`) now becomes `'compta.dashboard'`, `'compta.transactions.index'`, etc. Shared routes (parametres) exist under both prefixes.

**Route name mapping (old → new):**

| Old route name | New route name |
|---|---|
| `dashboard` | `compta.dashboard` |
| `transactions.index` | `compta.transactions.index` |
| `transactions.all` | `compta.transactions.all` |
| `transactions.import.template` | `compta.transactions.import.template` |
| `dons.index` | `compta.dons.index` |
| `cotisations.index` | `compta.cotisations.index` |
| `tiers.index` | `compta.tiers.index` |
| `tiers.transactions` | `compta.tiers.transactions` |
| `budget.index` | `compta.budget.index` |
| `budget.export` | `compta.budget.export` |
| `rapprochement.index` | `compta.rapprochement.index` |
| `rapprochement.detail` | `compta.rapprochement.detail` |
| `rapprochement.pdf` | `compta.rapprochement.pdf` |
| `virements.index` | `compta.virements.index` |
| `comptes-bancaires.transactions` | `compta.comptes-bancaires.transactions` |
| `rapports.index` | `compta.rapports.index` |
| `banques.helloasso-sync` | `compta.helloasso-sync` |
| `exercices.cloture` | `compta.exercices.cloture` |
| `exercices.changer` | `compta.exercices.changer` |
| `exercices.reouvrir` | `compta.exercices.reouvrir` |
| `exercices.audit` | `compta.exercices.audit` |
| `operations.index` | `compta.operations.index` |
| `operations.create` | `compta.operations.create` |
| `operations.store` | `compta.operations.store` |
| `operations.show` | `compta.operations.show` |
| `operations.edit` | `compta.operations.edit` |
| `operations.update` | `compta.operations.update` |
| `membres.index` | `gestion.adherents` |
| `parametres.*` | `compta.parametres.*` (or `$espace.parametres.*` in shared views) |

**Routes that DO NOT change:** `profil.index`, `logout`, `login`, `register`, `password.*`, `verification.*`

- [ ] **Step 1: Update Blade views — compta-specific views**

Files to update (use search-and-replace per file):
- `resources/views/livewire/dashboard.blade.php` — `route('transactions.index')` etc.
- `resources/views/livewire/rapprochement-list.blade.php`
- `resources/views/livewire/rapprochement-detail.blade.php`
- `resources/views/livewire/import-csv.blade.php`
- `resources/views/livewire/tiers-list.blade.php`
- `resources/views/livewire/helloasso-notification-banner.blade.php`
- `resources/views/operations/index.blade.php`
- `resources/views/operations/show.blade.php`
- `resources/views/operations/create.blade.php`
- `resources/views/operations/edit.blade.php`
- `resources/views/tiers/transactions.blade.php`
- `resources/views/comptes-bancaires/transactions.blade.php`
- `resources/views/livewire/banques/helloasso-sync-wizard.blade.php`

For each file: prefix route names with `compta.`. Example: `route('transactions.index')` → `route('compta.transactions.index')`.

- [ ] **Step 2: Update Blade views — shared views (parametres)**

For views under `resources/views/parametres/`:
- `parametres/categories/index.blade.php`
- `parametres/sous-categories/index.blade.php`
- `parametres/comptes-bancaires/index.blade.php`
- `parametres/utilisateurs/index.blade.php`

These use `route('parametres.xxx')`. Replace with dynamic espace prefix:
`route($espace->value . '.parametres.xxx')` — since `$espace` is shared by the middleware.

Example: `route('parametres.categories.index')` → `route($espace->value . '.parametres.categories.index')`

- [ ] **Step 3: Update PHP files — Controllers**

Controllers with `redirect()->route()` calls:
- `app/Http/Controllers/SousCategorieController.php` — replace `parametres.sous-categories.index` with dynamic espace prefix using `request()->attributes->get('espace')->value`
- `app/Http/Controllers/CategorieController.php` — same pattern
- `app/Http/Controllers/CompteBancaireController.php` — same pattern
- `app/Http/Controllers/UserController.php` — same pattern
- `app/Http/Controllers/OperationController.php` — `operations.index`, `operations.show` → `compta.operations.index`, `compta.operations.show`

For shared controllers (parametres), use a helper to build the route name:

```php
// In each shared controller method, get espace from request:
$prefix = request()->attributes->get('espace')->value;
return redirect()->route("{$prefix}.parametres.sous-categories.index");
```

- [ ] **Step 4: Update PHP files — Livewire components**

Files with `route()` or `redirect(route())`:
- `app/Livewire/RapprochementList.php` — `route('rapprochement.detail', ...)` → `route('compta.rapprochement.detail', ...)`
- `app/Livewire/RapprochementDetail.php` — `route('rapprochement.index')` → `route('compta.rapprochement.index')`
- `app/Livewire/Exercices/ClotureWizard.php` — `exercices.changer` → `compta.exercices.changer`
- `app/Livewire/Exercices/ReouvrirExercice.php` — `exercices.changer`, `exercices.reouvrir` → prefixed
- `app/Livewire/Exercices/ChangerExercice.php` — `exercices.changer` → prefixed
- `app/Livewire/BudgetTable.php` — check for route references
- `app/Livewire/TransactionCompteList.php` — check for route references

- [ ] **Step 5: Update Auth controllers**

- `app/Http/Controllers/Auth/AuthenticatedSessionController.php` — `route('dashboard', absolute: false)` → `route('home')`
- `app/Http/Controllers/Auth/ConfirmablePasswordController.php` — same
- `app/Http/Controllers/Auth/VerifyEmailController.php` — same
- `app/Http/Controllers/Auth/EmailVerificationNotificationController.php` — same
- `app/Http/Controllers/Auth/EmailVerificationPromptController.php` — same

Note: Auth redirects use `route('home')` (the root `/` route defined in Task 3) which redirects to `/{user->dernier_espace}/dashboard`. This ensures users land in their last-used espace after login.

- [ ] **Step 6: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: Some existing tests may fail due to old route names — this is expected. These will be fixed in Task 8. Focus on verifying that the route() updates in views and controllers are correct, not on pre-existing test failures.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor(espace): update all route() references with espace prefix"
```

---

## Task 5: Update layout with dynamic navbar and espace switcher

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Create: `resources/views/components/espace-switcher.blade.php`

- [ ] **Step 1: Create the espace-switcher Blade component**

```blade
{{-- resources/views/components/espace-switcher.blade.php --}}
@php
    use App\Enums\Espace;
    $currentEspace = $espace ?? Espace::Compta;
    $otherEspace = $currentEspace === Espace::Compta ? Espace::Gestion : Espace::Compta;
@endphp
<div class="dropdown d-inline-block">
    <a class="text-decoration-none dropdown-toggle" href="#"
       role="button" data-bs-toggle="dropdown" aria-expanded="false"
       style="color: rgba(255,255,255,0.85); font-size: .85rem;">
        {{ $currentEspace->label() }}
    </a>
    <ul class="dropdown-menu" style="min-width: 180px;">
        <li>
            <a class="dropdown-item {{ $currentEspace === Espace::Compta ? 'active' : '' }}"
               href="{{ route('compta.dashboard') }}">
                <span class="d-inline-block rounded-circle me-2" style="width:10px;height:10px;background-color:{{ Espace::Compta->color() }}"></span>
                {{ Espace::Compta->label() }}
            </a>
        </li>
        <li>
            <a class="dropdown-item {{ $currentEspace === Espace::Gestion ? 'active' : '' }}"
               href="{{ route('gestion.dashboard') }}">
                <span class="d-inline-block rounded-circle me-2" style="width:10px;height:10px;background-color:{{ Espace::Gestion->color() }}"></span>
                {{ Espace::Gestion->label() }}
            </a>
        </li>
    </ul>
</div>
```

- [ ] **Step 2: Update layout — dynamic CSS**

In `layouts/app.blade.php`, replace the hardcoded `.navbar-svs` background and dropdown styles with CSS custom properties driven by `$espaceColor`:

Replace the `<style>` block's `.navbar-svs` background color:
```css
.navbar-svs {
    background-color: {{ $espaceColor ?? '#722281' }};
}
```

Update dropdown shadow color to use the espace color:
```css
.navbar-svs .dropdown-menu {
    box-shadow: 0 4px 16px {{ ($espaceColor ?? '#722281') }}2e;
}
```

- [ ] **Step 3: Update layout — brand area with switcher**

Replace the static "Comptabilité" text in the brand area (line 111) with the switcher component:

Old:
```blade
<span class="d-block small opacity-75">Comptabilité</span>
```

New:
```blade
<x-espace-switcher />
```

- [ ] **Step 4: Update layout — conditional menus**

Wrap the compta-specific menu items (Transactions, Banques, Tiers, Budget, Rapports, Exercices) in `@if($espace === \App\Enums\Espace::Compta)`.

Wrap the gestion-specific menu items (Adhérents, HelloAsso sync direct link) in `@if($espace === \App\Enums\Espace::Gestion)`.

Keep Paramètres and user dropdown in both spaces.

**Compta menus (within `@if($espace === \App\Enums\Espace::Compta)`):**
- Transactions dropdown
- Banques dropdown
- Tiers
- Budget, Rapports
- Exercices dropdown

**Gestion menus (within `@if($espace === \App\Enums\Espace::Gestion)`):**
- Adhérents link: `<a href="{{ route('gestion.adherents') }}">Adhérents</a>`
- HelloAsso Sync direct link: `<a href="{{ route('gestion.helloasso-sync') }}">Sync HelloAsso</a>`

**Shared (no condition):**
- Paramètres dropdown (update route names to use `$espace->value . '.parametres.*'`)
- Exercice badge (keep visible in both spaces)
- User dropdown

- [ ] **Step 5: Update layout — dynamic footer color**

Replace the footer background logic (line 394):

Old:
```php
@php $footerBg = app()->environment('production') ? '#722281' : '#b45309'; @endphp
```

New:
```php
@php $footerBg = app()->environment('production') ? ($espaceColor ?? '#722281') : '#b45309'; @endphp
```

- [ ] **Step 6: Update layout — page title**

Line 19, update the title to include espace:

Old:
```blade
<title>{{ $title ?? $nomAsso.' Comptabilité' }}</title>
```

New:
```blade
<title>{{ $title ?? $nomAsso.' '.($espaceLabel ?? 'Comptabilité') }}</title>
```

- [ ] **Step 7: Test visually in browser**

Open `http://localhost/compta/dashboard` — should see violet navbar
Open `http://localhost/gestion/dashboard` — should see blue navbar (will need placeholder view — see Task 6)

- [ ] **Step 8: Commit**

```bash
git add resources/views/layouts/app.blade.php resources/views/components/espace-switcher.blade.php
git commit -m "feat(espace): dynamic navbar, footer, and espace switcher"
```

---

## Task 6: Gestion Dashboard

**Files:**
- Create: `app/Livewire/GestionDashboard.php`
- Create: `resources/views/livewire/gestion-dashboard.blade.php`
- Create: `resources/views/gestion/dashboard.blade.php`
- Test: `tests/Feature/GestionDashboardTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/GestionDashboardTest.php
declare(strict_types=1);

use App\Models\User;

test('gestion dashboard loads successfully', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/gestion/dashboard')
        ->assertOk()
        ->assertSee('Opérations')
        ->assertSee('Dernières adhésions')
        ->assertSee('Derniers dons');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/GestionDashboardTest.php`
Expected: FAIL — view not found

- [ ] **Step 3: Create the page wrapper view**

```blade
{{-- resources/views/gestion/dashboard.blade.php --}}
<x-app-layout>
    <livewire:gestion-dashboard />
</x-app-layout>
```

- [ ] **Step 4: Create the Livewire component**

```php
<?php
// app/Livewire/GestionDashboard.php
declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatutOperation;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Services\ExerciceService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class GestionDashboard extends Component
{
    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();

        // Opérations : toutes pour l'exercice, triées par date_debut
        $operations = Operation::query()
            ->where(function ($q) use ($exercice): void {
                $range = app(ExerciceService::class)->dateRange($exercice);
                $q->where(function ($inner) use ($range): void {
                    $inner->whereNotNull('date_debut')
                        ->whereNotNull('date_fin')
                        ->where('date_debut', '<=', $range['end']->toDateString())
                        ->where('date_fin', '>=', $range['start']->toDateString());
                })->orWhere(function ($inner) use ($range): void {
                    $inner->whereNotNull('date_debut')
                        ->whereNull('date_fin')
                        ->where('date_debut', '<=', $range['end']->toDateString())
                        ->where('date_debut', '>=', $range['start']->toDateString());
                });
            })
            ->orderBy('date_debut')
            ->get();

        // Dernières adhésions (cotisations)
        $cotSousCategorieIds = SousCategorie::where('pour_cotisations', true)->pluck('id');
        $dernieresAdhesions = Transaction::where('type', 'recette')
            ->forExercice($exercice)
            ->whereHas('lignes', fn ($q) => $q->whereIn('sous_categorie_id', $cotSousCategorieIds))
            ->with('tiers')
            ->latest('date')->latest('id')
            ->take(10)
            ->get();

        // Derniers dons
        $donSousCategorieIds = SousCategorie::where('pour_dons', true)->pluck('id');
        $derniersDons = Transaction::where('type', 'recette')
            ->forExercice($exercice)
            ->whereHas('lignes', fn ($q) => $q->whereIn('sous_categorie_id', $donSousCategorieIds))
            ->with('tiers')
            ->latest('date')->latest('id')
            ->take(10)
            ->get();

        return view('livewire.gestion-dashboard', [
            'operations' => $operations,
            'dernieresAdhesions' => $dernieresAdhesions,
            'derniersDons' => $derniersDons,
        ]);
    }
}
```

- [ ] **Step 5: Create the Livewire Blade view**

```blade
{{-- resources/views/livewire/gestion-dashboard.blade.php --}}
<div>
    <div class="row g-4">
        {{-- Carte Opérations --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-calendar-event"></i> Opérations
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                <tr>
                                    <th>Opération</th>
                                    <th>Début</th>
                                    <th>Fin</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse($operations as $op)
                                    @php
                                        $now = now();
                                        if ($op->statut === \App\Enums\StatutOperation::Cloturee) {
                                            $badge = ['Terminée', 'bg-secondary'];
                                        } elseif ($op->date_debut && $op->date_debut->isFuture()) {
                                            $days = (int) $now->diffInDays($op->date_debut);
                                            $badge = ["Dans {$days} jour" . ($days > 1 ? 's' : ''), 'bg-info'];
                                        } elseif ($op->date_fin && $op->date_fin->isPast()) {
                                            $badge = ['Terminée', 'bg-secondary'];
                                        } else {
                                            $badge = ['En cours', 'bg-success'];
                                        }
                                    @endphp
                                    <tr>
                                        <td>
                                            <a href="{{ route('compta.operations.show', $op) }}" class="text-decoration-none">
                                                {{ $op->nom }}
                                            </a>
                                        </td>
                                        <td class="small text-nowrap">{{ $op->date_debut?->format('d/m/Y') ?? '—' }}</td>
                                        <td class="small text-nowrap">{{ $op->date_fin?->format('d/m/Y') ?? '—' }}</td>
                                        <td><span class="badge {{ $badge[1] }}">{{ $badge[0] }}</span></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-3">Aucune opération pour cet exercice.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Carte Dernières adhésions --}}
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-person-check"></i> Dernières adhésions
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                <tr>
                                    <th>Date</th>
                                    <th>Adhérent</th>
                                    <th class="text-end">Montant</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse($dernieresAdhesions as $tx)
                                    <tr>
                                        <td class="small text-nowrap">{{ $tx->date->format('d/m/Y') }}</td>
                                        <td class="small">{{ $tx->tiers?->displayName() ?? '—' }}</td>
                                        <td class="small text-end fw-semibold">{{ number_format((float) $tx->montant_total, 2, ',', ' ') }} €</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-3">Aucune adhésion récente.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Carte Derniers dons --}}
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-heart"></i> Derniers dons
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                <tr>
                                    <th>Date</th>
                                    <th>Donateur</th>
                                    <th class="text-end">Montant</th>
                                </tr>
                            </thead>
                            <tbody style="color:#555">
                                @forelse($derniersDons as $don)
                                    <tr>
                                        <td class="small text-nowrap">{{ $don->date->format('d/m/Y') }}</td>
                                        <td class="small">{{ $don->tiers?->displayName() ?? '—' }}</td>
                                        <td class="small text-end fw-semibold">{{ number_format((float) $don->montant_total, 2, ',', ' ') }} €</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-3">Aucun don récent.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 6: Run test**

Run: `./vendor/bin/sail test tests/Feature/GestionDashboardTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/GestionDashboard.php resources/views/livewire/gestion-dashboard.blade.php resources/views/gestion/dashboard.blade.php tests/Feature/GestionDashboardTest.php
git commit -m "feat(espace): add Gestion dashboard with operations, adhesions, and dons cards"
```

---

## Task 7: Migrate Membres → Adhérents

**Files:**
- Create: `app/Livewire/AdherentList.php` (copy and adapt from `MembreList.php`)
- Create: `resources/views/livewire/adherent-list.blade.php` (copy and adapt from `livewire/membre-list.blade.php`)
- Create: `resources/views/gestion/adherents.blade.php`
- Delete: `app/Livewire/MembreList.php` (after creating replacement)
- Delete: `resources/views/livewire/membre-list.blade.php`
- Delete: `resources/views/membres/index.blade.php`
- Test: `tests/Feature/AdherentListTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/AdherentListTest.php
declare(strict_types=1);

use App\Models\User;

test('adherents page loads successfully', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/gestion/adherents')
        ->assertOk()
        ->assertSee('Adhérent');
});

test('legacy /membres redirects to /gestion/adherents', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/membres')
        ->assertRedirect('/gestion/adherents');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/AdherentListTest.php`
Expected: FAIL — view/component not found

- [ ] **Step 3: Create AdherentList component**

Copy `app/Livewire/MembreList.php` to `app/Livewire/AdherentList.php` with these changes:
- Class name: `AdherentList`
- View reference: `return view('livewire.adherent-list', compact('membres'));` (keep `$membres` variable name for now)

- [ ] **Step 4: Create Blade views**

Copy `resources/views/livewire/membre-list.blade.php` to `resources/views/livewire/adherent-list.blade.php` with these changes:
- "Nouvelle cotisation" button href: `route('compta.transactions.index')`
- "Rechercher un membre" placeholder → "Rechercher un adhérent…"
- "Voir les transactions" link: `route('compta.tiers.transactions', $membre->id)`
- "Nouvelle cotisation" action link: `route('compta.transactions.index')`
- "Aucun membre trouvé" → "Aucun adhérent trouvé."

Create wrapper view:
```blade
{{-- resources/views/gestion/adherents.blade.php --}}
<x-app-layout>
    <livewire:adherent-list />
</x-app-layout>
```

- [ ] **Step 5: Delete old MembreList files**

Delete:
- `app/Livewire/MembreList.php`
- `resources/views/livewire/membre-list.blade.php`
- `resources/views/membres/index.blade.php`

- [ ] **Step 6: Run tests**

Run: `./vendor/bin/sail test tests/Feature/AdherentListTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/AdherentList.php resources/views/livewire/adherent-list.blade.php resources/views/gestion/adherents.blade.php tests/Feature/AdherentListTest.php
git rm app/Livewire/MembreList.php resources/views/livewire/membre-list.blade.php resources/views/membres/index.blade.php
git commit -m "feat(espace): migrate Membres to Adhérents in Gestion space"
```

---

## Task 8: Update existing tests for new route names

**Files:**
- Modify: All files in `tests/` that reference old route names

- [ ] **Step 1: Find all test files with old route names**

Run: `grep -r "route('" tests/ --include="*.php" | grep -v 'compta\.\|gestion\.\|login\|logout\|register\|password\|verification\|profil' `

This finds tests still using old unprefixed route names.

- [ ] **Step 2: Update each test file**

Apply the same route name mapping from Task 4. Typical patterns:
- `->get(route('dashboard'))` → `->get(route('compta.dashboard'))`
- `->get('/dashboard')` → `->get('/compta/dashboard')`
- `->post(route('parametres.categories.store'))` → `->post(route('compta.parametres.categories.store'))`

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: ALL PASS. Fix any remaining failures.

- [ ] **Step 4: Commit**

```bash
git add tests/
git commit -m "test: update all test route references for espace prefixes"
```

---

## Task 9: Final verification and cleanup

- [ ] **Step 1: Run Pint for code style**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 2: Run full test suite one last time**

Run: `./vendor/bin/sail test`
Expected: ALL PASS

- [ ] **Step 3: Manual smoke test in browser**

Verify:
1. `http://localhost/` redirects to `/compta/dashboard`
2. Compta dashboard loads with violet navbar (#722281)
3. Switch to Gestion via switcher → blue navbar (#63B2EA)
4. Gestion dashboard shows operations, adhesions, dons cards
5. Navigate to Adhérents in Gestion → list works with filters
6. Switch back to Compta → all menus present, Membres gone
7. Parametres accessible from both spaces
8. Legacy URLs redirect properly (e.g., `/dashboard` → `/compta/dashboard`)
9. Footer color changes with espace (in production env) and stays orange in dev

- [ ] **Step 4: Commit any final fixes**

```bash
git add -A
git commit -m "chore: final cleanup for double espace implementation"
```
