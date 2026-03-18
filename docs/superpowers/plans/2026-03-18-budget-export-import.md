# Budget Export / Import — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre l'export du budget en CSV ou Excel et son réimport pour faciliter la reconduction annuelle et le travail hors application.

**Architecture:** `BudgetExportService` génère les lignes de données, `BudgetExportController` les formate en CSV ou XLSX et les retourne en téléchargement. `BudgetImportService` valide et charge un fichier CSV/XLSX. Les boutons Export/Import sont intégrés dans le composant Livewire `BudgetTable` existant.

**Tech Stack:** Laravel 11, Livewire 4, Pest PHP, `maatwebsite/excel` (à installer), Bootstrap 5

---

## File Map

| Action | Fichier | Rôle |
|--------|---------|------|
| Créer | `app/Services/BudgetImportResult.php` | DTO résultat import (calqué sur CsvImportResult) |
| Créer | `app/Services/BudgetExportService.php` | Génère les lignes export + CSV string |
| Créer | `app/Exports/BudgetExport.php` | Implémentation maatwebsite/excel pour XLSX |
| Créer | `app/Http/Controllers/BudgetExportController.php` | Route GET /budget/export → fichier |
| Créer | `app/Services/BudgetImportService.php` | Valide + importe un fichier budget |
| Modifier | `app/Livewire/BudgetTable.php` | Ajouter props export + action export(), WithFileUploads + import() |
| Modifier | `resources/views/livewire/budget-table.blade.php` | Modal export + panel import |
| Modifier | `routes/web.php` | Route GET budget.export |
| Créer | `tests/Feature/BudgetExportServiceTest.php` | Tests BudgetExportService |
| Créer | `tests/Feature/BudgetExportControllerTest.php` | Tests contrôleur export |
| Créer | `tests/Feature/BudgetImportServiceTest.php` | Tests BudgetImportService |

---

## Task 1 — BudgetImportResult + BudgetExportService

**Files:**
- Create: `app/Services/BudgetImportResult.php`
- Create: `app/Services/BudgetExportService.php`
- Create: `tests/Feature/BudgetExportServiceTest.php`

- [ ] **Step 1 : Écrire les tests BudgetExportService**

```php
// tests/Feature/BudgetExportServiceTest.php
<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Services\BudgetExportService;

beforeEach(function () {
    // Catégories dépenses
    $catCharge = Categorie::factory()->create(['nom' => 'Charges', 'type' => TypeCategorie::Depense]);
    $this->scLoyers = SousCategorie::factory()->create(['nom' => 'Loyers', 'categorie_id' => $catCharge->id]);
    $this->scElec   = SousCategorie::factory()->create(['nom' => 'Électricité', 'categorie_id' => $catCharge->id]);

    // Catégories recettes
    $catProduit = Categorie::factory()->create(['nom' => 'Produits', 'type' => TypeCategorie::Recette]);
    $this->scCotis = SousCategorie::factory()->create(['nom' => 'Cotisations', 'categorie_id' => $catProduit->id]);

    // Budget 2025 : Loyers=1200, Électricité=0, Cotisations=850
    BudgetLine::factory()->create(['sous_categorie_id' => $this->scLoyers->id, 'exercice' => 2025, 'montant_prevu' => 1200.00]);
    BudgetLine::factory()->create(['sous_categorie_id' => $this->scElec->id,   'exercice' => 2025, 'montant_prevu' => 0.00]);
    BudgetLine::factory()->create(['sous_categorie_id' => $this->scCotis->id,  'exercice' => 2025, 'montant_prevu' => 850.00]);
});

it('retourne les lignes dans l\'ordre dépenses puis recettes', function () {
    $rows = app(BudgetExportService::class)->rows(2026, null);

    // Charges avant Produits
    $noms = array_column($rows, 1);
    $posLoyers = array_search('Loyers', $noms);
    $posCotis  = array_search('Cotisations', $noms);
    expect($posLoyers)->toBeLessThan($posCotis);
});

it('met l\'exercice cible dans la première colonne', function () {
    $rows = app(BudgetExportService::class)->rows(2026, null);

    foreach ($rows as $row) {
        expect($row[0])->toBe('2026');
    }
});

it('source null (zéro partout) produit des montants vides', function () {
    $rows = app(BudgetExportService::class)->rows(2026, null);

    foreach ($rows as $row) {
        expect($row[2])->toBe('');
    }
});

it('source 2025 remplit les montants non nuls, laisse vide les zéros', function () {
    $rows = app(BudgetExportService::class)->rows(2026, 2025);

    $byName = array_column($rows, null, 1);
    expect($byName['Loyers'][2])->toBe('1200.00');
    expect($byName['Électricité'][2])->toBe('');   // montant=0 → vide
    expect($byName['Cotisations'][2])->toBe('850.00');
});

it('source N-1 absente produit des montants vides', function () {
    $rows = app(BudgetExportService::class)->rows(2026, 2024); // pas de données 2024

    foreach ($rows as $row) {
        expect($row[2])->toBe('');
    }
});

it('toCsv génère un CSV valide avec en-tête', function () {
    $rows = [
        ['2026', 'Loyers', '1200.00'],
        ['2026', 'Électricité', ''],
    ];

    $csv = app(BudgetExportService::class)->toCsv($rows);

    expect($csv)
        ->toContain('exercice;sous_categorie;montant_prevu')
        ->toContain('2026;Loyers;1200.00')
        ->toContain('2026;Électricité;');
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/BudgetExportServiceTest.php
```
Résultat attendu : FAIL (classe inexistante)

- [ ] **Step 3 : Créer BudgetImportResult**

```php
// app/Services/BudgetImportResult.php
<?php

declare(strict_types=1);

namespace App\Services;

final class BudgetImportResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $linesImported = 0,
        public readonly array $errors = [], // [['line' => int, 'message' => string]]
    ) {}
}
```

- [ ] **Step 4 : Créer BudgetExportService**

```php
// app/Services/BudgetExportService.php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeCategorie;
use App\Models\BudgetLine;
use App\Models\Categorie;

final class BudgetExportService
{
    /**
     * Retourne les lignes d'export triées dépenses puis recettes.
     *
     * @param  int       $exerciceCible  Valeur à écrire dans la colonne exercice
     * @param  int|null  $sourceExercice Budget source pour les montants ; null = zéro partout
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function rows(int $exerciceCible, ?int $sourceExercice): array
    {
        $budgetLines = $sourceExercice !== null
            ? BudgetLine::forExercice($sourceExercice)->get()->keyBy('sous_categorie_id')
            : collect();

        $rows = [];

        foreach ([TypeCategorie::Depense, TypeCategorie::Recette] as $type) {
            $categories = Categorie::where('type', $type)
                ->with(['sousCategories' => fn ($q) => $q->orderBy('nom')])
                ->orderBy('nom')
                ->get();

            foreach ($categories as $categorie) {
                foreach ($categorie->sousCategories as $sc) {
                    $line   = $budgetLines->get($sc->id);
                    $montant = $line ? (float) $line->montant_prevu : 0.0;

                    $rows[] = [
                        (string) $exerciceCible,
                        $sc->nom,
                        $montant > 0 ? number_format($montant, 2, '.', '') : '',
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Convertit les lignes en chaîne CSV UTF-8 avec séparateur ';'.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $rows
     */
    public function toCsv(array $rows): string
    {
        $lines = ['exercice;sous_categorie;montant_prevu'];

        foreach ($rows as $row) {
            $escaped = array_map(
                fn (string $v): string => str_contains($v, ';') || str_contains($v, '"')
                    ? '"' . str_replace('"', '""', $v) . '"'
                    : $v,
                $row
            );
            $lines[] = implode(';', $escaped);
        }

        return implode("\n", $lines) . "\n";
    }
}
```

- [ ] **Step 5 : Lancer les tests et vérifier qu'ils passent**

```bash
./vendor/bin/sail artisan test tests/Feature/BudgetExportServiceTest.php
```
Résultat attendu : PASS (6 tests)

- [ ] **Step 6 : Lancer la suite complète pour détecter les régressions**

```bash
./vendor/bin/sail artisan test
```

- [ ] **Step 7 : Commit**

```bash
git add app/Services/BudgetImportResult.php app/Services/BudgetExportService.php tests/Feature/BudgetExportServiceTest.php
git commit -m "feat: BudgetImportResult + BudgetExportService (CSV)"
```

---

## Task 2 — maatwebsite/excel + BudgetExport + BudgetExportController

**Files:**
- Install: `maatwebsite/excel` via Composer
- Create: `app/Exports/BudgetExport.php`
- Create: `app/Http/Controllers/BudgetExportController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/BudgetExportControllerTest.php`

- [ ] **Step 1 : Écrire les tests du contrôleur**

```php
// tests/Feature/BudgetExportControllerTest.php
<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();

    $cat = Categorie::factory()->create(['nom' => 'Charges', 'type' => TypeCategorie::Depense]);
    $sc  = SousCategorie::factory()->create(['nom' => 'Loyers', 'categorie_id' => $cat->id]);
    BudgetLine::factory()->create(['sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 1200.00]);
});

it('télécharge un CSV budget', function () {
    // Forcer l'exercice courant à 2025 pour un test déterministe
    $response = $this->actingAs($this->user)
        ->withSession(['exercice_actif' => 2025])
        ->get(route('budget.export', ['format' => 'csv', 'exercice' => 2026, 'source' => 'courant']));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertDownload('budget-2026.csv');

    expect($response->getContent())
        ->toContain('exercice;sous_categorie;montant_prevu')
        ->toContain('2026;Loyers;1200.00');
});

it('source zero produit des montants vides dans le CSV', function () {
    $response = $this->actingAs($this->user)
        ->get(route('budget.export', ['format' => 'csv', 'exercice' => 2026, 'source' => 'zero']));

    $response->assertOk();
    expect($response->getContent())->toContain('2026;Loyers;');
    expect($response->getContent())->not->toContain('1200');
});

it('télécharge un Excel budget', function () {
    $response = $this->actingAs($this->user)
        ->get(route('budget.export', ['format' => 'xlsx', 'exercice' => 2026, 'source' => 'courant']));

    $response->assertOk();
    $response->assertDownload('budget-2026.xlsx');
});

it('redirige les invités vers login', function () {
    $response = $this->get(route('budget.export', ['format' => 'csv', 'exercice' => 2026, 'source' => 'zero']));
    $response->assertRedirect(route('login'));
});

it('rejette un format invalide', function () {
    $response = $this->actingAs($this->user)
        ->get(route('budget.export', ['format' => 'pdf', 'exercice' => 2026, 'source' => 'zero']));

    $response->assertStatus(422);
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/BudgetExportControllerTest.php
```
Résultat attendu : FAIL (route inexistante)

- [ ] **Step 3 : Installer maatwebsite/excel**

```bash
./vendor/bin/sail composer require maatwebsite/excel
```

Puis publier la config :
```bash
./vendor/bin/sail artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider" --tag=config
```

- [ ] **Step 4 : Créer BudgetExport (classe maatwebsite)**

```php
// app/Exports/BudgetExport.php
<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

final class BudgetExport implements FromArray, WithHeadings
{
    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $rows
     */
    public function __construct(private readonly array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return ['exercice', 'sous_categorie', 'montant_prevu'];
    }
}
```

- [ ] **Step 5 : Créer BudgetExportController**

```php
// app/Http/Controllers/BudgetExportController.php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\BudgetExport;
use App\Services\BudgetExportService;
use App\Services\ExerciceService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

final class BudgetExportController extends Controller
{
    public function __invoke(Request $request, BudgetExportService $service, ExerciceService $exerciceService): Response
    {
        $request->validate([
            'format'   => ['required', 'in:csv,xlsx'],
            'exercice' => ['required', 'integer'],
            'source'   => ['required', 'in:zero,courant,n1'],
        ]);

        $exerciceCible  = (int) $request->exercice;
        $exerciceCourant = $exerciceService->current();

        $sourceExercice = match($request->source) {
            'zero'   => null,
            'courant' => $exerciceCourant,
            'n1'     => $exerciceCourant - 1,
        };

        $rows     = $service->rows($exerciceCible, $sourceExercice);
        $filename = "budget-{$exerciceCible}.{$request->format}";

        if ($request->format === 'xlsx') {
            return Excel::download(new BudgetExport($rows), $filename);
        }

        $csv = $service->toCsv($rows);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
```

- [ ] **Step 6 : Ajouter la route dans routes/web.php**

Trouver la ligne :
```php
Route::view('/budget', 'budget.index')->name('budget.index');
```

La remplacer par :
```php
Route::view('/budget', 'budget.index')->name('budget.index');
Route::get('/budget/export', \App\Http\Controllers\BudgetExportController::class)->name('budget.export');
```

- [ ] **Step 7 : Lancer les tests contrôleur**

```bash
./vendor/bin/sail artisan test tests/Feature/BudgetExportControllerTest.php
```
Résultat attendu : PASS (5 tests)

- [ ] **Step 8 : Lancer la suite complète**

```bash
./vendor/bin/sail artisan test
```

- [ ] **Step 9 : Commit**

```bash
git add app/Exports/BudgetExport.php app/Http/Controllers/BudgetExportController.php routes/web.php tests/Feature/BudgetExportControllerTest.php config/excel.php
git commit -m "feat: BudgetExportController + route GET /budget/export (CSV + Excel)"
```

---

## Task 3 — BudgetImportService

**Files:**
- Create: `app/Services/BudgetImportService.php`
- Create: `tests/Feature/BudgetImportServiceTest.php`

- [ ] **Step 1 : Écrire les tests BudgetImportService**

```php
// tests/Feature/BudgetImportServiceTest.php
<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\BudgetImportService;
use Illuminate\Http\UploadedFile;

function makeBudgetCsvFile(string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'budget_test_');
    file_put_contents($path, $content);
    return new UploadedFile($path, 'budget.csv', 'text/csv', null, true);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $catCharge  = Categorie::factory()->create(['nom' => 'Charges', 'type' => TypeCategorie::Depense]);
    $this->scLoyers = SousCategorie::factory()->create(['nom' => 'Loyers', 'categorie_id' => $catCharge->id]);
    $this->scElec   = SousCategorie::factory()->create(['nom' => 'Électricité', 'categorie_id' => $catCharge->id]);
});

it('importe un CSV valide et insère les lignes non nulles', function () {
    $csv = "exercice;sous_categorie;montant_prevu\n"
         . "2025;Loyers;1200.00\n"
         . "2025;Électricité;\n"; // vide → ignoré

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeTrue()
        ->and($result->linesImported)->toBe(1);

    expect(BudgetLine::where('exercice', 2025)->count())->toBe(1);
    expect(BudgetLine::where('sous_categorie_id', $this->scLoyers->id)->value('montant_prevu'))->toBe('1200.00');
});

it('ignore les lignes avec montant à zéro', function () {
    $csv = "exercice;sous_categorie;montant_prevu\n"
         . "2025;Loyers;0\n"
         . "2025;Électricité;0.00\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeTrue()
        ->and($result->linesImported)->toBe(0);

    expect(BudgetLine::where('exercice', 2025)->count())->toBe(0);
});

it('supprime les lignes existantes de l\'exercice avant import', function () {
    BudgetLine::factory()->create(['sous_categorie_id' => $this->scLoyers->id, 'exercice' => 2025, 'montant_prevu' => 999]);
    BudgetLine::factory()->create(['sous_categorie_id' => $this->scLoyers->id, 'exercice' => 2024, 'montant_prevu' => 500]); // autre exercice

    $csv = "exercice;sous_categorie;montant_prevu\n"
         . "2025;Électricité;300.00\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeTrue();
    // L'ancienne ligne 2025 est supprimée
    expect(BudgetLine::where('sous_categorie_id', $this->scLoyers->id)->where('exercice', 2025)->exists())->toBeFalse();
    // La ligne 2024 est préservée
    expect(BudgetLine::where('exercice', 2024)->count())->toBe(1);
});

it('rejette si l\'en-tête est invalide', function () {
    $csv = "exercice;nom_sc;montant\n2025;Loyers;100\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('En-tête invalide');
});

it('rejette si l\'exercice dans le fichier ne correspond pas', function () {
    $csv = "exercice;sous_categorie;montant_prevu\n"
         . "2024;Loyers;100.00\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('2024')
        ->and($result->errors[0]['message'])->toContain('2025');
});

it('liste tous les exercices incorrects distincts dans le message d\'erreur', function () {
    $csv = "exercice;sous_categorie;montant_prevu\n"
         . "2024;Loyers;100.00\n"
         . "2023;Électricité;200.00\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('2023')
        ->and($result->errors[0]['message'])->toContain('2024');
});

it('rejette si une sous-catégorie est introuvable', function () {
    $csv = "exercice;sous_categorie;montant_prevu\n"
         . "2025;Inconnu;100.00\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('Inconnu')
        ->and($result->errors[0]['line'])->toBe(2);
});

it('rejette si une sous-catégorie est ambiguë (doublon de nom)', function () {
    $cat2 = Categorie::factory()->create(['nom' => 'Produits', 'type' => TypeCategorie::Recette]);
    SousCategorie::factory()->create(['nom' => 'Loyers', 'categorie_id' => $cat2->id]); // doublon !

    $csv = "exercice;sous_categorie;montant_prevu\n"
         . "2025;Loyers;100.00\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('ambigu');
});

it('rejette si un montant est invalide (négatif)', function () {
    $csv = "exercice;sous_categorie;montant_prevu\n"
         . "2025;Loyers;-50.00\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('invalide');
});

it('rejette si un montant est invalide (non numérique)', function () {
    $csv = "exercice;sous_categorie;montant_prevu\n"
         . "2025;Loyers;abc\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse();
});

it('n\'insère rien si validation échoue (atomicité)', function () {
    BudgetLine::factory()->create(['sous_categorie_id' => $this->scLoyers->id, 'exercice' => 2025, 'montant_prevu' => 999]);

    $csv = "exercice;sous_categorie;montant_prevu\n"
         . "2025;Loyers;100.00\n"
         . "2025;Inconnu;200.00\n"; // erreur

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse();
    // La ligne existante est préservée car aucune suppression n'a eu lieu
    expect(BudgetLine::where('exercice', 2025)->count())->toBe(1);
    expect(BudgetLine::where('exercice', 2025)->value('montant_prevu'))->toBe('999.00');
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/BudgetImportServiceTest.php
```
Résultat attendu : FAIL (classe inexistante)

- [ ] **Step 3 : Créer BudgetImportService**

```php
// app/Services/BudgetImportService.php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\SousCategorie;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

final class BudgetImportService
{
    private const EXPECTED_HEADERS = ['exercice', 'sous_categorie', 'montant_prevu'];

    public function import(UploadedFile $file, int $exercice): BudgetImportResult
    {
        $rows = $this->parseFile($file);

        if ($rows === null) {
            return new BudgetImportResult(false, errors: [['line' => 0, 'message' => 'Fichier illisible ou format non supporté.']]);
        }

        if (empty($rows)) {
            return new BudgetImportResult(false, errors: [['line' => 0, 'message' => 'Le fichier est vide.']]);
        }

        // Valider l'en-tête
        $headerError = $this->validateHeader($rows[0]);
        if ($headerError !== null) {
            return new BudgetImportResult(false, errors: [['line' => 1, 'message' => $headerError]]);
        }

        $dataRows = array_slice($rows, 1);

        // Charger toutes les sous-catégories indexées par nom (lowercase)
        // Détecte les homonymes : clé => [SousCategorie, ...]
        /** @var array<string, list<SousCategorie>> */
        $scByName = [];
        foreach (SousCategorie::all() as $sc) {
            $key = Str::lower(trim($sc->nom));
            $scByName[$key][] = $sc;
        }

        $errors           = [];
        $wrongExercices   = [];

        foreach ($dataRows as $idx => $row) {
            $lineNum       = $idx + 2;
            $exerciceCell  = trim((string) ($row[0] ?? ''));
            $scNom         = trim((string) ($row[1] ?? ''));
            $montantCell   = trim((string) ($row[2] ?? ''));

            // Exercice
            if ($exerciceCell !== (string) $exercice) {
                $wrongExercices[] = $exerciceCell;
            }

            // Sous-catégorie
            if ($scNom === '') {
                $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : sous-catégorie vide (champ obligatoire)."];
            } elseif (!isset($scByName[Str::lower($scNom)])) {
                $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : sous-catégorie '{$scNom}' introuvable."];
            } elseif (count($scByName[Str::lower($scNom)]) > 1) {
                $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : nom '{$scNom}' ambigu (plusieurs sous-catégories portent ce nom)."];
            }

            // Montant : vide et 0/0.00 sont acceptés, négatif ou non-numérique sont des erreurs
            if ($montantCell !== '' && $montantCell !== '0' && $montantCell !== '0.00') {
                if (!is_numeric($montantCell) || (float) $montantCell < 0) {
                    $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : montant_prevu '{$montantCell}' invalide (nombre >= 0 attendu ou cellule vide)."];
                }
            }
        }

        // Erreur exercice : rapport groupé
        if (!empty($wrongExercices)) {
            $unique = array_unique($wrongExercices);
            sort($unique);
            $list   = implode(', ', $unique);
            $errors = array_merge([['line' => 0, 'message' => "Le fichier contient les exercices {$list}, l'exercice ouvert est {$exercice}."]], $errors);
        }

        if (!empty($errors)) {
            return new BudgetImportResult(false, errors: $errors);
        }

        // Insertion dans une transaction DB
        $inserted = 0;

        DB::transaction(function () use ($dataRows, $exercice, $scByName, &$inserted) {
            BudgetLine::where('exercice', $exercice)->delete();

            foreach ($dataRows as $row) {
                $scNom       = trim((string) ($row[1] ?? ''));
                $montantCell = trim((string) ($row[2] ?? ''));

                // Ignorer montant vide ou zéro
                if ($montantCell === '' || $montantCell === '0' || $montantCell === '0.00') {
                    continue;
                }

                if (!is_numeric($montantCell) || (float) $montantCell <= 0) {
                    continue;
                }

                $sc = $scByName[Str::lower($scNom)][0];

                BudgetLine::create([
                    'sous_categorie_id' => $sc->id,
                    'exercice'          => $exercice,
                    'montant_prevu'     => (float) $montantCell,
                ]);

                $inserted++;
            }
        });

        return new BudgetImportResult(true, linesImported: $inserted);
    }

    /**
     * Retourne les lignes du fichier (tableau 2D de strings), ou null en cas d'erreur.
     *
     * @return list<list<string>>|null
     */
    private function parseFile(UploadedFile $file): ?array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'xlsx') {
            return $this->parseXlsx($file);
        }

        return $this->parseCsv($file);
    }

    /** @return list<list<string>>|null */
    private function parseCsv(UploadedFile $file): ?array
    {
        $content = file_get_contents($file->getRealPath());

        if ($content === false) {
            return null;
        }

        // Supprimer BOM UTF-8
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            return null;
        }

        $rows  = [];
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = array_map('strval', str_getcsv($line, ';'));
        }

        return $rows;
    }

    /** @return list<list<string>>|null */
    private function parseXlsx(UploadedFile $file): ?array
    {
        $import = new class implements ToArray {
            /** @var list<list<string>> */
            public array $data = [];

            public function array(array $array): void
            {
                $this->data = array_map(
                    fn (array $row): array => array_map(fn ($v): string => (string) ($v ?? ''), $row),
                    $array
                );
            }
        };

        try {
            Excel::import($import, $file);
        } catch (\Throwable) {
            return null;
        }

        return $import->data;
    }

    private function validateHeader(array $row): ?string
    {
        $normalized = array_map(fn ($h) => Str::lower(trim($h)), $row);
        $missing    = array_diff(self::EXPECTED_HEADERS, $normalized);

        if (!empty($missing)) {
            return 'En-tête invalide. Colonnes manquantes ou incorrectes : ' . implode(', ', $missing) . '.';
        }

        return null;
    }
}
```

- [ ] **Step 4 : Lancer les tests import service**

```bash
./vendor/bin/sail artisan test tests/Feature/BudgetImportServiceTest.php
```
Résultat attendu : PASS (11 tests)

- [ ] **Step 5 : Lancer la suite complète**

```bash
./vendor/bin/sail artisan test
```

- [ ] **Step 6 : Commit**

```bash
git add app/Services/BudgetImportService.php tests/Feature/BudgetImportServiceTest.php
git commit -m "feat: BudgetImportService (validation + import CSV/Excel)"
```

---

## Task 4 — BudgetTable Livewire + vue

**Files:**
- Modify: `app/Livewire/BudgetTable.php`
- Modify: `resources/views/livewire/budget-table.blade.php`

Cette tâche intègre le modal d'export et le panel d'import dans le composant existant. Pas de nouveau fichier de test séparé : la logique est dans les services (déjà testés). On vérifie manuellement dans le navigateur.

- [ ] **Step 1 : Modifier BudgetTable.php**

Remplacer le contenu de `app/Livewire/BudgetTable.php` :

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\TypeCategorie;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Services\BudgetExportService;
use App\Services\BudgetImportService;
use App\Services\BudgetService;
use App\Services\ExerciceService;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class BudgetTable extends Component
{
    use WithFileUploads;

    // ── Edition inline ────────────────────────────────────────────────────────
    public ?int $editingLineId = null;
    public string $editingMontant = '';

    // ── Export ────────────────────────────────────────────────────────────────
    public bool $showExportModal = false;
    public string $exportFormat   = 'csv';
    public string $exportExercice = 'courant'; // 'courant' | 'suivant'
    public string $exportSource   = 'courant'; // 'zero' | 'courant' | 'n1'

    // ── Import ────────────────────────────────────────────────────────────────
    public bool $showImportPanel = false;

    #[Validate(['file', 'mimes:csv,txt,xlsx', 'max:2048'])]
    public ?TemporaryUploadedFile $budgetFile = null;

    /** @var list<array{line: int, message: string}>|null */
    public ?array $importErrors = null;

    public ?string $importSuccess = null;

    // ── Actions édition ───────────────────────────────────────────────────────

    public function addLine(int $sousCategorieId): void
    {
        BudgetLine::create([
            'sous_categorie_id' => $sousCategorieId,
            'exercice'          => app(ExerciceService::class)->current(),
            'montant_prevu'     => 0,
        ]);
    }

    public function startEdit(int $lineId): void
    {
        $line                  = BudgetLine::findOrFail($lineId);
        $this->editingLineId   = $lineId;
        $this->editingMontant  = (string) $line->montant_prevu;
    }

    public function saveEdit(): void
    {
        $this->validate(['editingMontant' => ['required', 'numeric', 'min:0']]);

        BudgetLine::findOrFail($this->editingLineId)->update(['montant_prevu' => $this->editingMontant]);
        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->editingLineId  = null;
        $this->editingMontant = '';
    }

    public function deleteLine(int $lineId): void
    {
        BudgetLine::findOrFail($lineId)->delete();
    }

    // ── Actions export ────────────────────────────────────────────────────────

    public function openExportModal(): void
    {
        $this->showExportModal = true;
    }

    public function closeExportModal(): void
    {
        $this->showExportModal = false;
    }

    public function export(): void
    {
        $exerciceService = app(ExerciceService::class);
        $exerciceCible   = $this->exportExercice === 'suivant'
            ? $exerciceService->current() + 1
            : $exerciceService->current();

        $url = route('budget.export', [
            'format'   => $this->exportFormat,
            'exercice' => $exerciceCible,
            'source'   => $this->exportSource,
        ]);

        $this->js("window.location.href = '{$url}'");
        $this->showExportModal = false;
    }

    // ── Actions import ────────────────────────────────────────────────────────

    public function toggleImportPanel(): void
    {
        $this->showImportPanel = !$this->showImportPanel;

        if (!$this->showImportPanel) {
            $this->importErrors  = null;
            $this->importSuccess = null;
            $this->budgetFile    = null;
            $this->resetValidation();
        }
    }

    public function importBudget(): void
    {
        $this->validate();

        $exercice = app(ExerciceService::class)->current();
        $result   = app(BudgetImportService::class)->import($this->budgetFile, $exercice);

        if ($result->success) {
            $exerciceLabel       = app(ExerciceService::class)->label($exercice);
            $this->importSuccess = "{$result->linesImported} lignes importées pour l'exercice {$exerciceLabel}.";
            $this->importErrors  = null;
            $this->budgetFile    = null;
            $this->resetValidation();
        } else {
            $this->importErrors  = $result->errors;
            $this->importSuccess = null;
        }
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): \Illuminate\View\View
    {
        $budgetService = app(BudgetService::class);
        $exercice      = app(ExerciceService::class)->current();

        $depenseCategories = Categorie::where('type', TypeCategorie::Depense)
            ->with(['sousCategories' => fn ($q) => $q->orderBy('nom')])
            ->orderBy('nom')
            ->get();

        $recetteCategories = Categorie::where('type', TypeCategorie::Recette)
            ->with(['sousCategories' => fn ($q) => $q->orderBy('nom')])
            ->orderBy('nom')
            ->get();

        $budgetLines = BudgetLine::forExercice($exercice)->get()->keyBy('sous_categorie_id');

        $realiseData    = [];
        $allSousCategories = $depenseCategories->flatMap->sousCategories
            ->merge($recetteCategories->flatMap->sousCategories);

        foreach ($allSousCategories as $sc) {
            $realiseData[$sc->id] = $budgetService->realise($sc->id, $exercice);
        }

        return view('livewire.budget-table', [
            'depenseCategories' => $depenseCategories,
            'recetteCategories' => $recetteCategories,
            'budgetLines'       => $budgetLines,
            'realiseData'       => $realiseData,
            'exerciceLabel'     => app(ExerciceService::class)->label($exercice),
            'exportExerciceCourant' => $exercice,
            'exportExerciceSuivant' => $exercice + 1,
        ]);
    }
}
```

- [ ] **Step 2 : Ajouter les boutons Export/Import + modal export + panel import dans la vue**

En haut de `resources/views/livewire/budget-table.blade.php`, ajouter juste après `<div>` :

```blade
{{-- Boutons Export / Import --}}
<div class="d-flex gap-2 mb-3">
    <button wire:click="openExportModal" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-download"></i> Exporter
    </button>
    <button wire:click="toggleImportPanel" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-upload"></i> Importer
    </button>
</div>

{{-- Modal Export --}}
@if ($showExportModal)
<div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5)">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Exporter le budget</h5>
                <button wire:click="closeExportModal" type="button" class="btn-close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Format</label>
                    <select wire:model="exportFormat" class="form-select">
                        <option value="csv">CSV</option>
                        <option value="xlsx">Excel (.xlsx)</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Exercice à écrire dans le fichier</label>
                    <select wire:model="exportExercice" class="form-select">
                        <option value="courant">Exercice courant ({{ $exportExerciceCourant }}-{{ $exportExerciceCourant + 1 }})</option>
                        <option value="suivant">Exercice suivant ({{ $exportExerciceSuivant }}-{{ $exportExerciceSuivant + 1 }})</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Montants à inclure</label>
                    <select wire:model="exportSource" class="form-select">
                        <option value="zero">Zéro partout (cellules vides)</option>
                        <option value="courant">Montants de l'exercice courant</option>
                        <option value="n1">Montants de l'exercice N-1</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button wire:click="closeExportModal" type="button" class="btn btn-secondary">Annuler</button>
                <button wire:click="export" type="button" class="btn btn-primary">
                    <i class="bi bi-download"></i> Télécharger
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Panel Import --}}
@if ($showImportPanel)
<div class="card mb-3 border-warning">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Importer le budget — exercice {{ $exerciceLabel }}</span>
        <button wire:click="toggleImportPanel" type="button" class="btn-close"></button>
    </div>
    <div class="card-body">
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            L'import supprimera toutes les lignes budgétaires existantes pour l'exercice {{ $exerciceLabel }} avant de charger les nouvelles données.
            Les montants vides ou nuls ne sont pas chargés. Cette action est irréversible.
        </div>

        @if ($importSuccess)
            <div class="alert alert-success">{{ $importSuccess }}</div>
        @endif

        @if ($importErrors)
            <div class="alert alert-danger">
                <strong>Erreurs de validation :</strong>
                <ul class="mb-0 mt-1">
                    @foreach ($importErrors as $error)
                        <li>{{ $error['line'] > 0 ? "Ligne {$error['line']} : " : '' }}{{ $error['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mb-3">
            <label class="form-label">Fichier budget (CSV ou Excel)</label>
            <input type="file" wire:model="budgetFile" accept=".csv,.txt,.xlsx" class="form-control">
            @error('budgetFile') <span class="text-danger small">{{ $message }}</span> @enderror
        </div>
        <button wire:click="importBudget" class="btn btn-warning" wire:loading.attr="disabled">
            <span wire:loading wire:target="importBudget" class="spinner-border spinner-border-sm"></span>
            Valider l'import
        </button>
    </div>
</div>
@endif
```

- [ ] **Step 3 : Vérifier manuellement dans le navigateur**

1. Naviguer vers `http://localhost/budget`
2. Cliquer "Exporter" → modal s'ouvre avec les 3 selects
3. Choisir CSV + exercice suivant + montants courants → cliquer Télécharger → fichier téléchargé
4. Ouvrir le fichier : vérifier colonnes, ordre des lignes, montants
5. Modifier quelques montants dans le fichier
6. Cliquer "Importer" → panel s'ouvre avec avertissement
7. Charger le fichier modifié → succès + tableau mis à jour
8. Tester un fichier avec exercice incorrect → message d'erreur précis

- [ ] **Step 4 : Lancer la suite de tests**

```bash
./vendor/bin/sail artisan test
```
Résultat attendu : toute la suite passe

- [ ] **Step 5 : Commit**

```bash
git add app/Livewire/BudgetTable.php resources/views/livewire/budget-table.blade.php
git commit -m "feat: export/import budget dans BudgetTable (modal export + panel import)"
```
