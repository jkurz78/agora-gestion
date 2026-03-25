# Onglet Règlements — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Règlements" tab to GestionOperations that lets animators plan expected payments per participant per session, with inline editing and realized/planned totals.

**Architecture:** New `reglements` table (participant × seance intersection, same pattern as `presences`). New `ReglementTable` Livewire component with Alpine.js inline editing. Read-only realized amounts computed from `transaction_lignes` joined through `transactions`.

**Tech Stack:** Laravel 11, Livewire 4, Alpine.js, Bootstrap 5, Pest PHP

**Spec:** `docs/superpowers/specs/2026-03-25-reglements-design.md`

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `database/migrations/2026_03_25_120002_create_reglements_table.php` | Schema: reglements table |
| Create | `app/Models/Reglement.php` | Eloquent model |
| Modify | `app/Models/Participant.php` | Add `reglements()` hasMany |
| Modify | `app/Models/Seance.php` | Add `reglements()` hasMany |
| Modify | `app/Enums/ModePaiement.php` | Add `trigramme()` and `reglementCases()` helpers |
| Create | `app/Livewire/ReglementTable.php` | Livewire component: grid logic |
| Create | `resources/views/livewire/reglement-table.blade.php` | Grid view |
| Modify | `resources/views/livewire/gestion-operations.blade.php` | Add Règlements tab |
| Create | `tests/Feature/ReglementTableTest.php` | Tests |

---

### Task 1: Migration and Model

**Files:**
- Create: `database/migrations/2026_03_25_120002_create_reglements_table.php`
- Create: `app/Models/Reglement.php`
- Modify: `app/Models/Participant.php:53-56`
- Modify: `app/Models/Seance.php:34-37`
- Create: `tests/Feature/ReglementTableTest.php`

- [ ] **Step 1: Write the test file with model tests**

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Livewire\ReglementTable;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->operation = Operation::factory()->create();
});

it('can create a reglement', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    $reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
    ]);

    expect($reglement)->not->toBeNull();
    expect($reglement->mode_paiement)->toBe(ModePaiement::Cheque);
    expect((float) $reglement->montant_prevu)->toBe(30.00);
});

it('enforces unique participant-seance constraint', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 30.00,
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 50.00,
    ]);
})->throws(\Illuminate\Database\QueryException::class);

it('cascades delete when seance is deleted', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 30.00,
    ]);

    $seance->delete();
    expect(Reglement::count())->toBe(0);
});

it('has participant and seance relationships', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    $reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 30.00,
    ]);

    expect($reglement->participant->id)->toBe($participant->id);
    expect($reglement->seance->id)->toBe($seance->id);
    expect($participant->reglements)->toHaveCount(1);
    expect($seance->reglements)->toHaveCount(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/ReglementTableTest.php`
Expected: FAIL — table `reglements` does not exist, `Reglement` class not found

- [ ] **Step 3: Create the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reglements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('participant_id')->constrained('participants')->cascadeOnDelete();
            $table->foreignId('seance_id')->constrained('seances')->cascadeOnDelete();
            $table->string('mode_paiement')->nullable();
            $table->decimal('montant_prevu', 10, 2)->default(0);
            $table->unsignedBigInteger('remise_id')->nullable();
            $table->timestamps();

            $table->unique(['participant_id', 'seance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglements');
    }
};
```

- [ ] **Step 4: Create the Reglement model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModePaiement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Reglement extends Model
{
    protected $fillable = [
        'participant_id',
        'seance_id',
        'mode_paiement',
        'montant_prevu',
        'remise_id',
    ];

    protected function casts(): array
    {
        return [
            'mode_paiement' => ModePaiement::class,
            'montant_prevu' => 'decimal:2',
            'remise_id' => 'integer',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function seance(): BelongsTo
    {
        return $this->belongsTo(Seance::class);
    }
}
```

- [ ] **Step 5: Add `reglements()` relation to Participant model**

In `app/Models/Participant.php`, add after the `presences()` method (line 55):

```php
    public function reglements(): HasMany
    {
        return $this->hasMany(Reglement::class);
    }
```

- [ ] **Step 6: Add `reglements()` relation to Seance model**

In `app/Models/Seance.php`, add after the `presences()` method (line 37):

```php
    public function reglements(): HasMany
    {
        return $this->hasMany(Reglement::class);
    }
```

- [ ] **Step 7: Run migration**

Run: `./vendor/bin/sail artisan migrate`

- [ ] **Step 8: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/ReglementTableTest.php`
Expected: All 4 tests PASS

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_03_25_120002_create_reglements_table.php app/Models/Reglement.php app/Models/Participant.php app/Models/Seance.php tests/Feature/ReglementTableTest.php
git commit -m "feat(reglements): migration, model and relations"
```

---

### Task 2: ModePaiement enum helpers

**Files:**
- Modify: `app/Enums/ModePaiement.php`
- Modify: `tests/Feature/ReglementTableTest.php`

- [ ] **Step 1: Add test for enum helpers**

Append to `tests/Feature/ReglementTableTest.php`:

```php
it('provides trigramme and reglement cases', function () {
    expect(ModePaiement::Cheque->trigramme())->toBe('CHQ');
    expect(ModePaiement::Virement->trigramme())->toBe('VMT');
    expect(ModePaiement::Especes->trigramme())->toBe('ESP');

    $cases = ModePaiement::reglementCases();
    expect($cases)->toHaveCount(3);
    expect($cases)->toContain(ModePaiement::Cheque);
    expect($cases)->not->toContain(ModePaiement::Cb);
});

it('cycles through reglement payment modes', function () {
    expect(ModePaiement::nextReglementMode(null))->toBe(ModePaiement::Cheque);
    expect(ModePaiement::nextReglementMode(ModePaiement::Cheque))->toBe(ModePaiement::Virement);
    expect(ModePaiement::nextReglementMode(ModePaiement::Virement))->toBe(ModePaiement::Especes);
    expect(ModePaiement::nextReglementMode(ModePaiement::Especes))->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/ReglementTableTest.php --filter="trigramme|cycles"`
Expected: FAIL — methods don't exist

- [ ] **Step 3: Add helpers to ModePaiement**

In `app/Enums/ModePaiement.php`, add after the `label()` method:

```php
    public function trigramme(): string
    {
        return match ($this) {
            self::Virement => 'VMT',
            self::Cheque => 'CHQ',
            self::Especes => 'ESP',
            self::Cb => 'CB',
            self::Prelevement => 'PRL',
        };
    }

    /** @return list<self> */
    public static function reglementCases(): array
    {
        return [self::Cheque, self::Virement, self::Especes];
    }

    public static function nextReglementMode(?self $current): ?self
    {
        $cycle = self::reglementCases();

        if ($current === null) {
            return $cycle[0];
        }

        $index = array_search($current, $cycle, true);

        if ($index === false || $index === count($cycle) - 1) {
            return null;
        }

        return $cycle[$index + 1];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/ReglementTableTest.php`
Expected: All 6 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Enums/ModePaiement.php tests/Feature/ReglementTableTest.php
git commit -m "feat(reglements): add trigramme, reglementCases and cycle helpers to ModePaiement"
```

---

### Task 3: ReglementTable Livewire component (actions)

**Files:**
- Create: `app/Livewire/ReglementTable.php`
- Modify: `tests/Feature/ReglementTableTest.php`

- [ ] **Step 1: Add tests for Livewire actions**

Append to `tests/Feature/ReglementTableTest.php`:

```php
it('renders reglement table', function () {
    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->assertOk();
});

it('can cycle mode paiement', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    // null → CHQ
    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('cycleModePaiement', $participant->id, $seance->id);

    $reglement = Reglement::first();
    expect($reglement->mode_paiement)->toBe(ModePaiement::Cheque);

    // CHQ → VMT
    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('cycleModePaiement', $participant->id, $seance->id);

    expect($reglement->fresh()->mode_paiement)->toBe(ModePaiement::Virement);
});

it('can update montant', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('updateMontant', $participant->id, $seance->id, '30,50');

    $reglement = Reglement::first();
    expect((float) $reglement->montant_prevu)->toBe(30.50);
});

it('can copy line from first seance', function () {
    $s1 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $s2 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 2]);
    $s3 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 3]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $s1->id,
        'mode_paiement' => ModePaiement::Especes->value,
        'montant_prevu' => 25.00,
    ]);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('copierLigne', $participant->id);

    expect(Reglement::where('seance_id', $s2->id)->first()->montant_prevu)->toBe('25.00');
    expect(Reglement::where('seance_id', $s2->id)->first()->mode_paiement)->toBe(ModePaiement::Especes);
    expect(Reglement::where('seance_id', $s3->id)->first()->montant_prevu)->toBe('25.00');
});

it('refuses modification on locked reglement', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
        'remise_id' => 999,
    ]);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('updateMontant', $participant->id, $seance->id, '50,00');

    // Should not have changed
    expect((float) Reglement::first()->montant_prevu)->toBe(30.00);
});

it('copier ligne skips locked cells', function () {
    $s1 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $s2 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 2]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $s1->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 25.00,
    ]);
    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $s2->id,
        'mode_paiement' => ModePaiement::Especes->value,
        'montant_prevu' => 10.00,
        'remise_id' => 999,
    ]);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('copierLigne', $participant->id);

    // S2 locked: should keep original values
    $s2Reg = Reglement::where('seance_id', $s2->id)->first();
    expect($s2Reg->mode_paiement)->toBe(ModePaiement::Especes);
    expect((float) $s2Reg->montant_prevu)->toBe(10.00);
});

it('refuses cycle on locked reglement', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
        'remise_id' => 999,
    ]);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('cycleModePaiement', $participant->id, $seance->id);

    expect(Reglement::first()->mode_paiement)->toBe(ModePaiement::Cheque);
});

it('displays realized amounts from transactions', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 50.00,
    ]);

    // Create a recette transaction with matching tiers, operation, seance numero
    $transaction = \App\Models\Transaction::create([
        'type' => 'recette',
        'date' => now(),
        'libelle' => 'Paiement test',
        'montant_total' => 30.00,
        'tiers_id' => $tiers->id,
        'compte_id' => \App\Models\Compte::first()?->id ?? \App\Models\Compte::factory()->create()->id,
        'exercice' => now()->month >= 9 ? now()->year : now()->year - 1,
    ]);

    \App\Models\TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'montant' => 30.00,
        'operation_id' => $this->operation->id,
        'seance' => 1,
        'exercice' => $transaction->exercice,
    ]);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->assertSee('30,00');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/ReglementTableTest.php --filter="renders|cycle|montant|copy|locked|copier|refuses cycle|realized"`
Expected: FAIL — `ReglementTable` class not found

- [ ] **Step 3: Create the ReglementTable component**

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Models\Operation;
use App\Models\Reglement;
use App\Models\Seance;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

final class ReglementTable extends Component
{
    public Operation $operation;

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
    }

    public function cycleModePaiement(int $participantId, int $seanceId): void
    {
        $seance = Seance::where('operation_id', $this->operation->id)->findOrFail($seanceId);

        $reglement = Reglement::where('participant_id', $participantId)
            ->where('seance_id', $seance->id)
            ->first();

        if ($reglement?->remise_id !== null) {
            return;
        }

        $current = $reglement?->mode_paiement;
        $next = ModePaiement::nextReglementMode($current);

        Reglement::updateOrCreate(
            ['participant_id' => $participantId, 'seance_id' => $seance->id],
            ['mode_paiement' => $next]
        );
    }

    public function updateMontant(int $participantId, int $seanceId, string $montant): void
    {
        $seance = Seance::where('operation_id', $this->operation->id)->findOrFail($seanceId);

        $existing = Reglement::where('participant_id', $participantId)
            ->where('seance_id', $seance->id)
            ->first();

        if ($existing?->remise_id !== null) {
            return;
        }

        $parsed = (float) str_replace(',', '.', $montant);

        Reglement::updateOrCreate(
            ['participant_id' => $participantId, 'seance_id' => $seance->id],
            ['montant_prevu' => $parsed]
        );
    }

    public function copierLigne(int $participantId): void
    {
        $seances = Seance::where('operation_id', $this->operation->id)
            ->orderBy('numero')
            ->get();

        if ($seances->isEmpty()) {
            return;
        }

        $source = Reglement::where('participant_id', $participantId)
            ->where('seance_id', $seances->first()->id)
            ->first();

        if (! $source) {
            return;
        }

        foreach ($seances->skip(1) as $seance) {
            $existing = Reglement::where('participant_id', $participantId)
                ->where('seance_id', $seance->id)
                ->first();

            if ($existing?->remise_id !== null) {
                continue;
            }

            Reglement::updateOrCreate(
                ['participant_id' => $participantId, 'seance_id' => $seance->id],
                [
                    'mode_paiement' => $source->mode_paiement,
                    'montant_prevu' => $source->montant_prevu,
                ]
            );
        }
    }

    public function render(): View
    {
        $seances = Seance::where('operation_id', $this->operation->id)
            ->orderBy('numero')
            ->get();

        $participants = $this->operation->participants()
            ->with('tiers')
            ->get()
            ->sortBy(fn ($p) => mb_strtolower(($p->tiers->nom ?? '') . ' ' . ($p->tiers->prenom ?? '')))
            ->values();

        // Load all reglements in one query, indexed by "participantId-seanceId"
        $seanceIds = $seances->pluck('id');
        $reglements = Reglement::whereIn('seance_id', $seanceIds)->get();

        $reglementMap = [];
        foreach ($reglements as $r) {
            $reglementMap[$r->participant_id . '-' . $r->seance_id] = $r;
        }

        // Compute realized amounts from transaction_lignes
        $realiseMap = $this->computeRealise($seances, $participants);

        return view('livewire.reglement-table', [
            'seances' => $seances,
            'participants' => $participants,
            'reglementMap' => $reglementMap,
            'realiseMap' => $realiseMap,
        ]);
    }

    /**
     * @return array<string, float> keyed by "participantId-seanceId"
     */
    private function computeRealise($seances, $participants): array
    {
        if ($seances->isEmpty() || $participants->isEmpty()) {
            return [];
        }

        $tiersIds = $participants->pluck('tiers_id')->unique()->values();
        $seanceNumeros = $seances->pluck('numero', 'id'); // id => numero

        $rows = DB::table('transaction_lignes')
            ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->where('transactions.type', 'recette')
            ->whereIn('transactions.tiers_id', $tiersIds)
            ->where('transaction_lignes.operation_id', $this->operation->id)
            ->whereNotNull('transaction_lignes.seance')
            ->whereNull('transactions.deleted_at')
            ->whereNull('transaction_lignes.deleted_at')
            ->select(
                'transactions.tiers_id',
                'transaction_lignes.seance as seance_numero',
                DB::raw('SUM(transaction_lignes.montant) as total')
            )
            ->groupBy('transactions.tiers_id', 'transaction_lignes.seance')
            ->get();

        // Build tiers_id => participant_id mapping
        $tiersToParticipant = $participants->pluck('id', 'tiers_id');
        // Build seance numero => seance id mapping
        $numeroToSeanceId = $seanceNumeros->flip(); // numero => id

        $map = [];
        foreach ($rows as $row) {
            $participantId = $tiersToParticipant[$row->tiers_id] ?? null;
            $seanceId = $numeroToSeanceId[$row->seance_numero] ?? null;

            if ($participantId !== null && $seanceId !== null) {
                $map[$participantId . '-' . $seanceId] = (float) $row->total;
            }
        }

        return $map;
    }
}
```

- [ ] **Step 4: Create a minimal view** (just enough to make `assertOk()` pass)

Create `resources/views/livewire/reglement-table.blade.php`:

```blade
<div>
    @if($participants->isEmpty())
        <div class="text-center text-muted py-4">
            <i class="bi bi-people" style="font-size:2rem;opacity:0.3"></i>
            <p class="mt-2">Aucun participant inscrit à cette opération.</p>
        </div>
    @elseif($seances->isEmpty())
        <div class="text-center text-muted py-4">
            <i class="bi bi-calendar-week" style="font-size:2rem;opacity:0.3"></i>
            <p class="mt-2">Aucune séance définie pour cette opération.</p>
        </div>
    @else
        {{-- Grid placeholder — built in Task 4 --}}
        <p>{{ $participants->count() }} participants × {{ $seances->count() }} séances</p>
    @endif
</div>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/ReglementTableTest.php`
Expected: All 14 tests PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/ReglementTable.php resources/views/livewire/reglement-table.blade.php tests/Feature/ReglementTableTest.php
git commit -m "feat(reglements): ReglementTable Livewire component with actions and tests"
```

---

### Task 4: Blade view (full grid)

**Files:**
- Modify: `resources/views/livewire/reglement-table.blade.php`

- [ ] **Step 1: Write the complete grid view**

Replace the placeholder in `resources/views/livewire/reglement-table.blade.php` with the full grid. The structure follows `seance-table.blade.php` patterns:

```blade
<div style="max-width:100%;overflow:hidden">
    @if($participants->isEmpty())
        <div class="text-center text-muted py-4">
            <i class="bi bi-people" style="font-size:2rem;opacity:0.3"></i>
            <p class="mt-2">Aucun participant inscrit à cette opération.</p>
        </div>
    @elseif($seances->isEmpty())
        <div class="text-center text-muted py-4">
            <i class="bi bi-calendar-week" style="font-size:2rem;opacity:0.3"></i>
            <p class="mt-2">Aucune séance définie pour cette opération.</p>
        </div>
    @else
        @php
            // Compute totals
            $totalPrevuParSeance = [];
            $totalRealiseParSeance = [];
            foreach ($seances as $s) {
                $totalPrevuParSeance[$s->id] = 0;
                $totalRealiseParSeance[$s->id] = 0;
            }
            $totalPrevuParParticipant = [];
            $totalRealiseParParticipant = [];

            foreach ($participants as $p) {
                $totalPrevuParParticipant[$p->id] = 0;
                $totalRealiseParParticipant[$p->id] = 0;
                foreach ($seances as $s) {
                    $key = $p->id . '-' . $s->id;
                    $prevu = (float) ($reglementMap[$key]?->montant_prevu ?? 0);
                    $realise = $realiseMap[$key] ?? 0;
                    $totalPrevuParSeance[$s->id] += $prevu;
                    $totalRealiseParSeance[$s->id] += $realise;
                    $totalPrevuParParticipant[$p->id] += $prevu;
                    $totalRealiseParParticipant[$p->id] += $realise;
                }
            }
            $grandTotalPrevu = array_sum($totalPrevuParSeance);
            $grandTotalRealise = array_sum($totalRealiseParSeance);
        @endphp

        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" style="font-size:12px;table-layout:fixed;width:{{ 160 + ($seances->count() * 130) + 100 }}px">
                <thead>
                    {{-- Row 1: S# headers --}}
                    <tr style="background:#3d5473;color:#fff;--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <th style="position:sticky;left:0;z-index:2;background:#3d5473;min-width:160px;vertical-align:middle;font-size:11px">Participant</th>
                        @foreach($seances as $seance)
                            <th style="min-width:120px;text-align:center;font-size:12px">S{{ $seance->numero }}</th>
                        @endforeach
                        <th style="min-width:100px;text-align:center;font-size:12px">Total</th>
                    </tr>
                    {{-- Row 2: Titles --}}
                    <tr>
                        <td style="position:sticky;left:0;z-index:1;background:#f8f9fa"></td>
                        @foreach($seances as $seance)
                            <td style="background:#f8f9fa;text-align:center;font-size:11px;color:#6c757d">{{ $seance->titre ?? '' }}</td>
                        @endforeach
                        <td style="background:#f8f9fa"></td>
                    </tr>
                    {{-- Row 3: Dates --}}
                    <tr>
                        <td style="position:sticky;left:0;z-index:1;background:#f8f9fa"></td>
                        @foreach($seances as $seance)
                            <td style="background:#f8f9fa;text-align:center;font-size:11px;color:#6c757d">{{ $seance->date?->format('d/m') ?? '' }}</td>
                        @endforeach
                        <td style="background:#f8f9fa"></td>
                    </tr>
                </thead>
                <tbody>
                    @foreach($participants as $participant)
                        @php
                            $prevuLigne = $totalPrevuParParticipant[$participant->id];
                            $realiseLigne = $totalRealiseParParticipant[$participant->id];
                            $ecartLigne = $realiseLigne - $prevuLigne;
                        @endphp
                        {{-- Row 1: Mode + Montant prévu --}}
                        <tr>
                            <td rowspan="2" style="position:sticky;left:0;z-index:1;background:#fff;font-weight:500;white-space:nowrap;vertical-align:middle;font-size:11px">
                                {{ $participant->tiers->nom }} {{ $participant->tiers->prenom }}
                                <button class="btn btn-sm p-0 ms-1" style="color:#0d6efd;font-size:11px;border:1px solid #0d6efd;border-radius:3px;padding:0 4px !important;line-height:1.4"
                                        wire:click="copierLigne({{ $participant->id }})"
                                        title="Recopier la 1re séance sur toute la ligne">→</button>
                            </td>
                            @foreach($seances as $seance)
                                @php
                                    $key = $participant->id . '-' . $seance->id;
                                    $reglement = $reglementMap[$key] ?? null;
                                    $mode = $reglement?->mode_paiement;
                                    $montant = $reglement ? number_format((float) $reglement->montant_prevu, 2, ',', '') : '0,00';
                                    $locked = $reglement?->remise_id !== null;
                                    $triColors = [
                                        'cheque' => 'background:#e7f1ff;color:#0d6efd',
                                        'virement' => 'background:#d4edda;color:#155724',
                                        'especes' => 'background:#fff3cd;color:#856404',
                                    ];
                                    $triStyle = $mode ? ($triColors[$mode->value] ?? 'background:#f0f0f0;color:#adb5bd') : 'background:#f0f0f0;color:#adb5bd';
                                    $triLabel = $mode ? $mode->trigramme() : '—';
                                @endphp
                                <td style="padding:4px 6px;vertical-align:middle;border-bottom:none;white-space:nowrap">
                                    <div class="d-flex align-items-center justify-content-center gap-1">
                                        @if($locked)
                                            <i class="bi bi-lock-fill" style="font-size:10px;color:#6c757d" title="Remise en banque effectuée"></i>
                                        @endif
                                        <span style="font-weight:600;font-size:11px;padding:2px 5px;border-radius:3px;{{ $triStyle }};{{ $locked ? '' : 'cursor:pointer;' }}"
                                              @if(!$locked) wire:click="cycleModePaiement({{ $participant->id }}, {{ $seance->id }})" @endif
                                              title="{{ $locked ? 'Verrouillé' : 'Clic pour changer' }}">{{ $triLabel }}</span>
                                        @if($locked)
                                            <span style="font-size:12px;font-variant-numeric:tabular-nums">{{ $montant }}</span>
                                        @else
                                            <span style="font-size:12px;font-variant-numeric:tabular-nums;border:1px solid transparent;border-radius:3px;padding:1px 4px;min-width:40px;display:inline-block;text-align:right"
                                                  x-data="{ editing: false, value: @js($montant) }"
                                                  @click="if(!editing){editing=true;$nextTick(()=>{$refs.input.focus();$refs.input.select()})}"
                                                  :style="!editing ? 'cursor:text' : ''">
                                                <template x-if="!editing">
                                                    <span x-text="value" style="display:inline-block;min-width:40px;text-align:right"></span>
                                                </template>
                                                <template x-if="editing">
                                                    <input type="text" x-ref="input" x-model="value"
                                                           @blur="editing=false; $wire.call('updateMontant', {{ $participant->id }}, {{ $seance->id }}, value)"
                                                           @keydown.enter="$refs.input.blur()"
                                                           @keydown.escape="editing=false"
                                                           style="width:55px;border:1px solid #0d6efd;border-radius:3px;padding:1px 4px;font-size:12px;text-align:right;outline:none">
                                                </template>
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            @endforeach
                            <td rowspan="2" style="text-align:center;vertical-align:middle;padding:4px 6px">
                                <div style="font-weight:600;font-size:12px">{{ number_format($prevuLigne, 2, ',', ' ') }}</div>
                                <div style="font-size:11px;color:{{ $realiseLigne > 0 ? '#198754' : '#6c757d' }}">{{ number_format($realiseLigne, 2, ',', ' ') }}</div>
                                @if(abs($ecartLigne) > 0.01)
                                    <div style="font-size:10px;color:{{ $ecartLigne < 0 ? '#dc3545' : '#198754' }}">{{ ($ecartLigne >= 0 ? '+' : '') . number_format($ecartLigne, 2, ',', ' ') }}</div>
                                @else
                                    <div style="font-size:10px;color:#6c757d">Écart 0</div>
                                @endif
                            </td>
                        </tr>
                        {{-- Row 2: Réalisé --}}
                        <tr>
                            @foreach($seances as $seance)
                                @php
                                    $key = $participant->id . '-' . $seance->id;
                                    $realise = $realiseMap[$key] ?? 0;
                                    $prevu = (float) ($reglementMap[$key]?->montant_prevu ?? 0);
                                    $color = $prevu == 0 && $realise == 0 ? '#6c757d' : ($realise >= $prevu && $prevu > 0 ? '#198754' : '#dc3545');
                                @endphp
                                <td style="padding:2px 6px;background:#f8f9fa;border-top:none;text-align:center">
                                    <span style="font-size:11px;color:{{ $color }}">
                                        {{ $realise > 0 ? number_format($realise, 2, ',', '') : ($prevu > 0 ? '0,00' : '—') }}
                                    </span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    {{-- Total prévu --}}
                    <tr style="background:#eef1f5;font-weight:600;font-size:12px">
                        <td style="position:sticky;left:0;z-index:1;background:#eef1f5;padding:6px 12px">Total prévu</td>
                        @foreach($seances as $seance)
                            <td style="text-align:center">{{ number_format($totalPrevuParSeance[$seance->id], 2, ',', ' ') }}</td>
                        @endforeach
                        <td style="text-align:center;font-weight:700">{{ number_format($grandTotalPrevu, 2, ',', ' ') }}</td>
                    </tr>
                    {{-- Total réalisé --}}
                    <tr style="background:#eef1f5;font-size:12px;color:#198754">
                        <td style="position:sticky;left:0;z-index:1;background:#eef1f5;padding:4px 12px">Total réalisé</td>
                        @foreach($seances as $seance)
                            <td style="text-align:center">{{ number_format($totalRealiseParSeance[$seance->id], 2, ',', ' ') }}</td>
                        @endforeach
                        <td style="text-align:center">{{ number_format($grandTotalRealise, 2, ',', ' ') }}</td>
                    </tr>
                    {{-- Écart --}}
                    @php $grandEcart = $grandTotalRealise - $grandTotalPrevu; @endphp
                    <tr style="background:#eef1f5;font-size:11px;color:#6c757d">
                        <td style="position:sticky;left:0;z-index:1;background:#eef1f5;padding:4px 12px">Écart</td>
                        @foreach($seances as $seance)
                            @php $ecart = ($totalRealiseParSeance[$seance->id]) - ($totalPrevuParSeance[$seance->id]); @endphp
                            <td style="text-align:center;{{ $ecart < -0.01 ? 'color:#dc3545' : '' }}">
                                {{ abs($ecart) > 0.01 ? (($ecart >= 0 ? '+' : '') . number_format($ecart, 2, ',', ' ')) : '0' }}
                            </td>
                        @endforeach
                        <td style="text-align:center;{{ $grandEcart < -0.01 ? 'color:#dc3545' : '' }}">
                            {{ abs($grandEcart) > 0.01 ? (($grandEcart >= 0 ? '+' : '') . number_format($grandEcart, 2, ',', ' ')) : '0' }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
```

- [ ] **Step 2: Run tests to verify nothing broke**

Run: `./vendor/bin/sail test tests/Feature/ReglementTableTest.php`
Expected: All 14 tests PASS

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/reglement-table.blade.php
git commit -m "feat(reglements): complete grid view with trigrammes, inline edit and totals"
```

---

### Task 5: Tab integration in GestionOperations

**Files:**
- Modify: `resources/views/livewire/gestion-operations.blade.php:43-57` (tabs) and `116-118` (content)

- [ ] **Step 1: Add the Règlements tab**

In `resources/views/livewire/gestion-operations.blade.php`, insert the new tab after the Séances tab (after line 47, before the Compte résultat `<li>`):

```blade
        <li class="nav-item">
            <button class="nav-link {{ $activeTab === 'reglements' ? 'active' : '' }}" wire:click="setTab('reglements')">Règlements</button>
        </li>
```

- [ ] **Step 2: Add the tab content**

After line 118 (`@endif` for seances), add:

```blade
    @if($activeTab === 'reglements')
        <livewire:reglement-table :operation="$selectedOperation" :key="'rt-'.$selectedOperation->id" />
    @endif
```

- [ ] **Step 3: Test manually in browser**

Open http://localhost, log in as admin@svs.fr, go to Gestion > select an operation. Verify:
- "Règlements" tab appears between Séances and Compte résultat
- Clicking it shows the grid (or empty state messages)
- Tab is visible even without `peut_voir_donnees_sensibles` permission

- [ ] **Step 4: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: All tests PASS (no regressions)

- [ ] **Step 5: Run Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/gestion-operations.blade.php
git commit -m "feat(reglements): add Règlements tab to GestionOperations"
```

---

### Task 6: Final verification

- [ ] **Step 1: Run the full test suite**

Run: `./vendor/bin/sail test`
Expected: All tests PASS

- [ ] **Step 2: Manual testing checklist**

Open http://localhost as admin@svs.fr:
- [ ] Create or select an operation with séances and participants
- [ ] Click the "Règlements" tab
- [ ] Click a trigramme to cycle CHQ → VMT → ESP → — → CHQ
- [ ] Click a montant to edit inline, type "30,50", blur to save
- [ ] Fill S1 for a participant, click → to copy across the line
- [ ] Verify totals update correctly (prévu row, réalisé row, écart row)
- [ ] Verify totals on the right column (per participant)
- [ ] Log in as jean@svs.fr (no `peut_voir_donnees_sensibles`) — verify Règlements tab is visible but Séances tab is hidden

- [ ] **Step 3: Commit any fixes from manual testing**
