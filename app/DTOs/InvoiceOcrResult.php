<?php

declare(strict_types=1);

namespace App\DTOs;

final class InvoiceOcrResult
{
    /**
     * @param  array<InvoiceOcrLigne>  $lignes
     * @param  array<string>  $warnings
     */
    public function __construct(
        public readonly ?string $date,
        public readonly ?string $reference,
        public readonly ?int $tiers_id,
        public readonly ?string $tiers_nom,
        public readonly ?float $montant_total,
        public readonly array $lignes,
        public readonly array $warnings = [],
    ) {}
}
