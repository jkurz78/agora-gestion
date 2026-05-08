<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;

it('persiste un reçu fiscal avec ses champs', function () {
    /** @var Association $asso */
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $tiers = Tiers::factory()->create();

    $recu = RecuFiscalEmis::factory()->create([
        'tiers_id' => $tiers->id,
        'numero' => '2026-0001',
        'annee_civile' => 2026,
        'montant_centimes' => 15000,
        'date_versement' => '2026-03-15',
        'mode_versement' => 'cheque',
        'forme_don' => 'numeraire',
        'article_cgi' => 'art_200',
        'pdf_path' => 'recus_fiscaux/2026/2026-0001.pdf',
        'pdf_hash' => str_repeat('a', 64),
    ]);

    expect($recu->numero)->toBe('2026-0001');
    expect($recu->isActif())->toBeTrue();
    expect($recu->isAnnule())->toBeFalse();
});

it('détecte un reçu annulé', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $tiers = Tiers::factory()->create();

    $recu = RecuFiscalEmis::factory()->create([
        'tiers_id' => $tiers->id,
        'annule_at' => now(),
        'annule_motif' => 'Don supprimé',
    ]);

    expect($recu->isAnnule())->toBeTrue();
    expect($recu->isActif())->toBeFalse();
});

it('isole les reçus par tenant via TenantScope', function () {
    $asso1 = Association::factory()->create();
    $asso2 = Association::factory()->create();

    TenantContext::boot($asso1);
    $tiers1 = Tiers::factory()->create();
    RecuFiscalEmis::factory()->create(['tiers_id' => $tiers1->id]);

    TenantContext::boot($asso2);
    expect(RecuFiscalEmis::count())->toBe(0);
});

it('garantit l\'unicité du numéro par association', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $tiers = Tiers::factory()->create();

    RecuFiscalEmis::factory()->create(['numero' => '2026-0001', 'tiers_id' => $tiers->id]);

    expect(fn () => RecuFiscalEmis::factory()->create(['numero' => '2026-0001', 'tiers_id' => $tiers->id]))
        ->toThrow(QueryException::class);
});
