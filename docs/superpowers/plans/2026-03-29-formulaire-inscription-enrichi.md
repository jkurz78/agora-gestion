# Formulaire d'inscription enrichi — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transformer le formulaire public d'inscription en un wizard 7 pages Alpine.js couvrant coordonnées, santé, documents, infos pratiques, engagement financier, droit à l'image et engagements.

**Architecture:** Wizard côté client Alpine.js dans une seule vue Blade avec partials par étape. Un seul POST final persiste toutes les données. Pas de sauvegarde intermédiaire — les données restent dans le DOM.

**Tech Stack:** Laravel 11, Alpine.js (CDN), Bootstrap 5, Pest PHP

**Spec:** `docs/superpowers/specs/2026-03-29-formulaire-inscription-enrichi-design.md`

---

## Task 1 : Enum DroitImage

**Files:**
- Create: `app/Enums/DroitImage.php`
- Test: `tests/Unit/DroitImageTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Enums\DroitImage;

test('enum has expected cases', function () {
    expect(DroitImage::cases())->toHaveCount(4);
    expect(DroitImage::UsagePropre->value)->toBe('usage_propre');
    expect(DroitImage::UsageConfidentiel->value)->toBe('usage_confidentiel');
    expect(DroitImage::Diffusion->value)->toBe('diffusion');
    expect(DroitImage::Refus->value)->toBe('refus');
});

test('label returns french labels', function () {
    expect(DroitImage::UsagePropre->label())->toBe('Usage propre');
    expect(DroitImage::UsageConfidentiel->label())->toBe('Usage confidentiel');
    expect(DroitImage::Diffusion->label())->toBe('Diffusion');
    expect(DroitImage::Refus->label())->toBe('Refus');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Unit/DroitImageTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create the enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum DroitImage: string
{
    case UsagePropre = 'usage_propre';
    case UsageConfidentiel = 'usage_confidentiel';
    case Diffusion = 'diffusion';
    case Refus = 'refus';

    public function label(): string
    {
        return match ($this) {
            self::UsagePropre => 'Usage propre',
            self::UsageConfidentiel => 'Usage confidentiel',
            self::Diffusion => 'Diffusion',
            self::Refus => 'Refus',
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Unit/DroitImageTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Enums/DroitImage.php tests/Unit/DroitImageTest.php
git commit -m "feat(formulaire): add DroitImage enum"
```

---

## Task 2 : Migrations

**Files:**
- Create: `database/migrations/2026_03_29_100001_add_medecin_therapeute_to_participant_donnees_medicales.php`
- Create: `database/migrations/2026_03_29_100002_add_inscription_fields_to_participants.php`
- Create: `database/migrations/2026_03_29_100003_add_helloasso_url_to_exercices.php`
- Create: `database/migrations/2026_03_29_100004_add_attestation_medicale_path_to_type_operations.php`

- [ ] **Step 1: Create migration for participant_donnees_medicales**

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
        Schema::table('participant_donnees_medicales', function (Blueprint $table): void {
            $table->text('medecin_nom')->nullable()->after('notes');
            $table->text('medecin_prenom')->nullable()->after('medecin_nom');
            $table->text('medecin_telephone')->nullable()->after('medecin_prenom');
            $table->text('medecin_email')->nullable()->after('medecin_telephone');
            $table->text('medecin_adresse')->nullable()->after('medecin_email');
            $table->text('therapeute_nom')->nullable()->after('medecin_adresse');
            $table->text('therapeute_prenom')->nullable()->after('therapeute_nom');
            $table->text('therapeute_telephone')->nullable()->after('therapeute_prenom');
            $table->text('therapeute_email')->nullable()->after('therapeute_telephone');
            $table->text('therapeute_adresse')->nullable()->after('therapeute_email');
        });
    }

    public function down(): void
    {
        Schema::table('participant_donnees_medicales', function (Blueprint $table): void {
            $table->dropColumn([
                'medecin_nom', 'medecin_prenom', 'medecin_telephone', 'medecin_email', 'medecin_adresse',
                'therapeute_nom', 'therapeute_prenom', 'therapeute_telephone', 'therapeute_email', 'therapeute_adresse',
            ]);
        });
    }
};
```

- [ ] **Step 2: Create migration for participants**

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
        Schema::table('participants', function (Blueprint $table): void {
            $table->string('nom_jeune_fille')->nullable()->after('refere_par_id');
            $table->string('nationalite')->nullable()->after('nom_jeune_fille');
            $table->string('adresse_par_nom')->nullable()->after('nationalite');
            $table->string('adresse_par_prenom')->nullable()->after('adresse_par_nom');
            $table->string('adresse_par_telephone')->nullable()->after('adresse_par_prenom');
            $table->string('adresse_par_email')->nullable()->after('adresse_par_telephone');
            $table->string('adresse_par_adresse')->nullable()->after('adresse_par_email');
            $table->string('droit_image')->nullable()->after('adresse_par_adresse');
            $table->string('mode_paiement_choisi')->nullable()->after('droit_image');
            $table->string('moyen_paiement_choisi')->nullable()->after('mode_paiement_choisi');
            $table->boolean('autorisation_contact_medecin')->default(false)->after('moyen_paiement_choisi');
            $table->dateTime('rgpd_accepte_at')->nullable()->after('autorisation_contact_medecin');
        });
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->dropColumn([
                'nom_jeune_fille', 'nationalite',
                'adresse_par_nom', 'adresse_par_prenom', 'adresse_par_telephone', 'adresse_par_email', 'adresse_par_adresse',
                'droit_image', 'mode_paiement_choisi', 'moyen_paiement_choisi',
                'autorisation_contact_medecin', 'rgpd_accepte_at',
            ]);
        });
    }
};
```

- [ ] **Step 3: Create migration for exercices**

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
        Schema::table('exercices', function (Blueprint $table): void {
            $table->string('helloasso_url')->nullable()->after('cloture_par_id');
        });
    }

    public function down(): void
    {
        Schema::table('exercices', function (Blueprint $table): void {
            $table->dropColumn('helloasso_url');
        });
    }
};
```

- [ ] **Step 4: Create migration for type_operations**

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
        Schema::table('type_operations', function (Blueprint $table): void {
            $table->string('attestation_medicale_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('type_operations', function (Blueprint $table): void {
            $table->dropColumn('attestation_medicale_path');
        });
    }
};
```

- [ ] **Step 5: Run migrations**

Run: `./vendor/bin/sail artisan migrate`
Expected: 4 migrations run successfully

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_03_29_*
git commit -m "feat(formulaire): add migrations for enriched form fields"
```

---

## Task 3 : Model updates

**Files:**
- Modify: `app/Models/Participant.php` — add new fields to `$fillable` and `casts()`
- Modify: `app/Models/ParticipantDonneesMedicales.php` — add medecin/therapeute fields
- Modify: `app/Models/Exercice.php` — add `helloasso_url`
- Modify: `app/Models/TypeOperation.php` — add `attestation_medicale_path`
- Test: `tests/Feature/ParticipantModelTest.php` — add encryption test for new medical fields

- [ ] **Step 1: Write test for new encrypted fields**

Add to `tests/Feature/ParticipantModelTest.php`:

```php
test('medecin and therapeute fields are encrypted in database', function () {
    $tiers = Tiers::create(['nom' => 'Test', 'prenom' => 'User', 'type' => 'particulier']);
    $typeOp = TypeOperation::create(['code' => 'TST', 'nom' => 'Test', 'sous_categorie_id' => SousCategorie::first()->id]);
    $operation = Operation::create(['nom' => 'Op test', 'type_operation_id' => $typeOp->id, 'statut' => 'active']);
    $participant = Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $operation->id, 'date_inscription' => now()]);

    ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'medecin_nom' => 'Dr Martin',
        'medecin_telephone' => '0612345678',
        'therapeute_nom' => 'Mme Dubois',
        'therapeute_email' => 'dubois@example.com',
    ]);

    $raw = DB::table('participant_donnees_medicales')
        ->where('participant_id', $participant->id)
        ->first();

    expect($raw->medecin_nom)->not->toBe('Dr Martin');
    expect($raw->therapeute_nom)->not->toBe('Mme Dubois');

    $record = ParticipantDonneesMedicales::where('participant_id', $participant->id)->first();
    expect($record->medecin_nom)->toBe('Dr Martin');
    expect($record->therapeute_nom)->toBe('Mme Dubois');
});

test('participant stores droit_image as enum', function () {
    $tiers = Tiers::create(['nom' => 'Test', 'prenom' => 'User', 'type' => 'particulier']);
    $typeOp = TypeOperation::create(['code' => 'TST2', 'nom' => 'Test', 'sous_categorie_id' => SousCategorie::first()->id]);
    $operation = Operation::create(['nom' => 'Op test', 'type_operation_id' => $typeOp->id, 'statut' => 'active']);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
        'droit_image' => DroitImage::UsagePropre,
    ]);

    $participant->refresh();
    expect($participant->droit_image)->toBe(DroitImage::UsagePropre);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/ParticipantModelTest.php`
Expected: FAIL — unknown column or property

- [ ] **Step 3: Update Participant model**

In `app/Models/Participant.php`, add to `$fillable`:
```php
'nom_jeune_fille',
'nationalite',
'adresse_par_nom',
'adresse_par_prenom',
'adresse_par_telephone',
'adresse_par_email',
'adresse_par_adresse',
'droit_image',
'mode_paiement_choisi',
'moyen_paiement_choisi',
'autorisation_contact_medecin',
'rgpd_accepte_at',
```

In `casts()`, add:
```php
'droit_image' => DroitImage::class,
'autorisation_contact_medecin' => 'boolean',
'rgpd_accepte_at' => 'datetime',
```

Add import: `use App\Enums\DroitImage;`

- [ ] **Step 4: Update ParticipantDonneesMedicales model**

In `app/Models/ParticipantDonneesMedicales.php`, add to `$fillable`:
```php
'medecin_nom',
'medecin_prenom',
'medecin_telephone',
'medecin_email',
'medecin_adresse',
'therapeute_nom',
'therapeute_prenom',
'therapeute_telephone',
'therapeute_email',
'therapeute_adresse',
```

In `casts()`, add the same 10 fields with `'encrypted'` cast (same pattern as existing fields).

- [ ] **Step 5: Update Exercice model**

In `app/Models/Exercice.php`, add `'helloasso_url'` to `$fillable`.

- [ ] **Step 6: Update TypeOperation model**

In `app/Models/TypeOperation.php`, add `'attestation_medicale_path'` to `$fillable`.

- [ ] **Step 7: Run tests to verify they pass**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/ParticipantModelTest.php`
Expected: PASS

- [ ] **Step 8: Run full test suite**

Run: `./vendor/bin/sail exec laravel.test php artisan test`
Expected: All existing tests still pass

- [ ] **Step 9: Commit**

```bash
git add app/Models/Participant.php app/Models/ParticipantDonneesMedicales.php app/Models/Exercice.php app/Models/TypeOperation.php tests/Feature/ParticipantModelTest.php
git commit -m "feat(formulaire): update models with enriched form fields"
```

---

## Task 4 : Layout et Alpine.js CDN

**Files:**
- Modify: `resources/views/formulaire/layout.blade.php` — add Alpine.js CDN + widen layout

- [ ] **Step 1: Add Alpine.js CDN script and x-cloak CSS**

In `resources/views/formulaire/layout.blade.php`, add in `<head>`:
```html
<style>[x-cloak] { display: none !important; }</style>
```

And before `</body>`:
```html
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script>
```

- [ ] **Step 2: Widen layout**

Change the column class from `col-md-7 col-lg-6` to `col-md-10 col-lg-8`.

- [ ] **Step 3: Verify in browser**

Visit `http://localhost/formulaire` — page should load without JS errors. Alpine.js should be available in the browser console (`Alpine.version` should return a version string).

- [ ] **Step 4: Commit**

```bash
git add resources/views/formulaire/layout.blade.php
git commit -m "feat(formulaire): add Alpine.js CDN and widen layout"
```

---

## Task 5 : FormulaireController::show() — enrichir les données passées à la vue

**Files:**
- Modify: `app/Http/Controllers/FormulaireController.php` — method `show()`
- Test: `tests/Feature/FormulaireControllerTest.php` — add test

- [ ] **Step 1: Write test for enriched show data**

Add to `tests/Feature/FormulaireControllerTest.php` in the `describe('show')` block:

```php
it('passes typeOperation and tarif data to the view', function () {
    $token = $this->service->generate($this->participant);

    $response = $this->get(route('formulaire.show', ['token' => $token->token]));

    $response->assertStatus(200);
    $response->assertViewHas('participant');
    $response->assertViewHas('operation');
    $response->assertViewHas('typeOperation');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/FormulaireControllerTest.php --filter="passes typeOperation"`
Expected: FAIL — view does not have `typeOperation`

- [ ] **Step 3: Update show() method**

In `FormulaireController::show()`, replace the view return with:

```php
$participant->load(['tiers', 'operation.typeOperation', 'operation.seances', 'typeOperationTarif', 'donneesMedicales']);

$operation = $participant->operation;
$typeOperation = $operation->typeOperation;

return view('formulaire.remplir', [
    'participant' => $participant,
    'tiers' => $participant->tiers,
    'operation' => $operation,
    'typeOperation' => $typeOperation,
    'tarif' => $participant->typeOperationTarif,
    'donneesMedicales' => $participant->donneesMedicales,
    'seancesCount' => $operation->nombre_seances,
    'token' => $request->input('token'),
]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/FormulaireControllerTest.php`
Expected: PASS (all tests in the file)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/FormulaireController.php tests/Feature/FormulaireControllerTest.php
git commit -m "feat(formulaire): enrich show() with typeOperation and tarif data"
```

---

## Task 6 : Vue wizard — structure principale et step 1 (Coordonnées)

**Files:**
- Modify: `resources/views/formulaire/remplir.blade.php` — refactor into wizard shell
- Create: `resources/views/formulaire/steps/step-1.blade.php`

- [ ] **Step 1: Create the wizard shell in remplir.blade.php**

Replace the current form content in `remplir.blade.php` with the wizard structure:

```blade
@extends('formulaire.layout')

@section('content')
<div x-data="formulaireWizard()" class="mb-4">
    {{-- Progress bar --}}
    <div class="progress mb-4">
        <div class="progress-bar" role="progressbar" :aria-valuenow="step" aria-valuemin="1" aria-valuemax="7"
             :style="'width: ' + Math.round((step / 7) * 100) + '%'">
            Étape <span x-text="step"></span> / 7
        </div>
    </div>

    {{-- Server-side validation errors --}}
    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('formulaire.store') }}" enctype="multipart/form-data" @submit.prevent="submitForm($event)">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        @include('formulaire.steps.step-1')
        @include('formulaire.steps.step-2')
        @include('formulaire.steps.step-3')
        @include('formulaire.steps.step-4')
        @include('formulaire.steps.step-5')
        @include('formulaire.steps.step-6')
        @include('formulaire.steps.step-7')

        {{-- Navigation buttons --}}
        <div class="d-flex justify-content-between mt-4">
            <button type="button" class="btn btn-outline-secondary" x-show="step > 1" x-cloak @click="prevStep()">
                <i class="bi bi-arrow-left"></i> Précédent
            </button>
            <div x-show="step === 1"></div>
            <button type="button" class="btn btn-primary" x-show="step < 7" @click="nextStep()">
                Suivant <i class="bi bi-arrow-right"></i>
            </button>
            <button type="submit" class="btn btn-success" x-show="step === 7" x-cloak>
                <i class="bi bi-check-lg"></i> Valider et envoyer
            </button>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script>
function formulaireWizard() {
    return {
        step: 1,
        errors: {},

        nextStep() {
            if (this.validateStep(this.step)) {
                this.step++;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },

        prevStep() {
            this.step--;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        validateStep(n) {
            this.errors = {};
            const section = document.querySelector(`[data-step="${n}"]`);
            if (!section) return true;

            let valid = true;

            // Check required text/select/date inputs
            const requiredInputs = section.querySelectorAll('input[data-required]:not([type="checkbox"]), select[data-required], textarea[data-required]');
            requiredInputs.forEach(field => {
                const name = field.getAttribute('name');
                if (!field.value || field.value.trim() === '') {
                    this.errors[name] = 'Ce champ est obligatoire';
                    valid = false;
                }
            });

            // Check required checkboxes
            const requiredCheckboxes = section.querySelectorAll('input[type="checkbox"][data-required]');
            requiredCheckboxes.forEach(field => {
                const name = field.getAttribute('name');
                if (!field.checked) {
                    this.errors[name] = 'Cet engagement est obligatoire';
                    valid = false;
                }
            });

            // Email format validation
            const emails = section.querySelectorAll('input[type="email"]');
            emails.forEach(field => {
                if (field.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) {
                    this.errors[field.name] = "Format d'email invalide";
                    valid = false;
                }
            });

            return valid;
        },

        hasError(name) {
            return this.errors[name] !== undefined;
        },

        submitForm(event) {
            if (this.validateStep(7)) {
                event.target.submit();
            }
        }
    };
}
</script>
@endsection
```

- [ ] **Step 2: Create step-1.blade.php (Coordonnées)**

```blade
<div x-show="step === 1" data-step="1" x-cloak>
    {{-- Greeting card --}}
    <div class="card mb-4 border-primary">
        <div class="card-body text-center">
            <h5>Bonjour {{ $tiers->prenom }} {{ $tiers->nom }}</h5>
            <p class="mb-1">{{ $operation->nom }}</p>
            @if($operation->date_debut)
                <small class="text-muted">
                    Du {{ $operation->date_debut->format('d/m/Y') }}
                    @if($operation->date_fin) au {{ $operation->date_fin->format('d/m/Y') }} @endif
                    @if($seancesCount) — {{ $seancesCount }} séance(s) @endif
                </small>
            @endif
        </div>
    </div>

    <h5 class="mb-3"><i class="bi bi-person"></i> Coordonnées</h5>

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Téléphone</label>
            <input type="tel" name="telephone" class="form-control" :class="hasError('telephone') && 'is-invalid'"
                   value="{{ old('telephone', $tiers->telephone) }}" maxlength="30">
            <div class="invalid-feedback" x-text="errors.telephone"></div>
        </div>
        <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" :class="hasError('email') && 'is-invalid'"
                   value="{{ old('email', $tiers->email) }}" maxlength="255">
            <div class="invalid-feedback" x-text="errors.email"></div>
        </div>
    </div>

    <div class="mt-3">
        <label class="form-label">Adresse</label>
        <input type="text" name="adresse_ligne1" class="form-control"
               value="{{ old('adresse_ligne1', $tiers->adresse_ligne1) }}" maxlength="500">
    </div>

    <div class="row g-3 mt-1">
        <div class="col-md-4">
            <label class="form-label">Code postal</label>
            <input type="text" name="code_postal" class="form-control"
                   value="{{ old('code_postal', $tiers->code_postal) }}" maxlength="10">
        </div>
        <div class="col-md-8">
            <label class="form-label">Ville</label>
            <input type="text" name="ville" class="form-control"
                   value="{{ old('ville', $tiers->ville) }}" maxlength="100">
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-md-6">
            <label class="form-label">Nom de jeune fille</label>
            <input type="text" name="nom_jeune_fille" class="form-control"
                   value="{{ old('nom_jeune_fille', $participant->nom_jeune_fille) }}" maxlength="255">
        </div>
        <div class="col-md-6">
            <label class="form-label">Nationalité</label>
            <input type="text" name="nationalite" class="form-control"
                   value="{{ old('nationalite', $participant->nationalite) }}" maxlength="100">
        </div>
    </div>

    {{-- Adressé par --}}
    <h6 class="mt-4 mb-3"><i class="bi bi-person-plus"></i> Je vous suis adressé(e) par</h6>

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Nom</label>
            <input type="text" name="adresse_par_nom" class="form-control"
                   value="{{ old('adresse_par_nom', $participant->adresse_par_nom) }}" maxlength="255">
        </div>
        <div class="col-md-6">
            <label class="form-label">Prénom</label>
            <input type="text" name="adresse_par_prenom" class="form-control"
                   value="{{ old('adresse_par_prenom', $participant->adresse_par_prenom) }}" maxlength="255">
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-md-4">
            <label class="form-label">Téléphone</label>
            <input type="tel" name="adresse_par_telephone" class="form-control"
                   value="{{ old('adresse_par_telephone', $participant->adresse_par_telephone) }}" maxlength="30">
        </div>
        <div class="col-md-8">
            <label class="form-label">Email</label>
            <input type="email" name="adresse_par_email" class="form-control"
                   value="{{ old('adresse_par_email', $participant->adresse_par_email) }}" maxlength="255">
        </div>
    </div>

    <div class="mt-3">
        <label class="form-label">Adresse</label>
        <input type="text" name="adresse_par_adresse" class="form-control"
               value="{{ old('adresse_par_adresse', $participant->adresse_par_adresse) }}" maxlength="500">
    </div>
</div>
```

- [ ] **Step 3: Create placeholder partials for steps 2-7**

Create `resources/views/formulaire/steps/step-2.blade.php` through `step-7.blade.php` with minimal placeholder content:

```blade
<div x-show="step === N" x-cloak data-step="N">
    <p>Étape N — À implémenter</p>
</div>
```

(Replace N with the step number for each file.)

- [ ] **Step 4: Verify in browser**

Visit `http://localhost/formulaire`, enter a valid token. The wizard should display step 1 with the coordonnées fields. "Suivant" should navigate to step 2 (placeholder). "Précédent" should come back to step 1. Progress bar should update.

- [ ] **Step 5: Commit**

```bash
git add resources/views/formulaire/remplir.blade.php resources/views/formulaire/steps/
git commit -m "feat(formulaire): wizard shell + step 1 (coordonnées)"
```

---

## Task 7 : Step 2 — Données de santé

**Files:**
- Create: `resources/views/formulaire/steps/step-2.blade.php` (replace placeholder)

- [ ] **Step 1: Implement step 2**

```blade
<div x-show="step === 2" x-cloak data-step="2">
    <div class="alert alert-warning d-flex align-items-center mb-3">
        <i class="bi bi-shield-lock me-2"></i>
        <span>Ces informations sont <strong>confidentielles et chiffrées</strong>.</span>
    </div>

    <h5 class="mb-3"><i class="bi bi-heart-pulse"></i> Données de santé</h5>

    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Date de naissance</label>
            <input type="date" name="date_naissance" class="form-control"
                   value="{{ old('date_naissance', $donneesMedicales?->date_naissance) }}">
        </div>
        <div class="col-md-3">
            <label class="form-label">Sexe</label>
            <select name="sexe" class="form-select">
                <option value="">—</option>
                <option value="M" @selected(old('sexe', $donneesMedicales?->sexe) === 'M')>Masculin</option>
                <option value="F" @selected(old('sexe', $donneesMedicales?->sexe) === 'F')>Féminin</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Taille (cm)</label>
            <input type="number" name="taille" class="form-control" min="50" max="250"
                   value="{{ old('taille', $donneesMedicales?->taille) }}">
        </div>
        <div class="col-md-3">
            <label class="form-label">Poids (kg)</label>
            <input type="number" name="poids" class="form-control" min="20" max="300"
                   value="{{ old('poids', $donneesMedicales?->poids) }}">
        </div>
    </div>

    {{-- Médecin traitant --}}
    <h6 class="mt-4 mb-3"><i class="bi bi-hospital"></i> Médecin traitant</h6>

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Nom</label>
            <input type="text" name="medecin_nom" class="form-control"
                   value="{{ old('medecin_nom', $donneesMedicales?->medecin_nom) }}" maxlength="255">
        </div>
        <div class="col-md-6">
            <label class="form-label">Prénom</label>
            <input type="text" name="medecin_prenom" class="form-control"
                   value="{{ old('medecin_prenom', $donneesMedicales?->medecin_prenom) }}" maxlength="255">
        </div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-md-4">
            <label class="form-label">Téléphone</label>
            <input type="tel" name="medecin_telephone" class="form-control"
                   value="{{ old('medecin_telephone', $donneesMedicales?->medecin_telephone) }}" maxlength="30">
        </div>
        <div class="col-md-8">
            <label class="form-label">Email</label>
            <input type="email" name="medecin_email" class="form-control"
                   value="{{ old('medecin_email', $donneesMedicales?->medecin_email) }}" maxlength="255">
        </div>
    </div>
    <div class="mt-3">
        <label class="form-label">Adresse</label>
        <input type="text" name="medecin_adresse" class="form-control"
               value="{{ old('medecin_adresse', $donneesMedicales?->medecin_adresse) }}" maxlength="500">
    </div>

    {{-- Thérapeute référent --}}
    <h6 class="mt-4 mb-3"><i class="bi bi-person-badge"></i> Thérapeute référent</h6>

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Nom</label>
            <input type="text" name="therapeute_nom" class="form-control"
                   value="{{ old('therapeute_nom', $donneesMedicales?->therapeute_nom) }}" maxlength="255">
        </div>
        <div class="col-md-6">
            <label class="form-label">Prénom</label>
            <input type="text" name="therapeute_prenom" class="form-control"
                   value="{{ old('therapeute_prenom', $donneesMedicales?->therapeute_prenom) }}" maxlength="255">
        </div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-md-4">
            <label class="form-label">Téléphone</label>
            <input type="tel" name="therapeute_telephone" class="form-control"
                   value="{{ old('therapeute_telephone', $donneesMedicales?->therapeute_telephone) }}" maxlength="30">
        </div>
        <div class="col-md-8">
            <label class="form-label">Email</label>
            <input type="email" name="therapeute_email" class="form-control"
                   value="{{ old('therapeute_email', $donneesMedicales?->therapeute_email) }}" maxlength="255">
        </div>
    </div>
    <div class="mt-3">
        <label class="form-label">Adresse</label>
        <input type="text" name="therapeute_adresse" class="form-control"
               value="{{ old('therapeute_adresse', $donneesMedicales?->therapeute_adresse) }}" maxlength="500">
    </div>

    {{-- Notes médicales --}}
    <div class="mt-4">
        <label class="form-label">Notes médicales</label>
        <textarea name="notes" class="form-control" rows="3" maxlength="1000"
                  placeholder="Allergies, traitements en cours, particularités...">{{ old('notes', $donneesMedicales?->notes) }}</textarea>
    </div>
</div>
```

- [ ] **Step 2: Verify in browser**

Navigate to step 2 in the wizard. All medical fields should display. If `donneesMedicales` exists, fields should be pre-filled.

- [ ] **Step 3: Commit**

```bash
git add resources/views/formulaire/steps/step-2.blade.php
git commit -m "feat(formulaire): step 2 — données de santé"
```

---

## Task 8 : Step 3 — Documents

**Files:**
- Create: `resources/views/formulaire/steps/step-3.blade.php` (replace placeholder)

- [ ] **Step 1: Implement step 3**

```blade
<div x-show="step === 3" x-cloak data-step="3">
    <h5 class="mb-3"><i class="bi bi-file-earmark-arrow-up"></i> Documents</h5>

    @if($typeOperation->attestation_medicale_path)
        <div class="alert alert-info d-flex align-items-center mb-3">
            <i class="bi bi-download me-2"></i>
            <span>
                Téléchargez l'attestation médicale à faire remplir par votre médecin :
                <a href="{{ asset('storage/' . $typeOperation->attestation_medicale_path) }}" target="_blank" class="alert-link">
                    Télécharger le document <i class="bi bi-box-arrow-up-right"></i>
                </a>
            </span>
        </div>
    @endif

    <p class="text-muted mb-3">
        Vous pouvez joindre jusqu'à 3 documents (certificat médical, attestation, etc.).
        <br>Formats acceptés : PDF, JPG, PNG — 5 Mo maximum par fichier.
    </p>

    <div class="mb-3">
        <label class="form-label">Document 1</label>
        <input type="file" name="documents[]" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
    </div>
    <div class="mb-3">
        <label class="form-label">Document 2</label>
        <input type="file" name="documents[]" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
    </div>
    <div class="mb-3">
        <label class="form-label">Document 3</label>
        <input type="file" name="documents[]" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
    </div>
</div>
```

- [ ] **Step 2: Verify in browser**

Navigate to step 3. File inputs should display. If `attestation_medicale_path` is set on the TypeOperation, the download link should appear.

- [ ] **Step 3: Commit**

```bash
git add resources/views/formulaire/steps/step-3.blade.php
git commit -m "feat(formulaire): step 3 — documents"
```

---

## Task 9 : Step 4 — Informations pratiques

**Files:**
- Create: `resources/views/formulaire/steps/step-4.blade.php` (replace placeholder)

- [ ] **Step 1: Implement step 4**

```blade
<div x-show="step === 4" x-cloak data-step="4">
    <h5 class="mb-3"><i class="bi bi-info-circle"></i> Informations pratiques</h5>

    <div class="card mb-3">
        <div class="card-body">
            <h6 class="card-title">Coupons Sport</h6>
            <p class="card-text">Certaines collectivités proposent des coupons sport pour aider au financement des activités sportives. Renseignez-vous auprès de votre mairie ou de votre conseil départemental.</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h6 class="card-title">Sport sur ordonnance (Sport-Santé)</h6>
            <p class="card-text">Votre médecin traitant peut vous prescrire une activité physique adaptée dans le cadre du dispositif Sport-Santé. Ce dispositif peut ouvrir droit à des aides financières selon votre situation.</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h6 class="card-title">Prises en charge complémentaires</h6>
            <p class="card-text">Certaines mutuelles et comités d'entreprise prennent en charge tout ou partie des séances de kinésithérapie, ostéopathie, sophrologie ou thérapie. N'hésitez pas à vous renseigner auprès de votre mutuelle ou de votre CE.</p>
        </div>
    </div>
</div>
```

Note : les textes ci-dessus sont des exemples. Le contenu exact sera affiné à partir de la fiche papier lors de l'implémentation.

- [ ] **Step 2: Verify in browser**

Navigate to step 4. Informational cards should display. No form fields — just "Suivant"/"Précédent".

- [ ] **Step 3: Commit**

```bash
git add resources/views/formulaire/steps/step-4.blade.php
git commit -m "feat(formulaire): step 4 — informations pratiques"
```

---

## Task 10 : Step 5 — Engagement financier

**Files:**
- Create: `resources/views/formulaire/steps/step-5.blade.php` (replace placeholder)

- [ ] **Step 1: Implement step 5**

```blade
<div x-show="step === 5" x-cloak data-step="5">
    <h5 class="mb-3"><i class="bi bi-currency-euro"></i> Engagement financier</h5>

    @if($tarif && $seancesCount)
        @php
            $montantTotal = $seancesCount * $tarif->montant;
        @endphp
        <div class="card mb-3">
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td>Tarif</td>
                        <td class="text-end fw-bold">{{ number_format($tarif->montant, 2, ',', ' ') }} € / séance</td>
                    </tr>
                    <tr>
                        <td>Nombre de séances</td>
                        <td class="text-end fw-bold">{{ $seancesCount }}</td>
                    </tr>
                    <tr class="table-primary">
                        <td><strong>Montant total</strong></td>
                        <td class="text-end fw-bold">{{ number_format($montantTotal, 2, ',', ' ') }} €</td>
                    </tr>
                </table>
            </div>
        </div>
    @else
        <div class="alert alert-secondary mb-3">
            <i class="bi bi-info-circle me-1"></i> Tarif à confirmer avec l'association.
        </div>
    @endif

    <div class="mb-4">
        <label class="form-label fw-bold">Mode de paiement</label>
        <div class="form-check">
            <input type="radio" name="mode_paiement_choisi" value="comptant" class="form-check-input"
                   id="paiement_comptant" @checked(old('mode_paiement_choisi') === 'comptant')>
            <label class="form-check-label" for="paiement_comptant">Comptant (en une fois)</label>
        </div>
        <div class="form-check">
            <input type="radio" name="mode_paiement_choisi" value="par_seance" class="form-check-input"
                   id="paiement_seance" @checked(old('mode_paiement_choisi') === 'par_seance')>
            <label class="form-check-label" for="paiement_seance">Par séance</label>
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label fw-bold">Moyen de règlement</label>
        <div class="form-check">
            <input type="radio" name="moyen_paiement_choisi" value="especes" class="form-check-input"
                   id="moyen_especes" @checked(old('moyen_paiement_choisi') === 'especes')>
            <label class="form-check-label" for="moyen_especes">Espèces</label>
        </div>
        <div class="form-check">
            <input type="radio" name="moyen_paiement_choisi" value="cheque" class="form-check-input"
                   id="moyen_cheque" @checked(old('moyen_paiement_choisi') === 'cheque')>
            <label class="form-check-label" for="moyen_cheque">Chèque</label>
        </div>
        <div class="form-check">
            <input type="radio" name="moyen_paiement_choisi" value="virement" class="form-check-input"
                   id="moyen_virement" @checked(old('moyen_paiement_choisi') === 'virement')>
            <label class="form-check-label" for="moyen_virement">Virement</label>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Verify in browser**

Navigate to step 5. If tarif + seances are set, the calculated table should display. Radio buttons for payment options should be functional.

- [ ] **Step 3: Commit**

```bash
git add resources/views/formulaire/steps/step-5.blade.php
git commit -m "feat(formulaire): step 5 — engagement financier"
```

---

## Task 11 : Step 6 — Droit à l'image

**Files:**
- Create: `resources/views/formulaire/steps/step-6.blade.php` (replace placeholder)

- [ ] **Step 1: Implement step 6**

```blade
<div x-show="step === 6" x-cloak data-step="6">
    <h5 class="mb-3"><i class="bi bi-camera"></i> Autorisation de prise de vues</h5>

    <div class="card mb-4">
        <div class="card-body" style="font-size: 0.95rem;">
            <p>Nous avons l'habitude dans les ateliers thérapeutiques de proposer la prise de photos à différents temps de l'atelier. Ces photos peuvent être individuelles ou de groupe.</p>
            <p>Ces photos sont réalisées à titre de souvenir mais aussi pour vous permettre d'évaluer avec le temps tout votre cheminement thérapeutique.</p>
            <p>Nous vous proposerons, au fil des séances, de vous photographier individuellement ou en groupe avec votre cheval ou poney.</p>
            <p>Les photos vous seront remises individuellement en téléchargement informatique sécurisé à la fin de chaque séance et vous devrez <strong>vous engager au préalable à ne les utiliser que pour votre usage personnel</strong>, éventuellement à l'usage du groupe ou avec l'accord écrit des personnes photographiées en cas de diffusion.</p>
            <p>Le groupe peut également être amené à donner son accord à la diffusion de certaines photos dans le cadre de la formation des équipes encadrantes des ateliers thérapeutiques et ce, à visée didactique.</p>
            <p class="mb-0">Vous pouvez à tout moment modifier votre décision en en faisant part au responsable de l'équipe encadrante de votre atelier.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header fw-bold">
            <em>J'inscris ci-dessous l'accord que je donne parmi les propositions qui suivent</em>
        </div>
        <div class="card-body">
            <div class="form-check mb-3">
                <input type="radio" name="droit_image" value="usage_propre" class="form-check-input"
                       id="droit_usage_propre" @checked(old('droit_image') === 'usage_propre')>
                <label class="form-check-label" for="droit_usage_propre">
                    Je donne mon accord pour la prise de photos/vidéos me concernant <strong>pour mon usage propre</strong>
                </label>
            </div>
            <div class="form-check mb-3">
                <input type="radio" name="droit_image" value="usage_confidentiel" class="form-check-input"
                       id="droit_confidentiel" @checked(old('droit_image') === 'usage_confidentiel')>
                <label class="form-check-label" for="droit_confidentiel">
                    Je donne mon accord pour la prise de photos/vidéos me concernant <strong>et pour un usage confidentiel au sein de l'équipe thérapeutique</strong>
                </label>
            </div>
            <div class="form-check mb-3">
                <input type="radio" name="droit_image" value="diffusion" class="form-check-input"
                       id="droit_diffusion" @checked(old('droit_image') === 'diffusion')>
                <label class="form-check-label" for="droit_diffusion">
                    Je donne mon accord pour la prise de photos/vidéos me concernant <strong>et pour une diffusion</strong>
                </label>
            </div>
            <div class="form-check">
                <input type="radio" name="droit_image" value="refus" class="form-check-input"
                       id="droit_refus" @checked(old('droit_image') === 'refus')>
                <label class="form-check-label" for="droit_refus">
                    <strong>Je ne donne pas mon accord</strong> pour la prise de photos/vidéos
                </label>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Verify in browser**

Navigate to step 6. Full explanatory text and 4 radio options should display.

- [ ] **Step 3: Commit**

```bash
git add resources/views/formulaire/steps/step-6.blade.php
git commit -m "feat(formulaire): step 6 — droit à l'image"
```

---

## Task 12 : Step 7 — Engagements & confirmation

**Files:**
- Create: `resources/views/formulaire/steps/step-7.blade.php` (replace placeholder)

- [ ] **Step 1: Implement step 7**

```blade
<div x-show="step === 7" x-cloak data-step="7">
    <h5 class="mb-3"><i class="bi bi-check2-square"></i> Engagements</h5>

    {{-- Mandatory checkboxes --}}
    <div class="card mb-4">
        <div class="card-header fw-bold">Engagements obligatoires</div>
        <div class="card-body">
            <div class="form-check mb-3">
                <input type="checkbox" name="engagement_presence" value="1" class="form-check-input"
                       id="engagement_presence" data-required :class="hasError('engagement_presence') && 'is-invalid'">
                <label class="form-check-label" for="engagement_presence">
                    Je m'engage à être présent(e) à toutes les séances prévues.
                </label>
                <div class="invalid-feedback" x-text="errors.engagement_presence"></div>
            </div>

            <div class="form-check mb-3">
                <input type="checkbox" name="engagement_certificat" value="1" class="form-check-input"
                       id="engagement_certificat" data-required :class="hasError('engagement_certificat') && 'is-invalid'">
                <label class="form-check-label" for="engagement_certificat">
                    Je fournirai un certificat médical de non contre-indication à la pratique sportive.
                </label>
                <div class="invalid-feedback" x-text="errors.engagement_certificat"></div>
            </div>

            <div class="form-check mb-3">
                <input type="checkbox" name="engagement_reglement" value="1" class="form-check-input"
                       id="engagement_reglement" data-required :class="hasError('engagement_reglement') && 'is-invalid'">
                <label class="form-check-label" for="engagement_reglement">
                    J'accepte les conditions de règlement. Les séances sont dues dans tous les cas, même en cas d'absence.
                </label>
                <div class="invalid-feedback" x-text="errors.engagement_reglement"></div>
            </div>

            <div class="form-check mb-3">
                <input type="checkbox" name="engagement_rgpd" value="1" class="form-check-input"
                       id="engagement_rgpd" data-required :class="hasError('engagement_rgpd') && 'is-invalid'">
                <label class="form-check-label" for="engagement_rgpd">
                    J'accepte le traitement électronique de mes données personnelles. Je dispose d'un droit d'accès, de modification et de suppression de mes données (droit à l'oubli) à l'issue de l'opération.
                </label>
                <div class="invalid-feedback" x-text="errors.engagement_rgpd"></div>
            </div>
        </div>
    </div>

    {{-- Optional checkbox --}}
    <div class="card mb-4">
        <div class="card-header fw-bold">Autorisation optionnelle</div>
        <div class="card-body">
            <div class="form-check">
                <input type="checkbox" name="autorisation_contact_medecin" value="1" class="form-check-input"
                       id="autorisation_contact">
                <label class="form-check-label" for="autorisation_contact">
                    J'autorise l'association à prendre contact avec mon médecin traitant et/ou mon thérapeute référent si nécessaire.
                </label>
            </div>
        </div>
    </div>

    {{-- Token re-entry --}}
    <div class="card border-primary">
        <div class="card-header fw-bold text-primary">
            <i class="bi bi-pen"></i> Confirmation
        </div>
        <div class="card-body">
            <p>Pour confirmer votre engagement, veuillez re-saisir le code qui vous a été communiqué :</p>
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <input type="text" name="token_confirmation" class="form-control form-control-lg text-center font-monospace"
                           placeholder="XXXX-XXXX" maxlength="9" autocomplete="off" autocapitalize="characters"
                           data-required :class="hasError('token_confirmation') && 'is-invalid'">
                    <div class="invalid-feedback" x-text="errors.token_confirmation"></div>
                </div>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Verify in browser**

Navigate to step 7. Try to submit without checking mandatory boxes — should show errors. Try to submit without re-entering token — should show error. Check all boxes, enter token, submit should proceed.

- [ ] **Step 3: Commit**

```bash
git add resources/views/formulaire/steps/step-7.blade.php
git commit -m "feat(formulaire): step 7 — engagements et confirmation"
```

---

## Task 13 : Route et page de remerciement

> **Note :** Cette tâche est déplacée avant l'enrichissement de `store()` car les tests de la tâche suivante ont besoin de la route `formulaire.merci`.

**Files:**
- Modify: `routes/web.php` — add `formulaire.merci` route
- Modify: `app/Http/Controllers/FormulaireController.php` — add `merci()` method
- Create: `resources/views/formulaire/merci.blade.php`

- [ ] **Step 1: Add route**

In `routes/web.php`, inside the `formulaire` group, add:
```php
Route::get('/merci', [FormulaireController::class, 'merci'])->name('formulaire.merci');
```

- [ ] **Step 2: Add merci() method to controller**

In `FormulaireController`, add:

```php
public function merci(Request $request): View
{
    $helloassoUrl = session('helloasso_url');

    return view('formulaire.merci', [
        'helloassoUrl' => $helloassoUrl,
    ]);
}
```

- [ ] **Step 3: Create merci.blade.php**

```blade
@extends('formulaire.layout')

@section('content')
<div class="text-center py-5">
    <div class="mb-4">
        <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
    </div>
    <h3>Merci !</h3>
    <p class="lead text-muted">Vos informations ont bien été enregistrées.</p>
    <p>Vous pouvez fermer cette page.</p>

    @if($helloassoUrl)
        <hr class="my-4">
        <div class="card border-info mx-auto" style="max-width: 500px;">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-heart"></i> Adhésion</h5>
                <p class="card-text">Si vous n'êtes pas encore adhérent(e), vous pouvez compléter votre adhésion en ligne :</p>
                <a href="{{ $helloassoUrl }}" target="_blank" class="btn btn-primary">
                    Adhérer via HelloAsso <i class="bi bi-box-arrow-up-right"></i>
                </a>
            </div>
        </div>
    @endif
</div>
@endsection
```

- [ ] **Step 4: Verify in browser**

Visit `http://localhost/formulaire/merci` — should display the thank-you page without HelloAsso link.

- [ ] **Step 5: Commit**

```bash
git add routes/web.php app/Http/Controllers/FormulaireController.php resources/views/formulaire/merci.blade.php
git commit -m "feat(formulaire): thank-you page with conditional HelloAsso link"
```

---

## Task 14 : FormulaireController::store() — enrichir la soumission

**Files:**
- Modify: `app/Http/Controllers/FormulaireController.php` — method `store()`
- Modify: `tests/Feature/FormulaireControllerTest.php` — update old tests + add new tests

- [ ] **Step 1: Update existing store tests**

Les tests existants pour `store()` dans `FormulaireControllerTest.php` vont casser car :
- La validation exige maintenant `engagement_*` et `token_confirmation`
- La redirection passe de `formulaire.index` à `formulaire.merci`

Mettre à jour tous les tests `store` existants pour inclure les champs obligatoires (`engagement_presence`, `engagement_certificat`, `engagement_reglement`, `engagement_rgpd`, `token_confirmation`) et vérifier la nouvelle redirection vers `formulaire.merci`.

- [ ] **Step 2: Write new tests for enriched store**

Add to `tests/Feature/FormulaireControllerTest.php` (ajouter les `use` nécessaires : `DroitImage`, `Seance`, `TypeOperationTarif`, `ModePaiement`, `Reglement`) :

```php
it('stores enriched form data including medical contacts and engagements', function () {
    Storage::fake('local');
    $token = $this->service->generate($this->participant);

    $response = $this->post(route('formulaire.store'), [
        'token' => $token->token,
        'telephone' => '0612345678',
        'email' => 'test@example.com',
        'nom_jeune_fille' => 'Martin',
        'nationalite' => 'Française',
        'adresse_par_nom' => 'Dupont',
        'adresse_par_prenom' => 'Jean',
        'date_naissance' => '1990-01-15',
        'sexe' => 'F',
        'taille' => '165',
        'poids' => '60',
        'medecin_nom' => 'Dr Legrand',
        'medecin_telephone' => '0145678901',
        'therapeute_nom' => 'Mme Moreau',
        'droit_image' => 'usage_propre',
        'mode_paiement_choisi' => 'par_seance',
        'moyen_paiement_choisi' => 'cheque',
        'autorisation_contact_medecin' => '1',
        'engagement_presence' => '1',
        'engagement_certificat' => '1',
        'engagement_reglement' => '1',
        'engagement_rgpd' => '1',
        'token_confirmation' => $token->token,
    ]);

    $response->assertRedirectToRoute('formulaire.merci');

    $this->participant->refresh();
    expect($this->participant->nom_jeune_fille)->toBe('Martin');
    expect($this->participant->nationalite)->toBe('Française');
    expect($this->participant->adresse_par_nom)->toBe('Dupont');
    expect($this->participant->droit_image)->toBe(DroitImage::UsagePropre);
    expect($this->participant->mode_paiement_choisi)->toBe('par_seance');
    expect($this->participant->moyen_paiement_choisi)->toBe('cheque');
    expect($this->participant->autorisation_contact_medecin)->toBeTrue();
    expect($this->participant->rgpd_accepte_at)->not->toBeNull();

    $medical = $this->participant->donneesMedicales;
    expect($medical->medecin_nom)->toBe('Dr Legrand');
    expect($medical->therapeute_nom)->toBe('Mme Moreau');
});

it('rejects submission when token_confirmation does not match', function () {
    $token = $this->service->generate($this->participant);

    $response = $this->post(route('formulaire.store'), [
        'token' => $token->token,
        'token_confirmation' => 'WRONG-CODE',
        'engagement_presence' => '1',
        'engagement_certificat' => '1',
        'engagement_reglement' => '1',
        'engagement_rgpd' => '1',
    ]);

    $response->assertSessionHasErrors('token_confirmation');
});

it('creates reglement lines per seance when mode is par_seance', function () {
    $token = $this->service->generate($this->participant);

    $seance1 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => now()]);
    $seance2 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 2, 'date' => now()->addWeek()]);

    $tarif = TypeOperationTarif::create([
        'type_operation_id' => $this->operation->typeOperation->id,
        'libelle' => 'Tarif test',
        'montant' => 50.00,
    ]);
    $this->participant->update(['type_operation_tarif_id' => $tarif->id]);

    $this->post(route('formulaire.store'), [
        'token' => $token->token,
        'mode_paiement_choisi' => 'par_seance',
        'moyen_paiement_choisi' => 'cheque',
        'engagement_presence' => '1',
        'engagement_certificat' => '1',
        'engagement_reglement' => '1',
        'engagement_rgpd' => '1',
        'token_confirmation' => $token->token,
    ]);

    expect(Reglement::where('participant_id', $this->participant->id)->count())->toBe(2);

    $reglement = Reglement::where('participant_id', $this->participant->id)
        ->where('seance_id', $seance1->id)->first();
    expect($reglement->montant_prevu)->toBe('50.00');
    expect($reglement->mode_paiement)->toBe(ModePaiement::Cheque);
});

it('creates reglement lines per seance when mode is comptant', function () {
    $token = $this->service->generate($this->participant);

    $seance1 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => now()]);
    $seance2 = Seance::create(['operation_id' => $this->operation->id, 'numero' => 2, 'date' => now()->addWeek()]);

    $tarif = TypeOperationTarif::create([
        'type_operation_id' => $this->operation->typeOperation->id,
        'libelle' => 'Tarif test',
        'montant' => 50.00,
    ]);
    $this->participant->update(['type_operation_tarif_id' => $tarif->id]);

    $this->post(route('formulaire.store'), [
        'token' => $token->token,
        'mode_paiement_choisi' => 'comptant',
        'moyen_paiement_choisi' => 'virement',
        'engagement_presence' => '1',
        'engagement_certificat' => '1',
        'engagement_reglement' => '1',
        'engagement_rgpd' => '1',
        'token_confirmation' => $token->token,
    ]);

    // Comptant : même montant par séance (total/nb_seances = tarif car total = nb_seances * tarif)
    expect(Reglement::where('participant_id', $this->participant->id)->count())->toBe(2);

    $reglement = Reglement::where('participant_id', $this->participant->id)
        ->where('seance_id', $seance1->id)->first();
    expect($reglement->montant_prevu)->toBe('50.00');
    expect($reglement->mode_paiement)->toBe(ModePaiement::Virement);
});

it('does not overwrite existing reglements', function () {
    $token = $this->service->generate($this->participant);

    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => now()]);

    $tarif = TypeOperationTarif::create([
        'type_operation_id' => $this->operation->typeOperation->id,
        'libelle' => 'Tarif test',
        'montant' => 50.00,
    ]);
    $this->participant->update(['type_operation_tarif_id' => $tarif->id]);

    // Pre-existing reglement
    Reglement::create([
        'participant_id' => $this->participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Virement,
        'montant_prevu' => 75.00,
    ]);

    $this->post(route('formulaire.store'), [
        'token' => $token->token,
        'mode_paiement_choisi' => 'par_seance',
        'moyen_paiement_choisi' => 'especes',
        'engagement_presence' => '1',
        'engagement_certificat' => '1',
        'engagement_reglement' => '1',
        'engagement_rgpd' => '1',
        'token_confirmation' => $token->token,
    ]);

    $reglement = Reglement::where('participant_id', $this->participant->id)
        ->where('seance_id', $seance->id)->first();
    // Not overwritten
    expect($reglement->montant_prevu)->toBe('75.00');
    expect($reglement->mode_paiement)->toBe(ModePaiement::Virement);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/FormulaireControllerTest.php`
Expected: FAIL

- [ ] **Step 3: Update store() method**

Rewrite `FormulaireController::store()`:

```php
public function store(Request $request): RedirectResponse
{
    $service = app(FormulaireTokenService::class);
    $result = $service->validate($request->input('token', ''));

    if ($result['status'] !== 'valid') {
        return redirect()->route('formulaire.index')
            ->withErrors(['token' => 'Code invalide ou expiré.']);
    }

    $participant = $result['participant'];

    $request->validate([
        // Coordonnées
        'telephone' => ['nullable', 'string', 'max:30'],
        'email' => ['nullable', 'email', 'max:255'],
        'adresse_ligne1' => ['nullable', 'string', 'max:500'],
        'code_postal' => ['nullable', 'string', 'max:10'],
        'ville' => ['nullable', 'string', 'max:100'],
        'nom_jeune_fille' => ['nullable', 'string', 'max:255'],
        'nationalite' => ['nullable', 'string', 'max:100'],
        // Adressé par
        'adresse_par_nom' => ['nullable', 'string', 'max:255'],
        'adresse_par_prenom' => ['nullable', 'string', 'max:255'],
        'adresse_par_telephone' => ['nullable', 'string', 'max:30'],
        'adresse_par_email' => ['nullable', 'email', 'max:255'],
        'adresse_par_adresse' => ['nullable', 'string', 'max:500'],
        // Santé
        'date_naissance' => ['nullable', 'date', 'before:today'],
        'sexe' => ['nullable', 'in:M,F'],
        'taille' => ['nullable', 'numeric', 'between:50,250'],
        'poids' => ['nullable', 'numeric', 'between:20,300'],
        'notes' => ['nullable', 'string', 'max:1000'],
        'medecin_nom' => ['nullable', 'string', 'max:255'],
        'medecin_prenom' => ['nullable', 'string', 'max:255'],
        'medecin_telephone' => ['nullable', 'string', 'max:30'],
        'medecin_email' => ['nullable', 'email', 'max:255'],
        'medecin_adresse' => ['nullable', 'string', 'max:500'],
        'therapeute_nom' => ['nullable', 'string', 'max:255'],
        'therapeute_prenom' => ['nullable', 'string', 'max:255'],
        'therapeute_telephone' => ['nullable', 'string', 'max:30'],
        'therapeute_email' => ['nullable', 'email', 'max:255'],
        'therapeute_adresse' => ['nullable', 'string', 'max:500'],
        // Documents
        'documents.*' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        'documents' => ['nullable', 'array', 'max:3'],
        // Engagement financier
        'mode_paiement_choisi' => ['nullable', 'in:comptant,par_seance'],
        'moyen_paiement_choisi' => ['nullable', 'in:especes,cheque,virement'],
        // Droit à l'image
        'droit_image' => ['nullable', 'in:usage_propre,usage_confidentiel,diffusion,refus'],
        // Engagements
        'engagement_presence' => ['required', 'accepted'],
        'engagement_certificat' => ['required', 'accepted'],
        'engagement_reglement' => ['required', 'accepted'],
        'engagement_rgpd' => ['required', 'accepted'],
        'autorisation_contact_medecin' => ['nullable'],
        // Confirmation token
        'token_confirmation' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
            $normalized = strtoupper(str_replace(' ', '', $value));
            $expected = strtoupper(str_replace(' ', '', $request->input('token', '')));
            if ($normalized !== $expected) {
                $fail('Le code de confirmation ne correspond pas.');
            }
        }],
    ]);

    DB::transaction(function () use ($request, $participant): void {
        // 1. Merge Tiers
        $tiers = $participant->tiers;
        $coordFields = ['telephone', 'email', 'adresse_ligne1', 'code_postal', 'ville'];
        foreach ($coordFields as $field) {
            $newValue = $request->input($field);
            if ($newValue !== null && $newValue !== '' && $newValue !== ($tiers->{$field} ?? '')) {
                $tiers->{$field} = $newValue;
            }
        }
        $tiers->save();

        // 2. Update Participant
        $participant->update([
            'nom_jeune_fille' => $request->input('nom_jeune_fille') ?: null,
            'nationalite' => $request->input('nationalite') ?: null,
            'adresse_par_nom' => $request->input('adresse_par_nom') ?: null,
            'adresse_par_prenom' => $request->input('adresse_par_prenom') ?: null,
            'adresse_par_telephone' => $request->input('adresse_par_telephone') ?: null,
            'adresse_par_email' => $request->input('adresse_par_email') ?: null,
            'adresse_par_adresse' => $request->input('adresse_par_adresse') ?: null,
            'droit_image' => $request->input('droit_image') ?: null,
            'mode_paiement_choisi' => $request->input('mode_paiement_choisi') ?: null,
            'moyen_paiement_choisi' => $request->input('moyen_paiement_choisi') ?: null,
            'autorisation_contact_medecin' => $request->boolean('autorisation_contact_medecin'),
            'rgpd_accepte_at' => now(),
        ]);

        // 3. Upsert medical data
        ParticipantDonneesMedicales::updateOrCreate(
            ['participant_id' => $participant->id],
            [
                'date_naissance' => $request->input('date_naissance') ?: null,
                'sexe' => $request->input('sexe') ?: null,
                'taille' => $request->input('taille') ?: null,
                'poids' => $request->input('poids') ?: null,
                'notes' => $request->input('notes') ?: null,
                'medecin_nom' => $request->input('medecin_nom') ?: null,
                'medecin_prenom' => $request->input('medecin_prenom') ?: null,
                'medecin_telephone' => $request->input('medecin_telephone') ?: null,
                'medecin_email' => $request->input('medecin_email') ?: null,
                'medecin_adresse' => $request->input('medecin_adresse') ?: null,
                'therapeute_nom' => $request->input('therapeute_nom') ?: null,
                'therapeute_prenom' => $request->input('therapeute_prenom') ?: null,
                'therapeute_telephone' => $request->input('therapeute_telephone') ?: null,
                'therapeute_email' => $request->input('therapeute_email') ?: null,
                'therapeute_adresse' => $request->input('therapeute_adresse') ?: null,
            ]
        );

        // 4. Store documents
        if ($request->hasFile('documents')) {
            $dir = "participants/{$participant->id}";
            foreach ($request->file('documents') as $file) {
                if ($file->isValid()) {
                    $file->store($dir, 'local');
                }
            }
        }

        // 5. Create reglements if none exist
        $this->createReglementsIfNeeded($participant, $request);

        // 6. Mark token
        $participant->formulaireToken->update([
            'rempli_at' => now(),
            'rempli_ip' => $request->ip(),
        ]);
    });

    // Resolve HelloAsso URL via flash session (not query param — avoids URL guessing)
    $helloassoUrl = null;
    $typeOperation = $participant->operation->typeOperation;
    if ($typeOperation?->reserve_adherents) {
        $now = now();
        $annee = $now->month >= 9 ? $now->year : $now->year - 1;
        $exercice = \App\Models\Exercice::where('annee', $annee)->first();
        $helloassoUrl = $exercice?->helloasso_url;
    }

    return redirect()->route('formulaire.merci')
        ->with('helloasso_url', $helloassoUrl);
}

private function createReglementsIfNeeded(Participant $participant, Request $request): void
{
    if (Reglement::where('participant_id', $participant->id)->exists()) {
        return;
    }

    $tarif = $participant->typeOperationTarif;
    if ($tarif === null) {
        return;
    }

    $seances = Seance::where('operation_id', $participant->operation_id)
        ->orderBy('numero')
        ->get();

    if ($seances->isEmpty()) {
        return;
    }

    $moyenMap = [
        'especes' => ModePaiement::Especes,
        'cheque' => ModePaiement::Cheque,
        'virement' => ModePaiement::Virement,
    ];
    $moyen = $moyenMap[$request->input('moyen_paiement_choisi')] ?? null;

    // Note : comptant et par_seance produisent le même montant par séance
    // car total = nb_seances * tarif et montant_ligne = total / nb_seances = tarif.
    // Le choix est stocké sur Participant.mode_paiement_choisi comme préférence.
    foreach ($seances as $seance) {
        Reglement::create([
            'participant_id' => $participant->id,
            'seance_id' => $seance->id,
            'mode_paiement' => $moyen,
            'montant_prevu' => $tarif->montant,
        ]);
    }
}
```

Add necessary imports at the top of the controller:
```php
use App\Enums\ModePaiement;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use Illuminate\Support\Facades\DB;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail exec laravel.test php artisan test tests/Feature/FormulaireControllerTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/FormulaireController.php tests/Feature/FormulaireControllerTest.php
git commit -m "feat(formulaire): enriched store() with all new fields and reglement creation"
```

---

## Task 15 : Tests d'intégration end-to-end

**Files:**
- Modify: `tests/Feature/FormulaireControllerTest.php` — ensure full coverage

- [ ] **Step 1: Run the full test suite**

Run: `./vendor/bin/sail exec laravel.test php artisan test`
Expected: All tests pass

- [ ] **Step 2: Run Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`
Expected: All files formatted

- [ ] **Step 3: Verify the full flow manually in browser**

1. Go to `http://localhost/formulaire`
2. Enter a valid token
3. Navigate through all 7 steps
4. Fill in data on each step
5. On step 7, check all mandatory boxes, enter token, submit
6. Verify redirect to thank-you page
7. Check database: participant fields, medical data, reglements

- [ ] **Step 4: Commit any fixes**

```bash
git add -A
git commit -m "fix(formulaire): adjustments from end-to-end testing"
```

---

## Task 16 : Nettoyage de l'ancien formulaire

**Files:**
- Delete/archive: content of old `resources/views/formulaire/remplir.blade.php` (already replaced in task 6)

- [ ] **Step 1: Verify old form code is fully replaced**

Check that `remplir.blade.php` is the new wizard version, and no dead code remains from the old single-page form.

- [ ] **Step 2: Run full test suite one final time**

Run: `./vendor/bin/sail exec laravel.test php artisan test`
Expected: All green

- [ ] **Step 3: Run Pint one final time**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "chore(formulaire): cleanup old form code"
```
