<?php

declare(strict_types=1);

use App\Services\InvoiceOcrService;

/**
 * Helper : invoque la méthode privée buildPrompt() via Reflection.
 * InvoiceOcrService est `final`, donc l'héritage n'est pas possible.
 */
function buildPromptViaReflection(?array $context): string
{
    $service = app(InvoiceOcrService::class);
    $method = new ReflectionMethod(InvoiceOcrService::class, 'buildPrompt');
    $method->setAccessible(true);

    return $method->invoke($service, $context);
}

// ── reference_attendue ────────────────────────────────────────────────────────

it('inclut la référence attendue dans le prompt', function (): void {
    $prompt = buildPromptViaReflection(['reference_attendue' => 'F-2026-0042']);

    expect($prompt)->toContain('F-2026-0042');
});

it('inclut un exemple de warning numéro dans le prompt quand reference_attendue est fourni', function (): void {
    $prompt = buildPromptViaReflection(['reference_attendue' => 'F-2026-0042']);

    expect($prompt)->toContain('numéro');
});

// ── date_attendue ─────────────────────────────────────────────────────────────

it('inclut la date attendue dans le prompt', function (): void {
    $prompt = buildPromptViaReflection(['date_attendue' => '2026-03-15']);

    expect($prompt)->toContain('2026-03-15');
});

it('inclut un exemple de warning date dans le prompt quand date_attendue est fourni', function (): void {
    $prompt = buildPromptViaReflection(['date_attendue' => '2026-03-15']);

    expect($prompt)->toContain('date');
});

// ── rétro-compatibilité ───────────────────────────────────────────────────────

it('ne mentionne pas reference_attendue ni "numéro déposé" quand seul tiers_attendu est fourni', function (): void {
    $prompt = buildPromptViaReflection(['tiers_attendu' => 'ACME SAS']);

    expect($prompt)
        ->not->toContain('reference_attendue')
        ->not->toContain('numéro déposé');
});

it('ne mentionne pas date_attendue ni "date déposée" quand seul tiers_attendu est fourni', function (): void {
    $prompt = buildPromptViaReflection(['tiers_attendu' => 'ACME SAS']);

    expect($prompt)
        ->not->toContain('date_attendue')
        ->not->toContain('date déposée');
});

it('contient le tiers attendu dans le bloc contexte', function (): void {
    $prompt = buildPromptViaReflection(['tiers_attendu' => 'ACME SAS']);

    expect($prompt)->toContain('ACME SAS');
});

// ── combinaison complète ──────────────────────────────────────────────────────

it('combine tiers_attendu + reference_attendue + date_attendue dans le même bloc', function (): void {
    $prompt = buildPromptViaReflection([
        'tiers_attendu' => 'ACME SAS',
        'reference_attendue' => 'F-2026-0099',
        'date_attendue' => '2026-04-01',
    ]);

    expect($prompt)
        ->toContain('ACME SAS')
        ->toContain('F-2026-0099')
        ->toContain('2026-04-01')
        ->toContain('numéro')
        ->toContain('date');
});
