# Refonte écran Sous-catégories — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer l'écran sous-catégories statique (Blade + Controller) par un composant Livewire avec modale CRUD, édition inline, toggles sans rechargement, et filtres sur les colonnes flags.

**Architecture:** Composant Livewire `SousCategorieList` qui gère le CRUD complet via modale Bootstrap, les toggles de flags via `wire:click`, et l'édition inline via Alpine.js avec `wire:ignore.self`. Le tri et les filtres restent en JS côté client.

**Tech Stack:** Laravel 11, Livewire 4, Alpine.js, Bootstrap 5 (CDN), Pest PHP

**Spec:** `docs/superpowers/specs/2026-03-28-sous-categories-redesign-design.md`

---

## Fichiers

| Fichier | Action | Responsabilité |
|---------|--------|----------------|
| `app/Livewire/SousCategorieList.php` | Créer | Composant Livewire : CRUD, toggles, édition inline |
| `resources/views/livewire/sous-categorie-list.blade.php` | Créer | Vue : tableau, modale, filtres, tri JS |
| `resources/views/parametres/sous-categories/index.blade.php` | Modifier | Simplifier : juste `<livewire:sous-categorie-list />` |
| `app/Http/Controllers/SousCategorieController.php` | Modifier | Ne garder que `index()` |
| `routes/web.php` | Modifier | Remplacer resource par route unique |
| `app/Http/Requests/StoreSousCategorieRequest.php` | Supprimer | Remplacé par validation Livewire |
| `app/Http/Requests/UpdateSousCategorieRequest.php` | Supprimer | Remplacé par validation Livewire |
| `tests/Feature/SousCategorieTest.php` | Réécrire | Tests Livewire (`Livewire::test()`) |

---

### Task 1: Composant Livewire — CRUD via modale

**Files:**
- Create: `app/Livewire/SousCategorieList.php`
- Test: `tests/Feature/SousCategorieTest.php`

**Contexte :** Suivre le pattern de `app/Livewire/TypeOperationManager.php` pour la structure (propriétés publiques, `showModal`, `editingId`, `resetForm()`, `save()`, `delete()`). Les conventions du projet : `declare(strict_types=1)`, `final class`, type hints partout.

- [ ] **Step 1: Écrire les tests Livewire pour le CRUD**

Réécrire `tests/Feature/SousCategorieTest.php` avec des tests Livewire :

```php
<?php

declare(strict_types=1);

use App\Livewire\SousCategorieList;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->categorie = Categorie::factory()->create();
});

it('renders the sous-categorie list component', function () {
    Livewire::test(SousCategorieList::class)
        ->assertStatus(200);
});

it('can create a sous-categorie via modal', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->assertSet('showModal', true)
        ->assertSet('editingId', null)
        ->set('categorie_id', (string) $this->categorie->id)
        ->set('nom', 'Électricité')
        ->set('code_cerfa', '1234')
        ->set('pour_dons', false)
        ->set('pour_cotisations', false)
        ->set('pour_inscriptions', false)
        ->call('save')
        ->assertSet('showModal', false);

    $this->assertDatabaseHas('sous_categories', [
        'categorie_id' => $this->categorie->id,
        'nom' => 'Électricité',
        'code_cerfa' => '1234',
    ]);
});

it('validates required fields when creating', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->set('categorie_id', '')
        ->set('nom', '')
        ->call('save')
        ->assertHasErrors(['categorie_id', 'nom']);
});

it('validates categorie_id exists', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->set('categorie_id', '99999')
        ->set('nom', 'Test')
        ->call('save')
        ->assertHasErrors(['categorie_id']);
});

it('validates nom max length', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->set('categorie_id', (string) $this->categorie->id)
        ->set('nom', str_repeat('a', 101))
        ->call('save')
        ->assertHasErrors(['nom']);
});

it('validates code_cerfa max length', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->set('categorie_id', (string) $this->categorie->id)
        ->set('nom', 'Test')
        ->set('code_cerfa', str_repeat('a', 11))
        ->call('save')
        ->assertHasErrors(['code_cerfa']);
});

it('can create without code_cerfa', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->set('categorie_id', (string) $this->categorie->id)
        ->set('nom', 'Sans CERFA')
        ->call('save')
        ->assertSet('showModal', false);

    $this->assertDatabaseHas('sous_categories', [
        'nom' => 'Sans CERFA',
        'code_cerfa' => null,
    ]);
});

it('can update a sous-categorie via modal', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);

    Livewire::test(SousCategorieList::class)
        ->call('openEdit', $sc->id)
        ->assertSet('showModal', true)
        ->assertSet('editingId', $sc->id)
        ->assertSet('nom', $sc->nom)
        ->set('nom', 'Nom modifié')
        ->set('code_cerfa', '9999')
        ->call('save')
        ->assertSet('showModal', false);

    $this->assertDatabaseHas('sous_categories', [
        'id' => $sc->id,
        'nom' => 'Nom modifié',
        'code_cerfa' => '9999',
    ]);
});

it('can toggle a flag', function () {
    $sc = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'pour_dons' => false,
    ]);

    Livewire::test(SousCategorieList::class)
        ->call('toggleFlag', $sc->id, 'pour_dons');

    expect($sc->fresh()->pour_dons)->toBeTrue();
});

it('rejects invalid flag names', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);

    Livewire::test(SousCategorieList::class)
        ->call('toggleFlag', $sc->id, 'invalid_flag');

    // No change, no crash — method silently ignores invalid flags
});

it('can update a field inline', function () {
    $sc = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'nom' => 'Ancien nom',
    ]);

    Livewire::test(SousCategorieList::class)
        ->call('updateField', $sc->id, 'nom', 'Nouveau nom');

    expect($sc->fresh()->nom)->toBe('Nouveau nom');
});

it('validates inline field update', function () {
    $sc = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'nom' => 'Ancien nom',
    ]);

    Livewire::test(SousCategorieList::class)
        ->call('updateField', $sc->id, 'nom', '')
        ->assertSet('flashType', 'danger');

    expect($sc->fresh()->nom)->toBe('Ancien nom');
});

it('can delete a sous-categorie', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);

    Livewire::test(SousCategorieList::class)
        ->call('delete', $sc->id);

    $this->assertDatabaseMissing('sous_categories', ['id' => $sc->id]);
});

it('shows error when deleting a sous-categorie with linked lignes', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);

    $depense = Transaction::factory()->asDepense()->create([
        'saisi_par' => $this->user->id,
        'date' => '2025-10-15',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $depense->id,
        'sous_categorie_id' => $sc->id,
    ]);

    Livewire::test(SousCategorieList::class)
        ->call('delete', $sc->id)
        ->assertSet('flashType', 'danger');

    $this->assertDatabaseHas('sous_categories', ['id' => $sc->id]);
});
```

- [ ] **Step 2: Vérifier que les tests échouent**

Run: `./vendor/bin/sail test tests/Feature/SousCategorieTest.php`
Expected: FAIL — `SousCategorieList` class not found

- [ ] **Step 3: Créer le composant Livewire**

Créer `app/Livewire/SousCategorieList.php` :

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Categorie;
use App\Models\SousCategorie;
use Illuminate\Database\QueryException;
use Illuminate\View\View;
use Livewire\Component;

final class SousCategorieList extends Component
{
    // ── Modal state ──────────────────────────────────────────────
    public bool $showModal = false;

    public ?int $editingId = null;

    // ── Form fields ──────────────────────────────────────────────
    public string $categorie_id = '';

    public string $nom = '';

    public string $code_cerfa = '';

    public bool $pour_dons = false;

    public bool $pour_cotisations = false;

    public bool $pour_inscriptions = false;

    // ── Flash message ────────────────────────────────────────────
    public string $flashMessage = '';

    public string $flashType = '';

    public function render(): View
    {
        return view('livewire.sous-categorie-list', [
            'categories' => Categorie::orderBy('nom')->get(),
            'sousCategories' => SousCategorie::with('categorie')->orderBy('nom')->get(),
        ]);
    }

    // ── Modal actions ────────────────────────────────────────────

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $sc = SousCategorie::findOrFail($id);

        $this->editingId = $sc->id;
        $this->categorie_id = (string) $sc->categorie_id;
        $this->nom = $sc->nom;
        $this->code_cerfa = $sc->code_cerfa ?? '';
        $this->pour_dons = $sc->pour_dons;
        $this->pour_cotisations = $sc->pour_cotisations;
        $this->pour_inscriptions = $sc->pour_inscriptions;

        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'categorie_id' => 'required|exists:categories,id',
            'nom' => 'required|string|max:100',
            'code_cerfa' => 'nullable|string|max:10',
            'pour_dons' => 'boolean',
            'pour_cotisations' => 'boolean',
            'pour_inscriptions' => 'boolean',
        ]);

        $data = [
            'categorie_id' => (int) $this->categorie_id,
            'nom' => $this->nom,
            'code_cerfa' => $this->code_cerfa !== '' ? $this->code_cerfa : null,
            'pour_dons' => $this->pour_dons,
            'pour_cotisations' => $this->pour_cotisations,
            'pour_inscriptions' => $this->pour_inscriptions,
        ];

        if ($this->editingId) {
            SousCategorie::findOrFail($this->editingId)->update($data);
        } else {
            SousCategorie::create($data);
        }

        $this->showModal = false;
        $this->resetForm();
    }

    // ── Toggle flag ──────────────────────────────────────────────

    public function toggleFlag(int $id, string $flag): void
    {
        if (! in_array($flag, ['pour_dons', 'pour_cotisations', 'pour_inscriptions'], true)) {
            return;
        }

        $sc = SousCategorie::findOrFail($id);
        $sc->update([$flag => ! $sc->$flag]);
    }

    // ── Inline edit ──────────────────────────────────────────────

    public function updateField(int $id, string $field, string $value): void
    {
        if (! in_array($field, ['nom', 'code_cerfa'], true)) {
            return;
        }

        $rules = [
            'nom' => 'required|string|max:100',
            'code_cerfa' => 'nullable|string|max:10',
        ];

        $validator = validator([$field => $value], [$field => $rules[$field]]);

        if ($validator->fails()) {
            $this->flashMessage = $validator->errors()->first($field);
            $this->flashType = 'danger';

            return;
        }

        $sc = SousCategorie::findOrFail($id);
        $sc->update([$field => $value !== '' ? $value : null]);
    }

    // ── Delete ───────────────────────────────────────────────────

    public function delete(int $id): void
    {
        try {
            SousCategorie::findOrFail($id)->delete();
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                $this->flashMessage = 'Suppression impossible : cet élément est utilisé dans les données de l\'application.';
                $this->flashType = 'danger';

                return;
            }
            throw $e;
        }
    }

    // ── Private helpers ──────────────────────────────────────────

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->categorie_id = '';
        $this->nom = '';
        $this->code_cerfa = '';
        $this->pour_dons = false;
        $this->pour_cotisations = false;
        $this->pour_inscriptions = false;
        $this->resetValidation();
    }
}
```

- [ ] **Step 4: Vérifier que les tests passent**

Run: `./vendor/bin/sail test tests/Feature/SousCategorieTest.php`
Expected: PASS (le composant existe mais pas encore de vue → les tests CRUD devraient passer car Livewire::test n'a pas besoin d'une vue complète pour les appels de méthodes). Si les tests échouent car la vue manque, créer un placeholder vide `resources/views/livewire/sous-categorie-list.blade.php` contenant juste `<div></div>`.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/SousCategorieList.php tests/Feature/SousCategorieTest.php
# Si créé: resources/views/livewire/sous-categorie-list.blade.php
git commit -m "feat(sous-categories): composant Livewire CRUD avec tests"
```

---

### Task 2: Vue Blade — Tableau, modale, filtres, tri

**Files:**
- Create: `resources/views/livewire/sous-categorie-list.blade.php`

**Contexte :** Suivre le pattern de `resources/views/livewire/type-operation-manager.blade.php`. En-tête `table-dark` avec `--bs-table-bg:#3d5473`. Modale = div fixed overlay comme dans TypeOperationManager. Tri = JS côté client avec `localeCompare('fr')`. Les cellules Nom et Code CERFA utilisent Alpine.js + `wire:ignore.self` pour l'édition inline.

- [ ] **Step 1: Créer la vue complète**

Créer `resources/views/livewire/sous-categorie-list.blade.php` :

```blade
<div>
    {{-- Flash message --}}
    @if($flashMessage)
        <div class="alert alert-{{ $flashType }} alert-dismissible fade show">
            {{ $flashMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" wire:click="$set('flashMessage', '')"></button>
        </div>
    @endif

    {{-- Toolbar --}}
    <div class="mb-3 d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div class="d-flex flex-wrap gap-2 align-items-center">
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
        <button class="btn btn-primary btn-sm" wire:click="openCreate">
            <i class="bi bi-plus-lg"></i> Ajouter une sous-catégorie
        </button>
    </div>

    {{-- Table --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover" id="scTable" wire:ignore.self>
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th class="sortable" data-col="0" style="cursor:pointer;user-select:none">Catégorie <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th class="sortable" data-col="1" style="cursor:pointer;user-select:none">Nom <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th class="sortable" data-col="2" style="cursor:pointer;user-select:none">Code CERFA <i class="bi bi-arrow-down-up" style="font-size:.7rem"></i></th>
                    <th class="text-center filterable-flag" data-flag="pour_dons" style="cursor:pointer;user-select:none">
                        Dons <span class="badge bg-secondary flag-badge" style="font-size:.65rem">tous</span>
                    </th>
                    <th class="text-center filterable-flag" data-flag="pour_cotisations" style="cursor:pointer;user-select:none">
                        Cotisations <span class="badge bg-secondary flag-badge" style="font-size:.65rem">tous</span>
                    </th>
                    <th class="text-center filterable-flag" data-flag="pour_inscriptions" style="cursor:pointer;user-select:none">
                        Inscriptions <span class="badge bg-secondary flag-badge" style="font-size:.65rem">tous</span>
                    </th>
                    <th style="width:100px" class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sousCategories as $sc)
                    <tr wire:key="sc-{{ $sc->id }}"
                        data-type="{{ $sc->categorie->type->value }}"
                        data-categorie="{{ $sc->categorie_id }}"
                        data-pour_dons="{{ $sc->pour_dons ? '1' : '0' }}"
                        data-pour_cotisations="{{ $sc->pour_cotisations ? '1' : '0' }}"
                        data-pour_inscriptions="{{ $sc->pour_inscriptions ? '1' : '0' }}">
                        {{-- Catégorie (non éditable inline) --}}
                        <td>{{ $sc->categorie->nom }}</td>

                        {{-- Nom (éditable inline) --}}
                        <td wire:ignore.self
                            x-data="{ editing: false, value: @js($sc->nom), original: @js($sc->nom) }"
                            @click="if (!editing) { editing = true; $nextTick(() => $refs.input.focus()) }"
                            style="cursor:pointer">
                            <template x-if="!editing">
                                <span x-text="value"></span>
                            </template>
                            <template x-if="editing">
                                <input x-ref="input" type="text" x-model="value"
                                       class="form-control form-control-sm"
                                       maxlength="100"
                                       @keydown.enter="if (value.trim()) { $wire.updateField({{ $sc->id }}, 'nom', value); editing = false; original = value } else { value = original; editing = false }"
                                       @keydown.escape="value = original; editing = false"
                                       @blur="if (value.trim() && value !== original) { $wire.updateField({{ $sc->id }}, 'nom', value); original = value }; editing = false"
                                       @click.stop>
                            </template>
                        </td>

                        {{-- Code CERFA (éditable inline) --}}
                        <td wire:ignore.self
                            x-data="{ editing: false, value: @js($sc->code_cerfa ?? ''), original: @js($sc->code_cerfa ?? '') }"
                            @click="if (!editing) { editing = true; $nextTick(() => $refs.input.focus()) }"
                            style="cursor:pointer">
                            <template x-if="!editing">
                                <span x-text="value || '—'" :class="{ 'text-muted': !value }"></span>
                            </template>
                            <template x-if="editing">
                                <input x-ref="input" type="text" x-model="value"
                                       class="form-control form-control-sm"
                                       maxlength="10"
                                       @keydown.enter="$wire.updateField({{ $sc->id }}, 'code_cerfa', value); editing = false; original = value"
                                       @keydown.escape="value = original; editing = false"
                                       @blur="if (value !== original) { $wire.updateField({{ $sc->id }}, 'code_cerfa', value); original = value }; editing = false"
                                       @click.stop>
                            </template>
                        </td>

                        {{-- Dons toggle --}}
                        <td class="text-center">
                            <button wire:click="toggleFlag({{ $sc->id }}, 'pour_dons')"
                                    class="btn btn-sm {{ $sc->pour_dons ? 'btn-success' : 'btn-outline-secondary' }}"
                                    style="padding:.15rem .4rem;font-size:.7rem"
                                    title="{{ $sc->pour_dons ? 'Désactiver pour les dons' : 'Activer pour les dons' }}">
                                {{ $sc->pour_dons ? '✓' : '–' }}
                            </button>
                        </td>

                        {{-- Cotisations toggle --}}
                        <td class="text-center">
                            <button wire:click="toggleFlag({{ $sc->id }}, 'pour_cotisations')"
                                    class="btn btn-sm {{ $sc->pour_cotisations ? 'btn-success' : 'btn-outline-secondary' }}"
                                    style="padding:.15rem .4rem;font-size:.7rem"
                                    title="{{ $sc->pour_cotisations ? 'Désactiver pour les cotisations' : 'Activer pour les cotisations' }}">
                                {{ $sc->pour_cotisations ? '✓' : '–' }}
                            </button>
                        </td>

                        {{-- Inscriptions toggle --}}
                        <td class="text-center">
                            <button wire:click="toggleFlag({{ $sc->id }}, 'pour_inscriptions')"
                                    class="btn btn-sm {{ $sc->pour_inscriptions ? 'btn-success' : 'btn-outline-secondary' }}"
                                    style="padding:.15rem .4rem;font-size:.7rem"
                                    title="{{ $sc->pour_inscriptions ? 'Désactiver pour les inscriptions' : 'Activer pour les inscriptions' }}">
                                {{ $sc->pour_inscriptions ? '✓' : '–' }}
                            </button>
                        </td>

                        {{-- Actions --}}
                        <td class="text-center">
                            <div class="d-flex gap-1 justify-content-center">
                                <button class="btn btn-sm btn-outline-primary"
                                        wire:click="openEdit({{ $sc->id }})"
                                        style="padding:.15rem .35rem;font-size:.75rem"
                                        title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger"
                                        wire:click="delete({{ $sc->id }})"
                                        wire:confirm="Supprimer cette sous-catégorie ?"
                                        style="padding:.15rem .35rem;font-size:.75rem"
                                        title="Supprimer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-muted">Aucune sous-catégorie enregistrée.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ═══════════════════════════════════════════════════════════
         MODAL CREATE/EDIT
         ═══════════════════════════════════════════════════════════ --}}
    @if($showModal)
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center"
             style="background:rgba(0,0,0,.4);z-index:2000"
             wire:click.self="$set('showModal', false)">
            <div class="bg-white rounded p-4 shadow" style="width:500px;max-width:95vw;max-height:90vh;overflow-y:auto">
                <h5 class="fw-bold mb-3">
                    {{ $editingId ? 'Modifier la sous-catégorie' : 'Ajouter une sous-catégorie' }}
                </h5>

                {{-- Catégorie --}}
                <div class="mb-3">
                    <label class="form-label small">Catégorie <span class="text-danger">*</span></label>
                    <select wire:model="categorie_id" class="form-select form-select-sm @error('categorie_id') is-invalid @enderror">
                        <option value="">— Choisir —</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->nom }} ({{ $cat->type->label() }})</option>
                        @endforeach
                    </select>
                    @error('categorie_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                {{-- Nom + Code CERFA --}}
                <div class="row g-2 mb-3">
                    <div class="col-md-8">
                        <label class="form-label small">Nom <span class="text-danger">*</span></label>
                        <input type="text" wire:model="nom" class="form-control form-control-sm @error('nom') is-invalid @enderror" maxlength="100">
                        @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Code CERFA</label>
                        <input type="text" wire:model="code_cerfa" class="form-control form-control-sm @error('code_cerfa') is-invalid @enderror" maxlength="10">
                        @error('code_cerfa') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                {{-- Flags --}}
                <div class="mb-3 d-flex gap-4">
                    <div class="form-check">
                        <input type="checkbox" wire:model="pour_dons" class="form-check-input" id="modalPourDons">
                        <label class="form-check-label" for="modalPourDons">Dons</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" wire:model="pour_cotisations" class="form-check-input" id="modalPourCotisations">
                        <label class="form-check-label" for="modalPourCotisations">Cotisations</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" wire:model="pour_inscriptions" class="form-check-input" id="modalPourInscriptions">
                        <label class="form-check-label" for="modalPourInscriptions">Inscriptions</label>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="d-flex gap-2 justify-content-end mt-4">
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showModal', false)">Annuler</button>
                    <button type="button" class="btn btn-sm btn-primary" wire:click="save">
                        <i class="bi bi-check-lg"></i> Enregistrer
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════
         JS: SORTING + FILTERING (côté client)
         ═══════════════════════════════════════════════════════════ --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const table = document.getElementById('scTable');
            if (!table) return;

            // ── Sorting ──────────────────────────────────────────
            const sortHeaders = table.querySelectorAll('th.sortable');
            let currentCol = null;
            let ascending = true;

            sortHeaders.forEach(function (th) {
                th.addEventListener('click', function () {
                    const col = parseInt(this.dataset.col);
                    if (currentCol === col) {
                        ascending = !ascending;
                    } else {
                        currentCol = col;
                        ascending = true;
                    }

                    const tbody = table.querySelector('tbody');
                    const rows = Array.from(tbody.querySelectorAll('tr[data-type]'));

                    rows.sort(function (a, b) {
                        const aCell = a.children[col];
                        const bCell = b.children[col];
                        if (!aCell || !bCell) return 0;
                        const aVal = (aCell.dataset.sort || aCell.textContent || '').trim().toLowerCase();
                        const bVal = (bCell.dataset.sort || bCell.textContent || '').trim().toLowerCase();
                        const result = aVal.localeCompare(bVal, 'fr');
                        return ascending ? result : -result;
                    });

                    rows.forEach(function (row) { tbody.appendChild(row); });

                    // Update sort indicators
                    sortHeaders.forEach(function (h) {
                        const icon = h.querySelector('i');
                        if (icon) icon.className = 'bi bi-arrow-down-up';
                    });
                    const icon = th.querySelector('i');
                    if (icon) {
                        icon.className = ascending ? 'bi bi-arrow-down' : 'bi bi-arrow-up';
                    }
                });
            });

            // ── Filtering ────────────────────────────────────────
            var flagFilters = {};

            function filterSousCategories() {
                var typeVal = document.querySelector('input[name="scTypeFilter"]:checked')?.value || 'all';
                var catVal = document.getElementById('scCatFilter')?.value || '';

                document.querySelectorAll('#scTable tr[data-type]').forEach(function (row) {
                    var typeOk = typeVal === 'all' || row.dataset.type === typeVal;
                    var catOk = catVal === '' || row.dataset.categorie === catVal;

                    var flagsOk = true;
                    Object.keys(flagFilters).forEach(function (flag) {
                        if (flagFilters[flag]) {
                            flagsOk = flagsOk && row.dataset[flag] === '1';
                        }
                    });

                    row.style.display = (typeOk && catOk && flagsOk) ? '' : 'none';
                });
            }

            // Type filter
            document.querySelectorAll('input[name="scTypeFilter"]').forEach(function (r) {
                r.addEventListener('change', filterSousCategories);
            });

            // Category filter
            var catFilter = document.getElementById('scCatFilter');
            if (catFilter) catFilter.addEventListener('change', filterSousCategories);

            // Flag filters (click on header badge)
            document.querySelectorAll('.filterable-flag').forEach(function (th) {
                th.addEventListener('click', function () {
                    var flag = this.dataset.flag;
                    flagFilters[flag] = !flagFilters[flag];

                    var badge = this.querySelector('.flag-badge');
                    if (flagFilters[flag]) {
                        badge.textContent = '✓ filtré';
                        badge.className = 'badge bg-success flag-badge';
                        badge.style.fontSize = '.65rem';
                    } else {
                        badge.textContent = 'tous';
                        badge.className = 'badge bg-secondary flag-badge';
                        badge.style.fontSize = '.65rem';
                    }

                    filterSousCategories();
                });
            });

            // ── Re-apply sort after Livewire morph ───────────────
            function reApplySort() {
                if (currentCol === null) return;
                const tbody = table.querySelector('tbody');
                const rows = Array.from(tbody.querySelectorAll('tr[data-type]'));
                rows.sort(function (a, b) {
                    const aCell = a.children[currentCol];
                    const bCell = b.children[currentCol];
                    if (!aCell || !bCell) return 0;
                    const aVal = (aCell.dataset.sort || aCell.textContent || '').trim().toLowerCase();
                    const bVal = (bCell.dataset.sort || bCell.textContent || '').trim().toLowerCase();
                    const result = aVal.localeCompare(bVal, 'fr');
                    return ascending ? result : -result;
                });
                rows.forEach(function (row) { tbody.appendChild(row); });
                filterSousCategories();
            }

            Livewire.hook('morph.updated', ({ el }) => {
                if (el.id === 'scTable' || el.closest('#scTable')) {
                    requestAnimationFrame(reApplySort);
                }
            });
        });
    </script>
</div>
```

- [ ] **Step 2: Relancer les tests**

Run: `./vendor/bin/sail test tests/Feature/SousCategorieTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/sous-categorie-list.blade.php
git commit -m "feat(sous-categories): vue Livewire avec modale, inline edit, filtres et tri"
```

---

### Task 3: Nettoyage — Routes, controller, form requests, vue index

**Files:**
- Modify: `routes/web.php` (lignes 40-41)
- Modify: `app/Http/Controllers/SousCategorieController.php`
- Modify: `resources/views/parametres/sous-categories/index.blade.php`
- Delete: `app/Http/Requests/StoreSousCategorieRequest.php`
- Delete: `app/Http/Requests/UpdateSousCategorieRequest.php`

- [ ] **Step 1: Simplifier les routes**

Dans `routes/web.php`, remplacer les lignes 40-41 :

```php
// Avant :
Route::resource('sous-categories', SousCategorieController::class)->except(['show']);
Route::post('sous-categories/{sousCategory}/toggle-flag', [SousCategorieController::class, 'toggleFlag'])->name('sous-categories.toggle-flag');

// Après :
Route::get('sous-categories', [SousCategorieController::class, 'index'])->name('sous-categories.index');
```

- [ ] **Step 2: Simplifier le controller**

Remplacer le contenu de `app/Http/Controllers/SousCategorieController.php` :

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

final class SousCategorieController extends Controller
{
    public function index(): View
    {
        return view('parametres.sous-categories.index');
    }
}
```

- [ ] **Step 3: Simplifier la vue index**

Remplacer le contenu de `resources/views/parametres/sous-categories/index.blade.php` :

```blade
<x-app-layout>
    <h1 class="mb-4">Sous-catégories</h1>

    <livewire:sous-categorie-list />
</x-app-layout>
```

- [ ] **Step 4: Supprimer les form requests**

```bash
rm app/Http/Requests/StoreSousCategorieRequest.php
rm app/Http/Requests/UpdateSousCategorieRequest.php
```

- [ ] **Step 5: Vérifier que les imports inutilisés sont retirés du controller**

Le controller n'a plus besoin des imports de `StoreSousCategorieRequest`, `UpdateSousCategorieRequest`, `Categorie`, `SousCategorie`, `QueryException`, `Request`, `RedirectResponse`. Seul `Illuminate\View\View` reste.

- [ ] **Step 6: Relancer les tests**

Run: `./vendor/bin/sail test tests/Feature/SousCategorieTest.php`
Expected: PASS

Run: `./vendor/bin/sail test`
Expected: PASS (aucun autre test ne devrait casser — vérifier qu'aucun test n'utilise les routes supprimées)

- [ ] **Step 7: Lancer Pint**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint
```

- [ ] **Step 8: Commit**

```bash
git add routes/web.php app/Http/Controllers/SousCategorieController.php resources/views/parametres/sous-categories/index.blade.php
git add -u app/Http/Requests/StoreSousCategorieRequest.php app/Http/Requests/UpdateSousCategorieRequest.php
git commit -m "refactor(sous-categories): nettoyage routes, controller et form requests"
```
