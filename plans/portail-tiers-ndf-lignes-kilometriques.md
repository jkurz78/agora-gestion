# Plan: Portail Tiers NDF — Lignes de frais kilométriques

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter aux NDF du portail Tiers un second type de ligne "kilométrique" (CV + km + barème → montant auto-calculé, carte grise PJ obligatoire), avec sous-catégorie résolue via un nouveau flag `pour_frais_kilometriques` et description auto-générée poussée dans `transaction_lignes.notes` au moment de la validation back-office.

**Architecture:** Table `notes_de_frais_lignes` étendue avec `type` enum et `metadata` JSON + strategy pattern (`LigneTypeInterface`) pour préparer les futurs types normés (repas, hébergement). Aucune nouvelle table, aucun nouveau menu Paramètres — réutilisation des flags SousCategorie (pattern existant `pour_dons`/`pour_cotisations`/...).

**Tech Stack:** Laravel 11, Livewire 4, Pest 3, MySQL 8, Bootstrap 5.

**Branch:** `feat/portail-tiers-slice1-auth-otp` (même branche que Slices 1-3, alignement programme NDF).

**Spec:** [docs/specs/2026-04-20-portail-tiers-ndf-lignes-kilometriques-design.md](../docs/specs/2026-04-20-portail-tiers-ndf-lignes-kilometriques-design.md)

## Conventions de test

- Tests Pest dans `tests/Feature/...` et `tests/Unit/...`.
- Tests feature touchant les données tenant étendent `Tests\Support\TenantTestCase` (ou laissent `tests/Pest.php` gérer via `pest('*')`).
- Locale fr, factories existantes.
- Lancer les tests ciblés : `./vendor/bin/sail test --filter=<NomDuTest>`.
- Lancer la suite complète au besoin : `./vendor/bin/sail test`.
- `./vendor/bin/sail artisan migrate:fresh --seed --env=testing` si schéma touché.

---

## Task 1 — Migrations : `type` + `metadata` + flag sous-cat

**Files:**
- Create: `database/migrations/2026_04_20_100000_add_type_and_metadata_to_notes_de_frais_lignes_table.php`
- Create: `database/migrations/2026_04_20_100001_add_pour_frais_kilometriques_to_sous_categories_table.php`

- [ ] **Step 1: Créer la migration notes_de_frais_lignes**

`database/migrations/2026_04_20_100000_add_type_and_metadata_to_notes_de_frais_lignes_table.php` :

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
        Schema::table('notes_de_frais_lignes', function (Blueprint $table) {
            $table->string('type', 20)->default('standard')->after('seance');
            $table->json('metadata')->nullable()->after('piece_jointe_path');
        });
    }

    public function down(): void
    {
        Schema::table('notes_de_frais_lignes', function (Blueprint $table) {
            $table->dropColumn(['type', 'metadata']);
        });
    }
};
```

- [ ] **Step 2: Créer la migration sous_categories**

`database/migrations/2026_04_20_100001_add_pour_frais_kilometriques_to_sous_categories_table.php` :

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
        Schema::table('sous_categories', function (Blueprint $table) {
            $table->boolean('pour_frais_kilometriques')->default(false)->after('pour_inscriptions');
        });
    }

    public function down(): void
    {
        Schema::table('sous_categories', function (Blueprint $table) {
            $table->dropColumn('pour_frais_kilometriques');
        });
    }
};
```

- [ ] **Step 3: Jouer les migrations**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate:fresh --seed --env=testing
```

Expected: deux migrations appliquées, pas d'erreur.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_20_100000_add_type_and_metadata_to_notes_de_frais_lignes_table.php \
        database/migrations/2026_04_20_100001_add_pour_frais_kilometriques_to_sous_categories_table.php
git commit -m "feat(ndf): migrations type+metadata lignes et flag pour_frais_kilometriques"
```

---

## Task 2 — Enum `NoteDeFraisLigneType` + model casts

**Files:**
- Create: `app/Enums/NoteDeFraisLigneType.php`
- Modify: `app/Models/NoteDeFraisLigne.php`
- Modify: `app/Models/SousCategorie.php`
- Test: `tests/Unit/Enums/NoteDeFraisLigneTypeTest.php`

- [ ] **Step 1: Écrire le test enum**

`tests/Unit/Enums/NoteDeFraisLigneTypeTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;

it('expose les deux cas standard et kilometrique', function () {
    expect(NoteDeFraisLigneType::Standard->value)->toBe('standard');
    expect(NoteDeFraisLigneType::Kilometrique->value)->toBe('kilometrique');
    expect(NoteDeFraisLigneType::cases())->toHaveCount(2);
});
```

- [ ] **Step 2: Faire échouer**

Run: `./vendor/bin/sail test --filter=NoteDeFraisLigneTypeTest`
Expected: FAIL — classe absente.

- [ ] **Step 3: Créer l'enum**

`app/Enums/NoteDeFraisLigneType.php` :

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum NoteDeFraisLigneType: string
{
    case Standard = 'standard';
    case Kilometrique = 'kilometrique';
}
```

- [ ] **Step 4: Passer au vert**

Run: `./vendor/bin/sail test --filter=NoteDeFraisLigneTypeTest`
Expected: PASS.

- [ ] **Step 5: Ajouter les casts sur NoteDeFraisLigne**

Modifier `app/Models/NoteDeFraisLigne.php` :

- Ajouter `use App\Enums\NoteDeFraisLigneType;` en haut.
- Ajouter `'type'` et `'metadata'` à `$fillable` (après `piece_jointe_path`).
- Dans `casts()`, ajouter :

```php
'type' => NoteDeFraisLigneType::class,
'metadata' => 'array',
```

- [ ] **Step 6: Ajouter le flag sur SousCategorie**

Modifier `app/Models/SousCategorie.php` :

- Ajouter `'pour_frais_kilometriques'` à `$fillable` (après `pour_inscriptions`).
- Dans `casts()`, ajouter `'pour_frais_kilometriques' => 'boolean',`.

- [ ] **Step 7: Test rapide cast NDF ligne**

Ajouter à `tests/Unit/Enums/NoteDeFraisLigneTypeTest.php` :

```php
it('cast type enum et metadata array sur NoteDeFraisLigne', function () {
    $ligne = new \App\Models\NoteDeFraisLigne();
    $ligne->type = NoteDeFraisLigneType::Kilometrique;
    $ligne->metadata = ['cv_fiscaux' => 5, 'distance_km' => 420, 'bareme_eur_km' => 0.636];

    expect($ligne->type)->toBe(NoteDeFraisLigneType::Kilometrique);
    expect($ligne->metadata)->toBe(['cv_fiscaux' => 5, 'distance_km' => 420, 'bareme_eur_km' => 0.636]);
});
```

Run: `./vendor/bin/sail test --filter=NoteDeFraisLigneTypeTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Enums/NoteDeFraisLigneType.php \
        app/Models/NoteDeFraisLigne.php \
        app/Models/SousCategorie.php \
        tests/Unit/Enums/NoteDeFraisLigneTypeTest.php
git commit -m "feat(ndf): enum NoteDeFraisLigneType + casts models + flag sous-categorie"
```

---

## Task 3 — Interface `LigneTypeInterface` + `StandardLigneType`

**Files:**
- Create: `app/Services/NoteDeFrais/LigneTypes/LigneTypeInterface.php`
- Create: `app/Services/NoteDeFrais/LigneTypes/StandardLigneType.php`
- Test: `tests/Unit/Services/NoteDeFrais/LigneTypes/StandardLigneTypeTest.php`

- [ ] **Step 1: Écrire le test StandardLigneType**

`tests/Unit/Services/NoteDeFrais/LigneTypes/StandardLigneTypeTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Services\NoteDeFrais\LigneTypes\StandardLigneType;

beforeEach(function () {
    $this->strategy = new StandardLigneType();
});

it('retourne la clé Standard', function () {
    expect($this->strategy->key())->toBe(NoteDeFraisLigneType::Standard);
});

it('calcule le montant depuis la valeur saisie', function () {
    $montant = $this->strategy->computeMontant(['montant' => 42.5]);
    expect($montant)->toBe(42.5);
});

it('accepte montant sous forme de chaine avec virgule', function () {
    $montant = $this->strategy->computeMontant(['montant' => '42,5']);
    expect($montant)->toBe(42.5);
});

it('metadata est un tableau vide', function () {
    expect($this->strategy->metadata(['montant' => 42.5]))->toBe([]);
});

it('renderDescription renvoie chaine vide', function () {
    expect($this->strategy->renderDescription([]))->toBe('');
});

it('resolveSousCategorieId renvoie l\'id saisi inchange', function () {
    expect($this->strategy->resolveSousCategorieId(7))->toBe(7);
    expect($this->strategy->resolveSousCategorieId(null))->toBeNull();
});

it('validate ne leve pas pour un draft minimal valide', function () {
    $this->strategy->validate(['montant' => 10]);
    expect(true)->toBeTrue(); // pas d'exception
});
```

- [ ] **Step 2: Faire échouer**

Run: `./vendor/bin/sail test --filter=StandardLigneTypeTest`
Expected: FAIL — classes absentes.

- [ ] **Step 3: Créer l'interface**

`app/Services/NoteDeFrais/LigneTypes/LigneTypeInterface.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\NoteDeFrais\LigneTypes;

use App\Enums\NoteDeFraisLigneType;

interface LigneTypeInterface
{
    public function key(): NoteDeFraisLigneType;

    /**
     * Lance ValidationException si le draft est invalide pour ce type.
     *
     * @param array<string,mixed> $draft
     */
    public function validate(array $draft): void;

    /**
     * Calcule le montant server-side à stocker (jamais pris en confiance du client pour les types calculés).
     *
     * @param array<string,mixed> $draft
     */
    public function computeMontant(array $draft): float;

    /**
     * Payload JSON stocké sur la ligne (metadata).
     *
     * @param array<string,mixed> $draft
     * @return array<string,mixed>
     */
    public function metadata(array $draft): array;

    /**
     * Description humaine utilisée côté back-office (transaction_lignes.notes).
     *
     * @param array<string,mixed> $metadata
     */
    public function renderDescription(array $metadata): string;

    /**
     * Résolution de la sous-catégorie. Peut forcer une valeur côté stratégie (km) ou conserver celle saisie (standard).
     */
    public function resolveSousCategorieId(?int $requestedId): ?int;
}
```

- [ ] **Step 4: Créer StandardLigneType**

`app/Services/NoteDeFrais/LigneTypes/StandardLigneType.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\NoteDeFrais\LigneTypes;

use App\Enums\NoteDeFraisLigneType;

final class StandardLigneType implements LigneTypeInterface
{
    public function key(): NoteDeFraisLigneType
    {
        return NoteDeFraisLigneType::Standard;
    }

    public function validate(array $draft): void
    {
        // Pas de validation supplémentaire : déléguée au service (submit + saveDraft).
    }

    public function computeMontant(array $draft): float
    {
        $raw = $draft['montant'] ?? 0;
        if (is_string($raw)) {
            $raw = str_replace(',', '.', $raw);
        }

        return (float) $raw;
    }

    public function metadata(array $draft): array
    {
        return [];
    }

    public function renderDescription(array $metadata): string
    {
        return '';
    }

    public function resolveSousCategorieId(?int $requestedId): ?int
    {
        return $requestedId;
    }
}
```

- [ ] **Step 5: Passer au vert**

Run: `./vendor/bin/sail test --filter=StandardLigneTypeTest`
Expected: PASS (tous les it).

- [ ] **Step 6: Commit**

```bash
git add app/Services/NoteDeFrais/LigneTypes/LigneTypeInterface.php \
        app/Services/NoteDeFrais/LigneTypes/StandardLigneType.php \
        tests/Unit/Services/NoteDeFrais/LigneTypes/StandardLigneTypeTest.php
git commit -m "feat(ndf): interface LigneTypeInterface + StandardLigneType"
```

---

## Task 4 — `KilometriqueLigneType`

**Files:**
- Create: `app/Services/NoteDeFrais/LigneTypes/KilometriqueLigneType.php`
- Test: `tests/Unit/Services/NoteDeFrais/LigneTypes/KilometriqueLigneTypeTest.php`

- [ ] **Step 1: Écrire les tests**

`tests/Unit/Services/NoteDeFrais/LigneTypes/KilometriqueLigneTypeTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Services\NoteDeFrais\LigneTypes\KilometriqueLigneType;
use App\Tenant\TenantContext;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->strategy = new KilometriqueLigneType();
});

it('retourne la clé Kilometrique', function () {
    expect($this->strategy->key())->toBe(NoteDeFraisLigneType::Kilometrique);
});

it('calcule montant = distance x bareme, arrondi 2 décimales', function () {
    $montant = $this->strategy->computeMontant([
        'distance_km' => 420,
        'bareme_eur_km' => 0.636,
    ]);
    expect($montant)->toBe(267.12);
});

it('arrondit half-up le montant', function () {
    // 0.15 * 10 = 1.5 — l'arrondi doit rester 1.5
    $montant = $this->strategy->computeMontant([
        'distance_km' => 10,
        'bareme_eur_km' => 0.15,
    ]);
    expect($montant)->toBe(1.5);
});

it('accepte les virgules françaises dans les champs', function () {
    $montant = $this->strategy->computeMontant([
        'distance_km' => '420,5',
        'bareme_eur_km' => '0,636',
    ]);
    expect($montant)->toBe(round(420.5 * 0.636, 2));
});

it('metadata contient cv_fiscaux, distance_km, bareme_eur_km', function () {
    $metadata = $this->strategy->metadata([
        'cv_fiscaux' => '5',
        'distance_km' => '420',
        'bareme_eur_km' => '0,636',
    ]);
    expect($metadata)->toBe([
        'cv_fiscaux' => 5,
        'distance_km' => 420.0,
        'bareme_eur_km' => 0.636,
    ]);
});

it('renderDescription formate la phrase française', function () {
    $desc = $this->strategy->renderDescription([
        'cv_fiscaux' => 5,
        'distance_km' => 420,
        'bareme_eur_km' => 0.636,
    ]);
    expect($desc)->toBe('Déplacement de 420 km avec un véhicule 5 CV au barème de 0,636 €/km');
});

it('renderDescription retourne chaine vide si metadata vide', function () {
    expect($this->strategy->renderDescription([]))->toBe('');
});

it('validate lève si cv_fiscaux manquant ou invalide', function () {
    expect(fn () => $this->strategy->validate([
        'distance_km' => 100,
        'bareme_eur_km' => 0.5,
    ]))->toThrow(ValidationException::class);

    expect(fn () => $this->strategy->validate([
        'cv_fiscaux' => 0,
        'distance_km' => 100,
        'bareme_eur_km' => 0.5,
    ]))->toThrow(ValidationException::class);

    expect(fn () => $this->strategy->validate([
        'cv_fiscaux' => 100,
        'distance_km' => 100,
        'bareme_eur_km' => 0.5,
    ]))->toThrow(ValidationException::class);
});

it('validate lève si distance_km <= 0', function () {
    expect(fn () => $this->strategy->validate([
        'cv_fiscaux' => 5,
        'distance_km' => 0,
        'bareme_eur_km' => 0.5,
    ]))->toThrow(ValidationException::class);
});

it('validate lève si bareme_eur_km <= 0', function () {
    expect(fn () => $this->strategy->validate([
        'cv_fiscaux' => 5,
        'distance_km' => 100,
        'bareme_eur_km' => 0,
    ]))->toThrow(ValidationException::class);
});

it('validate passe pour un draft km complet valide', function () {
    $this->strategy->validate([
        'cv_fiscaux' => 5,
        'distance_km' => 420,
        'bareme_eur_km' => 0.636,
    ]);
    expect(true)->toBeTrue();
});

it('resolveSousCategorieId retourne null si aucune sous-cat flaggée', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    expect($this->strategy->resolveSousCategorieId(null))->toBeNull();
});

it('resolveSousCategorieId retourne l\'id unique si exactement une flaggée', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $cat = Categorie::factory()->create(['association_id' => $asso->id]);
    $sc = SousCategorie::create([
        'association_id' => $asso->id,
        'categorie_id' => $cat->id,
        'nom' => 'Déplacements',
        'pour_frais_kilometriques' => true,
    ]);

    expect($this->strategy->resolveSousCategorieId(null))->toBe($sc->id);
});

it('resolveSousCategorieId retourne null si plusieurs flaggées', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $cat = Categorie::factory()->create(['association_id' => $asso->id]);
    SousCategorie::create([
        'association_id' => $asso->id,
        'categorie_id' => $cat->id,
        'nom' => 'Déplacements bénévoles',
        'pour_frais_kilometriques' => true,
    ]);
    SousCategorie::create([
        'association_id' => $asso->id,
        'categorie_id' => $cat->id,
        'nom' => 'Déplacements salariés',
        'pour_frais_kilometriques' => true,
    ]);

    expect($this->strategy->resolveSousCategorieId(null))->toBeNull();
});
```

- [ ] **Step 2: Faire échouer**

Run: `./vendor/bin/sail test --filter=KilometriqueLigneTypeTest`
Expected: FAIL — classe absente.

- [ ] **Step 3: Implémenter KilometriqueLigneType**

`app/Services/NoteDeFrais/LigneTypes/KilometriqueLigneType.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\NoteDeFrais\LigneTypes;

use App\Enums\NoteDeFraisLigneType;
use App\Models\SousCategorie;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class KilometriqueLigneType implements LigneTypeInterface
{
    public function key(): NoteDeFraisLigneType
    {
        return NoteDeFraisLigneType::Kilometrique;
    }

    public function validate(array $draft): void
    {
        $normalized = [
            'cv_fiscaux' => $draft['cv_fiscaux'] ?? null,
            'distance_km' => $this->toFloat($draft['distance_km'] ?? null),
            'bareme_eur_km' => $this->toFloat($draft['bareme_eur_km'] ?? null),
        ];

        $validator = Validator::make(
            $normalized,
            [
                'cv_fiscaux' => ['required', 'integer', 'between:1,50'],
                'distance_km' => ['required', 'numeric', 'gt:0'],
                'bareme_eur_km' => ['required', 'numeric', 'gt:0'],
            ],
            [
                'cv_fiscaux.required' => 'La puissance fiscale est obligatoire.',
                'cv_fiscaux.integer' => 'La puissance fiscale doit être un entier.',
                'cv_fiscaux.between' => 'La puissance fiscale doit être comprise entre 1 et 50 CV.',
                'distance_km.required' => 'La distance est obligatoire.',
                'distance_km.numeric' => 'La distance doit être un nombre.',
                'distance_km.gt' => 'La distance doit être supérieure à zéro.',
                'bareme_eur_km.required' => 'Le barème est obligatoire.',
                'bareme_eur_km.numeric' => 'Le barème doit être un nombre.',
                'bareme_eur_km.gt' => 'Le barème doit être supérieur à zéro.',
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    public function computeMontant(array $draft): float
    {
        $distance = $this->toFloat($draft['distance_km'] ?? 0);
        $bareme = $this->toFloat($draft['bareme_eur_km'] ?? 0);

        return round($distance * $bareme, 2, PHP_ROUND_HALF_UP);
    }

    public function metadata(array $draft): array
    {
        return [
            'cv_fiscaux' => (int) ($draft['cv_fiscaux'] ?? 0),
            'distance_km' => $this->toFloat($draft['distance_km'] ?? 0),
            'bareme_eur_km' => $this->toFloat($draft['bareme_eur_km'] ?? 0),
        ];
    }

    public function renderDescription(array $metadata): string
    {
        if ($metadata === []) {
            return '';
        }

        $km = $this->formatNumber((float) ($metadata['distance_km'] ?? 0));
        $cv = (int) ($metadata['cv_fiscaux'] ?? 0);
        $bareme = $this->formatNumber((float) ($metadata['bareme_eur_km'] ?? 0), 3);

        return "Déplacement de {$km} km avec un véhicule {$cv} CV au barème de {$bareme} €/km";
    }

    public function resolveSousCategorieId(?int $requestedId): ?int
    {
        $flagged = SousCategorie::where('pour_frais_kilometriques', true)->pluck('id');

        if ($flagged->count() === 1) {
            return (int) $flagged->first();
        }

        return null;
    }

    private function toFloat(mixed $value): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        return (float) $value;
    }

    private function formatNumber(float $value, int $maxDecimals = 2): string
    {
        $formatted = rtrim(rtrim(number_format($value, $maxDecimals, ',', ''), '0'), ',');

        return $formatted === '' ? '0' : $formatted;
    }
}
```

- [ ] **Step 4: Passer au vert**

Run: `./vendor/bin/sail test --filter=KilometriqueLigneTypeTest`
Expected: PASS (tous les it).

- [ ] **Step 5: Commit**

```bash
git add app/Services/NoteDeFrais/LigneTypes/KilometriqueLigneType.php \
        tests/Unit/Services/NoteDeFrais/LigneTypes/KilometriqueLigneTypeTest.php
git commit -m "feat(ndf): KilometriqueLigneType — validate, computeMontant, metadata, renderDescription, resolveSousCategorie"
```

---

## Task 5 — `LigneTypeRegistry`

**Files:**
- Create: `app/Services/NoteDeFrais/LigneTypes/LigneTypeRegistry.php`
- Test: `tests/Unit/Services/NoteDeFrais/LigneTypes/LigneTypeRegistryTest.php`

- [ ] **Step 1: Écrire le test**

`tests/Unit/Services/NoteDeFrais/LigneTypes/LigneTypeRegistryTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Services\NoteDeFrais\LigneTypes\KilometriqueLigneType;
use App\Services\NoteDeFrais\LigneTypes\LigneTypeRegistry;
use App\Services\NoteDeFrais\LigneTypes\StandardLigneType;

it('résout Standard vers StandardLigneType', function () {
    $registry = new LigneTypeRegistry();
    expect($registry->for(NoteDeFraisLigneType::Standard))->toBeInstanceOf(StandardLigneType::class);
});

it('résout Kilometrique vers KilometriqueLigneType', function () {
    $registry = new LigneTypeRegistry();
    expect($registry->for(NoteDeFraisLigneType::Kilometrique))->toBeInstanceOf(KilometriqueLigneType::class);
});

it('est un singleton via le container', function () {
    $first = app(LigneTypeRegistry::class);
    $second = app(LigneTypeRegistry::class);
    expect($first)->toBe($second);
});
```

- [ ] **Step 2: Faire échouer**

Run: `./vendor/bin/sail test --filter=LigneTypeRegistryTest`
Expected: FAIL — classe absente.

- [ ] **Step 3: Implémenter le registre**

`app/Services/NoteDeFrais/LigneTypes/LigneTypeRegistry.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\NoteDeFrais\LigneTypes;

use App\Enums\NoteDeFraisLigneType;

final class LigneTypeRegistry
{
    /** @var array<string, LigneTypeInterface> */
    private array $cache = [];

    public function for(NoteDeFraisLigneType $type): LigneTypeInterface
    {
        if (! isset($this->cache[$type->value])) {
            $this->cache[$type->value] = match ($type) {
                NoteDeFraisLigneType::Standard => new StandardLigneType(),
                NoteDeFraisLigneType::Kilometrique => new KilometriqueLigneType(),
            };
        }

        return $this->cache[$type->value];
    }
}
```

- [ ] **Step 4: Enregistrer en singleton**

Modifier `app/Providers/AppServiceProvider.php` méthode `register()`, ajouter :

```php
$this->app->singleton(\App\Services\NoteDeFrais\LigneTypes\LigneTypeRegistry::class);
```

(Si la méthode `register()` est vide, y ajouter la ligne ; sinon simplement y insérer.)

- [ ] **Step 5: Passer au vert**

Run: `./vendor/bin/sail test --filter=LigneTypeRegistryTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/NoteDeFrais/LigneTypes/LigneTypeRegistry.php \
        app/Providers/AppServiceProvider.php \
        tests/Unit/Services/NoteDeFrais/LigneTypes/LigneTypeRegistryTest.php
git commit -m "feat(ndf): LigneTypeRegistry + binding singleton"
```

---

## Task 6 — `NoteDeFraisService` : intégration strategy

**Files:**
- Modify: `app/Services/Portail/NoteDeFrais/NoteDeFraisService.php`
- Test: `tests/Feature/Services/Portail/NoteDeFraisServiceKmTest.php`

- [ ] **Step 1: Écrire les tests**

`tests/Feature/Services/Portail/NoteDeFraisServiceKmTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\NoteDeFrais;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\Portail\NoteDeFrais\NoteDeFraisService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);

    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);

    $this->cat = Categorie::factory()->create(['association_id' => $this->asso->id]);
    $this->scKm = SousCategorie::create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->cat->id,
        'nom' => 'Déplacements',
        'pour_frais_kilometriques' => true,
    ]);

    $this->service = app(NoteDeFraisService::class);
});

it('sauvegarde une ligne kilometrique avec type, metadata et montant calculé server-side', function () {
    $ndf = $this->service->saveDraft($this->tiers, [
        'date' => '2026-04-20',
        'libelle' => 'NDF avril',
        'lignes' => [
            [
                'type' => 'kilometrique',
                'libelle' => 'Paris-Rennes AG',
                'montant' => 99999.99, // tampering client ignoré
                'cv_fiscaux' => 5,
                'distance_km' => 420,
                'bareme_eur_km' => 0.636,
                'sous_categorie_id' => null,
                'operation_id' => null,
                'seance' => null,
                'piece_jointe_path' => null,
            ],
        ],
    ]);

    $ligne = $ndf->lignes()->first();
    expect($ligne->type)->toBe(NoteDeFraisLigneType::Kilometrique);
    expect((float) $ligne->montant)->toBe(267.12);
    expect($ligne->metadata)->toBe([
        'cv_fiscaux' => 5,
        'distance_km' => 420.0,
        'bareme_eur_km' => 0.636,
    ]);
    // Sous-catégorie résolue automatiquement via le flag
    expect((int) $ligne->sous_categorie_id)->toBe((int) $this->scKm->id);
});

it('sauvegarde une ligne standard sans metadata', function () {
    $sc = SousCategorie::create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->cat->id,
        'nom' => 'Fournitures',
    ]);

    $ndf = $this->service->saveDraft($this->tiers, [
        'date' => '2026-04-20',
        'libelle' => 'NDF avril',
        'lignes' => [
            [
                'type' => 'standard',
                'libelle' => 'Stylos',
                'montant' => 12.50,
                'sous_categorie_id' => $sc->id,
                'operation_id' => null,
                'seance' => null,
                'piece_jointe_path' => null,
            ],
        ],
    ]);

    $ligne = $ndf->lignes()->first();
    expect($ligne->type)->toBe(NoteDeFraisLigneType::Standard);
    expect((float) $ligne->montant)->toBe(12.50);
    expect($ligne->metadata)->toBe([]);
    expect((int) $ligne->sous_categorie_id)->toBe((int) $sc->id);
});

it('laisse sous_categorie_id à null pour ligne km si aucune sous-cat flaggée', function () {
    $this->scKm->update(['pour_frais_kilometriques' => false]);

    $ndf = $this->service->saveDraft($this->tiers, [
        'date' => '2026-04-20',
        'libelle' => 'NDF avril',
        'lignes' => [
            [
                'type' => 'kilometrique',
                'libelle' => 'Paris-Rennes AG',
                'montant' => 0,
                'cv_fiscaux' => 5,
                'distance_km' => 420,
                'bareme_eur_km' => 0.636,
                'sous_categorie_id' => null,
                'operation_id' => null,
                'seance' => null,
                'piece_jointe_path' => null,
            ],
        ],
    ]);

    $ligne = $ndf->lignes()->first();
    expect($ligne->sous_categorie_id)->toBeNull();
});

it('laisse sous_categorie_id à null pour ligne km si deux sous-cat flaggées', function () {
    SousCategorie::create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->cat->id,
        'nom' => 'Déplacements bis',
        'pour_frais_kilometriques' => true,
    ]);

    $ndf = $this->service->saveDraft($this->tiers, [
        'date' => '2026-04-20',
        'libelle' => 'NDF avril',
        'lignes' => [
            [
                'type' => 'kilometrique',
                'libelle' => 'Paris-Rennes AG',
                'montant' => 0,
                'cv_fiscaux' => 5,
                'distance_km' => 420,
                'bareme_eur_km' => 0.636,
                'sous_categorie_id' => null,
                'operation_id' => null,
                'seance' => null,
                'piece_jointe_path' => null,
            ],
        ],
    ]);

    $ligne = $ndf->lignes()->first();
    expect($ligne->sous_categorie_id)->toBeNull();
});

it('isolation tenant — flag flaggé dans asso A invisible pour asso B', function () {
    $assoB = Association::factory()->create();
    $tiersB = Tiers::factory()->create(['association_id' => $assoB->id]);
    // La sous-cat flaggée reste dans asso A (this->scKm).

    TenantContext::boot($assoB);

    $ndf = $this->service->saveDraft($tiersB, [
        'date' => '2026-04-20',
        'libelle' => 'NDF B',
        'lignes' => [
            [
                'type' => 'kilometrique',
                'libelle' => 'Trajet',
                'montant' => 0,
                'cv_fiscaux' => 5,
                'distance_km' => 100,
                'bareme_eur_km' => 0.5,
                'sous_categorie_id' => null,
                'operation_id' => null,
                'seance' => null,
                'piece_jointe_path' => null,
            ],
        ],
    ]);

    $ligne = $ndf->lignes()->first();
    expect($ligne->sous_categorie_id)->toBeNull(); // aucune sous-cat flaggée dans B
});
```

- [ ] **Step 2: Faire échouer**

Run: `./vendor/bin/sail test --filter=NoteDeFraisServiceKmTest`
Expected: FAIL — `type` non géré, metadata absent, etc.

- [ ] **Step 3: Modifier `NoteDeFraisService::saveDraft`**

Modifier `app/Services/Portail/NoteDeFrais/NoteDeFraisService.php` :

- En haut, ajouter les imports :

```php
use App\Enums\NoteDeFraisLigneType;
use App\Services\NoteDeFrais\LigneTypes\LigneTypeRegistry;
```

- Dans `saveDraft`, remplacer la boucle actuelle `foreach ($data['lignes'] as $ligneData)` par :

```php
$registry = app(LigneTypeRegistry::class);

foreach ($data['lignes'] as $ligneData) {
    $typeValue = $ligneData['type'] ?? NoteDeFraisLigneType::Standard->value;
    $type = NoteDeFraisLigneType::from($typeValue);
    $strategy = $registry->for($type);

    $montant = $strategy->computeMontant($ligneData);
    $metadata = $strategy->metadata($ligneData);
    $sousCategorieId = $strategy->resolveSousCategorieId(
        isset($ligneData['sous_categorie_id']) ? (int) $ligneData['sous_categorie_id'] : null
    );

    NoteDeFraisLigne::create([
        'note_de_frais_id' => $ndf->id,
        'type' => $type->value,
        'libelle' => $ligneData['libelle'] ?? null,
        'montant' => $montant,
        'metadata' => $metadata !== [] ? $metadata : null,
        'sous_categorie_id' => $sousCategorieId,
        'operation_id' => $ligneData['operation_id'] ?? null,
        'seance' => $ligneData['seance'] ?? null,
        'piece_jointe_path' => $ligneData['piece_jointe_path'] ?? null,
    ]);
}
```

- Mettre à jour la phpdoc de `saveDraft` pour refléter les nouveaux champs optionnels (`type`, `cv_fiscaux`, `distance_km`, `bareme_eur_km`).

- [ ] **Step 4: Passer au vert**

Run: `./vendor/bin/sail test --filter=NoteDeFraisServiceKmTest`
Expected: PASS.

- [ ] **Step 5: Vérifier non-régression sur les tests existants**

Run: `./vendor/bin/sail test tests/Feature/Services/Portail/`
Expected: PASS (aucune régression standard).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Portail/NoteDeFrais/NoteDeFraisService.php \
        tests/Feature/Services/Portail/NoteDeFraisServiceKmTest.php
git commit -m "feat(ndf): NoteDeFraisService route via LigneTypeRegistry (type, metadata, résolution sous-cat)"
```

---

## Task 7 — `NoteDeFraisValidationService` : `notes` enrichie pour km

**Files:**
- Modify: `app/Services/NoteDeFrais/NoteDeFraisValidationService.php`
- Test: `tests/Feature/Services/BackOffice/NoteDeFraisValidationServiceKmTest.php`

- [ ] **Step 1: Écrire le test**

`tests/Feature/Services/BackOffice/NoteDeFraisValidationServiceKmTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Enums\StatutNoteDeFrais;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\NoteDeFrais\NoteDeFraisValidationService;
use App\Services\NoteDeFrais\ValidationData;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);

    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);

    $this->cat = Categorie::factory()->create([
        'association_id' => $this->asso->id,
        'type' => \App\Enums\TypeCategorie::Depense->value,
    ]);
    $this->sc = SousCategorie::create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->cat->id,
        'nom' => 'Déplacements',
        'pour_frais_kilometriques' => true,
    ]);

    $this->compte = Compte::factory()->create(['association_id' => $this->asso->id]);

    $this->service = app(NoteDeFraisValidationService::class);
});

it('peuple transaction_lignes.notes avec libelle + description km pour une ligne kilometrique', function () {
    $ndf = NoteDeFrais::create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'date' => now()->subDay(),
        'libelle' => 'NDF avril',
        'statut' => StatutNoteDeFrais::Soumise->value,
        'submitted_at' => now(),
    ]);

    // PJ carte grise à copier
    Storage::disk('local')->put("associations/{$this->asso->id}/notes-de-frais/{$ndf->id}/ligne-1.pdf", 'carte-grise');

    NoteDeFraisLigne::create([
        'note_de_frais_id' => $ndf->id,
        'type' => NoteDeFraisLigneType::Kilometrique->value,
        'libelle' => 'Paris-Rennes AG',
        'montant' => 267.12,
        'metadata' => [
            'cv_fiscaux' => 5,
            'distance_km' => 420,
            'bareme_eur_km' => 0.636,
        ],
        'sous_categorie_id' => $this->sc->id,
        'piece_jointe_path' => "associations/{$this->asso->id}/notes-de-frais/{$ndf->id}/ligne-1.pdf",
    ]);

    $data = new ValidationData(
        compte_id: $this->compte->id,
        mode_paiement: \App\Enums\ModePaiement::Virement,
        date: now()->format('Y-m-d'),
    );

    $tx = $this->service->valider($ndf, $data);

    $ligneTx = $tx->lignes()->first();
    expect($ligneTx->notes)->toBe(
        'Paris-Rennes AG — Déplacement de 420 km avec un véhicule 5 CV au barème de 0,636 €/km'
    );
    expect($ligneTx->piece_jointe_path)->not->toBeNull();
});

it('conserve le comportement actuel pour une ligne standard (notes = libelle)', function () {
    $ndf = NoteDeFrais::create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'date' => now()->subDay(),
        'libelle' => 'NDF avril',
        'statut' => StatutNoteDeFrais::Soumise->value,
        'submitted_at' => now(),
    ]);

    Storage::disk('local')->put("associations/{$this->asso->id}/notes-de-frais/{$ndf->id}/ligne-1.pdf", 'justif');

    NoteDeFraisLigne::create([
        'note_de_frais_id' => $ndf->id,
        'type' => NoteDeFraisLigneType::Standard->value,
        'libelle' => 'Stylos bureau',
        'montant' => 12.50,
        'sous_categorie_id' => $this->sc->id,
        'piece_jointe_path' => "associations/{$this->asso->id}/notes-de-frais/{$ndf->id}/ligne-1.pdf",
    ]);

    $data = new ValidationData(
        compte_id: $this->compte->id,
        mode_paiement: \App\Enums\ModePaiement::Virement,
        date: now()->format('Y-m-d'),
    );

    $tx = $this->service->valider($ndf, $data);
    $ligneTx = $tx->lignes()->first();

    expect($ligneTx->notes)->toBe('Stylos bureau');
});
```

- [ ] **Step 2: Faire échouer**

Run: `./vendor/bin/sail test --filter=NoteDeFraisValidationServiceKmTest`
Expected: FAIL — `notes` ne contient pas la description km.

- [ ] **Step 3: Modifier le service**

Dans `app/Services/NoteDeFrais/NoteDeFraisValidationService.php` :

- Ajouter en haut :

```php
use App\Services\NoteDeFrais\LigneTypes\LigneTypeRegistry;
```

- Injecter le registre via constructor :

```php
public function __construct(
    private readonly TransactionService $transactionService,
    private readonly LigneTypeRegistry $ligneTypeRegistry,
) {}
```

- Remplacer la construction de `$lignesData` (autour de la ligne 120 actuelle) :

```php
$lignesData = $lignesNdf->map(function ($ligne) {
    $strategy = $this->ligneTypeRegistry->for($ligne->type);
    $description = $strategy->renderDescription($ligne->metadata ?? []);

    $notes = $description !== ''
        ? ($ligne->libelle ? "{$ligne->libelle} — {$description}" : $description)
        : $ligne->libelle;

    return [
        'sous_categorie_id' => $ligne->sous_categorie_id,
        'operation_id' => $ligne->operation_id,
        'seance' => $ligne->seance,
        'notes' => $notes,
        'montant' => (float) $ligne->montant,
    ];
})->toArray();
```

- [ ] **Step 4: Passer au vert**

Run: `./vendor/bin/sail test --filter=NoteDeFraisValidationServiceKmTest`
Expected: PASS (les deux it).

- [ ] **Step 5: Vérifier non-régression**

Run: `./vendor/bin/sail test tests/Feature/BackOffice/ tests/Feature/Services/NoteDeFrais/`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/NoteDeFrais/NoteDeFraisValidationService.php \
        tests/Feature/Services/BackOffice/NoteDeFraisValidationServiceKmTest.php
git commit -m "feat(ndf): validation back-office enrichit transaction_lignes.notes pour lignes km"
```

---

## Task 8 — Livewire Form portail : wizard km (ouverture + état)

**Files:**
- Modify: `app/Livewire/Portail/NoteDeFrais/Form.php`
- Test: `tests/Feature/Livewire/Portail/NoteDeFrais/FormKmWizardTest.php`

- [ ] **Step 1: Écrire le test (ouverture + reset)**

`tests/Feature/Livewire/Portail/NoteDeFrais/FormKmWizardTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\Portail\NoteDeFrais\Form;
use App\Models\Association;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);

    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    $this->actingAs($this->tiers, 'tiers-portail');
});

it('ouvre le wizard km et reset le draft', function () {
    Livewire::test(Form::class, ['association' => $this->asso])
        ->call('openKilometriqueWizard')
        ->assertSet('wizardStep', 1)
        ->assertSet('wizardType', 'kilometrique')
        ->assertSet('draftLigne.cv_fiscaux', null)
        ->assertSet('draftLigne.distance_km', null)
        ->assertSet('draftLigne.bareme_eur_km', null);
});

it('peut fermer le wizard km', function () {
    Livewire::test(Form::class, ['association' => $this->asso])
        ->call('openKilometriqueWizard')
        ->call('cancelLigneWizard')
        ->assertSet('wizardStep', 0)
        ->assertSet('wizardType', null);
});

it('ouvrir wizard standard remet wizardType à standard', function () {
    Livewire::test(Form::class, ['association' => $this->asso])
        ->call('openLigneWizard')
        ->assertSet('wizardStep', 1)
        ->assertSet('wizardType', 'standard');
});

it('computed montant km = distance x bareme', function () {
    Livewire::test(Form::class, ['association' => $this->asso])
        ->call('openKilometriqueWizard')
        ->set('draftLigne.distance_km', '420')
        ->set('draftLigne.bareme_eur_km', '0,636')
        ->assertSet('draftMontantCalcule', 267.12);
});

it('computed montant km = 0 si champs manquants', function () {
    Livewire::test(Form::class, ['association' => $this->asso])
        ->call('openKilometriqueWizard')
        ->assertSet('draftMontantCalcule', 0.0);
});
```

- [ ] **Step 2: Faire échouer**

Run: `./vendor/bin/sail test --filter=FormKmWizardTest`
Expected: FAIL — `openKilometriqueWizard` absente.

- [ ] **Step 3: Étendre le composant Form**

Modifier `app/Livewire/Portail/NoteDeFrais/Form.php` :

- En haut, ajouter :

```php
use App\Enums\NoteDeFraisLigneType;
use App\Services\NoteDeFrais\LigneTypes\LigneTypeRegistry;
```

- Ajouter la propriété :

```php
/** 'standard' | 'kilometrique' | null */
public ?string $wizardType = null;
```

- Étendre le tableau `$draftLigne` pour inclure les champs km :

```php
public array $draftLigne = [
    'justif' => null,
    'libelle' => '',
    'montant' => '',
    'sous_categorie_id' => null,
    'operation_id' => null,
    'seance' => null,
    'cv_fiscaux' => null,
    'distance_km' => null,
    'bareme_eur_km' => null,
];
```

- Adapter `resetDraftLigne()` en miroir (tous les champs remis à null / ''), et faire `$this->resetErrorBag()` (sans argument) pour purger tout état.

- Dans `openLigneWizard()`, setter `$this->wizardType = 'standard'`.

- Ajouter la méthode :

```php
public function openKilometriqueWizard(): void
{
    $this->resetDraftLigne();
    $this->wizardType = 'kilometrique';
    $this->wizardStep = 1;
    $this->dispatch('ligne-wizard-opened');
}
```

- Dans `cancelLigneWizard()`, setter `$this->wizardType = null;` avant `wizardStep = 0;`.

- Ajouter la propriété calculée (computed Livewire) :

```php
public function getDraftMontantCalculeProperty(): float
{
    if ($this->wizardType !== 'kilometrique') {
        return 0.0;
    }

    $registry = app(LigneTypeRegistry::class);
    $strategy = $registry->for(NoteDeFraisLigneType::Kilometrique);

    return $strategy->computeMontant([
        'distance_km' => $this->draftLigne['distance_km'] ?? 0,
        'bareme_eur_km' => $this->draftLigne['bareme_eur_km'] ?? 0,
    ]);
}
```

- [ ] **Step 4: Passer au vert**

Run: `./vendor/bin/sail test --filter=FormKmWizardTest`
Expected: PASS (les 5 it).

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Portail/NoteDeFrais/Form.php \
        tests/Feature/Livewire/Portail/NoteDeFrais/FormKmWizardTest.php
git commit -m "feat(ndf): Livewire Form portail — ouverture wizard km + calcul live du montant"
```

---

## Task 9 — Livewire Form portail : navigation + confirmation wizard km

**Files:**
- Modify: `app/Livewire/Portail/NoteDeFrais/Form.php`
- Test: `tests/Feature/Livewire/Portail/NoteDeFrais/FormKmWizardTest.php`

- [ ] **Step 1: Étendre les tests**

Ajouter à `tests/Feature/Livewire/Portail/NoteDeFrais/FormKmWizardTest.php` :

```php
use App\Models\Categorie;
use App\Models\SousCategorie;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('étape 1 wizard km exige la carte grise', function () {
    Livewire::test(Form::class, ['association' => $this->asso])
        ->call('openKilometriqueWizard')
        ->call('wizardNext')
        ->assertHasErrors(['draftLigne.justif']);
});

it('étape 1 km → étape 2 avec la carte grise uploadée', function () {
    Storage::fake('local');

    Livewire::test(Form::class, ['association' => $this->asso])
        ->call('openKilometriqueWizard')
        ->set('draftLigne.justif', UploadedFile::fake()->create('carte-grise.pdf', 200, 'application/pdf'))
        ->call('wizardNext')
        ->assertSet('wizardStep', 2)
        ->assertHasNoErrors();
});

it('étape 2 km valide CV + km + bareme + libellé', function () {
    Storage::fake('local');

    Livewire::test(Form::class, ['association' => $this->asso])
        ->call('openKilometriqueWizard')
        ->set('draftLigne.justif', UploadedFile::fake()->create('carte-grise.pdf', 200, 'application/pdf'))
        ->call('wizardNext')
        ->call('wizardConfirm')
        ->assertHasErrors([
            'draftLigne.libelle',
            'draftLigne.cv_fiscaux',
            'draftLigne.distance_km',
            'draftLigne.bareme_eur_km',
        ]);
});

it('confirme la ligne km et l\'ajoute au tableau des lignes', function () {
    Storage::fake('local');

    $cat = Categorie::factory()->create(['association_id' => $this->asso->id]);
    SousCategorie::create([
        'association_id' => $this->asso->id,
        'categorie_id' => $cat->id,
        'nom' => 'Déplacements',
        'pour_frais_kilometriques' => true,
    ]);

    Livewire::test(Form::class, ['association' => $this->asso])
        ->call('openKilometriqueWizard')
        ->set('draftLigne.justif', UploadedFile::fake()->create('carte-grise.pdf', 200, 'application/pdf'))
        ->call('wizardNext')
        ->set('draftLigne.libelle', 'Paris-Rennes AG')
        ->set('draftLigne.cv_fiscaux', 5)
        ->set('draftLigne.distance_km', '420')
        ->set('draftLigne.bareme_eur_km', '0,636')
        ->call('wizardConfirm')
        ->assertSet('wizardStep', 0)
        ->assertSet('wizardType', null)
        ->assertCount('lignes', 1);
});
```

- [ ] **Step 2: Faire échouer**

Run: `./vendor/bin/sail test --filter=FormKmWizardTest`
Expected: FAIL (les nouveaux it).

- [ ] **Step 3: Adapter `wizardNext` et `wizardConfirm`**

Modifier `app/Livewire/Portail/NoteDeFrais/Form.php` :

- Dans `wizardNext()`, après la validation existante de step 1 (justif), **skipper l'OCR pour le type km** :

```php
public function wizardNext(): void
{
    if ($this->wizardStep === 1) {
        $this->validateOnly('draftLigne.justif', [
            'draftLigne.justif' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,heic', 'max:5120'],
        ], [
            'draftLigne.justif.required' => $this->wizardType === 'kilometrique'
                ? 'La carte grise est obligatoire.'
                : 'Un justificatif est obligatoire.',
            'draftLigne.justif.file' => 'Le fichier est invalide.',
            'draftLigne.justif.mimes' => 'Formats acceptés : PDF, JPG, PNG, HEIC.',
            'draftLigne.justif.max' => 'Le fichier ne doit pas dépasser 5 Mo.',
        ]);

        if ($this->wizardType === 'standard') {
            /** @var TemporaryUploadedFile $justif */
            $justif = $this->draftLigne['justif'];
            $hints = app(JustificatifAnalyser::class)->analyse($justif);
            if ($hints['libelle']) {
                $this->draftLigne['libelle'] = $hints['libelle'];
            }
            if ($hints['montant']) {
                $this->draftLigne['montant'] = (string) $hints['montant'];
            }
            $this->wizardStep = 2;
            return;
        }

        // wizardType === 'kilometrique' : pas d'OCR
        $this->wizardStep = 2;
        return;
    }

    if ($this->wizardStep === 2 && $this->wizardType === 'standard') {
        $this->validateOnly('draftLigne.montant', [
            'draftLigne.montant' => ['required', 'numeric', 'gt:0'],
        ], [
            'draftLigne.montant.required' => 'Le montant est obligatoire.',
            'draftLigne.montant.numeric' => 'Le montant doit être un nombre.',
            'draftLigne.montant.gt' => 'Le montant doit être supérieur à zéro.',
        ]);
        $this->wizardStep = 3;
    }
}
```

- Adapter `wizardConfirm()` pour router selon `wizardType` :

```php
public function wizardConfirm(): void
{
    if ($this->wizardType === 'kilometrique') {
        $this->validate([
            'draftLigne.libelle' => ['required', 'string', 'min:1'],
            'draftLigne.cv_fiscaux' => ['required', 'integer', 'between:1,50'],
            'draftLigne.distance_km' => ['required', 'numeric', 'gt:0'],
            'draftLigne.bareme_eur_km' => ['required', 'numeric', 'gt:0'],
        ], [
            'draftLigne.libelle.required' => 'Le libellé est obligatoire.',
            'draftLigne.cv_fiscaux.required' => 'La puissance fiscale est obligatoire.',
            'draftLigne.cv_fiscaux.integer' => 'La puissance fiscale doit être un entier.',
            'draftLigne.cv_fiscaux.between' => 'La puissance fiscale doit être entre 1 et 50 CV.',
            'draftLigne.distance_km.required' => 'La distance est obligatoire.',
            'draftLigne.distance_km.numeric' => 'La distance doit être un nombre.',
            'draftLigne.distance_km.gt' => 'La distance doit être supérieure à zéro.',
            'draftLigne.bareme_eur_km.required' => 'Le barème est obligatoire.',
            'draftLigne.bareme_eur_km.numeric' => 'Le barème doit être un nombre.',
            'draftLigne.bareme_eur_km.gt' => 'Le barème doit être supérieur à zéro.',
        ]);

        $this->lignes[] = [
            'id' => null,
            'type' => 'kilometrique',
            'sous_categorie_id' => null,
            'operation_id' => $this->draftLigne['operation_id'] ?? null,
            'seance' => $this->draftLigne['seance'] ?? null,
            'libelle' => $this->draftLigne['libelle'],
            'montant' => (string) $this->draftMontantCalcule,
            'cv_fiscaux' => (int) $this->draftLigne['cv_fiscaux'],
            'distance_km' => $this->toFloatNormalized($this->draftLigne['distance_km']),
            'bareme_eur_km' => $this->toFloatNormalized($this->draftLigne['bareme_eur_km']),
            'piece_jointe_path' => null,
            'justif' => $this->draftLigne['justif'],
        ];

        $this->resetDraftLigne();
        $this->wizardStep = 0;
        $this->wizardType = null;
        $this->dispatch('ligne-wizard-closed');
        return;
    }

    // Flux standard existant
    $this->validateOnly('draftLigne.sous_categorie_id', [
        'draftLigne.sous_categorie_id' => ['required'],
    ], [
        'draftLigne.sous_categorie_id.required' => 'La sous-catégorie est obligatoire.',
    ]);

    $this->lignes[] = [
        'id' => null,
        'type' => 'standard',
        'sous_categorie_id' => $this->draftLigne['sous_categorie_id'],
        'operation_id' => $this->draftLigne['operation_id'],
        'seance' => $this->draftLigne['seance'],
        'libelle' => $this->draftLigne['libelle'],
        'montant' => $this->draftLigne['montant'],
        'piece_jointe_path' => null,
        'justif' => $this->draftLigne['justif'],
    ];

    $this->resetDraftLigne();
    $this->wizardStep = 0;
    $this->wizardType = null;
    $this->dispatch('ligne-wizard-closed');
}

private function toFloatNormalized(mixed $value): float
{
    if (is_string($value)) {
        $value = str_replace(',', '.', $value);
    }
    return (float) $value;
}
```

- Étendre `buildData()` pour propager les champs du type :

```php
$lignesData = [];
foreach ($this->lignes as $ligne) {
    $lignesData[] = [
        'type' => $ligne['type'] ?? 'standard',
        'libelle' => $ligne['libelle'] ?? null,
        'montant' => $ligne['montant'] !== null && $ligne['montant'] !== ''
            ? (float) str_replace(',', '.', (string) $ligne['montant'])
            : 0,
        'sous_categorie_id' => $ligne['sous_categorie_id'] ? (int) $ligne['sous_categorie_id'] : null,
        'operation_id' => $ligne['operation_id'] ? (int) $ligne['operation_id'] : null,
        'seance' => $ligne['seance'] ? (int) $ligne['seance'] : null,
        'piece_jointe_path' => $ligne['piece_jointe_path'] ?? null,
        'cv_fiscaux' => $ligne['cv_fiscaux'] ?? null,
        'distance_km' => $ligne['distance_km'] ?? null,
        'bareme_eur_km' => $ligne['bareme_eur_km'] ?? null,
    ];
}
```

- Étendre le `map(...)` dans `mount()` pour recharger les champs km quand on édite une NDF existante :

```php
$this->lignes = $noteDeFrais->lignes->map(fn (NoteDeFraisLigne $l) => [
    'id' => $l->id,
    'type' => $l->type->value,
    'sous_categorie_id' => $l->sous_categorie_id,
    'operation_id' => $l->operation_id,
    'seance' => $l->seance,
    'libelle' => $l->libelle,
    'montant' => (string) $l->montant,
    'piece_jointe_path' => $l->piece_jointe_path,
    'cv_fiscaux' => $l->metadata['cv_fiscaux'] ?? null,
    'distance_km' => $l->metadata['distance_km'] ?? null,
    'bareme_eur_km' => $l->metadata['bareme_eur_km'] ?? null,
    'justif' => null,
])->all();
```

- Étendre `addLigne()` (ajout inline) pour porter le `type => 'standard'` par défaut.

- [ ] **Step 4: Passer au vert**

Run: `./vendor/bin/sail test --filter=FormKmWizardTest`
Expected: PASS (tous les it).

- [ ] **Step 5: Non-régression sur la suite Portail**

Run: `./vendor/bin/sail test tests/Feature/Livewire/Portail/NoteDeFrais/`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Portail/NoteDeFrais/Form.php \
        tests/Feature/Livewire/Portail/NoteDeFrais/FormKmWizardTest.php
git commit -m "feat(ndf): Livewire Form portail — wizardNext + wizardConfirm routés par wizardType"
```

---

## Task 10 — Templates Blade : deuxième bouton + wizard km

**Files:**
- Modify: `resources/views/livewire/portail/note-de-frais/form.blade.php`
- Test: `tests/Feature/Livewire/Portail/NoteDeFrais/FormKmWizardTest.php`

- [ ] **Step 1: Ajouter le test d'affichage**

Ajouter à `tests/Feature/Livewire/Portail/NoteDeFrais/FormKmWizardTest.php` :

```php
it('affiche le bouton "Ajouter un déplacement"', function () {
    Livewire::test(Form::class, ['association' => $this->asso])
        ->assertSee('Ajouter un déplacement')
        ->assertSee('Ajouter une ligne de frais');
});

it('affiche les champs CV, km, barème au step 2 du wizard km', function () {
    Storage::fake('local');

    $view = Livewire::test(Form::class, ['association' => $this->asso])
        ->call('openKilometriqueWizard')
        ->set('draftLigne.justif', UploadedFile::fake()->create('carte-grise.pdf', 200, 'application/pdf'))
        ->call('wizardNext');

    $view->assertSee('Puissance fiscale');
    $view->assertSee('Distance');
    $view->assertSee('Barème');
    $view->assertSee('impots.gouv.fr'); // lien d'aide
});
```

- [ ] **Step 2: Faire échouer**

Run: `./vendor/bin/sail test --filter=FormKmWizardTest`
Expected: FAIL — les chaînes ne sont pas présentes.

- [ ] **Step 3: Lire le template existant pour trouver l'emplacement**

Lire `resources/views/livewire/portail/note-de-frais/form.blade.php`. Repérer le bouton existant `Ajouter une ligne de frais` et le bloc conditionnel `@if ($wizardStep >= 1)` ... `@endif`.

- [ ] **Step 4: Ajouter le second bouton**

Juste après le bouton actuel `Ajouter une ligne de frais`, l'envelopper dans un `d-flex gap-2` :

```blade
<div class="d-flex gap-2 mb-3">
    <button type="button" wire:click="openLigneWizard" class="btn btn-outline-primary" @if($wizardStep > 0) disabled @endif>
        <i class="bi bi-plus-lg"></i> Ajouter une ligne de frais
    </button>
    <button type="button" wire:click="openKilometriqueWizard" class="btn btn-outline-primary" @if($wizardStep > 0) disabled @endif>
        <i class="bi bi-car-front"></i> Ajouter un déplacement
    </button>
</div>
```

- [ ] **Step 5: Dédoubler le bloc wizard par type**

Remplacer le bloc `@if ($wizardStep >= 1)` existant par une branche `@if ($wizardType === 'kilometrique')` encapsulant la version km, suivie du wizard standard actuel dans un `@else`. Version km :

```blade
@if ($wizardStep >= 1 && $wizardType === 'kilometrique')
    <div class="card mb-3 border-primary">
        <div class="card-body">
            <h6 class="card-title">Nouveau déplacement — étape {{ $wizardStep }} / 2</h6>

            @if ($wizardStep === 1)
                <div class="mb-3">
                    <label class="form-label">Carte grise <span class="text-danger">*</span></label>
                    <input type="file" wire:model="draftLigne.justif" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.heic">
                    <div class="form-text">PDF, JPG, PNG ou HEIC — 5 Mo max.</div>
                    @error('draftLigne.justif') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>

                <div class="d-flex gap-2">
                    <button type="button" wire:click="cancelLigneWizard" class="btn btn-outline-secondary">Annuler</button>
                    <button type="button" wire:click="wizardNext" class="btn btn-primary">Suivant</button>
                </div>
            @endif

            @if ($wizardStep === 2)
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Libellé du déplacement <span class="text-danger">*</span></label>
                        <input type="text" wire:model="draftLigne.libelle" class="form-control" placeholder="ex. Paris-Rennes AG annuelle">
                        @error('draftLigne.libelle') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Puissance fiscale (CV) <span class="text-danger">*</span></label>
                        <input type="number" step="1" min="1" max="50" wire:model.live.debounce.300ms="draftLigne.cv_fiscaux" class="form-control">
                        @error('draftLigne.cv_fiscaux') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Distance (km) <span class="text-danger">*</span></label>
                        <input type="number" step="0.1" min="0" wire:model.live.debounce.300ms="draftLigne.distance_km" class="form-control">
                        @error('draftLigne.distance_km') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Barème (€/km) <span class="text-danger">*</span></label>
                        <input type="number" step="0.001" min="0" wire:model.live.debounce.300ms="draftLigne.bareme_eur_km" class="form-control">
                        <div class="form-text">
                            <a href="https://www.impots.gouv.fr/particulier/frais-de-deplacement" target="_blank" rel="noopener noreferrer">Consulter le barème officiel</a>
                        </div>
                        @error('draftLigne.bareme_eur_km') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Opération (facultatif)</label>
                        {{-- Reprendre ici la même stratégie d'autocomplete/select que le wizard standard --}}
                        <select wire:model="draftLigne.operation_id" class="form-select">
                            <option value="">—</option>
                            @foreach ($operations as $op)
                                <option value="{{ $op->id }}">{{ $op->nom }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6 d-flex align-items-end">
                        <div class="ms-auto text-end">
                            <div class="text-muted small">Montant calculé</div>
                            <div class="h4 mb-0">{{ number_format($this->draftMontantCalcule, 2, ',', ' ') }} €</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="button" wire:click="wizardPrev" class="btn btn-outline-secondary">Retour</button>
                    <button type="button" wire:click="cancelLigneWizard" class="btn btn-outline-secondary">Annuler</button>
                    <button type="button" wire:click="wizardConfirm" class="btn btn-primary">Ajouter le déplacement</button>
                </div>
            @endif
        </div>
    </div>
@endif
```

Conserver le wizard standard existant en enveloppant son `@if ($wizardStep >= 1)` actuel dans une condition `$wizardType === 'standard'`.

- [ ] **Step 6: Passer au vert**

Run: `./vendor/bin/sail test --filter=FormKmWizardTest`
Expected: PASS.

- [ ] **Step 7: Vérification visuelle locale**

Ouvrir http://localhost dans un navigateur, se connecter comme Tiers sur le portail, créer une NDF, cliquer "Ajouter un déplacement", uploader un PDF, passer à l'étape 2, vérifier le rendu et le calcul live. Noter tout ajustement cosmétique requis.

- [ ] **Step 8: Commit**

```bash
git add resources/views/livewire/portail/note-de-frais/form.blade.php \
        tests/Feature/Livewire/Portail/NoteDeFrais/FormKmWizardTest.php
git commit -m "feat(ndf): template portail — bouton + wizard km 2 étapes"
```

---

## Task 11 — Affichage ligne km : badge + sous-ligne infos

**Files:**
- Create: `resources/views/livewire/portail/note-de-frais/partials/ligne-details.blade.php`
- Modify: `resources/views/livewire/portail/note-de-frais/form.blade.php`
- Modify: `resources/views/livewire/portail/note-de-frais/show.blade.php`
- Modify: `resources/views/livewire/back-office/note-de-frais/show.blade.php`
- Test: `tests/Feature/Livewire/Portail/NoteDeFrais/FormKmWizardTest.php`

- [ ] **Step 1: Ajouter les tests**

Ajouter à `tests/Feature/Livewire/Portail/NoteDeFrais/FormKmWizardTest.php` :

```php
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Enums\StatutNoteDeFrais;
use App\Enums\NoteDeFraisLigneType;

it('affiche badge Km et sous-ligne d\'infos pour une ligne kilometrique existante', function () {
    $ndf = NoteDeFrais::create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'date' => '2026-04-20',
        'libelle' => 'NDF avril',
        'statut' => StatutNoteDeFrais::Brouillon->value,
    ]);

    NoteDeFraisLigne::create([
        'note_de_frais_id' => $ndf->id,
        'type' => NoteDeFraisLigneType::Kilometrique->value,
        'libelle' => 'Paris-Rennes AG',
        'montant' => 267.12,
        'metadata' => ['cv_fiscaux' => 5, 'distance_km' => 420, 'bareme_eur_km' => 0.636],
    ]);

    Livewire::test(Form::class, ['association' => $this->asso, 'noteDeFrais' => $ndf])
        ->assertSee('Km')
        ->assertSee('5 CV')
        ->assertSee('420 km')
        ->assertSee('0,636');
});
```

- [ ] **Step 2: Faire échouer**

Run: `./vendor/bin/sail test --filter=FormKmWizardTest`
Expected: FAIL — partial absent.

- [ ] **Step 3: Créer le partial**

`resources/views/livewire/portail/note-de-frais/partials/ligne-details.blade.php` :

```blade
@php
    /**
     * @var array{type?: string, libelle?: string|null, cv_fiscaux?: int|null, distance_km?: float|null, bareme_eur_km?: float|null} $ligne
     */
    $type = $ligne['type'] ?? 'standard';
    $cv = $ligne['cv_fiscaux'] ?? null;
    $km = $ligne['distance_km'] ?? null;
    $bareme = $ligne['bareme_eur_km'] ?? null;
@endphp

@if ($type === 'kilometrique')
    <div>
        <span class="badge bg-info text-dark me-1">Km</span>
        <span>{{ $ligne['libelle'] ?? '' }}</span>
    </div>
    @if ($cv !== null && $km !== null && $bareme !== null)
        <small class="text-muted d-block">
            {{ (int) $cv }} CV · {{ rtrim(rtrim(number_format((float) $km, 2, ',', ''), '0'), ',') ?: '0' }} km · {{ rtrim(rtrim(number_format((float) $bareme, 3, ',', ''), '0'), ',') ?: '0' }} €/km
        </small>
    @endif
@else
    <span>{{ $ligne['libelle'] ?? '' }}</span>
@endif
```

- [ ] **Step 4: Intégrer dans le tableau du Form**

Dans `resources/views/livewire/portail/note-de-frais/form.blade.php`, repérer la cellule `<td>` qui affiche le libellé de chaque ligne du tableau des lignes (boucle `@foreach ($lignes as ...)`). Remplacer son contenu par :

```blade
@include('livewire.portail.note-de-frais.partials.ligne-details', ['ligne' => $ligne])
```

- [ ] **Step 5: Intégrer dans Show portail**

Même traitement dans `resources/views/livewire/portail/note-de-frais/show.blade.php` — identifier la boucle d'affichage des lignes et substituer le libellé par l'include du partial. Depuis Show, chaque ligne est un Model Eloquent — construire l'array attendu :

```blade
@include('livewire.portail.note-de-frais.partials.ligne-details', [
    'ligne' => [
        'type' => $l->type->value,
        'libelle' => $l->libelle,
        'cv_fiscaux' => $l->metadata['cv_fiscaux'] ?? null,
        'distance_km' => $l->metadata['distance_km'] ?? null,
        'bareme_eur_km' => $l->metadata['bareme_eur_km'] ?? null,
    ]
])
```

- [ ] **Step 6: Intégrer dans back-office Show NDF**

Même substitution dans `resources/views/livewire/back-office/note-de-frais/show.blade.php` dans la boucle des lignes NDF. Utiliser le même pattern que Show portail.

- [ ] **Step 7: Passer au vert**

Run: `./vendor/bin/sail test --filter=FormKmWizardTest`
Expected: PASS.

- [ ] **Step 8: Vérification visuelle locale**

Ouvrir http://localhost :
- Portail : créer une NDF avec une ligne km, vérifier le badge et la sous-ligne dans Form puis dans Show.
- Back-office (compte admin) : ouvrir la NDF soumise, vérifier badge + sous-ligne dans la liste des lignes.

- [ ] **Step 9: Commit**

```bash
git add resources/views/livewire/portail/note-de-frais/partials/ligne-details.blade.php \
        resources/views/livewire/portail/note-de-frais/form.blade.php \
        resources/views/livewire/portail/note-de-frais/show.blade.php \
        resources/views/livewire/back-office/note-de-frais/show.blade.php \
        tests/Feature/Livewire/Portail/NoteDeFrais/FormKmWizardTest.php
git commit -m "feat(ndf): partial ligne-details — badge Km + sous-ligne 5 CV · 420 km · 0,636 €/km"
```

---

## Task 12 — Écran Sous-catégories : colonne flag Frais km

**Files:**
- Modify: `app/Livewire/SousCategorieList.php`
- Modify: `resources/views/livewire/sous-categorie-list.blade.php`
- Test: `tests/Feature/Livewire/SousCategorieListKmTest.php`

- [ ] **Step 1: Écrire le test**

`tests/Feature/Livewire/SousCategorieListKmTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\SousCategorieList;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);

    $this->admin = User::factory()->create(['association_id' => $this->asso->id, 'role' => 'admin']);
    $this->actingAs($this->admin);

    $this->cat = Categorie::factory()->create(['association_id' => $this->asso->id]);
});

it('crée une sous-catégorie avec flag pour_frais_kilometriques coché', function () {
    Livewire::test(SousCategorieList::class)
        ->call('openCreate')
        ->set('categorie_id', (string) $this->cat->id)
        ->set('nom', 'Déplacements')
        ->set('pour_frais_kilometriques', true)
        ->call('save');

    $sc = SousCategorie::where('nom', 'Déplacements')->first();
    expect($sc->pour_frais_kilometriques)->toBeTrue();
});

it('affiche l\'état du flag Frais kilométriques dans le tableau', function () {
    SousCategorie::create([
        'association_id' => $this->asso->id,
        'categorie_id' => $this->cat->id,
        'nom' => 'Déplacements',
        'pour_frais_kilometriques' => true,
    ]);

    Livewire::test(SousCategorieList::class)
        ->assertSee('Frais kilométriques');
});
```

- [ ] **Step 2: Faire échouer**

Run: `./vendor/bin/sail test --filter=SousCategorieListKmTest`
Expected: FAIL — propriété absente.

- [ ] **Step 3: Modifier le composant**

Dans `app/Livewire/SousCategorieList.php` :

- Ajouter la propriété `public bool $pour_frais_kilometriques = false;` à côté des trois existantes.
- Dans `openEdit()`, ajouter `$this->pour_frais_kilometriques = $sc->pour_frais_kilometriques;`.
- Dans `resetForm()` (ou équivalent), ajouter `$this->pour_frais_kilometriques = false;`.
- Dans `save()`, étendre la règle de validation avec `'pour_frais_kilometriques' => 'boolean',` puis inclure `'pour_frais_kilometriques' => $this->pour_frais_kilometriques,` dans l'array `updateOrCreate` / `create`.

- [ ] **Step 4: Modifier le template**

Dans `resources/views/livewire/sous-categorie-list.blade.php` :

- Ajouter une colonne `Frais kilométriques` dans l'entête du tableau, entre la colonne existante la plus appropriée (après `Inscriptions`).
- Dans chaque ligne de tableau, afficher :

```blade
<td class="text-center">
    @if ($sousCategorie->pour_frais_kilometriques)
        <i class="bi bi-check-circle-fill text-success"></i>
    @endif
</td>
```

- Dans la modale de création/édition (form), ajouter la checkbox :

```blade
<div class="form-check mb-2">
    <input class="form-check-input" type="checkbox" wire:model="pour_frais_kilometriques" id="pour_frais_kilometriques">
    <label class="form-check-label" for="pour_frais_kilometriques">Frais kilométriques</label>
</div>
```

- [ ] **Step 5: Passer au vert**

Run: `./vendor/bin/sail test --filter=SousCategorieListKmTest`
Expected: PASS.

- [ ] **Step 6: Vérification visuelle locale**

Ouvrir http://localhost/sous-categories en admin, créer/éditer une sous-catégorie, vérifier la checkbox et la colonne.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/SousCategorieList.php \
        resources/views/livewire/sous-categorie-list.blade.php \
        tests/Feature/Livewire/SousCategorieListKmTest.php
git commit -m "feat(ndf): écran sous-catégories — flag pour_frais_kilometriques"
```

---

## Task 13 — Tests isolation tenant (ligne km)

**Files:**
- Test: `tests/Feature/Tenant/NoteDeFraisKmTenantIsolationTest.php`

- [ ] **Step 1: Écrire le test**

`tests/Feature/Tenant/NoteDeFraisKmTenantIsolationTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Tenant\TenantContext;

it('une ligne km de asso A est invisible de asso B', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    TenantContext::boot($assoA);
    $tiersA = Tiers::factory()->create(['association_id' => $assoA->id]);
    $catA = Categorie::factory()->create(['association_id' => $assoA->id]);
    $scA = SousCategorie::create([
        'association_id' => $assoA->id,
        'categorie_id' => $catA->id,
        'nom' => 'Déplacements',
        'pour_frais_kilometriques' => true,
    ]);
    $ndfA = NoteDeFrais::create([
        'association_id' => $assoA->id,
        'tiers_id' => $tiersA->id,
        'date' => '2026-04-20',
        'libelle' => 'NDF A',
        'statut' => \App\Enums\StatutNoteDeFrais::Brouillon->value,
    ]);
    NoteDeFraisLigne::create([
        'note_de_frais_id' => $ndfA->id,
        'type' => NoteDeFraisLigneType::Kilometrique->value,
        'libelle' => 'Secret A',
        'montant' => 100,
        'metadata' => ['cv_fiscaux' => 5, 'distance_km' => 200, 'bareme_eur_km' => 0.5],
        'sous_categorie_id' => $scA->id,
    ]);

    TenantContext::boot($assoB);

    expect(NoteDeFrais::query()->count())->toBe(0);
    expect(NoteDeFraisLigne::query()->count())->toBe(0);
    expect(SousCategorie::where('pour_frais_kilometriques', true)->count())->toBe(0);
});
```

- [ ] **Step 2: Faire tourner**

Run: `./vendor/bin/sail test --filter=NoteDeFraisKmTenantIsolationTest`
Expected: PASS (TenantScope fail-closed scelle les données A pour asso B).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Tenant/NoteDeFraisKmTenantIsolationTest.php
git commit -m "test(ndf): isolation tenant lignes km et flag sous-cat"
```

---

## Task 14 — Documentation `portail-tiers.md`

**Files:**
- Modify: `docs/portail-tiers.md`

- [ ] **Step 1: Lire `docs/portail-tiers.md`**

Identifier la section Slice 2 ou une section "Types de lignes".

- [ ] **Step 2: Ajouter une sous-section**

Sous la description des lignes NDF standards, insérer :

```markdown
### Lignes de frais kilométriques

En plus de la ligne de frais standard, le Tiers peut saisir un **déplacement** via un second bouton "Ajouter un déplacement". Le wizard km demande en deux étapes :

1. **Carte grise** du véhicule (PDF / JPG / PNG / HEIC, 5 Mo max, obligatoire).
2. **Libellé**, **puissance fiscale (CV)**, **distance (km)** et **barème (€/km)**. Un lien vers le barème officiel (`impots.gouv.fr`) est fourni à titre d'aide. Le montant s'affiche en temps réel : `montant = distance × barème`, arrondi à 2 décimales. L'opération et la séance restent facultatives. Aucune sous-catégorie n'est demandée au Tiers — voir résolution automatique ci-dessous.

**Stockage ligne** : la ligne utilise la même table `notes_de_frais_lignes` que les lignes standards, distinguées via le champ `type` (`standard` | `kilometrique`). Les paramètres km sont persistés dans le champ JSON `metadata` (`cv_fiscaux`, `distance_km`, `bareme_eur_km`). Le montant stocké est recalculé côté serveur à chaque save — aucune valeur client n'est prise en confiance.

**Résolution de la sous-catégorie** : l'écran Paramètres → Sous-catégories expose un flag `Frais kilométriques`. Au save d'une ligne km, le service applique automatiquement la sous-catégorie flaggée si elle est unique dans l'asso. Si 0 ou plusieurs sont flaggées, `sous_categorie_id` reste `null` et le comptable tranchera au back-office (mini-form déjà éditable).

**Back-office** : aucune modification UI. Lors de la validation d'une NDF, le champ `transaction_lignes.notes` est enrichi :

- Ligne standard → `notes = libelle NDF` (comportement inchangé).
- Ligne km → `notes = "{libelle Tiers} — Déplacement de {km} km avec un véhicule {CV} CV au barème de {bareme} €/km"`.

Le comptable conserve la description fiscale nécessaire à sa validation, la carte grise est copiée vers `transaction_lignes.piece_jointe_path` selon la convention existante.

**Architecture extensible** : `App\Services\NoteDeFrais\LigneTypes\LigneTypeInterface` + `LigneTypeRegistry` préparent l'ajout futur de types normés (repas, hébergement) — chaque nouveau type ajoute un case à l'enum `NoteDeFraisLigneType` + une classe strategy, sans migration.
```

- [ ] **Step 3: Commit**

```bash
git add docs/portail-tiers.md
git commit -m "docs(ndf): section lignes kilométriques dans portail-tiers.md"
```

---

## Task 15 — Suite complète + nettoyage

- [ ] **Step 1: Lancer toute la suite**

Run: `./vendor/bin/sail test`
Expected: PASS sur tous les tests (pas de régression).

- [ ] **Step 2: Pint**

Run: `./vendor/bin/sail composer pint` (ou `./vendor/bin/pint`)
Expected: pas de fichier modifié, ou commit du nettoyage.

Si modifications :

```bash
git add -A
git commit -m "style: pint"
```

- [ ] **Step 3: Recap**

Lister les commits ajoutés avec `git log --oneline origin/main..HEAD | head -30`. Préparer la synthèse pour le user (fonctionnalités livrées, fichiers touchés, tests ajoutés).

---

## Self-Review

**1. Spec coverage :**
- §1 Intent & périmètre : Tasks 1 (migrations), 6 (service portail), 7 (service back-office), 8-11 (Livewire + templates), 12 (flag sous-cat). ✓
- §2 BDD Scenario 1 (saisie nominale) : Task 9 + Task 6. ✓
- §2 Scenario 2-3 (résolution sous-cat) : Task 6 (3 tests). ✓
- §2 Scenario 4 (anti-tampering) : Task 6 (test dédié). ✓
- §2 Scenario 5 (back-office) : Task 7. ✓
- §2 Scenario 6 (édition brouillon) : Task 9 (mount étendu). ✓
- §2 Scenario 7 (carte grise obligatoire) : Task 9. ✓
- §2 Scenario 8 (isolation tenant) : Task 13. ✓
- §3 Architecture (migrations, enum, strategy, services, Livewire, templates) : Tasks 1-12. ✓
- §4 Acceptance Criteria : couverts.
- §5 Forward compatibility : noté dans Task 14 (doc) et architecture.

**2. Placeholder scan :** aucun TBD / TODO laissé. Code complet dans chaque step (seul step visuel 10-step 7 et 11-step 8 demandent une vérif navigateur — c'est par design, pas un placeholder).

**3. Type consistency :** `NoteDeFraisLigneType` (pas `LigneType`), `LigneTypeInterface` stable, signatures identiques entre Tasks 3/4/5/6/7. `draftMontantCalcule` computed property cohérent entre Task 8 et Task 10.

**4. Spec ambiguïtés levées :** format FR virgule, arrondi half-up, anti-tampering, description back-office en français, séparateur `—`, metadata stockée null si vide.
