<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('drops the dons table', function () {
    expect(Schema::hasTable('dons'))->toBeFalse();
});

it('drops the cotisations table', function () {
    expect(Schema::hasTable('cotisations'))->toBeFalse();
});
