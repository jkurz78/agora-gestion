<?php

declare(strict_types=1);

use App\Models\Categorie;
use App\Models\SousCategorie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('sous_categories table has pour_inscriptions column', function () {
    expect(Schema::hasColumn('sous_categories', 'pour_inscriptions'))->toBeTrue();
});

it('pour_inscriptions defaults to false', function () {
    $sc = SousCategorie::factory()->create();

    $sc->refresh();

    expect($sc->pour_inscriptions)->toBeFalse();
});

it('can set pour_inscriptions to true', function () {
    $sc = SousCategorie::factory()->pourInscriptions()->create();

    $sc->refresh();

    expect($sc->pour_inscriptions)->toBeTrue();
});
