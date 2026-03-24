<?php

declare(strict_types=1);

use App\Services\ExerciceService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->service = new ExerciceService;
});

describe('current()', function () {
    it('returns current year when month >= 9 (September)', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2025, 9, 15));
        expect($this->service->current())->toBe(2025);
    });

    it('returns current year when month is December', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2025, 12, 1));
        expect($this->service->current())->toBe(2025);
    });

    it('returns previous year when month < 9 (January)', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 1, 15));
        expect($this->service->current())->toBe(2025);
    });

    it('returns previous year when month is August', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 31));
        expect($this->service->current())->toBe(2025);
    });
});

describe('dateRange()', function () {
    it('returns correct start and end dates for exercice 2025', function () {
        $range = $this->service->dateRange(2025);

        expect($range['start'])->toBeInstanceOf(CarbonImmutable::class)
            ->and($range['start']->format('Y-m-d'))->toBe('2025-09-01')
            ->and($range['end'])->toBeInstanceOf(CarbonImmutable::class)
            ->and($range['end']->format('Y-m-d'))->toBe('2026-08-31');
    });

    it('returns correct dates for exercice 2024', function () {
        $range = $this->service->dateRange(2024);

        expect($range['start']->format('Y-m-d'))->toBe('2024-09-01')
            ->and($range['end']->format('Y-m-d'))->toBe('2025-08-31');
    });
});

describe('label()', function () {
    it('returns formatted label for exercice 2025', function () {
        expect($this->service->label(2025))->toBe('2025-2026');
    });

    it('returns formatted label for exercice 2024', function () {
        expect($this->service->label(2024))->toBe('2024-2025');
    });
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});
