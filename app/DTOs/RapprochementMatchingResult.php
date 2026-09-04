<?php

declare(strict_types=1);

namespace App\DTOs;

final class RapprochementMatchingResult
{
    /**
     * @param  array<RapprochementMatchingProposition>  $propositions
     * @param  array<ReleveOcrMouvement>  $nonApparies
     */
    public function __construct(
        public readonly array $propositions = [],
        public readonly array $nonApparies = [],
    ) {}
}
