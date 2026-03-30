<?php

declare(strict_types=1);

namespace App\Helpers;

final class ArticleFr
{
    /**
     * "de" + article défini avec contraction.
     * de("le parcours") → "du parcours"
     * de("la formation") → "de la formation"
     * de("les ateliers") → "des ateliers"
     * de("l'atelier") → "de l'atelier"
     */
    public static function de(string $libelleArticle): string
    {
        if (str_starts_with($libelleArticle, 'le ')) {
            return 'du '.substr($libelleArticle, 3);
        }

        if (str_starts_with($libelleArticle, 'les ')) {
            return 'des '.substr($libelleArticle, 4);
        }

        return 'de '.$libelleArticle;
    }

    /**
     * "à" + article défini avec contraction.
     * a("le parcours") → "au parcours"
     * a("la formation") → "à la formation"
     * a("les ateliers") → "aux ateliers"
     * a("l'atelier") → "à l'atelier"
     */
    public static function a(string $libelleArticle): string
    {
        if (str_starts_with($libelleArticle, 'le ')) {
            return 'au '.substr($libelleArticle, 3);
        }

        if (str_starts_with($libelleArticle, 'les ')) {
            return 'aux '.substr($libelleArticle, 4);
        }

        return 'à '.$libelleArticle;
    }

    /**
     * Contracte les articles dans un texte complet.
     * "à le parcours" → "au parcours"
     * "de le parcours" → "du parcours"
     * "à les ateliers" → "aux ateliers"
     * "de les ateliers" → "des ateliers"
     * "à la formation" → inchangé
     * "de la formation" → inchangé
     */
    public static function contracter(string $texte): string
    {
        return str_replace(
            ['à le ', 'à les ', 'de le ', 'de les ', 'À le ', 'À les ', 'De le ', 'De les '],
            ['au ', 'aux ', 'du ', 'des ', 'Au ', 'Aux ', 'Du ', 'Des '],
            $texte
        );
    }
}
