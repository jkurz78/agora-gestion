<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('cotisations table has tiers_id and no membre_id', function () {
    expect(Schema::hasColumn('cotisations', 'tiers_id'))->toBeTrue();
    expect(Schema::hasColumn('cotisations', 'membre_id'))->toBeFalse();
});
