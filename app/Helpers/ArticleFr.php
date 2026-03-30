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
        // Decode HTML entities first (TinyMCE encodes à as &agrave; etc.)
        $texte = html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Capture HTML tags between preposition and article, preserve them
        // "à <strong>le parcours" → "au <strong>parcours"
        // "de le parcours" → "du parcours"
        $tags = '((?:<[^>]+>\s*)*)';

        $texte = preg_replace('/\bà\s+'.$tags.'le\s/iu', 'au $1', $texte) ?? $texte;
        $texte = preg_replace('/\bà\s+'.$tags.'les\s/iu', 'aux $1', $texte) ?? $texte;
        $texte = preg_replace('/\bde\s+'.$tags.'le\s/iu', 'du $1', $texte) ?? $texte;
        $texte = preg_replace('/\bde\s+'.$tags.'les\s/iu', 'des $1', $texte) ?? $texte;

        return $texte;
    }
}
