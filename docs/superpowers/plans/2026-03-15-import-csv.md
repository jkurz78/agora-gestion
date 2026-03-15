# Import CSV — Dépenses & Recettes Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre l'import de dépenses et recettes depuis un fichier CSV avec validation exhaustive avant toute insertion, intégré dans les listes existantes.

**Architecture:** Deux phases — validation exhaustive du CSV (phase 1, aucune écriture), puis insertion via `DepenseService`/`RecetteService` existants (phase 2). Un composant Livewire `ImportCsv` paramétré par type (`depense`/`recette`) gère l'UI et délègue au `CsvImportService`. Les routes de template sont servies par un contrôleur léger.

**Tech Stack:** Laravel 11, Livewire 4, Pest PHP, Bootstrap 5 CDN, MySQL (Sail)

---

## Chunk 1 — Schema, Forms et Service

### Task 1: Migration — libelle nullable, reference NOT NULL

> Peut tourner en parallèle avec Task 2 (pas de dépendance entre les deux).

**Files:**
- Create: `database/migrations/2026_03_15_000001_alter_depenses_recettes_reference_libelle.php`
- Create: `tests/Feature/MigrationReferenceLibelleTest.php`

**Contexte codebase:**
- Table `depenses` : `libelle VARCHAR(255) NOT NULL`, `reference VARCHAR(100) NULL`
- Table `recettes` : identique
- Les `SoftDeletes` sont actifs — la migration ne touche pas aux deleted records
- Les tests Pest utilisent `RefreshDatabase` — la migration sera rejouée à chaque test
- Note : la logique de backfill (`UPDATE ... WHERE reference IS NULL`) ne peut pas être testée via Pest/RefreshDatabase car la contrainte est déjà en place. Le backfill est couvert par la migration elle-même et validé en staging.

- [ ] **Step 1: Écrire les tests de migration**

Créer `tests/Feature/MigrationReferenceLibelleTest.php` :

```php
<?php

use App\Models\CompteBancaire;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('libelle est nullable sur depenses', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();

    DB::table('depenses')->insert([
        'date'          => '2025-10-01',
        'libelle'       => null,
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'reference'     => 'REF-LIBELLE-NULL',
        'compte_id'     => $compte->id,
        'pointe'        => false,
        'saisi_par'     => $user->id,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    expect(DB::table('depenses')->where('reference', 'REF-LIBELLE-NULL')->value('libelle'))->toBeNull();
});

it('libelle est nullable sur recettes', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();

    DB::table('recettes')->insert([
        'date'          => '2025-10-01',
        'libelle'       => null,
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'reference'     => 'REF-LIBELLE-NULL-R',
        'compte_id'     => $compte->id,
        'pointe'        => false,
        'saisi_par'     => $user->id,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    expect(DB::table('recettes')->where('reference', 'REF-LIBELLE-NULL-R')->value('libelle'))->toBeNull();
});

it('reference est obligatoire (NOT NULL) sur depenses', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();

    expect(fn () => DB::table('depenses')->insert([
        'date'          => '2025-10-01',
        'libelle'       => 'Test',
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'reference'     => null,
        'compte_id'     => $compte->id,
        'pointe'        => false,
        'saisi_par'     => $user->id,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]))->toThrow(\Exception::class);
});

it('reference est obligatoire (NOT NULL) sur recettes', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();

    expect(fn () => DB::table('recettes')->insert([
        'date'          => '2025-10-01',
        'libelle'       => 'Test',
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'reference'     => null,
        'compte_id'     => $compte->id,
        'pointe'        => false,
        'saisi_par'     => $user->id,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]))->toThrow(\Exception::class);
});
```

- [ ] **Step 2: Vérifier que les tests échouent (libelle pas encore nullable)**

```bash
./vendor/bin/sail artisan test --filter=MigrationReferenceLibelleTest
```
Expected: FAIL sur les tests `libelle est nullable` (colonne NOT NULL actuellement)

- [ ] **Step 3: Écrire la migration**

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
        // Backfill : remplir reference sur les lignes existantes avant la contrainte NOT NULL
        DB::statement("UPDATE depenses SET reference = CONCAT('IMPORT-MIGRATION-', id) WHERE reference IS NULL");
        DB::statement("UPDATE recettes SET reference = CONCAT('IMPORT-MIGRATION-', id) WHERE reference IS NULL");

        Schema::table('depenses', function (Blueprint $table) {
            $table->string('libelle', 255)->nullable()->change();
            $table->string('reference', 100)->nullable(false)->change();
        });

        Schema::table('recettes', function (Blueprint $table) {
            $table->string('libelle', 255)->nullable()->change();
            $table->string('reference', 100)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // Remettre libelle NOT NULL (remplacer les NULL par chaîne vide)
        DB::statement("UPDATE depenses SET libelle = '' WHERE libelle IS NULL");
        DB::statement("UPDATE recettes SET libelle = '' WHERE libelle IS NULL");

        Schema::table('depenses', function (Blueprint $table) {
            $table->string('libelle', 255)->nullable(false)->change();
            $table->string('reference', 100)->nullable()->change();
        });

        Schema::table('recettes', function (Blueprint $table) {
            $table->string('libelle', 255)->nullable(false)->change();
            $table->string('reference', 100)->nullable()->change();
        });
    }
};
```

- [ ] **Step 4: Rejouer les migrations et lancer les tests**

```bash
./vendor/bin/sail artisan migrate:fresh --seed
./vendor/bin/sail artisan test --filter=MigrationReferenceLibelleTest
```
Expected: PASS (4 tests)

- [ ] **Step 5: Vérifier que les tests existants passent encore**

```bash
./vendor/bin/sail artisan test --filter="DepenseTest|RecetteTest"
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_03_15_000001_alter_depenses_recettes_reference_libelle.php \
        tests/Feature/MigrationReferenceLibelleTest.php
git commit -m "feat: libelle nullable et reference NOT NULL sur depenses et recettes"
```

---

### Task 2: Form updates — DepenseForm, RecetteForm et vues formulaires

> Peut tourner en parallèle avec Task 1 (code PHP/Blade uniquement, pas de DB).

**Files:**
- Modify: `app/Livewire/DepenseForm.php`
- Modify: `app/Livewire/RecetteForm.php`
- Modify: `resources/views/livewire/depense-form.blade.php`
- Modify: `resources/views/livewire/recette-form.blade.php`

**Contexte codebase:**
- `DepenseForm.php` ligne 25 : `public string $libelle = '';` → doit devenir `public ?string $libelle = null;`
- `DepenseForm.php` ligne 144 : `'libelle' => ['required', 'string', 'max:255']` → `['nullable', 'string', 'max:255']`
- `DepenseForm.php` : `reference` n'est pas dans les règles de validation → à ajouter
- `depense-form.blade.php` ligne 44 : label Libellé a `<span class="text-danger">*</span>` → retirer
- `depense-form.blade.php` ligne 40-41 : label Référence n'a pas d'astérisque, input sans validation feedback → à corriger
- `RecetteForm.php` et `recette-form.blade.php` : structure identique à DepenseForm

- [ ] **Step 1: Modifier DepenseForm.php**

Trois changements dans `app/Livewire/DepenseForm.php` :

**Changement 1** — propriété libelle (ligne 25) :
```php
// avant
public string $libelle = '';
// après
public ?string $libelle = null;
```

**Changement 2** — dans le tableau passé à `$this->validate()`, modifier libelle et ajouter reference :
```php
// avant (la clé 'reference' n'existe pas, 'libelle' est required)
'libelle' => ['required', 'string', 'max:255'],
// après (ajouter reference, rendre libelle nullable)
'libelle'   => ['nullable', 'string', 'max:255'],
'reference' => ['required', 'string', 'max:100'],
```

**Changement 3** — dans `save()`, `reference` est maintenant requis donc non null après validation. Remplacer :
```php
// avant
'reference' => $this->reference ?: null,
// après
'reference' => $this->reference,
```

- [ ] **Step 2: Modifier RecetteForm.php de façon identique**

Dans `app/Livewire/RecetteForm.php`, appliquer exactement les mêmes 3 changements. Les lignes correspondantes ont les mêmes numéros (structure identique à DepenseForm).

- [ ] **Step 3: Modifier depense-form.blade.php**

**Changement 1** — Retirer l'astérisque sur libelle (chercher la ligne `Libellé <span class="text-danger">*</span>`) :
```blade
{{-- avant --}}
<label for="libelle" class="form-label">Libellé <span class="text-danger">*</span></label>
{{-- après --}}
<label for="libelle" class="form-label">Libellé</label>
```

**Changement 2** — Ajouter astérisque et validation feedback sur référence (chercher la ligne `<label for="reference"`) :
```blade
{{-- avant --}}
<label for="reference" class="form-label">Référence</label>
<input type="text" wire:model="reference" id="reference" class="form-control">

{{-- après --}}
<label for="reference" class="form-label">Référence <span class="text-danger">*</span></label>
<input type="text" wire:model="reference" id="reference"
       class="form-control @error('reference') is-invalid @enderror">
@error('reference') <div class="invalid-feedback">{{ $message }}</div> @enderror
```

- [ ] **Step 4: Modifier recette-form.blade.php de façon identique**

Appliquer les mêmes deux modifications (libelle sans astérisque, référence avec astérisque et feedback) dans `resources/views/livewire/recette-form.blade.php`.

- [ ] **Step 5: Vérifier les tests existants**

```bash
./vendor/bin/sail artisan test --filter="DepenseTest|RecetteTest"
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/DepenseForm.php app/Livewire/RecetteForm.php \
        resources/views/livewire/depense-form.blade.php \
        resources/views/livewire/recette-form.blade.php
git commit -m "feat: libelle facultatif et reference obligatoire dans les formulaires"
```

---

### Task 3: CsvImportResult + CsvImportService

**Files:**
- Create: `app/Services/CsvImportResult.php`
- Create: `app/Services/CsvImportService.php`
- Create: `tests/Feature/CsvImportServiceTest.php`

**Contexte codebase critique :**
- `DepenseService::create(array $data, array $lignes): Depense` — crée la dépense + ses lignes dans une transaction. `saisi_par` est résolu par `auth()->id()` à l'intérieur. `numero_piece` est assigné par `NumeroPieceService::assign()` avec `SELECT FOR UPDATE`. **Ne jamais envelopper ces appels dans une transaction externe.**
- `RecetteService::create()` : signature et comportement identiques.
- `SousCategorie` → filtrée par `categorie.type` (`TypeCategorie::Depense` ou `TypeCategorie::Recette`)
- `CompteBancaire` → filtrée par `actif_recettes_depenses = true`
- `Tiers::displayName()` → retourne `$this->nom` si `type === 'entreprise'`, sinon `"{$this->prenom} {$this->nom}"`
- `Tiers` → booléens `pour_depenses` / `pour_recettes`
- `Operation` → lookup par nom (case-insensitive)
- Exercice comptable : 1er sept → 31 août. Les doublons en base sont détectés par `date` + `reference` dans la table `depenses`/`recettes` (hors soft-deleted, **sans filtre exercice** — une même référence dans un autre exercice n'est pas un doublon).
- `$type` vaut `'depense'` ou `'recette'`. `TypeCategorie::from($type)` donne l'enum.
- Regroupement : lignes partageant `date` + `reference` forment une seule transaction, même si non-contiguës dans le fichier. `mode_paiement`, `compte`, `libelle`, `tiers` lus sur la **1re ligne du groupe** uniquement.

**Validation mode_paiement et compte :** Ces champs sont requis sur la première ligne d'un groupe. Comme on ne sait pas au moment du parsing de la ligne si c'est une première ligne, la validation se fait **lors du groupement** : quand un nouveau groupe est créé, on vérifie que `mode_paiement` et `compte` sont fournis et valides.

- [ ] **Step 1: Créer CsvImportResult**

Créer `app/Services/CsvImportResult.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services;

final class CsvImportResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $transactionsCreated = 0,
        public readonly int $lignesCreated = 0,
        public readonly array $errors = [], // [['line' => 4, 'message' => '...']]
    ) {}
}
```

- [ ] **Step 2: Écrire les tests pour CsvImportService**

Créer `tests/Feature/CsvImportServiceTest.php` :

```php
<?php

use App\Enums\TypeCategorie;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Services\CsvImportService;
use Illuminate\Http\UploadedFile;

// Helper : créer un UploadedFile depuis une string CSV
function makeCsvFile(string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'csv_test_');
    file_put_contents($path, $content);

    return new UploadedFile($path, 'test.csv', 'text/csv', null, true);
}

beforeEach(function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->compte = CompteBancaire::factory()->create([
        'nom'                     => 'Compte principal',
        'actif_recettes_depenses' => true,
    ]);

    $cat = Categorie::factory()->create(['type' => TypeCategorie::Depense]);
    $this->sc = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Fournitures']);
});

it('importe un CSV valide avec une transaction simple', function () {
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Achat test;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeTrue()
        ->and($result->transactionsCreated)->toBe(1)
        ->and($result->lignesCreated)->toBe(1)
        ->and($result->errors)->toBeEmpty();
});

it('regroupe plusieurs lignes CSV en une seule transaction', function () {
    $cat2 = Categorie::factory()->create(['type' => TypeCategorie::Depense]);
    SousCategorie::factory()->create(['categorie_id' => $cat2->id, 'nom' => 'Communication']);

    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Test;;\n"
         . "2024-09-15;FAC-001;Communication;50.00;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeTrue()
        ->and($result->transactionsCreated)->toBe(1)
        ->and($result->lignesCreated)->toBe(2);
});

it('regroupe des lignes non-contigues de meme date+reference en une seule transaction', function () {
    $cat2 = Categorie::factory()->create(['type' => TypeCategorie::Depense]);
    SousCategorie::factory()->create(['categorie_id' => $cat2->id, 'nom' => 'Communication']);

    // FAC-001 apparaît en lignes 2 et 4 (non-contigus), FAC-002 en ligne 3
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Test1;;\n"
         . "2024-09-16;FAC-002;Fournitures;50.00;cheque;Compte principal;Test2;;\n"
         . "2024-09-15;FAC-001;Communication;30.00;;;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeTrue()
        ->and($result->transactionsCreated)->toBe(2)  // FAC-001 + FAC-002
        ->and($result->lignesCreated)->toBe(3);        // 2 lignes pour FAC-001, 1 pour FAC-002
});

it('rejette un fichier avec un encodage non-UTF8', function () {
    $content = iconv('UTF-8', 'ISO-8859-1',
        "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
        . "2024-09-15;FAC-001;Catégorie;100.00;virement;Compte;Libellé;;\n"
    );

    $result = app(CsvImportService::class)->import(makeCsvFile($content), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('UTF-8');
});

it('ignore le BOM UTF-8 en debut de fichier', function () {
    $bom = "\xEF\xBB\xBF";
    $csv = $bom . "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Test;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeTrue();
});

it('rejette un CSV avec un en-tete manquant', function () {
    $result = app(CsvImportService::class)->import(
        makeCsvFile("date;reference;sous_categorie;montant_ligne\n2024-09-15;FAC-001;Fournitures;100.00\n"),
        'depense'
    );

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('en-tête');
});

it('rejette une date invalide avec le bon numero de ligne', function () {
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "32/13/2024;FAC-001;Fournitures;100.00;virement;Compte principal;Test;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['line'])->toBe(2)
        ->and($result->errors[0]['message'])->toContain('date');
});

it('rejette un mode_paiement invalide', function () {
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "2024-09-15;FAC-001;Fournitures;100.00;carte;Compte principal;Test;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['line'])->toBe(2)
        ->and($result->errors[0]['message'])->toContain('mode_paiement');
});

it('rejette un mode_paiement vide sur la premiere ligne dun groupe', function () {
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "2024-09-15;FAC-001;Fournitures;100.00;;Compte principal;Test;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('mode_paiement');
});

it('rejette un compte vide sur la premiere ligne dun groupe', function () {
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "2024-09-15;FAC-001;Fournitures;100.00;virement;;Test;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('compte');
});

it('rejette une sous-categorie inconnue', function () {
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "2024-09-15;FAC-001;Toto;100.00;virement;Compte principal;Test;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('Toto');
});

it('rejette un compte inconnu', function () {
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "2024-09-15;FAC-001;Fournitures;100.00;virement;Compte inexistant;Test;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('Compte inexistant');
});

it('rejette un tiers homonyme', function () {
    Tiers::factory()->create(['type' => 'particulier', 'nom' => 'DUPONT', 'prenom' => 'Jean', 'pour_depenses' => true]);
    Tiers::factory()->create(['type' => 'particulier', 'nom' => 'DUPONT', 'prenom' => 'Jean', 'pour_depenses' => true]);

    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Test;Jean DUPONT;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('homonyme');
});

it('rejette un tiers sans le flag pour_depenses', function () {
    Tiers::factory()->create([
        'type'          => 'particulier',
        'nom'           => 'MARTIN',
        'prenom'        => 'Paul',
        'pour_depenses' => false,
        'pour_recettes' => true,
    ]);

    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Test;Paul MARTIN;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('Paul MARTIN')
        ->and($result->errors[0]['message'])->toContain('dépenses');
});

it('rejette un doublon en base de donnees', function () {
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Test;;\n";

    $result1 = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');
    expect($result1->success)->toBeTrue();

    $result2 = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');
    expect($result2->success)->toBeFalse()
        ->and($result2->errors[0]['message'])->toContain('FAC-001');
});

it('collecte toutes les erreurs avant de rejeter', function () {
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "invalide;FAC-001;Fournitures;100.00;virement;Compte principal;Test;;\n"
         . "2024-09-16;FAC-002;SousCatInconnue;100.00;virement;Compte principal;Test;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeFalse()
        ->and(count($result->errors))->toBeGreaterThanOrEqual(2);
});

it('ignore les lignes vides', function () {
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "\n"
         . "2024-09-15;FAC-001;Fournitures;100.00;virement;Compte principal;Test;;\n"
         . "\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeTrue()
        ->and($result->transactionsCreated)->toBe(1);
});

it('est insensible a la casse pour sous_categorie et compte', function () {
    $csv = "date;reference;sous_categorie;montant_ligne;mode_paiement;compte;libelle;tiers;operation\n"
         . "2024-09-15;FAC-001;FOURNITURES;100.00;virement;COMPTE PRINCIPAL;Test;;\n";

    $result = app(CsvImportService::class)->import(makeCsvFile($csv), 'depense');

    expect($result->success)->toBeTrue();
});
```

- [ ] **Step 3: Vérifier que les tests échouent (service pas encore créé)**

```bash
./vendor/bin/sail artisan test --filter=CsvImportServiceTest
```
Expected: FAIL — `Class "App\Services\CsvImportService" not found`

- [ ] **Step 4: Créer CsvImportService**

Créer `app/Services/CsvImportService.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeCategorie;
use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\Operation;
use App\Models\Recette;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\DepenseService;
use App\Services\RecetteService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CsvImportService
{
    private const EXPECTED_HEADERS = [
        'date', 'reference', 'sous_categorie', 'montant_ligne',
        'mode_paiement', 'compte', 'libelle', 'tiers', 'operation',
    ];

    private const MODES_PAIEMENT = ['virement', 'cheque', 'especes', 'cb', 'prelevement'];

    public function import(UploadedFile $file, string $type): CsvImportResult
    {
        // --- Phase 1 : Lecture et validation ---

        $content = $this->readUtf8($file);
        if ($content === null) {
            return new CsvImportResult(false, errors: [[
                'line'    => 0,
                'message' => 'Le fichier doit être encodé en UTF-8. Enregistrez votre fichier CSV en UTF-8 depuis Excel ou LibreOffice.',
            ]]);
        }

        $rows = $this->parseCsv($content);

        if (empty($rows)) {
            return new CsvImportResult(false, errors: [['line' => 0, 'message' => 'Le fichier est vide.']]);
        }

        // Valider l'en-tête
        $headerError = $this->validateHeader($rows[0]);
        if ($headerError !== null) {
            return new CsvImportResult(false, errors: [['line' => 1, 'message' => $headerError]]);
        }

        array_shift($rows); // Retirer la ligne d'en-tête

        // Charger les lookups DB une seule fois (case-insensitive via lowercase key)
        $typeEnum   = TypeCategorie::from($type);
        $flagField  = $type === 'depense' ? 'pour_depenses' : 'pour_recettes';
        $typeLabel  = $type === 'depense' ? 'dépenses' : 'recettes';

        /** @var Collection<string, SousCategorie> */
        $sousCategories = SousCategorie::whereHas('categorie', fn ($q) => $q->where('type', $typeEnum))
            ->get()
            ->keyBy(fn ($sc) => Str::lower(trim($sc->nom)));

        /** @var Collection<string, CompteBancaire> */
        $comptes = CompteBancaire::where('actif_recettes_depenses', true)
            ->get()
            ->keyBy(fn ($c) => Str::lower(trim($c->nom)));

        // Map tiers : displayName (lowercase) → liste de Tiers (pour détecter les homonymes)
        /** @var array<string, list<Tiers>> */
        $tiersMap = [];
        foreach (Tiers::all() as $tiers) {
            $key              = Str::lower(trim($tiers->displayName()));
            $tiersMap[$key][] = $tiers;
        }

        /** @var Collection<string, Operation> */
        $operations = Operation::all()->keyBy(fn ($op) => Str::lower(trim($op->nom)));

        // Parser et grouper les lignes par date+reference
        $errors     = [];
        $groups     = []; // groupKey => ['data' => [...], 'lignes' => [...], 'firstLine' => int]
        $groupOrder = []; // ordre d'apparition des groupes

        foreach ($rows as $idx => $row) {
            $csvLine = $idx + 2; // +1 pour l'en-tête, +1 pour l'indexation 1-based

            // Validation des champs par ligne
            $rowErrors = $this->validateRow($row, $csvLine, $sousCategories, $tiersMap, $operations, $flagField, $typeLabel);
            $errors    = array_merge($errors, $rowErrors);

            if (!empty($rowErrors)) {
                continue;
            }

            $date      = trim($row[0]);
            $reference = trim($row[1]);
            $groupKey  = $date . '|' . $reference;

            $scNom       = Str::lower(trim($row[2]));
            $sc          = $sousCategories[$scNom];
            $montant     = trim($row[3]);
            $operationNom = Str::lower(trim($row[8] ?? ''));
            $operationId = $operationNom !== '' && isset($operations[$operationNom])
                ? $operations[$operationNom]->id
                : null;

            if (!isset($groups[$groupKey])) {
                // Première ligne de ce groupe : mode_paiement et compte sont obligatoires
                $mode      = trim($row[4] ?? '');
                $compteNom = Str::lower(trim($row[5] ?? ''));

                if ($mode === '') {
                    $errors[] = ['line' => $csvLine, 'message' => 'Colonne mode_paiement : obligatoire sur la première ligne d\'une transaction.'];
                    continue;
                }

                if (!in_array($mode, self::MODES_PAIEMENT, true)) {
                    $errors[] = ['line' => $csvLine, 'message' => 'Colonne mode_paiement : valeur "' . $mode . '" invalide. Valeurs acceptées : ' . implode(', ', self::MODES_PAIEMENT) . '.'];
                    continue;
                }

                if ($compteNom === '') {
                    $errors[] = ['line' => $csvLine, 'message' => 'Colonne compte : obligatoire sur la première ligne d\'une transaction.'];
                    continue;
                }

                if (!isset($comptes[$compteNom])) {
                    $errors[] = ['line' => $csvLine, 'message' => 'Colonne compte : "' . trim($row[5]) . '" inconnu ou inactif (actif_recettes_depenses = false).'];
                    continue;
                }

                // Résoudre le tiers (déjà validé dans validateRow)
                $tiersCsvNom = Str::lower(trim($row[7] ?? ''));
                $tiersId     = null;
                if ($tiersCsvNom !== '') {
                    $tiersId = $tiersMap[$tiersCsvNom][0]->id;
                }

                $groups[$groupKey] = [
                    'data' => [
                        'date'          => $date,
                        'reference'     => $reference,
                        'libelle'       => trim($row[6] ?? '') !== '' ? trim($row[6]) : null,
                        'mode_paiement' => $mode,
                        'compte_id'     => $comptes[$compteNom]->id,
                        'tiers_id'      => $tiersId,
                        'montant_total' => 0.0,
                    ],
                    'lignes'    => [],
                    'firstLine' => $csvLine,
                ];
                $groupOrder[] = $groupKey;
            }

            $groups[$groupKey]['lignes'][] = [
                'sous_categorie_id' => $sc->id,
                'montant'           => $montant,
                'operation_id'      => $operationId,
            ];
            $groups[$groupKey]['data']['montant_total'] =
                round($groups[$groupKey]['data']['montant_total'] + (float) $montant, 2);
        }

        // Vérifier les doublons en base (hors soft-deleted, sans filtre exercice)
        $modelClass = $type === 'depense' ? Depense::class : Recette::class;
        foreach ($groups as $key => $group) {
            [$date, $reference] = explode('|', $key, 2);
            $exists = $modelClass::withoutTrashed()
                ->where('date', $date)
                ->where('reference', $reference)
                ->exists();
            if ($exists) {
                $errors[] = [
                    'line'    => $group['firstLine'],
                    'message' => "Doublon : la transaction du {$date} avec la référence \"{$reference}\" existe déjà en base.",
                ];
            }
        }

        if (!empty($errors)) {
            return new CsvImportResult(false, errors: $errors);
        }

        // --- Phase 2 : Insertion ---
        // Chaque appel à DepenseService/RecetteService gère sa propre transaction DB.
        // Pas de transaction englobante (incompatible avec SELECT FOR UPDATE de NumeroPieceService).
        // En cas d'échec partiel, les transactions déjà committées sont conservées.

        $service             = $type === 'depense' ? app(DepenseService::class) : app(RecetteService::class);
        $transactionsCreated = 0;
        $lignesCreated       = 0;

        foreach ($groupOrder as $key) {
            $group = $groups[$key];
            try {
                $service->create($group['data'], $group['lignes']);
                $transactionsCreated++;
                $lignesCreated += count($group['lignes']);
            } catch (\Exception $e) {
                return new CsvImportResult(false, errors: [[
                    'line'    => $group['firstLine'],
                    'message' => 'Erreur lors de l\'insertion : ' . $e->getMessage(),
                ]]);
            }
        }

        return new CsvImportResult(
            success:             true,
            transactionsCreated: $transactionsCreated,
            lignesCreated:       $lignesCreated,
        );
    }

    private function readUtf8(UploadedFile $file): ?string
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            return null;
        }

        // Supprimer le BOM UTF-8 si présent
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            return null;
        }

        return $content;
    }

    /** @return list<list<string>> */
    private function parseCsv(string $content): array
    {
        $rows  = [];
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue; // Ignorer les lignes vides silencieusement
            }
            $rows[] = str_getcsv($line, ';');
        }

        return $rows;
    }

    private function validateHeader(array $row): ?string
    {
        $normalized = array_map(fn ($h) => Str::lower(trim($h)), $row);
        $missing    = array_diff(self::EXPECTED_HEADERS, $normalized);
        if (!empty($missing)) {
            return 'En-tête invalide. Colonnes manquantes : ' . implode(', ', $missing) . '.';
        }

        return null;
    }

    /**
     * Valide les champs d'une ligne (hors mode_paiement et compte, validés lors du groupement).
     *
     * @param  array<string, list<Tiers>>  $tiersMap
     * @return list<array{line: int, message: string}>
     */
    private function validateRow(
        array $row,
        int $csvLine,
        Collection $sousCategories,
        array $tiersMap,
        Collection $operations,
        string $flagField,
        string $typeLabel,
    ): array {
        $errors = [];

        // date (col 0) — format YYYY-MM-DD
        $date = trim($row[0] ?? '');
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);
        if ($date === '' || $parsed === false || $parsed->format('Y-m-d') !== $date) {
            $errors[] = ['line' => $csvLine, 'message' => "Colonne date : valeur \"{$date}\" invalide (format attendu : YYYY-MM-DD)."];
        }

        // reference (col 1)
        $reference = trim($row[1] ?? '');
        if ($reference === '') {
            $errors[] = ['line' => $csvLine, 'message' => 'Colonne reference : valeur vide (champ obligatoire).'];
        } elseif (mb_strlen($reference) > 100) {
            $errors[] = ['line' => $csvLine, 'message' => 'Colonne reference : valeur trop longue (max 100 caractères).'];
        }

        // sous_categorie (col 2)
        $scNom = Str::lower(trim($row[2] ?? ''));
        if ($scNom === '') {
            $errors[] = ['line' => $csvLine, 'message' => 'Colonne sous_categorie : valeur vide (champ obligatoire).'];
        } elseif (!isset($sousCategories[$scNom])) {
            $errors[] = ['line' => $csvLine, 'message' => "Colonne sous_categorie : \"{$row[2]}\" inconnue ou de mauvais type."];
        }

        // montant_ligne (col 3)
        $montant = trim($row[3] ?? '');
        if (!is_numeric($montant) || (float) $montant <= 0) {
            $errors[] = ['line' => $csvLine, 'message' => "Colonne montant_ligne : valeur \"{$montant}\" invalide (doit être un nombre > 0)."];
        }

        // tiers (col 7) — optionnel
        $tiersNom = Str::lower(trim($row[7] ?? ''));
        if ($tiersNom !== '') {
            $candidates = $tiersMap[$tiersNom] ?? [];
            if (empty($candidates)) {
                $errors[] = ['line' => $csvLine, 'message' => "Colonne tiers : \"{$row[7]}\" inconnu."];
            } elseif (count($candidates) > 1) {
                $nb = count($candidates);
                $errors[] = ['line' => $csvLine, 'message' => "Colonne tiers : \"{$row[7]}\" — homonyme détecté ({$nb} tiers trouvés). Résolvez l'ambiguïté en base avant l'import."];
            } elseif (!$candidates[0]->{$flagField}) {
                $errors[] = ['line' => $csvLine, 'message' => "Le tiers \"{$row[7]}\" existe mais n'est pas autorisé pour les {$typeLabel}."];
            }
        }

        // operation (col 8) — optionnel
        $opNom = Str::lower(trim($row[8] ?? ''));
        if ($opNom !== '' && !isset($operations[$opNom])) {
            $errors[] = ['line' => $csvLine, 'message' => "Colonne operation : \"{$row[8]}\" inconnue."];
        }

        return $errors;
    }
}
```

- [ ] **Step 5: Lancer les tests**

```bash
./vendor/bin/sail artisan test --filter=CsvImportServiceTest
```
Expected: PASS. Si des tests échouent, corriger avant de passer à la suite.

- [ ] **Step 6: Lancer la suite complète**

```bash
./vendor/bin/sail artisan test
```
Expected: Tous les tests PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/CsvImportResult.php app/Services/CsvImportService.php \
        tests/Feature/CsvImportServiceTest.php
git commit -m "feat: CsvImportService avec validation exhaustive et insertion via services existants"
```

---

## Chunk 2 — UI et Intégration

### Task 4: Composant Livewire ImportCsv + vue

> Peut tourner en parallèle avec Task 5.

**Files:**
- Create: `app/Livewire/ImportCsv.php`
- Create: `resources/views/livewire/import-csv.blade.php`

**Contexte codebase :**
- Pattern Livewire du projet : `final class`, `declare(strict_types=1)`, méthodes typées
- `WithFileUploads` fourni par Livewire 4 — activer dans le composant
- Upload max : 2 Mo (Sail par défaut = 8 Mo, aucune config à changer)
- L'événement `csv-imported` sera écouté par `DepenseList` et `RecetteList` (ajouté dans Task 6)
- Bootstrap 5 CDN — pas de Tailwind, pas de Vite
- La barre "Importer" + "Télécharger le modèle" est intégrée dans la vue du composant

- [ ] **Step 1: Créer le composant Livewire ImportCsv**

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\CsvImportService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

final class ImportCsv extends Component
{
    use WithFileUploads;

    public string $type; // 'depense' ou 'recette'

    public bool $showPanel = false;

    /** @var array<int, array{line: int, message: string}>|null */
    public ?array $errors = null;

    public ?string $successMessage = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $csvFile = null;

    public function togglePanel(): void
    {
        $this->showPanel = !$this->showPanel;
        $this->reset(['errors', 'successMessage', 'csvFile']);
    }

    public function import(): void
    {
        $this->validate([
            'csvFile' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ], [
            'csvFile.required' => 'Veuillez sélectionner un fichier.',
            'csvFile.mimes'    => 'Le fichier doit être au format CSV.',
            'csvFile.max'      => 'Le fichier ne doit pas dépasser 2 Mo.',
        ]);

        $result = app(CsvImportService::class)->import($this->csvFile, $this->type);

        if ($result->success) {
            $label                = $this->type === 'depense' ? 'dépenses' : 'recettes';
            $this->successMessage = "Import réussi : {$result->transactionsCreated} transaction(s) créée(s) ({$result->lignesCreated} ligne(s) comptable(s)).";
            $this->errors         = null;
            $this->csvFile        = null;
            $this->dispatch('csv-imported');
        } else {
            $this->errors         = $result->errors;
            $this->successMessage = null;
        }
    }

    public function render(): View
    {
        $label         = $this->type === 'depense' ? 'dépenses' : 'recettes';
        $templateRoute = $this->type === 'depense' ? 'depenses.import.template' : 'recettes.import.template';

        return view('livewire.import-csv', compact('label', 'templateRoute'));
    }
}
```

- [ ] **Step 2: Créer la vue import-csv.blade.php**

```blade
<div>
    {{-- Barre d'action import --}}
    <div class="d-flex gap-2 mb-3">
        <button type="button" wire:click="togglePanel"
                class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-upload"></i> Importer
        </button>
        <a href="{{ route($templateRoute) }}"
           class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-download"></i> Télécharger le modèle
        </a>
    </div>

    @if ($showPanel)
        <div class="card mb-4 border-secondary">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Importer des {{ $label }} — CSV</span>
                <button type="button" wire:click="togglePanel" class="btn-close"></button>
            </div>
            <div class="card-body">
                <form wire:submit="import">
                    <div class="mb-3">
                        <input type="file" wire:model="csvFile" accept=".csv"
                               class="form-control @error('csvFile') is-invalid @enderror">
                        @error('csvFile')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"
                            wire:loading.attr="disabled">
                        <span wire:loading wire:target="import">
                            <span class="spinner-border spinner-border-sm"></span>
                        </span>
                        Lancer l'import
                    </button>
                </form>

                @if ($successMessage)
                    <div class="alert alert-success mt-3 mb-0">
                        <i class="bi bi-check-circle-fill"></i> {{ $successMessage }}
                    </div>
                @endif

                @if ($errors)
                    <div class="mt-3">
                        <div class="alert alert-danger py-2 mb-2">
                            <i class="bi bi-x-circle-fill"></i>
                            <strong>{{ count($errors) }} erreur(s) détectée(s) — aucune donnée importée</strong>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                    <tr>
                                        <th style="width:80px">Ligne</th>
                                        <th>Erreur</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($errors as $err)
                                        <tr>
                                            <td class="text-center text-muted small">
                                                {{ $err['line'] > 0 ? $err['line'] : '—' }}
                                            </td>
                                            <td class="small">{{ $err['message'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 3: Commit**

```bash
git add app/Livewire/ImportCsv.php resources/views/livewire/import-csv.blade.php
git commit -m "feat: composant Livewire ImportCsv avec panneau upload et affichage erreurs"
```

---

### Task 5: CsvImportController + routes

> Peut tourner en parallèle avec Task 4.

**Files:**
- Create: `app/Http/Controllers/CsvImportController.php`
- Create: `tests/Feature/CsvImportControllerTest.php`
- Modify: `routes/web.php`

**Contexte codebase :**
- Toutes les routes sont dans `Route::middleware('auth')` dans `routes/web.php`.
- Le CSV template est téléchargeable directement (pas de Livewire) — route GET standard.
- Le BOM UTF-8 est ajouté au début du fichier pour compatibilité Excel (l'import le détecte et le supprime).

- [ ] **Step 1: Écrire les tests pour la route template**

Créer `tests/Feature/CsvImportControllerTest.php` :

```php
<?php

use App\Models\User;

it('telecharge le template depense', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('depenses.import.template'));

    $response->assertOk();
    // Note : Symfony normalise sans espace après ';' → 'text/csv;charset=UTF-8'
    $response->assertHeader('content-type', 'text/csv;charset=UTF-8');
    expect($response->streamedContent())->toContain('date;reference;sous_categorie');
});

it('telecharge le template recette', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('recettes.import.template'));

    $response->assertOk();
    expect($response->streamedContent())->toContain('date;reference;sous_categorie');
});

it('redirige vers login si non authentifie pour le template depense', function () {
    $this->get(route('depenses.import.template'))->assertRedirect(route('login'));
});
```

- [ ] **Step 2: Vérifier que les tests échouent (routes pas encore définies)**

```bash
./vendor/bin/sail artisan test --filter=CsvImportControllerTest
```
Expected: FAIL — route not found

- [ ] **Step 3: Créer CsvImportController**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\StreamedResponse;

final class CsvImportController extends Controller
{
    public function template(string $type): StreamedResponse
    {
        $filename = "modele-{$type}.csv";

        $header = ['date', 'reference', 'sous_categorie', 'montant_ligne', 'mode_paiement', 'compte', 'libelle', 'tiers', 'operation'];

        $examples = match ($type) {
            'recette' => [
                ['2024-09-15', 'REC-001', 'Cotisation annuelle', '50.00', 'virement', 'Compte principal', 'Cotisation 2024', 'Jean DUPONT', ''],
                ['2024-09-15', 'REC-001', 'Don général', '100.00', '', '', '', '', ''],
                ['2024-09-20', 'REC-002', 'Subvention', '500.00', 'virement', 'Compte principal', 'Subvention mairie', '', 'AG 2024'],
            ],
            default => [
                ['2024-09-15', 'FAC-001', 'Fournitures', '100.00', 'virement', 'Compte principal', 'Achat papeterie', 'MAISON DUPONT', ''],
                ['2024-09-15', 'FAC-001', 'Communication', '50.00', '', '', '', '', ''],
                ['2024-09-20', 'CHQ-042', 'Déplacements', '75.00', 'cheque', 'Compte principal', 'Frais déplacement', '', 'AG 2024'],
            ],
        };

        return response()->streamDownload(function () use ($header, $examples): void {
            $handle = fopen('php://output', 'w');
            assert($handle !== false);
            // BOM UTF-8 pour compatibilité Excel
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $header, ';');
            foreach ($examples as $row) {
                fputcsv($handle, $row, ';');
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
```

- [ ] **Step 4: Ajouter les routes dans routes/web.php**

Dans le groupe `Route::middleware('auth')->group(function () { ... })`, ajouter ces deux routes. Les placer n'importe où dans le groupe (Laravel résout `/depenses/import/template` correctement même si `/depenses` est une `Route::view` définie ailleurs) :

```php
// Import CSV — templates téléchargeables
Route::get('/depenses/import/template', [\App\Http\Controllers\CsvImportController::class, 'template'])
    ->defaults('type', 'depense')
    ->name('depenses.import.template');

Route::get('/recettes/import/template', [\App\Http\Controllers\CsvImportController::class, 'template'])
    ->defaults('type', 'recette')
    ->name('recettes.import.template');
```

- [ ] **Step 5: Lancer les tests**

```bash
./vendor/bin/sail artisan test --filter=CsvImportControllerTest
```
Expected: PASS (3 tests)

- [ ] **Step 6: Vérifier l'enregistrement des routes**

```bash
./vendor/bin/sail artisan route:list | grep import
```
Expected : 2 lignes, `/depenses/import/template` et `/recettes/import/template`

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/CsvImportController.php routes/web.php \
        tests/Feature/CsvImportControllerTest.php
git commit -m "feat: CsvImportController et routes de telechargement du template CSV"
```

---

### Task 6: Intégration — DepenseList, RecetteList et vues index

**Files:**
- Modify: `app/Livewire/DepenseList.php`
- Modify: `app/Livewire/RecetteList.php`
- Modify: `resources/views/depenses/index.blade.php`
- Modify: `resources/views/recettes/index.blade.php`

**Contexte codebase :**
- `DepenseList` écoute `depense-saved` via `#[On('depense-saved')]` (méthode `onDepenseSaved()`, corps vide)
- `RecetteList` idem avec `recette-saved`
- `depenses/index.blade.php` contient : `<livewire:depense-form />` et `<livewire:depense-list />`
- `recettes/index.blade.php` : même structure avec les composants recette
- `resetPage()` déclenche un re-render automatique de Livewire — c'est la bonne approche ici (remet à la page 1 après import)

- [ ] **Step 1: Ajouter l'écouteur csv-imported dans DepenseList**

Dans `app/Livewire/DepenseList.php`, après la méthode `onDepenseSaved()`, ajouter :

```php
#[On('csv-imported')]
public function onCsvImported(): void
{
    $this->resetPage();
}
```

- [ ] **Step 2: Ajouter l'écouteur csv-imported dans RecetteList**

Dans `app/Livewire/RecetteList.php`, après la méthode `onRecetteSaved()` (ou équivalent), ajouter :

```php
#[On('csv-imported')]
public function onCsvImported(): void
{
    $this->resetPage();
}
```

- [ ] **Step 3: Mettre à jour depenses/index.blade.php**

Lire le fichier actuel, puis ajouter `<livewire:import-csv type="depense" />` entre `depense-form` et `depense-list` (ne pas remplacer le fichier en entier — éditer pour insérer la ligne) :

```blade
<x-app-layout>
    <h1 class="mb-4">Dépenses</h1>
    <livewire:depense-form />
    <livewire:import-csv type="depense" />
    <livewire:depense-list />
</x-app-layout>
```

- [ ] **Step 4: Mettre à jour recettes/index.blade.php**

Lire le fichier actuel, puis ajouter `<livewire:import-csv type="recette" />` entre le composant formulaire et la liste (même logique que l'étape 3 mais pour les recettes). Ne pas remplacer le fichier en entier — éditer pour ajouter la ligne manquante.

- [ ] **Step 5: Lancer toute la suite de tests**

```bash
./vendor/bin/sail artisan test
```
Expected: Tous les tests PASS

- [ ] **Step 6: Commit final**

```bash
git add app/Livewire/DepenseList.php app/Livewire/RecetteList.php \
        resources/views/depenses/index.blade.php resources/views/recettes/index.blade.php
git commit -m "feat: integration import CSV dans les ecrans depenses et recettes"
```
