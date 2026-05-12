<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use Illuminate\Support\Carbon;

final readonly class RecuFiscalLigneDTO
{
    public function __construct(
        public int $id,
        public string $numero,
        public string $type, // 'don' | 'cotisation'
        public Carbon $dateEmission,
        public float $montant,
        public string $downloadUrl,
        public ?string $sourceUrl,
    ) {}
}
