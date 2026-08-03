<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\UsageCompte;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->compteDon = Compte::factory()->numero('754A')->create(['association_id' => $this->asso->id, 'intitule' => 'Dons manuels', 'classe' => 7]);
    $this->compteCoti = Compte::factory()->numero('756A')->create(['association_id' => $this->asso->id, 'intitule' => 'Cotisations', 'classe' => 7]);
    UsageCompte::forceCreate([
        'association_id' => $this->asso->id, 'compte_id' => $this->compteDon->id, 'usage' => UsageComptable::Don->value,
    ]);
    UsageCompte::forceCreate([
        'association_id' => $this->asso->id, 'compte_id' => $this->compteCoti->id, 'usage' => UsageComptable::Cotisation->value,
    ]);
});

it('Compte::hasUsage returns true/false correctly', function () {
    expect($this->compteDon->hasUsage(UsageComptable::Don))->toBeTrue();
    expect($this->compteDon->hasUsage(UsageComptable::Cotisation))->toBeFalse();
    expect($this->compteCoti->hasUsage(UsageComptable::Cotisation))->toBeTrue();
});

it('Compte::forUsage scope filters correctly', function () {
    $dons = Compte::forUsage(UsageComptable::Don)->get();
    expect($dons)->toHaveCount(1);
    expect((int) $dons->first()->id)->toBe((int) $this->compteDon->id);
});

it('Association::comptesFor returns the right compte', function () {
    $compteCoti = Compte::where('association_id', $this->asso->id)->where('numero_pcg', '756A')->firstOrFail();

    $comptes = $this->asso->comptesFor(UsageComptable::Cotisation);
    expect($comptes)->toHaveCount(1);
    expect((int) $comptes->first()->id)->toBe((int) $compteCoti->id);
});
