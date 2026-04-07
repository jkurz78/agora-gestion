<?php

declare(strict_types=1);

namespace App\Services;

final class TiersCsvParseResult
{
    public function __construct(
        public readonly bool $success,
        public readonly array $rows = [],   // Normalized rows: array of assoc arrays with column names as keys
        public readonly array $errors = [], // [['line' => int, 'message' => string]]
    ) {}
}
