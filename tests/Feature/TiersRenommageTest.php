<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('recettes table has tiers_id column', function () {
    expect(Schema::hasColumn('recettes', 'tiers_id'))->toBeTrue();
    expect(Schema::hasColumn('recettes', 'payeur'))->toBeFalse();
});

it('depenses table has tiers_id column', function () {
    expect(Schema::hasColumn('depenses', 'tiers_id'))->toBeTrue();
    expect(Schema::hasColumn('depenses', 'beneficiaire'))->toBeFalse();
});
