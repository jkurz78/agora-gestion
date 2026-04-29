<?php

declare(strict_types=1);

namespace App\Support\Demo;

use Carbon\Carbon;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Converts absolute dates to relative delta strings and back.
 *
 * Delta format: [-|+]N[d|M|y]
 *   d = days
 *   M = months (multiples of 30 days)
 *   y = years (multiples of 365 days)
 *
 * Granularity rules (shortest wins):
 *   - If diff in days is an exact multiple of 365 → use years
 *   - Else if diff in days is an exact multiple of 30 → use months
 *   - Else use days
 */
final class DateDelta
{
    /**
     * Convert an absolute date to a delta string relative to $reference.
     *
     * Examples:
     *   toDelta(2026-04-15, 2026-04-28) → '-13d'
     *   toDelta(2025-04-28, 2026-04-28) → '-1y'
     *   toDelta(2026-05-03, 2026-04-28) → '+5d'
     */
    public static function toDelta(DateTimeInterface $absolute, DateTimeInterface $reference): string
    {
        $absDate = Carbon::instance($absolute)->startOfDay();
        $refDate = Carbon::instance($reference)->startOfDay();

        // diffInDays can return negative; take abs and compute sign separately
        $diffDays = abs((int) $refDate->diffInDays($absDate));
        $sign = $absDate->lessThan($refDate) ? '-' : ($absDate->greaterThan($refDate) ? '+' : '');

        if ($diffDays === 0) {
            return '0d';
        }

        // Prefer years if exact multiple of 365
        if ($diffDays % 365 === 0) {
            $years = intdiv($diffDays, 365);

            return $sign.$years.'y';
        }

        // Prefer months if exact multiple of 30
        if ($diffDays % 30 === 0) {
            $months = intdiv($diffDays, 30);

            return $sign.$months.'M';
        }

        return $sign.$diffDays.'d';
    }

    /**
     * Convert a delta string back to an absolute date relative to $reference.
     *
     * Examples:
     *   fromDelta('-13d', 2026-04-28) → 2026-04-15
     *   fromDelta('-1y', 2026-04-28)  → 2025-04-28
     *   fromDelta('+5d', 2026-04-28)  → 2026-05-03
     */
    public static function fromDelta(string $delta, DateTimeInterface $reference): Carbon
    {
        $refDate = Carbon::instance($reference)->startOfDay();

        if ($delta === '0d') {
            return $refDate->copy();
        }

        if (! preg_match('/^([+-]?)(\d+)([dMy])$/', $delta, $matches)) {
            throw new InvalidArgumentException("Invalid delta format: {$delta}");
        }

        $signStr = $matches[1];
        $amount = (int) $matches[2];
        $unit = $matches[3];

        // A sign of '+' means future, '-' or no sign means past.
        // '0d' is already handled above, so no-sign here implies past.
        $isPast = ($signStr !== '+');

        $result = $refDate->copy();

        match ($unit) {
            'd' => $isPast ? $result->subDays($amount) : $result->addDays($amount),
            'M' => $isPast ? $result->subDays($amount * 30) : $result->addDays($amount * 30),
            'y' => $isPast ? $result->subDays($amount * 365) : $result->addDays($amount * 365),
        };

        return $result;
    }
}
