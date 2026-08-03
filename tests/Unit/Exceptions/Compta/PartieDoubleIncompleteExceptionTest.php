<?php

declare(strict_types=1);

use App\Exceptions\Compta\PartieDoubleIncompleteException;

test('nonEquilibree contains transaction id and equilibree=false', function () {
    $e = PartieDoubleIncompleteException::nonEquilibree(42);

    expect($e->getMessage())
        ->toContain('#42')
        ->toContain('equilibree=false');
});

test('sansLignes contains transaction id and aucune ligne comptable', function () {
    $e = PartieDoubleIncompleteException::sansLignes(42);

    expect($e->getMessage())
        ->toContain('#42')
        ->toContain('aucune ligne comptable');
});

test('desequilibree contains transaction id, debit and credit amounts', function () {
    $e = PartieDoubleIncompleteException::desequilibree(42, '100.00', '80.00');

    expect($e->getMessage())
        ->toContain('#42')
        ->toContain('100.00')
        ->toContain('80.00');
});
