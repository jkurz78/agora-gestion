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

    private const JAUNE = '#E3B341';

    private const ORANGE = '#E07B39';

    private const ORANGE_FONCE = '#C85A2A';

    private const ROUGE = '#A83C32';

    private const ROUGE_FONCE = '#6E1E18';

    /**
     * Couleur (hex) de la barre de progression budgétaire, selon le sens de la ligne.
     *
     * Six paliers asymétriques, tolérance de 3 % autour de la cible (100 %).
     * Le VERT n'est pas le premier degré d'une rampe qui irait en s'éclaircissant :
     * c'est l'absence de gravité, le repos. Ce n'est QUE côté défavorable que la
     * rampe existe, et elle s'assombrit à dessein (jaune → orange → orange foncé →
     * rouge → rouge foncé) : la luminosité décroît régulièrement, ce qui rend
     * l'échelle lisible en niveaux de gris et pour un daltonisme rouge-vert — la
     * teinte et la luminosité portent alors la même information.
     *
     * Côté favorable, aucune dégradation n'existe : une charge à 40 % (grosse
     * économie) est aussi verte qu'une charge à 103 %, une recette à 150 % est
     * aussi verte qu'une recette à 97 %.
     *
     * @param  float  $pct  Réalisé / budget × 100. Peut être négatif (contra-produit
     *                      débité), auquel cas la ligne tombe tout en bas de la rampe.
     * @param  bool  $isCharge  true = charge (dépense), false = produit (recette).
     */
    public static function couleurBarre(float $pct, bool $isCharge): string
    {
        if ($isCharge) {
            if ($pct <= 103) {
                return self::VERT;
            }
            if ($pct <= 108) {
                return self::JAUNE;
            }
            if ($pct <= 113) {
                return self::ORANGE;
            }
            if ($pct <= 118) {
                return self::ORANGE_FONCE;
            }
            if ($pct <= 123) {
                return self::ROUGE;
            }

            return self::ROUGE_FONCE;
        }

        // Produit : symétrique inversé — plus c'est haut, mieux c'est.
        if ($pct >= 97) {
            return self::VERT;
        }
        if ($pct >= 92) {
            return self::JAUNE;
        }
        if ($pct >= 87) {
            return self::ORANGE;
        }
        if ($pct >= 82) {
            return self::ORANGE_FONCE;
        }
        if ($pct >= 77) {
            return self::ROUGE;
        }

        return self::ROUGE_FONCE;
    }

    /**
     * Écart budgétaire brut : ce qu'on a fait moins ce qu'on avait prévu.
     *
     * Le nombre est un fait, identique pour une charge et pour un produit ;
     * c'est la COULEUR qui porte l'appréciation ({@see ecartEstFavorable()}).
     * Dépenser 70 de plus et encaisser 70 de plus donnent tous deux +70 —
     * l'un est une mauvaise nouvelle, l'autre une bonne.
     */
    public static function ecart(float $prevu, float $realise): float
    {
        return $realise - $prevu;
    }

    /**
     * Un écart est-il favorable ?
     *
     * Charge  : dépenser MOINS que prévu (écart négatif) est une économie.
     * Produit : encaisser PLUS que prévu (écart positif) est un gain.
     * Un résultat se comporte comme un produit.
     */
    public static function ecartEstFavorable(float $ecart, bool $isCharge): bool
    {
        return $isCharge ? $ecart <= 0 : $ecart >= 0;
    }
}
