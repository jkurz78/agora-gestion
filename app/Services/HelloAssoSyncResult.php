<?php

declare(strict_types=1);

namespace App\Services;

final class HelloAssoSyncResult
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly int $transactionsCreated = 0,
        public readonly int $transactionsUpdated = 0,
        public readonly int $lignesCreated = 0,
        public readonly int $lignesUpdated = 0,
        public readonly int $ordersSkipped = 0,
        public readonly array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function totalTransactions(): int
    {
        return $this->transactionsCreated + $this->transactionsUpdated;
    }
}
