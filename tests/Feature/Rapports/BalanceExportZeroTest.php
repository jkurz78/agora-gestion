<?php

declare(strict_types=1);

/**
 * `Worksheet::fromArray()` compare chaque valeur à sa sentinelle `null` avec un
 * `!=` LÂCHE tant que `strictNullComparison` vaut `false` (son défaut) :
 * `0.0 != null` rend `false`, donc la cellule n'est jamais posée.
 *
 * Un compte qui n'a mouvementé qu'un seul sens (ici 411, débité de 120 €,
 * jamais crédité) porte un Mouvement crédit à 0,00 : un vrai zéro comptable,
 * pas une absence — la balance doit l'écrire, à la ligne du compte comme à
 * la ligne TOTAL.
 */

use App\Enums\SensVentilation;
use App\Models\Tiers;
use App\Services\Compta\EcritureGenerator;
use App\Support\MontantDecimal;
use App\Tenant\TenantContext;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
    session(['exercice_actif' => 2025]);
});

afterEach(function (): void {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

function lireClasseurBalanceZero(TestResponse $response): Worksheet
{
    $tmp = tempnam(sys_get_temp_dir(), 'balzero').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());
    $sheet = IOFactory::load($tmp)->getActiveSheet();
    @unlink($tmp);

    return $sheet;
}

it('la balance ecrit 0,00 pour un mouvement credit reellement nul, a la ligne de compte comme au TOTAL', function (): void {
    $tiers = Tiers::factory()->create([
        'association_id' => (int) $this->association->id,
        'nom' => 'Client Balance Zero',
    ]);

    // Compte 411 débité de 120 €, jamais crédité : Mouvement crédit vaut 0,
    // un vrai zéro comptable.
    app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['sens' => SensVentilation::Credit,
            'compte' => $this->compte706,
            'montant' => MontantDecimal::depuisCentimes(12000),
        ]],
        dateConstatation: new DateTimeImmutable('2025-10-05'),
        libelle: 'Créance balance zéro',
    );

    $response = $this->get(route('rapports.export', [
        'rapport' => 'balance',
        'format' => 'xlsx',
        'du' => '2025-10-01',
        'au' => '2025-10-31',
        'comptes' => '411',
        'colonnes' => '6',
        'exercice' => 2025,
        'detail_tiers' => 1,
    ]))->assertOk();

    $sheet = lireClasseurBalanceZero($response);
    // formatData: false — le format `#,##0.00` posé sur les colonnes de
    // montant renverrait sinon des chaînes formatées pour la recherche
    // ci-dessous ; getCell()->getValue() plus bas reste toujours brut.
    $rows = $sheet->toArray(null, true, false);

    // Colonnes (6) : A Compte | B Intitulé | C Tiers | D Ouverture débit
    // | E Ouverture crédit | F Mouvement débit | G Mouvement crédit
    // | H Solde final débit | I Solde final crédit
    $ligneCompteIndex = null;
    $ligneTotalIndex = null;
    foreach ($rows as $i => $r) {
        if (($r[0] ?? null) === '411') {
            $ligneCompteIndex = $i + 1;
        }
        if (($r[0] ?? null) === 'TOTAL') {
            $ligneTotalIndex = $i + 1;
        }
    }

    expect($ligneCompteIndex)->not->toBeNull()
        ->and($ligneTotalIndex)->not->toBeNull();

    $creditLigne = $sheet->getCell('G'.$ligneCompteIndex)->getValue();
    expect($creditLigne)->not->toBeNull()
        ->and($creditLigne)->toBeFloat()
        ->and($creditLigne)->toBe(0.0);

    $creditTotal = $sheet->getCell('G'.$ligneTotalIndex)->getValue();
    expect($creditTotal)->not->toBeNull()
        ->and($creditTotal)->toBeFloat()
        ->and($creditTotal)->toBe(0.0);

    // Le pendant : sur la ligne TOTAL, la colonne Intitulé (B) n'a jamais été
    // posée et doit rester vide.
    expect($sheet->getCell('B'.$ligneTotalIndex)->getValue())->toBeNull();
});
