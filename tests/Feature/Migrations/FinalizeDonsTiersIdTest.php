<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('dons table has tiers_id and no donateur_id', function () {
    expect(Schema::hasColumn('dons', 'tiers_id'))->toBeTrue();
    expect(Schema::hasColumn('dons', 'donateur_id'))->toBeFalse();
});
