<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->catRecette = Categorie::factory()->for($this->asso, 'association')->create(['type' => TypeCategorie::Recette]);
    $this->scDon = SousCategorie::factory()->for($this->asso, 'association')->for($this->catRecette)->create(['nom' => 'Dons manuels']);
    $this->scCoti = SousCategorie::factory()->for($this->asso, 'association')->for($this->catRecette)->create(['nom' => 'Cotisations']);
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

it('Association::sousCategoriesFor returns the right sous-cat', function () {
    $cotis = $this->asso->sousCategoriesFor(UsageComptable::Cotisation);
    expect($cotis)->toHaveCount(1);
    expect($cotis->first()->id)->toBe($this->scCoti->id);
});
