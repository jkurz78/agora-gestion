# Impression Rapprochements Bancaires (PDF) — Plan d'implémentation

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter la génération PDF des rapprochements bancaires (transactions pointées uniquement) et un module Paramètres > Association pour configurer le nom, l'adresse et le logo de l'association.

**Architecture:** Module Association en Livewire avec upload de logo (Storage::disk('public')), singleton `id=1` géré via `updateOrCreate`. Contrôleur classique `RapprochementPdfController` qui collecte les transactions pointées, encode le logo en base64, et retourne un PDF via DomPDF (`barryvdh/laravel-dompdf`).

**Tech Stack:** Laravel 11, Livewire 4.2, DomPDF (barryvdh/laravel-dompdf), Pest PHP, Bootstrap 5 CDN.

---

## Chunk 1 : Module Paramètres > Association

### Task 1 : Migration et modèle Association

**Files:**
- Create: `database/migrations/2026_03_14_000001_create_association_table.php`
- Create: `app/Models/Association.php`
- Create: `tests/Feature/AssociationTest.php`

- [ ] **Step 1 : Écrire le test échouant**

```php
<?php
// tests/Feature/AssociationTest.php

use App\Models\Association;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('creates association row with id=1 when none exists', function () {
    Association::updateOrCreate(['id' => 1], [
        'nom' => 'Mon Association',
        'adresse' => '1 rue de Paris',
        'code_postal' => '75001',
        'ville' => 'Paris',
        'email' => 'contact@asso.fr',
        'telephone' => '0123456789',
    ]);

    $this->assertDatabaseHas('association', [
        'id' => 1,
        'nom' => 'Mon Association',
    ]);
});

it('updates existing association without creating duplicate', function () {
    Association::updateOrCreate(['id' => 1], ['nom' => 'V1']);
    Association::updateOrCreate(['id' => 1], ['nom' => 'V2']);

    expect(Association::count())->toBe(1)
        ->and(Association::find(1)->nom)->toBe('V2');
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/AssociationTest.php
```

Résultat attendu : FAIL — table `association` n'existe pas.

- [ ] **Step 3 : Créer la migration**

```php
<?php
// database/migrations/2026_03_14_000001_create_association_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('association', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('adresse')->nullable();
            $table->string('code_postal', 10)->nullable();
            $table->string('ville')->nullable();
            $table->string('email')->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('association');
    }
};
```

- [ ] **Step 4 : Créer le modèle**

```php
<?php
// app/Models/Association.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Association extends Model
{
    protected $table = 'association';

    protected $fillable = [
        'nom',
        'adresse',
        'code_postal',
        'ville',
        'email',
        'telephone',
        'logo_path',
    ];

    protected function casts(): array
    {
        return [
            'id'         => 'integer',
            'nom'        => 'string',
            'adresse'    => 'string',
            'code_postal'=> 'string',
            'ville'      => 'string',
            'email'      => 'string',
            'telephone'  => 'string',
            'logo_path'  => 'string',
        ];
    }
}
```

- [ ] **Step 5 : Lancer la migration**

```bash
./vendor/bin/sail artisan migrate
```

- [ ] **Step 6 : Lancer les tests pour vérifier qu'ils passent**

```bash
./vendor/bin/sail artisan test tests/Feature/AssociationTest.php
```

Résultat attendu : 2 tests PASS.

- [ ] **Step 7 : Commit**

```bash
git add database/migrations/2026_03_14_000001_create_association_table.php \
        app/Models/Association.php \
        tests/Feature/AssociationTest.php
git commit -m "feat: migration et modèle Association (singleton id=1)"
```

---

### Task 2 : Composant Livewire AssociationForm + vue + route

**Files:**
- Create: `app/Livewire/Parametres/AssociationForm.php`
- Create: `resources/views/livewire/parametres/association-form.blade.php`
- Create: `resources/views/parametres/association.blade.php`
- Modify: `routes/web.php` (ajouter route `parametres.association`)

- [ ] **Step 1 : Écrire les tests échouants**

Ajouter dans `tests/Feature/AssociationTest.php` :

```php
it('association page is accessible to authenticated user', function () {
    $this->actingAs($this->user)
        ->get(route('parametres.association'))
        ->assertOk();
});

it('association page redirects guest to login', function () {
    $this->get(route('parametres.association'))
        ->assertRedirect(route('login'));
});

it('can save association info via livewire', function () {
    Livewire::actingAs($this->user)
        ->test(\App\Livewire\Parametres\AssociationForm::class)
        ->set('nom', 'SVS')
        ->set('adresse', '12 rue des Lilas')
        ->set('code_postal', '75001')
        ->set('ville', 'Paris')
        ->set('email', 'contact@svs.fr')
        ->set('telephone', '0123456789')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('association', [
        'id'  => 1,
        'nom' => 'SVS',
        'email' => 'contact@svs.fr',
    ]);
});

it('validates nom is required', function () {
    Livewire::actingAs($this->user)
        ->test(\App\Livewire\Parametres\AssociationForm::class)
        ->set('nom', '')
        ->call('save')
        ->assertHasErrors(['nom' => 'required']);
});

it('validates email format', function () {
    Livewire::actingAs($this->user)
        ->test(\App\Livewire\Parametres\AssociationForm::class)
        ->set('nom', 'SVS')
        ->set('email', 'pas-un-email')
        ->call('save')
        ->assertHasErrors(['email']);
});

it('rejects logo exceeding 2MB', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('logo.png', 3000, 'image/png'); // 3 Mo

    Livewire::actingAs($this->user)
        ->test(\App\Livewire\Parametres\AssociationForm::class)
        ->set('nom', 'SVS')
        ->set('logo', $file)
        ->call('save')
        ->assertHasErrors(['logo']);
});

it('rejects logo with invalid mime type', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf');

    Livewire::actingAs($this->user)
        ->test(\App\Livewire\Parametres\AssociationForm::class)
        ->set('nom', 'SVS')
        ->set('logo', $file)
        ->call('save')
        ->assertHasErrors(['logo']);
});

it('saves valid logo and persists logo_path', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->image('logo.png', 200, 200);

    Livewire::actingAs($this->user)
        ->test(\App\Livewire\Parametres\AssociationForm::class)
        ->set('nom', 'SVS')
        ->set('logo', $file)
        ->call('save')
        ->assertHasNoErrors();

    $association = Association::find(1);
    expect($association->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($association->logo_path);
});

it('deletes old logo file before saving new one', function () {
    Storage::fake('public');

    // Premier upload
    Storage::disk('public')->put('association/logo.png', 'old');
    Association::updateOrCreate(['id' => 1], [
        'nom'       => 'SVS',
        'logo_path' => 'association/logo.png',
    ]);

    $newFile = UploadedFile::fake()->image('logo.jpg', 200, 200);

    Livewire::actingAs($this->user)
        ->test(\App\Livewire\Parametres\AssociationForm::class)
        ->set('nom', 'SVS')
        ->set('logo', $newFile)
        ->call('save')
        ->assertHasNoErrors();

    Storage::disk('public')->assertMissing('association/logo.png');
});
```

- [ ] **Step 2 : Lancer pour vérifier l'échec**

```bash
./vendor/bin/sail artisan test tests/Feature/AssociationTest.php
```

Résultat attendu : plusieurs FAIL (route et composant n'existent pas).

- [ ] **Step 3 : Ajouter la route dans `routes/web.php`**

Dans le groupe `parametres.` (en première position, avant `categories`), ajouter :

```php
Route::view('/parametres/association', 'parametres.association')->name('parametres.association');
```

Le groupe devient :

```php
Route::prefix('parametres')->name('parametres.')->group(function () {
    Route::view('/association', 'parametres.association')->name('association');  // ← nouveau, en premier
    Route::resource('categories', CategorieController::class)->except(['show']);
    Route::resource('sous-categories', SousCategorieController::class)->except(['show']);
    Route::resource('comptes-bancaires', CompteBancaireController::class)->except(['show']);
    Route::resource('utilisateurs', UserController::class)->only(['index', 'store', 'update', 'destroy']);
});
```

- [ ] **Step 4 : Créer la vue page**

```blade
{{-- resources/views/parametres/association.blade.php --}}
<x-app-layout>
    <h1 class="mb-4">Association</h1>
    <livewire:parametres.association-form />
</x-app-layout>
```

- [ ] **Step 5 : Créer le composant Livewire**

```php
<?php
// app/Livewire/Parametres/AssociationForm.php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Models\Association;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

final class AssociationForm extends Component
{
    use WithFileUploads;

    public string $nom = '';
    public string $adresse = '';
    public string $code_postal = '';
    public string $ville = '';
    public string $email = '';
    public string $telephone = '';
    public $logo = null;
    public ?string $logo_path = null;

    public function mount(): void
    {
        $association = Association::find(1);
        if ($association) {
            $this->nom         = $association->nom ?? '';
            $this->adresse     = $association->adresse ?? '';
            $this->code_postal = $association->code_postal ?? '';
            $this->ville       = $association->ville ?? '';
            $this->email       = $association->email ?? '';
            $this->telephone   = $association->telephone ?? '';
            $this->logo_path   = $association->logo_path;
        }
    }

    public function save(): void
    {
        $this->validate([
            'nom'         => ['required', 'string', 'max:255'],
            'adresse'     => ['nullable', 'string', 'max:500'],
            'code_postal' => ['nullable', 'string', 'max:10'],
            'ville'       => ['nullable', 'string', 'max:255'],
            'email'       => ['nullable', 'email', 'max:255'],
            'telephone'   => ['nullable', 'string', 'max:30'],
            'logo'        => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ]);

        $data = [
            'nom'         => $this->nom,
            'adresse'     => $this->adresse,
            'code_postal' => $this->code_postal,
            'ville'       => $this->ville,
            'email'       => $this->email,
            'telephone'   => $this->telephone,
        ];

        if ($this->logo !== null) {
            // Supprimer l'ancien logo s'il existe
            if ($this->logo_path !== null && Storage::disk('public')->exists($this->logo_path)) {
                Storage::disk('public')->delete($this->logo_path);
            }

            $extension       = $this->logo->extension();
            $path            = Storage::disk('public')->putFileAs('association', $this->logo, 'logo.'.$extension);
            $data['logo_path'] = $path;
            $this->logo_path = $path;
            $this->logo      = null;
        }

        Association::updateOrCreate(['id' => 1], $data);

        session()->flash('success', 'Informations de l\'association mises à jour.');
    }

    public function render(): View
    {
        $logoUrl = null;
        if ($this->logo_path !== null && Storage::disk('public')->exists($this->logo_path)) {
            $logoUrl = Storage::url($this->logo_path);
        }

        return view('livewire.parametres.association-form', ['logoUrl' => $logoUrl]);
    }
}
```

- [ ] **Step 6 : Créer la vue Blade du composant**

```blade
{{-- resources/views/livewire/parametres/association-form.blade.php --}}
<div>
    @if (session('success'))
        <div class="alert alert-success alert-dismissible mb-4">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card" style="max-width: 640px;">
        <div class="card-body">

            {{-- Logo actuel --}}
            @if ($logoUrl)
                <div class="mb-3">
                    <label class="form-label text-muted small">Logo actuel</label><br>
                    <img src="{{ $logoUrl }}" alt="Logo association" style="max-height: 80px; border: 1px solid #dee2e6; border-radius: 4px; padding: 4px;">
                </div>
            @endif

            <div class="mb-3">
                <label class="form-label">Nom de l'association <span class="text-danger">*</span></label>
                <input type="text" class="form-control @error('nom') is-invalid @enderror"
                       wire:model="nom">
                @error('nom') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Adresse</label>
                <input type="text" class="form-control" wire:model="adresse">
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Code postal</label>
                    <input type="text" class="form-control" wire:model="code_postal">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Ville</label>
                    <input type="text" class="form-control" wire:model="ville">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" class="form-control @error('email') is-invalid @enderror"
                       wire:model="email">
                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Téléphone</label>
                <input type="text" class="form-control" wire:model="telephone">
            </div>

            <div class="mb-4">
                <label class="form-label">Logo (PNG ou JPG, max 2 Mo)</label>
                <input type="file" class="form-control @error('logo') is-invalid @enderror"
                       wire:model="logo" accept=".png,.jpg,.jpeg">
                @error('logo') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">
                <span wire:loading.remove><i class="bi bi-floppy"></i> Enregistrer</span>
                <span wire:loading>Enregistrement…</span>
            </button>

        </div>
    </div>
</div>
```

- [ ] **Step 7 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Feature/AssociationTest.php
```

Résultat attendu : tous les tests PASS.

- [ ] **Step 8 : Commit**

```bash
git add app/Livewire/Parametres/AssociationForm.php \
        resources/views/livewire/parametres/association-form.blade.php \
        resources/views/parametres/association.blade.php \
        routes/web.php \
        tests/Feature/AssociationTest.php
git commit -m "feat: module Paramètres > Association avec upload logo"
```

---

### Task 3 : Sous-menu "Association" dans la navbar

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

- [ ] **Step 1 : Ajouter l'entrée de menu**

Dans `resources/views/layouts/app.blade.php`, insérer **uniquement** les 2 nouvelles lignes suivantes juste après `<ul class="dropdown-menu">` (ligne 60 du fichier actuel), avant l'entrée Catégories existante. Utiliser l'outil Edit en cherchant le texte unique `<ul class="dropdown-menu">` suivi de `<li>` :

Remplacer :
```blade
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.categories.*') ? 'active' : '' }}"
```

Par :
```blade
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.association') ? 'active' : '' }}"
                                   href="{{ route('parametres.association') }}">
                                    <i class="bi bi-building"></i> Association
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item {{ request()->routeIs('parametres.categories.*') ? 'active' : '' }}"
```

- [ ] **Step 2 : Vérifier visuellement** — naviguer sur http://localhost et vérifier que "Association" apparaît en premier dans le dropdown Paramètres.

- [ ] **Step 3 : Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat: sous-menu Association dans Paramètres"
```

---

## Chunk 2 : Génération PDF

### Task 4 : Installation DomPDF

**Files:**
- Modify: `composer.json` (via commande Composer)

- [ ] **Step 1 : Installer le package**

```bash
./vendor/bin/sail composer require barryvdh/laravel-dompdf
```

Résultat attendu : package installé, `composer.json` et `composer.lock` mis à jour.

- [ ] **Step 2 : Publier la configuration**

```bash
./vendor/bin/sail artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider"
```

Cela crée `config/dompdf.php`. Les valeurs par défaut conviennent — ne pas modifier.

- [ ] **Step 3 : Commit**

```bash
git add composer.json composer.lock config/dompdf.php
git commit -m "feat: installation barryvdh/laravel-dompdf"
```

---

### Task 5 : Contrôleur PDF et tests

**Files:**
- Create: `app/Http/Controllers/RapprochementPdfController.php`
- Create: `tests/Feature/RapprochementPdfTest.php`
- Modify: `routes/web.php`

- [ ] **Step 1 : Écrire les tests échouants**

```php
<?php
// tests/Feature/RapprochementPdfTest.php

use App\Enums\StatutRapprochement;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\RapprochementBancaire;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->compte = CompteBancaire::factory()->create([
        'nom' => 'Compte Test',
    ]);

    $this->rapprochement = RapprochementBancaire::factory()->create([
        'compte_id'       => $this->compte->id,
        'date_fin'        => now()->format('Y-m-d'),
        'solde_ouverture' => 1000.00,
        'solde_fin'       => 1200.00,
        'statut'          => StatutRapprochement::Verrouille,
        'saisi_par'       => $this->user->id,
    ]);
});

it('requires authentication to download PDF', function () {
    $this->get(route('rapprochement.pdf', $this->rapprochement))
        ->assertRedirect(route('login'));
});

it('returns 404 for non-existent rapprochement', function () {
    $this->actingAs($this->user)
        ->get(route('rapprochement.pdf', 99999))
        ->assertNotFound();
});

it('returns a PDF for authenticated user', function () {
    $this->actingAs($this->user)
        ->get(route('rapprochement.pdf', $this->rapprochement))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('generates PDF even when association is not configured', function () {
    expect(Association::find(1))->toBeNull();

    $this->actingAs($this->user)
        ->get(route('rapprochement.pdf', $this->rapprochement))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('generates PDF even when logo file is missing', function () {
    Association::updateOrCreate(['id' => 1], [
        'nom'       => 'Test',
        'logo_path' => 'association/logo-inexistant.png',
    ]);

    $this->actingAs($this->user)
        ->get(route('rapprochement.pdf', $this->rapprochement))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('passes only pointed transactions to PDF view', function () {
    Depense::factory()->create([
        'compte_id'        => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'libelle'          => 'Dépense pointée',
        'montant_total'    => 100.00,
        'date'             => now()->format('Y-m-d'),
    ]);

    Depense::factory()->create([
        'compte_id'        => $this->compte->id,
        'rapprochement_id' => null,
        'libelle'          => 'Dépense non pointée',
        'montant_total'    => 50.00,
        'date'             => now()->subDay()->format('Y-m-d'),
    ]);

    // Mock PDF facade to capture view data without rendering binary
    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(function (string $view, array $data): bool {
            expect($data['transactions'])->toHaveCount(1);
            expect($data['transactions'][0]['label'])->toBe('Dépense pointée');
            return true;
        })
        ->andReturnSelf();

    Pdf::shouldReceive('download')
        ->once()
        ->andReturn(response('', 200));

    $this->actingAs($this->user)
        ->get(route('rapprochement.pdf', $this->rapprochement))
        ->assertOk();
});
```

- [ ] **Step 2 : Lancer pour vérifier l'échec**

```bash
./vendor/bin/sail artisan test tests/Feature/RapprochementPdfTest.php
```

Résultat attendu : FAIL — route `rapprochement.pdf` n'existe pas.

- [ ] **Step 3 : Ajouter la route dans `routes/web.php`**

Après la ligne `Route::get('/rapprochement/{rapprochement}', ...)`, ajouter :

```php
use App\Http\Controllers\RapprochementPdfController;

// … dans le groupe middleware auth …

Route::get('/rapprochement/{rapprochement}/pdf', RapprochementPdfController::class)
    ->name('rapprochement.pdf');
```

Et ajouter l'import en haut du fichier :

```php
use App\Http\Controllers\RapprochementPdfController;
```

- [ ] **Step 4 : Créer le contrôleur**

```php
<?php
// app/Http/Controllers/RapprochementPdfController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\RapprochementBancaire;
use App\Models\Recette;
use App\Models\VirementInterne;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class RapprochementPdfController extends Controller
{
    public function __invoke(RapprochementBancaire $rapprochement): Response
    {
        $compte = $rapprochement->compte;
        $rid    = $rapprochement->id;

        $transactions = $this->collectTransactions($compte->id, $rid);

        $totalDebit  = $transactions->sum(fn ($tx) => $tx['montant_signe'] < 0 ? $tx['montant_signe'] : 0);
        $totalCredit = $transactions->sum(fn ($tx) => $tx['montant_signe'] > 0 ? $tx['montant_signe'] : 0);

        $association = Association::find(1);
        $logoBase64  = null;
        $logoMime    = 'image/png';

        if ($association?->logo_path && Storage::disk('public')->exists($association->logo_path)) {
            $logoBase64 = base64_encode(Storage::disk('public')->get($association->logo_path));
            $ext        = pathinfo($association->logo_path, PATHINFO_EXTENSION);
            $logoMime   = match (strtolower($ext)) {
                'jpg', 'jpeg' => 'image/jpeg',
                default       => 'image/png',
            };
        }

        $pdf = Pdf::loadView('pdf.rapprochement', [
            'rapprochement' => $rapprochement,
            'compte'        => $compte,
            'transactions'  => $transactions,
            'totalDebit'    => abs($totalDebit),
            'totalCredit'   => $totalCredit,
            'association'   => $association,
            'logoBase64'    => $logoBase64,
            'logoMime'      => $logoMime,
        ]);

        return $pdf->download('rapprochement-'.$rapprochement->id.'.pdf');
    }

    private function collectTransactions(int $compteId, int $rid): Collection
    {
        $transactions = collect();

        Depense::where('compte_id', $compteId)
            ->where('rapprochement_id', $rid)
            ->get()
            ->each(function (Depense $d) use (&$transactions) {
                $transactions->push([
                    'date'          => $d->date,
                    'type'          => 'Dépense',
                    'label'         => $d->libelle,
                    'reference'     => $d->reference,
                    'montant_signe' => -(float) $d->montant_total,
                ]);
            });

        Recette::where('compte_id', $compteId)
            ->where('rapprochement_id', $rid)
            ->get()
            ->each(function (Recette $r) use (&$transactions) {
                $transactions->push([
                    'date'          => $r->date,
                    'type'          => 'Recette',
                    'label'         => $r->libelle,
                    'reference'     => $r->reference,
                    'montant_signe' => (float) $r->montant_total,
                ]);
            });

        Don::where('compte_id', $compteId)
            ->where('rapprochement_id', $rid)
            ->with('donateur')
            ->get()
            ->each(function (Don $d) use (&$transactions) {
                $transactions->push([
                    'date'          => $d->date,
                    'type'          => 'Don',
                    'label'         => $d->donateur
                        ? $d->donateur->nom.' '.$d->donateur->prenom
                        : ($d->objet ?? 'Don anonyme'),
                    'reference'     => null,
                    'montant_signe' => (float) $d->montant,
                ]);
            });

        Cotisation::where('compte_id', $compteId)
            ->where('rapprochement_id', $rid)
            ->with('membre')
            ->get()
            ->each(function (Cotisation $c) use (&$transactions) {
                $transactions->push([
                    'date'          => $c->date_paiement,
                    'type'          => 'Cotisation',
                    'label'         => $c->membre ? $c->membre->nom.' '.$c->membre->prenom : 'Cotisation',
                    'reference'     => null,
                    'montant_signe' => (float) $c->montant,
                ]);
            });

        VirementInterne::where('compte_source_id', $compteId)
            ->where('rapprochement_source_id', $rid)
            ->with('compteDestination')
            ->get()
            ->each(function (VirementInterne $v) use (&$transactions) {
                $transactions->push([
                    'date'          => $v->date,
                    'type'          => 'Virement sortant',
                    'label'         => 'Virement vers '.$v->compteDestination->nom,
                    'reference'     => $v->reference,
                    'montant_signe' => -(float) $v->montant,
                ]);
            });

        VirementInterne::where('compte_destination_id', $compteId)
            ->where('rapprochement_destination_id', $rid)
            ->with('compteSource')
            ->get()
            ->each(function (VirementInterne $v) use (&$transactions) {
                $transactions->push([
                    'date'          => $v->date,
                    'type'          => 'Virement entrant',
                    'label'         => 'Virement depuis '.$v->compteSource->nom,
                    'reference'     => $v->reference,
                    'montant_signe' => (float) $v->montant,
                ]);
            });

        return $transactions->sortBy('date')->values();
    }
}
```

- [ ] **Step 5 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Feature/RapprochementPdfTest.php
```

Résultat attendu : FAIL sur les tests "returns a PDF" — la vue n'existe pas encore.

- [ ] **Step 6 : Créer la vue PDF**

> **Important DomPDF** : DomPDF ne supporte pas CSS Flexbox. Tous les layouts multi-colonnes utilisent `<table>` HTML natif.

```blade
{{-- resources/views/pdf/rapprochement.blade.php --}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }

        .section-title { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4px; color: #444; margin-bottom: 5px; }

        table.layout { width: 100%; border-collapse: collapse; }
        table.layout td { vertical-align: middle; padding: 0; }

        .asso-name { font-size: 13px; font-weight: bold; }
        .asso-contact { color: #555; font-size: 9px; margin-top: 2px; }
        .doc-title { font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; text-align: right; }
        .doc-date { color: #777; font-size: 9px; margin-top: 3px; text-align: right; }

        .header-sep { border-bottom: 2px solid #333; margin-bottom: 14px; padding-bottom: 12px; }

        .info-bg { background: #f5f5f5; padding: 8px 0; margin-bottom: 12px; }
        .info-label { font-size: 8px; text-transform: uppercase; letter-spacing: 0.4px; color: #777; display: block; }
        .info-value { font-weight: bold; font-size: 10px; }

        .solde-card { border: 1px solid #ddd; padding: 6px 8px; text-align: center; }
        .solde-card-ok { border: 1px solid #198754; padding: 6px 8px; text-align: center; }
        .solde-label { font-size: 8px; text-transform: uppercase; color: #777; }
        .solde-value { font-weight: bold; font-size: 11px; margin-top: 2px; }
        .green { color: #198754; }

        table.data { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        table.data thead tr { background: #e9ecef; border-bottom: 2px solid #adb5bd; }
        table.data thead th { padding: 5px 6px; text-align: left; font-weight: 700; color: #2d3748; }
        table.data thead th.tr { text-align: right; }
        table.data tbody tr.even { background: #f9f9f9; }
        table.data tbody td { padding: 4px 6px; border-bottom: 1px solid #eee; }
        table.data tbody td.muted { color: #888; }
        table.data tbody td.debit { color: #dc3545; text-align: right; }
        table.data tbody td.credit { color: #198754; text-align: right; }
        table.data tfoot tr { background: #eee; font-weight: bold; }
        table.data tfoot td { padding: 5px 6px; }
        .empty { text-align: center; color: #888; padding: 12px; }

        .footer-sep { border-top: 1px solid #ddd; margin-top: 16px; padding-top: 8px; color: #aaa; font-size: 8px; }
    </style>
</head>
<body>

    {{-- En-tête : table layout (flexbox non supporté par DomPDF) --}}
    <div class="header-sep">
        <table class="layout">
            <tr>
                @if ($logoBase64)
                <td style="width: 70px; padding-right: 10px;">
                    <img src="data:{{ $logoMime }};base64,{{ $logoBase64 }}" alt="Logo" style="max-height: 56px; max-width: 64px;">
                </td>
                @endif
                <td>
                    <div class="asso-name">{{ $association?->nom ?? '' }}</div>
                    @if ($association?->adresse)
                        <div class="asso-contact">{{ $association->adresse }}@if($association->code_postal || $association->ville), {{ trim($association->code_postal.' '.$association->ville) }}@endif</div>
                    @endif
                    @if ($association?->email)
                        <div class="asso-contact">{{ $association->email }}@if($association->telephone) — {{ $association->telephone }}@endif</div>
                    @endif
                </td>
                <td style="width: 200px;">
                    <div class="doc-title">Rapprochement bancaire</div>
                    <div class="doc-date">Généré le {{ now()->format('d/m/Y') }}</div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Bloc infos --}}
    <div class="info-bg" style="margin-bottom: 12px;">
        <table class="layout">
            <tr>
                <td style="padding: 0 12px;">
                    <span class="info-label">Compte</span>
                    <span class="info-value">{{ $compte->nom }}</span>
                </td>
                <td style="padding: 0 12px;">
                    <span class="info-label">Date de relevé</span>
                    <span class="info-value">{{ $rapprochement->date_fin->format('d/m/Y') }}</span>
                </td>
                <td style="padding: 0 12px;">
                    <span class="info-label">Statut</span>
                    <span class="info-value">{{ $rapprochement->isVerrouille() ? 'Verrouillé' : 'En cours' }}</span>
                </td>
                <td style="padding: 0 12px;">
                    <span class="info-label">Saisi par</span>
                    <span class="info-value">{{ $rapprochement->saisiPar?->nom ?? '—' }}</span>
                </td>
            </tr>
        </table>
    </div>

    {{-- Bandeau soldes --}}
    @php $ecart = (float) $rapprochement->solde_fin - ((float) $rapprochement->solde_ouverture + $totalCredit - $totalDebit); @endphp
    <table class="layout" style="margin-bottom: 12px;">
        <tr>
            <td style="padding-right: 4px;">
                <div class="solde-card">
                    <div class="solde-label">Solde ouverture</div>
                    <div class="solde-value">{{ number_format((float) $rapprochement->solde_ouverture, 2, ',', ' ') }} €</div>
                </div>
            </td>
            <td style="padding-right: 4px;">
                <div class="solde-card">
                    <div class="solde-label">Solde relevé</div>
                    <div class="solde-value">{{ number_format((float) $rapprochement->solde_fin, 2, ',', ' ') }} €</div>
                </div>
            </td>
            <td style="padding-right: 4px;">
                <div class="solde-card">
                    <div class="solde-label">Solde pointé</div>
                    <div class="solde-value">{{ number_format((float) $rapprochement->solde_ouverture + $totalCredit - $totalDebit, 2, ',', ' ') }} €</div>
                </div>
            </td>
            <td>
                <div class="{{ abs($ecart) < 0.01 ? 'solde-card-ok' : 'solde-card' }}">
                    <div class="solde-label">Écart</div>
                    <div class="solde-value {{ abs($ecart) < 0.01 ? 'green' : '' }}">{{ number_format($ecart, 2, ',', ' ') }} €</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- Titre section --}}
    <div class="section-title">Transactions pointées ({{ $transactions->count() }})</div>

    {{-- Tableau des transactions --}}
    <table class="data">
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Libellé</th>
                <th>Réf.</th>
                <th class="tr">Débit</th>
                <th class="tr">Crédit</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($transactions as $i => $tx)
                <tr class="{{ $i % 2 === 1 ? 'even' : '' }}">
                    <td>{{ $tx['date']->format('d/m/Y') }}</td>
                    <td>{{ $tx['type'] }}</td>
                    <td>{{ $tx['label'] }}</td>
                    <td class="muted">{{ $tx['reference'] ?? '—' }}</td>
                    <td class="debit">
                        @if ($tx['montant_signe'] < 0)
                            {{ number_format(abs($tx['montant_signe']), 2, ',', ' ') }} €
                        @endif
                    </td>
                    <td class="credit">
                        @if ($tx['montant_signe'] > 0)
                            {{ number_format($tx['montant_signe'], 2, ',', ' ') }} €
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="empty">Aucune transaction pointée.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" style="text-align: right;">Totaux</td>
                <td style="text-align: right; color: #dc3545;">{{ number_format($totalDebit, 2, ',', ' ') }} €</td>
                <td style="text-align: right; color: #198754;">{{ number_format($totalCredit, 2, ',', ' ') }} €</td>
            </tr>
        </tfoot>
    </table>

    {{-- Pied de page --}}
    <div class="footer-sep">
        <table class="layout">
            <tr>
                <td>SVS Accounting — Document généré automatiquement</td>
                <td style="text-align: right;">Page 1</td>
            </tr>
        </table>
    </div>

</body>
</html>
```

- [ ] **Step 7 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Feature/RapprochementPdfTest.php
```

Résultat attendu : tous les tests PASS.

- [ ] **Step 8 : Commit**

```bash
git add app/Http/Controllers/RapprochementPdfController.php \
        resources/views/pdf/rapprochement.blade.php \
        routes/web.php \
        tests/Feature/RapprochementPdfTest.php
git commit -m "feat: génération PDF rapprochement bancaire via DomPDF"
```

---

### Task 6 : Bouton "Télécharger PDF" dans la vue détail

**Files:**
- Modify: `resources/views/livewire/rapprochement-detail.blade.php`

- [ ] **Step 1 : Ajouter le bouton dans l'en-tête**

Dans `resources/views/livewire/rapprochement-detail.blade.php`, dans le bloc d'en-tête (`<div class="d-flex justify-content-between ..."`), ajouter le bouton PDF à côté du bouton "Retour". Remplacer :

```blade
<a href="{{ route('rapprochement.index') }}" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i> Retour
</a>
```

par :

```blade
<div class="d-flex gap-2">
    <a href="{{ route('rapprochement.pdf', $rapprochement) }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-file-pdf"></i> Télécharger PDF
    </a>
    <a href="{{ route('rapprochement.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Retour
    </a>
</div>
```

- [ ] **Step 2 : Vérifier visuellement** — ouvrir http://localhost/rapprochement/{id} et cliquer sur "Télécharger PDF". Le PDF doit se télécharger avec les transactions pointées uniquement.

- [ ] **Step 3 : Lancer tous les tests**

```bash
./vendor/bin/sail artisan test
```

Résultat attendu : tous les tests existants + nouveaux PASS, aucune régression.

- [ ] **Step 4 : Commit**

```bash
git add resources/views/livewire/rapprochement-detail.blade.php
git commit -m "feat: bouton Télécharger PDF sur la page détail rapprochement"
```

---

### Task 7 : Vérifier storage:link

**Files:** aucun

- [ ] **Step 1 : Vérifier que le lien symbolique existe**

Depuis la racine du projet :

```bash
readlink public/storage
```

Si la commande ne retourne rien (lien absent) :

```bash
./vendor/bin/sail artisan storage:link
```

- [ ] **Step 2 : Vérifier l'upload du logo** — aller sur http://localhost/parametres/association, uploader un logo PNG, sauvegarder, et vérifier que l'aperçu s'affiche.
