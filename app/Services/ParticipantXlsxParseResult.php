<?php

declare(strict_types=1);

namespace App\Services;

final class ParticipantXlsxParseResult
{
    /**
     * @param  array<int, array<string, string>>  $rows    Normalized rows: assoc arrays with column names as keys
     * @param  array<int, array{line: int, message: string}>  $errors
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $rows = [],
        public readonly array $errors = [],
    ) {}
}
