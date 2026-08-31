<?php

use App\Services\Compta\SensMontantPd;

it('signe une ligne de classe 6 au debit', function () {
    expect(SensMontantPd::ligne(6))->toBe('SUM(tl.debit) - SUM(tl.credit)');
});

it('signe une ligne de classe 7 au credit', function () {
    expect(SensMontantPd::ligne(7))->toBe('SUM(tl.credit) - SUM(tl.debit)');
});

it('porte une affectation de classe 6 au sens de sa ligne parente', function () {
    expect(SensMontantPd::affectation(6))
        ->toBe('SUM(tla.montant * (CASE WHEN tl.debit >= tl.credit THEN 1 ELSE -1 END))');
});

it('porte une affectation de classe 7 au sens de sa ligne parente', function () {
    expect(SensMontantPd::affectation(7))
        ->toBe('SUM(tla.montant * (CASE WHEN tl.credit >= tl.debit THEN 1 ELSE -1 END))');
});

it('accepte des alias personnalises', function () {
    expect(SensMontantPd::affectation(7, 'tl', 'tla2'))
        ->toBe('SUM(tla2.montant * (CASE WHEN tl.credit >= tl.debit THEN 1 ELSE -1 END))');
});
