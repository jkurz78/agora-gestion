<?php

declare(strict_types=1);

// Le rapport sort en Excel et en PDF par le registre existant. C'est par la
// que sort la ventilation, et ce fichier n'est JAMAIS relu par l'application.

use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\RapportService;
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
    //
    // Sans cast, sinon (float) null === 0.0 efface exactement la distinction
    // que ce test pretend etablir : une cellule jamais posee (relue `null`)
    // et une cellule posee a `0.0` deviendraient indiscernables, et un
    // xlsxEcrireLigneBudget() mute en `if ($realise !== 0.0) { ...set... }`
    // laisserait ce test vert.
    $valeurRealise = $sheet->getCell('D'.$rowIndex)->getValue();

    expect($valeurRealise)->not->toBeNull()
        ->and($valeurRealise)->toBeFloat()
        ->and($valeurRealise)->toBe(0.0);
});

// ── Section vide : trois surfaces, un seul comportement attendu (celui de
// l'ecran, qui masque toute la ligne de total quand la section n'a aucun
// compte). Le fixture partage plus haut ne budgete que des comptes de
// depense pour $this->operation : le cote RECETTES y est donc toujours vide,
// et sert a verifier qu'aucune ligne TOTAL RECETTES n'en sort.

it('le pdf n imprime aucune ligne de total quand une section n a aucun compte', function (): void {
    $operations = app(RapportService::class)->budgetParOperations(2025, [(int) $this->operation->id]);

    $html = view('pdf.rapport-budget-operations', [
        'operations' => $operations,
        'title' => 'Budget par operations',
        'subtitle' => null,
        'association' => null,
        'headerLogoBase64' => null,
        'headerLogoMime' => null,
        'appLogoBase64' => null,
        'footerLogoBase64' => null,
        'footerLogoMime' => null,
    ])->render();

    expect($html)->toContain('Aucun compte.');
    expect($html)->not->toContain('TOTAL RECETTES');
    // Le cote DEPENSES, lui, a bien un compte et garde sa ligne de total.
    expect($html)->toContain('TOTAL DÉPENSES');
});

it('le xlsx n ecrit aucune ligne de total quand une section n a aucun compte', function (): void {
    $response = $this->get(route('rapports.export', [
        'rapport' => 'budget-operations',
        'format' => 'xlsx',
        'exercice' => 2025,
        'ops' => [(int) $this->operation->id],
    ]))->assertOk();

    $sheet = lireClasseurBudgetOperations($response);
    $rows = collect($sheet->toArray())->map(fn ($r) => $r[0] ?? null);

    expect($rows)->not->toContain('TOTAL RECETTES');
    expect($rows)->toContain('TOTAL DÉPENSES');
});

// ── La legende et le « dont hors dotation » : presents a l'ecran (voir
// BudgetOperationsEcranTest.php), absents des deux exports jusqu'ici. C'est
// pourtant le PDF qu'on pose sur la table en reunion, la ou le tiret est le
// plus muet.

it('le pdf porte la legende du perimetre du previsionnel et le detail hors dotation', function (): void {
    $compteHorsDotation = Compte::factory()->numero('625B')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Deplacements',
        'classe' => 6,
    ]);
    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-10',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteHorsDotation->id,
        'operation_id' => $this->operation->id,
        'debit' => 150.00,
        'credit' => 0,
    ]);

    $operations = app(RapportService::class)->budgetParOperations(2025, [(int) $this->operation->id]);

    $html = view('pdf.rapport-budget-operations', [
        'operations' => $operations,
        'title' => 'Budget par operations',
        'subtitle' => null,
        'association' => null,
        'headerLogoBase64' => null,
        'headerLogoMime' => null,
        'appLogoBase64' => null,
        'footerLogoBase64' => null,
        'footerLogoMime' => null,
    ])->render();

    expect($html)->toContain('règlements des participants');
    expect($html)->toContain('dont hors dotation');
    expect($html)->toContain('150,00');
});

it('le xlsx porte la legende du perimetre du previsionnel et le detail hors dotation', function (): void {
    $compteHorsDotation = Compte::factory()->numero('625B')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Deplacements',
        'classe' => 6,
    ]);
    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'date' => '2025-11-10',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteHorsDotation->id,
        'operation_id' => $this->operation->id,
        'debit' => 150.00,
        'credit' => 0,
    ]);

    $response = $this->get(route('rapports.export', [
        'rapport' => 'budget-operations',
        'format' => 'xlsx',
        'exercice' => 2025,
        'ops' => [(int) $this->operation->id],
    ]))->assertOk();

    $sheet = lireClasseurBudgetOperations($response);
    $rows = collect($sheet->toArray())->map(fn ($r) => $r[0] ?? null)->filter()->values();

    expect($rows->contains(fn ($v) => str_contains((string) $v, 'règlements des participants')))->toBeTrue();
    expect($rows->contains(fn ($v) => str_contains((string) $v, 'dont hors dotation')))->toBeTrue();
});
