# Tiers — Écran liste amélioré — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enrichir l'écran liste des tiers : icônes type, colonne Ville+CP, sous-ligne contact entreprise, recherche élargie, filtre HelloAsso, tri serveur sur Nom/Ville/Email.

**Architecture:** Deux fichiers uniquement — `TiersList.php` (logique PHP) et `tiers-list.blade.php` (vue). Aucun nouveau fichier. Les factory states nécessaires aux tests sont ajoutés dans `TiersFactory.php`. Ce plan s'exécute **après le merge de `feat/tiers-restructuration`** dans `main` (les colonnes `entreprise`, `ville`, `code_postal`, `helloasso_id` doivent exister sur la table `tiers`).

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Pest PHP, SQLite (tests)

---

## Fichiers modifiés

| Fichier | Nature |
|---|---|
| `app/Livewire/TiersList.php` | Ajout `sortBy`, `sortDir`, `filtreHelloasso`, méthodes `sort()` et `updatedFiltreHelloasso()`, mise à jour `render()` |
| `resources/views/livewire/tiers-list.blade.php` | Nouveau layout : icônes, sous-ligne contact, colonne Ville+CP, tri, filtre HA |
| `database/factories/TiersFactory.php` | Ajout états `entreprise()` et `avecHelloasso()` |
| `tests/Livewire/TiersListTest.php` | 13 nouveaux cas de test |

---

## Task 1 : Factory states pour les tests

**Files:**
- Modify: `database/factories/TiersFactory.php`

Le `TiersFactory` post-merge a un état `particulier` par défaut mais manque d'états pour les entreprises avec `ville`, `code_postal`, `helloasso_id`. Ces états sont nécessaires aux tests des tâches suivantes.

- [ ] **Step 1 : Lire le fichier actuel**

```bash
cat database/factories/TiersFactory.php
```

- [ ] **Step 2 : Ajouter les deux états**

Dans `database/factories/TiersFactory.php`, après la méthode `membre()`, ajouter :

```php
public function entreprise(): static
{
    return $this->state([
        'type'       => 'entreprise',
        'nom'        => null,
        'prenom'     => null,
        'entreprise' => fake()->company(),
    ]);
}

public function avecHelloasso(): static
{
    return $this->state([
        'helloasso_id' => fake()->uuid(),
    ]);
}
```

- [ ] **Step 3 : Vérifier que la suite de tests existante passe toujours**

```bash
./vendor/bin/sail artisan test --filter TiersListTest
```

Expected : tous verts, 0 failure.

- [ ] **Step 4 : Commit**

```bash
git add database/factories/TiersFactory.php
git commit -m "test(tiers): ajoute états factory entreprise() et avecHelloasso()"
```

---

## Task 2 : Recherche élargie (entreprise, ville, code_postal, email)

**Files:**
- Modify: `app/Livewire/TiersList.php`
- Test: `tests/Livewire/TiersListTest.php`

La recherche actuelle ne porte que sur `nom` et `prenom`. L'objectif est d'étendre aux champs `entreprise`, `ville`, `code_postal`, `email`.

- [ ] **Step 1 : Écrire les tests échouants**

Dans `tests/Livewire/TiersListTest.php`, ajouter à la fin :

```php
it('recherche dans le champ entreprise', function () {
    Tiers::factory()->entreprise()->create(['entreprise' => 'ACME Corp', 'ville' => null]);
    Tiers::factory()->create(['nom' => 'Dupont', 'entreprise' => null]);

    Livewire::test(TiersList::class)
        ->set('search', 'ACME')
        ->assertSee('ACME Corp')
        ->assertDontSee('Dupont');
});

it('recherche dans le champ ville', function () {
    Tiers::factory()->create(['nom' => 'Martin', 'ville' => 'Lyon']);
    Tiers::factory()->create(['nom' => 'Dupont', 'ville' => 'Paris']);

    Livewire::test(TiersList::class)
        ->set('search', 'Lyon')
        ->assertSee('Martin')
        ->assertDontSee('Dupont');
});

it('recherche dans le champ code_postal', function () {
    Tiers::factory()->create(['nom' => 'Martin', 'code_postal' => '75001', 'ville' => 'Paris']);
    Tiers::factory()->create(['nom' => 'Dupont', 'code_postal' => '69001', 'ville' => 'Lyon']);

    Livewire::test(TiersList::class)
        ->set('search', '75')
        ->assertSee('Martin')
        ->assertDontSee('Dupont');
});

it('recherche dans le champ email', function () {
    Tiers::factory()->create(['nom' => 'Martin', 'email' => 'martin@acme.fr']);
    Tiers::factory()->create(['nom' => 'Dupont', 'email' => 'dupont@other.fr']);

    Livewire::test(TiersList::class)
        ->set('search', 'acme')
        ->assertSee('Martin')
        ->assertDontSee('Dupont');
});
```

- [ ] **Step 2 : Vérifier que ces 4 tests échouent**

```bash
./vendor/bin/sail artisan test --filter "recherche dans le champ"
```

Expected : 4 FAILED.

- [ ] **Step 3 : Mettre à jour la méthode `render()` dans `TiersList.php`**

Remplacer le bloc `if ($this->search !== '')` par :

```php
if ($this->search !== '') {
    $query->where(function ($q): void {
        $q->where('nom',          'like', "%{$this->search}%")
          ->orWhere('prenom',     'like', "%{$this->search}%")
          ->orWhere('entreprise', 'like', "%{$this->search}%")
          ->orWhere('ville',      'like', "%{$this->search}%")
          ->orWhere('code_postal','like', "%{$this->search}%")
          ->orWhere('email',      'like', "%{$this->search}%");
    });
}
```

- [ ] **Step 4 : Vérifier que les 4 tests passent**

```bash
./vendor/bin/sail artisan test --filter "recherche dans le champ"
```

Expected : 4 PASSED.

- [ ] **Step 5 : Vérifier que la suite complète passe**

```bash
./vendor/bin/sail artisan test --filter TiersListTest
```

Expected : tous verts.

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/TiersList.php tests/Livewire/TiersListTest.php
git commit -m "feat(tiers-list): recherche élargie (entreprise, ville, code_postal, email)"
```

---

## Task 3 : Filtre HelloAsso

**Files:**
- Modify: `app/Livewire/TiersList.php`
- Test: `tests/Livewire/TiersListTest.php`

- [ ] **Step 1 : Écrire les tests échouants**

Dans `tests/Livewire/TiersListTest.php`, ajouter :

```php
it('filtre helloasso actif — affiche seulement les tiers avec helloasso_id', function () {
    Tiers::factory()->avecHelloasso()->create(['nom' => 'Martin']);
    Tiers::factory()->create(['nom' => 'Dupont', 'helloasso_id' => null]);

    Livewire::test(TiersList::class)
        ->set('filtreHelloasso', true)
        ->assertSee('Martin')
        ->assertDontSee('Dupont');
});

it('filtre helloasso inactif — affiche tous les tiers', function () {
    Tiers::factory()->avecHelloasso()->create(['nom' => 'Martin']);
    Tiers::factory()->create(['nom' => 'Dupont', 'helloasso_id' => null]);

    Livewire::test(TiersList::class)
        ->set('filtreHelloasso', false)
        ->assertSee('Martin')
        ->assertSee('Dupont');
});
```

- [ ] **Step 2 : Vérifier que ces 2 tests échouent**

```bash
./vendor/bin/sail artisan test --filter "filtre helloasso"
```

Expected : 2 FAILED (propriété `filtreHelloasso` inexistante).

- [ ] **Step 3 : Ajouter la propriété et la méthode dans `TiersList.php`**

Après la déclaration de `$filtre`, ajouter :

```php
public bool $filtreHelloasso = false;
```

Après `updatedFiltre()`, ajouter :

```php
public function updatedFiltreHelloasso(): void
{
    $this->resetPage();
}
```

Dans `render()`, après le bloc `if ($this->filtre ...)`, ajouter :

```php
if ($this->filtreHelloasso) {
    $query->whereNotNull('helloasso_id');
}
```

- [ ] **Step 4 : Vérifier que les 2 tests passent**

```bash
./vendor/bin/sail artisan test --filter "filtre helloasso"
```

Expected : 2 PASSED.

- [ ] **Step 5 : Commit**

```bash
git add app/Livewire/TiersList.php tests/Livewire/TiersListTest.php
git commit -m "feat(tiers-list): filtre HelloAsso uniquement"
```

---

## Task 4 : Tri serveur (Nom, Ville, Email)

**Files:**
- Modify: `app/Livewire/TiersList.php`
- Test: `tests/Livewire/TiersListTest.php`

- [ ] **Step 1 : Écrire les tests échouants**

Dans `tests/Livewire/TiersListTest.php`, ajouter :

```php
it('tri par nom ASC — ordre COALESCE(entreprise, nom)', function () {
    Tiers::factory()->entreprise()->create(['entreprise' => 'Zéphyr SA']);
    Tiers::factory()->create(['nom' => 'Arnaud', 'entreprise' => null]);
    Tiers::factory()->entreprise()->create(['entreprise' => 'Martin SARL']);

    $component = Livewire::test(TiersList::class)
        ->call('sort', 'nom');

    $html = $component->html();
    $posArnaud  = strpos($html, 'Arnaud');
    $posMartin  = strpos($html, 'Martin SARL');
    $posZephyr  = strpos($html, 'Zéphyr SA');

    expect($posArnaud)->toBeLessThan($posMartin);
    expect($posMartin)->toBeLessThan($posZephyr);
});

it('tri par nom DESC', function () {
    Tiers::factory()->entreprise()->create(['entreprise' => 'Zéphyr SA']);
    Tiers::factory()->create(['nom' => 'Arnaud', 'entreprise' => null]);

    $component = Livewire::test(TiersList::class)
        ->call('sort', 'nom')   // asc
        ->call('sort', 'nom');  // desc (toggle)

    $html = $component->html();
    expect(strpos($html, 'Zéphyr SA'))->toBeLessThan(strpos($html, 'Arnaud'));
});

it('tri par ville', function () {
    Tiers::factory()->create(['nom' => 'Martin', 'ville' => 'Paris']);
    Tiers::factory()->create(['nom' => 'Dupont', 'ville' => 'Bordeaux']);

    $component = Livewire::test(TiersList::class)
        ->call('sort', 'ville');

    $html = $component->html();
    expect(strpos($html, 'Bordeaux'))->toBeLessThan(strpos($html, 'Paris'));
});

it('entreprise sans raison sociale — displayName affiche nom, tri COALESCE rabat sur nom', function () {
    Tiers::factory()->create(['type' => 'entreprise', 'entreprise' => null, 'nom' => 'Ancien', 'prenom' => null]);
    Tiers::factory()->entreprise()->create(['entreprise' => 'Zéphyr SA']);

    $component = Livewire::test(TiersList::class)
        ->call('sort', 'nom');

    $html = $component->html();
    expect(strpos($html, 'Ancien'))->toBeLessThan(strpos($html, 'Zéphyr SA'));
});
```

- [ ] **Step 2 : Vérifier que ces 4 tests échouent**

```bash
./vendor/bin/sail artisan test --filter "tri par"
./vendor/bin/sail artisan test --filter "entreprise sans raison"
```

Expected : 4 FAILED.

> Note Fish shell : ces commandes s'exécutent directement, pas de problème d'échappement ici.

- [ ] **Step 3 : Ajouter les propriétés et la méthode `sort()` dans `TiersList.php`**

Après `$filtreHelloasso`, ajouter :

```php
public string $sortBy  = 'nom';
public string $sortDir = 'asc';
```

Après `updatedFiltreHelloasso()`, ajouter :

```php
public function sort(string $col): void
{
    $allowed = ['nom', 'ville', 'email'];
    if (! in_array($col, $allowed, true)) {
        return;
    }
    if ($this->sortBy === $col) {
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    } else {
        $this->sortBy  = $col;
        $this->sortDir = 'asc';
    }
    $this->resetPage();
}
```

Dans `render()`, remplacer `Tiers::orderBy('nom')` par `Tiers::query()` et ajouter à la fin du query (avant `paginate`) :

```php
$dir = $this->sortDir === 'desc' ? 'desc' : 'asc';
if ($this->sortBy === 'nom') {
    $query->orderByRaw('COALESCE(entreprise, nom) ' . $dir);
} else {
    $query->orderBy($this->sortBy, $dir);
}
```

- [ ] **Step 4 : Vérifier que les 4 tests passent**

```bash
./vendor/bin/sail artisan test --filter "tri par"
./vendor/bin/sail artisan test --filter "entreprise sans raison"
```

Expected : 4 PASSED.

- [ ] **Step 5 : Vérifier que la suite complète passe**

```bash
./vendor/bin/sail artisan test --filter TiersListTest
```

Expected : tous verts.

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/TiersList.php tests/Livewire/TiersListTest.php
git commit -m "feat(tiers-list): tri serveur sur Nom (COALESCE), Ville, Email"
```

---

## Task 5 : Vue Blade — icônes, sous-ligne, colonne Ville+CP, filtre HA, en-têtes triables

**Files:**
- Modify: `resources/views/livewire/tiers-list.blade.php`
- Test: `tests/Livewire/TiersListTest.php`

- [ ] **Step 1 : Écrire les tests échouants**

Dans `tests/Livewire/TiersListTest.php`, ajouter :

```php
it('affiche icône 👤 pour un particulier', function () {
    Tiers::factory()->create(['type' => 'particulier', 'nom' => 'Durand']);

    Livewire::test(TiersList::class)
        ->assertSee('👤');
});

it('affiche icône 🏢 pour une entreprise', function () {
    Tiers::factory()->entreprise()->create(['entreprise' => 'ACME Corp']);

    Livewire::test(TiersList::class)
        ->assertSee('🏢');
});

it('affiche la sous-ligne contact pour une entreprise avec nom renseigné', function () {
    Tiers::factory()->entreprise()->create([
        'entreprise' => 'ACME Corp',
        'nom'        => 'Dupont',
        'prenom'     => 'Jean',
    ]);

    Livewire::test(TiersList::class)
        ->assertSeeHtml('class="text-muted small"')
        ->assertSee('Jean Dupont');
});

it('n\'affiche pas de sous-ligne contact pour une entreprise sans nom ni prénom', function () {
    Tiers::factory()->entreprise()->create([
        'entreprise' => 'ACME Corp',
        'nom'        => null,
        'prenom'     => null,
    ]);

    Livewire::test(TiersList::class)
        ->assertDontSeeHtml('class="text-muted small"');
});

it('affiche la ville et le code postal dans la colonne Ville', function () {
    Tiers::factory()->create(['nom' => 'Martin', 'ville' => 'Paris', 'code_postal' => '75001']);

    Livewire::test(TiersList::class)
        ->assertSee('75001 Paris');
});

it('affiche un tiret si ville et code_postal sont null', function () {
    Tiers::factory()->create(['nom' => 'Martin', 'ville' => null, 'code_postal' => null]);

    Livewire::test(TiersList::class)
        ->assertSee('—');
});

it('affiche la checkbox filtre HelloAsso dans les filtres', function () {
    Livewire::test(TiersList::class)
        ->assertSeeHtml('wire:model.live="filtreHelloasso"');
});

it('affiche les en-têtes triables Nom et Ville avec wire:click', function () {
    Livewire::test(TiersList::class)
        ->assertSeeHtml("sort('nom')")
        ->assertSeeHtml("sort('ville')");
});
```

- [ ] **Step 2 : Vérifier que ces 8 tests échouent**

```bash
./vendor/bin/sail artisan test --filter "icône|sous-ligne|ville|code postal|tiret|checkbox|en-têtes"
```

Expected : 8 FAILED.

- [ ] **Step 3 : Réécrire `tiers-list.blade.php`**

Remplacer intégralement le contenu par :

```blade
{{-- resources/views/livewire/tiers-list.blade.php --}}
<div>
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- Filtres --}}
    <div class="row g-2 mb-3 align-items-center">
        <div class="col-md-5">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                class="form-control"
                placeholder="Rechercher un tiers..."
            >
        </div>
        <div class="col-md-3">
            <select wire:model.live="filtre" class="form-select">
                <option value="">Tous les tiers</option>
                <option value="depenses">Utilisables en dépenses</option>
                <option value="recettes">Utilisables en recettes</option>
            </select>
        </div>
        <div class="col-md-auto d-flex align-items-center">
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox"
                       wire:model.live="filtreHelloasso" id="filtreHelloasso">
                <label class="form-check-label" for="filtreHelloasso">HelloAsso uniquement</label>
            </div>
        </div>
    </div>

    {{-- Tableau --}}
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>
                        <a href="#" wire:click.prevent="sort('nom')" class="text-white text-decoration-none">
                            Nom
                            @if($sortBy === 'nom')
                                <i class="bi bi-arrow-{{ $sortDir === 'asc' ? 'up' : 'down' }}"></i>
                            @endif
                        </a>
                    </th>
                    <th>
                        <a href="#" wire:click.prevent="sort('email')" class="text-white text-decoration-none">
                            Email
                            @if($sortBy === 'email')
                                <i class="bi bi-arrow-{{ $sortDir === 'asc' ? 'up' : 'down' }}"></i>
                            @endif
                        </a>
                    </th>
                    <th>Téléphone</th>
                    <th>
                        <a href="#" wire:click.prevent="sort('ville')" class="text-white text-decoration-none">
                            Ville
                            @if($sortBy === 'ville')
                                <i class="bi bi-arrow-{{ $sortDir === 'asc' ? 'up' : 'down' }}"></i>
                            @endif
                        </a>
                    </th>
                    <th class="text-center">Dép.</th>
                    <th class="text-center">Rec.</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tiersList as $tiers)
                    <tr>
                        <td class="fw-semibold">
                            {{ $tiers->type === 'entreprise' ? '🏢' : '👤' }}
                            {{ $tiers->displayName() }}
                            @if ($tiers->helloasso_id)
                                <span class="badge ms-1" style="background:#722281;font-size:.65rem" title="Identifiant HelloAsso : {{ $tiers->helloasso_id }}">HA</span>
                            @endif
                            @if ($tiers->type === 'entreprise' && ($tiers->nom || $tiers->prenom))
                                <div class="text-muted small">{{ trim(($tiers->prenom ? $tiers->prenom . ' ' : '') . ($tiers->nom ?? '')) }}</div>
                            @endif
                        </td>
                        <td>{{ $tiers->email ?? '-' }}</td>
                        <td>{{ $tiers->telephone ?? '-' }}</td>
                        <td>{{ trim(($tiers->code_postal ? $tiers->code_postal . ' ' : '') . ($tiers->ville ?? '')) ?: '—' }}</td>
                        <td class="text-center">
                            @if ($tiers->pour_depenses)
                                <i class="bi bi-check-lg text-success"></i>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if ($tiers->pour_recettes)
                                <i class="bi bi-check-lg text-success"></i>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('tiers.transactions', $tiers->id) }}"
                               class="btn btn-sm btn-outline-secondary me-1"
                               title="Transactions">
                                <i class="bi bi-clock-history"></i>
                            </a>
                            <button
                                class="btn btn-sm btn-outline-primary me-1"
                                wire:click="requestEdit({{ $tiers->id }})"
                                title="Modifier"
                            ><i class="bi bi-pencil"></i></button>
                            <button
                                class="btn btn-sm btn-outline-danger"
                                wire:click="delete({{ $tiers->id }})"
                                wire:confirm="Supprimer ce tiers ?"
                                title="Supprimer"
                            ><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Aucun tiers.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-per-page-selector :paginator="$tiersList" storageKey="tiers" wire:model.live="perPage" />
    {{ $tiersList->links() }}
</div>
```

- [ ] **Step 4 : Vérifier que les 8 tests passent**

```bash
./vendor/bin/sail artisan test --filter "icône\|sous-ligne\|ville\|code postal\|tiret\|checkbox\|en-têtes"
```

Expected : 8 PASSED.

- [ ] **Step 5 : Vérifier que la suite TiersListTest complète passe**

```bash
./vendor/bin/sail artisan test --filter TiersListTest
```

Expected : tous verts.

- [ ] **Step 6 : Lancer la suite complète pour vérifier aucune régression**

```bash
./vendor/bin/sail artisan test
```

Expected : tous verts.

- [ ] **Step 7 : Commit**

```bash
git add resources/views/livewire/tiers-list.blade.php tests/Livewire/TiersListTest.php
git commit -m "feat(tiers-list): vue enrichie — icônes, sous-ligne contact, colonne Ville+CP, filtre HA, tri"
```

---

## État final de `TiersList.php`

Pour référence, voici le fichier complet attendu après les Tasks 2, 3, 4 :

```php
<?php

// app/Livewire/TiersList.php
declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\WithPerPage;
use App\Models\Tiers;
use App\Services\TiersService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

final class TiersList extends Component
{
    use WithPagination;
    use WithPerPage;

    protected string $paginationTheme = 'bootstrap';

    public string $search = '';
    public string $filtre = ''; // '', 'depenses', 'recettes'
    public bool   $filtreHelloasso = false;
    public string $sortBy  = 'nom';
    public string $sortDir = 'asc';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFiltre(): void
    {
        $this->resetPage();
    }

    public function updatedFiltreHelloasso(): void
    {
        $this->resetPage();
    }

    public function sort(string $col): void
    {
        $allowed = ['nom', 'ville', 'email'];
        if (! in_array($col, $allowed, true)) {
            return;
        }
        if ($this->sortBy === $col) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy  = $col;
            $this->sortDir = 'asc';
        }
        $this->resetPage();
    }

    #[On('tiers-saved')]
    public function refresh(): void {}

    public function requestEdit(int $id): void
    {
        $this->dispatch('edit-tiers', id: $id);
    }

    public function delete(int $id): void
    {
        $tiers = Tiers::findOrFail($id);
        try {
            app(TiersService::class)->delete($tiers);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $query = Tiers::query();

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('nom',          'like', "%{$this->search}%")
                  ->orWhere('prenom',     'like', "%{$this->search}%")
                  ->orWhere('entreprise', 'like', "%{$this->search}%")
                  ->orWhere('ville',      'like', "%{$this->search}%")
                  ->orWhere('code_postal','like', "%{$this->search}%")
                  ->orWhere('email',      'like', "%{$this->search}%");
            });
        }

        if ($this->filtre === 'depenses') {
            $query->where('pour_depenses', true);
        } elseif ($this->filtre === 'recettes') {
            $query->where('pour_recettes', true);
        }

        if ($this->filtreHelloasso) {
            $query->whereNotNull('helloasso_id');
        }

        $dir = $this->sortDir === 'desc' ? 'desc' : 'asc';
        if ($this->sortBy === 'nom') {
            $query->orderByRaw('COALESCE(entreprise, nom) ' . $dir);
        } else {
            $query->orderBy($this->sortBy, $dir);
        }

        return view('livewire.tiers-list', [
            'tiersList' => $query->paginate($this->effectivePerPage()),
        ]);
    }
}
```
