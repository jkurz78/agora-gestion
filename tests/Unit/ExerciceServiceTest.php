<?php

declare(strict_types=1);

use App\Services\ExerciceService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->service = new ExerciceService();
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

describe('available()', function () {
    it('returns 5 exercice years by default, descending from current', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2025, 10, 1));
        $available = $this->service->available();

        expect($available)->toBe([2025, 2024, 2023, 2022, 2021]);
    });

    it('returns specified count of exercice years', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2025, 10, 1));
        $available = $this->service->available(3);

        expect($available)->toBe([2025, 2024, 2023]);
    });

    it('returns 1 exercice year when count is 1', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 1));
        $available = $this->service->available(1);

        expect($available)->toBe([2025]);
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
