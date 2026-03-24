# HelloAsso Callback Léger — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enregistrer les notifications webhook HelloAsso et afficher un bandeau invitant à relancer la synchronisation.

**Architecture:** Route API sans auth recevant les webhooks HelloAsso, table `helloasso_notifications` pour stocker les événements, composant Livewire bandeau global dans le layout, purge au lancement de la synchro. Token secret dans l'URL pour sécuriser le endpoint.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Pest PHP

---

### Task 1: Migration — table `helloasso_notifications`

**Files:**
- Create: `database/migrations/2026_03_24_100001_create_helloasso_notifications_table.php`

- [ ] **Step 1: Créer la migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helloasso_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('libelle');
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helloasso_notifications');
    }
};
```

- [ ] **Step 2: Exécuter la migration**

Run: `./vendor/bin/sail artisan migrate`
Expected: table `helloasso_notifications` créée

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_03_24_100001_create_helloasso_notifications_table.php
git commit -m "feat: migration table helloasso_notifications"
```

---

### Task 2: Migration — colonne `callback_token` sur `helloasso_parametres`

**Files:**
- Create: `database/migrations/2026_03_24_100002_add_callback_token_to_helloasso_parametres.php`

- [ ] **Step 1: Créer la migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helloasso_parametres', function (Blueprint $table) {
            $table->string('callback_token', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('helloasso_parametres', function (Blueprint $table) {
            $table->dropColumn('callback_token');
        });
    }
};
```

- [ ] **Step 2: Exécuter la migration**

Run: `./vendor/bin/sail artisan migrate`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_03_24_100002_add_callback_token_to_helloasso_parametres.php
git commit -m "feat: migration callback_token sur helloasso_parametres"
```

---

### Task 3: Modèle `HelloAssoNotification`

**Files:**
- Create: `app/Models/HelloAssoNotification.php`
- Modify: `app/Models/HelloAssoParametres.php` (ajouter `callback_token` aux fillable)

- [ ] **Step 1: Créer le modèle**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HelloAssoNotification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'association_id',
        'event_type',
        'libelle',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'association_id' => 'integer',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }
}
```

- [ ] **Step 2: Ajouter `callback_token` aux fillable de `HelloAssoParametres`**

Dans `app/Models/HelloAssoParametres.php`, ajouter `'callback_token'` au tableau `$fillable`.

- [ ] **Step 3: Commit**

```bash
git add app/Models/HelloAssoNotification.php app/Models/HelloAssoParametres.php
git commit -m "feat: modèle HelloAssoNotification + callback_token fillable"
```

---

### Task 4: Route API + Controller callback

**Files:**
- Modify: `bootstrap/app.php` (ajouter routage API)
- Create: `routes/api.php`
- Create: `app/Http/Controllers/HelloAssoCallbackController.php`

- [ ] **Step 1: Activer le routage API dans `bootstrap/app.php`**

Ajouter `api: __DIR__.'/../routes/api.php',` dans le bloc `withRouting()` :

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

- [ ] **Step 2: Créer `routes/api.php`**

```php
<?php

use App\Http\Controllers\HelloAssoCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/helloasso/callback/{token}', HelloAssoCallbackController::class)
    ->name('api.helloasso.callback');
```

- [ ] **Step 3: Créer le controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HelloAssoNotification;
use App\Models\HelloAssoParametres;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HelloAssoCallbackController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $parametres = HelloAssoParametres::where('callback_token', $token)->first();

        if ($parametres === null) {
            return response()->json(['error' => 'Invalid token'], 403);
        }

        $payload = $request->all();
        $eventType = $payload['eventType'] ?? 'unknown';
        $libelle = self::buildLibelle($eventType, $payload['data'] ?? []);

        HelloAssoNotification::create([
            'association_id' => $parametres->association_id,
            'event_type' => $eventType,
            'libelle' => $libelle,
            'payload' => $payload,
        ]);

        return response()->json(['status' => 'ok']);
    }

    private static function buildLibelle(string $eventType, array $data): string
    {
        $name = trim(($data['payer']['firstName'] ?? $data['name'] ?? '') . ' ' . ($data['payer']['lastName'] ?? ''));

        $formType = $data['formType'] ?? '';
        $formName = $data['formName'] ?? $data['formSlug'] ?? '';

        $prefix = match (true) {
            str_contains(strtolower($eventType), 'order') && strtolower($formType) === 'membership' => 'Nouvelle cotisation',
            str_contains(strtolower($eventType), 'order') && strtolower($formType) === 'donation' => 'Nouveau don',
            str_contains(strtolower($eventType), 'order') && strtolower($formType) === 'event' => 'Nouvelle inscription',
            str_contains(strtolower($eventType), 'order') => 'Nouvelle commande',
            str_contains(strtolower($eventType), 'payment') => 'Nouveau paiement',
            str_contains(strtolower($eventType), 'form') => 'Modification formulaire',
            default => 'Notification HelloAsso',
        };

        $parts = [$prefix];
        if ($name !== '') {
            $parts[] = "de {$name}";
        }
        if ($formName !== '' && ! str_contains(strtolower($eventType), 'form')) {
            $parts[] = "({$formName})";
        }

        return implode(' ', $parts);
    }
}
```

- [ ] **Step 4: Vérifier que la route est enregistrée**

Run: `./vendor/bin/sail artisan route:list --path=helloasso/callback`
Expected: `POST api/helloasso/callback/{token}`

- [ ] **Step 5: Commit**

```bash
git add bootstrap/app.php routes/api.php app/Http/Controllers/HelloAssoCallbackController.php
git commit -m "feat: endpoint API callback HelloAsso avec token"
```

---

### Task 5: Génération du token dans `HelloassoForm`

**Files:**
- Modify: `app/Livewire/Parametres/HelloassoForm.php` (générer token au save)

- [ ] **Step 1: Modifier `sauvegarder()` pour générer le token si absent**

Dans la méthode `sauvegarder()` de `app/Livewire/Parametres/HelloassoForm.php`, après le `updateOrCreate`, ajouter la génération du token :

L'appel `updateOrCreate` existant (ligne 61) ne capture pas le retour. Modifier :

```php
// AVANT (ligne 61-64) :
HelloAssoParametres::updateOrCreate(
    ['association_id' => 1],
    $payload,
);

// APRÈS :
$parametres = HelloAssoParametres::updateOrCreate(
    ['association_id' => 1],
    $payload,
);

// Générer le token callback si absent
if ($parametres->callback_token === null) {
    $token = bin2hex(random_bytes(32));
    $parametres->update(['callback_token' => $token]);
    $this->callbackToken = $token;
} else {
    $this->callbackToken = $parametres->callback_token;
}
```

- [ ] **Step 2: Ajouter une méthode `regenererToken()`**

```php
public function regenererToken(): void
{
    $p = HelloAssoParametres::where('association_id', 1)->first();
    if ($p !== null) {
        $token = bin2hex(random_bytes(32));
        $p->update(['callback_token' => $token]);
        $this->callbackToken = $token;
    }
}
```

- [ ] **Step 3: Ajouter une propriété calculée `callbackUrl`**

Ajouter dans `mount()` le chargement du token, et une méthode `getCallbackUrl()` :

```php
public ?string $callbackToken = null;

// Dans mount(), après le chargement existant :
$this->callbackToken = $p->callback_token;

// Méthode publique :
public function getCallbackUrl(): ?string
{
    if ($this->callbackToken === null) {
        return null;
    }
    return url("/api/helloasso/callback/{$this->callbackToken}");
}
```

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/Parametres/HelloassoForm.php
git commit -m "feat: génération et régénération du callback token"
```

---

### Task 6: UI callback sur l'écran paramètres HelloAsso

**Files:**
- Modify: `resources/views/livewire/parametres/helloasso-form.blade.php`

- [ ] **Step 1: Ajouter le bloc callback URL après la card existante**

Après la `</div>` fermante de la card connexion (ligne 116), ajouter :

```blade
@php $callbackUrl = $this->getCallbackUrl(); @endphp
@if ($callbackUrl)
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-bell me-1"></i> Notification de callback</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-info mb-3">
            <p class="mb-1">Pour recevoir les notifications HelloAsso en temps réel, copiez l'URL ci-dessous
            et collez-la dans votre espace HelloAsso :</p>
            <ol class="mb-0">
                <li>Connectez-vous sur <strong>admin.helloasso.com</strong></li>
                <li>Allez dans <strong>Paramètres API → Notifications</strong></li>
                <li>Collez l'URL dans le champ <strong>« Mon URL de callback »</strong></li>
            </ol>
        </div>

        <div class="input-group mb-3">
            <input type="text" class="form-control font-monospace" value="{{ $callbackUrl }}" readonly
                   id="callback-url-input">
            <button class="btn btn-outline-secondary" type="button"
                    onclick="navigator.clipboard.writeText(document.getElementById('callback-url-input').value).then(() => { this.innerHTML = '<i class=\'bi bi-check2\'></i> Copié'; setTimeout(() => this.innerHTML = '<i class=\'bi bi-clipboard\'></i> Copier', 2000) })">
                <i class="bi bi-clipboard"></i> Copier
            </button>
        </div>

        <button type="button" class="btn btn-outline-warning btn-sm"
                wire:click="regenererToken"
                wire:confirm="Attention : si vous régénérez le token, l'ancienne URL ne fonctionnera plus. Vous devrez mettre à jour l'URL sur HelloAsso. Continuer ?">
            <i class="bi bi-arrow-repeat"></i> Régénérer le token
        </button>
    </div>
</div>
@endif
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/parametres/helloasso-form.blade.php
git commit -m "feat: affichage URL callback sur écran paramètres HelloAsso"
```

---

### Task 7: Composant Livewire bandeau notifications

**Files:**
- Create: `app/Livewire/HelloassoNotificationBanner.php`
- Create: `resources/views/livewire/helloasso-notification-banner.blade.php`

- [ ] **Step 1: Créer le composant Livewire**

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\HelloAssoNotification;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoNotificationBanner extends Component
{
    public bool $showDetails = false;

    public function toggleDetails(): void
    {
        $this->showDetails = ! $this->showDetails;
    }

    public function render(): View
    {
        $notifications = HelloAssoNotification::where('association_id', 1)
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.helloasso-notification-banner', [
            'notifications' => $notifications,
            'count' => $notifications->count(),
        ]);
    }
}
```

- [ ] **Step 2: Créer la vue Blade**

```blade
{{-- resources/views/livewire/helloasso-notification-banner.blade.php --}}
<div>
    @if ($count > 0)
    <div class="alert alert-warning mb-0 rounded-0 border-start-0 border-end-0 py-2 px-4"
         style="border-top: none;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong>Attention</strong>, les données HelloAsso ne sont pas à jour.
                <strong>{{ $count }} notification(s) reçue(s).</strong>
                <button type="button" class="btn btn-link btn-sm p-0 ms-1"
                        wire:click="toggleDetails">
                    {{ $showDetails ? 'Masquer les détails' : 'Voir les détails' }}
                </button>
            </div>
            <a href="{{ route('banques.helloasso-sync') }}" class="btn btn-warning btn-sm">
                <i class="bi bi-arrow-repeat"></i> Lancer la synchronisation
            </a>
        </div>

        @if ($showDetails)
        <ul class="list-unstyled mt-2 mb-0 ms-4">
            @foreach ($notifications as $notif)
            <li class="small text-muted">
                <i class="bi bi-dot"></i>
                {{ $notif->created_at->format('d/m H:i') }} — {{ $notif->libelle }}
            </li>
            @endforeach
        </ul>
        @endif
    </div>
    @endif
</div>
```

- [ ] **Step 3: Commit**

```bash
git add app/Livewire/HelloassoNotificationBanner.php resources/views/livewire/helloasso-notification-banner.blade.php
git commit -m "feat: composant Livewire bandeau notifications HelloAsso"
```

---

### Task 8: Intégrer le bandeau dans le layout

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

- [ ] **Step 1: Ajouter le composant après la navbar**

Dans `resources/views/layouts/app.blade.php`, après la balise `</nav>` fermante (ligne 347), juste avant `@endauth`, ajouter :

```blade
    <livewire:helloasso-notification-banner />
```

Le layout deviendra :
```blade
    </nav>
    <livewire:helloasso-notification-banner />
    @endauth
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat: bandeau HelloAsso dans le layout global"
```

---

### Task 9: Purge des notifications au lancement de la synchro

**Files:**
- Modify: `app/Livewire/Banques/HelloassoSyncWizard.php:85-88` (méthode `mount()`)

- [ ] **Step 1: Ajouter la purge dans `mount()`**

Dans `app/Livewire/Banques/HelloassoSyncWizard.php`, dans la méthode `mount()`, ajouter avant `$this->checkConfig()` :

```php
use App\Models\HelloAssoNotification;

// En début de mount() :
HelloAssoNotification::where('association_id', 1)->delete();
```

- [ ] **Step 2: Commit**

```bash
git add app/Livewire/Banques/HelloassoSyncWizard.php
git commit -m "feat: purge notifications HelloAsso au lancement synchro"
```

---

### Task 10: Commande artisan `helloasso:simulate-callback`

**Files:**
- Create: `app/Console/Commands/HelloAssoSimulateCallback.php`

- [ ] **Step 1: Créer la commande**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HelloAssoParametres;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class HelloAssoSimulateCallback extends Command
{
    protected $signature = 'helloasso:simulate-callback
                            {--type=Order : Type d\'événement (Order, Payment, Form)}
                            {--form-type=Membership : Type de formulaire (Membership, Donation, Event)}
                            {--name=Jean Dupont : Nom du payeur}';

    protected $description = 'Simule un appel callback HelloAsso pour tester la chaîne complète';

    public function handle(): int
    {
        $parametres = HelloAssoParametres::where('association_id', 1)->first();

        if ($parametres === null || $parametres->callback_token === null) {
            $this->error('Aucun token callback configuré. Sauvegardez d\'abord les paramètres HelloAsso.');
            return self::FAILURE;
        }

        $nameParts = explode(' ', $this->option('name'), 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? 'Test';

        $payload = [
            'eventType' => $this->option('type'),
            'data' => [
                'formType' => $this->option('form-type'),
                'formSlug' => 'formulaire-test',
                'formName' => 'Formulaire de test',
                'payer' => [
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                ],
            ],
        ];

        $url = url("/api/helloasso/callback/{$parametres->callback_token}");
        $this->info("Envoi vers : {$url}");
        $this->info('Payload : ' . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $response = Http::post($url, $payload);

        if ($response->successful()) {
            $this->info('Callback simulé avec succès (HTTP ' . $response->status() . ')');
            return self::SUCCESS;
        }

        $this->error('Erreur HTTP ' . $response->status() . ' : ' . $response->body());
        return self::FAILURE;
    }
}
```

- [ ] **Step 2: Tester la commande**

Run: `./vendor/bin/sail artisan helloasso:simulate-callback`
Expected: "Callback simulé avec succès (HTTP 200)"

Run: `./vendor/bin/sail artisan helloasso:simulate-callback --type=Order --form-type=Donation --name="Marie Martin"`
Expected: "Callback simulé avec succès (HTTP 200)"

- [ ] **Step 3: Vérifier que les notifications sont créées**

Run: `./vendor/bin/sail artisan tinker --execute="echo App\Models\HelloAssoNotification::count()"`
Expected: 2 (ou plus selon les tests précédents)

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/HelloAssoSimulateCallback.php
git commit -m "feat: commande artisan helloasso:simulate-callback"
```

---

### Task 11: Tests

**Files:**
- Create: `tests/Feature/HelloAssoCallbackTest.php`

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\HelloAssoNotification;
use App\Models\HelloAssoParametres;

beforeEach(function () {
    $association = Association::first() ?? Association::create([
        'nom' => 'Test Association',
    ]);
    $this->parametres = HelloAssoParametres::create([
        'association_id' => $association->id,
        'callback_token' => 'test-token-abc123',
        'environnement' => 'sandbox',
    ]);
});

test('callback avec token valide crée une notification', function () {
    $payload = [
        'eventType' => 'Order',
        'data' => [
            'formType' => 'Membership',
            'formSlug' => 'cotisation-2026',
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont'],
        ],
    ];

    $response = $this->postJson('/api/helloasso/callback/test-token-abc123', $payload);

    $response->assertOk();
    expect(HelloAssoNotification::count())->toBe(1);

    $notif = HelloAssoNotification::first();
    expect($notif->event_type)->toBe('Order');
    expect($notif->libelle)->toContain('cotisation');
    expect($notif->libelle)->toContain('Jean Dupont');
    expect($notif->payload)->toEqual($payload);
});

test('callback avec token invalide retourne 403', function () {
    $response = $this->postJson('/api/helloasso/callback/wrong-token', ['eventType' => 'Order']);

    $response->assertForbidden();
    expect(HelloAssoNotification::count())->toBe(0);
});

test('callback don génère le bon libellé', function () {
    $payload = [
        'eventType' => 'Order',
        'data' => [
            'formType' => 'Donation',
            'formName' => 'Dons libres',
            'payer' => ['firstName' => 'Marie', 'lastName' => 'Martin'],
        ],
    ];

    $this->postJson('/api/helloasso/callback/test-token-abc123', $payload);

    $notif = HelloAssoNotification::first();
    expect($notif->libelle)->toContain('Nouveau don');
    expect($notif->libelle)->toContain('Marie Martin');
});

test('callback sans données payeur génère un libellé sans nom', function () {
    $payload = [
        'eventType' => 'Form',
        'data' => ['formSlug' => 'mon-formulaire'],
    ];

    $this->postJson('/api/helloasso/callback/test-token-abc123', $payload);

    $notif = HelloAssoNotification::first();
    expect($notif->libelle)->toBe('Modification formulaire');
});
```

- [ ] **Step 2: Exécuter les tests**

Run: `./vendor/bin/sail artisan test --filter=HelloAssoCallback`
Expected: 4 tests pass

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/HelloAssoCallbackTest.php
git commit -m "test: tests callback HelloAsso"
```
