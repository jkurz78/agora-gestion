<?php

declare(strict_types=1);

namespace App\Services\Compta\ANouveau;

use Carbon\CarbonImmutable;

final readonly class ANouveauPreview
{
    /**
     * @param  list<array{
     *     compte_id: int,
     *     numero_pcg: string,
     *     debit: string,
     *     credit: string,
     *     tiers_id: ?int,
     *     libelle: string,
     *     source_ligne_id: ?int,
     *     racine_ligne_id: ?int
     * }>  $lignes
     */
    public function __construct(
        public int $exerciceSource,
        public int $exerciceCible,
        public CarbonImmutable $dateCible,
        public array $lignes,
        public string $totalDebit,
        public string $totalCredit,
    ) {}

    public function equilibree(): bool
    {
        return bccomp($this->totalDebit, $this->totalCredit, 2) === 0;
    }
}
