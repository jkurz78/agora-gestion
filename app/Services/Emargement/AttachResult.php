<?php

declare(strict_types=1);

namespace App\Services\Emargement;

final readonly class AttachResult
{
    public function __construct(
        public bool $success,
        public ?string $reason = null,
        public ?string $detail = null,
    ) {}

    public static function success(): self
    {
        return new self(true);
    }

    public static function failure(string $reason, ?string $detail = null): self
    {
        return new self(false, $reason, $detail);
    }
}
