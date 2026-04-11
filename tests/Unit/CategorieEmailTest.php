<?php

declare(strict_types=1);

use App\Enums\CategorieEmail;

it('CategorieEmail::Message exists with value message', function () {
    expect(CategorieEmail::Message)->toBeInstanceOf(CategorieEmail::class)
        ->and(CategorieEmail::Message->value)->toBe('message');
});

it('CategorieEmail::Message label returns Message libre', function () {
    expect(CategorieEmail::Message->label())->toBe('Message libre');
});

it('CategorieEmail::Message variables contains the 4 seance variables', function () {
    $variables = CategorieEmail::Message->variables();

    expect($variables)->toHaveKey('{date_prochaine_seance}')
        ->and($variables)->toHaveKey('{date_precedente_seance}')
        ->and($variables)->toHaveKey('{numero_prochaine_seance}')
        ->and($variables)->toHaveKey('{numero_precedente_seance}');

    expect($variables['{date_prochaine_seance}'])->toBe('Date de la prochaine séance')
        ->and($variables['{date_precedente_seance}'])->toBe('Date de la précédente séance')
        ->and($variables['{numero_prochaine_seance}'])->toBe('Numéro de la prochaine séance')
        ->and($variables['{numero_precedente_seance}'])->toBe('Numéro de la précédente séance');
});
