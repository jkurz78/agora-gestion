<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;

it('expose la colonne de valeur par type', function (): void {
    expect(TypeQuestion::TexteCourt->valueColumn())->toBe('value_text');
    expect(TypeQuestion::Satisfaction->valueColumn())->toBe('value_integer');
    expect(TypeQuestion::Ressenti->valueColumn())->toBe('value_integer');
    expect(TypeQuestion::CaseACocher->valueColumn())->toBe('value_boolean');
    expect(TypeQuestion::ChoixUnique->valueColumn())->toBe('value_option');
});

it('identifie les types à options', function (): void {
    expect(TypeQuestion::ChoixUnique->aDesOptions())->toBeTrue();
    expect(TypeQuestion::TexteCourt->aDesOptions())->toBeFalse();
});

it('donne un libellé français', function (): void {
    expect(TypeQuestion::Satisfaction->label())->toBe('Satisfaction (5 niveaux)');
});
