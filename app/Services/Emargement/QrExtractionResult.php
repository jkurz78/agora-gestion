<?php

declare(strict_types=1);

namespace App\Services\Emargement;

final readonly class QrExtractionResult
{
    public function __construct(
        public ?int $seanceId,
        public string $reason,
        public ?string $detail,
    ) {}

    public static function ok(int $seanceId): self
    {
        return new self($seanceId, 'ok', null);
    }

    public static function failure(string $reason, ?string $detail = null): self
    {
        return new self(null, $reason, $detail);
    }
}
