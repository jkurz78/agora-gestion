<?php

declare(strict_types=1);

use App\Models\Association;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('stream le PDF binaire stocké', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = $this->ligneDonValide();

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne);

    $response = $service->streamPdf($recu);

    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain($recu->numero);
});

it('throw si l\'intégrité est compromise (fichier modifié)', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'J',
        'signataire_qualite' => 'P',
    ]);
    TenantContext::boot($asso);
    $ligne = $this->ligneDonValide();

    $service = app(RecuFiscalService::class);
    $recu = $service->obtenirOuGenerer($ligne);

    // Corrompt le fichier sur disque
    Storage::disk('local')->put($recu->pdfFullPath(), 'corrupted-content');

    expect(fn () => $service->streamPdf($recu))
        ->toThrow(RuntimeException::class, 'Intégrité');
});
