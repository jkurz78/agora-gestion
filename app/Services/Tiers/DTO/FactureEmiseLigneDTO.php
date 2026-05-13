<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use Illuminate\Support\Carbon;

final readonly class FactureEmiseLigneDTO
{
    public function __construct(
        public int $id,
        public string $numero,
        public Carbon $date,
        public string $type, // 'facture' | 'devis' | 'pro_forma'
        public string $statut,
        public float $montantTtc,
        public string $ficheUrl,
    ) {}
}
