# Paramètres Sous-menus Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transformer la page paramètres à onglets en 4 pages indépendantes accessibles via un dropdown navbar.

**Architecture:** Les controllers existants (Categorie, SousCategorie, CompteBancaire) ont déjà des méthodes `index()` qui redirigent vers `parametres.index` — on les remplace par de vraies vues. On ajoute `index()` à `UserController`. Les vues sont extraites des onglets Bootstrap actuels. La navbar passe de lien simple à dropdown.

**Tech Stack:** Laravel 11, Bootstrap 5 (CDN), Blade, Pest PHP

---

## Fichiers modifiés/créés

| Action | Fichier | Rôle |
|---|---|---|
| Modify | `routes/web.php` | Ajouter index à utilisateurs, supprimer route parametres.index |
| Delete | `app/Http/Controllers/ParametreController.php` | Plus nécessaire |
| Modify | `app/Http/Controllers/CategorieController.php` | Remplacer index() + redirections |
| Modify | `app/Http/Controllers/SousCategorieController.php` | Remplacer index() + redirections |
| Modify | `app/Http/Controllers/CompteBancaireController.php` | Remplacer index() + redirections, supprimer activeTab |
| Modify | `app/Http/Controllers/UserController.php` | Ajouter index() + corriger redirections |
| Create | `resources/views/parametres/categories/index.blade.php` | Page catégories |
| Create | `resources/views/parametres/sous-categories/index.blade.php` | Page sous-catégories |
| Create | `resources/views/parametres/comptes-bancaires/index.blade.php` | Page comptes bancaires |
| Create | `resources/views/parametres/utilisateurs/index.blade.php` | Page utilisateurs |
| Delete | `resources/views/parametres/index.blade.php` | Remplacée par les pages ci-dessus |
| Modify | `resources/views/layouts/app.blade.php` | Dropdown navbar |
| Create | `tests/Feature/Http/ParametresNavigationTest.php` | Tests HTTP routes |

---

## Chunk 1: Routes, controllers et tests

### Task 1: Tests HTTP et routes

**Files:**
- Create: `tests/Feature/Http/ParametresNavigationTest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Créer le fichier de tests**

```php
<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('GET /parametres/categories retourne 200', function () {
    $response = $this->get('/parametres/categories');
    $response->assertStatus(200);
});

test('GET /parametres/sous-categories retourne 200', function () {
    $response = $this->get('/parametres/sous-categories');
    $response->assertStatus(200);
});

test('GET /parametres/comptes-bancaires retourne 200', function () {
    $response = $this->get('/parametres/comptes-bancaires');
    $response->assertStatus(200);
});

test('GET /parametres/utilisateurs retourne 200', function () {
    $response = $this->get('/parametres/utilisateurs');
    $response->assertStatus(200);
});

test('GET /parametres redirige ou retourne 404 (route supprimée)', function () {
    $response = $this->get('/parametres');
    $response->assertStatus(404);
});

test('POST /parametres/categories redirige vers /parametres/categories', function () {
    $response = $this->post('/parametres/categories', [
        'nom' => 'Test Categorie',
        'type' => 'depense',
    ]);
    $response->assertRedirect('/parametres/categories');
});

test('POST /parametres/comptes-bancaires redirige vers /parametres/comptes-bancaires', function () {
    $response = $this->post('/parametres/comptes-bancaires', [
        'nom' => 'Compte Test',
        'solde_initial' => 0,
        'date_solde_initial' => '2025-09-01',
    ]);
    $response->assertRedirect('/parametres/comptes-bancaires');
});

test('POST /parametres/utilisateurs redirige vers /parametres/utilisateurs', function () {
    $response = $this->post('/parametres/utilisateurs', [
        'nom' => 'Test User',
        'email' => 'testuser@example.com',
        'password' => 'password123!',
        'password_confirmation' => 'password123!',
    ]);
    $response->assertRedirect('/parametres/utilisateurs');
});
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

```bash
cd /Users/jurgen/Library/CloudStorage/SynologyDrive-home/dev/svs-accounting && ./vendor/bin/sail artisan test --filter=ParametresNavigationTest
```
Attendu : FAIL (routes/vues n'existent pas encore)

- [ ] **Step 3: Modifier `routes/web.php`**

Supprimer la ligne :
```php
Route::get('/parametres', [ParametreController::class, 'index'])->name('parametres.index');
```

Supprimer aussi l'import `use App\Http\Controllers\ParametreController;` s'il existe.

Remplacer dans le groupe `parametres` :
```php
Route::resource('utilisateurs', UserController::class)->only(['store', 'update', 'destroy']);
```
par :
```php
Route::resource('utilisateurs', UserController::class)->only(['index', 'store', 'update', 'destroy']);
```

- [ ] **Step 4: Supprimer `ParametreController.php`**

```bash
rm /Users/jurgen/Library/CloudStorage/SynologyDrive-home/dev/svs-accounting/app/Http/Controllers/ParametreController.php
```

- [ ] **Step 5: Vérifier que les routes sont correctes**

```bash
cd /Users/jurgen/Library/CloudStorage/SynologyDrive-home/dev/svs-accounting && ./vendor/bin/sail artisan route:list --path=parametres
```
Attendu : voir `parametres.categories.index`, `parametres.sous-categories.index`, `parametres.comptes-bancaires.index`, `parametres.utilisateurs.index`

---

### Task 2: CategorieController — index() et redirections

**Files:**
- Modify: `app/Http/Controllers/CategorieController.php`
- Create: `resources/views/parametres/categories/index.blade.php`

- [ ] **Step 1: Remplacer `index()` dans `CategorieController`**

Remplacer :
```php
public function index(): RedirectResponse
{
    return redirect()->route('parametres.index');
}
```

par :
```php
public function index(): \Illuminate\View\View
{
    return view('parametres.categories.index', [
        'categories' => \App\Models\Categorie::with('sousCategories')->orderBy('nom')->get(),
    ]);
}
```

Ajouter l'import `use Illuminate\View\View;` et supprimer `use Illuminate\Http\RedirectResponse;` si elle n'est plus utilisée (vérifier que store/update/destroy l'utilisent encore — oui, garder l'import).

- [ ] **Step 2: Mettre à jour toutes les redirections vers `parametres.categories.index`**

Dans `store()`, `update()`, `destroy()` : remplacer `parametres.index` par `parametres.categories.index`.

Résultat final de `CategorieController.php` :
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategorieRequest;
use App\Http\Requests\UpdateCategorieRequest;
use App\Models\Categorie;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class CategorieController extends Controller
{
    public function index(): View
    {
        return view('parametres.categories.index', [
            'categories' => Categorie::with('sousCategories')->orderBy('nom')->get(),
        ]);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('parametres.categories.index');
    }

    public function store(StoreCategorieRequest $request): RedirectResponse
    {
        Categorie::create($request->validated());

        return redirect()->route('parametres.categories.index')
            ->with('success', 'Catégorie créée avec succès.');
    }

    public function edit(Categorie $category): RedirectResponse
    {
        return redirect()->route('parametres.categories.index');
    }

    public function update(UpdateCategorieRequest $request, Categorie $category): RedirectResponse
    {
        $category->update($request->validated());

        return redirect()->route('parametres.categories.index')
            ->with('success', 'Catégorie mise à jour avec succès.');
    }

    public function destroy(Categorie $category): RedirectResponse
    {
        try {
            $category->delete();

            return redirect()->route('parametres.categories.index')
                ->with('success', 'Catégorie supprimée avec succès.');
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return redirect()->route('parametres.categories.index')
                    ->with('error', 'Suppression impossible : cet élément est utilisé dans les données de l\'application.');
            }
            throw $e;
        }
    }
}
```

- [ ] **Step 3: Créer la vue `resources/views/parametres/categories/index.blade.php`**

Extraire le contenu du div `#categories-pane` de `resources/views/parametres/index.blade.php` (lignes 46-143), l'encapsuler dans `<x-app-layout>` avec un titre. Supprimer les attributs de tab pane (`class="tab-pane fade show active"`, `id="categories-pane"`, `role="tabpanel"`, etc.) :

```blade
<x-app-layout>
    <h1 class="mb-4">Catégories</h1>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="mb-3 d-flex gap-2 align-items-center flex-wrap">
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse"
                data-bs-target="#addCategorieForm">
            <i class="bi bi-plus-lg"></i> Ajouter une catégorie
        </button>
        <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Filtre catégories">
            <input type="radio" class="btn-check" name="catFilter" id="catAll" value="all" checked autocomplete="off">
            <label class="btn btn-outline-secondary" for="catAll">Tout</label>
            <input type="radio" class="btn-check" name="catFilter" id="catRecette" value="recette" autocomplete="off">
            <label class="btn btn-outline-secondary" for="catRecette">Recettes</label>
            <input type="radio" class="btn-check" name="catFilter" id="catDepense" value="depense" autocomplete="off">
            <label class="btn btn-outline-secondary" for="catDepense">Dépenses</label>
        </div>
    </div>

    <div class="collapse mb-3" id="addCategorieForm">
        <div class="card card-body">
            <form action="{{ route('parametres.categories.store') }}" method="POST" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-5">
                    <label for="cat_nom" class="form-label">Nom</label>
                    <input type="text" name="nom" id="cat_nom" class="form-control" required maxlength="100">
                </div>
                <div class="col-md-4">
                    <label for="cat_type" class="form-label">Type</label>
                    <select name="type" id="cat_type" class="form-select" required>
                        <option value="">-- Choisir --</option>
                        @foreach (\App\Enums\TypeCategorie::cases() as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-success w-100">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <table class="table table-striped table-hover">
        <thead class="table-dark">
            <tr>
                <th>Nom</th>
                <th>Type</th>
                <th>Sous-catégories</th>
                <th style="width: 180px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($categories as $categorie)
                <tr data-type="{{ $categorie->type->value }}">
                    <td>{{ $categorie->nom }}</td>
                    <td>
                        <span class="badge {{ $categorie->type === \App\Enums\TypeCategorie::Depense ? 'bg-danger' : 'bg-success' }}">
                            {{ $categorie->type->label() }}
                        </span>
                    </td>
                    <td>{{ $categorie->sousCategories->count() }}</td>
                    <td>
                        <form action="{{ route('parametres.categories.update', $categorie) }}" method="POST" class="d-inline">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="nom" value="{{ $categorie->nom }}">
                            <input type="hidden" name="type" value="{{ $categorie->type->value }}">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    onclick="editCategorie(this, {{ $categorie->id }}, '{{ addslashes($categorie->nom) }}', '{{ $categorie->type->value }}')">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </form>
                        <form action="{{ route('parametres.categories.destroy', $categorie) }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Supprimer cette catégorie ?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-muted">Aucune catégorie enregistrée.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <script>
    document.querySelectorAll('input[name="catFilter"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            const val = this.value;
            document.querySelectorAll('tr[data-type]').forEach(function(row) {
                row.style.display = (val === 'all' || row.dataset.type === val) ? '' : 'none';
            });
        });
    });

    function editCategorie(btn, id, nom, type) {
        const newNom = prompt('Nom de la catégorie :', nom);
        if (newNom === null) return;
        const form = btn.closest('form');
        form.querySelector('input[name="nom"]').value = newNom;
        form.submit();
    }
    </script>
</x-app-layout>
```

- [ ] **Step 4: Lancer les tests**

```bash
cd /Users/jurgen/Library/CloudStorage/SynologyDrive-home/dev/svs-accounting && ./vendor/bin/sail artisan test --filter=ParametresNavigationTest
```
Attendu : le test `GET /parametres/categories retourne 200` passe maintenant.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/CategorieController.php \
        resources/views/parametres/categories/index.blade.php \
        tests/Feature/Http/ParametresNavigationTest.php \
        routes/web.php
git commit -m "feat: page catégories indépendante sous /parametres/categories"
```

---

### Task 3: SousCategorieController — index() et redirections

**Files:**
- Modify: `app/Http/Controllers/SousCategorieController.php`
- Create: `resources/views/parametres/sous-categories/index.blade.php`

- [ ] **Step 1: Remplacer `index()` et mettre à jour les redirections**

Résultat final de `SousCategorieController.php` :
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreSousCategorieRequest;
use App\Http\Requests\UpdateSousCategorieRequest;
use App\Models\Categorie;
use App\Models\SousCategorie;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class SousCategorieController extends Controller
{
    public function index(): View
    {
        return view('parametres.sous-categories.index', [
            'categories' => Categorie::with('sousCategories')->orderBy('nom')->get(),
        ]);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('parametres.sous-categories.index');
    }

    public function store(StoreSousCategorieRequest $request): RedirectResponse
    {
        SousCategorie::create($request->validated());

        return redirect()->route('parametres.sous-categories.index')
            ->with('success', 'Sous-catégorie créée avec succès.');
    }

    public function edit(SousCategorie $sousCategory): RedirectResponse
    {
        return redirect()->route('parametres.sous-categories.index');
    }

    public function update(UpdateSousCategorieRequest $request, SousCategorie $sousCategory): RedirectResponse
    {
        $sousCategory->update($request->validated());

        return redirect()->route('parametres.sous-categories.index')
            ->with('success', 'Sous-catégorie mise à jour avec succès.');
    }

    public function destroy(SousCategorie $sousCategory): RedirectResponse
    {
        try {
            $sousCategory->delete();

            return redirect()->route('parametres.sous-categories.index')
                ->with('success', 'Sous-catégorie supprimée avec succès.');
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return redirect()->route('parametres.sous-categories.index')
                    ->with('error', 'Suppression impossible : cet élément est utilisé dans les données de l\'application.');
            }
            throw $e;
        }
    }
}
```

- [ ] **Step 2: Créer la vue `resources/views/parametres/sous-categories/index.blade.php`**

Extraire le contenu du div `#sous-categories-pane` de `resources/views/parametres/index.blade.php` (lignes 146-248), encapsuler dans `<x-app-layout>`, retirer les attributs de tab pane. Ajouter les flash messages success/error comme pour catégories. Le sélecteur JS doit cibler `tr[data-type]` au lieu de `#sous-categories-pane tr[data-type]`.

```blade
<x-app-layout>
    <h1 class="mb-4">Sous-catégories</h1>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse"
                data-bs-target="#addSousCategorieForm">
            <i class="bi bi-plus-lg"></i> Ajouter une sous-catégorie
        </button>
        <div class="btn-group btn-group-sm" role="group" aria-label="Filtre type">
            <input type="radio" class="btn-check" name="scTypeFilter" id="scAll" value="all" checked autocomplete="off">
            <label class="btn btn-outline-secondary" for="scAll">Tout</label>
            <input type="radio" class="btn-check" name="scTypeFilter" id="scRecette" value="recette" autocomplete="off">
            <label class="btn btn-outline-secondary" for="scRecette">Recettes</label>
            <input type="radio" class="btn-check" name="scTypeFilter" id="scDepense" value="depense" autocomplete="off">
            <label class="btn btn-outline-secondary" for="scDepense">Dépenses</label>
        </div>
        <select id="scCatFilter" class="form-select form-select-sm" style="width:auto;">
            <option value="">— Toutes les catégories —</option>
            @foreach ($categories as $cat)
                <option value="{{ $cat->id }}">{{ $cat->nom }}</option>
            @endforeach
        </select>
    </div>

    <div class="collapse mb-3" id="addSousCategorieForm">
        <div class="card card-body">
            <form action="{{ route('parametres.sous-categories.store') }}" method="POST" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-4">
                    <label for="sc_categorie" class="form-label">Catégorie</label>
                    <select name="categorie_id" id="sc_categorie" class="form-select" required>
                        <option value="">-- Choisir --</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->nom }} ({{ $cat->type->label() }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="sc_nom" class="form-label">Nom</label>
                    <input type="text" name="nom" id="sc_nom" class="form-control" required maxlength="100">
                </div>
                <div class="col-md-2">
                    <label for="sc_cerfa" class="form-label">Code CERFA</label>
                    <input type="text" name="code_cerfa" id="sc_cerfa" class="form-control" maxlength="10">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success w-100">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <table class="table table-striped table-hover">
        <thead class="table-dark">
            <tr>
                <th>Catégorie</th>
                <th>Nom</th>
                <th>Code CERFA</th>
                <th style="width: 180px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @php
                $sousCategories = $categories->flatMap->sousCategories->sortBy('nom');
            @endphp
            @forelse ($sousCategories as $sc)
                <tr data-type="{{ $sc->categorie->type->value }}" data-categorie="{{ $sc->categorie_id }}">
                    <td>{{ $sc->categorie->nom }}</td>
                    <td>{{ $sc->nom }}</td>
                    <td>{{ $sc->code_cerfa ?? '—' }}</td>
                    <td>
                        <form action="{{ route('parametres.sous-categories.destroy', $sc) }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Supprimer cette sous-catégorie ?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-muted">Aucune sous-catégorie enregistrée.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <script>
    function filterSousCategories() {
        var typeVal = document.querySelector('input[name="scTypeFilter"]:checked').value;
        var catVal = document.getElementById('scCatFilter').value;
        document.querySelectorAll('tr[data-type]').forEach(function(row) {
            var typeOk = typeVal === 'all' || row.dataset.type === typeVal;
            var catOk = catVal === '' || row.dataset.categorie === catVal;
            row.style.display = (typeOk && catOk) ? '' : 'none';
        });
    }
    document.querySelectorAll('input[name="scTypeFilter"]').forEach(function(r) {
        r.addEventListener('change', filterSousCategories);
    });
    var scCatFilter = document.getElementById('scCatFilter');
    if (scCatFilter) { scCatFilter.addEventListener('change', filterSousCategories); }
    </script>
</x-app-layout>
```

- [ ] **Step 3: Lancer les tests**

```bash
cd /Users/jurgen/Library/CloudStorage/SynologyDrive-home/dev/svs-accounting && ./vendor/bin/sail artisan test --filter=ParametresNavigationTest
```
Attendu : `GET /parametres/sous-categories retourne 200` passe maintenant.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/SousCategorieController.php \
        resources/views/parametres/sous-categories/index.blade.php
git commit -m "feat: page sous-catégories indépendante sous /parametres/sous-categories"
```

---

### Task 4: CompteBancaireController — index() et redirections

**Files:**
- Modify: `app/Http/Controllers/CompteBancaireController.php`
- Create: `resources/views/parametres/comptes-bancaires/index.blade.php`

- [ ] **Step 1: Remplacer `index()`, mettre à jour redirections, supprimer `activeTab`**

Résultat final de `CompteBancaireController.php` :
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompteBancaireRequest;
use App\Http\Requests\UpdateCompteBancaireRequest;
use App\Models\CompteBancaire;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class CompteBancaireController extends Controller
{
    public function index(): View
    {
        return view('parametres.comptes-bancaires.index', [
            'comptesBancaires' => CompteBancaire::orderBy('nom')->get(),
        ]);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('parametres.comptes-bancaires.index');
    }

    public function store(StoreCompteBancaireRequest $request): RedirectResponse
    {
        CompteBancaire::create($request->validated());

        return redirect()->route('parametres.comptes-bancaires.index')
            ->with('success', 'Compte bancaire créé avec succès.');
    }

    public function edit(CompteBancaire $comptesBancaire): RedirectResponse
    {
        return redirect()->route('parametres.comptes-bancaires.index');
    }

    public function update(UpdateCompteBancaireRequest $request, CompteBancaire $comptesBancaire): RedirectResponse
    {
        $comptesBancaire->update($request->validated());

        return redirect()->route('parametres.comptes-bancaires.index')
            ->with('success', 'Compte bancaire mis à jour avec succès.');
    }

    public function destroy(CompteBancaire $comptesBancaire): RedirectResponse
    {
        try {
            $comptesBancaire->delete();

            return redirect()->route('parametres.comptes-bancaires.index')
                ->with('success', 'Compte bancaire supprimé avec succès.');
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return redirect()->route('parametres.comptes-bancaires.index')
                    ->with('error', 'Suppression impossible : cet élément est utilisé dans les données de l\'application.');
            }
            throw $e;
        }
    }
}
```

- [ ] **Step 2: Créer la vue `resources/views/parametres/comptes-bancaires/index.blade.php`**

Extraire le contenu du div `#comptes-pane` de `parametres/index.blade.php` (lignes 251-321), encapsuler dans `<x-app-layout>`, retirer les attributs de tab pane, ajouter les flash messages :

```blade
<x-app-layout>
    <h1 class="mb-4">Comptes bancaires</h1>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="mb-3">
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse"
                data-bs-target="#addCompteForm">
            <i class="bi bi-plus-lg"></i> Ajouter un compte bancaire
        </button>
    </div>

    <div class="collapse mb-3" id="addCompteForm">
        <div class="card card-body">
            <form action="{{ route('parametres.comptes-bancaires.store') }}" method="POST" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label for="cb_nom" class="form-label">Nom</label>
                    <input type="text" name="nom" id="cb_nom" class="form-control" required maxlength="150">
                </div>
                <div class="col-md-3">
                    <label for="cb_iban" class="form-label">IBAN</label>
                    <input type="text" name="iban" id="cb_iban" class="form-control" maxlength="34">
                </div>
                <div class="col-md-2">
                    <label for="cb_solde" class="form-label">Solde initial</label>
                    <input type="number" name="solde_initial" id="cb_solde" class="form-control" required step="0.01">
                </div>
                <div class="col-md-2">
                    <label for="cb_date" class="form-label">Date solde</label>
                    <input type="date" name="date_solde_initial" id="cb_date" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success w-100">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <table class="table table-striped table-hover">
        <thead class="table-dark">
            <tr>
                <th>Nom</th>
                <th>IBAN</th>
                <th>Solde initial</th>
                <th>Date solde</th>
                <th style="width: 180px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($comptesBancaires as $compte)
                <tr>
                    <td>{{ $compte->nom }}</td>
                    <td>{{ $compte->iban ?? '—' }}</td>
                    <td>{{ number_format((float) $compte->solde_initial, 2, ',', ' ') }} &euro;</td>
                    <td>{{ $compte->date_solde_initial->format('d/m/Y') }}</td>
                    <td>
                        <form action="{{ route('parametres.comptes-bancaires.destroy', $compte) }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Supprimer ce compte bancaire ?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-muted">Aucun compte bancaire enregistré.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</x-app-layout>
```

- [ ] **Step 3: Lancer les tests**

```bash
cd /Users/jurgen/Library/CloudStorage/SynologyDrive-home/dev/svs-accounting && ./vendor/bin/sail artisan test --filter=ParametresNavigationTest
```
Attendu : `GET /parametres/comptes-bancaires retourne 200` et `POST /parametres/comptes-bancaires redirige` passent.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/CompteBancaireController.php \
        resources/views/parametres/comptes-bancaires/index.blade.php
git commit -m "feat: page comptes bancaires indépendante sous /parametres/comptes-bancaires"
```

---

### Task 5: UserController — index() et redirections

**Files:**
- Modify: `app/Http/Controllers/UserController.php`
- Create: `resources/views/parametres/utilisateurs/index.blade.php`

- [ ] **Step 1: Ajouter `index()` et mettre à jour les redirections**

Résultat final de `UserController.php` :
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

final class UserController extends Controller
{
    public function index(): View
    {
        return view('parametres.utilisateurs.index', [
            'utilisateurs' => User::orderBy('nom')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        User::create([
            'nom' => $validated['nom'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        return redirect()->route('parametres.utilisateurs.index')
            ->with('success', 'Utilisateur créé.');
    }

    public function update(Request $request, User $utilisateur): RedirectResponse
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', "unique:users,email,{$utilisateur->id}"],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        $utilisateur->nom = $validated['nom'];
        $utilisateur->email = $validated['email'];

        if (! empty($validated['password'])) {
            $utilisateur->password = $validated['password'];
        }

        $utilisateur->save();

        return redirect()->route('parametres.utilisateurs.index')
            ->with('success', 'Utilisateur mis à jour.');
    }

    public function destroy(User $utilisateur): RedirectResponse
    {
        if ($utilisateur->id === auth()->id()) {
            return redirect()->route('parametres.utilisateurs.index')
                ->with('error', 'Vous ne pouvez pas supprimer votre propre compte.');
        }

        $utilisateur->delete();

        return redirect()->route('parametres.utilisateurs.index')
            ->with('success', 'Utilisateur supprimé.');
    }
}
```

- [ ] **Step 2: Créer la vue `resources/views/parametres/utilisateurs/index.blade.php`**

Extraire le contenu du div `#utilisateurs-pane` de `parametres/index.blade.php` (lignes 324-440), encapsuler dans `<x-app-layout>`, retirer les attributs de tab pane, ajouter les flash messages. Le script JS `#addUserForm` doit s'ouvrir auto en cas d'erreur de validation :

```blade
<x-app-layout>
    <h1 class="mb-4">Utilisateurs</h1>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="mb-3">
        <button class="btn btn-primary btn-sm" type="button"
                data-bs-toggle="collapse" data-bs-target="#addUserForm">
            <i class="bi bi-plus-lg"></i> Ajouter un utilisateur
        </button>
    </div>
    <div class="collapse mb-3" id="addUserForm">
        <div class="card card-body">
            <form action="{{ route('parametres.utilisateurs.store') }}" method="POST" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-3">
                    <label class="form-label">Nom</label>
                    <input type="text" name="nom" class="form-control @error('nom') is-invalid @enderror"
                           value="{{ old('nom') }}" required maxlength="100">
                    @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                           value="{{ old('email') }}" required maxlength="150">
                    @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-2">
                    <label class="form-label">Mot de passe</label>
                    <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                           required>
                    @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-2">
                    <label class="form-label">Confirmer</label>
                    <input type="password" name="password_confirmation" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success w-100">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>

    <table class="table table-sm table-striped table-hover">
        <thead class="table-dark">
            <tr><th>Nom</th><th>Email</th><th style="width:100px;"></th></tr>
        </thead>
        <tbody>
            @forelse ($utilisateurs as $utilisateur)
                <tr>
                    <td>{{ $utilisateur->nom }}</td>
                    <td>{{ $utilisateur->email }}</td>
                    <td>
                        <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#editUser{{ $utilisateur->id }}"
                                    title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </button>
                            @if ($utilisateur->id !== auth()->id())
                                <form method="POST"
                                      action="{{ route('parametres.utilisateurs.destroy', $utilisateur) }}"
                                      onsubmit="return confirm('Supprimer cet utilisateur ?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
                <tr class="collapse" id="editUser{{ $utilisateur->id }}">
                    <td colspan="3" class="bg-light">
                        <form action="{{ route('parametres.utilisateurs.update', $utilisateur) }}"
                              method="POST" class="row g-2 align-items-end p-2">
                            @csrf @method('PUT')
                            <div class="col-md-3">
                                <label class="form-label">Nom</label>
                                <input type="text" name="nom" class="form-control"
                                       value="{{ $utilisateur->nom }}" required maxlength="100">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control"
                                       value="{{ $utilisateur->email }}" required maxlength="150">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Nouveau mdp <span class="text-muted">(opt.)</span></label>
                                <input type="password" name="password" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Confirmer</label>
                                <input type="password" name="password_confirmation" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-success w-100">Mettre à jour</button>
                            </div>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-muted">Aucun utilisateur.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($errors->hasAny(['nom', 'email', 'password']))
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var el = document.getElementById('addUserForm');
            if (el) { new bootstrap.Collapse(el, { toggle: false }).show(); }
        });
    </script>
    @endif
</x-app-layout>
```

- [ ] **Step 3: Lancer les tests**

```bash
cd /Users/jurgen/Library/CloudStorage/SynologyDrive-home/dev/svs-accounting && ./vendor/bin/sail artisan test --filter=ParametresNavigationTest
```
Attendu : `GET /parametres/utilisateurs retourne 200` et `POST /parametres/utilisateurs redirige` passent.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/UserController.php \
        resources/views/parametres/utilisateurs/index.blade.php
git commit -m "feat: page utilisateurs indépendante sous /parametres/utilisateurs"
```

---

## Chunk 2: Navbar et nettoyage

### Task 6: Dropdown navbar + suppression ancienne page

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Delete: `resources/views/parametres/index.blade.php`

- [ ] **Step 1: Modifier `resources/views/layouts/app.blade.php`**

Lire le fichier d'abord. La navbar actuelle a un tableau `$navItems` avec une entrée `parametres.index` et une boucle `@foreach`. Il faut :
1. Supprimer l'entrée `['route' => 'parametres.index', ...]` du tableau `$navItems`
2. Après la boucle `@foreach ($navItems as $item)`, ajouter le dropdown Paramètres

La structure finale de la section navbar :

```blade
<ul class="navbar-nav me-auto">
    @php
        $navItems = [
            ['route' => 'depenses.index',      'icon' => 'arrow-down-circle',      'label' => 'Dépenses'],
            ['route' => 'recettes.index',      'icon' => 'arrow-up-circle',        'label' => 'Recettes'],
            ['route' => 'virements.index',     'icon' => 'arrow-left-right',       'label' => 'Virements'],
            ['route' => 'budget.index',        'icon' => 'piggy-bank',             'label' => 'Budget'],
            ['route' => 'rapprochement.index', 'icon' => 'bank',                   'label' => 'Rapprochement'],
            ['route' => 'membres.index',       'icon' => 'people',                 'label' => 'Membres'],
            ['route' => 'dons.index',          'icon' => 'heart',                  'label' => 'Dons'],
            ['route' => 'rapports.index',      'icon' => 'file-earmark-bar-graph', 'label' => 'Rapports'],
        ];
    @endphp
    @foreach ($navItems as $item)
        @if (Route::has($item['route']))
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs(str_replace('.index', '.*', $item['route'])) ? 'active' : '' }}"
                   href="{{ route($item['route']) }}">
                    <i class="bi bi-{{ $item['icon'] }}"></i> {{ $item['label'] }}
                </a>
            </li>
        @endif
    @endforeach

    {{-- Dropdown Paramètres --}}
    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle {{ request()->routeIs('parametres.*') || request()->routeIs('operations.*') ? 'active' : '' }}"
           href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-gear"></i> Paramètres
        </a>
        <ul class="dropdown-menu">
            <li>
                <a class="dropdown-item {{ request()->routeIs('parametres.categories.*') ? 'active' : '' }}"
                   href="{{ route('parametres.categories.index') }}">
                    <i class="bi bi-tags"></i> Catégories
                </a>
            </li>
            <li>
                <a class="dropdown-item {{ request()->routeIs('parametres.sous-categories.*') ? 'active' : '' }}"
                   href="{{ route('parametres.sous-categories.index') }}">
                    <i class="bi bi-tag"></i> Sous-catégories
                </a>
            </li>
            <li>
                <a class="dropdown-item {{ request()->routeIs('operations.*') ? 'active' : '' }}"
                   href="{{ route('operations.index') }}">
                    <i class="bi bi-calendar-event"></i> Opérations
                </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <a class="dropdown-item {{ request()->routeIs('parametres.comptes-bancaires.*') ? 'active' : '' }}"
                   href="{{ route('parametres.comptes-bancaires.index') }}">
                    <i class="bi bi-bank"></i> Comptes bancaires
                </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <a class="dropdown-item {{ request()->routeIs('parametres.utilisateurs.*') ? 'active' : '' }}"
                   href="{{ route('parametres.utilisateurs.index') }}">
                    <i class="bi bi-people"></i> Utilisateurs
                </a>
            </li>
        </ul>
    </li>
</ul>
```

- [ ] **Step 2: Supprimer l'ancienne vue**

```bash
rm /Users/jurgen/Library/CloudStorage/SynologyDrive-home/dev/svs-accounting/resources/views/parametres/index.blade.php
```

- [ ] **Step 3: Lancer la suite complète des tests**

```bash
cd /Users/jurgen/Library/CloudStorage/SynologyDrive-home/dev/svs-accounting && ./vendor/bin/sail artisan test
```
Attendu : tous PASS, y compris le test `GET /parametres retourne 404`.

- [ ] **Step 4: Vérifier que le cache de routes est propre**

```bash
cd /Users/jurgen/Library/CloudStorage/SynologyDrive-home/dev/svs-accounting && ./vendor/bin/sail artisan route:clear
```

- [ ] **Step 5: Commit final**

```bash
git add resources/views/layouts/app.blade.php
git rm resources/views/parametres/index.blade.php
git commit -m "feat: dropdown navbar paramètres avec sous-menus indépendants"
```
