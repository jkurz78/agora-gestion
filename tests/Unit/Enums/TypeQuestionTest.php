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

it('Information a le bon libellé', function (): void {
    expect(TypeQuestion::Information->label())->toBe('Information / intertitre');
});

it('Information n\'est pas un type réponse, les autres oui', function (): void {
    expect(TypeQuestion::Information->estReponse())->toBeFalse();
    expect(TypeQuestion::TexteCourt->estReponse())->toBeTrue();
    expect(TypeQuestion::Satisfaction->estReponse())->toBeTrue();
    expect(TypeQuestion::ChoixUnique->estReponse())->toBeTrue();
});

it('valueColumn sur Information lève une LogicException', function (): void {
    expect(fn () => TypeQuestion::Information->valueColumn())
        ->toThrow(LogicException::class);
});

it('Information n\'a pas d\'options', function (): void {
    expect(TypeQuestion::Information->aDesOptions())->toBeFalse();
});
