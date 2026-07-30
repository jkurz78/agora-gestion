<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\EtapeCompta;

/**
 * État comptable d'une association à un instant donné — dérivé, jamais stocké.
 */
final readonly class EtatCompta
{
    /**
     * @param  array<string, string>  $blocages  Conditions bloquantes détectées,
     *                                           indexées par EtapeCompta::value,
     *                                           décrites en français et SANS commande.
     */
    public function __construct(
        public EtapeCompta $etape,
        public array $blocages,
    ) {}

    public function estOperationnel(): bool
    {
        return $this->etape === EtapeCompta::Operationnel;
    }

    /**
     * Cette condition précise fait-elle partie des blocages ?
     *
     * Une garde exprime ainsi son intention (« le backfill est-il requis ? »)
     * sans comparer l'identité de $etape, qui ne révèle que le premier blocage.
     */
    public function exige(EtapeCompta $etape): bool
    {
        return isset($this->blocages[$etape->value]);
    }

    /**
     * Les causes, concaténées, prêtes à afficher sur n'importe quel support.
     *
     * Ne contient jamais de commande : le remède dépend du support et du tenant,
     * c'est à l'appelant de le composer.
     */
    public function causes(): string
    {
        return implode(' ', $this->blocages);
    }
}
