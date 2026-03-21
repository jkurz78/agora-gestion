# Tiers — Restructuration modèle et formulaire unique

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enrichir le modèle `Tiers` (adresse structurée, entreprise, date de naissance, helloasso_id) et consolider les deux formulaires en un seul modal avec section détails repliable.

**Architecture:** Migration renomme `adresse` → `adresse_ligne1` et ajoute 6 colonnes. `TiersForm` devient le formulaire unique (radio type, accordéon détails, listener `open-tiers-form`). `TiersAutocomplete` supprime son mini-modal et dispatche vers `TiersForm`.

**Tech Stack:** Laravel 11, Livewire 4, Pest PHP, Bootstrap 5 (CDN), MySQL (Docker Sail)

**Spec:** `docs/superpowers/specs/2026-03-21-tiers-restructuration-design.md`

**Commande de test globale:** `./vendor/bin/sail artisan test --stop-on-failure`

---

## Task 1 : Migration — Restructurer la table `tiers`

**Files:**
- Create: `database/migrations/2026_03_21_100000_restructure_tiers_table.php`

- [ ] **Étape 1 : Écrire le test de migration**

Ajouter en tête de `tests/Unit/Models/TiersTest.php` (après la ligne `use App\Models\Tiers;` existante) :

```php
use Illuminate\Support\Facades\Schema;
```

Puis ajouter les tests :

```php
it('tiers table has new columns after migration', function () {
    expect(Schema::hasColumn('tiers', 'adresse_ligne1'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'adresse'))->toBeFalse();
    expect(Schema::hasColumn('tiers', 'code_postal'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'ville'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'pays'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'entreprise'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'date_naissance'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'helloasso_id'))->toBeTrue();
});

it('helloasso_id unique constraint allows multiple nulls', function () {
    Tiers::factory()->create(['helloasso_id' => null, 'pour_depenses' => true]);
    Tiers::factory()->create(['helloasso_id' => null, 'pour_depenses' => true]);
    expect(Tiers::whereNull('helloasso_id')->count())->toBe(2);
});

it('helloasso_id unique constraint rejects duplicate non-null values', function () {
    Tiers::factory()->create(['helloasso_id' => 'ha-123', 'pour_depenses' => true]);
    expect(fn () => Tiers::factory()->create(['helloasso_id' => 'ha-123', 'pour_depenses' => true]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Étape 2 : Lancer les tests — vérifier l'échec**

```bash
./vendor/bin/sail artisan test tests/Unit/Models/TiersTest.php --filter="tiers table has new columns"
```
Attendu : FAIL — `adresse_ligne1` n'existe pas encore.

- [ ] **Étape 3 : Créer la migration**

```php
<?php
// database/migrations/2026_03_21_100000_restructure_tiers_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->renameColumn('adresse', 'adresse_ligne1');
            $table->string('code_postal', 10)->nullable()->after('adresse_ligne1');
            $table->string('ville', 100)->nullable()->after('code_postal');
            $table->string('pays', 100)->nullable()->default('France')->after('ville');
            $table->string('entreprise', 255)->nullable()->after('prenom');
            $table->date('date_naissance')->nullable()->after('entreprise');
            $table->string('helloasso_id', 255)->nullable()->unique()->after('date_naissance');
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->renameColumn('adresse_ligne1', 'adresse');
            $table->dropColumn([
                'code_postal', 'ville', 'pays',
                'entreprise', 'date_naissance', 'helloasso_id',
            ]);
        });
    }
};
```

- [ ] **Étape 4 : Lancer la migration**

```bash
./vendor/bin/sail artisan migrate
```

- [ ] **Étape 5 : Lancer les tests — vérifier le succès**

```bash
./vendor/bin/sail artisan test tests/Unit/Models/TiersTest.php
```
Attendu : tous les tests PASS (y compris les anciens).

- [ ] **Étape 6 : Commit**

```bash
git add database/migrations/2026_03_21_100000_restructure_tiers_table.php tests/Unit/Models/TiersTest.php
git commit -m "feat(tiers): migration restructuration — adresse_ligne1, entreprise, date_naissance, helloasso_id"
```

---

## Task 2 : Modèle `Tiers` — Nouveaux champs et displayName()

**Files:**
- Modify: `app/Models/Tiers.php`
- Modify: `database/factories/TiersFactory.php`
- Modify: `tests/Unit/Models/TiersTest.php`

- [ ] **Étape 1 : Ajouter les tests du modèle**

Ajouter dans `tests/Unit/Models/TiersTest.php` :

```php
it('displayName returns entreprise field for entreprise type', function () {
    $tiers = new Tiers(['type' => 'entreprise', 'entreprise' => 'ACME Corp', 'nom' => 'Dupont']);
    expect($tiers->displayName())->toBe('ACME Corp');
});

it('displayName falls back to nom when entreprise field is null', function () {
    $tiers = new Tiers(['type' => 'entreprise', 'entreprise' => null, 'nom' => 'Mairie de Lyon']);
    expect($tiers->displayName())->toBe('Mairie de Lyon');
});

it('can create tiers with all new fields', function () {
    $tiers = Tiers::factory()->create([
        'entreprise'    => 'ACME Corp',
        'code_postal'   => '75001',
        'ville'         => 'Paris',
        'pays'          => 'France',
        'date_naissance' => '1990-05-15',
        'helloasso_id'  => 'ha-abc123',
        'pour_depenses' => true,
    ]);
    expect($tiers->entreprise)->toBe('ACME Corp');
    expect($tiers->code_postal)->toBe('75001');
    expect($tiers->pays)->toBe('France');
    expect($tiers->helloasso_id)->toBe('ha-abc123');
});
```

- [ ] **Étape 2 : Lancer les tests — vérifier l'échec**

```bash
./vendor/bin/sail artisan test tests/Unit/Models/TiersTest.php --filter="displayName returns entreprise"
```
Attendu : FAIL — `displayName()` retourne encore `$this->nom`.

- [ ] **Étape 3 : Mettre à jour `app/Models/Tiers.php`**

Remplacer le contenu du fichier :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Tiers extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'nom',
        'prenom',
        'entreprise',
        'email',
        'telephone',
        'adresse_ligne1',
        'code_postal',
        'ville',
        'pays',
        'date_naissance',
        'pour_depenses',
        'pour_recettes',
        'helloasso_id',
    ];

    protected function casts(): array
    {
        return [
            'pour_depenses'  => 'boolean',
            'pour_recettes'  => 'boolean',
            'date_naissance' => 'date',
        ];
    }

    public function displayName(): string
    {
        if ($this->type === 'entreprise') {
            return $this->entreprise ?? $this->nom;
        }

        return trim(($this->prenom ? $this->prenom . ' ' : '') . $this->nom);
    }

    public function dons(): HasMany
    {
        return $this->hasMany(Don::class);
    }

    public function cotisations(): HasMany
    {
        return $this->hasMany(Cotisation::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
```

- [ ] **Étape 4 : Mettre à jour `database/factories/TiersFactory.php`**

Remplacer le contenu :

```php
<?php

// database/factories/TiersFactory.php
declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tiers;
use Illuminate\Database\Eloquent\Factories\Factory;

final class TiersFactory extends Factory
{
    protected $model = Tiers::class;

    public function definition(): array
    {
        $type = fake()->randomElement(['entreprise', 'particulier']);

        return [
            'type'           => $type,
            'nom'            => $type === 'entreprise' ? fake()->lastName() : fake()->lastName(),
            'prenom'         => $type === 'particulier' ? fake()->firstName() : null,
            'entreprise'     => $type === 'entreprise' ? fake()->company() : null,
            'email'          => fake()->optional()->safeEmail(),
            'telephone'      => fake()->optional()->phoneNumber(),
            'adresse_ligne1' => fake()->optional()->streetAddress(),
            'code_postal'    => fake()->optional()->postcode(),
            'ville'          => fake()->optional()->city(),
            'pays'           => 'France',
            'date_naissance' => null,
            'helloasso_id'   => null,
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

    public function membre(): static
    {
        return $this->state([
            'type'          => 'particulier',
            'prenom'        => fake()->firstName(),
            'pour_depenses' => false,
            'pour_recettes' => false,
        ]);
    }
}
```

- [ ] **Étape 5 : Corriger les références à `adresse` dans `TiersAutocomplete`**

Dans `app/Livewire/TiersAutocomplete.php`, la méthode `doSearch()` est OK (ne référence pas `adresse`).
Vérifier aussi `app/Livewire/TiersList.php` si il accède à `$tiers->adresse` — remplacer par `$tiers->adresse_ligne1`.

```bash
grep -rn "->adresse\b\|'adresse'\b\|\"adresse\"" \
  app/Livewire app/Services resources/views \
  --include="*.php" --include="*.blade.php"
```

Pour chaque occurrence trouvée, remplacer `adresse` par `adresse_ligne1` (sauf dans les migrations).

- [ ] **Étape 6 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Unit/Models/TiersTest.php
```
Attendu : tous PASS.

- [ ] **Étape 7 : Lancer la suite complète pour détecter les régressions**

```bash
./vendor/bin/sail artisan test --stop-on-failure
```
Attendu : PASS. Si FAIL, corriger les références `adresse` → `adresse_ligne1` dans les fichiers signalés.

- [ ] **Étape 8 : Commit**

```bash
git add app/Models/Tiers.php database/factories/TiersFactory.php tests/Unit/Models/TiersTest.php
git commit -m "feat(tiers): modèle — nouveaux champs, displayName() entreprise, factory mise à jour"
```

---

## Task 3 : `TiersForm` — Formulaire unique avec détails repliables

**Files:**
- Modify: `app/Livewire/TiersForm.php`
- Modify: `resources/views/livewire/tiers-form.blade.php`
- Modify: `tests/Livewire/TiersFormTest.php`

- [ ] **Étape 1 : Écrire les tests**

Remplacer le contenu de `tests/Livewire/TiersFormTest.php` :

```php
<?php

// tests/Livewire/TiersFormTest.php
declare(strict_types=1);

use App\Livewire\TiersForm;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// --- Création particulier ---

it('can create a particulier with minimal fields', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'particulier')
        ->set('nom', 'Dupont')
        ->set('pour_recettes', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('tiers-saved');

    $this->assertDatabaseHas('tiers', ['nom' => 'Dupont', 'type' => 'particulier']);
});

it('dispatches tiers-saved with the created tiers id', function () {
    $component = Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('nom', 'Durand')
        ->set('pour_recettes', true)
        ->call('save');

    $tiers = Tiers::where('nom', 'Durand')->firstOrFail();
    $component->assertDispatched('tiers-saved', id: $tiers->id);
});

it('can create a particulier with all fields', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'particulier')
        ->set('nom', 'Martin')
        ->set('prenom', 'Jean')
        ->set('email', 'jean@example.fr')
        ->set('telephone', '06 12 34 56 78')
        ->set('adresse_ligne1', '5 rue du Port')
        ->set('code_postal', '78500')
        ->set('ville', 'Sartrouville')
        ->set('pays', 'France')
        ->set('date_naissance', '1980-06-15')
        ->set('pour_recettes', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('tiers', [
        'nom'          => 'Martin',
        'prenom'       => 'Jean',
        'code_postal'  => '78500',
        'ville'        => 'Sartrouville',
        'date_naissance' => '1980-06-15',
    ]);
});

// --- Création entreprise ---

it('can create an entreprise with minimal fields', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'entreprise')
        ->set('entreprise', 'ACME Corp')
        ->set('pour_depenses', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('tiers-saved');

    $this->assertDatabaseHas('tiers', ['entreprise' => 'ACME Corp', 'type' => 'entreprise']);
});

it('requires entreprise field when type is entreprise', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'entreprise')
        ->set('entreprise', '')
        ->set('pour_depenses', true)
        ->call('save')
        ->assertHasErrors(['entreprise']);
});

// --- Switch radio ---

it('switch to entreprise concatenates prenom and nom into entreprise field', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('nom', 'Martin')
        ->set('prenom', 'Jean')
        ->set('type', 'entreprise') // déclenche updatedType()
        ->assertSet('entreprise', 'Jean Martin')
        ->assertSet('nom', '')
        ->assertSet('prenom', null);
});

it('switch to entreprise with only nom fills entreprise field', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('nom', 'Dupont')
        ->set('type', 'entreprise')
        ->assertSet('entreprise', 'Dupont')
        ->assertSet('nom', '');
});

// --- Validation ---

it('validates nom required for particulier', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'particulier')
        ->set('nom', '')
        ->set('pour_recettes', true)
        ->call('save')
        ->assertHasErrors(['nom']);
});

it('validates at least one usage flag', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('nom', 'Test')
        ->set('pour_depenses', false)
        ->set('pour_recettes', false)
        ->call('save')
        ->assertHasErrors(['pour_depenses']);
});

// --- Édition ---

it('loads existing tiers for editing', function () {
    $tiers = Tiers::factory()->create([
        'nom'        => 'Leclerc',
        'type'       => 'entreprise',
        'entreprise' => 'Leclerc SA',
        'ville'      => 'Bordeaux',
    ]);

    Livewire::test(TiersForm::class)
        ->dispatch('edit-tiers', id: $tiers->id)
        ->assertSet('nom', 'Leclerc')
        ->assertSet('entreprise', 'Leclerc SA')
        ->assertSet('ville', 'Bordeaux')
        ->assertSet('showDetails', true); // ville est renseignée → détails ouverts
});

it('details section is closed on new form', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->assertSet('showDetails', false);
});

it('can update a tiers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Ancien', 'pour_depenses' => true]);

    Livewire::test(TiersForm::class)
        ->dispatch('edit-tiers', id: $tiers->id)
        ->set('nom', 'Nouveau')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('tiers-saved');

    expect($tiers->fresh()->nom)->toBe('Nouveau');
});

// --- Listener open-tiers-form ---

it('opens with prefill from open-tiers-form event', function () {
    Livewire::test(TiersForm::class)
        ->dispatch('open-tiers-form', prefill: [
            'nom'           => 'Jean Dupont',
            'pour_recettes' => true,
            'pour_depenses' => false,
        ])
        ->assertSet('showForm', true)
        ->assertSet('nom', 'Jean Dupont')
        ->assertSet('type', 'particulier')
        ->assertSet('pour_recettes', true)
        ->assertSet('pour_depenses', false);
});
```

- [ ] **Étape 2 : Lancer les tests — vérifier l'échec**

```bash
./vendor/bin/sail artisan test tests/Livewire/TiersFormTest.php
```
Attendu : plusieurs FAIL (nouvelles propriétés et méthodes absentes).

- [ ] **Étape 3 : Mettre à jour `app/Livewire/TiersForm.php`**

Remplacer le contenu :

```php
<?php

// app/Livewire/TiersForm.php
declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\TiersService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class TiersForm extends Component
{
    public ?int $tiersId = null;

    public string $type = 'particulier';

    public string $nom = '';

    public ?string $prenom = null;

    public ?string $entreprise = null;

    public ?string $email = null;

    public ?string $telephone = null;

    public ?string $adresse_ligne1 = null;

    public ?string $code_postal = null;

    public ?string $ville = null;

    public string $pays = 'France';

    public ?string $date_naissance = null;

    public bool $pour_depenses = false;

    public bool $pour_recettes = false;

    public bool $showForm = false;

    public bool $showDetails = false;

    public function showNewForm(): void
    {
        $this->reset([
            'tiersId', 'type', 'nom', 'prenom', 'entreprise', 'email', 'telephone',
            'adresse_ligne1', 'code_postal', 'ville', 'pays', 'date_naissance',
            'pour_depenses', 'pour_recettes', 'showDetails',
        ]);
        $this->type = 'particulier';
        $this->pays = 'France';
        $this->resetValidation();
        $this->showForm = true;
    }

    // Appelé automatiquement par Livewire quand $type change via wire:model.live
    public function updatedType(): void
    {
        if ($this->type === 'entreprise') {
            $this->entreprise = trim(($this->prenom ? $this->prenom . ' ' : '') . $this->nom);
            $this->nom = '';
            $this->prenom = null;
        }
    }

    #[On('edit-tiers')]
    public function edit(int $id): void
    {
        $tiers = Tiers::findOrFail($id);

        $this->tiersId       = $tiers->id;
        $this->type          = $tiers->type;
        $this->nom           = $tiers->nom;
        $this->prenom        = $tiers->prenom;
        $this->entreprise    = $tiers->entreprise;
        $this->email         = $tiers->email;
        $this->telephone     = $tiers->telephone;
        $this->adresse_ligne1 = $tiers->adresse_ligne1;
        $this->code_postal   = $tiers->code_postal;
        $this->ville         = $tiers->ville;
        $this->pays          = $tiers->pays ?? 'France';
        $this->date_naissance = $tiers->date_naissance?->format('Y-m-d');
        $this->pour_depenses = $tiers->pour_depenses;
        $this->pour_recettes = $tiers->pour_recettes;

        // Ouvrir la section détails si au moins un champ y est renseigné
        $this->showDetails = (bool) ($tiers->email || $tiers->telephone
            || $tiers->adresse_ligne1 || $tiers->code_postal
            || $tiers->ville || ($tiers->pays && $tiers->pays !== 'France')
            || $tiers->date_naissance);

        $this->showForm = true;
    }

    #[On('open-tiers-form')]
    public function openWithPrefill(array $prefill): void
    {
        $this->reset([
            'tiersId', 'type', 'nom', 'prenom', 'entreprise', 'email', 'telephone',
            'adresse_ligne1', 'code_postal', 'ville', 'pays', 'date_naissance',
            'pour_depenses', 'pour_recettes', 'showDetails',
        ]);
        $this->type          = 'particulier';
        $this->pays          = 'France';
        $this->nom           = $prefill['nom'] ?? '';
        $this->pour_recettes = (bool) ($prefill['pour_recettes'] ?? false);
        $this->pour_depenses = (bool) ($prefill['pour_depenses'] ?? false);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function resetForm(): void
    {
        $this->reset([
            'tiersId', 'type', 'nom', 'prenom', 'entreprise', 'email', 'telephone',
            'adresse_ligne1', 'code_postal', 'ville', 'pays', 'date_naissance',
            'pour_depenses', 'pour_recettes', 'showForm', 'showDetails',
        ]);
        $this->pays = 'France';
        $this->resetValidation();
    }

    public function save(): void
    {
        $rules = [
            'type'            => ['required', 'in:entreprise,particulier'],
            'nom'             => $this->type === 'particulier'
                ? ['required', 'string', 'max:150']
                : ['nullable', 'string', 'max:150'],
            'prenom'          => ['nullable', 'string', 'max:100'],
            'entreprise'      => $this->type === 'entreprise'
                ? ['required', 'string', 'max:255']
                : ['nullable', 'string', 'max:255'],
            'email'           => ['nullable', 'email', 'max:255'],
            'telephone'       => ['nullable', 'string', 'max:30'],
            'adresse_ligne1'  => ['nullable', 'string'],
            'code_postal'     => ['nullable', 'string', 'max:10'],
            'ville'           => ['nullable', 'string', 'max:100'],
            'pays'            => ['nullable', 'string', 'max:100'],
            'date_naissance'  => ['nullable', 'date'],
            'pour_depenses'   => ['boolean'],
            'pour_recettes'   => ['boolean'],
        ];

        $validated = $this->validate($rules, [
            'nom.required'        => 'Le nom est obligatoire.',
            'entreprise.required' => 'La raison sociale est obligatoire.',
        ]);

        if (! $this->pour_depenses && ! $this->pour_recettes) {
            $this->addError('pour_depenses', 'Cochez au moins une utilisation (dépenses ou recettes).');
            return;
        }

        $service = app(TiersService::class);

        if ($this->tiersId) {
            $tiers = Tiers::findOrFail($this->tiersId);
            $tiers = $service->update($tiers, $validated);
        } else {
            $tiers = $service->create($validated);
        }

        $id = $tiers->id;
        $this->dispatch('tiers-saved', id: $id);
        $this->resetForm();
    }

    public function render(): View
    {
        return view('livewire.tiers-form');
    }
}
```

- [ ] **Étape 4 : Mettre à jour la vue `resources/views/livewire/tiers-form.blade.php`**

Remplacer le contenu :

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
        <div class="position-fixed top-0 start-0 w-100 h-100"
             style="background:rgba(0,0,0,.5);z-index:1040;overflow-y:auto"
             wire:click.self="resetForm">
        <div class="container py-4">
        <div class="card mb-4 shadow-sm border-0">
            <div class="card-header fw-semibold" style="background:#722281;color:white">
                {{ $tiersId ? 'Modifier le tiers' : 'Nouveau tiers' }}
            </div>
            <div class="card-body">
                <div class="row g-3">

                    {{-- Type --}}
                    <div class="col-12">
                        <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       wire:model.live="type" value="particulier" id="type_particulier">
                                <label class="form-check-label" for="type_particulier">Particulier</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       wire:model.live="type" value="entreprise" id="type_entreprise">
                                <label class="form-check-label" for="type_entreprise">Entreprise</label>
                            </div>
                        </div>
                        @error('type') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>

                    {{-- Entreprise (visible si type = entreprise) --}}
                    @if ($type === 'entreprise')
                        <div class="col-md-8">
                            <label class="form-label">Raison sociale <span class="text-danger">*</span></label>
                            <input type="text" wire:model="entreprise"
                                   class="form-control @error('entreprise') is-invalid @enderror"
                                   placeholder="Raison sociale">
                            @error('entreprise') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    @endif

                    {{-- Nom (toujours présent, label selon type) --}}
                    @if ($type === 'particulier')
                        <div class="col-md-4">
                            <label class="form-label">Nom <span class="text-danger">*</span></label>
                            <input type="text" wire:model="nom"
                                   class="form-control @error('nom') is-invalid @enderror"
                                   placeholder="Nom de famille">
                            @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Prénom</label>
                            <input type="text" wire:model="prenom" class="form-control" placeholder="Prénom">
                        </div>
                    @else
                        {{-- Champ Nom contact (optionnel, dans section principale pour entreprise) --}}
                        <div class="col-md-4">
                            <label class="form-label text-muted">Contact</label>
                            <input type="text" wire:model="nom" class="form-control"
                                   placeholder="Nom du contact (optionnel)">
                        </div>
                    @endif

                    {{-- Usage --}}
                    <div class="col-12">
                        <label class="form-label fw-semibold">
                            Utilisation <span class="text-danger">*</span>
                        </label>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       wire:model="pour_depenses" id="pourDepenses">
                                <label class="form-check-label" for="pourDepenses">Dépenses</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       wire:model="pour_recettes" id="pourRecettes">
                                <label class="form-check-label" for="pourRecettes">Recettes</label>
                            </div>
                        </div>
                        @error('pour_depenses')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Bouton toggle détails --}}
                    <div class="col-12">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                wire:click="$toggle('showDetails')">
                            {{ $showDetails ? '▲ Masquer les détails' : '▼ Détails (adresse, contact, date de naissance…)' }}
                        </button>
                    </div>

                    {{-- Section détails --}}
                    @if ($showDetails)
                        {{-- Email --}}
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" wire:model="email"
                                   class="form-control @error('email') is-invalid @enderror"
                                   placeholder="contact@exemple.fr">
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        {{-- Téléphone --}}
                        <div class="col-md-6">
                            <label class="form-label">Téléphone</label>
                            <input type="text" wire:model="telephone" class="form-control" placeholder="06 …">
                        </div>

                        {{-- Adresse --}}
                        <div class="col-12">
                            <label class="form-label">Adresse</label>
                            <input type="text" wire:model="adresse_ligne1" class="form-control"
                                   placeholder="N° et nom de rue">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Code postal</label>
                            <input type="text" wire:model="code_postal" class="form-control" placeholder="75001">
                        </div>

                        <div class="col-md-5">
                            <label class="form-label">Ville</label>
                            <input type="text" wire:model="ville" class="form-control" placeholder="Paris">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Pays</label>
                            <input type="text" wire:model="pays" class="form-control" placeholder="France">
                        </div>

                        {{-- Date de naissance --}}
                        <div class="col-md-4">
                            <label class="form-label">Date de naissance</label>
                            <input type="date" wire:model="date_naissance" class="form-control">
                        </div>
                    @endif

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
        </div>
        </div>
    @endif
</div>
```

- [ ] **Étape 5 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Livewire/TiersFormTest.php
```
Attendu : tous PASS.

- [ ] **Étape 6 : Commit**

```bash
git add app/Livewire/TiersForm.php resources/views/livewire/tiers-form.blade.php \
        tests/Livewire/TiersFormTest.php
git commit -m "feat(tiers): formulaire unique — radio type, entreprise, adresse structurée, accordéon détails"
```

---

## Task 4 : `TiersAutocomplete` — Suppression mini-modal, dispatch vers TiersForm

**Files:**
- Modify: `app/Livewire/TiersAutocomplete.php`
- Modify: `resources/views/livewire/tiers-autocomplete.blade.php`
- Modify: `tests/Livewire/TiersAutocompleteTest.php`

- [ ] **Étape 1 : Écrire les tests**

Remplacer le contenu de `tests/Livewire/TiersAutocompleteTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\TiersAutocomplete;
use App\Livewire\TiersForm;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the component', function () {
    Livewire::test(TiersAutocomplete::class)->assertOk();
});

it('can search tiers by name', function () {
    Tiers::factory()->create(['nom' => 'Dupont', 'pour_depenses' => true]);
    Tiers::factory()->create(['nom' => 'Martin', 'pour_depenses' => true]);

    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->set('search', 'Dup')
        ->assertSet('open', true)
        ->call('doSearch')
        ->assertSee('Dupont')
        ->assertDontSee('Martin');
});

it('can search tiers by entreprise name', function () {
    Tiers::factory()->create([
        'type' => 'entreprise', 'entreprise' => 'ACME Corp', 'nom' => 'Dupont',
        'pour_depenses' => true,
    ]);

    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->set('search', 'ACME')
        ->call('doSearch')
        ->assertSee('ACME Corp');
});

it('can select a tiers', function () {
    $tiers = Tiers::factory()->create([
        'type' => 'entreprise', 'entreprise' => 'ACME Corp', 'nom' => 'Dupont',
        'pour_depenses' => true,
    ]);

    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->call('selectTiers', $tiers->id)
        ->assertSet('tiersId', $tiers->id)
        ->assertSet('selectedLabel', 'ACME Corp');
});

it('can clear the selection', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'pour_recettes' => true]);

    Livewire::test(TiersAutocomplete::class)
        ->call('selectTiers', $tiers->id)
        ->call('clearTiers')
        ->assertSet('tiersId', null)
        ->assertSet('selectedLabel', null);
});

it('dispatches open-tiers-form with prefill when creating new tiers', function () {
    Livewire::test(TiersAutocomplete::class, ['filtre' => 'recettes'])
        ->set('search', 'Jean Dupont')
        ->call('openCreateModal')
        ->assertDispatched('open-tiers-form', prefill: [
            'nom'           => 'Jean Dupont',
            'pour_recettes' => true,
            'pour_depenses' => false,
        ]);
});

it('dispatches open-tiers-form with depenses flag for depenses filter', function () {
    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->set('search', 'ACME')
        ->call('openCreateModal')
        ->assertDispatched('open-tiers-form', prefill: [
            'nom'           => 'ACME',
            'pour_recettes' => false,
            'pour_depenses' => true,
        ]);
});

it('selects tiers on tiers-saved event', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Nouveau', 'pour_recettes' => true]);

    Livewire::test(TiersAutocomplete::class)
        ->dispatch('tiers-saved', id: $tiers->id)
        ->assertSet('tiersId', $tiers->id);
});

it('shows activate modal for tiers excluded by filter', function () {
    $tiers = Tiers::factory()->create([
        'nom' => 'Dupont', 'pour_depenses' => false, 'pour_recettes' => true,
    ]);

    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->set('search', 'Dupont')
        ->call('openCreateModal')
        ->assertSet('showActivateModal', true);
});

it('confirmCreate method no longer exists', function () {
    expect(method_exists(TiersAutocomplete::class, 'confirmCreate'))->toBeFalse();
});
```

- [ ] **Étape 2 : Lancer les tests — vérifier l'échec**

```bash
./vendor/bin/sail artisan test tests/Livewire/TiersAutocompleteTest.php
```
Attendu : plusieurs FAIL.

- [ ] **Étape 3 : Mettre à jour `app/Livewire/TiersAutocomplete.php`**

Remplacer le contenu :

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use Illuminate\View\View;
use Livewire\Attributes\Modelable;
use Livewire\Attributes\On;
use Livewire\Component;

final class TiersAutocomplete extends Component
{
    #[Modelable]
    public ?int $tiersId = null;

    public string $filtre = 'tous'; // 'depenses' | 'recettes' | 'dons' | 'tous'

    public string $search = '';

    public bool $open = false;

    public ?string $selectedLabel = null;

    public ?string $selectedType = null;

    /** @var array{id: int, label: string, type: string}|null */
    public ?array $existingTiers = null;

    public bool $showActivateModal = false;

    /** @var array<int, array{id: int, label: string, type: string}> */
    public array $results = [];

    public function mount(): void
    {
        if ($this->tiersId !== null) {
            $tiers = Tiers::find($this->tiersId);
            $this->selectedLabel = $tiers?->displayName();
            $this->selectedType  = $tiers?->type;
        }
    }

    public function updatedTiersId(mixed $value): void
    {
        $id = ($value !== '' && $value !== null) ? (int) $value : null;
        $this->tiersId = $id;
        if ($id !== null) {
            $tiers = Tiers::find($id);
            $this->selectedLabel = $tiers?->displayName();
            $this->selectedType  = $tiers?->type;
        } else {
            $this->selectedLabel = null;
            $this->selectedType  = null;
        }
    }

    public function updatedSearch(): void
    {
        $this->doSearch();
    }

    public function doSearch(): void
    {
        $query = Tiers::query();

        match ($this->filtre) {
            'depenses' => $query->where('pour_depenses', true),
            'recettes' => $query->where('pour_recettes', true),
            'dons'     => $query->where('pour_recettes', true),
            default    => null,
        };

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('nom', 'like', '%' . $this->search . '%')
                    ->orWhere('prenom', 'like', '%' . $this->search . '%')
                    ->orWhere('entreprise', 'like', '%' . $this->search . '%');
            });
        }

        $this->results = $query->limit(8)->get()->map(fn (Tiers $t): array => [
            'id'   => $t->id,
            'label' => $t->displayName(),
            'type' => $t->type,
        ])->toArray();

        $this->open = true;
    }

    public function selectTiers(int $id): void
    {
        $tiers = Tiers::findOrFail($id);
        $this->tiersId       = $tiers->id;
        $this->selectedLabel = $tiers->displayName();
        $this->selectedType  = $tiers->type;
        $this->search        = '';
        $this->open          = false;
        $this->results       = [];
    }

    public function clearTiers(): void
    {
        $this->tiersId        = null;
        $this->selectedLabel  = null;
        $this->selectedType   = null;
        $this->search         = '';
        $this->open           = false;
        $this->results        = [];
        $this->existingTiers  = null;
        $this->showActivateModal = false;
    }

    public function openCreateModal(): void
    {
        $search   = $this->search;
        $existing = Tiers::where(function ($q) use ($search): void {
            $q->where('nom', 'like', '%' . $search . '%')
                ->orWhere('prenom', 'like', '%' . $search . '%')
                ->orWhere('entreprise', 'like', '%' . $search . '%');
        })->first();

        $excludedByFilter = $existing && match ($this->filtre) {
            'depenses'        => ! $existing->pour_depenses,
            'recettes', 'dons' => ! $existing->pour_recettes,
            default           => false,
        };

        if ($excludedByFilter) {
            $this->existingTiers = [
                'id'    => $existing->id,
                'label' => $existing->displayName(),
                'type'  => $existing->type,
            ];
            $this->showActivateModal = true;
            $this->open = false;
        } else {
            $this->dispatch('open-tiers-form', prefill: [
                'nom'           => $this->search,
                'pour_recettes' => in_array($this->filtre, ['recettes', 'dons']),
                'pour_depenses' => $this->filtre === 'depenses',
            ])->to(TiersForm::class);
            $this->open = false;
        }
    }

    public function activateTiers(): void
    {
        if ($this->existingTiers === null) {
            return;
        }

        $tiers = Tiers::findOrFail($this->existingTiers['id']);

        $updates = match ($this->filtre) {
            'depenses'         => ['pour_depenses' => true],
            'recettes', 'dons' => ['pour_recettes' => true],
            default            => [],
        };

        if (! empty($updates)) {
            $tiers->update($updates);
        }

        $this->selectTiers($tiers->id);
        $this->showActivateModal = false;
        $this->existingTiers     = null;
    }

    #[On('tiers-saved')]
    public function onTiersSaved(int $id): void
    {
        $this->selectTiers($id);
    }

    public function render(): View
    {
        return view('livewire.tiers-autocomplete');
    }
}
```

- [ ] **Étape 4 : Mettre à jour la vue `resources/views/livewire/tiers-autocomplete.blade.php`**

Supprimer le bloc `{{-- Create modal --}}` en entier — depuis le commentaire `{{-- Create modal --}}` jusqu'au `@endif` correspondant inclus. Garder tout le reste (search input, results dropdown, activate modal).

Le bloc `+ Créer "..."` dans la liste de résultats appelle maintenant `openCreateModal` (inchangé) :
```blade
wire:click="openCreateModal"
```
C'est déjà correct — pas de changement sur cette ligne.

- [ ] **Étape 5 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Livewire/TiersAutocompleteTest.php
```
Attendu : tous PASS.

- [ ] **Étape 6 : Lancer la suite complète**

```bash
./vendor/bin/sail artisan test --stop-on-failure
```
Attendu : tous PASS.

- [ ] **Étape 7 : Commit**

```bash
git add app/Livewire/TiersAutocomplete.php \
        resources/views/livewire/tiers-autocomplete.blade.php \
        tests/Livewire/TiersAutocompleteTest.php
git commit -m "feat(tiers): autocomplete — supprime mini-modal, dispatch open-tiers-form vers TiersForm"
```

---

## Task 5 : Vérification finale et suite complète des tests

- [ ] **Étape 1 : Lancer migrate:fresh avec seeds**

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```
Attendu : 0 erreur.

- [ ] **Étape 2 : Lancer tous les tests**

```bash
./vendor/bin/sail artisan test
```
Attendu : tous PASS.

- [ ] **Étape 3 : Vérifier en navigation manuelle**

Tester dans le navigateur sur http://localhost :
1. Page `/tiers` → bouton "Nouveau tiers" → modal s'ouvre
2. Saisir un nom → switch radio vers "Entreprise" → nom/prénom transférés dans entreprise
3. Clic "▼ Détails" → section s'ouvre, saisir une adresse en 4 champs
4. Créer le tiers → succès
5. Éditer le tiers → modal s'ouvre avec détails déjà ouverts (adresse renseignée)
6. Dans une saisie de transaction → champ tiers autocomplete → `+ Créer "X"` → `TiersForm` modal s'ouvre pré-rempli → créer → tiers sélectionné automatiquement
