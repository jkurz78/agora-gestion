<?php

declare(strict_types=1);

// Le rapport sort en Excel et en PDF par le registre existant. C'est par la
// que sort la ventilation, et ce fichier n'est JAMAIS relu par l'application.

use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);
    $this->actingAs($this->user);

    $this->compte = Compte::factory()->numero('606')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Fournitures',
        'classe' => 6,
    ]);
    $this->operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Stage de printemps',
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'operation_id' => $this->operation->id,
        'exercice' => 2025,
        'montant_prevu' => 300.00,
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget(['exercice_actif', 'current_association_id']);
});

/**
 * Charge une réponse xlsx streamée en feuille PhpSpreadsheet, sans laisser de
 * fichier temporaire derrière soi. On garde la Worksheet (pas juste toArray())
 * pour pouvoir relire une cellule précise à la coordonnée exacte.
 */
function lireClasseurBudgetOperations(TestResponse $response): Worksheet
{
    $tmp = tempnam(sys_get_temp_dir(), 'bopxport').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());
    $sheet = IOFactory::load($tmp)->getActiveSheet();
    @unlink($tmp);

    return $sheet;
}

it('exporte le rapport en xlsx', function (): void {
    $this->get(route('rapports.export', [
        'rapport' => 'budget-operations',
        'format' => 'xlsx',
        'exercice' => 2025,
        'ops' => [(int) $this->operation->id],
    ]))->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exporte le rapport en pdf', function (): void {
    $this->get(route('rapports.export', [
        'rapport' => 'budget-operations',
        'format' => 'pdf',
        'exercice' => 2025,
        'ops' => [(int) $this->operation->id],
    ]))->assertOk();
});

it('refuse un format non declare au registre', function (): void {
    $this->get(route('rapports.export', [
        'rapport' => 'budget-operations',
        'format' => 'csv',
        'exercice' => 2025,
    ]))->assertNotFound();
});

it('une operation budgetee sans mouvement sort avec son budget', function (): void {
    // C'est le test qui tombe si le drapeau avecBudget disparaît de
    // normaliserOperations() : la sélection serait vidée par SEL-01 (aucun
    // mouvement réel sur l'exercice) et l'export échouerait en 422 au lieu de
    // produire le classeur avec son budget de 300 €.
    $response = $this->get(route('rapports.export', [
        'rapport' => 'budget-operations',
        'format' => 'xlsx',
        'exercice' => 2025,
        'ops' => [(int) $this->operation->id],
    ]))->assertOk();

    $sheet = lireClasseurBudgetOperations($response);
    $rows = $sheet->toArray();

    $ligneCompte = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'Fournitures');

    expect($ligneCompte)->not->toBeNull()
        ->and((float) $ligneCompte[1])->toBe(300.0);
});

it('une cellule non couverte reste vide dans le xlsx, pas un zero', function (): void {
    // Le prévisionnel ne couvre que les règlements des participants et les
    // coûts d'encadrement — aucune des deux sources ne touche ce compte, qui
    // n'a qu'une ventilation budgétaire. La colonne Prévisionnel doit donc
    // rester une cellule VIDE, jamais un 0 numérique : un 0 dirait « aucun
    // prévisionnel attendu », faux à côté d'un budget de 300 €.
    $response = $this->get(route('rapports.export', [
        'rapport' => 'budget-operations',
        'format' => 'xlsx',
        'exercice' => 2025,
        'ops' => [(int) $this->operation->id],
    ]))->assertOk();

    $sheet = lireClasseurBudgetOperations($response);
    $rows = $sheet->toArray();

    $rowIndex = null;
    foreach ($rows as $i => $r) {
        if (($r[0] ?? null) === 'Fournitures') {
            $rowIndex = $i + 1; // toArray() est 0-indexé, les lignes de la feuille sont 1-indexées
            break;
        }
    }

    expect($rowIndex)->not->toBeNull();

    // La cellule n'a jamais été posée (voir RapportExportController::xlsxEcrireLigneBudget()) :
    // elle relit `null`, jamais un `0` numérique qui affirmerait à tort
    // « aucun prévisionnel attendu » à côté d'un budget de 300 €.
    $valeurPrevisionnel = $sheet->getCell('C'.$rowIndex)->getValue();

    expect($valeurPrevisionnel)->toBeNull()
        ->and($valeurPrevisionnel)->not->toBe(0)
        ->and($valeurPrevisionnel)->not->toBe(0.0)
        ->and($valeurPrevisionnel)->not->toBe('0');

    // La colonne Réalisé, elle, est un vrai zéro (aucun mouvement) : elle doit
    // rester un nombre affiché, jamais escamotée comme le prévisionnel.
    expect((float) $sheet->getCell('D'.$rowIndex)->getValue())->toBe(0.0);
});
