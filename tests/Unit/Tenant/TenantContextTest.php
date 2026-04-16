<?php

declare(strict_types=1);

use App\Models\Association;
use App\Tenant\TenantContext;

beforeEach(function () {
    TenantContext::clear();
    $this->asso = Association::factory()->create(['nom' => 'Test Asso', 'slug' => 'test-asso']);
});

afterEach(function () {
    TenantContext::clear();
});

it('boot stores association and is retrievable via current', function () {
    TenantContext::boot($this->asso);
    expect(TenantContext::current())->not->toBeNull()
        ->and(TenantContext::current()->id)->toBe($this->asso->id);
});

it('current returns null before boot', function () {
    expect(TenantContext::current())->toBeNull();
});

it('clear resets context', function () {
    TenantContext::boot($this->asso);
    TenantContext::clear();
    expect(TenantContext::current())->toBeNull();
});

it('currentId returns association id or null', function () {
    expect(TenantContext::currentId())->toBeNull();
    TenantContext::boot($this->asso);
    expect(TenantContext::currentId())->toBe($this->asso->id);
});

it('hasBooted reflects state', function () {
    expect(TenantContext::hasBooted())->toBeFalse();
    TenantContext::boot($this->asso);
    expect(TenantContext::hasBooted())->toBeTrue();
});

it('requireCurrent throws when not booted', function () {
    expect(fn () => TenantContext::requireCurrent())->toThrow(RuntimeException::class);
});

it('requireCurrent returns association when booted', function () {
    TenantContext::boot($this->asso);
    expect(TenantContext::requireCurrent()->id)->toBe($this->asso->id);
});
