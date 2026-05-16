<?php

declare(strict_types=1);

use App\Enums\CategorieEmail;

// ─── labelPortail() ───────────────────────────────────────────────────────────

it('CategorieEmail::Formulaire labelPortail returns Questionnaire', function (): void {
    expect(CategorieEmail::Formulaire->labelPortail())->toBe('Questionnaire');
});

it('CategorieEmail::Attestation labelPortail returns Attestation', function (): void {
    expect(CategorieEmail::Attestation->labelPortail())->toBe('Attestation');
});

it('CategorieEmail::Document labelPortail returns Document', function (): void {
    expect(CategorieEmail::Document->labelPortail())->toBe('Document');
});

it('CategorieEmail::Message labelPortail returns Message', function (): void {
    expect(CategorieEmail::Message->labelPortail())->toBe('Message');
});

it('CategorieEmail::Communication labelPortail returns Actualités', function (): void {
    expect(CategorieEmail::Communication->labelPortail())->toBe('Actualités');
});

// ─── cssBadgePortail() ────────────────────────────────────────────────────────

it('CategorieEmail::Formulaire cssBadgePortail returns bg-warning text-dark', function (): void {
    expect(CategorieEmail::Formulaire->cssBadgePortail())->toBe('bg-warning text-dark');
});

it('CategorieEmail::Attestation cssBadgePortail returns bg-success', function (): void {
    expect(CategorieEmail::Attestation->cssBadgePortail())->toBe('bg-success');
});

it('CategorieEmail::Document cssBadgePortail returns bg-info text-dark', function (): void {
    expect(CategorieEmail::Document->cssBadgePortail())->toBe('bg-info text-dark');
});

it('CategorieEmail::Message cssBadgePortail returns bg-secondary', function (): void {
    expect(CategorieEmail::Message->cssBadgePortail())->toBe('bg-secondary');
});

it('CategorieEmail::Communication cssBadgePortail returns bg-primary', function (): void {
    expect(CategorieEmail::Communication->cssBadgePortail())->toBe('bg-primary');
});
