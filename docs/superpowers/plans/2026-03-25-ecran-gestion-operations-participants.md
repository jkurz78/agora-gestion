# Écran Gestion Opérations & Participants — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a single-page operations management screen in the Gestion space with tabbed navigation (Details/Participants), inline-editable participant table, WYSIWYG medical notes, and Excel export.

**Architecture:** A `GestionOperations` Livewire component wrapping an operation selector + Bootstrap tabs. The Participants tab embeds a `ParticipantTable` Livewire component with click-to-edit cells, add/edit modals, and export. Quill.js (CDN) for rich-text notes, openspout for Excel export.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 CDN, Quill.js 2.x CDN, openspout/openspout, Pest PHP

**Spec:** `docs/superpowers/specs/2026-03-25-ecran-gestion-operations-participants-design.md`

---

## Task 1: Migrations + Model updates

**Files:**
- Create: `database/migrations/2026_03_25_100000_add_taille_notes_to_participant_donnees_medicales.php`
- Create: `database/migrations/2026_03_25_100001_add_refere_par_id_to_participants.php`
- Modify: `app/Models/Participant.php`
- Modify: `app/Models/ParticipantDonneesMedicales.php`
- Test: `tests/Feature/ParticipantModelTest.php` (add tests)

- [ ] **Step 1: Create migration for taille + notes on participant_donnees_medicales**

```php
<?php
// database/migrations/2026_03_25_100000_add_taille_notes_to_participant_donnees_medicales.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participant_donnees_medicales', function (Blueprint $table): void {
            $table->text('taille')->nullable()->after('poids');
            $table->text('notes')->nullable()->after('taille');
        });
    }

    public function down(): void
    {
        Schema::table('participant_donnees_medicales', function (Blueprint $table): void {
            $table->dropColumn(['taille', 'notes']);
        });
    }
};
```

- [ ] **Step 2: Create migration for refere_par_id on participants**

```php
<?php
// database/migrations/2026_03_25_100001_add_refere_par_id_to_participants.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->foreignId('refere_par_id')->nullable()->after('notes')->constrained('tiers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('refere_par_id');
        });
    }
};
```

- [ ] **Step 3: Update Participant model**

Add `'refere_par_id'` to `$fillable`. Add relation:
```php
public function referePar(): BelongsTo
{
    return $this->belongsTo(Tiers::class, 'refere_par_id');
}
```

- [ ] **Step 4: Update ParticipantDonneesMedicales model**

Add `'taille'` and `'notes'` to `$fillable`. Add to casts:
```php
'taille' => 'encrypted',
'notes' => 'encrypted',
```

- [ ] **Step 5: Add tests to ParticipantModelTest.php**

```php
test('participant can have refere_par tiers', function (): void {
    $referent = Tiers::factory()->create();
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => Operation::factory()->create()->id,
        'date_inscription' => now(),
        'refere_par_id' => $referent->id,
    ]);
    expect($participant->referePar->id)->toBe($referent->id);
});

test('donnees medicales taille and notes are encrypted', function (): void {
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => Operation::factory()->create()->id,
        'date_inscription' => now(),
    ]);
    $donnees = ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'taille' => '165',
        'notes' => '<p>Suivi renforcé</p>',
    ]);
    $donnees->refresh();
    expect($donnees->taille)->toBe('165');
    expect($donnees->notes)->toBe('<p>Suivi renforcé</p>');

    $raw = \DB::table('participant_donnees_medicales')->where('id', $donnees->id)->first();
    expect($raw->taille)->not->toBe('165');
    expect($raw->notes)->not->toBe('<p>Suivi renforcé</p>');
});
```

- [ ] **Step 6: Run migrations and tests**

Run: `./vendor/bin/sail artisan migrate && ./vendor/bin/sail test tests/Feature/ParticipantModelTest.php`

- [ ] **Step 7: Commit**

```bash
git commit -m "feat(participants): add taille, notes (encrypted) and refere_par_id"
```

---

## Task 2: Install openspout dependency

- [ ] **Step 1: Install openspout**

Run: `./vendor/bin/sail composer require openspout/openspout`

- [ ] **Step 2: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: add openspout/openspout dependency for Excel export"
```

---

## Task 3: Navigation updates (navbar + dashboard links + cleanup)

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/livewire/gestion-dashboard.blade.php`
- Modify: `resources/views/operations/show.blade.php`
- Modify: `routes/web.php`
- Delete: `app/Livewire/ParticipantList.php`
- Delete: `resources/views/livewire/participant-list.blade.php`
- Create: `resources/views/gestion/operations.blade.php`

- [ ] **Step 1: Add routes**

In `routes/web.php`, inside the Gestion group (after the `adherents` route), add:
```php
Route::view('/operations', 'gestion.operations')->name('operations');
Route::get('/operations/{operation}/participants/export', \App\Http\Controllers\ParticipantExportController::class)
    ->name('operations.participants.export');
```

- [ ] **Step 2: Create wrapper view**

```blade
{{-- resources/views/gestion/operations.blade.php --}}
<x-app-layout>
    <livewire:gestion-operations />
</x-app-layout>
```

- [ ] **Step 3: Update navbar — add Opérations link in Gestion menu**

In `layouts/app.blade.php`, inside the `@if(($espace ?? null) === \App\Enums\Espace::Gestion)` block (around line 280), add between Adhérents and Sync HelloAsso:
```blade
                    {{-- Opérations --}}
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('gestion.operations*') ? 'active' : '' }}"
                           href="{{ route('gestion.operations') }}">
                            <i class="bi bi-calendar-event"></i> Opérations
                        </a>
                    </li>
```

- [ ] **Step 4: Remove Opérations from Paramètres dropdown when in Gestion**

In `layouts/app.blade.php`, wrap the operations link in Paramètres (around line 339) with an espace check:
```blade
@if(($espace ?? null) === \App\Enums\Espace::Compta)
@if (Route::has('compta.operations.index'))
...existing operations link...
@endif
@endif
```

- [ ] **Step 5: Update dashboard Gestion — link to /gestion/operations?id=X**

In `resources/views/livewire/gestion-dashboard.blade.php`, change the operation link from:
```blade
<a href="{{ route('compta.operations.show', $op) }}"
```
To:
```blade
<a href="{{ route('gestion.operations', ['id' => $op->id]) }}"
```

- [ ] **Step 6: Remove ParticipantList from compta operation show**

In `resources/views/operations/show.blade.php`, remove:
```blade
    {{-- Participants --}}
    <livewire:participant-list :operation="$operation" />
```

- [ ] **Step 7: Delete old ParticipantList files**

```bash
git rm app/Livewire/ParticipantList.php resources/views/livewire/participant-list.blade.php
```

- [ ] **Step 8: Commit**

```bash
git commit -m "feat(gestion): add Opérations route and navbar link, update dashboard links, remove old ParticipantList"
```

---

## Task 4: GestionOperations Livewire component (selector + tabs + details)

**Files:**
- Create: `app/Livewire/GestionOperations.php`
- Create: `resources/views/livewire/gestion-operations.blade.php`
- Test: `tests/Feature/GestionOperationsTest.php`

- [ ] **Step 1: Write tests**

```php
<?php
// tests/Feature/GestionOperationsTest.php
declare(strict_types=1);

use App\Models\Operation;
use App\Models\User;

test('gestion operations page loads', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/gestion/operations')
        ->assertOk()
        ->assertSee('Opération');
});

test('operations are listed in selector', function (): void {
    $user = User::factory()->create();
    $op = Operation::factory()->create(['nom' => 'Art-thérapie test']);
    $this->actingAs($user)
        ->get('/gestion/operations')
        ->assertSee('Art-thérapie test');
});

test('operation can be pre-selected via URL parameter', function (): void {
    $user = User::factory()->create();
    $op = Operation::factory()->create(['nom' => 'Sophrologie test']);
    $this->actingAs($user)
        ->get('/gestion/operations?id=' . $op->id)
        ->assertOk()
        ->assertSee('Sophrologie test');
});
```

- [ ] **Step 2: Create GestionOperations component**

```php
<?php
// app/Livewire/GestionOperations.php
declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Services\ExerciceService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class GestionOperations extends Component
{
    #[Url(as: 'id')]
    public ?int $selectedOperationId = null;

    public string $activeTab = 'details';

    public function mount(): void
    {
        // If no operation selected, don't force one
    }

    public function selectOperation(int $id): void
    {
        $this->selectedOperationId = $id;
        $this->activeTab = 'details';
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();
        $range = app(ExerciceService::class)->dateRange($exercice);

        $operations = Operation::query()
            ->where(function ($q) use ($range): void {
                $q->where(function ($inner) use ($range): void {
                    $inner->whereNotNull('date_debut')
                        ->whereNotNull('date_fin')
                        ->where('date_debut', '<=', $range['end']->toDateString())
                        ->where('date_fin', '>=', $range['start']->toDateString());
                })->orWhere(function ($inner) use ($range): void {
                    $inner->whereNotNull('date_debut')
                        ->whereNull('date_fin')
                        ->where('date_debut', '<=', $range['end']->toDateString())
                        ->where('date_debut', '>=', $range['start']->toDateString());
                });
            })
            ->orderBy('date_debut')
            ->get();

        $selectedOperation = $this->selectedOperationId
            ? Operation::find($this->selectedOperationId)
            : null;

        return view('livewire.gestion-operations', [
            'operations' => $operations,
            'selectedOperation' => $selectedOperation,
        ]);
    }
}
```

- [ ] **Step 3: Create the Blade view**

The view has:
- Top bar: operation `<select>` dropdown + "+" button
- Bootstrap nav-tabs: Détails, Participants, Séances (disabled), Finances (disabled)
- Détails tab: operation info in a `<dl>` grid (read-only)
- Participants tab: will embed `<livewire:participant-table>` (created in Task 5)
- When no operation selected: a centered message

See spec for exact layout. Use the Gestion espace color (#A9014F) for active tab styling.

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/sail test tests/Feature/GestionOperationsTest.php`

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(gestion): add GestionOperations component with selector and tabs"
```

---

## Task 5: ParticipantTable Livewire component (table + inline edit + modals)

This is the largest task. The component handles:
- Listing participants with conditional medical columns
- Click-to-edit inline editing (calls Livewire methods to save)
- Add modal (TiersAutocomplete + Tiers fields + date inscription)
- Edit modal (all Tiers fields + participant fields + medical data if flag)
- Delete with confirmation
- Notes medical modal with Quill WYSIWYG editor

**Files:**
- Create: `app/Livewire/ParticipantTable.php`
- Create: `resources/views/livewire/participant-table.blade.php`
- Test: `tests/Feature/ParticipantTableTest.php`

- [ ] **Step 1: Write tests**

```php
<?php
// tests/Feature/ParticipantTableTest.php
declare(strict_types=1);

use App\Livewire\ParticipantTable;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->operation = Operation::factory()->create();
});

it('renders participant table', function () {
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertOk()
        ->assertSee('participants');
});

it('shows participants in table', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Marie']);
    Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-01-15',
    ]);
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertSee('Dupont')
        ->assertSee('Marie');
});

it('can add participant via modal', function () {
    $tiers = Tiers::factory()->create();
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('openAddModal')
        ->set('addTiersId', $tiers->id)
        ->set('addDateInscription', '2026-03-25')
        ->call('addParticipant')
        ->assertHasNoErrors();
    expect(Participant::where('tiers_id', $tiers->id)->where('operation_id', $this->operation->id)->exists())->toBeTrue();
});

it('prevents duplicate participant', function () {
    $tiers = Tiers::factory()->create();
    Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('openAddModal')
        ->set('addTiersId', $tiers->id)
        ->set('addDateInscription', '2026-03-25')
        ->call('addParticipant')
        ->assertHasErrors('addTiersId');
});

it('can update tiers field inline', function () {
    $tiers = Tiers::factory()->create(['telephone' => '0100000000']);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('updateTiersField', $participant->id, 'telephone', '0600000000');
    $tiers->refresh();
    expect($tiers->telephone)->toBe('0600000000');
});

it('can update participant date_inscription inline', function () {
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-01-01',
    ]);
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('updateParticipantField', $participant->id, 'date_inscription', '2026-06-15');
    $participant->refresh();
    expect($participant->date_inscription->format('Y-m-d'))->toBe('2026-06-15');
});

it('can remove participant', function () {
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('removeParticipant', $participant->id);
    expect(Participant::find($participant->id))->toBeNull();
});

it('hides medical columns when user lacks permission', function () {
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertDontSee('Date naissance')
        ->assertDontSee('Taille');
});

it('shows medical columns when user has permission', function () {
    $this->user->update(['peut_voir_donnees_sensibles' => true]);
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertSee('Date naissance')
        ->assertSee('Taille');
});

it('can save medical data via modal', function () {
    $this->user->update(['peut_voir_donnees_sensibles' => true]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('openMedicalNotesModal', $participant->id)
        ->set('medNotes', '<p>Test notes</p>')
        ->call('saveMedicalNotes');
    $participant->refresh();
    expect($participant->donneesMedicales->notes)->toBe('<p>Test notes</p>');
});
```

- [ ] **Step 2: Create ParticipantTable component**

The Livewire component with these methods:
- `mount(Operation $operation)` — set operation
- `render()` — load participants with tiers, conditionally with donneesMedicales
- `openAddModal()` / `addParticipant()` — add with TiersAutocomplete + Tiers fields
- `openEditModal(int $participantId)` / `saveEdit()` — full edit modal
- `removeParticipant(int $id)` — delete with cascade
- `updateTiersField(int $participantId, string $field, string $value)` — inline edit Tiers
- `updateParticipantField(int $participantId, string $field, string $value)` — inline edit participant
- `updateMedicalField(int $participantId, string $field, string $value)` — inline edit medical
- `openMedicalNotesModal(int $participantId)` / `saveMedicalNotes()` — WYSIWYG notes

Properties for add modal: `addTiersId`, `addDateInscription`, Tiers fields (`addNom`, `addPrenom`, `addAdresse`, `addCodePostal`, `addVille`, `addTelephone`, `addEmail`), `showAddModal`

Properties for edit modal: `editParticipantId`, all Tiers fields, participant fields, medical fields, `showEditModal`

Properties for notes modal: `medNotesParticipantId`, `medNotes`, `showMedicalNotesModal`

- [ ] **Step 3: Create the Blade view**

The view contains:
- Toolbar: participant count + Export Excel button + Add button
- Table with click-to-edit cells using Alpine.js `x-data` for inline edit state
- Inline edit pattern: each editable `<td>` has `@click` → shows input, `@blur` → calls Livewire method
- Add modal: TiersAutocomplete + all Tiers fields + date inscription
- Edit modal: all fields + TiersAutocomplete for référé par + medical fields (if flag) + Quill editor for notes
- Notes modal: Quill WYSIWYG editor with minimal toolbar (bold, italic, lists)
- Medical notes tooltip on hover (using Bootstrap tooltip with HTML content)

Quill CDN links in the view (only loaded when notes modal is open):
```html
<link href="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.js"></script>
```

JS sorting on columns using the project's existing `data-sort` convention.

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/sail test tests/Feature/ParticipantTableTest.php`

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(gestion): add ParticipantTable component with inline edit, modals, and WYSIWYG notes"
```

---

## Task 6: Excel export controller

**Files:**
- Create: `app/Http/Controllers/ParticipantExportController.php`
- Test: `tests/Feature/ParticipantExportTest.php`

- [ ] **Step 1: Write tests**

```php
<?php
// tests/Feature/ParticipantExportTest.php
declare(strict_types=1);

use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\User;

test('export returns xlsx file', function (): void {
    $user = User::factory()->create();
    $operation = Operation::factory()->create(['nom' => 'Test Export']);
    Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get(route('gestion.operations.participants.export', $operation));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

test('export filename includes operation name', function (): void {
    $user = User::factory()->create();
    $operation = Operation::factory()->create(['nom' => 'Sophrologie']);

    $response = $this->actingAs($user)
        ->get(route('gestion.operations.participants.export', $operation));

    expect($response->headers->get('content-disposition'))->toContain('Sophrologie');
});

test('export excludes medical columns when user lacks permission', function (): void {
    $user = User::factory()->create(['peut_voir_donnees_sensibles' => false]);
    $operation = Operation::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('gestion.operations.participants.export', $operation));

    $response->assertOk();
    // The file is generated without medical columns — no assertion on content needed,
    // just verify it doesn't crash
});
```

- [ ] **Step 2: Create export controller**

```php
<?php
// app/Http/Controllers/ParticipantExportController.php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Models\Participant;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ParticipantExportController extends Controller
{
    public function __invoke(Request $request, Operation $operation): StreamedResponse
    {
        $canSeeSensible = $request->user()->peut_voir_donnees_sensibles ?? false;

        $participants = Participant::where('operation_id', $operation->id)
            ->with(['tiers', 'referePar', 'donneesMedicales'])
            ->get();

        $filename = 'participants-' . \Str::slug($operation->nom) . '-' . now()->format('Y-m-d') . '.xlsx';

        return response()->streamDownload(function () use ($participants, $canSeeSensible): void {
            $writer = new Writer();
            $writer->openToOutput();

            // Header row
            $headerStyle = (new Style())->setFontBold();
            $headers = ['Nom', 'Prénom', 'Téléphone', 'Email', 'Date inscription', 'Référé par'];
            if ($canSeeSensible) {
                $headers = array_merge($headers, ['Date naissance', 'Sexe', 'Taille', 'Poids']);
            }
            $writer->addRow(Row::fromValues($headers, $headerStyle));

            // Data rows
            foreach ($participants as $p) {
                $row = [
                    $p->tiers->nom ?? '',
                    $p->tiers->prenom ?? '',
                    $p->tiers->telephone ?? '',
                    $p->tiers->email ?? '',
                    $p->date_inscription?->format('d/m/Y') ?? '',
                    $p->referePar?->displayName() ?? '',
                ];
                if ($canSeeSensible) {
                    $med = $p->donneesMedicales;
                    $row = array_merge($row, [
                        $med?->date_naissance ?? '',
                        $med?->sexe ?? '',
                        $med?->taille ?? '',
                        $med?->poids ?? '',
                    ]);
                }
                $writer->addRow(Row::fromValues($row));
            }

            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
```

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/sail test tests/Feature/ParticipantExportTest.php`

- [ ] **Step 4: Commit**

```bash
git commit -m "feat(gestion): add participant Excel export via openspout"
```

---

## Task 7: Update existing tests + final cleanup

- [ ] **Step 1: Remove/update tests referencing deleted ParticipantList**

Search for references to `ParticipantList` in tests:
```bash
grep -r "ParticipantList\|participant-list" tests/
```
Update or delete test files that reference the old component. The `tests/Feature/ParticipantListTest.php` should be deleted (replaced by `ParticipantTableTest.php`).

Also update `tests/Feature/AdherentListTest.php` if it references ParticipantList.

- [ ] **Step 2: Run Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: ALL PASS

- [ ] **Step 4: Commit**

```bash
git commit -m "test: update tests for ParticipantTable, remove old ParticipantList tests"
```

- [ ] **Step 5: Manual smoke test**

Verify in browser:
1. `/gestion/operations` — page loads with operation selector
2. Select an operation → Details tab shows info
3. Switch to Participants tab → table loads
4. Click "Ajouter" → modal with TiersAutocomplete + Tiers fields
5. Click a cell → inline edit works, saves on blur
6. Click edit button → full edit modal with all fields
7. Click delete → confirmation then removal
8. Notes icon → modal with Quill editor
9. Export Excel → downloads .xlsx file
10. Dashboard Gestion → operation links go to `/gestion/operations?id=X`
11. Navbar Gestion → "Opérations" link present, Paramètres without Opérations
12. Compta operations/show → no participants section
