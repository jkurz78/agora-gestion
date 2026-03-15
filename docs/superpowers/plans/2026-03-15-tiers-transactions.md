# Tiers — Transactions Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter une page `/tiers/{id}/transactions` listant toutes les transactions d'un tiers (dépenses, recettes, dons, cotisations) avec tri et filtres, accessible depuis un bouton dans la liste des tiers.

**Architecture:** Un service `TiersTransactionService` construit une UNION SQL sur 4 tables filtrée par `tiers_id` (même pattern que `TransactionCompteService`). Un composant Livewire `TiersTransactions` consomme ce service et expose les filtres/tri. Une route avec Route Model Binding charge le tiers et retourne la vue layout.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Pest PHP, `DB::query()->fromSub()` pour le UNION paginé.

---

## Chunk 1: Service TiersTransactionService + tests

### Task 1: Service TiersTransactionService

**Files:**
- Create: `app/Services/TiersTransactionService.php`
- Test: `tests/Feature/Services/TiersTransactionServiceTest.php`

- [ ] **Step 1: Écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

use App\Models\Depense;
use App\Models\Don;
use App\Models\Cotisation;
use App\Models\Recette;
use App\Models\Tiers;
use App\Services\TiersTransactionService;

beforeEach(function (): void {
    $this->tiers = Tiers::factory()->create();
    $this->service = new TiersTransactionService();
    session(['exercice_actif' => 2025]);
});

it('retourne les transactions de tous les types pour un tiers', function (): void {
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Ma dépense']);
    Don::factory()->create(['tiers_id' => $this->tiers->id, 'objet' => 'Mon don']);

    $result = $this->service->paginate($this->tiers, '', '', '', '', 'date', 'desc');

    expect($result->total())->toBe(2);
});

it('filtre par type', function (): void {
    Depense::factory()->create(['tiers_id' => $this->tiers->id]);
    Don::factory()->create(['tiers_id' => $this->tiers->id]);

    $result = $this->service->paginate($this->tiers, 'don', '', '', '', 'date', 'desc');

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->source_type)->toBe('don');
});

it('filtre par texte sur le libellé', function (): void {
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Frais transport']);
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Loyer bureau']);

    $result = $this->service->paginate($this->tiers, '', '', '', 'Loyer', 'date', 'desc');

    expect($result->total())->toBe(1);
});

it('filtre par date début', function (): void {
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'date' => '2025-10-01']);
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'date' => '2025-12-01']);

    $result = $this->service->paginate($this->tiers, '', '2025-11-01', '', '', 'date', 'desc');

    expect($result->total())->toBe(1);
});

it('exclut les transactions soft-deletées', function (): void {
    $dep = Depense::factory()->create(['tiers_id' => $this->tiers->id]);
    $dep->delete();

    $result = $this->service->paginate($this->tiers, '', '', '', '', 'date', 'desc');

    expect($result->total())->toBe(0);
});

it('ne retourne pas les transactions d\'un autre tiers', function (): void {
    $autre = Tiers::factory()->create();
    Depense::factory()->create(['tiers_id' => $autre->id]);

    $result = $this->service->paginate($this->tiers, '', '', '', '', 'date', 'desc');

    expect($result->total())->toBe(0);
});

it('trie par montant desc', function (): void {
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'montant_total' => 100]);
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'montant_total' => 50]);

    $result = $this->service->paginate($this->tiers, '', '', '', '', 'montant', 'desc');

    expect((float) $result->items()[0]->montant)->toBeGreaterThan((float) $result->items()[1]->montant);
});
```

- [ ] **Step 2: Lancer les tests — vérifier qu'ils échouent**

```bash
./vendor/bin/sail php artisan test tests/Feature/Services/TiersTransactionServiceTest.php
```

Attendu : FAIL — classe non trouvée.

- [ ] **Step 3: Créer le service**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tiers;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class TiersTransactionService
{
    public function paginate(
        Tiers $tiers,
        string $typeFilter,
        string $dateDebut,
        string $dateFin,
        string $search,
        string $sortBy,
        string $sortDir,
        int $perPage = 50,
    ): LengthAwarePaginator {
        $id = $tiers->id;

        $recettes = DB::table('recettes as r')
            ->leftJoin('comptes_bancaires as cb', 'cb.id', '=', 'r.compte_id')
            ->selectRaw("r.id, 'recette' as source_type, r.date, r.libelle, cb.nom as compte, r.montant_total as montant")
            ->where('r.tiers_id', $id)
            ->whereNull('r.deleted_at');

        $depenses = DB::table('depenses as d')
            ->leftJoin('comptes_bancaires as cb', 'cb.id', '=', 'd.compte_id')
            ->selectRaw("d.id, 'depense' as source_type, d.date, d.libelle, cb.nom as compte, d.montant_total as montant")
            ->where('d.tiers_id', $id)
            ->whereNull('d.deleted_at');

        $dons = DB::table('dons as dn')
            ->leftJoin('comptes_bancaires as cb', 'cb.id', '=', 'dn.compte_id')
            ->selectRaw("dn.id, 'don' as source_type, dn.date, dn.objet as libelle, cb.nom as compte, dn.montant")
            ->where('dn.tiers_id', $id)
            ->whereNull('dn.deleted_at');

        $cotisations = DB::table('cotisations as c')
            ->leftJoin('comptes_bancaires as cb', 'cb.id', '=', 'c.compte_id')
            ->selectRaw("c.id, 'cotisation' as source_type, c.date_paiement as date, CONCAT('Cotisation ', c.exercice) as libelle, cb.nom as compte, c.montant")
            ->where('c.tiers_id', $id)
            ->whereNull('c.deleted_at');

        $union = $recettes
            ->unionAll($depenses)
            ->unionAll($dons)
            ->unionAll($cotisations);

        $allowed = ['date', 'source_type', 'montant'];
        $sortBy  = in_array($sortBy, $allowed, true) ? $sortBy : 'date';
        $sortDir = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

        $query = DB::query()->fromSub($union, 't');

        if ($typeFilter !== '') {
            $query->where('source_type', $typeFilter);
        }
        if ($dateDebut !== '') {
            $query->where('date', '>=', $dateDebut);
        }
        if ($dateFin !== '') {
            $query->where('date', '<=', $dateFin);
        }
        if ($search !== '') {
            $query->where('libelle', 'like', '%' . $search . '%');
        }

        return $query->orderBy($sortBy, $sortDir)->paginate($perPage);
    }
}
```

- [ ] **Step 4: Lancer les tests — vérifier qu'ils passent**

```bash
./vendor/bin/sail php artisan test tests/Feature/Services/TiersTransactionServiceTest.php
```

Attendu : PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/TiersTransactionService.php tests/Feature/Services/TiersTransactionServiceTest.php
git commit -m "feat: TiersTransactionService — UNION paginée par tiers"
```

---

## Chunk 2: Composant Livewire TiersTransactions

### Task 2: Composant + vue blade

**Files:**
- Create: `app/Livewire/TiersTransactions.php`
- Create: `resources/views/livewire/tiers-transactions.blade.php`
- Test: `tests/Livewire/TiersTransactionsTest.php`

- [ ] **Step 1: Écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

use App\Livewire\TiersTransactions;
use App\Models\Depense;
use App\Models\Don;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user  = User::factory()->create();
    $this->tiers = Tiers::factory()->create(['nom' => 'Dupont']);
});

it('renders the component', function (): void {
    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->assertOk()
        ->assertSee('Dupont');
});

it('affiche un message quand aucune transaction', function (): void {
    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->assertSee('Aucune transaction');
});

it('affiche les dépenses du tiers', function (): void {
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Achat test']);

    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->assertSee('Achat test');
});

it('filtre par type', function (): void {
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Ma dépense']);
    Don::factory()->create(['tiers_id' => $this->tiers->id, 'objet' => 'Mon don']);

    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->set('typeFilter', 'don')
        ->assertSee('Mon don')
        ->assertDontSee('Ma dépense');
});

it('filtre par recherche texte', function (): void {
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Frais transport']);
    Depense::factory()->create(['tiers_id' => $this->tiers->id, 'libelle' => 'Loyer bureau']);

    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->set('search', 'Loyer')
        ->assertSee('Loyer bureau')
        ->assertDontSee('Frais transport');
});

it('bascule la direction du tri sur la même colonne', function (): void {
    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->call('sort', 'montant')
        ->assertSet('sortBy', 'montant')
        ->assertSet('sortDir', 'asc')
        ->call('sort', 'montant')
        ->assertSet('sortDir', 'desc');
});

it('remet sortDir à asc quand on change de colonne', function (): void {
    Livewire::actingAs($this->user)
        ->test(TiersTransactions::class, ['tiersId' => $this->tiers->id])
        ->call('sort', 'montant')
        ->call('sort', 'date')
        ->assertSet('sortBy', 'date')
        ->assertSet('sortDir', 'asc');
});
```

- [ ] **Step 2: Lancer les tests — vérifier qu'ils échouent**

```bash
./vendor/bin/sail php artisan test tests/Livewire/TiersTransactionsTest.php
```

Attendu : FAIL — classe non trouvée.

- [ ] **Step 3: Créer le composant**

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\TiersTransactionService;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class TiersTransactions extends Component
{
    use WithPagination;

    public int $tiersId;

    public string $typeFilter = '';
    public string $dateDebut  = '';
    public string $dateFin    = '';
    public string $search     = '';
    public string $sortBy     = 'date';
    public string $sortDir    = 'desc';

    public function sort(string $col): void
    {
        if ($this->sortBy === $col) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy  = $col;
            $this->sortDir = 'asc';
        }
        $this->resetPage();
    }

    public function updatedTypeFilter(): void { $this->resetPage(); }
    public function updatedDateDebut(): void  { $this->resetPage(); }
    public function updatedDateFin(): void    { $this->resetPage(); }
    public function updatedSearch(): void     { $this->resetPage(); }

    public function render(): View
    {
        $tiers = Tiers::findOrFail($this->tiersId);

        $transactions = app(TiersTransactionService::class)->paginate(
            $tiers,
            $this->typeFilter,
            $this->dateDebut,
            $this->dateFin,
            $this->search,
            $this->sortBy,
            $this->sortDir,
        );

        return view('livewire.tiers-transactions', compact('tiers', 'transactions'));
    }
}
```

- [ ] **Step 4: Créer la vue blade `resources/views/livewire/tiers-transactions.blade.php`**

```blade
<div>
    {{-- Filtres --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select wire:model.live="typeFilter" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <option value="depense">Dépense</option>
                        <option value="recette">Recette</option>
                        <option value="don">Don</option>
                        <option value="cotisation">Cotisation</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Du</label>
                    <input type="date" wire:model.live="dateDebut" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Au</label>
                    <input type="date" wire:model.live="dateFin" class="form-control form-control-sm">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Libellé</label>
                    <input type="text" wire:model.live.debounce.300ms="search"
                           class="form-control form-control-sm" placeholder="Rechercher…">
                </div>
            </div>
        </div>
    </div>

    {{-- Tableau --}}
    <div class="table-responsive">
        <table class="table table-sm table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th style="cursor:pointer" wire:click="sort('date')">
                        Date @if($sortBy === 'date') {{ $sortDir === 'asc' ? '▲' : '▼' }} @endif
                    </th>
                    <th style="cursor:pointer" wire:click="sort('source_type')">
                        Type @if($sortBy === 'source_type') {{ $sortDir === 'asc' ? '▲' : '▼' }} @endif
                    </th>
                    <th>Libellé</th>
                    <th>Compte</th>
                    <th class="text-end" style="cursor:pointer" wire:click="sort('montant')">
                        Montant @if($sortBy === 'montant') {{ $sortDir === 'asc' ? '▲' : '▼' }} @endif
                    </th>
                </tr>
            </thead>
            <tbody>
                @forelse($transactions as $tx)
                    <tr>
                        <td class="text-nowrap">{{ \Carbon\Carbon::parse($tx->date)->format('d/m/Y') }}</td>
                        <td>
                            @php
                                $badgeClass = match($tx->source_type) {
                                    'recette'    => 'bg-success',
                                    'depense'    => 'bg-danger',
                                    'don'        => 'bg-info',
                                    'cotisation' => 'bg-secondary',
                                    default      => 'bg-light text-dark',
                                };
                                $label = match($tx->source_type) {
                                    'recette'    => 'Recette',
                                    'depense'    => 'Dépense',
                                    'don'        => 'Don',
                                    'cotisation' => 'Cotisation',
                                    default      => $tx->source_type,
                                };
                            @endphp
                            <span class="badge {{ $badgeClass }}">{{ $label }}</span>
                        </td>
                        <td>{{ $tx->libelle }}</td>
                        <td>{{ $tx->compte ?? '—' }}</td>
                        <td class="text-end text-nowrap fw-semibold @if(in_array($tx->source_type, ['recette','don'])) text-success @else text-danger @endif">
                            {{ number_format((float) $tx->montant, 2, ',', ' ') }} €
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">Aucune transaction trouvée.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $transactions->links() }}
</div>
```

- [ ] **Step 5: Lancer les tests**

```bash
./vendor/bin/sail php artisan test tests/Livewire/TiersTransactionsTest.php
```

Attendu : PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/TiersTransactions.php resources/views/livewire/tiers-transactions.blade.php tests/Livewire/TiersTransactionsTest.php
git commit -m "feat: composant TiersTransactions — liste paginée avec filtres et tri"
```

---

## Chunk 3: Route, vue layout et bouton dans tiers-list

### Task 3: Route + vue layout + bouton

**Files:**
- Create: `resources/views/tiers/transactions.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/livewire/tiers-list.blade.php`

- [ ] **Step 1: Écrire le test HTTP pour la route**

Dans `tests/Feature/TiersTransactionsRouteTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('affiche la page transactions d\'un tiers existant', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'Martin']);

    $this->actingAs($this->user)
        ->get(route('tiers.transactions', $tiers))
        ->assertOk()
        ->assertSee('Martin');
});

it('retourne 404 pour un tiers inexistant', function (): void {
    $this->actingAs($this->user)
        ->get(route('tiers.transactions', 9999))
        ->assertNotFound();
});

it('redirige les guests vers login', function (): void {
    $tiers = Tiers::factory()->create();

    $this->get(route('tiers.transactions', $tiers))
        ->assertRedirect('/login');
});
```

- [ ] **Step 2: Lancer les tests — vérifier qu'ils échouent**

```bash
./vendor/bin/sail php artisan test tests/Feature/TiersTransactionsRouteTest.php
```

Attendu : FAIL — route non trouvée.

- [ ] **Step 3: Ajouter la route dans `routes/web.php`**

Dans le groupe `middleware('auth')`, après la ligne `Route::view('/tiers', 'tiers.index')->name('tiers.index');`, ajouter :

```php
Route::get('/tiers/{tiers}/transactions', function (\App\Models\Tiers $tiers) {
    return view('tiers.transactions', compact('tiers'));
})->name('tiers.transactions');
```

- [ ] **Step 4: Créer la vue layout `resources/views/tiers/transactions.blade.php`**

```blade
<x-app-layout>
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="{{ route('tiers.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Tiers
        </a>
        <h1 class="mb-0 h4">Transactions — {{ $tiers->displayName() }}</h1>
    </div>

    <livewire:tiers-transactions :tiersId="$tiers->id" />
</x-app-layout>
```

- [ ] **Step 5: Ajouter le bouton dans `resources/views/livewire/tiers-list.blade.php`**

Dans la colonne actions, entre le bouton modifier et le bouton supprimer :

Remplacer :
```blade
                        <td class="text-end">
                            <button
                                class="btn btn-sm btn-outline-primary me-1"
                                wire:click="$dispatch('edit-tiers', { id: {{ $tiers->id }} })"
                                title="Modifier"
                            ><i class="bi bi-pencil"></i></button>
                            <button
                                class="btn btn-sm btn-outline-danger"
                                wire:click="delete({{ $tiers->id }})"
                                wire:confirm="Supprimer ce tiers ?"
                                title="Supprimer"
                            ><i class="bi bi-trash"></i></button>
                        </td>
```

Par :
```blade
                        <td class="text-end">
                            <a href="{{ route('tiers.transactions', $tiers->id) }}"
                               class="btn btn-sm btn-outline-secondary me-1"
                               title="Transactions">
                                <i class="bi bi-clock-history"></i>
                            </a>
                            <button
                                class="btn btn-sm btn-outline-primary me-1"
                                wire:click="$dispatch('edit-tiers', { id: {{ $tiers->id }} })"
                                title="Modifier"
                            ><i class="bi bi-pencil"></i></button>
                            <button
                                class="btn btn-sm btn-outline-danger"
                                wire:click="delete({{ $tiers->id }})"
                                wire:confirm="Supprimer ce tiers ?"
                                title="Supprimer"
                            ><i class="bi bi-trash"></i></button>
                        </td>
```

- [ ] **Step 6: Lancer tous les tests**

```bash
./vendor/bin/sail php artisan test tests/Feature/TiersTransactionsRouteTest.php tests/Livewire/TiersTransactionsTest.php tests/Feature/Services/TiersTransactionServiceTest.php
```

Attendu : PASS (16 tests).

- [ ] **Step 7: Commit**

```bash
git add routes/web.php resources/views/tiers/transactions.blade.php resources/views/livewire/tiers-list.blade.php tests/Feature/TiersTransactionsRouteTest.php
git commit -m "feat: route et bouton transactions par tiers"
```

- [ ] **Step 8: Lancer la suite complète**

```bash
./vendor/bin/sail php artisan test
```

Attendu : 0 échec.
