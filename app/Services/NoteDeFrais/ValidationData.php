<?php

declare(strict_types=1);

namespace App\Services\NoteDeFrais;

use App\Enums\ModePaiement;

final readonly class ValidationData
{
    public function __construct(
        public int $compte_id,
        public ModePaiement $mode_paiement,
        public string $date,
    ) {}
}
