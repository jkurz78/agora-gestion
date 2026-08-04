# Immobilisations — lot 1 — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** doter AgoraGestion d'un registre des immobilisations, de la saisie d'une acquisition en classe 2 et de la génération des dotations aux amortissements, sans modifier aucun rapport existant.

**Architecture :** la fiche d'immobilisation est le maître ; elle crée son écriture d'acquisition en déléguant à la mécanique dépense existante (`EcritureGenerator::pourDepenseACredit`), dont le garde-fou « classe 6 » s'ouvre à la classe 2 par un paramètre nommé à défaut `false`. Les dotations sont des transactions `type = Depense` / `journal = Od` générées par exercice, sur le modèle de `pourProvisionDotation`. Le calcul est linéaire, au prorata mensuel, en centimes entiers.

**Tech stack :** Laravel 11, Livewire 4, Bootstrap 5 (CDN), MySQL via Sail, Pest, `barryvdh/laravel-dompdf`.

**Spec de référence :** [docs/specs/2026-08-04-immobilisations-design.md](../specs/2026-08-04-immobilisations-design.md)

---

## Conventions à respecter (valables pour toutes les tâches)

- `declare(strict_types=1)` + `final class` + type hints sur toutes les méthodes.
- Formatage : `./vendor/bin/pint` avant chaque commit.
- Locale `fr` partout (labels, messages de validation).
- Tout modèle tenant-scopé étend `App\Models\TenantModel`.
- **La table des associations s'appelle `association` au singulier** : dans les migrations, écrire `->constrained('association')`.
- Cast `(int)` des **deux** côtés dans les comparaisons `===` de PK/FK.
- SoftDeletes sur les modèles financiers.
- En-têtes de tableaux : `table-dark` + `style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880"`.
- Tri de colonnes : JS côté client, `data-sort` sur les `<td>` (dates ISO `Y-m-d`, nombres bruts).
- `wire:confirm` : toujours via modale Bootstrap, jamais `confirm()` natif.
- **Le mois de début d'exercice est configurable par tenant** (`exercice_mois_debut`, défaut 9). Ne jamais coder « 31/08 » ni « septembre » en dur : passer par `ExerciceService::dateRange()`.

### Lancer les tests

Un fichier : `php -d memory_limit=1G ./vendor/bin/pest tests/Unit/Chemin/MonTest.php --compact`

La suite complète : `php -d memory_limit=1G ./vendor/bin/pest --compact` (512 Mo ne suffisent pas).

**Note sur l'environnement local** : PHP 8.5 en local contre 8.4 en CI. La suite affiche des milliers de lignes `deprecated` qui ne sont **pas** des échecs. Une sortie du type « 5799 deprecated, N passed » avec zéro `failed` est une suite **verte**. Ne pas chercher à « corriger » ces avertissements.

---

## Structure des fichiers

**Créés :**

| Fichier | Responsabilité |
|---|---|
| `database/migrations/2026_08_04_100001_create_immobilisations_tables.php` | les trois tables |
| `app/Models/Immobilisation.php` | fiche, relations, accesseurs d'affichage |
| `app/Models/ImmobilisationDotation.php` | dotation comptabilisée |
| `database/factories/ImmobilisationFactory.php` | fabrique de test |
| `app/Services/Immobilisation/ImmobilisationSequenceService.php` | numérotation `IM00001` |
| `app/Services/Immobilisation/PlanAmortissementCalculator.php` | **calcul pur**, sans I/O |
| `app/Services/Immobilisation/ImmobilisationService.php` | acquisition (fiche + écriture) |
| `app/Services/Immobilisation/DotationService.php` | génération / recalcul / annulation + gardes de date |
| `app/Services/Immobilisation/ImmobilisationComptesSeeder.php` | kit de comptes 21X/281X/6811 + familles |
| `app/Livewire/Immobilisations/ImmobilisationIndex.php` | livre + modale de création |
| `app/Livewire/Immobilisations/ImmobilisationShow.php` | fiche + plan d'amortissement |
| `app/Livewire/Immobilisations/DotationsExercice.php` | aperçu et génération |
| `app/Http/Controllers/ImmobilisationPdfController.php` | export PDF de la fiche |
| `resources/views/livewire/immobilisations/*.blade.php` | vues des trois composants |
| `resources/views/pdf/immobilisation.blade.php` | gabarit PDF |

**Modifiés :**

| Fichier | Nature |
|---|---|
| `app/Services/Compta/EcritureGenerator.php` | garde classe 2 sur `pourDepenseACredit` + nouvelle `pourDotationAmortissement` |
| `app/Livewire/PlanComptable.php` | ouverture à la classe 2 |
| `app/Services/Compta/PlanComptableSelecteur.php` | type `immobilisation` (classe 2) |
| `app/Livewire/TransactionForm.php` | `isLockedByImmobilisation` |
| `app/Livewire/TransactionUniverselle.php` | badge et compte affiché |
| `app/Services/ClotureCheckService.php` | avertissement dotations non générées |
| `routes/web.php` | trois routes + PDF |
| `resources/views/components/sidebar.blade.php` | entrée dans le groupe Comptabilité |

---

## Task 1 : tables et modèles

**Files:**
- Create: `database/migrations/2026_08_04_100001_create_immobilisations_tables.php`
- Create: `app/Models/Immobilisation.php`
- Create: `app/Models/ImmobilisationDotation.php`
- Create: `database/factories/ImmobilisationFactory.php`
- Test: `tests/Unit/Models/ImmobilisationTest.php`

- [ ] **Step 1 : écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Transaction;
use App\Tenant\TenantContext;

it('crée une immobilisation tenant-scopée avec ses comptes', function (): void {
    $compte = Compte::factory()->create(['numero_pcg' => '2188', 'classe' => 2]);
    $compteAmort = Compte::factory()->create(['numero_pcg' => '28188', 'classe' => 2]);
    $tx = Transaction::factory()->create();

    $immo = Immobilisation::create([
        'numero' => 'IM00001',
        'libelle' => '20 tenues d’escrime',
        'quantite' => 20,
        'compte_id' => $compte->id,
        'compte_amortissement_id' => $compteAmort->id,
        'montant_acquisition' => '3000.00',
        'date_mise_en_service' => '2026-09-12',
        'duree_mois' => 60,
        'transaction_id' => $tx->id,
    ]);

    expect((int) $immo->association_id)->toBe((int) TenantContext::currentId())
        ->and($immo->quantite)->toBe(20)
        ->and($immo->duree_mois)->toBe(60)
        ->and($immo->compte->numero_pcg)->toBe('2188')
        ->and($immo->compteAmortissement->numero_pcg)->toBe('28188')
        ->and($immo->transactionsAcquisition())->toHaveCount(1);
});

it('affiche la durée en années quand elle est un multiple de 12', function (): void {
    $immo = Immobilisation::factory()->make(['duree_mois' => 60]);
    expect($immo->duree_label)->toBe('5 ans');

    $immo = Immobilisation::factory()->make(['duree_mois' => 30]);
    expect($immo->duree_label)->toBe('30 mois');

    $immo = Immobilisation::factory()->make(['duree_mois' => 12]);
    expect($immo->duree_label)->toBe('1 an');
});
```

- [ ] **Step 2 : lancer le test pour vérifier qu'il échoue**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Unit/Models/ImmobilisationTest.php --compact`
Expected : FAIL — `Class "App\Models\Immobilisation" not found`

- [ ] **Step 3 : écrire la migration**

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
        Schema::create('immobilisations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->string('numero', 10);
            $table->string('libelle', 255);
            $table->unsignedInteger('quantite')->default(1);
            $table->foreignId('compte_id')->constrained('comptes');
            $table->foreignId('compte_amortissement_id')->constrained('comptes');
            $table->decimal('montant_acquisition', 10, 2);
            $table->date('date_mise_en_service');
            $table->unsignedSmallInteger('duree_mois');
            $table->foreignId('transaction_id')->constrained('transactions');
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['association_id', 'numero']);
            $table->index('transaction_id');
        });

        Schema::create('immobilisation_dotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->foreignId('immobilisation_id')->constrained('immobilisations')->cascadeOnDelete();
            $table->unsignedSmallInteger('exercice');
            $table->decimal('montant', 10, 2);
            $table->foreignId('transaction_id')->constrained('transactions');
            $table->timestamps();

            $table->unique(['immobilisation_id', 'exercice']);
            $table->index('transaction_id');
        });

        Schema::create('immobilisation_sequences', function (Blueprint $table): void {
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->unsignedBigInteger('dernier_numero')->default(0);

            $table->primary('association_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('immobilisation_sequences');
        Schema::dropIfExists('immobilisation_dotations');
        Schema::dropIfExists('immobilisations');
    }
};
```

- [ ] **Step 4 : écrire les modèles**

`app/Models/Immobilisation.php` :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

final class Immobilisation extends TenantModel
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'immobilisations';

    protected $fillable = [
        'association_id',
        'numero',
        'libelle',
        'quantite',
        'compte_id',
        'compte_amortissement_id',
        'montant_acquisition',
        'date_mise_en_service',
        'duree_mois',
        'transaction_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'duree_mois' => 'integer',
            'montant_acquisition' => 'decimal:2',
            'date_mise_en_service' => 'date',
        ];
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_id');
    }

    public function compteAmortissement(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_amortissement_id');
    }

    public function dotations(): HasMany
    {
        return $this->hasMany(ImmobilisationDotation::class)->orderBy('exercice');
    }

    /**
     * Transactions d'acquisition — au pluriel dès le lot 1, bien qu'adossée à un
     * unique FK. Les consommateurs (fiche, PDF, badge, verrou) sont ainsi écrits
     * contre une collection : le jour où une immobilisation portera plusieurs
     * achats, le passage 1:1 → 1:N ne touchera aucun site de lecture.
     *
     * @return Collection<int, Transaction>
     */
    public function transactionsAcquisition(): Collection
    {
        $transaction = $this->relationLoaded('transaction')
            ? $this->getRelation('transaction')
            : Transaction::find((int) $this->transaction_id);

        return $transaction === null ? collect() : collect([$transaction]);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    /** « 5 ans » quand la durée est un multiple de 12, « 30 mois » sinon. */
    public function getDureeLabelAttribute(): string
    {
        $mois = (int) $this->duree_mois;

        if ($mois % 12 !== 0) {
            return $mois.' mois';
        }

        $annees = intdiv($mois, 12);

        return $annees === 1 ? '1 an' : $annees.' ans';
    }

    /** Cumul des dotations réellement comptabilisées, en centimes. */
    public function cumulAmortiCentimes(): int
    {
        return (int) round(((float) $this->dotations()->sum('montant')) * 100);
    }

    public function montantAcquisitionCentimes(): int
    {
        return (int) round(((float) $this->montant_acquisition) * 100);
    }

    /** Valeur nette comptable, en centimes. */
    public function valeurNetteCentimes(): int
    {
        return $this->montantAcquisitionCentimes() - $this->cumulAmortiCentimes();
    }

    public function estEnService(): bool
    {
        return ! $this->date_mise_en_service->isFuture();
    }
}
```

`app/Models/ImmobilisationDotation.php` :

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ImmobilisationDotation extends TenantModel
{
    use HasFactory;

    protected $table = 'immobilisation_dotations';

    protected $fillable = [
        'association_id',
        'immobilisation_id',
        'exercice',
        'montant',
        'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'exercice' => 'integer',
            'montant' => 'decimal:2',
        ];
    }

    public function immobilisation(): BelongsTo
    {
        return $this->belongsTo(Immobilisation::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
```

- [ ] **Step 5 : écrire la factory**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Immobilisation> */
final class ImmobilisationFactory extends Factory
{
    protected $model = Immobilisation::class;

    public function definition(): array
    {
        return [
            'numero' => 'IM'.str_pad((string) $this->faker->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'libelle' => $this->faker->words(3, true),
            'quantite' => 1,
            // Numéros tirés au hasard et NON fixes : `comptes` porte un unique
            // (association_id, numero_pcg) et CompteFactory ne fait pas de
            // firstOrCreate. Deux `make()` dans le même test rejoueraient ces
            // closures et violeraient la contrainte avec des numéros en dur.
            // Les 5 caractères évitent aussi toute collision avec le kit
            // ImmobilisationComptesSeeder, qui pose des numéros à 4 caractères.
            'compte_id' => fn (): int => (int) Compte::factory()->create([
                'numero_pcg' => '21'.str_pad((string) $this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
                'classe' => 2,
            ])->id,
            'compte_amortissement_id' => fn (): int => (int) Compte::factory()->create([
                'numero_pcg' => '28'.str_pad((string) $this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
                'classe' => 2,
            ])->id,
            'montant_acquisition' => '3000.00',
            'date_mise_en_service' => '2026-09-12',
            'duree_mois' => 60,
            'transaction_id' => fn (): int => (int) Transaction::factory()->create()->id,
        ];
    }
}
```

- [ ] **Step 6 : lancer le test pour vérifier qu'il passe**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Unit/Models/ImmobilisationTest.php --compact`
Expected : PASS (2 tests)

- [ ] **Step 7 : formater et committer**

```bash
./vendor/bin/pint app/Models database/factories database/migrations tests/Unit/Models
git add app/Models/Immobilisation.php app/Models/ImmobilisationDotation.php database/factories/ImmobilisationFactory.php database/migrations/2026_08_04_100001_create_immobilisations_tables.php tests/Unit/Models/ImmobilisationTest.php
git commit -m "feat(immos): tables et modèles du registre des immobilisations"
```

---

## Task 2 : séquence de numérotation `IM00001`

**Files:**
- Create: `app/Services/Immobilisation/ImmobilisationSequenceService.php`
- Test: `tests/Unit/Services/Immobilisation/ImmobilisationSequenceServiceTest.php`

- [ ] **Step 1 : écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Services\Immobilisation\ImmobilisationSequenceService;
use App\Tenant\TenantContext;

it('produit des numéros consécutifs formatés sur 5 chiffres', function (): void {
    $service = app(ImmobilisationSequenceService::class);

    expect($service->prochain())->toBe('IM00001')
        ->and($service->prochain())->toBe('IM00002')
        ->and($service->prochain())->toBe('IM00003');
});

it('cloisonne la séquence par tenant', function (): void {
    $service = app(ImmobilisationSequenceService::class);

    expect($service->prochain())->toBe('IM00001')
        ->and($service->prochain())->toBe('IM00002');

    $autre = Association::factory()->create();
    TenantContext::boot($autre);

    expect($service->prochain())->toBe('IM00001');
});
```

- [ ] **Step 2 : lancer le test pour vérifier qu'il échoue**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Unit/Services/Immobilisation/ImmobilisationSequenceServiceTest.php --compact`
Expected : FAIL — `Target class [App\Services\Immobilisation\ImmobilisationSequenceService] does not exist.`

- [ ] **Step 3 : écrire le service**

Le verrouillage reprend l'idiome de `NumeroPieceService::assign` : `insertOrIgnore` pour garantir l'existence de la ligne, puis `lockForUpdate` avant l'incrément.

```php
<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Numérotation publique des immobilisations : IM00001, IM00002…
 *
 * Séquence par tenant et NON par exercice, contrairement au numéro de pièce :
 * une immobilisation traverse les exercices.
 */
final class ImmobilisationSequenceService
{
    public function prochain(): string
    {
        $associationId = (int) TenantContext::currentId();

        DB::table('immobilisation_sequences')->insertOrIgnore([
            'association_id' => $associationId,
            'dernier_numero' => 0,
        ]);

        $sequence = DB::table('immobilisation_sequences')
            ->where('association_id', $associationId)
            ->lockForUpdate()
            ->first();

        $numero = (int) $sequence->dernier_numero + 1;

        DB::table('immobilisation_sequences')
            ->where('association_id', $associationId)
            ->update(['dernier_numero' => $numero]);

        return 'IM'.str_pad((string) $numero, 5, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 4 : lancer le test pour vérifier qu'il passe**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Unit/Services/Immobilisation/ImmobilisationSequenceServiceTest.php --compact`
Expected : PASS (2 tests)

- [ ] **Step 5 : formater et committer**

```bash
./vendor/bin/pint app/Services/Immobilisation tests/Unit/Services/Immobilisation
git add app/Services/Immobilisation/ImmobilisationSequenceService.php tests/Unit/Services/Immobilisation/ImmobilisationSequenceServiceTest.php
git commit -m "feat(immos): séquence de numérotation IM00001 par tenant"
```

---

## Task 3 : calcul du plan d'amortissement

C'est le cœur du module. Le calcul se fait **en centimes entiers** pour éliminer toute erreur de virgule flottante.

**Files:**
- Create: `app/Services/Immobilisation/PlanAmortissementCalculator.php`
- Test: `tests/Unit/Services/Immobilisation/PlanAmortissementCalculatorTest.php`

- [ ] **Step 1 : écrire les tests qui échouent**

```php
<?php

declare(strict_types=1);

use App\Models\Immobilisation;
use App\Services\Immobilisation\PlanAmortissementCalculator;

function immo(string $miseEnService, int $dureeMois, string $montant): Immobilisation
{
    return Immobilisation::factory()->make([
        'date_mise_en_service' => $miseEnService,
        'duree_mois' => $dureeMois,
        'montant_acquisition' => $montant,
    ]);
}

it('compte le mois de mise en service pour un mois entier', function (): void {
    $calc = app(PlanAmortissementCalculator::class);

    // Exercice 2026 = 01/09/2026 → 31/08/2027. Mise en service en février 2027 :
    // février à août inclus = 7 mois, que la MES soit le 12 ou le 26.
    expect($calc->moisEcoules(immo('2027-02-12', 36, '1000.00'), 2026))->toBe(7)
        ->and($calc->moisEcoules(immo('2027-02-26', 36, '1000.00'), 2026))->toBe(7);
});

it('plafonne les mois écoulés à la durée', function (): void {
    $calc = app(PlanAmortissementCalculator::class);

    expect($calc->moisEcoules(immo('2027-02-15', 36, '1000.00'), 2029))->toBe(36);
});

it('plancher les mois écoulés à zéro quand la mise en service est postérieure', function (): void {
    $calc = app(PlanAmortissementCalculator::class);

    expect($calc->moisEcoules(immo('2028-03-01', 60, '3000.00'), 2026))->toBe(0);
});

it('produit une année pleine sur un exercice complet', function (): void {
    $calc = app(PlanAmortissementCalculator::class);
    $immo = immo('2026-09-12', 60, '3000.00');

    expect($calc->cumulTheoriqueCentimes($immo, 2026))->toBe(60000)
        ->and($calc->dotationCentimes($immo, 2026, 0))->toBe(60000)
        ->and($calc->dotationCentimes($immo, 2027, 60000))->toBe(60000);
});

it('absorbe les arrondis et solde le bien à l’euro près', function (): void {
    $calc = app(PlanAmortissementCalculator::class);
    $immo = immo('2027-02-15', 36, '1000.00');

    $d2026 = $calc->dotationCentimes($immo, 2026, 0);
    $d2027 = $calc->dotationCentimes($immo, 2027, $d2026);
    $d2028 = $calc->dotationCentimes($immo, 2028, $d2026 + $d2027);
    $d2029 = $calc->dotationCentimes($immo, 2029, $d2026 + $d2027 + $d2028);

    expect($d2026)->toBe(19444)
        ->and($d2027)->toBe(33334)
        ->and($d2028)->toBe(33333)
        ->and($d2029)->toBe(13889)
        ->and($d2026 + $d2027 + $d2028 + $d2029)->toBe(100000);
});

it('gère une durée non multiple de douze', function (): void {
    $calc = app(PlanAmortissementCalculator::class);
    $immo = immo('2026-09-01', 30, '3000.00');

    // 12 mois sur 30 → 1200,00 €
    expect($calc->cumulTheoriqueCentimes($immo, 2026))->toBe(120000)
        // 24 mois sur 30 → 2400,00 €
        ->and($calc->cumulTheoriqueCentimes($immo, 2027))->toBe(240000)
        // 30 mois plafonnés → soldé
        ->and($calc->cumulTheoriqueCentimes($immo, 2028))->toBe(300000);
});

it('rattrape une durée corrigée en cours de vie sur l’exercice suivant', function (): void {
    $calc = app(PlanAmortissementCalculator::class);

    // 3 000 € sur 60 mois : 600 € comptabilisés en 2026.
    // La durée est ramenée à 30 mois → cumul théorique fin 2027 = 2 400 €.
    $immoCorrigee = immo('2026-09-01', 30, '3000.00');

    expect($calc->dotationCentimes($immoCorrigee, 2027, 60000))->toBe(180000);
});

it('ne dote rien quand le bien n’est pas encore en service', function (): void {
    $calc = app(PlanAmortissementCalculator::class);
    $immo = immo('2028-03-01', 60, '3000.00');

    expect($calc->dotationCentimes($immo, 2026, 0))->toBe(0);
});
```

- [ ] **Step 2 : lancer les tests pour vérifier qu'ils échouent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Unit/Services/Immobilisation/PlanAmortissementCalculatorTest.php --compact`
Expected : FAIL — `Target class [App\Services\Immobilisation\PlanAmortissementCalculator] does not exist.`

- [ ] **Step 3 : écrire le calculateur**

```php
<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

use App\Models\Immobilisation;
use App\Services\ExerciceService;
use Carbon\CarbonImmutable;

/**
 * Amortissement linéaire au prorata mensuel.
 *
 * Règles (spec § 6) :
 *  - le mois de mise en service compte pour un mois entier ;
 *  - cumul théorique = montant × mois écoulés / durée, arrondi au centime ;
 *  - dotation = cumul théorique − cumul déjà comptabilisé.
 *
 * Cette dernière règle absorbe les arrondis au lieu de les accumuler : la
 * dernière dotation solde le bien à l'euro près par construction, et une durée
 * ou un montant corrigés en cours de vie se rattrapent d'eux-mêmes sur
 * l'exercice suivant.
 *
 * Tous les calculs se font en centimes entiers — aucun flottant intermédiaire.
 */
final class PlanAmortissementCalculator
{
    public function __construct(private readonly ExerciceService $exerciceService) {}

    /**
     * Nombre de mois écoulés entre le mois de mise en service (inclus) et le
     * mois de clôture de l'exercice (inclus), plafonné à la durée, plancher 0.
     */
    public function moisEcoules(Immobilisation $immobilisation, int $exercice): int
    {
        $finExercice = CarbonImmutable::instance(
            $this->exerciceService->dateRange($exercice)['end']->toDateTime()
        );

        $mes = CarbonImmutable::instance($immobilisation->date_mise_en_service->toDateTime());

        $mois = (($finExercice->year - $mes->year) * 12) + ($finExercice->month - $mes->month) + 1;

        return max(0, min($mois, (int) $immobilisation->duree_mois));
    }

    /** Cumul théorique en centimes à la fin de l'exercice donné. */
    public function cumulTheoriqueCentimes(Immobilisation $immobilisation, int $exercice): int
    {
        $dureeMois = (int) $immobilisation->duree_mois;

        if ($dureeMois <= 0) {
            return 0;
        }

        $montantCentimes = $immobilisation->montantAcquisitionCentimes();
        $moisEcoules = $this->moisEcoules($immobilisation, $exercice);

        return (int) round($montantCentimes * $moisEcoules / $dureeMois);
    }

    /**
     * Dotation de l'exercice, en centimes.
     *
     * Jamais négative : si le cumul comptabilisé dépasse le cumul théorique
     * (durée allongée après coup), la dotation est nulle et l'écart se résorbe
     * sur les exercices suivants.
     */
    public function dotationCentimes(
        Immobilisation $immobilisation,
        int $exercice,
        int $cumulComptabiliseCentimes,
    ): int {
        $cumulTheorique = $this->cumulTheoriqueCentimes($immobilisation, $exercice);

        return max(0, $cumulTheorique - $cumulComptabiliseCentimes);
    }
}
```

- [ ] **Step 4 : lancer les tests pour vérifier qu'ils passent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Unit/Services/Immobilisation/PlanAmortissementCalculatorTest.php --compact`
Expected : PASS (8 tests)

- [ ] **Step 5 : formater et committer**

```bash
./vendor/bin/pint app/Services/Immobilisation tests/Unit/Services/Immobilisation
git add app/Services/Immobilisation/PlanAmortissementCalculator.php tests/Unit/Services/Immobilisation/PlanAmortissementCalculatorTest.php
git commit -m "feat(immos): calcul linéaire au prorata mensuel, en centimes entiers"
```

---

## Task 4 : ouvrir le garde-fou de classe à la classe 2

**Files:**
- Modify: `app/Services/Compta/EcritureGenerator.php` (méthode `pourDepenseACredit`, garde vers la ligne 965)
- Test: `tests/Unit/Services/Compta/EcritureGeneratorImmobilisationTest.php`

- [ ] **Step 1 : écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

use App\Exceptions\Compta\CompteIncorrectException;
use App\Models\Compte;
use App\Models\Tiers;
use App\Services\Compta\EcritureGenerator;
use Carbon\Carbon;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
});

it('refuse un compte de classe 2 sans le drapeau', function (): void {
    $generator = app(EcritureGenerator::class);
    $compte2 = Compte::factory()->create(['numero_pcg' => '2188', 'classe' => 2]);
    $tiers = Tiers::factory()->create();

    expect(fn () => $generator->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compte2, 'montant' => 3000.0]],
        dateConstatation: Carbon::parse('2026-09-12'),
    ))->toThrow(CompteIncorrectException::class);
});

it('accepte un compte de classe 2 avec le drapeau', function (): void {
    $generator = app(EcritureGenerator::class);
    $compte2 = Compte::factory()->create(['numero_pcg' => '2188', 'classe' => 2]);
    $tiers = Tiers::factory()->create();

    $tx = $generator->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compte2, 'montant' => 3000.0]],
        dateConstatation: Carbon::parse('2026-09-12'),
        autoriseImmobilisation: true,
    );

    expect($tx->equilibree)->toBeTrue()
        ->and($tx->lignes->firstWhere('compte_id', (int) $compte2->id)->debit)->toEqual('3000.00');
});

it('refuse une classe autre que 2 ou 6 même avec le drapeau', function (): void {
    $generator = app(EcritureGenerator::class);
    $compte7 = Compte::factory()->create(['numero_pcg' => '706', 'classe' => 7]);
    $tiers = Tiers::factory()->create();

    expect(fn () => $generator->pourDepenseACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compte7, 'montant' => 100.0]],
        dateConstatation: Carbon::parse('2026-09-12'),
        autoriseImmobilisation: true,
    ))->toThrow(CompteIncorrectException::class);
});
```

- [ ] **Step 2 : lancer les tests pour vérifier qu'ils échouent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Unit/Services/Compta/EcritureGeneratorImmobilisationTest.php --compact`
Expected : FAIL — `Unknown named parameter $autoriseImmobilisation`

- [ ] **Step 3 : modifier la signature et le garde**

Dans `app/Services/Compta/EcritureGenerator.php`, méthode `pourDepenseACredit`, ajouter le paramètre en **dernière position** (pour ne casser aucun appel positionnel existant) :

```php
    public function pourDepenseACredit(
        Tiers $tiers,
        iterable $ventilations,
        \DateTimeInterface $dateConstatation,
        ?string $libelle = null,
        ?Transaction $existingTransaction = null,
        bool $autoriseImmobilisation = false,
    ): Transaction {
```

Puis remplacer le bloc de validation :

```php
        // --- Validation : chaque compte ventilé est classe 6 ---
        foreach ($ventilationsNorm as $v) {
            /** @var Compte $compteVent */
            $compteVent = $v['compte'];

            if ($compteVent->classe !== 6) {
                throw CompteIncorrectException::classeAttendue(
                    $compteVent->numero_pcg,
                    $compteVent->classe,
                    6
                );
            }
        }
```

par :

```php
        // --- Validation : chaque compte ventilé est classe 6 ---
        //
        // $autoriseImmobilisation ouvre la classe 2 et n'est passé que par
        // ImmobilisationService. Les autres appelants (TransactionService,
        // TransactionConverter, HelloAsso, notes de frais, factures
        // fournisseurs) conservent le défaut false et restent verrouillés.
        foreach ($ventilationsNorm as $v) {
            /** @var Compte $compteVent */
            $compteVent = $v['compte'];

            $classeAutorisee = $compteVent->classe === 6
                || ($autoriseImmobilisation && $compteVent->classe === 2);

            if (! $classeAutorisee) {
                throw CompteIncorrectException::classeAttendue(
                    $compteVent->numero_pcg,
                    $compteVent->classe,
                    $autoriseImmobilisation ? '2 ou 6' : 6
                );
            }
        }
```

Mettre à jour le docblock de la méthode :

```php
     * @throws CompteIncorrectException Si un compte ventilé ∉ classe 6
     *                                  (∉ classes 2 et 6 si $autoriseImmobilisation).
```

- [ ] **Step 4 : lancer les tests pour vérifier qu'ils passent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Unit/Services/Compta/EcritureGeneratorImmobilisationTest.php --compact`
Expected : PASS (3 tests)

- [ ] **Step 5 : vérifier la non-régression du garde existant**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Unit/Services/Compta --compact`
Expected : PASS — en particulier `EcritureGeneratorPourDepenseACreditTest.php`, dont le test « lève CompteIncorrectException si compte ventilation ∉ classe 6 » doit rester vert.

- [ ] **Step 6 : formater et committer**

```bash
./vendor/bin/pint app/Services/Compta tests/Unit/Services/Compta
git add app/Services/Compta/EcritureGenerator.php tests/Unit/Services/Compta/EcritureGeneratorImmobilisationTest.php
git commit -m "feat(immos): ouvre la classe 2 dans pourDepenseACredit via un drapeau à défaut false"
```

---

## Task 5 : comptes de classe 2, familles, et ouverture du plan comptable

**Files:**
- Create: `app/Services/Immobilisation/ImmobilisationComptesSeeder.php`
- Modify: `app/Livewire/PlanComptable.php:38`
- Modify: `app/Services/Compta/PlanComptableSelecteur.php`
- Test: `tests/Unit/Services/Immobilisation/ImmobilisationComptesSeederTest.php`

- [ ] **Step 1 : écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Famille;
use App\Services\Compta\PlanComptableSelecteur;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;

it('crée le kit de comptes et les familles', function (): void {
    ImmobilisationComptesSeeder::seed();

    foreach (['2154', '2183', '2184', '2188', '28154', '28183', '28184', '28188', '6811'] as $numero) {
        expect(Compte::ofNumero($numero))->not->toBeNull("Le compte {$numero} devrait exister");
    }

    expect(Compte::ofNumero('2188')->classe)->toBe(2)
        ->and(Compte::ofNumero('6811')->classe)->toBe(6);

    foreach (['21', '28', '68'] as $code) {
        expect(Famille::where('code', $code)->exists())->toBeTrue("La famille {$code} devrait exister");
    }
});

it('est idempotent', function (): void {
    ImmobilisationComptesSeeder::seed();
    ImmobilisationComptesSeeder::seed();

    expect(Compte::where('numero_pcg', '2188')->count())->toBe(1)
        ->and(Famille::where('code', '21')->count())->toBe(1);
});

it('expose les comptes de classe 2 au sélecteur de ventilation', function (): void {
    ImmobilisationComptesSeeder::seed();

    $groupes = PlanComptableSelecteur::groupesPourType('immobilisation');
    $numeros = $groupes->flatMap(fn (array $g) => $g['comptes']->pluck('numero_pcg'))->all();

    expect($numeros)->toContain('2188')
        ->and($numeros)->not->toContain('6811');
});
```

- [ ] **Step 2 : lancer le test pour vérifier qu'il échoue**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Unit/Services/Immobilisation/ImmobilisationComptesSeederTest.php --compact`
Expected : FAIL — `Class "App\Services\Immobilisation\ImmobilisationComptesSeeder" not found`

- [ ] **Step 3 : écrire le seeder**

```php
<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

use App\Models\Compte;
use App\Models\Famille;
use App\Tenant\TenantContext;

/**
 * Kit minimal de comptes nécessaires aux immobilisations, créé à la demande.
 *
 * Idempotent : un rejeu est un no-op sur les comptes et familles déjà présents.
 * Même intention que ComptesProvisioningService, restreinte aux immobilisations.
 */
final class ImmobilisationComptesSeeder
{
    /** @var array<string, string> numero_pcg => intitulé */
    private const COMPTES = [
        '2154' => 'Matériel',
        '2183' => 'Matériel de bureau et informatique',
        '2184' => 'Mobilier',
        '2188' => 'Autres immobilisations corporelles',
        '28154' => 'Amortissements du matériel',
        '28183' => 'Amortissements du matériel de bureau et informatique',
        '28184' => 'Amortissements du mobilier',
        '28188' => 'Amortissements des autres immobilisations corporelles',
        '6811' => 'Dotations aux amortissements sur immobilisations corporelles',
    ];

    /** @var array<string, string> code famille => nom */
    private const FAMILLES = [
        '21' => 'Immobilisations corporelles',
        '28' => 'Amortissements des immobilisations',
        '68' => 'Dotations aux amortissements',
    ];

    public static function seed(): void
    {
        $associationId = (int) TenantContext::currentId();

        foreach (self::FAMILLES as $code => $nom) {
            Famille::firstOrCreate(
                ['association_id' => $associationId, 'code' => $code],
                ['nom' => $nom],
            );
        }

        foreach (self::COMPTES as $numero => $intitule) {
            Compte::firstOrCreate(
                ['association_id' => $associationId, 'numero_pcg' => $numero],
                [
                    'intitule' => $intitule,
                    'classe' => (int) substr($numero, 0, 1),
                    'actif' => true,
                    'lettrable' => false,
                ],
            );
        }
    }

    /**
     * Compte d'amortissement dérivé du compte d'immobilisation par la règle PCG :
     * on insère un « 8 » après le premier chiffre (2154 → 28154).
     *
     * Retourne null si le compte dérivé n'existe pas dans le plan du tenant.
     */
    public static function compteAmortissementPour(Compte $compteImmobilisation): ?Compte
    {
        $numero = (string) $compteImmobilisation->numero_pcg;

        return Compte::ofNumero('2'.'8'.substr($numero, 1));
    }
}
```

- [ ] **Step 4 : ouvrir le sélecteur à la classe 2**

Dans `app/Services/Compta/PlanComptableSelecteur.php`, remplacer le `match` :

```php
        $classe = match ($type) {
            'depense' => 6,
            'recette' => 7,
            default => throw new \InvalidArgumentException("Type de ventilation invalide : {$type}"),
        };
```

par :

```php
        $classe = match ($type) {
            'depense' => 6,
            'recette' => 7,
            'immobilisation' => 2,
            default => throw new \InvalidArgumentException("Type de ventilation invalide : {$type}"),
        };
```

Puis, dans la même méthode, exclure les comptes d'amortissement du choix : on ne ventile jamais une acquisition sur un 28X.

```php
        $comptes = Compte::where('classe', $classe)
            ->where('actif', true)
            ->when($classe === 2, fn ($q) => $q->where('numero_pcg', 'NOT LIKE', '28%'))
            ->orderBy('numero_pcg')
            ->get();
```

Mettre à jour le docblock du paramètre :

```php
     * @param  string  $type  'depense' (classe 6), 'recette' (classe 7) ou
     *                        'immobilisation' (classe 2, hors comptes 28X)
```

- [ ] **Step 5 : ouvrir l'écran Plan comptable à la classe 2**

Dans `app/Livewire/PlanComptable.php`, ligne 38, remplacer :

```php
        $comptes = Compte::whereIn('classe', [6, 7])
```

par :

```php
        $comptes = Compte::whereIn('classe', [2, 6, 7])
```

Et adapter le commentaire de classe, ligne 18 :

```php
 * Gère les comptes de résultat (classes 6/7) et les comptes d'immobilisation
 * et d'amortissement (classe 2), groupés par famille
```

- [ ] **Step 6 : lancer les tests pour vérifier qu'ils passent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Unit/Services/Immobilisation/ImmobilisationComptesSeederTest.php --compact`
Expected : PASS (3 tests)

- [ ] **Step 7 : vérifier la non-régression du plan comptable**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/PlanComptable --compact` (si le répertoire n'existe pas, lancer `php -d memory_limit=1G ./vendor/bin/pest --filter=PlanComptable --compact`)
Expected : PASS

- [ ] **Step 8 : formater et committer**

```bash
./vendor/bin/pint app/Services app/Livewire/PlanComptable.php tests/Unit/Services/Immobilisation
git add app/Services/Immobilisation/ImmobilisationComptesSeeder.php app/Services/Compta/PlanComptableSelecteur.php app/Livewire/PlanComptable.php tests/Unit/Services/Immobilisation/ImmobilisationComptesSeederTest.php
git commit -m "feat(immos): kit de comptes classe 2, familles 21/28/68 et ouverture du plan comptable"
```

---

## Task 6 : acquisition — fiche et écriture, atomiquement

**Files:**
- Create: `app/Services/Immobilisation/ImmobilisationService.php`
- Create: `app/Exceptions/Immobilisation/MiseEnServiceAnterieureException.php`
- Test: `tests/Feature/Immobilisation/AcquisitionTest.php`

- [ ] **Step 1 : écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Exceptions\Immobilisation\MiseEnServiceAnterieureException;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
});

it('crée la fiche et son écriture d’acquisition à crédit', function (): void {
    $tiers = Tiers::factory()->create();

    $immo = app(ImmobilisationService::class)->acquerir(
        tiers: $tiers,
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    expect($immo->numero)->toBe('IM00001')
        ->and($immo->quantite)->toBe(20);

    $tx = $immo->transaction;
    expect($tx->type)->toBe(TypeTransaction::Depense)
        ->and($tx->journal)->toBe(JournalComptable::Achat)
        ->and($tx->equilibree)->toBeTrue();

    $ligne2188 = $tx->lignes->firstWhere('compte_id', (int) Compte::ofNumero('2188')->id);
    expect($ligne2188->debit)->toEqual('3000.00');

    $ligne401 = $tx->lignes->firstWhere('compte_id', (int) Compte::ofNumero('401')->id);
    expect($ligne401->credit)->toEqual('3000.00')
        ->and((int) $ligne401->tiers_id)->toBe((int) $tiers->id);
});

it('refuse une mise en service antérieure à l’exercice de l’acquisition', function (): void {
    $tiers = Tiers::factory()->create();

    expect(fn () => app(ImmobilisationService::class)->acquerir(
        tiers: $tiers,
        libelle: 'Matériel',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),      // exercice 2026
        dateMiseEnService: Carbon::parse('2026-06-01'), // exercice 2025
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    ))->toThrow(MiseEnServiceAnterieureException::class);
});

it('accepte une mise en service antérieure à l’achat dans le même exercice', function (): void {
    $tiers = Tiers::factory()->create();

    $immo = app(ImmobilisationService::class)->acquerir(
        tiers: $tiers,
        libelle: 'Matériel livré puis facturé',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-10-15'),
        dateMiseEnService: Carbon::parse('2026-09-20'),
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    );

    expect($immo->date_mise_en_service->toDateString())->toBe('2026-09-20');
});

it('n’écrit aucune fiche si l’écriture échoue', function (): void {
    $tiers = Tiers::factory()->create();
    $compte6 = Compte::factory()->create(['numero_pcg' => '606', 'classe' => 6]);

    expect(fn () => app(ImmobilisationService::class)->acquerir(
        tiers: $tiers,
        libelle: 'Compte invalide',
        quantite: 1,
        compte: $compte6,                                  // classe 6 : refusé côté immo
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 36,
        modePaiement: null,
        compteTresorerie: null,
    ))->toThrow(Throwable::class);

    expect(Immobilisation::count())->toBe(0);
});
```

- [ ] **Step 2 : lancer les tests pour vérifier qu'ils échouent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/AcquisitionTest.php --compact`
Expected : FAIL — `Target class [App\Services\Immobilisation\ImmobilisationService] does not exist.`

- [ ] **Step 3 : écrire l'exception**

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Immobilisation;

use RuntimeException;

final class MiseEnServiceAnterieureException extends RuntimeException
{
    public static function pourExercice(string $miseEnService, string $debutExercice): self
    {
        return new self(
            "La date de mise en service ({$miseEnService}) ne peut pas précéder le début de "
            ."l'exercice de l'acquisition ({$debutExercice}) : le bien serait amorti avant "
            .'son entrée à l’actif.'
        );
    }
}
```

- [ ] **Step 4 : écrire le service**

```php
<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

use App\Enums\ModePaiement;
use App\Exceptions\Immobilisation\MiseEnServiceAnterieureException;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Services\Compta\EcritureGenerator;
use App\Services\ExerciceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Acquisition d'une immobilisation.
 *
 * La fiche est le maître : elle naît avec son écriture dans la même transaction
 * DB, ce qui interdit structurellement la fiche orpheline comme l'acquisition
 * sans fiche.
 *
 * L'écriture est déléguée à la mécanique dépense existante — c'est elle qui
 * apporte la dette 401, le lettrage, le règlement, la remise et le
 * rapprochement, sans une ligne de code de plus ici.
 */
final class ImmobilisationService
{
    public function __construct(
        private readonly EcritureGenerator $ecritureGenerator,
        private readonly ImmobilisationSequenceService $sequence,
        private readonly ExerciceService $exerciceService,
    ) {}

    public function acquerir(
        Tiers $tiers,
        string $libelle,
        int $quantite,
        Compte $compte,
        Compte $compteAmortissement,
        string $montant,
        \DateTimeInterface $dateAchat,
        \DateTimeInterface $dateMiseEnService,
        int $dureeMois,
        ?ModePaiement $modePaiement,
        ?Compte $compteTresorerie,
        ?string $notes = null,
    ): Immobilisation {
        $this->assertMiseEnServiceCoherente($dateAchat, $dateMiseEnService);

        return DB::transaction(function () use (
            $tiers, $libelle, $quantite, $compte, $compteAmortissement, $montant,
            $dateAchat, $dateMiseEnService, $dureeMois, $modePaiement,
            $compteTresorerie, $notes
        ): Immobilisation {
            $transaction = $this->ecritureGenerator->pourDepenseACredit(
                tiers: $tiers,
                ventilations: [['compte' => $compte, 'montant' => (float) $montant]],
                dateConstatation: $dateAchat,
                libelle: $libelle,
                existingTransaction: null,
                autoriseImmobilisation: true,
            );

            if ($modePaiement !== null && $compteTresorerie !== null) {
                $this->ecritureGenerator->pourReglementFournisseur(
                    transactionDette: $transaction,
                    mode: $modePaiement,
                    compteTresorerie: $compteTresorerie,
                    datePaiement: $dateAchat,
                    libelle: 'Règlement '.$libelle,
                );
            }

            return Immobilisation::create([
                'numero' => $this->sequence->prochain(),
                'libelle' => $libelle,
                'quantite' => $quantite,
                'compte_id' => (int) $compte->id,
                'compte_amortissement_id' => (int) $compteAmortissement->id,
                'montant_acquisition' => $montant,
                'date_mise_en_service' => CarbonImmutable::instance(
                    CarbonImmutable::parse($dateMiseEnService->format('Y-m-d'))
                )->toDateString(),
                'duree_mois' => $dureeMois,
                'transaction_id' => (int) $transaction->id,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * La mise en service ne peut pas précéder le début de l'exercice de
     * l'acquisition — sinon on doterait un exercice où le bien n'est pas encore
     * à l'actif. Aucune borne supérieure : la mise en service différée est
     * légitime, et le calculateur la gère par son plancher à 0.
     */
    private function assertMiseEnServiceCoherente(
        \DateTimeInterface $dateAchat,
        \DateTimeInterface $dateMiseEnService,
    ): void {
        $exerciceAchat = $this->exerciceService->anneeForDate(
            CarbonImmutable::parse($dateAchat->format('Y-m-d'))
        );
        $debutExercice = $this->exerciceService->dateRange($exerciceAchat)['start'];

        if (CarbonImmutable::parse($dateMiseEnService->format('Y-m-d'))->lt($debutExercice)) {
            throw MiseEnServiceAnterieureException::pourExercice(
                $dateMiseEnService->format('d/m/Y'),
                $debutExercice->format('d/m/Y'),
            );
        }
    }
}
```

- [ ] **Step 5 : lancer les tests pour vérifier qu'ils passent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/AcquisitionTest.php --compact`
Expected : PASS (4 tests)

- [ ] **Step 6 : formater et committer**

```bash
./vendor/bin/pint app/Services/Immobilisation app/Exceptions/Immobilisation tests/Feature/Immobilisation
git add app/Services/Immobilisation/ImmobilisationService.php app/Exceptions/Immobilisation/MiseEnServiceAnterieureException.php tests/Feature/Immobilisation/AcquisitionTest.php
git commit -m "feat(immos): acquisition atomique fiche + écriture en classe 2"
```

---

## Task 7 : génération, recalcul et annulation des dotations

**Files:**
- Modify: `app/Services/Compta/EcritureGenerator.php` (nouvelle méthode `pourDotationAmortissement`)
- Create: `app/Services/Immobilisation/DotationService.php`
- Create: `app/Services/Immobilisation/LigneDotationPreview.php`
- Create: `app/Exceptions/Immobilisation/DotationInterditeException.php`
- Test: `tests/Feature/Immobilisation/DotationTest.php`

- [ ] **Step 1 : écrire les tests qui échouent**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Exceptions\Immobilisation\DotationInterditeException;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->creerImmo = function (string $montant = '3000.00', int $duree = 60): Immobilisation {
        return app(ImmobilisationService::class)->acquerir(
            tiers: Tiers::factory()->create(),
            libelle: '20 tenues d’escrime',
            quantite: 20,
            compte: Compte::ofNumero('2188'),
            compteAmortissement: Compte::ofNumero('28188'),
            montant: $montant,
            dateAchat: Carbon::parse('2026-09-12'),
            dateMiseEnService: Carbon::parse('2026-09-12'),
            dureeMois: $duree,
            modePaiement: null,
            compteTresorerie: null,
        );
    };
});

it('génère une écriture 6811 / 28188 datée du dernier jour de l’exercice', function (): void {
    ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15'); // on clôture en octobre, après la fin de l’exercice

    app(DotationService::class)->generer(2026);

    $dotation = ImmobilisationDotation::where('exercice', 2026)->firstOrFail();
    expect($dotation->montant)->toEqual('600.00');

    $tx = $dotation->transaction;
    expect($tx->date->toDateString())->toBe('2027-08-31')
        ->and($tx->type)->toBe(TypeTransaction::Depense)
        ->and($tx->journal)->toBe(JournalComptable::Od)
        ->and($tx->equilibree)->toBeTrue()
        ->and($tx->libelle)->toContain('IM00001');

    $debit = $tx->lignes->firstWhere('compte_id', (int) Compte::ofNumero('6811')->id);
    $credit = $tx->lignes->firstWhere('compte_id', (int) Compte::ofNumero('28188')->id);
    expect($debit->debit)->toEqual('600.00')
        ->and($credit->credit)->toEqual('600.00');

    Carbon::setTestNow();
});

it('ignore la date du jour pour dater l’écriture', function (): void {
    ($this->creerImmo)();

    Carbon::setTestNow('2029-01-05'); // deux ans plus tard
    app(DotationService::class)->generer(2026);
    Carbon::setTestNow();

    expect(ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->transaction->date->toDateString())
        ->toBe('2027-08-31');
});

it('est idempotent : un rejeu ne crée pas de doublon', function (): void {
    ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');

    app(DotationService::class)->generer(2026);
    app(DotationService::class)->generer(2026);

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(1);

    Carbon::setTestNow();
});

it('refuse de générer sur un exercice non terminé', function (): void {
    ($this->creerImmo)();
    Carbon::setTestNow('2027-03-01'); // l’exercice 2026 se termine le 31/08/2027

    expect(fn () => app(DotationService::class)->generer(2026))
        ->toThrow(DotationInterditeException::class);

    Carbon::setTestNow();
});

it('refuse de générer sur un exercice clôturé', function (): void {
    ($this->creerImmo)();
    // Il n'existe pas d'ExerciceFactory : on crée l'exercice directement.
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);
    Carbon::setTestNow('2027-10-15');

    expect(fn () => app(DotationService::class)->generer(2026))
        ->toThrow(DotationInterditeException::class);

    Carbon::setTestNow();
});

it('détecte l’écart après modification de la fiche et recalcule', function (): void {
    $immo = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');

    app(DotationService::class)->generer(2026);
    expect(ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->montant)->toEqual('600.00');

    // La durée passe de 60 à 30 mois : le cumul théorique fin 2026 double.
    $immo->update(['duree_mois' => 30]);

    $apercu = app(DotationService::class)->apercu(2026);
    $ligne = $apercu->first();
    expect($ligne->montantComptabiliseCentimes)->toBe(60000)
        ->and($ligne->montantRecalculeCentimes)->toBe(120000)
        ->and($ligne->enEcart())->toBeTrue();

    app(DotationService::class)->recalculer($immo->fresh(), 2026);

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(1)
        ->and(ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->montant)->toEqual('1200.00');

    Carbon::setTestNow();
});

it('ne dote pas un bien pas encore en service', function (): void {
    app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Matériel commandé',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2028-03-01'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(0);

    Carbon::setTestNow();
});
```

- [ ] **Step 2 : lancer les tests pour vérifier qu'ils échouent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/DotationTest.php --compact`
Expected : FAIL — `Target class [App\Services\Immobilisation\DotationService] does not exist.`

- [ ] **Step 3 : ajouter `pourDotationAmortissement` à `EcritureGenerator`**

À insérer juste après `pourProvisionExtourne`, dont elle reprend la structure.

```php
    /**
     * Dotation aux amortissements d'une immobilisation : 6811 D / 281X C.
     *
     * type = Depense et journal = Od, comme pourProvisionDotation : c'est une
     * opération d'inventaire, pas un mouvement de trésorerie.
     *
     * La date est imposée par l'appelant (dernier jour de l'exercice cible) et
     * n'est jamais dérivée de now().
     */
    public function pourDotationAmortissement(
        Immobilisation $immobilisation,
        \DateTimeInterface $date,
        string $montant,
    ): Transaction {
        $tenantId = (int) TenantContext::currentId();
        $libelle = 'Dotation '.$immobilisation->numero.' — '.$immobilisation->libelle;

        $compteDebit = Compte::where('association_id', $tenantId)
            ->where('numero_pcg', '6811')
            ->firstOrFail();

        $compteCredit = Compte::where('association_id', $tenantId)
            ->whereKey((int) $immobilisation->compte_amortissement_id)
            ->firstOrFail();

        return DB::transaction(function () use (
            $immobilisation, $date, $montant, $libelle, $tenantId, $compteDebit, $compteCredit
        ): Transaction {
            $numeroPiece = app(NumeroPieceService::class)->assign(Carbon::parse($date->format('Y-m-d')));

            $transaction = Transaction::create([
                'association_id' => $tenantId,
                'type' => TypeTransaction::Depense,
                'date' => $date->format('Y-m-d'),
                'libelle' => $libelle,
                'montant_total' => $montant,
                'mode_paiement' => null,
                'saisi_par' => Auth::id(),
                'equilibree' => true,
                'type_ecriture' => 'normale',
                'journal' => JournalComptable::Od,
                'numero_piece' => $numeroPiece,
            ]);

            $ligneDebit = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => $compteDebit->id,
                'debit' => $montant,
                'credit' => 0,
                'tiers_id' => null,
                'libelle' => $libelle,
                'montant' => 0,
            ]);
            $ligneDebit->setRelation('compte', $compteDebit);

            $ligneCredit = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'compte_id' => $compteCredit->id,
                'debit' => 0,
                'credit' => $montant,
                'tiers_id' => null,
                'libelle' => $libelle,
                'montant' => 0,
            ]);
            $ligneCredit->setRelation('compte', $compteCredit);

            $lignes = collect([$ligneDebit, $ligneCredit]);
            $this->assertEquilibre($lignes);
            $this->assertTenantCoherence($lignes);

            return $transaction->load('lignes.compte');
        });
    }
```

Ajouter l'import en tête de fichier : `use App\Models\Immobilisation;`

- [ ] **Step 4 : écrire l'objet d'aperçu et l'exception**

`app/Services/Immobilisation/LigneDotationPreview.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

use App\Models\Immobilisation;

/**
 * Une ligne de l'écran « Dotations de l'exercice ».
 *
 * L'écart est dérivé de la comparaison entre le montant comptabilisé et le
 * montant recalculé — il n'existe aucun indicateur « dirty » stocké, donc rien
 * qui puisse se désynchroniser.
 */
final class LigneDotationPreview
{
    public function __construct(
        public readonly Immobilisation $immobilisation,
        public readonly int $moisEcoules,
        public readonly int $montantComptabiliseCentimes,
        public readonly int $montantRecalculeCentimes,
        public readonly bool $dejaComptabilisee,
    ) {}

    public function enEcart(): bool
    {
        return $this->dejaComptabilisee
            && $this->montantComptabiliseCentimes !== $this->montantRecalculeCentimes;
    }

    public function aGenerer(): bool
    {
        return ! $this->dejaComptabilisee && $this->montantRecalculeCentimes > 0;
    }
}
```

`app/Exceptions/Immobilisation/DotationInterditeException.php` :

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Immobilisation;

use RuntimeException;

final class DotationInterditeException extends RuntimeException
{
    public static function exerciceNonTermine(string $finExercice): self
    {
        return new self(
            "Les dotations ne peuvent être générées qu'une fois l'exercice terminé "
            ."(le {$finExercice}). Le plan d'amortissement reste consultable sur chaque fiche."
        );
    }

    public static function exerciceCloture(int $annee): self
    {
        return new self(
            "L'exercice {$annee} est clôturé : ses dotations ne peuvent plus être "
            .'générées, recalculées ni annulées.'
        );
    }
}
```

- [ ] **Step 5 : écrire le service de dotation**

```php
<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

use App\Exceptions\Immobilisation\DotationInterditeException;
use App\Models\Exercice;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Services\Compta\EcritureGenerator;
use App\Services\ExerciceService;
use App\Support\MontantDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Génération, recalcul et annulation des dotations aux amortissements.
 *
 * Invariants de date (spec § 5.2.1) : la date du jour n'intervient ni dans le
 * calcul ni dans l'écriture. Générer en octobre N+1 les dotations de l'exercice
 * N est le cas normal — la clôture des comptes suit la fin de la période.
 *
 * Les gardes vivent ici et non dans l'écran : TransactionForm contraint la date
 * à l'exercice en cours, mais EcritureGenerator ne vérifie rien.
 */
final class DotationService
{
    public function __construct(
        private readonly EcritureGenerator $ecritureGenerator,
        private readonly PlanAmortissementCalculator $calculator,
        private readonly ExerciceService $exerciceService,
    ) {}

    /**
     * Aperçu de l'exercice : une ligne par fiche, sans rien écrire.
     *
     * @return Collection<int, LigneDotationPreview>
     */
    public function apercu(int $exercice): Collection
    {
        return Immobilisation::query()
            ->with(['compte', 'dotations'])
            ->orderBy('numero')
            ->get()
            ->map(function (Immobilisation $immobilisation) use ($exercice): LigneDotationPreview {
                $dotation = $immobilisation->dotations
                    ->firstWhere('exercice', $exercice);

                $cumulAnterieurCentimes = (int) round(
                    ((float) $immobilisation->dotations
                        ->where('exercice', '<', $exercice)
                        ->sum('montant')) * 100
                );

                return new LigneDotationPreview(
                    immobilisation: $immobilisation,
                    moisEcoules: $this->calculator->moisEcoules($immobilisation, $exercice),
                    montantComptabiliseCentimes: $dotation === null
                        ? 0
                        : (int) round(((float) $dotation->montant) * 100),
                    montantRecalculeCentimes: $this->calculator->dotationCentimes(
                        $immobilisation,
                        $exercice,
                        $cumulAnterieurCentimes,
                    ),
                    dejaComptabilisee: $dotation !== null,
                );
            });
    }

    /** Génère les dotations manquantes de l'exercice. Idempotent. */
    public function generer(int $exercice): int
    {
        $this->assertExerciceGenerable($exercice);

        $generees = 0;

        foreach ($this->apercu($exercice) as $ligne) {
            if (! $ligne->aGenerer()) {
                continue;
            }

            $this->comptabiliser($ligne->immobilisation, $exercice, $ligne->montantRecalculeCentimes);
            $generees++;
        }

        return $generees;
    }

    /** Annule puis régénère la dotation d'une fiche pour l'exercice donné. */
    public function recalculer(Immobilisation $immobilisation, int $exercice): void
    {
        $this->assertExerciceGenerable($exercice);

        DB::transaction(function () use ($immobilisation, $exercice): void {
            $this->annuler($immobilisation, $exercice);

            $cumulAnterieurCentimes = (int) round(
                ((float) $immobilisation->dotations()
                    ->where('exercice', '<', $exercice)
                    ->sum('montant')) * 100
            );

            $montantCentimes = $this->calculator->dotationCentimes(
                $immobilisation,
                $exercice,
                $cumulAnterieurCentimes,
            );

            if ($montantCentimes > 0) {
                $this->comptabiliser($immobilisation, $exercice, $montantCentimes);
            }
        });
    }

    /**
     * Annule la dotation d'une fiche : soft-delete de la transaction et
     * suppression de la ligne.
     *
     * ATTENTION — la transaction supprimée emporte ses affectations
     * analytiques. Si la dotation avait été ventilée sur des opérations, ce
     * travail est perdu et doit être refait. L'appelant DOIT en avertir
     * l'utilisateur.
     */
    public function annuler(Immobilisation $immobilisation, int $exercice): void
    {
        $this->assertExerciceGenerable($exercice);

        $dotation = ImmobilisationDotation::query()
            ->where('immobilisation_id', (int) $immobilisation->id)
            ->where('exercice', $exercice)
            ->first();

        if ($dotation === null) {
            return;
        }

        DB::transaction(function () use ($dotation): void {
            $dotation->transaction?->delete();
            $dotation->delete();
        });
    }

    private function comptabiliser(Immobilisation $immobilisation, int $exercice, int $montantCentimes): void
    {
        $montant = MontantDecimal::depuisCentimes($montantCentimes);
        $dateEcriture = $this->finExercice($exercice);

        DB::transaction(function () use ($immobilisation, $exercice, $montant, $dateEcriture): void {
            $transaction = $this->ecritureGenerator->pourDotationAmortissement(
                immobilisation: $immobilisation,
                date: $dateEcriture,
                montant: $montant,
            );

            ImmobilisationDotation::create([
                'immobilisation_id' => (int) $immobilisation->id,
                'exercice' => $exercice,
                'montant' => $montant,
                'transaction_id' => (int) $transaction->id,
            ]);
        });
    }

    /** Dernier jour de l'exercice — jamais now(), jamais une valeur en dur. */
    private function finExercice(int $exercice): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $this->exerciceService->dateRange($exercice)['end']->toDateString()
        );
    }

    private function assertExerciceGenerable(int $exercice): void
    {
        $fin = $this->finExercice($exercice);

        if ($fin->isFuture()) {
            throw DotationInterditeException::exerciceNonTermine($fin->format('d/m/Y'));
        }

        $exerciceModel = Exercice::where('annee', $exercice)->first();

        if ($exerciceModel !== null && $exerciceModel->isCloture()) {
            throw DotationInterditeException::exerciceCloture($exercice);
        }
    }
}
```

- [ ] **Step 6 : lancer les tests pour vérifier qu'ils passent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/DotationTest.php --compact`
Expected : PASS (7 tests)

- [ ] **Step 7 : formater et committer**

```bash
./vendor/bin/pint app/Services app/Exceptions/Immobilisation tests/Feature/Immobilisation
git add app/Services/Compta/EcritureGenerator.php app/Services/Immobilisation/DotationService.php app/Services/Immobilisation/LigneDotationPreview.php app/Exceptions/Immobilisation/DotationInterditeException.php tests/Feature/Immobilisation/DotationTest.php
git commit -m "feat(immos): génération, recalcul et annulation des dotations avec gardes de date"
```

---

## Task 8 : routes, navigation et livre des immobilisations

**Files:**
- Create: `app/Livewire/Immobilisations/ImmobilisationIndex.php`
- Create: `resources/views/livewire/immobilisations/immobilisation-index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/components/sidebar.blade.php`
- Test: `tests/Feature/Immobilisation/LivreTest.php`

- [ ] **Step 1 : écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\ImmobilisationIndex;
use App\Models\Compte;
use App\Models\Tiers;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
});

it('affiche le livre avec la valeur nette comptable', function (): void {
    app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    Livewire::test(ImmobilisationIndex::class)
        ->assertSee('IM00001')
        ->assertSee('20 tenues d’escrime')
        ->assertSee('5 ans')
        ->assertSee('3 000,00');
});

it('signale une fiche pas encore en service', function (): void {
    app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Matériel commandé',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '1000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2099-03-01'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    Livewire::test(ImmobilisationIndex::class)->assertSee('Pas encore en service');
});
```

- [ ] **Step 2 : lancer le test pour vérifier qu'il échoue**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/LivreTest.php --compact`
Expected : FAIL — `Class "App\Livewire\Immobilisations\ImmobilisationIndex" not found`

- [ ] **Step 3 : écrire le composant**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Immobilisations;

use App\Models\Immobilisation;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

final class ImmobilisationIndex extends Component
{
    public function render(): View
    {
        /** @var Collection<int, Immobilisation> $immobilisations */
        $immobilisations = Immobilisation::query()
            ->with(['compte', 'dotations'])
            ->orderBy('numero')
            ->get();

        return view('livewire.immobilisations.immobilisation-index', [
            'immobilisations' => $immobilisations,
            'totalBrutCentimes' => $immobilisations->sum(
                fn (Immobilisation $i): int => $i->montantAcquisitionCentimes()
            ),
            'totalCumulCentimes' => $immobilisations->sum(
                fn (Immobilisation $i): int => $i->cumulAmortiCentimes()
            ),
            'totalNetCentimes' => $immobilisations->sum(
                fn (Immobilisation $i): int => $i->valeurNetteCentimes()
            ),
        ])->layout('layouts.app-sidebar', ['title' => 'Immobilisations']);
    }
}
```

- [ ] **Step 4 : écrire la vue**

`resources/views/livewire/immobilisations/immobilisation-index.blade.php` :

```blade
@php
    $euros = fn (int $centimes): string => number_format($centimes / 100, 2, ',', ' ');
@endphp

<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Livre des immobilisations</h4>
        <a href="{{ route('immobilisations.dotations') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-calculator me-1"></i> Dotations de l'exercice
        </a>
    </div>

    @if ($immobilisations->isEmpty())
        <div class="alert alert-info">
            Aucune immobilisation enregistrée. Les biens durables (matériel, mobilier,
            informatique) s'inscrivent ici plutôt qu'en charge de l'exercice.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle" id="table-immobilisations">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Numéro</th>
                        <th>Libellé</th>
                        <th class="text-end">Qté</th>
                        <th>Compte</th>
                        <th>Mise en service</th>
                        <th>Durée</th>
                        <th class="text-end">Valeur brute</th>
                        <th class="text-end">Amortissements</th>
                        <th class="text-end">Valeur nette</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($immobilisations as $immo)
                        <tr>
                            <td data-sort="{{ $immo->numero }}">
                                <a href="{{ route('immobilisations.show', $immo) }}">{{ $immo->numero }}</a>
                            </td>
                            <td>
                                {{ $immo->libelle }}
                                @unless ($immo->estEnService())
                                    <span class="badge bg-warning text-dark ms-1">Pas encore en service</span>
                                @endunless
                            </td>
                            <td class="text-end" data-sort="{{ $immo->quantite }}">{{ $immo->quantite }}</td>
                            <td>{{ $immo->compte->numero_pcg }} — {{ $immo->compte->intitule }}</td>
                            <td data-sort="{{ $immo->date_mise_en_service->format('Y-m-d') }}">
                                {{ $immo->date_mise_en_service->format('d/m/Y') }}
                            </td>
                            <td data-sort="{{ $immo->duree_mois }}">{{ $immo->duree_label }}</td>
                            <td class="text-end" data-sort="{{ $immo->montantAcquisitionCentimes() }}">
                                {{ $euros($immo->montantAcquisitionCentimes()) }} €
                            </td>
                            <td class="text-end" data-sort="{{ $immo->cumulAmortiCentimes() }}">
                                {{ $euros($immo->cumulAmortiCentimes()) }} €
                            </td>
                            <td class="text-end fw-semibold" data-sort="{{ $immo->valeurNetteCentimes() }}">
                                {{ $euros($immo->valeurNetteCentimes()) }} €
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="6" class="text-end">Totaux</td>
                        <td class="text-end">{{ $euros($totalBrutCentimes) }} €</td>
                        <td class="text-end">{{ $euros($totalCumulCentimes) }} €</td>
                        <td class="text-end">{{ $euros($totalNetCentimes) }} €</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
```

- [ ] **Step 5 : ajouter les routes**

Chaque tâche déclare **uniquement** les routes dont elle crée la classe, pour rester committable seule. Ici : `immobilisations.index` et rien d'autre.

Dans `routes/web.php`, après le groupe « Comptabilité — Factures fournisseurs », ajouter :

```php
// ── Comptabilité — Immobilisations ──
// Attention à l'ordre lors des ajouts ultérieurs : la route littérale
// /dotations (tâche 12) doit précéder /{immobilisation} (tâche 10), sinon
// « dotations » serait capté comme valeur du paramètre de route.
Route::middleware(['auth', 'verified', EnsureTwoFactor::class])
    ->group(function (): void {
        Route::get('/comptabilite/immobilisations', ImmobilisationIndex::class)
            ->name('immobilisations.index');
    });
```

Import à ajouter en tête de `routes/web.php` :

```php
use App\Livewire\Immobilisations\ImmobilisationIndex;
```

Le blade écrit à l'étape 4 référence deux routes qui n'existent pas encore. Les neutraliser **dans cette tâche**, les tâches 10 et 12 les rétabliront :

- remplacer le bouton « Dotations de l'exercice » de l'en-tête par `{{-- Bouton rétabli en tâche 12 --}}` ;
- remplacer la cellule du numéro par `<td data-sort="{{ $immo->numero }}">{{ $immo->numero }}</td>`, sans balise `<a>`.

- [ ] **Step 6 : ajouter l'entrée de sidebar**

Dans `resources/views/components/sidebar.blade.php`, dans le groupe **Comptabilité**, après l'entrée « Budget » (vers la ligne 258-263), insérer :

```blade
                            <li class="nav-item">
                                <a href="{{ route('immobilisations.index') }}"
                                   class="nav-link {{ request()->routeIs('immobilisations.*') ? 'active' : '' }}">
                                    <i class="bi bi-box-seam me-1"></i> Immobilisations
                                </a>
                            </li>
```

Dans `resources/views/layouts/app-sidebar.blade.php`, ligne 155, ajouter `'immobilisations.*'` à la liste des routes qui affichent le titre « Comptabilité » :

```php
                        request()->routeIs('comptabilite.transactions*', 'comptabilite.budget*', 'comptabilite.ndf.*', 'comptabilite.factures-fournisseurs.*', 'immobilisations.*') => 'Comptabilité',
```

- [ ] **Step 7 : lancer les tests pour vérifier qu'ils passent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/LivreTest.php --compact`
Expected : PASS (2 tests)

- [ ] **Step 8 : formater et committer**

```bash
./vendor/bin/pint app/Livewire/Immobilisations routes tests/Feature/Immobilisation
git add app/Livewire/Immobilisations/ImmobilisationIndex.php resources/views/livewire/immobilisations/immobilisation-index.blade.php routes/web.php resources/views/components/sidebar.blade.php resources/views/layouts/app-sidebar.blade.php tests/Feature/Immobilisation/LivreTest.php
git commit -m "feat(immos): livre des immobilisations, route et entrée de menu"
```

---

## Task 9 : formulaire de création

**Files:**
- Modify: `app/Livewire/Immobilisations/ImmobilisationIndex.php`
- Modify: `resources/views/livewire/immobilisations/immobilisation-index.blade.php`
- Test: `tests/Feature/Immobilisation/CreationFormulaireTest.php`

- [ ] **Step 1 : écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\ImmobilisationIndex;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
});

it('crée une immobilisation depuis la modale', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', '20 tenues d’escrime')
        ->set('quantite', 20)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '3000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-09-12')
        ->set('duree_mois', 60)
        ->call('enregistrer')
        ->assertHasNoErrors();

    $immo = Immobilisation::firstOrFail();
    expect($immo->numero)->toBe('IM00001')
        ->and($immo->libelle)->toBe('20 tenues d’escrime')
        ->and((int) $immo->compte_amortissement_id)->toBe((int) Compte::ofNumero('28188')->id);
});

it('pré-remplit le compte d’amortissement dérivé du compte choisi', function (): void {
    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('compte_id', (string) Compte::ofNumero('2154')->id)
        ->assertSet('compte_amortissement_id', (string) Compte::ofNumero('28154')->id);
});

it('refuse une mise en service antérieure à l’exercice d’acquisition', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->set('libelle', 'Matériel')
        ->set('quantite', 1)
        ->set('compte_id', (string) Compte::ofNumero('2188')->id)
        ->set('tiers_id', (int) $tiers->id)
        ->set('montant', '1000.00')
        ->set('date_achat', '2026-09-12')
        ->set('date_mise_en_service', '2026-06-01')
        ->set('duree_mois', 36)
        ->call('enregistrer')
        ->assertHasErrors('date_mise_en_service');

    expect(Immobilisation::count())->toBe(0);
});
```

- [ ] **Step 2 : lancer le test pour vérifier qu'il échoue**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/CreationFormulaireTest.php --compact`
Expected : FAIL — `Method ouvrirModal does not exist`

- [ ] **Step 3 : étendre le composant**

Ajouter à `ImmobilisationIndex` les propriétés, la validation et les méthodes ci-dessous. `updatedCompteId` réalise la dérivation du compte d'amortissement.

```php
    // ── État de la modale ────────────────────────────────────────
    public bool $showModal = false;

    public string $libelle = '';

    public int $quantite = 1;

    public string $compte_id = '';

    public string $compte_amortissement_id = '';

    public ?int $tiers_id = null;

    public string $montant = '';

    public string $date_achat = '';

    public string $date_mise_en_service = '';

    public int $duree_mois = 60;

    public string $notes = '';

    public string $flashMessage = '';

    public string $flashType = '';

    /** Durées proposées, en mois. La saisie libre reste possible. */
    public const DUREES_USUELLES = [36 => '3 ans', 60 => '5 ans', 84 => '7 ans', 120 => '10 ans', 180 => '15 ans'];

    public function ouvrirModal(): void
    {
        $this->reset([
            'libelle', 'quantite', 'compte_id', 'compte_amortissement_id',
            'tiers_id', 'montant', 'date_achat', 'date_mise_en_service', 'notes',
        ]);
        $this->quantite = 1;
        $this->duree_mois = 60;
        $this->date_achat = app(ExerciceService::class)->defaultDate();
        $this->date_mise_en_service = $this->date_achat;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function fermerModal(): void
    {
        $this->showModal = false;
    }

    /** Dérive le compte 281X du compte 21X choisi (2154 → 28154). */
    public function updatedCompteId(string $value): void
    {
        $compte = Compte::find((int) $value);

        if ($compte === null) {
            return;
        }

        $derive = ImmobilisationComptesSeeder::compteAmortissementPour($compte);
        $this->compte_amortissement_id = $derive === null ? '' : (string) $derive->id;
    }

    /** La date d'achat pilote la mise en service tant que celle-ci n'a pas été touchée. */
    public function updatedDateAchat(string $value): void
    {
        if ($this->date_mise_en_service === '' || $this->date_mise_en_service < $value) {
            $this->date_mise_en_service = $value;
        }
    }

    public function enregistrer(): void
    {
        $this->validate([
            'libelle' => ['required', 'string', 'max:255'],
            'quantite' => ['required', 'integer', 'min:1'],
            'compte_id' => ['required', 'exists:comptes,id'],
            'compte_amortissement_id' => ['required', 'exists:comptes,id'],
            'tiers_id' => ['required', 'exists:tiers,id'],
            'montant' => ['required', 'numeric', 'gt:0'],
            'date_achat' => ['required', 'date'],
            'date_mise_en_service' => ['required', 'date'],
            'duree_mois' => ['required', 'integer', 'min:1', 'max:600'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'libelle' => 'libellé',
            'compte_id' => 'compte d’immobilisation',
            'compte_amortissement_id' => 'compte d’amortissement',
            'tiers_id' => 'fournisseur',
            'date_achat' => 'date d’achat',
            'date_mise_en_service' => 'date de mise en service',
            'duree_mois' => 'durée',
        ]);

        try {
            app(ImmobilisationService::class)->acquerir(
                tiers: Tiers::findOrFail((int) $this->tiers_id),
                libelle: $this->libelle,
                quantite: $this->quantite,
                compte: Compte::findOrFail((int) $this->compte_id),
                compteAmortissement: Compte::findOrFail((int) $this->compte_amortissement_id),
                montant: number_format((float) $this->montant, 2, '.', ''),
                dateAchat: CarbonImmutable::parse($this->date_achat),
                dateMiseEnService: CarbonImmutable::parse($this->date_mise_en_service),
                dureeMois: $this->duree_mois,
                modePaiement: null,
                compteTresorerie: null,
                notes: $this->notes === '' ? null : $this->notes,
            );
        } catch (MiseEnServiceAnterieureException $e) {
            $this->addError('date_mise_en_service', $e->getMessage());

            return;
        }

        $this->showModal = false;
        $this->flashMessage = 'Immobilisation enregistrée.';
        $this->flashType = 'success';
    }
```

Ajouter dans `render()` la liste des comptes proposés :

```php
            'comptesImmobilisation' => PlanComptableSelecteur::groupesPourType('immobilisation'),
            'dureesUsuelles' => self::DUREES_USUELLES,
```

Imports à ajouter :

```php
use App\Exceptions\Immobilisation\MiseEnServiceAnterieureException;
use App\Models\Compte;
use App\Models\Tiers;
use App\Services\Compta\PlanComptableSelecteur;
use App\Services\ExerciceService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\CarbonImmutable;
```

- [ ] **Step 4 : ajouter la modale à la vue**

Ajouter le bouton d'ouverture dans l'en-tête, à côté du titre :

```blade
        <button type="button" class="btn btn-primary btn-sm" wire:click="ouvrirModal">
            <i class="bi bi-plus-lg me-1"></i> Nouvelle immobilisation
        </button>
```

Puis, en fin de vue, la modale. `data-bs-backdrop="static"` et `data-bs-keyboard="false"` empêchent la fermeture au clic extérieur, conformément au correctif `57af945a`.

```blade
    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)"
             data-bs-backdrop="static" data-bs-keyboard="false" wire:key="modal-immo">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Nouvelle immobilisation</h5>
                        <button type="button" class="btn-close" wire:click="fermerModal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Libellé</label>
                                <input type="text" class="form-control @error('libelle') is-invalid @enderror"
                                       wire:model="libelle" placeholder="20 tenues d'escrime">
                                @error('libelle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Quantité</label>
                                <input type="number" min="1" class="form-control @error('quantite') is-invalid @enderror"
                                       wire:model="quantite">
                                @error('quantite') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Fournisseur</label>
                                <livewire:tiers-autocomplete wire:model="tiers_id" :key="'tiers-immo'" />
                                @error('tiers_id') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Montant</label>
                                <div class="input-group">
                                    <input type="text" class="form-control @error('montant') is-invalid @enderror"
                                           wire:model="montant" inputmode="decimal">
                                    <span class="input-group-text">€</span>
                                    @error('montant') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Compte d'immobilisation</label>
                                <select class="form-select @error('compte_id') is-invalid @enderror" wire:model.live="compte_id">
                                    <option value="">— choisir —</option>
                                    @foreach ($comptesImmobilisation as $groupe)
                                        <optgroup label="{{ $groupe['famille']?->nom ?? 'Autres' }}">
                                            @foreach ($groupe['comptes'] as $compte)
                                                <option value="{{ $compte->id }}">
                                                    {{ $compte->numero_pcg }} — {{ $compte->intitule }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                                @error('compte_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Compte d'amortissement</label>
                                <input type="text" class="form-control" readonly
                                       value="{{ $compte_amortissement_id ? \App\Models\Compte::find((int) $compte_amortissement_id)?->numero_pcg.' — '.\App\Models\Compte::find((int) $compte_amortissement_id)?->intitule : '' }}">
                                @error('compte_amortissement_id') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Date d'achat</label>
                                <input type="date" class="form-control @error('date_achat') is-invalid @enderror"
                                       wire:model.live="date_achat">
                                @error('date_achat') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Mise en service</label>
                                <input type="date" class="form-control @error('date_mise_en_service') is-invalid @enderror"
                                       wire:model="date_mise_en_service">
                                @error('date_mise_en_service') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Durée d'amortissement</label>
                                <select class="form-select @error('duree_mois') is-invalid @enderror" wire:model="duree_mois">
                                    @foreach ($dureesUsuelles as $mois => $label)
                                        <option value="{{ $mois }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('duree_mois') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" rows="2" wire:model="notes"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="fermerModal">Annuler</button>
                        <button type="button" class="btn btn-primary" wire:click="enregistrer">Enregistrer</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
```

Ajouter aussi, en haut de la vue, l'affichage du message flash :

```blade
    @if ($flashMessage !== '')
        <div class="alert alert-{{ $flashType }} alert-dismissible fade show">
            {{ $flashMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
```

- [ ] **Step 5 : lancer les tests pour vérifier qu'ils passent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/CreationFormulaireTest.php --compact`
Expected : PASS (3 tests)

- [ ] **Step 6 : formater et committer**

```bash
./vendor/bin/pint app/Livewire/Immobilisations tests/Feature/Immobilisation
git add app/Livewire/Immobilisations/ImmobilisationIndex.php resources/views/livewire/immobilisations/immobilisation-index.blade.php tests/Feature/Immobilisation/CreationFormulaireTest.php
git commit -m "feat(immos): formulaire de saisie d'une acquisition"
```

---

## Task 10 : fiche et plan d'amortissement

**Files:**
- Create: `app/Livewire/Immobilisations/ImmobilisationShow.php`
- Create: `resources/views/livewire/immobilisations/immobilisation-show.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/livewire/immobilisations/immobilisation-index.blade.php` (rétablir le lien sur le numéro)
- Test: `tests/Feature/Immobilisation/FicheTest.php`

- [ ] **Step 1 : écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\ImmobilisationShow;
use App\Models\Compte;
use App\Models\Tiers;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->immo = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );
});

it('affiche le plan d’amortissement complet sur toute la durée', function (): void {
    $composant = Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo]);

    $plan = $composant->viewData('plan');

    expect($plan)->toHaveCount(5)
        ->and($plan[0]['exercice'])->toBe(2026)
        ->and($plan[0]['dotationCentimes'])->toBe(60000)
        ->and($plan[4]['exercice'])->toBe(2030)
        ->and($plan[4]['cumulCentimes'])->toBe(300000)
        ->and($plan[4]['valeurNetteCentimes'])->toBe(0);
});

it('distingue les exercices comptabilisés des projections', function (): void {
    Carbon::setTestNow('2027-10-15');
    app(App\Services\Immobilisation\DotationService::class)->generer(2026);
    Carbon::setTestNow();

    $plan = Livewire::test(ImmobilisationShow::class, ['immobilisation' => $this->immo->fresh()])
        ->viewData('plan');

    expect($plan[0]['comptabilisee'])->toBeTrue()
        ->and($plan[1]['comptabilisee'])->toBeFalse();
});
```

- [ ] **Step 2 : lancer le test pour vérifier qu'il échoue**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/FicheTest.php --compact`
Expected : FAIL — `Class "App\Livewire\Immobilisations\ImmobilisationShow" not found`

- [ ] **Step 3 : écrire le composant**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Immobilisations;

use App\Models\Immobilisation;
use App\Services\ExerciceService;
use App\Services\Immobilisation\PlanAmortissementCalculator;
use Illuminate\View\View;
use Livewire\Component;

final class ImmobilisationShow extends Component
{
    public Immobilisation $immobilisation;

    public function mount(Immobilisation $immobilisation): void
    {
        $this->immobilisation = $immobilisation;
    }

    public function render(): View
    {
        $this->immobilisation->load(['compte', 'compteAmortissement', 'dotations.transaction', 'transaction.tiers']);

        return view('livewire.immobilisations.immobilisation-show', [
            'plan' => $this->construirePlan(),
        ])->layout('layouts.app-sidebar', ['title' => $this->immobilisation->numero]);
    }

    /**
     * Plan complet, de l'exercice de mise en service jusqu'au solde du bien.
     *
     * Les exercices déjà comptabilisés portent le montant réellement écrit ;
     * les suivants sont des projections calculées à la volée — rien n'est stocké,
     * donc rien ne peut devenir périmé.
     *
     * @return list<array{exercice: int, moisEcoules: int, dotationCentimes: int, cumulCentimes: int, valeurNetteCentimes: int, comptabilisee: bool}>
     */
    private function construirePlan(): array
    {
        $calculator = app(PlanAmortissementCalculator::class);
        $exerciceService = app(ExerciceService::class);

        $exerciceDebut = $exerciceService->anneeForDate(
            \Carbon\CarbonImmutable::parse($this->immobilisation->date_mise_en_service->toDateString())
        );

        $montantCentimes = $this->immobilisation->montantAcquisitionCentimes();
        $plan = [];
        $cumul = 0;
        $exercice = $exerciceDebut;

        // Borne dure : la durée en mois ne peut pas s'étaler sur plus d'exercices
        // que (durée / 12) + 2, ce qui protège d'une boucle infinie si le calcul
        // venait à stagner.
        $borne = intdiv((int) $this->immobilisation->duree_mois, 12) + 2;

        for ($i = 0; $i < $borne && $cumul < $montantCentimes; $i++) {
            $dotationRecalculee = $calculator->dotationCentimes($this->immobilisation, $exercice, $cumul);

            $dotationEnregistree = $this->immobilisation->dotations->firstWhere('exercice', $exercice);
            $comptabilisee = $dotationEnregistree !== null;

            $dotation = $comptabilisee
                ? (int) round(((float) $dotationEnregistree->montant) * 100)
                : $dotationRecalculee;

            $cumul += $dotation;

            $plan[] = [
                'exercice' => $exercice,
                'moisEcoules' => $calculator->moisEcoules($this->immobilisation, $exercice),
                'dotationCentimes' => $dotation,
                'cumulCentimes' => $cumul,
                'valeurNetteCentimes' => $montantCentimes - $cumul,
                'comptabilisee' => $comptabilisee,
            ];

            $exercice++;
        }

        return $plan;
    }
}
```

- [ ] **Step 4 : écrire la vue**

```blade
@php
    $euros = fn (int $centimes): string => number_format($centimes / 100, 2, ',', ' ');
    $exerciceService = app(App\Services\ExerciceService::class);
@endphp

<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <a href="{{ route('immobilisations.index') }}" class="text-decoration-none small">
                <i class="bi bi-arrow-left"></i> Livre des immobilisations
            </a>
            <h4 class="mb-0 mt-1">{{ $immobilisation->numero }} — {{ $immobilisation->libelle }}</h4>
        </div>
        <a href="{{ route('immobilisations.pdf', $immobilisation) }}" class="btn btn-outline-secondary btn-sm" target="_blank">
            <i class="bi bi-file-earmark-pdf me-1"></i> Imprimer la fiche
        </a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Identité</h6></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-5">Quantité</dt><dd class="col-7">{{ $immobilisation->quantite }}</dd>
                        <dt class="col-5">Compte</dt>
                        <dd class="col-7">{{ $immobilisation->compte->numero_pcg }} — {{ $immobilisation->compte->intitule }}</dd>
                        <dt class="col-5">Amortissements</dt>
                        <dd class="col-7">{{ $immobilisation->compteAmortissement->numero_pcg }} — {{ $immobilisation->compteAmortissement->intitule }}</dd>
                        <dt class="col-5">Mise en service</dt>
                        <dd class="col-7">
                            {{ $immobilisation->date_mise_en_service->format('d/m/Y') }}
                            @unless ($immobilisation->estEnService())
                                <span class="badge bg-warning text-dark">Pas encore en service</span>
                            @endunless
                        </dd>
                        <dt class="col-5">Durée</dt><dd class="col-7">{{ $immobilisation->duree_label }}</dd>
                        <dt class="col-5">Valeur brute</dt>
                        <dd class="col-7">{{ $euros($immobilisation->montantAcquisitionCentimes()) }} €</dd>
                        <dt class="col-5">Valeur nette</dt>
                        <dd class="col-7 fw-semibold">{{ $euros($immobilisation->valeurNetteCentimes()) }} €</dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">Acquisition</h6></div>
                <div class="card-body">
                    @foreach ($immobilisation->transactionsAcquisition() as $tx)
                        <dl class="row mb-0">
                            <dt class="col-5">Date</dt><dd class="col-7">{{ $tx->date->format('d/m/Y') }}</dd>
                            <dt class="col-5">Fournisseur</dt><dd class="col-7">{{ $tx->tiers?->nom_complet ?? '—' }}</dd>
                            <dt class="col-5">Pièce</dt><dd class="col-7">{{ $tx->numero_piece ?? '—' }}</dd>
                            <dt class="col-5">Montant</dt><dd class="col-7">{{ number_format((float) $tx->montant_total, 2, ',', ' ') }} €</dd>
                        </dl>
                    @endforeach
                    @if ($immobilisation->notes)
                        <hr><p class="mb-0 small text-muted">{{ $immobilisation->notes }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h6 class="mb-0">Plan d'amortissement</h6></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Exercice</th>
                        <th class="text-end">Mois</th>
                        <th class="text-end">Dotation</th>
                        <th class="text-end">Cumul</th>
                        <th class="text-end">Valeur nette</th>
                        <th>État</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($plan as $ligne)
                        <tr class="{{ $ligne['comptabilisee'] ? '' : 'text-muted fst-italic' }}">
                            <td>{{ $exerciceService->label($ligne['exercice']) }}</td>
                            <td class="text-end">{{ $ligne['moisEcoules'] }}</td>
                            <td class="text-end">{{ $euros($ligne['dotationCentimes']) }} €</td>
                            <td class="text-end">{{ $euros($ligne['cumulCentimes']) }} €</td>
                            <td class="text-end">{{ $euros($ligne['valeurNetteCentimes']) }} €</td>
                            <td>
                                @if ($ligne['comptabilisee'])
                                    <span class="badge bg-success">Comptabilisée</span>
                                @else
                                    <span class="badge bg-light text-dark border">Prévisionnel</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
```

- [ ] **Step 5 : ajouter la route et rétablir le lien du livre**

Dans `routes/web.php`, ajouter dans le groupe Immobilisations (**après** `immobilisations.index`, et en gardant à l'esprit que la route `/dotations` de la tâche 12 devra être déclarée avant celle-ci) :

```php
        Route::get('/comptabilite/immobilisations/{immobilisation}', ImmobilisationShow::class)
            ->name('immobilisations.show');
```

Import : `use App\Livewire\Immobilisations\ImmobilisationShow;`

Dans `immobilisation-index.blade.php`, rétablir le lien sur le numéro :

```blade
                            <td data-sort="{{ $immo->numero }}">
                                <a href="{{ route('immobilisations.show', $immo) }}">{{ $immo->numero }}</a>
                            </td>
```

- [ ] **Step 6 : lancer les tests pour vérifier qu'ils passent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation --compact`
Expected : PASS (tous les tests des tâches 6, 8, 9, 10)

Note : le lien `immobilisations.pdf` dans la vue échouera tant que la tâche 11 n'est pas faite. Le remplacer temporairement par `#` et le rétablir en tâche 11, ou enchaîner directement sur la tâche 11 avant de lancer les tests de vue.

- [ ] **Step 7 : formater et committer**

```bash
./vendor/bin/pint app/Livewire/Immobilisations routes tests/Feature/Immobilisation
git add app/Livewire/Immobilisations/ImmobilisationShow.php resources/views/livewire/immobilisations/immobilisation-show.blade.php resources/views/livewire/immobilisations/immobilisation-index.blade.php routes/web.php tests/Feature/Immobilisation/FicheTest.php
git commit -m "feat(immos): fiche et plan d'amortissement"
```

---

## Task 11 : export PDF de la fiche

**Files:**
- Create: `app/Http/Controllers/ImmobilisationPdfController.php`
- Create: `resources/views/pdf/immobilisation.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Immobilisation/PdfTest.php`

- [ ] **Step 1 : écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Tiers;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;
use Tests\Support\TenantTestCase;

uses(TenantTestCase::class);

it('produit un PDF de la fiche', function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $immo = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    $this->actingAsAdmin()
        ->get(route('immobilisations.pdf', $immo))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
```

- [ ] **Step 2 : lancer le test pour vérifier qu'il échoue**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/PdfTest.php --compact`
Expected : FAIL — route `immobilisations.pdf` non définie

- [ ] **Step 3 : écrire le contrôleur**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Immobilisation;
use App\Services\ExerciceService;
use App\Services\Immobilisation\PlanAmortissementCalculator;
use App\Support\CurrentAssociation;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

final class ImmobilisationPdfController extends Controller
{
    public function __invoke(Immobilisation $immobilisation): Response
    {
        $immobilisation->load(['compte', 'compteAmortissement', 'dotations', 'transaction.tiers']);

        $pdf = Pdf::loadView('pdf.immobilisation', [
            'immobilisation' => $immobilisation,
            'association' => CurrentAssociation::get(),
            'plan' => $this->plan($immobilisation),
            'exerciceService' => app(ExerciceService::class),
        ])->setPaper('a4', 'portrait');

        return $pdf->stream('immobilisation-'.$immobilisation->numero.'.pdf');
    }

    /**
     * Même construction que ImmobilisationShow — le PDF est un rendu figé de la
     * fiche, il ne doit pas diverger de l'écran.
     *
     * @return list<array{exercice: int, moisEcoules: int, dotationCentimes: int, cumulCentimes: int, valeurNetteCentimes: int, comptabilisee: bool}>
     */
    private function plan(Immobilisation $immobilisation): array
    {
        $calculator = app(PlanAmortissementCalculator::class);
        $exerciceService = app(ExerciceService::class);

        $exercice = $exerciceService->anneeForDate(
            CarbonImmutable::parse($immobilisation->date_mise_en_service->toDateString())
        );

        $montantCentimes = $immobilisation->montantAcquisitionCentimes();
        $plan = [];
        $cumul = 0;
        $borne = intdiv((int) $immobilisation->duree_mois, 12) + 2;

        for ($i = 0; $i < $borne && $cumul < $montantCentimes; $i++) {
            $enregistree = $immobilisation->dotations->firstWhere('exercice', $exercice);
            $comptabilisee = $enregistree !== null;

            $dotation = $comptabilisee
                ? (int) round(((float) $enregistree->montant) * 100)
                : $calculator->dotationCentimes($immobilisation, $exercice, $cumul);

            $cumul += $dotation;

            $plan[] = [
                'exercice' => $exercice,
                'moisEcoules' => $calculator->moisEcoules($immobilisation, $exercice),
                'dotationCentimes' => $dotation,
                'cumulCentimes' => $cumul,
                'valeurNetteCentimes' => $montantCentimes - $cumul,
                'comptabilisee' => $comptabilisee,
            ];

            $exercice++;
        }

        return $plan;
    }
}
```

- [ ] **Step 4 : écrire le gabarit PDF**

`resources/views/pdf/immobilisation.blade.php` :

```blade
@php
    $euros = fn (int $centimes): string => number_format($centimes / 100, 2, ',', ' ');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #212529; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .sub { color: #6c757d; font-size: 10px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #3d5473; color: #fff; text-align: left; padding: 5px 6px; font-size: 10px; }
        td { padding: 4px 6px; border-bottom: 1px solid #dee2e6; }
        .num { text-align: right; }
        .prev { color: #6c757d; font-style: italic; }
        dl { margin: 0; }
        dt { float: left; clear: left; width: 130px; font-weight: bold; }
        dd { margin-left: 140px; margin-bottom: 3px; }
    </style>
</head>
<body>
    <h1>{{ $immobilisation->numero }} — {{ $immobilisation->libelle }}</h1>
    <div class="sub">{{ $association?->nom }} — fiche d'immobilisation éditée le {{ now()->format('d/m/Y') }}</div>

    <dl>
        <dt>Quantité</dt><dd>{{ $immobilisation->quantite }}</dd>
        <dt>Compte</dt><dd>{{ $immobilisation->compte->numero_pcg }} — {{ $immobilisation->compte->intitule }}</dd>
        <dt>Amortissements</dt><dd>{{ $immobilisation->compteAmortissement->numero_pcg }} — {{ $immobilisation->compteAmortissement->intitule }}</dd>
        <dt>Mise en service</dt><dd>{{ $immobilisation->date_mise_en_service->format('d/m/Y') }}</dd>
        <dt>Durée</dt><dd>{{ $immobilisation->duree_label }}</dd>
        <dt>Valeur brute</dt><dd>{{ $euros($immobilisation->montantAcquisitionCentimes()) }} €</dd>
        <dt>Valeur nette</dt><dd>{{ $euros($immobilisation->valeurNetteCentimes()) }} €</dd>
        @foreach ($immobilisation->transactionsAcquisition() as $tx)
            <dt>Acquisition</dt>
            <dd>{{ $tx->date->format('d/m/Y') }} — {{ $tx->tiers?->nom_complet ?? '—' }} — pièce {{ $tx->numero_piece ?? '—' }}</dd>
        @endforeach
    </dl>

    <table>
        <thead>
            <tr>
                <th>Exercice</th>
                <th class="num">Mois</th>
                <th class="num">Dotation</th>
                <th class="num">Cumul</th>
                <th class="num">Valeur nette</th>
                <th>État</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($plan as $ligne)
                <tr class="{{ $ligne['comptabilisee'] ? '' : 'prev' }}">
                    <td>{{ $exerciceService->label($ligne['exercice']) }}</td>
                    <td class="num">{{ $ligne['moisEcoules'] }}</td>
                    <td class="num">{{ $euros($ligne['dotationCentimes']) }} €</td>
                    <td class="num">{{ $euros($ligne['cumulCentimes']) }} €</td>
                    <td class="num">{{ $euros($ligne['valeurNetteCentimes']) }} €</td>
                    <td>{{ $ligne['comptabilisee'] ? 'Comptabilisée' : 'Prévisionnel' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
```

- [ ] **Step 5 : ajouter la route**

```php
        Route::get('/comptabilite/immobilisations/{immobilisation}/pdf', ImmobilisationPdfController::class)
            ->name('immobilisations.pdf');
```

Import : `use App\Http\Controllers\ImmobilisationPdfController;`

Rétablir dans `immobilisation-show.blade.php` le lien `route('immobilisations.pdf', $immobilisation)` s'il avait été remplacé par `#` en tâche 10.

- [ ] **Step 6 : lancer le test pour vérifier qu'il passe**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/PdfTest.php --compact`
Expected : PASS (1 test)

- [ ] **Step 7 : formater et committer**

```bash
./vendor/bin/pint app/Http/Controllers routes tests/Feature/Immobilisation
git add app/Http/Controllers/ImmobilisationPdfController.php resources/views/pdf/immobilisation.blade.php resources/views/livewire/immobilisations/immobilisation-show.blade.php routes/web.php tests/Feature/Immobilisation/PdfTest.php
git commit -m "feat(immos): export PDF imprimable de la fiche"
```

---

## Task 12 : écran des dotations de l'exercice

**Files:**
- Create: `app/Livewire/Immobilisations/DotationsExercice.php`
- Create: `resources/views/livewire/immobilisations/dotations-exercice.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/livewire/immobilisations/immobilisation-index.blade.php` (rétablir le bouton)
- Test: `tests/Feature/Immobilisation/DotationsEcranTest.php`

- [ ] **Step 1 : écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\DotationsExercice;
use App\Models\Compte;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->immo = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );
});

it('affiche l’aperçu et génère les dotations', function (): void {
    Carbon::setTestNow('2027-10-15');

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->assertSee('IM00001')
        ->assertSee('600,00')
        ->call('genererTout')
        ->assertHasNoErrors();

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(1);

    Carbon::setTestNow();
});

it('bloque la génération sur un exercice non terminé', function (): void {
    Carbon::setTestNow('2027-03-01');

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->call('genererTout');

    expect(ImmobilisationDotation::count())->toBe(0);

    Carbon::setTestNow();
});

it('signale un écart et le recalcule', function (): void {
    Carbon::setTestNow('2027-10-15');

    Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');

    $this->immo->update(['duree_mois' => 30]);

    Livewire::test(DotationsExercice::class)
        ->set('exercice', 2026)
        ->assertSee('Écart')
        ->call('recalculer', (int) $this->immo->id)
        ->assertHasNoErrors();

    expect(ImmobilisationDotation::where('exercice', 2026)->firstOrFail()->montant)->toEqual('1200.00');

    Carbon::setTestNow();
});
```

- [ ] **Step 2 : lancer le test pour vérifier qu'il échoue**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/DotationsEcranTest.php --compact`
Expected : FAIL — `Class "App\Livewire\Immobilisations\DotationsExercice" not found`

- [ ] **Step 3 : écrire le composant**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Immobilisations;

use App\Exceptions\Immobilisation\DotationInterditeException;
use App\Models\Immobilisation;
use App\Services\ExerciceService;
use App\Services\Immobilisation\DotationService;
use Illuminate\View\View;
use Livewire\Component;

final class DotationsExercice extends Component
{
    public int $exercice = 0;

    public string $flashMessage = '';

    public string $flashType = '';

    public function mount(): void
    {
        // Par défaut, l'exercice précédent : c'est celui qu'on clôture.
        $this->exercice = app(ExerciceService::class)->current() - 1;
    }

    public function render(): View
    {
        return view('livewire.immobilisations.dotations-exercice', [
            'lignes' => app(DotationService::class)->apercu($this->exercice),
            'exerciceService' => app(ExerciceService::class),
            'exercicesDisponibles' => app(ExerciceService::class)->availableYears(),
        ])->layout('layouts.app-sidebar', ['title' => 'Dotations aux amortissements']);
    }

    public function genererTout(): void
    {
        try {
            $nombre = app(DotationService::class)->generer($this->exercice);
        } catch (DotationInterditeException $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType = 'warning';

            return;
        }

        $this->flashMessage = $nombre === 0
            ? 'Aucune dotation à générer pour cet exercice.'
            : $nombre.' dotation'.($nombre > 1 ? 's générées' : ' générée').'. Pensez à les ventiler avant de clôturer.';
        $this->flashType = 'success';
    }

    public function recalculer(int $immobilisationId): void
    {
        $immobilisation = Immobilisation::findOrFail($immobilisationId);

        try {
            app(DotationService::class)->recalculer($immobilisation, $this->exercice);
        } catch (DotationInterditeException $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType = 'warning';

            return;
        }

        $this->flashMessage = 'Dotation recalculée pour '.$immobilisation->numero
            .'. Si elle avait été ventilée, la ventilation est à refaire.';
        $this->flashType = 'success';
    }
}
```

- [ ] **Step 4 : écrire la vue**

Le `wire:confirm` du bouton « Recalculer » porte l'avertissement sur la perte de ventilation, conformément à la spec § 7.4.

```blade
@php
    $euros = fn (int $centimes): string => number_format($centimes / 100, 2, ',', ' ');
@endphp

<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Dotations aux amortissements</h4>
        <div class="d-flex gap-2 align-items-center">
            <select class="form-select form-select-sm" wire:model.live="exercice" style="width:auto">
                @foreach ($exercicesDisponibles as $annee)
                    <option value="{{ $annee }}">{{ $exerciceService->label($annee) }}</option>
                @endforeach
            </select>
            <button type="button" class="btn btn-primary btn-sm" wire:click="genererTout">
                <i class="bi bi-play-fill me-1"></i> Générer les dotations manquantes
            </button>
        </div>
    </div>

    @if ($flashMessage !== '')
        <div class="alert alert-{{ $flashType }} alert-dismissible fade show">
            {{ $flashMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if ($lignes->isEmpty())
        <div class="alert alert-info">Aucune immobilisation au registre.</div>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Numéro</th>
                        <th>Libellé</th>
                        <th class="text-end">Mois</th>
                        <th class="text-end">Comptabilisé</th>
                        <th class="text-end">Recalculé</th>
                        <th class="text-end">Écart</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lignes as $ligne)
                        @php
                            $ecart = $ligne->montantRecalculeCentimes - $ligne->montantComptabiliseCentimes;
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('immobilisations.show', $ligne->immobilisation) }}">
                                    {{ $ligne->immobilisation->numero }}
                                </a>
                            </td>
                            <td>{{ $ligne->immobilisation->libelle }}</td>
                            <td class="text-end">{{ $ligne->moisEcoules }}</td>
                            <td class="text-end">
                                {{ $ligne->dejaComptabilisee ? $euros($ligne->montantComptabiliseCentimes).' €' : '—' }}
                            </td>
                            <td class="text-end">{{ $euros($ligne->montantRecalculeCentimes) }} €</td>
                            <td class="text-end {{ $ligne->enEcart() ? 'text-danger fw-semibold' : '' }}">
                                @if ($ligne->enEcart())
                                    Écart {{ $euros($ecart) }} €
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if ($ligne->enEcart())
                                    <button type="button" class="btn btn-warning btn-sm"
                                            wire:click="recalculer({{ $ligne->immobilisation->id }})"
                                            wire:confirm="La dotation actuelle sera remplacée. Si elle avait été ventilée sur des opérations, cette ventilation sera perdue et devra être refaite. Continuer ?">
                                        Recalculer
                                    </button>
                                @elseif ($ligne->aGenerer())
                                    <span class="badge bg-primary">À générer</span>
                                @elseif ($ligne->dejaComptabilisee)
                                    <span class="badge bg-success">À jour</span>
                                @else
                                    <span class="text-muted small">Rien à doter</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
```

- [ ] **Step 5 : ajouter la route et rétablir le bouton du livre**

Dans `routes/web.php`, ajouter la route **avant** `immobilisations.show`, sinon « dotations » serait capté comme paramètre `{immobilisation}` :

```php
        Route::get('/comptabilite/immobilisations/dotations', DotationsExercice::class)
            ->name('immobilisations.dotations');
```

Import : `use App\Livewire\Immobilisations\DotationsExercice;`

Dans `immobilisation-index.blade.php`, rétablir le bouton dans l'en-tête :

```blade
        <a href="{{ route('immobilisations.dotations') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-calculator me-1"></i> Dotations de l'exercice
        </a>
```

- [ ] **Step 6 : lancer les tests pour vérifier qu'ils passent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation --compact`
Expected : PASS (toutes les tâches précédentes incluses)

- [ ] **Step 7 : formater et committer**

```bash
./vendor/bin/pint app/Livewire/Immobilisations routes tests/Feature/Immobilisation
git add app/Livewire/Immobilisations/DotationsExercice.php resources/views/livewire/immobilisations/dotations-exercice.blade.php resources/views/livewire/immobilisations/immobilisation-index.blade.php routes/web.php tests/Feature/Immobilisation/DotationsEcranTest.php
git commit -m "feat(immos): écran de génération et de recalcul des dotations"
```

---

## Task 13 : verrouillage du formulaire de transaction et affichage dans la liste

**Files:**
- Modify: `app/Livewire/TransactionForm.php`
- Modify: `resources/views/livewire/transaction-form.blade.php`
- Modify: `app/Livewire/TransactionUniverselle.php`
- Modify: `resources/views/livewire/transaction-universelle.blade.php`
- Test: `tests/Feature/Immobilisation/VerrouTransactionTest.php`

**Contexte** : `TransactionLigne::scopeVentilation` retient les classes 6 et 7. Une ligne de classe 2 n'en fait donc pas partie : la transaction d'acquisition apparaît **sans ligne** dans le formulaire et dans la liste. On ne modifie **pas** ce scope — l'élargir rendrait la ligne d'acquisition modifiable depuis le formulaire générique et ferait entrer les actifs dans la matrice analytique. On verrouille à la place.

- [ ] **Step 1 : écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

use App\Livewire\TransactionForm;
use App\Models\Compte;
use App\Models\Tiers;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->immo = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );
});

it('verrouille le formulaire d’une transaction d’acquisition', function (): void {
    Livewire::test(TransactionForm::class)
        ->call('edit', (int) $this->immo->transaction_id)
        ->assertSet('isLockedByImmobilisation', true)
        ->assertSee('provient de l’immobilisation');
});

it('ne verrouille pas une transaction ordinaire', function (): void {
    $compte6 = Compte::factory()->create(['numero_pcg' => '606', 'classe' => 6]);
    $tx = App\Models\Transaction::factory()->create();
    App\Models\TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte6->id,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('edit', (int) $tx->id)
        ->assertSet('isLockedByImmobilisation', false);
});
```

- [ ] **Step 2 : lancer le test pour vérifier qu'il échoue**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/VerrouTransactionTest.php --compact`
Expected : FAIL — propriété `isLockedByImmobilisation` inexistante

- [ ] **Step 3 : ajouter le verrou au composant**

Dans `app/Livewire/TransactionForm.php`, à côté des propriétés `isLockedByFacture` / `isLockedByHelloAsso` :

```php
    /** Transaction issue d'une acquisition d'immobilisation — la fiche est le maître. */
    public bool $isLockedByImmobilisation = false;

    public ?int $immobilisationId = null;

    public string $immobilisationLibelle = '';
```

Ajouter ces trois propriétés à la liste `$this->reset([...])` déjà présente (ligne ~537), aux côtés de `isLockedByFacture`.

Dans la méthode `edit()`, après le chargement de la transaction (vers la ligne 478) :

```php
        $immobilisation = Immobilisation::where('transaction_id', (int) $transaction->id)->first();
        $this->isLockedByImmobilisation = $immobilisation !== null;
        $this->immobilisationId = $immobilisation === null ? null : (int) $immobilisation->id;
        $this->immobilisationLibelle = $immobilisation === null
            ? ''
            : $immobilisation->numero.' — '.$immobilisation->libelle;
```

Import : `use App\Models\Immobilisation;`

Dans la méthode de sauvegarde, refuser toute écriture sur une transaction verrouillée. Ajouter en tout début de `save()` (ou de la méthode qui persiste, selon le nom exact dans le fichier) :

```php
        if ($this->isLockedByImmobilisation) {
            $this->addError('lignes', 'Cette transaction est pilotée par une fiche d’immobilisation : modifiez la fiche.');

            return;
        }
```

- [ ] **Step 4 : afficher le bandeau dans la vue**

Dans `resources/views/livewire/transaction-form.blade.php`, à côté du bandeau HelloAsso existant (vers la ligne 130) :

```blade
    @if ($isLockedByImmobilisation)
        <div class="alert alert-info d-flex align-items-center gap-2">
            <i class="bi bi-box-seam"></i>
            <div>
                Cette transaction provient de l’immobilisation
                <strong>{{ $immobilisationLibelle }}</strong> — les écritures suivent la fiche.
                <a href="{{ route('immobilisations.show', $immobilisationId) }}">Ouvrir la fiche</a>
            </div>
        </div>
    @endif
```

- [ ] **Step 5 : afficher le compte et le badge dans la liste universelle**

Dans `app/Livewire/TransactionUniverselle.php`, la liste charge `lignes` filtrées par `ventilation()` (ligne ~303) — une acquisition n'en a aucune. Charger en plus les fiches concernées :

```php
        // Transactions d'acquisition d'immobilisation : leur unique ligne métier
        // est en classe 2, donc hors du scope ventilation(). On les résout à part
        // pour afficher le compte et un badge, sans toucher au scope.
        $immobilisationsParTransaction = Immobilisation::query()
            ->with('compte')
            ->whereIn('transaction_id', $transactions->pluck('id')->all())
            ->get()
            ->keyBy(fn (Immobilisation $i): int => (int) $i->transaction_id);
```

Passer `$immobilisationsParTransaction` à la vue. Import : `use App\Models\Immobilisation;`

Dans `resources/views/livewire/transaction-universelle.blade.php`, ligne ~580, remplacer :

```blade
                        {{ $tx->compte_ventilation_nom ?? '' }}
```

par :

```blade
                        @php $immoTx = $immobilisationsParTransaction[(int) $tx->id] ?? null; @endphp
                        @if ($immoTx !== null)
                            {{ $immoTx->compte->numero_pcg }} — {{ $immoTx->compte->intitule }}
                            <span class="badge bg-info text-dark ms-1">Immobilisation</span>
                        @else
                            {{ $tx->compte_ventilation_nom ?? '' }}
                        @endif
```

- [ ] **Step 6 : lancer les tests pour vérifier qu'ils passent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/VerrouTransactionTest.php --compact`
Expected : PASS (2 tests)

- [ ] **Step 7 : vérifier la non-régression du formulaire et de la liste**

Run : `php -d memory_limit=1G ./vendor/bin/pest --filter="TransactionForm|TransactionUniverselle" --compact`
Expected : PASS

- [ ] **Step 8 : formater et committer**

```bash
./vendor/bin/pint app/Livewire tests/Feature/Immobilisation
git add app/Livewire/TransactionForm.php app/Livewire/TransactionUniverselle.php resources/views/livewire/transaction-form.blade.php resources/views/livewire/transaction-universelle.blade.php tests/Feature/Immobilisation/VerrouTransactionTest.php
git commit -m "feat(immos): verrouille l'acquisition dans le formulaire et l'affiche dans la liste"
```

---

## Task 14 : contrôle de clôture

**Files:**
- Modify: `app/Services/ClotureCheckService.php`
- Test: `tests/Feature/Immobilisation/ClotureCheckTest.php`

- [ ] **Step 1 : écrire le test qui échoue**

```php
<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Tiers;
use App\Services\ClotureCheckService;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );
});

it('avertit quand des dotations ne sont pas générées', function (): void {
    Carbon::setTestNow('2027-10-15');

    $resultat = app(ClotureCheckService::class)->executer(2026);
    $item = collect($resultat->avertissements)->firstWhere('nom', 'Dotations aux amortissements');

    expect($item)->not->toBeNull()
        ->and($item->ok)->toBeFalse()
        ->and($item->message)->toContain('1');

    Carbon::setTestNow();
});

it('passe au vert une fois les dotations générées', function (): void {
    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);

    $resultat = app(ClotureCheckService::class)->executer(2026);
    $item = collect($resultat->avertissements)->firstWhere('nom', 'Dotations aux amortissements');

    expect($item->ok)->toBeTrue();

    Carbon::setTestNow();
});
```

- [ ] **Step 2 : lancer le test pour vérifier qu'il échoue**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/ClotureCheckTest.php --compact`
Expected : FAIL — l'item « Dotations aux amortissements » est `null`

- [ ] **Step 3 : ajouter le contrôle**

Dans `app/Services/ClotureCheckService.php`, ajouter l'appel dans le tableau `avertissements` de `executer()` (**pas** dans `bloquants` : le contrôle est informatif) :

```php
            avertissements: [
                $this->checkTransactionsNonPointees($start, $end),
                $this->checkBudgetAbsent($annee),
                $this->checkMouvementsExerciceCible($annee),
                $this->checkDotationsAmortissements($annee),
            ],
```

Puis la méthode :

```php
    /**
     * Des dotations aux amortissements restent-elles à générer ?
     *
     * Avertissement et non bloquant : une association sans immobilisation n'a
     * rien à doter, et le trésorier reste maître de l'ordre de ses opérations.
     * Le contrôle est l'endroit naturel où se rappeler que les dotations doivent
     * être générées — puis ventilées — avant de clôturer.
     */
    private function checkDotationsAmortissements(int $annee): CheckItem
    {
        $lignes = app(DotationService::class)->apercu($annee);
        $aGenerer = $lignes->filter(fn ($ligne): bool => $ligne->aGenerer())->count();
        $enEcart = $lignes->filter(fn ($ligne): bool => $ligne->enEcart())->count();

        if ($aGenerer === 0 && $enEcart === 0) {
            return new CheckItem(
                nom: 'Dotations aux amortissements',
                ok: true,
                message: $lignes->isEmpty()
                    ? 'Aucune immobilisation au registre'
                    : 'Les dotations de l’exercice sont à jour',
            );
        }

        $parts = [];
        if ($aGenerer > 0) {
            $parts[] = $aGenerer.' dotation'.($aGenerer > 1 ? 's' : '').' à générer';
        }
        if ($enEcart > 0) {
            $parts[] = $enEcart.' dotation'.($enEcart > 1 ? 's' : '').' à recalculer';
        }

        return new CheckItem(
            nom: 'Dotations aux amortissements',
            ok: false,
            message: implode(', ', $parts).'. Générez-les puis ventilez-les avant de clôturer : '
                .'la ventilation n’est plus possible sur un exercice clôturé.',
        );
    }
```

Import : `use App\Services\Immobilisation\DotationService;`

- [ ] **Step 4 : lancer les tests pour vérifier qu'ils passent**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation/ClotureCheckTest.php --compact`
Expected : PASS (2 tests)

- [ ] **Step 5 : vérifier la non-régression de la clôture**

Run : `php -d memory_limit=1G ./vendor/bin/pest --filter="Cloture" --compact`
Expected : PASS

- [ ] **Step 6 : formater et committer**

```bash
./vendor/bin/pint app/Services tests/Feature/Immobilisation
git add app/Services/ClotureCheckService.php tests/Feature/Immobilisation/ClotureCheckTest.php
git commit -m "feat(immos): avertissement de clôture sur les dotations non générées"
```

---

## Task 15 : parcours bout-en-bout et cloisonnement tenant

Cette tâche ne crée aucune fonctionnalité : elle prouve que l'ensemble tient, notamment sur les points où le module touche l'existant sans le modifier.

**Files:**
- Create: `tests/Feature/Immobilisation/ParcoursCompletTest.php`
- Create: `tests/Feature/Immobilisation/CloisonnementTenantTest.php`

- [ ] **Step 1 : écrire le test de parcours complet**

```php
<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Tiers;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Services\Rapports\CompteResultatBuilder;
use Carbon\Carbon;

beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();
});

it('exclut l’acquisition du compte de résultat et y intègre la dotation', function (): void {
    app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);
    Carbon::setTestNow();

    $resultat = app(CompteResultatBuilder::class)->compteDeResultat(2026);

    $json = json_encode($resultat);

    // L'acquisition (classe 2) n'apparaît pas : le compte de résultat lit les
    // classes 6 et 7, la classe 2 en est exclue mécaniquement.
    expect($json)->not->toContain('2188');
    // La dotation (classe 6) y figure, pour 600,00 €.
    expect($json)->toContain('6811')
        ->and($json)->toContain('600');
});

it('reporte les soldes 21X et 281X à l’à-nouveau', function (): void {
    app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);
    Carbon::setTestNow();

    // ANouveauPreview est un readonly avec une propriété publique `lignes`,
    // chaque ligne étant un array portant la clé `numero_pcg`.
    $preview = app(ANouveauPreviewBuilder::class)->build(2026);
    $numeros = collect($preview->lignes)->pluck('numero_pcg');

    expect($numeros)->toContain('2188')
        ->and($numeros)->toContain('28188');
});
```

**Intention à prouver**, si une assertion devait être ajustée : (1) le compte 2188 n'apparaît nulle part dans le compte de résultat, (2) le compte 6811 y figure pour 600,00 €, (3) l'aperçu d'à-nouveau contient une ligne 2188 débitrice de 3 000 € et une ligne 28188 créditrice de 600 €. Adapter l'assertion, **jamais** le code de production : `CompteResultatBuilder` et `ANouveauPreviewBuilder` ne doivent pas être modifiés par ce lot.

- [ ] **Step 2 : écrire le test de cloisonnement**

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;

it('cloisonne fiches et dotations par tenant', function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Tenues tenant A',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );

    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);
    Carbon::setTestNow();

    expect(Immobilisation::count())->toBe(1)
        ->and(ImmobilisationDotation::count())->toBe(1);

    // Bascule sur un autre tenant : plus rien ne doit être visible.
    TenantContext::boot(Association::factory()->create());

    expect(Immobilisation::count())->toBe(0)
        ->and(ImmobilisationDotation::count())->toBe(0)
        ->and(app(DotationService::class)->apercu(2026))->toHaveCount(0);
});
```

- [ ] **Step 3 : lancer les tests**

Run : `php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Immobilisation --compact`
Expected : PASS

Si les assertions sur `CompteResultatBuilder` ou `ANouveauPreviewBuilder` échouent pour cause d'API différente, corriger les assertions — **pas** le code de production : ces deux briques ne doivent pas être modifiées par ce lot.

- [ ] **Step 4 : lancer la suite complète**

Run : `php -d memory_limit=1G ./vendor/bin/pest --compact`
Expected : zéro `failed`. Les milliers de lignes `deprecated` sont normales en local (PHP 8.5) et ne sont pas des échecs.

- [ ] **Step 5 : formater et committer**

```bash
./vendor/bin/pint tests/Feature/Immobilisation
git add tests/Feature/Immobilisation/ParcoursCompletTest.php tests/Feature/Immobilisation/CloisonnementTenantTest.php
git commit -m "test(immos): parcours bout-en-bout et cloisonnement tenant"
```

---

## Auto-revue du plan

**Couverture de la spec** — chaque section a sa tâche :

| Section de la spec | Tâche |
|---|---|
| § 4.1 `immobilisations`, `transactionsAcquisition()`, `duree_label` | 1 |
| § 4.1.1 contrôle des deux dates | 6 (service) et 9 (formulaire) |
| § 4.2 `immobilisation_dotations`, unicité | 1 et 7 |
| § 4.3 séquence `IM00001` | 2 |
| § 5.1 acquisition + garde classe 2 | 4 et 6 |
| § 5.2 dotation 6811 / 281X, journal Od | 7 |
| § 5.2.1 invariants de date | 7 |
| § 5.3 compte de résultat + famille 68 | 5 (famille) et 15 (preuve) |
| § 6 règle de calcul et exemples chiffrés | 3 |
| § 7.1 livre + « pas encore en service » | 8 |
| § 7.2 fiche + PDF | 10 et 11 |
| § 7.3 formulaire | 9 |
| § 7.4 dotations, écart, avertissement ventilation | 12 |
| § 8 kit de comptes, ouverture du plan comptable | 5 |
| § 9 verrou, badge, clôture | 13 et 14 |
| § 10 tests | réparti, plus 15 |

**Points de vigilance repérés à la relecture :**

1. **Ordre des routes** — `/comptabilite/immobilisations/dotations` doit être déclarée **avant** `/comptabilite/immobilisations/{immobilisation}`, faute de quoi « dotations » serait capté comme paramètre. Rappelé aux tâches 8, 10 et 12.
2. **Dépendances de vues entre tâches** — les tâches 8, 10 et 12 se référencent mutuellement par `route()`. Chaque tâche indique explicitement quoi neutraliser puis rétablir pour rester committable seule.
3. **Cohérence des signatures** — `acquerir()` est appelée avec les mêmes arguments nommés dans les tâches 6, 8, 9, 10, 11, 12, 13, 14 et 15. `apercu()`, `generer()`, `recalculer()` et `annuler()` gardent leur nom des tâches 7 à 14. `dotationCentimes($immo, $exercice, $cumul)` garde ses trois arguments partout.
4. **Pas d'`ExerciceFactory`** dans le projet — vérifié. La tâche 7 crée l'exercice avec `Exercice::create(['annee' => …, 'statut' => …])` ; `association_id` est posé automatiquement par `TenantModel`.
5. **API des rapports** — vérifiée : l'entrée du compte de résultat est `CompteResultatBuilder::compteDeResultat(int $exercice)` (il n'existe pas de `build()`), et `ANouveauPreview` est un `readonly` exposant `->lignes` en propriété publique. Les assertions de la tâche 15 sont alignées dessus.
6. **`ImmobilisationIndex` porte deux responsabilités** — le livre et la modale de création. C'est le patron déjà retenu par `ProvisionIndex`, on le suit. Si le composant devenait difficile à lire, l'extraction de la modale serait le premier découpage à faire.
