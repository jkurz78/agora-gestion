<?php

declare(strict_types=1);

use App\Enums\Sens;
use App\Enums\StatutReglement;

it('expose la nouvelle valeur en_main', function () {
    expect(StatutReglement::EnMain->value)->toBe('en_main');
});

it('label direction-aware : ouvert = Dû dans les deux sens', function () {
    expect(StatutReglement::EnAttente->label(Sens::Recette))->toBe('Dû');
    expect(StatutReglement::EnAttente->label(Sens::Depense))->toBe('Dû');
});

it('label direction-aware : dénoué = Remis (recette) / Réglé (dépense)', function () {
    expect(StatutReglement::Recu->label(Sens::Recette))->toBe('Remis');
    expect(StatutReglement::Recu->label(Sens::Depense))->toBe('Réglé');
});

it('label : en main = À remettre, pointé = Pointé', function () {
    expect(StatutReglement::EnMain->label())->toBe('À remettre');
    expect(StatutReglement::Pointe->label(Sens::Recette))->toBe('Pointé');
    expect(StatutReglement::Pointe->label(Sens::Depense))->toBe('Pointé');
});

it('helpers de position : estOuvert / estEnMain / estDenoue', function () {
    expect(StatutReglement::EnAttente->estOuvert())->toBeTrue();
    expect(StatutReglement::EnMain->estEnMain())->toBeTrue();
    expect(StatutReglement::Recu->estDenoue())->toBeTrue();
    expect(StatutReglement::Pointe->estOuvert())->toBeFalse();
});

it('isEncaisse inchangé : tout sauf EnAttente est encaissé (EnMain inclus)', function () {
    expect(StatutReglement::EnAttente->isEncaisse())->toBeFalse();
    expect(StatutReglement::EnMain->isEncaisse())->toBeTrue();
    expect(StatutReglement::Recu->isEncaisse())->toBeTrue();
    expect(StatutReglement::Pointe->isEncaisse())->toBeTrue();
});
