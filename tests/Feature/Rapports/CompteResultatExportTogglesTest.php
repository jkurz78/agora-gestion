<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Provision;
use App\Models\User;
use App\Services\ProvisionService;
use App\Tenant\TenantContext;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

function headerCellsXlsx(TestResponse $response): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'cr').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());
    $sheet = IOFactory::load($tmp)->getActiveSheet();
    $cells = [];
    foreach (range('A', 'G') as $col) {
        $cells[] = (string) $sheet->getCell($col.'1')->getValue();
    }
    @unlink($tmp);

    return array_filter($cells, fn ($v) => $v !== '');
}

it('XLSX : sans params, toutes les colonnes sont présentes', function () {
    $response = $this->get(route('rapports.export', ['rapport' => 'compte-resultat', 'format' => 'xlsx', 'exercice' => 2025]));
    $response->assertOk();
    expect(headerCellsXlsx($response))->toContain('Budget')->toContain('Écart');
});

it('XLSX : les provisions et extournes respectent Nature, Famille, Compte', function () {
    $compte = Compte::factory()->numero('706')->create(['intitule' => 'Compte ajustements']);
    Provision::factory()->create([
        'exercice' => 2025,
        'type' => TypeTransaction::Recette,
        'compte_id' => $compte->id,
        'libelle' => 'Provision courante',
        'date' => '2026-08-31',
        'saisi_par' => $this->user->id,
    ]);
    Provision::factory()->create([
        'exercice' => 2024,
        'type' => TypeTransaction::Recette,
        'compte_id' => $compte->id,
        'libelle' => 'Provision antérieure',
        'date' => '2025-08-31',
        'saisi_par' => $this->user->id,
    ]);

    $familleNom = app(ProvisionService::class)->provisionsExercice(2025)->first()['famille_nom'];
    $response = $this->get(route('rapports.export', [
        'rapport' => 'compte-resultat',
        'format' => 'xlsx',
        'exercice' => 2025,
    ]));
    $response->assertOk();

    $tmp = tempnam(sys_get_temp_dir(), 'cr-ajustements').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());
    $sheet = IOFactory::load($tmp)->getActiveSheet();
    $rows = [];
    for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
        $rows[] = [
            (string) $sheet->getCell('A'.$row)->getValue(),
            (string) $sheet->getCell('B'.$row)->getValue(),
            (string) $sheet->getCell('C'.$row)->getValue(),
        ];
    }
    @unlink($tmp);

    expect([
        $sheet->getCell('A1')->getValue(),
        $sheet->getCell('B1')->getValue(),
        $sheet->getCell('C1')->getValue(),
    ])->toBe(['Nature', 'Famille', 'Compte'])
        ->and($rows)->toContain(['Provision', $familleNom, 'Compte ajustements'])
        ->and($rows)->toContain(['Extourne', $familleNom, 'Compte ajustements']);
});

it('XLSX : budget=0 retire Budget et Écart', function () {
    $response = $this->get(route('rapports.export', ['rapport' => 'compte-resultat', 'format' => 'xlsx', 'exercice' => 2025, 'budget' => '0']));
    $response->assertOk();
    $cells = headerCellsXlsx($response);
    expect($cells)->not->toContain('Budget');
    expect($cells)->not->toContain('Écart');
});

it('XLSX : n1=0 retire la colonne N-1', function () {
    $labelN1 = '2024-2025';
    $response = $this->get(route('rapports.export', ['rapport' => 'compte-resultat', 'format' => 'xlsx', 'exercice' => 2025, 'n1' => '0']));
    $response->assertOk();
    expect(headerCellsXlsx($response))->not->toContain($labelN1);
});

it('PDF : la route répond 200 avec les toggles', function () {
    $this->get(route('rapports.export', ['rapport' => 'compte-resultat', 'format' => 'pdf', 'exercice' => 2025, 'n1' => '0', 'budget' => '0']))
        ->assertOk();
});

it('PDF : la vue omet N-1 et budget quand les flags sont false', function () {
    $html = view('pdf.rapport-compte-resultat', [
        'charges' => [], 'produits' => [],
        'labelN' => '2025-2026', 'labelN1' => '2024-2025',
        'totalChargesN' => 0.0, 'totalProduitsN' => 0.0, 'totalChargesN1' => 0.0, 'totalProduitsN1' => 0.0,
        'provisions' => collect(), 'provisionsN1' => collect(), 'extournes' => collect(), 'extournesN1' => collect(),
        'totalProvisions' => 0.0, 'totalProvisionsN1' => 0.0, 'totalExtournes' => 0.0, 'totalExtournesN1' => 0.0,
        'resultatBrut' => 0.0, 'resultatBrutN1' => 0.0,
        'resultatNet' => 0.0, 'resultatNetN1' => 0.0,
        'title' => 'Compte de résultat', 'subtitle' => 'Exercice 2025-2026',
        'association' => null, 'headerLogoBase64' => null, 'headerLogoMime' => null,
        'appLogoBase64' => null, 'footerLogoBase64' => null, 'footerLogoMime' => null,
        'compareN1' => false, 'compareBudget' => false,
    ])->render();

    expect($html)->not->toContain('2024-2025')   // en-tête N-1
        ->not->toContain('Budget');
});
