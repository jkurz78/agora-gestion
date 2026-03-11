<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

final class ExerciceService
{
    /**
     * Return the current exercice year.
     * Financial year runs September 1 to August 31, identified by start year.
     */
    public function current(): int
    {
        $now = CarbonImmutable::now();

        return $now->month >= 9 ? $now->year : $now->year - 1;
    }

    /**
     * Return the start and end dates for a given exercice.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function dateRange(int $exercice): array
    {
        return [
            'start' => CarbonImmutable::create($exercice, 9, 1)->startOfDay(),
            'end' => CarbonImmutable::create($exercice + 1, 8, 31)->startOfDay(),
        ];
    }

    /**
     * Return a list of available exercice years, descending from current.
     *
     * @return list<int>
     */
    public function available(int $count = 5): array
    {
        $current = $this->current();

        return array_map(
            fn (int $i): int => $current - $i,
            range(0, $count - 1),
        );
    }

    /**
     * Return a display label for the given exercice, e.g. "2025-2026".
     */
    public function label(int $exercice): string
    {
        return $exercice.'-'.($exercice + 1);
    }
}
