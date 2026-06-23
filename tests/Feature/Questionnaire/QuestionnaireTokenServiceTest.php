<?php

declare(strict_types=1);

use App\Services\Questionnaire\QuestionnaireTokenService;

it('produit un token clair long et son hash sha256', function (): void {
    $pair = app(QuestionnaireTokenService::class)->generer();

    expect(strlen($pair['clair']))->toBeGreaterThanOrEqual(40);
    expect($pair['hash'])->toBe(hash('sha256', $pair['clair']));
    expect($pair['hash'])->toHaveLength(64);
});

it('produit un code court lisible sans caractères ambigus', function (): void {
    $code = app(QuestionnaireTokenService::class)->codeCourt();

    expect($code)->toMatch('/^[0-9A-Z\-]+$/');
    expect($code)->not->toContain('O'); // alphabet sans ambiguïté
});
