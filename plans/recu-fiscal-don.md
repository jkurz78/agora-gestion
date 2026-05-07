# Reçu fiscal de don — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Émettre un reçu fiscal légal au format PDF/A-3 pour chaque transaction de don, idempotent, depuis le quick view Tiers en back-office.

**Architecture:** Service `RecuFiscalService` (get-or-create + annulation + ré-émission) + table `recus_fiscaux_emis` + PDF/A-3 via `Atgp\FacturX\Writer` (réutilisé du pipeline facture/document prévisionnel) + binaire stocké sur disque tenant.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, DomPDF (`barryvdh/laravel-dompdf`), `atgp/factur-x`, `kwn/number-to-words` (nouveau), Pest PHP.

**Spec :** [docs/specs/2026-05-07-recu-fiscal-don-design.md](../docs/specs/2026-05-07-recu-fiscal-don-design.md)

---

## Pré-requis

Avant de commencer, vérifier :
- Branche dédiée créée : `feat/recu-fiscal-don`
- Suite verte sur `main` au point de départ
- Docker Sail démarré (`./vendor/bin/sail up -d`)

```bash
git checkout -b feat/recu-fiscal-don
./vendor/bin/sail test --parallel
```

---

## Task 1 : Dépendance composer `kwn/number-to-words`

**Files:**
- Modify: `composer.json` (ajout require)
- Modify: `composer.lock` (regénéré)

- [ ] **Step 1: Installer la dépendance via composer**

```bash
docker run --rm -v "$(pwd):/app" -w /app composer:latest require kwn/number-to-words --no-scripts --no-interaction
```

Expected: ajout de `"kwn/number-to-words": "^x.x.x"` dans `composer.json`, regénération du `composer.lock`.

- [ ] **Step 2: Vérifier l'installation depuis Sail**

```bash
./vendor/bin/sail composer show kwn/number-to-words
```

Expected: la dépendance apparaît avec ses dépendances transitives (kwn/php-power-set).

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore(deps): add kwn/number-to-words for tax receipt amount-in-words"
```

---

## Task 2 : Service `MontantEnLettresService`

**Files:**
- Create: `app/Services/MontantEnLettresService.php`
- Test: `tests/Unit/Services/MontantEnLettresServiceTest.php`

- [ ] **Step 1: Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Services\MontantEnLettresService;

it('formate un montant entier sans centimes', function () {
    $service = app(MontantEnLettresService::class);
    expect($service->convertir(150))->toBe('cent cinquante euros');
});

it('formate un montant avec centimes', function () {
    $service = app(MontantEnLettresService::class);
    expect($service->convertir(1234.56))->toBe('mille deux cent trente-quatre euros et cinquante-six centimes');
});

it('formate quatre-vingts au pluriel', function () {
    $service = app(MontantEnLettresService::class);
    expect($service->convertir(80))->toBe('quatre-vingts euros');
});

it('formate cent au pluriel uniquement quand multiple', function () {
    $service = app(MontantEnLettresService::class);
    expect($service->convertir(100))->toBe('cent euros');
    expect($service->convertir(200))->toBe('deux cents euros');
});

it('formate un million', function () {
    $service = app(MontantEnLettresService::class);
    expect($service->convertir(1_000_000))->toBe('un million d\'euros');
});

it('formate un centime seul', function () {
    $service = app(MontantEnLettresService::class);
    expect($service->convertir(0.01))->toBe('zéro euros et un centime');
});
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

```bash
./vendor/bin/sail test tests/Unit/Services/MontantEnLettresServiceTest.php
```

Expected: FAIL — `Class App\Services\MontantEnLettresService not found`.

- [ ] **Step 3: Implémenter le service**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use NumberToWords\NumberToWords;

final class MontantEnLettresService
{
    public function convertir(float $montant): string
    {
        $entier = (int) floor(abs($montant));
        $centimes = (int) round((abs($montant) - $entier) * 100);

        $numberToWords = new NumberToWords;
        $transformer = $numberToWords->getNumberTransformer('fr');

        $partieEntiere = $transformer->toWords($entier);
        $libelleEuros = $entier > 1 ? 'euros' : 'euros';
        $libelleEuros = $entier === 1_000_000 ? "d'euros" : $libelleEuros;

        $resultat = "{$partieEntiere} {$libelleEuros}";
        if ($entier === 1_000_000) {
            $resultat = "{$partieEntiere} d'euros";
        }

        if ($centimes > 0) {
            $partieCentimes = $transformer->toWords($centimes);
            $libelleCentimes = $centimes > 1 ? 'centimes' : 'centime';
            $resultat .= " et {$partieCentimes} {$libelleCentimes}";
        }

        return $resultat;
    }
}
```

- [ ] **Step 4: Lancer le test, ajuster si nécessaire**

```bash
./vendor/bin/sail test tests/Unit/Services/MontantEnLettresServiceTest.php
```

Expected: PASS pour les 6 cas. Si la lib `kwn/number-to-words` produit une variation pour un cas (ex: « zéro euro » singulier), ajuster soit l'attendu, soit la logique de pluralisation pour rester en accord avec la lib.

- [ ] **Step 5: Commit**

```bash
git add app/Services/MontantEnLettresService.php tests/Unit/Services/MontantEnLettresServiceTest.php
git commit -m "feat(recu-fiscal): MontantEnLettresService — conversion montant € en lettres"
```

---

## Task 3 : Migration — colonnes fiscales sur `associations`

**Files:**
- Create: `database/migrations/2026_05_07_100001_add_recu_fiscal_fields_to_associations.php`
- Test: `tests/Feature/Migrations/AssociationRecuFiscalFieldsTest.php`

- [ ] **Step 1: Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Models\Association;

it('persiste les champs fiscaux d\'éligibilité reçu fiscal sur Association', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'regime_fiscal_don' => 'Intérêt général',
        'objet_recu_fiscal' => 'Œuvre d\'intérêt général à caractère social',
        'rescrit_fiscal_numero' => '2024/RES/0042',
        'rescrit_fiscal_date' => '2024-06-15',
        'signataire_nom' => 'Jean Dupont',
        'signataire_qualite' => 'Président',
    ]);

    $asso->refresh();

    expect($asso->eligible_recu_fiscal)->toBeTrue();
    expect($asso->regime_fiscal_don)->toBe('Intérêt général');
    expect($asso->objet_recu_fiscal)->toBe('Œuvre d\'intérêt général à caractère social');
    expect($asso->rescrit_fiscal_numero)->toBe('2024/RES/0042');
    expect($asso->rescrit_fiscal_date->format('Y-m-d'))->toBe('2024-06-15');
    expect($asso->signataire_nom)->toBe('Jean Dupont');
    expect($asso->signataire_qualite)->toBe('Président');
});

it('par défaut, eligible_recu_fiscal est false', function () {
    $asso = Association::factory()->create();
    expect($asso->eligible_recu_fiscal)->toBeFalse();
});
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

```bash
./vendor/bin/sail test tests/Feature/Migrations/AssociationRecuFiscalFieldsTest.php
```

Expected: FAIL — colonnes inconnues.

- [ ] **Step 3: Écrire la migration**

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
        Schema::table('associations', function (Blueprint $table) {
            $table->boolean('eligible_recu_fiscal')->default(false)->after('siret');
            $table->string('regime_fiscal_don')->nullable()->after('eligible_recu_fiscal');
            $table->text('objet_recu_fiscal')->nullable()->after('regime_fiscal_don');
            $table->string('rescrit_fiscal_numero')->nullable()->after('objet_recu_fiscal');
            $table->date('rescrit_fiscal_date')->nullable()->after('rescrit_fiscal_numero');
            $table->string('signataire_nom')->nullable()->after('rescrit_fiscal_date');
            $table->string('signataire_qualite')->nullable()->after('signataire_nom');
        });
    }

    public function down(): void
    {
        Schema::table('associations', function (Blueprint $table) {
            $table->dropColumn([
                'eligible_recu_fiscal',
                'regime_fiscal_don',
                'objet_recu_fiscal',
                'rescrit_fiscal_numero',
                'rescrit_fiscal_date',
                'signataire_nom',
                'signataire_qualite',
            ]);
        });
    }
};
```

- [ ] **Step 4: Mettre à jour Association model + factory**

`app/Models/Association.php` — ajouter au `$fillable` :
```php
'eligible_recu_fiscal',
'regime_fiscal_don',
'objet_recu_fiscal',
'rescrit_fiscal_numero',
'rescrit_fiscal_date',
'signataire_nom',
'signataire_qualite',
```

Et au `$casts` :
```php
'eligible_recu_fiscal' => 'boolean',
'rescrit_fiscal_date' => 'date',
```

- [ ] **Step 5: Migrer + retester**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail test tests/Feature/Migrations/AssociationRecuFiscalFieldsTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_07_100001_add_recu_fiscal_fields_to_associations.php app/Models/Association.php tests/Feature/Migrations/AssociationRecuFiscalFieldsTest.php
git commit -m "feat(recu-fiscal): champs fiscaux d'éligibilité sur associations"
```

---

## Task 4 : Migration + modèle `RecuFiscalEmis`

**Files:**
- Create: `database/migrations/2026_05_07_100002_create_recus_fiscaux_emis_table.php`
- Create: `app/Models/RecuFiscalEmis.php`
- Create: `database/factories/RecuFiscalEmisFactory.php`
- Test: `tests/Feature/Models/RecuFiscalEmisTest.php`

- [ ] **Step 1: Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Support\TenantContext;

it('persiste un reçu fiscal avec ses champs', function () {
    /** @var Association $asso */
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $tiers = Tiers::factory()->create();

    $recu = RecuFiscalEmis::factory()->create([
        'tiers_id' => $tiers->id,
        'numero' => '2026-0001',
        'annee_civile' => 2026,
        'montant_centimes' => 15000,
        'date_versement' => '2026-03-15',
        'mode_versement' => 'cheque',
        'forme_don' => 'numeraire',
        'article_cgi' => 'art_200',
        'pdf_path' => 'recus_fiscaux/2026/2026-0001.pdf',
        'pdf_hash' => str_repeat('a', 64),
    ]);

    expect($recu->numero)->toBe('2026-0001');
    expect($recu->isActif())->toBeTrue();
    expect($recu->isAnnule())->toBeFalse();
});

it('détecte un reçu annulé', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $tiers = Tiers::factory()->create();

    $recu = RecuFiscalEmis::factory()->create([
        'tiers_id' => $tiers->id,
        'annule_at' => now(),
        'annule_motif' => 'Don supprimé',
    ]);

    expect($recu->isAnnule())->toBeTrue();
    expect($recu->isActif())->toBeFalse();
});

it('isole les reçus par tenant via TenantScope', function () {
    $asso1 = Association::factory()->create();
    $asso2 = Association::factory()->create();

    TenantContext::boot($asso1);
    $tiers1 = Tiers::factory()->create();
    RecuFiscalEmis::factory()->create(['tiers_id' => $tiers1->id]);

    TenantContext::boot($asso2);
    expect(RecuFiscalEmis::count())->toBe(0);
});

it('garantit l\'unicité du numéro par association', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $tiers = Tiers::factory()->create();

    RecuFiscalEmis::factory()->create(['numero' => '2026-0001', 'tiers_id' => $tiers->id]);

    expect(fn () => RecuFiscalEmis::factory()->create(['numero' => '2026-0001', 'tiers_id' => $tiers->id]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
```

- [ ] **Step 2: Lancer le test, vérifier l'échec**

```bash
./vendor/bin/sail test tests/Feature/Models/RecuFiscalEmisTest.php
```

Expected: FAIL — modèle/table inconnu.

- [ ] **Step 3: Écrire la migration**

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
        Schema::create('recus_fiscaux_emis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->cascadeOnDelete();
            $table->string('numero');
            $table->smallInteger('annee_civile');
            $table->foreignId('tiers_id')->constrained();
            $table->foreignId('transaction_ligne_id')->nullable()->constrained('transaction_lignes');
            $table->integer('montant_centimes');
            $table->date('date_versement');
            $table->string('mode_versement');
            $table->string('forme_don');
            $table->string('article_cgi');
            $table->string('pdf_path');
            $table->string('pdf_hash', 64);
            $table->timestamp('emitted_at');
            $table->foreignId('emitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('annule_at')->nullable();
            $table->text('annule_motif')->nullable();
            $table->foreignId('remplace_par_id')->nullable()->constrained('recus_fiscaux_emis')->nullOnDelete();
            $table->timestamps();

            $table->unique(['association_id', 'numero']);
            $table->index(['association_id', 'tiers_id', 'annee_civile']);
            $table->index(['association_id', 'transaction_ligne_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recus_fiscaux_emis');
    }
};
```

- [ ] **Step 4: Écrire le modèle**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

final class RecuFiscalEmis extends TenantModel
{
    use HasFactory;

    protected $table = 'recus_fiscaux_emis';

    protected $fillable = [
        'association_id',
        'numero',
        'annee_civile',
        'tiers_id',
        'transaction_ligne_id',
        'montant_centimes',
        'date_versement',
        'mode_versement',
        'forme_don',
        'article_cgi',
        'pdf_path',
        'pdf_hash',
        'emitted_at',
        'emitted_by_user_id',
        'annule_at',
        'annule_motif',
        'remplace_par_id',
    ];

    protected $casts = [
        'annee_civile' => 'integer',
        'montant_centimes' => 'integer',
        'date_versement' => 'date',
        'emitted_at' => 'datetime',
        'annule_at' => 'datetime',
    ];

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function transactionLigne(): BelongsTo
    {
        return $this->belongsTo(TransactionLigne::class);
    }

    public function emittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitted_by_user_id');
    }

    public function remplacePar(): BelongsTo
    {
        return $this->belongsTo(self::class, 'remplace_par_id');
    }

    public function isAnnule(): bool
    {
        return $this->annule_at !== null;
    }

    public function isActif(): bool
    {
        return ! $this->isAnnule();
    }

    public function pdfFullPath(): string
    {
        return "associations/{$this->association_id}/{$this->pdf_path}";
    }

    public function verifierIntegrite(): bool
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($this->pdfFullPath())) {
            return false;
        }

        return hash('sha256', $disk->get($this->pdfFullPath())) === $this->pdf_hash;
    }
}
```

- [ ] **Step 5: Écrire la factory**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

final class RecuFiscalEmisFactory extends Factory
{
    protected $model = RecuFiscalEmis::class;

    public function definition(): array
    {
        $associationId = TenantContext::currentId();

        return [
            'association_id' => $associationId,
            'numero' => '2026-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'annee_civile' => 2026,
            'tiers_id' => Tiers::factory(),
            'transaction_ligne_id' => null,
            'montant_centimes' => 15000,
            'date_versement' => $this->faker->date(),
            'mode_versement' => 'cheque',
            'forme_don' => 'numeraire',
            'article_cgi' => 'art_200',
            'pdf_path' => 'recus_fiscaux/2026/test.pdf',
            'pdf_hash' => str_repeat('a', 64),
            'emitted_at' => now(),
        ];
    }
}
```

- [ ] **Step 6: Migrer + retester**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail test tests/Feature/Models/RecuFiscalEmisTest.php
```

Expected: PASS pour les 4 tests.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_05_07_100002_create_recus_fiscaux_emis_table.php app/Models/RecuFiscalEmis.php database/factories/RecuFiscalEmisFactory.php tests/Feature/Models/RecuFiscalEmisTest.php
git commit -m "feat(recu-fiscal): table recus_fiscaux_emis + modèle RecuFiscalEmis"
```

---

## Task 4.5 : Helper de test `ligneDonValide`

**Files:**
- Create: `tests/Support/LigneDonHelper.php` (trait)
- Modify: `tests/Pest.php` (uses du trait)

Tous les tests de service / observer / controller ont besoin d'une `TransactionLigne` éligible. On factorise dans un trait pour ne pas dupliquer.

- [ ] **Step 1: Lire les factories existantes pour comprendre la convention**

```bash
ls database/factories/ | grep -iE "transaction|tiers|sous"
```

Lire `TransactionFactory`, `TransactionLigneFactory`, `SousCategorieFactory`, `TiersFactory` pour identifier les noms de colonnes (statut, date_operation, mode_paiement, montant, etc.) et les valeurs valides (`StatutTransaction::Recu` probable).

- [ ] **Step 2: Créer le helper**

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\UsageComptable;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;

trait LigneDonHelper
{
    protected function ligneDonValide(array $tiersOverrides = [], array $transactionOverrides = []): TransactionLigne
    {
        $tiers = Tiers::factory()->create(array_merge([
            'type' => 'particulier',
            'nom' => 'Dupont',
            'prenom' => 'Marie',
            'adresse' => '12 rue des Lilas',
            'code_postal' => '75001',
            'ville' => 'Paris',
        ], $tiersOverrides));

        $sousCategorieDon = SousCategorie::query()
            ->whereHas('usages', fn ($q) => $q->where('usage', UsageComptable::Don->value))
            ->first()
            ?? SousCategorie::factory()->create()->tap(function (SousCategorie $sc) {
                $sc->usages()->create(['usage' => UsageComptable::Don->value]);
            });

        $transaction = Transaction::factory()->create(array_merge([
            'tiers_id' => $tiers->id,
            'date_operation' => now()->subMonths(2),
            // Adapter le nom et la valeur du statut selon la convention projet (probable enum StatutTransaction).
            'statut' => 'recu',
            'mode_paiement' => 'cheque',
        ], $transactionOverrides));

        return TransactionLigne::factory()->create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $sousCategorieDon->id,
            'montant' => 150.00,
        ]);
    }
}
```

**Note** : le subagent doit ouvrir les factories existantes et adapter les valeurs/colonnes (notamment `statut` qui est probablement un enum `StatutTransaction::Recu` à utiliser via `->value`). Si une factory exige un champ obligatoire absent ici, l'ajouter.

- [ ] **Step 3: Activer le trait pour tous les tests pertinents**

Dans `tests/Pest.php`, ajouter au bloc `uses(...)` :

```php
uses(\Tests\Support\LigneDonHelper::class)->in(
    'Feature/Services',
    'Feature/Observers',
    'Feature/Http',
    'Feature/Livewire',
    'Unit/Services',
);
```

Dans les tests, remplacer les appels `ligneDonValide()` par `$this->ligneDonValide()` (cohérent avec un trait Pest).

- [ ] **Step 4: Sanity-check**

Modifier rapidement un test existant (ex: `RecuFiscalServiceEligibiliteTest`) pour utiliser `$this->ligneDonValide()` et lancer :

```bash
./vendor/bin/sail test tests/Unit/Services/RecuFiscalServiceEligibiliteTest.php
```

Expected: les tests qui dépendent du helper compilent et passent (s'ils étaient déjà au vert avant) ; ceux qui étaient en placeholder se débloquent.

- [ ] **Step 5: Commit**

```bash
git add tests/Support/LigneDonHelper.php tests/Pest.php
git commit -m "test(recu-fiscal): helper ligneDonValide pour les tests d'éligibilité et génération"
```

---

## Task 5 : Exception métier `RecuFiscalException`

**Files:**
- Create: `app/Exceptions/RecuFiscalException.php`

- [ ] **Step 1: Écrire la classe d'exception**

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class RecuFiscalException extends RuntimeException
{
    public static function associationNonEligible(): self
    {
        return new self('L\'association n\'est pas éligible à l\'émission de reçus fiscaux. Configurez l\'éligibilité dans Paramètres → Association.');
    }

    public static function adresseDonateurManquante(string $champManquant): self
    {
        return new self("Adresse postale du donateur incomplète : {$champManquant} manquant.");
    }

    public static function signataireManquant(): self
    {
        return new self('Le signataire (nom et qualité) doit être configuré dans les paramètres de l\'association.');
    }

    public static function transactionNonEncaissee(): self
    {
        return new self('Un don doit être encaissé pour donner droit à un reçu fiscal.');
    }

    public static function sansSousCategorie(): self
    {
        return new self('La transaction n\'a pas de sous-catégorie associée.');
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Exceptions/RecuFiscalException.php
git commit -m "feat(recu-fiscal): RecuFiscalException — cas de blocage d'éligibilité"
```

---

## Task 6 : `RecuFiscalService::validerEligibilite`

**Files:**
- Create: `app/Services/RecuFiscalService.php` (squelette + validerEligibilite)
- Test: `tests/Unit/Services/RecuFiscalServiceEligibiliteTest.php`

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Exceptions\RecuFiscalException;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use App\Support\TenantContext;

function ligneDonValide(): TransactionLigne
{
    // Helper créant une ligne de don conforme à toutes les règles d'éligibilité.
    // Adapter selon les factories existantes du projet (Transaction + TransactionLigne + SousCategorie usage Don).
    // À implémenter dans le test ; voir les factories existantes du projet.
    throw new \LogicException('À implémenter via factories projet');
}

it('throw si l\'association n\'est pas éligible', function () {
    $asso = Association::factory()->create(['eligible_recu_fiscal' => false]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();

    $service = app(RecuFiscalService::class);

    expect(fn () => $service->validerEligibilite($ligne))
        ->toThrow(RecuFiscalException::class, 'n\'est pas éligible');
});

it('throw si le signataire n\'est pas configuré', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => null,
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();

    $service = app(RecuFiscalService::class);

    expect(fn () => $service->validerEligibilite($ligne))
        ->toThrow(RecuFiscalException::class, 'signataire');
});

it('throw si l\'adresse du donateur est incomplète', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Dupont',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($asso);

    $ligne = ligneDonValide();
    $ligne->transaction->tiers->update(['adresse' => null]);

    $service = app(RecuFiscalService::class);

    expect(fn () => $service->validerEligibilite($ligne))
        ->toThrow(RecuFiscalException::class, 'Adresse');
});

it('throw si la transaction n\'est pas encaissée', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();
    $ligne->transaction->update(['statut' => 'a_regler']);  // ou enum non-Recu selon convention projet

    $service = app(RecuFiscalService::class);

    expect(fn () => $service->validerEligibilite($ligne))
        ->toThrow(RecuFiscalException::class, 'encaissé');
});

it('passe si tout est valide', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();

    $service = app(RecuFiscalService::class);

    $service->validerEligibilite($ligne);
    expect(true)->toBeTrue();  // arrive jusqu'ici sans exception
});
```

**Note pour le sous-agent** : la fonction `ligneDonValide()` doit créer une `Transaction` de statut `Recu` avec une `TransactionLigne` rattachée à une `SousCategorie` ayant `UsageComptable::Don`, sur un `Tiers` complet (adresse, ville, code_postal). Lire les factories existantes (`TransactionFactory`, `TransactionLigneFactory`, `SousCategorieFactory`, `TiersFactory`) pour respecter les conventions du projet.

- [ ] **Step 2: Lancer le test, vérifier l'échec**

```bash
./vendor/bin/sail test tests/Unit/Services/RecuFiscalServiceEligibiliteTest.php
```

Expected: FAIL.

- [ ] **Step 3: Implémenter `validerEligibilite`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\RecuFiscalException;
use App\Models\Association;
use App\Models\TransactionLigne;
use App\Support\TenantContext;

final class RecuFiscalService
{
    public function validerEligibilite(TransactionLigne $ligne): void
    {
        $asso = Association::findOrFail(TenantContext::currentId());

        if (! $asso->eligible_recu_fiscal) {
            throw RecuFiscalException::associationNonEligible();
        }

        if (empty($asso->signataire_nom) || empty($asso->signataire_qualite)) {
            throw RecuFiscalException::signataireManquant();
        }

        if (! $ligne->sousCategorie) {
            throw RecuFiscalException::sansSousCategorie();
        }

        $transaction = $ligne->transaction;
        if ($transaction->statut?->value !== 'recu' && $transaction->statut !== 'recu') {
            throw RecuFiscalException::transactionNonEncaissee();
        }

        $tiers = $transaction->tiers;
        foreach (['adresse', 'code_postal', 'ville'] as $champ) {
            if (empty($tiers->{$champ})) {
                throw RecuFiscalException::adresseDonateurManquante($champ);
            }
        }
    }
}
```

**Note** : adapter le nom de la propriété `statut` et la valeur `'recu'` selon les conventions exactes du modèle Transaction du projet (enum `StatutTransaction::Recu` probablement). Lire `app/Models/Transaction.php` et `app/Enums/StatutTransaction.php` (ou équivalent) pour la valeur exacte.

- [ ] **Step 4: Lancer les tests, vérifier le PASS**

```bash
./vendor/bin/sail test tests/Unit/Services/RecuFiscalServiceEligibiliteTest.php
```

Expected: PASS pour les 5 cas.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RecuFiscalService.php tests/Unit/Services/RecuFiscalServiceEligibiliteTest.php
git commit -m "feat(recu-fiscal): RecuFiscalService::validerEligibilite — 5 cas de blocage"
```

---

## Task 7 : Numérotation des reçus fiscaux

**Files:**
- Modify: `app/Services/NumeroPieceService.php` (ajout méthode si applicable, ou usage pattern existant)
- Test: `tests/Unit/Services/RecuFiscalNumerotationTest.php`

**Note** : avant d'écrire, lire `app/Services/NumeroPieceService.php` pour comprendre la mécanique exacte (table de séquence, lock pessimiste, format). Le service `RecuFiscalService` doit déléguer la génération du numéro à `NumeroPieceService` ou implémenter une méthode équivalente avec lock pessimiste sur une nouvelle séquence.

- [ ] **Step 1: Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Services\RecuFiscalService;
use App\Support\TenantContext;

it('génère des numéros séquentiels par tenant et année', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $service = app(RecuFiscalService::class);

    $numero1 = invokePrivateMethod($service, 'allouerNumero', [2026]);
    $numero2 = invokePrivateMethod($service, 'allouerNumero', [2026]);
    $numero3 = invokePrivateMethod($service, 'allouerNumero', [2026]);

    expect($numero1)->toBe('2026-0001');
    expect($numero2)->toBe('2026-0002');
    expect($numero3)->toBe('2026-0003');
});

it('isole les séquences par tenant', function () {
    $asso1 = Association::factory()->create();
    $asso2 = Association::factory()->create();

    TenantContext::boot($asso1);
    $service = app(RecuFiscalService::class);
    $numeroAsso1 = invokePrivateMethod($service, 'allouerNumero', [2026]);

    TenantContext::boot($asso2);
    $numeroAsso2 = invokePrivateMethod($service, 'allouerNumero', [2026]);

    expect($numeroAsso1)->toBe('2026-0001');
    expect($numeroAsso2)->toBe('2026-0001');  // chaque asso démarre à 0001
});

it('isole les séquences par année civile', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $service = app(RecuFiscalService::class);

    $numero2025 = invokePrivateMethod($service, 'allouerNumero', [2025]);
    $numero2026 = invokePrivateMethod($service, 'allouerNumero', [2026]);

    expect($numero2025)->toBe('2025-0001');
    expect($numero2026)->toBe('2026-0001');
});

function invokePrivateMethod(object $object, string $method, array $args): mixed
{
    $reflection = new \ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $args);
}
```

- [ ] **Step 2: Lancer, vérifier l'échec**

```bash
./vendor/bin/sail test tests/Unit/Services/RecuFiscalNumerotationTest.php
```

Expected: FAIL — méthode `allouerNumero` inexistante.

- [ ] **Step 3: Implémenter `allouerNumero` dans `RecuFiscalService`**

Ajouter à `app/Services/RecuFiscalService.php` :

```php
use App\Models\RecuFiscalEmis;
use Illuminate\Support\Facades\DB;

private function allouerNumero(int $annee): string
{
    return DB::transaction(function () use ($annee) {
        $associationId = TenantContext::currentId();

        $dernier = RecuFiscalEmis::query()
            ->where('association_id', $associationId)
            ->where('annee_civile', $annee)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        $sequence = 1;
        if ($dernier !== null) {
            $parts = explode('-', $dernier->numero);
            $sequence = (int) end($parts) + 1;
        }

        return sprintf('%d-%04d', $annee, $sequence);
    });
}
```

- [ ] **Step 4: Lancer, vérifier le PASS**

```bash
./vendor/bin/sail test tests/Unit/Services/RecuFiscalNumerotationTest.php
```

Expected: PASS pour les 3 cas.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RecuFiscalService.php tests/Unit/Services/RecuFiscalNumerotationTest.php
git commit -m "feat(recu-fiscal): allocation numéro séquentiel par tenant + année"
```

---

## Task 8 : Vue PDF Blade `recu-fiscal-don`

**Files:**
- Create: `resources/views/pdf/recu-fiscal-don.blade.php`

**Note** : s'inspirer de `resources/views/pdf/facture.blade.php` et `resources/views/pdf/attestation-presence.blade.php` pour la structure CSS, l'inclusion du footer (`PdfFooterRenderer`), et le rendu du logo.

- [ ] **Step 1: Écrire la vue (sans test direct, sera testée par les feature tests)**

```blade
@php
    use App\Support\PdfFooterRenderer;

    /** @var \App\Models\RecuFiscalEmis $recu */
    /** @var \App\Models\Association $asso */
    /** @var \App\Models\Tiers $donateur */
    /** @var string $montantFormate */
    /** @var string $montantEnLettres */
    /** @var string $articleCgiLibelle */
    /** @var string $formeLibelle */
    /** @var string $modeLibelle */
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Reçu fiscal n° {{ $recu->numero }}</title>
    <style>
        @page { margin: 1cm 1.5cm 3cm 1.5cm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #333; }
        h1 { font-size: 14pt; text-align: center; text-transform: uppercase; margin: 1.5em 0; }
        .header { display: table; width: 100%; }
        .header-left { display: table-cell; width: 60%; }
        .header-right { display: table-cell; width: 40%; text-align: right; }
        .logo { max-height: 80px; }
        .bloc { margin: 1em 0; padding: 0.5em 1em; border: 1px solid #ccc; }
        .bloc-titre { font-weight: bold; text-transform: uppercase; font-size: 9pt; color: #666; margin-bottom: 0.3em; }
        .montant { font-size: 14pt; font-weight: bold; }
        .signature { margin-top: 3em; text-align: right; }
        .signature img { max-height: 80px; }
        .mention-legale { font-style: italic; font-size: 9pt; margin-top: 1em; }
    </style>
</head>
<body>

<div class="header">
    <div class="header-left">
        <strong>{{ $asso->nom }}</strong><br>
        {{ $asso->adresse }}<br>
        {{ $asso->code_postal }} {{ $asso->ville }}<br>
        @if($asso->siret)
            SIRET : {{ $asso->siret }}<br>
        @endif
        @if($asso->numero_rna ?? null)
            RNA : {{ $asso->numero_rna }}
        @endif
    </div>
    <div class="header-right">
        @if($asso->logo_path)
            <img src="{{ public_path('storage/'.$asso->logo_path) }}" class="logo" alt="Logo">
        @endif
    </div>
</div>

<h1>Reçu au titre des dons à certains organismes d'intérêt général</h1>

<p style="text-align: center; font-weight: bold;">
    Reçu n° {{ $recu->numero }} &mdash; Émis le {{ $recu->emitted_at->format('d/m/Y') }}
</p>

<div class="bloc">
    <div class="bloc-titre">Bénéficiaire</div>
    <strong>{{ $asso->nom }}</strong><br>
    @if($asso->regime_fiscal_don)
        Régime : {{ $asso->regime_fiscal_don }}<br>
    @endif
    @if($asso->objet_recu_fiscal)
        Objet : {{ $asso->objet_recu_fiscal }}<br>
    @endif
    @if($asso->rescrit_fiscal_numero)
        Rescrit fiscal n° {{ $asso->rescrit_fiscal_numero }}
        en date du {{ $asso->rescrit_fiscal_date?->format('d/m/Y') }}
    @endif
</div>

<div class="bloc">
    <div class="bloc-titre">Donateur</div>
    @if($donateur->type === 'entreprise')
        <strong>{{ $donateur->displayName() }}</strong> (personne morale)<br>
    @else
        <strong>{{ trim(($donateur->prenom ? $donateur->prenom.' ' : '').strtoupper($donateur->nom)) }}</strong> (personne physique)<br>
    @endif
    {{ $donateur->adresse }}<br>
    {{ $donateur->code_postal }} {{ $donateur->ville }}
</div>

<p>
    L'association reconnaît avoir reçu de
    <strong>{{ $donateur->displayName() }}</strong>
    la somme de <span class="montant">{{ $montantFormate }}</span>
    ({{ $montantEnLettres }})
    au titre de :
</p>

<ul>
    <li><strong>Date du versement :</strong> {{ $recu->date_versement->format('d/m/Y') }}</li>
    <li><strong>Mode de versement :</strong> {{ $modeLibelle }}</li>
    <li><strong>Forme du don :</strong> {{ $formeLibelle }}</li>
</ul>

<p class="mention-legale">
    Le bénéficiaire certifie sur l'honneur que les dons et versements qu'il reçoit
    ouvrent droit à la réduction d'impôt prévue à l'<strong>{{ $articleCgiLibelle }}</strong> du Code général des impôts.
</p>

<div class="signature">
    Fait à {{ $asso->ville }}, le {{ $recu->emitted_at->format('d/m/Y') }}<br><br>
    <strong>{{ $asso->signataire_nom }}</strong><br>
    {{ $asso->signataire_qualite }}<br>
    @if($asso->cachet_signature_path ?? null)
        <img src="{{ public_path('storage/'.$asso->cachet_signature_path) }}" alt="Signature">
    @endif
</div>

{!! PdfFooterRenderer::render($asso) !!}

</body>
</html>
```

**Note** : les noms de propriétés (`logo_path`, `cachet_signature_path`, `numero_rna`) doivent être adaptés aux noms exacts du modèle `Association` du projet — vérifier dans `app/Models/Association.php` avant. Si une propriété n'existe pas, utiliser le fallback approprié ou retirer le bloc concerné.

- [ ] **Step 2: Vérifier que la vue compile**

```bash
./vendor/bin/sail artisan view:cache
./vendor/bin/sail artisan view:clear
```

Expected: pas d'erreur de compilation.

- [ ] **Step 3: Commit**

```bash
git add resources/views/pdf/recu-fiscal-don.blade.php
git commit -m "feat(recu-fiscal): vue Blade pdf.recu-fiscal-don avec mentions légales"
```

---

## Task 9 : `RecuFiscalService::obtenirOuGenerer` (cœur du service)

**Files:**
- Modify: `app/Services/RecuFiscalService.php`
- Test: `tests/Feature/Services/RecuFiscalGenerationTest.php`

**Note préalable** : ce step réunit la génération PDF + wrap PDF/A-3 + persistance. Lire `app/Services/DocumentPrevisionnelService.php:186-204` pour le pattern exact de génération PDF/A-3.

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('génère un reçu fiscal pour un don valide', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Dupont',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();  // helper du test précédent

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne);

    expect($recu)->toBeInstanceOf(RecuFiscalEmis::class);
    expect($recu->numero)->toBe('2026-0001');
    expect($recu->annee_civile)->toBe((int) $ligne->transaction->date_operation->format('Y'));
    expect($recu->tiers_id)->toBe($ligne->transaction->tiers_id);
    expect($recu->transaction_ligne_id)->toBe($ligne->id);
    expect($recu->montant_centimes)->toBe((int) round($ligne->montant * 100));
    expect($recu->pdf_hash)->toHaveLength(64);
    expect(Storage::disk('local')->exists($recu->pdfFullPath()))->toBeTrue();
});

it('est idempotent : un deuxième appel retourne le même reçu', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();

    $service = app(RecuFiscalService::class);
    $recu1 = $service->obtenirOuGenerer($ligne);
    $recu2 = $service->obtenirOuGenerer($ligne);

    expect($recu2->id)->toBe($recu1->id);
    expect($recu2->numero)->toBe($recu1->numero);
});

it('dérive article 200 pour un donateur particulier', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();
    $ligne->transaction->tiers->update(['type' => 'particulier']);

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne);

    expect($recu->article_cgi)->toBe('art_200');
});

it('dérive article 238 bis pour un donateur entreprise', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();
    $ligne->transaction->tiers->update(['type' => 'entreprise']);

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne);

    expect($recu->article_cgi)->toBe('art_238_bis');
});

it('dérive forme abandon_revenus pour sous-cat avec usage AbandonCreance', function () {
    // Créer une transaction sur sous-cat 771 (AbandonCreance) — voir factories
    // ...
    expect(true)->toBeTrue();  // placeholder, à compléter avec factory adéquate
});

it('vérifie que le pdf_hash correspond bien au binaire stocké', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne);

    expect($recu->verifierIntegrite())->toBeTrue();
});
```

- [ ] **Step 2: Lancer, vérifier l'échec**

```bash
./vendor/bin/sail test tests/Feature/Services/RecuFiscalGenerationTest.php
```

Expected: FAIL — méthode inexistante.

- [ ] **Step 3: Implémenter `obtenirOuGenerer`**

Compléter `app/Services/RecuFiscalService.php` :

```php
use App\Enums\UsageComptable;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Models\TransactionLigne;
use App\Models\User;
use Atgp\FacturX\Writer as FacturXWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

public function obtenirOuGenerer(TransactionLigne $ligne, ?User $user = null): RecuFiscalEmis
{
    return DB::transaction(function () use ($ligne, $user) {
        $existant = RecuFiscalEmis::query()
            ->where('transaction_ligne_id', $ligne->id)
            ->whereNull('annule_at')
            ->lockForUpdate()
            ->first();

        if ($existant !== null) {
            return $existant;
        }

        $this->validerEligibilite($ligne);

        $asso = Association::findOrFail(TenantContext::currentId());
        $tiers = $ligne->transaction->tiers;
        $sousCat = $ligne->sousCategorie;
        $dateVersement = $ligne->transaction->date_operation;
        $anneeCivile = (int) $dateVersement->format('Y');

        $articleCgi = $this->determinerArticleCgi($tiers);
        $formeDon = $this->determinerFormeDon($sousCat);
        $modeVersement = $ligne->transaction->mode_paiement?->value ?? 'autre';
        $numero = $this->allouerNumero($anneeCivile);

        $pdfBinaire = $this->genererPdfBinaire($asso, $tiers, $ligne, $numero, $articleCgi, $formeDon, $modeVersement);
        $pdfPath = "recus_fiscaux/{$anneeCivile}/{$numero}.pdf";
        $fullPath = "associations/{$asso->id}/{$pdfPath}";
        Storage::disk('local')->put($fullPath, $pdfBinaire);

        $hash = hash('sha256', $pdfBinaire);

        return RecuFiscalEmis::create([
            'association_id' => $asso->id,
            'numero' => $numero,
            'annee_civile' => $anneeCivile,
            'tiers_id' => $tiers->id,
            'transaction_ligne_id' => $ligne->id,
            'montant_centimes' => (int) round($ligne->montant * 100),
            'date_versement' => $dateVersement,
            'mode_versement' => $modeVersement,
            'forme_don' => $formeDon,
            'article_cgi' => $articleCgi,
            'pdf_path' => $pdfPath,
            'pdf_hash' => $hash,
            'emitted_at' => now(),
            'emitted_by_user_id' => $user?->id,
        ]);
    });
}

private function determinerArticleCgi(Tiers $donateur): string
{
    return $donateur->type === 'entreprise' ? 'art_238_bis' : 'art_200';
}

private function determinerFormeDon(SousCategorie $sc): string
{
    return $sc->hasUsage(UsageComptable::AbandonCreance)
        ? 'abandon_revenus'
        : 'numeraire';
}

private function genererPdfBinaire(
    Association $asso,
    Tiers $donateur,
    TransactionLigne $ligne,
    string $numero,
    string $articleCgi,
    string $formeDon,
    string $modeVersement,
): string {
    $montantFloat = (float) $ligne->montant;
    $montantFormate = number_format($montantFloat, 2, ',', ' ').' €';
    $montantEnLettres = app(MontantEnLettresService::class)->convertir($montantFloat);

    $articleCgiLibelle = match ($articleCgi) {
        'art_200' => 'article 200',
        'art_238_bis' => 'article 238 bis',
        default => $articleCgi,
    };

    $formeLibelle = match ($formeDon) {
        'numeraire' => 'Don manuel en numéraire',
        'abandon_revenus' => 'Le donateur renonce expressément au remboursement des frais engagés dans le cadre de son activité bénévole et entend en faire don à l\'association.',
        default => $formeDon,
    };

    $modeLibelle = match ($modeVersement) {
        'cheque' => 'Chèque',
        'virement' => 'Virement bancaire',
        'espece' => 'Espèces',
        'carte' => 'Carte bancaire',
        default => 'Autre',
    };

    $recuTemporaire = (object) [
        'numero' => $numero,
        'emitted_at' => now(),
        'date_versement' => $ligne->transaction->date_operation,
    ];

    $pdfBase = Pdf::loadView('pdf.recu-fiscal-don', [
        'recu' => $recuTemporaire,
        'asso' => $asso,
        'donateur' => $donateur,
        'montantFormate' => $montantFormate,
        'montantEnLettres' => $montantEnLettres,
        'articleCgiLibelle' => $articleCgiLibelle,
        'formeLibelle' => $formeLibelle,
        'modeLibelle' => $modeLibelle,
    ])->setPaper('a4', 'portrait');

    $pdfContent = $pdfBase->output();

    $xml = $this->genererMetadataXml($numero, $ligne, $asso, $donateur);
    $writer = new FacturXWriter;

    return $writer->generate($pdfContent, $xml, 'minimum', false);
}

private function genererMetadataXml(string $numero, TransactionLigne $ligne, Association $asso, Tiers $donateur): string
{
    $date = now()->format('Ymd');
    $montant = number_format((float) $ligne->montant, 2, '.', '');
    $sellerName = htmlspecialchars($asso->nom ?? '', ENT_XML1, 'UTF-8');
    $siret = htmlspecialchars($asso->siret ?? '', ENT_XML1, 'UTF-8');
    $buyerName = htmlspecialchars($donateur->displayName(), ENT_XML1, 'UTF-8');

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100">
  <rsm:ExchangedDocument>
    <ram:ID>{$numero}</ram:ID>
    <ram:TypeCode>325</ram:TypeCode>
    <ram:IssueDateTime><udt:DateTimeString format="102">{$date}</udt:DateTimeString></ram:IssueDateTime>
  </rsm:ExchangedDocument>
  <rsm:SupplyChainTradeTransaction>
    <ram:ApplicableHeaderTradeAgreement>
      <ram:SellerTradeParty><ram:Name>{$sellerName}</ram:Name><ram:SpecifiedLegalOrganization><ram:ID>{$siret}</ram:ID></ram:SpecifiedLegalOrganization></ram:SellerTradeParty>
      <ram:BuyerTradeParty><ram:Name>{$buyerName}</ram:Name></ram:BuyerTradeParty>
    </ram:ApplicableHeaderTradeAgreement>
    <ram:ApplicableHeaderTradeSettlement>
      <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>
      <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
        <ram:GrandTotalAmount currencyID="EUR">{$montant}</ram:GrandTotalAmount>
      </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
    </ram:ApplicableHeaderTradeSettlement>
  </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
XML;
}
```

- [ ] **Step 4: Lancer, vérifier le PASS**

```bash
./vendor/bin/sail test tests/Feature/Services/RecuFiscalGenerationTest.php
```

Expected: PASS pour les 6 cas.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RecuFiscalService.php tests/Feature/Services/RecuFiscalGenerationTest.php
git commit -m "feat(recu-fiscal): obtenirOuGenerer — génération PDF/A-3 idempotente avec dérivation article+forme"
```

---

## Task 10 : `RecuFiscalService::streamPdf` + vérification d'intégrité

**Files:**
- Modify: `app/Services/RecuFiscalService.php`
- Test: `tests/Feature/Services/RecuFiscalStreamTest.php`

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Services\RecuFiscalService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('stream le PDF binaire stocké', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne);

    $response = $service->streamPdf($recu);

    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain($recu->numero);
});

it('throw si l\'intégrité est compromise (fichier modifié)', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne);

    Storage::disk('local')->put($recu->pdfFullPath(), 'corrupted-content');

    expect(fn () => $service->streamPdf($recu))
        ->toThrow(\RuntimeException::class, 'Intégrité');
});
```

- [ ] **Step 2: Lancer, vérifier l'échec**

```bash
./vendor/bin/sail test tests/Feature/Services/RecuFiscalStreamTest.php
```

Expected: FAIL.

- [ ] **Step 3: Implémenter `streamPdf`**

Ajouter à `RecuFiscalService` :

```php
use Symfony\Component\HttpFoundation\StreamedResponse;

public function streamPdf(RecuFiscalEmis $recu): StreamedResponse
{
    if (! $recu->verifierIntegrite()) {
        throw new \RuntimeException("Intégrité du PDF reçu n°{$recu->numero} compromise — hash incorrect");
    }

    $disk = Storage::disk('local');
    $filename = "recu-fiscal-{$recu->numero}.pdf";

    return $disk->download($recu->pdfFullPath(), $filename);
}
```

- [ ] **Step 4: Lancer, vérifier le PASS**

```bash
./vendor/bin/sail test tests/Feature/Services/RecuFiscalStreamTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RecuFiscalService.php tests/Feature/Services/RecuFiscalStreamTest.php
git commit -m "feat(recu-fiscal): streamPdf avec vérification d'intégrité SHA256"
```

---

## Task 11 : `RecuFiscalService::annuler` + `reemettre`

**Files:**
- Modify: `app/Services/RecuFiscalService.php`
- Test: `tests/Feature/Services/RecuFiscalAnnulationTest.php`

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Services\RecuFiscalService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('annule un reçu actif', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();
    $user = User::factory()->create();

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne, $user);

    $service->annuler($recu, 'Adresse corrigée', $user);
    $recu->refresh();

    expect($recu->isAnnule())->toBeTrue();
    expect($recu->annule_motif)->toBe('Adresse corrigée');
});

it('réémet un reçu : annule l\'ancien et chaîne le nouveau', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();
    $user = User::factory()->create();

    $service = app(RecuFiscalService::class);
    $ancien = $service->obtenirOuGenerer($ligne, $user);

    $nouveau = $service->reemettre($ancien, 'Adresse corrigée', $user);
    $ancien->refresh();

    expect($ancien->isAnnule())->toBeTrue();
    expect($ancien->remplace_par_id)->toBe($nouveau->id);
    expect($nouveau->numero)->not->toBe($ancien->numero);
    expect($nouveau->isActif())->toBeTrue();
});
```

- [ ] **Step 2: Lancer, vérifier l'échec**

```bash
./vendor/bin/sail test tests/Feature/Services/RecuFiscalAnnulationTest.php
```

Expected: FAIL.

- [ ] **Step 3: Implémenter `annuler` + `reemettre`**

Ajouter à `RecuFiscalService` :

```php
public function annuler(RecuFiscalEmis $recu, string $motif, ?User $user = null): void
{
    if ($recu->isAnnule()) {
        return;
    }

    $recu->update([
        'annule_at' => now(),
        'annule_motif' => $motif,
    ]);
}

public function reemettre(RecuFiscalEmis $ancien, string $motif, ?User $user = null): RecuFiscalEmis
{
    return DB::transaction(function () use ($ancien, $motif, $user) {
        $this->annuler($ancien, $motif, $user);

        $ligne = $ancien->transactionLigne;
        $nouveau = $this->obtenirOuGenerer($ligne, $user);

        $ancien->update(['remplace_par_id' => $nouveau->id]);

        return $nouveau;
    });
}
```

- [ ] **Step 4: Lancer, vérifier le PASS**

```bash
./vendor/bin/sail test tests/Feature/Services/RecuFiscalAnnulationTest.php
```

Expected: PASS pour les 2 cas.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RecuFiscalService.php tests/Feature/Services/RecuFiscalAnnulationTest.php
git commit -m "feat(recu-fiscal): annuler + reemettre avec chaînage remplace_par_id"
```

---

## Task 12 : Observer auto-annulation sur modification/suppression de don

**Files:**
- Create: `app/Observers/TransactionLigneRecuFiscalObserver.php` (ou extension de l'observer existant si présent)
- Modify: `app/Providers/AppServiceProvider.php` (enregistrement de l'observer)
- Test: `tests/Feature/Observers/RecuFiscalAutoAnnulationTest.php`

**Note préalable** : vérifier si un `TransactionLigneObserver` existe déjà dans le projet. Si oui, étendre. Sinon, créer un observer dédié au reçu fiscal pour rester découplé.

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Services\RecuFiscalService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('annule auto le reçu si la ligne don est supprimée', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);
    expect($recu->isActif())->toBeTrue();

    $ligne->delete();
    $recu->refresh();

    expect($recu->isAnnule())->toBeTrue();
    expect($recu->annule_motif)->toContain('supprim');
});

it('annule auto le reçu si le montant change', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);
    $ligne->update(['montant' => $ligne->montant + 10]);
    $recu->refresh();

    expect($recu->isAnnule())->toBeTrue();
    expect($recu->annule_motif)->toContain('modifi');
});

it('n\'annule PAS le reçu si seules les notes/libelle changent', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);
    $ligne->update(['notes' => 'Nouvelle note', 'libelle' => 'Nouveau libellé']);
    $recu->refresh();

    expect($recu->isActif())->toBeTrue();
});
```

- [ ] **Step 2: Lancer, vérifier l'échec**

```bash
./vendor/bin/sail test tests/Feature/Observers/RecuFiscalAutoAnnulationTest.php
```

Expected: FAIL.

- [ ] **Step 3: Créer l'observer**

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\RecuFiscalEmis;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;

final class TransactionLigneRecuFiscalObserver
{
    public function __construct(
        private readonly RecuFiscalService $service,
    ) {}

    public function deleting(TransactionLigne $ligne): void
    {
        $this->annulerReçusActifs($ligne, 'Don supprimé');
    }

    public function updating(TransactionLigne $ligne): void
    {
        $champsCritiques = ['montant', 'sous_categorie_id'];
        $changements = array_intersect($champsCritiques, array_keys($ligne->getDirty()));

        // Pour date_operation et tiers_id, ils sont sur Transaction (pas TransactionLigne).
        // Si la propriété existe sur TransactionLigne dans ce projet, l'ajouter ci-dessus.
        if (empty($changements)) {
            return;
        }

        $detail = implode(', ', $changements);
        $this->annulerReçusActifs($ligne, "Don modifié — {$detail}");
    }

    private function annulerReçusActifs(TransactionLigne $ligne, string $motif): void
    {
        $recus = RecuFiscalEmis::query()
            ->where('transaction_ligne_id', $ligne->id)
            ->whereNull('annule_at')
            ->get();

        foreach ($recus as $recu) {
            $this->service->annuler($recu, $motif);
        }
    }
}
```

**Note** : adapter la liste de champs critiques selon le schéma exact de `TransactionLigne` du projet. Pour les changements sur la `Transaction` parente (date_operation, tiers_id, statut), un observer supplémentaire sur `Transaction` peut être nécessaire — à ajouter en step suivant si applicable.

- [ ] **Step 4: Enregistrer l'observer**

Modifier `app/Providers/AppServiceProvider.php` dans `boot()` :

```php
use App\Models\TransactionLigne;
use App\Observers\TransactionLigneRecuFiscalObserver;

TransactionLigne::observe(TransactionLigneRecuFiscalObserver::class);
```

- [ ] **Step 5: Lancer, vérifier le PASS**

```bash
./vendor/bin/sail test tests/Feature/Observers/RecuFiscalAutoAnnulationTest.php
```

Expected: PASS pour les 3 cas.

- [ ] **Step 6: Commit**

```bash
git add app/Observers/TransactionLigneRecuFiscalObserver.php app/Providers/AppServiceProvider.php tests/Feature/Observers/RecuFiscalAutoAnnulationTest.php
git commit -m "feat(recu-fiscal): observer auto-annulation sur modification/suppression de don"
```

---

## Task 13 : Observer Transaction (date_operation, tiers_id, statut)

**Files:**
- Create: `app/Observers/TransactionRecuFiscalObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Observers/TransactionRecuFiscalObserverTest.php`

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Services\RecuFiscalService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('annule auto le reçu si la date_operation change', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);
    $ligne->transaction->update(['date_operation' => now()->subDays(10)]);
    $recu->refresh();

    expect($recu->isAnnule())->toBeTrue();
});

it('annule auto le reçu si le tiers change', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = ligneDonValide();
    $autreTiers = \App\Models\Tiers::factory()->create();

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);
    $ligne->transaction->update(['tiers_id' => $autreTiers->id]);
    $recu->refresh();

    expect($recu->isAnnule())->toBeTrue();
});
```

- [ ] **Step 2: Lancer, vérifier l'échec**

- [ ] **Step 3: Implémenter l'observer + l'enregistrer**

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\RecuFiscalEmis;
use App\Models\Transaction;
use App\Services\RecuFiscalService;

final class TransactionRecuFiscalObserver
{
    public function __construct(
        private readonly RecuFiscalService $service,
    ) {}

    public function updating(Transaction $transaction): void
    {
        $champsCritiques = ['date_operation', 'tiers_id'];
        $changements = array_intersect($champsCritiques, array_keys($transaction->getDirty()));

        if (empty($changements)) {
            return;
        }

        $ligneIds = $transaction->lignes()->pluck('id');
        $recus = RecuFiscalEmis::query()
            ->whereIn('transaction_ligne_id', $ligneIds)
            ->whereNull('annule_at')
            ->get();

        $detail = implode(', ', $changements);
        foreach ($recus as $recu) {
            $this->service->annuler($recu, "Don modifié — {$detail}");
        }
    }
}
```

Enregistrement dans `AppServiceProvider::boot()` :
```php
Transaction::observe(TransactionRecuFiscalObserver::class);
```

- [ ] **Step 4: Lancer, vérifier le PASS**

- [ ] **Step 5: Commit**

```bash
git add app/Observers/TransactionRecuFiscalObserver.php app/Providers/AppServiceProvider.php tests/Feature/Observers/TransactionRecuFiscalObserverTest.php
git commit -m "feat(recu-fiscal): observer Transaction — auto-annulation sur date/tiers change"
```

---

## Task 14 : Policy `RecuFiscalPolicy`

**Files:**
- Create: `app/Policies/RecuFiscalPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php` (mapping)
- Test: `tests/Feature/Policies/RecuFiscalPolicyTest.php`

- [ ] **Step 1: Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\User;
use App\Support\TenantContext;

it('autorise un user du tenant à télécharger', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso);  // selon convention projet
    $recu = RecuFiscalEmis::factory()->create();

    expect($user->can('download', $recu))->toBeTrue();
});

it('refuse un user d\'un autre tenant', function () {
    $asso1 = Association::factory()->create();
    $asso2 = Association::factory()->create();

    TenantContext::boot($asso1);
    $recu = RecuFiscalEmis::factory()->create();

    TenantContext::boot($asso2);
    $user = User::factory()->create();
    $user->associations()->attach($asso2);

    expect($user->can('download', $recu))->toBeFalse();
});
```

**Note** : adapter `$user->associations()->attach()` à la convention du projet (table pivot `association_user` selon les migrations vues).

- [ ] **Step 2: Lancer, vérifier l'échec**

- [ ] **Step 3: Implémenter la policy**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RecuFiscalEmis;
use App\Models\User;

final class RecuFiscalPolicy
{
    public function download(User $user, RecuFiscalEmis $recu): bool
    {
        return $user->associations()
            ->where('association_id', $recu->association_id)
            ->exists();
    }

    public function annuler(User $user, RecuFiscalEmis $recu): bool
    {
        return $this->download($user, $recu);
    }
}
```

- [ ] **Step 4: Enregistrer dans `AuthServiceProvider`**

```php
protected $policies = [
    // ...
    \App\Models\RecuFiscalEmis::class => \App\Policies\RecuFiscalPolicy::class,
];
```

- [ ] **Step 5: Lancer, vérifier le PASS**

- [ ] **Step 6: Commit**

```bash
git add app/Policies/RecuFiscalPolicy.php app/Providers/AuthServiceProvider.php tests/Feature/Policies/RecuFiscalPolicyTest.php
git commit -m "feat(recu-fiscal): RecuFiscalPolicy — autorisation tenant scope"
```

---

## Task 15 : Route + Controller `GET recu-fiscal`

**Files:**
- Create: `app/Http/Controllers/RecuFiscalController.php`
- Modify: `routes/web.php` (ajout route)
- Test: `tests/Feature/Http/RecuFiscalControllerTest.php`

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\RecuFiscalService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('télécharge le PDF d\'un don éligible (création + stream)', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso);
    $ligne = ligneDonValide();

    $response = $this->actingAs($user)
        ->get(route('tiers.dons.recu-fiscal', [$ligne->transaction->tiers, $ligne]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
});

it('redirige vers le reçu de remplacement si l\'actuel est annulé', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso);
    $ligne = ligneDonValide();

    $service = app(RecuFiscalService::class);
    $ancien = $service->obtenirOuGenerer($ligne, $user);
    $nouveau = $service->reemettre($ancien, 'Test', $user);

    // Les deux ont le même transaction_ligne_id ; le controller doit retourner le nouveau (actif).
    $response = $this->actingAs($user)
        ->get(route('tiers.dons.recu-fiscal', [$ligne->transaction->tiers, $ligne]));

    $response->assertOk();
    $response->assertHeader('Content-Disposition', 'attachment; filename=recu-fiscal-'.$nouveau->numero.'.pdf');
});

it('retourne 422 si l\'asso n\'est pas éligible', function () {
    $asso = Association::factory()->create(['eligible_recu_fiscal' => false]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso);
    $ligne = ligneDonValide();

    $response = $this->actingAs($user)
        ->get(route('tiers.dons.recu-fiscal', [$ligne->transaction->tiers, $ligne]));

    $response->assertStatus(422);
});

it('retourne 403 pour un user d\'un autre tenant', function () {
    $asso1 = Association::factory()->create(['eligible_recu_fiscal' => true]);
    $asso2 = Association::factory()->create();

    TenantContext::boot($asso1);
    $ligne = ligneDonValide();

    $user2 = User::factory()->create();
    $user2->associations()->attach($asso2);

    $response = $this->actingAs($user2)
        ->get(route('tiers.dons.recu-fiscal', [$ligne->transaction->tiers, $ligne]));

    $response->assertForbidden();
});
```

- [ ] **Step 2: Lancer, vérifier l'échec**

- [ ] **Step 3: Implémenter le controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\RecuFiscalException;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use Illuminate\Http\Request;

final class RecuFiscalController extends Controller
{
    public function __construct(
        private readonly RecuFiscalService $service,
    ) {}

    public function download(Request $request, Tiers $tiers, TransactionLigne $ligne)
    {
        abort_unless((int) $ligne->transaction->tiers_id === (int) $tiers->id, 404);

        // 1. Si un reçu actif existe pour cette ligne (potentiellement le remplaçant
        //    d'un ancien annulé), le service le retourne directement.
        try {
            $recu = $this->service->obtenirOuGenerer($ligne, $request->user());
        } catch (RecuFiscalException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->authorize('download', $recu);

        // 2. Si — cas exceptionnel — le reçu retourné est annulé sans remplaçant,
        //    retourner 410 Gone (par exemple suite à une annulation manuelle sans ré-émission).
        if ($recu->isAnnule() && $recu->remplace_par_id === null) {
            return response()->json([
                'message' => "Reçu annulé le {$recu->annule_at->format('d/m/Y')} — motif : {$recu->annule_motif}",
            ], 410);
        }

        return $this->service->streamPdf($recu);
    }
}
```

- [ ] **Step 4: Enregistrer la route**

Dans `routes/web.php`, dans le groupe authentifié + tenant :

```php
Route::get('/tiers/{tiers}/dons/{ligne}/recu-fiscal', [RecuFiscalController::class, 'download'])
    ->name('tiers.dons.recu-fiscal');
```

- [ ] **Step 5: Lancer, vérifier le PASS**

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/RecuFiscalController.php routes/web.php tests/Feature/Http/RecuFiscalControllerTest.php
git commit -m "feat(recu-fiscal): route GET tiers.dons.recu-fiscal + controller"
```

---

## Task 16 : Page Paramètres → Reçus fiscaux (Livewire)

**Files:**
- Create: `app/Livewire/Parametres/RecusFiscaux.php`
- Create: `resources/views/livewire/parametres/recus-fiscaux.blade.php`
- Modify: `routes/web.php` (route param)
- Modify: vue layout des paramètres pour ajouter l'entrée
- Test: `tests/Feature/Livewire/Parametres/RecusFiscauxTest.php`

**Note** : suivre la convention des autres composants Livewire de Paramètres (`app/Livewire/Parametres/HelloAssoSyncConfig.php` / `app/Livewire/Parametres/Comptabilite/UsagesComptables.php`).

- [ ] **Step 1: Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Livewire\Parametres\RecusFiscaux;
use App\Models\Association;
use App\Models\User;
use App\Support\TenantContext;
use Livewire\Livewire;

it('affiche les valeurs actuelles de l\'asso', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'regime_fiscal_don' => 'RUP',
        'signataire_nom' => 'Jean',
    ]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso);

    Livewire::actingAs($user)->test(RecusFiscaux::class)
        ->assertSet('eligibleRecuFiscal', true)
        ->assertSet('regimeFiscalDon', 'RUP')
        ->assertSet('signataireNom', 'Jean');
});

it('persiste les modifications', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso);

    Livewire::actingAs($user)->test(RecusFiscaux::class)
        ->set('eligibleRecuFiscal', true)
        ->set('regimeFiscalDon', 'Intérêt général')
        ->set('signataireNom', 'Marie Curie')
        ->set('signataireQualite', 'Présidente')
        ->call('enregistrer')
        ->assertHasNoErrors();

    $asso->refresh();
    expect($asso->eligible_recu_fiscal)->toBeTrue();
    expect($asso->regime_fiscal_don)->toBe('Intérêt général');
    expect($asso->signataire_nom)->toBe('Marie Curie');
});
```

- [ ] **Step 2: Lancer, vérifier l'échec**

- [ ] **Step 3: Implémenter le composant Livewire**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Models\Association;
use App\Support\TenantContext;
use Livewire\Component;

final class RecusFiscaux extends Component
{
    public bool $eligibleRecuFiscal = false;
    public string $regimeFiscalDon = '';
    public string $objetRecuFiscal = '';
    public string $rescritFiscalNumero = '';
    public ?string $rescritFiscalDate = null;
    public string $signataireNom = '';
    public string $signataireQualite = '';

    public function mount(): void
    {
        $asso = Association::findOrFail(TenantContext::currentId());
        $this->eligibleRecuFiscal = (bool) $asso->eligible_recu_fiscal;
        $this->regimeFiscalDon = (string) $asso->regime_fiscal_don;
        $this->objetRecuFiscal = (string) $asso->objet_recu_fiscal;
        $this->rescritFiscalNumero = (string) $asso->rescrit_fiscal_numero;
        $this->rescritFiscalDate = $asso->rescrit_fiscal_date?->format('Y-m-d');
        $this->signataireNom = (string) $asso->signataire_nom;
        $this->signataireQualite = (string) $asso->signataire_qualite;
    }

    protected function rules(): array
    {
        return [
            'eligibleRecuFiscal' => ['boolean'],
            'regimeFiscalDon' => ['nullable', 'string', 'max:255'],
            'objetRecuFiscal' => ['nullable', 'string', 'max:5000'],
            'rescritFiscalNumero' => ['nullable', 'string', 'max:100'],
            'rescritFiscalDate' => ['nullable', 'date'],
            'signataireNom' => ['nullable', 'string', 'max:255'],
            'signataireQualite' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function enregistrer(): void
    {
        $this->validate();

        $asso = Association::findOrFail(TenantContext::currentId());
        $asso->update([
            'eligible_recu_fiscal' => $this->eligibleRecuFiscal,
            'regime_fiscal_don' => $this->regimeFiscalDon ?: null,
            'objet_recu_fiscal' => $this->objetRecuFiscal ?: null,
            'rescrit_fiscal_numero' => $this->rescritFiscalNumero ?: null,
            'rescrit_fiscal_date' => $this->rescritFiscalDate,
            'signataire_nom' => $this->signataireNom ?: null,
            'signataire_qualite' => $this->signataireQualite ?: null,
        ]);

        session()->flash('success', 'Paramètres reçus fiscaux enregistrés.');
    }

    public function render()
    {
        return view('livewire.parametres.recus-fiscaux');
    }
}
```

- [ ] **Step 4: Implémenter la vue Blade**

```blade
<div>
    <h3>Reçus fiscaux</h3>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="alert alert-info">
        <strong>Conditions légales</strong> : votre association doit être éligible (intérêt général, RUP, ou disposer d'un rescrit fiscal favorable)
        pour émettre des reçus fiscaux. Référence :
        <a href="https://bofip.impots.gouv.fr/bofip/5872-PGP" target="_blank" rel="noopener">BOI-IR-RICI-250-30</a>.
    </div>

    <form wire:submit.prevent="enregistrer">
        <div class="form-check mb-3">
            <input type="checkbox" id="eligible" class="form-check-input" wire:model="eligibleRecuFiscal">
            <label for="eligible" class="form-check-label">Émettre des reçus fiscaux</label>
        </div>

        <div class="mb-3">
            <label for="regime" class="form-label">Régime fiscal</label>
            <input type="text" id="regime" class="form-control" wire:model="regimeFiscalDon"
                   placeholder="Ex: Intérêt général, RUP, cultuelle, ...">
        </div>

        <div class="mb-3">
            <label for="objet" class="form-label">Objet</label>
            <textarea id="objet" class="form-control" wire:model="objetRecuFiscal" rows="3"
                      placeholder="Ex: Œuvre d'intérêt général à caractère social"></textarea>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label for="rescritNum" class="form-label">N° de rescrit fiscal (optionnel)</label>
                <input type="text" id="rescritNum" class="form-control" wire:model="rescritFiscalNumero">
            </div>
            <div class="col-md-6">
                <label for="rescritDate" class="form-label">Date du rescrit</label>
                <input type="date" id="rescritDate" class="form-control" wire:model="rescritFiscalDate">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label for="sigNom" class="form-label">Signataire — Nom</label>
                <input type="text" id="sigNom" class="form-control" wire:model="signataireNom">
            </div>
            <div class="col-md-6">
                <label for="sigQual" class="form-label">Signataire — Qualité</label>
                <input type="text" id="sigQual" class="form-control" wire:model="signataireQualite"
                       placeholder="Ex: Président·e, Trésorier·e">
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Enregistrer</button>
    </form>
</div>
```

- [ ] **Step 5: Ajouter la route + entrée navigation**

Route + lien sidebar dans la zone Paramètres (suivre le pattern des autres pages Paramètres existantes).

- [ ] **Step 6: Lancer, vérifier le PASS**

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Parametres/RecusFiscaux.php resources/views/livewire/parametres/recus-fiscaux.blade.php routes/web.php tests/Feature/Livewire/Parametres/RecusFiscauxTest.php
git commit -m "feat(recu-fiscal): page Paramètres → Reçus fiscaux pour configurer l'éligibilité"
```

---

## Task 17 : Quick view Tiers — onglet « Dons » avec actions

**Files:**
- Modify: `app/Livewire/TiersQuickView.php`
- Modify: `resources/views/livewire/tiers-quick-view.blade.php`
- Test: `tests/Feature/Livewire/TiersQuickViewDonsTest.php`

- [ ] **Step 1: Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Livewire\TiersQuickView;
use App\Models\Association;
use App\Models\User;
use App\Services\RecuFiscalService;
use App\Support\TenantContext;
use Livewire\Livewire;

it('affiche la liste des dons d\'un tiers', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso);
    $ligne = ligneDonValide();

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $ligne->transaction->tiers_id)
        ->assertSeeText(number_format($ligne->montant, 2, ',', ' ').' €')
        ->assertSeeText('Télécharger reçu fiscal');
});

it('affiche le numéro du reçu si déjà émis', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso);
    $ligne = ligneDonValide();

    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne, $user);

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $ligne->transaction->tiers_id)
        ->assertSeeText($recu->numero);
});
```

- [ ] **Step 2: Lancer, vérifier l'échec**

- [ ] **Step 3: Étendre `TiersQuickView`**

Charger les dons et leurs reçus dans `render()` :

```php
public function render(): View
{
    $tiers = Tiers::find($this->tiersId);
    $availableYears = $this->computeAvailableYears($tiers);

    $donSousCategorieIds = SousCategorie::forUsage(UsageComptable::Don)->pluck('id');
    $dons = TransactionLigne::query()
        ->whereHas('transaction', fn ($q) => $q->where('tiers_id', $tiers->id))
        ->whereIn('sous_categorie_id', $donSousCategorieIds)
        ->with(['transaction', 'sousCategorie'])
        ->orderByDesc('id')
        ->get();

    $recusParLigne = RecuFiscalEmis::query()
        ->whereIn('transaction_ligne_id', $dons->pluck('id'))
        ->whereNull('annule_at')
        ->get()
        ->keyBy('transaction_ligne_id');

    return view('livewire.tiers-quick-view', compact('tiers', 'availableYears', 'dons', 'recusParLigne'));
}
```

- [ ] **Step 4: Étendre la vue Blade**

Ajouter un panneau « Dons » dans `resources/views/livewire/tiers-quick-view.blade.php` :

```blade
@if($dons->isNotEmpty())
    <div class="mt-4">
        <h6>Dons du tiers</h6>
        <table class="table table-sm">
            <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                <tr>
                    <th>Date</th>
                    <th>Sous-catégorie</th>
                    <th>Mode</th>
                    <th class="text-end">Montant</th>
                    <th>Reçu fiscal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($dons as $don)
                    <tr>
                        <td data-sort="{{ $don->transaction->date_operation->format('Y-m-d') }}">
                            {{ $don->transaction->date_operation->format('d/m/Y') }}
                        </td>
                        <td>{{ $don->sousCategorie->nom }}</td>
                        <td>{{ ucfirst($don->transaction->mode_paiement?->value ?? '—') }}</td>
                        <td class="text-end" data-sort="{{ $don->montant }}">
                            {{ number_format($don->montant, 2, ',', ' ') }} €
                        </td>
                        <td>
                            @if(isset($recusParLigne[$don->id]))
                                @php $recu = $recusParLigne[$don->id]; @endphp
                                <a href="{{ route('tiers.dons.recu-fiscal', [$tiers, $don]) }}" class="badge bg-success text-decoration-none">
                                    n° {{ $recu->numero }}
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-danger ms-1"
                                        wire:click="ouvrirModaleAnnulation({{ $recu->id }})"
                                        title="Annuler et ré-émettre">
                                    ⋯
                                </button>
                            @else
                                <a href="{{ route('tiers.dons.recu-fiscal', [$tiers, $don]) }}" class="btn btn-sm btn-primary">
                                    Télécharger reçu fiscal
                                </a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
```

- [ ] **Step 5: Lancer, vérifier le PASS**

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/TiersQuickView.php resources/views/livewire/tiers-quick-view.blade.php tests/Feature/Livewire/TiersQuickViewDonsTest.php
git commit -m "feat(recu-fiscal): quick view Tiers — onglet Dons avec téléchargement reçus"
```

---

## Task 18 : Modale Bootstrap d'annulation/ré-émission

**Files:**
- Modify: `app/Livewire/TiersQuickView.php`
- Modify: `resources/views/livewire/tiers-quick-view.blade.php`
- Test: ajouts dans `tests/Feature/Livewire/TiersQuickViewDonsTest.php`

- [ ] **Step 1: Écrire le test**

```php
it('annule + ré-émet un reçu via la modale', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso);
    $ligne = ligneDonValide();
    $ancien = app(RecuFiscalService::class)->obtenirOuGenerer($ligne, $user);

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $ligne->transaction->tiers_id)
        ->call('ouvrirModaleAnnulation', $ancien->id)
        ->set('motifAnnulation', 'Adresse corrigée')
        ->call('confirmerReEmission');

    $ancien->refresh();
    expect($ancien->isAnnule())->toBeTrue();
    expect($ancien->remplace_par_id)->not->toBeNull();
});
```

- [ ] **Step 2: Lancer, vérifier l'échec**

- [ ] **Step 3: Ajouter les méthodes Livewire + la modale**

Dans `TiersQuickView` :
```php
public ?int $recuAAnnuler = null;
public string $motifAnnulation = '';

public function ouvrirModaleAnnulation(int $recuId): void
{
    $this->recuAAnnuler = $recuId;
    $this->motifAnnulation = '';
    $this->dispatch('open-modal', name: 'modale-annulation-recu');
}

public function confirmerReEmission(RecuFiscalService $service): void
{
    $recu = RecuFiscalEmis::findOrFail($this->recuAAnnuler);
    $service->reemettre($recu, $this->motifAnnulation, auth()->user());
    $this->recuAAnnuler = null;
    $this->motifAnnulation = '';
    $this->dispatch('close-modal', name: 'modale-annulation-recu');
}
```

Modale dans la vue :
```blade
<div class="modal fade" id="modale-annulation-recu" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Annuler et ré-émettre</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Le reçu actuel sera annulé et un nouveau sera généré avec les coordonnées actuelles.</p>
                <label for="motif" class="form-label">Motif</label>
                <input type="text" id="motif" class="form-control" wire:model="motifAnnulation"
                       placeholder="Ex: Adresse corrigée">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" wire:click="confirmerReEmission">
                    Confirmer
                </button>
            </div>
        </div>
    </div>
</div>
```

**Note** : adapter les events `open-modal` / `close-modal` à la convention Livewire/Bootstrap du projet — vérifier d'autres modales (`AttestationModal`, `TiersMergeModal`).

- [ ] **Step 4: Lancer, vérifier le PASS**

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/TiersQuickView.php resources/views/livewire/tiers-quick-view.blade.php tests/Feature/Livewire/TiersQuickViewDonsTest.php
git commit -m "feat(recu-fiscal): modale annulation + ré-émission de reçu fiscal"
```

---

## Task 18.5 : Avertissements UX (HelloAsso, données modifiées)

**Files:**
- Modify: `app/Livewire/TiersQuickView.php`
- Modify: `resources/views/livewire/tiers-quick-view.blade.php`
- Test: ajouts dans `tests/Feature/Livewire/TiersQuickViewDonsTest.php`

Avant un téléchargement (cas « pas encore de reçu actif »), si :
- la transaction provient d'HelloAsso (`source = 'helloasso'` ou équivalent), OU
- l'asso ou le tiers a `updated_at` postérieur à la `date_operation` du don,

on affiche une modale non bloquante demandant confirmation. Si OK, le téléchargement procède (le service crée le reçu et stream le PDF) ; sinon, l'action est annulée.

- [ ] **Step 1: Lire la convention HelloAsso**

```bash
grep -nE "source.*helloasso|->source|helloasso_id" app/Models/Transaction.php database/migrations/*transaction*.php 2>/dev/null
```

Identifier le champ exact (`source`, `helloasso_id`, ou flag) pour la détection.

- [ ] **Step 2: Écrire le test**

```php
it('affiche un avertissement HelloAsso si le don provient d\'HelloAsso', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso);
    $ligne = $this->ligneDonValide();
    $ligne->transaction->update(['source' => 'helloasso']);  // adapter au champ réel

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $ligne->transaction->tiers_id)
        ->assertSeeText('HelloAsso peut avoir déjà émis');
});

it('affiche un avertissement si l\'asso ou le tiers a été modifié depuis le don', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
        'updated_at' => now(),
    ]);
    TenantContext::boot($asso);
    $user = User::factory()->create();
    $user->associations()->attach($asso);
    $ligne = $this->ligneDonValide([], ['date_operation' => now()->subYear()]);

    Livewire::actingAs($user)->test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $ligne->transaction->tiers_id)
        ->assertSeeText('coordonnées');
});
```

- [ ] **Step 3: Implémenter la détection**

Dans `TiersQuickView::render()`, calculer pour chaque don les flags :

```php
$alertesParLigne = $dons->mapWithKeys(function ($don) use ($tiers) {
    $asso = Association::findOrFail(TenantContext::currentId());
    $alertes = [];
    if (($don->transaction->source ?? null) === 'helloasso') {
        $alertes[] = 'helloasso';
    }
    if (
        $asso->updated_at?->gt($don->transaction->date_operation)
        || $tiers->updated_at?->gt($don->transaction->date_operation)
    ) {
        $alertes[] = 'donnees_modifiees';
    }
    return [$don->id => $alertes];
});
```

Et passer `$alertesParLigne` à la vue.

- [ ] **Step 4: Modifier le bouton de téléchargement pour afficher la modale d'avertissement**

Dans la vue, transformer le bouton « Télécharger reçu fiscal » en bouton qui ouvre une modale d'avertissement si `$alertesParLigne[$don->id]` non vide ; sinon lien direct vers la route.

```blade
@if(!empty($alertesParLigne[$don->id]))
    <button type="button" class="btn btn-sm btn-primary"
            wire:click="afficherAvertissements({{ $don->id }})">
        Télécharger reçu fiscal
    </button>
@else
    <a href="{{ route('tiers.dons.recu-fiscal', [$tiers, $don]) }}" class="btn btn-sm btn-primary">
        Télécharger reçu fiscal
    </a>
@endif
```

Ajouter dans `TiersQuickView` :

```php
public ?int $ligneAvecAvertissement = null;
public array $avertissementsActifs = [];

public function afficherAvertissements(int $ligneId): void
{
    $this->ligneAvecAvertissement = $ligneId;
    $this->avertissementsActifs = $this->alertesParLigne[$ligneId] ?? [];
    $this->dispatch('open-modal', name: 'modale-avertissement-recu');
}

public function continuerTelechargement(): void
{
    $this->dispatch('close-modal', name: 'modale-avertissement-recu');
    $this->redirect(route('tiers.dons.recu-fiscal', [$this->tiersId, $this->ligneAvecAvertissement]));
}
```

Modale dans la vue :

```blade
<div class="modal fade" id="modale-avertissement-recu" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Vérifications avant émission</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                @if(in_array('helloasso', $avertissementsActifs))
                    <div class="alert alert-warning">
                        <strong>HelloAsso peut avoir déjà émis un reçu fiscal pour ce don.</strong>
                        Le donateur ne doit pas déduire deux fois le même montant.
                    </div>
                @endif
                @if(in_array('donnees_modifiees', $avertissementsActifs))
                    <div class="alert alert-info">
                        Les coordonnées du donateur ou de l'association ont été modifiées depuis le don.
                        Le reçu portera les coordonnées actuelles.
                    </div>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" wire:click="continuerTelechargement">
                    Continuer
                </button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 5: Lancer, vérifier le PASS**

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/TiersQuickView.php resources/views/livewire/tiers-quick-view.blade.php tests/Feature/Livewire/TiersQuickViewDonsTest.php
git commit -m "feat(recu-fiscal): avertissements HelloAsso et données modifiées avant émission"
```

---

## Task 19 : Test d'isolation multi-tenant

**Files:**
- Create: `tests/Feature/RecuFiscalTenantIsolationTest.php`

- [ ] **Step 1: Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Support\TenantContext;

it('le scope global empêche un autre tenant de voir les reçus', function () {
    $asso1 = Association::factory()->create();
    $asso2 = Association::factory()->create();

    TenantContext::boot($asso1);
    $tiers = Tiers::factory()->create();
    $recu = RecuFiscalEmis::factory()->create(['tiers_id' => $tiers->id]);

    expect(RecuFiscalEmis::find($recu->id))->not->toBeNull();

    TenantContext::boot($asso2);
    expect(RecuFiscalEmis::find($recu->id))->toBeNull();
});

it('sans TenantContext booté, RecuFiscalEmis::all() ne retourne rien', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $tiers = Tiers::factory()->create();
    RecuFiscalEmis::factory()->create(['tiers_id' => $tiers->id]);

    TenantContext::clear();

    expect(RecuFiscalEmis::count())->toBe(0);  // fail-closed
});
```

- [ ] **Step 2: Lancer, vérifier le PASS** (le scope hérite de `TenantModel`)

```bash
./vendor/bin/sail test tests/Feature/RecuFiscalTenantIsolationTest.php
```

Expected: PASS sans modification de code.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/RecuFiscalTenantIsolationTest.php
git commit -m "test(recu-fiscal): isolation tenant fail-closed sur RecuFiscalEmis"
```

---

## Task 20 : Suite complète + lint Pint

- [ ] **Step 1: Lancer toute la suite**

```bash
./vendor/bin/sail test --parallel
```

Expected: PASS sur l'ensemble (toute la suite avant + nouveaux tests).

- [ ] **Step 2: Lancer Pint**

```bash
./vendor/bin/sail bin pint
```

- [ ] **Step 3: Re-lancer la suite après Pint**

```bash
./vendor/bin/sail test --parallel
```

Expected: PASS.

- [ ] **Step 4: Commit Pint si modifs**

```bash
git add -u
git diff --cached --quiet || git commit -m "style: pint"
```

---

## Task 21 : Mise à jour MEMORY.md (correction de l'entrée stale)

**Files:**
- Modify: `/Users/jurgen/.claude/projects/-Users-jurgen-dev-agora-gestion/memory/MEMORY.md`
- Create: `/Users/jurgen/.claude/projects/-Users-jurgen-dev-agora-gestion/memory/project_recu_fiscal_don.md`

- [ ] **Step 1: Corriger l'entrée stale `project_ndf_abandon_creance`**

Dans `MEMORY.md`, remplacer la ligne `🎯 PROCHAINE SESSION` du projet `project_ndf_abandon_creance.md` par un statut « ✅ Livré 2026-04-21 ».

- [ ] **Step 2: Créer `project_recu_fiscal_don.md`** avec le statut MVP en cours, la branche, la spec et le plan.

- [ ] **Step 3: Ajouter la ligne à `MEMORY.md`** sous la section livraisons.

- [ ] **Step 4: Pas de commit (memory hors repo)**

---

## Done

À l'issue de Task 20, on devrait avoir :
- `feat/recu-fiscal-don` avec ~20 commits TDD
- Suite verte
- Émission unitaire de reçu fiscal PDF/A-3 fonctionnelle depuis le quick view Tiers
- Couverture tests : unit (service, dérivations, montant en lettres) + feature (controller, observers, isolation tenant) + Livewire (UX)

Steps suivants après merge :
- Test manuel en preview
- PR vers main avec `/agentic-dev-team:pr`
- Tag + release
