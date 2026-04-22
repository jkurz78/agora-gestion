<?php

declare(strict_types=1);

use App\Models\Association;
use App\Support\MonoAssociation;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Flush memoized cache before every test.
    MonoAssociation::flush();
    // Wipe all associations so every test controls DB state explicitly.
    // forceDelete bypasses SoftDeletes; Association has none, but harmless.
    DB::table('association')->delete();
});

it('returns false when no association exists in DB', function () {
    // DB already empty from beforeEach.
    expect(MonoAssociation::isActive())->toBeFalse();
});

it('returns true when exactly one association exists in DB', function () {
    Association::factory()->create();

    expect(MonoAssociation::isActive())->toBeTrue();
});

it('returns false when two or more associations exist in DB', function () {
    Association::factory()->count(2)->create();

    expect(MonoAssociation::isActive())->toBeFalse();
});

it('only hits the database once across multiple calls (memoized)', function () {
    Association::factory()->create();

    DB::enableQueryLog();

    for ($i = 0; $i < 5; $i++) {
        MonoAssociation::isActive();
    }

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    expect(count($log))->toBe(1);
});

it('returns updated result after flush when a second association is added', function () {
    Association::factory()->create();

    expect(MonoAssociation::isActive())->toBeTrue();

    Association::factory()->create();

    // Without flush the cache still says true; flush resets it.
    MonoAssociation::flush();

    expect(MonoAssociation::isActive())->toBeFalse();
});

it('keeps returning the memoized value when a second association is added without flush', function () {
    Association::factory()->create();

    // Prime the cache → true.
    expect(MonoAssociation::isActive())->toBeTrue();

    Association::factory()->create();

    // No flush → still returns the cached true.
    expect(MonoAssociation::isActive())->toBeTrue();
});
