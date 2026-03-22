<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('transactions table has tiers_id column', function () {
    expect(Schema::hasColumn('transactions', 'tiers_id'))->toBeTrue();
});
