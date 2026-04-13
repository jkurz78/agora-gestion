<?php
declare(strict_types=1);

use App\Enums\StatutReglement;

it('a les trois cas attendus', function (): void {
    expect(StatutReglement::cases())->toHaveCount(3);
    expect(StatutReglement::EnAttente->value)->toBe('en_attente');
    expect(StatutReglement::Recu->value)->toBe('recu');
    expect(StatutReglement::Pointe->value)->toBe('pointe');
});

it('isEncaisse retourne true pour recu et pointe', function (): void {
    expect(StatutReglement::EnAttente->isEncaisse())->toBeFalse();
    expect(StatutReglement::Recu->isEncaisse())->toBeTrue();
    expect(StatutReglement::Pointe->isEncaisse())->toBeTrue();
});

it('label retourne le libellé français', function (): void {
    expect(StatutReglement::EnAttente->label())->toBe('En attente');
    expect(StatutReglement::Recu->label())->toBe('Reçu');
    expect(StatutReglement::Pointe->label())->toBe('Pointé');
});
