# Pagination Per-Page Selector Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add per-page selector (15/20/25/50/100/Tous, default 20) with localStorage persistence, first/last page buttons (⟪/⟫), and result counter to all 10 paginated Livewire lists.

**Architecture:** Shared PHP trait `WithPerPage` (server-side state), Blade component `<x-per-page-selector>` (Alpine.js + localStorage + result counter), and a customized Livewire Bootstrap pagination view (adds «/» first/last buttons, removes built-in counter to avoid duplication). Applied to 8 standard components directly and 2 service-based components via named/positional parameter forwarding.

**Tech Stack:** Laravel 11, Livewire 4, Alpine.js (bundled with Livewire 4), Bootstrap 5 (CDN), browser localStorage API, Pest PHP.

---

## File Structure

**New files:**
- `app/Livewire/Concerns/WithPerPage.php` — Trait: `$perPage = 20`, `updatedPerPage()`, `effectivePerPage()`
- `resources/views/components/per-page-selector.blade.php` — Alpine select + localStorage + result counter
- `resources/views/vendor/livewire/bootstrap/pagination.blade.php` — Custom pagination view with first/last buttons

**Modified PHP files (8 standard components):**
- `app/Livewire/DepenseList.php` — add trait, `paginate(15)` → `paginate($this->effectivePerPage())`
- `app/Livewire/RecetteList.php` — same (15)
- `app/Livewire/CotisationList.php` — same (15)
- `app/Livewire/DonList.php` — same (15)
- `app/Livewire/VirementInterneList.php` — same (20)
- `app/Livewire/RapprochementList.php` — same (20)
- `app/Livewire/MembreList.php` — same (50)
- `app/Livewire/TiersList.php` — same (20)

**Modified PHP files (2 service-based components):**
- `app/Livewire/TiersTransactions.php` — add trait, pass `$this->effectivePerPage()` as 8th positional arg to `TiersTransactionService::paginate()`
- `app/Livewire/TransactionCompteList.php` — add trait + missing `$paginationTheme`, add `perPage:` named arg to `TransactionCompteService::paginate()`

**Modified Blade files (all 10):**
- Add `<x-per-page-selector :paginator="$var" storageKey="key" wire:model.live="perPage" />` before `{{ $var->links() }}` in each list view.

Paginator variable → storageKey mapping:
| Blade file | Variable | storageKey |
|---|---|---|
| depense-list | `$depenses` | `depenses` |
| recette-list | `$recettes` | `recettes` |
| cotisation-list | `$cotisations` | `cotisations` |
| don-list | `$dons` | `dons` |
| virement-interne-list | `$virements` | `virements` |
| rapprochement-list | `$rapprochements` | `rapprochements` |
| membre-list | `$membres` | `membres` |
| tiers-list | `$tiersList` | `tiers` |
| tiers-transactions | `$transactions` | `tiers-transactions` |
| transaction-compte-list | `$paginator` | `transaction-compte` |

---

## Task 1: Create custom Livewire pagination view with first/last buttons

**Files:**
- Create: `resources/views/vendor/livewire/bootstrap/pagination.blade.php`

The directory `resources/views/vendor/livewire/bootstrap/` does not exist yet — create it with the file.

This view is based on the source at `vendor/livewire/livewire/src/Features/SupportPagination/views/bootstrap.blade.php` with two changes:
1. Add first-page (`«`) and last-page (`»`) buttons flanking the existing prev/next
2. Remove the "Showing X to Y of Z results" counter (the `<x-per-page-selector>` component handles this)

- [ ] **Step 1: Create the custom pagination view**

Create `resources/views/vendor/livewire/bootstrap/pagination.blade.php`:

```blade
@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav class="d-flex justify-items-center justify-content-between">
            {{-- Mobile layout --}}
            <div class="d-flex justify-content-between flex-fill d-sm-none">
                <ul class="pagination">
                    {{-- First Page --}}
                    @if ($paginator->onFirstPage())
                        <li class="page-item disabled" aria-disabled="true">
                            <span class="page-link" aria-hidden="true">&laquo;</span>
                        </li>
                    @else
                        <li class="page-item">
                            <button type="button" class="page-link"
                                    wire:click="gotoPage(1, '{{ $paginator->getPageName() }}')"
                                    x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                    wire:loading.attr="disabled"
                                    aria-label="Première page">&laquo;</button>
                        </li>
                    @endif

                    {{-- Previous Page --}}
                    @if ($paginator->onFirstPage())
                        <li class="page-item disabled" aria-disabled="true">
                            <span class="page-link">@lang('pagination.previous')</span>
                        </li>
                    @else
                        <li class="page-item">
                            <button type="button" class="page-link"
                                    wire:click="previousPage('{{ $paginator->getPageName() }}')"
                                    x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                    wire:loading.attr="disabled">@lang('pagination.previous')</button>
                        </li>
                    @endif

                    {{-- Next Page --}}
                    @if ($paginator->hasMorePages())
                        <li class="page-item">
                            <button type="button" class="page-link"
                                    wire:click="nextPage('{{ $paginator->getPageName() }}')"
                                    x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                    wire:loading.attr="disabled">@lang('pagination.next')</button>
                        </li>
                    @else
                        <li class="page-item disabled" aria-disabled="true">
                            <span class="page-link" aria-hidden="true">@lang('pagination.next')</span>
                        </li>
                    @endif

                    {{-- Last Page --}}
                    @if ($paginator->hasMorePages())
                        <li class="page-item">
                            <button type="button" class="page-link"
                                    wire:click="gotoPage({{ $paginator->lastPage() }}, '{{ $paginator->getPageName() }}')"
                                    x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                    wire:loading.attr="disabled"
                                    aria-label="Dernière page">&raquo;</button>
                        </li>
                    @else
                        <li class="page-item disabled" aria-disabled="true">
                            <span class="page-link" aria-hidden="true">&raquo;</span>
                        </li>
                    @endif
                </ul>
            </div>

            {{-- Desktop layout --}}
            <div class="d-none flex-sm-fill d-sm-flex align-items-sm-center justify-content-sm-end">
                <div>
                    <ul class="pagination">
                        {{-- First Page --}}
                        @if ($paginator->onFirstPage())
                            <li class="page-item disabled" aria-disabled="true" aria-label="Première page">
                                <span class="page-link" aria-hidden="true">&laquo;</span>
                            </li>
                        @else
                            <li class="page-item">
                                <button type="button" class="page-link"
                                        wire:click="gotoPage(1, '{{ $paginator->getPageName() }}')"
                                        x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                        wire:loading.attr="disabled"
                                        aria-label="Première page">&laquo;</button>
                            </li>
                        @endif

                        {{-- Previous Page --}}
                        @if ($paginator->onFirstPage())
                            <li class="page-item disabled" aria-disabled="true" aria-label="@lang('pagination.previous')">
                                <span class="page-link" aria-hidden="true">&lsaquo;</span>
                            </li>
                        @else
                            <li class="page-item">
                                <button type="button"
                                        dusk="previousPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}"
                                        class="page-link"
                                        wire:click="previousPage('{{ $paginator->getPageName() }}')"
                                        x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                        wire:loading.attr="disabled"
                                        aria-label="@lang('pagination.previous')">&lsaquo;</button>
                            </li>
                        @endif

                        {{-- Pagination Elements --}}
                        @foreach ($elements as $element)
                            {{-- "Three Dots" Separator --}}
                            @if (is_string($element))
                                <li class="page-item disabled" aria-disabled="true">
                                    <span class="page-link">{{ $element }}</span>
                                </li>
                            @endif

                            {{-- Array Of Links --}}
                            @if (is_array($element))
                                @foreach ($element as $page => $url)
                                    @if ($page == $paginator->currentPage())
                                        <li class="page-item active"
                                            wire:key="paginator-{{ $paginator->getPageName() }}-page-{{ $page }}"
                                            aria-current="page">
                                            <span class="page-link">{{ $page }}</span>
                                        </li>
                                    @else
                                        <li class="page-item"
                                            wire:key="paginator-{{ $paginator->getPageName() }}-page-{{ $page }}">
                                            <button type="button" class="page-link"
                                                    wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                                                    x-on:click="{{ $scrollIntoViewJsSnippet }}">{{ $page }}</button>
                                        </li>
                                    @endif
                                @endforeach
                            @endif
                        @endforeach

                        {{-- Next Page --}}
                        @if ($paginator->hasMorePages())
                            <li class="page-item">
                                <button type="button"
                                        dusk="nextPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}"
                                        class="page-link"
                                        wire:click="nextPage('{{ $paginator->getPageName() }}')"
                                        x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                        wire:loading.attr="disabled"
                                        aria-label="@lang('pagination.next')">&rsaquo;</button>
                            </li>
                        @else
                            <li class="page-item disabled" aria-disabled="true" aria-label="@lang('pagination.next')">
                                <span class="page-link" aria-hidden="true">&rsaquo;</span>
                            </li>
                        @endif

                        {{-- Last Page --}}
                        @if ($paginator->hasMorePages())
                            <li class="page-item">
                                <button type="button" class="page-link"
                                        wire:click="gotoPage({{ $paginator->lastPage() }}, '{{ $paginator->getPageName() }}')"
                                        x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                        wire:loading.attr="disabled"
                                        aria-label="Dernière page">&raquo;</button>
                            </li>
                        @else
                            <li class="page-item disabled" aria-disabled="true" aria-label="Dernière page">
                                <span class="page-link" aria-hidden="true">&raquo;</span>
                            </li>
                        @endif
                    </ul>
                </div>
            </div>
        </nav>
    @endif
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/vendor/livewire/bootstrap/pagination.blade.php
git commit -m "feat: custom Livewire Bootstrap pagination view with first/last page buttons"
```

---

## Task 2: Create WithPerPage trait and verify with DepenseList

**Files:**
- Create: `app/Livewire/Concerns/WithPerPage.php`
- Modify: `app/Livewire/DepenseList.php` (also used to verify the trait works before applying everywhere)
- Modify: `tests/Livewire/DepenseListTest.php` (add perPage tests)

- [ ] **Step 1: Write failing tests in DepenseListTest.php**

Add these two tests at the end of `tests/Livewire/DepenseListTest.php`:

```php
it('has default perPage of 20', function () {
    Livewire::test(DepenseList::class)
        ->assertSet('perPage', 20);
});

it('resets to page 1 when perPage changes', function () {
    Livewire::test(DepenseList::class)
        ->set('perPage', 50)
        ->assertSet('page', 1);
});
```

- [ ] **Step 2: Run to verify failure**

```bash
./vendor/bin/sail artisan test tests/Livewire/DepenseListTest.php --filter="perPage"
```
Expected: FAIL – `perPage` property not found on component

- [ ] **Step 3: Create the trait**

Create `app/Livewire/Concerns/WithPerPage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

trait WithPerPage
{
    public int $perPage = 20;

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function effectivePerPage(): int
    {
        return $this->perPage === 0 ? PHP_INT_MAX : $this->perPage;
    }
}
```

- [ ] **Step 4: Apply trait to DepenseList**

In `app/Livewire/DepenseList.php`:

Add import after the existing `use Livewire\WithPagination;` import:
```php
use App\Livewire\Concerns\WithPerPage;
```

Add `use WithPerPage;` inside the class body after `use WithPagination;`:
```php
use WithPagination;
use WithPerPage;
```

Change `->paginate(15)` in `render()` to:
```php
'depenses' => $query->paginate($this->effectivePerPage()),
```

- [ ] **Step 5: Run tests to verify pass**

```bash
./vendor/bin/sail artisan test tests/Livewire/DepenseListTest.php
```
Expected: all passing

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Concerns/WithPerPage.php app/Livewire/DepenseList.php tests/Livewire/DepenseListTest.php
git commit -m "feat: WithPerPage trait (default 20, effectivePerPage), applied to DepenseList"
```

---

## Task 3: Create x-per-page-selector Blade component

**Files:**
- Create: `resources/views/components/per-page-selector.blade.php`

The component takes a `$paginator` (nullable `LengthAwarePaginator`) and `$storageKey` (string). It forwards `wire:model` attributes to the select element via `$attributes`. On Alpine `init()`, reads localStorage and dispatches a `change` event so `wire:model.live` picks it up. On `change`, writes to localStorage.

- [ ] **Step 1: Create the component**

```blade
@props([
    'paginator'  => null,
    'storageKey' => '',
])

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-2 mb-1"
     x-data="{
         key: 'perPage.{{ $storageKey }}',
         init() {
             const saved = localStorage.getItem(this.key);
             if (saved !== null) {
                 this.$refs.select.value = saved;
                 this.$refs.select.dispatchEvent(new Event('change'));
             }
         }
     }">
    <small class="text-muted">
        @if ($paginator && $paginator->total() > 0)
            Affichage <strong>{{ $paginator->firstItem() }}</strong>–<strong>{{ $paginator->lastItem() }}</strong>
            sur <strong>{{ $paginator->total() }}</strong>
        @elseif ($paginator)
            Aucun résultat
        @endif
    </small>
    <div class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 text-muted small">Lignes par page :</label>
        <select x-ref="select"
                x-on:change="localStorage.setItem(key, $event.target.value)"
                class="form-select form-select-sm w-auto"
                {{ $attributes->filter(fn($v, $k) => str_starts_with($k, 'wire:')) }}>
            <option value="15">15</option>
            <option value="20">20</option>
            <option value="25">25</option>
            <option value="50">50</option>
            <option value="100">100</option>
            <option value="0">Tous</option>
        </select>
    </div>
</div>
```

- [ ] **Step 2: Add selector to depense-list.blade.php** (already has WithPerPage from Task 2)

In `resources/views/livewire/depense-list.blade.php`, find the line:
```blade
    {{ $depenses->links() }}
```
And replace with:
```blade
    <x-per-page-selector :paginator="$depenses" storageKey="depenses" wire:model.live="perPage" />
    {{ $depenses->links() }}
```

- [ ] **Step 3: Verify in browser**

Navigate to the dépenses list (http://localhost/depenses). The per-page selector should appear with:
- Counter showing "Affichage 1–20 sur N"
- Select defaulted to 20
- Change to 50 → list reloads with 50 rows
- Reload page → stays at 50 (localStorage)
- Pagination bar shows « ‹ [pages] › »

- [ ] **Step 4: Commit**

```bash
git add resources/views/components/per-page-selector.blade.php resources/views/livewire/depense-list.blade.php
git commit -m "feat: x-per-page-selector component with localStorage, applied to depense-list"
```

---

## Task 4: Apply WithPerPage to remaining 7 standard components

**Files:**
- Modify: `app/Livewire/RecetteList.php`, `CotisationList.php`, `DonList.php`, `VirementInterneList.php`, `RapprochementList.php`, `MembreList.php`, `TiersList.php`
- Modify: `resources/views/livewire/recette-list.blade.php`, `cotisation-list.blade.php`, `don-list.blade.php`, `virement-interne-list.blade.php`, `rapprochement-list.blade.php`, `membre-list.blade.php`, `tiers-list.blade.php`

For each PHP component, apply the same changes as DepenseList in Task 2:
1. Add `use App\Livewire\Concerns\WithPerPage;` import
2. Add `use WithPerPage;` in the class body
3. Change `->paginate(N)` to `->paginate($this->effectivePerPage())`

For each Blade view, add before `{{ $var->links() }}`:
```blade
<x-per-page-selector :paginator="$VAR" storageKey="KEY" wire:model.live="perPage" />
```

**RapprochementList note:** The paginate call is at line 97 and may be chained on its own line — just change the argument.

**MembreList note:** The paginate call is `$query->orderBy('nom')->paginate(50)` assigned directly to `$membres` — change to `->paginate($this->effectivePerPage())`.

- [ ] **Step 1: Update RecetteList.php**

```php
use App\Livewire\Concerns\WithPerPage;
// in class body after use WithPagination;:
use WithPerPage;
// in render():
'recettes' => $query->paginate($this->effectivePerPage()),
```

- [ ] **Step 2: Update CotisationList.php**

```php
use App\Livewire\Concerns\WithPerPage;
use WithPerPage;
'cotisations' => $query->paginate($this->effectivePerPage()),
```

- [ ] **Step 3: Update DonList.php**

```php
use App\Livewire\Concerns\WithPerPage;
use WithPerPage;
'dons' => $query->paginate($this->effectivePerPage()),
```

- [ ] **Step 4: Update VirementInterneList.php**

```php
use App\Livewire\Concerns\WithPerPage;
use WithPerPage;
->paginate($this->effectivePerPage())
```

- [ ] **Step 5: Update RapprochementList.php**

```php
use App\Livewire\Concerns\WithPerPage;
use WithPerPage;
->paginate($this->effectivePerPage())
```

- [ ] **Step 6: Update MembreList.php**

```php
use App\Livewire\Concerns\WithPerPage;
use WithPerPage;
$membres = $query->orderBy('nom')->paginate($this->effectivePerPage());
```

- [ ] **Step 7: Update TiersList.php**

```php
use App\Livewire\Concerns\WithPerPage;
use WithPerPage;
'tiersList' => $query->paginate($this->effectivePerPage()),
```

- [ ] **Step 8: Update all 7 blade views**

For each file, find the `{{ $var->links() }}` line and add the selector before it:

**recette-list.blade.php:**
```blade
    <x-per-page-selector :paginator="$recettes" storageKey="recettes" wire:model.live="perPage" />
    {{ $recettes->links() }}
```

**cotisation-list.blade.php:**
```blade
    <x-per-page-selector :paginator="$cotisations" storageKey="cotisations" wire:model.live="perPage" />
    {{ $cotisations->links() }}
```

**don-list.blade.php:**
```blade
    <x-per-page-selector :paginator="$dons" storageKey="dons" wire:model.live="perPage" />
    {{ $dons->links() }}
```

**virement-interne-list.blade.php:**
```blade
    <x-per-page-selector :paginator="$virements" storageKey="virements" wire:model.live="perPage" />
    {{ $virements->links() }}
```

**rapprochement-list.blade.php:** The `{{ $rapprochements->links() }}` is inside a conditional block at line 141 — add the selector on the line above it, still inside the same block.
```blade
    <x-per-page-selector :paginator="$rapprochements" storageKey="rapprochements" wire:model.live="perPage" />
    {{ $rapprochements->links() }}
```

**membre-list.blade.php:**
```blade
    <x-per-page-selector :paginator="$membres" storageKey="membres" wire:model.live="perPage" />
    {{ $membres->links() }}
```

**tiers-list.blade.php:**
```blade
    <x-per-page-selector :paginator="$tiersList" storageKey="tiers" wire:model.live="perPage" />
    {{ $tiersList->links() }}
```

- [ ] **Step 9: Run tests**

```bash
./vendor/bin/sail artisan test tests/Livewire/
```
Expected: all passing

- [ ] **Step 10: Commit**

```bash
git add app/Livewire/RecetteList.php app/Livewire/CotisationList.php app/Livewire/DonList.php \
        app/Livewire/VirementInterneList.php app/Livewire/RapprochementList.php \
        app/Livewire/MembreList.php app/Livewire/TiersList.php \
        resources/views/livewire/recette-list.blade.php \
        resources/views/livewire/cotisation-list.blade.php \
        resources/views/livewire/don-list.blade.php \
        resources/views/livewire/virement-interne-list.blade.php \
        resources/views/livewire/rapprochement-list.blade.php \
        resources/views/livewire/membre-list.blade.php \
        resources/views/livewire/tiers-list.blade.php
git commit -m "feat: apply WithPerPage to remaining 7 standard Livewire list components"
```

---

## Task 5: Apply WithPerPage to TiersTransactions (service-based)

**Files:**
- Modify: `app/Livewire/TiersTransactions.php`
- Modify: `resources/views/livewire/tiers-transactions.blade.php`

`TiersTransactionService::paginate()` signature:
```php
paginate(Tiers $tiers, string $typeFilter, string $dateDebut, string $dateFin,
         string $search, string $sortBy, string $sortDir, int $perPage = 50)
```
`$this->effectivePerPage()` is passed as the 8th positional argument.

- [ ] **Step 1: Update TiersTransactions.php**

Add import:
```php
use App\Livewire\Concerns\WithPerPage;
```

Add `use WithPerPage;` after `use WithPagination;` in the class body.

Update the service call in `render()`:
```php
$transactions = app(TiersTransactionService::class)->paginate(
    $tiers,
    $this->typeFilter,
    $this->dateDebut,
    $this->dateFin,
    $this->search,
    $this->sortBy,
    $this->sortDir,
    $this->effectivePerPage(),
);
```

- [ ] **Step 2: Update tiers-transactions.blade.php**

Find `{{ $transactions->links() }}` and replace with:
```blade
    <x-per-page-selector :paginator="$transactions" storageKey="tiers-transactions" wire:model.live="perPage" />
    {{ $transactions->links() }}
```

- [ ] **Step 3: Run tests**

```bash
./vendor/bin/sail artisan test tests/Livewire/TiersTransactionsTest.php
```
Expected: passing

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/TiersTransactions.php resources/views/livewire/tiers-transactions.blade.php
git commit -m "feat: apply WithPerPage to TiersTransactions (service-based)"
```

---

## Task 6: Apply WithPerPage to TransactionCompteList (service-based)

**Files:**
- Modify: `app/Livewire/TransactionCompteList.php`
- Modify: `resources/views/livewire/transaction-compte-list.blade.php`

**Notes:**
- `TransactionCompteList` is missing `protected string $paginationTheme = 'bootstrap';` — add it
- When `$compteId` is null, `$paginator` is null — the `<x-per-page-selector>` handles this gracefully (shows nothing in the counter section)
- `TransactionCompteService::paginate()` uses named arguments already in the component; add `perPage:` before `page:`

`TransactionCompteService::paginate()` signature:
```php
paginate(CompteBancaire $compte, ?string $dateDebut, ?string $dateFin,
         ?string $searchTiers, string $sortColumn, string $sortDirection,
         int $perPage = 15, int $page = 1)
```

- [ ] **Step 1: Update TransactionCompteList.php**

Add import:
```php
use App\Livewire\Concerns\WithPerPage;
```

Add in the class body (after `use WithPagination;`):
```php
use WithPagination;
use WithPerPage;

protected string $paginationTheme = 'bootstrap';
```

Update service call — add `perPage:` named argument before `page:`:
```php
$result = app(TransactionCompteService::class)->paginate(
    compte: $compte,
    dateDebut: $this->dateDebut ?: null,
    dateFin: $this->dateFin ?: null,
    searchTiers: $this->searchTiers ?: null,
    sortColumn: $this->sortColumn,
    sortDirection: $this->sortDirection,
    perPage: $this->effectivePerPage(),
    page: $this->getPage(),
);
```

- [ ] **Step 2: Update transaction-compte-list.blade.php**

The `{{ $paginator->links() }}` is inside an `@if ($paginator)` block. Add the selector inside the same block, just before it:
```blade
    <x-per-page-selector :paginator="$paginator" storageKey="transaction-compte" wire:model.live="perPage" />
    {{ $paginator->links() }}
```

- [ ] **Step 3: Run full test suite**

```bash
./vendor/bin/sail artisan test tests/Livewire/ tests/Feature/
```
Expected: all passing

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/TransactionCompteList.php resources/views/livewire/transaction-compte-list.blade.php
git commit -m "feat: apply WithPerPage to TransactionCompteList, fix missing paginationTheme"
```
