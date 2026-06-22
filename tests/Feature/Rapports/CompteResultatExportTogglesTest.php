<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

function headerCellsXlsx(\Illuminate\Testing\TestResponse $response): array
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
