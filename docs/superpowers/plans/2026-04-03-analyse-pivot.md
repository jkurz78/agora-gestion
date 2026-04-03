# Analyse Pivot Table Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a prototype "Analyse" page in the Gestion space with two interactive pivot table views (Participants/Règlements and Financière) powered by PivotTable.js.

**Architecture:** A single Livewire component `AnalysePivot` serves two pre-built flat datasets as JSON. PivotTable.js (loaded from CDN with jQuery/jQueryUI) renders the interactive pivot UI client-side. A toggle switches between the two views, an exercice selector filters the data.

**Tech Stack:** Laravel 11, Livewire 4, PivotTable.js 2.23.0 (CDN), jQuery 3.7.1 (CDN), jQueryUI 1.13.2 (CDN), Bootstrap 5

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `app/Livewire/AnalysePivot.php` | Livewire component: exercice filter, two data queries, JSON output |
| Create | `resources/views/livewire/analyse-pivot.blade.php` | UI: exercice selector, view toggle, PivotTable.js container + CDN scripts |
| Create | `resources/views/gestion/analyse/index.blade.php` | Wrapper: app-layout + livewire component |
| Modify | `routes/web.php:~119` | Add route for `/gestion/analyse` |
| Modify | `resources/views/layouts/app.blade.php:~298` | Add "Analyse" menu item in Gestion navbar |
| Create | `tests/Feature/Livewire/AnalysePivotTest.php` | Basic rendering and data tests |

---

### Task 1: Livewire Component — Data Queries

**Files:**
- Create: `app/Livewire/AnalysePivot.php`
- Create: `tests/Feature/Livewire/AnalysePivotTest.php`

- [ ] **Step 1: Write the test — component renders**

```php
<?php

declare(strict_types=1);

use App\Livewire\AnalysePivot;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    view()->share('espace', \App\Enums\Espace::Gestion);
});

it('renders the analyse pivot component', function () {
    Livewire::test(AnalysePivot::class)
        ->assertOk()
        ->assertSee('Analyse');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Livewire/AnalysePivotTest.php`
Expected: FAIL — class AnalysePivot not found

- [ ] **Step 3: Create the Livewire component**

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CompteBancaire;
use App\Models\Reglement;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class AnalysePivot extends Component
{
    #[Url(as: 'exercice')]
    public ?int $filterExercice = null;

    #[Url(as: 'vue')]
    public string $activeView = 'participants';

    public function mount(): void
    {
        if ($this->filterExercice === null) {
            $this->filterExercice = app(ExerciceService::class)->current();
        }
    }

    public function switchView(string $view): void
    {
        $this->activeView = $view;
    }

    /** @return list<array<string, mixed>> */
    public function getParticipantsDataProperty(): array
    {
        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($this->filterExercice ?? $exerciceService->current());

        return Reglement::query()
            ->join('participants', 'participants.id', '=', 'reglements.participant_id')
            ->join('tiers', 'tiers.id', '=', 'participants.tiers_id')
            ->join('seances', 'seances.id', '=', 'reglements.seance_id')
            ->join('operations', 'operations.id', '=', 'participants.operation_id')
            ->join('type_operations', 'type_operations.id', '=', 'operations.type_operation_id')
            ->leftJoin('presences', function ($join) {
                $join->on('presences.participant_id', '=', 'participants.id')
                    ->on('presences.seance_id', '=', 'seances.id');
            })
            ->whereBetween('seances.date', [$range['start'], $range['end']])
            ->select([
                'operations.nom as Opération',
                'type_operations.nom as Type opération',
                DB::raw("CONCAT(seances.numero, ' - ', seances.titre) as Séance"),
                'seances.date as Date séance',
                'tiers.nom as Nom',
                'tiers.prenom as Prénom',
                'tiers.ville as Ville',
                'participants.date_inscription as Date inscription',
                'reglements.mode_paiement as Mode paiement',
                'reglements.montant_prevu as Montant prévu',
                'presences.statut as Présence',
            ])
            ->get()
            ->map(function ($row) {
                $data = (array) $row->getAttributes();
                $data['Date séance'] = $row->getAttribute('Date séance')
                    ? \Carbon\Carbon::parse($row->getAttribute('Date séance'))->format('d/m/Y')
                    : null;
                $data['Date inscription'] = $row->getAttribute('Date inscription')
                    ? \Carbon\Carbon::parse($row->getAttribute('Date inscription'))->format('d/m/Y')
                    : null;
                $data['Montant prévu'] = (float) ($data['Montant prévu'] ?? 0);

                return $data;
            })
            ->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function getFinancierDataProperty(): array
    {
        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($this->filterExercice ?? $exerciceService->current());

        return TransactionLigne::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->join('tiers', 'tiers.id', '=', 'transactions.tiers_id')
            ->join('sous_categories', 'sous_categories.id', '=', 'transaction_lignes.sous_categorie_id')
            ->join('categories', 'categories.id', '=', 'sous_categories.categorie_id')
            ->join('comptes_bancaires', 'comptes_bancaires.id', '=', 'transactions.compte_id')
            ->leftJoin('operations', 'operations.id', '=', 'transaction_lignes.operation_id')
            ->leftJoin('type_operations', 'type_operations.id', '=', 'operations.type_operation_id')
            ->whereBetween('transactions.date', [$range['start'], $range['end']])
            ->whereNull('transaction_lignes.deleted_at')
            ->select([
                'operations.nom as Opération',
                'type_operations.nom as Type opération',
                'transaction_lignes.seance as Séance n°',
                DB::raw("CASE WHEN tiers.type = 'entreprise' THEN COALESCE(tiers.entreprise, tiers.nom) ELSE CONCAT(COALESCE(tiers.prenom, ''), ' ', tiers.nom) END as Tiers"),
                'tiers.type as Type tiers',
                'transactions.date as Date',
                'transaction_lignes.montant as Montant',
                'sous_categories.nom as Sous-catégorie',
                'categories.nom as Catégorie',
                'transactions.type as Type',
                'comptes_bancaires.nom as Compte',
            ])
            ->get()
            ->map(function ($row) {
                $data = (array) $row->getAttributes();
                $data['Date'] = $row->getAttribute('Date')
                    ? \Carbon\Carbon::parse($row->getAttribute('Date'))->format('d/m/Y')
                    : null;
                $data['Montant'] = (float) ($data['Montant'] ?? 0);

                return $data;
            })
            ->toArray();
    }

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);

        return view('livewire.analyse-pivot', [
            'exerciceYears' => $exerciceService->availableYears(),
            'pivotData' => $this->activeView === 'participants'
                ? $this->participantsData
                : $this->financierData,
        ]);
    }
}
```

- [ ] **Step 4: Create a placeholder Blade view**

Create `resources/views/livewire/analyse-pivot.blade.php` with:

```blade
<div>
    <h4>Analyse</h4>
</div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Livewire/AnalysePivotTest.php`
Expected: PASS

- [ ] **Step 6: Write test — data queries return arrays**

Add to `tests/Feature/Livewire/AnalysePivotTest.php`:

```php
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;

it('returns participants data with correct fields', function () {
    $typeOp = TypeOperation::factory()->create();
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
    ]);
    $tiers = Tiers::factory()->create([
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'ville' => 'Paris',
    ]);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
        'date' => '2026-01-20',
        'titre' => 'Séance test',
    ]);
    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => 'CB',
        'montant_prevu' => 25.00,
    ]);

    $component = Livewire::test(AnalysePivot::class);
    $data = $component->get('participantsData');

    expect($data)->toBeArray()->not->toBeEmpty();
    expect($data[0])->toHaveKeys([
        'Opération', 'Type opération', 'Séance', 'Nom', 'Prénom',
        'Ville', 'Mode paiement', 'Montant prévu',
    ]);
    expect($data[0]['Nom'])->toBe('Dupont');
    expect($data[0]['Montant prévu'])->toBe(25.0);
});

it('returns financier data with correct fields', function () {
    $compte = CompteBancaire::first() ?? CompteBancaire::factory()->create();
    $tiers = Tiers::factory()->create(['nom' => 'Fournisseur', 'pour_depenses' => true]);
    $sousCategorie = SousCategorie::first();
    $transaction = Transaction::create([
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'type' => 'depense',
        'date' => '2026-01-15',
        'libelle' => 'Test',
        'montant_total' => 100.00,
        'mode_paiement' => 'virement',
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => 100.00,
    ]);

    $component = Livewire::test(AnalysePivot::class)
        ->set('activeView', 'financier');
    $data = $component->get('financierData');

    expect($data)->toBeArray()->not->toBeEmpty();
    expect($data[0])->toHaveKeys([
        'Tiers', 'Date', 'Montant', 'Sous-catégorie', 'Catégorie', 'Type', 'Compte',
    ]);
    expect($data[0]['Montant'])->toBe(100.0);
});
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/Livewire/AnalysePivotTest.php -v`
Expected: All 3 tests PASS

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/AnalysePivot.php resources/views/livewire/analyse-pivot.blade.php tests/Feature/Livewire/AnalysePivotTest.php
git commit -m "feat: add AnalysePivot Livewire component with two data views"
```

---

### Task 2: Blade View with PivotTable.js

**Files:**
- Overwrite: `resources/views/livewire/analyse-pivot.blade.php`

- [ ] **Step 1: Create the full Blade view**

```blade
<div>
    {{-- Header with exercice selector and view toggle --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-graph-up me-2"></i>Analyse</h4>
        <div class="d-flex gap-3 align-items-center">
            <div class="btn-group btn-group-sm" role="group">
                <button type="button"
                        class="btn {{ $activeView === 'participants' ? 'btn-primary' : 'btn-outline-primary' }}"
                        wire:click="switchView('participants')">
                    <i class="bi bi-people me-1"></i>Participants / Règlements
                </button>
                <button type="button"
                        class="btn {{ $activeView === 'financier' ? 'btn-primary' : 'btn-outline-primary' }}"
                        wire:click="switchView('financier')">
                    <i class="bi bi-cash-stack me-1"></i>Financière
                </button>
            </div>
            <select class="form-select form-select-sm" style="width:auto" wire:model.live="filterExercice">
                @foreach($exerciceYears as $year)
                    <option value="{{ $year }}">{{ $year }}/{{ $year + 1 }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Pivot table container --}}
    <div id="pivot-output" class="border rounded bg-white p-2"
         wire:ignore
         wire:key="pivot-{{ $activeView }}-{{ $filterExercice }}">
    </div>

    {{-- CDN dependencies (loaded only on this page) --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.min.css">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.14.1/jquery-ui.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.fr.min.js"></script>

    <script>
        document.addEventListener('livewire:navigated', initPivot);
        document.addEventListener('DOMContentLoaded', initPivot);

        // Re-init when Livewire re-renders (view or exercice change)
        Livewire.hook('morph.updated', ({ el }) => {
            if (el.id === 'pivot-output' || el.closest?.('#pivot-output')) {
                setTimeout(initPivot, 50);
            }
        });

        function initPivot() {
            if (typeof jQuery === 'undefined' || typeof jQuery.pivotUI === 'undefined') {
                setTimeout(initPivot, 100);
                return;
            }

            var data = @json($pivotData);
            var view = @json($activeView);

            var defaults = view === 'participants'
                ? { rows: ["Opération"], vals: ["Montant prévu"], aggregatorName: "Somme" }
                : { rows: ["Opération"], vals: ["Montant"], aggregatorName: "Somme" };

            jQuery("#pivot-output").empty().pivotUI(data, Object.assign({
                locale: "fr",
                cols: [],
                rendererName: "Table",
            }, defaults));
        }
    </script>

    <style>
        /* Harmonize PivotTable.js with app style */
        .pvtUi { width: 100%; }
        .pvtTable { font-size: 0.85rem; }
        .pvtAxisContainer, .pvtVals { background: #f8f9fa; border-color: #dee2e6 !important; }
        .pvtFilterBox { font-size: 0.85rem; }
        .pvtTable tbody tr td { padding: 4px 8px; }
        .pvtTable thead tr th { background-color: #3d5473; color: white; padding: 4px 8px; }
    </style>
</div>
```

- [ ] **Step 2: Run tests to verify nothing is broken**

Run: `./vendor/bin/sail test tests/Feature/Livewire/AnalysePivotTest.php -v`
Expected: All 3 tests PASS

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/analyse-pivot.blade.php
git commit -m "feat: Blade view with PivotTable.js CDN integration and two-view toggle"
```

---

### Task 3: Route, Menu, and Page Wrapper

**Files:**
- Modify: `routes/web.php:~119`
- Modify: `resources/views/layouts/app.blade.php:~298`
- Create: `resources/views/gestion/analyse/index.blade.php`

- [ ] **Step 1: Create the page wrapper view**

Create `resources/views/gestion/analyse/index.blade.php`:

```blade
<x-app-layout>
    <livewire:analyse-pivot />
</x-app-layout>
```

- [ ] **Step 2: Add the route**

In `routes/web.php`, find the gestion route group (the block starting with `Route::middleware(['auth', DetecteEspace::class.':gestion'])`) and add after the existing `Route::view('/operations', ...)` line:

```php
        Route::view('/analyse', 'gestion.analyse.index')->name('analyse');
```

- [ ] **Step 3: Add the menu item**

In `resources/views/layouts/app.blade.php`, find the Gestion navbar section (inside `@if(($espace ?? null) === \App\Enums\Espace::Gestion)`). Add after the Adhérents `<li>` block and before the Opérations dropdown:

```blade
                                {{-- Analyse --}}
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs('gestion.analyse*') ? 'active' : '' }}"
                                       href="{{ route('gestion.analyse') }}">
                                        <i class="bi bi-graph-up me-1"></i> Analyse
                                    </a>
                                </li>
```

- [ ] **Step 4: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: All tests PASS, no regressions

- [ ] **Step 5: Run Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint --test`
If fixes needed: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 6: Commit**

```bash
git add routes/web.php resources/views/layouts/app.blade.php resources/views/gestion/analyse/index.blade.php
git commit -m "feat: route, menu et page wrapper pour écran Analyse"
```
