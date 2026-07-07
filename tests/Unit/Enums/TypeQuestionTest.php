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

// ── SatisfactionTexteLong ────────────────────────────────────────

it('SatisfactionTexteLong a le bon libellé', function (): void {
    expect(TypeQuestion::SatisfactionTexteLong->label())->toBe('Satisfaction + texte long');
});

it('SatisfactionTexteLong a value_integer comme colonne primaire', function (): void {
    expect(TypeQuestion::SatisfactionTexteLong->valueColumn())->toBe('value_integer');
});

it('SatisfactionTexteLong est un type réponse', function (): void {
    expect(TypeQuestion::SatisfactionTexteLong->estReponse())->toBeTrue();
});

it('SatisfactionTexteLong n\'a pas d\'options', function (): void {
    expect(TypeQuestion::SatisfactionTexteLong->aDesOptions())->toBeFalse();
});

// ── Date ────────────────────────────────────────
it('Date a le bon libellé', function (): void {
    expect(TypeQuestion::Date->label())->toBe('Date');
});

it('Date stocke dans value_text', function (): void {
    expect(TypeQuestion::Date->valueColumn())->toBe('value_text');
});

it('Date est un type réponse sans options', function (): void {
    expect(TypeQuestion::Date->estReponse())->toBeTrue();
    expect(TypeQuestion::Date->aDesOptions())->toBeFalse();
});

// ── ChoixMultiple ────────────────────────────────
it('ChoixMultiple a le bon libellé', function (): void {
    expect(TypeQuestion::ChoixMultiple->label())->toBe('Choix multiple');
});

it('ChoixMultiple stocke dans value_option', function (): void {
    expect(TypeQuestion::ChoixMultiple->valueColumn())->toBe('value_option');
});

it('ChoixMultiple est un type réponse avec options', function (): void {
    expect(TypeQuestion::ChoixMultiple->estReponse())->toBeTrue();
    expect(TypeQuestion::ChoixMultiple->aDesOptions())->toBeTrue();
});

// ── Nombre ───────────────────────────────────────
it('Nombre a le bon libellé', function (): void {
    expect(TypeQuestion::Nombre->label())->toBe('Nombre');
});

it('Nombre stocke dans value_text', function (): void {
    expect(TypeQuestion::Nombre->valueColumn())->toBe('value_text');
});

it('Nombre est un type réponse sans options', function (): void {
    expect(TypeQuestion::Nombre->estReponse())->toBeTrue();
    expect(TypeQuestion::Nombre->aDesOptions())->toBeFalse();
});

// ── Email ────────────────────────────────────────
it('Email a le bon libellé', function (): void {
    expect(TypeQuestion::Email->label())->toBe('Adresse email');
});

it('Email stocke dans value_text', function (): void {
    expect(TypeQuestion::Email->valueColumn())->toBe('value_text');
});

it('Email est un type réponse sans options', function (): void {
    expect(TypeQuestion::Email->estReponse())->toBeTrue();
    expect(TypeQuestion::Email->aDesOptions())->toBeFalse();
});

// ── SelectionNumerique ───────────────────────────
it('SelectionNumerique a le bon libellé', function (): void {
    expect(TypeQuestion::SelectionNumerique->label())->toBe('Sélection numérique');
});

it('SelectionNumerique stocke dans value_integer', function (): void {
    expect(TypeQuestion::SelectionNumerique->valueColumn())->toBe('value_integer');
});

it('SelectionNumerique est un type réponse sans options', function (): void {
    expect(TypeQuestion::SelectionNumerique->estReponse())->toBeTrue();
    expect(TypeQuestion::SelectionNumerique->aDesOptions())->toBeFalse();
});
