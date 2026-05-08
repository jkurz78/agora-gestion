<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Exceptions\RecuFiscalException;
use App\Models\Association;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;

it('throw si l\'association n\'est pas éligible', function () {
    $asso = Association::factory()->create(['eligible_recu_fiscal' => false]);
    TenantContext::boot($asso);
    $ligne = $this->ligneDonValide();

    $service = app(RecuFiscalService::class);

    expect(fn () => $service->validerEligibilite($ligne))
        ->toThrow(RecuFiscalException::class, 'n\'est pas éligible');
});

it('throw si le signataire n\'est pas configuré', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => null,
    ]);
    TenantContext::boot($asso);
    $ligne = $this->ligneDonValide();

    $service = app(RecuFiscalService::class);

    expect(fn () => $service->validerEligibilite($ligne))
        ->toThrow(RecuFiscalException::class, 'signataire');
});

it('throw si l\'adresse du donateur est incomplète', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Dupont',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($asso);

    $ligne = $this->ligneDonValide(tiersOverrides: ['adresse_ligne1' => null]);

    $service = app(RecuFiscalService::class);

    expect(fn () => $service->validerEligibilite($ligne))
        ->toThrow(RecuFiscalException::class, 'Adresse');
});

it('throw si la transaction n\'est pas encaissée', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($asso);

    $ligne = $this->ligneDonValide(transactionOverrides: ['statut_reglement' => StatutReglement::EnAttente]);

    $service = app(RecuFiscalService::class);

    expect(fn () => $service->validerEligibilite($ligne))
        ->toThrow(RecuFiscalException::class, 'encaiss');
});

it('passe si tout est valide', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($asso);
    $ligne = $this->ligneDonValide();

    $service = app(RecuFiscalService::class);

    $service->validerEligibilite($ligne);
    expect(true)->toBeTrue();
});
