<?php

declare(strict_types=1);

use App\Enums\JournalComptable;

it('expose les 4 journaux avec leurs valeurs', function () {
    expect(JournalComptable::Vente->value)->toBe('vente');
    expect(JournalComptable::Achat->value)->toBe('achat');
    expect(JournalComptable::Banque->value)->toBe('banque');
    expect(JournalComptable::Od->value)->toBe('od');
});

it('fournit un libellé lisible', function () {
    expect(JournalComptable::Vente->label())->toBe('Journal des ventes');
    expect(JournalComptable::Banque->label())->toBe('Journal de banque');
});
