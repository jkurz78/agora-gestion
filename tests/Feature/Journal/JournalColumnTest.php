<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('ajoute la colonne journal sur transactions', function () {
    expect(Schema::hasColumn('transactions', 'journal'))->toBeTrue();
});
