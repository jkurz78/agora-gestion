<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\User;
use App\Services\Budget\BudgetGelService;
use App\Services\BudgetImportService;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;

function makeBudgetCsvFile(string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'budget_test_');
    file_put_contents($path, $content);

    return new UploadedFile($path, 'budget.csv', 'text/csv', null, true);
}

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);

    // Comptes classe 6 → l'import résout les libellés contre comptes.intitule.
    $this->scLoyers = Compte::factory()->numero('613')->create(['intitule' => 'Loyers']);
    $this->scElec = Compte::factory()->numero('616')->create(['intitule' => 'Électricité']);
});

afterEach(function () {
    TenantContext::clear();
});

it('importe un CSV valide et insère les lignes non nulles', function () {
    $csv = "exercice;famille;compte;montant_prevu\n"
         ."2025-2026;Charges;Loyers;1200.00\n"
         ."2025-2026;Charges;Électricité;\n"; // vide → ignoré

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeTrue()
        ->and($result->linesImported)->toBe(1);

    expect(BudgetLine::where('exercice', 2025)->count())->toBe(1);
    expect(BudgetLine::where('compte_id', $this->scLoyers->id)->value('montant_prevu'))->toBe('1200.00');
});

it('ignore les lignes avec montant à zéro', function () {
    $csv = "exercice;famille;compte;montant_prevu\n"
         ."2025-2026;Charges;Loyers;0\n"
         ."2025-2026;Charges;Électricité;0.00\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeTrue()
        ->and($result->linesImported)->toBe(0);

    expect(BudgetLine::where('exercice', 2025)->count())->toBe(0);
});

it('supprime les lignes existantes de l\'exercice avant import', function () {
    BudgetLine::factory()->create(['compte_id' => $this->scLoyers->id, 'exercice' => 2025, 'montant_prevu' => 999]);
    BudgetLine::factory()->create(['compte_id' => $this->scLoyers->id, 'exercice' => 2024, 'montant_prevu' => 500]); // autre exercice

    $csv = "exercice;famille;compte;montant_prevu\n"
         ."2025-2026;Charges;Électricité;300.00\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeTrue();
    // L'ancienne ligne 2025 est supprimée
    expect(BudgetLine::where('compte_id', $this->scLoyers->id)->where('exercice', 2025)->exists())->toBeFalse();
    // La ligne 2024 est préservée
    expect(BudgetLine::where('exercice', 2024)->count())->toBe(1);
});

it('rejette si l\'en-tête est invalide', function () {
    $csv = "exercice;nom_sc;montant\n2025-2026;Charges;Loyers;100\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('En-tête invalide');
});

it('rejette si l\'exercice dans le fichier ne correspond pas', function () {
    $csv = "exercice;famille;compte;montant_prevu\n"
         ."2024-2025;Charges;Loyers;100.00\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('2024-2025')
        ->and($result->errors[0]['message'])->toContain('2025');
});

it('liste tous les exercices incorrects distincts dans le message d\'erreur', function () {
    $csv = "exercice;famille;compte;montant_prevu\n"
         ."2024-2025;Charges;Loyers;100.00\n"
         ."2023-2024;Charges;Électricité;200.00\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('2023-2024')
        ->and($result->errors[0]['message'])->toContain('2024-2025');
});

it('rejette si un compte est introuvable', function () {
    $csv = "exercice;famille;compte;montant_prevu\n"
         ."2025-2026;Charges;Inconnu;100.00\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('Inconnu')
        ->and($result->errors[0]['line'])->toBe(2);
});

it('rejette si un compte est ambigu (doublon d\'intitulé)', function () {
    Compte::factory()->numero('756')->create(['intitule' => 'Loyers']); // compte doublon d'intitulé !

    $csv = "exercice;famille;compte;montant_prevu\n"
         ."2025-2026;Charges;Loyers;100.00\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('ambigu');
});

it('rejette si un montant est invalide (négatif)', function () {
    $csv = "exercice;famille;compte;montant_prevu\n"
         ."2025-2026;Charges;Loyers;-50.00\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('invalide');
});

it('rejette si un montant est invalide (non numérique)', function () {
    $csv = "exercice;famille;compte;montant_prevu\n"
         ."2025-2026;Charges;Loyers;abc\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse();
});

it('rejette un fichier sans lignes de données', function () {
    $csv = "exercice;famille;compte;montant_prevu\n";

    BudgetLine::factory()->create(['compte_id' => $this->scLoyers->id, 'exercice' => 2025, 'montant_prevu' => 999]);

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse()
        ->and($result->errors[0]['message'])->toContain('aucune ligne');

    // Le budget existant est préservé
    expect(BudgetLine::where('exercice', 2025)->count())->toBe(1);
});

it('ignore les montants à zéro sous toutes les formes', function () {
    $csv = "exercice;famille;compte;montant_prevu\n"
         ."2025-2026;Charges;Loyers;0.0\n"
         ."2025-2026;Charges;Électricité;0.000\n";

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeTrue()
        ->and($result->linesImported)->toBe(0);
});

it('n\'insère rien si validation échoue (atomicité)', function () {
    BudgetLine::factory()->create(['compte_id' => $this->scLoyers->id, 'exercice' => 2025, 'montant_prevu' => 999]);

    $csv = "exercice;famille;compte;montant_prevu\n"
         ."2025-2026;Charges;Loyers;100.00\n"
         ."2025-2026;Charges;Inconnu;200.00\n"; // erreur

    $result = app(BudgetImportService::class)->import(makeBudgetCsvFile($csv), 2025);

    expect($result->success)->toBeFalse();
    // La ligne existante est préservée car aucune suppression n'a eu lieu
    expect(BudgetLine::where('exercice', 2025)->count())->toBe(1);
    expect(BudgetLine::where('exercice', 2025)->value('montant_prevu'))->toBe('999.00');
});

it('ne detruit pas la ventilation lors d un re-import', function () {
    $exercice = app(ExerciceService::class)->current();
    $compte = Compte::factory()->numero('606')->create(['intitule' => 'Achats']);
    $op = Operation::factory()->create();

    BudgetLine::factory()->create([
        'compte_id' => $compte->id, 'exercice' => $exercice,
        'operation_id' => null, 'montant_prevu' => 1000.00,
    ]);
    $ventilation = BudgetLine::factory()->create([
        'compte_id' => $compte->id, 'exercice' => $exercice,
        'operation_id' => $op->id, 'montant_prevu' => 400.00,
    ]);

    $csv = "exercice;famille;compte;montant_prevu\n{$exercice};Famille;Achats;1500.00\n";
    $fichier = UploadedFile::fake()->createWithContent('budget.csv', $csv);

    $resultat = app(BudgetImportService::class)->import($fichier, $exercice);

    expect($resultat->success)->toBeTrue()
        ->and(BudgetLine::find($ventilation->id))->not->toBeNull()
        ->and((float) BudgetLine::find($ventilation->id)->montant_prevu)->toBe(400.0)
        ->and((float) BudgetLine::forExercice($exercice)->enveloppes()->sum('montant_prevu'))->toBe(1500.0);
});

it('accepte un fichier a cinq colonnes en ignorant la colonne de reference', function () {
    $exercice = app(ExerciceService::class)->current();
    Compte::factory()->numero('606')->create(['intitule' => 'Achats']);

    $csv = "exercice;famille;compte;montant_prevu;realise_2024-2025\n{$exercice};Famille;Achats;1500.00;1320.44\n";
    $fichier = UploadedFile::fake()->createWithContent('budget.csv', $csv);

    $resultat = app(BudgetImportService::class)->import($fichier, $exercice);

    expect($resultat->success)->toBeTrue()
        ->and((float) BudgetLine::forExercice($exercice)->enveloppes()->sum('montant_prevu'))->toBe(1500.0);
});

it('refuse l import quand le budget est valide', function () {
    $exercice = app(ExerciceService::class)->current();
    Compte::factory()->numero('606')->create(['intitule' => 'Achats']);

    // Pas de factory sur Exercice : création directe.
    $modele = Exercice::create([
        'annee' => $exercice, 'statut' => StatutExercice::Ouvert,
    ]);
    app(BudgetGelService::class)->valider($modele, $this->user ?? User::factory()->create());

    $csv = "exercice;famille;compte;montant_prevu\n{$exercice};Famille;Achats;1500.00\n";
    $fichier = UploadedFile::fake()->createWithContent('budget.csv', $csv);

    $resultat = app(BudgetImportService::class)->import($fichier, $exercice);

    expect($resultat->success)->toBeFalse()
        ->and($resultat->errors[0]['message'])->toContain('déverrouill');
});

it('annonce ce qui sera remplace et ce qui sera conserve', function () {
    $exercice = app(ExerciceService::class)->current();
    $compte = Compte::factory()->numero('606')->create(['intitule' => 'Achats']);
    $op = Operation::factory()->create();

    BudgetLine::factory()->create(['compte_id' => $compte->id, 'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 1000.00]);
    BudgetLine::factory()->create(['compte_id' => $compte->id, 'exercice' => $exercice, 'operation_id' => $op->id, 'montant_prevu' => 400.00]);

    $rendu = app(BudgetImportService::class)->compteRendu($exercice);

    expect($rendu['enveloppes'])->toBe(1)
        ->and($rendu['ventilations'])->toBe(1)
        ->and($rendu['montant_ventile'])->toBe(400.0)
        ->and($rendu['operations'])->toBe(1);
});
