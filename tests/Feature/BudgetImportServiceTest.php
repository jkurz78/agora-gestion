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

it('rejette un fichier sans lignes de données', function () {
    $csv = "exercice;sous_categorie;montant_prevu\n";

    BudgetLine::factory()->create(['sous_categorie_id' => $this->scLoyers->id, 'exercice' => 2025, 'montant_prevu' => 999]);

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('aucune ligne');

    // Le budget existant est préservé
    expect(BudgetLine::where('exercice', 2025)->count())->toBe(1);
});

it('ignore les montants à zéro sous toutes les formes', function () {
    $csv = "exercice;sous_categorie;montant_prevu\n"
         . "2025;Loyers;0.0\n"
         . "2025;Électricité;0.000\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeTrue()
        ->and($result->linesImported)->toBe(0);
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
