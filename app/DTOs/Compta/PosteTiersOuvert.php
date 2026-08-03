<?php

declare(strict_types=1);

namespace App\DTOs\Compta;

use Carbon\CarbonImmutable;

final readonly class PosteTiersOuvert
{
    /**
     * @param  array<int>  $ligneIdsOuvertes
     */
    public function __construct(
        public int $ligneActionId,
        public int $ligneCanoniqueId,
        public array $ligneIdsOuvertes,
        public int $transactionOrigineId,
        public int $compteId,
        public string $numeroCompte,
        public int $tiersId,
        public string $tiersNom,
        public int $soldeCentimes,
        public CarbonImmutable $dateOrigine,
        public CarbonImmutable $dateAffichage,
        public ?string $numeroPiece,
        public ?string $reference,
        public string $libelle,
        public int $exerciceOrigine,
        public int $exerciceActif,
        public bool $estReporte,
    ) {}
}
