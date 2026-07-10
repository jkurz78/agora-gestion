<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->catRecette = Categorie::factory()->for($this->asso, 'association')->create(['type' => TypeCategorie::Recette]);
    $this->scDon = SousCategorie::factory()->for($this->asso, 'association')->for($this->catRecette)->create(['nom' => 'Dons manuels', 'code_cerfa' => '754A']);
    $this->scCoti = SousCategorie::factory()->for($this->asso, 'association')->for($this->catRecette)->create(['nom' => 'Cotisations', 'code_cerfa' => '756A']);
    UsageSousCategorie::create([
        'association_id' => $this->asso->id, 'sous_categorie_id' => $this->scDon->id, 'usage' => UsageComptable::Don,
    ]);
    UsageSousCategorie::create([
        'association_id' => $this->asso->id, 'sous_categorie_id' => $this->scCoti->id, 'usage' => UsageComptable::Cotisation,
    ]);
});

it('SousCategorie::hasUsage returns true/false correctly', function () {
    expect($this->scDon->hasUsage(UsageComptable::Don))->toBeTrue();
    expect($this->scDon->hasUsage(UsageComptable::Cotisation))->toBeFalse();
    expect($this->scCoti->hasUsage(UsageComptable::Cotisation))->toBeTrue();
});

it('SousCategorie::forUsage scope filters correctly', function () {
    $dons = SousCategorie::forUsage(UsageComptable::Don)->get();
    expect($dons)->toHaveCount(1);
    expect($dons->first()->id)->toBe($this->scDon->id);
});

it('Association::comptesFor returns the right compte', function () {
    $compteCoti = Compte::where('association_id', $this->asso->id)->where('numero_pcg', '756A')->firstOrFail();

    $comptes = $this->asso->comptesFor(UsageComptable::Cotisation);
    expect($comptes)->toHaveCount(1);
    expect($comptes->first()->id)->toBe($compteCoti->id);
});
