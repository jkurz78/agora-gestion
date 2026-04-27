<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutDevis: string
{
    case Brouillon = 'brouillon';
    case Envoye = 'envoye';
    case Accepte = 'accepte';
    case Refuse = 'refuse';
    case Annule = 'annule';

    /**
     * Un devis peut être modifié (lignes ajoutées/éditées/supprimées)
     * uniquement en statut brouillon ou envoyé.
     */
    public function peutEtreModifie(): bool
    {
        return match ($this) {
            self::Brouillon, self::Envoye => true,
            default => false,
        };
    }

    /**
     * La transition vers "envoyé" n'est possible que depuis "brouillon".
     */
    public function peutPasserEnvoye(): bool
    {
        return $this === self::Brouillon;
    }

    /**
     * La duplication est possible depuis tout statut.
     */
    public function peutEtreDuplique(): bool
    {
        return true;
    }

    /**
     * L'annulation est possible depuis tout statut sauf "annulé".
     */
    public function peutEtreAnnule(): bool
    {
        return $this !== self::Annule;
    }

    /**
     * Libellé français du statut.
     */
    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Envoye => 'Envoyé',
            self::Accepte => 'Accepté',
            self::Refuse => 'Refusé',
            self::Annule => 'Annulé',
        };
    }
}
