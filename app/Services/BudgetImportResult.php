<?php

declare(strict_types=1);

namespace App\Services;

final class BudgetImportResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $linesImported = 0,
        public readonly array $errors = [], // [['line' => int, 'message' => string]]
    ) {}
}
