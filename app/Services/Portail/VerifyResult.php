<?php

declare(strict_types=1);

namespace App\Services\Portail;

final class VerifyResult
{
    /** @param list<int> $tiersIds */
    public function __construct(
        public readonly VerifyStatus $status,
        public readonly array $tiersIds = [],
    ) {}

    /** @param list<int> $tiersIds */
    public static function success(array $tiersIds): self
    {
        return new self(VerifyStatus::Success, $tiersIds);
    }

    public static function invalid(): self
    {
        return new self(VerifyStatus::Invalid);
    }

    public static function cooldown(): self
    {
        return new self(VerifyStatus::Cooldown);
    }
}
