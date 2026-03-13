<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('recettes table has tiers column', function () {
    expect(Schema::hasColumn('recettes', 'tiers'))->toBeTrue();
    expect(Schema::hasColumn('recettes', 'payeur'))->toBeFalse();
});

it('depenses table has tiers column', function () {
    expect(Schema::hasColumn('depenses', 'tiers'))->toBeTrue();
    expect(Schema::hasColumn('depenses', 'beneficiaire'))->toBeFalse();
});
