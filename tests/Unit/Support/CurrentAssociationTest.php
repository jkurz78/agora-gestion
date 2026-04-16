<?php

declare(strict_types=1);

use App\Models\Association;
use App\Support\CurrentAssociation;
use App\Tenant\TenantContext;

beforeEach(fn () => TenantContext::clear());
afterEach(fn () => TenantContext::clear());

it('get returns current association', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    expect(CurrentAssociation::get()->id)->toBe($asso->id);
});

it('get throws when no tenant booted', function () {
    TenantContext::clear();

    expect(fn () => CurrentAssociation::get())->toThrow(RuntimeException::class);
});

it('tryGet returns null when no tenant', function () {
    TenantContext::clear();

    expect(CurrentAssociation::tryGet())->toBeNull();
});

it('id returns current association id', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    expect(CurrentAssociation::id())->toBe($asso->id);
});
