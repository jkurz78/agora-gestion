<?php

declare(strict_types=1);

namespace App\DTOs;

final class InvoiceOcrLigne
{
    public function __construct(
        public readonly ?string $description,
        public readonly ?int $sous_categorie_id,
        public readonly ?int $operation_id,
        public readonly ?int $seance,
        public readonly float $montant,
    ) {}
}
