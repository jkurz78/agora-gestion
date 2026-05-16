<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->asso = Association::factory()->create(['slug' => 'mon-association']);
    TenantContext::boot($this->asso);
});

it('pdfFilename retourne <slug>-recu-fiscal-<numero>.pdf avec association chargée', function () {
    $recu = RecuFiscalEmis::factory()->create([
        'association_id' => $this->asso->id,
        'numero' => '2026-001',
    ]);
    $recu->load('association');

    expect($recu->pdfFilename())->toBe('mon-association-recu-fiscal-2026-001.pdf');
});

it('pdfFilename conserve les tirets dans le slug', function () {
    $asso = Association::factory()->create(['slug' => 'soigner-vivre-sourire']);
    TenantContext::boot($asso);

    $recu = RecuFiscalEmis::factory()->create([
        'association_id' => $asso->id,
        'numero' => '2026-042',
    ]);
    $recu->load('association');

    expect($recu->pdfFilename())->toBe('soigner-vivre-sourire-recu-fiscal-2026-042.pdf');
});

it('pdfFilename retourne fallback asso-recu-fiscal-<numero>.pdf si association est null', function () {
    $recu = RecuFiscalEmis::factory()->make([
        'association_id' => $this->asso->id,
        'numero' => '2026-099',
    ]);
    // On force la relation à null pour tester le fallback
    $recu->setRelation('association', null);

    expect($recu->pdfFilename())->toBe('asso-recu-fiscal-2026-099.pdf');
});
