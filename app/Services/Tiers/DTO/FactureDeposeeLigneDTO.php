<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use Illuminate\Support\Carbon;

final readonly class FactureDeposeeLigneDTO
{
    public function __construct(
        public int $id,
        public string $numeroFournisseur,
        public Carbon $dateFacture,
        public string $statut,
        public int $pdfTaille,
        public Carbon $dateDepot,
        public string $downloadUrl,
        public ?string $ficheUrl,
    ) {}
}
