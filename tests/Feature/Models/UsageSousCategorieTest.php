<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use App\Tenant\TenantContext;

it('casts usage to UsageComptable enum', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $cat = Categorie::factory()->for($asso, 'association')->create(['type' => TypeCategorie::Recette]);
    $sc = SousCategorie::factory()->for($asso, 'association')->for($cat)->create();

    $link = UsageSousCategorie::create([
        'association_id' => $asso->id,
        'sous_categorie_id' => $sc->id,
        'usage' => UsageComptable::Don,
    ]);

    expect($link->usage)->toBe(UsageComptable::Don);
});

it('is tenant-scoped fail-closed', function () {
    $asso1 = Association::factory()->create();
    $asso2 = Association::factory()->create();
    TenantContext::boot($asso1);
    $cat1 = Categorie::factory()->for($asso1, 'association')->create(['type' => TypeCategorie::Recette]);
    $sc1 = SousCategorie::factory()->for($asso1, 'association')->for($cat1)->create();
    UsageSousCategorie::create([
        'association_id' => $asso1->id, 'sous_categorie_id' => $sc1->id, 'usage' => UsageComptable::Don,
    ]);
    TenantContext::boot($asso2);

    expect(UsageSousCategorie::count())->toBe(0);
});
