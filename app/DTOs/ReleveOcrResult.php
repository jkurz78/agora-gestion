<?php

declare(strict_types=1);

namespace App\DTOs;

final class ReleveOcrResult
{
    /**
     * @param  array<ReleveOcrMouvement>  $mouvements
     * @param  array<string>  $warnings
     */
    public function __construct(
        public readonly ?float $solde_ouverture,
        public readonly ?float $solde_cloture,
        public readonly ?string $date_cloture,
        public readonly ?string $banque,
        public readonly ?string $numero_compte,
        public readonly array $mouvements = [],
        public readonly array $warnings = [],
    ) {}
}
