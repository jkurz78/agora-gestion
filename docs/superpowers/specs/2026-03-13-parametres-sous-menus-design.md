# Paramètres — Sous-menus indépendants

## Contexte

Actuellement, la section "Paramètres" est un seul lien dans la navbar qui mène à une page unique avec 5 onglets Bootstrap (Catégories, Sous-catégories, Comptes bancaires, Utilisateurs, Opérations).

L'objectif est de transformer ce lien en dropdown navbar avec chaque section accessible via sa propre page indépendante.

---

## Navigation

Le lien "Paramètres" dans `resources/views/layouts/app.blade.php` devient un **dropdown Bootstrap** avec la structure suivante, dans l'ordre imposé :

1. **Catégories** → `route('parametres.categories.index')`
2. **Sous-catégories** → `route('parametres.sous-categories.index')`
3. **Opérations** → `route('operations.index')` *(route existante réutilisée)*
4. `<hr>` séparateur
5. **Comptes bancaires** → `route('parametres.comptes-bancaires.index')`
6. `<hr>` séparateur
7. **Utilisateurs** → `route('parametres.utilisateurs.index')`

Le dropdown affiche l'état actif (classe `active` sur le `<a>`) quand la route courante correspond à la section. Le dropdown lui-même est mis en évidence si l'on est sur n'importe quelle route `parametres.*` ou `operations.*`.

---

## Routes

### Routes existantes (inchangées)

```php
// Dans le groupe prefix('parametres')->name('parametres.')->group(...)
Route::resource('categories', CategorieController::class)->except(['show']);
// → GET /parametres/categories = parametres.categories.index ✓

Route::resource('sous-categories', SousCategorieController::class)->except(['show']);
// → GET /parametres/sous-categories = parametres.sous-categories.index ✓

Route::resource('comptes-bancaires', CompteBancaireController::class)->except(['show']);
// → GET /parametres/comptes-bancaires = parametres.comptes-bancaires.index ✓
```

### Route utilisateurs — modification

La ligne existante :
```php
Route::resource('utilisateurs', UserController::class)->only(['store', 'update', 'destroy']);
```
devient :
```php
Route::resource('utilisateurs', UserController::class)->only(['index', 'store', 'update', 'destroy']);
// → GET /parametres/utilisateurs = parametres.utilisateurs.index
```

### Route supprimée

```php
Route::get('/parametres', [ParametreController::class, 'index'])->name('parametres.index');
```

---

## Controllers

> **Note :** `CategorieController`, `SousCategorieController` et `CompteBancaireController` ont déjà une méthode `index()` qui fait un redirect vers `parametres.index`. Ces méthodes doivent être **remplacées** (pas ajoutées) par les implémentations ci-dessous.

### CategorieController — remplacer `index()`

```php
public function index(): View
{
    return view('parametres.categories.index', [
        'categories' => Categorie::with('sousCategories')->orderBy('nom')->get(),
    ]);
}
```

### SousCategorieController — remplacer `index()`

```php
public function index(): View
{
    return view('parametres.sous-categories.index', [
        'categories' => Categorie::with('sousCategories')->orderBy('nom')->get(),
    ]);
}
```

### CompteBancaireController — remplacer `index()`

```php
public function index(): View
{
    return view('parametres.comptes-bancaires.index', [
        'comptesBancaires' => CompteBancaire::orderBy('nom')->get(),
    ]);
}
```

Supprimer toute logique `session('activeTab')` dans ce controller.

### UserController — ajouter `index()`

```php
public function index(): View
{
    return view('parametres.utilisateurs.index', [
        'utilisateurs' => User::orderBy('nom')->get(),
    ]);
}
```

---

## Redirections après actions

Après `store` / `update` / `destroy`, chaque controller redirige vers sa propre page index. Supprimer aussi toute logique `session(['activeTab' => ...])` dans `CompteBancaireController`.

**Fichiers concernés :**
- `app/Http/Controllers/CategorieController.php` — toutes les redirections vers `parametres.index`
- `app/Http/Controllers/SousCategorieController.php` — toutes les redirections vers `parametres.index`
- `app/Http/Controllers/CompteBancaireController.php` — toutes les redirections vers `parametres.index` + suppression `session('activeTab')`
- `app/Http/Controllers/UserController.php` — toutes les redirections vers `parametres.index`

| Controller | Redirection actuelle | Nouvelle redirection |
|---|---|---|
| CategorieController | `parametres.index` | `parametres.categories.index` |
| SousCategorieController | `parametres.index` | `parametres.sous-categories.index` |
| CompteBancaireController | `parametres.index` + `session('activeTab')` | `parametres.comptes-bancaires.index` |
| UserController | `parametres.index` | `parametres.utilisateurs.index` |
| OperationController | `_redirect_back` ou `operations.index` | `operations.index` (inchangé) |

---

## Vues

### Vues à créer

Chaque vue reprend **exactement** le contenu de l'onglet correspondant dans `parametres/index.blade.php`, encapsulé dans `<x-app-layout>` avec un titre `<h1>`.

| Vue | Source (onglet) |
|---|---|
| `resources/views/parametres/categories/index.blade.php` | `#categories-pane` |
| `resources/views/parametres/sous-categories/index.blade.php` | `#sous-categories-pane` |
| `resources/views/parametres/comptes-bancaires/index.blade.php` | `#comptes-pane` |
| `resources/views/parametres/utilisateurs/index.blade.php` | `#utilisateurs-pane` |

Les IDs des éléments HTML internes (`addCategorieForm`, `catFilter`, etc.) restent inchangés car ils n'ont plus de conflit de portée entre onglets.

### Vues/fichiers à supprimer

- `resources/views/parametres/index.blade.php`

### Vues inchangées

- `resources/views/operations/` — l'`OperationController::index()` existant est réutilisé tel quel.

---

## Navbar — code Blade

Dans `resources/views/layouts/app.blade.php`, remplacer l'entrée "Paramètres" du tableau `$navItems` par un dropdown Bootstrap 5 :

```blade
{{-- Remplace l'entrée ['route' => 'parametres.index', ...] --}}
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
```

Ce bloc remplace **à la fois** l'entrée dans le tableau `$navItems` ET la boucle `@foreach` pour cet item. Les autres items de la navbar restent dans leur boucle `@foreach` habituelle.

---

## ParametreController

Le `ParametreController` et son fichier sont **supprimés** (plus aucune référence après suppression de la route `parametres.index`).

---

## Tests

Vérifier que les routes suivantes retournent HTTP 200 pour un utilisateur authentifié :
- `GET /parametres/categories`
- `GET /parametres/sous-categories`
- `GET /parametres/comptes-bancaires`
- `GET /parametres/utilisateurs`
- `GET /operations`

Vérifier que les redirections post-action sont correctes :
- `POST /parametres/categories` → redirige vers `/parametres/categories`
- `POST /parametres/comptes-bancaires` → redirige vers `/parametres/comptes-bancaires`
- `POST /parametres/utilisateurs` → redirige vers `/parametres/utilisateurs`
