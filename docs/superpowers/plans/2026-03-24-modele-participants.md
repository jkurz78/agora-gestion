# Modèle Participants — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add participant management to operations — models, migrations, Livewire UI on the operation detail page, and user access control for sensitive medical data.

**Architecture:** Two new tables (`participants`, `participant_donnees_medicales`) with Eloquent models, a `ParticipantList` Livewire component embedded in the operation show page, and a `peut_voir_donnees_sensibles` flag on the User model.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 CDN, Pest PHP

**Spec:** `docs/superpowers/specs/2026-03-24-modele-participants-design.md`

---

## Task 1: Migrations + Models (Participant, ParticipantDonneesMedicales, User flag)

**Files:**
- Create: `database/migrations/2026_03_24_210000_create_participants_table.php`
- Create: `database/migrations/2026_03_24_210001_create_participant_donnees_medicales_table.php`
- Create: `database/migrations/2026_03_24_210002_add_peut_voir_donnees_sensibles_to_users_table.php`
- Create: `app/Models/Participant.php`
- Create: `app/Models/ParticipantDonneesMedicales.php`
- Modify: `app/Models/User.php`
- Modify: `app/Models/Operation.php`
- Modify: `app/Models/Tiers.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Feature/ParticipantModelTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/ParticipantModelTest.php
declare(strict_types=1);

use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Tiers;
use App\Models\User;

test('participant belongs to tiers and operation', function (): void {
    $tiers = Tiers::factory()->create();
    $operation = Operation::factory()->create();

    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now()->toDateString(),
    ]);

    expect($participant->tiers->id)->toBe($tiers->id);
    expect($participant->operation->id)->toBe($operation->id);
});

test('participant unique constraint on tiers and operation', function (): void {
    $tiers = Tiers::factory()->create();
    $operation = Operation::factory()->create();

    Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now()->toDateString(),
    ]);

    Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now()->toDateString(),
    ]);
})->throws(\Illuminate\Database\QueryException::class);

test('tiers can participate in multiple operations', function (): void {
    $tiers = Tiers::factory()->create();
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();

    Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $op1->id, 'date_inscription' => now()]);
    Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $op2->id, 'date_inscription' => now()]);

    expect($tiers->participants)->toHaveCount(2);
});

test('operation has many participants', function (): void {
    $operation = Operation::factory()->create();
    $t1 = Tiers::factory()->create();
    $t2 = Tiers::factory()->create();

    Participant::create(['tiers_id' => $t1->id, 'operation_id' => $operation->id, 'date_inscription' => now()]);
    Participant::create(['tiers_id' => $t2->id, 'operation_id' => $operation->id, 'date_inscription' => now()]);

    expect($operation->participants)->toHaveCount(2);
});

test('donnees medicales are encrypted and linked to participant', function (): void {
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => Operation::factory()->create()->id,
        'date_inscription' => now()->toDateString(),
    ]);

    $donnees = ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'date_naissance' => '1985-06-15',
        'sexe' => 'F',
        'poids' => '65',
    ]);

    $donnees->refresh();
    expect($donnees->date_naissance)->toBe('1985-06-15');
    expect($donnees->sexe)->toBe('F');
    expect($donnees->poids)->toBe('65');

    // Verify encryption in DB (raw value should not be plaintext)
    $raw = \DB::table('participant_donnees_medicales')->where('id', $donnees->id)->first();
    expect($raw->date_naissance)->not->toBe('1985-06-15');
});

test('deleting participant cascades to donnees medicales', function (): void {
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => Operation::factory()->create()->id,
        'date_inscription' => now()->toDateString(),
    ]);

    ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'date_naissance' => '1990-01-01',
        'sexe' => 'M',
        'poids' => '80',
    ]);

    $participant->delete();

    expect(ParticipantDonneesMedicales::count())->toBe(0);
});

test('user peut_voir_donnees_sensibles defaults to false', function (): void {
    $user = User::factory()->create();
    expect($user->peut_voir_donnees_sensibles)->toBeFalse();
});

test('participant donnees medicales has unique constraint on participant_id', function (): void {
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => Operation::factory()->create()->id,
        'date_inscription' => now()->toDateString(),
    ]);

    ParticipantDonneesMedicales::create(['participant_id' => $participant->id]);

    ParticipantDonneesMedicales::create(['participant_id' => $participant->id]);
})->throws(\Illuminate\Database\QueryException::class);
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/ParticipantModelTest.php`
Expected: FAIL — Participant class not found

- [ ] **Step 3: Create migrations**

Migration 1 — `participants`:
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
        Schema::create('participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tiers_id')->constrained('tiers');
            $table->foreignId('operation_id')->constrained('operations');
            $table->date('date_inscription');
            $table->boolean('est_helloasso')->default(false);
            $table->unsignedInteger('helloasso_item_id')->nullable();
            $table->unsignedInteger('helloasso_order_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tiers_id', 'operation_id']);
            $table->index('operation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participants');
    }
};
```

Migration 2 — `participant_donnees_medicales`:
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
        Schema::create('participant_donnees_medicales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('participant_id')->unique()->constrained('participants')->cascadeOnDelete();
            $table->text('date_naissance')->nullable();
            $table->text('sexe')->nullable();
            $table->text('poids')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_donnees_medicales');
    }
};
```

Migration 3 — user flag:
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
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('peut_voir_donnees_sensibles')->default(false)->after('dernier_espace');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('peut_voir_donnees_sensibles');
        });
    }
};
```

- [ ] **Step 4: Create Participant model**

```php
<?php
// app/Models/Participant.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Participant extends Model
{
    protected $fillable = [
        'tiers_id',
        'operation_id',
        'date_inscription',
        'est_helloasso',
        'helloasso_item_id',
        'helloasso_order_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_inscription' => 'date',
            'est_helloasso' => 'boolean',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function donneesMedicales(): HasOne
    {
        return $this->hasOne(ParticipantDonneesMedicales::class);
    }
}
```

- [ ] **Step 5: Create ParticipantDonneesMedicales model**

```php
<?php
// app/Models/ParticipantDonneesMedicales.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ParticipantDonneesMedicales extends Model
{
    protected $table = 'participant_donnees_medicales';

    protected $fillable = [
        'participant_id',
        'date_naissance',
        'sexe',
        'poids',
    ];

    protected function casts(): array
    {
        return [
            'date_naissance' => 'encrypted',
            'sexe' => 'encrypted',
            'poids' => 'encrypted',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
```

- [ ] **Step 6: Update existing models**

**User.php** — add to `$fillable`:
```php
'peut_voir_donnees_sensibles',
```
Add to `casts()`:
```php
'peut_voir_donnees_sensibles' => 'boolean',
```

**UserFactory.php** — add to `definition()`:
```php
'peut_voir_donnees_sensibles' => false,
```

**Operation.php** — add relation:
```php
public function participants(): HasMany
{
    return $this->hasMany(Participant::class);
}
```
Add import: `use Illuminate\Database\Eloquent\Relations\HasMany;` (if not already there — check, it's already imported for `transactionLignes`).

**Tiers.php** — add relation:
```php
public function participants(): HasMany
{
    return $this->hasMany(Participant::class);
}
```
Add import: `use Illuminate\Database\Eloquent\Relations\HasMany;`

- [ ] **Step 7: Run migration and tests**

Run: `./vendor/bin/sail artisan migrate && ./vendor/bin/sail test tests/Feature/ParticipantModelTest.php`
Expected: All 8 tests PASS

- [ ] **Step 8: Commit**

```bash
git add app/Models/Participant.php app/Models/ParticipantDonneesMedicales.php app/Models/User.php app/Models/Operation.php app/Models/Tiers.php database/migrations/2026_03_24_21* database/factories/UserFactory.php tests/Feature/ParticipantModelTest.php
git commit -m "feat(participants): add Participant and ParticipantDonneesMedicales models with migrations"
```

---

## Task 2: ParticipantList Livewire component

**Files:**
- Create: `app/Livewire/ParticipantList.php`
- Create: `resources/views/livewire/participant-list.blade.php`
- Modify: `resources/views/operations/show.blade.php`
- Test: `tests/Feature/ParticipantListTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/ParticipantListTest.php
declare(strict_types=1);

use App\Livewire\ParticipantList;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

test('participant list renders for operation', function (): void {
    $user = User::factory()->create();
    $operation = Operation::factory()->create();

    $this->actingAs($user)
        ->get(route('compta.operations.show', $operation))
        ->assertOk()
        ->assertSee('Participants');
});

test('participant list shows enrolled participants', function (): void {
    $user = User::factory()->create();
    $operation = Operation::factory()->create();
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Marie']);

    Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);

    Livewire::actingAs($user)
        ->test(ParticipantList::class, ['operation' => $operation])
        ->assertSee('Dupont')
        ->assertSee('Marie');
});

test('can add participant to operation', function (): void {
    $user = User::factory()->create();
    $operation = Operation::factory()->create();
    $tiers = Tiers::factory()->create();

    Livewire::actingAs($user)
        ->test(ParticipantList::class, ['operation' => $operation])
        ->set('selectedTiersId', $tiers->id)
        ->set('dateInscription', '2026-03-24')
        ->call('addParticipant')
        ->assertHasNoErrors();

    expect(Participant::where('tiers_id', $tiers->id)->where('operation_id', $operation->id)->exists())->toBeTrue();
});

test('cannot add same participant twice', function (): void {
    $user = User::factory()->create();
    $operation = Operation::factory()->create();
    $tiers = Tiers::factory()->create();

    Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(ParticipantList::class, ['operation' => $operation])
        ->set('selectedTiersId', $tiers->id)
        ->set('dateInscription', '2026-03-24')
        ->call('addParticipant')
        ->assertHasErrors(['selectedTiersId']);
});

test('can remove participant', function (): void {
    $user = User::factory()->create();
    $operation = Operation::factory()->create();
    $tiers = Tiers::factory()->create();

    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(ParticipantList::class, ['operation' => $operation])
        ->call('removeParticipant', $participant->id);

    expect(Participant::find($participant->id))->toBeNull();
});

test('medical data hidden when user lacks permission', function (): void {
    $user = User::factory()->create(['peut_voir_donnees_sensibles' => false]);
    $operation = Operation::factory()->create();
    $tiers = Tiers::factory()->create();

    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);

    ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'date_naissance' => '1985-06-15',
        'sexe' => 'F',
        'poids' => '65',
    ]);

    Livewire::actingAs($user)
        ->test(ParticipantList::class, ['operation' => $operation])
        ->assertDontSee('1985')
        ->assertDontSee('65');
});

test('medical data visible when user has permission', function (): void {
    $user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $operation = Operation::factory()->create();
    $tiers = Tiers::factory()->create();

    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);

    ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'date_naissance' => '1985-06-15',
        'sexe' => 'F',
        'poids' => '65',
    ]);

    Livewire::actingAs($user)
        ->test(ParticipantList::class, ['operation' => $operation])
        ->assertSee('15/06/1985')
        ->assertSee('65');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/ParticipantListTest.php`
Expected: FAIL — ParticipantList class not found

- [ ] **Step 3: Create the Livewire component**

```php
<?php
// app/Livewire/ParticipantList.php
declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class ParticipantList extends Component
{
    public Operation $operation;

    public ?int $selectedTiersId = null;

    public string $dateInscription = '';

    public string $notes = '';

    public bool $showAddModal = false;

    public bool $showMedicalModal = false;

    public ?int $editingParticipantId = null;

    public string $medDateNaissance = '';

    public string $medSexe = '';

    public string $medPoids = '';

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
        $this->dateInscription = now()->format('Y-m-d');
    }

    public function openAddModal(): void
    {
        $this->selectedTiersId = null;
        $this->dateInscription = now()->format('Y-m-d');
        $this->notes = '';
        $this->showAddModal = true;
    }

    public function addParticipant(): void
    {
        $this->validate([
            'selectedTiersId' => [
                'required',
                'exists:tiers,id',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (Participant::where('tiers_id', $value)->where('operation_id', $this->operation->id)->exists()) {
                        $fail('Ce tiers est déjà inscrit à cette opération.');
                    }
                },
            ],
            'dateInscription' => ['required', 'date'],
        ]);

        Participant::create([
            'tiers_id' => $this->selectedTiersId,
            'operation_id' => $this->operation->id,
            'date_inscription' => $this->dateInscription,
            'notes' => $this->notes !== '' ? $this->notes : null,
        ]);

        $this->showAddModal = false;
        $this->selectedTiersId = null;
        $this->notes = '';
    }

    public function removeParticipant(int $participantId): void
    {
        Participant::where('id', $participantId)
            ->where('operation_id', $this->operation->id)
            ->delete();
    }

    public function openMedicalModal(int $participantId): void
    {
        $this->editingParticipantId = $participantId;
        $participant = Participant::findOrFail($participantId);
        $donnees = $participant->donneesMedicales;

        $this->medDateNaissance = $donnees?->date_naissance ?? '';
        $this->medSexe = $donnees?->sexe ?? '';
        $this->medPoids = $donnees?->poids ?? '';
        $this->showMedicalModal = true;
    }

    public function saveMedicalData(): void
    {
        $participant = Participant::where('id', $this->editingParticipantId)
            ->where('operation_id', $this->operation->id)
            ->firstOrFail();

        ParticipantDonneesMedicales::updateOrCreate(
            ['participant_id' => $participant->id],
            [
                'date_naissance' => $this->medDateNaissance !== '' ? $this->medDateNaissance : null,
                'sexe' => $this->medSexe !== '' ? $this->medSexe : null,
                'poids' => $this->medPoids !== '' ? $this->medPoids : null,
            ]
        );

        $this->showMedicalModal = false;
        $this->editingParticipantId = null;
    }

    public function render(): View
    {
        $canSeeSensible = auth()->user()->peut_voir_donnees_sensibles ?? false;

        $query = Participant::where('operation_id', $this->operation->id)
            ->with('tiers');

        if ($canSeeSensible) {
            $query->with('donneesMedicales');
        }

        $participants = $query->orderBy('created_at')->get();

        return view('livewire.participant-list', [
            'participants' => $participants,
            'canSeeSensible' => $canSeeSensible,
        ]);
    }
}
```

- [ ] **Step 4: Create the Blade view**

```blade
{{-- resources/views/livewire/participant-list.blade.php --}}
<div>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-people"></i> Participants ({{ $participants->count() }})</h5>
            <button class="btn btn-sm btn-primary" wire:click="openAddModal">
                <i class="bi bi-plus-lg"></i> Ajouter
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Participant</th>
                            <th>Date d'inscription</th>
                            @if($canSeeSensible)
                                <th>Date de naissance</th>
                                <th>Sexe</th>
                                <th>Poids</th>
                            @endif
                            <th>Notes</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody style="color:#555">
                        @forelse($participants as $participant)
                            <tr>
                                <td class="small">
                                    {{ $participant->tiers->displayName() }}
                                    @if($participant->est_helloasso)
                                        <span class="badge bg-info" style="font-size:.65rem">HelloAsso</span>
                                    @endif
                                </td>
                                <td class="small text-nowrap">{{ $participant->date_inscription->format('d/m/Y') }}</td>
                                @if($canSeeSensible)
                                    @php $med = $participant->donneesMedicales; @endphp
                                    <td class="small text-nowrap">
                                        @if($med?->date_naissance)
                                            {{ \Carbon\Carbon::parse($med->date_naissance)->format('d/m/Y') }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="small">{{ $med?->sexe ?? '—' }}</td>
                                    <td class="small">{{ $med?->poids ? $med->poids.' kg' : '—' }}</td>
                                @endif
                                <td class="small text-muted">{{ Str::limit($participant->notes, 40) ?? '—' }}</td>
                                <td class="text-end">
                                    <div class="d-flex gap-1 justify-content-end">
                                        @if($canSeeSensible)
                                            <button class="btn btn-sm btn-outline-secondary"
                                                    wire:click="openMedicalModal({{ $participant->id }})"
                                                    title="Données médicales">
                                                <i class="bi bi-heart-pulse"></i>
                                            </button>
                                        @endif
                                        <button class="btn btn-sm btn-outline-danger"
                                                wire:click="removeParticipant({{ $participant->id }})"
                                                wire:confirm="Retirer ce participant de l'opération ?"
                                                title="Retirer">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canSeeSensible ? 7 : 4 }}" class="text-center text-muted py-3">
                                    Aucun participant inscrit.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Modal Ajout Participant --}}
    @if($showAddModal)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ajouter un participant</h5>
                    <button type="button" class="btn-close" wire:click="$set('showAddModal', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tiers</label>
                        <livewire:tiers-autocomplete wire:model="selectedTiersId" filtre="tous" :key="'add-participant-autocomplete'" />
                        @error('selectedTiersId') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date d'inscription</label>
                        <input type="date" class="form-control" wire:model="dateInscription">
                        @error('dateInscription') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes <span class="text-muted">(optionnel)</span></label>
                        <textarea class="form-control" wire:model="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" wire:click="$set('showAddModal', false)">Annuler</button>
                    <button class="btn btn-primary" wire:click="addParticipant">Inscrire</button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Modal Données médicales --}}
    @if($showMedicalModal && $canSeeSensible)
    <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Données médicales</h5>
                    <button type="button" class="btn-close" wire:click="$set('showMedicalModal', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Date de naissance</label>
                        <input type="date" class="form-control" wire:model="medDateNaissance">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sexe</label>
                        <select class="form-select" wire:model="medSexe">
                            <option value="">—</option>
                            <option value="F">Féminin</option>
                            <option value="M">Masculin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Poids (kg)</label>
                        <input type="text" class="form-control" wire:model="medPoids" placeholder="ex: 65">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" wire:click="$set('showMedicalModal', false)">Annuler</button>
                    <button class="btn btn-primary" wire:click="saveMedicalData">Enregistrer</button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
```

- [ ] **Step 5: Add component to operations/show.blade.php**

After the closing `</div>` of the row (line 85), before `</x-app-layout>`, add:

```blade

    {{-- Participants --}}
    <livewire:participant-list :operation="$operation" />
```

- [ ] **Step 6: Run tests**

Run: `./vendor/bin/sail test tests/Feature/ParticipantListTest.php`
Expected: All 7 tests PASS

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/ParticipantList.php resources/views/livewire/participant-list.blade.php resources/views/operations/show.blade.php tests/Feature/ParticipantListTest.php
git commit -m "feat(participants): add ParticipantList Livewire component on operation show page"
```

---

## Task 3: User flag management in Parametres > Utilisateurs

**Files:**
- Modify: `app/Http/Controllers/UserController.php`
- Modify: `resources/views/parametres/utilisateurs/index.blade.php`
- Test: `tests/Feature/UserFlagDonneesSensiblesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/UserFlagDonneesSensiblesTest.php
declare(strict_types=1);

use App\Models\User;

test('user flag peut_voir_donnees_sensibles can be set via update', function (): void {
    $admin = User::factory()->create();
    $target = User::factory()->create(['peut_voir_donnees_sensibles' => false]);

    $this->actingAs($admin)
        ->put(route('compta.parametres.utilisateurs.update', $target), [
            'nom' => $target->nom,
            'email' => $target->email,
            'peut_voir_donnees_sensibles' => '1',
        ]);

    $target->refresh();
    expect($target->peut_voir_donnees_sensibles)->toBeTrue();
});

test('user flag peut_voir_donnees_sensibles defaults to false when unchecked', function (): void {
    $admin = User::factory()->create();
    $target = User::factory()->create(['peut_voir_donnees_sensibles' => true]);

    $this->actingAs($admin)
        ->put(route('compta.parametres.utilisateurs.update', $target), [
            'nom' => $target->nom,
            'email' => $target->email,
            // checkbox unchecked → not sent
        ]);

    $target->refresh();
    expect($target->peut_voir_donnees_sensibles)->toBeFalse();
});

test('checkbox visible in utilisateurs page', function (): void {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->get(route('compta.parametres.utilisateurs.index'))
        ->assertOk()
        ->assertSee('Données sensibles');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/UserFlagDonneesSensiblesTest.php`
Expected: FAIL — flag not persisted / checkbox not visible

- [ ] **Step 3: Update UserController**

In `store()`, after `'password'` validation, add:
```php
'peut_voir_donnees_sensibles' => ['boolean'],
```

In the `User::create()` call, add:
```php
'peut_voir_donnees_sensibles' => $request->boolean('peut_voir_donnees_sensibles'),
```

In `update()`, after the password logic, add:
```php
$utilisateur->peut_voir_donnees_sensibles = $request->boolean('peut_voir_donnees_sensibles');
```

- [ ] **Step 4: Update utilisateurs Blade view**

In the **add form** (collapse `#addUserForm`), before the submit button column, add a new column:
```blade
<div class="col-md-2 d-flex align-items-end">
    <div class="form-check mb-2">
        <input type="checkbox" class="form-check-input" name="peut_voir_donnees_sensibles" value="1" id="addSensible">
        <label class="form-check-label small" for="addSensible">Données sensibles</label>
    </div>
</div>
```

In each **edit form** (collapse `#editUser{{ $utilisateur->id }}`), before the submit button column, add:
```blade
<div class="col-md-2 d-flex align-items-end">
    <div class="form-check mb-2">
        <input type="checkbox" class="form-check-input" name="peut_voir_donnees_sensibles" value="1"
               id="editSensible{{ $utilisateur->id }}"
               {{ $utilisateur->peut_voir_donnees_sensibles ? 'checked' : '' }}>
        <label class="form-check-label small" for="editSensible{{ $utilisateur->id }}">Données sensibles</label>
    </div>
</div>
```

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/sail test tests/Feature/UserFlagDonneesSensiblesTest.php`
Expected: All 3 tests PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/UserController.php resources/views/parametres/utilisateurs/index.blade.php tests/Feature/UserFlagDonneesSensiblesTest.php
git commit -m "feat(participants): add peut_voir_donnees_sensibles flag management in Utilisateurs"
```

---

## Task 4: Final verification

- [ ] **Step 1: Run Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 2: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: ALL PASS

- [ ] **Step 3: Commit any Pint fixes**

```bash
git add -A && git commit -m "style: apply Pint fixes for participants feature"
```
