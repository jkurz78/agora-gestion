<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use Illuminate\Support\Carbon;

final readonly class PieceJointeLigneDTO
{
    public function __construct(
        public int $transactionId,
        public ?int $ligneId,
        public Carbon $dateTransaction,
        public string $type, // 'recette' | 'depense'
        public string $libelle,
        public string $niveau, // 'transaction' | 'ligne'
        public string $downloadUrl,
    ) {}
}
