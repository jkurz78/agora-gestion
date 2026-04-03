# TiersMergeModal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a reusable Livewire modal component that lets users compare, arbitrate, and enrich Tiers data before associating incoming data with an existing Tiers record.

**Architecture:** A standalone `TiersMergeModal` Livewire component communicates with parent components via Livewire events (`open-tiers-merge` / `tiers-merge-confirmed` / `tiers-merge-cancelled`). The modal renders a 3-column layout (source read-only, target read-only, editable result) with Alpine.js click-to-copy and real-time conflict coloring. Parents (HelloassoSyncWizard, ParticipantShow) dispatch the open event instead of directly updating the tiers.

**Tech Stack:** Laravel 11, Livewire 4, Alpine.js, Bootstrap 5 (CDN), Pest PHP

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `app/Livewire/TiersMergeModal.php` | Livewire component: receive event, load tiers, compute pre-fill, save merge |
| Create | `resources/views/livewire/tiers-merge-modal.blade.php` | 3-column modal UI with Alpine.js interactions |
| Create | `tests/Feature/Livewire/TiersMergeModalTest.php` | Tests for the merge modal component |
| Modify | `app/Livewire/Banques/HelloassoSyncWizard.php:277-295` | Replace direct update with dispatch to merge modal |
| Modify | `resources/views/livewire/banques/helloasso-sync-wizard.blade.php:244-256` | Include merge modal, wire callback |
| Modify | `app/Livewire/ParticipantShow.php:220-229,258-267,299-308` | Replace direct FK updates with dispatch to merge modal |
| Modify | `resources/views/livewire/participant-show.blade.php:238-258` | Include merge modal, wire callbacks |

---

### Task 1: TiersMergeModal Livewire Component — Core Logic

**Files:**
- Create: `app/Livewire/TiersMergeModal.php`
- Create: `tests/Feature/Livewire/TiersMergeModalTest.php`

- [ ] **Step 1: Write the failing test — component renders when opened**

```php
<?php

declare(strict_types=1);

use App\Livewire\TiersMergeModal;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('opens modal and loads tiers data on open-tiers-merge event', function () {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'marie@example.com',
        'telephone' => '0601020304',
        'adresse_ligne1' => '10 rue de Paris',
        'code_postal' => '75001',
        'ville' => 'Paris',
        'pays' => 'France',
    ]);

    $sourceData = [
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'new@example.com',
        'telephone' => '',
        'adresse_ligne1' => '20 avenue Victor Hugo',
        'code_postal' => '69001',
        'ville' => 'Lyon',
        'pays' => 'France',
    ];

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $tiers->id,
            sourceLabel: 'Données HelloAsso',
            targetLabel: 'Tiers existant',
            confirmLabel: 'Associer ce tiers',
            context: 'helloasso',
            contextData: ['index' => 0],
        )
        ->assertSet('showModal', true)
        ->assertSet('sourceData.email', 'new@example.com')
        ->assertSet('targetData.email', 'marie@example.com')
        ->assertSet('resultData.email', 'marie@example.com') // target has value, keep it
        ->assertSet('resultData.adresse_ligne1', '10 rue de Paris'); // target has value, keep it
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersMergeModalTest.php --filter="opens modal"`
Expected: FAIL — class TiersMergeModal not found

- [ ] **Step 3: Write the TiersMergeModal component**

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\TiersService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class TiersMergeModal extends Component
{
    public bool $showModal = false;

    public ?int $tiersId = null;

    public string $sourceLabel = '';

    public string $targetLabel = '';

    public string $confirmLabel = '';

    public string $context = '';

    /** @var array<string, mixed> */
    public array $contextData = [];

    /** @var array<string, ?string> */
    public array $sourceData = [];

    /** @var array<string, ?string> */
    public array $targetData = [];

    /** @var array<string, ?string> */
    public array $resultData = [];

    /** @var array<string, bool> */
    public array $sourceBooleans = [];

    public bool $helloassoIdConflict = false;

    /** @var list<string> */
    public const MERGE_FIELDS = [
        'type', 'nom', 'prenom', 'entreprise', 'email',
        'telephone', 'adresse_ligne1', 'code_postal', 'ville', 'pays',
    ];

    /** @var list<string> */
    private const BOOLEAN_FIELDS = ['pour_depenses', 'pour_recettes', 'est_helloasso'];

    #[On('open-tiers-merge')]
    public function openMerge(
        array $sourceData,
        int $tiersId,
        string $sourceLabel,
        string $targetLabel,
        string $confirmLabel,
        string $context,
        array $contextData = [],
    ): void {
        $tiers = Tiers::findOrFail($tiersId);

        $this->tiersId = $tiersId;
        $this->sourceLabel = $sourceLabel;
        $this->targetLabel = $targetLabel;
        $this->confirmLabel = $confirmLabel;
        $this->context = $context;
        $this->contextData = $contextData;

        // Normalize source and target to merge fields
        $this->sourceData = [];
        $this->targetData = [];
        foreach (self::MERGE_FIELDS as $field) {
            $this->sourceData[$field] = $this->normalizeValue($sourceData[$field] ?? null);
            $this->targetData[$field] = $this->normalizeValue($tiers->$field);
        }

        // Capture boolean flags from source for OR logic at save
        $this->sourceBooleans = [];
        foreach (self::BOOLEAN_FIELDS as $field) {
            $this->sourceBooleans[$field] = (bool) ($sourceData[$field] ?? false);
        }

        // Check HelloAsso identity conflict (both are HelloAsso with different nom/prenom)
        $sourceIsHelloasso = (bool) ($sourceData['est_helloasso'] ?? false);
        $sourceHaNom = $sourceData['helloasso_nom'] ?? null;
        $sourceHaPrenom = $sourceData['helloasso_prenom'] ?? null;
        $this->helloassoIdConflict = $sourceIsHelloasso
            && $tiers->est_helloasso
            && ($sourceHaNom !== $tiers->helloasso_nom || $sourceHaPrenom !== $tiers->helloasso_prenom);

        // Pre-fill result: target values, completed by source where target is empty
        $this->resultData = [];
        foreach (self::MERGE_FIELDS as $field) {
            if ($field === 'type') {
                // Type always takes target priority
                $this->resultData[$field] = $this->targetData[$field] !== null && $this->targetData[$field] !== ''
                    ? $this->targetData[$field]
                    : ($this->sourceData[$field] ?? 'particulier');
            } else {
                $this->resultData[$field] = ($this->targetData[$field] !== null && $this->targetData[$field] !== '')
                    ? $this->targetData[$field]
                    : $this->sourceData[$field];
            }
        }

        $this->showModal = true;
    }

    public function confirmMerge(): void
    {
        if ($this->tiersId === null || $this->helloassoIdConflict) {
            return;
        }

        $tiers = Tiers::findOrFail($this->tiersId);

        // Build update data from result fields
        $updateData = [];
        foreach (self::MERGE_FIELDS as $field) {
            $updateData[$field] = $this->resultData[$field] ?? null;
        }

        // OR logic for boolean flags
        foreach (self::BOOLEAN_FIELDS as $field) {
            $updateData[$field] = $tiers->$field || $this->sourceBooleans[$field];
        }

        app(TiersService::class)->update($tiers, $updateData);

        $this->dispatch('tiers-merge-confirmed',
            tiersId: $this->tiersId,
            context: $this->context,
            contextData: $this->contextData,
        );

        $this->closeModal();
    }

    public function cancelMerge(): void
    {
        $this->dispatch('tiers-merge-cancelled', context: $this->context);
        $this->closeModal();
    }

    public function render(): View
    {
        return view('livewire.tiers-merge-modal');
    }

    private function closeModal(): void
    {
        $this->showModal = false;
        $this->tiersId = null;
        $this->sourceData = [];
        $this->targetData = [];
        $this->resultData = [];
        $this->sourceBooleans = [];
        $this->contextData = [];
        $this->helloassoIdConflict = false;
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersMergeModalTest.php --filter="opens modal"`
Expected: PASS

- [ ] **Step 5: Write test — pre-fill completes empty target fields from source**

Add to `tests/Feature/Livewire/TiersMergeModalTest.php`:

```php
it('pre-fills result with source values when target fields are empty', function () {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => null,
        'telephone' => null,
        'adresse_ligne1' => null,
        'code_postal' => null,
        'ville' => null,
        'pays' => 'France',
    ]);

    $sourceData = [
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'marie@new.com',
        'telephone' => '0612345678',
        'adresse_ligne1' => '5 rue Neuve',
        'code_postal' => '31000',
        'ville' => 'Toulouse',
        'pays' => 'France',
    ];

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $tiers->id,
            sourceLabel: 'Source',
            targetLabel: 'Cible',
            confirmLabel: 'Valider',
            context: 'test',
        )
        ->assertSet('resultData.nom', 'Dupont')        // target had value
        ->assertSet('resultData.email', 'marie@new.com') // target was empty, took source
        ->assertSet('resultData.telephone', '0612345678') // target was empty, took source
        ->assertSet('resultData.ville', 'Toulouse');      // target was empty, took source
});
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersMergeModalTest.php --filter="pre-fills"`
Expected: PASS

- [ ] **Step 7: Write test — type always takes target priority**

```php
it('always keeps target type over source type', function () {
    $tiers = Tiers::factory()->create(['type' => 'entreprise', 'nom' => 'ACME']);

    $sourceData = ['type' => 'particulier', 'nom' => 'Dupont'];

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $tiers->id,
            sourceLabel: 'Source',
            targetLabel: 'Cible',
            confirmLabel: 'Valider',
            context: 'test',
        )
        ->assertSet('resultData.type', 'entreprise');
});
```

- [ ] **Step 8: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersMergeModalTest.php --filter="keeps target type"`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/TiersMergeModal.php tests/Feature/Livewire/TiersMergeModalTest.php
git commit -m "feat: add TiersMergeModal Livewire component with pre-fill logic and tests"
```

---

### Task 2: TiersMergeModal — Confirm and Cancel Logic

**Files:**
- Modify: `app/Livewire/TiersMergeModal.php` (already created)
- Modify: `tests/Feature/Livewire/TiersMergeModalTest.php`

- [ ] **Step 1: Write test — confirmMerge updates tiers and dispatches event**

Add to `tests/Feature/Livewire/TiersMergeModalTest.php`:

```php
it('updates tiers with result data on confirmMerge', function () {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'old@example.com',
        'pour_depenses' => false,
        'pour_recettes' => true,
        'est_helloasso' => false,
    ]);

    $sourceData = [
        'nom' => 'Durand',
        'prenom' => 'Jean',
        'email' => 'new@example.com',
        'pour_recettes' => false,
        'est_helloasso' => true,
    ];

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $tiers->id,
            sourceLabel: 'Source',
            targetLabel: 'Cible',
            confirmLabel: 'Valider',
            context: 'helloasso',
            contextData: ['index' => 0],
        )
        ->set('resultData.nom', 'Durand')
        ->set('resultData.email', 'new@example.com')
        ->call('confirmMerge')
        ->assertDispatched('tiers-merge-confirmed', fn ($name, $params) =>
            $params['tiersId'] === $tiers->id
            && $params['context'] === 'helloasso'
            && $params['contextData'] === ['index' => 0]
        )
        ->assertSet('showModal', false);

    $tiers->refresh();
    expect($tiers->nom)->toBe('Durand');
    expect($tiers->email)->toBe('new@example.com');
    // OR logic on booleans
    expect($tiers->pour_recettes)->toBeTrue();   // was true, stays true
    expect($tiers->est_helloasso)->toBeTrue();    // OR: false || true = true
});
```

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersMergeModalTest.php --filter="updates tiers"`
Expected: PASS

- [ ] **Step 3: Write test — cancelMerge dispatches event without DB change**

```php
it('dispatches tiers-merge-cancelled on cancel without DB changes', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'email' => 'old@test.com']);

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: ['nom' => 'Autre', 'email' => 'new@test.com'],
            tiersId: $tiers->id,
            sourceLabel: 'Source',
            targetLabel: 'Cible',
            confirmLabel: 'Valider',
            context: 'medecin',
        )
        ->call('cancelMerge')
        ->assertDispatched('tiers-merge-cancelled', fn ($name, $params) =>
            $params['context'] === 'medecin'
        )
        ->assertSet('showModal', false);

    $tiers->refresh();
    expect($tiers->nom)->toBe('Dupont');
    expect($tiers->email)->toBe('old@test.com');
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersMergeModalTest.php --filter="cancel"`
Expected: PASS

- [ ] **Step 5: Write test — helloasso_id conflict blocks confirmation**

```php
it('blocks confirmMerge when HelloAsso identities conflict', function () {
    $tiers = Tiers::factory()->create([
        'nom' => 'Dupont',
        'est_helloasso' => true,
        'helloasso_nom' => 'Dupont',
        'helloasso_prenom' => 'Marie',
    ]);

    $sourceData = [
        'nom' => 'Dupont',
        'est_helloasso' => true,
        'helloasso_nom' => 'Dupont',
        'helloasso_prenom' => 'Jean',
    ];

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $tiers->id,
            sourceLabel: 'Source',
            targetLabel: 'Cible',
            confirmLabel: 'Fusionner',
            context: 'fusion',
        )
        ->assertSet('helloassoIdConflict', true)
        ->call('confirmMerge')
        ->assertNotDispatched('tiers-merge-confirmed');

    $tiers->refresh();
    expect($tiers->nom)->toBe('Dupont'); // unchanged
});
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersMergeModalTest.php --filter="helloasso_id conflicts"`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/TiersMergeModal.php tests/Feature/Livewire/TiersMergeModalTest.php
git commit -m "feat: TiersMergeModal confirm/cancel logic with boolean OR and helloasso_id guard"
```

---

### Task 3: TiersMergeModal — Blade View with 3-Column Layout

**Files:**
- Create: `resources/views/livewire/tiers-merge-modal.blade.php`

- [ ] **Step 1: Create the Blade view**

```blade
@php
    $fields = [
        'type' => 'Type',
        'nom' => 'Nom',
        'prenom' => 'Prénom',
        'entreprise' => 'Entreprise',
        'email' => 'Email',
        'telephone' => 'Téléphone',
        'adresse_ligne1' => 'Adresse',
        'code_postal' => 'Code postal',
        'ville' => 'Ville',
        'pays' => 'Pays',
    ];
@endphp

<div>
    @if($showModal)
    <div class="modal fade show d-block" tabindex="-1" style="background-color:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i>Enrichissement du tiers</h5>
                    <button type="button" class="btn-close" wire:click="cancelMerge"></button>
                </div>
                <div class="modal-body p-0"
                     x-data="{
                         copyToResult(field, value) {
                             $wire.set('resultData.' + field, value);
                         }
                     }">

                    @if($helloassoIdConflict)
                        <div class="alert alert-danger m-3">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Ces deux tiers ont des identifiants HelloAsso différents. La fusion n'est pas possible.
                        </div>
                    @endif

                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                            <tr>
                                <th style="width:15%">Champ</th>
                                <th style="width:25%">{{ $sourceLabel }}</th>
                                <th style="width:25%">{{ $targetLabel }}</th>
                                <th style="width:35%">Résultat</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($fields as $key => $label)
                                @php
                                    $src = $sourceData[$key] ?? null;
                                    $tgt = $targetData[$key] ?? null;
                                    $res = $resultData[$key] ?? null;
                                    $hasConflict = $src !== null && $src !== '' && $tgt !== null && $tgt !== '' && $src !== $tgt;
                                    $srcColor = '';
                                    $tgtColor = '';
                                    if ($hasConflict) {
                                        $srcMatchesResult = $src === $res;
                                        $tgtMatchesResult = $tgt === $res;
                                        if ($srcMatchesResult && !$tgtMatchesResult) {
                                            $srcColor = 'background-color: rgba(46,125,50,0.15)'; // vert
                                            $tgtColor = 'background-color: rgba(181,69,58,0.15)'; // rouge
                                        } elseif ($tgtMatchesResult && !$srcMatchesResult) {
                                            $srcColor = 'background-color: rgba(181,69,58,0.15)';
                                            $tgtColor = 'background-color: rgba(46,125,50,0.15)';
                                        } else {
                                            // manual edit or both match
                                            $srcColor = $srcMatchesResult && $tgtMatchesResult ? '' : 'background-color: rgba(181,69,58,0.15)';
                                            $tgtColor = $srcMatchesResult && $tgtMatchesResult ? '' : 'background-color: rgba(181,69,58,0.15)';
                                        }
                                    }
                                @endphp
                                <tr wire:key="merge-row-{{ $key }}">
                                    <td class="fw-bold small align-middle">{{ $label }}</td>
                                    <td style="cursor:pointer;{{ $srcColor }}"
                                        @if($src !== null && $src !== '')
                                            x-on:click="copyToResult('{{ $key }}', '{{ addslashes($src) }}')"
                                            title="Cliquer pour copier vers Résultat"
                                        @endif
                                        class="small align-middle">
                                        {{ $src ?? '—' }}
                                    </td>
                                    <td style="cursor:pointer;{{ $tgtColor }}"
                                        @if($tgt !== null && $tgt !== '')
                                            x-on:click="copyToResult('{{ $key }}', '{{ addslashes($tgt) }}')"
                                            title="Cliquer pour copier vers Résultat"
                                        @endif
                                        class="small align-middle">
                                        {{ $tgt ?? '—' }}
                                    </td>
                                    <td class="p-1">
                                        @if($key === 'type')
                                            <select wire:model.live="resultData.{{ $key }}" class="form-select form-select-sm">
                                                <option value="particulier">Particulier</option>
                                                <option value="entreprise">Entreprise</option>
                                            </select>
                                        @else
                                            <input type="text"
                                                   wire:model.live.debounce.300ms="resultData.{{ $key }}"
                                                   class="form-control form-control-sm">
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" wire:click="cancelMerge">Annuler</button>
                    <button type="button" class="btn btn-success"
                            wire:click="confirmMerge"
                            @disabled($helloassoIdConflict)>
                        <i class="bi bi-check-lg me-1"></i>{{ $confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
```

- [ ] **Step 2: Write test — view renders fields and labels**

Add to `tests/Feature/Livewire/TiersMergeModalTest.php`:

```php
it('renders modal with field labels and column headers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Marie']);

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: ['nom' => 'Durand', 'prenom' => 'Jean'],
            tiersId: $tiers->id,
            sourceLabel: 'Données HelloAsso',
            targetLabel: 'Tiers existant',
            confirmLabel: 'Associer ce tiers',
            context: 'test',
        )
        ->assertSee('Données HelloAsso')
        ->assertSee('Tiers existant')
        ->assertSee('Résultat')
        ->assertSee('Associer ce tiers')
        ->assertSee('Dupont')
        ->assertSee('Durand');
});
```

- [ ] **Step 3: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersMergeModalTest.php --filter="renders modal"`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add resources/views/livewire/tiers-merge-modal.blade.php tests/Feature/Livewire/TiersMergeModalTest.php
git commit -m "feat: TiersMergeModal Blade view with 3-column layout and conflict coloring"
```

---

### Task 4: Integrate TiersMergeModal into HelloassoSyncWizard

**Files:**
- Modify: `app/Livewire/Banques/HelloassoSyncWizard.php:277-295`
- Modify: `resources/views/livewire/banques/helloasso-sync-wizard.blade.php:244-256`

- [ ] **Step 1: Write test — associerTiers dispatches open-tiers-merge instead of direct update**

Add to `tests/Feature/Livewire/TiersMergeModalTest.php`:

```php
use App\Livewire\Banques\HelloassoSyncWizard;

it('HelloassoSyncWizard associerTiers dispatches open-tiers-merge', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'pour_recettes' => true]);

    $component = Livewire::test(HelloassoSyncWizard::class);

    // Simulate state that would exist after loadTiers()
    $component->set('persons', [
        ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com',
         'address' => '5 rue X', 'city' => 'Lyon', 'zipCode' => '69001', 'country' => 'France',
         'tiers_id' => null, 'tiers_name' => null],
    ]);
    $component->set('selectedTiers', [0 => $tiers->id]);

    $component->call('associerTiers', 0)
        ->assertDispatched('open-tiers-merge');

    // Tiers should NOT be updated yet (no direct update)
    $tiers->refresh();
    expect($tiers->est_helloasso)->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersMergeModalTest.php --filter="HelloassoSyncWizard associerTiers"`
Expected: FAIL — `open-tiers-merge` not dispatched (current code updates directly)

- [ ] **Step 3: Modify `associerTiers()` in HelloassoSyncWizard**

Replace the method at `app/Livewire/Banques/HelloassoSyncWizard.php:277-295` with:

```php
    public function associerTiers(int $index): void
    {
        $tiersId = $this->selectedTiers[$index] ?? null;
        $person = $this->persons[$index] ?? null;
        if ($tiersId === null || $person === null) {
            return;
        }

        $this->dispatch('open-tiers-merge',
            sourceData: [
                'type' => 'particulier',
                'nom' => $person['lastName'],
                'prenom' => $person['firstName'],
                'email' => $person['email'],
                'adresse_ligne1' => $person['address'],
                'code_postal' => $person['zipCode'],
                'ville' => $person['city'],
                'pays' => $person['country'],
            ],
            tiersId: $tiersId,
            sourceLabel: 'Données HelloAsso',
            targetLabel: 'Tiers existant',
            confirmLabel: 'Associer ce tiers HelloAsso',
            context: 'helloasso',
            contextData: ['index' => $index, 'person' => $person],
        );
    }
```

- [ ] **Step 4: Add the callback handler in HelloassoSyncWizard**

Add after `associerTiers()`:

```php
    #[On('tiers-merge-confirmed')]
    public function onTiersMergeConfirmed(int $tiersId, string $context, array $contextData = []): void
    {
        if ($context !== 'helloasso') {
            return;
        }

        $index = $contextData['index'] ?? null;
        $person = $contextData['person'] ?? null;
        if ($index === null || $person === null) {
            return;
        }

        $tiers = Tiers::findOrFail($tiersId);
        $tiers->update([
            'est_helloasso' => true,
            'helloasso_nom' => $person['lastName'],
            'helloasso_prenom' => $person['firstName'],
        ]);

        $this->persons[$index]['tiers_id'] = $tiers->id;
        $this->persons[$index]['tiers_name'] = $tiers->displayName();
        $this->updateStepTwoSummary();
    }
```

Add `use Livewire\Attributes\On;` to the imports if not already present.

- [ ] **Step 5: Include `<livewire:tiers-merge-modal />` in the Blade view**

In `resources/views/livewire/banques/helloasso-sync-wizard.blade.php`, add at the very end of the file (before the closing `</div>`):

```blade
    <livewire:tiers-merge-modal />
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersMergeModalTest.php --filter="HelloassoSyncWizard associerTiers"`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Banques/HelloassoSyncWizard.php resources/views/livewire/banques/helloasso-sync-wizard.blade.php
git commit -m "feat: integrate TiersMergeModal into HelloassoSyncWizard"
```

---

### Task 5: Integrate TiersMergeModal into ParticipantShow

**Files:**
- Modify: `app/Livewire/ParticipantShow.php:220-229,258-267,299-308`
- Modify: `resources/views/livewire/participant-show.blade.php`

- [ ] **Step 1: Write test — mapMedecinTiers dispatches open-tiers-merge**

Add to `tests/Feature/Livewire/TiersMergeModalTest.php`:

```php
use App\Livewire\ParticipantShow;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\TypeOperation;

it('ParticipantShow mapMedecinTiers dispatches open-tiers-merge', function () {
    $typeOp = TypeOperation::factory()->create([
        'formulaire_parcours_therapeutique' => true,
        'formulaire_prescripteur' => true,
    ]);
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);
    $tiers = Tiers::factory()->create(['nom' => 'Participant']);
    $medecinTiers = Tiers::factory()->create(['nom' => 'DrMedecin', 'prenom' => 'Paul']);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);
    ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'medecin_nom' => 'Martin',
        'medecin_prenom' => 'Sophie',
        'medecin_telephone' => '0601020304',
        'medecin_email' => 'sophie@doc.fr',
    ]);

    Livewire::test(ParticipantShow::class, [
        'operation' => $operation,
        'participant' => $participant,
    ])
        ->set('mapMedecinTiersId', $medecinTiers->id)
        ->call('mapMedecinTiers')
        ->assertDispatched('open-tiers-merge');

    // Participant should NOT have medecin_tiers_id yet
    $participant->refresh();
    expect($participant->medecin_tiers_id)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersMergeModalTest.php --filter="mapMedecinTiers dispatches"`
Expected: FAIL — current code updates directly

- [ ] **Step 3: Modify the three map methods in ParticipantShow**

Replace `mapAdresseParTiers()` at lines 220-229:

```php
    public function mapAdresseParTiers(): void
    {
        if ($this->mapAdresseParTiersId === null) {
            return;
        }

        $sourceData = [
            'nom' => $this->participant->adresse_par_nom,
            'prenom' => $this->participant->adresse_par_prenom,
            'entreprise' => $this->participant->adresse_par_etablissement,
            'telephone' => $this->participant->adresse_par_telephone,
            'email' => $this->participant->adresse_par_email,
            'adresse_ligne1' => $this->participant->adresse_par_adresse,
            'code_postal' => $this->participant->adresse_par_code_postal,
            'ville' => $this->participant->adresse_par_ville,
        ];

        $this->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $this->mapAdresseParTiersId,
            sourceLabel: 'Données prescripteur du formulaire',
            targetLabel: 'Tiers existant',
            confirmLabel: 'Associer comme prescripteur',
            context: 'adresse_par',
        );
    }
```

Replace `mapMedecinTiers()` at lines 258-267:

```php
    public function mapMedecinTiers(): void
    {
        if ($this->mapMedecinTiersId === null) {
            return;
        }

        $med = $this->participant->donneesMedicales;
        $sourceData = $med ? [
            'nom' => $med->medecin_nom,
            'prenom' => $med->medecin_prenom,
            'telephone' => $med->medecin_telephone,
            'email' => $med->medecin_email,
            'adresse_ligne1' => $med->medecin_adresse,
            'code_postal' => $med->medecin_code_postal,
            'ville' => $med->medecin_ville,
        ] : [];

        $this->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $this->mapMedecinTiersId,
            sourceLabel: 'Données médecin du formulaire',
            targetLabel: 'Tiers existant',
            confirmLabel: 'Associer comme médecin traitant',
            context: 'medecin',
        );
    }
```

Replace `mapTherapeuteTiers()` at lines 299-308:

```php
    public function mapTherapeuteTiers(): void
    {
        if ($this->mapTherapeuteTiersId === null) {
            return;
        }

        $med = $this->participant->donneesMedicales;
        $sourceData = $med ? [
            'nom' => $med->therapeute_nom,
            'prenom' => $med->therapeute_prenom,
            'telephone' => $med->therapeute_telephone,
            'email' => $med->therapeute_email,
            'adresse_ligne1' => $med->therapeute_adresse,
            'code_postal' => $med->therapeute_code_postal,
            'ville' => $med->therapeute_ville,
        ] : [];

        $this->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $this->mapTherapeuteTiersId,
            sourceLabel: 'Données thérapeute du formulaire',
            targetLabel: 'Tiers existant',
            confirmLabel: 'Associer comme thérapeute référent',
            context: 'therapeute',
        );
    }
```

- [ ] **Step 4: Add the callback handler in ParticipantShow**

Add after the `unlinkTherapeuteTiers()` method (after line 338):

```php
    #[On('tiers-merge-confirmed')]
    public function onTiersMergeConfirmed(int $tiersId, string $context, array $contextData = []): void
    {
        match ($context) {
            'medecin' => $this->participant->update(['medecin_tiers_id' => $tiersId]),
            'therapeute' => $this->participant->update(['therapeute_tiers_id' => $tiersId]),
            'adresse_par' => $this->participant->update(['refere_par_id' => $tiersId]),
            default => null,
        };

        $message = match ($context) {
            'medecin' => 'Tiers associé au médecin traitant.',
            'therapeute' => 'Tiers associé au thérapeute.',
            'adresse_par' => 'Tiers associé au prescripteur.',
            default => 'Tiers associé.',
        };

        $this->dispatch('notify', message: $message);
        $this->participant->refresh();
        $this->loadParticipantData();
    }
```

Add `use Livewire\Attributes\On;` to the imports if not already present.

- [ ] **Step 5: Include `<livewire:tiers-merge-modal />` in participant-show Blade**

In `resources/views/livewire/participant-show.blade.php`, add at the end of the file (before closing `</div>`):

```blade
    <livewire:tiers-merge-modal />
```

- [ ] **Step 6: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersMergeModalTest.php --filter="mapMedecinTiers dispatches"`
Expected: PASS

- [ ] **Step 7: Run all existing ParticipantShow tests**

Run: `./vendor/bin/sail test tests/Feature/Livewire/ParticipantShowTest.php`
Expected: All 5 existing tests PASS (no regressions)

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/ParticipantShow.php resources/views/livewire/participant-show.blade.php
git commit -m "feat: integrate TiersMergeModal into ParticipantShow for medecin/therapeute/prescripteur"
```

---

### Task 6: Run Full Test Suite and Final Verification

**Files:**
- No changes — verification only

- [ ] **Step 1: Run the complete TiersMergeModal test suite**

Run: `./vendor/bin/sail test tests/Feature/Livewire/TiersMergeModalTest.php -v`
Expected: All tests PASS

- [ ] **Step 2: Run the full application test suite**

Run: `./vendor/bin/sail test`
Expected: All tests PASS, no regressions

- [ ] **Step 3: Run Pint for code style**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint --test`
Expected: No style violations (or fix them if any)

- [ ] **Step 4: Final commit if Pint fixes needed**

```bash
git add -A
git commit -m "style: apply Pint formatting"
```
