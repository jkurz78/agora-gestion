<?php

declare(strict_types=1);

use App\Enums\EtapeCompta;

it('donne un libellé français et un geste prescrit pour chaque étape', function (): void {
    foreach (EtapeCompta::cases() as $etape) {
        expect($etape->label())->not->toBe('');
    }

    expect(EtapeCompta::BackfillRequis->geste())
        ->toBe('php artisan compta:backfill-partie-double --all')
        ->and(EtapeCompta::ReconciliationRequise->geste())
        ->toBe('php artisan compta:reconcilier-statuts')
        ->and(EtapeCompta::Operationnel->geste())
        ->toBeNull();
});
