<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use Illuminate\Support\Carbon;

final readonly class DocumentParticipantLigneDTO
{
    public function __construct(
        public int $id,
        public string $label,
        public int $participantId,
        public string $participantNom,
        public string $source,
        public Carbon $dateDepot,
        public string $downloadUrl,
    ) {}
}
