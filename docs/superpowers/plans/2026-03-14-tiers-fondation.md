# Tiers — Fondation (Plan A) Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Créer la table `tiers` avec son modèle, service, et écran CRUD complet accessible depuis la navbar — sans toucher aux formulaires existants.

**Architecture:** Nouvelle table `tiers` avec les champs contact (nom, prénom, type, adresse, email, téléphone) et deux flags booléens (`pour_depenses`, `pour_recettes`). Composants Livewire `TiersList` + `TiersForm` sur le même pattern que `DepenseList`/`DepenseForm`. Route `/tiers` ajoutée à la navbar comme entrée de premier niveau.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 (CDN), Pest PHP, MySQL

---

## Fichiers touchés

| Action | Fichier |
|---|---|
| Créer | `database/migrations/2026_03_14_100000_create_tiers_table.php` |
| Créer | `app/Models/Tiers.php` |
| Créer | `database/factories/TiersFactory.php` |
| Créer | `app/Services/TiersService.php` |
| Créer | `app/Livewire/TiersList.php` |
| Créer | `app/Livewire/TiersForm.php` |
| Créer | `resources/views/livewire/tiers-list.blade.php` |
| Créer | `resources/views/livewire/tiers-form.blade.php` |
| Créer | `resources/views/tiers/index.blade.php` |
| Créer | `tests/Livewire/TiersListTest.php` |
| Créer | `tests/Livewire/TiersFormTest.php` |
| Modifier | `routes/web.php` |
| Modifier | `resources/views/layouts/app.blade.php` |

---

## Chunk 1 : Migration + Modèle + Factory

### Task 1 : Migration create_tiers_table

**Files:**
- Create: `database/migrations/2026_03_14_100000_create_tiers_table.php`

- [ ] **Step 1 : Écrire le test de migration**

```php
<?php
// tests/Feature/Migrations/TiersTableTest.php
declare(strict_types=1);

it('tiers table has expected columns', function () {
    expect(\Illuminate\Support\Facades\Schema::hasColumns('tiers', [
        'id', 'type', 'nom', 'prenom', 'email', 'telephone',
        'adresse', 'pour_depenses', 'pour_recettes',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});
```

- [ ] **Step 2 : Lancer le test — vérifier l'échec**

```bash
./vendor/bin/sail artisan test tests/Feature/Migrations/TiersTableTest.php
```
Attendu : FAIL — table 'tiers' doesn't exist

- [ ] **Step 3 : Créer la migration**

```php
<?php
// database/migrations/2026_03_14_100000_create_tiers_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiers', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['entreprise', 'particulier'])->default('particulier');
            $table->string('nom', 150);
            $table->string('prenom', 100)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->text('adresse')->nullable();
            $table->boolean('pour_depenses')->default(false);
            $table->boolean('pour_recettes')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiers');
    }
};
```

- [ ] **Step 4 : Lancer la migration**

```bash
./vendor/bin/sail artisan migrate
```

- [ ] **Step 5 : Relancer le test**

```bash
./vendor/bin/sail artisan test tests/Feature/Migrations/TiersTableTest.php
```
Attendu : PASS

- [ ] **Step 6 : Lint + Commit**

```bash
./vendor/bin/sail artisan pint
git add database/migrations/2026_03_14_100000_create_tiers_table.php tests/Feature/Migrations/TiersTableTest.php
git commit -m "feat: migration create_tiers_table"
```

---

### Task 2 : Modèle Tiers + Factory

**Files:**
- Create: `app/Models/Tiers.php`
- Create: `database/factories/TiersFactory.php`

- [ ] **Step 1 : Écrire les tests du modèle**

```php
<?php
// tests/Unit/Models/TiersTest.php
declare(strict_types=1);

use App\Models\Tiers;

it('displayName returns nom for entreprise', function () {
    $tiers = new Tiers(['type' => 'entreprise', 'nom' => 'Mairie de Lyon', 'prenom' => null]);
    expect($tiers->displayName())->toBe('Mairie de Lyon');
});

it('displayName returns prenom nom for particulier', function () {
    $tiers = new Tiers(['type' => 'particulier', 'nom' => 'Martin', 'prenom' => 'Jean']);
    expect($tiers->displayName())->toBe('Jean Martin');
});

it('displayName works with no prenom for particulier', function () {
    $tiers = new Tiers(['type' => 'particulier', 'nom' => 'Martin', 'prenom' => null]);
    expect($tiers->displayName())->toBe('Martin');
});

it('factory creates a valid tiers', function () {
    $tiers = Tiers::factory()->create();
    expect($tiers->nom)->not->toBeEmpty();
    expect($tiers->type)->toBeIn(['entreprise', 'particulier']);
});
```

- [ ] **Step 2 : Lancer — vérifier l'échec**

```bash
./vendor/bin/sail artisan test tests/Unit/Models/TiersTest.php
```
Attendu : FAIL — class not found

- [ ] **Step 3 : Créer le modèle**

```php
<?php
// app/Models/Tiers.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Tiers extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'nom',
        'prenom',
        'email',
        'telephone',
        'adresse',
        'pour_depenses',
        'pour_recettes',
    ];

    protected function casts(): array
    {
        return [
            'pour_depenses' => 'boolean',
            'pour_recettes' => 'boolean',
        ];
    }

    public function displayName(): string
    {
        if ($this->type === 'entreprise') {
            return $this->nom;
        }

        return trim(($this->prenom ? $this->prenom.' ' : '').$this->nom);
    }
}
```

- [ ] **Step 4 : Créer la factory**

```php
<?php
// database/factories/TiersFactory.php
declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

final class TiersFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement(['entreprise', 'particulier']);

        return [
            'type'           => $type,
            'nom'            => $type === 'entreprise'
                ? fake()->company()
                : fake()->lastName(),
            'prenom'         => $type === 'particulier' ? fake()->firstName() : null,
            'email'          => fake()->optional()->safeEmail(),
            'telephone'      => fake()->optional()->phoneNumber(),
            'adresse'        => fake()->optional()->address(),
            'pour_depenses'  => fake()->boolean(60),
            'pour_recettes'  => fake()->boolean(40),
        ];
    }

    public function pourDepenses(): static
    {
        return $this->state(['pour_depenses' => true]);
    }

    public function pourRecettes(): static
    {
        return $this->state(['pour_recettes' => true]);
    }
}
```

- [ ] **Step 5 : Relancer les tests**

```bash
./vendor/bin/sail artisan test tests/Unit/Models/TiersTest.php
```
Attendu : 4 PASS

- [ ] **Step 6 : Lint + Commit**

```bash
./vendor/bin/sail artisan pint
git add app/Models/Tiers.php database/factories/TiersFactory.php tests/Unit/Models/TiersTest.php
git commit -m "feat: modèle Tiers + factory"
```

---

## Chunk 2 : TiersService

### Task 3 : TiersService

**Files:**
- Create: `app/Services/TiersService.php`

- [ ] **Step 1 : Écrire les tests**

```php
<?php
// tests/Feature/Services/TiersServiceTest.php
declare(strict_types=1);

use App\Models\Tiers;
use App\Services\TiersService;

uses(\Tests\TestCase::class);

it('crée un tiers', function () {
    $tiers = app(TiersService::class)->create([
        'type'          => 'entreprise',
        'nom'           => 'Mairie de Lyon',
        'prenom'        => null,
        'email'         => 'contact@mairie.fr',
        'telephone'     => null,
        'adresse'       => null,
        'pour_depenses' => true,
        'pour_recettes' => false,
    ]);

    expect($tiers)->toBeInstanceOf(Tiers::class);
    expect($tiers->nom)->toBe('Mairie de Lyon');
    $this->assertDatabaseHas('tiers', ['nom' => 'Mairie de Lyon', 'pour_depenses' => true]);
});

it('met à jour un tiers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Ancien nom']);

    app(TiersService::class)->update($tiers, ['nom' => 'Nouveau nom']);

    expect($tiers->fresh()->nom)->toBe('Nouveau nom');
});

it('supprime un tiers sans contrainte', function () {
    $tiers = Tiers::factory()->create();

    app(TiersService::class)->delete($tiers);

    $this->assertDatabaseMissing('tiers', ['id' => $tiers->id]);
});
```

- [ ] **Step 2 : Lancer — vérifier l'échec**

```bash
./vendor/bin/sail artisan test tests/Feature/Services/TiersServiceTest.php
```

- [ ] **Step 3 : Créer le service**

```php
<?php
// app/Services/TiersService.php
declare(strict_types=1);

namespace App\Services;

use App\Models\Tiers;
use Illuminate\Support\Facades\DB;

final class TiersService
{
    public function create(array $data): Tiers
    {
        return DB::transaction(fn (): Tiers => Tiers::create($data));
    }

    public function update(Tiers $tiers, array $data): Tiers
    {
        return DB::transaction(function () use ($tiers, $data): Tiers {
            $tiers->update($data);

            return $tiers->fresh();
        });
    }

    public function delete(Tiers $tiers): void
    {
        // Plan B ajoutera ici la vérification des FK (dons, cotisations, depenses, recettes)
        DB::transaction(function () use ($tiers): void {
            $tiers->delete();
        });
    }
}
```

- [ ] **Step 4 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Feature/Services/TiersServiceTest.php
```
Attendu : 3 PASS

- [ ] **Step 5 : Lint + Commit**

```bash
./vendor/bin/sail artisan pint
git add app/Services/TiersService.php tests/Feature/Services/TiersServiceTest.php
git commit -m "feat: TiersService — CRUD"
```

---

## Chunk 3 : Composants Livewire

### Task 4 : TiersList

**Files:**
- Create: `app/Livewire/TiersList.php`
- Create: `resources/views/livewire/tiers-list.blade.php`
- Test: `tests/Livewire/TiersListTest.php`

- [ ] **Step 1 : Écrire les tests**

```php
<?php
// tests/Livewire/TiersListTest.php
declare(strict_types=1);

use App\Livewire\TiersList;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders the tiers list', function () {
    Tiers::factory()->create(['nom' => 'Mairie de Lyon']);

    Livewire::test(TiersList::class)
        ->assertOk()
        ->assertSee('Mairie de Lyon');
});

it('filters by search', function () {
    Tiers::factory()->create(['nom' => 'Mairie de Lyon']);
    Tiers::factory()->create(['nom' => 'Leclerc SA']);

    Livewire::test(TiersList::class)
        ->set('search', 'Mairie')
        ->assertSee('Mairie de Lyon')
        ->assertDontSee('Leclerc SA');
});

it('filters pour_depenses', function () {
    Tiers::factory()->pourDepenses()->create(['nom' => 'Fournisseur A']);
    Tiers::factory()->create(['nom' => 'Recette Only', 'pour_depenses' => false, 'pour_recettes' => true]);

    Livewire::test(TiersList::class)
        ->set('filtre', 'depenses')
        ->assertSee('Fournisseur A')
        ->assertDontSee('Recette Only');
});

it('can delete a tiers', function () {
    $tiers = Tiers::factory()->create();

    Livewire::test(TiersList::class)
        ->call('delete', $tiers->id);

    $this->assertDatabaseMissing('tiers', ['id' => $tiers->id]);
});
```

- [ ] **Step 2 : Lancer — vérifier l'échec**

```bash
./vendor/bin/sail artisan test tests/Livewire/TiersListTest.php
```

- [ ] **Step 3 : Créer TiersList**

```php
<?php
// app/Livewire/TiersList.php
declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\TiersService;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

final class TiersList extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $search = '';

    public string $filtre = ''; // '', 'depenses', 'recettes'

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFiltre(): void
    {
        $this->resetPage();
    }

    #[On('tiers-saved')]
    public function refresh(): void {}

    public function delete(int $id): void
    {
        $tiers = Tiers::findOrFail($id);
        try {
            app(TiersService::class)->delete($tiers);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): \Illuminate\View\View
    {
        $query = Tiers::orderBy('nom');

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('nom', 'like', '%'.$this->search.'%')
                  ->orWhere('prenom', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->filtre === 'depenses') {
            $query->where('pour_depenses', true);
        } elseif ($this->filtre === 'recettes') {
            $query->where('pour_recettes', true);
        }

        return view('livewire.tiers-list', [
            'tiersList' => $query->paginate(20),
        ]);
    }
}
```

- [ ] **Step 4 : Créer la vue**

```blade
{{-- resources/views/livewire/tiers-list.blade.php --}}
<div>
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- Filtres --}}
    <div class="row g-2 mb-3">
        <div class="col-md-6">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                class="form-control"
                placeholder="Rechercher un tiers…"
            >
        </div>
        <div class="col-md-4">
            <select wire:model.live="filtre" class="form-select">
                <option value="">Tous les tiers</option>
                <option value="depenses">Utilisables en dépenses</option>
                <option value="recettes">Utilisables en recettes</option>
            </select>
        </div>
    </div>

    {{-- Tableau --}}
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>Nom</th>
                    <th>Type</th>
                    <th>Email</th>
                    <th>Téléphone</th>
                    <th class="text-center">Dépenses</th>
                    <th class="text-center">Recettes</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tiersList as $tiers)
                    <tr>
                        <td class="fw-semibold">{{ $tiers->displayName() }}</td>
                        <td>
                            <span class="badge bg-secondary">
                                {{ $tiers->type === 'entreprise' ? 'Entreprise' : 'Particulier' }}
                            </span>
                        </td>
                        <td>{{ $tiers->email ?? '—' }}</td>
                        <td>{{ $tiers->telephone ?? '—' }}</td>
                        <td class="text-center">
                            @if ($tiers->pour_depenses)
                                <span class="badge bg-danger">Oui</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if ($tiers->pour_recettes)
                                <span class="badge bg-success">Oui</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <button
                                class="btn btn-sm btn-outline-primary me-1"
                                wire:click="$dispatch('edit-tiers', { id: {{ $tiers->id }} })"
                            >Modifier</button>
                            <button
                                class="btn btn-sm btn-outline-danger"
                                wire:click="delete({{ $tiers->id }})"
                                wire:confirm="Supprimer ce tiers ?"
                            >Supprimer</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Aucun tiers.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $tiersList->links() }}
</div>
```

- [ ] **Step 5 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Livewire/TiersListTest.php
```
Attendu : 4 PASS

- [ ] **Step 6 : Lint + Commit**

```bash
./vendor/bin/sail artisan pint
git add app/Livewire/TiersList.php resources/views/livewire/tiers-list.blade.php tests/Livewire/TiersListTest.php
git commit -m "feat: TiersList — liste avec recherche et filtres"
```

---

### Task 5 : TiersForm

**Files:**
- Create: `app/Livewire/TiersForm.php`
- Create: `resources/views/livewire/tiers-form.blade.php`
- Test: `tests/Livewire/TiersFormTest.php`

- [ ] **Step 1 : Écrire les tests**

```php
<?php
// tests/Livewire/TiersFormTest.php
declare(strict_types=1);

use App\Livewire\TiersForm;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('can create a new tiers', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'entreprise')
        ->set('nom', 'Mairie de Lyon')
        ->set('pour_depenses', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('tiers-saved');

    $this->assertDatabaseHas('tiers', ['nom' => 'Mairie de Lyon', 'pour_depenses' => true]);
});

it('validates nom is required', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('nom', '')
        ->call('save')
        ->assertHasErrors(['nom']);
});

it('validates at least one flag is checked', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('nom', 'Test')
        ->set('pour_depenses', false)
        ->set('pour_recettes', false)
        ->call('save')
        ->assertHasErrors(['pour_depenses']);
});

it('can load existing tiers for editing', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Leclerc SA', 'type' => 'entreprise']);

    Livewire::test(TiersForm::class)
        ->dispatch('edit-tiers', id: $tiers->id)
        ->assertSet('nom', 'Leclerc SA')
        ->assertSet('type', 'entreprise');
});

it('can update a tiers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Ancien nom', 'pour_depenses' => true]);

    Livewire::test(TiersForm::class)
        ->dispatch('edit-tiers', id: $tiers->id)
        ->set('nom', 'Nouveau nom')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('tiers-saved');

    expect($tiers->fresh()->nom)->toBe('Nouveau nom');
});
```

- [ ] **Step 2 : Lancer — vérifier l'échec**

```bash
./vendor/bin/sail artisan test tests/Livewire/TiersFormTest.php
```

- [ ] **Step 3 : Créer TiersForm**

```php
<?php
// app/Livewire/TiersForm.php
declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\TiersService;
use Livewire\Attributes\On;
use Livewire\Component;

final class TiersForm extends Component
{
    public ?int $tiersId = null;

    public string $type = 'particulier';

    public string $nom = '';

    public ?string $prenom = null;

    public ?string $email = null;

    public ?string $telephone = null;

    public ?string $adresse = null;

    public bool $pour_depenses = false;

    public bool $pour_recettes = false;

    public bool $showForm = false;

    public function showNewForm(): void
    {
        $this->reset([
            'tiersId', 'type', 'nom', 'prenom', 'email',
            'telephone', 'adresse', 'pour_depenses', 'pour_recettes',
        ]);
        $this->type = 'particulier';
        $this->resetValidation();
        $this->showForm = true;
    }

    #[On('edit-tiers')]
    public function edit(int $id): void
    {
        $tiers = Tiers::findOrFail($id);

        $this->tiersId      = $tiers->id;
        $this->type         = $tiers->type;
        $this->nom          = $tiers->nom;
        $this->prenom       = $tiers->prenom;
        $this->email        = $tiers->email;
        $this->telephone    = $tiers->telephone;
        $this->adresse      = $tiers->adresse;
        $this->pour_depenses = $tiers->pour_depenses;
        $this->pour_recettes = $tiers->pour_recettes;
        $this->showForm     = true;
    }

    public function resetForm(): void
    {
        $this->reset([
            'tiersId', 'type', 'nom', 'prenom', 'email',
            'telephone', 'adresse', 'pour_depenses', 'pour_recettes', 'showForm',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'type'         => ['required', 'in:entreprise,particulier'],
            'nom'          => ['required', 'string', 'max:150'],
            'prenom'       => ['nullable', 'string', 'max:100'],
            'email'        => ['nullable', 'email', 'max:255'],
            'telephone'    => ['nullable', 'string', 'max:30'],
            'adresse'      => ['nullable', 'string', 'max:500'],
            'pour_depenses' => ['boolean'],
            'pour_recettes' => ['boolean'],
        ], [
            'nom.required' => 'Le nom est obligatoire.',
        ]);

        // Au moins un flag doit être coché
        if (! $this->pour_depenses && ! $this->pour_recettes) {
            $this->addError('pour_depenses', 'Cochez au moins une utilisation (dépenses ou recettes).');
            return;
        }

        $service = app(TiersService::class);

        if ($this->tiersId) {
            $tiers = Tiers::findOrFail($this->tiersId);
            $service->update($tiers, $validated);
        } else {
            $service->create($validated);
        }

        $this->dispatch('tiers-saved');
        $this->resetForm();
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.tiers-form');
    }
}
```

- [ ] **Step 4 : Créer la vue**

```blade
{{-- resources/views/livewire/tiers-form.blade.php --}}
<div>
    {{-- Bouton nouveau --}}
    @unless ($showForm)
        <button wire:click="showNewForm" class="btn text-white mb-3" style="background:#722281">
            + Nouveau tiers
        </button>
    @endunless

    {{-- Formulaire --}}
    @if ($showForm)
        <div class="card mb-4 shadow-sm border-0">
            <div class="card-header fw-semibold" style="background:#722281;color:white">
                {{ $tiersId ? 'Modifier le tiers' : 'Nouveau tiers' }}
            </div>
            <div class="card-body">
                <div class="row g-3">
                    {{-- Type --}}
                    <div class="col-md-4">
                        <label class="form-label">Type <span class="text-danger">*</span></label>
                        <select wire:model.live="type" class="form-select @error('type') is-invalid @enderror">
                            <option value="particulier">Particulier</option>
                            <option value="entreprise">Entreprise</option>
                        </select>
                        @error('type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Nom --}}
                    <div class="col-md-4">
                        <label class="form-label">
                            {{ $type === 'entreprise' ? 'Raison sociale' : 'Nom' }}
                            <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            wire:model="nom"
                            class="form-control @error('nom') is-invalid @enderror"
                            placeholder="{{ $type === 'entreprise' ? 'Raison sociale' : 'Nom de famille' }}"
                        >
                        @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Prénom (particulier seulement) --}}
                    @if ($type === 'particulier')
                        <div class="col-md-4">
                            <label class="form-label">Prénom</label>
                            <input type="text" wire:model="prenom" class="form-control" placeholder="Prénom">
                        </div>
                    @endif

                    {{-- Email --}}
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input
                            type="email"
                            wire:model="email"
                            class="form-control @error('email') is-invalid @enderror"
                            placeholder="contact@exemple.fr"
                        >
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Téléphone --}}
                    <div class="col-md-4">
                        <label class="form-label">Téléphone</label>
                        <input type="text" wire:model="telephone" class="form-control" placeholder="06 …">
                    </div>

                    {{-- Adresse --}}
                    <div class="col-12">
                        <label class="form-label">Adresse</label>
                        <textarea wire:model="adresse" class="form-control" rows="2" placeholder="Adresse postale"></textarea>
                    </div>

                    {{-- Flags --}}
                    <div class="col-12">
                        <label class="form-label fw-semibold">
                            Utilisation <span class="text-danger">*</span>
                        </label>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" wire:model="pour_depenses" id="pourDepenses">
                                <label class="form-check-label" for="pourDepenses">Dépenses (fournisseur, intervenant…)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" wire:model="pour_recettes" id="pourRecettes">
                                <label class="form-check-label" for="pourRecettes">Recettes (dons, cotisations, ventes…)</label>
                            </div>
                        </div>
                        @error('pour_depenses')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                {{-- Actions --}}
                <div class="d-flex gap-2 mt-4">
                    <button wire:click="resetForm" class="btn btn-outline-secondary">Annuler</button>
                    <button wire:click="save" class="btn text-white" style="background:#722281">
                        {{ $tiersId ? 'Mettre à jour' : 'Créer le tiers' }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 5 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Livewire/TiersFormTest.php
```
Attendu : 5 PASS

- [ ] **Step 6 : Lint + Commit**

```bash
./vendor/bin/sail artisan pint
git add app/Livewire/TiersForm.php resources/views/livewire/tiers-form.blade.php tests/Livewire/TiersFormTest.php
git commit -m "feat: TiersForm — formulaire création/édition"
```

---

## Chunk 4 : Routes, vue index, navbar

### Task 6 : Route + vue index + navbar

**Files:**
- Create: `resources/views/tiers/index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`

- [ ] **Step 1 : Écrire le test HTTP (rouge en premier)**

```php
<?php
// tests/Feature/Http/TiersRouteTest.php
declare(strict_types=1);

use App\Models\User;

it('GET /tiers returns 200 for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/tiers')
        ->assertOk()
        ->assertSee('Tiers');
});

it('GET /tiers redirects unauthenticated user', function () {
    $this->get('/tiers')
        ->assertRedirect('/login');
});
```

- [ ] **Step 2 : Lancer le test — vérifier l'échec**

```bash
./vendor/bin/sail artisan test tests/Feature/Http/TiersRouteTest.php
```
Attendu : FAIL — 404

- [ ] **Step 3 : Créer la vue index**

```blade
{{-- resources/views/tiers/index.blade.php --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="fw-semibold fs-5 text-dark">Tiers</h2>
    </x-slot>

    <div class="container py-4">
        @livewire('tiers-form')
        @livewire('tiers-list')
    </div>
</x-app-layout>
```

- [ ] **Step 4 : Ajouter la route dans `routes/web.php`**

Après la ligne `Route::view('/dons', 'dons.index')`, ajouter :

```php
Route::view('/tiers', 'tiers.index')->name('tiers.index');
```

- [ ] **Step 5 : Ajouter le lien Tiers dans la navbar**

Dans `resources/views/layouts/app.blade.php`, dans la navbar, ajouter un lien **Tiers** entre le dropdown Banques et Budget. Chercher le bloc qui ressemble à :

```html
{{-- Budget --}}
<li class="nav-item">
    <a class="nav-link ..." href="{{ route('budget.index') }}">Budget</a>
</li>
```

Et ajouter avant lui :

```html
{{-- Tiers --}}
<li class="nav-item">
    <a class="nav-link {{ request()->routeIs('tiers.*') ? 'active fw-semibold' : '' }}"
       href="{{ route('tiers.index') }}">
        Tiers
    </a>
</li>
```

- [ ] **Step 6 : Relancer le test HTTP**

```bash
./vendor/bin/sail artisan test tests/Feature/Http/TiersRouteTest.php
```
Attendu : 2 PASS

- [ ] **Step 7 : Vérifier manuellement dans le navigateur**

```bash
# Le serveur tourne déjà via Sail
# Ouvrir http://localhost/tiers
```

Vérifier :
- La page charge sans erreur
- Le bouton "Nouveau tiers" apparaît
- Le formulaire s'ouvre au clic
- La création d'un tiers fonctionne
- La liste s'affiche
- Le lien "Tiers" apparaît dans la navbar

- [ ] **Step 8 : Lancer la suite complète**

```bash
./vendor/bin/sail artisan test
```
Attendu : tous PASS

- [ ] **Step 9 : Lint + Commit**

```bash
./vendor/bin/sail artisan pint
git add resources/views/tiers/index.blade.php routes/web.php resources/views/layouts/app.blade.php tests/Feature/Http/TiersRouteTest.php
git commit -m "feat: route /tiers + vue index + lien navbar"
```

---

## Fin du Plan A

Après ce plan, la table `tiers` existe, le CRUD est opérationnel, et le lien est dans la navbar.

Le **Plan B** (à exécuter ensuite) couvre :
- Remplacement de `Donateur` et `Membre` par `Tiers`
- Mise à jour des colonnes FK (`dons.donateur_id` → `tiers_id`, `cotisations.membre_id` → `tiers_id`, `depenses.tiers` string → `tiers_id`, `recettes.tiers` string → `tiers_id`)
- Autocomplete Alpine.js dans tous les formulaires
- Vue Membres (tiers ayant une cotisation sur l'exercice actif)
- Suppression des modèles `Membre` et `Donateur`
- Mise à jour du `TransactionCompteService`
- Mise à jour de la navbar (Cotisations et Dons dans Transactions)
