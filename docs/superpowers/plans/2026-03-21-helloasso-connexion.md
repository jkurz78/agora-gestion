# HelloAsso — Connexion OAuth2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Créer l'écran Paramètres > Connexion HelloAsso permettant de saisir les credentials OAuth2, de les sauvegarder chiffrés, et de tester la connexion en direct.

**Architecture:** Enum `HelloAssoEnvironnement` → Modèle `HelloAssoParametres` (encrypted cast) → Service `HelloAssoService` (Http:: avec Http::fake() en test) → Composant Livewire `Parametres\HelloAssoForm`. Le résultat du test est stocké en `?array` sur le composant (et non un objet PHP) pour rester sérialisable par Livewire 4 entre les requêtes.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 CDN, MySQL, Pest PHP, `Http::fake()` pour les tests du service, `Mockery` pour les tests Livewire.

---

## Fichiers à créer/modifier

| Fichier | Action |
|---|---|
| `app/Enums/HelloAssoEnvironnement.php` | Créer |
| `database/migrations/2026_03_21_000001_create_helloasso_parametres_table.php` | Créer |
| `app/Models/HelloAssoParametres.php` | Créer |
| `app/Services/HelloAssoTestResult.php` | Créer |
| `app/Services/HelloAssoService.php` | Créer |
| `app/Livewire/Parametres/HelloAssoForm.php` | Créer |
| `resources/views/parametres/helloasso.blade.php` | Créer |
| `resources/views/livewire/parametres/helloasso-form.blade.php` | Créer |
| `routes/web.php` | Modifier |
| `resources/views/layouts/app.blade.php` | Modifier |
| `tests/Feature/Enums/HelloAssoEnvironnementTest.php` | Créer |
| `tests/Feature/Services/HelloAssoServiceTest.php` | Créer |
| `tests/Feature/Livewire/HelloAssoFormTest.php` | Créer |
| `tests/Feature/Http/ParametresNavigationTest.php` | Modifier (ajouter test route) |

---

## Task 1 : Enum `HelloAssoEnvironnement`

**Files:**
- Create: `app/Enums/HelloAssoEnvironnement.php`
- Create: `tests/Feature/Enums/HelloAssoEnvironnementTest.php`

- [ ] **Step 1 : Écrire le test**

```php
<?php
// tests/Feature/Enums/HelloAssoEnvironnementTest.php
declare(strict_types=1);

use App\Enums\HelloAssoEnvironnement;

it('retourne la bonne URL de base pour production', function () {
    expect(HelloAssoEnvironnement::Production->baseUrl())
        ->toBe('https://api.helloasso.com');
});

it('retourne la bonne URL de base pour sandbox', function () {
    expect(HelloAssoEnvironnement::Sandbox->baseUrl())
        ->toBe('https://api.helloasso-sandbox.com');
});

it('retourne la bonne URL admin pour production', function () {
    expect(HelloAssoEnvironnement::Production->adminUrl())
        ->toBe('https://admin.helloasso.com');
});

it('retourne la bonne URL admin pour sandbox', function () {
    expect(HelloAssoEnvironnement::Sandbox->adminUrl())
        ->toBe('https://admin.helloasso-sandbox.com');
});

it('retourne le bon label', function () {
    expect(HelloAssoEnvironnement::Production->label())->toBe('Production');
    expect(HelloAssoEnvironnement::Sandbox->label())->toBe('Sandbox');
});

it('peut être casté depuis la valeur string production', function () {
    expect(HelloAssoEnvironnement::from('production'))
        ->toBe(HelloAssoEnvironnement::Production);
});
```

- [ ] **Step 2 : Lancer le test pour vérifier qu'il échoue**

```bash
./vendor/bin/sail artisan test tests/Feature/Enums/HelloAssoEnvironnementTest.php
```

Résultat attendu : FAIL — `HelloAssoEnvironnement` n'existe pas.

- [ ] **Step 3 : Créer l'enum**

```php
<?php
// app/Enums/HelloAssoEnvironnement.php
declare(strict_types=1);

namespace App\Enums;

enum HelloAssoEnvironnement: string
{
    case Production = 'production';
    case Sandbox    = 'sandbox';

    public function baseUrl(): string
    {
        return match ($this) {
            self::Production => 'https://api.helloasso.com',
            self::Sandbox    => 'https://api.helloasso-sandbox.com',
        };
    }

    public function adminUrl(): string
    {
        return match ($this) {
            self::Production => 'https://admin.helloasso.com',
            self::Sandbox    => 'https://admin.helloasso-sandbox.com',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Production => 'Production',
            self::Sandbox    => 'Sandbox',
        };
    }
}
```

- [ ] **Step 4 : Lancer le test pour vérifier qu'il passe**

```bash
./vendor/bin/sail artisan test tests/Feature/Enums/HelloAssoEnvironnementTest.php
```

Résultat attendu : PASS (6 tests).

- [ ] **Step 5 : Commit**

```bash
git add app/Enums/HelloAssoEnvironnement.php tests/Feature/Enums/HelloAssoEnvironnementTest.php
git commit -m "feat(helloasso): enum HelloAssoEnvironnement avec baseUrl, adminUrl et label"
```

---

## Task 2 : Migration + Modèle `HelloAssoParametres`

**Files:**
- Create: `database/migrations/2026_03_21_000001_create_helloasso_parametres_table.php`
- Create: `app/Models/HelloAssoParametres.php`

Note : la table de l'association est `association` (singulier, sans 's') — voir `2026_03_14_000001_create_association_table.php`.

- [ ] **Step 1 : Créer la migration**

```php
<?php
// database/migrations/2026_03_21_000001_create_helloasso_parametres_table.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helloasso_parametres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->unique()->constrained('association');
            $table->string('client_id', 255)->nullable();
            $table->text('client_secret')->nullable();
            $table->string('organisation_slug', 255)->nullable();
            $table->string('environnement', 20)->default('production');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helloasso_parametres');
    }
};
```

- [ ] **Step 2 : Créer le modèle**

```php
<?php
// app/Models/HelloAssoParametres.php
declare(strict_types=1);

namespace App\Models;

use App\Enums\HelloAssoEnvironnement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HelloAssoParametres extends Model
{
    protected $table = 'helloasso_parametres';

    protected $fillable = [
        'association_id',
        'client_id',
        'client_secret',
        'organisation_slug',
        'environnement',
    ];

    protected function casts(): array
    {
        return [
            'client_secret'  => 'encrypted',
            'association_id' => 'integer',
            'environnement'  => HelloAssoEnvironnement::class,
        ];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }
}
```

- [ ] **Step 3 : Lancer la migration**

```bash
./vendor/bin/sail artisan migrate
```

Résultat attendu : migration `2026_03_21_000001_create_helloasso_parametres_table` exécutée.

- [ ] **Step 4 : Vérifier que la suite de tests passe toujours**

```bash
./vendor/bin/sail artisan test
```

Résultat attendu : tous les tests existants passent.

- [ ] **Step 5 : Commit**

```bash
git add database/migrations/2026_03_21_000001_create_helloasso_parametres_table.php app/Models/HelloAssoParametres.php
git commit -m "feat(helloasso): migration et modèle HelloAssoParametres (client_secret chiffré)"
```

---

## Task 3 : `HelloAssoTestResult` + `HelloAssoService`

**Files:**
- Create: `app/Services/HelloAssoTestResult.php`
- Create: `app/Services/HelloAssoService.php`
- Create: `tests/Feature/Services/HelloAssoServiceTest.php`

Note TDD : `HelloAssoTestResult` est un DTO sans logique propre, créé en premier car les tests du service en dépendent. Ce n'est pas une violation du TDD — les tests restent écrits avant le service.

- [ ] **Step 1 : Créer le value object `HelloAssoTestResult`**

```php
<?php
// app/Services/HelloAssoTestResult.php
declare(strict_types=1);

namespace App\Services;

final class HelloAssoTestResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $organisationNom = null,
        public readonly ?string $erreur = null,
    ) {}
}
```

- [ ] **Step 2 : Écrire les tests du service**

```php
<?php
// tests/Feature/Services/HelloAssoServiceTest.php
declare(strict_types=1);

use App\Enums\HelloAssoEnvironnement;
use App\Models\HelloAssoParametres;
use App\Services\HelloAssoService;
use App\Services\HelloAssoTestResult;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->service = app(HelloAssoService::class);
});

function makeParametres(string $env = 'production'): HelloAssoParametres
{
    $p = new HelloAssoParametres();
    $p->client_id         = 'mon-client-id';
    $p->client_secret     = 'mon-client-secret';
    $p->organisation_slug = 'association-svs';
    $p->environnement     = HelloAssoEnvironnement::from($env);
    return $p;
}

it('retourne succès avec nom organisation quand connexion OK', function () {
    Http::fake([
        'api.helloasso.com/oauth2/token' => Http::response(['access_token' => 'tok123'], 200),
        'api.helloasso.com/v5/organizations/association-svs' => Http::response(['name' => 'SVS'], 200),
    ]);

    $result = $this->service->testerConnexion(makeParametres());

    expect($result)->toBeInstanceOf(HelloAssoTestResult::class);
    expect($result->success)->toBeTrue();
    expect($result->organisationNom)->toBe('SVS');
    expect($result->erreur)->toBeNull();
});

it('retourne erreur si token OAuth2 échoue (401)', function () {
    Http::fake([
        'api.helloasso.com/oauth2/token' => Http::response([], 401),
    ]);

    $result = $this->service->testerConnexion(makeParametres());

    expect($result->success)->toBeFalse();
    expect($result->erreur)->toContain('401');
});

it('retourne erreur si slug introuvable (404)', function () {
    Http::fake([
        'api.helloasso.com/oauth2/token' => Http::response(['access_token' => 'tok123'], 200),
        'api.helloasso.com/v5/organizations/association-svs' => Http::response([], 404),
    ]);

    $result = $this->service->testerConnexion(makeParametres());

    expect($result->success)->toBeFalse();
    expect($result->erreur)->toContain('404');
});

it('retourne erreur réseau si connexion impossible', function () {
    Http::fake([
        'api.helloasso.com/*' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        },
    ]);

    $result = $this->service->testerConnexion(makeParametres());

    expect($result->success)->toBeFalse();
    expect($result->erreur)->toContain('réseau');
});

it('utilise la bonne URL pour le sandbox', function () {
    Http::fake([
        'api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok-sb'], 200),
        'api.helloasso-sandbox.com/v5/organizations/association-svs' => Http::response(['name' => 'SVS Sandbox'], 200),
    ]);

    $result = $this->service->testerConnexion(makeParametres('sandbox'));

    expect($result->success)->toBeTrue();
    expect($result->organisationNom)->toBe('SVS Sandbox');
});
```

- [ ] **Step 3 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/Services/HelloAssoServiceTest.php
```

Résultat attendu : FAIL — `HelloAssoService` n'existe pas.

- [ ] **Step 4 : Créer le service**

Note : le champ JSON du nom de l'organisation est vraisemblablement `name`. Si l'API retourne un champ différent (ex. `organizationName`), l'ajuster après test contre la vraie API ou le sandbox HelloAsso.

```php
<?php
// app/Services/HelloAssoService.php
declare(strict_types=1);

namespace App\Services;

use App\Models\HelloAssoParametres;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class HelloAssoService
{
    public function testerConnexion(HelloAssoParametres $parametres): HelloAssoTestResult
    {
        $baseUrl = $parametres->environnement->baseUrl();

        // Étape 1 : obtenir un token OAuth2
        try {
            $tokenResponse = Http::timeout(10)->asForm()->post("{$baseUrl}/oauth2/token", [
                'client_id'     => $parametres->client_id,
                'client_secret' => $parametres->client_secret,
                'grant_type'    => 'client_credentials',
            ]);
        } catch (ConnectionException) {
            return new HelloAssoTestResult(
                success: false,
                erreur: 'Impossible de joindre HelloAsso : timeout ou erreur réseau',
            );
        }

        if ($tokenResponse->failed()) {
            return new HelloAssoTestResult(
                success: false,
                erreur: "Erreur d'authentification : client_id ou client_secret invalide (HTTP {$tokenResponse->status()})",
            );
        }

        $token = $tokenResponse->json('access_token');

        // Étape 2 : vérifier le slug organisation
        try {
            $orgResponse = Http::timeout(10)
                ->withToken($token)
                ->get("{$baseUrl}/v5/organizations/{$parametres->organisation_slug}");
        } catch (ConnectionException) {
            return new HelloAssoTestResult(
                success: false,
                erreur: 'Impossible de joindre HelloAsso : timeout ou erreur réseau',
            );
        }

        if ($orgResponse->status() === 404) {
            return new HelloAssoTestResult(
                success: false,
                erreur: 'Organisation introuvable : vérifiez le slug (HTTP 404)',
            );
        }

        if ($orgResponse->failed()) {
            return new HelloAssoTestResult(
                success: false,
                erreur: "Erreur lors de la vérification de l'organisation (HTTP {$orgResponse->status()})",
            );
        }

        $nom = $orgResponse->json('name') ?? $orgResponse->json('organizationName') ?? '—';

        return new HelloAssoTestResult(
            success: true,
            organisationNom: $nom,
        );
    }
}
```

- [ ] **Step 5 : Lancer les tests pour vérifier qu'ils passent**

```bash
./vendor/bin/sail artisan test tests/Feature/Services/HelloAssoServiceTest.php
```

Résultat attendu : PASS (5 tests).

- [ ] **Step 6 : Commit**

```bash
git add app/Services/HelloAssoTestResult.php app/Services/HelloAssoService.php tests/Feature/Services/HelloAssoServiceTest.php
git commit -m "feat(helloasso): HelloAssoService avec test OAuth2 + vérification slug"
```

---

## Task 4 : Route, vue page, et entrée menu

**Files:**
- Modify: `routes/web.php`
- Create: `resources/views/parametres/helloasso.blade.php`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `tests/Feature/Http/ParametresNavigationTest.php`

- [ ] **Step 1 : Ajouter le test de route**

Dans `tests/Feature/Http/ParametresNavigationTest.php`, ajouter à la fin du fichier (le `beforeEach` existant fournit déjà `actingAs`) :

```php
test('GET /parametres/helloasso retourne 200', function () {
    $response = $this->get('/parametres/helloasso');
    $response->assertStatus(200);
});
```

- [ ] **Step 2 : Lancer le test pour vérifier qu'il échoue**

```bash
./vendor/bin/sail artisan test tests/Feature/Http/ParametresNavigationTest.php --filter="helloasso"
```

Résultat attendu : FAIL 404.

- [ ] **Step 3 : Ajouter la route dans `routes/web.php`**

Dans le groupe `Route::prefix('parametres')->name('parametres.')`, après la ligne `Route::view('/association', 'parametres.association')->name('association');` :

```php
Route::view('/helloasso', 'parametres.helloasso')->name('helloasso');
```

- [ ] **Step 4 : Créer la vue page**

```blade
{{-- resources/views/parametres/helloasso.blade.php --}}
<x-app-layout>
    <h1 class="mb-4">Connexion HelloAsso</h1>
    <livewire:parametres.helloasso-form />
</x-app-layout>
```

- [ ] **Step 5 : Créer le composant Livewire minimal** (pour que la route réponde 200)

```php
<?php
// app/Livewire/Parametres/HelloAssoForm.php
declare(strict_types=1);

namespace App\Livewire\Parametres;

use Illuminate\View\View;
use Livewire\Component;

final class HelloAssoForm extends Component
{
    public function render(): View
    {
        return view('livewire.parametres.helloasso-form');
    }
}
```

```blade
{{-- resources/views/livewire/parametres/helloasso-form.blade.php --}}
<div>
    <!-- TODO -->
</div>
```

- [ ] **Step 6 : Lancer le test de route**

```bash
./vendor/bin/sail artisan test tests/Feature/Http/ParametresNavigationTest.php --filter="helloasso"
```

Résultat attendu : PASS.

- [ ] **Step 7 : Ajouter l'entrée dans le menu**

Dans `resources/views/layouts/app.blade.php`, dans le dropdown Paramètres, après le bloc `@endif` qui ferme "Association" (après la ligne `href="{{ route('parametres.association') }}"`) :

```blade
@if (Route::has('parametres.helloasso'))
<li>
    <a class="dropdown-item {{ request()->routeIs('parametres.helloasso') ? 'active' : '' }}"
       href="{{ route('parametres.helloasso') }}">
        <i class="bi bi-plug"></i> Connexion HelloAsso
    </a>
</li>
@endif
```

- [ ] **Step 8 : Commit**

```bash
git add routes/web.php resources/views/parametres/helloasso.blade.php resources/views/livewire/parametres/helloasso-form.blade.php app/Livewire/Parametres/HelloAssoForm.php resources/views/layouts/app.blade.php tests/Feature/Http/ParametresNavigationTest.php
git commit -m "feat(helloasso): route, vue page et entrée menu Paramètres"
```

---

## Task 5 : Composant Livewire `HelloAssoForm` complet

**Files:**
- Modify: `app/Livewire/Parametres/HelloAssoForm.php`
- Modify: `resources/views/livewire/parametres/helloasso-form.blade.php`
- Create: `tests/Feature/Livewire/HelloAssoFormTest.php`

Note technique : `$testResult` est stocké en `?array` (et non objet PHP) pour être sérialisable par Livewire 4 entre les requêtes. Le service retourne toujours un `HelloAssoTestResult`, converti en tableau par le composant.

Note technique : l'association id=1 est créée via `DB::table('association')->insert(...)` dans les tests car le modèle `Association` n'a pas de factory et `id` n'est pas dans `$fillable`.

- [ ] **Step 1 : Écrire les tests Livewire**

```php
<?php
// tests/Feature/Livewire/HelloAssoFormTest.php
declare(strict_types=1);

use App\Livewire\Parametres\HelloAssoForm;
use App\Models\HelloAssoParametres;
use App\Models\User;
use App\Services\HelloAssoService;
use App\Services\HelloAssoTestResult;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    // Créer l'association id=1 via DB car 'id' n'est pas dans $fillable du modèle Association
    DB::table('association')->insert(['id' => 1, 'nom' => 'SVS', 'created_at' => now(), 'updated_at' => now()]);
});

it('monte sans configuration existante', function () {
    Livewire::test(HelloAssoForm::class)
        ->assertSet('clientId', '')
        ->assertSet('organisationSlug', '')
        ->assertSet('environnement', 'production')
        ->assertSet('secretDejaEnregistre', false);
});

it('monte avec configuration existante et ne pré-remplit pas le secret', function () {
    HelloAssoParametres::create([
        'association_id'    => 1,
        'client_id'         => 'cid-123',
        'client_secret'     => 'secret-xyz',
        'organisation_slug' => 'association-svs',
        'environnement'     => 'production',
    ]);

    Livewire::test(HelloAssoForm::class)
        ->assertSet('clientId', 'cid-123')
        ->assertSet('clientSecret', '')
        ->assertSet('organisationSlug', 'association-svs')
        ->assertSet('secretDejaEnregistre', true);
});

it('sauvegarde une nouvelle configuration', function () {
    Livewire::test(HelloAssoForm::class)
        ->set('clientId', 'new-cid')
        ->set('clientSecret', 'new-secret')
        ->set('organisationSlug', 'asso-test')
        ->set('environnement', 'production')
        ->call('sauvegarder')
        ->assertHasNoErrors();

    $p = HelloAssoParametres::where('association_id', 1)->first();
    expect($p)->not->toBeNull();
    expect($p->client_id)->toBe('new-cid');
    expect($p->client_secret)->toBe('new-secret');
    expect($p->organisation_slug)->toBe('asso-test');
});

it('conserve le secret existant si le champ est laissé vide à la sauvegarde', function () {
    HelloAssoParametres::create([
        'association_id'    => 1,
        'client_id'         => 'cid',
        'client_secret'     => 'ancien-secret',
        'organisation_slug' => 'asso',
        'environnement'     => 'production',
    ]);

    Livewire::test(HelloAssoForm::class)
        ->set('clientId', 'cid-modifie')
        ->set('clientSecret', '')
        ->call('sauvegarder')
        ->assertHasNoErrors();

    $p = HelloAssoParametres::where('association_id', 1)->first();
    expect($p->client_id)->toBe('cid-modifie');
    expect($p->client_secret)->toBe('ancien-secret');
});

it('rejette un slug avec des caractères invalides à la sauvegarde', function () {
    Livewire::test(HelloAssoForm::class)
        ->set('clientId', 'cid')
        ->set('organisationSlug', 'SLUG INVALIDE!')
        ->call('sauvegarder')
        ->assertHasErrors(['organisationSlug']);
});

it('appelle le service et stocke le succès en tableau', function () {
    $mock = Mockery::mock(HelloAssoService::class);
    $mock->shouldReceive('testerConnexion')
        ->once()
        ->andReturn(new HelloAssoTestResult(success: true, organisationNom: 'SVS'));
    app()->instance(HelloAssoService::class, $mock);

    Livewire::test(HelloAssoForm::class)
        ->set('clientId', 'cid')
        ->set('clientSecret', 'secret')
        ->set('organisationSlug', 'asso-svs')
        ->call('testerConnexion')
        ->assertSet('testResult.success', true)
        ->assertSet('testResult.organisationNom', 'SVS');
});

it('stocke l\'erreur en tableau si le test échoue', function () {
    $mock = Mockery::mock(HelloAssoService::class);
    $mock->shouldReceive('testerConnexion')
        ->once()
        ->andReturn(new HelloAssoTestResult(success: false, erreur: "Erreur d'authentification (HTTP 401)"));
    app()->instance(HelloAssoService::class, $mock);

    Livewire::test(HelloAssoForm::class)
        ->set('clientId', 'cid')
        ->set('clientSecret', 'mauvais-secret')
        ->set('organisationSlug', 'asso-svs')
        ->call('testerConnexion')
        ->assertSet('testResult.success', false)
        ->assertSet('testResult.erreur', "Erreur d'authentification (HTTP 401)");
});

it('utilise le secret en base si clientSecret est vide pour le test', function () {
    HelloAssoParametres::create([
        'association_id'    => 1,
        'client_id'         => 'cid',
        'client_secret'     => 'secret-en-base',
        'organisation_slug' => 'asso-svs',
        'environnement'     => 'production',
    ]);

    $mock = Mockery::mock(HelloAssoService::class);
    $mock->shouldReceive('testerConnexion')
        ->once()
        ->withArgs(function (HelloAssoParametres $p) {
            return $p->client_secret === 'secret-en-base';
        })
        ->andReturn(new HelloAssoTestResult(success: true, organisationNom: 'SVS'));
    app()->instance(HelloAssoService::class, $mock);

    Livewire::test(HelloAssoForm::class)
        ->set('clientId', 'cid')
        ->set('clientSecret', '')
        ->set('organisationSlug', 'asso-svs')
        ->call('testerConnexion')
        ->assertSet('testResult.success', true);
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/HelloAssoFormTest.php
```

Résultat attendu : FAIL — `HelloAssoForm` incomplet.

- [ ] **Step 3 : Implémenter le composant Livewire**

```php
<?php
// app/Livewire/Parametres/HelloAssoForm.php
declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Enums\HelloAssoEnvironnement;
use App\Models\HelloAssoParametres;
use App\Services\HelloAssoService;
use Illuminate\View\View;
use Livewire\Component;

final class HelloAssoForm extends Component
{
    public string $clientId          = '';
    public string $clientSecret      = '';
    public string $organisationSlug  = '';
    public string $environnement     = 'production';
    /** @var array{success: bool, organisationNom: ?string, erreur: ?string}|null */
    public ?array $testResult        = null;
    public bool $secretDejaEnregistre = false;

    public function mount(): void
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();
        if ($p !== null) {
            $this->clientId         = $p->client_id ?? '';
            $this->organisationSlug = $p->organisation_slug ?? '';
            $this->environnement    = $p->environnement->value;
            if ($p->client_secret !== null) {
                $this->secretDejaEnregistre = true;
            }
        }
    }

    public function sauvegarder(): void
    {
        $this->validate([
            'clientId'         => ['nullable', 'string', 'max:255'],
            'clientSecret'     => ['nullable', 'string'],
            'organisationSlug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]*$/'],
            'environnement'    => ['required', 'in:production,sandbox'],
        ]);

        $payload = [
            'client_id'         => $this->clientId ?: null,
            'organisation_slug' => $this->organisationSlug ?: null,
            'environnement'     => $this->environnement,
        ];

        if ($this->clientSecret !== '') {
            $payload['client_secret'] = $this->clientSecret;
        }

        HelloAssoParametres::updateOrCreate(
            ['association_id' => 1],
            $payload,
        );

        if ($this->clientSecret !== '') {
            $this->secretDejaEnregistre = true;
        }

        $this->testResult = null;
        session()->flash('success', 'Paramètres HelloAsso enregistrés.');
    }

    public function testerConnexion(): void
    {
        $this->validate([
            'clientId'         => ['required', 'string'],
            'clientSecret'     => $this->secretDejaEnregistre ? ['nullable', 'string'] : ['required', 'string'],
            'organisationSlug' => ['required', 'string'],
            'environnement'    => ['required', 'in:production,sandbox'],
        ]);

        $secret = $this->clientSecret;
        if ($secret === '' && $this->secretDejaEnregistre) {
            $enBase = HelloAssoParametres::where('association_id', 1)->first();
            $secret = $enBase?->client_secret ?? '';
        }

        $parametres = new HelloAssoParametres();
        $parametres->client_id         = $this->clientId;
        $parametres->client_secret     = $secret;
        $parametres->organisation_slug = $this->organisationSlug;
        $parametres->environnement     = HelloAssoEnvironnement::from($this->environnement);

        $result = app(HelloAssoService::class)->testerConnexion($parametres);

        // Stocker en tableau pour la sérialisabilité Livewire 4
        $this->testResult = [
            'success'         => $result->success,
            'organisationNom' => $result->organisationNom,
            'erreur'          => $result->erreur,
        ];
    }

    public function render(): View
    {
        return view('livewire.parametres.helloasso-form');
    }
}
```

- [ ] **Step 4 : Lancer les tests pour vérifier qu'ils passent**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/HelloAssoFormTest.php
```

Résultat attendu : PASS (8 tests).

- [ ] **Step 5 : Commit intermédiaire**

```bash
git add app/Livewire/Parametres/HelloAssoForm.php tests/Feature/Livewire/HelloAssoFormTest.php
git commit -m "feat(helloasso): composant Livewire HelloAssoForm avec sauvegarde et test connexion"
```

---

## Task 6 : Vue Blade du formulaire

**Files:**
- Modify: `resources/views/livewire/parametres/helloasso-form.blade.php`

Les tests Livewire de la Task 5 couvrent le comportement. Vérifier visuellement dans le navigateur après implémentation.

- [ ] **Step 1 : Implémenter la vue**

```blade
{{-- resources/views/livewire/parametres/helloasso-form.blade.php --}}
<div>
    @if (session('success'))
        <div class="alert alert-success alert-dismissible mb-4">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card" style="max-width: 640px;">
        <div class="card-body">

            {{-- 1. Choix de l'environnement en tête --}}
            <div class="mb-4">
                <p class="fw-semibold mb-2">Sur quel environnement HelloAsso voulez-vous vous connecter ?</p>
                <div class="d-flex gap-4">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" wire:model.live="environnement"
                               value="production" id="env-prod">
                        <label class="form-check-label" for="env-prod">Production</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" wire:model.live="environnement"
                               value="sandbox" id="env-sandbox">
                        <label class="form-check-label" for="env-sandbox">Sandbox</label>
                    </div>
                </div>
            </div>

            {{-- 2. Bloc d'aide dynamique selon l'environnement --}}
            @php
                $adminUrl = \App\Enums\HelloAssoEnvironnement::from($environnement)->adminUrl();
            @endphp
            <div class="alert alert-info mb-4">
                <p class="mb-2">
                    Pour connecter l'application, connectez-vous sur
                    <a href="{{ $adminUrl }}" target="_blank" rel="noopener">{{ $adminUrl }}</a>
                    avec un compte <strong>administrateur</strong> de l'association, puis&nbsp;:
                </p>
                <ol class="mb-0">
                    <li>Allez dans <strong>Tableau de bord &gt; API &gt; Mes applications</strong></li>
                    <li>Créez une nouvelle application</li>
                    <li>Copiez le <strong>Client ID</strong> et le <strong>Client Secret</strong> dans les champs ci-dessous</li>
                    <li>Le slug organisation est visible dans l'URL de votre espace&nbsp;:
                        <code>helloasso.com/associations/<em>slug</em></code></li>
                </ol>
            </div>

            {{-- 3. Champs du formulaire --}}
            <div class="mb-3">
                <label class="form-label">Client ID</label>
                <input type="text" class="form-control @error('clientId') is-invalid @enderror"
                       wire:model="clientId" autocomplete="off">
                @error('clientId') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Client Secret</label>
                <input type="password" class="form-control @error('clientSecret') is-invalid @enderror"
                       wire:model="clientSecret" autocomplete="new-password"
                       @if($secretDejaEnregistre) placeholder="••••••••  (déjà enregistré)" @endif>
                <div class="form-text text-muted">
                    Chiffré en base de données.
                    @if($secretDejaEnregistre) Laisser vide pour conserver la valeur actuelle. @endif
                </div>
                @error('clientSecret') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="mb-4">
                <label class="form-label">Slug organisation</label>
                <input type="text" class="form-control @error('organisationSlug') is-invalid @enderror"
                       wire:model="organisationSlug" placeholder="ex : association-svs">
                <div class="form-text text-muted">
                    Visible dans l'URL : helloasso.com/associations/<em>slug</em>
                </div>
                @error('organisationSlug') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            {{-- 4. Boutons --}}
            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-primary" wire:click="sauvegarder">
                    Enregistrer
                </button>
                <button type="button" class="btn btn-outline-secondary" wire:click="testerConnexion"
                        wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="testerConnexion">Tester la connexion</span>
                    <span wire:loading wire:target="testerConnexion">
                        <span class="spinner-border spinner-border-sm" role="status"></span> Test en cours…
                    </span>
                </button>
            </div>

            {{-- 5. Résultat du test --}}
            @if ($testResult !== null)
                @if ($testResult['success'])
                    <div class="alert alert-success mb-0">
                        <i class="bi bi-check-circle-fill"></i>
                        Connexion réussie — Organisation : <strong>{{ $testResult['organisationNom'] }}</strong>
                    </div>
                @else
                    <div class="alert alert-danger mb-0">
                        <i class="bi bi-x-circle-fill"></i>
                        {{ $testResult['erreur'] }}
                    </div>
                @endif
            @endif

        </div>
    </div>
</div>
```

- [ ] **Step 2 : Lancer la suite complète de tests**

```bash
./vendor/bin/sail artisan test
```

Résultat attendu : tous les tests passent.

- [ ] **Step 3 : Vérifier visuellement dans le navigateur**

- Ouvrir http://localhost/parametres/helloasso
- Vérifier que le radio "Production / Sandbox" est en tête de page
- Basculer sur Sandbox : vérifier que le lien dans le bloc d'aide change vers `admin.helloasso-sandbox.com`
- Vérifier que "Connexion HelloAsso" apparaît dans le dropdown Paramètres
- Tester la sauvegarde avec des données fictives, puis le bouton "Tester" (erreur attendue sans vrais credentials)

- [ ] **Step 4 : Commit final**

```bash
git add resources/views/livewire/parametres/helloasso-form.blade.php
git commit -m "feat(helloasso): vue formulaire avec lien admin dynamique et résultat de test"
```
