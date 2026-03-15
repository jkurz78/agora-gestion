<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('la table sequences existe avec les bonnes colonnes', function () {
    expect(Schema::hasTable('sequences'))->toBeTrue();
    expect(Schema::hasColumn('sequences', 'exercice'))->toBeTrue();
    expect(Schema::hasColumn('sequences', 'dernier_numero'))->toBeTrue();
});

it('recettes a la colonne numero_piece', function () {
    expect(Schema::hasColumn('recettes', 'numero_piece'))->toBeTrue();
});

it('depenses a la colonne numero_piece', function () {
    expect(Schema::hasColumn('depenses', 'numero_piece'))->toBeTrue();
});

it('dons a la colonne numero_piece', function () {
    expect(Schema::hasColumn('dons', 'numero_piece'))->toBeTrue();
});

it('cotisations a la colonne numero_piece', function () {
    expect(Schema::hasColumn('cotisations', 'numero_piece'))->toBeTrue();
});

it('virements_internes a la colonne numero_piece', function () {
    expect(Schema::hasColumn('virements_internes', 'numero_piece'))->toBeTrue();
});
