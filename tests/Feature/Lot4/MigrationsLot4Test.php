<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
});

afterEach(function () {
    TenantContext::clear();
});

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
    $compte = CompteBancaire::factory()->create(['nom' => 'HelloAsso']);
    $scDon = SousCategorie::factory()->create(['nom' => 'Don']);

    $p = HelloAssoParametres::create([
        'association_id' => $this->association->id,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'test',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => $compte->id,
        'sous_categorie_don_id' => $scDon->id,
    ]);

    expect($p->compte_helloasso_id)->toBe($compte->id);
    expect($p->sous_categorie_don_id)->toBe($scDon->id);
});
