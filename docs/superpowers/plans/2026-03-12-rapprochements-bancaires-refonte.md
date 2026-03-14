# Rapprochements Bancaires — Refonte Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer le système de pointage simple par un vrai module de rapprochement bancaire avec gestion des relevés, calcul d'écart en temps réel et verrouillage.

**Architecture:** Nouvelle table `rapprochements_bancaires` + ajout de `rapprochement_id` (nullable FK) sur Depense, Recette, Don, Cotisation + `rapprochement_source_id`/`rapprochement_destination_id` sur VirementInterne. Deux nouveaux composants Livewire remplacent l'ancien `Rapprochement.php` : `RapprochementList` (sélection compte + liste des rapprochements) et `RapprochementDetail` (interface de pointage). Les formulaires DepenseForm et RecetteForm gèlent date/montant/compte si l'écriture est pointée dans un rapprochement verrouillé.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 CDN, Pest PHP, MySQL via Sail

**Prérequis :** Le module VirementInterne (migration 000014 + modèle) doit être en place. La route `/virements` est déjà présente dans `routes/web.php`.

---

## Fichiers créés / modifiés

| Fichier | Action |
|---|---|
| `database/migrations/2026_03_12_200001_create_rapprochements_bancaires_table.php` | Créer |
| `database/migrations/2026_03_12_200002_add_rapprochement_id_to_transactions.php` | Créer |
| `app/Enums/StatutRapprochement.php` | Créer |
| `app/Models/RapprochementBancaire.php` | Créer |
| `app/Models/Depense.php` | Modifier (relation + isLocked) |
| `app/Models/Recette.php` | Modifier (relation + isLocked) |
| `app/Models/Don.php` | Modifier (relation) |
| `app/Models/Cotisation.php` | Modifier (relation) |
| `app/Models/VirementInterne.php` | Modifier (rapprochement_source_id / rapprochement_destination_id) |
| `app/Services/RapprochementBancaireService.php` | Créer |
| `app/Services/RapprochementService.php` | Modifier (mettre à jour soldeTheorique) |
| `app/Livewire/RapprochementList.php` | Créer |
| `app/Livewire/RapprochementDetail.php` | Créer |
| `app/Livewire/Rapprochement.php` | Supprimer (remplacé) |
| `resources/views/rapprochement/index.blade.php` | Modifier |
| `resources/views/rapprochement/detail.blade.php` | Créer |
| `resources/views/livewire/rapprochement-list.blade.php` | Créer |
| `resources/views/livewire/rapprochement-detail.blade.php` | Créer |
| `resources/views/livewire/rapprochement.blade.php` | Supprimer |
| `routes/web.php` | Modifier (ajouter route detail) |
| `app/Livewire/DepenseForm.php` | Modifier (champs gelés si verrouillé) |
| `app/Livewire/RecetteForm.php` | Modifier |
| `resources/views/livewire/depense-form.blade.php` | Modifier |
| `resources/views/livewire/recette-form.blade.php` | Modifier |
| `tests/Feature/Services/RapprochementBancaireServiceTest.php` | Créer |

---

## Chunk 1 : Migrations, Enum, Modèles

### Task 1 : Migrations

**Files:**
- Create: `database/migrations/2026_03_12_200001_create_rapprochements_bancaires_table.php`
- Create: `database/migrations/2026_03_12_200002_add_rapprochement_id_to_transactions.php`

- [ ] **Step 1 : Créer la migration de la table rapprochements_bancaires**

```php
<?php
// database/migrations/2026_03_12_200001_create_rapprochements_bancaires_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rapprochements_bancaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compte_id')->constrained('comptes_bancaires');
            $table->date('date_fin');
            $table->decimal('solde_ouverture', 10, 2);
            $table->decimal('solde_fin', 10, 2);
            $table->string('statut', 20)->default('en_cours'); // 'en_cours' | 'verrouille'
            $table->foreignId('saisi_par')->constrained('users');
            $table->timestamp('verrouille_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rapprochements_bancaires');
    }
};
```

- [ ] **Step 2 : Créer la migration d'ajout des FK sur les transactions**

```php
<?php
// database/migrations/2026_03_12_200002_add_rapprochement_id_to_transactions.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['depenses', 'recettes', 'dons', 'cotisations'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('rapprochement_id')
                    ->nullable()
                    ->after('pointe')
                    ->constrained('rapprochements_bancaires')
                    ->nullOnDelete();
            });
        }

        Schema::table('virements_internes', function (Blueprint $table) {
            $table->foreignId('rapprochement_source_id')
                ->nullable()
                ->after('notes')
                ->constrained('rapprochements_bancaires')
                ->nullOnDelete();
            $table->foreignId('rapprochement_destination_id')
                ->nullable()
                ->after('rapprochement_source_id')
                ->constrained('rapprochements_bancaires')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['depenses', 'recettes', 'dons', 'cotisations'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropForeign(['rapprochement_id']);
                $table->dropColumn('rapprochement_id');
            });
        }
        Schema::table('virements_internes', function (Blueprint $table) {
            $table->dropForeign(['rapprochement_source_id']);
            $table->dropForeign(['rapprochement_destination_id']);
            $table->dropColumn(['rapprochement_source_id', 'rapprochement_destination_id']);
        });
    }
};
```

- [ ] **Step 3 : Lancer les migrations**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate:status
```

Vérifier que les 2 nouvelles migrations apparaissent avec le statut "Ran".

- [ ] **Step 4 : Commit**

```bash
git add database/migrations/2026_03_12_200001_create_rapprochements_bancaires_table.php \
        database/migrations/2026_03_12_200002_add_rapprochement_id_to_transactions.php
git commit -m "feat: migrations for rapprochements_bancaires and rapprochement_id on transactions"
```

---

### Task 2 : Enum + Modèle RapprochementBancaire

**Files:**
- Create: `app/Enums/StatutRapprochement.php`
- Create: `app/Models/RapprochementBancaire.php`

- [ ] **Step 1 : Créer l'enum StatutRapprochement**

```php
<?php
// app/Enums/StatutRapprochement.php

declare(strict_types=1);

namespace App\Enums;

enum StatutRapprochement: string
{
    case EnCours = 'en_cours';
    case Verrouille = 'verrouille';

    public function label(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::Verrouille => 'Verrouillé',
        };
    }
}
```

- [ ] **Step 2 : Créer le modèle RapprochementBancaire**

```php
<?php
// app/Models/RapprochementBancaire.php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutRapprochement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RapprochementBancaire extends Model
{
    use HasFactory;

    protected $table = 'rapprochements_bancaires';

    protected $fillable = [
        'compte_id',
        'date_fin',
        'solde_ouverture',
        'solde_fin',
        'statut',
        'saisi_par',
        'verrouille_at',
    ];

    protected function casts(): array
    {
        return [
            'date_fin' => 'date',
            'solde_ouverture' => 'decimal:2',
            'solde_fin' => 'decimal:2',
            'statut' => StatutRapprochement::class,
            'verrouille_at' => 'datetime',
        ];
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function depenses(): HasMany
    {
        return $this->hasMany(Depense::class, 'rapprochement_id');
    }

    public function recettes(): HasMany
    {
        return $this->hasMany(Recette::class, 'rapprochement_id');
    }

    public function dons(): HasMany
    {
        return $this->hasMany(Don::class, 'rapprochement_id');
    }

    public function cotisations(): HasMany
    {
        return $this->hasMany(Cotisation::class, 'rapprochement_id');
    }

    public function virementsSource(): HasMany
    {
        return $this->hasMany(VirementInterne::class, 'rapprochement_source_id');
    }

    public function virementsDestination(): HasMany
    {
        return $this->hasMany(VirementInterne::class, 'rapprochement_destination_id');
    }

    public function isVerrouille(): bool
    {
        return $this->statut === StatutRapprochement::Verrouille;
    }

    public function isEnCours(): bool
    {
        return $this->statut === StatutRapprochement::EnCours;
    }
}
```

- [ ] **Step 3 : Commit**

```bash
git add app/Enums/StatutRapprochement.php app/Models/RapprochementBancaire.php
git commit -m "feat: StatutRapprochement enum and RapprochementBancaire model"
```

---

### Task 3 : Mise à jour des modèles existants

**Files:**
- Modify: `app/Models/Depense.php`
- Modify: `app/Models/Recette.php`
- Modify: `app/Models/Don.php`
- Modify: `app/Models/Cotisation.php`
- Modify: `app/Models/VirementInterne.php`

- [ ] **Step 1 : Mettre à jour Depense.php**

Lire le fichier, puis :
- Ajouter `'rapprochement_id'` dans `$fillable`
- Ajouter dans `casts()` : *(rien de spécial, c'est un int nullable)*
- Ajouter les méthodes :

```php
public function rapprochement(): BelongsTo
{
    return $this->belongsTo(RapprochementBancaire::class, 'rapprochement_id');
}

public function isLockedByRapprochement(): bool
{
    return $this->rapprochement_id !== null
        && $this->rapprochement?->isVerrouille() === true;
}
```

- [ ] **Step 2 : Même chose pour Recette.php**

Ajouter `'rapprochement_id'` dans `$fillable` et les deux méthodes identiques à Depense.

- [ ] **Step 3 : Mettre à jour Don.php**

Ajouter `'rapprochement_id'` dans `$fillable` et la relation :

```php
public function rapprochement(): BelongsTo
{
    return $this->belongsTo(RapprochementBancaire::class, 'rapprochement_id');
}
```

- [ ] **Step 4 : Même chose pour Cotisation.php**

Ajouter `'rapprochement_id'` dans `$fillable` et la relation.

- [ ] **Step 5 : Mettre à jour VirementInterne.php**

Ajouter dans `$fillable` : `'rapprochement_source_id'`, `'rapprochement_destination_id'`

Ajouter les relations :

```php
public function rapprochementSource(): BelongsTo
{
    return $this->belongsTo(RapprochementBancaire::class, 'rapprochement_source_id');
}

public function rapprochementDestination(): BelongsTo
{
    return $this->belongsTo(RapprochementBancaire::class, 'rapprochement_destination_id');
}

public function isLockedByRapprochement(): bool
{
    $lockedBySource = $this->rapprochement_source_id !== null
        && $this->rapprochementSource?->isVerrouille() === true;
    $lockedByDestination = $this->rapprochement_destination_id !== null
        && $this->rapprochementDestination?->isVerrouille() === true;

    return $lockedBySource || $lockedByDestination;
}
```

- [ ] **Step 6 : Commit**

```bash
git add app/Models/Depense.php app/Models/Recette.php app/Models/Don.php \
        app/Models/Cotisation.php app/Models/VirementInterne.php
git commit -m "feat: add rapprochement relationships and isLocked helpers to transaction models"
```

---

## Chunk 2 : Service + Tests

### Task 4 : RapprochementBancaireService avec tests

**Files:**
- Create: `app/Services/RapprochementBancaireService.php`
- Create: `tests/Feature/Services/RapprochementBancaireServiceTest.php`

Le service encapsule toute la logique métier du module.

**Logique de calcul des soldes :**
- `solde_pointage` = `solde_ouverture` + somme des recettes pointées + somme des dons pointés + somme des cotisations pointées + somme des virements entrants pointés - somme des dépenses pointées - somme des virements sortants pointés
- `écart` = `solde_fin` - `solde_pointage` (doit être 0 pour verrouiller)

**Logique de toggle :**
- Si la transaction est déjà dans ce rapprochement (`rapprochement_id == $rapprochement->id`) : retirer (mettre `rapprochement_id = null`, `pointe = false`)
- Sinon : ajouter (mettre `rapprochement_id = $rapprochement->id`, `pointe = true`)
- Pour les virements : selon le `$side` ('source' ou 'destination'), mettre à jour `rapprochement_source_id` ou `rapprochement_destination_id`

- [ ] **Step 1 : Écrire les tests (TDD)**

```php
<?php
// tests/Feature/Services/RapprochementBancaireServiceTest.php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\RapprochementBancaire;
use App\Models\Recette;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\RapprochementBancaireService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compte = CompteBancaire::factory()->create([
        'solde_initial' => 1000.00,
        'date_solde_initial' => '2025-09-01',
    ]);
    $this->service = app(RapprochementBancaireService::class);
});

test('calculerSoldeOuverture retourne solde_initial si aucun rapprochement verrouillé', function () {
    $solde = $this->service->calculerSoldeOuverture($this->compte);
    expect($solde)->toBe(1000.00);
});

test('calculerSoldeOuverture retourne solde_fin du dernier rapprochement verrouillé', function () {
    RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'solde_fin' => 1500.00,
        'statut' => StatutRapprochement::Verrouille,
        'date_fin' => '2025-10-31',
        'saisi_par' => $this->user->id,
    ]);
    $solde = $this->service->calculerSoldeOuverture($this->compte);
    expect($solde)->toBe(1500.00);
});

test('create crée un rapprochement avec le bon solde_ouverture', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 1200.00);

    expect($rapprochement->statut)->toBe(StatutRapprochement::EnCours)
        ->and((float) $rapprochement->solde_ouverture)->toBe(1000.00)
        ->and((float) $rapprochement->solde_fin)->toBe(1200.00);
});

test('create échoue si un rapprochement en cours existe déjà', function () {
    $this->service->create($this->compte, '2025-10-31', 1200.00);

    expect(fn () => $this->service->create($this->compte, '2025-11-30', 1300.00))
        ->toThrow(RuntimeException::class);
});

test('calculerSoldePointage prend en compte les recettes pointées', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 1500.00);
    Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 300.00,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    $solde = $this->service->calculerSoldePointage($rapprochement->fresh());
    expect($solde)->toBe(1300.00); // 1000 + 300
});

test('calculerSoldePointage déduit les dépenses pointées', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 800.00);
    Depense::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 200.00,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    $solde = $this->service->calculerSoldePointage($rapprochement->fresh());
    expect($solde)->toBe(800.00); // 1000 - 200
});

test('toggleTransaction ajoute une dépense au rapprochement', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 800.00);
    $depense = Depense::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 200.00,
        'pointe' => false,
    ]);

    $this->service->toggleTransaction($rapprochement, 'depense', $depense->id);

    expect($depense->fresh()->rapprochement_id)->toBe($rapprochement->id)
        ->and($depense->fresh()->pointe)->toBeTrue();
});

test('toggleTransaction retire une dépense déjà pointée', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 800.00);
    $depense = Depense::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 200.00,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    $this->service->toggleTransaction($rapprochement, 'depense', $depense->id);

    expect($depense->fresh()->rapprochement_id)->toBeNull()
        ->and($depense->fresh()->pointe)->toBeFalse();
});

test('verrouiller échoue si écart non nul', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 1500.00);

    expect(fn () => $this->service->verrouiller($rapprochement))
        ->toThrow(RuntimeException::class);
});

test('verrouiller réussit quand écart = 0', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 1000.00);
    // solde_ouverture = 1000, solde_fin = 1000, aucune transaction → écart = 0

    $this->service->verrouiller($rapprochement);

    expect($rapprochement->fresh()->statut)->toBe(StatutRapprochement::Verrouille)
        ->and($rapprochement->fresh()->verrouille_at)->not->toBeNull();
});

test('toggleTransaction lève une exception si rapprochement verrouillé', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'saisi_par' => $this->user->id,
    ]);
    $depense = Depense::factory()->create(['compte_id' => $this->compte->id]);

    expect(fn () => $this->service->toggleTransaction($rapprochement, 'depense', $depense->id))
        ->toThrow(RuntimeException::class);
});
```

- [ ] **Step 2 : Lancer les tests — vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/Services/RapprochementBancaireServiceTest.php
```

Attendu : tous les tests échouent (classe non trouvée).

- [ ] **Step 3 : Créer les factories manquantes si besoin**

Vérifier si `RapprochementBancaire`, `Depense`, `Recette` ont des factories. Si non, les créer avec `./vendor/bin/sail artisan make:factory RapprochementBancaireFactory --model=RapprochementBancaire`. Remplir les factories avec des valeurs minimales valides.

- [ ] **Step 4 : Créer RapprochementBancaireService**

```php
<?php
// app/Services/RapprochementBancaireService.php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutRapprochement;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\RapprochementBancaire;
use App\Models\Recette;
use App\Models\VirementInterne;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RapprochementBancaireService
{
    /**
     * Calcule le solde d'ouverture : solde_fin du dernier rapprochement verrouillé,
     * ou solde_initial du compte si aucun n'existe.
     */
    public function calculerSoldeOuverture(CompteBancaire $compte): float
    {
        $dernier = RapprochementBancaire::where('compte_id', $compte->id)
            ->where('statut', StatutRapprochement::Verrouille)
            ->orderByDesc('date_fin')
            ->first();

        return $dernier ? (float) $dernier->solde_fin : (float) $compte->solde_initial;
    }

    /**
     * Crée un nouveau rapprochement pour un compte.
     * Lève RuntimeException si un rapprochement "en cours" existe déjà sur ce compte.
     */
    public function create(CompteBancaire $compte, string $dateFin, float $soldeFin): RapprochementBancaire
    {
        $enCours = RapprochementBancaire::where('compte_id', $compte->id)
            ->where('statut', StatutRapprochement::EnCours)
            ->exists();

        if ($enCours) {
            throw new RuntimeException("Un rapprochement est déjà en cours pour ce compte.");
        }

        return DB::transaction(function () use ($compte, $dateFin, $soldeFin) {
            return RapprochementBancaire::create([
                'compte_id' => $compte->id,
                'date_fin' => $dateFin,
                'solde_ouverture' => $this->calculerSoldeOuverture($compte),
                'solde_fin' => $soldeFin,
                'statut' => StatutRapprochement::EnCours,
                'saisi_par' => auth()->id(),
            ]);
        });
    }

    /**
     * Calcule le solde pointé courant :
     * solde_ouverture + entrées pointées − sorties pointées.
     */
    public function calculerSoldePointage(RapprochementBancaire $rapprochement): float
    {
        $solde = (float) $rapprochement->solde_ouverture;

        $solde += (float) Recette::where('rapprochement_id', $rapprochement->id)->sum('montant_total');
        $solde += (float) Don::where('rapprochement_id', $rapprochement->id)->sum('montant');
        $solde += (float) Cotisation::where('rapprochement_id', $rapprochement->id)->sum('montant');
        $solde += (float) VirementInterne::where('rapprochement_destination_id', $rapprochement->id)->sum('montant');
        $solde -= (float) Depense::where('rapprochement_id', $rapprochement->id)->sum('montant_total');
        $solde -= (float) VirementInterne::where('rapprochement_source_id', $rapprochement->id)->sum('montant');

        return round($solde, 2);
    }

    /**
     * Calcule l'écart : solde_fin - solde_pointage.
     * Quand l'écart est 0, le rapprochement peut être verrouillé.
     */
    public function calculerEcart(RapprochementBancaire $rapprochement): float
    {
        return round((float) $rapprochement->solde_fin - $this->calculerSoldePointage($rapprochement), 2);
    }

    /**
     * Pointe ou dé-pointe une transaction pour ce rapprochement.
     * Types acceptés : 'depense', 'recette', 'don', 'cotisation', 'virement_source', 'virement_destination'
     */
    public function toggleTransaction(RapprochementBancaire $rapprochement, string $type, int $id): void
    {
        if ($rapprochement->isVerrouille()) {
            throw new RuntimeException("Impossible de modifier un rapprochement verrouillé.");
        }

        DB::transaction(function () use ($rapprochement, $type, $id) {
            if (str_starts_with($type, 'virement')) {
                $this->toggleVirement($rapprochement, $type, $id);

                return;
            }

            $model = match ($type) {
                'depense' => Depense::findOrFail($id),
                'recette' => Recette::findOrFail($id),
                'don' => Don::findOrFail($id),
                'cotisation' => Cotisation::findOrFail($id),
            };

            if ((int) $model->rapprochement_id === $rapprochement->id) {
                // Dé-pointer
                $model->rapprochement_id = null;
                $model->pointe = false;
            } else {
                // Pointer
                $model->rapprochement_id = $rapprochement->id;
                $model->pointe = true;
            }
            $model->save();
        });
    }

    private function toggleVirement(RapprochementBancaire $rapprochement, string $type, int $id): void
    {
        $virement = VirementInterne::findOrFail($id);
        $field = $type === 'virement_source' ? 'rapprochement_source_id' : 'rapprochement_destination_id';

        if ((int) $virement->{$field} === $rapprochement->id) {
            $virement->{$field} = null;
        } else {
            $virement->{$field} = $rapprochement->id;
        }
        $virement->save();
    }

    /**
     * Verrouille le rapprochement. L'écart doit être 0.
     */
    public function verrouiller(RapprochementBancaire $rapprochement): void
    {
        if ($this->calculerEcart($rapprochement) !== 0.0) {
            throw new RuntimeException("Le rapprochement ne peut être verrouillé que si l'écart est nul.");
        }

        DB::transaction(function () use ($rapprochement) {
            $rapprochement->statut = StatutRapprochement::Verrouille;
            $rapprochement->verrouille_at = now();
            $rapprochement->save();
        });
    }
}
```

- [ ] **Step 5 : Lancer les tests — vérifier qu'ils passent**

```bash
./vendor/bin/sail artisan test tests/Feature/Services/RapprochementBancaireServiceTest.php
```

Attendu : tous les tests passent. Corriger les factories si nécessaire.

- [ ] **Step 6 : Commit**

```bash
git add app/Services/RapprochementBancaireService.php \
        tests/Feature/Services/RapprochementBancaireServiceTest.php
git commit -m "feat: RapprochementBancaireService with TDD tests"
```

---

## Chunk 3 : Livewire RapprochementList

### Task 5 : Livewire RapprochementList

**Files:**
- Create: `app/Livewire/RapprochementList.php`
- Create: `resources/views/livewire/rapprochement-list.blade.php`

Ce composant gère la page principale `/rapprochement` :
- Dropdown de sélection du compte
- Liste des rapprochements du compte sélectionné
- Formulaire inline pour créer un nouveau rapprochement (date_fin + solde_fin)
- Bouton désactivé si un rapprochement "en cours" existe

- [ ] **Step 1 : Créer RapprochementList.php**

```php
<?php
// app/Livewire/RapprochementList.php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Services\RapprochementBancaireService;
use Livewire\Component;

final class RapprochementList extends Component
{
    public ?int $compte_id = null;

    public bool $showCreateForm = false;

    public string $date_fin = '';

    public string $solde_fin = '';

    public function updatedCompteId(): void
    {
        $this->showCreateForm = false;
        $this->date_fin = '';
        $this->solde_fin = '';
    }

    public function create(): void
    {
        $this->validate([
            'date_fin' => ['required', 'date'],
            'solde_fin' => ['required', 'numeric'],
        ]);

        try {
            $compte = CompteBancaire::findOrFail($this->compte_id);
            $rapprochement = app(RapprochementBancaireService::class)
                ->create($compte, $this->date_fin, (float) $this->solde_fin);

            $this->showCreateForm = false;
            $this->date_fin = '';
            $this->solde_fin = '';
            $this->resetValidation();

            return redirect()->route('rapprochement.detail', $rapprochement);
        } catch (\RuntimeException $e) {
            $this->addError('date_fin', $e->getMessage());
        }
    }

    public function render()
    {
        $comptes = CompteBancaire::orderBy('nom')->get();
        $rapprochements = collect();
        $aEnCours = false;
        $soldeOuverture = null;

        if ($this->compte_id) {
            $rapprochements = RapprochementBancaire::where('compte_id', $this->compte_id)
                ->orderByDesc('date_fin')
                ->get();
            $aEnCours = $rapprochements->contains(fn ($r) => $r->isEnCours());

            if (! $aEnCours) {
                $compte = CompteBancaire::find($this->compte_id);
                if ($compte) {
                    $soldeOuverture = app(RapprochementBancaireService::class)
                        ->calculerSoldeOuverture($compte);
                }
            }
        }

        return view('livewire.rapprochement-list', [
            'comptes' => $comptes,
            'rapprochements' => $rapprochements,
            'aEnCours' => $aEnCours,
            'soldeOuverture' => $soldeOuverture,
        ]);
    }
}
```

- [ ] **Step 2 : Créer la vue rapprochement-list.blade.php**

```html
{{-- resources/views/livewire/rapprochement-list.blade.php --}}
<div>
    {{-- Sélection du compte --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="compte-select" class="form-label">Compte bancaire</label>
                    <select wire:model.live="compte_id" id="compte-select" class="form-select">
                        <option value="">-- Sélectionner un compte --</option>
                        @foreach ($comptes as $compte)
                            <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($compte_id && ! $aEnCours)
                    <div class="col-md-auto">
                        <button wire:click="$set('showCreateForm', true)"
                                class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i> Nouveau rapprochement
                        </button>
                    </div>
                @elseif ($compte_id && $aEnCours)
                    <div class="col-md-auto">
                        <button class="btn btn-primary" disabled title="Finalisez le rapprochement en cours avant d'en créer un nouveau.">
                            <i class="bi bi-plus-lg"></i> Nouveau rapprochement
                        </button>
                        <div class="form-text text-warning">
                            <i class="bi bi-exclamation-triangle"></i> Un rapprochement est en cours.
                        </div>
                    </div>
                @endif
            </div>

            {{-- Formulaire de création --}}
            @if ($showCreateForm)
                <div class="mt-3 p-3 border rounded bg-light">
                    <h6 class="mb-3">Nouveau relevé bancaire</h6>
                    @if ($soldeOuverture !== null)
                        <p class="mb-2 text-muted small">
                            Solde d'ouverture automatique :
                            <strong>{{ number_format($soldeOuverture, 2, ',', ' ') }} €</strong>
                        </p>
                    @endif
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Date de fin du relevé <span class="text-danger">*</span></label>
                            <input type="date" wire:model="date_fin"
                                   class="form-control @error('date_fin') is-invalid @enderror">
                            @error('date_fin') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Solde de fin du relevé <span class="text-danger">*</span></label>
                            <input type="number" wire:model="solde_fin" step="0.01"
                                   class="form-control @error('solde_fin') is-invalid @enderror">
                            @error('solde_fin') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-auto">
                            <button wire:click="create" class="btn btn-success">Créer</button>
                            <button wire:click="$set('showCreateForm', false)" class="btn btn-secondary">Annuler</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Liste des rapprochements --}}
    @if ($compte_id)
        @if ($rapprochements->isEmpty())
            <div class="alert alert-info">
                Aucun rapprochement pour ce compte. Créez le premier en cliquant sur "Nouveau rapprochement".
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Date de fin</th>
                            <th class="text-end">Solde ouverture</th>
                            <th class="text-end">Solde fin</th>
                            <th>Statut</th>
                            <th>Verrouillé le</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rapprochements as $rapprochement)
                            <tr>
                                <td>{{ $rapprochement->date_fin->format('d/m/Y') }}</td>
                                <td class="text-end">{{ number_format((float) $rapprochement->solde_ouverture, 2, ',', ' ') }} €</td>
                                <td class="text-end">{{ number_format((float) $rapprochement->solde_fin, 2, ',', ' ') }} €</td>
                                <td>
                                    @if ($rapprochement->isVerrouille())
                                        <span class="badge bg-secondary"><i class="bi bi-lock"></i> Verrouillé</span>
                                    @else
                                        <span class="badge bg-warning text-dark"><i class="bi bi-pencil"></i> En cours</span>
                                    @endif
                                </td>
                                <td>{{ $rapprochement->verrouille_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('rapprochement.detail', $rapprochement) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i>
                                        {{ $rapprochement->isEnCours() ? 'Continuer' : 'Consulter' }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @else
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Sélectionnez un compte bancaire pour afficher ses rapprochements.
        </div>
    @endif
</div>
```

- [ ] **Step 3 : Commit**

```bash
git add app/Livewire/RapprochementList.php \
        resources/views/livewire/rapprochement-list.blade.php
git commit -m "feat: RapprochementList Livewire component"
```

---

## Chunk 4 : Livewire RapprochementDetail

### Task 6 : Livewire RapprochementDetail

**Files:**
- Create: `app/Livewire/RapprochementDetail.php`
- Create: `resources/views/livewire/rapprochement-detail.blade.php`

C'est le cœur du module. Ce composant affiche toutes les transactions disponibles pour ce compte (non encore pointées ailleurs + celles de ce rapprochement), permet de les pointer/dé-pointer, affiche les soldes en temps réel, et permet de verrouiller.

**Logique de la liste des transactions :**
- Dépenses : `compte_id = $compte->id` AND (`rapprochement_id IS NULL` OR `rapprochement_id = $rapprochement->id`)
- Recettes : idem
- Dons : idem
- Cotisations : `compte_id = $compte->id` AND (`rapprochement_id IS NULL` OR `rapprochement_id = $rapprochement->id`)
- Virements sortants : `compte_source_id = $compte->id` AND (`rapprochement_source_id IS NULL` OR `rapprochement_source_id = $rapprochement->id`)
- Virements entrants : `compte_destination_id = $compte->id` AND (`rapprochement_destination_id IS NULL` OR `rapprochement_destination_id = $rapprochement->id`)

Chaque transaction est convertie en tableau unifié :
```php
[
    'id' => int,
    'type' => 'depense'|'recette'|'don'|'cotisation'|'virement_source'|'virement_destination',
    'date' => Carbon,
    'label' => string,
    'reference' => string|null,
    'montant_signe' => float, // négatif pour débits, positif pour crédits
    'pointe' => bool,
]
```

- [ ] **Step 1 : Créer RapprochementDetail.php**

```php
<?php
// app/Livewire/RapprochementDetail.php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\RapprochementBancaire;
use App\Models\Recette;
use App\Models\VirementInterne;
use App\Services\RapprochementBancaireService;
use Livewire\Component;

final class RapprochementDetail extends Component
{
    public RapprochementBancaire $rapprochement;

    public function mount(RapprochementBancaire $rapprochement): void
    {
        $this->rapprochement = $rapprochement;
    }

    public function toggle(string $type, int $id): void
    {
        try {
            app(RapprochementBancaireService::class)
                ->toggleTransaction($this->rapprochement, $type, $id);
            $this->rapprochement = $this->rapprochement->fresh();
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function verrouiller(): void
    {
        try {
            app(RapprochementBancaireService::class)
                ->verrouiller($this->rapprochement);
            $this->rapprochement = $this->rapprochement->fresh();
            session()->flash('success', 'Rapprochement verrouillé avec succès.');
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render()
    {
        $service = app(RapprochementBancaireService::class);
        $compte = $this->rapprochement->compte;
        $rid = $this->rapprochement->id;

        $transactions = collect();

        // Dépenses
        Depense::where('compte_id', $compte->id)
            ->where(fn ($q) => $q->whereNull('rapprochement_id')->orWhere('rapprochement_id', $rid))
            ->get()
            ->each(function (Depense $d) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $d->id,
                    'type' => 'depense',
                    'date' => $d->date,
                    'label' => $d->libelle,
                    'reference' => $d->reference,
                    'montant_signe' => -(float) $d->montant_total,
                    'pointe' => (int) $d->rapprochement_id === $rid,
                ]);
            });

        // Recettes
        Recette::where('compte_id', $compte->id)
            ->where(fn ($q) => $q->whereNull('rapprochement_id')->orWhere('rapprochement_id', $rid))
            ->get()
            ->each(function (Recette $r) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $r->id,
                    'type' => 'recette',
                    'date' => $r->date,
                    'label' => $r->libelle,
                    'reference' => $r->reference,
                    'montant_signe' => (float) $r->montant_total,
                    'pointe' => (int) $r->rapprochement_id === $rid,
                ]);
            });

        // Dons
        Don::where('compte_id', $compte->id)
            ->where(fn ($q) => $q->whereNull('rapprochement_id')->orWhere('rapprochement_id', $rid))
            ->with('donateur')
            ->get()
            ->each(function (Don $d) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $d->id,
                    'type' => 'don',
                    'date' => $d->date,
                    'label' => $d->donateur
                        ? $d->donateur->nom.' '.$d->donateur->prenom
                        : ($d->objet ?? 'Don anonyme'),
                    'reference' => null,
                    'montant_signe' => (float) $d->montant,
                    'pointe' => (int) $d->rapprochement_id === $rid,
                ]);
            });

        // Cotisations
        Cotisation::where('compte_id', $compte->id)
            ->where(fn ($q) => $q->whereNull('rapprochement_id')->orWhere('rapprochement_id', $rid))
            ->with('membre')
            ->get()
            ->each(function (Cotisation $c) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $c->id,
                    'type' => 'cotisation',
                    'date' => $c->date_paiement,
                    'label' => $c->membre ? $c->membre->nom.' '.$c->membre->prenom : 'Cotisation',
                    'reference' => null,
                    'montant_signe' => (float) $c->montant,
                    'pointe' => (int) $c->rapprochement_id === $rid,
                ]);
            });

        // Virements sortants (source = ce compte)
        VirementInterne::where('compte_source_id', $compte->id)
            ->where(fn ($q) => $q->whereNull('rapprochement_source_id')->orWhere('rapprochement_source_id', $rid))
            ->get()
            ->each(function (VirementInterne $v) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $v->id,
                    'type' => 'virement_source',
                    'date' => $v->date,
                    'label' => 'Virement vers '.$v->compteDestination->nom,
                    'reference' => $v->reference,
                    'montant_signe' => -(float) $v->montant,
                    'pointe' => (int) $v->rapprochement_source_id === $rid,
                ]);
            });

        // Virements entrants (destination = ce compte)
        VirementInterne::where('compte_destination_id', $compte->id)
            ->where(fn ($q) => $q->whereNull('rapprochement_destination_id')->orWhere('rapprochement_destination_id', $rid))
            ->get()
            ->each(function (VirementInterne $v) use (&$transactions, $rid) {
                $transactions->push([
                    'id' => $v->id,
                    'type' => 'virement_destination',
                    'date' => $v->date,
                    'label' => 'Virement depuis '.$v->compteSource->nom,
                    'reference' => $v->reference,
                    'montant_signe' => (float) $v->montant,
                    'pointe' => (int) $v->rapprochement_destination_id === $rid,
                ]);
            });

        $transactions = $transactions->sortBy('date')->values();

        $soldePointage = $service->calculerSoldePointage($this->rapprochement);
        $ecart = $service->calculerEcart($this->rapprochement);

        return view('livewire.rapprochement-detail', [
            'transactions' => $transactions,
            'soldePointage' => $soldePointage,
            'ecart' => $ecart,
        ]);
    }
}
```

- [ ] **Step 2 : Créer la vue rapprochement-detail.blade.php**

```html
{{-- resources/views/livewire/rapprochement-detail.blade.php --}}
<div>
    {{-- En-tête --}}
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <h4 class="mb-1">{{ $rapprochement->compte->nom }}</h4>
            <span class="text-muted">Relevé du {{ $rapprochement->date_fin->format('d/m/Y') }}</span>
            @if ($rapprochement->isVerrouille())
                <span class="badge bg-secondary ms-2"><i class="bi bi-lock"></i> Verrouillé</span>
            @else
                <span class="badge bg-warning text-dark ms-2"><i class="bi bi-pencil"></i> En cours</span>
            @endif
        </div>
        <a href="{{ route('rapprochement.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Retour
        </a>
    </div>

    {{-- Bandeau de soldes --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Solde ouverture</div>
                    <div class="fw-bold">{{ number_format((float) $rapprochement->solde_ouverture, 2, ',', ' ') }} €</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Solde fin (relevé)</div>
                    <div class="fw-bold">{{ number_format((float) $rapprochement->solde_fin, 2, ',', ' ') }} €</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Solde pointé</div>
                    <div class="fw-bold">{{ number_format($soldePointage, 2, ',', ' ') }} €</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-{{ $ecart == 0 ? 'success' : 'danger' }}">
                <div class="card-body py-2">
                    <div class="text-muted small">Écart</div>
                    <div class="fw-bold {{ $ecart == 0 ? 'text-success' : 'text-danger' }}">
                        {{ number_format($ecart, 2, ',', ' ') }} €
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Actions --}}
    @if ($rapprochement->isEnCours())
        <div class="d-flex gap-2 mb-4">
            <a href="{{ route('rapprochement.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-floppy"></i> Enregistrer et quitter
            </a>
            @if ($ecart == 0)
                <button wire:click="verrouiller"
                        wire:confirm="Verrouiller ce rapprochement ? Cette action est irréversible. Les champs Date, Montant et Compte bancaire des écritures pointées ne pourront plus être modifiés."
                        class="btn btn-danger">
                    <i class="bi bi-lock"></i> Verrouiller
                </button>
            @else
                <button class="btn btn-danger" disabled
                        title="L'écart doit être nul pour verrouiller.">
                    <i class="bi bi-lock"></i> Verrouiller (écart non nul)
                </button>
            @endif
        </div>
    @endif

    {{-- Table des transactions --}}
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Libellé</th>
                    <th>Réf.</th>
                    <th class="text-end">Débit</th>
                    <th class="text-end">Crédit</th>
                    <th class="text-center">Pointé</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transactions as $tx)
                    <tr class="{{ $tx['pointe'] ? 'table-success' : '' }}">
                        <td class="text-nowrap">{{ $tx['date']->format('d/m/Y') }}</td>
                        <td>
                            @switch($tx['type'])
                                @case('depense') <span class="badge bg-danger">Dépense</span> @break
                                @case('recette') <span class="badge bg-success">Recette</span> @break
                                @case('don') <span class="badge bg-info text-dark">Don</span> @break
                                @case('cotisation') <span class="badge bg-warning text-dark">Cotisation</span> @break
                                @case('virement_source') <span class="badge bg-secondary">Virement ↑</span> @break
                                @case('virement_destination') <span class="badge bg-secondary">Virement ↓</span> @break
                            @endswitch
                        </td>
                        <td>{{ $tx['label'] }}</td>
                        <td class="text-muted small">{{ $tx['reference'] ?? '—' }}</td>
                        <td class="text-end text-danger">
                            @if ($tx['montant_signe'] < 0)
                                {{ number_format(abs($tx['montant_signe']), 2, ',', ' ') }} €
                            @endif
                        </td>
                        <td class="text-end text-success">
                            @if ($tx['montant_signe'] > 0)
                                {{ number_format($tx['montant_signe'], 2, ',', ' ') }} €
                            @endif
                        </td>
                        <td class="text-center">
                            @if ($rapprochement->isEnCours())
                                <input type="checkbox"
                                       wire:click="toggle('{{ $tx['type'] }}', {{ $tx['id'] }})"
                                       {{ $tx['pointe'] ? 'checked' : '' }}
                                       class="form-check-input">
                            @else
                                <input type="checkbox"
                                       {{ $tx['pointe'] ? 'checked' : '' }}
                                       disabled
                                       class="form-check-input">
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted">
                            Aucune transaction disponible pour ce compte.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
```

- [ ] **Step 3 : Commit**

```bash
git add app/Livewire/RapprochementDetail.php \
        resources/views/livewire/rapprochement-detail.blade.php
git commit -m "feat: RapprochementDetail Livewire component"
```

---

## Chunk 5 : Routes, Vues, Locking

### Task 7 : Routes + Vues + Suppression ancien composant

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/views/rapprochement/index.blade.php`
- Create: `resources/views/rapprochement/detail.blade.php`
- Delete: `app/Livewire/Rapprochement.php`
- Delete: `resources/views/livewire/rapprochement.blade.php`

- [ ] **Step 1 : Mettre à jour routes/web.php**

Remplacer la ligne :
```php
Route::view('/rapprochement', 'rapprochement.index')->name('rapprochement.index');
```

Par :
```php
use App\Models\RapprochementBancaire;

Route::view('/rapprochement', 'rapprochement.index')->name('rapprochement.index');
Route::get('/rapprochement/{rapprochement}', function (RapprochementBancaire $rapprochement) {
    return view('rapprochement.detail', compact('rapprochement'));
})->name('rapprochement.detail');
```

- [ ] **Step 2 : Modifier resources/views/rapprochement/index.blade.php**

Remplacer `<livewire:rapprochement />` par `<livewire:rapprochement-list />` :

```html
<x-app-layout>
    <h1 class="mb-4">Rapprochements bancaires</h1>
    <livewire:rapprochement-list />
</x-app-layout>
```

- [ ] **Step 3 : Créer resources/views/rapprochement/detail.blade.php**

```html
<x-app-layout>
    <livewire:rapprochement-detail :rapprochement="$rapprochement" />
</x-app-layout>
```

- [ ] **Step 4 : Supprimer l'ancien composant Livewire**

```bash
rm app/Livewire/Rapprochement.php
rm resources/views/livewire/rapprochement.blade.php
```

- [ ] **Step 5 : Commit**

```bash
git add routes/web.php \
        resources/views/rapprochement/index.blade.php \
        resources/views/rapprochement/detail.blade.php
git rm app/Livewire/Rapprochement.php \
       resources/views/livewire/rapprochement.blade.php
git commit -m "feat: routes and views for rapprochements refonte, remove old Rapprochement component"
```

---

### Task 8 : Geler date/montant/compte dans DepenseForm et RecetteForm

**Files:**
- Modify: `app/Livewire/DepenseForm.php`
- Modify: `app/Livewire/RecetteForm.php`
- Modify: `resources/views/livewire/depense-form.blade.php`
- Modify: `resources/views/livewire/recette-form.blade.php`

**Règle :** Si une dépense/recette est pointée dans un rapprochement verrouillé (`rapprochement_id != null` && rapprochement.statut = 'verrouille'), les champs `date`, `montant` (lignes) et `compte_id` deviennent en lecture seule. Les autres champs (libellé, mode_paiement, notes, bénéficiaire, référence) restent modifiables.

- [ ] **Step 1 : Ajouter la propriété isLocked dans DepenseForm.php**

Lire le fichier. Dans la méthode `edit(int $id)`, après le chargement de la dépense, ajouter :

```php
// dans la classe DepenseForm, ajouter la propriété :
public bool $isLocked = false;

// dans edit(), après $depense = Depense::findOrFail($id) :
$this->isLocked = $depense->isLockedByRapprochement();
```

Dans `resetForm()`, ajouter `'isLocked'` à la liste reset (ou `$this->isLocked = false`).

- [ ] **Step 2 : Même chose dans RecetteForm.php**

- [ ] **Step 3 : Modifier depense-form.blade.php**

Pour les champs `date` et `compte_id`, ajouter la condition `disabled` et la présentation visuelle si verrouillé :

```html
{{-- Champ date --}}
<div class="col-md-2">
    <label for="date" class="form-label">
        Date <span class="text-danger">*</span>
        @if ($isLocked) <i class="bi bi-lock text-warning" title="Champ verrouillé par un rapprochement"></i> @endif
    </label>
    @if ($isLocked)
        <input type="date" value="{{ $date }}" class="form-control bg-light" disabled>
    @else
        <input type="date" wire:model="date" id="date"
               class="form-control @error('date') is-invalid @enderror">
        @error('date') <div class="invalid-feedback">{{ $message }}</div> @enderror
    @endif
</div>

{{-- Champ compte_id --}}
<div class="col-md-3">
    <label for="compte_id" class="form-label">
        Compte bancaire
        @if ($isLocked) <i class="bi bi-lock text-warning" title="Champ verrouillé par un rapprochement"></i> @endif
    </label>
    @if ($isLocked)
        <input type="text" value="{{ $comptes->firstWhere('id', $compte_id)?->nom ?? '—' }}"
               class="form-control bg-light" disabled>
    @else
        <select wire:model="compte_id" id="compte_id" class="form-select">
            <option value="">-- Aucun --</option>
            @foreach ($comptes as $compte)
                <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
            @endforeach
        </select>
    @endif
</div>
```

Pour les lignes de dépenses (montant par ligne) : encapsuler le champ `montant` dans `@if (!$isLocked)` / `@else readonly @endif`.

- [ ] **Step 4 : Même chose dans recette-form.blade.php**

- [ ] **Step 5 : Test manuel**

1. Créer une dépense sur compte A
2. Créer un rapprochement pour compte A, pointer cette dépense, verrouiller (si écart = 0)
3. Aller modifier la dépense → vérifier que date/montant/compte sont en lecture seule avec l'icône cadenas
4. Modifier libellé → doit fonctionner

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/DepenseForm.php app/Livewire/RecetteForm.php \
        resources/views/livewire/depense-form.blade.php \
        resources/views/livewire/recette-form.blade.php
git commit -m "feat: lock date/montant/compte in forms when pointed in locked rapprochement"
```

---

## Récapitulatif des commits

1. `feat: migrations for rapprochements_bancaires and rapprochement_id on transactions`
2. `feat: StatutRapprochement enum and RapprochementBancaire model`
3. `feat: add rapprochement relationships and isLocked helpers to transaction models`
4. `feat: RapprochementBancaireService with TDD tests`
5. `feat: RapprochementList Livewire component`
6. `feat: RapprochementDetail Livewire component`
7. `feat: routes and views for rapprochements refonte, remove old Rapprochement component`
8. `feat: lock date/montant/compte in forms when pointed in locked rapprochement`
