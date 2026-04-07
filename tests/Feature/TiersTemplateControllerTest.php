<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function (): void {
    $this->admin = User::factory()->create(['role' => Role::Admin]);
    $this->consultation = User::factory()->create(['role' => Role::Consultation]);
});

// ── CSV template ──

it('telecharge le modele CSV avec les en-tetes corrects', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(route('compta.tiers.template.csv'));

    $response->assertOk();
    $response->assertDownload('modele-tiers.csv');

    $content = $response->streamedContent();

    // Strip BOM if present
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

    $lines = array_filter(explode("\n", trim($content)));
    expect($lines)->toHaveCount(2);

    // Header row
    expect($lines[0])->toContain('nom;prenom;entreprise;email;telephone;adresse_ligne1;code_postal;ville;pays;pour_depenses;pour_recettes');

    // Example row
    expect($lines[1])->toContain('Dupont');
    expect($lines[1])->toContain('Jean');
    expect($lines[1])->toContain('jean.dupont@email.fr');
});

it('le modele CSV contient le BOM UTF-8', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(route('compta.tiers.template.csv'));

    $content = $response->streamedContent();

    expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF");
});

it('le modele CSV utilise le point-virgule comme separateur', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(route('compta.tiers.template.csv'));

    $content = $response->streamedContent();
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $lines = array_filter(explode("\n", trim($content)));

    // Count semicolons in header (10 separators for 11 columns)
    expect(substr_count($lines[0], ';'))->toBe(10);
});

// ── XLSX template ──

it('telecharge le modele XLSX valide', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(route('compta.tiers.template.xlsx'));

    $response->assertOk();
    $response->assertDownload('modele-tiers.xlsx');
});

it('le modele XLSX contient les en-tetes et la ligne exemple', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(route('compta.tiers.template.xlsx'));

    // Write response content to a temp file and read with PhpSpreadsheet
    $tempFile = tempnam(sys_get_temp_dir(), 'test_xlsx_');
    file_put_contents($tempFile, $response->getFile()->getContent());

    $spreadsheet = IOFactory::load($tempFile);
    $sheet = $spreadsheet->getActiveSheet();

    // Header row
    expect($sheet->getCell('A1')->getValue())->toBe('nom');
    expect($sheet->getCell('B1')->getValue())->toBe('prenom');
    expect($sheet->getCell('C1')->getValue())->toBe('entreprise');
    expect($sheet->getCell('D1')->getValue())->toBe('email');
    expect($sheet->getCell('K1')->getValue())->toBe('pour_recettes');

    // Example row
    expect($sheet->getCell('A2')->getValue())->toBe('Dupont');
    expect($sheet->getCell('B2')->getValue())->toBe('Jean');
    expect($sheet->getCell('D2')->getValue())->toBe('jean.dupont@email.fr');

    // Header is bold
    expect($sheet->getStyle('A1')->getFont()->getBold())->toBeTrue();

    unlink($tempFile);
});

// ── Auth & access control ──

it('redirige les invites vers login pour le CSV', function (): void {
    $this->get(route('compta.tiers.template.csv'))
        ->assertRedirect(route('login'));
});

it('redirige les invites vers login pour le XLSX', function (): void {
    $this->get(route('compta.tiers.template.xlsx'))
        ->assertRedirect(route('login'));
});

it('les utilisateurs authentifies peuvent telecharger le CSV', function (): void {
    $this->actingAs($this->consultation)
        ->get(route('compta.tiers.template.csv'))
        ->assertOk();
});

it('les utilisateurs authentifies peuvent telecharger le XLSX', function (): void {
    $this->actingAs($this->consultation)
        ->get(route('compta.tiers.template.xlsx'))
        ->assertOk();
});
