<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use Illuminate\Support\Carbon;

final readonly class AttestationPresenceLigneDTO
{
    public function __construct(
        public int $participantId,
        public string $participantNom,
        public int $operationId,
        public string $operationNom,
        public bool $operationArchivee,
        public int $nbPresences,
        public int $nbSeancesTotal,
        public ?Carbon $dateDernierePresence,
        public string $downloadUrl,
    ) {}
}
