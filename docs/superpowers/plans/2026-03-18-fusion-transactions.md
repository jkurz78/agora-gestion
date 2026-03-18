# Fusion Dépenses/Recettes en Transactions — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fusionner les tables `depenses`/`recettes` et leurs lignes en une structure unifiée `transactions`, réduire de 6 à 3 tables et de 4 à 2 composants Livewire.

**Architecture:** Un enum `TypeTransaction` porte la nature débit/crédit. Les montants restent positifs en base ; le signe est calculé à la volée via `CASE WHEN type='depense' THEN -montant_total ELSE montant_total END`. Un unique `TransactionService` remplace `DepenseService` et `RecetteService`.

**Tech Stack:** Laravel 11, Livewire 4, Pest PHP, MySQL via Docker Sail

**Spec :** `docs/superpowers/specs/2026-03-18-fusion-transactions-design.md`

---

## Fichiers créés / modifiés / supprimés

### Créés
| Fichier | Rôle |
|---|---|
| `app/Enums/TypeTransaction.php` | Enum `depense`/`recette` |
| `app/Models/Transaction.php` | Modèle principal |
| `app/Models/TransactionLigne.php` | Ligne de ventilation |
| `app/Models/TransactionLigneAffectation.php` | Affectation de ligne |
| `app/Services/TransactionService.php` | Logique métier unifiée |
| `app/Livewire/TransactionList.php` | Liste avec filtre type |
| `app/Livewire/TransactionForm.php` | Formulaire création/édition |
| `database/migrations/2026_03_18_100000_create_transactions_unified.php` | Migration + données |
| `database/factories/TransactionFactory.php` | Factory avec états `asDepense()`/`asRecette()` |
| `database/factories/TransactionLigneFactory.php` | Factory ligne |
| `tests/Feature/TransactionServiceTest.php` | Tests CRUD service |
| `tests/Feature/TransactionServiceLockTest.php` | Tests invariants rapprochement |
| `tests/Feature/TransactionAffectationTest.php` | Tests affectations |
| `resources/views/transactions/index.blade.php` | Page liste |
| `resources/views/livewire/transaction-list.blade.php` | Vue liste |
| `resources/views/livewire/transaction-form.blade.php` | Vue formulaire |

### Modifiés
| Fichier | Changement |
|---|---|
| `app/Services/TransactionCompteService.php` | Union simplifiée → requête unique `transactions` |
| `app/Services/RapportService.php` | Requêtes sur `transactions JOIN transaction_lignes` |
| `app/Livewire/Dashboard.php` | `Depense`/`Recette` → `Transaction` |
| `app/Models/RapprochementBancaire.php` | Relations `depenses()`/`recettes()` → `transactions()` |
| `app/Livewire/TiersTransactions.php` | Requêtes sur `transactions` |
| `app/Livewire/RapprochementDetail.php` | Références modèles mis à jour |
| `app/Livewire/ImportCsv.php` | Si référence depenses/recettes |
| `app/Livewire/TransactionCompteList.php` | `deleteDepense`/`deleteRecette` → `deleteTransaction` |
| `resources/views/layouts/app.blade.php` | Nouveau menu Transactions |
| `routes/web.php` | Route `/transactions`, suppression `/depenses` `/recettes` |

### Supprimés (Task 12)
`app/Models/Depense.php`, `Recette.php`, `DepenseLigne.php`, `RecetteLigne.php`, `DepenseLigneAffectation.php`, `RecetteLigneAffectation.php`,
`app/Services/DepenseService.php`, `RecetteService.php`,
`app/Livewire/DepenseForm.php`, `DepenseList.php`, `RecetteForm.php`, `RecetteList.php`,
`database/factories/DepenseFactory.php`, `RecetteFactory.php`, `DepenseLigneFactory.php`, `RecetteLigneFactory.php`,
`resources/views/livewire/depense-form.blade.php`, `depense-list.blade.php`, `recette-form.blade.php`, `recette-list.blade.php`,
`resources/views/depenses/index.blade.php`, `recettes/index.blade.php`,
`tests/Feature/DepenseTest.php`, `DepenseAffectationTest.php`, `DepenseServiceLockTest.php`, `RecetteTest.php`, `RecetteAffectationTest.php`, `RecetteServiceLockTest.php`

---

## Task 1 : Enum TypeTransaction

**Files:**
- Create: `app/Enums/TypeTransaction.php`

- [ ] **Créer l'enum**

```php
<?php
declare(strict_types=1);
namespace App\Enums;

enum TypeTransaction: string
{
    case Depense = 'depense';
    case Recette = 'recette';

    public function label(): string
    {
        return match ($this) {
            self::Depense => 'Dépense',
            self::Recette => 'Recette',
        };
    }
}
```

- [ ] **Vérifier que la suite passe (rien de cassé)**

```bash
./vendor/bin/sail artisan test --stop-on-failure 2>&1 | tail -5
```
Attendu : `Tests: N passed`

- [ ] **Commit**

```bash
git add app/Enums/TypeTransaction.php
git commit -m "feat: add TypeTransaction enum (depense|recette)"
```

---

## Task 2 : Modèles Transaction, TransactionLigne, TransactionLigneAffectation

**Files:**
- Create: `app/Models/Transaction.php`
- Create: `app/Models/TransactionLigne.php`
- Create: `app/Models/TransactionLigneAffectation.php`

- [ ] **Créer `app/Models/Transaction.php`**

```php
<?php
declare(strict_types=1);
namespace App\Models;

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Transaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'date',
        'libelle',
        'montant_total',
        'mode_paiement',
        'tiers_id',
        'reference',
        'compte_id',
        'pointe',
        'notes',
        'saisi_par',
        'rapprochement_id',
        'numero_piece',
    ];

    protected function casts(): array
    {
        return [
            'type'          => TypeTransaction::class,
            'date'          => 'date',
            'montant_total' => 'decimal:2',
            'mode_paiement' => ModePaiement::class,
            'pointe'        => 'boolean',
            'tiers_id'      => 'integer',
            'compte_id'     => 'integer',
            'saisi_par'     => 'integer',
            'rapprochement_id' => 'integer',
        ];
    }

    public function estDebit(): bool
    {
        return $this->type === TypeTransaction::Depense;
    }

    public function montantSigne(): float
    {
        return $this->estDebit() ? -(float) $this->montant_total : (float) $this->montant_total;
    }

    public function isLockedByRapprochement(): bool
    {
        return $this->rapprochement_id !== null
            && $this->rapprochement?->isVerrouille() === true;
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_id');
    }

    public function rapprochement(): BelongsTo
    {
        return $this->belongsTo(RapprochementBancaire::class, 'rapprochement_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(TransactionLigne::class);
    }

    /** @param Builder<Transaction> $query */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->whereBetween('date', [
            "{$exercice}-09-01",
            ($exercice + 1).'-08-31',
        ]);
    }
}
```

- [ ] **Créer `app/Models/TransactionLigne.php`**

```php
<?php
declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class TransactionLigne extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'transaction_lignes';
    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'sous_categorie_id',
        'operation_id',
        'seance',
        'montant',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'montant'          => 'decimal:2',
            'transaction_id'   => 'integer',
            'sous_categorie_id'=> 'integer',
            'operation_id'     => 'integer',
            'seance'           => 'integer',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function sousCategorie(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(TransactionLigneAffectation::class);
    }
}
```

- [ ] **Créer `app/Models/TransactionLigneAffectation.php`**

```php
<?php
declare(strict_types=1);
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TransactionLigneAffectation extends Model
{
    protected $table = 'transaction_ligne_affectations';

    protected $fillable = [
        'transaction_ligne_id',
        'operation_id',
        'seance',
        'montant',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'montant'              => 'decimal:2',
            'transaction_ligne_id' => 'integer',
            'operation_id'         => 'integer',
            'seance'               => 'integer',
        ];
    }

    public function transactionLigne(): BelongsTo
    {
        return $this->belongsTo(TransactionLigne::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
```

- [ ] **Vérifier syntaxe**

```bash
./vendor/bin/sail artisan about 2>&1 | grep -i error
```
Attendu : aucune erreur.

- [ ] **Commit**

```bash
git add app/Models/Transaction.php app/Models/TransactionLigne.php app/Models/TransactionLigneAffectation.php
git commit -m "feat: add Transaction, TransactionLigne, TransactionLigneAffectation models"
```

---

## Task 3 : Migration de données

**Files:**
- Create: `database/migrations/2026_03_18_100000_create_transactions_unified.php`

Cette migration crée les nouvelles tables, migre les données via `old_id`, puis supprime les anciennes tables.

- [ ] **Créer la migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Créer les nouvelles tables ─────────────────────────────────────
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('old_id')->nullable(); // temporaire pour migration
            $table->string('old_type', 10)->nullable();    // temporaire : 'depense'|'recette'
            $table->string('type', 10);
            $table->date('date');
            $table->string('libelle', 255)->nullable();
            $table->decimal('montant_total', 10, 2);
            $table->string('mode_paiement', 50);
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->foreignId('compte_id')->nullable()->constrained('comptes_bancaires')->nullOnDelete();
            $table->boolean('pointe')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rapprochement_id')->nullable()->constrained('rapprochements_bancaires')->nullOnDelete();
            $table->string('numero_piece', 50)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('transaction_lignes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('old_id')->nullable(); // temporaire
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('sous_categorie_id')->constrained('sous_categories');
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->integer('seance')->nullable();
            $table->decimal('montant', 10, 2);
            $table->text('notes')->nullable();
            $table->softDeletes();
        });

        Schema::create('transaction_ligne_affectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_ligne_id')->constrained('transaction_lignes')->cascadeOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->unsignedInteger('seance')->nullable();
            $table->decimal('montant', 10, 2);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        // ── 2. Migration des données ──────────────────────────────────────────
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // 2a. Insérer les dépenses
        DB::statement("
            INSERT INTO transactions
                (old_id, old_type, type, date, libelle, montant_total, mode_paiement,
                 tiers_id, reference, compte_id, pointe, notes, saisi_par,
                 rapprochement_id, numero_piece, deleted_at, created_at, updated_at)
            SELECT id, 'depense', 'depense', date, libelle, montant_total, mode_paiement,
                   tiers_id, reference, compte_id, pointe, notes, saisi_par,
                   rapprochement_id, numero_piece, deleted_at, created_at, updated_at
            FROM depenses
        ");

        // 2b. Insérer les recettes
        DB::statement("
            INSERT INTO transactions
                (old_id, old_type, type, date, libelle, montant_total, mode_paiement,
                 tiers_id, reference, compte_id, pointe, notes, saisi_par,
                 rapprochement_id, numero_piece, deleted_at, created_at, updated_at)
            SELECT id, 'recette', 'recette', date, libelle, montant_total, mode_paiement,
                   tiers_id, reference, compte_id, pointe, notes, saisi_par,
                   rapprochement_id, numero_piece, deleted_at, created_at, updated_at
            FROM recettes
        ");

        // 2c. Insérer les lignes de dépenses
        DB::statement("
            INSERT INTO transaction_lignes
                (old_id, transaction_id, sous_categorie_id, operation_id, seance, montant, notes, deleted_at)
            SELECT dl.id, t.id, dl.sous_categorie_id, dl.operation_id, dl.seance, dl.montant, dl.notes, dl.deleted_at
            FROM depense_lignes dl
            JOIN transactions t ON t.old_id = dl.depense_id AND t.old_type = 'depense'
        ");

        // 2d. Insérer les lignes de recettes
        DB::statement("
            INSERT INTO transaction_lignes
                (old_id, transaction_id, sous_categorie_id, operation_id, seance, montant, notes, deleted_at)
            SELECT rl.id, t.id, rl.sous_categorie_id, rl.operation_id, rl.seance, rl.montant, rl.notes, rl.deleted_at
            FROM recette_lignes rl
            JOIN transactions t ON t.old_id = rl.recette_id AND t.old_type = 'recette'
        ");

        // 2e. Insérer les affectations de dépenses
        DB::statement("
            INSERT INTO transaction_ligne_affectations
                (transaction_ligne_id, operation_id, seance, montant, notes, created_at, updated_at)
            SELECT tl.id, dla.operation_id, dla.seance, dla.montant, dla.notes, dla.created_at, dla.updated_at
            FROM depense_ligne_affectations dla
            JOIN transaction_lignes tl ON tl.old_id = dla.depense_ligne_id
            JOIN transactions t ON t.id = tl.transaction_id AND t.old_type = 'depense'
        ");

        // 2f. Insérer les affectations de recettes
        DB::statement("
            INSERT INTO transaction_ligne_affectations
                (transaction_ligne_id, operation_id, seance, montant, notes, created_at, updated_at)
            SELECT tl.id, rla.operation_id, rla.seance, rla.montant, rla.notes, rla.created_at, rla.updated_at
            FROM recette_ligne_affectations rla
            JOIN transaction_lignes tl ON tl.old_id = rla.recette_ligne_id
            JOIN transactions t ON t.id = tl.transaction_id AND t.old_type = 'recette'
        ");

        // ── 3. Supprimer les colonnes temporaires ─────────────────────────────
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['old_id', 'old_type']);
        });

        Schema::table('transaction_lignes', function (Blueprint $table) {
            $table->dropColumn('old_id');
        });

        // ── 4. Supprimer les anciennes tables ─────────────────────────────────
        Schema::dropIfExists('depense_ligne_affectations');
        Schema::dropIfExists('recette_ligne_affectations');
        Schema::dropIfExists('depense_lignes');
        Schema::dropIfExists('recette_lignes');
        Schema::dropIfExists('depenses');
        Schema::dropIfExists('recettes');

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        // down() non implémenté — migration de données irréversible sans backup
        throw new \RuntimeException('Cette migration est irréversible. Restaurer depuis un backup.');
    }
};
```

- [ ] **Vérifier la migration sur une base fraîche**

```bash
./vendor/bin/sail artisan migrate:fresh --seed 2>&1 | tail -10
```
Attendu : `INFO  Database seeding completed successfully.`

- [ ] **Vérifier le compte des données (si seed peuple depenses/recettes)**

```bash
./vendor/bin/sail artisan tinker --execute="echo Transaction::count().' transactions migrées';"
```

- [ ] **Lancer la suite de tests complète**

```bash
./vendor/bin/sail artisan test 2>&1 | tail -5
```
Note : beaucoup de tests vont échouer ici (ils référencent encore `Depense`/`Recette`). L'objectif est de confirmer que la migration elle-même ne lève pas d'erreur.

- [ ] **Commit**

```bash
git add database/migrations/2026_03_18_100000_create_transactions_unified.php
git commit -m "feat: migration données depenses+recettes → transactions"
```

---

## Task 4 : Factories

**Files:**
- Create: `database/factories/TransactionFactory.php`
- Create: `database/factories/TransactionLigneFactory.php`

- [ ] **Créer `database/factories/TransactionFactory.php`**

```php
<?php
declare(strict_types=1);
namespace Database\Factories;

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'type'          => fake()->randomElement(TypeTransaction::cases()),
            'date'          => fake()->dateTimeBetween('-1 year', 'now'),
            'libelle'       => fake()->sentence(4),
            'montant_total' => fake()->randomFloat(2, 10, 5000),
            'mode_paiement' => fake()->randomElement(ModePaiement::cases()),
            'reference'     => fake()->numerify('REF-####'),
            'compte_id'     => CompteBancaire::factory(),
            'pointe'        => fake()->boolean(20),
            'notes'         => fake()->optional()->sentence(),
            'saisi_par'     => User::factory(),
        ];
    }

    public function asDepense(): static
    {
        return $this->state(['type' => TypeTransaction::Depense]);
    }

    public function asRecette(): static
    {
        return $this->state(['type' => TypeTransaction::Recette]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Transaction $transaction) {
            $ligneCount = fake()->numberBetween(1, 3);
            $montants   = $this->splitAmount((float) $transaction->montant_total, $ligneCount);
            foreach ($montants as $montant) {
                TransactionLigne::factory()->create([
                    'transaction_id' => $transaction->id,
                    'montant'        => $montant,
                ]);
            }
        });
    }

    /** @return list<float> */
    private function splitAmount(float $total, int $parts): array
    {
        if ($parts === 1) {
            return [$total];
        }
        $amounts   = [];
        $remaining = $total;
        for ($i = 0; $i < $parts - 1; $i++) {
            $amount    = round($remaining / ($parts - $i) * fake()->randomFloat(2, 0.5, 1.5), 2);
            $amount    = min($amount, $remaining - (($parts - $i - 1) * 0.01));
            $amount    = max($amount, 0.01);
            $amounts[] = $amount;
            $remaining -= $amount;
        }
        $amounts[] = round($remaining, 2);
        return $amounts;
    }
}
```

- [ ] **Créer `database/factories/TransactionLigneFactory.php`**

Regarder `database/factories/DepenseLigneFactory.php` pour la structure exacte, remplacer `depense_id` par `transaction_id` et `DepenseLigne` par `TransactionLigne`.

```php
<?php
declare(strict_types=1);
namespace Database\Factories;

use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransactionLigne> */
class TransactionLigneFactory extends Factory
{
    protected $model = TransactionLigne::class;

    public function definition(): array
    {
        return [
            'transaction_id'    => Transaction::factory(),
            'sous_categorie_id' => SousCategorie::factory(),
            'montant'           => fake()->randomFloat(2, 5, 500),
            'operation_id'      => null,
            'seance'            => null,
            'notes'             => null,
        ];
    }
}
```

- [ ] **Tester les factories en tinker**

```bash
./vendor/bin/sail artisan tinker --execute="
\$t = \App\Models\Transaction::factory()->asDepense()->create();
echo \$t->type->value.' - '.\$t->montant_total.' - lignes: '.\$t->lignes()->count();
"
```
Attendu : `depense - [montant] - lignes: [1-3]`

- [ ] **Commit**

```bash
git add database/factories/TransactionFactory.php database/factories/TransactionLigneFactory.php
git commit -m "feat: add TransactionFactory and TransactionLigneFactory"
```

---

## Task 5 : TransactionService (TDD)

**Files:**
- Create: `tests/Feature/TransactionServiceTest.php`
- Create: `tests/Feature/TransactionServiceLockTest.php`
- Create: `tests/Feature/TransactionAffectationTest.php`
- Create: `app/Services/TransactionService.php`

### 5a — Écrire les tests d'abord

- [ ] **Créer `tests/Feature/TransactionServiceTest.php`**

S'inspirer de `tests/Feature/DepenseTest.php` et `tests/Feature/RecetteTest.php`. Reprendre les mêmes cas mais avec `Transaction::factory()->asDepense()` et `Transaction::factory()->asRecette()`. Points clés :

```php
<?php
declare(strict_types=1);
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(TransactionService::class);
    $this->compte = CompteBancaire::factory()->create();
});

it('crée une dépense avec ses lignes', function () {
    $sc = SousCategorie::factory()->create();
    $data = [
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-01',
        'libelle' => 'Test dépense',
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'reference' => 'REF-001',
        'compte_id' => $this->compte->id,
    ];
    $lignes = [['sous_categorie_id' => $sc->id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null]];

    $transaction = $this->service->create($data, $lignes);

    expect($transaction->type)->toBe(TypeTransaction::Depense)
        ->and($transaction->lignes()->count())->toBe(1);
});

it('crée une recette avec ses lignes', function () {
    $sc = SousCategorie::factory()->create();
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-01',
        'libelle' => 'Test recette',
        'montant_total' => '200.00',
        'mode_paiement' => 'virement',
        'reference' => 'REF-002',
        'compte_id' => $this->compte->id,
    ];
    $lignes = [['sous_categorie_id' => $sc->id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]];

    $transaction = $this->service->create($data, $lignes);

    expect($transaction->type)->toBe(TypeTransaction::Recette)
        ->and($transaction->montantSigne())->toBe(200.0);
});

it('montantSigne est négatif pour une dépense', function () {
    $transaction = Transaction::factory()->asDepense()->create(['montant_total' => '150.00']);
    expect($transaction->montantSigne())->toBe(-150.0);
});

it('montantSigne est positif pour une recette', function () {
    $transaction = Transaction::factory()->asRecette()->create(['montant_total' => '150.00']);
    expect($transaction->montantSigne())->toBe(150.0);
});

it('supprime une transaction non rapprochée', function () {
    $transaction = Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id]);
    $this->service->delete($transaction);
    expect(Transaction::find($transaction->id))->toBeNull();
});

it('rejette la suppression d\'une transaction rapprochée', function () {
    $transaction = Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id]);
    $transaction->update(['rapprochement_id' => 1]); // simulation
    expect(fn () => $this->service->delete($transaction))
        ->toThrow(RuntimeException::class);
});
```

- [ ] **Créer `tests/Feature/TransactionServiceLockTest.php`**

Adapter `tests/Feature/DepenseServiceLockTest.php` : remplacer `Depense`→`Transaction`, `DepenseService`→`TransactionService`, la factory `makeLockedDepense` devient `makeLockedTransaction(string $type)` pour tester les deux types.

Points clés à couvrir (un test par cas) :
- rejette modif date sur transaction rapprochée
- rejette modif compte sur transaction rapprochée
- rejette modif montant total sur transaction rapprochée
- rejette ajout de ligne sur transaction rapprochée
- rejette suppression de ligne sur transaction rapprochée
- **autorise** modif sous_categorie_id sur transaction rapprochée
- accepte modif libelle/notes sur transaction rapprochée

- [ ] **Créer `tests/Feature/TransactionAffectationTest.php`**

Adapter `tests/Feature/DepenseAffectationTest.php` : `DepenseLigne`→`TransactionLigne`, `DepenseService`→`TransactionService`.

- [ ] **Lancer les tests — vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/TransactionServiceTest.php 2>&1 | tail -5
```
Attendu : erreurs du type `TransactionService not found`.

### 5b — Implémenter TransactionService

- [ ] **Créer `app/Services/TransactionService.php`**

Fusionner `DepenseService` et `RecetteService`. Différences clés vs l'existant :
- `create()` reçoit `type` dans `$data` (string, converti en enum)
- `assertLockedInvariants()` : messages d'erreur génériques ("transaction" au lieu de "dépense"/"recette"), **PAS de vérification sous_categorie_id**
- Chemin verrouillé de `update()` : inclure `sous_categorie_id` dans les champs mis à jour
- Suppression d'une ligne : hard-delete des affectations AVANT soft-delete de la ligne

```php
<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionLigne;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class TransactionService
{
    public function create(array $data, array $lignes): Transaction
    {
        return DB::transaction(function () use ($data, $lignes) {
            $data['saisi_par']    = auth()->id();
            $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));
            $transaction = Transaction::create($data);
            foreach ($lignes as $ligne) {
                $transaction->lignes()->create($ligne);
            }
            return $transaction;
        });
    }

    public function update(Transaction $transaction, array $data, array $lignes): Transaction
    {
        return DB::transaction(function () use ($transaction, $data, $lignes) {
            $transaction->load(['rapprochement' => fn ($q) => $q->lockForUpdate()]);

            if ($transaction->isLockedByRapprochement()) {
                $this->assertLockedInvariants($transaction, $data, $lignes);
            }

            $transaction->update($data);

            if ($transaction->isLockedByRapprochement()) {
                foreach ($lignes as $ligneData) {
                    $transaction->lignes()->where('id', $ligneData['id'])->update([
                        'sous_categorie_id' => $ligneData['sous_categorie_id'],
                        'operation_id'      => $ligneData['operation_id'],
                        'seance'            => $ligneData['seance'],
                        'notes'             => $ligneData['notes'],
                    ]);
                }
            } else {
                $affectationsSnapshot = [];
                foreach ($lignes as $ligneData) {
                    $oldId = isset($ligneData['id']) && $ligneData['id'] !== null ? (int) $ligneData['id'] : null;
                    if ($oldId !== null) {
                        $existingLigne = $transaction->lignes()->where('id', $oldId)->first();
                        if ($existingLigne === null) { continue; }
                        $oldCents = (int) round((float) $existingLigne->montant * 100);
                        $newCents = (int) round((float) $ligneData['montant'] * 100);
                        if ($oldCents !== $newCents) { continue; }
                        $aff = $existingLigne->affectations()->get();
                        if ($aff->isNotEmpty()) {
                            $affectationsSnapshot[$oldId] = $aff->map(fn ($a) => [
                                'operation_id' => $a->operation_id,
                                'seance'       => $a->seance,
                                'montant'      => $a->montant,
                                'notes'        => $a->notes,
                            ])->toArray();
                        }
                    }
                }
                $transaction->lignes()->forceDelete();
                foreach ($lignes as $ligneData) {
                    $newLigne = $transaction->lignes()->create($ligneData);
                    $oldId    = isset($ligneData['id']) && $ligneData['id'] !== null ? (int) $ligneData['id'] : null;
                    if ($oldId !== null && isset($affectationsSnapshot[$oldId])) {
                        foreach ($affectationsSnapshot[$oldId] as $affData) {
                            $newLigne->affectations()->create($affData);
                        }
                    }
                }
            }

            return $transaction->fresh();
        });
    }

    public function delete(Transaction $transaction): void
    {
        if ($transaction->rapprochement_id !== null) {
            throw new \RuntimeException('Cette transaction est pointée dans un rapprochement et ne peut pas être supprimée.');
        }
        DB::transaction(function () use ($transaction) {
            $transaction->lignes()->each(function (TransactionLigne $ligne) {
                $ligne->affectations()->delete();
                $ligne->delete();
            });
            $transaction->delete();
        });
    }

    public function affecterLigne(TransactionLigne $ligne, array $affectations): void
    {
        DB::transaction(function () use ($ligne, $affectations) {
            if (count($affectations) === 0) {
                throw new \RuntimeException('La liste des affectations ne peut pas être vide.');
            }
            $total = 0;
            foreach ($affectations as $a) {
                if ((int) round((float) ($a['montant'] ?? 0) * 100) <= 0) {
                    throw new \RuntimeException('Chaque affectation doit avoir un montant positif.');
                }
                $total += (int) round((float) $a['montant'] * 100);
            }
            $attendu = (int) round((float) $ligne->montant * 100);
            if ($total !== $attendu) {
                throw new \RuntimeException(
                    "La somme des affectations ({$total} centimes) ne correspond pas au montant de la ligne ({$attendu} centimes)."
                );
            }
            $ligne->affectations()->delete();
            foreach ($affectations as $a) {
                $ligne->affectations()->create([
                    'operation_id' => $a['operation_id'] ?: null,
                    'seance'       => $a['seance'] ?: null,
                    'montant'      => $a['montant'],
                    'notes'        => $a['notes'] ?: null,
                ]);
            }
        });
    }

    public function supprimerAffectations(TransactionLigne $ligne): void
    {
        DB::transaction(fn () => $ligne->affectations()->forceDelete());
    }

    private function assertLockedInvariants(Transaction $transaction, array $data, array $lignes): void
    {
        if ($transaction->date->format('Y-m-d') !== $data['date']) {
            throw new \RuntimeException('La date ne peut pas être modifiée sur une transaction rapprochée.');
        }
        if ((int) $transaction->compte_id !== (int) $data['compte_id']) {
            throw new \RuntimeException('Le compte bancaire ne peut pas être modifié sur une transaction rapprochée.');
        }
        if ((int) round((float) $transaction->montant_total * 100) !== (int) round((float) $data['montant_total'] * 100)) {
            throw new \RuntimeException('Le montant total ne peut pas être modifié sur une transaction rapprochée.');
        }
        $existingLignes = $transaction->lignes()->get()->keyBy('id');
        if (count($lignes) !== $existingLignes->count()) {
            throw new \RuntimeException('Le nombre de lignes ne peut pas être modifié sur une transaction rapprochée.');
        }
        foreach ($lignes as $ligneData) {
            $id = $ligneData['id'] ?? null;
            if ($id === null || ! $existingLignes->has($id)) {
                throw new \RuntimeException('Ligne inconnue ou sans identifiant sur une transaction rapprochée.');
            }
            $existing = $existingLignes->get($id);
            if ((int) round((float) $existing->montant * 100) !== (int) round((float) $ligneData['montant'] * 100)) {
                throw new \RuntimeException('Le montant d\'une ligne ne peut pas être modifié sur une transaction rapprochée.');
            }
        }
    }
}
```

- [ ] **Lancer les tests — vérifier qu'ils passent**

```bash
./vendor/bin/sail artisan test tests/Feature/TransactionServiceTest.php tests/Feature/TransactionServiceLockTest.php tests/Feature/TransactionAffectationTest.php 2>&1 | tail -5
```
Attendu : tous verts.

- [ ] **Commit**

```bash
git add app/Services/TransactionService.php tests/Feature/TransactionServiceTest.php tests/Feature/TransactionServiceLockTest.php tests/Feature/TransactionAffectationTest.php
git commit -m "feat: TransactionService + tests (fusion DepenseService + RecetteService)"
```

---

## Task 6 : Adapter TransactionCompteService

**Files:**
- Modify: `app/Services/TransactionCompteService.php`
- Modify: `app/Livewire/TransactionCompteList.php`

- [ ] **Simplifier `buildUnion()` dans `TransactionCompteService`**

Remplacer la double sous-requête `$recettes UNION $depenses` par une requête unique sur `transactions` :

```php
$transactions = DB::table('transactions as tx')
    ->leftJoin('tiers as t', 't.id', '=', 'tx.tiers_id')
    ->selectRaw("
        tx.id,
        tx.type as source_type,
        tx.date,
        CASE WHEN tx.type = 'depense' THEN 'Dépense' ELSE 'Recette' END as type_label,
        TRIM(CONCAT(COALESCE(t.prenom,''), ' ', COALESCE(t.nom,''))) as tiers,
        t.type as tiers_type,
        tx.libelle,
        tx.reference,
        CASE WHEN tx.type = 'depense' THEN -(tx.montant_total) ELSE tx.montant_total END as montant,
        tx.mode_paiement,
        tx.pointe,
        tx.numero_piece
    ")
    ->where('tx.compte_id', $id)
    ->whereNull('tx.deleted_at')
    ->when($dateDebut, fn ($q) => $q->where('tx.date', '>=', $dateDebut))
    ->when($dateFin, fn ($q) => $q->where('tx.date', '<=', $dateFin))
    ->when($tiersLike, fn ($q) => $q->whereRaw(
        "TRIM(CONCAT(COALESCE(t.prenom,''), ' ', COALESCE(t.nom,''))) LIKE ?", [$tiersLike]
    ));
```

Retourner `$transactions->unionAll($dons)->unionAll($cotisations)->unionAll($virementsSource)->unionAll($virementsDestination)`.

- [ ] **Mettre à jour `TransactionCompteList`**

Dans `deleteTransaction()`, remplacer les cases `'recette'` et `'depense'` par un cas unique qui appelle `TransactionService::delete()` :

```php
match ($sourceType) {
    'depense', 'recette' => $this->deleteTransactionGeneric($id),
    'don'       => $this->deleteDon($id),
    // ...
};
```

Dans `redirectToEdit()`, remplacer les cases `recette`/`depense` :
```php
'depense', 'recette' => route('transactions.index').'?edit='.$id,
```

- [ ] **Lancer les tests TransactionCompteService si existants, sinon test manuel**

```bash
./vendor/bin/sail artisan test --filter=TransactionCompte 2>&1 | tail -5
```

- [ ] **Commit**

```bash
git add app/Services/TransactionCompteService.php app/Livewire/TransactionCompteList.php
git commit -m "refactor: TransactionCompteService utilise la table transactions unifiée"
```

---

## Task 7 : Adapter RapportService + Dashboard

**Files:**
- Modify: `app/Services/RapportService.php`
- Modify: `app/Livewire/Dashboard.php`

- [ ] **Mettre à jour `RapportService`**

Remplacer dans `fetchDepenseRows()` et ses helpers : `depenses` → `transactions WHERE type='depense'`, `depense_lignes` → `transaction_lignes`.
Remplacer dans `fetchProduitsRows()` et ses helpers : `recettes` → `transactions WHERE type='recette'`, `recette_lignes` → `transaction_lignes`.

Pour chaque méthode privée qui fait une requête SQL sur `depenses` ou `recettes`, adapter le `FROM` et les noms de colonnes.

- [ ] **Mettre à jour `Dashboard`**

Remplacer `use App\Models\Depense` → `use App\Models\Transaction` et `use App\Models\Recette` (idem).
Adapter les agrégats :
```php
// Avant :
$totalDepenses = Depense::forExercice($exercice)->sum('montant_total');
$totalRecettes = Recette::forExercice($exercice)->sum('montant_total');

// Après :
$totalDepenses = Transaction::where('type', 'depense')->forExercice($exercice)->sum('montant_total');
$totalRecettes = Transaction::where('type', 'recette')->forExercice($exercice)->sum('montant_total');
```

- [ ] **Tester les rapports manuellement** (ouvrir `/rapports` dans le navigateur)

- [ ] **Lancer la suite de tests**

```bash
./vendor/bin/sail artisan test 2>&1 | tail -5
```

- [ ] **Commit**

```bash
git add app/Services/RapportService.php app/Livewire/Dashboard.php
git commit -m "refactor: RapportService et Dashboard sur table transactions"
```

---

## Task 8 : TransactionList (Livewire + vue)

**Files:**
- Create: `app/Livewire/TransactionList.php`
- Create: `resources/views/livewire/transaction-list.blade.php`
- Create: `resources/views/transactions/index.blade.php`

- [ ] **Créer `app/Livewire/TransactionList.php`**

S'inspirer de `DepenseList.php`. Ajouter une propriété `typeFilter` (valeurs : `''`, `'depense'`, `'recette'`).

```php
<?php
declare(strict_types=1);
namespace App\Livewire;

use App\Enums\TypeTransaction;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Transaction;
use App\Services\ExerciceService;
use App\Services\TransactionService;
use App\Livewire\Concerns\WithPerPage;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class TransactionList extends Component
{
    use WithPagination, WithPerPage;

    protected string $paginationTheme = 'bootstrap';

    #[Url(as: 'type')]
    public string $typeFilter = ''; // '' | 'depense' | 'recette'

    public ?int $categorie_id = null;
    public ?int $sous_categorie_id = null;
    public ?int $operation_id = null;
    public ?int $compte_id = null;
    public ?string $pointe = null;
    public ?string $tiers = null;

    public function updatedTypeFilter(): void { $this->resetPage(); }
    public function updatedCategorieId(): void { $this->sous_categorie_id = null; $this->resetPage(); }
    public function updatedSousCategorieId(): void { $this->resetPage(); }
    public function updatedOperationId(): void { $this->resetPage(); }
    public function updatedCompteId(): void { $this->resetPage(); }
    public function updatedPointe(): void { $this->resetPage(); }
    public function updatedTiers(): void { $this->resetPage(); }

    public function delete(int $id): void
    {
        $transaction = Transaction::findOrFail($id);
        try {
            app(TransactionService::class)->delete($transaction);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    #[On('transaction-saved')]
    public function onTransactionSaved(): void {}

    #[On('csv-imported')]
    public function refreshList(): void {}

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();

        $query = Transaction::with(['lignes.sousCategorie.categorie', 'compte', 'saisiPar'])
            ->forExercice($exercice)
            ->latest('date')->latest('id');

        if ($this->typeFilter !== '') {
            $query->where('type', $this->typeFilter);
        }

        // Filtres catégorie/sous-catégorie filtrés par type si typeFilter est défini
        if ($this->categorie_id) {
            $query->whereHas('lignes.sousCategorie', fn ($q) => $q->where('categorie_id', $this->categorie_id));
        }
        if ($this->sous_categorie_id) {
            $query->whereHas('lignes', fn ($q) => $q->where('sous_categorie_id', $this->sous_categorie_id));
        }
        if ($this->operation_id) {
            $opId = $this->operation_id;
            $query->whereHas('lignes', fn ($q) => $q
                ->where(fn ($inner) => $inner->where('operation_id', $opId)->whereDoesntHave('affectations'))
                ->orWhereHas('affectations', fn ($qa) => $qa->where('operation_id', $opId))
            );
        }
        if ($this->compte_id) {
            $query->where('compte_id', $this->compte_id);
        }
        if ($this->pointe !== null && $this->pointe !== '') {
            $query->where('pointe', $this->pointe === '1');
        }
        if ($this->tiers) {
            $tiersSearch = $this->tiers;
            $query->whereHas('tiers', fn ($q) => $q->whereRaw(
                "TRIM(CONCAT(COALESCE(prenom,''), ' ', COALESCE(nom,''))) LIKE ?", ["%{$tiersSearch}%"]
            ));
        }

        // Catégories filtrées selon le typeFilter
        $typeCategorie = $this->typeFilter !== '' ? $this->typeFilter : null;
        $categories = Categorie::when($typeCategorie, fn ($q) => $q->where('type', $typeCategorie))
            ->orderBy('nom')->get();

        return view('livewire.transaction-list', [
            'transactions' => $query->paginate($this->effectivePerPage()),
            'categories'   => $categories,
            'operations'   => Operation::orderBy('nom')->get(),
            'comptes'      => CompteBancaire::orderBy('nom')->get(),
            'typeLabels'   => TypeTransaction::cases(),
        ]);
    }
}
```

- [ ] **Créer `resources/views/livewire/transaction-list.blade.php`**

Copier `resources/views/livewire/depense-list.blade.php` comme base. Adapter :
- Filtre type en en-tête : boutons radio `Toutes / Dépenses / Recettes` liés à `wire:model.live="typeFilter"`
- Colonne "Type" dans le tableau (affiche le label dépense/recette avec badge coloré)
- Event `depense-saved` / `recette-saved` → `transaction-saved`
- Boutons "Nouvelle dépense" et "Nouvelle recette" qui émettent `$dispatch('open-transaction-form', {type: 'depense'})` et `{type: 'recette'}`

- [ ] **Créer `resources/views/transactions/index.blade.php`**

Copier `resources/views/depenses/index.blade.php`, remplacer les composants Livewire `depense-list` → `transaction-list` et `depense-form` → `transaction-form`.

- [ ] **Lancer les tests Livewire existants pour TransactionList (si écrits)**

```bash
./vendor/bin/sail artisan test --filter=TransactionList 2>&1 | tail -5
```

- [ ] **Commit**

```bash
git add app/Livewire/TransactionList.php resources/views/livewire/transaction-list.blade.php resources/views/transactions/index.blade.php
git commit -m "feat: TransactionList Livewire avec filtre type dépense/recette"
```

---

## Task 9 : TransactionForm (Livewire + vue)

**Files:**
- Create: `app/Livewire/TransactionForm.php`
- Create: `resources/views/livewire/transaction-form.blade.php`

- [ ] **Créer `app/Livewire/TransactionForm.php`**

Copier `DepenseForm.php` comme base. Changements clés :
- Propriété `public string $type = ''` (initialisée depuis l'argument ou la transaction en édition)
- `$transactionId` au lieu de `$depenseId`
- Event écouté : `#[On('open-transaction-form')]` au lieu de `edit-depense`
- `showNewForm(string $type)` reçoit le type en argument
- Dans `render()` : filtrer `sousCategories` par `categorie.type = $this->type` (via `TypeCategorie` ou comparaison string)
- Remplacer `DepenseLigne` → `TransactionLigne`, `DepenseLigneAffectation` → `TransactionLigneAffectation`
- Remplacer `DepenseService` → `TransactionService`
- Dispatch `transaction-saved` au lieu de `depense-saved`
- Label bouton soumettre : `"Enregistrer la " . ($this->type === 'depense' ? 'dépense' : 'recette')`

```php
public function showNewForm(string $type): void
{
    $this->reset([...]);
    $this->type = $type;
    $this->isLocked = false;
    $this->resetValidation();
    $this->showForm = true;
    $this->date = app(ExerciceService::class)->defaultDate();
    $this->compte_id = Transaction::where('saisi_par', auth()->id())
        ->whereNotNull('compte_id')
        ->latest()
        ->value('compte_id');
    $this->addLigne();
}

#[On('open-transaction-form')]
public function openForm(string $type, ?int $id = null): void
{
    if ($id !== null) {
        $this->edit($id);
    } else {
        $this->showNewForm($type);
    }
}

#[On('edit-transaction')]
public function edit(int $id): void
{
    $transaction = Transaction::with('lignes')->findOrFail($id);
    $this->type = $transaction->type->value;
    // ... reste identique à DepenseForm::edit()
}
```

- [ ] **Créer `resources/views/livewire/transaction-form.blade.php`**

Copier `resources/views/livewire/depense-form.blade.php` comme base. Adapter :
- Afficher le type en lecture seule (badge "Dépense" ou "Recette") en haut du formulaire
- Label bouton soumettre dynamique : `{{ $type === 'depense' ? 'Enregistrer la dépense' : 'Enregistrer la recette' }}`

- [ ] **Vérifier l'affichage en ouvrant `/transactions` dans le navigateur**

Tester : créer une dépense, créer une recette, éditer chacune, vérifier le filtre.

- [ ] **Commit**

```bash
git add app/Livewire/TransactionForm.php resources/views/livewire/transaction-form.blade.php
git commit -m "feat: TransactionForm Livewire unifié (dépense + recette)"
```

---

## Task 10 : Routes + Navigation

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`

- [ ] **Mettre à jour `routes/web.php`**

```php
// Remplacer :
Route::view('/depenses', 'depenses.index')->name('depenses.index');
Route::view('/recettes', 'recettes.index')->name('recettes.index');
Route::get('/depenses/import/template', ...)->name('depenses.import.template');
Route::get('/recettes/import/template', ...)->name('recettes.import.template');

// Par :
Route::view('/transactions', 'transactions.index')->name('transactions.index');
Route::get('/transactions/import/template', [CsvImportController::class, 'template'])
    ->defaults('type', 'transaction')
    ->name('transactions.import.template');
```

- [ ] **Mettre à jour le menu dans `resources/views/layouts/app.blade.php`**

Remplacer les entrées "Dépenses" et "Recettes" par le nouveau menu :

```blade
{{-- Dropdown Transactions --}}
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle {{ request()->routeIs('transactions.*') || request()->routeIs('virements.*') || request()->routeIs('dons.*') || request()->routeIs('cotisations.*') ? 'active' : '' }}"
       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-arrow-down-up"></i> Transactions
    </a>
    <ul class="dropdown-menu">
        <li>
            <a class="dropdown-item {{ request()->routeIs('transactions.*') && !request()->query('type') ? 'active' : '' }}"
               href="{{ route('transactions.index') }}">
                <i class="bi bi-list-ul"></i> Toutes
            </a>
        </li>
        <li>
            <a class="dropdown-item {{ request()->query('type') === 'depense' ? 'active' : '' }}"
               href="{{ route('transactions.index') }}?type=depense">
                <i class="bi bi-arrow-down-circle"></i> Dépenses
            </a>
        </li>
        <li>
            <a class="dropdown-item {{ request()->query('type') === 'recette' ? 'active' : '' }}"
               href="{{ route('transactions.index') }}?type=recette">
                <i class="bi bi-arrow-up-circle"></i> Recettes
            </a>
        </li>
        <li><hr class="dropdown-divider"></li>
        @if (Route::has('virements.index'))
        <li>
            <a class="dropdown-item {{ request()->routeIs('virements.*') ? 'active' : '' }}"
               href="{{ route('virements.index') }}">
                <i class="bi bi-arrow-left-right"></i> Virements
            </a>
        </li>
        @endif
        <li><hr class="dropdown-divider"></li>
        @if (Route::has('dons.index'))
        <li>
            <a class="dropdown-item {{ request()->routeIs('dons.*') ? 'active' : '' }}"
               href="{{ route('dons.index') }}">
                <i class="bi bi-heart"></i> Dons
            </a>
        </li>
        @endif
        @if (Route::has('cotisations.index'))
        <li>
            <a class="dropdown-item {{ request()->routeIs('cotisations.*') ? 'active' : '' }}"
               href="{{ route('cotisations.index') }}">
                <i class="bi bi-person-check"></i> Cotisations
            </a>
        </li>
        @endif
    </ul>
</li>
```

- [ ] **Vérifier la navigation dans le navigateur**

Tester les 3 liens (Toutes, Dépenses, Recettes) et vérifier que le filtre se pré-sélectionne.

- [ ] **Lancer la suite complète**

```bash
./vendor/bin/sail artisan test 2>&1 | tail -5
```

- [ ] **Commit**

```bash
git add routes/web.php resources/views/layouts/app.blade.php
git commit -m "feat: route /transactions et menu navigation unifié"
```

---

## Task 11 : Adapter les composants restants

**Files:**
- Modify: `app/Livewire/TiersTransactions.php`
- Modify: `app/Livewire/RapprochementDetail.php`
- Modify: `app/Livewire/ImportCsv.php` (si applicable)

- [ ] **Adapter `RapprochementBancaire`**

Remplacer les relations `depenses()` et `recettes()` par `transactions()` (toutes) et deux méthodes dérivées :

```php
public function transactions(): HasMany
{
    return $this->hasMany(Transaction::class, 'rapprochement_id');
}

public function depenses(): HasMany
{
    return $this->transactions()->where('type', 'depense');
}

public function recettes(): HasMany
{
    return $this->transactions()->where('type', 'recette');
}
```

> Supprimer les `use App\Models\Depense` et `use App\Models\Recette`, ajouter `use App\Models\Transaction`.

- [ ] **Adapter `TiersTransactions`**

Remplacer les requêtes sur `depenses` et `recettes` par `Transaction::where('tiers_id', ...)`. Adapter les noms de modèles importés.

- [ ] **Adapter `RapprochementDetail`**

Remplacer `Depense::find()` / `Recette::find()` → `Transaction::find()`. Adapter les dispatch d'events (`depense-saved` → `transaction-saved`, idem recette).

- [ ] **Vérifier `ImportCsv`**

```bash
grep -n "Depense\|Recette\|depense\|recette" app/Livewire/ImportCsv.php
```
Adapter si des références sont trouvées.

- [ ] **Lancer la suite complète**

```bash
./vendor/bin/sail artisan test 2>&1 | tail -5
```
Attendu : tous les nouveaux tests passent, pas de régression.

- [ ] **Commit**

```bash
git add app/Livewire/TiersTransactions.php app/Livewire/RapprochementDetail.php app/Livewire/ImportCsv.php
git commit -m "refactor: adapter TiersTransactions, RapprochementDetail, ImportCsv"
```

---

## Task 12 : Nettoyage — suppression des anciens fichiers

- [ ] **Vérifier qu'aucune référence aux anciens modèles ne subsiste**

```bash
grep -rn "DepenseService\|RecetteService\|DepenseLigne\|RecetteLigne\|App\\\\Models\\\\Depense\b\|App\\\\Models\\\\Recette\b" app/ resources/ tests/ routes/ --include="*.php" --include="*.blade.php"
```
Attendu : aucun résultat (ou uniquement dans les fichiers à supprimer).

- [ ] **Supprimer les fichiers PHP**

```bash
rm app/Models/Depense.php app/Models/Recette.php
rm app/Models/DepenseLigne.php app/Models/RecetteLigne.php
rm app/Models/DepenseLigneAffectation.php app/Models/RecetteLigneAffectation.php
rm app/Services/DepenseService.php app/Services/RecetteService.php
rm app/Livewire/DepenseForm.php app/Livewire/DepenseList.php
rm app/Livewire/RecetteForm.php app/Livewire/RecetteList.php
rm database/factories/DepenseFactory.php database/factories/RecetteFactory.php
rm database/factories/DepenseLigneFactory.php database/factories/RecetteLigneFactory.php
```

- [ ] **Supprimer les vues**

```bash
rm resources/views/livewire/depense-form.blade.php resources/views/livewire/depense-list.blade.php
rm resources/views/livewire/recette-form.blade.php resources/views/livewire/recette-list.blade.php
rm -r resources/views/depenses resources/views/recettes
```

- [ ] **Supprimer les anciens tests**

```bash
rm tests/Feature/DepenseTest.php tests/Feature/DepenseAffectationTest.php tests/Feature/DepenseServiceLockTest.php
rm tests/Feature/RecetteTest.php tests/Feature/RecetteAffectationTest.php tests/Feature/RecetteServiceLockTest.php
```

- [ ] **Lancer la suite complète une dernière fois**

```bash
./vendor/bin/sail artisan test 2>&1 | tail -5
```
Attendu : tous verts, aucun fichier manquant.

- [ ] **Vider le cache**

```bash
./vendor/bin/sail artisan optimize:clear
```

- [ ] **Commit final**

```bash
git add -A
git commit -m "chore: suppression des anciens fichiers dépenses/recettes après fusion"
```

---

## Vérification finale

- [ ] Ouvrir `/transactions` → liste avec filtre "Toutes"
- [ ] Cliquer "Dépenses" dans le menu → filtre pré-sélectionné
- [ ] Créer une dépense via "Nouvelle dépense" → apparaît dans la liste
- [ ] Créer une recette via "Nouvelle recette" → apparaît dans la liste
- [ ] Ouvrir le rapport compte de résultat → données cohérentes
- [ ] Ouvrir un rapprochement existant → transactions affichées correctement
- [ ] `./vendor/bin/sail artisan test` → 0 échec
