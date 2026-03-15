# Renommage tiers — `payeur` → `tiers` (recettes) & `beneficiaire` → `tiers` (dépenses)

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename `recettes.payeur` → `recettes.tiers` and `depenses.beneficiaire` → `depenses.tiers` throughout the application via two migrations, then update models, Livewire components, Blade views, and factories to use the unified `tiers` field name.

**Architecture:** Two `renameColumn` migrations (one per table); update `$fillable` arrays on both models; rename the property in both Livewire components and update every reference (`save()`, `edit()`, `showNewForm()`, `resetForm()`); update Blade labels, `id` attributes, and `wire:model` bindings; update factories; write schema-assertion tests before writing migrations (strict TDD).

**Tech Stack:** Laravel 11, Pest PHP, Bootstrap 5 (CDN), Blade, Livewire 4

---

## Task 1: Migrations — colonnes renommées

**Files:**
- Create: `database/migrations/2026_03_13_100000_rename_payeur_to_tiers_on_recettes.php`
- Create: `database/migrations/2026_03_13_100001_rename_beneficiaire_to_tiers_on_depenses.php`
- Create: `tests/Feature/TiersRenommageTest.php`

- [ ] **Step 1 : Écrire les tests (RED)**

Créer `tests/Feature/TiersRenommageTest.php` :

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('recettes table has tiers column', function () {
    expect(Schema::hasColumn('recettes', 'tiers'))->toBeTrue();
    expect(Schema::hasColumn('recettes', 'payeur'))->toBeFalse();
});

it('depenses table has tiers column', function () {
    expect(Schema::hasColumn('depenses', 'tiers'))->toBeTrue();
    expect(Schema::hasColumn('depenses', 'beneficiaire'))->toBeFalse();
});
```

Run pour confirmer FAIL :
```bash
./vendor/bin/sail artisan test --filter TiersRenommageTest
```

- [ ] **Step 2 : Créer la première migration**

Créer `database/migrations/2026_03_13_100000_rename_payeur_to_tiers_on_recettes.php` :

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
        Schema::table('recettes', function (Blueprint $table) {
            $table->renameColumn('payeur', 'tiers');
        });
    }

    public function down(): void
    {
        Schema::table('recettes', function (Blueprint $table) {
            $table->renameColumn('tiers', 'payeur');
        });
    }
};
```

- [ ] **Step 3 : Créer la deuxième migration**

Créer `database/migrations/2026_03_13_100001_rename_beneficiaire_to_tiers_on_depenses.php` :

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
        Schema::table('depenses', function (Blueprint $table) {
            $table->renameColumn('beneficiaire', 'tiers');
        });
    }

    public function down(): void
    {
        Schema::table('depenses', function (Blueprint $table) {
            $table->renameColumn('tiers', 'beneficiaire');
        });
    }
};
```

- [ ] **Step 4 : Appliquer les migrations et vérifier GREEN**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan test --filter TiersRenommageTest
```

Les deux tests doivent passer.

- [ ] **Step 5 : Commit**

```bash
git add database/migrations/2026_03_13_100000_rename_payeur_to_tiers_on_recettes.php \
        database/migrations/2026_03_13_100001_rename_beneficiaire_to_tiers_on_depenses.php \
        tests/Feature/TiersRenommageTest.php
git commit -m "test+migrate: rename payeur→tiers (recettes) and beneficiaire→tiers (depenses)"
```

---

## Task 2: Modèles Eloquent

**Files:**
- Modify: `app/Models/Recette.php`
- Modify: `app/Models/Depense.php`

- [ ] **Step 1 : Mettre à jour `$fillable` de `Recette`**

Dans `app/Models/Recette.php`, remplacer `'payeur'` par `'tiers'` dans le tableau `$fillable`.

Avant :
```php
protected $fillable = [
    'date', 'libelle', 'montant_total', 'mode_paiement',
    'payeur', 'reference', 'compte_id', 'pointe', 'notes',
    'saisi_par', 'rapprochement_id',
];
```

Après :
```php
protected $fillable = [
    'date', 'libelle', 'montant_total', 'mode_paiement',
    'tiers', 'reference', 'compte_id', 'pointe', 'notes',
    'saisi_par', 'rapprochement_id',
];
```

- [ ] **Step 2 : Mettre à jour `$fillable` de `Depense`**

Dans `app/Models/Depense.php`, remplacer `'beneficiaire'` par `'tiers'` dans le tableau `$fillable`.

Avant :
```php
protected $fillable = [
    'date', 'libelle', 'montant_total', 'mode_paiement',
    'beneficiaire', 'reference', 'compte_id', 'pointe', 'notes',
    'saisi_par', 'rapprochement_id',
];
```

Après :
```php
protected $fillable = [
    'date', 'libelle', 'montant_total', 'mode_paiement',
    'tiers', 'reference', 'compte_id', 'pointe', 'notes',
    'saisi_par', 'rapprochement_id',
];
```

- [ ] **Step 3 : Run tests**

```bash
./vendor/bin/sail artisan test
```

Tous les tests doivent être verts.

---

## Task 3: Factories

**Files:**
- Modify: `database/factories/RecetteFactory.php`
- Modify: `database/factories/DepenseFactory.php`

- [ ] **Step 1 : Mettre à jour `RecetteFactory`**

Dans `database/factories/RecetteFactory.php`, méthode `definition()`, remplacer :
```php
'payeur' => fake()->optional()->company(),
```
par :
```php
'tiers' => fake()->optional()->company(),
```

- [ ] **Step 2 : Mettre à jour `DepenseFactory`**

Dans `database/factories/DepenseFactory.php`, méthode `definition()`, remplacer :
```php
'beneficiaire' => fake()->optional()->company(),
```
par :
```php
'tiers' => fake()->optional()->company(),
```

- [ ] **Step 3 : Run tests**

```bash
./vendor/bin/sail artisan test
```

---

## Task 4: Composants Livewire

**Files:**
- Modify: `app/Livewire/RecetteForm.php`
- Modify: `app/Livewire/DepenseForm.php`
- Modify: `app/Livewire/RecetteList.php`
- Modify: `app/Livewire/DepenseList.php`
- Modify: `tests/Livewire/RecetteFormTest.php`
- Modify: `tests/Livewire/DepenseFormTest.php`
- Modify: `tests/Livewire/RecetteListTest.php`
- Modify: `tests/Livewire/DepenseListTest.php`

- [ ] **Step 0 : Mettre à jour les tests Livewire (RED)**

Dans `tests/Livewire/RecetteFormTest.php`, remplacer les deux occurrences de `payeur` :
```php
// ligne ~71
->set('payeur', 'M. Dupont')
// → remplacer par :
->set('tiers', 'M. Dupont')

// ligne ~97 (assertDatabaseHas)
'payeur' => 'M. Dupont',
// → remplacer par :
'tiers' => 'M. Dupont',
```

Dans `tests/Livewire/DepenseFormTest.php`, remplacer les deux occurrences de `beneficiaire` :
```php
// ligne ~71
->set('beneficiaire', 'Fournisseur XYZ')
// → remplacer par :
->set('tiers', 'Fournisseur XYZ')

// ligne ~97 (assertDatabaseHas)
'beneficiaire' => 'Fournisseur XYZ',
// → remplacer par :
'tiers' => 'Fournisseur XYZ',
```

Dans `tests/Livewire/RecetteListTest.php`, remplacer toutes les occurrences de `payeur` :
```php
// nom du test
it('filters recettes by payeur', function () {
// → remplacer par :
it('filters recettes by tiers', function () {

// factory calls (2 occurrences)
'payeur' => 'Gamma SARL',
'payeur' => 'Delta Inc',
// → remplacer par :
'tiers' => 'Gamma SARL',
'tiers' => 'Delta Inc',

// ->set()
->set('payeur', 'Gamma')
// → remplacer par :
->set('tiers', 'Gamma')
```

Dans `tests/Livewire/DepenseListTest.php`, remplacer toutes les occurrences de `beneficiaire` :
```php
// nom du test
it('filters depenses by beneficiaire', function () {
// → remplacer par :
it('filters depenses by tiers', function () {

// factory calls (2 occurrences)
'beneficiaire' => 'Alpha Corp',
'beneficiaire' => 'Beta SA',
// → remplacer par :
'tiers' => 'Alpha Corp',
'tiers' => 'Beta SA',

// ->set()
->set('beneficiaire', 'Alpha')
// → remplacer par :
->set('tiers', 'Alpha')
```

Run pour confirmer FAIL :
```bash
./vendor/bin/sail artisan test --filter "RecetteFormTest|DepenseFormTest|RecetteListTest|DepenseListTest"
```

Les tests qui utilisent `payeur`/`beneficiaire` doivent échouer.

- [ ] **Step 1 : Mettre à jour `RecetteForm`**

Dans `app/Livewire/RecetteForm.php` :

1. Renommer la propriété publique :
   ```php
   // Avant
   public ?string $payeur = null;
   // Après
   public ?string $tiers = null;
   ```

2. Dans `showNewForm()`, remplacer `'payeur'` par `'tiers'` dans la liste passée à `reset()` :
   ```php
   $this->reset(['recetteId', 'date', 'libelle', 'mode_paiement',
       'tiers', 'reference', 'compte_id', 'notes', 'lignes']);
   ```

3. Dans `edit()`, remplacer :
   ```php
   $this->payeur = $recette->payeur;
   ```
   par :
   ```php
   $this->tiers = $recette->tiers;
   ```

4. Dans `resetForm()`, remplacer `'payeur'` par `'tiers'` dans la liste passée à `reset()` :
   ```php
   $this->reset([
       'recetteId', 'date', 'libelle', 'mode_paiement',
       'tiers', 'reference', 'compte_id', 'notes', 'lignes', 'showForm', 'isLocked',
   ]);
   ```

5. Dans `save()`, dans le tableau `$data`, remplacer :
   ```php
   'payeur' => $this->payeur ?: null,
   ```
   par :
   ```php
   'tiers' => $this->tiers ?: null,
   ```

- [ ] **Step 2 : Mettre à jour `DepenseForm`**

Dans `app/Livewire/DepenseForm.php` :

1. Renommer la propriété publique :
   ```php
   // Avant
   public ?string $beneficiaire = null;
   // Après
   public ?string $tiers = null;
   ```

2. Dans `showNewForm()`, remplacer `'beneficiaire'` par `'tiers'` dans la liste passée à `reset()` :
   ```php
   $this->reset(['depenseId', 'date', 'libelle', 'mode_paiement',
       'tiers', 'reference', 'compte_id', 'notes', 'lignes']);
   ```

3. Dans `edit()`, remplacer :
   ```php
   $this->beneficiaire = $depense->beneficiaire;
   ```
   par :
   ```php
   $this->tiers = $depense->tiers;
   ```

4. Dans `resetForm()`, remplacer `'beneficiaire'` par `'tiers'` dans la liste passée à `reset()` :
   ```php
   $this->reset([
       'depenseId', 'date', 'libelle', 'mode_paiement',
       'tiers', 'reference', 'compte_id', 'notes', 'lignes', 'showForm', 'isLocked',
   ]);
   ```

5. Dans `save()`, dans le tableau `$data`, remplacer :
   ```php
   'beneficiaire' => $this->beneficiaire ?: null,
   ```
   par :
   ```php
   'tiers' => $this->tiers ?: null,
   ```

- [ ] **Step 3 : Mettre à jour `RecetteList`**

Dans `app/Livewire/RecetteList.php` :

1. Renommer la propriété publique :
   ```php
   // Avant
   public ?string $payeur = null;
   // Après
   public ?string $tiers = null;
   ```

2. Dans la méthode de construction de la requête, remplacer :
   ```php
   if ($this->payeur) {
       $query->where('payeur', 'like', '%'.$this->payeur.'%');
   ```
   par :
   ```php
   if ($this->tiers) {
       $query->where('tiers', 'like', '%'.$this->tiers.'%');
   ```

- [ ] **Step 4 : Mettre à jour `DepenseList`**

Dans `app/Livewire/DepenseList.php` :

1. Renommer la propriété publique :
   ```php
   // Avant
   public ?string $beneficiaire = null;
   // Après
   public ?string $tiers = null;
   ```

2. Dans la méthode de construction de la requête, remplacer :
   ```php
   if ($this->beneficiaire) {
       $query->where('beneficiaire', 'like', '%'.$this->beneficiaire.'%');
   ```
   par :
   ```php
   if ($this->tiers) {
       $query->where('tiers', 'like', '%'.$this->tiers.'%');
   ```

- [ ] **Step 5 : Run tests**

```bash
./vendor/bin/sail artisan test
```

Tous les tests doivent être verts.

---

## Task 5: Vues Blade

**Files:**
- Modify: `resources/views/livewire/recette-form.blade.php`
- Modify: `resources/views/livewire/depense-form.blade.php`
- Modify: `resources/views/livewire/recette-list.blade.php`
- Modify: `resources/views/livewire/depense-list.blade.php`

- [ ] **Step 1 : Mettre à jour `recette-form.blade.php`**

Localiser le bloc :
```blade
<div class="col-md-2">
    <label for="payeur" class="form-label">Payeur</label>
    <input type="text" wire:model="payeur" id="payeur" class="form-control">
</div>
```

Remplacer par :
```blade
<div class="col-md-2">
    <label for="tiers" class="form-label">Tiers</label>
    <input type="text" wire:model="tiers" id="tiers" class="form-control">
</div>
```

- [ ] **Step 2 : Mettre à jour `depense-form.blade.php`**

Localiser le bloc :
```blade
<div class="col-md-2">
    <label for="beneficiaire" class="form-label">Bénéficiaire</label>
    <input type="text" wire:model="beneficiaire" id="beneficiaire" class="form-control">
</div>
```

Remplacer par :
```blade
<div class="col-md-2">
    <label for="tiers" class="form-label">Tiers</label>
    <input type="text" wire:model="tiers" id="tiers" class="form-control">
</div>
```

- [ ] **Step 3 : Mettre à jour `recette-list.blade.php`**

Trois occurrences à remplacer :

```blade
{{-- Filtre label --}}
<label for="filter-payeur" class="form-label">Payeur</label>
{{-- → remplacer par : --}}
<label for="filter-tiers" class="form-label">Tiers</label>

{{-- Filtre input (wire:model + id) --}}
<input type="text" wire:model.live.debounce.300ms="payeur"
       id="filter-payeur"
{{-- → remplacer par : --}}
<input type="text" wire:model.live.debounce.300ms="tiers"
       id="filter-tiers"

{{-- Cellule du tableau --}}
<td>{{ $recette->payeur ?? '—' }}</td>
{{-- → remplacer par : --}}
<td>{{ $recette->tiers ?? '—' }}</td>
```

- [ ] **Step 4 : Mettre à jour `depense-list.blade.php`**

Trois occurrences à remplacer :

```blade
{{-- Filtre label --}}
<label for="filter-beneficiaire" class="form-label">Bénéficiaire</label>
{{-- → remplacer par : --}}
<label for="filter-tiers" class="form-label">Tiers</label>

{{-- Filtre input (wire:model + id) --}}
<input type="text" wire:model.live.debounce.300ms="beneficiaire"
       id="filter-beneficiaire"
{{-- → remplacer par : --}}
<input type="text" wire:model.live.debounce.300ms="tiers"
       id="filter-tiers"

{{-- Cellule du tableau --}}
<td>{{ $depense->beneficiaire ?? '—' }}</td>
{{-- → remplacer par : --}}
<td>{{ $depense->tiers ?? '—' }}</td>
```

- [ ] **Step 5 : Run pint et tests finaux**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint \
    app/Models/Recette.php \
    app/Models/Depense.php \
    app/Livewire/RecetteForm.php \
    app/Livewire/DepenseForm.php \
    app/Livewire/RecetteList.php \
    app/Livewire/DepenseList.php \
    database/factories/RecetteFactory.php \
    database/factories/DepenseFactory.php

./vendor/bin/sail artisan test
```

- [ ] **Step 6 : Commit final**

```bash
git add app/Models/Recette.php \
        app/Models/Depense.php \
        app/Livewire/RecetteForm.php \
        app/Livewire/DepenseForm.php \
        app/Livewire/RecetteList.php \
        app/Livewire/DepenseList.php \
        resources/views/livewire/recette-form.blade.php \
        resources/views/livewire/depense-form.blade.php \
        resources/views/livewire/recette-list.blade.php \
        resources/views/livewire/depense-list.blade.php \
        database/factories/RecetteFactory.php \
        database/factories/DepenseFactory.php \
        tests/Livewire/RecetteFormTest.php \
        tests/Livewire/DepenseFormTest.php \
        tests/Livewire/RecetteListTest.php \
        tests/Livewire/DepenseListTest.php
git commit -m "feat: rename payeur/beneficiaire to tiers in models, Livewire components, views, factories, and tests"
```
```

---

## Plan 2: `docs/superpowers/plans/2026-03-13-transactions-par-compte.md`

```markdown