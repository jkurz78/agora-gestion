<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('creates the usages_sous_categories table with correct columns and unique index', function () {
    expect(Schema::hasTable('usages_sous_categories'))->toBeTrue();
    expect(Schema::hasColumns('usages_sous_categories', [
        'id', 'association_id', 'sous_categorie_id', 'usage', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('enforces unique (association_id, sous_categorie_id, usage)', function () {
    $asso = \App\Models\Association::factory()->create();
    \App\Tenant\TenantContext::boot($asso);
    $cat = \App\Models\Categorie::factory()->for($asso, 'association')->create(['type' => \App\Enums\TypeCategorie::Recette]);
    $sc = \App\Models\SousCategorie::factory()->for($asso, 'association')->for($cat)->create();

    \Illuminate\Support\Facades\DB::table('usages_sous_categories')->insert([
        'association_id' => $asso->id, 'sous_categorie_id' => $sc->id, 'usage' => 'don',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => \Illuminate\Support\Facades\DB::table('usages_sous_categories')->insert([
        'association_id' => $asso->id, 'sous_categorie_id' => $sc->id, 'usage' => 'don',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
