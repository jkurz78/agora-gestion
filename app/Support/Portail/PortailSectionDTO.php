<?php

declare(strict_types=1);

namespace App\Support\Portail;

final readonly class PortailSectionDTO
{
    public function __construct(
        public string $id,
        public string $label,
        public string $routeName,
        public string $icon,
        public int $ordre,
        public ?string $groupe,
        public bool $visible,
        public ?int $badge,
    ) {}
}
