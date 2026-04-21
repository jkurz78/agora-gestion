<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\SousCategorie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('sous_categories table has no pour_inscriptions column (dropped)', function () {
    expect(Schema::hasColumn('sous_categories', 'pour_inscriptions'))->toBeFalse();
});

it('sous_categories factory creates without usage by default', function () {
    $sc = SousCategorie::factory()->create();
    $sc->refresh();

    expect($sc->hasUsage(UsageComptable::Inscription))->toBeFalse();
});

it('factory pourInscriptions creates pivot row', function () {
    $sc = SousCategorie::factory()->pourInscriptions()->create();
    $sc->refresh();

    expect($sc->hasUsage(UsageComptable::Inscription))->toBeTrue();
});
