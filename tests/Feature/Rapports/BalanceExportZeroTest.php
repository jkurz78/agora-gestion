<?php

declare(strict_types=1);

/**
 * La balance présente ses montants en colonnes débit/crédit (ouverture,
 * mouvements, solde). Le côté inutilisé reste VIDE — décision du propriétaire
 * (2026-09-10), prise avec celle du grand livre et des journaux, les trois
 * rapports partageant cette présentation.
 *
 * Ce test épingle cette décision : reposer `strictNullComparison: true` sur
 * l'un des deux sites de la balance (ligne de compte, total) le fait tomber.
 * GrandLivreExportZeroTest détaille le raisonnement.
 *
 * Scénario : un 411 débité de 120 €, jamais crédité — son Mouvement crédit
 * nul reste vide.
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

it('la balance laisse vide un mouvement credit nul, a la ligne de compte comme au TOTAL', function (): void {
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

    // La ligne porte bien son montant : 120,00 en Mouvement débit (F). Sans
    // cette vérification, un export vide passerait les assertions suivantes.
    $debitLigne = $sheet->getCell('F'.$ligneCompteIndex)->getValue();
    expect($debitLigne)->not->toBeNull()
        ->and((float) $debitLigne)->toBe(120.0);

    // Mouvement crédit (G), nul, reste VIDE — à la ligne comme au TOTAL.
    expect($sheet->getCell('G'.$ligneCompteIndex)->getValue())->toBeNull();
    expect($sheet->getCell('G'.$ligneTotalIndex)->getValue())->toBeNull();

    // La colonne Intitulé (B) du TOTAL, jamais posée, reste vide.
    expect($sheet->getCell('B'.$ligneTotalIndex)->getValue())->toBeNull();
});
