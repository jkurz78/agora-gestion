<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('dons table has tiers_id column', function () {
    expect(Schema::hasColumn('dons', 'tiers_id'))->toBeTrue();
});

it('cotisations table has tiers_id column', function () {
    expect(Schema::hasColumn('cotisations', 'tiers_id'))->toBeTrue();
});

it('depenses table has tiers_id column', function () {
    expect(Schema::hasColumn('depenses', 'tiers_id'))->toBeTrue();
});

it('recettes table has tiers_id column', function () {
    expect(Schema::hasColumn('recettes', 'tiers_id'))->toBeTrue();
});
