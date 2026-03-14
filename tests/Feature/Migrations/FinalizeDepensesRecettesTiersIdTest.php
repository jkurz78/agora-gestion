<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('depenses table has tiers_id and no string tiers column', function () {
    expect(Schema::hasColumn('depenses', 'tiers_id'))->toBeTrue();
    expect(Schema::hasColumn('depenses', 'tiers'))->toBeFalse();
});

it('recettes table has tiers_id and no string tiers column', function () {
    expect(Schema::hasColumn('recettes', 'tiers_id'))->toBeTrue();
    expect(Schema::hasColumn('recettes', 'tiers'))->toBeFalse();
});
