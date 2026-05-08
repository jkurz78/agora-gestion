<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use App\Models\RecuFiscalEmis;
use App\Models\TransactionLigne;

final class DonLigneDTO
{
    /**
     * @param  array<int, string>  $alertes  Codes : 'helloasso', 'donnees_modifiees'
     */
    public function __construct(
        public readonly TransactionLigne $ligne,
        public readonly ?RecuFiscalEmis $recu,
        public readonly array $alertes,
        public readonly bool $peutTelecharger,
        public readonly ?string $raisonBlocage,
    ) {}
}
