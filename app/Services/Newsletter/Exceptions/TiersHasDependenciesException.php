<?php

declare(strict_types=1);

namespace App\Services\Newsletter\Exceptions;

use Exception;

final class TiersHasDependenciesException extends Exception
{
    /** @param array<string, int> $dependencyCounts */
    public function __construct(public readonly array $dependencyCounts)
    {
        $total = array_sum($dependencyCounts);
        parent::__construct("Tiers a {$total} dépendance(s) : suppression impossible.");
    }
}
