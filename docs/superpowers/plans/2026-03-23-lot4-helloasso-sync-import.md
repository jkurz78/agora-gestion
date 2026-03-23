# Lot 4 — Synchronisation HelloAsso : rapprochement formulaires et import

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implémenter le workflow complet d'import HelloAsso : configuration des mappings (sous-catégories, comptes, formulaires→opérations) et service d'import des orders en transactions SVS.

**Architecture:** La config de synchronisation (comptes, mappings sous-catégories) est stockée dans des colonnes ajoutées à `helloasso_parametres`. Le mapping formulaires→opérations utilise une nouvelle table `helloasso_form_mappings`. Un `HelloAssoSyncService` orchestre l'import : pour chaque order, il groupe les items par bénéficiaire, résout les sous-catégories et opérations, et fait un upsert Transaction+TransactionLignes. Un composant Livewire permet de configurer les mappings et lancer la synchronisation avec rapport.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Pest PHP, HelloAsso API v5

**Branch:** `feat/v2-unification-helloasso` (continuer sur la même branche)

---

## Contexte

### Ce que le lot 4 construit

1. **Migration** : étendre `helloasso_parametres` avec les colonnes de config sync + créer `helloasso_form_mappings`
2. **Modèle** `HelloAssoFormMapping` — mapping formulaire HelloAsso → Opération SVS
3. **Composant config** `HelloassoSyncConfig` — UI pour configurer comptes, sous-catégories, formulaires
4. **Value object** `HelloAssoSyncResult` — résultat de synchronisation
5. **Service** `HelloAssoSyncService` — import des orders en transactions
6. **Composant sync** `HelloassoSync` — lancement de la sync + affichage rapport
7. **Intégration** dans la page paramètres HelloAsso
8. **Vérification** finale

### Règles métier clés

- **1 Transaction SVS = 1 bénéficiaire unique** dans une commande HelloAsso
- Si une commande a des items pour des bénéficiaires différents → autant de Transactions
- Si une commande a plusieurs items pour le même bénéficiaire → 1 Transaction multi-lignes
- Les montants HelloAsso sont en **centimes** → diviser par 100
- Idempotence : `helloasso_item_id` (unique sur TransactionLigne), `helloasso_order_id + tiers_id` (unique sur Transaction)
- Chaque commande est traitée dans sa propre `DB::transaction()`
- Items Registration sans opération mappée → erreur bloquante (collectée dans le rapport, pas d'exception)

### Mapping des modes de paiement

| HelloAsso PaymentMeans | ModePaiement SVS |
|---|---|
| `Card` | `cb` |
| `Sepa` | `prelevement` |
| `Check` | `cheque` |
| `Cash` | `especes` |
| `BankTransfer` | `virement` |
| `Other` / `None` / défaut | `cb` |

---

## Structure des fichiers

### Fichiers à créer

| Fichier | Responsabilité |
|---|---|
| `database/migrations/2026_03_23_000001_add_sync_config_to_helloasso_parametres.php` | Ajouter colonnes config sync |
| `database/migrations/2026_03_23_000002_create_helloasso_form_mappings_table.php` | Table form→operation mapping |
| `app/Models/HelloAssoFormMapping.php` | Modèle Eloquent du mapping |
| `app/Services/HelloAssoSyncResult.php` | Value object résultat sync |
| `app/Services/HelloAssoSyncService.php` | Service d'import |
| `app/Livewire/Parametres/HelloassoSyncConfig.php` | Composant config |
| `resources/views/livewire/parametres/helloasso-sync-config.blade.php` | Vue config |
| `app/Livewire/Parametres/HelloassoSync.php` | Composant lancement sync |
| `resources/views/livewire/parametres/helloasso-sync.blade.php` | Vue sync + rapport |
| `tests/Feature/Lot4/HelloAssoSyncServiceTest.php` | Tests du service d'import |
| `tests/Feature/Lot4/HelloAssoSyncConfigTest.php` | Tests du composant config |
| `tests/Feature/Lot4/HelloAssoSyncTest.php` | Tests du composant sync |

### Fichiers à modifier

| Fichier | Modification |
|---|---|
| `app/Models/HelloAssoParametres.php` | Ajouter colonnes sync dans $fillable + relations |
| `resources/views/parametres/helloasso.blade.php` | Ajouter les 2 nouveaux composants |

---

### Task 1: Migrations — config sync + table form mappings

**Files:**
- Create: `database/migrations/2026_03_23_000001_add_sync_config_to_helloasso_parametres.php`
- Create: `database/migrations/2026_03_23_000002_create_helloasso_form_mappings_table.php`
- Modify: `app/Models/HelloAssoParametres.php`
- Create: `tests/Feature/Lot4/MigrationsLot4Test.php`

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Models\HelloAssoParametres;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('has sync config columns on helloasso_parametres', function () {
    expect(Schema::hasColumn('helloasso_parametres', 'compte_helloasso_id'))->toBeTrue();
    expect(Schema::hasColumn('helloasso_parametres', 'compte_versement_id'))->toBeTrue();
    expect(Schema::hasColumn('helloasso_parametres', 'sous_categorie_don_id'))->toBeTrue();
    expect(Schema::hasColumn('helloasso_parametres', 'sous_categorie_cotisation_id'))->toBeTrue();
    expect(Schema::hasColumn('helloasso_parametres', 'sous_categorie_inscription_id'))->toBeTrue();
});

it('has helloasso_form_mappings table', function () {
    expect(Schema::hasTable('helloasso_form_mappings'))->toBeTrue();
    expect(Schema::hasColumn('helloasso_form_mappings', 'form_slug'))->toBeTrue();
    expect(Schema::hasColumn('helloasso_form_mappings', 'form_type'))->toBeTrue();
    expect(Schema::hasColumn('helloasso_form_mappings', 'operation_id'))->toBeTrue();
});

it('can save sync config on helloasso_parametres', function () {
    \DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

    $p = HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'test',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => 1,
        'sous_categorie_don_id' => 2,
    ]);

    expect($p->compte_helloasso_id)->toBe(1);
    expect($p->sous_categorie_don_id)->toBe(2);
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot4/MigrationsLot4Test.php --stop-on-failure`
Expected: FAIL

- [ ] **Step 3: Créer la migration config sync**

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
        Schema::table('helloasso_parametres', function (Blueprint $table) {
            $table->foreignId('compte_helloasso_id')->nullable()->constrained('comptes_bancaires')->nullOnDelete()->after('environnement');
            $table->foreignId('compte_versement_id')->nullable()->constrained('comptes_bancaires')->nullOnDelete()->after('compte_helloasso_id');
            $table->foreignId('sous_categorie_don_id')->nullable()->constrained('sous_categories')->nullOnDelete()->after('compte_versement_id');
            $table->foreignId('sous_categorie_cotisation_id')->nullable()->constrained('sous_categories')->nullOnDelete()->after('sous_categorie_don_id');
            $table->foreignId('sous_categorie_inscription_id')->nullable()->constrained('sous_categories')->nullOnDelete()->after('sous_categorie_cotisation_id');
        });
    }

    public function down(): void
    {
        Schema::table('helloasso_parametres', function (Blueprint $table) {
            $table->dropForeign(['compte_helloasso_id']);
            $table->dropForeign(['compte_versement_id']);
            $table->dropForeign(['sous_categorie_don_id']);
            $table->dropForeign(['sous_categorie_cotisation_id']);
            $table->dropForeign(['sous_categorie_inscription_id']);
            $table->dropColumn([
                'compte_helloasso_id', 'compte_versement_id',
                'sous_categorie_don_id', 'sous_categorie_cotisation_id', 'sous_categorie_inscription_id',
            ]);
        });
    }
};
```

- [ ] **Step 4: Créer la migration form mappings**

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
        Schema::create('helloasso_form_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('helloasso_parametres_id')->constrained('helloasso_parametres')->cascadeOnDelete();
            $table->string('form_slug');
            $table->string('form_type');
            $table->string('form_title')->nullable();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->timestamps();

            $table->unique(['helloasso_parametres_id', 'form_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helloasso_form_mappings');
    }
};
```

- [ ] **Step 5: Mettre à jour le modèle HelloAssoParametres**

Dans `app/Models/HelloAssoParametres.php`, ajouter les colonnes au `$fillable` et les relations :

```php
protected $fillable = [
    'association_id',
    'client_id',
    'client_secret',
    'organisation_slug',
    'environnement',
    'compte_helloasso_id',
    'compte_versement_id',
    'sous_categorie_don_id',
    'sous_categorie_cotisation_id',
    'sous_categorie_inscription_id',
];

protected function casts(): array
{
    return [
        'client_secret' => 'encrypted',
        'association_id' => 'integer',
        'compte_helloasso_id' => 'integer',
        'compte_versement_id' => 'integer',
        'sous_categorie_don_id' => 'integer',
        'sous_categorie_cotisation_id' => 'integer',
        'sous_categorie_inscription_id' => 'integer',
        'environnement' => HelloAssoEnvironnement::class,
    ];
}

public function compteHelloasso(): BelongsTo
{
    return $this->belongsTo(\App\Models\CompteBancaire::class, 'compte_helloasso_id');
}

public function compteVersement(): BelongsTo
{
    return $this->belongsTo(\App\Models\CompteBancaire::class, 'compte_versement_id');
}

public function formMappings(): HasMany
{
    return $this->hasMany(\App\Models\HelloAssoFormMapping::class, 'helloasso_parametres_id');
}
```

Ajouter `use Illuminate\Database\Eloquent\Relations\HasMany;` dans les imports.

- [ ] **Step 6: Lancer les tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot4/MigrationsLot4Test.php --stop-on-failure`
Expected: PASS

- [ ] **Step 7: Lancer la suite complète**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --stop-on-failure`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(lot4): add sync config columns and helloasso_form_mappings table"
```

---

### Task 2: Modèle HelloAssoFormMapping

**Files:**
- Create: `app/Models/HelloAssoFormMapping.php`

- [ ] **Step 1: Créer le modèle**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class HelloAssoFormMapping extends Model
{
    protected $table = 'helloasso_form_mappings';

    protected $fillable = [
        'helloasso_parametres_id',
        'form_slug',
        'form_type',
        'form_title',
        'operation_id',
    ];

    protected function casts(): array
    {
        return [
            'helloasso_parametres_id' => 'integer',
            'operation_id' => 'integer',
        ];
    }

    public function parametres(): BelongsTo
    {
        return $this->belongsTo(HelloAssoParametres::class, 'helloasso_parametres_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
```

- [ ] **Step 2: Lancer les tests existants**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot4/ --stop-on-failure`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add app/Models/HelloAssoFormMapping.php
git commit -m "feat(lot4): add HelloAssoFormMapping model"
```

---

### Task 3: Value object HelloAssoSyncResult

**Files:**
- Create: `app/Services/HelloAssoSyncResult.php` (remplacer le fichier existant `HelloAssoTestResult.php` n'est pas nécessaire — c'est un nouveau VO)

Note : le fichier `app/Services/HelloAssoTestResult.php` existe déjà pour le test de connexion. Ce nouveau VO est distinct.

- [ ] **Step 1: Créer le value object**

```php
<?php

declare(strict_types=1);

namespace App\Services;

final class HelloAssoSyncResult
{
    /**
     * @param  list<string>  $errors
     */
    /**
     * Note : tiersCreated/tiersUpdated (Lot 3) et virementsCreated/virementsUpdated (Lot 5)
     * seront ajoutés quand ces lots s'intégreront avec ce service.
     */
    public function __construct(
        public readonly int $transactionsCreated = 0,
        public readonly int $transactionsUpdated = 0,
        public readonly int $lignesCreated = 0,
        public readonly int $lignesUpdated = 0,
        public readonly int $ordersSkipped = 0,
        public readonly array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function totalTransactions(): int
    {
        return $this->transactionsCreated + $this->transactionsUpdated;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/HelloAssoSyncResult.php
git commit -m "feat(lot4): add HelloAssoSyncResult value object"
```

---

### Task 4: Service HelloAssoSyncService — import des orders

**Files:**
- Create: `app/Services/HelloAssoSyncService.php`
- Create: `tests/Feature/Lot4/HelloAssoSyncServiceTest.php`

**Contexte :** C'est le cœur du Lot 4. Ce service prend les orders HelloAsso et les importe en Transactions + TransactionLignes. Il :
1. Groupe les items par bénéficiaire (user, ou payer si user absent)
2. Résout le tiers via email (`est_helloasso = true`)
3. Résout la sous-catégorie via le mapping item.type → sous_categorie_id (config dans HelloAssoParametres)
4. Résout l'opération via le mapping formSlug → operation_id (table helloasso_form_mappings)
5. Fait un upsert Transaction (match `helloasso_order_id` + `tiers_id`)
6. Fait un upsert TransactionLigne (match `helloasso_item_id`)
7. Chaque order dans sa propre `DB::transaction()`, les erreurs sont collectées

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\HelloAssoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    \DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

    $this->compte = CompteBancaire::factory()->create(['nom' => 'HelloAsso']);
    $this->scDon = SousCategorie::where('pour_dons', true)->first()
        ?? SousCategorie::factory()->create(['pour_dons' => true, 'nom' => 'Don']);
    $this->scCot = SousCategorie::where('pour_cotisations', true)->first()
        ?? SousCategorie::factory()->create(['pour_cotisations' => true, 'nom' => 'Cotisation']);

    $this->parametres = HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'test',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => $this->compte->id,
        'sous_categorie_don_id' => $this->scDon->id,
        'sous_categorie_cotisation_id' => $this->scCot->id,
    ]);

    $this->tiers = Tiers::factory()->avecHelloasso()->create([
        'email' => 'jean@test.com',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
    ]);
});

it('imports a simple donation order', function () {
    $orders = [
        [
            'id' => 100,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 5000,
            'formSlug' => 'dons-libres',
            'formType' => 'Donation',
            'items' => [
                ['id' => 1001, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don libre'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [
                ['id' => 201, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card', 'cashOutState' => 'CashedOut'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->transactionsCreated)->toBe(1);
    expect($result->lignesCreated)->toBe(1);
    expect($result->errors)->toBeEmpty();

    $tx = Transaction::where('helloasso_order_id', 100)->first();
    expect($tx)->not->toBeNull();
    expect($tx->tiers_id)->toBe($this->tiers->id);
    expect((float) $tx->montant_total)->toBe(50.00);
    expect($tx->compte_id)->toBe($this->compte->id);
    expect($tx->type->value)->toBe('recette');
    expect($tx->mode_paiement)->toBe(ModePaiement::Cb);

    $ligne = $tx->lignes()->first();
    expect($ligne->helloasso_item_id)->toBe(1001);
    expect((float) $ligne->montant)->toBe(50.00);
    expect($ligne->sous_categorie_id)->toBe($this->scDon->id);
});

it('imports a membership order with exercice', function () {
    $orders = [
        [
            'id' => 101,
            'date' => '2025-11-01T10:00:00+01:00',
            'amount' => 3000,
            'formSlug' => 'adhesion-2025',
            'formType' => 'Membership',
            'items' => [
                ['id' => 1002, 'amount' => 3000, 'state' => 'Processed', 'type' => 'Membership', 'name' => 'Adhésion'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [
                ['id' => 202, 'amount' => 3000, 'date' => '2025-11-01T10:00:00+01:00', 'paymentMeans' => 'Card'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->transactionsCreated)->toBe(1);

    $ligne = TransactionLigne::where('helloasso_item_id', 1002)->first();
    expect($ligne->sous_categorie_id)->toBe($this->scCot->id);
    expect($ligne->exercice)->toBe(2025);
});

it('groups items by beneficiary into one transaction', function () {
    $orders = [
        [
            'id' => 102,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 8000,
            'formSlug' => 'adhesion-2025',
            'formType' => 'Membership',
            'items' => [
                ['id' => 1003, 'amount' => 3000, 'state' => 'Processed', 'type' => 'Membership', 'name' => 'Adhésion'],
                ['id' => 1004, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don complémentaire'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [
                ['id' => 203, 'amount' => 8000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card'],
            ],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->transactionsCreated)->toBe(1);
    expect($result->lignesCreated)->toBe(2);

    $tx = Transaction::where('helloasso_order_id', 102)->first();
    expect((float) $tx->montant_total)->toBe(80.00);
    expect($tx->lignes)->toHaveCount(2);
});

it('skips orders with unknown tiers email', function () {
    $orders = [
        [
            'id' => 103,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 2000,
            'formSlug' => 'dons-libres',
            'formType' => 'Donation',
            'items' => [
                ['id' => 1005, 'amount' => 2000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don'],
            ],
            'user' => ['firstName' => 'Inconnu', 'lastName' => 'Personne', 'email' => 'inconnu@test.com'],
            'payer' => ['firstName' => 'Inconnu', 'lastName' => 'Personne', 'email' => 'inconnu@test.com'],
            'payments' => [['id' => 204, 'amount' => 2000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->transactionsCreated)->toBe(0);
    expect($result->ordersSkipped)->toBe(1);
    expect($result->errors)->toHaveCount(1);
    expect($result->errors[0])->toContain('inconnu@test.com');
});

it('is idempotent — re-importing same order updates instead of duplicating', function () {
    $orders = [
        [
            'id' => 104,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 5000,
            'formSlug' => 'dons-libres',
            'formType' => 'Donation',
            'items' => [
                ['id' => 1006, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [['id' => 205, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result1 = $service->synchroniser($orders, 2025);
    expect($result1->transactionsCreated)->toBe(1);

    $result2 = $service->synchroniser($orders, 2025);
    expect($result2->transactionsCreated)->toBe(0);
    expect($result2->transactionsUpdated)->toBe(1);

    expect(Transaction::where('helloasso_order_id', 104)->count())->toBe(1);
});

it('resolves operation from form mapping for Registration items', function () {
    $scInscr = SousCategorie::factory()->create(['pour_inscriptions' => true, 'nom' => 'Inscription']);
    $this->parametres->update(['sous_categorie_inscription_id' => $scInscr->id]);

    $operation = Operation::factory()->create(['nom' => 'Stage été 2026']);
    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'stage-ete-2026',
        'form_type' => 'Event',
        'form_title' => 'Stage été 2026',
        'operation_id' => $operation->id,
    ]);

    $orders = [
        [
            'id' => 105,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 15000,
            'formSlug' => 'stage-ete-2026',
            'formType' => 'Event',
            'items' => [
                ['id' => 1007, 'amount' => 15000, 'state' => 'Processed', 'type' => 'Registration', 'name' => 'Stage'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [['id' => 206, 'amount' => 15000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->transactionsCreated)->toBe(1);
    $ligne = TransactionLigne::where('helloasso_item_id', 1007)->first();
    expect($ligne->sous_categorie_id)->toBe($scInscr->id);
    expect($ligne->operation_id)->toBe($operation->id);
});

it('reports error for Registration item without mapped operation', function () {
    $scInscr = SousCategorie::factory()->create(['pour_inscriptions' => true, 'nom' => 'Inscription']);
    $this->parametres->update(['sous_categorie_inscription_id' => $scInscr->id]);
    // No form mapping created

    $orders = [
        [
            'id' => 106,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 10000,
            'formSlug' => 'stage-non-mappe',
            'formType' => 'Event',
            'items' => [
                ['id' => 1008, 'amount' => 10000, 'state' => 'Processed', 'type' => 'Registration', 'name' => 'Stage'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [['id' => 207, 'amount' => 10000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->errors)->toHaveCount(1);
    expect($result->errors[0])->toContain('stage-non-mappe');
    expect(Transaction::where('helloasso_order_id', 106)->count())->toBe(0);
});

it('maps payment means correctly', function () {
    $orders = [
        [
            'id' => 107,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 5000,
            'formSlug' => 'dons-libres',
            'formType' => 'Donation',
            'items' => [
                ['id' => 1009, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [['id' => 208, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Sepa']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser($orders, 2025);

    $tx = Transaction::where('helloasso_order_id', 107)->first();
    expect($tx->mode_paiement)->toBe(ModePaiement::Prelevement);
});

it('splits order with multiple beneficiaries into separate transactions', function () {
    $tiers2 = Tiers::factory()->avecHelloasso()->create([
        'email' => 'marie@test.com',
        'nom' => 'Martin',
        'prenom' => 'Marie',
    ]);

    $orders = [
        [
            'id' => 108,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 6000,
            'formSlug' => 'adhesion-2025',
            'formType' => 'Membership',
            'items' => [
                [
                    'id' => 1010, 'amount' => 3000, 'state' => 'Processed', 'type' => 'Membership', 'name' => 'Adhésion Jean',
                    'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
                ],
                [
                    'id' => 1011, 'amount' => 3000, 'state' => 'Processed', 'type' => 'Membership', 'name' => 'Adhésion Marie',
                    'user' => ['firstName' => 'Marie', 'lastName' => 'Martin', 'email' => 'marie@test.com'],
                ],
            ],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [['id' => 209, 'amount' => 6000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->transactionsCreated)->toBe(2);
    expect(Transaction::where('helloasso_order_id', 108)->count())->toBe(2);
    expect(Transaction::where('helloasso_order_id', 108)->where('tiers_id', $this->tiers->id)->exists())->toBeTrue();
    expect(Transaction::where('helloasso_order_id', 108)->where('tiers_id', $tiers2->id)->exists())->toBeTrue();
});

it('imports zero-amount items normally', function () {
    $orders = [
        [
            'id' => 109,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 0,
            'formSlug' => 'dons-libres',
            'formType' => 'Donation',
            'items' => [
                ['id' => 1012, 'amount' => 0, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don libre'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [['id' => 210, 'amount' => 0, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniser($orders, 2025);

    expect($result->transactionsCreated)->toBe(1);
    $ligne = TransactionLigne::where('helloasso_item_id', 1012)->first();
    expect((float) $ligne->montant)->toBe(0.00);
});

it('defaults to cb for orders with empty payments array', function () {
    $orders = [
        [
            'id' => 110,
            'date' => '2025-10-15T10:00:00+02:00',
            'amount' => 5000,
            'formSlug' => 'dons-libres',
            'formType' => 'Donation',
            'items' => [
                ['id' => 1013, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don'],
            ],
            'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
            'payments' => [],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniser($orders, 2025);

    $tx = Transaction::where('helloasso_order_id', 110)->first();
    expect($tx->mode_paiement)->toBe(ModePaiement::Cb);
});
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot4/HelloAssoSyncServiceTest.php --stop-on-failure`
Expected: FAIL — la classe n'existe pas

- [ ] **Step 3: Implémenter `HelloAssoSyncService`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ModePaiement;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\NumeroPieceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class HelloAssoSyncService
{
    /** @var array<string, int> Cache formSlug → operation_id */
    private array $formMappingCache = [];

    public function __construct(
        private readonly HelloAssoParametres $parametres,
    ) {
        // Pre-load form mappings
        foreach ($this->parametres->formMappings as $mapping) {
            if ($mapping->operation_id !== null) {
                $this->formMappingCache[$mapping->form_slug] = $mapping->operation_id;
            }
        }
    }

    /**
     * Import HelloAsso orders into SVS transactions.
     *
     * @param  list<array<string, mixed>>  $orders
     */
    public function synchroniser(array $orders, int $exercice): HelloAssoSyncResult
    {
        $txCreated = 0;
        $txUpdated = 0;
        $lignesCreated = 0;
        $lignesUpdated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($orders as $order) {
            try {
                $result = $this->processOrder($order, $exercice);
                $txCreated += $result['tx_created'];
                $txUpdated += $result['tx_updated'];
                $lignesCreated += $result['lignes_created'];
                $lignesUpdated += $result['lignes_updated'];
                $skipped += $result['skipped'];
            } catch (\Throwable $e) {
                $errors[] = "Commande #{$order['id']} : {$e->getMessage()}";
                $skipped++;
            }
        }

        return new HelloAssoSyncResult(
            transactionsCreated: $txCreated,
            transactionsUpdated: $txUpdated,
            lignesCreated: $lignesCreated,
            lignesUpdated: $lignesUpdated,
            ordersSkipped: $skipped,
            errors: $errors,
        );
    }

    /**
     * @return array{tx_created: int, tx_updated: int, lignes_created: int, lignes_updated: int, skipped: int}
     */
    private function processOrder(array $order, int $exercice): array
    {
        // Group items by beneficiary email
        $groups = $this->groupItemsByBeneficiary($order);
        $result = ['tx_created' => 0, 'tx_updated' => 0, 'lignes_created' => 0, 'lignes_updated' => 0, 'skipped' => 0];

        $orderDate = Carbon::parse($order['date'])->toDateString();
        $modePaiement = $this->resolveModePaiement($order['payments'] ?? []);

        foreach ($groups as $email => $items) {
            $tiers = Tiers::where('email', $email)->where('est_helloasso', true)->first();
            if ($tiers === null) {
                throw new \RuntimeException("Tiers non trouvé pour {$email} — rapprochez d'abord les tiers");
            }

            // Pre-validate: resolve sous-catégories and opérations for all items
            $resolvedItems = [];
            foreach ($items as $item) {
                $resolved = $this->resolveItem($item, $order['formSlug']);
                $resolvedItems[] = $resolved;
            }

            DB::transaction(function () use ($order, $orderDate, $modePaiement, $tiers, $resolvedItems, $exercice, &$result) {
                $totalCentimes = collect($resolvedItems)->sum(fn (array $r) => $r['item']['amount']);
                $totalEuros = round($totalCentimes / 100, 2);

                // Upsert Transaction
                $existing = Transaction::where('helloasso_order_id', $order['id'])
                    ->where('tiers_id', $tiers->id)
                    ->first();

                if ($existing) {
                    $existing->update([
                        'date' => $orderDate,
                        'montant_total' => $totalEuros,
                        'mode_paiement' => $modePaiement,
                        'libelle' => $this->buildLibelle($order),
                    ]);
                    $result['tx_updated']++;
                    $tx = $existing;
                } else {
                    $tx = Transaction::create([
                        'type' => 'recette',
                        'date' => $orderDate,
                        'libelle' => $this->buildLibelle($order),
                        'montant_total' => $totalEuros,
                        'mode_paiement' => $modePaiement,
                        'tiers_id' => $tiers->id,
                        'compte_id' => $this->parametres->compte_helloasso_id,
                        'helloasso_order_id' => $order['id'],
                        'saisi_par' => auth()->id(),
                        'numero_piece' => app(NumeroPieceService::class)->assign(Carbon::parse($orderDate)),
                    ]);
                    $result['tx_created']++;
                }

                // Upsert TransactionLignes
                foreach ($resolvedItems as $resolved) {
                    $item = $resolved['item'];
                    $montantEuros = round($item['amount'] / 100, 2);

                    $existingLigne = TransactionLigne::where('helloasso_item_id', $item['id'])->first();

                    if ($existingLigne) {
                        $existingLigne->update([
                            'transaction_id' => $tx->id,
                            'sous_categorie_id' => $resolved['sous_categorie_id'],
                            'operation_id' => $resolved['operation_id'],
                            'montant' => $montantEuros,
                            'exercice' => $resolved['exercice'] === 'use_sync_exercice' ? $exercice : null,
                        ]);
                        $result['lignes_updated']++;
                    } else {
                        TransactionLigne::create([
                            'transaction_id' => $tx->id,
                            'sous_categorie_id' => $resolved['sous_categorie_id'],
                            'operation_id' => $resolved['operation_id'],
                            'montant' => $montantEuros,
                            'helloasso_item_id' => $item['id'],
                            'exercice' => $resolved['exercice'] === 'use_sync_exercice' ? $exercice : null,
                        ]);
                        $result['lignes_created']++;
                    }
                }
            });
        }

        return $result;
    }

    /**
     * Group items by beneficiary email. Uses 'user' if present, else 'payer'.
     *
     * @return array<string, list<array>>
     */
    private function groupItemsByBeneficiary(array $order): array
    {
        $groups = [];

        foreach ($order['items'] as $item) {
            // Per-item user takes priority, fallback to order-level user/payer
            $person = $item['user'] ?? $order['user'] ?? $order['payer'] ?? null;
            if ($person === null) {
                continue;
            }

            $email = strtolower(trim($person['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $groups[$email][] = $item;
        }

        return $groups;
    }

    /**
     * Resolve sous-catégorie and opération for an item.
     *
     * @return array{item: array, sous_categorie_id: int, operation_id: ?int, exercice: string|null}
     */
    private function resolveItem(array $item, string $formSlug): array
    {
        $type = $item['type'] ?? 'Donation';
        $sousCategorieId = $this->resolveSousCategorie($type);

        $operationId = $this->formMappingCache[$formSlug] ?? null;

        // Registration items require an operation
        if ($type === 'Registration' && $operationId === null) {
            throw new \RuntimeException("Formulaire '{$formSlug}' non mappé — impossible d'importer un item Registration sans opération");
        }

        // Exercice: only for Membership items (null = don't set exercice on the ligne)
        $exercice = ($type === 'Membership') ? 'use_sync_exercice' : null;

        return [
            'item' => $item,
            'sous_categorie_id' => $sousCategorieId,
            'operation_id' => $operationId,
            'exercice' => $exercice,
        ];
    }

    private function resolveSousCategorie(string $itemType): int
    {
        $id = match ($itemType) {
            'Donation' => $this->parametres->sous_categorie_don_id,
            'Membership' => $this->parametres->sous_categorie_cotisation_id,
            'Registration' => $this->parametres->sous_categorie_inscription_id,
            default => $this->parametres->sous_categorie_don_id, // Fallback pour types inconnus (PaymentForm, etc.)
        };

        if ($id === null) {
            throw new \RuntimeException("Sous-catégorie non configurée pour le type '{$itemType}'");
        }

        return $id;
    }

    private function resolveModePaiement(array $payments): ModePaiement
    {
        $means = $payments[0]['paymentMeans'] ?? 'Card';

        return match ($means) {
            'Card' => ModePaiement::Cb,
            'Sepa' => ModePaiement::Prelevement,
            'Check' => ModePaiement::Cheque,
            'Cash' => ModePaiement::Especes,
            'BankTransfer' => ModePaiement::Virement,
            default => ModePaiement::Cb,
        };
    }

    private function buildLibelle(array $order): string
    {
        $formSlug = $order['formSlug'] ?? '';
        $formType = $order['formType'] ?? '';

        return "HelloAsso — {$formType} ({$formSlug})";
    }
}
```

- [ ] **Step 4: Lancer les tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot4/HelloAssoSyncServiceTest.php --stop-on-failure`
Expected: PASS

- [ ] **Step 5: Lancer la suite complète**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --stop-on-failure`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(lot4): add HelloAssoSyncService — import orders as SVS transactions"
```

---

### Task 5: Composant Livewire — Configuration sync (comptes, sous-catégories, formulaires)

**Files:**
- Create: `app/Livewire/Parametres/HelloassoSyncConfig.php`
- Create: `resources/views/livewire/parametres/helloasso-sync-config.blade.php`
- Create: `tests/Feature/Lot4/HelloAssoSyncConfigTest.php`

**Contexte :** Ce composant permet au trésorier de configurer :
1. Le compte HelloAsso (réception des paiements)
2. Le compte de versement (destination des cash-outs — pour le Lot 5)
3. Le mapping des sous-catégories (Donation, Membership, Registration)
4. Le mapping des formulaires → opérations (chargé dynamiquement depuis l'API)

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Livewire\Parametres\HelloassoSyncConfig;
use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Models\SousCategorie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    \DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

    $this->parametres = HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'mon-asso',
        'environnement' => 'sandbox',
    ]);
});

it('renders the component', function () {
    Livewire::test(HelloassoSyncConfig::class)
        ->assertStatus(200);
});

it('saves sync config', function () {
    $compte = CompteBancaire::factory()->create(['nom' => 'HA']);
    $scDon = SousCategorie::where('pour_dons', true)->first()
        ?? SousCategorie::factory()->create(['pour_dons' => true]);

    Livewire::test(HelloassoSyncConfig::class)
        ->set('compteHelloassoId', $compte->id)
        ->set('sousCategorieDonId', $scDon->id)
        ->call('sauvegarder')
        ->assertHasNoErrors();

    $this->parametres->refresh();
    expect($this->parametres->compte_helloasso_id)->toBe($compte->id);
    expect($this->parametres->sous_categorie_don_id)->toBe($scDon->id);
});

it('loads forms from API and syncs mappings', function () {
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

    Livewire::test(HelloassoSyncConfig::class)
        ->call('chargerFormulaires')
        ->assertSee('adhesion-2025')
        ->assertSee('dons-libres');

    expect(HelloAssoFormMapping::count())->toBe(2);
});

it('saves form-to-operation mapping', function () {
    $operation = Operation::factory()->create(['nom' => 'Stage']);
    $mapping = HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'stage-ete',
        'form_type' => 'Event',
        'form_title' => 'Stage été',
    ]);

    Livewire::test(HelloassoSyncConfig::class)
        ->set("formOperations.{$mapping->id}", $operation->id)
        ->call('sauvegarderFormulaires')
        ->assertHasNoErrors();

    $mapping->refresh();
    expect($mapping->operation_id)->toBe($operation->id);
});
```

- [ ] **Step 2: Lancer les tests pour vérifier l'échec**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot4/HelloAssoSyncConfigTest.php --stop-on-failure`

- [ ] **Step 3: Implémenter le composant**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Services\HelloAssoApiClient;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoSyncConfig extends Component
{
    public ?int $compteHelloassoId = null;

    public ?int $compteVersementId = null;

    public ?int $sousCategorieDonId = null;

    public ?int $sousCategorieCotisationId = null;

    public ?int $sousCategorieInscriptionId = null;

    /** @var array<int, ?int> mapping_id → operation_id */
    public array $formOperations = [];

    public ?string $message = null;

    public ?string $erreur = null;

    public function mount(): void
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();
        if ($p !== null) {
            $this->compteHelloassoId = $p->compte_helloasso_id;
            $this->compteVersementId = $p->compte_versement_id;
            $this->sousCategorieDonId = $p->sous_categorie_don_id;
            $this->sousCategorieCotisationId = $p->sous_categorie_cotisation_id;
            $this->sousCategorieInscriptionId = $p->sous_categorie_inscription_id;

            // Load existing form mappings
            foreach ($p->formMappings as $m) {
                $this->formOperations[$m->id] = $m->operation_id;
            }
        }
    }

    public function sauvegarder(): void
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();
        if ($p === null) {
            $this->erreur = 'Paramètres HelloAsso non configurés.';
            return;
        }

        $p->update([
            'compte_helloasso_id' => $this->compteHelloassoId ?: null,
            'compte_versement_id' => $this->compteVersementId ?: null,
            'sous_categorie_don_id' => $this->sousCategorieDonId ?: null,
            'sous_categorie_cotisation_id' => $this->sousCategorieCotisationId ?: null,
            'sous_categorie_inscription_id' => $this->sousCategorieInscriptionId ?: null,
        ]);

        $this->message = 'Configuration enregistrée.';
    }

    public function chargerFormulaires(): void
    {
        $this->erreur = null;
        $p = HelloAssoParametres::where('association_id', 1)->first();
        if ($p === null || $p->client_id === null) {
            $this->erreur = 'Paramètres HelloAsso non configurés.';
            return;
        }

        try {
            $client = new HelloAssoApiClient($p);
            $forms = $client->fetchForms();
        } catch (\RuntimeException $e) {
            $this->erreur = $e->getMessage();
            return;
        }

        // Upsert form mappings
        foreach ($forms as $form) {
            HelloAssoFormMapping::updateOrCreate(
                [
                    'helloasso_parametres_id' => $p->id,
                    'form_slug' => $form['formSlug'],
                ],
                [
                    'form_type' => $form['formType'] ?? '',
                    'form_title' => $form['title'] ?? $form['formSlug'],
                ],
            );
        }

        // Reload mappings
        $this->formOperations = [];
        foreach ($p->formMappings()->get() as $m) {
            $this->formOperations[$m->id] = $m->operation_id;
        }

        $this->message = count($forms) . ' formulaires chargés.';
    }

    public function sauvegarderFormulaires(): void
    {
        foreach ($this->formOperations as $mappingId => $operationId) {
            HelloAssoFormMapping::where('id', $mappingId)->update([
                'operation_id' => $operationId ?: null,
            ]);
        }

        $this->message = 'Mapping des formulaires enregistré.';
    }

    public function render(): View
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();

        return view('livewire.parametres.helloasso-sync-config', [
            'comptes' => CompteBancaire::orderBy('nom')->get(),
            'sousCategoriesDon' => SousCategorie::where('pour_dons', true)->orderBy('nom')->get(),
            'sousCategoriesCotisation' => SousCategorie::where('pour_cotisations', true)->orderBy('nom')->get(),
            'sousCategoriesInscription' => SousCategorie::where('pour_inscriptions', true)->orderBy('nom')->get(),
            'operations' => Operation::orderBy('nom')->get(),
            'formMappings' => $p?->formMappings()->orderBy('form_slug')->get() ?? collect(),
        ]);
    }
}
```

- [ ] **Step 4: Créer la vue Blade**

Créer `resources/views/livewire/parametres/helloasso-sync-config.blade.php` :

```blade
<div>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-gear me-1"></i> Configuration de la synchronisation</h5>
        </div>
        <div class="card-body">
            @if($erreur)
                <div class="alert alert-danger">{{ $erreur }}</div>
            @endif
            @if($message)
                <div class="alert alert-success">{{ $message }}</div>
            @endif

            {{-- Comptes --}}
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Compte HelloAsso (réception)</label>
                    <select wire:model="compteHelloassoId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($comptes as $c)
                            <option value="{{ $c->id }}">{{ $c->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Compte de versement (destination)</label>
                    <select wire:model="compteVersementId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($comptes as $c)
                            <option value="{{ $c->id }}">{{ $c->nom }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Mapping sous-catégories --}}
            <h6 class="mt-3">Mapping des sous-catégories</h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small">Dons (Donation)</label>
                    <select wire:model="sousCategorieDonId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($sousCategoriesDon as $sc)
                            <option value="{{ $sc->id }}">{{ $sc->nom }} ({{ $sc->code_cerfa }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Cotisations (Membership)</label>
                    <select wire:model="sousCategorieCotisationId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($sousCategoriesCotisation as $sc)
                            <option value="{{ $sc->id }}">{{ $sc->nom }} ({{ $sc->code_cerfa }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Inscriptions (Registration)</label>
                    <select wire:model="sousCategorieInscriptionId" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        @foreach($sousCategoriesInscription as $sc)
                            <option value="{{ $sc->id }}">{{ $sc->nom }} ({{ $sc->code_cerfa }})</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <button wire:click="sauvegarder" class="btn btn-sm btn-primary">
                <i class="bi bi-check-lg me-1"></i> Enregistrer la configuration
            </button>

            <hr class="my-4">

            {{-- Mapping formulaires → opérations --}}
            <h6>Mapping des formulaires → opérations</h6>
            <div class="d-flex align-items-center gap-2 mb-3">
                <button wire:click="chargerFormulaires" class="btn btn-sm btn-outline-primary" wire:loading.attr="disabled">
                    <span wire:loading wire:target="chargerFormulaires" class="spinner-border spinner-border-sm me-1"></span>
                    <i class="bi bi-cloud-download me-1" wire:loading.remove wire:target="chargerFormulaires"></i>
                    Charger les formulaires depuis HelloAsso
                </button>
            </div>

            @if($formMappings->isNotEmpty())
                <table class="table table-sm">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Formulaire</th>
                            <th>Type</th>
                            <th>Opération SVS</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($formMappings as $fm)
                            <tr wire:key="fm-{{ $fm->id }}">
                                <td class="small">{{ $fm->form_title ?? $fm->form_slug }}</td>
                                <td class="small"><span class="badge text-bg-secondary">{{ $fm->form_type }}</span></td>
                                <td>
                                    <select wire:model="formOperations.{{ $fm->id }}" class="form-select form-select-sm">
                                        <option value="">— Aucune —</option>
                                        @foreach($operations as $op)
                                            <option value="{{ $op->id }}">{{ $op->nom }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <button wire:click="sauvegarderFormulaires" class="btn btn-sm btn-primary">
                    <i class="bi bi-check-lg me-1"></i> Enregistrer le mapping
                </button>
            @else
                <p class="text-muted small">Aucun formulaire chargé. Cliquez sur le bouton ci-dessus.</p>
            @endif
        </div>
    </div>
</div>
```

- [ ] **Step 5: Lancer les tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot4/HelloAssoSyncConfigTest.php --stop-on-failure`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(lot4): add HelloAsso sync config component — comptes, sous-catégories, formulaires"
```

---

### Task 6: Composant Livewire — Lancement sync + rapport

**Files:**
- Create: `app/Livewire/Parametres/HelloassoSync.php`
- Create: `resources/views/livewire/parametres/helloasso-sync.blade.php`
- Create: `tests/Feature/Lot4/HelloAssoSyncTest.php`

**Contexte :** Ce composant permet au trésorier de lancer la synchronisation pour un exercice donné et affiche le rapport de résultat.

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Livewire\Parametres\HelloassoSync;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    \DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

    $compte = CompteBancaire::factory()->create(['nom' => 'HelloAsso']);
    $scDon = SousCategorie::where('pour_dons', true)->first()
        ?? SousCategorie::factory()->create(['pour_dons' => true, 'nom' => 'Don']);
    $scCot = SousCategorie::where('pour_cotisations', true)->first()
        ?? SousCategorie::factory()->create(['pour_cotisations' => true, 'nom' => 'Cotisation']);

    HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'mon-asso',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => $compte->id,
        'sous_categorie_don_id' => $scDon->id,
        'sous_categorie_cotisation_id' => $scCot->id,
    ]);

    Tiers::factory()->avecHelloasso()->create(['email' => 'jean@test.com', 'nom' => 'Dupont', 'prenom' => 'Jean']);
});

it('renders the component', function () {
    Livewire::test(HelloassoSync::class)
        ->assertStatus(200);
});

it('runs sync and displays report', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'id' => 100, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00',
                        'formSlug' => 'dons-libres', 'formType' => 'Donation',
                        'items' => [['id' => 1001, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don']],
                        'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
                        'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
                        'payments' => [['id' => 201, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
                    ],
                ],
                'pagination' => [],
            ])
            ->push(['data' => [], 'pagination' => []]),
    ]);

    Livewire::test(HelloassoSync::class)
        ->call('synchroniser')
        ->assertSee('1 créée')
        ->assertSee('Synchronisation terminée');

    expect(Transaction::where('helloasso_order_id', 100)->count())->toBe(1);
});

it('shows error when config is incomplete', function () {
    HelloAssoParametres::where('association_id', 1)->update(['compte_helloasso_id' => null]);

    Livewire::test(HelloassoSync::class)
        ->call('synchroniser')
        ->assertSee('Compte HelloAsso non configuré');
});
```

- [ ] **Step 2: Lancer les tests pour vérifier l'échec**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot4/HelloAssoSyncTest.php --stop-on-failure`

- [ ] **Step 3: Implémenter le composant**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Models\HelloAssoParametres;
use App\Services\ExerciceService;
use App\Services\HelloAssoApiClient;
use App\Services\HelloAssoSyncResult;
use App\Services\HelloAssoSyncService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoSync extends Component
{
    public int $exercice;

    public ?HelloAssoSyncResult $result = null;

    public ?string $erreur = null;

    public bool $syncing = false;

    public function mount(): void
    {
        $this->exercice = app(ExerciceService::class)->current();
    }

    public function synchroniser(): void
    {
        $this->erreur = null;
        $this->result = null;

        $parametres = HelloAssoParametres::where('association_id', 1)->first();
        if ($parametres === null || $parametres->client_id === null) {
            $this->erreur = 'Paramètres HelloAsso non configurés.';
            return;
        }

        if ($parametres->compte_helloasso_id === null) {
            $this->erreur = 'Compte HelloAsso non configuré. Configurez-le dans la section ci-dessus.';
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

        $syncService = new HelloAssoSyncService($parametres);
        $this->result = $syncService->synchroniser($orders, $this->exercice);
    }

    public function render(): View
    {
        return view('livewire.parametres.helloasso-sync', [
            'exercices' => app(ExerciceService::class)->available(5),
        ]);
    }
}
```

- [ ] **Step 4: Créer la vue Blade**

Créer `resources/views/livewire/parametres/helloasso-sync.blade.php` :

```blade
<div>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-arrow-repeat me-1"></i> Lancer la synchronisation</h5>
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
                    <button wire:click="synchroniser" class="btn btn-sm btn-success" wire:loading.attr="disabled">
                        <span wire:loading wire:target="synchroniser" class="spinner-border spinner-border-sm me-1"></span>
                        <i class="bi bi-arrow-repeat me-1" wire:loading.remove wire:target="synchroniser"></i>
                        Synchroniser avec HelloAsso
                    </button>
                </div>
            </div>

            @if($erreur)
                <div class="alert alert-danger">{{ $erreur }}</div>
            @endif

            @if($result)
                <div class="alert {{ $result->hasErrors() ? 'alert-warning' : 'alert-success' }}">
                    <strong><i class="bi bi-check-circle me-1"></i> Synchronisation terminée</strong>
                    <ul class="mb-0 mt-2">
                        <li>Transactions : <strong>{{ $result->transactionsCreated }}</strong> créée(s), <strong>{{ $result->transactionsUpdated }}</strong> mise(s) à jour</li>
                        <li>Lignes : <strong>{{ $result->lignesCreated }}</strong> créée(s), <strong>{{ $result->lignesUpdated }}</strong> mise(s) à jour</li>
                        @if($result->ordersSkipped > 0)
                            <li>Commandes ignorées : <strong>{{ $result->ordersSkipped }}</strong></li>
                        @endif
                    </ul>
                </div>

                @if($result->hasErrors())
                    <div class="alert alert-danger">
                        <strong><i class="bi bi-exclamation-triangle me-1"></i> {{ count($result->errors) }} erreur(s) :</strong>
                        <ul class="mb-0 mt-1">
                            @foreach($result->errors as $error)
                                <li class="small">{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
```

- [ ] **Step 5: Lancer les tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot4/HelloAssoSyncTest.php --stop-on-failure`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(lot4): add HelloAsso sync component — launch sync and display report"
```

---

### Task 7: Intégrer les composants dans la page Paramètres HelloAsso

**Files:**
- Modify: `resources/views/parametres/helloasso.blade.php`

- [ ] **Step 1: Lire la vue actuelle**

Lire `resources/views/parametres/helloasso.blade.php`.

- [ ] **Step 2: Ajouter les composants**

```blade
<x-app-layout>
    <div class="container py-3">
        <h1 class="mb-4">Connexion HelloAsso</h1>
        <livewire:parametres.helloasso-form />
        <livewire:parametres.helloasso-sync-config />
        <livewire:parametres.helloasso-tiers-rapprochement />
        <livewire:parametres.helloasso-sync />
    </div>
</x-app-layout>
```

L'ordre logique : connexion → config sync → rapprochement tiers → lancer la sync.

- [ ] **Step 3: Lancer les tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --stop-on-failure`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add resources/views/parametres/helloasso.blade.php
git commit -m "feat(lot4): integrate sync config and sync launch components in HelloAsso settings"
```

---

### Task 8: Vérification finale + Pint + suite complète

**Files:** Aucun nouveau fichier

- [ ] **Step 1: Lancer Pint**

Run: `./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint`

- [ ] **Step 2: Lancer migrate:fresh --seed**

Run: `./vendor/bin/sail exec -T laravel.test php artisan migrate:fresh --seed`
Expected: Succès sans erreur.

- [ ] **Step 3: Lancer la suite de tests complète**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test`
Expected: Tous les tests passent.

- [ ] **Step 4: Commit si Pint a modifié des fichiers**

```bash
git add -A
git commit -m "style(lot4): pint formatting"
```

---

## Notes pour l'implémenteur

1. **Ordre des tâches** : Task 1 (migrations) en premier. Task 2 (modèle) dépend de Task 1. Task 3 (VO) est indépendant. Task 4 (sync service) dépend de 1+2+3. Task 5 (config UI) dépend de 1+2. Task 6 (sync UI) dépend de 4+5. Task 7 dépend de 5+6.

2. **Factories** : Si `SousCategorie::factory()` ou `Operation::factory()` n'existent pas, les créer avec les champs minimaux nécessaires. Le `CategoriesSeeder` crée des sous-catégories avec les bons flags — les tests peuvent s'en servir si `RefreshDatabase` est utilisé (car les seeders tournent via `migrate:fresh`). Sinon, créer les enregistrements dans le `beforeEach`.

3. **Exercice** : Pour les items Membership (cotisations), le champ `exercice` sur TransactionLigne est rempli avec l'exercice passé à `synchroniser()`. Pour les autres types (Donation, Registration), `exercice` reste null. Le mécanisme utilise un marqueur `'use_sync_exercice'` dans `resolveItem()` pour distinguer les cas.

4. **Mode de paiement** : On prend le premier payment de l'order pour déterminer le mode de paiement. En pratique, un order HelloAsso n'a qu'un seul payment.

5. **Montants** : HelloAsso = centimes (int). SVS = euros (decimal). Division par 100 systématique.

6. **Sécurité** : Les composants de configuration sont sur la page paramètres, protégée par le middleware auth + admin.

7. **HelloAssoSyncResult** : C'est un VO non-Livewire-friendly car il a des propriétés `readonly`. Le composant `HelloassoSync` peut le stocker comme `public ?HelloAssoSyncResult $result` — Livewire 4 gère la sérialisation des objets simples. Si ça pose problème, convertir en array.
