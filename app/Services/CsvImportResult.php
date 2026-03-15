<?php

declare(strict_types=1);

namespace App\Services;

final class CsvImportResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $transactionsCreated = 0,
        public readonly int $lignesCreated = 0,
        public readonly array $errors = [], // [['line' => 4, 'message' => '...']]
    ) {}
}
