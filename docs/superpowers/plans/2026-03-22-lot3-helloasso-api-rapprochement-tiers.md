# Lot 3 — HelloAsso API Client & Rapprochement Tiers

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construire le client API HelloAsso et l'écran de rapprochement des tiers HelloAsso avec les tiers SVS.

**Architecture:** Le service `HelloAssoApiClient` encapsule l'authentification OAuth2 et les appels API paginés. Un composant Livewire `HelloassoTiersRapprochement` présente les personnes HelloAsso non liées et permet au trésorier de les associer, créer ou ignorer. La colonne `helloasso_id` (string) est convertie en `est_helloasso` (boolean).

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Pest PHP, HelloAsso API v5

**Branch:** `feat/v2-unification-helloasso` (continuer sur la même branche)

---

## Contexte

### Ce que le lot 3 construit

1. **Migration** : `helloasso_id` (string nullable unique) → `est_helloasso` (boolean default false)
2. **Service `HelloAssoApiClient`** : authentification OAuth2, appels API paginés (orders, forms)
3. **Écran de rapprochement des tiers** : pour chaque personne HelloAsso non liée, proposer des correspondances, permettre associer/créer/ignorer
4. **Marquage `est_helloasso = true`** sur les tiers associés

### API HelloAsso — structure des données

L'endpoint `GET /v5/organizations/{slug}/orders` retourne :
```json
{
  "data": [
    {
      "id": 12578,
      "date": "2025-10-15T17:27:02+01:00",
      "amount": 5000,
      "formSlug": "adhesion-2025",
      "formType": "Membership",
      "items": [
        { "id": 456789, "amount": 5000, "state": "Processed", "tierType": "Membership" }
      ],
      "user": { "firstName": "Jean", "lastName": "Dupont", "email": "jean@example.com" },
      "payer": {
        "firstName": "Jean", "lastName": "Dupont", "email": "jean@example.com",
        "address": "12 rue des Lilas", "city": "Paris", "zipCode": "75001", "country": "FRA"
      },
      "payments": [
        { "id": 159875, "amount": 5000, "paymentMeans": "Card", "cashOutState": "CashedOut" }
      ]
    }
  ],
  "pagination": { "continuationToken": "...", "pageSize": 20, "totalCount": -1 }
}
```

**Pagination** : continuer tant que `data` n'est pas vide (pas basé sur l'absence de `continuationToken`).

**Montants** : en centimes (int), conversion `/ 100` à l'import.

**Personne HelloAsso** : pas d'ID persistant. Le rapprochement avec les tiers SVS se fait par **email** (match principal) et **nom+prénom** (suggestion secondaire).

### Fichiers existants pertinents

- `app/Services/HelloAssoService.php` — service existant avec `testerConnexion()`, à enrichir
- `app/Services/HelloAssoTestResult.php` — value object résultat test
- `app/Models/HelloAssoParametres.php` — modèle paramètres (client_id, client_secret, organisation_slug, environnement)
- `app/Enums/HelloAssoEnvironnement.php` — enum Production/Sandbox avec `baseUrl()`
- `app/Models/Tiers.php` — modèle tiers avec `helloasso_id` string
- `app/Services/TiersService.php` — CRUD tiers
- `app/Livewire/Parametres/HelloassoForm.php` — formulaire paramètres existant
- `database/factories/TiersFactory.php` — factory avec état `avecHelloasso()`

---

### Task 1: Migration — `helloasso_id` → `est_helloasso`

**Files:**
- Create: `database/migrations/2026_03_22_300001_convert_helloasso_id_to_est_helloasso.php`
- Modify: `app/Models/Tiers.php`
- Modify: `database/factories/TiersFactory.php`
- Create: `tests/Feature/Lot3/MigrationEstHelloassoTest.php`

**Contexte :** La colonne `helloasso_id` (string nullable unique) stockait un identifiant HelloAsso. Comme les personnes HelloAsso n'ont pas d'ID persistant dans l'API, on la remplace par un simple booléen `est_helloasso` qui indique si le tiers est piloté par HelloAsso (données mises à jour à chaque synchro).

- [ ] **Step 1: Écrire le test**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('has est_helloasso boolean column on tiers', function () {
    expect(Schema::hasColumn('tiers', 'est_helloasso'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'helloasso_id'))->toBeFalse();
});

it('defaults est_helloasso to false', function () {
    $tiers = \App\Models\Tiers::factory()->create();
    expect($tiers->est_helloasso)->toBeFalse();
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot3/MigrationEstHelloassoTest.php --stop-on-failure`
Expected: FAIL — `helloasso_id` existe encore, `est_helloasso` n'existe pas

- [ ] **Step 3: Créer la migration**

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
        Schema::table('tiers', function (Blueprint $table) {
            $table->dropUnique(['helloasso_id']);
            $table->dropColumn('helloasso_id');
        });

        Schema::table('tiers', function (Blueprint $table) {
            $table->boolean('est_helloasso')->default(false)->after('pour_recettes');
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->dropColumn('est_helloasso');
        });

        Schema::table('tiers', function (Blueprint $table) {
            $table->string('helloasso_id', 255)->nullable()->unique()->after('date_naissance');
        });
    }
};
```

- [ ] **Step 4: Adapter le modèle Tiers**

Dans `app/Models/Tiers.php` :

1. Remplacer `'helloasso_id'` par `'est_helloasso'` dans `$fillable`
2. Ajouter `'est_helloasso' => 'boolean'` dans `casts()`

- [ ] **Step 5: Adapter la factory**

Dans `database/factories/TiersFactory.php` :

1. Dans `definition()`, remplacer toute référence à `helloasso_id` par `'est_helloasso' => false`
2. Renommer l'état `avecHelloasso()` pour retourner `['est_helloasso' => true]` au lieu d'un UUID

- [ ] **Step 6: Adapter les références résiduelles à `helloasso_id`**

Chercher dans `app/`, `resources/views/`, `tests/` (hors migrations et plans) toute mention de `helloasso_id` et les adapter. Fichiers connus à modifier :

1. `app/Livewire/TiersList.php` — `whereNotNull('helloasso_id')` → `where('est_helloasso', true)`
2. `app/Livewire/TiersForm.php` — champ `helloasso_id` → `est_helloasso` (checkbox boolean)
3. `resources/views/livewire/tiers-list.blade.php` — badge/indicateur `helloasso_id` → `est_helloasso`
4. `resources/views/livewire/tiers-form.blade.php` — champ formulaire `helloasso_id` → `est_helloasso`
5. `tests/Livewire/TiersListTest.php` — factory states et assertions `helloasso_id` → `est_helloasso`
6. `tests/Unit/Models/TiersTest.php` — assertions sur `helloasso_id` → `est_helloasso`
7. `tests/Feature/Migrations/TiersTableTest.php` — assertion de colonne `helloasso_id` → `est_helloasso`

Vérifier qu'il ne reste aucune autre occurrence via `grep -r 'helloasso_id' app/ resources/views/ tests/ --include='*.php' --include='*.blade.php' | grep -v migrations | grep -v plans`.

- [ ] **Step 7: Lancer le test**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot3/MigrationEstHelloassoTest.php --stop-on-failure`
Expected: PASS

- [ ] **Step 8: Lancer la suite complète**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --stop-on-failure`
Expected: PASS (vérifier qu'aucun test existant ne casse)

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "refactor(lot3): convert helloasso_id string to est_helloasso boolean on tiers"
```

---

### Task 2: Service `HelloAssoApiClient` — authentification et appels paginés

**Files:**
- Create: `app/Services/HelloAssoApiClient.php`
- Create: `tests/Feature/Lot3/HelloAssoApiClientTest.php`

**Contexte :** Le service existant `HelloAssoService` gère le test de connexion. On crée un nouveau service `HelloAssoApiClient` dédié aux appels API paginés, séparé pour respecter le SRP. Il encapsule :
- L'obtention d'un token OAuth2 (réutilisable pendant 30 min)
- Les appels GET paginés via `continuationToken`
- Les endpoints : orders, forms (cash-outs sera ajouté dans le Lot 5)

Le service `HelloAssoService` existant n'est pas modifié.

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Models\HelloAssoParametres;
use App\Services\HelloAssoApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->parametres = HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test-client-id',
        'client_secret' => 'test-secret',
        'organisation_slug' => 'mon-asso',
        'environnement' => 'sandbox',
    ]);
});

it('fetches orders with pagination', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'id' => 1,
                        'date' => '2025-10-15T10:00:00+02:00',
                        'amount' => 5000,
                        'formSlug' => 'adhesion',
                        'formType' => 'Membership',
                        'items' => [['id' => 101, 'amount' => 5000, 'state' => 'Processed', 'tierType' => 'Membership']],
                        'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
                        'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
                        'payments' => [['id' => 201, 'amount' => 5000, 'paymentMeans' => 'Card', 'cashOutState' => 'CashedOut']],
                    ],
                ],
                'pagination' => ['continuationToken' => 'token-page2', 'pageSize' => 20],
            ])
            ->push([
                'data' => [],
                'pagination' => ['continuationToken' => null, 'pageSize' => 20],
            ]),
    ]);

    $client = new HelloAssoApiClient($this->parametres);
    $orders = $client->fetchOrders('2025-09-01', '2026-08-31');

    expect($orders)->toHaveCount(1);
    expect($orders[0]['id'])->toBe(1);
    expect($orders[0]['user']['email'])->toBe('jean@test.com');
});

it('fetches multiple pages of orders', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push([
                'data' => [['id' => 1, 'amount' => 1000, 'items' => [], 'user' => null, 'payer' => ['firstName' => 'A', 'lastName' => 'B', 'email' => 'a@b.com'], 'payments' => []]],
                'pagination' => ['continuationToken' => 'page2'],
            ])
            ->push([
                'data' => [['id' => 2, 'amount' => 2000, 'items' => [], 'user' => null, 'payer' => ['firstName' => 'C', 'lastName' => 'D', 'email' => 'c@d.com'], 'payments' => []]],
                'pagination' => ['continuationToken' => 'page3'],
            ])
            ->push([
                'data' => [],
                'pagination' => [],
            ]),
    ]);

    $client = new HelloAssoApiClient($this->parametres);
    $orders = $client->fetchOrders('2025-09-01', '2026-08-31');

    expect($orders)->toHaveCount(2);
});

it('fetches organization forms', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/forms*' => Http::sequence()
            ->push([
                'data' => [
                    ['formSlug' => 'adhesion-2025', 'formType' => 'Membership', 'title' => 'Adhésion 2025', 'state' => 'Public'],
                    ['formSlug' => 'dons-libres', 'formType' => 'Donation', 'title' => 'Dons libres', 'state' => 'Public'],
                ],
                'pagination' => [],
            ])
            ->push(['data' => [], 'pagination' => []]),
    ]);

    $client = new HelloAssoApiClient($this->parametres);
    $forms = $client->fetchForms();

    expect($forms)->toHaveCount(2);
    expect($forms[0]['formSlug'])->toBe('adhesion-2025');
});

it('throws on authentication failure', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    $client = new HelloAssoApiClient($this->parametres);
    $client->fetchOrders('2025-09-01', '2026-08-31');
})->throws(\RuntimeException::class, 'Authentification HelloAsso échouée');
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot3/HelloAssoApiClientTest.php --stop-on-failure`
Expected: FAIL — la classe `HelloAssoApiClient` n'existe pas

- [ ] **Step 3: Implémenter `HelloAssoApiClient`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HelloAssoParametres;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HelloAssoApiClient
{
    private string $baseUrl;

    private string $clientId;

    private string $clientSecret;

    private string $organisationSlug;

    private ?string $accessToken = null;

    public function __construct(HelloAssoParametres $parametres)
    {
        $this->baseUrl = $parametres->environnement->baseUrl();
        $this->clientId = $parametres->client_id;
        $this->clientSecret = $parametres->client_secret;
        $this->organisationSlug = $parametres->organisation_slug;
    }

    /**
     * Fetch all orders for a date range, handling pagination.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchOrders(string $from, string $to): array
    {
        $this->authenticate();

        return $this->fetchPaginated(
            "/v5/organizations/{$this->organisationSlug}/orders",
            ['from' => $from, 'to' => $to],
        );
    }

    /**
     * Fetch all forms for the organization.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchForms(): array
    {
        $this->authenticate();

        return $this->fetchPaginated(
            "/v5/organizations/{$this->organisationSlug}/forms",
        );
    }

    private function authenticate(): void
    {
        if ($this->accessToken !== null) {
            return;
        }

        try {
            $response = Http::timeout(10)->asForm()->post("{$this->baseUrl}/oauth2/token", [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Impossible de joindre HelloAsso : timeout ou erreur réseau');
        }

        if ($response->failed()) {
            throw new RuntimeException("Authentification HelloAsso échouée (HTTP {$response->status()})");
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Réponse HelloAsso inattendue : token manquant');
        }

        $this->accessToken = $token;
    }

    /**
     * @param  array<string, string>  $params
     * @return list<array<string, mixed>>
     */
    private function fetchPaginated(string $path, array $params = []): array
    {
        $all = [];
        $continuationToken = null;

        do {
            $query = array_merge($params, ['pageSize' => 100]);
            if ($continuationToken !== null) {
                $query['continuationToken'] = $continuationToken;
            }

            try {
                $response = Http::timeout(30)
                    ->withToken($this->accessToken)
                    ->get("{$this->baseUrl}{$path}", $query);
            } catch (ConnectionException) {
                throw new RuntimeException("Erreur réseau lors de l'appel à {$path}");
            }

            if ($response->failed()) {
                throw new RuntimeException("Erreur API HelloAsso {$path} (HTTP {$response->status()})");
            }

            $data = $response->json('data', []);
            if (empty($data)) {
                break;
            }

            array_push($all, ...$data);

            $continuationToken = $response->json('pagination.continuationToken');
        } while (true);

        return $all;
    }
}
```

- [ ] **Step 4: Lancer les tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot3/HelloAssoApiClientTest.php --stop-on-failure`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(lot3): add HelloAssoApiClient service with OAuth2 and paginated API calls"
```

---

### Task 3: Service `HelloAssoTiersResolver` — extraction et rapprochement des personnes

**Files:**
- Create: `app/Services/HelloAssoTiersResolver.php`
- Create: `tests/Feature/Lot3/HelloAssoTiersResolverTest.php`

**Contexte :** Ce service extrait les personnes uniques depuis les orders HelloAsso, les déduplique par email, et cherche des correspondances dans la table `tiers`. Il ne persiste rien — il retourne une structure de données utilisée par le composant Livewire de rapprochement.

Une personne HelloAsso est identifiée par le `user` de l'order (bénéficiaire). Si `user` est absent, on utilise le `payer`. Le rapprochement se fait :
1. **Match exact par email** → correspondance forte
2. **Match par nom+prénom** → suggestion (correspondance faible)
3. **Aucun match** → non lié

Un tiers avec `est_helloasso = true` et même email est considéré comme **déjà lié**.

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Services\HelloAssoTiersResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('extracts unique persons from orders by email', function () {
    $orders = [
        ['user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'], 'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com']],
        ['user' => ['firstName' => 'Marie', 'lastName' => 'Martin', 'email' => 'marie@test.com'], 'payer' => ['firstName' => 'Marie', 'lastName' => 'Martin', 'email' => 'marie@test.com']],
        ['user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'], 'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com']],
    ];

    $resolver = new HelloAssoTiersResolver();
    $persons = $resolver->extractPersons($orders);

    expect($persons)->toHaveCount(2);
    expect(collect($persons)->pluck('email')->sort()->values()->all())->toBe(['jean@test.com', 'marie@test.com']);
});

it('uses payer when user is null', function () {
    $orders = [
        ['user' => null, 'payer' => ['firstName' => 'Paul', 'lastName' => 'Durand', 'email' => 'paul@test.com']],
    ];

    $resolver = new HelloAssoTiersResolver();
    $persons = $resolver->extractPersons($orders);

    expect($persons)->toHaveCount(1);
    expect($persons[0]['email'])->toBe('paul@test.com');
    expect($persons[0]['firstName'])->toBe('Paul');
});

it('skips orders with no email', function () {
    $orders = [
        ['user' => ['firstName' => 'X', 'lastName' => 'Y', 'email' => ''], 'payer' => ['firstName' => 'X', 'lastName' => 'Y', 'email' => '']],
        ['user' => null, 'payer' => null],
    ];

    $resolver = new HelloAssoTiersResolver();
    $persons = $resolver->extractPersons($orders);

    expect($persons)->toHaveCount(0);
});

it('marks already linked tiers (est_helloasso + same email)', function () {
    $tiers = Tiers::factory()->create(['email' => 'jean@test.com', 'est_helloasso' => true]);

    $persons = [
        ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
    ];

    $resolver = new HelloAssoTiersResolver();
    $result = $resolver->resolve($persons);

    expect($result['linked'])->toHaveCount(1);
    expect($result['linked'][0]['tiers_id'])->toBe($tiers->id);
    expect($result['unlinked'])->toHaveCount(0);
});

it('suggests match by email for non-helloasso tiers', function () {
    $tiers = Tiers::factory()->create(['email' => 'jean@test.com', 'est_helloasso' => false, 'nom' => 'Dupont', 'prenom' => 'Jean']);

    $persons = [
        ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
    ];

    $resolver = new HelloAssoTiersResolver();
    $result = $resolver->resolve($persons);

    expect($result['unlinked'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'][0]['tiers_id'])->toBe($tiers->id);
    expect($result['unlinked'][0]['suggestions'][0]['match_type'])->toBe('email');
});

it('suggests match by name+prenom', function () {
    $tiers = Tiers::factory()->create(['email' => 'autre@test.com', 'nom' => 'Dupont', 'prenom' => 'Jean']);

    $persons = [
        ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean-nouveau@test.com'],
    ];

    $resolver = new HelloAssoTiersResolver();
    $result = $resolver->resolve($persons);

    expect($result['unlinked'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'][0]['match_type'])->toBe('nom');
});

it('returns empty suggestions when no match', function () {
    $persons = [
        ['firstName' => 'Inconnu', 'lastName' => 'Personne', 'email' => 'inconnu@test.com'],
    ];

    $resolver = new HelloAssoTiersResolver();
    $result = $resolver->resolve($persons);

    expect($result['unlinked'])->toHaveCount(1);
    expect($result['unlinked'][0]['suggestions'])->toHaveCount(0);
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot3/HelloAssoTiersResolverTest.php --stop-on-failure`
Expected: FAIL — la classe n'existe pas

- [ ] **Step 3: Implémenter `HelloAssoTiersResolver`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tiers;

final class HelloAssoTiersResolver
{
    /**
     * Extract unique persons from orders, deduplicated by email.
     * Uses user (beneficiary) if present, otherwise payer.
     *
     * @param  list<array<string, mixed>>  $orders
     * @return list<array{firstName: string, lastName: string, email: string}>
     */
    public function extractPersons(array $orders): array
    {
        $seen = [];

        foreach ($orders as $order) {
            $person = $order['user'] ?? $order['payer'] ?? null;
            if ($person === null) {
                continue;
            }

            $email = strtolower(trim($person['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            if (! isset($seen[$email])) {
                $seen[$email] = [
                    'firstName' => $person['firstName'] ?? '',
                    'lastName' => $person['lastName'] ?? '',
                    'email' => $email,
                ];
            }
        }

        return array_values($seen);
    }

    /**
     * Resolve persons against SVS Tiers database.
     *
     * @param  list<array{firstName: string, lastName: string, email: string}>  $persons
     * @return array{linked: list<array>, unlinked: list<array>}
     */
    public function resolve(array $persons): array
    {
        $linked = [];
        $unlinked = [];

        foreach ($persons as $person) {
            // Check if already linked (est_helloasso + same email)
            $existingLinked = Tiers::where('email', $person['email'])
                ->where('est_helloasso', true)
                ->first();

            if ($existingLinked) {
                $linked[] = [
                    'email' => $person['email'],
                    'firstName' => $person['firstName'],
                    'lastName' => $person['lastName'],
                    'tiers_id' => $existingLinked->id,
                    'tiers_name' => $existingLinked->displayName(),
                ];

                continue;
            }

            // Find suggestions
            $suggestions = [];

            // Match by email (strong match)
            $emailMatch = Tiers::where('email', $person['email'])->first();
            if ($emailMatch) {
                $suggestions[] = [
                    'tiers_id' => $emailMatch->id,
                    'tiers_name' => $emailMatch->displayName(),
                    'match_type' => 'email',
                ];
            }

            // Match by name+prenom case-insensitive (weak match) — only if not already suggested
            $suggestedIds = collect($suggestions)->pluck('tiers_id')->all();
            $nameMatches = Tiers::whereRaw('LOWER(nom) = ?', [strtolower($person['lastName'])])
                ->whereRaw('LOWER(prenom) = ?', [strtolower($person['firstName'])])
                ->whereNotIn('id', $suggestedIds)
                ->get();

            foreach ($nameMatches as $match) {
                $suggestions[] = [
                    'tiers_id' => $match->id,
                    'tiers_name' => $match->displayName(),
                    'match_type' => 'nom',
                ];
            }

            $unlinked[] = [
                'email' => $person['email'],
                'firstName' => $person['firstName'],
                'lastName' => $person['lastName'],
                'suggestions' => $suggestions,
            ];
        }

        return ['linked' => $linked, 'unlinked' => $unlinked];
    }
}
```

- [ ] **Step 4: Lancer les tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot3/HelloAssoTiersResolverTest.php --stop-on-failure`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(lot3): add HelloAssoTiersResolver — extract persons and match to SVS tiers"
```

---

### Task 4: Composant Livewire — Écran de rapprochement des tiers

**Files:**
- Create: `app/Livewire/Parametres/HelloassoTiersRapprochement.php`
- Create: `resources/views/livewire/parametres/helloasso-tiers-rapprochement.blade.php`
- Create: `tests/Feature/Lot3/HelloassoTiersRapprochementTest.php`

**Contexte :** Ce composant est intégré dans la page `parametres/helloasso`. Il se déclenche quand le trésorier clique "Récupérer les tiers HelloAsso". Le composant :
1. Appelle l'API HelloAsso pour récupérer les orders de l'exercice sélectionné
2. Extrait les personnes uniques
3. Résout les correspondances avec les tiers SVS
4. Affiche la liste des personnes non liées avec les suggestions
5. Permet les actions : associer, créer, ignorer

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Livewire\Parametres\HelloassoTiersRapprochement;
use App\Models\HelloAssoParametres;
use App\Models\Tiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test-id',
        'client_secret' => 'test-secret',
        'organisation_slug' => 'mon-asso',
        'environnement' => 'sandbox',
    ]);
});

function fakeHelloAssoOrders(array $orders = []): void
{
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push(['data' => $orders, 'pagination' => ['continuationToken' => 'next']])
            ->push(['data' => [], 'pagination' => []]),
    ]);
}

it('renders the component', function () {
    Livewire::test(HelloassoTiersRapprochement::class)
        ->assertStatus(200);
});

it('fetches and displays unlinked persons', function () {
    fakeHelloAssoOrders([
        [
            'id' => 1, 'amount' => 5000,
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'items' => [], 'payments' => [],
        ],
    ]);

    Livewire::test(HelloassoTiersRapprochement::class)
        ->call('fetchTiers')
        ->assertSee('Jean')
        ->assertSee('Dupont')
        ->assertSee('jean@test.com');
});

it('associates a person to an existing tiers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => 'jean-ancien@test.com']);

    fakeHelloAssoOrders([
        [
            'id' => 1, 'amount' => 5000,
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'items' => [], 'payments' => [],
        ],
    ]);

    Livewire::test(HelloassoTiersRapprochement::class)
        ->call('fetchTiers')
        ->call('associer', 'jean@test.com', $tiers->id);

    $tiers->refresh();
    expect($tiers->est_helloasso)->toBeTrue();
    expect($tiers->email)->toBe('jean@test.com');
});

it('creates a new tiers from HelloAsso person', function () {
    fakeHelloAssoOrders([
        [
            'id' => 1, 'amount' => 5000,
            'user' => ['firstName' => 'Marie', 'lastName' => 'Martin', 'email' => 'marie@test.com'],
            'payer' => ['firstName' => 'Marie', 'lastName' => 'Martin', 'email' => 'marie@test.com', 'address' => '5 rue A', 'city' => 'Lyon', 'zipCode' => '69001', 'country' => 'FRA'],
            'items' => [], 'payments' => [],
        ],
    ]);

    Livewire::test(HelloassoTiersRapprochement::class)
        ->call('fetchTiers')
        ->call('creer', 'marie@test.com');

    $tiers = Tiers::where('email', 'marie@test.com')->first();
    expect($tiers)->not->toBeNull();
    expect($tiers->nom)->toBe('Martin');
    expect($tiers->prenom)->toBe('Marie');
    expect($tiers->est_helloasso)->toBeTrue();
    expect($tiers->pour_recettes)->toBeTrue();
});

it('ignores a person', function () {
    fakeHelloAssoOrders([
        [
            'id' => 1, 'amount' => 5000,
            'user' => ['firstName' => 'Paul', 'lastName' => 'Durand', 'email' => 'paul@test.com'],
            'payer' => ['firstName' => 'Paul', 'lastName' => 'Durand', 'email' => 'paul@test.com'],
            'items' => [], 'payments' => [],
        ],
    ]);

    Livewire::test(HelloassoTiersRapprochement::class)
        ->call('fetchTiers')
        ->call('ignorer', 'paul@test.com')
        ->assertDontSee('paul@test.com');
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot3/HelloassoTiersRapprochementTest.php --stop-on-failure`
Expected: FAIL — le composant n'existe pas

- [ ] **Step 3: Implémenter le composant Livewire**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Models\HelloAssoParametres;
use App\Models\Tiers;
use App\Services\ExerciceService;
use App\Services\HelloAssoApiClient;
use App\Services\HelloAssoTiersResolver;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoTiersRapprochement extends Component
{
    public int $exercice;

    /** @var list<array> */
    public array $linked = [];

    /** @var list<array> */
    public array $unlinked = [];

    /** @var array<string, array> Données payer indexées par email, pour la création */
    public array $payerData = [];

    public bool $fetched = false;

    public ?string $erreur = null;

    public function mount(): void
    {
        $this->exercice = app(ExerciceService::class)->current();
    }

    public function fetchTiers(): void
    {
        $this->erreur = null;
        $this->fetched = false;

        $parametres = HelloAssoParametres::where('association_id', 1)->first();
        if ($parametres === null || $parametres->client_id === null) {
            $this->erreur = 'Paramètres HelloAsso non configurés.';

            return;
        }

        try {
            $client = new HelloAssoApiClient($parametres);

            $exerciceService = app(ExerciceService::class);
            $range = $exerciceService->dateRange($this->exercice);
            $from = $range['start']->toDateString();
            $to = $range['end']->toDateString();

            $orders = $client->fetchOrders($from, $to);
        } catch (\RuntimeException $e) {
            $this->erreur = $e->getMessage();

            return;
        }

        $resolver = new HelloAssoTiersResolver();
        $persons = $resolver->extractPersons($orders);
        $result = $resolver->resolve($persons);

        $this->linked = $result['linked'];
        $this->unlinked = $result['unlinked'];
        $this->fetched = true;

        // Store payer data for creation (address info)
        $this->payerData = [];
        foreach ($orders as $order) {
            $payer = $order['payer'] ?? null;
            if ($payer && ! empty($payer['email'])) {
                $email = strtolower(trim($payer['email']));
                if (! isset($this->payerData[$email])) {
                    $this->payerData[$email] = $payer;
                }
            }
        }
    }

    public function associer(string $email, int $tiersId): void
    {
        $tiers = Tiers::findOrFail($tiersId);
        $tiers->update([
            'est_helloasso' => true,
            'email' => $email,
        ]);

        // Capture person data BEFORE removing from unlinked
        $person = collect($this->unlinked)->firstWhere('email', $email);

        // Move from unlinked to linked
        $this->unlinked = collect($this->unlinked)
            ->reject(fn (array $p) => $p['email'] === $email)
            ->values()
            ->all();

        $this->linked[] = [
            'email' => $email,
            'firstName' => $person['firstName'] ?? '',
            'lastName' => $person['lastName'] ?? '',
            'tiers_id' => $tiers->id,
            'tiers_name' => $tiers->displayName(),
        ];
    }

    public function creer(string $email): void
    {
        $person = collect($this->unlinked)->firstWhere('email', $email);
        if ($person === null) {
            return;
        }

        $payer = $this->payerData[$email] ?? [];

        $tiers = Tiers::create([
            'type' => 'particulier',
            'nom' => $person['lastName'],
            'prenom' => $person['firstName'],
            'email' => $email,
            'adresse_ligne1' => $payer['address'] ?? null,
            'ville' => $payer['city'] ?? null,
            'code_postal' => $payer['zipCode'] ?? null,
            'pays' => $payer['country'] ?? null,
            'est_helloasso' => true,
            'pour_recettes' => true,
        ]);

        $this->unlinked = collect($this->unlinked)
            ->reject(fn (array $p) => $p['email'] === $email)
            ->values()
            ->all();

        $this->linked[] = [
            'email' => $email,
            'firstName' => $person['firstName'],
            'lastName' => $person['lastName'],
            'tiers_id' => $tiers->id,
            'tiers_name' => $tiers->displayName(),
        ];
    }

    public function ignorer(string $email): void
    {
        $this->unlinked = collect($this->unlinked)
            ->reject(fn (array $p) => $p['email'] === $email)
            ->values()
            ->all();
    }

    public function render(): View
    {
        $exercices = app(ExerciceService::class)->available(5);

        return view('livewire.parametres.helloasso-tiers-rapprochement', [
            'exercices' => $exercices,
        ]);
    }
}
```

- [ ] **Step 4: Créer la vue Blade**

Créer `resources/views/livewire/parametres/helloasso-tiers-rapprochement.blade.php` :

```blade
<div>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-people me-1"></i> Rapprochement des tiers HelloAsso</h5>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3 align-items-end">
                <div class="col-auto">
                    <label class="form-label">Exercice</label>
                    <select wire:model="exercice" class="form-select form-select-sm">
                        @foreach($exercices as $ex)
                            <option value="{{ $ex }}">{{ $ex }}/{{ $ex + 1 }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button wire:click="fetchTiers" class="btn btn-sm btn-primary" wire:loading.attr="disabled">
                        <span wire:loading wire:target="fetchTiers" class="spinner-border spinner-border-sm me-1"></span>
                        <i class="bi bi-cloud-download me-1" wire:loading.remove wire:target="fetchTiers"></i>
                        Récupérer les tiers HelloAsso
                    </button>
                </div>
            </div>

            @if($erreur)
                <div class="alert alert-danger">{{ $erreur }}</div>
            @endif

            @if($fetched)
                {{-- Summary --}}
                <div class="alert alert-info">
                    <strong>{{ count($linked) }}</strong> tiers déjà liés,
                    <strong>{{ count($unlinked) }}</strong> tiers à rapprocher
                </div>

                {{-- Unlinked persons --}}
                @if(count($unlinked) > 0)
                    <h6 class="mt-3">Tiers à rapprocher</h6>
                    <table class="table table-sm table-hover">
                        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                            <tr>
                                <th>Personne HelloAsso</th>
                                <th>Email</th>
                                <th>Correspondance suggérée</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($unlinked as $person)
                                <tr wire:key="unlinked-{{ $person['email'] }}">
                                    <td class="small">{{ $person['firstName'] }} {{ $person['lastName'] }}</td>
                                    <td class="small text-muted">{{ $person['email'] }}</td>
                                    <td>
                                        @if(count($person['suggestions']) > 0)
                                            @foreach($person['suggestions'] as $sug)
                                                <div class="d-flex align-items-center gap-2 mb-1">
                                                    <span class="badge text-bg-{{ $sug['match_type'] === 'email' ? 'success' : 'warning' }}">
                                                        {{ $sug['match_type'] === 'email' ? 'Email' : 'Nom' }}
                                                    </span>
                                                    <span class="small">{{ $sug['tiers_name'] }}</span>
                                                    <button wire:click="associer('{{ $person['email'] }}', {{ $sug['tiers_id'] }})"
                                                            class="btn btn-sm btn-outline-success py-0 px-1">
                                                        <i class="bi bi-link-45deg"></i> Associer
                                                    </button>
                                                </div>
                                            @endforeach
                                        @else
                                            <span class="text-muted small">Aucune correspondance</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <button wire:click="creer('{{ $person['email'] }}')"
                                                    class="btn btn-sm btn-outline-primary py-0 px-1"
                                                    title="Créer un nouveau tiers">
                                                <i class="bi bi-person-plus"></i> Créer
                                            </button>
                                            <button wire:click="ignorer('{{ $person['email'] }}')"
                                                    class="btn btn-sm btn-outline-secondary py-0 px-1"
                                                    title="Ignorer cette personne">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @elseif(count($linked) > 0)
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle me-1"></i>
                        Tous les tiers HelloAsso sont rapprochés.
                    </div>
                @else
                    <div class="alert alert-warning">
                        Aucune commande trouvée sur cet exercice.
                    </div>
                @endif

                {{-- Linked persons --}}
                @if(count($linked) > 0)
                    <details class="mt-3">
                        <summary class="fw-semibold small text-muted">{{ count($linked) }} tiers déjà liés</summary>
                        <table class="table table-sm mt-2">
                            <thead>
                                <tr>
                                    <th class="small">Personne HelloAsso</th>
                                    <th class="small">Tiers SVS</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($linked as $l)
                                    <tr>
                                        <td class="small">{{ $l['firstName'] }} {{ $l['lastName'] }} ({{ $l['email'] }})</td>
                                        <td class="small">{{ $l['tiers_name'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </details>
                @endif
            @endif
        </div>
    </div>
</div>
```

- [ ] **Step 5: Lancer les tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot3/HelloassoTiersRapprochementTest.php --stop-on-failure`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(lot3): add HelloAsso tiers reconciliation Livewire component"
```

---

### Task 5: Intégrer le composant dans la page Paramètres HelloAsso

**Files:**
- Modify: `resources/views/parametres/helloasso.blade.php`

**Contexte :** Le composant `helloasso-tiers-rapprochement` doit être affiché sous le formulaire de paramétrage existant dans la page `parametres.helloasso`.

- [ ] **Step 1: Lire la vue actuelle**

Lire `resources/views/parametres/helloasso.blade.php` pour comprendre la structure.

- [ ] **Step 2: Ajouter le composant**

Ajouter `<livewire:parametres.helloasso-tiers-rapprochement />` après le composant `helloasso-form` existant :

```blade
<x-app-layout>
    <div class="container py-3">
        <livewire:parametres.helloasso-form />
        <livewire:parametres.helloasso-tiers-rapprochement />
    </div>
</x-app-layout>
```

Note : le composant de rapprochement est autonome — il a son propre select d'exercice et son propre bouton de fetch. Il ne dépend pas du composant formulaire.

- [ ] **Step 3: Vérifier l'affichage**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --stop-on-failure`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat(lot3): integrate tiers reconciliation component in HelloAsso settings page"
```

---

### Task 6: Indicateur HelloAsso sur la liste des tiers

**Files:**
- Modify: `resources/views/livewire/tiers-list.blade.php`

**Contexte :** La liste des tiers doit afficher un indicateur visuel pour les tiers marqués `est_helloasso = true`. Un petit badge ou icône suffit pour distinguer les tiers pilotés par HelloAsso.

- [ ] **Step 1: Lire la vue tiers-list**

Lire `resources/views/livewire/tiers-list.blade.php` pour trouver où ajouter l'indicateur, probablement après le displayName.

- [ ] **Step 2: Ajouter l'indicateur**

À côté du nom du tiers, ajouter un badge conditionnel :

```blade
@if($tiers->est_helloasso)
    <span class="badge text-bg-info ms-1" style="font-size:.6rem" title="Synchronisé depuis HelloAsso">HA</span>
@endif
```

- [ ] **Step 3: Ajouter le filtre HelloAsso dans la liste**

Si la liste a un filtre HelloAsso existant (checkbox `helloasso_only` ou similaire), adapter pour utiliser `est_helloasso` au lieu de `helloasso_id IS NOT NULL`.

- [ ] **Step 4: Lancer les tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --stop-on-failure`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(lot3): add HelloAsso badge indicator on tiers list"
```

---

### Task 7: Vérification finale + Pint + suite complète

**Files:** Aucun nouveau fichier

- [ ] **Step 1: Chercher les références résiduelles à `helloasso_id`**

Chercher dans `app/`, `resources/views/`, `tests/` (hors migrations et plans) toute mention de `helloasso_id` qui n'aurait pas été adaptée.

- [ ] **Step 2: Lancer Pint**

Run: `./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint`

- [ ] **Step 3: Lancer migrate:fresh --seed**

Run: `./vendor/bin/sail exec -T laravel.test php artisan migrate:fresh --seed`
Expected: Succès sans erreur.

- [ ] **Step 4: Lancer la suite de tests complète**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test`
Expected: Tous les tests passent.

- [ ] **Step 5: Commit si Pint a modifié des fichiers**

```bash
git add -A
git commit -m "style(lot3): pint formatting"
```

---

## Notes pour l'implémenteur

1. **Ordre des tâches** : La Task 1 (migration) doit être faite en premier car les tests des autres tasks dépendent du champ `est_helloasso`. Les Tasks 2 et 3 sont indépendantes. La Task 4 dépend de 2 et 3. Les Tasks 5-6 dépendent de 4.

2. **API HelloAsso** : Les tests utilisent `Http::fake()` pour simuler l'API. Aucun appel réel n'est fait pendant les tests.

3. **Payer vs User** : Le `user` est le bénéficiaire, le `payer` est le payeur. Pour le rapprochement tiers, on utilise le `user` (bénéficiaire). Pour l'adresse lors de la création d'un tiers, on utilise le `payer` (qui a l'adresse complète).

4. **Case-insensitive email** : Le matching email est en minuscules (`strtolower`). La création de tiers stocke l'email en minuscules.

5. **Exercice** : Le composant utilise `ExerciceService::dateRange()` pour calculer les dates from/to de l'exercice (1er sept → 31 août).

6. **Sécurité** : Le `HelloAssoApiClient` ne stocke pas le token — il le garde en mémoire pour la durée de l'instance. Le secret OAuth2 est chiffré en base via le cast `encrypted` du modèle `HelloAssoParametres`.

7. **`est_helloasso` n'est pas `#[Locked]`** : C'est un champ modifiable par l'utilisateur dans le formulaire tiers (pour dissocier manuellement un tiers de HelloAsso si besoin).
