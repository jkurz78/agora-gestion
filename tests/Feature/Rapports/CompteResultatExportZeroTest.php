<?php

declare(strict_types=1);

/**
 * `Worksheet::fromArray()` compare chaque valeur à sa sentinelle `null` avec un
 * `!=` LÂCHE tant que `strictNullComparison` vaut `false` (son défaut) :
 * `0.0 != null` rend `false`, donc la cellule n'est jamais posée. Tout zéro
 * numérique disparaissait silencieusement du classeur, indiscernable d'une
 * absence.
 *
 * Ce test prouve la distinction sur le compte de résultat, exactement là où
 * elle compte : un compte budgété sans le moindre mouvement N porte un
 * `montant_n` FABRIQUÉ à 0.0 (voir CompteResultatBuilder::buildHierarchyFull())
 * — ce zéro doit s'écrire « 0,00 » — alors que son `montant_n1` est un vrai
 * `null` (aucune écriture N-1 nulle part pour ce compte) et doit rester une
 * cellule vide.
 */

use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
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
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget('current_association_id');
});

function lireClasseurCompteResultat(TestResponse $response): Worksheet
{
    $tmp = tempnam(sys_get_temp_dir(), 'crzero').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());
    $sheet = IOFactory::load($tmp)->getActiveSheet();
    @unlink($tmp);

    return $sheet;
}

it('un compte budgete sans mouvement N ni N-1 ecrit 0,00 pour le montant N et laisse le N-1 vide, au detail comme au sous-total', function (): void {
    // Compte de charge budgété 1 500 €, aucune transaction : montant_n vaut
    // 0.0 (fabriqué pour être affiché), montant_n1 vaut null (vraie absence).
    $compte = Compte::factory()->numero('613A')->create([
        'association_id' => $this->association->id,
        'intitule' => 'Location salle',
    ]);
    BudgetLine::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'exercice' => 2025,
        'operation_id' => null,
        'montant_prevu' => 1500.00,
    ]);

    $response = $this->get(route('rapports.export', [
        'rapport' => 'compte-resultat',
        'format' => 'xlsx',
        'exercice' => 2025,
    ]))->assertOk();

    $sheet = lireClasseurCompteResultat($response);
    $rows = $sheet->toArray();

    // Colonnes : A Type | B Famille | C Compte | D N-1 | E N | F Budget | G Écart
    $ligneDetailIndex = null;
    foreach ($rows as $i => $r) {
        if (($r[2] ?? null) === 'Location salle') {
            $ligneDetailIndex = $i + 1; // toArray() 0-indexé, la feuille est 1-indexée
            break;
        }
    }
    expect($ligneDetailIndex)->not->toBeNull();

    // Ligne de détail : montant N à 0,00 (existe), N-1 vide (absent).
    $montantN1Detail = $sheet->getCell('D'.$ligneDetailIndex)->getValue();
    $montantNDetail = $sheet->getCell('E'.$ligneDetailIndex)->getValue();

    expect($montantN1Detail)->toBeNull();
    expect($montantNDetail)->not->toBeNull()
        ->and($montantNDetail)->toBeFloat()
        ->and($montantNDetail)->toBe(0.0);

    // Ligne de sous-total famille (juste après, colonne C = 'TOTAL') : même
    // distinction — un seul compte dans la famille, donc le sous-total montant_n
    // vaut 0.0 (somme d'un seul 0.0) et montant_n1 reste null (rien à sommer).
    $ligneSousTotalIndex = $ligneDetailIndex + 1;
    expect($sheet->getCell('C'.$ligneSousTotalIndex)->getValue())->toBe('TOTAL');

    $montantN1SousTotal = $sheet->getCell('D'.$ligneSousTotalIndex)->getValue();
    $montantNSousTotal = $sheet->getCell('E'.$ligneSousTotalIndex)->getValue();

    expect($montantN1SousTotal)->toBeNull();
    expect($montantNSousTotal)->not->toBeNull()
        ->and($montantNSousTotal)->toBeFloat()
        ->and($montantNSousTotal)->toBe(0.0);
});
