<?php

declare(strict_types=1);

use App\Models\HelloAssoParametres;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('has sync config columns on helloasso_parametres', function () {
    expect(Schema::hasColumn('helloasso_parametres', 'compte_helloasso_id'))->toBeTrue();
    expect(Schema::hasColumn('helloasso_parametres', 'compte_versement_id'))->toBeTrue();
    expect(Schema::hasColumn('helloasso_parametres', 'sous_categorie_don_id'))->toBeTrue();
    expect(Schema::hasColumn('helloasso_parametres', 'sous_categorie_cotisation_id'))->toBeTrue();
    expect(Schema::hasColumn('helloasso_parametres', 'sous_categorie_inscription_id'))->toBeTrue();
});

it('has helloasso_form_mappings table', function () {
    expect(Schema::hasTable('helloasso_form_mappings'))->toBeTrue();
    expect(Schema::hasColumn('helloasso_form_mappings', 'form_slug'))->toBeTrue();
    expect(Schema::hasColumn('helloasso_form_mappings', 'form_type'))->toBeTrue();
    expect(Schema::hasColumn('helloasso_form_mappings', 'operation_id'))->toBeTrue();
});

it('can save sync config on helloasso_parametres', function () {
    DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('comptes_bancaires')->insertOrIgnore(['id' => 1, 'nom' => 'HelloAsso', 'solde_initial' => 0, 'date_solde_initial' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('categories')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'type' => 'recette', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sous_categories')->insertOrIgnore(['id' => 2, 'categorie_id' => 1, 'nom' => 'Don', 'created_at' => now(), 'updated_at' => now()]);

    $p = HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'test',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => 1,
        'sous_categorie_don_id' => 2,
    ]);

    expect($p->compte_helloasso_id)->toBe(1);
    expect($p->sous_categorie_don_id)->toBe(2);
});
