<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

final readonly class DocumentsTimelineDTO
{
    public function __construct(
        /** @var RecuFiscalLigneDTO[] */
        public array $recusFiscaux,
        /** @var FactureEmiseLigneDTO[] */
        public array $facturesEmises,
        /** @var FactureDeposeeLigneDTO[] */
        public array $facturesDeposees,
        /** @var DocumentParticipantLigneDTO[] */
        public array $justificatifsParticipants,
        /** @var PieceJointeLigneDTO[] */
        public array $piecesJointes,
        /** @var DocumentPrevisionnelLigneDTO[] */
        public array $documentsPrevisionnels,
        public int $totalGlobal,
    ) {}
}
