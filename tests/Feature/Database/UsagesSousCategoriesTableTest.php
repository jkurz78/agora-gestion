<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the usages_sous_categories table with correct columns and unique index', function () {
    expect(Schema::hasTable('usages_sous_categories'))->toBeTrue();
    expect(Schema::hasColumns('usages_sous_categories', [
        'id', 'association_id', 'sous_categorie_id', 'usage', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('enforces unique (association_id, sous_categorie_id, usage)', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);
    $cat = Categorie::factory()->for($asso, 'association')->create(['type' => TypeCategorie::Recette]);
    $sc = SousCategorie::factory()->for($asso, 'association')->for($cat)->create();

    DB::table('usages_sous_categories')->insert([
        'association_id' => $asso->id, 'sous_categorie_id' => $sc->id, 'usage' => 'don',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('usages_sous_categories')->insert([
        'association_id' => $asso->id, 'sous_categorie_id' => $sc->id, 'usage' => 'don',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
