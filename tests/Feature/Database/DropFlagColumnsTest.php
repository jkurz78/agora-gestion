<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('drops flag columns from sous_categories', function () {
    expect(Schema::hasColumn('sous_categories', 'pour_dons'))->toBeFalse();
    expect(Schema::hasColumn('sous_categories', 'pour_cotisations'))->toBeFalse();
    expect(Schema::hasColumn('sous_categories', 'pour_inscriptions'))->toBeFalse();
    expect(Schema::hasColumn('sous_categories', 'pour_frais_kilometriques'))->toBeFalse();
});
