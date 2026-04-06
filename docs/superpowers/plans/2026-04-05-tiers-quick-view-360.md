# Tiers Quick View 360° — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a rich Bootstrap popover showing a 360° summary of a Tiers (contact info, transactions, participations, referrals, invoices) invocable from anywhere via an info icon.

**Architecture:** A Blade component `<x-tiers-info-icon>` dispatches a browser event. A single global Livewire component `TiersQuickView` in the app layout receives the event, calls `TiersQuickViewService::getSummary()` to aggregate data, and renders a Bootstrap 5 popover anchored to the clicked icon. Sections are conditional — only shown when data exists.

**Tech Stack:** Laravel 11, Livewire 4, Alpine.js, Bootstrap 5 (CDN), Pest PHP

**Key discovery:** `TypeTransaction` only has `Depense` and `Recette`. Dons, cotisations, and inscriptions are recettes where `transaction_lignes.sous_categorie` has `pour_dons`, `pour_cotisations`, or `pour_inscriptions` set to true. The service must join through `transaction_lignes → sous_categories` to distinguish them.

---

## File Structure

### Files to create
| File | Responsibility |
|---|---|
| `app/Services/TiersQuickViewService.php` | Aggregates all data for the quick view (transactions by type, participations, referrals, invoices) |
| `app/Livewire/TiersQuickView.php` | Livewire component: receives tiersId via event, calls service, manages exercice selector |
| `resources/views/livewire/tiers-quick-view.blade.php` | Rich popover HTML: header, conditional sections, links |
| `resources/views/components/tiers-info-icon.blade.php` | Simple Blade component: icon button that dispatches browser event |
| `tests/Feature/Services/TiersQuickViewServiceTest.php` | Tests for the aggregation service |
| `tests/Feature/Livewire/TiersQuickViewTest.php` | Tests for the Livewire component |

### Files to modify
| File | Change |
|---|---|
| `resources/views/layouts/app.blade.php` | Add `<livewire:tiers-quick-view />` after existing global components |
| `resources/views/livewire/tiers-autocomplete.blade.php` | Add `<x-tiers-info-icon>` in the selected pill |
| `resources/views/livewire/tiers-list.blade.php` | Add `<x-tiers-info-icon>` next to tiers name |
| `resources/views/livewire/transaction-universelle.blade.php` | Add `<x-tiers-info-icon>` in tiers column |
| `resources/views/livewire/participant-table.blade.php` | Add `<x-tiers-info-icon>` next to "Référé par" |
| `resources/views/livewire/participant-show.blade.php` | Add `<x-tiers-info-icon>` next to médecin/thérapeute/référent tiers |
| `resources/views/livewire/facture-list.blade.php` | Add `<x-tiers-info-icon>` next to tiers name |

---

## Task 1: TiersQuickViewService — Tests

**Files:**
- Create: `tests/Feature/Services/TiersQuickViewServiceTest.php`

- [ ] **Step 1: Write the test file with all service tests**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\Facture;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\TiersQuickViewService;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(TiersQuickViewService::class);
    // Exercice 2025 = Sept 2025 → Aug 2026
    $this->exercice = 2025;
});

test('returns empty sections for tiers with no activity', function (): void {
    $tiers = Tiers::factory()->create();

    $summary = $this->service->getSummary($tiers, $this->exercice);

    expect($summary)->toHaveKey('contact')
        ->not->toHaveKey('depenses')
        ->not->toHaveKey('recettes')
        ->not->toHaveKey('dons')
        ->not->toHaveKey('cotisations')
        ->not->toHaveKey('participations')
        ->not->toHaveKey('referent')
        ->not->toHaveKey('factures');
});

test('returns contact info', function (): void {
    $tiers = Tiers::factory()->create([
        'email' => 'test@example.com',
        'telephone' => '06 12 34 56 78',
    ]);

    $summary = $this->service->getSummary($tiers, $this->exercice);

    expect($summary['contact'])->toBe([
        'email' => 'test@example.com',
        'telephone' => '06 12 34 56 78',
    ]);
});

test('aggregates depenses with operation breakdown', function (): void {
    $tiers = Tiers::factory()->create();
    $operation = Operation::factory()->create(['nom' => 'Atelier Yoga']);
    $sousCategorie = SousCategorie::factory()->create(['nom' => 'Animation']);

    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'tiers_id' => $tiers->id,
        'date' => '2025-10-15',
        'montant_total' => 300,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'operation_id' => $operation->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => 300,
    ]);

    $summary = $this->service->getSummary($tiers, $this->exercice);

    expect($summary)->toHaveKey('depenses');
    expect($summary['depenses']['count'])->toBe(1);
    expect((float) $summary['depenses']['total'])->toBe(300.0);
    expect($summary['depenses']['par_operation'])->toHaveCount(1);
    expect($summary['depenses']['par_operation'][0]['operation_nom'])->toBe('Atelier Yoga');
    expect($summary['depenses']['par_operation'][0]['sous_categorie'])->toBe('Animation');
});

test('aggregates recettes excluding dons and cotisations', function (): void {
    $tiers = Tiers::factory()->create();
    $scNormale = SousCategorie::factory()->create([
        'pour_dons' => false,
        'pour_cotisations' => false,
    ]);

    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'tiers_id' => $tiers->id,
        'date' => '2025-11-01',
        'montant_total' => 500,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $scNormale->id,
        'montant' => 500,
    ]);

    $summary = $this->service->getSummary($tiers, $this->exercice);

    expect($summary)->toHaveKey('recettes');
    expect($summary['recettes']['count'])->toBe(1);
    expect((float) $summary['recettes']['total'])->toBe(500.0);
    expect($summary)->not->toHaveKey('dons')
        ->not->toHaveKey('cotisations');
});

test('aggregates dons separately', function (): void {
    $tiers = Tiers::factory()->create();
    $scDon = SousCategorie::factory()->create(['pour_dons' => true]);

    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'tiers_id' => $tiers->id,
        'date' => '2025-12-01',
        'montant_total' => 200,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $scDon->id,
        'montant' => 200,
    ]);

    $summary = $this->service->getSummary($tiers, $this->exercice);

    expect($summary)->toHaveKey('dons');
    expect($summary['dons']['count'])->toBe(1);
    expect((float) $summary['dons']['total'])->toBe(200.0);
});

test('aggregates cotisations separately', function (): void {
    $tiers = Tiers::factory()->create();
    $scCotis = SousCategorie::factory()->create(['pour_cotisations' => true]);

    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'tiers_id' => $tiers->id,
        'date' => '2026-01-15',
        'montant_total' => 50,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $scCotis->id,
        'montant' => 50,
    ]);

    $summary = $this->service->getSummary($tiers, $this->exercice);

    expect($summary)->toHaveKey('cotisations');
    expect($summary['cotisations']['count'])->toBe(1);
    expect((float) $summary['cotisations']['total'])->toBe(50.0);
});

test('includes participations with operation details', function (): void {
    $tiers = Tiers::factory()->create();
    $operation = Operation::factory()->create([
        'nom' => 'Stage été',
        'date_debut' => '2026-01-10',
    ]);
    Participant::factory()->create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
    ]);

    $summary = $this->service->getSummary($tiers, $this->exercice);

    expect($summary)->toHaveKey('participations');
    expect($summary['participations'])->toHaveCount(1);
    expect($summary['participations'][0]['operation_nom'])->toBe('Stage été');
});

test('includes referent data when user has sensible access', function (): void {
    $user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->actingAs($user);

    $tiers = Tiers::factory()->create();
    $tiersParticipant = Tiers::factory()->create(['prenom' => 'Marie', 'nom' => 'Dupont']);
    $operation = Operation::factory()->create();
    Participant::factory()->create([
        'tiers_id' => $tiersParticipant->id,
        'operation_id' => $operation->id,
        'refere_par_id' => $tiers->id,
    ]);

    $summary = $this->service->getSummary($tiers, $this->exercice);

    expect($summary)->toHaveKey('referent');
    expect($summary['referent']['refere_par'])->toHaveCount(1);
});

test('excludes referent data when user lacks sensible access', function (): void {
    $user = User::factory()->create(['peut_voir_donnees_sensibles' => false]);
    $this->actingAs($user);

    $tiers = Tiers::factory()->create();
    $tiersParticipant = Tiers::factory()->create();
    $operation = Operation::factory()->create();
    Participant::factory()->create([
        'tiers_id' => $tiersParticipant->id,
        'operation_id' => $operation->id,
        'refere_par_id' => $tiers->id,
    ]);

    $summary = $this->service->getSummary($tiers, $this->exercice);

    expect($summary)->not->toHaveKey('referent');
});

test('aggregates factures with impayees count', function (): void {
    $tiers = Tiers::factory()->create();

    Facture::factory()->create([
        'tiers_id' => $tiers->id,
        'exercice' => $this->exercice,
        'statut' => 'validee',
        'montant_total' => 1000,
    ]);
    Facture::factory()->create([
        'tiers_id' => $tiers->id,
        'exercice' => $this->exercice,
        'statut' => 'brouillon',
        'montant_total' => 500,
    ]);

    $summary = $this->service->getSummary($tiers, $this->exercice);

    expect($summary)->toHaveKey('factures');
    expect($summary['factures']['count'])->toBe(2);
    expect((float) $summary['factures']['total'])->toBe(1500.0);
});

test('ignores transactions outside exercice', function (): void {
    $tiers = Tiers::factory()->create();

    Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'tiers_id' => $tiers->id,
        'date' => '2024-06-15', // Outside exercice 2025
        'montant_total' => 100,
    ]);

    $summary = $this->service->getSummary($tiers, $this->exercice);

    expect($summary)->not->toHaveKey('depenses');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/Services/TiersQuickViewServiceTest.php`
Expected: FAIL — `TiersQuickViewService` class not found

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Services/TiersQuickViewServiceTest.php
git commit -m "test: add TiersQuickViewService tests (red)"
```

---

## Task 2: TiersQuickViewService — Implementation

**Files:**
- Create: `app/Services/TiersQuickViewService.php`

- [ ] **Step 1: Create the service**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeTransaction;
use App\Models\Facture;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

final class TiersQuickViewService
{
    public function getSummary(Tiers $tiers, int $exercice): array
    {
        $summary = [
            'contact' => [
                'email' => $tiers->getRawOriginal('email'),
                'telephone' => $tiers->telephone,
            ],
        ];

        $this->addDepenses($summary, $tiers, $exercice);
        $this->addRecettes($summary, $tiers, $exercice);
        $this->addDons($summary, $tiers, $exercice);
        $this->addCotisations($summary, $tiers, $exercice);
        $this->addParticipations($summary, $tiers);
        $this->addReferent($summary, $tiers);
        $this->addFactures($summary, $tiers, $exercice);

        return $summary;
    }

    private function addDepenses(array &$summary, Tiers $tiers, int $exercice): void
    {
        $depenses = Transaction::where('tiers_id', $tiers->id)
            ->where('type', TypeTransaction::Depense)
            ->forExercice($exercice)
            ->get();

        if ($depenses->isEmpty()) {
            return;
        }

        // Breakdown by operation via transaction_lignes
        $lignes = DB::table('transaction_lignes')
            ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->leftJoin('operations', 'operations.id', '=', 'transaction_lignes.operation_id')
            ->leftJoin('sous_categories', 'sous_categories.id', '=', 'transaction_lignes.sous_categorie_id')
            ->where('transactions.tiers_id', $tiers->id)
            ->where('transactions.type', TypeTransaction::Depense->value)
            ->whereBetween('transactions.date', ["{$exercice}-09-01", ($exercice + 1) . '-08-31'])
            ->whereNull('transactions.deleted_at')
            ->whereNull('transaction_lignes.deleted_at')
            ->select([
                'operations.id as operation_id',
                'operations.nom as operation_nom',
                'sous_categories.nom as sous_categorie',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(transaction_lignes.montant) as total'),
            ])
            ->groupBy('operations.id', 'operations.nom', 'sous_categories.nom')
            ->get();

        $parOperation = $lignes
            ->filter(fn ($row) => $row->operation_id !== null)
            ->map(fn ($row) => [
                'operation_id' => $row->operation_id,
                'operation_nom' => $row->operation_nom,
                'sous_categorie' => $row->sous_categorie,
                'count' => (int) $row->count,
                'total' => (float) $row->total,
            ])
            ->values()
            ->all();

        $summary['depenses'] = [
            'count' => $depenses->count(),
            'total' => (float) $depenses->sum('montant_total'),
            'par_operation' => $parOperation,
        ];
    }

    private function addRecettes(array &$summary, Tiers $tiers, int $exercice): void
    {
        // Recettes = type recette WHERE sous_categorie is NOT pour_dons and NOT pour_cotisations
        $result = DB::table('transactions')
            ->join('transaction_lignes', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->join('sous_categories', 'sous_categories.id', '=', 'transaction_lignes.sous_categorie_id')
            ->where('transactions.tiers_id', $tiers->id)
            ->where('transactions.type', TypeTransaction::Recette->value)
            ->where('sous_categories.pour_dons', false)
            ->where('sous_categories.pour_cotisations', false)
            ->whereBetween('transactions.date', ["{$exercice}-09-01", ($exercice + 1) . '-08-31'])
            ->whereNull('transactions.deleted_at')
            ->whereNull('transaction_lignes.deleted_at')
            ->select([
                DB::raw('COUNT(DISTINCT transactions.id) as count'),
                DB::raw('SUM(transaction_lignes.montant) as total'),
            ])
            ->first();

        if ($result && (int) $result->count > 0) {
            $summary['recettes'] = [
                'count' => (int) $result->count,
                'total' => (float) $result->total,
            ];
        }
    }

    private function addDons(array &$summary, Tiers $tiers, int $exercice): void
    {
        $result = DB::table('transactions')
            ->join('transaction_lignes', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->join('sous_categories', 'sous_categories.id', '=', 'transaction_lignes.sous_categorie_id')
            ->where('transactions.tiers_id', $tiers->id)
            ->where('transactions.type', TypeTransaction::Recette->value)
            ->where('sous_categories.pour_dons', true)
            ->whereBetween('transactions.date', ["{$exercice}-09-01", ($exercice + 1) . '-08-31'])
            ->whereNull('transactions.deleted_at')
            ->whereNull('transaction_lignes.deleted_at')
            ->select([
                DB::raw('COUNT(DISTINCT transactions.id) as count'),
                DB::raw('SUM(transaction_lignes.montant) as total'),
            ])
            ->first();

        if ($result && (int) $result->count > 0) {
            $summary['dons'] = [
                'count' => (int) $result->count,
                'total' => (float) $result->total,
            ];
        }
    }

    private function addCotisations(array &$summary, Tiers $tiers, int $exercice): void
    {
        $result = DB::table('transactions')
            ->join('transaction_lignes', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->join('sous_categories', 'sous_categories.id', '=', 'transaction_lignes.sous_categorie_id')
            ->where('transactions.tiers_id', $tiers->id)
            ->where('transactions.type', TypeTransaction::Recette->value)
            ->where('sous_categories.pour_cotisations', true)
            ->whereBetween('transactions.date', ["{$exercice}-09-01", ($exercice + 1) . '-08-31'])
            ->whereNull('transactions.deleted_at')
            ->whereNull('transaction_lignes.deleted_at')
            ->select([
                DB::raw('COUNT(DISTINCT transactions.id) as count'),
                DB::raw('SUM(transaction_lignes.montant) as total'),
            ])
            ->first();

        if ($result && (int) $result->count > 0) {
            $summary['cotisations'] = [
                'count' => (int) $result->count,
                'total' => (float) $result->total,
            ];
        }
    }

    private function addParticipations(array &$summary, Tiers $tiers): void
    {
        $participations = Participant::where('tiers_id', $tiers->id)
            ->with('operation:id,nom,date_debut')
            ->get()
            ->map(fn (Participant $p) => [
                'operation_id' => $p->operation_id,
                'operation_nom' => $p->operation->nom,
                'date_debut' => $p->operation->date_debut?->format('Y-m-d'),
            ])
            ->unique('operation_id')
            ->values()
            ->all();

        if (! empty($participations)) {
            $summary['participations'] = $participations;
        }
    }

    private function addReferent(array &$summary, Tiers $tiers): void
    {
        if (! auth()->user()?->peut_voir_donnees_sensibles) {
            return;
        }

        $referePar = Participant::where('refere_par_id', $tiers->id)
            ->with(['tiers:id,nom,prenom', 'operation:id,nom'])
            ->get()
            ->map(fn (Participant $p) => [
                'participant_id' => $p->id,
                'nom' => $p->tiers?->displayName() ?? '—',
                'operation' => $p->operation?->nom,
            ])
            ->all();

        $medecin = Participant::where('medecin_tiers_id', $tiers->id)
            ->with(['tiers:id,nom,prenom', 'operation:id,nom'])
            ->get()
            ->map(fn (Participant $p) => [
                'participant_id' => $p->id,
                'nom' => $p->tiers?->displayName() ?? '—',
                'operation' => $p->operation?->nom,
            ])
            ->all();

        $therapeute = Participant::where('therapeute_tiers_id', $tiers->id)
            ->with(['tiers:id,nom,prenom', 'operation:id,nom'])
            ->get()
            ->map(fn (Participant $p) => [
                'participant_id' => $p->id,
                'nom' => $p->tiers?->displayName() ?? '—',
                'operation' => $p->operation?->nom,
            ])
            ->all();

        if (! empty($referePar) || ! empty($medecin) || ! empty($therapeute)) {
            $summary['referent'] = [
                'refere_par' => $referePar,
                'medecin' => $medecin,
                'therapeute' => $therapeute,
            ];
        }
    }

    private function addFactures(array &$summary, Tiers $tiers, int $exercice): void
    {
        $factures = Facture::where('tiers_id', $tiers->id)
            ->where('exercice', $exercice)
            ->get();

        if ($factures->isEmpty()) {
            return;
        }

        $impayees = $factures->filter(fn (Facture $f) => $f->statut->value === 'validee' && ! $f->isAcquittee())->count();

        $summary['factures'] = [
            'count' => $factures->count(),
            'impayees' => $impayees,
            'total' => (float) $factures->sum('montant_total'),
        ];
    }
}
```

- [ ] **Step 2: Run all tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/Services/TiersQuickViewServiceTest.php`
Expected: All tests PASS

- [ ] **Step 3: Run Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 4: Commit**

```bash
git add app/Services/TiersQuickViewService.php
git commit -m "feat: add TiersQuickViewService for 360° tiers summary"
```

---

## Task 3: Blade Component — TiersInfoIcon

**Files:**
- Create: `resources/views/components/tiers-info-icon.blade.php`

- [ ] **Step 1: Create the Blade component**

```blade
@props(['tiersId'])

@if($tiersId)
<button type="button"
        class="btn btn-link p-0 ms-1 text-info"
        style="font-size:.75rem;line-height:1;vertical-align:middle"
        title="Vue 360°"
        x-data
        @click.stop="$dispatch('open-tiers-quick-view', { tiersId: {{ $tiersId }}, anchorRect: JSON.parse(JSON.stringify($el.getBoundingClientRect())) })">
    <i class="bi bi-info-circle"></i>
</button>
@endif
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/components/tiers-info-icon.blade.php
git commit -m "feat: add TiersInfoIcon blade component"
```

---

## Task 4: TiersQuickView Livewire Component — Tests

**Files:**
- Create: `tests/Feature/Livewire/TiersQuickViewTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Livewire\TiersQuickView;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can load tiers data', function (): void {
    $tiers = Tiers::factory()->create([
        'email' => 'test@example.com',
        'prenom' => 'Jean',
        'nom' => 'Dupont',
    ]);

    Livewire::test(TiersQuickView::class)
        ->call('loadTiers', $tiers->id)
        ->assertSet('tiersId', $tiers->id)
        ->assertSet('visible', true)
        ->assertSee('test@example.com');
});

test('can change exercice', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(TiersQuickView::class)
        ->call('loadTiers', $tiers->id)
        ->set('exercice', 2024)
        ->assertSet('exercice', 2024);
});

test('displays depenses section when data exists', function (): void {
    $tiers = Tiers::factory()->create();

    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'tiers_id' => $tiers->id,
        'date' => '2025-10-15',
        'montant_total' => 300,
    ]);

    $sc = SousCategorie::factory()->create();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'montant' => 300,
    ]);

    Livewire::test(TiersQuickView::class)
        ->call('loadTiers', $tiers->id)
        ->assertSee('Dépenses')
        ->assertSee('300');
});

test('hides when close is called', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(TiersQuickView::class)
        ->call('loadTiers', $tiers->id)
        ->assertSet('visible', true)
        ->call('close')
        ->assertSet('visible', false);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersQuickViewTest.php`
Expected: FAIL — `TiersQuickView` class not found

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Livewire/TiersQuickViewTest.php
git commit -m "test: add TiersQuickView Livewire component tests (red)"
```

---

## Task 5: TiersQuickView Livewire Component — Implementation

**Files:**
- Create: `app/Livewire/TiersQuickView.php`

- [ ] **Step 1: Create the Livewire component**

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\ExerciceService;
use App\Services\TiersQuickViewService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class TiersQuickView extends Component
{
    public ?int $tiersId = null;

    public int $exercice;

    public bool $visible = false;

    public array $summary = [];

    public ?string $tiersDisplayName = null;

    public ?string $tiersType = null;

    public array $exerciceOptions = [];

    public function mount(): void
    {
        $this->exercice = app(ExerciceService::class)->current();
        $this->exerciceOptions = app(ExerciceService::class)->availableYears();
    }

    #[On('open-tiers-quick-view')]
    public function loadTiers(int $tiersId): void
    {
        $this->tiersId = $tiersId;
        $this->exercice = app(ExerciceService::class)->current();
        $this->refreshData();
        $this->visible = true;
    }

    public function updatedExercice(): void
    {
        $this->refreshData();
    }

    public function close(): void
    {
        $this->visible = false;
        $this->tiersId = null;
        $this->summary = [];
    }

    public function refreshData(): void
    {
        if ($this->tiersId === null) {
            return;
        }

        $tiers = Tiers::find($this->tiersId);
        if ($tiers === null) {
            $this->close();

            return;
        }

        $this->tiersDisplayName = $tiers->displayName();
        $this->tiersType = $tiers->type;
        $this->summary = app(TiersQuickViewService::class)->getSummary($tiers, $this->exercice);
    }

    public function render(): View
    {
        return view('livewire.tiers-quick-view');
    }
}
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersQuickViewTest.php`
Expected: FAIL — view `livewire.tiers-quick-view` not found (will be created in next task)

---

## Task 6: TiersQuickView Blade View

**Files:**
- Create: `resources/views/livewire/tiers-quick-view.blade.php`

- [ ] **Step 1: Create the popover view**

```blade
<div>
    @if($visible && $tiersId)
    <div
        x-data="{
            popoverVisible: true,
            init() {
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') $wire.close();
                });
            }
        }"
        x-show="popoverVisible"
        x-on:open-tiers-quick-view.window="popoverVisible = true"
        class="position-fixed"
        style="z-index:2050"
        x-cloak
    >
        {{-- Backdrop --}}
        <div class="position-fixed top-0 start-0 w-100 h-100" style="z-index:2049" wire:click="close"></div>

        {{-- Popover card --}}
        <div class="position-fixed bg-white border rounded-3 shadow-lg"
             style="z-index:2051;width:460px;max-width:95vw;max-height:80vh;overflow-y:auto;top:50%;left:50%;transform:translate(-50%,-50%)"
             @click.stop>

            {{-- Header --}}
            <div class="px-3 py-2 border-bottom d-flex align-items-center justify-content-between"
                 style="background:#f0e8f5">
                <div>
                    <span class="me-1">{{ $tiersType === 'entreprise' ? '🏢' : '👤' }}</span>
                    <strong>{{ $tiersDisplayName }}</strong>
                    <span class="badge text-bg-secondary ms-1" style="font-size:.6rem">
                        {{ $tiersType === 'entreprise' ? 'Entreprise' : 'Particulier' }}
                    </span>
                </div>
                <button type="button" class="btn-close btn-close-sm" wire:click="close"></button>
            </div>

            <div class="px-3 py-2">
                {{-- Contact --}}
                <div class="d-flex gap-3 mb-2 text-muted small">
                    @if($summary['contact']['email'] ?? null)
                        <a href="mailto:{{ $summary['contact']['email'] }}" class="text-decoration-none">
                            <i class="bi bi-envelope me-1"></i>{{ $summary['contact']['email'] }}
                        </a>
                    @endif
                    @if($summary['contact']['telephone'] ?? null)
                        <a href="tel:{{ $summary['contact']['telephone'] }}" class="text-decoration-none">
                            <i class="bi bi-telephone me-1"></i>{{ $summary['contact']['telephone'] }}
                        </a>
                    @endif
                </div>

                {{-- Exercice selector --}}
                <div class="d-flex align-items-center gap-2 mb-3">
                    <label class="small text-muted">Exercice :</label>
                    <select wire:model.live="exercice" class="form-select form-select-sm" style="width:auto">
                        @foreach($exerciceOptions as $year)
                            <option value="{{ $year }}">{{ $year }}-{{ $year + 1 }}</option>
                        @endforeach
                    </select>
                </div>

                <hr class="my-2">

                @php $hasSections = false; @endphp

                {{-- Dépenses --}}
                @if(isset($summary['depenses']))
                    @php $hasSections = true; @endphp
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <h6 class="mb-0 small fw-bold"><i class="bi bi-arrow-up-circle text-danger me-1"></i>Dépenses</h6>
                            <a href="{{ route('compta.tiers.transactions', $tiersId) }}" class="small text-decoration-none">
                                {{ $summary['depenses']['count'] }} dépense{{ $summary['depenses']['count'] > 1 ? 's' : '' }}
                                — {{ number_format($summary['depenses']['total'], 2, ',', ' ') }} €
                            </a>
                        </div>
                        @if(!empty($summary['depenses']['par_operation']))
                            <div class="ps-3">
                                @foreach($summary['depenses']['par_operation'] as $op)
                                    <div class="small text-muted">
                                        <a href="{{ route('gestion.operations.show', $op['operation_id']) }}" class="text-decoration-none">
                                            {{ $op['operation_nom'] }}
                                        </a>
                                        @if($op['sous_categorie'])
                                            <span class="text-muted">— {{ $op['sous_categorie'] }}</span>
                                        @endif
                                        : {{ number_format($op['total'], 2, ',', ' ') }} €
                                        ({{ $op['count'] }})
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Recettes --}}
                @if(isset($summary['recettes']))
                    @php $hasSections = true; @endphp
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 small fw-bold"><i class="bi bi-arrow-down-circle text-success me-1"></i>Recettes</h6>
                            <a href="{{ route('compta.tiers.transactions', $tiersId) }}" class="small text-decoration-none">
                                {{ $summary['recettes']['count'] }} recette{{ $summary['recettes']['count'] > 1 ? 's' : '' }}
                                — {{ number_format($summary['recettes']['total'], 2, ',', ' ') }} €
                            </a>
                        </div>
                    </div>
                @endif

                {{-- Dons --}}
                @if(isset($summary['dons']))
                    @php $hasSections = true; @endphp
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 small fw-bold"><i class="bi bi-heart text-danger me-1"></i>Dons</h6>
                            <a href="{{ route('compta.tiers.transactions', $tiersId) }}" class="small text-decoration-none">
                                {{ $summary['dons']['count'] }} don{{ $summary['dons']['count'] > 1 ? 's' : '' }}
                                — {{ number_format($summary['dons']['total'], 2, ',', ' ') }} €
                            </a>
                        </div>
                    </div>
                @endif

                {{-- Cotisations --}}
                @if(isset($summary['cotisations']))
                    @php $hasSections = true; @endphp
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 small fw-bold"><i class="bi bi-person-badge text-primary me-1"></i>Adhésion</h6>
                            <span class="small">
                                Cotisation : {{ number_format($summary['cotisations']['total'], 2, ',', ' ') }} €
                            </span>
                        </div>
                    </div>
                @endif

                {{-- Participations --}}
                @if(isset($summary['participations']))
                    @php $hasSections = true; @endphp
                    <div class="mb-3">
                        <h6 class="mb-1 small fw-bold"><i class="bi bi-people text-primary me-1"></i>Participations</h6>
                        <div class="ps-3">
                            @foreach($summary['participations'] as $p)
                                <div class="small">
                                    <a href="{{ route('gestion.operations.show', $p['operation_id']) }}" class="text-decoration-none">
                                        {{ $p['operation_nom'] }}
                                    </a>
                                    @if($p['date_debut'])
                                        <span class="text-muted">— {{ \Carbon\Carbon::parse($p['date_debut'])->format('d/m/Y') }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Référent --}}
                @if(isset($summary['referent']))
                    @php $hasSections = true; @endphp
                    <div class="mb-3">
                        <h6 class="mb-1 small fw-bold"><i class="bi bi-link-45deg text-secondary me-1"></i>Référent</h6>
                        <div class="ps-3">
                            @if(!empty($summary['referent']['refere_par']))
                                <div class="small text-muted mb-1">A référé :</div>
                                @foreach($summary['referent']['refere_par'] as $r)
                                    <div class="small ps-2">
                                        {{ $r['nom'] }}
                                        <x-tiers-info-icon :tiersId="$r['participant_id']" />
                                        @if($r['operation']) <span class="text-muted">— {{ $r['operation'] }}</span> @endif
                                    </div>
                                @endforeach
                            @endif
                            @if(!empty($summary['referent']['medecin']))
                                <div class="small text-muted mb-1 mt-1">Médecin de :</div>
                                @foreach($summary['referent']['medecin'] as $r)
                                    <div class="small ps-2">{{ $r['nom'] }} @if($r['operation']) <span class="text-muted">— {{ $r['operation'] }}</span> @endif</div>
                                @endforeach
                            @endif
                            @if(!empty($summary['referent']['therapeute']))
                                <div class="small text-muted mb-1 mt-1">Thérapeute de :</div>
                                @foreach($summary['referent']['therapeute'] as $r)
                                    <div class="small ps-2">{{ $r['nom'] }} @if($r['operation']) <span class="text-muted">— {{ $r['operation'] }}</span> @endif</div>
                                @endforeach
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Factures --}}
                @if(isset($summary['factures']))
                    @php $hasSections = true; @endphp
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 small fw-bold"><i class="bi bi-receipt text-warning me-1"></i>Factures</h6>
                            <a href="{{ route('compta.factures') }}" class="small text-decoration-none">
                                {{ $summary['factures']['count'] }} facture{{ $summary['factures']['count'] > 1 ? 's' : '' }}
                                — {{ number_format($summary['factures']['total'], 2, ',', ' ') }} €
                                @if($summary['factures']['impayees'] > 0)
                                    <span class="badge text-bg-danger ms-1">{{ $summary['factures']['impayees'] }} impayée{{ $summary['factures']['impayees'] > 1 ? 's' : '' }}</span>
                                @endif
                            </a>
                        </div>
                    </div>
                @endif

                @if(!$hasSections)
                    <p class="text-muted small text-center mb-2">Aucune activité sur cet exercice.</p>
                @endif

                {{-- Footer link --}}
                <hr class="my-2">
                <div class="text-center">
                    <a href="{{ route('compta.tiers.transactions', $tiersId) }}" class="small text-decoration-none fw-medium">
                        Toutes les transactions →
                    </a>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
```

- [ ] **Step 2: Run all tests**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersQuickViewTest.php`
Expected: All PASS

- [ ] **Step 3: Run Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/TiersQuickView.php resources/views/livewire/tiers-quick-view.blade.php
git commit -m "feat: add TiersQuickView Livewire component and view"
```

---

## Task 7: Register TiersQuickView in Layout

**Files:**
- Modify: `resources/views/layouts/app.blade.php:500-502`

- [ ] **Step 1: Add the global component**

After line 502 (`<livewire:virement-interne-form />`), add:

```blade
<livewire:tiers-quick-view />
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat: register TiersQuickView in global layout"
```

---

## Task 8: Integrate TiersInfoIcon in TiersAutocomplete

**Files:**
- Modify: `resources/views/livewire/tiers-autocomplete.blade.php:6`

- [ ] **Step 1: Add the icon in the selected pill**

In the selected state div (line 4-8), add the info icon between the label span and the close button:

Change line 6:
```blade
        <span class="fw-medium">{{ $selectedLabel }}</span>
        <button type="button" class="btn-close btn-close-sm ms-auto" wire:click="clearTiers" aria-label="Effacer"></button>
```

To:
```blade
        <span class="fw-medium">{{ $selectedLabel }}</span>
        <x-tiers-info-icon :tiersId="$tiersId" />
        <button type="button" class="btn-close btn-close-sm ms-auto" wire:click="clearTiers" aria-label="Effacer"></button>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/tiers-autocomplete.blade.php
git commit -m "feat: add 360° info icon in TiersAutocomplete selected state"
```

---

## Task 9: Integrate TiersInfoIcon in TiersList

**Files:**
- Modify: `resources/views/livewire/tiers-list.blade.php:72-73`

- [ ] **Step 1: Add the icon next to the tiers name**

Change lines 72-73:
```blade
                        <td class="fw-semibold">
                            {{ $tiers->type === 'entreprise' ? '🏢' : '👤' }}
                            {{ $tiers->displayName() }}
```

To:
```blade
                        <td class="fw-semibold">
                            {{ $tiers->type === 'entreprise' ? '🏢' : '👤' }}
                            {{ $tiers->displayName() }}
                            <x-tiers-info-icon :tiersId="$tiers->id" />
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/tiers-list.blade.php
git commit -m "feat: add 360° info icon in TiersList table"
```

---

## Task 10: Integrate TiersInfoIcon in TransactionUniverselle

**Files:**
- Modify: `resources/views/livewire/transaction-universelle.blade.php:481-494`

- [ ] **Step 1: Add the icon next to tiers name in transaction table**

Change the tiers column (around lines 481-494):
```blade
                    @if($showTiersCol)
                        <td class="small text-nowrap" style="max-width:160px;overflow:hidden;text-overflow:ellipsis">
                            @if($tx->tiers)
                                @if($tx->tiers_type === 'entreprise')
                                    <i class="bi bi-building text-muted me-1" style="font-size:.7rem"></i>
                                @elseif($tx->tiers_type)
                                    <i class="bi bi-person text-muted me-1" style="font-size:.7rem"></i>
                                @else
                                    <i class="bi bi-bank text-muted me-1" style="font-size:.7rem"></i>
                                @endif
                                {{ $tx->tiers }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
```

To:
```blade
                    @if($showTiersCol)
                        <td class="small text-nowrap" style="max-width:160px;overflow:hidden;text-overflow:ellipsis">
                            @if($tx->tiers)
                                @if($tx->tiers_type === 'entreprise')
                                    <i class="bi bi-building text-muted me-1" style="font-size:.7rem"></i>
                                @elseif($tx->tiers_type)
                                    <i class="bi bi-person text-muted me-1" style="font-size:.7rem"></i>
                                @else
                                    <i class="bi bi-bank text-muted me-1" style="font-size:.7rem"></i>
                                @endif
                                {{ $tx->tiers }}
                                <x-tiers-info-icon :tiersId="$tx->tiers_id" />
                            @else
                                <span class="text-muted">—</span>
                            @endif
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/transaction-universelle.blade.php
git commit -m "feat: add 360° info icon in TransactionUniverselle tiers column"
```

---

## Task 11: Integrate TiersInfoIcon in ParticipantTable

**Files:**
- Modify: `resources/views/livewire/participant-table.blade.php:303-304`

- [ ] **Step 1: Add the icon next to "Référé par" name**

Change lines 303-305:
```blade
                        <td class="small" data-sort="{{ $p->referePar?->displayName() ?? '' }}">
                            {{ $p->referePar?->displayName() ?? '—' }}
                        </td>
```

To:
```blade
                        <td class="small" data-sort="{{ $p->referePar?->displayName() ?? '' }}">
                            {{ $p->referePar?->displayName() ?? '—' }}
                            @if($p->refere_par_id)
                                <x-tiers-info-icon :tiersId="$p->refere_par_id" />
                            @endif
                        </td>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/participant-table.blade.php
git commit -m "feat: add 360° info icon in ParticipantTable referePar column"
```

---

## Task 12: Integrate TiersInfoIcon in ParticipantShow

**Files:**
- Modify: `resources/views/livewire/participant-show.blade.php`

- [ ] **Step 1: Add the icon next to médecin tiers associé**

Find the médecin tiers display (around line 233):
```blade
                            <span><i class="bi bi-link-45deg"></i> <strong>Tiers associé :</strong> {{ $medecinTiers->nom }} {{ $medecinTiers->prenom }}</span>
```

Change to:
```blade
                            <span><i class="bi bi-link-45deg"></i> <strong>Tiers associé :</strong> {{ $medecinTiers->nom }} {{ $medecinTiers->prenom }} <x-tiers-info-icon :tiersId="$medecinTiers->id" /></span>
```

- [ ] **Step 2: Add the icon next to thérapeute tiers associé**

Find the thérapeute tiers display (around line 306):
```blade
                            <span><i class="bi bi-link-45deg"></i> <strong>Tiers associé :</strong> {{ $therapeuteTiers->nom }} {{ $therapeuteTiers->prenom }}</span>
```

Change to:
```blade
                            <span><i class="bi bi-link-45deg"></i> <strong>Tiers associé :</strong> {{ $therapeuteTiers->nom }} {{ $therapeuteTiers->prenom }} <x-tiers-info-icon :tiersId="$therapeuteTiers->id" /></span>
```

- [ ] **Step 3: Add the icon next to référent tiers associé**

Find the référent (referePar) tiers display (around line 382-385) — look for `$refTiers`:
```blade
                @php $refTiers = $participant->referePar ?? null; @endphp
```

In the corresponding display of `$refTiers` name, add `<x-tiers-info-icon :tiersId="$refTiers->id" />` after the name.

- [ ] **Step 4: Commit**

```bash
git add resources/views/livewire/participant-show.blade.php
git commit -m "feat: add 360° info icon in ParticipantShow for médecin/thérapeute/référent"
```

---

## Task 13: Integrate TiersInfoIcon in FactureList

**Files:**
- Modify: `resources/views/livewire/facture-list.blade.php:94`

- [ ] **Step 1: Add the icon next to tiers name**

Change line 94:
```blade
                            <td class="small">{{ $facture->tiers?->displayName() }}</td>
```

To:
```blade
                            <td class="small">
                                {{ $facture->tiers?->displayName() }}
                                @if($facture->tiers_id)
                                    <x-tiers-info-icon :tiersId="$facture->tiers_id" />
                                @endif
                            </td>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/facture-list.blade.php
git commit -m "feat: add 360° info icon in FactureList tiers column"
```

---

## Task 14: Run Full Test Suite + Pint

- [ ] **Step 1: Run Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 2: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: All tests PASS

- [ ] **Step 3: Commit any Pint fixes**

```bash
git add -A
git commit -m "style: apply Pint formatting after TiersQuickView 360° implementation"
```
