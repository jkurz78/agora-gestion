<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('la table tiers ne contient plus les colonnes membres legacy', function (): void {
    expect(Schema::hasColumn('tiers', 'statut_membre'))->toBeFalse()
        ->and(Schema::hasColumn('tiers', 'date_adhesion'))->toBeFalse()
        ->and(Schema::hasColumn('tiers', 'notes_membre'))->toBeFalse();
});
