# Dons & Cotisations — Sous-catégorie Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter `sous_categorie_id` (NOT NULL) sur `dons` et `cotisations`, avec flags `pour_dons`/`pour_cotisations` sur `sous_categories`, pour débloquer les rapports et la saisie typée.

**Architecture:** Flag sur `sous_categories` → sélecteurs filtrés dans les formulaires → agrégation dans `RapportService::compteDeResultat()`. Pas de nouveau modèle, pas de nouvelle table.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Pest PHP, MySQL

**Spec:** `docs/superpowers/specs/2026-03-16-dons-cotisations-sous-categorie-design.md`

---

## Chunk 1 : Migration & Seeder

### Task 1 : Migration

**Files:**
- Create: `database/migrations/2026_03_16_000001_add_sous_categorie_flags_and_fks.php`

- [ ] **Step 1 : Écrire la migration**

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
        Schema::table('sous_categories', function (Blueprint $table): void {
            $table->boolean('pour_dons')->default(false)->after('code_cerfa');
            $table->boolean('pour_cotisations')->default(false)->after('pour_dons');
        });

        Schema::table('dons', function (Blueprint $table): void {
            $table->foreignId('sous_categorie_id')
                ->after('tiers_id')
                ->constrained('sous_categories');
        });

        Schema::table('cotisations', function (Blueprint $table): void {
            $table->foreignId('sous_categorie_id')
                ->after('tiers_id')
                ->constrained('sous_categories');
        });
    }

    public function down(): void
    {
        Schema::table('cotisations', function (Blueprint $table): void {
            $table->dropForeign(['sous_categorie_id']);
            $table->dropColumn('sous_categorie_id');
        });

        Schema::table('dons', function (Blueprint $table): void {
            $table->dropForeign(['sous_categorie_id']);
            $table->dropColumn('sous_categorie_id');
        });

        Schema::table('sous_categories', function (Blueprint $table): void {
            $table->dropColumn(['pour_dons', 'pour_cotisations']);
        });
    }
};
```

- [ ] **Step 2 : Vérifier que la migration passe**

```bash
./vendor/bin/sail artisan migrate
```
Attendu : migration exécutée sans erreur.

### Task 2 : Seeder

**Files:**
- Modify: `database/seeders/CategoriesSeeder.php`

- [ ] **Step 3 : Mettre à jour le seeder — ajouter les flags**

Dans `CategoriesSeeder.php`, ajouter `pour_cotisations` et `pour_dons` dans les tableaux `sous` correspondants :

```php
// 75 - Cotisations et dons
'sous' => [
    ['nom' => 'Cotisations', 'code_cerfa' => '751', 'pour_cotisations' => true],
    ['nom' => 'Dons manuels', 'code_cerfa' => '754', 'pour_dons' => true],
    ['nom' => 'Mécénat',      'code_cerfa' => '756', 'pour_dons' => true],
],
// 77 - Produits exceptionnels
'sous' => [
    ['nom' => 'Abandon de créance', 'code_cerfa' => '771', 'pour_dons' => true],
],
```

Toutes les autres sous-catégories gardent les flags à `false` par défaut (ne pas les ajouter explicitement).

- [ ] **Step 4 : Vérifier le seeder en environnement de dev**

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```
Attendu : pas d'erreur, les sous-catégories sont recréées avec les bons flags.

- [ ] **Step 5 : Commit**

```bash
git add database/migrations/2026_03_16_000001_add_sous_categorie_flags_and_fks.php
git add database/seeders/CategoriesSeeder.php
git commit -m "feat: migration + seeder pour_dons/pour_cotisations + sous_categorie_id sur dons et cotisations"
```

---

## Chunk 2 : Modèles

### Task 3 : SousCategorie

**Files:**
- Modify: `app/Models/SousCategorie.php`

- [ ] **Step 1 : Ajouter les deux flags dans `$fillable` et `casts()`**

Dans `$fillable`, ajouter après `'code_cerfa'` :
```php
'pour_dons',
'pour_cotisations',
```

Dans `casts()`, ajouter :
```php
'pour_dons' => 'boolean',
'pour_cotisations' => 'boolean',
```

### Task 4 : Don

**Files:**
- Modify: `app/Models/Don.php`

- [ ] **Step 2 : Ajouter `sous_categorie_id` et la relation**

Dans `$fillable`, ajouter `'sous_categorie_id'` après `'tiers_id'`.

Dans `casts()`, ajouter :
```php
'sous_categorie_id' => 'integer',
```

Ajouter la relation :
```php
public function sousCategorie(): BelongsTo
{
    return $this->belongsTo(SousCategorie::class);
}
```

### Task 5 : Cotisation

**Files:**
- Modify: `app/Models/Cotisation.php`

- [ ] **Step 3 : Mêmes ajouts que Don**

Dans `$fillable`, ajouter `'sous_categorie_id'` après `'tiers_id'`.

Dans `casts()`, ajouter :
```php
'sous_categorie_id' => 'integer',
```

Ajouter la relation :
```php
public function sousCategorie(): BelongsTo
{
    return $this->belongsTo(SousCategorie::class);
}
```

- [ ] **Step 4 : Commit**

```bash
git add app/Models/SousCategorie.php app/Models/Don.php app/Models/Cotisation.php
git commit -m "feat: ajout sous_categorie_id et flags pour_dons/pour_cotisations sur les modèles"
```

---

## Chunk 3 : Formulaire Don

### Task 6 : DonForm — PHP

**Files:**
- Modify: `app/Livewire/DonForm.php`

- [ ] **Step 1 : Ajouter la propriété et la logique**

Ajouter la propriété publique :
```php
public ?int $sous_categorie_id = null;
```

Dans `showNewForm()`, après la date, ajouter le défaut :
```php
$this->sous_categorie_id = Don::where('saisi_par', auth()->id())
    ->latest()
    ->value('sous_categorie_id');
```

Dans `edit(int $id)`, ajouter :
```php
$this->sous_categorie_id = $don->sous_categorie_id;
```

Dans `resetForm()`, ajouter `'sous_categorie_id'` dans le tableau passé à `$this->reset()`.

Dans `save()`, ajouter dans `$rules` :
```php
'sous_categorie_id' => ['required', 'exists:sous_categories,id'],
```

Dans le tableau `$data`, ajouter :
```php
'sous_categorie_id' => $this->sous_categorie_id,
```

Dans `render()`, passer les options au template :
```php
'naturesdon' => \App\Models\SousCategorie::where('pour_dons', true)->orderBy('nom')->get(),
```

### Task 7 : DonForm — Vue

**Files:**
- Modify: `resources/views/livewire/don-form.blade.php`

- [ ] **Step 2 : Ajouter le sélecteur "Nature du don"**

Dans la première ligne de la grille (`row g-3 mb-3`), ajouter un `col-md-3` avec :
```html
<div class="col-md-3">
    <label for="sous_categorie_id" class="form-label">Nature du don <span class="text-danger">*</span></label>
    <select wire:model="sous_categorie_id" id="sous_categorie_id"
            class="form-select @error('sous_categorie_id') is-invalid @enderror">
        <option value="">-- Choisir --</option>
        @foreach ($naturesdon as $sc)
            <option value="{{ $sc->id }}">{{ $sc->nom }}</option>
        @endforeach
    </select>
    @error('sous_categorie_id')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
```

- [ ] **Step 3 : Tester manuellement**

Ouvrir le formulaire de nouveau don :
- Le sélecteur "Nature du don" est présent et affiche les 3 options (Dons manuels, Mécénat, Abandon de créance)
- La validation bloque si aucun choix
- La valeur est pré-remplie sur le 2e don (valeur du dernier don saisi)
- L'édition charge la valeur existante

- [ ] **Step 4 : Commit**

```bash
git add app/Livewire/DonForm.php resources/views/livewire/don-form.blade.php
git commit -m "feat: sélecteur Nature du don (sous_categorie_id) dans DonForm"
```

---

## Chunk 4 : Liste Don

### Task 8 : DonList — PHP + Vue

**Files:**
- Modify: `app/Livewire/DonList.php`
- Modify: `resources/views/livewire/don-list.blade.php`

- [ ] **Step 1 : Ajouter le filtre dans DonList**

Ajouter la propriété :
```php
public ?int $sous_categorie_id = null;
```

Ajouter la méthode de reset :
```php
public function updatedSousCategorieId(): void
{
    $this->resetPage();
}
```

Dans `render()`, ajouter le filtre à la requête :
```php
if ($this->sous_categorie_id) {
    $query->where('sous_categorie_id', $this->sous_categorie_id);
}
```

Passer les options au template (eager load la relation aussi) :
```php
// Changer with(['tiers', 'operation', 'compte']) en :
->with(['tiers', 'operation', 'compte', 'sousCategorie'])
// Passer au template :
'naturesdon' => \App\Models\SousCategorie::where('pour_dons', true)->orderBy('nom')->get(),
```

- [ ] **Step 2 : Mettre à jour la vue don-list**

Lire d'abord `resources/views/livewire/don-list.blade.php` pour voir la structure existante.

Ajouter dans la zone de filtres un select "Nature du don" :
```html
<select wire:model.live="sous_categorie_id" class="form-select form-select-sm" style="width:auto">
    <option value="">— Toutes les natures —</option>
    @foreach ($naturesdon as $sc)
        <option value="{{ $sc->id }}">{{ $sc->nom }}</option>
    @endforeach
</select>
```

Ajouter la colonne dans le `<thead>` et afficher `$don->sousCategorie->nom ?? '—'` dans le `<tbody>`.

- [ ] **Step 3 : Commit**

```bash
git add app/Livewire/DonList.php resources/views/livewire/don-list.blade.php
git commit -m "feat: colonne et filtre Nature du don dans DonList"
```

---

## Chunk 5 : Formulaire & Liste Cotisation

### Task 9 : CotisationForm — PHP

**Files:**
- Modify: `app/Livewire/CotisationForm.php`

- [ ] **Step 1 : Ajouter la propriété et la logique**

Ajouter la propriété :
```php
public ?int $sous_categorie_id = null;
```

Dans `showNewForm()` et `openForTiers()`, ajouter le défaut (juste avant `$this->showForm = true`) :
```php
$this->sous_categorie_id = \App\Models\Cotisation::where('exercice', app(\App\Services\ExerciceService::class)->current())
    ->latest()
    ->value('sous_categorie_id');
```

Dans `resetForm()`, ajouter `'sous_categorie_id'` au tableau de reset.

Dans `save()`, ajouter dans les règles de validation :
```php
'sous_categorie_id' => ['required', 'exists:sous_categories,id'],
```

Ajouter dans `$data` :
```php
'sous_categorie_id' => $validated['sous_categorie_id'],
```

Dans `render()`, passer :
```php
'postescomptables' => \App\Models\SousCategorie::where('pour_cotisations', true)->orderBy('nom')->get(),
```

### Task 10 : CotisationForm — Vue

**Files:**
- Modify: `resources/views/livewire/cotisation-form.blade.php`

- [ ] **Step 2 : Ajouter le sélecteur "Poste comptable"**

Lire le fichier avant modification. Ajouter dans le formulaire :
```html
<div class="col-md-3">
    <label for="sous_categorie_id" class="form-label">Poste comptable <span class="text-danger">*</span></label>
    <select wire:model="sous_categorie_id" id="sous_categorie_id"
            class="form-select @error('sous_categorie_id') is-invalid @enderror">
        <option value="">-- Choisir --</option>
        @foreach ($postescomptables as $sc)
            <option value="{{ $sc->id }}">{{ $sc->nom }}</option>
        @endforeach
    </select>
    @error('sous_categorie_id')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
```

### Task 11 : CotisationList — PHP + Vue

**Files:**
- Modify: `app/Livewire/CotisationList.php`
- Modify: `resources/views/livewire/cotisation-list.blade.php`

- [ ] **Step 3 : Ajouter filtre + colonne**

Dans `CotisationList`, ajouter :
```php
public ?int $sous_categorie_id = null;

public function updatedSousCategorieId(): void
{
    $this->resetPage();
}
```

Modifier la requête dans `render()` :
```php
// Changer with(['tiers', 'compte']) en :
->with(['tiers', 'compte', 'sousCategorie'])
// Ajouter le filtre :
if ($this->sous_categorie_id) {
    $query->where('sous_categorie_id', $this->sous_categorie_id);
}
// Passer au template :
'postescomptables' => \App\Models\SousCategorie::where('pour_cotisations', true)->orderBy('nom')->get(),
```

Lire `cotisation-list.blade.php` puis ajouter le select filtre et la colonne "Poste comptable".

- [ ] **Step 4 : Commit**

```bash
git add app/Livewire/CotisationForm.php resources/views/livewire/cotisation-form.blade.php
git add app/Livewire/CotisationList.php resources/views/livewire/cotisation-list.blade.php
git commit -m "feat: poste comptable sur CotisationForm et CotisationList"
```

---

## Chunk 6 : Écran Sous-catégories

### Task 12 : Toggles pour_dons / pour_cotisations

**Files:**
- Modify: `app/Http/Controllers/SousCategorieController.php`
- Modify: `app/Http/Requests/UpdateSousCategorieRequest.php`
- Modify: `resources/views/parametres/sous-categories/index.blade.php`
- Modify: `routes/web.php`

- [ ] **Step 1 : Ajouter la route de toggle**

Dans `routes/web.php`, dans le groupe paramètres, ajouter :
```php
Route::patch('sous-categories/{sousCategory}/toggle-flag', [SousCategorieController::class, 'toggleFlag'])
    ->name('parametres.sous-categories.toggle-flag');
```

- [ ] **Step 2 : Ajouter la méthode `toggleFlag` dans le contrôleur**

```php
public function toggleFlag(Request $request, SousCategorie $sousCategory): RedirectResponse
{
    $flag = $request->input('flag');
    if (!in_array($flag, ['pour_dons', 'pour_cotisations'], true)) {
        abort(422);
    }

    $sousCategory->update([$flag => !$sousCategory->{$flag}]);

    return redirect()->route('parametres.sous-categories.index')
        ->with('success', 'Sous-catégorie mise à jour.');
}
```

- [ ] **Step 3 : Mettre à jour la vue**

Ajouter deux colonnes dans le `<thead>` :
```html
<th class="text-center">Dons</th>
<th class="text-center">Cotisations</th>
```

Dans le `<tbody>`, pour chaque ligne `$sc`, ajouter les deux cellules avec un mini-formulaire toggle :
```html
<td class="text-center">
    <form action="{{ route('parametres.sous-categories.toggle-flag', $sc) }}" method="POST">
        @csrf @method('PATCH')
        <input type="hidden" name="flag" value="pour_dons">
        <button type="submit" class="btn btn-sm {{ $sc->pour_dons ? 'btn-success' : 'btn-outline-secondary' }}"
                title="{{ $sc->pour_dons ? 'Désactiver pour les dons' : 'Activer pour les dons' }}">
            <i class="bi bi-{{ $sc->pour_dons ? 'check-circle-fill' : 'circle' }}"></i>
        </button>
    </form>
</td>
<td class="text-center">
    <form action="{{ route('parametres.sous-categories.toggle-flag', $sc) }}" method="POST">
        @csrf @method('PATCH')
        <input type="hidden" name="flag" value="pour_cotisations">
        <button type="submit" class="btn btn-sm {{ $sc->pour_cotisations ? 'btn-success' : 'btn-outline-secondary' }}"
                title="{{ $sc->pour_cotisations ? 'Désactiver pour les cotisations' : 'Activer pour les cotisations' }}">
            <i class="bi bi-{{ $sc->pour_cotisations ? 'check-circle-fill' : 'circle' }}"></i>
        </button>
    </form>
</td>
```

Ajuster `colspan` du `@empty` de 4 à 6.

- [ ] **Step 4 : Commit**

```bash
git add app/Http/Controllers/SousCategorieController.php routes/web.php
git add resources/views/parametres/sous-categories/index.blade.php
git commit -m "feat: toggles pour_dons et pour_cotisations dans l'écran sous-catégories"
```

---

## Chunk 7 : Rapports

### Task 13 : RapportService — inclusion dons & cotisations

**Files:**
- Modify: `app/Services/RapportService.php`

- [ ] **Step 1 : Ajouter les imports nécessaires**

En haut du fichier, ajouter :
```php
use App\Models\Cotisation;
use App\Models\Don;
```

- [ ] **Step 2 : Modifier `compteDeResultat()`**

Après la construction du tableau `$produits` (recette_lignes), ajouter les dons :

```php
// Produits depuis les dons
$donsQuery = Don::query()
    ->join('sous_categories', 'dons.sous_categorie_id', '=', 'sous_categories.id')
    ->whereNull('dons.deleted_at')
    ->whereBetween('dons.date', [$startDate, $endDate]);

if ($operationIds) {
    $donsQuery->whereIn('dons.operation_id', $operationIds);
}

$donsProduits = $donsQuery
    ->select(
        'sous_categories.code_cerfa',
        'sous_categories.nom as label',
        DB::raw('SUM(dons.montant) as montant')
    )
    ->groupBy('sous_categories.id', 'sous_categories.code_cerfa', 'sous_categories.nom')
    ->get()
    ->map(fn ($row) => [
        'code_cerfa' => $row->code_cerfa,
        'label'      => $row->label,
        'montant'    => (float) $row->montant,
    ])
    ->toArray();

// Produits depuis les cotisations
$cotisationsQuery = Cotisation::query()
    ->join('sous_categories', 'cotisations.sous_categorie_id', '=', 'sous_categories.id')
    ->whereNull('cotisations.deleted_at')
    ->where('cotisations.exercice', $exercice);

$cotisationsProduits = $cotisationsQuery
    ->select(
        'sous_categories.code_cerfa',
        'sous_categories.nom as label',
        DB::raw('SUM(cotisations.montant) as montant')
    )
    ->groupBy('sous_categories.id', 'sous_categories.code_cerfa', 'sous_categories.nom')
    ->get()
    ->map(fn ($row) => [
        'code_cerfa' => $row->code_cerfa,
        'label'      => $row->label,
        'montant'    => (float) $row->montant,
    ])
    ->toArray();
```

Fusionner et agréger les trois sources avant le `return` :

```php
// Fusionner produits recettes + dons + cotisations par code_cerfa+label
$allProduits = array_merge($produits, $donsProduits, $cotisationsProduits);
$mergedProduits = [];
foreach ($allProduits as $item) {
    $key = ($item['code_cerfa'] ?? '') . '|' . $item['label'];
    if (!isset($mergedProduits[$key])) {
        $mergedProduits[$key] = $item;
    } else {
        $mergedProduits[$key]['montant'] = round($mergedProduits[$key]['montant'] + $item['montant'], 2);
    }
}
usort($mergedProduits, fn ($a, $b) => strcmp(($a['code_cerfa'] ?? '') . $a['label'], ($b['code_cerfa'] ?? '') . $b['label']));

return ['charges' => $charges, 'produits' => array_values($mergedProduits)];
```

- [ ] **Step 3 : Vérifier manuellement**

Ouvrir le rapport compte de résultat. Les dons et cotisations saisis doivent apparaître dans les produits sous leurs codes CERFA respectifs.

- [ ] **Step 4 : Commit**

```bash
git add app/Services/RapportService.php
git commit -m "feat: dons et cotisations inclus dans le compte de résultat"
```

---

## Chunk 8 : Tests

### Task 14 : Tests

**Files:**
- Modify: `tests/Feature/DonTest.php` (si existant, sinon créer)
- Modify: `tests/Feature/CotisationTest.php` (si existant)

- [ ] **Step 1 : Vérifier les tests existants**

```bash
./vendor/bin/sail artisan test --filter=Don
./vendor/bin/sail artisan test --filter=Cotisation
```

- [ ] **Step 2 : Ajouter les tests manquants pour DonForm**

Dans le test feature Don, ajouter un test vérifiant que la création d'un don sans `sous_categorie_id` échoue la validation, et qu'avec une valeur valide elle réussit. Se référer aux tests existants de DepenseTest ou RecetteTest comme modèle de structure.

```php
it('requiert une sous_categorie pour créer un don', function () {
    // ...
});

it('crée un don avec une nature valide', function () {
    // ...
});
```

- [ ] **Step 3 : Lancer tous les tests**

```bash
./vendor/bin/sail artisan test
```
Attendu : tous les tests passent (aucune régression).

- [ ] **Step 4 : Commit final**

```bash
git add tests/
git commit -m "test: validation sous_categorie_id sur dons et cotisations"
git push
```
