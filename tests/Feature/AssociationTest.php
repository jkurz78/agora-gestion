<?php
declare(strict_types=1);

use App\Models\Association;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates association row with id=1 when none exists', function () {
    $association = Association::find(1) ?? new Association();
    $association->id = 1;
    $association->fill(['nom' => 'SVS Test', 'ville' => 'Paris'])->save();

    expect(Association::count())->toBe(1)
        ->and(Association::find(1)?->nom)->toBe('SVS Test');
});

it('updates existing association without creating duplicate', function () {
    $assoc = Association::find(1) ?? new Association();
    $assoc->id = 1;
    $assoc->fill(['nom' => 'Initial', 'ville' => 'Paris'])->save();

    $assoc2 = Association::find(1) ?? new Association();
    $assoc2->id = 1;
    $assoc2->fill(['nom' => 'Mis à jour', 'ville' => 'Lyon'])->save();

    expect(Association::count())->toBe(1)
        ->and(Association::find(1)?->nom)->toBe('Mis à jour');
});
