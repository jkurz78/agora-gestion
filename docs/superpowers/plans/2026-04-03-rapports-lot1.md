# Lot 1 — Réorganisation Rapports + Analyse Financière — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transformer la page rapports à onglets en écrans dédiés avec dropdown navbar, et dédoubler le pivot table entre gestion (participants) et compta (financier).

**Architecture:** 3 nouvelles routes view sous `/compta/rapports/`, dropdown Bootstrap dans la navbar, composant AnalysePivot paramétré par `$mode` au lieu d'un toggle. Pas de changement sur RapportService ni les modèles.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, PivotTable.js 2.23.0, Pest PHP

---

### Task 1: Routes — nouvelles routes rapports + redirect

**Files:**
- Modify: `routes/web.php:97` (remplacer la route rapports.index)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RapportRoutesTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('responds 200 on compte-resultat page', function () {
    $this->get('/compta/rapports/compte-resultat')->assertOk();
});

it('responds 200 on operations page', function () {
    $this->get('/compta/rapports/operations')->assertOk();
});

it('responds 200 on analyse page', function () {
    $this->get('/compta/rapports/analyse')->assertOk();
});

it('redirects old /compta/rapports to compte-resultat', function () {
    $this->get('/compta/rapports')
        ->assertRedirect('/compta/rapports/compte-resultat');
});

it('redirects legacy /rapports to compte-resultat', function () {
    $this->get('/rapports')
        ->assertRedirect('/compta/rapports/compte-resultat');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/RapportRoutesTest.php`
Expected: FAIL — routes don't exist yet, views don't exist yet.

- [ ] **Step 3: Create placeholder views**

Create `resources/views/rapports/compte-resultat.blade.php`:
```blade
<x-app-layout>
    <h1 class="mb-4">Compte de résultat</h1>
    <livewire:rapport-compte-resultat />
</x-app-layout>
```

Create `resources/views/rapports/operations.blade.php`:
```blade
<x-app-layout>
    <h1 class="mb-4">Compte de résultat par opération(s)</h1>
    <livewire:rapport-compte-resultat-operations />
</x-app-layout>
```

Create `resources/views/rapports/analyse.blade.php`:
```blade
<x-app-layout>
    {{-- PivotTable.js CDN dependencies --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.min.css">
    <style>
        .pvtUi { width: 100%; }
        .pvtTable { font-size: 0.85rem; }
        .pvtAxisContainer, .pvtVals { background: #f8f9fa; border-color: #dee2e6 !important; }
        .pvtFilterBox { font-size: 0.85rem; }
        .pvtTable td, .pvtTable th { padding: 4px 8px; color: #212529; }
        .pvtTotalLabel, .pvtTotal, .pvtGrandTotal { font-weight: bold; background-color: #e9ecef; }
        .pvtAxisLabel { background-color: #3d5473 !important; color: white !important; }
    </style>

    <h1 class="mb-4">Analyse financière</h1>
    <livewire:analyse-pivot mode="financier" />

    @push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.14.1/jquery-ui.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pivottable/2.23.0/pivot.fr.min.js"></script>
    @endpush
</x-app-layout>
```

- [ ] **Step 4: Update routes**

In `routes/web.php`, replace line 97:
```php
Route::view('/rapports', 'rapports.index')->name('rapports.index');
```

With:
```php
// Rapports — écrans dédiés
Route::view('/rapports/compte-resultat', 'rapports.compte-resultat')->name('rapports.compte-resultat');
Route::view('/rapports/operations', 'rapports.operations')->name('rapports.operations');
Route::view('/rapports/analyse', 'rapports.analyse')->name('rapports.analyse');
Route::redirect('/rapports', '/compta/rapports/compte-resultat', 301)->name('rapports.index');
```

Also update the legacy redirect at line 190, change:
```php
Route::permanentRedirect('/rapports', '/compta/rapports');
```
To:
```php
Route::permanentRedirect('/rapports', '/compta/rapports/compte-resultat');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/RapportRoutesTest.php`
Expected: All 5 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/RapportRoutesTest.php routes/web.php resources/views/rapports/compte-resultat.blade.php resources/views/rapports/operations.blade.php resources/views/rapports/analyse.blade.php
git commit -m "feat(rapports): écrans dédiés avec routes + redirect 301"
```

---

### Task 2: Navigation — dropdown Rapports dans la navbar

**Files:**
- Modify: `resources/views/layouts/app.blade.php:227-242`

- [ ] **Step 1: Replace the Rapports nav item**

In `resources/views/layouts/app.blade.php`, the `$navItems` array at line 228 currently includes Rapports. Remove it from the array so it only contains Budget:

```php
@php
    $navItems = [
        ['route' => 'compta.budget.index',   'icon' => 'piggy-bank',             'label' => 'Budget'],
    ];
@endphp
```

Then, after the `@endforeach` that renders `$navItems` (around line 242), and **before** the Factures `<li>`, add the Rapports dropdown:

```blade
{{-- Dropdown Rapports --}}
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle {{ request()->routeIs('compta.rapports.*') ? 'active' : '' }}"
       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-file-earmark-bar-graph"></i> Rapports
    </a>
    <ul class="dropdown-menu">
        <li>
            <a class="dropdown-item {{ request()->routeIs('compta.rapports.compte-resultat') ? 'active' : '' }}"
               href="{{ route('compta.rapports.compte-resultat') }}">
                Compte de résultat
            </a>
        </li>
        <li>
            <a class="dropdown-item {{ request()->routeIs('compta.rapports.operations') ? 'active' : '' }}"
               href="{{ route('compta.rapports.operations') }}">
                Compte de résultat par opérations
            </a>
        </li>
        <li><hr class="dropdown-divider"></li>
        <li>
            <a class="dropdown-item {{ request()->routeIs('compta.rapports.analyse') ? 'active' : '' }}"
               href="{{ route('compta.rapports.analyse') }}">
                <i class="bi bi-graph-up me-1"></i>Analyse financière
            </a>
        </li>
    </ul>
</li>
```

- [ ] **Step 2: Run existing tests to verify no regression**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/RapportRoutesTest.php`
Expected: All PASS (routes still work with new nav).

- [ ] **Step 3: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat(rapports): dropdown navbar avec sous-items"
```

---

### Task 3: AnalysePivot — paramètre `$mode`, suppression toggle

**Files:**
- Modify: `app/Livewire/AnalysePivot.php`
- Modify: `resources/views/livewire/analyse-pivot.blade.php`
- Modify: `resources/views/gestion/analyse/index.blade.php`
- Modify: `tests/Feature/Livewire/AnalysePivotTest.php`

- [ ] **Step 1: Update existing tests + add mode tests**

Replace `tests/Feature/Livewire/AnalysePivotTest.php` with:

```php
<?php

declare(strict_types=1);

use App\Enums\Espace;
use App\Livewire\AnalysePivot;
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
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    view()->share('espace', Espace::Gestion);
});

it('renders in participants mode without toggle buttons', function () {
    Livewire::test(AnalysePivot::class, ['mode' => 'participants'])
        ->assertOk()
        ->assertSee('Analyse')
        ->assertDontSee('Financière');
});

it('renders in financier mode without toggle buttons', function () {
    Livewire::test(AnalysePivot::class, ['mode' => 'financier'])
        ->assertOk()
        ->assertSee('Analyse')
        ->assertDontSee('Participants / Règlements');
});

it('defaults to participants mode', function () {
    $component = Livewire::test(AnalysePivot::class, ['mode' => 'participants']);
    expect($component->get('mode'))->toBe('participants');
});

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
        'mode_paiement' => 'cb',
        'montant_prevu' => 25.00,
    ]);

    $component = Livewire::test(AnalysePivot::class, ['mode' => 'participants']);
    $data = $component->get('participantsData');

    expect($data)->toBeArray()->not->toBeEmpty();
    expect($data[0])->toHaveKeys([
        'Opération', 'Type opération', 'Séance', 'Nom', 'Prénom',
        'Ville', 'Mode paiement', 'Montant prévu',
    ]);
    expect($data[0]['Nom'])->toBe('Dupont');
    expect($data[0]['Montant prévu'])->toBe(25.0);
});

it('returns financier data with correct fields including temporal dimensions', function () {
    $compte = CompteBancaire::factory()->create();
    $tiers = Tiers::factory()->create(['nom' => 'Fournisseur', 'pour_depenses' => true]);
    $sousCategorie = SousCategorie::factory()->create();
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

    $component = Livewire::test(AnalysePivot::class, ['mode' => 'financier']);
    $data = $component->get('financierData');

    expect($data)->toBeArray()->not->toBeEmpty();
    expect($data[0])->toHaveKeys([
        'Tiers', 'Date', 'Montant', 'Sous-catégorie', 'Catégorie', 'Type', 'Compte',
        'Mois', 'Trimestre', 'Semestre',
    ]);
    expect($data[0]['Montant'])->toBe(100.0);
    // January 2026 → exercice 2025 → T2 (Dec-Feb), S1 (Sept-Feb)
    expect($data[0]['Mois'])->toBe('Janvier 2026');
    expect($data[0]['Trimestre'])->toBe('T2 2025-2026');
    expect($data[0]['Semestre'])->toBe('S1 2025-2026');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Livewire/AnalysePivotTest.php`
Expected: FAIL — `mode` param not accepted yet, toggle buttons still present.

- [ ] **Step 3: Update AnalysePivot component**

Replace `app/Livewire/AnalysePivot.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Reglement;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class AnalysePivot extends Component
{
    #[Url(as: 'exercice')]
    public ?int $filterExercice = null;

    public string $mode = 'participants';

    public function mount(string $mode = 'participants'): void
    {
        $this->mode = $mode;

        if ($this->filterExercice === null) {
            $this->filterExercice = app(ExerciceService::class)->current();
        }
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
                    ? Carbon::parse($row->getAttribute('Date séance'))->format('d/m/Y')
                    : null;
                $data['Date inscription'] = $row->getAttribute('Date inscription')
                    ? Carbon::parse($row->getAttribute('Date inscription'))->format('d/m/Y')
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
        $exercice = $this->filterExercice ?? $exerciceService->current();
        $range = $exerciceService->dateRange($exercice);

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
            ->map(function ($row) use ($exercice) {
                $data = (array) $row->getAttributes();
                $date = $row->getAttribute('Date')
                    ? Carbon::parse($row->getAttribute('Date'))
                    : null;
                $data['Date'] = $date?->format('d/m/Y');
                $data['Montant'] = (float) ($data['Montant'] ?? 0);

                // Temporal dimensions
                if ($date) {
                    $data['Mois'] = ucfirst($date->translatedFormat('F Y'));
                    $data['Trimestre'] = $this->trimestre($date, $exercice);
                    $data['Semestre'] = $this->semestre($date, $exercice);
                } else {
                    $data['Mois'] = null;
                    $data['Trimestre'] = null;
                    $data['Semestre'] = null;
                }

                return $data;
            })
            ->toArray();
    }

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);

        return view('livewire.analyse-pivot', [
            'exerciceYears' => $exerciceService->availableYears(),
            'pivotData' => $this->mode === 'participants'
                ? $this->participantsData
                : $this->financierData,
        ]);
    }

    /**
     * Map a date to its trimestre within the exercice (Sept-Aug).
     * T1: Sept-Nov, T2: Dec-Feb, T3: Mar-May, T4: Jun-Aug
     */
    private function trimestre(Carbon $date, int $exercice): string
    {
        $month = $date->month;
        $t = match (true) {
            in_array($month, [9, 10, 11]) => 'T1',
            in_array($month, [12, 1, 2]) => 'T2',
            in_array($month, [3, 4, 5]) => 'T3',
            default => 'T4',
        };

        return $t.' '.$exercice.'-'.($exercice + 1);
    }

    /**
     * Map a date to its semestre within the exercice (Sept-Aug).
     * S1: Sept-Feb, S2: Mar-Aug
     */
    private function semestre(Carbon $date, int $exercice): string
    {
        $month = $date->month;
        $s = ($month >= 9 || $month <= 2) ? 'S1' : 'S2';

        return $s.' '.$exercice.'-'.($exercice + 1);
    }
}
```

- [ ] **Step 4: Update the Livewire blade — remove toggle**

Replace `resources/views/livewire/analyse-pivot.blade.php` with:

```blade
<div>
    {{-- Header with exercice selector --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-graph-up me-2"></i>Analyse</h4>
        <div class="d-flex gap-3 align-items-center">
            <select class="form-select form-select-sm" style="width:auto" wire:model.live="filterExercice">
                @foreach($exerciceYears as $year)
                    <option value="{{ $year }}">{{ $year }}/{{ $year + 1 }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Data carrier + pivot container --}}
    <div id="pivot-wrapper" data-pivot='@json($pivotData)' data-view="{{ $mode }}">
        <div id="pivot-output" wire:ignore class="border rounded bg-white p-2"></div>
    </div>

    @script
    <script>
        function renderPivot() {
            var wrapper = document.getElementById('pivot-wrapper');
            var el = document.getElementById('pivot-output');
            if (!wrapper || !el || typeof jQuery === 'undefined' || typeof jQuery.fn.pivotUI === 'undefined') {
                setTimeout(renderPivot, 100);
                return;
            }

            var data = JSON.parse(wrapper.dataset.pivot || '[]');
            var view = wrapper.dataset.view || 'participants';

            var defaults = view === 'participants'
                ? { rows: ["Opération"], vals: ["Montant prévu"], aggregatorName: "Somme" }
                : { rows: ["Catégorie"], vals: ["Montant"], aggregatorName: "Somme" };

            jQuery(el).empty().pivotUI(data, Object.assign({
                locale: "fr",
                cols: [],
                rendererName: "Table",
            }, defaults));
        }

        renderPivot();
        $wire.$watch('filterExercice', () => setTimeout(renderPivot, 100));
    </script>
    @endscript
</div>
```

- [ ] **Step 5: Update gestion analyse page to pass mode**

In `resources/views/gestion/analyse/index.blade.php`, change line 14:
```blade
<livewire:analyse-pivot />
```
To:
```blade
<livewire:analyse-pivot mode="participants" />
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Livewire/AnalysePivotTest.php`
Expected: All 6 tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/AnalysePivot.php resources/views/livewire/analyse-pivot.blade.php resources/views/gestion/analyse/index.blade.php tests/Feature/Livewire/AnalysePivotTest.php
git commit -m "feat(rapports): AnalysePivot paramétré par mode, dimensions temporelles"
```

---

### Task 4: Cleanup — supprimer les fichiers obsolètes

**Files:**
- Delete: `resources/views/rapports/index.blade.php`
- Delete: `app/Livewire/RapportSeances.php`
- Delete: `resources/views/livewire/rapport-seances.blade.php`
- Delete: `tests/Livewire/RapportSeancesTest.php`

- [ ] **Step 1: Remove test for RapportSeances first**

Delete `tests/Livewire/RapportSeancesTest.php`.

- [ ] **Step 2: Remove component and views**

Delete these files:
- `app/Livewire/RapportSeances.php`
- `resources/views/livewire/rapport-seances.blade.php`
- `resources/views/rapports/index.blade.php`

- [ ] **Step 3: Run full test suite to verify no regression**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test`
Expected: All tests PASS — no references to deleted files remain.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore(rapports): suppression page onglets + RapportSeances (lot 2)"
```

---

### Task 5: Run Pint + vérification finale

**Files:** Any auto-formatted files

- [ ] **Step 1: Run Pint**

Run: `./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint`

- [ ] **Step 2: Run full test suite**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test`
Expected: All tests PASS.

- [ ] **Step 3: Commit if Pint changed anything**

```bash
git add -A
git commit -m "style: apply Pint formatting"
```
