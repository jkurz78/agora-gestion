<?php

declare(strict_types=1);

namespace App\Support;

final class TemplateSubstitution
{
    /**
     * Applique des substitutions {var} → valeur dans une chaîne. Quand une
     * valeur est vide ou null, absorbe un espace adjacent (priorité droite,
     * puis gauche) pour éviter les doubles espaces dans le rendu final.
     *
     * @param  array<string, ?string>  $variables  ex. ['{civilite}' => 'M.', '{politesse}' => '']
     */
    public static function apply(string $body, array $variables): string
    {
        foreach ($variables as $key => $value) {
            if ($value === null || $value === '') {
                if (str_contains($body, $key.' ')) {
                    $body = str_replace($key.' ', '', $body);
                } elseif (str_contains($body, ' '.$key)) {
                    $body = str_replace(' '.$key, '', $body);
                } else {
                    $body = str_replace($key, '', $body);
                }
            } else {
                $body = str_replace($key, $value, $body);
            }
        }

        return $body;
    }
}
