<?php

declare(strict_types=1);

use App\Services\InvoiceOcrService;

/**
 * Helper : invoque la méthode privée buildContextBlock() via Reflection.
 * Pure construction de chaîne — aucune requête DB.
 */
function buildContextBlockViaReflection(array $context): string
{
    $service = app(InvoiceOcrService::class);
    $reflection = new ReflectionMethod(InvoiceOcrService::class, 'buildContextBlock');
    $reflection->setAccessible(true);

    return $reflection->invoke($service, $context);
}

// ── reference_attendue ────────────────────────────────────────────────────────

it('inclut la référence attendue dans le prompt', function (): void {
    $block = buildContextBlockViaReflection(['reference_attendue' => 'F-2026-0042']);

    expect($block)->toContain('F-2026-0042');
});

it('inclut un exemple de warning numéro dans le prompt quand reference_attendue est fourni', function (): void {
    $block = buildContextBlockViaReflection(['reference_attendue' => 'F-2026-0042']);

    expect($block)->toContain('numéro déposé');
});

// ── date_attendue ─────────────────────────────────────────────────────────────

it('inclut la date attendue dans le prompt', function (): void {
    $block = buildContextBlockViaReflection(['date_attendue' => '2026-03-15']);

    expect($block)->toContain('2026-03-15');
});

it('inclut un exemple de warning date dans le prompt quand date_attendue est fourni', function (): void {
    $block = buildContextBlockViaReflection(['date_attendue' => '2026-03-15']);

    expect($block)->toContain('date déposée');
});

// ── rétro-compatibilité ───────────────────────────────────────────────────────

it('ne mentionne pas reference_attendue ni "numéro déposé" quand seul tiers_attendu est fourni', function (): void {
    $block = buildContextBlockViaReflection(['tiers_attendu' => 'ACME SAS']);

    expect($block)
        ->not->toContain('reference_attendue')
        ->not->toContain('numéro déposé');
});

it('ne mentionne pas date_attendue ni "date déposée" quand seul tiers_attendu est fourni', function (): void {
    $block = buildContextBlockViaReflection(['tiers_attendu' => 'ACME SAS']);

    expect($block)
        ->not->toContain('date_attendue')
        ->not->toContain('date déposée');
});

it('contient le tiers attendu dans le bloc contexte', function (): void {
    $block = buildContextBlockViaReflection(['tiers_attendu' => 'ACME SAS']);

    expect($block)->toContain('ACME SAS');
});

// ── combinaison complète ──────────────────────────────────────────────────────

it('combine tiers_attendu + reference_attendue + date_attendue dans le même bloc', function (): void {
    $block = buildContextBlockViaReflection([
        'tiers_attendu' => 'ACME SAS',
        'reference_attendue' => 'F-2026-0099',
        'date_attendue' => '2026-04-01',
    ]);

    expect($block)
        ->toContain('ACME SAS')
        ->toContain('F-2026-0099')
        ->toContain('2026-04-01')
        ->toContain('numéro déposé')
        ->toContain('date déposée');
});
