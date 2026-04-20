<?php

declare(strict_types=1);

namespace App\Services\Portail\NoteDeFrais;

use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Analyse un justificatif pour pré-remplir les champs d'une ligne de NDF.
 *
 * V1 : implémentation stub qui retourne null (aucune extraction).
 * V2 (deferred) : sera implémentée avec l'API Claude si config/portail.ocr.driver === 'claude'.
 */
final class JustificatifAnalyser
{
    /**
     * @return array{libelle: ?string, montant: ?float, sous_categorie_hint: ?string}
     */
    public function analyse(TemporaryUploadedFile $justif): array
    {
        if (config('portail.ocr.driver') !== 'claude') {
            return ['libelle' => null, 'montant' => null, 'sous_categorie_hint' => null];
        }

        // TODO(v1): appeler l'API Claude avec config('portail.ocr.claude_api_key')
        //           et config('portail.ocr.claude_model') sur le contenu du fichier.
        //           Parser la réponse pour retourner libelle/montant/sous_categorie_hint.
        return ['libelle' => null, 'montant' => null, 'sous_categorie_hint' => null];
    }
}
