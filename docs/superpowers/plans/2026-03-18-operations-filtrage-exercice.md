# Opérations — Filtrage par exercice Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restreindre la liste des opérations disponibles à celles dont les dates chevauchent l'exercice actif, rendre les dates obligatoires, et ajouter un toggle "tout afficher" sur l'écran paramètres.

**Architecture:** Ajout d'un scope Eloquent `pourExercice()` sur `Operation`, utilisé dans `TransactionForm`, `RapportCompteResultatOperations` et `RapportSeances`. Les dates `date_debut`/`date_fin` passent de nullable à required dans les FormRequests et les vues. L'écran paramètres opérations gagne un toggle `?all=1` via le controller.

**Tech Stack:** Laravel 11, Livewire 4, Eloquent scopes, ExerciceService (session-based), Pest PHP

---

## Fichiers touchés

- Modify: `app/Models/Operation.php` — ajouter scope `pourExercice`
- Modify: `app/Http/Requests/StoreOperationRequest.php` — dates required
- Modify: `app/Http/Requests/UpdateOperationRequest.php` — dates required
- Modify: `resources/views/operations/create.blade.php` — labels * + required
- Modify: `resources/views/operations/edit.blade.php` — labels * + required
- Modify: `app/Livewire/TransactionForm.php` — filtre exercice + statut
- Modify: `app/Livewire/RapportCompteResultatOperations.php` — filtre exercice
- Modify: `app/Livewire/RapportSeances.php` — filtre exercice
- Modify: `app/Http/Controllers/OperationController.php` — toggle ?all=1
- Modify: `resources/views/operations/index.blade.php` — toggle UI
- Create: `tests/Unit/OperationScopeTest.php` — tests du scope pourExercice
- Modify: `tests/Feature/OperationValidationTest.php` (ou créer) — dates required

---

### Task 1 : Scope `Operation::pourExercice()`

**Files:**
- Modify: `app/Models/Operation.php`
- Create: `tests/Unit/OperationScopeTest.php`

Le scope filtre les opérations dont les dates chevauchent l'exercice.
Condition : `date_debut <= fin_exercice AND date_fin >= debut_exercice`
Pour exercice 2025 : `date_debut <= 2026-08-31 AND date_fin >= 2025-09-01`

- [ ] **Step 1 : Écrire les tests échouants**

Créer `tests/Unit/OperationScopeTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Enums\StatutOperation;

// Exercice 2025 : 2025-09-01 → 2026-08-31

it('exclut une opération terminée avant l\'exercice', function () {
    Operation::factory()->create([
        'date_debut' => '2024-09-01',
        'date_fin'   => '2025-08-31', // finit exactement avant l'exercice 2025
        'statut'     => StatutOperation::EnCours,
    ]);

    expect(Operation::pourExercice(2025)->count())->toBe(0);
});

it('inclut une opération qui débute avant et se termine dans l\'exercice', function () {
    Operation::factory()->create([
        'date_debut' => '2025-06-01',
        'date_fin'   => '2025-11-30',
        'statut'     => StatutOperation::EnCours,
    ]);

    expect(Operation::pourExercice(2025)->count())->toBe(1);
});

it('inclut une opération entièrement dans l\'exercice', function () {
    Operation::factory()->create([
        'date_debut' => '2025-10-01',
        'date_fin'   => '2026-03-31',
        'statut'     => StatutOperation::EnCours,
    ]);

    expect(Operation::pourExercice(2025)->count())->toBe(1);
});

it('inclut une opération qui chevauche entièrement l\'exercice', function () {
    Operation::factory()->create([
        'date_debut' => '2025-01-01',
        'date_fin'   => '2027-01-01',
        'statut'     => StatutOperation::EnCours,
    ]);

    expect(Operation::pourExercice(2025)->count())->toBe(1);
});

it('exclut une opération future qui commence après l\'exercice', function () {
    Operation::factory()->create([
        'date_debut' => '2026-09-01',
        'date_fin'   => '2027-08-31',
        'statut'     => StatutOperation::EnCours,
    ]);

    expect(Operation::pourExercice(2025)->count())->toBe(0);
});

it('inclut une opération clôturée si elle chevauche l\'exercice (statut ignoré par le scope)', function () {
    Operation::factory()->create([
        'date_debut' => '2025-10-01',
        'date_fin'   => '2026-03-31',
        'statut'     => StatutOperation::Cloturee,
    ]);

    expect(Operation::pourExercice(2025)->count())->toBe(1);
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail php artisan test tests/Unit/OperationScopeTest.php
```

Expected : FAIL — méthode `pourExercice` inexistante.

- [ ] **Step 3 : Implémenter le scope dans `Operation`**

Dans `app/Models/Operation.php`, ajouter l'import et la méthode après les casts :

```php
use App\Services\ExerciceService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtre les opérations dont les dates chevauchent l'exercice donné.
 * Exercice N : 1er sept N → 31 août N+1.
 *
 * @param  Builder<Operation>  $query
 */
public function scopePourExercice(Builder $query, int $exercice): void
{
    $range = app(ExerciceService::class)->dateRange($exercice);
    $query
        ->whereNotNull('date_debut')
        ->whereNotNull('date_fin')
        ->where('date_debut', '<=', $range['end']->toDateString())
        ->where('date_fin', '>=', $range['start']->toDateString());
}
```

Note : `ExerciceService::dateRange()` retourne `['start' => CarbonImmutable, 'end' => CarbonImmutable]` (tableau associatif, pas de liste).
Accéder via `$range['start']` et `$range['end']`, jamais avec destructuring numérique `[$start, $end]`.

- [ ] **Step 4 : Lancer les tests pour vérifier qu'ils passent**

```bash
./vendor/bin/sail php artisan test tests/Unit/OperationScopeTest.php
```

Expected : 6 tests PASS.

- [ ] **Step 5 : Commit**

```bash
git add app/Models/Operation.php tests/Unit/OperationScopeTest.php
git commit -m "feat: scope Operation::pourExercice() avec chevauchement de dates"
```

---

### Task 2 : Dates obligatoires — validation et vues

**Files:**
- Modify: `app/Http/Requests/StoreOperationRequest.php`
- Modify: `app/Http/Requests/UpdateOperationRequest.php`
- Modify: `resources/views/operations/create.blade.php`
- Modify: `resources/views/operations/edit.blade.php`

- [ ] **Step 1 : Écrire les tests échouants**

Créer ou compléter `tests/Feature/OperationValidationTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('rejette la création sans date_debut', function () {
    $response = $this->post(route('operations.store'), [
        'nom'    => 'Test op',
        'statut' => 'en_cours',
    ]);
    $response->assertSessionHasErrors('date_debut');
});

it('rejette la création sans date_fin', function () {
    $response = $this->post(route('operations.store'), [
        'nom'       => 'Test op',
        'date_debut' => '2025-09-01',
        'statut'    => 'en_cours',
    ]);
    $response->assertSessionHasErrors('date_fin');
});

it('accepte la création avec les deux dates', function () {
    $response = $this->post(route('operations.store'), [
        'nom'        => 'Test op',
        'date_debut' => '2025-09-01',
        'date_fin'   => '2026-03-31',
        'statut'     => 'en_cours',
    ]);
    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
});

it('rejette la modification sans date_debut', function () {
    $op = \App\Models\Operation::factory()->create();
    $response = $this->put(route('operations.update', $op), [
        'nom'    => 'Test op',
        'statut' => 'en_cours',
    ]);
    $response->assertSessionHasErrors('date_debut');
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail php artisan test tests/Feature/OperationValidationTest.php
```

Expected : FAIL — dates encore nullable.

- [ ] **Step 3 : Mettre à jour les FormRequests**

Dans `app/Http/Requests/StoreOperationRequest.php`, remplacer :
```php
'date_debut' => ['nullable', 'date'],
'date_fin'   => ['nullable', 'date', 'after_or_equal:date_debut'],
```
par :
```php
'date_debut' => ['required', 'date'],
'date_fin'   => ['required', 'date', 'after_or_equal:date_debut'],
```

Dans `app/Http/Requests/UpdateOperationRequest.php`, même remplacement.

- [ ] **Step 4 : Mettre à jour les vues**

Dans `resources/views/operations/create.blade.php`, remplacer :
```html
<label for="date_debut" class="form-label">Date début</label>
```
par :
```html
<label for="date_debut" class="form-label">Date début <span class="text-danger">*</span></label>
```

Et ajouter `required` sur l'input `x-date-input` en ajoutant `:required="true"` si supporté, sinon ajouter un champ hidden de fallback. Vérifier comment `x-date-input` gère l'attribut `required`.

Même chose pour `date_fin` dans create et edit.

Dans `resources/views/operations/edit.blade.php`, même modifications sur `date_debut` et `date_fin`.

- [ ] **Step 5 : Lancer les tests**

```bash
./vendor/bin/sail php artisan test tests/Feature/OperationValidationTest.php
```

Expected : 4 tests PASS.

- [ ] **Step 6 : Commit**

```bash
git add app/Http/Requests/StoreOperationRequest.php \
        app/Http/Requests/UpdateOperationRequest.php \
        resources/views/operations/create.blade.php \
        resources/views/operations/edit.blade.php \
        tests/Feature/OperationValidationTest.php
git commit -m "feat: dates début/fin obligatoires sur les opérations"
```

---

### Task 3 : TransactionForm — filtre exercice + statut

**Files:**
- Modify: `app/Livewire/TransactionForm.php` (ligne ~340)

Actuellement : `Operation::where('statut', StatutOperation::EnCours)->orderBy('nom')->get()`

Nouveau : `Operation::pourExercice(ExerciceService::current())->where('statut', StatutOperation::EnCours)->orderBy('nom')->get()`

- [ ] **Step 1 : Écrire le test échouant**

Dans un fichier test existant ou `tests/Livewire/TransactionFormOperationsFilterTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Livewire\TransactionForm;
use App\Models\Operation;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    // Exercice actif : 2025 (2025-09-01 → 2026-08-31)
    session(['exercice_actif' => 2025]);
});

it('n\'affiche pas une opération hors exercice dans le formulaire de transaction', function () {
    // Opération passée (exercice 2024)
    Operation::factory()->create([
        'nom'        => 'Op passée',
        'date_debut' => '2024-09-01',
        'date_fin'   => '2025-08-31',
        'statut'     => StatutOperation::EnCours,
    ]);

    Livewire::test(TransactionForm::class)
        ->assertDontSee('Op passée');
});

it('affiche une opération dans l\'exercice courant', function () {
    Operation::factory()->create([
        'nom'        => 'Op courante',
        'date_debut' => '2025-10-01',
        'date_fin'   => '2026-03-31',
        'statut'     => StatutOperation::EnCours,
    ]);

    Livewire::test(TransactionForm::class)
        ->assertSee('Op courante');
});

it('n\'affiche pas une opération clôturée même dans l\'exercice', function () {
    Operation::factory()->create([
        'nom'        => 'Op clôturée',
        'date_debut' => '2025-10-01',
        'date_fin'   => '2026-03-31',
        'statut'     => StatutOperation::Cloturee,
    ]);

    Livewire::test(TransactionForm::class)
        ->assertDontSee('Op clôturée');
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail php artisan test tests/Livewire/TransactionFormOperationsFilterTest.php
```

- [ ] **Step 3 : Modifier TransactionForm**

Dans `app/Livewire/TransactionForm.php`, à la ligne ~340, remplacer :

```php
'operations' => Operation::where('statut', StatutOperation::EnCours)->orderBy('nom')->get(),
```

par :

```php
'operations' => Operation::pourExercice(app(ExerciceService::class)->current())
    ->where('statut', StatutOperation::EnCours)
    ->orderBy('nom')
    ->get(),
```

`ExerciceService` est déjà importé dans ce fichier (ligne ~15).

- [ ] **Step 4 : Lancer les tests**

```bash
./vendor/bin/sail php artisan test tests/Livewire/TransactionFormOperationsFilterTest.php
```

Expected : 3 tests PASS.

- [ ] **Step 5 : Commit**

```bash
git add app/Livewire/TransactionForm.php tests/Livewire/TransactionFormOperationsFilterTest.php
git commit -m "feat: TransactionForm filtre les opérations par exercice actif"
```

---

### Task 4 : Rapports — filtre exercice sur les opérations listées

**Files:**
- Modify: `app/Livewire/RapportCompteResultatOperations.php` (ligne ~48)
- Modify: `app/Livewire/RapportSeances.php` (ligne ~56)

Actuellement dans `RapportCompteResultatOperations` :
```php
$operations = Operation::orderBy('nom')->get();
```

Actuellement dans `RapportSeances` :
```php
$operations = Operation::whereNotNull('nombre_seances')->...->get();
```

- [ ] **Step 1 : Écrire les tests échouants**

Dans `tests/Livewire/RapportOperationsExerciceTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Livewire\RapportCompteResultatOperations;
use App\Livewire\RapportSeances;
use App\Models\Operation;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    session(['exercice_actif' => 2025]);
});

it('RapportCompteResultatOperations n\'affiche pas les opérations hors exercice', function () {
    Operation::factory()->create([
        'nom'        => 'Op hors exercice',
        'date_debut' => '2024-09-01',
        'date_fin'   => '2025-08-30',
        'statut'     => StatutOperation::EnCours,
    ]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->assertDontSee('Op hors exercice');
});

it('RapportCompteResultatOperations affiche les opérations clôturées dans l\'exercice', function () {
    Operation::factory()->create([
        'nom'        => 'Op clôturée visible',
        'date_debut' => '2025-10-01',
        'date_fin'   => '2026-03-31',
        'statut'     => StatutOperation::Cloturee,
    ]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSee('Op clôturée visible');
});

it('RapportSeances n\'affiche pas les opérations hors exercice', function () {
    Operation::factory()->create([
        'nom'           => 'Op séances hors exercice',
        'date_debut'    => '2024-09-01',
        'date_fin'      => '2025-08-30',
        'nombre_seances' => 3,
        'statut'        => StatutOperation::EnCours,
    ]);

    Livewire::test(RapportSeances::class)
        ->assertDontSee('Op séances hors exercice');
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail php artisan test tests/Livewire/RapportOperationsExerciceTest.php
```

- [ ] **Step 3 : Modifier RapportCompteResultatOperations**

Dans `app/Livewire/RapportCompteResultatOperations.php`, remplacer :

```php
$operations = Operation::orderBy('nom')->get();
```

par :

```php
$operations = Operation::pourExercice($exercice)->orderBy('nom')->get();
```

`$exercice` est déjà calculé juste au-dessus (ligne ~47).

- [ ] **Step 4 : Modifier RapportSeances**

Dans `app/Livewire/RapportSeances.php`, remplacer :

```php
$operations = Operation::whereNotNull('nombre_seances')
```

par :

```php
$exercice = app(ExerciceService::class)->current();
$operations = Operation::pourExercice($exercice)->whereNotNull('nombre_seances')
```

Vérifier que `ExerciceService` est importé (`use App\Services\ExerciceService;`), sinon l'ajouter.

- [ ] **Step 5 : Lancer les tests**

```bash
./vendor/bin/sail php artisan test tests/Livewire/RapportOperationsExerciceTest.php
```

Expected : 3 tests PASS.

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/RapportCompteResultatOperations.php \
        app/Livewire/RapportSeances.php \
        tests/Livewire/RapportOperationsExerciceTest.php
git commit -m "feat: rapports filtrent les opérations par exercice actif"
```

---

### Task 5 : Écran paramètres opérations — toggle ?all=1

**Files:**
- Modify: `app/Http/Controllers/OperationController.php`
- Modify: `resources/views/operations/index.blade.php`

- [ ] **Step 1 : Écrire le test échouant**

Dans `tests/Feature/OperationIndexToggleTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Models\Operation;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    session(['exercice_actif' => 2025]);
});

it('masque par défaut les opérations hors exercice', function () {
    Operation::factory()->create([
        'nom'        => 'Op passée',
        'date_debut' => '2024-09-01',
        'date_fin'   => '2025-08-30',
        'statut'     => StatutOperation::EnCours,
    ]);

    $this->get(route('operations.index'))
        ->assertDontSee('Op passée');
});

it('affiche toutes les opérations avec ?all=1', function () {
    Operation::factory()->create([
        'nom'        => 'Op passée',
        'date_debut' => '2024-09-01',
        'date_fin'   => '2025-08-30',
        'statut'     => StatutOperation::EnCours,
    ]);

    $this->get(route('operations.index', ['all' => 1]))
        ->assertSee('Op passée');
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail php artisan test tests/Feature/OperationIndexToggleTest.php
```

- [ ] **Step 3 : Modifier OperationController::index()**

Dans `app/Http/Controllers/OperationController.php`, modifier la méthode `index()` :

```php
public function index(Request $request): View
{
    $showAll = $request->boolean('all');
    $exercice = app(\App\Services\ExerciceService::class)->current();

    $operations = $showAll
        ? Operation::orderByDesc('date_debut')->get()
        : Operation::pourExercice($exercice)->orderByDesc('date_debut')->get();

    return view('operations.index', [
        'operations' => $operations,
        'showAll'    => $showAll,
        'exercice'   => $exercice,
    ]);
}
```

Ajouter l'import `use Illuminate\Http\Request;` si absent.

- [ ] **Step 4 : Modifier la vue operations/index.blade.php**

Dans `resources/views/operations/index.blade.php`, dans le div d'en-tête (juste avant la table), ajouter le toggle après le bouton "Ajouter une opération" :

```html
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Opérations</h1>
    <div class="d-flex gap-2 align-items-center">
        @if ($showAll)
            <a href="{{ route('operations.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-funnel"></i> Exercice {{ $exercice }}-{{ $exercice + 1 }} seulement
            </a>
        @else
            <a href="{{ route('operations.index', ['all' => 1]) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-list-ul"></i> Toutes les opérations
            </a>
        @endif
        <a href="{{ route('operations.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Ajouter une opération
        </a>
    </div>
</div>
```

- [ ] **Step 5 : Lancer les tests**

```bash
./vendor/bin/sail php artisan test tests/Feature/OperationIndexToggleTest.php
```

Expected : 2 tests PASS.

- [ ] **Step 6 : Lancer la suite complète**

```bash
./vendor/bin/sail php artisan test
```

Expected : aucun nouveau échec.

- [ ] **Step 7 : Commit**

```bash
git add app/Http/Controllers/OperationController.php \
        resources/views/operations/index.blade.php \
        tests/Feature/OperationIndexToggleTest.php
git commit -m "feat: toggle exercice/tout sur l'écran liste des opérations"
```
