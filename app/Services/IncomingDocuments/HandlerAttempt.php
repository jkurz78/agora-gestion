<?php

declare(strict_types=1);

namespace App\Services\IncomingDocuments;

final readonly class HandlerAttempt
{
    public function __construct(
        public string $outcome,
        public ?string $failureReason = null,
        public ?string $failureDetail = null,
        public array $context = [],
    ) {}

    public static function handled(array $context = []): self
    {
        return new self('handled', context: $context);
    }

    public static function pass(): self
    {
        return new self('pass');
    }

    public static function failed(string $reason, ?string $detail = null): self
    {
        return new self('failed', failureReason: $reason, failureDetail: $detail);
    }
}
