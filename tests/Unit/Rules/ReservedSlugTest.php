<?php

declare(strict_types=1);

use App\Rules\ReservedSlug;
use Illuminate\Contracts\Validation\ValidationRule;

// ──────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────

/**
 * Invokes the rule and returns whether $fail was called.
 * Returns the message passed to $fail, or null if $fail was never called.
 */
function validateSlug(string $value): ?string
{
    $rule = new ReservedSlug;
    $failMessage = null;

    $rule->validate('slug', $value, function (string $message) use (&$failMessage) {
        $failMessage = $message;
    });

    return $failMessage;
}

// ──────────────────────────────────────────────
// Contract
// ──────────────────────────────────────────────

it('implements ValidationRule interface', function () {
    expect(new ReservedSlug)->toBeInstanceOf(ValidationRule::class);
});

// ──────────────────────────────────────────────
// Valid slugs — $fail must NOT be called
// ──────────────────────────────────────────────

it('accepts a normal slug "svs"', function () {
    expect(validateSlug('svs'))->toBeNull();
});

it('accepts a normal slug "exemple"', function () {
    expect(validateSlug('exemple'))->toBeNull();
});

it('accepts a normal slug "mon-asso-2025"', function () {
    expect(validateSlug('mon-asso-2025'))->toBeNull();
});

// ──────────────────────────────────────────────
// Reserved slugs — $fail MUST be called with "Slug réservé"
// ──────────────────────────────────────────────

it('rejects "dashboard"', function () {
    expect(validateSlug('dashboard'))->toBe('Slug réservé');
});

it('rejects "login"', function () {
    expect(validateSlug('login'))->toBe('Slug réservé');
});

it('rejects "portail"', function () {
    expect(validateSlug('portail'))->toBe('Slug réservé');
});

it('rejects "admin"', function () {
    expect(validateSlug('admin'))->toBe('Slug réservé');
});

it('rejects uppercase "DASHBOARD" (case-insensitive)', function () {
    expect(validateSlug('DASHBOARD'))->toBe('Slug réservé');
});

it('rejects slug with surrounding spaces "  login  " (trim)', function () {
    expect(validateSlug('  login  '))->toBe('Slug réservé');
});

// ──────────────────────────────────────────────
// Full blacklist — every reserved slug must be rejected
// ──────────────────────────────────────────────

$blacklist = [
    'login', 'logout', 'dashboard', 'membres', 'operations', 'depenses',
    'recettes', 'dons', 'budget', 'rapprochement', 'rapports', 'exercices',
    'parametres', 'facturation', 'banques', 'tiers', 'comptabilite',
    'profile', 'admin', 'super-admin', 'portail', 'formulaire', 't',
    'email', 'tenant-assets', 'api', 'webhook', 'webhooks', 'storage',
    'livewire', 'vendor', 'sanctum', 'oauth', 'inbound-mail', 'horizon',
    'telescope', 'onboarding', 'association-selector', 'switch-association',
    'app', 'www', 'public', 'assets', 'help', 'support',
];

dataset('reserved_slugs', $blacklist);

it('rejects every slug in the blacklist', function (string $slug) {
    expect(validateSlug($slug))->toBe('Slug réservé');
})->with('reserved_slugs');
