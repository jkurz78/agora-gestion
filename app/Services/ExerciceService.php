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
        if (session()->has('exercice_actif')) {
            return (int) session('exercice_actif');
        }

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
        $now = CarbonImmutable::now();
        $realCurrent = $now->month >= 9 ? $now->year : $now->year - 1;

        return array_map(
            fn (int $i): int => $realCurrent - $i,
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

    /**
     * Return the best default date for a new entry in the active exercice.
     * Returns today if in range, dateFin if past, dateDebut if future.
     */
    public function defaultDate(): string
    {
        $range = $this->dateRange($this->current());
        $today = CarbonImmutable::today();

        if ($today->lt($range['start'])) {
            return $range['start']->toDateString();
        }

        if ($today->gt($range['end'])) {
            return $range['end']->toDateString();
        }

        return $today->toDateString();
    }
}
