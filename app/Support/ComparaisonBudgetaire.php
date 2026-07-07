<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Logique de comparaison réalisé vs budget pour le Compte de résultat.
 *
 * Le sens dépend de la nature de la ligne :
 *  - CHARGE (dépense)  : favorable = dépenser MOINS que le budget ;
 *  - PRODUIT (recette) : favorable = encaisser PLUS que le budget.
 *
 * D'où une couleur de barre direction-aware (le bug historique colorait
 * « > 100 % = rouge » pour tout le monde, ce qui est inversé pour les produits).
 */
final class ComparaisonBudgetaire
{
    private const VERT = '#2E7D32';

    private const ORANGE = '#fd7e14';

    private const ROUGE = '#B5453A';

    /**
     * Couleur (hex) de la barre de progression budgétaire, selon le sens de la ligne.
     *
     * @param  float  $pct  Réalisé / budget × 100.
     * @param  bool  $isCharge  true = charge (dépense), false = produit (recette).
     */
    public static function couleurBarre(float $pct, bool $isCharge): string
    {
        if ($isCharge) {
            // Charge : sous le budget = vert, approche = orange, dépassement = rouge.
            return $pct > 100 ? self::ROUGE : ($pct > 90 ? self::ORANGE : self::VERT);
        }

        // Produit : symétrique inversé — objectif atteint/dépassé = vert,
        // approche = orange, en dessous = rouge.
        return $pct >= 100 ? self::VERT : ($pct >= 90 ? self::ORANGE : self::ROUGE);
    }
}
