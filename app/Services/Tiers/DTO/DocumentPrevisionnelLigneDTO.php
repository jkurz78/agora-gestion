<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use App\Enums\TypeDocumentPrevisionnel;
use Illuminate\Support\Carbon;

final readonly class DocumentPrevisionnelLigneDTO
{
    public function __construct(
        public int $id,
        public string $numero,
        public TypeDocumentPrevisionnel $type,
        public int $version,
        public Carbon $date,
        public float $montantTotal,
        public int $operationId,
        public string $operationNom,
        public int $participantId,
        public string $participantNom,
        public string $downloadUrl,
    ) {}
}
