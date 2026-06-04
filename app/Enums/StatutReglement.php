<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Position d'un règlement dans le grand livre partie double (chantier 4).
 *
 * Les noms de cases sont conservés (zéro rename — décision planning) ; ils
 * décrivent une position ledger, neutre au sens :
 *   - EnAttente = « ouvert / dû »   : 411/401 non lettré.
 *   - EnMain    = « à remettre »     : portage 5112/530 en main, pas de 512X.
 *   - Recu      = « dénoué »          : 512X présent (recette : remis ; dépense : réglé).
 *   - Pointe    = « pointé »          : la transaction porteuse du 512X est rapprochée.
 *
 * Le statut est dérivé du ledger par EtatReglementResolver ; cet enum n'est
 * plus posé à la main (la colonne est un miroir recalculé).
 */
enum StatutReglement: string
{
    case EnAttente = 'en_attente';
    case EnMain = 'en_main';
    case Recu = 'recu';
    case Pointe = 'pointe';

    /**
     * Libellé utilisateur, direction-aware (sans jargon comptable).
     *
     * Les extrémités (EnAttente, Pointe) sont identiques dans les deux sens ;
     * seul l'état « dénoué » diffère (Remis pour une recette, Réglé pour une dépense).
     */
    public function label(?Sens $sens = null): string
    {
        return match ($this) {
            self::EnAttente => 'Dû',
            self::EnMain => 'À remettre',
            self::Recu => $sens === Sens::Depense ? 'Réglé' : 'Remis',
            self::Pointe => 'Pointé',
        };
    }

    public function estOuvert(): bool
    {
        return $this === self::EnAttente;
    }

    public function estEnMain(): bool
    {
        return $this === self::EnMain;
    }

    public function estDenoue(): bool
    {
        return $this === self::Recu;
    }

    public function isEncaisse(): bool
    {
        return $this !== self::EnAttente;
    }
}
