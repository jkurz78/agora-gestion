<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

trait HasPolitesseVariables
{
    /**
     * Construit le sous-ensemble des 7 variables politesse pour
     * substitution. Vide-safe : retourne des chaînes prêtes pour le
     * substituteur intelligent.
     *
     * @return array<string, string>
     */
    protected function politesseVariables(
        ?string $civilite,
        ?string $politesse,
        ?string $prenom,
        ?string $nom,
    ): array {
        $civ = $civilite ?? '';
        $pol = $politesse ?? '';
        $p = $prenom ?? '';
        $n = $nom ?? '';

        $nomSeul = trim($n);
        $prenomNom = trim($p.' '.$n);

        return [
            '{civilite}' => $civ,
            '{politesse}' => $pol,
            '{civilite_nom}' => $civ !== '' ? trim($civ.' '.$nomSeul) : $nomSeul,
            '{politesse_nom}' => $pol !== '' ? trim($pol.' '.$nomSeul) : $nomSeul,
            '{civilite_prenom_nom}' => $civ !== '' ? trim($civ.' '.$prenomNom) : $prenomNom,
            '{politesse_prenom_nom}' => $pol !== '' ? trim($pol.' '.$prenomNom) : $prenomNom,
            '{salutation}' => $pol !== '' ? $pol : 'Madame, Monsieur',
            // Rétrocompat avec v4.3.3
            '{adresse_polie}' => $pol !== '' ? trim($pol.' '.$nomSeul) : $nomSeul,
        ];
    }
}
