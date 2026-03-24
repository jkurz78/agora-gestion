<?php

declare(strict_types=1);

namespace App\Services;

final class CheckItem
{
    public function __construct(
        public readonly string $nom,
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?array $details = null,
    ) {}
}
