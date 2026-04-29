<?php

declare(strict_types=1);

use App\Support\Demo\DateDelta;
use Carbon\Carbon;

test('toDelta returns 0d for same date', function (): void {
    $ref = Carbon::parse('2026-04-28');
    $date = Carbon::parse('2026-04-28');

    expect(DateDelta::toDelta($date, $ref))->toBe('0d');
});

test('toDelta returns -13d for 13 days in the past', function (): void {
    $ref = Carbon::parse('2026-04-28');
    $date = Carbon::parse('2026-04-15');

    expect(DateDelta::toDelta($date, $ref))->toBe('-13d');
});

test('toDelta returns +5d for 5 days in the future', function (): void {
    $ref = Carbon::parse('2026-04-28');
    $date = Carbon::parse('2026-05-03');

    expect(DateDelta::toDelta($date, $ref))->toBe('+5d');
});

test('toDelta prefers months for exact multiples of 30', function (): void {
    $ref = Carbon::parse('2026-04-28');
    $date = Carbon::parse('2026-02-27'); // 60 days before

    expect(DateDelta::toDelta($date, $ref))->toBe('-2M');
});

test('toDelta returns -1y for exactly one year', function (): void {
    $ref = Carbon::parse('2026-04-28');
    $date = Carbon::parse('2025-04-28');

    expect(DateDelta::toDelta($date, $ref))->toBe('-1y');
});

test('fromDelta round-trips toDelta within one day', function (): void {
    $ref = Carbon::parse('2026-04-28');
    $original = Carbon::parse('2026-01-10');

    $delta = DateDelta::toDelta($original, $ref);
    $restored = DateDelta::fromDelta($delta, $ref);

    // Should be within 1 day of original
    expect(abs($original->diffInDays($restored)))->toBeLessThanOrEqual(1);
});

test('fromDelta -13d from 2026-04-28 returns 2026-04-15', function (): void {
    $ref = Carbon::parse('2026-04-28');
    $result = DateDelta::fromDelta('-13d', $ref);

    expect($result->format('Y-m-d'))->toBe('2026-04-15');
});

test('fromDelta +5d from 2026-04-28 returns 2026-05-03', function (): void {
    $ref = Carbon::parse('2026-04-28');
    $result = DateDelta::fromDelta('+5d', $ref);

    expect($result->format('Y-m-d'))->toBe('2026-05-03');
});

test('fromDelta -2M from 2026-04-28 returns 2026-02-27', function (): void {
    $ref = Carbon::parse('2026-04-28');
    $result = DateDelta::fromDelta('-2M', $ref);

    // -2M = -60 days; 2026-04-28 - 60 days = 2026-02-27
    expect($result->format('Y-m-d'))->toBe('2026-02-27');
});

test('fromDelta -1y from 2026-04-28 returns 2025-04-28', function (): void {
    $ref = Carbon::parse('2026-04-28');
    $result = DateDelta::fromDelta('-1y', $ref);

    expect($result->format('Y-m-d'))->toBe('2025-04-28');
});
