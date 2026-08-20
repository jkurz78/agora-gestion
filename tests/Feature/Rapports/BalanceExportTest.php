<?php

declare(strict_types=1);

use App\Enums\SensVentilation;
use App\Models\Tiers;
use App\Services\Compta\EcritureGenerator;
use App\Support\MontantDecimal;
use App\Tenant\TenantContext;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

function creerDonneesExportBalance(object $contexte): Tiers
{
    $tiers = Tiers::factory()->create([
        'association_id' => (int) $contexte->association->id,
        'nom' => 'Client Export Balance',
        'prenom' => null,
    ]);

    app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['sens' => SensVentilation::Credit,
            'compte' => $contexte->compte706,
            'montant' => MontantDecimal::depuisCentimes(12000),
        ]],
        dateConstatation: new DateTimeImmutable('2025-10-05'),
        libelle: 'Créance export balance',
    );

    return $tiers;
}

function cellulesBalanceXlsx(TestResponse $response): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'balance').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());

    $sheet = IOFactory::load($tmp)->getActiveSheet();
    $rows = [];

    for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
        $values = [];
        for ($col = 1; $col <= 8; $col++) {
            $values[] = $sheet->getCell(Coordinate::stringFromColumnIndex($col).$row)->getValue();
        }
        $rows[] = $values;
    }

    @unlink($tmp);

    return $rows;
}

it('exporte la balance en Excel avec les filtres et colonnes demandés', function (): void {
    creerDonneesExportBalance($this);

    $response = $this->get(route('rapports.export', [
        'rapport' => 'balance',
        'format' => 'xlsx',
        'du' => '2025-10-01',
        'au' => '2025-10-31',
        'comptes' => '411',
        'colonnes' => '6',
        'exercice' => 2025,
        // Balance auxiliaire : sans ce drapeau, le compte collectif 411 est
        // exporté en une seule ligne, sans le tiers.
        'detail_tiers' => 1,
    ]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $rows = cellulesBalanceXlsx($response);

    expect($rows[0][0])->toBe('Balance comptable')
        ->and($rows[1][0])->toBe('Période')
        ->and($rows[2][0])->toBe('Comptes')
        ->and($rows[4])->toContain('Compte')
        ->and($rows[4])->toContain('Mouvement débit')
        ->and(collect($rows)->flatten()->filter(fn ($value): bool => $value === '411')->isNotEmpty())->toBeTrue()
        ->and(collect($rows)->flatten()->filter(fn ($value): bool => $value === 'CLIENT EXPORT BALANCE')->isNotEmpty())->toBeTrue()
        ->and(collect($rows)->flatten()->filter(fn ($value): bool => $value === 120.0)->isNotEmpty())->toBeTrue();
});

it('exporte la balance en PDF', function (): void {
    creerDonneesExportBalance($this);

    $this->get(route('rapports.export', [
        'rapport' => 'balance',
        'format' => 'pdf',
        'du' => '2025-10-01',
        'au' => '2025-10-31',
        'comptes' => '411',
        'colonnes' => '4',
        'exercice' => 2025,
    ]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('la vue PDF balance respecte le format quatre colonnes', function (): void {
    $html = view('pdf.rapport-balance', [
        'balance' => [
            'date_debut' => '2025-10-01',
            'date_fin' => '2025-10-31',
            'prefixes_comptes' => ['411'],
            'lignes' => [[
                'numero_compte' => '411',
                'intitule_compte' => 'Clients',
                'tiers' => 'CLIENT EXPORT BALANCE',
                'solde_ouverture_debit_centimes' => 0,
                'solde_ouverture_credit_centimes' => 0,
                'mouvement_debit_centimes' => 12000,
                'mouvement_credit_centimes' => 0,
                'solde_fin_debit_centimes' => 12000,
                'solde_fin_credit_centimes' => 0,
            ]],
            'totaux' => [
                'mouvement_debit_centimes' => 12000,
                'mouvement_credit_centimes' => 0,
                'solde_fin_debit_centimes' => 12000,
                'solde_fin_credit_centimes' => 0,
            ],
        ],
        'colonnes' => 4,
        'dateDebut' => '2025-10-01',
        'dateFin' => '2025-10-31',
        'comptes' => '411',
        'title' => 'Balance comptable',
        'subtitle' => 'Du 01/10/2025 au 31/10/2025',
        'association' => null,
        'headerLogoBase64' => null,
        'headerLogoMime' => null,
        'appLogoBase64' => null,
        'footerLogoBase64' => null,
        'footerLogoMime' => null,
    ])->render();

    expect($html)
        ->toContain('Balance comptable')
        ->toContain('Ouverture')
        ->toContain('Solde final')
        ->toContain('CLIENT EXPORT BALANCE')
        ->not->toContain('Mouvements');
});
