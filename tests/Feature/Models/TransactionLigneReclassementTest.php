<?php

declare(strict_types=1);

use App\Models\TransactionLigne;

/*
 * `reclassee_at` est LA marque : elle dit que le compte de cette ligne a été
 * choisi à la main. La synchronisation HelloAsso s'en sert pour ne plus
 * réécrire le compte ni l'opération.
 */

it('n\'est pas reclassée par défaut', function () {
    expect((new TransactionLigne)->estReclassee())->toBeFalse();
});

it('est reclassée dès que la marque porte une date', function () {
    $ligne = new TransactionLigne(['reclassee_at' => now()]);

    expect($ligne->estReclassee())->toBeTrue();
});
