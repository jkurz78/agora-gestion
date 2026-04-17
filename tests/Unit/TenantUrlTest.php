<?php

declare(strict_types=1);

use App\Support\TenantUrl;

it('route() returns the same thing as the global route() helper', function () {
    $expected = route('login');
    expect(TenantUrl::route('login'))->toBe($expected);
});

it('route() passes params through', function () {
    $expected = route('email.optout', ['token' => 'abc123']);
    expect(TenantUrl::route('email.optout', ['token' => 'abc123']))->toBe($expected);
});

it('to() prefixes with app URL', function () {
    $expected = url('/dashboard');
    expect(TenantUrl::to('/dashboard'))->toBe($expected);
});

it('signed() generates a signed URL', function () {
    $signed = TenantUrl::signed('email.optout', ['token' => 'abc123']);
    expect($signed)->toContain('signature=')->toContain('/email/optout/abc123');
});
