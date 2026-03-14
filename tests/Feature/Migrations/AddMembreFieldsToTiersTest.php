<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Schema;

it('tiers table has date_adhesion statut_membre notes_membre columns', function () {
    expect(Schema::hasColumn('tiers', 'date_adhesion'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'statut_membre'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'notes_membre'))->toBeTrue();
});
